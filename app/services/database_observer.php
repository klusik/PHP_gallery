<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/database_observer.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides privacy-safe, request-local database execution telemetry.
 *
 * Responsibilities:
 *   - Normalize SQL into low-cardinality operation/table/fingerprint buckets
 *   - Buffer executed-statement metrics in memory instead of writing per query
 *   - Suppress telemetry recursion while persisting buffered aggregates
 *   - Keep raw SQL, bound values, request bodies, and user-entered values out of persistence
 *   - Fail open so observability cannot break a successful gallery request
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Query execution is observed centrally from app/database.php. Prepared-statement
 *     creation is deliberately not counted as a logical query.
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Core\request_data;
use function Gallery\Models\telemetry_model_upsert_db_query_metric_aggregate;

/**
 * Return a privacy-safe SQL operation bucket.
 *
 * @param string $sql Sql value.
 * @return string Text result for the caller.
 */
function telemetry_sql_operation(string $sql): string
{
    // $firstToken stores the first SQL token used as an operation category.
    $firstToken = strtolower((string) strtok(ltrim($sql), " \t\n\r"));
    return match ($firstToken) {
        'select' => 'select',
        'insert' => 'insert',
        'update' => 'update',
        'delete' => 'delete',
        'replace' => 'replace',
        'create', 'alter', 'drop', 'truncate' => 'ddl',
        'begin', 'commit', 'rollback', 'start' => 'transaction',
        default => 'other',
    };
}

/**
 * Return a best-effort primary table name without storing the full SQL string.
 *
 * @param string $sql Sql value.
 * @return string Text result for the caller.
 */
function telemetry_sql_table_name(string $sql): string
{
    // $normalizedSql stores a compact query shape used only for table extraction.
    $normalizedSql = preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? '';
    foreach (['from', 'into', 'update', 'table'] as $marker) {
        $optionalClause = $marker === 'table' ? '(?:if\\s+(?:not\\s+)?exists\\s+)?' : '';
        if (preg_match('/\b' . preg_quote($marker, '/') . '\s+' . $optionalClause . '`?([a-z0-9_]+)`?/i', $normalizedSql, $match) === 1) {
            return substr((string) $match[1], 0, 80);
        }
    }
    return '';
}

/**
 * Return a short hash of a normalized SQL shape.
 *
 * Raw SQL is never returned or persisted. Quoted literals, numeric literals,
 * hexadecimal literals, and whitespace are normalized before hashing.
 *
 * @param string $sql Sql value.
 * @return string Text result for the caller.
 */
function telemetry_sql_fingerprint(string $sql): string
{
    // $shape stores a normalized SQL shape with values removed.
    $shape = preg_replace('/\b0x[0-9a-f]+\b/i', '?', $sql) ?? $sql;
    $shape = preg_replace('/\'[^\']*\'|"[^"]*"|\b\d+(?:\.\d+)?\b/', '?', $shape) ?? $shape;
    $shape = preg_replace('/\s+/', ' ', strtolower(trim($shape))) ?? $shape;
    return substr(hash('sha256', $shape), 0, 16);
}

/**
 * Return mutable request-local database observer state.
 *
 * @return array<string,mixed> Observer state by reference.
 */
function &telemetry_database_observer_state(): array
{
    if (!isset($GLOBALS['cms_telemetry_database_observer_state']) || !is_array($GLOBALS['cms_telemetry_database_observer_state'])) {
        $GLOBALS['cms_telemetry_database_observer_state'] = [
            'evaluated' => false,
            'evaluating' => false,
            'enabled' => false,
            'slow_threshold_ms' => 250,
            'suppressed' => false,
            'shutdown_registered' => false,
            'buffer' => [],
        ];
    }
    return $GLOBALS['cms_telemetry_database_observer_state'];
}

/**
 * Return whether database telemetry may observe the current request.
 *
 * Capability/schema/settings checks are cached after the first evaluation. The
 * evaluating guard prevents the settings/schema queries needed by this decision
 * from recursively observing themselves.
 */
function telemetry_database_observer_enabled(): bool
{
    $state = &telemetry_database_observer_state();
    if (!empty($state['suppressed']) || !empty($state['evaluating'])) {
        return false;
    }
    if (!empty($state['evaluated'])) {
        return !empty($state['enabled']);
    }

    $state['evaluating'] = true;
    try {
        if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('telemetry')) {
            $state['enabled'] = false;
        } elseif (!function_exists(__NAMESPACE__ . '\\telemetry_schema_ready') || !telemetry_schema_ready()) {
            $state['enabled'] = false;
        } else {
            $settings = function_exists(__NAMESPACE__ . '\telemetry_all_settings') ? telemetry_all_settings() : [];
            $masterEnabled = (string) ($settings['telemetry_enabled'] ?? '0') === '1';
            $databaseEnabled = (string) ($settings['telemetry_database_enabled'] ?? '1') === '1';
            $state['enabled'] = $masterEnabled && $databaseEnabled;
            $threshold = (int) ($settings['telemetry_slow_query_threshold_ms'] ?? 250);
            $state['slow_threshold_ms'] = max(1, min(60_000, $threshold));
        }
    } catch (Throwable) {
        $state['enabled'] = false;
    } finally {
        $state['evaluating'] = false;
        $state['evaluated'] = true;
    }

    return !empty($state['enabled']);
}

/** Return whether one table belongs to telemetry's own persistence/reporting layer. */
function telemetry_database_observer_internal_table(string $tableName): bool
{
    return $tableName !== '' && str_starts_with(strtolower($tableName), 'telemetry_');
}

/**
 * Observe one executed SQL statement without persisting raw SQL.
 *
 * @param string $sql SQL text used only transiently to derive safe buckets.
 * @param float $latencyMs Execution latency in milliseconds.
 * @param bool $ok Whether execution succeeded.
 * @param int $rowCount Driver rowCount() value. SELECT values are intentionally ignored.
 * @param ?string $error Optional execution error. Never persisted.
 * @return void No return value; effects are recorded in the owned state.
 */
function telemetry_observe_db_query(string $sql, float $latencyMs, bool $ok, int $rowCount = 0, ?string $error = null): void
{
    unset($error); // Error text may contain private SQL/driver detail and is never retained.
    if ($sql === '' || !telemetry_database_observer_enabled()) {
        return;
    }

    $operation = telemetry_sql_operation($sql);
    $tableName = telemetry_sql_table_name($sql);
    if (telemetry_database_observer_internal_table($tableName)) {
        return;
    }

    $routeName = telemetry_short_identifier(request_data('query')['page'] ?? 'unknown', 80) ?? 'unknown';
    $fingerprint = telemetry_sql_fingerprint($sql);
    $state = &telemetry_database_observer_state();
    $latencyRounded = max(0, (int) round($latencyMs));
    $slow = $latencyRounded >= (int) ($state['slow_threshold_ms'] ?? 250) ? 1 : 0;
    $rowsAffected = $ok && in_array($operation, ['insert', 'update', 'delete', 'replace'], true) ? max(0, $rowCount) : 0;

    $key = implode('|', [$routeName, $operation, $tableName, $fingerprint]);
    if (!isset($state['buffer'][$key])) {
        $state['buffer'][$key] = [
            'bucket_start' => date('Y-m-d H:00:00'),
            'route_name' => $routeName,
            'operation' => $operation,
            'table_name' => $tableName,
            'query_fingerprint' => $fingerprint,
            'query_count' => 0,
            'failed_count' => 0,
            'slow_count' => 0,
            'latency_ms_sum' => 0,
            'latency_ms_max' => 0,
            'rows_returned_sum' => 0,
            'rows_affected_sum' => 0,
        ];
    }

    $row = &$state['buffer'][$key];
    $row['query_count']++;
    $row['failed_count'] += $ok ? 0 : 1;
    $row['slow_count'] += $slow;
    $row['latency_ms_sum'] += $latencyRounded;
    $row['latency_ms_max'] = max((int) $row['latency_ms_max'], $latencyRounded);
    // PDOStatement::rowCount() is not portable for SELECT, so rows returned remain intentionally zero.
    $row['rows_affected_sum'] += $rowsAffected;
    unset($row);

    if (empty($state['shutdown_registered'])) {
        $state['shutdown_registered'] = true;
        register_shutdown_function(__NAMESPACE__ . '\\telemetry_flush_db_query_buffer');
    }
}

/**
 * Compatibility wrapper for older call sites that already provide normalized counters.
 *
 * New central instrumentation should call telemetry_observe_db_query().
 */
function telemetry_record_db_query(string $sql, float $latencyMs, int $rowsReturned = 0, int $rowsAffected = 0, bool $failed = false): void
{
    $operation = telemetry_sql_operation($sql);
    $rowCount = $operation === 'select' ? $rowsReturned : $rowsAffected;
    telemetry_observe_db_query($sql, $latencyMs, !$failed, $rowCount, null);
}

/**
 * Persist buffered database telemetry aggregates with recursive observation suppressed.
 *
 * Failures are deliberately swallowed after a best-effort operational warning so
 * database observability can never change the response status of an otherwise
 * successful request.
 */
function telemetry_flush_db_query_buffer(): void
{
    $state = &telemetry_database_observer_state();
    if (empty($state['buffer']) || !empty($state['suppressed'])) {
        return;
    }

    $buffer = array_values($state['buffer']);
    $state['buffer'] = [];
    $state['suppressed'] = true;
    try {
        foreach ($buffer as $row) {
            telemetry_model_upsert_db_query_metric_aggregate([
                (string) $row['bucket_start'],
                (string) $row['route_name'],
                (string) $row['operation'],
                (string) $row['table_name'],
                (string) $row['query_fingerprint'],
                (int) $row['query_count'],
                (int) $row['failed_count'],
                (int) $row['slow_count'],
                (int) $row['latency_ms_sum'],
                (int) $row['latency_ms_max'],
                (int) $row['rows_returned_sum'],
                (int) $row['rows_affected_sum'],
                now_sql(),
            ]);
        }
    } catch (Throwable $exception) {
        if (function_exists(__NAMESPACE__ . '\\admin_log_event')) {
            try {
                admin_log_event('warning', 'telemetry.database_observer_flush_failed', 'Database telemetry aggregates could not be stored.', [
                    'aggregate_count' => count($buffer),
                    'error_kind' => get_class($exception),
                ], [
                    'category' => 'telemetry',
                    'severity' => 'warning',
                    'route_name' => 'telemetry_db_observer',
                ]);
            } catch (Throwable) {
                // Observability failure remains fail-open even when operational logging is unavailable.
            }
        }
    } finally {
        $state['suppressed'] = false;
    }
}
