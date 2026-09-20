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

use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Models\telemetry_maintenance_model_acquire_lock;
use function Gallery\Models\telemetry_maintenance_model_release_lock;
use function Gallery\Models\telemetry_maintenance_model_next_rollup_day;
use function Gallery\Models\telemetry_maintenance_model_start_job;
use function Gallery\Models\telemetry_maintenance_model_finish_job;

/**
 * Persist the first hourly date not yet archived as a complete stable day.
 * Type: string.
 * Units: setting key.
 * Scope: this installation telemetry settings.
 * Consumers: daily catch-up and hourly retention.
 * Rationale: a durable exclusive checkpoint prevents replay from partially purged hours.
 */
const TELEMETRY_ROLLUP_CHECKPOINT_KEY = 'telemetry_rollup_next_date';
/**
 * Persist independent scheduling admission across PHP requests.
 * Type: string.
 * Units: setting key.
 * Scope: this installation telemetry settings.
 * Consumers: scheduled telemetry maintenance.
 * Rationale: a shared throttle bounds recurring work without depending on thumbnail progress.
 */
const TELEMETRY_MAINTENANCE_NEXT_KEY = 'telemetry_maintenance_next_at';
use function Gallery\Models\telemetry_model_delete_older_than;
use function Gallery\Models\telemetry_model_rollup_daily;
use function Gallery\Models\telemetry_model_setting;

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
    $toDate = $toDate ?: date('Y-m-d', strtotime('-1 day'));
    return telemetry_model_rollup_daily($fromDate, $toDate, now_sql());
}

/**
 * Purge telemetry rows according to configured retention limits.
 *
 * @return array<string,int> Structured result data for the caller.
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
    $deadline = microtime(true) + max(1, (int) cms_runtime_limit('telemetry.maintenance_time_budget_seconds'));
    $pending = false;
    return telemetry_purge_bounded($deadline, $pending, false);
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
        return telemetry_model_delete_older_than($tableName, $columnName, $days,
            max(1, (int) cms_runtime_limit('telemetry.maintenance_delete_batch_size')),
            $tableName === 'telemetry_hourly_metrics' ? telemetry_rollup_checkpoint() : null);
    } catch (\InvalidArgumentException) {
        return 0;
    }
}

/**
 * Run daily rollup and retention cleanup, logging only the operational summary.
 *
 * @return array<string,mixed> Structured result data for the caller.
 * @param array{force?:bool,time_budget_seconds?:int} $options Operation-specific controls; unsupported keys do not change behavior.
 */
function telemetry_run_maintenance(array $options = []): array
{
    $result = ['ok' => true, 'rolled_up' => 0, 'deleted' => [], 'has_more' => false];
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('telemetry')) {
        return $result + ['skipped' => true, 'reason' => 'disabled'];
    }
    $schemaStatus = presentation_telemetry_schema_status();
    presentation_schema_assert_known($schemaStatus, 'telemetry_maintenance', 'Telemetry maintenance schema could not be verified.');
    if (schema_inspection_is_missing($schemaStatus)) {
        return $result + ['skipped' => true, 'reason' => 'schema_missing'];
    }
    if (!telemetry_maintenance_model_acquire_lock()) {
        return $result + ['skipped' => true, 'reason' => 'busy'];
    }
    $started = microtime(true);
    $jobId = 0;
    try {
        // Recheck the throttle under the lock. CLI/Admin force one slice by
        // default; scheduled site maintenance runs only when independently due.
        if (empty($options['force']) && array_key_exists('force', $options)
            && (int) telemetry_model_setting(TELEMETRY_MAINTENANCE_NEXT_KEY) > time()) {
            return $result + ['skipped' => true, 'reason' => 'not_due'];
        }
        $retrySeconds = max(30, (int) cms_runtime_limit('telemetry.maintenance_retry_seconds'));
        telemetry_set_setting(TELEMETRY_MAINTENANCE_NEXT_KEY, (string) (time() + $retrySeconds));
        $jobId = telemetry_maintenance_model_start_job(now_sql());
        $budget = max(1, min(20, (int) ($options['time_budget_seconds'] ?? cms_runtime_limit('telemetry.maintenance_time_budget_seconds'))));
        $deadline = $started + $budget;
        // Privacy cleanup runs first and remains independent of a broken rollup.
        // Reserve half the slice for catch-up; each table has a separate batch cap.
        $pending = false;
        $result['deleted'] = telemetry_purge_bounded($started + $budget / 2, $pending, true);
        // Ingest accepts timestamps up to 24 hours old. Archive only stable
        // days; refresh yesterday on every slice to include delayed beacons.
        // Preserve the ingest timestamp policy across 23/25-hour local days.
        // Type: integer. Units: elapsed seconds. Scope: stable-day archival boundary.
        // Consumers: telemetry daily catch-up and hourly purge admission.
        // Rationale: ingest accepts the preceding 86400 seconds, not one calendar day.
        $closedBefore = date('Y-m-d', (int) $started - 86400);
        $refreshThrough = date('Y-m-d', strtotime('-1 day'));
        $checkpoint = telemetry_rollup_checkpoint();
        $maxDays = max(1, min(31, (int) cms_runtime_limit('telemetry.maintenance_rollup_days')));
        for ($i = 0; $i < $maxDays && microtime(true) < $deadline; $i++) {
            $day = telemetry_maintenance_model_next_rollup_day($checkpoint, $closedBefore);
            if ($day === null) {
                $checkpoint = $closedBefore;
                telemetry_set_setting(TELEMETRY_ROLLUP_CHECKPOINT_KEY, $checkpoint);
                break;
            }
            $result['rolled_up'] += telemetry_rollup_daily($day, $day);
            $checkpoint = date('Y-m-d', strtotime($day . ' +1 day'));
            // Persist only AFTER successful idempotent rollup and BEFORE purge.
            // A crash cannot cause partially purged hours to replace full totals.
            telemetry_set_setting(TELEMETRY_ROLLUP_CHECKPOINT_KEY, $checkpoint);
        }
        $result['has_more'] = $pending || $checkpoint === null || $checkpoint < $closedBefore;
        if ($checkpoint !== null && microtime(true) < $deadline) {
            $hourlyPending = false;
            $result['deleted']['telemetry_hourly_metrics'] = telemetry_purge_table_bounded(
                'telemetry_hourly_metrics', 'bucket_start',
                telemetry_maintenance_retention_days('telemetry_hourly_retention_days', 90, 7, 730),
                $deadline, $hourlyPending, $checkpoint
            );
            $result['has_more'] = $result['has_more'] || $hourlyPending;
        } else {
            $result['has_more'] = true;
        }
        if (microtime(true) < $deadline) {
            $result['rolled_up'] += telemetry_rollup_daily($closedBefore, $refreshThrough);
        } else {
            $result['has_more'] = true;
        }
        $result['checkpoint'] = $checkpoint;
        $interval = $result['has_more'] ? $retrySeconds : max($retrySeconds, (int) cms_runtime_limit('telemetry.maintenance_interval_seconds'));
        telemetry_set_setting(TELEMETRY_MAINTENANCE_NEXT_KEY, (string) (time() + $interval));
        telemetry_maintenance_model_finish_job($jobId, 'completed', now_sql(), (int) round((microtime(true) - $started) * 1000),
            $result['rolled_up'] + array_sum($result['deleted']), null);
    } catch (Throwable) {
        $result['ok'] = false;
        $result['has_more'] = true;
        $result['error'] = 'maintenance_failed';
        if ($jobId > 0) {
            try {
                telemetry_maintenance_model_finish_job($jobId, 'failed', now_sql(), (int) round((microtime(true) - $started) * 1000), array_sum($result['deleted']), 'maintenance_failed');
            } catch (Throwable) {
                // The started record survives and is reconciled by the next owner.
            }
        }
    } finally {
        try {
            telemetry_maintenance_model_release_lock();
        } catch (Throwable) {
            $result['ok'] = false;
            $result['error'] = 'lock_release_failed';
        }
    }
    try {
        admin_log_event($result['ok'] ? 'info' : 'warning', $result['ok'] ? 'telemetry.maintenance_completed' : 'telemetry.maintenance_failed',
            'Telemetry maintenance slice finished.', $result, ['category' => 'telemetry', 'route_name' => 'admin_telemetry']);
    } catch (Throwable) {
        // Operational logging must not change the committed maintenance outcome.
    }
    return $result;
}

/**
 * Read a strict completed-day checkpoint; invalid state cannot authorize purge.
 * @return ?string Exclusive upper bound of safely archived hourly buckets.
 */
function telemetry_rollup_checkpoint(): ?string
{
    // Do not use the forgiving reporting getter: a failed read is NOT an
    // absent checkpoint. Restarting on partially purged hours destroys totals.
    $stored = telemetry_model_setting(TELEMETRY_ROLLUP_CHECKPOINT_KEY);
    if ($stored === false) {
        return null;
    }
    $value = (string) $stored;
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
        throw new \RuntimeException('Invalid telemetry archival checkpoint.');
    }
    return $value;
}

/**
 * Purge bounded batches from independent retention targets.
 * @param float $deadline Wall-clock slice deadline.
 * @param bool $pending Set when another slice is needed.
 * @param bool $skipHourly True while daily catch-up has not yet run in this slice.
 * @return array<string,int> Deleted rows per table.
 */
function telemetry_purge_bounded(float $deadline, bool &$pending, bool $skipHourly): array
{
    $targets = [
        'telemetry_events' => ['occurred_at', telemetry_maintenance_retention_days('telemetry_raw_retention_days', 7, 1, 90)],
        'telemetry_sessions' => ['last_seen_at', telemetry_maintenance_retention_days('telemetry_session_retention_days', 30, 1, 365)],
        'telemetry_daily_metrics' => ['bucket_date', telemetry_maintenance_retention_days('telemetry_daily_retention_days', 730, 30, 3650)],
        'telemetry_db_query_metrics' => ['bucket_start', telemetry_maintenance_retention_days('telemetry_hourly_retention_days', 90, 7, 730)],
        'telemetry_job_runs' => ['started_at', 180],
    ];
    $checkpoint = $skipHourly ? null : telemetry_rollup_checkpoint();
    if (!$skipHourly && $checkpoint !== null) {
        $targets['telemetry_hourly_metrics'] = ['bucket_start', telemetry_maintenance_retention_days('telemetry_hourly_retention_days', 90, 7, 730)];
    }
    $deleted = [];
    foreach ($targets as $table => [$column, $days]) {
        $deleted[$table] = telemetry_purge_table_bounded($table, $column, $days, $deadline, $pending,
            $table === 'telemetry_hourly_metrics' ? $checkpoint : null);
    }
    return $deleted;
}

/**
 * Delete limited oldest-first batches, never exceeding the configured loop cap.
 * @param string $table Model-allowlisted table.
 * @param string $column Model-allowlisted timestamp column.
 * @param int $days Retention age in days.
 * @param float $deadline Wall-clock deadline checked between statements.
 * @param bool $pending Set on a full batch or exhausted deadline.
 * @param ?string $before Additional exclusive archival checkpoint for hourly rows.
 * @return int Number of deleted rows, not a physical disk-space measurement.
 */
function telemetry_purge_table_bounded(string $table, string $column, int $days, float $deadline, bool &$pending, ?string $before = null): int
{
    $limit = max(1, min(10000, (int) cms_runtime_limit('telemetry.maintenance_delete_batch_size')));
    $batches = max(1, min(20, (int) cms_runtime_limit('telemetry.maintenance_delete_batches')));
    $deleted = 0;
    for ($i = 0; $i < $batches; $i++) {
        if (microtime(true) >= $deadline) {
            $pending = true;
            return $deleted;
        }
        $count = telemetry_model_delete_older_than($table, $column, $days, $limit, $before);
        $deleted += $count;
        if ($count < $limit) {
            return $deleted;
        }
    }
    $pending = true;
    return $deleted;
}

/**
 * Run telemetry before, and independently of, the expensive site-maintenance lock.
 * @return array<string,mixed> Safe outcome even if the database/schema is unavailable.
 */
function telemetry_run_scheduled_maintenance(): array
{
    try {
        return telemetry_run_maintenance(['force' => false]);
    } catch (Throwable) {
        return ['ok' => false, 'has_more' => true, 'error' => 'maintenance_unavailable'];
    }
}

/**
 * Read destructive-maintenance retention without converting read errors to defaults.
 * @param string $key Canonical retention setting key selected by this service.
 * @param int $default Default used only for a confirmed absent setting row.
 * @param int $minimum Minimum supported age in days.
 * @param int $maximum Maximum supported age in days.
 * @return int Configured, bounded retention age.
 */
function telemetry_maintenance_retention_days(string $key, int $default, int $minimum, int $maximum): int
{
    $stored = telemetry_model_setting($key);
    return max($minimum, min($maximum, $stored === false ? $default : (int) $stored));
}
