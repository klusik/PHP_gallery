<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/recording.php
 * Module Type: Service
 *
 * Purpose:
 *   Hot-path instrumentation that records events during an active run.
 *
 * Responsibilities:
 *   - Record database connection, prepare, transaction, and query events
 *   - Record named marks, components, and maintenance events
 *   - Reduce SQL and callsites to bounded shapes before they are stored
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
 *   - Loaded by app/services/admin_test_runs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_runs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Return a bounded UTF-8-aware text prefix without requiring mbstring.
 */
function admin_test_run_text_prefix(string $value, int $length): string
{
    $length = max(0, $length);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}

/**
 * Return a request-safe SQL shape with values removed.
 *
 * @param string $sql SQL statement text.
 * @return string Normalized, value-redacted SQL prefix capped at 2000 characters.
 */
function admin_test_run_sql_shape(string $sql): string
{
    $shape =
        preg_replace(
            '/\'[^\']*\'|"[^"]*"|\b0x[0-9a-f]+\b|\b-?\d+(?:\.\d+)?\b/i',
            '?',
            $sql,
        ) ?? $sql;
    $shape = preg_replace('/\s+/', ' ', trim($shape)) ?? trim($shape);
    return admin_test_run_text_prefix($shape, 2000);
}

/**
 * Return whether a backtrace file belongs to the database or instrumentation layer.
 *
 * Reporting one of these frames would name the tracing code instead of the
 * application code that issued the query.
 *
 * @param string $file Forward-slash normalized absolute file path.
 * @return bool True when the frame must be skipped.
 */
function admin_test_run_callsite_is_instrumentation(string $file): bool
{
    return (
        str_ends_with($file, '/app/database.php')
            || str_ends_with($file, '/app/services/admin_test_runs.php')
            || str_contains($file, '/app/services/admin_test_runs/')
    );
}

/**
 * Return one useful application callsite for a traced DB operation.
 *
 * Frames belonging to the database layer and to this instrumentation module
 * are skipped so the reported callsite is the application code that issued the
 * query. The module entry file and every part file under
 * app/services/admin_test_runs/ are both instrumentation, so both are skipped.
 *
 * @return array{file:string,line:int,function:string}|array{} Application source callsite, or an empty array when no application frame is found.
 */
function admin_test_run_callsite(): array
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
        $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
        if ($file === '' || admin_test_run_callsite_is_instrumentation($file)) {
            continue;
        }
        $root = str_replace('\\', '/', dirname(__DIR__, 3)) . '/';
        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }
        return [
            'file' => $file,
            'line' => (int) ($frame['line'] ?? 0),
            'function' => (string) ($frame['function'] ?? ''),
        ];
    }
    return [];
}

/**
 * Record observation-only lifecycle events from existing updater/maintenance subsystems.
 *
 * @param array<string,mixed> $context Safe event context.
 * @param string $subsystem Maintenance subsystem identifier.
 * @param string $event Event name.
 * @return void Appends a sanitized event to the active request trace when present.
 */
function admin_test_run_record_maintenance_event(
    string $subsystem,
    string $event,
    array $context = [],
): void {
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
    ) {
        return;
    }
    $GLOBALS['admin_test_run_request']['maintenance_events'][] = [
        'subsystem' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $subsystem)
            ?: 'unknown',
        'event' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $event) ?: 'event',
        'at_unix' => microtime(true),
        'context' => $context,
    ];
}

/**
 * Add a high-resolution lifecycle mark to the active request.
 *
 * @param array<string,mixed> $context Structured context.
 * @param string $name Lifecycle mark name.
 * @return void Appends a timed mark to the active request trace when present.
 */
function admin_test_run_mark(string $name, array $context = []): void
{
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
    ) {
        return;
    }
    $state = &$GLOBALS['admin_test_run_request'];
    $now = microtime(true);
    $state['marks'][] = [
        'name' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $name) ?: 'mark',
        'at_unix' => $now,
        'offset_from_request_ms' => max(
            0.0,
            ($now - (float) $state['request_time_unix']) * 1000,
        ),
        'offset_from_instrumentation_ms' => max(
            0.0,
            ($now - (float) $state['instrumentation_enter_unix']) * 1000,
        ),
        'memory_usage_bytes' => memory_get_usage(true),
        'context' => $context,
    ];
}

/**
 * Attach one named diagnostic component snapshot to the current request.
 *
 * @param array<string,mixed> $payload Component payload.
 * @param string $name Diagnostic component name.
 * @return void Stores the component payload on the active request when present.
 */
function admin_test_run_record_component(string $name, array $payload): void
{
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
    ) {
        return;
    }
    if (function_exists(__NAMESPACE__
        . '\\admin_test_run_sanitize_browser_value')) {
        $sanitized = admin_test_run_sanitize_browser_value($payload);
        $payload = is_array($sanitized) ? $sanitized : [];
    }
    $GLOBALS['admin_test_run_request']['components'][$name] = $payload;
}

/**
 * Record the shared PDO connection attempt.
 *
 * @param float $elapsedMs Connection duration in milliseconds.
 * @param bool $ok Whether connection establishment succeeded.
 * @param string $driver PDO driver name.
 * @param string|null $error Optional bounded error message.
 * @return void Appends the connection attempt when an unfinished trace is active.
 */
function admin_test_run_record_db_connection(
    float $elapsedMs,
    bool $ok,
    string $driver,
    ?string $error = null,
): void {
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
        || !empty($GLOBALS['admin_test_run_request']['finished'])
    ) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['connection_attempts'][] = [
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'driver' => $driver,
        'error' => $error,
    ];
}

/**
 * Record one PDO prepare operation separately from statement execution.
 *
 * @param string $sql Prepared SQL statement text.
 * @param float $elapsedMs Prepare duration in milliseconds.
 * @param bool $ok Whether prepare succeeded.
 * @param string|null $error Optional bounded error message.
 * @return void Appends the redacted prepare event when an unfinished trace is active.
 */
function admin_test_run_record_db_prepare(
    string $sql,
    float $elapsedMs,
    bool $ok,
    ?string $error = null,
): void {
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
        || !empty($GLOBALS['admin_test_run_request']['finished'])
    ) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['prepare_events'][] = [
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'fingerprint' => function_exists(__NAMESPACE__
            . '\\telemetry_sql_fingerprint')
            ? telemetry_sql_fingerprint($sql)
            : substr(hash('sha256', admin_test_run_sql_shape($sql)), 0, 16),
        'shape' => admin_test_run_sql_shape($sql),
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
}

/**
 * Record a database transaction lifecycle event.
 *
 * @param string $operation Transaction operation name.
 * @param float $elapsedMs Operation duration in milliseconds.
 * @param bool $ok Whether the operation succeeded.
 * @param string|null $error Optional bounded error message.
 * @return void Appends the transaction event when an unfinished trace is active.
 */
function admin_test_run_record_db_transaction(
    string $operation,
    float $elapsedMs,
    bool $ok,
    ?string $error = null,
): void {
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
        || !empty($GLOBALS['admin_test_run_request']['finished'])
    ) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['transaction_events'][] = [
        'at_unix' => microtime(true),
        'operation' => $operation,
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
}

/**
 * Record one PDO operation without persisting raw parameter values.
 *
 * @param string $sql Executed SQL statement text.
 * @param float $elapsedMs Execution duration in milliseconds.
 * @param bool $ok Whether execution succeeded.
 * @param int $rowCount Affected or returned row count when available.
 * @param string|null $error Optional bounded error message.
 * @return void Updates aggregate counters and appends a bounded redacted query event when tracing is active.
 */
function admin_test_run_record_db_query(
    string $sql,
    float $elapsedMs,
    bool $ok,
    int $rowCount = 0,
    ?string $error = null,
): void {
    if (
        !isset($GLOBALS['admin_test_run_request'])
        || !is_array($GLOBALS['admin_test_run_request'])
        || !empty($GLOBALS['admin_test_run_request']['finished'])
    ) {
        return;
    }
    $dbState = &$GLOBALS['admin_test_run_request']['db'];
    $dbState['query_count_total']++;
    $dbState['query_total_ms'] += $elapsedMs;
    $dbState['query_max_ms'] = max(
        (float) $dbState['query_max_ms'],
        $elapsedMs,
    );
    if (!$ok) {
        $dbState['failed_count']++;
    }
    if (
        (int) $dbState['query_count_recorded']
        >= ADMIN_TEST_RUN_MAX_DB_EVENTS_PER_REQUEST
    ) {
        $dbState['query_events_truncated'] = true;
        return;
    }
    $dbState['query_count_recorded']++;
    $dbState['queries'][] = [
        'sequence' => (int) $dbState['query_count_total'],
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'operation' => function_exists(__NAMESPACE__
            . '\\telemetry_sql_operation')
            ? telemetry_sql_operation($sql)
            : strtolower(strtok(ltrim($sql), " \t\n\r") ?: 'other'),
        'table' => function_exists(__NAMESPACE__ . '\\telemetry_sql_table_name')
            ? telemetry_sql_table_name($sql)
            : '',
        'fingerprint' => function_exists(__NAMESPACE__
            . '\\telemetry_sql_fingerprint')
            ? telemetry_sql_fingerprint($sql)
            : substr(hash('sha256', admin_test_run_sql_shape($sql)), 0, 16),
        'shape' => admin_test_run_sql_shape($sql),
        'row_count' => $rowCount,
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
}
