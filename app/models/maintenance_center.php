<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/maintenance_center.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable Maintenance Center job persistence and the central mutation claim.
 *
 * Responsibilities:
 *   - Create and load persisted maintenance jobs
 *   - Persist bounded plan/state/progress checkpoints
 *   - Atomically claim and release the single central mutation owner slot
 *   - Expose migration revision and bounded history cleanup to the service layer
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
 *   - SQL and PDO transaction ownership stay in this model.
 *   - Services pass semantic fields only; arbitrary SQL fragments are never accepted.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use PDOException;
use RuntimeException;
use function Gallery\Core\db;

/** Acquire a short-lived database-scoped single-flight lock for one job step. */
function maintenance_center_model_acquire_step_lock(int $jobId): bool
{
    $stmt = db()->prepare("SELECT GET_LOCK(CONCAT('pg_maintenance_center_', LEFT(SHA2(DATABASE(), 256), 24), '_', ?), 0)");
    $stmt->execute([$jobId]);
    $value = $stmt->fetchColumn();
    if ($value === null || $value === false) {
        throw new RuntimeException('Maintenance Center step lock is unavailable.');
    }
    return (int) $value === 1;
}

/** Release the current connection's short-lived single-flight job lock. */
function maintenance_center_model_release_step_lock(int $jobId): void
{
    $stmt = db()->prepare("SELECT RELEASE_LOCK(CONCAT('pg_maintenance_center_', LEFT(SHA2(DATABASE(), 256), 24), '_', ?))");
    $stmt->execute([$jobId]);
}

/** Acquire a short-lived actor-scoped lock while replacing/creating an analysis job. */
function maintenance_center_model_acquire_analysis_start_lock(int $actorId): bool
{
    $stmt = db()->prepare("SELECT GET_LOCK(CONCAT('pg_mc_analysis_', LEFT(SHA2(DATABASE(), 256), 24), '_', ?), 0)");
    $stmt->execute([$actorId]);
    $value = $stmt->fetchColumn();
    if ($value === null || $value === false) {
        throw new RuntimeException('Maintenance Center analysis-start lock is unavailable.');
    }
    return (int) $value === 1;
}

/** Release the current connection's short-lived actor-scoped analysis-start lock. */
function maintenance_center_model_release_analysis_start_lock(int $actorId): void
{
    $stmt = db()->prepare("SELECT RELEASE_LOCK(CONCAT('pg_mc_analysis_', LEFT(SHA2(DATABASE(), 256), 24), '_', ?))");
    $stmt->execute([$actorId]);
}

/** Return true when the Maintenance Center persistence migration is available. */
function maintenance_center_model_schema_ready(): bool
{
    try {
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_jobs'");
        return (int) $stmt->fetchColumn() >= 18;
    } catch (\Throwable) {
        return false;
    }
}

/** Return the current applied-migration revision without mutating schema state. */
function maintenance_center_model_schema_revision(): string
{
    try {
        $row = db()->query('SELECT COUNT(*) AS migration_count, MAX(version) AS max_version FROM schema_migrations')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'unknown';
        }
        return (string) ($row['migration_count'] ?? 0) . ':' . (string) ($row['max_version'] ?? '');
    } catch (\Throwable) {
        return 'unknown';
    }
}

/** Create one persisted analysis job and return its id. */
function maintenance_center_model_create_job(array $row): int
{
    $stmt = db()->prepare(
        'INSERT INTO maintenance_jobs
            (actor_id, status, mode, phase, plan_json, state_json, plan_hash, app_version, schema_revision,
             registry_revision, progress_done, progress_total, progress_percent, cancel_requested, lock_key,
             error_category, error_message, created_at, started_at, updated_at, finished_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $row['actor_id'],
        (string) $row['status'],
        (string) ($row['mode'] ?? 'standard'),
        (string) $row['phase'],
        $row['plan_json'] ?? null,
        (string) $row['state_json'],
        $row['plan_hash'] ?? null,
        (string) $row['app_version'],
        (string) $row['schema_revision'],
        (string) $row['registry_revision'],
        max(0, (int) ($row['progress_done'] ?? 0)),
        max(0, (int) ($row['progress_total'] ?? 0)),
        max(0.0, min(100.0, (float) ($row['progress_percent'] ?? 0.0))),
        !empty($row['cancel_requested']) ? 1 : 0,
        $row['lock_key'] ?? null,
        $row['error_category'] ?? null,
        $row['error_message'] ?? null,
        (string) $row['created_at'],
        $row['started_at'] ?? null,
        (string) $row['updated_at'],
        $row['finished_at'] ?? null,
    ]);
    return (int) db()->lastInsertId();
}

/** Load one job by id. */
function maintenance_center_model_job(int $jobId): ?array
{
    $stmt = db()->prepare('SELECT * FROM maintenance_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Load the newest job belonging to one administrator. */
function maintenance_center_model_latest_actor_job(int $actorId): ?array
{
    $stmt = db()->prepare('SELECT * FROM maintenance_jobs WHERE actor_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$actorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Load the newest unfinished actor job. */
function maintenance_center_model_latest_unfinished_actor_job(int $actorId): ?array
{
    $stmt = db()->prepare("SELECT * FROM maintenance_jobs WHERE actor_id = ? AND status IN ('analyzing','ready','running','paused') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$actorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Load the current installation-wide central mutation owner. */
function maintenance_center_model_mutation_owner(): ?array
{
    try {
        $stmt = db()->query("SELECT id, actor_id, status, phase, updated_at FROM maintenance_jobs WHERE lock_key = 'central' LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (\Throwable) {
        return null;
    }
}

/** Load the most recently completed central maintenance job. */
function maintenance_center_model_latest_completed_job(): ?array
{
    try {
        $stmt = db()->query("SELECT * FROM maintenance_jobs WHERE status = 'completed' ORDER BY finished_at DESC, id DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (\Throwable) {
        return null;
    }
}

/** Persist a bounded set of mutable job fields. */
function maintenance_center_model_update_job(int $jobId, int $actorId, array $fields): bool
{
    $allowed = [
        'status', 'mode', 'phase', 'plan_json', 'state_json', 'plan_hash', 'progress_done', 'progress_total',
        'progress_percent', 'cancel_requested', 'lock_key', 'error_category', 'error_message', 'started_at',
        'updated_at', 'finished_at', 'app_version', 'schema_revision', 'registry_revision',
    ];
    $assignments = [];
    $values = [];
    foreach ($fields as $name => $value) {
        if (!in_array((string) $name, $allowed, true)) {
            throw new RuntimeException('Unsupported Maintenance Center persistence field.');
        }
        $assignments[] = '`' . $name . '` = ?';
        $values[] = $value;
    }
    if ($assignments === []) {
        return true;
    }
    $values[] = $jobId;
    $values[] = $actorId;
    $stmt = db()->prepare('UPDATE maintenance_jobs SET ' . implode(', ', $assignments) . ' WHERE id = ? AND actor_id = ?');
    $stmt->execute($values);
    if ($stmt->rowCount() > 0) {
        return true;
    }
    $row = maintenance_center_model_job($jobId);
    return is_array($row) && (int) ($row['actor_id'] ?? 0) === $actorId;
}

/** Atomically claim the single installation-wide central mutation slot. */
function maintenance_center_model_claim_mutation_lock(int $jobId, int $actorId, string $now): bool
{
    try {
        $stmt = db()->prepare(
            "UPDATE maintenance_jobs
                SET lock_key = 'central', status = 'running', phase = 'execute', started_at = COALESCE(started_at, ?), updated_at = ?
              WHERE id = ? AND actor_id = ? AND status IN ('ready','paused') AND (lock_key IS NULL OR lock_key = 'central')"
        );
        $stmt->execute([$now, $now, $jobId, $actorId]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        $row = maintenance_center_model_job($jobId);
        return is_array($row)
            && (int) ($row['actor_id'] ?? 0) === $actorId
            && (string) ($row['lock_key'] ?? '') === 'central'
            && in_array((string) ($row['status'] ?? ''), ['running', 'paused'], true);
    } catch (PDOException $exception) {
        // Duplicate unique lock_key means another central job already owns the mutation slot.
        if ((string) $exception->getCode() === '23000') {
            return false;
        }
        throw $exception;
    }
}

/** Release the central claim while moving the job to a terminal state. */
function maintenance_center_model_finish_job(int $jobId, int $actorId, string $status, string $phase, string $stateJson, float $percent, string $now, ?string $errorCategory = null, ?string $errorMessage = null): bool
{
    $stmt = db()->prepare(
        'UPDATE maintenance_jobs
            SET status = ?, phase = ?, state_json = ?, progress_percent = ?, lock_key = NULL,
                cancel_requested = 0, error_category = ?, error_message = ?, updated_at = ?, finished_at = ?
          WHERE id = ? AND actor_id = ?'
    );
    $stmt->execute([$status, $phase, $stateJson, max(0.0, min(100.0, $percent)), $errorCategory, $errorMessage, $now, $now, $jobId, $actorId]);
    return $stmt->rowCount() > 0;
}

/** Set cooperative cancellation without interrupting an in-flight atomic operation. */
function maintenance_center_model_request_cancel(int $jobId, int $actorId, string $now): bool
{
    $stmt = db()->prepare("UPDATE maintenance_jobs SET cancel_requested = 1, updated_at = ? WHERE id = ? AND actor_id = ? AND status IN ('analyzing','ready','running','paused')");
    $stmt->execute([$now, $jobId, $actorId]);
    return $stmt->rowCount() > 0;
}

/** Remove a bounded batch of old terminal jobs. */
function maintenance_center_model_cleanup_history(string $finishedBefore, int $limit = 100): int
{
    $limit = max(1, min(500, $limit));
    $stmt = db()->prepare("DELETE FROM maintenance_jobs WHERE lock_key IS NULL AND status IN ('completed','failed','cancelled') AND finished_at IS NOT NULL AND finished_at < ? ORDER BY finished_at ASC LIMIT " . $limit);
    $stmt->execute([$finishedBefore]);
    return $stmt->rowCount();
}
