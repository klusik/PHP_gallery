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
function application_update_job_transition_allowed(
    string $from,
    string $to,
): bool {
    if ($from === $to) {
        return true;
    }
    if ($from === 'failed' && $to !== '') {
        return true;
    }

    $stages = application_update_job_stages();
    $fromIndex = array_search($from, $stages, true);
    $toIndex = array_search($to, $stages, true);
    return (
        is_int($fromIndex) && is_int($toIndex) && $toIndex === ($fromIndex + 1)
    );
}

/**
 * Atomically write a small JSON state file.
 *
 * @param string $path Destination JSON path.
 * @param array<string,mixed> $payload State payload.
 * @return void Commits the state or refuses without removing the previous checkpoint.
 */
function application_update_write_json_atomic(
    string $path,
    array $payload,
): void {
    application_update_ensure_dir(dirname($path));
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    if ($json === false) {
        throw new RuntimeException('Could not encode update job state.');
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not persist update job state.');
    }
    // Windows readers may briefly deny replacement. Retry the same complete file;
    // never unlink the previous checkpoint to make a commit succeed.
    $attempts = PHP_OS_FAMILY === 'Windows'
        ? APPLICATION_UPDATE_STATE_RENAME_ATTEMPTS
        : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if (@rename($temporary, $path)) {
            return;
        }
        if (($attempt + 1) < $attempts) {
            usleep(APPLICATION_UPDATE_STATE_RENAME_DELAY_US);
        }
    }
    @unlink($temporary);
    throw new RuntimeException('Could not commit update job state.');
}

/**
 * Persist a durable update job checkpoint.
 *
 * @param array{id:string,stage:string,...<string,mixed>} $job Durable update job checkpoint.
 * @return void Atomically persists the job state with an updated timestamp.
 */
function application_update_save_job(array $job): void
{
    $jobId = (string) ($job['id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Update job identifier is missing.');
    }
    $job['updated_at'] = time();
    application_update_write_json_atomic(
        application_update_job_state_path($jobId),
        $job,
    );
}

/**
 * Persist the last completed update-job pointer.
 *
 * @param string $jobId Completed job identifier.
 */
function application_update_set_last_job(string $jobId): void
{
    application_update_write_json_atomic(application_update_jobs_root()
        . '/last-job.json', [
        'job_id' => $jobId,
        'updated_at' => time(),
    ]);
}

/**
 * Persist or clear the active-job pointer.
 *
 * @param ?string $jobId Job identifier or null to clear.
 * @return void Persists or removes the active-job pointer.
 */
function application_update_set_active_job(?string $jobId): void
{
    $path = application_update_jobs_root() . '/active-job.json';
    if ($jobId === null || $jobId === '') {
        @unlink($path);
        return;
    }
    application_update_write_json_atomic($path, [
        'job_id' => $jobId,
        'updated_at' => time(),
    ]);
}

/**
 * Clear the active-job pointer only when it still belongs to the completing job.
 *
 * A worker does not hold the global start lock for its full time slice. Without
 * this compare-and-clear step, another request could observe a terminal job, start
 * a new update, and then have the old worker unlink the new active-job pointer.
 *
 * @param string $jobId Job that believes it is releasing active ownership.
 * @return void Clears the active pointer only when it still identifies this job.
 */
function application_update_clear_active_job_if(string $jobId): void
{
    application_update_lookup_release_active($jobId);
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
 * @param array{id?:string,operation?:string,trigger?:string,status?:string,stage?:string,progress?:array<string,int|string|float|bool|null>,created_at?:int,updated_at?:int,started_at?:int,finished_at?:int,attempts?:int,error?:array{message?:string,reference?:string,retryable?:bool}|null,result?:array<string,int|string|float|bool|null|list<string>|array<string,int|string|float|bool|null|list<string>>>,checkpoints?:array<string,bool>,runtime_limits?:array<string,int|string|bool>} $job Private durable job state fields used in the public projection.
 * @return array{id:string,operation:string,trigger:string,status:string,stage:string,stage_percent:int,progress:array<string,int|string|float|bool|null>,created_at:int,updated_at:int,started_at:int,finished_at:int,attempts:int,runtime_waiting:bool,integrity_warnings:list<string>,can_reset:bool,error:?array{message?:string,reference?:string,retryable?:bool},result:array<string,int|string|float|bool|null|list<string>|array<string,int|string|float|bool|null|list<string>>>,can_resume:bool,can_cancel:bool,can_rollback:bool,background:bool,runtime_limits:array<string,int|string|bool>} Public-safe job state; attempts counts lifetime worker slices, runtime_waiting reports contention, integrity_warnings lists relative file identities and can_reset offers explicit replacement.
 */
function application_update_job_public_state(array $job): array
{
    $stage = (string) ($job['stage'] ?? '');
    $stages = application_update_job_stages();
    $index = array_search($stage, $stages, true);
    $stagePercent = is_int($index) && count($stages) > 1
        ? (int) floor(($index / (count($stages) - 1)) * 100)
        : 0;

    $progress = (array) ($job['progress'] ?? []);
    if (
        isset($progress['current'], $progress['total'])
        && (int) $progress['total'] > 0
    ) {
        $progress['percent'] = min(
            100,
            (int) floor(
                ((int) $progress['current'] / (int) $progress['total']) * 100,
            ),
        );
    }

    // $publicError upgrades pre-fix persisted manifest failures to the current
    // bounded guidance without needing the original private exception text.
    $publicError = isset($job['error']) && is_array($job['error'])
        ? $job['error']
        : null;
    if (
        $publicError !== null
        && !application_update_job_error_retryable($job)
    ) {
        // Preserve the manifest-specific message only for legacy manifest
        // failures; deterministic PHP/migration errors need their own guidance.
        if (!array_key_exists('retryable', $publicError)
            && empty($job['result']['superseded_by'])
            && empty($job['checkpoints']['archive_url'])
            && ($publicError['reference'] ?? '') !== 'F05D71A153A2') {
            $publicError['message'] = application_update_manifest_mismatch_message();
        }
        $publicError['retryable'] = false;
    }
    if ($publicError !== null && ($job['status'] ?? '') === 'failed') {
        $publicError['message'] = (string) ($publicError['message'] ?? '')
            . ' ' . application_update_recovery_guidance($job);
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
        'runtime_waiting' => ($job['status'] ?? '') === 'running'
            && !empty($job['checkpoints']['runtime_lease_busy']),
        'integrity_warnings' => array_keys((array) ($job['integrity_warnings'] ?? [])),
        'can_reset' => empty($job['result']['superseded_by'])
            && in_array($job['status'] ?? '', ['running', 'paused', 'failed'], true),
        'error' => $publicError,
        'result' => isset($job['result']) && is_array($job['result'])
            ? $job['result']
            : [],
        'can_resume' =>
            in_array((string) ($job['status'] ?? ''), ['running', 'paused'], true)
                || (
                    (string) ($job['status'] ?? '') === 'failed'
                    && application_update_job_error_retryable($job)
                ),
        'can_cancel' =>
            empty($job['recovery_override']) && ($job['operation'] ?? '') !== 'manual_deploy' &&
            empty($job['result']['superseded_by'])
                && empty($job['automatic_rollback'])
                && empty($job['result']['recovery_released']) && in_array(
                (string) ($job['status'] ?? ''),
                ['running', 'failed'],
                true,
            )
                && empty($job['checkpoints']['activation_complete'])
                && (
                    !is_int($index)
                    || $index < (int) array_search('activate', $stages, true)
                ),
        'can_rollback' =>
            empty($job['recovery_override']) && ($job['operation'] ?? '') !== 'manual_deploy' &&
            empty($job['result']['superseded_by'])
                && (($job['recovery_schema'] ?? 0) !== 1
                    || ($job['status'] ?? '') === 'completed')
                && (string) ($job['operation'] ?? '') !== 'rollback'
                && !empty($job['checkpoints']['backup_complete'])
                && (
                    in_array(
                        (string) ($job['stage'] ?? ''),
                        [
                            'activate',
                            'migrate',
                            'finalize',
                            'cleanup',
                            'completed',
                        ],
                        true,
                    )
                    || !empty($job['checkpoints']['activation_complete'])
                ),
        'background' => (string) ($job['trigger'] ?? '') === 'background',
        'runtime_limits' => (array) ($job['runtime_limits'] ?? []),
    ];
}


// Recovery responsibilities stay with their existing updater domain owner.

/**
 * Prepare a new private job without changing the globally active pointer.
 * @param string $operation Validated updater operation.
 * @param array<string,scalar|null> $parameters Normalized semantic parameters.
 * @param string $trigger Normalized api, admin or background ownership.
 * @param string $reservedId Already journaled successor identity, or empty for a fresh ordinary job.
 * @return array<string,mixed> Durable private job with its own workspace.
 */
function application_update_create_job(
    string $operation,
    array $parameters,
    string $trigger,
    string $reservedId = '',
): array {
    $jobId = $reservedId !== '' ? $reservedId
        : gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
    if (is_file(application_update_job_state_path($jobId))) {
        throw new RuntimeException('Update job identity already exists.');
    }
    $jobDir = application_update_job_dir($jobId);
    foreach (['extract', 'ready', 'rollback/original'] as $directory) {
        application_update_ensure_dir($jobDir . '/' . $directory);
    }
    $now = time();
    $job = [
        'schema' => 1,
        'recovery_schema' => 1,
        'id' => $jobId,
        'operation' => $operation,
        'trigger' => in_array($trigger, ['api', 'admin', 'background'], true)
            ? $trigger : 'api',
        'status' => 'running',
        'stage' => $operation === 'rollback' ? 'plan' : 'download',
        'created_at' => $now,
        'started_at' => $now,
        'updated_at' => $now,
        'finished_at' => 0,
        'attempts' => 0,
        'installed_version_at_start' => application_update_version_from_local_bootstrap(
            application_update_project_root() . '/app/bootstrap.php',
        ) ?? '',
        'auto_retries' => 0,
        'parameters' => $parameters,
        'runtime_limits' => application_update_runtime_limits(),
        'progress' => ['current' => 0, 'total' => 0, 'message' => 'Update job created.'],
        'checkpoints' => ['migrations_started' => false],
        'error' => null,
        'result' => [],
        // Discovery runs after session_write_close(). The configured name is
        // still available and is not a credential; keep it for authenticated
        // Admin recovery admission even when no session remains active here.
        'admin_session_name' => session_name(),
    ];
    application_update_save_job($job);
    return $job;
}
