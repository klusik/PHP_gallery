<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/public_search_diagnostics.php
 * Module Type: Model
 *
 * Purpose:
 *   Provides admin-only instrumentation primitives for progressive public-search diagnostics.
 *
 * Responsibilities:
 *   - Capture per-query latency and row counts only while an explicit diagnostic run is active
 *   - Re-run captured SELECT statements through EXPLAIN without changing normal public-search behavior
 *   - Report bounded MariaDB/MySQL table-size and index metadata for search-related tables
 *   - Keep SQL execution and database metadata inspection inside the model layer
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
 *   - The profiler is request-local and disabled by default, so ordinary public search keeps only one lightweight branch check per model query.
 *   - Captured parameters are limited to values already supplied to public-search statements and must never contain application credentials.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Throwable;
use function Gallery\Core\db;

/** @var array<int, string> */
const PUBLIC_SEARCH_DIAGNOSTIC_TABLES = [
    'galleries',
    'images',
    'tags',
    'gallery_tags',
    'image_tags',
    'gallery_translations',
    'image_translations',
    'image_ai_metadata',
];

/**
 * Return the request-local profiler state by reference.
 *
 * @return array{active: bool, queries: array<int, array<string, mixed>>, sequence: int}
 */
function &public_search_diagnostics_profile_state(): array
{
    static $state = [
        'active' => false,
        'queries' => [],
        'sequence' => 0,
    ];
    return $state;
}

/**
 * Start a fresh request-local public-search query capture.
 */
function public_search_diagnostics_profile_start(): void
{
    $state =& public_search_diagnostics_profile_state();
    $state['active'] = true;
    $state['queries'] = [];
    $state['sequence'] = 0;
}

/**
 * Stop the current query capture and return all recorded statements.
 *
 * @return array<int, array<string, mixed>> Captured query diagnostics.
 */
function public_search_diagnostics_profile_stop(): array
{
    $state =& public_search_diagnostics_profile_state();
    $queries = $state['queries'];
    $state['active'] = false;
    $state['queries'] = [];
    $state['sequence'] = 0;
    return $queries;
}

/**
 * Return true while an explicit Admin search-diagnostics run is recording queries.
 */
function public_search_diagnostics_profile_active(): bool
{
    $state =& public_search_diagnostics_profile_state();
    return $state['active'] === true;
}

/**
 * Execute one SELECT and optionally capture its timing and bounded inputs.
 *
 * @param string $label Stable diagnostic subquery label.
 * @param string $sql Prepared SELECT statement.
 * @param array<int, mixed> $params Bound statement parameters.
 * @return array<int, array<string, mixed>> Fetched rows.
 */
function public_search_diagnostics_fetch_all(string $label, string $sql, array $params = []): array
{
    $profile = public_search_diagnostics_profile_active();
    $startedAt = $profile ? hrtime(true) : 0;
    $ok = false;
    $rows = [];
    $errorClass = '';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $ok = true;
        return $rows;
    } catch (Throwable $exception) {
        $errorClass = get_class($exception);
        throw $exception;
    } finally {
        if ($profile) {
            $elapsedMs = max(0.0, (hrtime(true) - $startedAt) / 1_000_000);
            $state =& public_search_diagnostics_profile_state();
            $state['sequence']++;
            $state['queries'][] = [
                'sequence' => $state['sequence'],
                'label' => substr(trim($label), 0, 120),
                'elapsed_ms' => round($elapsedMs, 3),
                'rows_returned' => count($rows),
                'ok' => $ok,
                'error_class' => $errorClass,
                'sql' => trim($sql),
                'params' => public_search_diagnostics_normalize_params($params),
            ];
        }
    }
}

/**
 * Normalize captured parameters into bounded JSON-safe scalar values.
 *
 * @param array<int, mixed> $params Raw prepared-statement parameters.
 * @return array<int, int|float|string|bool|null> Bounded parameter values.
 */
function public_search_diagnostics_normalize_params(array $params): array
{
    $normalized = [];
    foreach ($params as $value) {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            $normalized[] = $value;
            continue;
        }
        $text = (string) $value;
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 180);
        } else {
            $text = substr($text, 0, 180);
        }
        $normalized[] = $text;
    }
    return $normalized;
}

/**
 * Explain captured SELECT statements using the current production optimizer.
 *
 * EXPLAIN is attempted in JSON form first and falls back to the classic tabular
 * plan when the connected MySQL/MariaDB version rejects FORMAT=JSON.
 *
 * @param array<int, array<string, mixed>> $queries Captured first-run statements.
 * @return array<int, array<string, mixed>> Explain reports in execution order.
 */
function public_search_diagnostics_explain_queries(array $queries): array
{
    $reports = [];
    $seen = [];

    foreach ($queries as $query) {
        $sql = trim((string) ($query['sql'] ?? ''));
        if ($sql === '' || preg_match('/^SELECT\b/i', $sql) !== 1) {
            continue;
        }
        $label = (string) ($query['label'] ?? 'query');
        $params = is_array($query['params'] ?? null) ? array_values($query['params']) : [];
        $signature = hash('sha256', $label . "\n" . $sql . "\n" . json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;

        $reports[] = public_search_diagnostics_explain_one($label, $sql, $params);
    }

    return $reports;
}

/**
 * Build one optimizer plan for a captured statement.
 *
 * @param string $label Stable diagnostic label.
 * @param string $sql Prepared SELECT statement.
 * @param array<int, mixed> $params Bound parameters.
 * @return array<string, mixed> Explain result.
 */
function public_search_diagnostics_explain_one(string $label, string $sql, array $params): array
{
    $startedAt = hrtime(true);
    try {
        $stmt = db()->prepare('EXPLAIN FORMAT=JSON ' . $sql);
        $stmt->execute($params);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'label' => $label,
            'status' => 'ok',
            'format' => 'json',
            'elapsed_ms' => round(max(0.0, (hrtime(true) - $startedAt) / 1_000_000), 3),
            'plan' => is_array($decoded) ? $decoded : (string) $raw,
        ];
    } catch (Throwable $jsonException) {
        try {
            $stmt = db()->prepare('EXPLAIN ' . $sql);
            $stmt->execute($params);
            return [
                'label' => $label,
                'status' => 'ok',
                'format' => 'table',
                'elapsed_ms' => round(max(0.0, (hrtime(true) - $startedAt) / 1_000_000), 3),
                'plan' => $stmt->fetchAll(),
            ];
        } catch (Throwable $fallbackException) {
            return [
                'label' => $label,
                'status' => 'error',
                'format' => 'unavailable',
                'elapsed_ms' => round(max(0.0, (hrtime(true) - $startedAt) / 1_000_000), 3),
                'error_class' => get_class($fallbackException),
                'json_error_class' => get_class($jsonException),
            ];
        }
    }
}

/**
 * Return bounded database metadata relevant to public-search optimization.
 *
 * @return array<string, mixed> Database version, table-size estimates, and indexes.
 */
function public_search_diagnostics_database_snapshot(): array
{
    $pdo = db();
    $snapshot = [
        'driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
        'server_version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        'server_info' => '',
        'sql_mode' => '',
        'tables' => [],
        'indexes' => [],
    ];

    try {
        $stmt = $pdo->query('SELECT VERSION() AS version, @@version_comment AS version_comment, @@sql_mode AS sql_mode');
        $row = $stmt !== false ? $stmt->fetch() : false;
        if (is_array($row)) {
            $snapshot['server_version'] = (string) ($row['version'] ?? $snapshot['server_version']);
            $snapshot['server_info'] = (string) ($row['version_comment'] ?? '');
            $snapshot['sql_mode'] = (string) ($row['sql_mode'] ?? '');
        }
    } catch (Throwable) {
    }

    $placeholders = implode(', ', array_fill(0, count(PUBLIC_SEARCH_DIAGNOSTIC_TABLES), '?'));
    try {
        $stmt = $pdo->prepare("SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME IN ($placeholders)
            ORDER BY TABLE_NAME ASC");
        $stmt->execute(PUBLIC_SEARCH_DIAGNOSTIC_TABLES);
        foreach ($stmt->fetchAll() as $row) {
            $snapshot['tables'][] = [
                'table' => (string) ($row['TABLE_NAME'] ?? ''),
                'engine' => (string) ($row['ENGINE'] ?? ''),
                'estimated_rows' => (int) ($row['TABLE_ROWS'] ?? 0),
                'data_bytes' => (int) ($row['DATA_LENGTH'] ?? 0),
                'index_bytes' => (int) ($row['INDEX_LENGTH'] ?? 0),
                'free_bytes' => (int) ($row['DATA_FREE'] ?? 0),
            ];
        }
    } catch (Throwable $exception) {
        $snapshot['table_metadata_error_class'] = get_class($exception);
    }

    foreach (PUBLIC_SEARCH_DIAGNOSTIC_TABLES as $table) {
        try {
            $stmt = $pdo->query('SHOW INDEX FROM `' . $table . '`');
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            $indexes = [];
            foreach ($rows as $row) {
                $indexes[] = [
                    'key_name' => (string) ($row['Key_name'] ?? ''),
                    'non_unique' => (int) ($row['Non_unique'] ?? 0),
                    'seq_in_index' => (int) ($row['Seq_in_index'] ?? 0),
                    'column_name' => (string) ($row['Column_name'] ?? ''),
                    'collation' => (string) ($row['Collation'] ?? ''),
                    'cardinality' => isset($row['Cardinality']) ? (int) $row['Cardinality'] : null,
                    'index_type' => (string) ($row['Index_type'] ?? ''),
                ];
            }
            $snapshot['indexes'][$table] = $indexes;
        } catch (Throwable $exception) {
            $snapshot['indexes'][$table] = [
                ['error_class' => get_class($exception)],
            ];
        }
    }

    return $snapshot;
}
