<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/state.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns job state persistence, stage transitions, and locking.
 *
 * Responsibilities:
 *   - Persist job state atomically so an interrupted request cannot corrupt it
 *   - Enforce the allowed stage transition graph
 *   - Track the active and last job under a leased lock
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
 *   - Loaded by app/services/updates_jobs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/updates_jobs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\run_migrations_bounded;

/**
 * Return the ordered state-machine stages for release installation jobs.
 *
 * @return array<int,string> Ordered stage identifiers.
 */
function application_update_job_stages(): array
{
    return [
        'download',
        'archive_validate',
        'extract',
        'package_validate',
        'plan',
        'stage_files',
        'backup',
        'ready',
        'activate',
        'migrate',
        'finalize',
        'cleanup',
        'completed',
    ];
}

/**
 * Return true when one stage may transition directly to another.
 *
 * @param string $from Current stage.
 * @param string $to Requested next stage.
 * @return bool True for a valid state-machine transition.
 */
function application_update_job_transition_allowed(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    if ($from === 'failed' && $to !== '') {
        return true;
    }

    $stages = application_update_job_stages();
    $fromIndex = array_search($from, $stages, true);
    $toIndex = array_search($to, $stages, true);
    return is_int($fromIndex) && is_int($toIndex) && $toIndex === $fromIndex + 1;
}

/**
 * Atomically write a small JSON state file.
 *
 * @param string $path Destination JSON path.
 * @param array $payload State payload.
 */
function application_update_write_json_atomic(string $path, array $payload): void
{
    application_update_ensure_dir(dirname($path));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode update job state.');
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not persist update job state.');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not commit update job state.');
    }
}

/**
 * Load a persisted JSON state file.
 *
 * @param string $path JSON path.
 * @return array<string,mixed> Decoded payload or an empty array.
 */
function application_update_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return the private directory for one job identifier.
 *
 * @param string $jobId Job identifier.
 * @return string Absolute directory path.
 */
function application_update_job_dir(string $jobId): string
{
    if (!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/', $jobId)) {
        throw new RuntimeException('Invalid update job identifier.');
    }
    return application_update_jobs_root() . '/jobs/' . $jobId;
}

/**
 * Return the durable state path for one update job.
 *
 * @param string $jobId Job identifier.
 * @return string Absolute state path.
 */
function application_update_job_state_path(string $jobId): string
{
    return application_update_job_dir($jobId) . '/job.json';
}

/**
 * Load one durable update job.
 *
 * @param string $jobId Job identifier.
 * @return array<string,mixed> Job state.
 */
function application_update_load_job(string $jobId): array
{
    $job = application_update_read_json(application_update_job_state_path($jobId));
    if ($job === [] || (string) ($job['id'] ?? '') !== $jobId) {
        throw new RuntimeException('Update job state was not found.');
    }
    return $job;
}

/**
 * Persist a durable update job checkpoint.
 *
 * @param array $job Job state.
 */
function application_update_save_job(array $job): void
{
    $jobId = (string) ($job['id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Update job identifier is missing.');
    }
    $job['updated_at'] = time();
    application_update_write_json_atomic(application_update_job_state_path($jobId), $job);
}

/**
 * Return true when a job no longer requires worker execution.
 *
 * @param array $job Job state.
 * @return bool True for completed or permanently failed states.
 */
function application_update_job_terminal(array $job): bool
{
    return in_array((string) ($job['status'] ?? ''), ['completed', 'cancelled'], true);
}

/**
 * Return the active update job when one exists.
 *
 * @return array<string,mixed>|null Active job state.
 */
function application_update_active_job(): ?array
{
    $pointerPath = application_update_jobs_root() . '/active-job.json';
    $pointer = application_update_read_json($pointerPath);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if ($jobId === '') {
        return null;
    }

    try {
        $job = application_update_load_job($jobId);
    } catch (Throwable) {
        @unlink($pointerPath);
        return null;
    }

    if (application_update_job_terminal($job)) {
        @unlink($pointerPath);
        return null;
    }
    return $job;
}

/**
 * Return the last completed update job for optional rollback controls.
 *
 * @return array<string,mixed>|null Last completed job state.
 */
function application_update_last_job(): ?array
{
    $pointerPath = application_update_jobs_root() . '/last-job.json';
    $pointer = application_update_read_json($pointerPath);
    $jobId = (string) ($pointer['job_id'] ?? '');
    if ($jobId === '') {
        return null;
    }
    try {
        return application_update_load_job($jobId);
    } catch (Throwable) {
        @unlink($pointerPath);
        return null;
    }
}

/**
 * Persist the last completed update-job pointer.
 *
 * @param string $jobId Completed job identifier.
 */
function application_update_set_last_job(string $jobId): void
{
    application_update_write_json_atomic(application_update_jobs_root() . '/last-job.json', [
        'job_id' => $jobId,
        'updated_at' => time(),
    ]);
}

/**
 * Persist or clear the active-job pointer.
 *
 * @param ?string $jobId Job identifier or null to clear.
 */
function application_update_set_active_job(?string $jobId): void
{
    $path = application_update_jobs_root() . '/active-job.json';
    if ($jobId === null || $jobId === '') {
        @unlink($path);
        return;
    }
    application_update_write_json_atomic($path, ['job_id' => $jobId, 'updated_at' => time()]);
}

/**
 * Clear the active-job pointer only when it still belongs to the completing job.
 *
 * A worker does not hold the global start lock for its full time slice. Without
 * this compare-and-clear step, another request could observe a terminal job, start
 * a new update, and then have the old worker unlink the new active-job pointer.
 *
 * @param string $jobId Job that believes it is releasing active ownership.
 */
function application_update_clear_active_job_if(string $jobId): void
{
    $lock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15);
    if ($lock === null) {
        // Leaving a terminal pointer in place is safe because active_job() removes
        // terminal pointers on the next serialized start/status pass.
        return;
    }
    try {
        $path = application_update_jobs_root() . '/active-job.json';
        $pointer = application_update_read_json($path);
        if (hash_equals((string) ($pointer['job_id'] ?? ''), $jobId)) {
            @unlink($path);
        }
    } finally {
        application_update_release_lock($lock);
    }
}

/**
 * Acquire a non-blocking filesystem lock and refresh its human-readable lease metadata.
 *
 * flock() is the correctness primitive. The JSON metadata is diagnostic only.
 * A crashed worker releases the kernel lock automatically even if stale metadata
 * remains in the file, so stale lock recovery requires no unsafe lock deletion.
 *
 * @param string $path Lock path.
 * @param int $leaseSeconds Diagnostic lease duration.
 * @return resource|null Open locked handle or null when another worker owns it.
 */
function application_update_acquire_lock(string $path, int $leaseSeconds = 30)
{
    application_update_ensure_dir(dirname($path));
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Could not open update worker lock.');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    $metadata = [
        'owner' => bin2hex(random_bytes(8)),
        'acquired_at' => time(),
        'expires_at' => time() + max(5, $leaseSeconds),
    ];
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($metadata, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    return $handle;
}

/**
 * Release an update worker lock handle.
 *
 * @param resource|null $handle Locked handle.
 */
function application_update_release_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Return a public/Admin-safe update job projection.
 *
 * @param array $job Private durable job state.
 * @return array<string,mixed> Safe state for JSON/UI/logging.
 */
function application_update_job_public_state(array $job): array
{
    $stage = (string) ($job['stage'] ?? '');
    $stages = application_update_job_stages();
    $index = array_search($stage, $stages, true);
    $stagePercent = is_int($index) && count($stages) > 1 ? (int) floor(($index / (count($stages) - 1)) * 100) : 0;

    $progress = (array) ($job['progress'] ?? []);
    if (isset($progress['current'], $progress['total']) && (int) $progress['total'] > 0) {
        $progress['percent'] = min(100, (int) floor(((int) $progress['current'] / (int) $progress['total']) * 100));
    }

    // $publicError upgrades pre-fix persisted manifest failures to the current
    // bounded guidance without needing the original private exception text.
    $publicError = isset($job['error']) && is_array($job['error']) ? $job['error'] : null;
    if ($publicError !== null && !application_update_job_error_retryable($job)) {
        $publicError['message'] = application_update_manifest_mismatch_message();
        $publicError['retryable'] = false;
    }

    return [
        'id' => (string) ($job['id'] ?? ''),
        'operation' => (string) ($job['operation'] ?? ''),
        'trigger' => (string) ($job['trigger'] ?? ''),
        'status' => (string) ($job['status'] ?? ''),
        'stage' => $stage,
        'stage_percent' => $stagePercent,
        'progress' => $progress,
        'created_at' => (int) ($job['created_at'] ?? 0),
        'updated_at' => (int) ($job['updated_at'] ?? 0),
        'started_at' => (int) ($job['started_at'] ?? 0),
        'finished_at' => (int) ($job['finished_at'] ?? 0),
        'attempts' => (int) ($job['attempts'] ?? 0),
        'error' => $publicError,
        'result' => isset($job['result']) && is_array($job['result']) ? $job['result'] : [],
        'can_resume' => (string) ($job['status'] ?? '') === 'running'
            || ((string) ($job['status'] ?? '') === 'failed' && application_update_job_error_retryable($job)),
        'can_cancel' => in_array((string) ($job['status'] ?? ''), ['running', 'failed'], true)
            && empty($job['checkpoints']['activation_complete'])
            && (!is_int($index) || $index < (int) array_search('activate', $stages, true)),
        'can_rollback' => (string) ($job['operation'] ?? '') !== 'rollback'
            && !empty($job['checkpoints']['backup_complete'])
            && (in_array((string) ($job['stage'] ?? ''), ['activate', 'migrate', 'finalize', 'cleanup', 'completed'], true)
                || !empty($job['checkpoints']['activation_complete'])),
        'background' => (string) ($job['trigger'] ?? '') === 'background',
        'runtime_limits' => (array) ($job['runtime_limits'] ?? []),
    ];
}
