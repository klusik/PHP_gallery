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
