<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/telemetry_maintenance.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns single-flight telemetry maintenance, recovery checkpoints and job evidence.
 *
 * Responsibilities:
 *   - Keep bounded telemetry operations in their owning MVC layer
 *   - Preserve visitor privacy and explicit diagnostic outcomes
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use function Gallery\Core\db;

/**
 * Acquire the database-scoped maintenance lock without waiting.
 *
 * The database name is hashed by the database, never exported or persisted in
 * telemetry. NULL is an unavailable locking facility, not safe admission.
 * @return bool True only for the exclusive owner; false when another owner runs.
 */
function telemetry_maintenance_model_acquire_lock(): bool
{
    $value = db()->query("SELECT GET_LOCK(CONCAT('pg_telemetry_', LEFT(SHA2(DATABASE(), 256), 32)), 0)")->fetchColumn();
    if ($value === null || $value === false) {
        throw new RuntimeException('Telemetry maintenance lock unavailable.');
    }
    return (int) $value === 1;
}

/**
 * Release the same connection's database-scoped maintenance lock.
 * @return void No return value; effects are recorded in the owned state.
 */
function telemetry_maintenance_model_release_lock(): void
{
    db()->query("SELECT RELEASE_LOCK(CONCAT('pg_telemetry_', LEFT(SHA2(DATABASE(), 256), 32)))");
}

/**
 * Find the next populated completed day at or after a durable checkpoint.
 * @param ?string $fromDate Inclusive checkpoint, or null for first catch-up.
 * @param string $today Exclusive current local calendar day.
 * @return ?string Populated day, or null when all completed days were processed.
 */
function telemetry_maintenance_model_next_rollup_day(?string $fromDate, string $today): ?string
{
    $stmt = db()->prepare('SELECT DATE(MIN(bucket_start)) FROM telemetry_hourly_metrics'
        . ' WHERE bucket_start < ?' . ($fromDate !== null ? ' AND bucket_start >= ?' : ''));
    $stmt->execute($fromDate !== null ? [$today, $fromDate] : [$today]);
    $day = $stmt->fetchColumn();
    return is_string($day) && $day !== '' ? $day : null;
}

/**
 * Persist a job start and mark abandoned runs under the acquired exclusive lock.
 * @param string $now Current SQL timestamp.
 * @return int Newly inserted job identifier.
 */
function telemetry_maintenance_model_start_job(string $now): int
{
    // Holding the named lock proves no other cooperating maintenance owner is
    // alive. A leftover started row therefore records an interrupted process.
    $stmt = db()->prepare("UPDATE telemetry_job_runs SET status = 'failed', finished_at = ?, error_kind = 'interrupted'
        WHERE job_name = 'telemetry.maintenance' AND status = 'started'");
    $stmt->execute([$now]);
    $stmt = db()->prepare("INSERT INTO telemetry_job_runs (job_name, status, started_at)
        VALUES ('telemetry.maintenance', 'started', ?)");
    $stmt->execute([$now]);
    return (int) db()->lastInsertId();
}

/**
 * Finish a bounded maintenance slice without SQL, request or visitor data.
 * @param int $jobId Owned job identifier.
 * @param string $status completed or failed.
 * @param string $now Current SQL timestamp.
 * @param int $durationMs Elapsed wall-clock milliseconds.
 * @param int $itemCount Processed or deleted item count.
 * @param ?string $errorKind Bounded failure code; never an exception message.
 * @return void Updates only this job's operational outcome.
 */
function telemetry_maintenance_model_finish_job(int $jobId, string $status, string $now, int $durationMs, int $itemCount, ?string $errorKind): void
{
    if (!in_array($status, ['completed', 'failed'], true)
        || !in_array($errorKind, [null, 'maintenance_failed'], true)) {
        throw new \InvalidArgumentException('Unsupported telemetry job outcome.');
    }
    $stmt = db()->prepare('UPDATE telemetry_job_runs SET status = ?, finished_at = ?, duration_ms = ?, item_count = ?, error_kind = ? WHERE id = ?');
    $stmt->execute([$status, $now, min(4294967295, max(0, $durationMs)), min(4294967295, max(0, $itemCount)), $errorKind, $jobId]);
}


/**
 * Count retention-eligible telemetry rows without mutating telemetry state.
 *
 * Cutoffs are semantic timestamps prepared by the owning telemetry service.
 * The table/column pairs remain fixed here so callers cannot inject identifiers.
 *
 * @param array<string,string> $cutoffs SQL/date exclusive cutoffs by telemetry storage kind.
 * @param ?string $hourlyBefore Optional rollup checkpoint bounding hourly deletion eligibility.
 * @return array<string,int> Eligible rows per retention-owned telemetry table.
 */
function telemetry_maintenance_model_retention_counts(array $cutoffs, ?string $hourlyBefore): array
{
    $queries = [
        'telemetry_events' => ['occurred_at', (string) ($cutoffs['telemetry_events'] ?? '')],
        'telemetry_sessions' => ['last_seen_at', (string) ($cutoffs['telemetry_sessions'] ?? '')],
        'telemetry_daily_metrics' => ['bucket_date', (string) ($cutoffs['telemetry_daily_metrics'] ?? '')],
        'telemetry_db_query_metrics' => ['bucket_start', (string) ($cutoffs['telemetry_db_query_metrics'] ?? '')],
        'telemetry_job_runs' => ['started_at', (string) ($cutoffs['telemetry_job_runs'] ?? '')],
    ];
    if ($hourlyBefore !== null && $hourlyBefore !== '') {
        $queries['telemetry_hourly_metrics'] = ['bucket_start', (string) ($cutoffs['telemetry_hourly_metrics'] ?? '')];
    }

    $result = [];
    foreach ($queries as $table => [$column, $cutoff]) {
        if ($cutoff === '') {
            $result[$table] = 0;
            continue;
        }
        if ($table === 'telemetry_hourly_metrics') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM telemetry_hourly_metrics WHERE bucket_start < ? AND bucket_start < ?');
            $stmt->execute([$cutoff, $hourlyBefore]);
        } else {
            $sql = match ($table) {
                'telemetry_events' => 'SELECT COUNT(*) FROM telemetry_events WHERE occurred_at < ?',
                'telemetry_sessions' => 'SELECT COUNT(*) FROM telemetry_sessions WHERE last_seen_at < ?',
                'telemetry_daily_metrics' => 'SELECT COUNT(*) FROM telemetry_daily_metrics WHERE bucket_date < ?',
                'telemetry_db_query_metrics' => 'SELECT COUNT(*) FROM telemetry_db_query_metrics WHERE bucket_start < ?',
                'telemetry_job_runs' => 'SELECT COUNT(*) FROM telemetry_job_runs WHERE started_at < ?',
                default => throw new RuntimeException('Unsupported telemetry retention counter.'),
            };
            $stmt = db()->prepare($sql);
            $stmt->execute([$cutoff]);
        }
        $result[$table] = max(0, (int) $stmt->fetchColumn());
    }
    return $result;
}
