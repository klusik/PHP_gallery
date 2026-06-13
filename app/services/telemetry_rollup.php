<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/telemetry_rollup.php
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

use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Telemetry rollup and cleanup service.
 *
 * Raw telemetry is intentionally short-lived. Hourly records are rolled up into
 * daily records so the admin can keep useful trends without storing detailed
 * per-session event history for longer than necessary.
 */

/**
 * Roll hourly telemetry metrics into daily metrics for the configured window.
 *
 * @param ?string $fromDate From date value.
 * @param ?string $toDate To date value.
 * @return int Integer result for the caller.
 */
function telemetry_rollup_daily(?string $fromDate = null, ?string $toDate = null): int
{
    if (!telemetry_settings_schema_ready()) {
        return 0;
    }
    // $fromDate stores the inclusive start date for daily rollup.
    $fromDate = $fromDate ?: date('Y-m-d', strtotime('-2 days'));
    // $toDate stores the inclusive end date for daily rollup.
    $toDate = $toDate ?: date('Y-m-d');
    // $stmt stores the daily rollup insert-select query.
    $stmt = db()->prepare('INSERT INTO telemetry_daily_metrics (
        bucket_date, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        sample_count, event_count, value_sum, value_min, value_max, updated_at
    )
    SELECT
        DATE(bucket_start), metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        SUM(sample_count), SUM(event_count), SUM(value_sum), MIN(value_min), MAX(value_max), ?
    FROM telemetry_hourly_metrics
    WHERE bucket_start >= ? AND bucket_start < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(bucket_start), metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result
    ON DUPLICATE KEY UPDATE
        sample_count = VALUES(sample_count),
        event_count = VALUES(event_count),
        value_sum = VALUES(value_sum),
        value_min = VALUES(value_min),
        value_max = VALUES(value_max),
        updated_at = VALUES(updated_at)');
    $stmt->execute([now_sql(), $fromDate . ' 00:00:00', $toDate]);
    return $stmt->rowCount();
}

/**
 * Purge telemetry rows according to configured retention limits.
 *
 * @return array Structured result data for the caller.
 */
function telemetry_purge_expired(): array
{
    if (!telemetry_settings_schema_ready()) {
        return [];
    }
    // $deleted stores deleted row counts by table.
    $deleted = [];
    $deleted['telemetry_events'] = telemetry_delete_older_than('telemetry_events', 'occurred_at', telemetry_retention_days('telemetry_raw_retention_days', 7, 1, 90));
    $deleted['telemetry_sessions'] = telemetry_delete_older_than('telemetry_sessions', 'last_seen_at', telemetry_retention_days('telemetry_session_retention_days', 30, 1, 365));
    $deleted['telemetry_hourly_metrics'] = telemetry_delete_older_than('telemetry_hourly_metrics', 'bucket_start', telemetry_retention_days('telemetry_hourly_retention_days', 90, 7, 730));
    $deleted['telemetry_daily_metrics'] = telemetry_delete_older_than('telemetry_daily_metrics', 'bucket_date', telemetry_retention_days('telemetry_daily_retention_days', 730, 30, 3650));
    $deleted['telemetry_db_query_metrics'] = telemetry_delete_older_than('telemetry_db_query_metrics', 'bucket_start', telemetry_retention_days('telemetry_hourly_retention_days', 90, 7, 730));
    $deleted['telemetry_job_runs'] = telemetry_delete_older_than('telemetry_job_runs', 'started_at', 180);
    return $deleted;
}

/**
 * Delete rows from one telemetry table older than the supplied day count.
 *
 * @param string $tableName Table name value.
 * @param string $columnName Column name value.
 * @param int $days Days value.
 * @return int Integer result for the caller.
 */
function telemetry_delete_older_than(string $tableName, string $columnName, int $days): int
{
    // $safeTables stores table and column names that are allowed in retention cleanup SQL.
    $safeTables = [
        'telemetry_events' => ['occurred_at'],
        'telemetry_sessions' => ['last_seen_at'],
        'telemetry_hourly_metrics' => ['bucket_start'],
        'telemetry_daily_metrics' => ['bucket_date'],
        'telemetry_db_query_metrics' => ['bucket_start'],
        'telemetry_job_runs' => ['started_at'],
    ];
    if (!isset($safeTables[$tableName]) || !in_array($columnName, $safeTables[$tableName], true)) {
        return 0;
    }
    // $stmt stores the bounded retention delete query.
    $stmt = db()->prepare("DELETE FROM {$tableName} WHERE {$columnName} < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

/**
 * Run daily rollup and retention cleanup, logging only the operational summary.
 *
 * @return array Structured result data for the caller.
 */
function telemetry_run_maintenance(): array
{
    // $result stores the maintenance result shown in the admin UI.
    $result = [
        'rolled_up' => telemetry_rollup_daily(),
        'deleted' => telemetry_purge_expired(),
    ];
    admin_log_event('info', 'telemetry.maintenance_completed', 'Telemetry maintenance completed.', $result, [
        'category' => 'telemetry',
        'severity' => 'info',
        'route_name' => 'admin_telemetry',
    ]);
    return $result;
}
