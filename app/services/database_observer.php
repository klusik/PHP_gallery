<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/database_observer.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

/**
 * Database telemetry helper functions.
 *
 * This module provides safe aggregation helpers for future DB instrumentation.
 * It stores query shape metadata only and never persists raw SQL or parameters.
 */

/**
 * Return a privacy-safe SQL operation bucket.
 *
 * @param string $sql Sql value.
 * @return string Text result for the caller.
 */
function telemetry_sql_operation(string $sql): string
{
    // $firstToken stores the first SQL token used as an operation category.
    $firstToken = strtolower(strtok(ltrim($sql), " \t\n\r"));
    return match ($firstToken) {
        'select' => 'select',
        'insert' => 'insert',
        'update' => 'update',
        'delete' => 'delete',
        'replace' => 'replace',
        'create', 'alter', 'drop' => 'ddl',
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
        if (preg_match('/\b' . preg_quote($marker, '/') . '\s+`?([a-z0-9_]+)`?/i', $normalizedSql, $match) === 1) {
            return substr($match[1], 0, 80);
        }
    }
    return '';
}

/**
 * Return a short hash of a normalized SQL shape.
 *
 * @param string $sql Sql value.
 * @return string Text result for the caller.
 */
function telemetry_sql_fingerprint(string $sql): string
{
    // $shape stores a normalized SQL shape with values removed.
    $shape = preg_replace('/\'[^\']*\'|"[^"]*"|\b\d+\b/', '?', $sql) ?? $sql;
    $shape = preg_replace('/\s+/', ' ', strtolower(trim($shape))) ?? $shape;
    return substr(hash('sha256', $shape), 0, 16);
}

/**
 * Record an aggregated database query metric.
 *
 * @param string $sql Sql value.
 * @param float $latencyMs Latency ms value.
 * @param int $rowsReturned Rows returned value.
 * @param int $rowsAffected Rows affected value.
 * @param bool $failed Failed value.
 */
function telemetry_record_db_query(string $sql, float $latencyMs, int $rowsReturned = 0, int $rowsAffected = 0, bool $failed = false): void
{
    if (!telemetry_setting_enabled('telemetry_database_enabled', '1') || !telemetry_settings_schema_ready()) {
        return;
    }
    try {
        // $slowThreshold stores the configured slow-query threshold.
        $slowThreshold = (int) telemetry_setting('telemetry_slow_query_threshold_ms', '250');
        // $stmt stores the hourly database metric upsert query.
        $stmt = db()->prepare('INSERT INTO telemetry_db_query_metrics (
            bucket_start, route_name, operation, table_name, query_fingerprint, query_count, failed_count,
            slow_count, latency_ms_sum, latency_ms_max, rows_returned_sum, rows_affected_sum, updated_at
        ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            query_count = query_count + 1,
            failed_count = failed_count + VALUES(failed_count),
            slow_count = slow_count + VALUES(slow_count),
            latency_ms_sum = latency_ms_sum + VALUES(latency_ms_sum),
            latency_ms_max = GREATEST(latency_ms_max, VALUES(latency_ms_max)),
            rows_returned_sum = rows_returned_sum + VALUES(rows_returned_sum),
            rows_affected_sum = rows_affected_sum + VALUES(rows_affected_sum),
            updated_at = VALUES(updated_at)');
        $stmt->execute([
            date('Y-m-d H:00:00'),
            telemetry_short_identifier($_GET['page'] ?? 'unknown', 80) ?? 'unknown',
            telemetry_sql_operation($sql),
            telemetry_sql_table_name($sql),
            telemetry_sql_fingerprint($sql),
            $failed ? 1 : 0,
            $latencyMs >= $slowThreshold ? 1 : 0,
            max(0, (int) round($latencyMs)),
            max(0, (int) round($latencyMs)),
            max(0, $rowsReturned),
            max(0, $rowsAffected),
            now_sql(),
        ]);
    } catch (Throwable) {
    }
}
