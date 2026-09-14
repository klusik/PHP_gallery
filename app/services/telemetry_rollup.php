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

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\telemetry_model_delete_older_than;
use function Gallery\Models\telemetry_model_rollup_daily;

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
    $schemaStatus = presentation_telemetry_schema_status();
    presentation_schema_assert_known(
        $schemaStatus,
        'telemetry_daily_rollup',
        'Telemetry report schema could not be verified. No rollup was performed.'
    );
    if (schema_inspection_is_missing($schemaStatus)) {
        return 0;
    }
    // $fromDate stores the inclusive start date for daily rollup.
    $fromDate = $fromDate ?: date('Y-m-d', strtotime('-2 days'));
    // $toDate stores the inclusive end date for daily rollup.
    $toDate = $toDate ?: date('Y-m-d');
    return telemetry_model_rollup_daily($fromDate, $toDate, now_sql());
}

/**
 * Purge telemetry rows according to configured retention limits.
 *
 * @return array Structured result data for the caller.
 */
function telemetry_purge_expired(): array
{
    $schemaStatus = presentation_telemetry_schema_status();
    presentation_schema_assert_known(
        $schemaStatus,
        'telemetry_retention_purge',
        'Telemetry report schema could not be verified. No retention purge was performed.'
    );
    if (schema_inspection_is_missing($schemaStatus)) {
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
    // Keep the service API fail-closed for unexpected table/column pairs while
    // the model owns the persistence allowlist and delete statement.
    try {
        return telemetry_model_delete_older_than($tableName, $columnName, $days);
    } catch (\InvalidArgumentException) {
        return 0;
    }
}

/**
 * Run daily rollup and retention cleanup, logging only the operational summary.
 *
 * @return array Structured result data for the caller.
 */
function telemetry_run_maintenance(): array
{
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('telemetry')) {
        return ['rolled_up' => 0, 'deleted' => []];
    }

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
