<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides the durable, resumable application-update state machine used by Admin and background workers.
 *
 * Responsibilities:
 *   - Persist update job checkpoints outside active application files
 *   - Bound normal worker invocations by elapsed wall-clock time
 *   - Stream downloads to disk without loading release archives into PHP memory
 *   - Extract, validate, stage, and back up release files before activation
 *   - Serialize workers with durable job metadata and operating-system file locks
 *   - Keep activation as a small retry-safe critical section
 *   - Resume migrations at migration-file boundaries
 *   - Redact update failures before they reach Admin or public responses
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
 *   - Correctness must not depend on set_time_limit() or ignore_user_abort().
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
 * Return the update workspace used for jobs, archives, extracts, and rollback data.
 *
 * @return string Absolute filesystem path.
 */
function application_update_jobs_root(): string
{
    $root = application_update_project_root() . '/cache/updates';
    application_update_ensure_dir($root);
    application_update_ensure_dir($root . '/jobs');
    return $root;
}

/**
 * Return a conservative wall-clock budget for one update worker request.
 *
 * PHP's max_execution_time is only one possible limit. Reverse proxies, FastCGI,
 * web servers, and hosting control planes can impose shorter independent limits.
 * The updater therefore deliberately uses a much smaller slice and never calls
 * set_time_limit() as a correctness mechanism.
 *
 * @param float $requestedSeconds Preferred upper wall-clock slice.
 * @return array{started_at: float, deadline: float, seconds: float, php_max_execution_time: int, memory_limit: string}
 */
function application_update_time_budget(float $requestedSeconds = 8.0): array
{
    $startedAt = microtime(true);
    $requestedSeconds = max(1.0, min(12.0, $requestedSeconds));
    $phpMax = (int) ini_get('max_execution_time');

    // Leave a five-second guard when PHP itself has a finite request limit.
    if ($phpMax > 0) {
        $requestedSeconds = min($requestedSeconds, max(1.0, (float) $phpMax - 5.0));
    }

    return [
        'started_at' => $startedAt,
        'deadline' => $startedAt + $requestedSeconds,
        'seconds' => $requestedSeconds,
        'php_max_execution_time' => $phpMax,
        'memory_limit' => (string) ini_get('memory_limit'),
    ];
}

/**
 * Return true when enough time remains to begin another normal checkpoint unit.
 *
 * @param array $budget Budget returned by application_update_time_budget().
 * @param float $reserveSeconds Minimum remaining wall-clock reserve.
 * @return bool True when another bounded unit may start.
 */
function application_update_budget_allows(array $budget, float $reserveSeconds = 0.75): bool
{
    return microtime(true) + max(0.05, $reserveSeconds) < (float) ($budget['deadline'] ?? 0.0);
}

/**
 * Return runtime timeout diagnostics without attempting to extend them.
 *
 * @return array<string,mixed> Safe runtime-limit diagnostics.
 */
function application_update_runtime_limits(): array
{
    return [
        'php_max_execution_time' => (int) ini_get('max_execution_time'),
        'php_memory_limit' => (string) ini_get('memory_limit'),
        'set_time_limit_available' => function_exists('set_time_limit'),
        'ignore_user_abort_available' => function_exists('ignore_user_abort'),
        'design_depends_on_timeout_extension' => false,
        'proxy_timeout_detectable' => false,
    ];
}

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
 * Return a short safe reference for an internal updater failure.
 *
 * @param string $message Internal exception message.
 * @return string Stable diagnostic reference.
 */
function application_update_error_reference(string $message): string
{
    return strtoupper(substr(hash('sha256', $message), 0, 12));
}

/**
 * Return bounded recovery guidance for a package-publisher integrity failure.
 */
function application_update_manifest_mismatch_message(): string
{
    return 'This release package does not match its integrity manifest. Retrying the same build cannot repair it; cancel this job and select a newer release or beta code.';
}

/**
 * Convert arbitrary updater failures into an Admin-safe message and reference.
 *
 * The returned payload deliberately excludes the exception text, filesystem
 * paths, URLs, SQL, credentials, tokens, and stack traces.
 *
 * @param Throwable|string $error Internal failure.
 * @return array{message:string,reference:string,retryable:bool}
 */
function application_update_safe_error($error): array
{
    $internal = $error instanceof Throwable ? $error->getMessage() : (string) $error;
    $reference = application_update_error_reference($internal);
    $lower = strtolower($internal);

    $retryable = true;
    if (str_contains($lower, 'failed core-manifest integrity validation')
        || str_contains($lower, 'installable file that is missing from the core manifest')
        || str_contains($lower, 'version markers do not agree')) {
        $message = application_update_manifest_mismatch_message();
        $retryable = false;
    } elseif (str_contains($lower, 'archive') || str_contains($lower, 'zip') || str_contains($lower, 'extract')) {
        $message = 'The downloaded update package could not be prepared or validated.';
    } elseif (str_contains($lower, 'migration') || str_contains($lower, 'schema')) {
        $message = 'The database migration stage could not be completed safely.';
    } elseif (str_contains($lower, 'download') || str_contains($lower, 'http') || str_contains($lower, 'curl') || str_contains($lower, 'github')) {
        $message = 'The update download could not be completed.';
    } elseif (str_contains($lower, 'backup') || str_contains($lower, 'rollback')) {
        $message = 'The updater could not prepare or use the rollback data safely.';
    } elseif (str_contains($lower, 'activate') || str_contains($lower, 'replace') || str_contains($lower, 'rename')) {
        $message = 'The prepared update could not be activated safely.';
    } else {
        $message = 'The update job could not continue safely.';
    }

    return ['message' => $message, 'reference' => $reference, 'retryable' => $retryable];
}

/**
 * Return whether a failed update job may benefit from retrying the same source.
 *
 * Older persisted jobs predate the explicit retryable field. Their bounded
 * reference can still identify the three deterministic package-publisher
 * failures without retaining or exposing the original exception text.
 */
function application_update_job_error_retryable(array $job): bool
{
    $error = isset($job['error']) && is_array($job['error']) ? $job['error'] : [];
    if (array_key_exists('retryable', $error)) {
        return $error['retryable'] !== false;
    }

    $reference = strtoupper((string) ($error['reference'] ?? ''));
    $nonRetryableReferences = [
        application_update_error_reference('Downloaded update archive failed core-manifest integrity validation.'),
        application_update_error_reference('Downloaded update archive contains an installable file that is missing from the core manifest.'),
        application_update_error_reference('Downloaded update package version markers do not agree.'),
    ];
    return !in_array($reference, $nonRetryableReferences, true);
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

/**
 * Normalize and validate job-operation parameters before persistence.
 *
 * @param string $operation Update operation.
 * @param array $parameters Caller parameters.
 * @return array<string,mixed> Safe normalized parameters.
 */
function application_update_job_parameters(string $operation, array $parameters): array
{
    if (!in_array($operation, ['stable_update', 'beta_install', 'stable_restore', 'clean_reinstall', 'rollback'], true)) {
        throw new RuntimeException('Unsupported update operation.');
    }

    if ($operation === 'rollback') {
        $sourceJobId = trim((string) ($parameters['source_job_id'] ?? ''));
        if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $sourceJobId)) {
            throw new RuntimeException('Rollback source job identifier is invalid.');
        }
        return ['source_job_id' => $sourceJobId];
    }

    if ($operation === 'beta_install') {
        $commit = strtolower(trim((string) ($parameters['commit'] ?? '')));
        if (!preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            throw new RuntimeException('Enter a valid beta code.');
        }
        return ['commit' => $commit];
    }

    $branch = trim((string) ($parameters['branch'] ?? ''));
    if ($branch === '') {
        $branch = application_update_branch_candidates()[0] ?? '';
    }
    application_update_assert_allowed_branch($branch);

    $normalized = ['branch' => $branch];
    if ($operation === 'clean_reinstall') {
        $normalized['clean_unexpected_files'] = true;
    }
    if (isset($parameters['target_version'])) {
        $normalized['target_version'] = trim((string) $parameters['target_version']);
    }
    return $normalized;
}

/**
 * Start a durable update job or return the already-active job.
 *
 * @param string $operation Update operation.
 * @param array $parameters Operation parameters.
 * @param string $trigger api, admin, or background.
 * @return array<string,mixed> Safe job state.
 */
function application_update_start_job(string $operation, array $parameters = [], string $trigger = 'api'): array
{
    if ($operation !== 'rollback' && !class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for application updates.');
    }

    $startLock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15);
    if ($startLock === null) {
        $active = application_update_active_job();
        if ($active !== null) {
            return application_update_job_public_state($active);
        }
        throw new RuntimeException('Another update request is being prepared.');
    }

    try {
        $active = application_update_active_job();
        if ($active !== null) {
            return application_update_job_public_state($active);
        }

        $parameters = application_update_job_parameters($operation, $parameters);
        $jobId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $jobDir = application_update_job_dir($jobId);
        application_update_ensure_dir($jobDir);
        application_update_ensure_dir($jobDir . '/extract');
        application_update_ensure_dir($jobDir . '/ready');
        application_update_ensure_dir($jobDir . '/rollback/original');

        $now = time();
        $job = [
            'schema' => 1,
            'id' => $jobId,
            'operation' => $operation,
            'trigger' => in_array($trigger, ['api', 'admin', 'background'], true) ? $trigger : 'api',
            'status' => 'running',
            'stage' => $operation === 'rollback' ? 'plan' : 'download',
            'created_at' => $now,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => 0,
            'attempts' => 0,
            'parameters' => $parameters,
            'runtime_limits' => application_update_runtime_limits(),
            'progress' => ['current' => 0, 'total' => 0, 'message' => 'Update job created.'],
            'checkpoints' => [],
            'error' => null,
            'result' => [],
        ];
        application_update_save_job($job);
        application_update_set_active_job($jobId);
        return application_update_job_public_state($job);
    } finally {
        application_update_release_lock($startLock);
    }
}

/**
 * Start a rollback job from a completed or failed post-activation update snapshot.
 *
 * A failed source job is first marked cancelled while holding the global start
 * lock. Its durable rollback directory remains untouched and becomes the source
 * for a new prepared activation job. Pre-activation failures are not eligible
 * because the active installation was not changed and should simply be retried
 * or abandoned.
 *
 * @param string $sourceJobId Source update job identifier.
 * @param string $trigger api or admin.
 * @return array<string,mixed> Safe rollback job state.
 */
function application_update_start_rollback_job(string $sourceJobId, string $trigger = 'api'): array
{
    $sourceJobId = trim($sourceJobId);
    if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $sourceJobId)) {
        throw new RuntimeException('Rollback source job identifier is invalid.');
    }
    $startLock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15);
    if ($startLock === null) {
        throw new RuntimeException('Another update request is being prepared.');
    }
    try {
        $source = application_update_load_job($sourceJobId);
        if (empty($source['checkpoints']['backup_complete'])) {
            throw new RuntimeException('Rollback snapshot is not complete.');
        }
        $sourceStage = (string) ($source['stage'] ?? '');
        $eligible = in_array($sourceStage, ['activate', 'migrate', 'finalize', 'cleanup', 'completed'], true)
            || !empty($source['checkpoints']['activation_complete']);
        if (!$eligible) {
            throw new RuntimeException('Rollback is not required before activation begins.');
        }
        $active = application_update_active_job();
        if ($active !== null && (string) ($active['id'] ?? '') !== $sourceJobId) {
            return application_update_job_public_state($active);
        }
        if ($active !== null) {
            if ((string) ($source['status'] ?? '') !== 'failed') {
                throw new RuntimeException('Running update must reach a checkpoint before rollback can start.');
            }
            $source['status'] = 'cancelled';
            $source['finished_at'] = time();
            $source['result']['cancelled_for_rollback'] = true;
            application_update_save_job($source);
            application_update_set_active_job(null);
        }

        $jobId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
        $jobDir = application_update_job_dir($jobId);
        application_update_ensure_dir($jobDir . '/ready');
        application_update_ensure_dir($jobDir . '/rollback/original');
        $now = time();
        $job = [
            'schema' => 1,
            'id' => $jobId,
            'operation' => 'rollback',
            'trigger' => in_array($trigger, ['api', 'admin'], true) ? $trigger : 'api',
            'status' => 'running',
            'stage' => 'plan',
            'created_at' => $now,
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => 0,
            'attempts' => 0,
            'parameters' => ['source_job_id' => $sourceJobId],
            'runtime_limits' => application_update_runtime_limits(),
            'progress' => ['current' => 0, 'total' => 0, 'message' => 'Rollback job created.'],
            'checkpoints' => [],
            'error' => null,
            'result' => [],
        ];
        application_update_save_job($job);
        application_update_set_active_job($jobId);
        return application_update_job_public_state($job);
    } finally {
        application_update_release_lock($startLock);
    }
}

/**
 * Return the remote archive URL for a persisted job without storing it in job state.
 *
 * @param array $job Job state.
 * @return string Trusted update URL.
 */
function application_update_job_archive_url(array $job): string
{
    $operation = (string) ($job['operation'] ?? '');
    $parameters = (array) ($job['parameters'] ?? []);
    if ($operation === 'beta_install') {
        return application_update_commit_zip_url((string) ($parameters['commit'] ?? ''));
    }
    return application_update_zip_url((string) ($parameters['branch'] ?? ''));
}

/**
 * Stream a remote archive into the job workspace for a bounded amount of time.
 *
 * Partial files are kept between requests. cURL Range requests are preferred.
 * When a server ignores a resume Range and replies 200, the local partial file
 * is truncated before response bytes are written so duplicate data cannot be appended.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when the complete HTTP response finished in this request.
 */
function application_update_job_download_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $archivePath = $jobDir . '/package.zip';
    $offset = is_file($archivePath) ? (int) filesize($archivePath) : 0;
    $url = trim((string) ($job['checkpoints']['archive_url'] ?? ''));
    if ($url === '') {
        // Keep the same trusted archive URL across continuation requests. The
        // response validator below protects Range resume when a branch head moves.
        $url = application_update_job_archive_url($job);
        $job['checkpoints']['archive_url'] = $url;
        application_update_save_job($job);
    }
    if (!str_starts_with($url, 'https://codeload.github.com/') && !str_starts_with($url, 'https://github.com/')) {
        throw new RuntimeException('Persisted update archive source is not trusted.');
    }

    // Keep each external I/O call below the normal worker slice.
    $remaining = max(2.0, (float) ($budget['deadline'] ?? microtime(true) + 2.0) - microtime(true) - 0.75);
    $ioTimeout = (int) max(2, min(7, floor($remaining)));

    if (function_exists('curl_init')) {
        $handle = fopen($archivePath, $offset > 0 ? 'ab' : 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open update archive destination.');
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Could not initialize update download.');
        }
        $responseStatus = 0;
        $contentLength = null;
        $contentRangeTotal = null;
        $responseEtag = null;
        $responseLastModified = null;
        $rangeHonored = $offset === 0;
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(4, $ioTimeout),
            CURLOPT_TIMEOUT => $ioTimeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . \Gallery\Core\cms_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FILE => $handle,
            CURLOPT_FAILONERROR => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$responseStatus, &$contentLength, &$contentRangeTotal, &$responseEtag, &$responseLastModified, &$rangeHonored, $offset, $handle): int {
                $length = strlen($line);
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', trim($line), $match) === 1) {
                    $responseStatus = (int) $match[1];
                    $contentLength = null;
                    $contentRangeTotal = null;
                    $responseEtag = null;
                    $responseLastModified = null;
                    if ($offset > 0 && $responseStatus === 200) {
                        // If-Range or the origin rejected the partial snapshot. Restart
                        // safely instead of appending bytes from a changed branch head.
                        ftruncate($handle, 0);
                        rewind($handle);
                        $rangeHonored = false;
                    }
                } elseif (stripos($line, 'Content-Range:') === 0 && $offset > 0) {
                    $rangeHonored = true;
                    if (preg_match('#/([0-9]+)\s*$#', trim($line), $rangeMatch) === 1) {
                        $contentRangeTotal = (int) $rangeMatch[1];
                    }
                } elseif (stripos($line, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr($line, strlen('Content-Length:')));
                } elseif (stripos($line, 'ETag:') === 0) {
                    $responseEtag = trim(substr($line, strlen('ETag:')));
                } elseif (stripos($line, 'Last-Modified:') === 0) {
                    $responseLastModified = trim(substr($line, strlen('Last-Modified:')));
                }
                return $length;
            },
        ]);
        if ($offset > 0) {
            curl_setopt($curl, CURLOPT_RANGE, $offset . '-');
            $ifRange = trim((string) ($job['checkpoints']['archive_etag'] ?? ''));
            if ($ifRange === '') {
                $ifRange = trim((string) ($job['checkpoints']['archive_last_modified'] ?? ''));
            }
            if ($ifRange !== '') {
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['If-Range: ' . $ifRange]);
            }
        }

        $ok = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fflush($handle);
        fclose($handle);

        $size = is_file($archivePath) ? (int) filesize($archivePath) : 0;
        $expectedBytes = $contentRangeTotal;
        if ($expectedBytes === null && $contentLength !== null) {
            $expectedBytes = $rangeHonored && $offset > 0 ? $offset + $contentLength : $contentLength;
        }
        if ($responseEtag !== null && $responseEtag !== '') {
            $job['checkpoints']['archive_etag'] = $responseEtag;
        }
        if ($responseLastModified !== null && $responseLastModified !== '') {
            $job['checkpoints']['archive_last_modified'] = $responseLastModified;
        }
        if ($expectedBytes !== null && $expectedBytes > 0) {
            $job['checkpoints']['archive_expected_bytes'] = $expectedBytes;
        }
        $job['progress'] = [
            'current' => $size,
            'total' => (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0),
            'message' => 'Downloading update package.',
            'unit' => 'bytes',
        ];
        application_update_save_job($job);

        if ($status >= 400) {
            throw new RuntimeException('Update archive HTTP request failed.');
        }
        if ($ok === true && in_array($status, [200, 206], true)) {
            $expected = (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0);
            if ($expected > 0 && $size !== $expected) {
                throw new RuntimeException('Update archive download completed with an unexpected size.');
            }
            return true;
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT && $size > 0) {
            return false;
        }
        throw new RuntimeException('Update archive download failed before completion.');
    }

    $headers = "User-Agent: PHP-Gallery-CMS/" . \Gallery\Core\cms_current_version() . "\r\nCache-Control: no-cache\r\n";
    if ($offset > 0) {
        $headers .= 'Range: bytes=' . $offset . "-\r\n";
        $ifRange = trim((string) ($job['checkpoints']['archive_etag'] ?? ''));
        if ($ifRange === '') {
            $ifRange = trim((string) ($job['checkpoints']['archive_last_modified'] ?? ''));
        }
        if ($ifRange !== '') {
            $headers .= 'If-Range: ' . $ifRange . "\r\n";
        }
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $ioTimeout,
            'header' => $headers,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
        ],
    ]);
    $remote = @fopen($url, 'rb', false, $context);
    if ($remote === false) {
        throw new RuntimeException('Update archive download could not be opened.');
    }
    $metadata = stream_get_meta_data($remote);
    $status = 0;
    $contentLength = 0;
    $contentRangeTotal = 0;
    $responseEtag = '';
    $responseLastModified = '';
    $rangeHonored = $offset === 0;
    foreach ((array) ($metadata['wrapper_data'] ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', (string) $line, $match) === 1) {
            $status = (int) $match[1];
            $contentLength = 0;
            $contentRangeTotal = 0;
            $responseEtag = '';
            $responseLastModified = '';
        } elseif (stripos((string) $line, 'Content-Range:') === 0) {
            $rangeHonored = true;
            if (preg_match('#/([0-9]+)\s*$#', trim((string) $line), $rangeMatch) === 1) {
                $contentRangeTotal = (int) $rangeMatch[1];
            }
        } elseif (stripos((string) $line, 'Content-Length:') === 0) {
            $contentLength = (int) trim(substr((string) $line, strlen('Content-Length:')));
        } elseif (stripos((string) $line, 'ETag:') === 0) {
            $responseEtag = trim(substr((string) $line, strlen('ETag:')));
        } elseif (stripos((string) $line, 'Last-Modified:') === 0) {
            $responseLastModified = trim(substr((string) $line, strlen('Last-Modified:')));
        }
    }
    if ($status >= 400) {
        fclose($remote);
        throw new RuntimeException('Update archive HTTP request failed.');
    }

    $local = fopen($archivePath, $offset > 0 && $rangeHonored ? 'ab' : 'wb');
    if ($local === false) {
        fclose($remote);
        throw new RuntimeException('Could not open update archive destination.');
    }
    if ($offset > 0 && !$rangeHonored) {
        $offset = 0;
    }

    $complete = false;
    while (application_update_budget_allows($budget, 0.6)) {
        $chunk = fread($remote, 1024 * 256);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            if (feof($remote)) {
                $complete = true;
            }
            break;
        }
        if (fwrite($local, $chunk) === false) {
            fclose($local);
            fclose($remote);
            throw new RuntimeException('Could not write update archive chunk.');
        }
    }
    fflush($local);
    fclose($local);
    fclose($remote);

    $size = is_file($archivePath) ? (int) filesize($archivePath) : 0;
    if ($responseEtag !== '') {
        $job['checkpoints']['archive_etag'] = $responseEtag;
    }
    if ($responseLastModified !== '') {
        $job['checkpoints']['archive_last_modified'] = $responseLastModified;
    }
    $expectedBytes = $contentRangeTotal > 0 ? $contentRangeTotal : ($contentLength > 0 ? $offset + $contentLength : 0);
    if ($expectedBytes > 0) {
        $job['checkpoints']['archive_expected_bytes'] = $expectedBytes;
    }
    $job['progress'] = [
        'current' => $size,
        'total' => (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0),
        'message' => 'Downloading update package.',
        'unit' => 'bytes',
    ];
    application_update_save_job($job);
    if ($complete) {
        $expected = (int) ($job['checkpoints']['archive_expected_bytes'] ?? 0);
        if ($expected > 0 && $size !== $expected) {
            throw new RuntimeException('Update archive download completed with an unexpected size.');
        }
    }
    return $complete;
}

/**
 * Normalize a ZIP entry path and reject traversal, absolute paths, and drive paths.
 *
 * @param string $entry ZIP entry name.
 * @return string Safe normalized relative path.
 */
function application_update_safe_zip_entry(string $entry): string
{
    $normalized = str_replace('\\', '/', $entry);
    $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
    if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized)) {
        throw new RuntimeException('Update archive contains an unsafe path.');
    }
    foreach (explode('/', trim($normalized, '/')) as $segment) {
        if ($segment === '..' || $segment === '.') {
            throw new RuntimeException('Update archive contains an unsafe path.');
        }
    }
    return $normalized;
}

/**
 * Return true when a ZIP entry is a Unix symbolic link.
 *
 * @param ZipArchive $zip Open archive.
 * @param int $index Entry index.
 * @return bool True when the archive metadata identifies a symlink.
 */
function application_update_zip_entry_is_symlink(ZipArchive $zip, int $index): bool
{
    $opsys = 0;
    $attributes = 0;
    if (!method_exists($zip, 'getExternalAttributesIndex') || !$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
        return false;
    }
    $mode = ($attributes >> 16) & 0xFFFF;
    return ($mode & 0170000) === 0120000;
}

/**
 * Validate archive structure in bounded batches before extraction starts.
 *
 * The ZIP directory itself can contain thousands of entries. Persisting the next
 * central-directory index prevents a deliberately large but still permitted
 * archive from turning validation into one long shared-hosting request.
 *
 * @param array $job Job state, updated by reference.
 * @param ?array $budget Optional worker budget. Null still caps one call by entry count.
 * @return bool True when every archive entry has been validated.
 */
function application_update_job_validate_archive(array &$job, ?array $budget = null): bool
{
    $archivePath = application_update_job_dir((string) $job['id']) . '/package.zip';
    if (!is_file($archivePath) || filesize($archivePath) === 0) {
        throw new RuntimeException('Downloaded update archive is empty.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be opened.');
    }

    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 20000) {
            throw new RuntimeException('Downloaded update archive has an invalid entry count.');
        }

        $index = (int) ($job['checkpoints']['archive_validate_index'] ?? 0);
        $uncompressed = (int) ($job['checkpoints']['archive_uncompressed_bytes'] ?? 0);
        if ($index < 0 || $index > $zip->numFiles) {
            throw new RuntimeException('Update archive validation checkpoint is invalid.');
        }
        $processed = 0;
        while ($index < $zip->numFiles && $processed < 500 && ($budget === null || application_update_budget_allows($budget, 0.7))) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException('Downloaded update archive contains an unreadable entry.');
            }
            application_update_safe_zip_entry((string) $stat['name']);
            if (application_update_zip_entry_is_symlink($zip, $index)) {
                throw new RuntimeException('Downloaded update archive contains unsupported symbolic links.');
            }
            $size = max(0, (int) ($stat['size'] ?? 0));
            // Application releases should contain code/assets, not giant opaque blobs.
            // This cap also bounds the one-entry extraction unit on slow hosting.
            if ($size > 32 * 1024 * 1024) {
                throw new RuntimeException('Downloaded update archive contains an oversized file.');
            }
            $uncompressed += $size;
            if ($uncompressed > 512 * 1024 * 1024) {
                throw new RuntimeException('Downloaded update archive expands beyond the safe size limit.');
            }

            $index++;
            $processed++;
            $job['checkpoints']['archive_validate_index'] = $index;
            $job['checkpoints']['archive_uncompressed_bytes'] = $uncompressed;
            $job['progress'] = [
                'current' => $index,
                'total' => $zip->numFiles,
                'message' => 'Validating update package structure.',
                'unit' => 'entries',
            ];
            application_update_save_job($job);
        }

        if ($index >= $zip->numFiles) {
            $job['checkpoints']['archive_entries'] = $zip->numFiles;
            $job['checkpoints']['archive_uncompressed_bytes'] = $uncompressed;
            $job['checkpoints']['extract_index'] = (int) ($job['checkpoints']['extract_index'] ?? 0);
            $job['progress'] = [
                'current' => $zip->numFiles,
                'total' => $zip->numFiles,
                'message' => 'Update package structure validated.',
                'unit' => 'entries',
            ];
            application_update_save_job($job);
            return true;
        }

        return false;
    } finally {
        $zip->close();
    }
}

/**
 * Extract a bounded number of ZIP entries and checkpoint the next entry index.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when extraction completed.
 */
function application_update_job_extract_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $archivePath = $jobDir . '/package.zip';
    $extractDir = $jobDir . '/extract';
    application_update_ensure_dir($extractDir);

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be reopened for extraction.');
    }
    $index = (int) ($job['checkpoints']['extract_index'] ?? 0);
    $processed = 0;
    try {
        while ($index < $zip->numFiles && $processed < 80 && application_update_budget_allows($budget, 0.7)) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new RuntimeException('Downloaded update archive contains an unreadable entry.');
            }
            $entry = application_update_safe_zip_entry((string) $stat['name']);
            $target = $extractDir . '/' . str_replace('/', DIRECTORY_SEPARATOR, $entry);
            if (str_ends_with($entry, '/')) {
                application_update_ensure_dir($target);
            } else {
                application_update_ensure_dir(dirname($target));
                $input = $zip->getStream((string) $stat['name']);
                if ($input === false) {
                    throw new RuntimeException('Could not read an update archive entry.');
                }
                $temporary = $target . '.part';
                $output = fopen($temporary, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new RuntimeException('Could not create an extracted update file.');
                }
                $copied = stream_copy_to_stream($input, $output);
                fclose($output);
                fclose($input);
                if ($copied === false || $copied !== (int) ($stat['size'] ?? $copied)) {
                    @unlink($temporary);
                    throw new RuntimeException('Extracted update file failed size verification.');
                }
                if (!rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('Could not commit an extracted update file.');
                }
            }
            $index++;
            $processed++;
            $job['checkpoints']['extract_index'] = $index;
            $job['progress'] = ['current' => $index, 'total' => $zip->numFiles, 'message' => 'Extracting update package.', 'unit' => 'entries'];
            application_update_save_job($job);
        }
    } finally {
        $zip->close();
    }
    return $index >= (int) ($job['checkpoints']['archive_entries'] ?? $index);
}

/**
 * Calculate the normalized-text SHA-256 used by app/core-manifest.json.
 *
 * Hashing is streamed so package verification does not need one PHP string as
 * large as the release file. CRLF/CR normalization matches the manifest generator,
 * including a UTF-8 BOM at the beginning of a file.
 *
 * @param string $path File path.
 * @return string Manifest-format hash.
 */
function application_update_manifest_hash(string $path): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read an update file for integrity validation.');
    }
    $context = hash_init('sha256');
    $first = true;
    $carryCarriageReturn = false;
    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Could not read an update file for integrity validation.');
            }
            if ($chunk === '') {
                continue;
            }
            if ($first) {
                $first = false;
                if (str_starts_with($chunk, "\xEF\xBB\xBF")) {
                    $chunk = substr($chunk, 3);
                }
            }
            if ($carryCarriageReturn) {
                $chunk = "\r" . $chunk;
                $carryCarriageReturn = false;
            }
            if ($chunk !== '' && str_ends_with($chunk, "\r")) {
                $chunk = substr($chunk, 0, -1);
                $carryCarriageReturn = true;
            }
            if ($chunk !== '') {
                hash_update($context, str_replace(["\r\n", "\r"], "\n", $chunk));
            }
        }
        if ($carryCarriageReturn) {
            hash_update($context, "\n");
        }
    } finally {
        fclose($handle);
    }
    return 'sha256:' . hash_final($context);
}

/**
 * Validate the extracted package before activation.
 *
 * Core-manifest verification is temporarily disabled. Archive entry validation,
 * source-root validation, updater-managed path filtering, schema preflight, and
 * activation rollback remain enforced by their owning stages.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when the package is ready for activation.
 */
function application_update_job_validate_package_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $sourceRoot = application_update_extracted_root($jobDir . '/extract');
    application_update_assert_source_root($sourceRoot);
    $job['checkpoints']['source_root'] = $sourceRoot;

    $bootstrapVersion = application_update_version_from_local_bootstrap($sourceRoot . '/app/bootstrap.php');
    $job['checkpoints']['validated_version'] = $bootstrapVersion ?? '';
    if ((string) ($job['operation'] ?? '') === 'stable_update') {
        $validatedVersion = (string) $job['checkpoints']['validated_version'];
        if ($validatedVersion === '' || version_compare($validatedVersion, \Gallery\Core\cms_current_version(), '<=')) {
            throw new RuntimeException('No newer version is available in the downloaded stable package.');
        }
        $targetVersion = application_update_normalize_version((string) ($job['parameters']['target_version'] ?? ''));
        if ($targetVersion !== null && version_compare($validatedVersion, $targetVersion, '<')) {
            throw new RuntimeException('Downloaded stable package is older than the version selected for this job.');
        }
    }

    unset($job['checkpoints']['manifest_files'], $job['checkpoints']['verify_index']);
    $job['progress'] = ['current' => 1, 'total' => 1, 'message' => 'Package structure validated.', 'unit' => 'step'];
    application_update_save_job($job);
    return true;
}

/**
 * Return incoming release files in deterministic activation order.
 *
 * @param string $sourceRoot Extracted source root.
 * @return array<int,string> Project-relative files.
 */
function application_update_release_files(string $sourceRoot): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (application_update_path_is_protected($relative)
            || !application_update_path_is_managed_by_updater($relative, false)
            || in_array(basename($relative), ['.DS_Store', 'Thumbs.db'], true)) {
            continue;
        }
        $files[] = $relative;
    }

    usort($files, static function (string $left, string $right): int {
        $priority = static function (string $path): int {
            return match ($path) {
                'index.php' => 50,
                'public/index.php' => 40,
                'app/bootstrap.php' => 30,
                'app/services/updates.php' => 20,
                default => 0,
            };
        };
        $comparison = $priority($left) <=> $priority($right);
        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });
    return $files;
}

/**
 * Filter an incoming release to files that would actually change the active tree.
 *
 * Hash comparisons happen before backup/activation so the activation critical section
 * contains only new or changed files. Managed symbolic-link destinations are rejected
 * rather than followed or replaced implicitly.
 *
 * @param string $sourceRoot Extracted or rollback source root.
 * @param string $destinationRoot Active project root.
 * @param array<int,string> $files Incoming project-relative files.
 * @return array<int,string> New or changed files, preserving activation order.
 */
function application_update_changed_release_files(string $sourceRoot, string $destinationRoot, array $files): array
{
    $changed = [];
    foreach ($files as $relative) {
        $relative = (string) $relative;
        $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('Prepared update source file is missing or unsafe.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('Updater refuses symbolic links in managed activation paths.');
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Update cannot replace an active directory with a file.');
        }
        if (is_file($destination)) {
            $sourceSize = filesize($source);
            $destinationSize = filesize($destination);
            if ($sourceSize !== false && $destinationSize !== false && $sourceSize === $destinationSize) {
                $sourceHash = hash_file('sha256', $source);
                $destinationHash = hash_file('sha256', $destination);
                if (is_string($sourceHash) && is_string($destinationHash) && hash_equals($sourceHash, $destinationHash)) {
                    continue;
                }
            }
        }
        $changed[] = $relative;
    }
    return $changed;
}


/**
 * Return obsolete managed destination paths that the incoming release does not contain.
 *
 * Protected directories are pruned before recursive traversal. Normal updates inspect
 * only updater-owned application roots instead of walking galleries/cache/custom data.
 * Clean reinstall may inspect additional root entries, but still never descends into
 * protected directories.
 *
 * @param string $sourceRoot Extracted source root.
 * @param string $destinationRoot Active project root.
 * @param bool $cleanUnexpectedFiles Whether clean reinstall owns all non-protected paths.
 * @return array<int,string> Paths ordered deepest first.
 */
function application_update_obsolete_paths(string $sourceRoot, string $destinationRoot, bool $cleanUnexpectedFiles): array
{
    $removed = [];
    $roots = [];
    if ($cleanUnexpectedFiles) {
        foreach (new FilesystemIterator($destinationRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            $relative = str_replace('\\', '/', $entry->getFilename());
            if ($relative !== '' && !application_update_path_is_protected($relative)) {
                $roots[] = $entry->getPathname();
            }
        }
    } else {
        foreach (['app', 'public', 'database/migrations', 'scripts'] as $relativeRoot) {
            $path = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (file_exists($path) || is_link($path)) {
                $roots[] = $path;
            }
        }
        foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php'] as $relativeFile) {
            $path = $destinationRoot . '/' . $relativeFile;
            if (file_exists($path) || is_link($path)) {
                $roots[] = $path;
            }
        }
    }

    // Exact application-owned server-policy files remain updater-managed even when
    // their parent directories are protected from recursive cleanup. Add them for
    // both normal updates and clean reinstalls without traversing gallery/cache data.
    foreach (application_update_managed_server_policy_files() as $relativeFile) {
        $path = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (file_exists($path) || is_link($path)) {
            $roots[] = $path;
        }
    }

    $roots = array_values(array_unique($roots));
    foreach ($roots as $rootPath) {
        $relativeRoot = str_replace('\\', '/', substr($rootPath, strlen($destinationRoot) + 1));
        if ($relativeRoot === '' || application_update_path_is_protected($relativeRoot)) {
            continue;
        }
        if (is_link($rootPath)) {
            continue;
        }
        if (is_file($rootPath)) {
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (!file_exists($source) && application_update_path_is_managed_by_updater($relativeRoot, $cleanUnexpectedFiles)) {
                $removed[] = $relativeRoot;
            }
            continue;
        }
        if (!is_dir($rootPath)) {
            continue;
        }

        $directory = new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function ($current) use ($destinationRoot): bool {
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($destinationRoot) + 1));
                return $relative !== '' && !$current->isLink() && !application_update_path_is_protected($relative);
            }
        );
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($destinationRoot) + 1));
            if ($relative === '' || !application_update_path_is_managed_by_updater($relative, $cleanUnexpectedFiles)) {
                continue;
            }
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!file_exists($source)) {
                $removed[] = $relative;
            }
        }

        if (application_update_path_is_managed_by_updater($relativeRoot, $cleanUnexpectedFiles)) {
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (!file_exists($source)) {
                $removed[] = $relativeRoot;
            }
        }
    }

    return array_values(array_unique($removed));
}

/**
 * Build the bounded rollback-item list for one activation plan.
 *
 * Obsolete directories do not need recursive snapshot copies because their files
 * are already present as individual child entries in the obsolete-path plan.
 * Empty directories contain no rollback data. A file-vs-directory replacement is
 * rejected here before backup or activation begins.
 *
 * @param string $root Active project root.
 * @param array<int,string> $files Incoming activation files.
 * @param array<int,string> $obsolete Obsolete destination paths.
 * @return array<int,string> File-level paths to snapshot or mark as newly created.
 */
function application_update_backup_items_for_plan(string $root, array $files, array $obsolete): array
{
    $items = [];
    foreach ($files as $relative) {
        $relative = (string) $relative;
        $destination = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($destination)) {
            throw new RuntimeException('Updater refuses symbolic links in managed activation paths.');
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Update cannot replace an active directory with a file.');
        }
        $items[$relative] = true;
    }
    foreach ($obsolete as $relative) {
        $relative = (string) $relative;
        $destination = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($destination) || is_link($destination)) {
            $items[$relative] = true;
        }
    }
    return array_keys($items);
}

/**
 * Build and persist the complete activation plan before active files are touched.
 *
 * @param array $job Job state, updated by reference.
 */
function application_update_job_build_plan(array &$job): void
{
    $operation = (string) ($job['operation'] ?? '');
    if ($operation === 'rollback') {
        $sourceJobId = (string) ($job['parameters']['source_job_id'] ?? '');
        $sourceJobDir = application_update_job_dir($sourceJobId);
        $metadata = application_update_read_json($sourceJobDir . '/rollback/metadata.json');
        if ($metadata === [] || !is_dir($sourceJobDir . '/rollback/original')) {
            throw new RuntimeException('Rollback source snapshot is incomplete.');
        }
        $sourceRoot = $sourceJobDir . '/rollback/original';
        $job['checkpoints']['source_root'] = $sourceRoot;
        $files = application_update_changed_release_files(
            $sourceRoot,
            application_update_project_root(),
            application_update_release_files($sourceRoot)
        );
        $obsolete = [];
        foreach ((array) ($metadata['created_paths'] ?? []) as $relative) {
            $relative = application_update_safe_zip_entry((string) $relative);
            if ($relative !== '' && !application_update_path_is_protected($relative)) {
                $obsolete[] = $relative;
            }
        }
        $job['checkpoints']['activation_files'] = array_values(array_unique($files));
        $job['checkpoints']['obsolete_paths'] = array_values(array_unique($obsolete));
        $job['checkpoints']['stage_index'] = (int) ($job['checkpoints']['stage_index'] ?? 0);
        $job['checkpoints']['backup_index'] = (int) ($job['checkpoints']['backup_index'] ?? 0);
        $job['checkpoints']['backup_items'] = application_update_backup_items_for_plan(application_update_project_root(), $files, $obsolete);
        $job['checkpoints']['rollback_source_job_id'] = $sourceJobId;
        $job['progress'] = ['current' => 0, 'total' => count($files), 'message' => 'Rollback activation plan prepared.', 'unit' => 'files'];
        application_update_assert_activation_schema_known('application_update.job_rollback_activation');
        application_update_save_job($job);
        return;
    }

    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    if ($sourceRoot === '') {
        throw new RuntimeException('Validated update source is missing.');
    }
    $root = application_update_project_root();
    application_update_assert_activation_schema_known('application_update.job_activation');
    $releaseFiles = application_update_release_files($sourceRoot);
    if ($releaseFiles === []) {
        throw new RuntimeException('Validated update package contains no installable files.');
    }
    $files = application_update_changed_release_files($sourceRoot, $root, $releaseFiles);
    $cleanUnexpected = !empty($job['parameters']['clean_unexpected_files']);
    $obsolete = application_update_obsolete_paths($sourceRoot, $root, $cleanUnexpected);

    $job['checkpoints']['activation_files'] = $files;
    $job['checkpoints']['obsolete_paths'] = $obsolete;
    $job['checkpoints']['stage_index'] = (int) ($job['checkpoints']['stage_index'] ?? 0);
    $job['checkpoints']['backup_index'] = (int) ($job['checkpoints']['backup_index'] ?? 0);
    $job['checkpoints']['backup_items'] = application_update_backup_items_for_plan(application_update_project_root(), $files, $obsolete);
    $job['progress'] = ['current' => 0, 'total' => count($files), 'message' => 'Activation plan prepared.', 'unit' => 'files'];
    application_update_save_job($job);
}

/**
 * Copy and verify release files into the job ready tree in bounded batches.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when every activation file is staged.
 */
function application_update_job_stage_files_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    $files = (array) ($job['checkpoints']['activation_files'] ?? []);
    $index = (int) ($job['checkpoints']['stage_index'] ?? 0);
    $processed = 0;

    while ($index < count($files) && $processed < 40 && application_update_budget_allows($budget, 0.7)) {
        $relative = (string) $files[$index];
        $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $jobDir . '/ready/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        application_update_ensure_dir(dirname($destination));
        if (!is_file($destination) || filesize($destination) !== filesize($source) || !hash_equals(hash_file('sha256', $source), hash_file('sha256', $destination))) {
            $temporary = $destination . '.part';
            if (!copy($source, $temporary)) {
                throw new RuntimeException('Could not stage a prepared update file.');
            }
            if (!hash_equals(hash_file('sha256', $source), hash_file('sha256', $temporary))) {
                @unlink($temporary);
                throw new RuntimeException('Prepared update file failed integrity verification.');
            }
            if (!rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('Could not commit a prepared update file.');
            }
        }
        $index++;
        $processed++;
        $job['checkpoints']['stage_index'] = $index;
        $job['progress'] = ['current' => $index, 'total' => count($files), 'message' => 'Staging release files outside the active installation.', 'unit' => 'files'];
        application_update_save_job($job);
    }
    return $index >= count($files);
}

/**
 * Copy one existing active path into the rollback snapshot.
 *
 * Directories are represented by their contained files; created-path metadata
 * records paths that did not exist before activation so rollback can remove them.
 *
 * @param string $root Active project root.
 * @param string $backupRoot Rollback snapshot root.
 * @param string $relative Project-relative path.
 * @return bool True when the path existed before activation.
 */
function application_update_backup_path_to_directory(string $root, string $backupRoot, string $relative): bool
{
    $source = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!file_exists($source) && !is_link($source)) {
        return false;
    }
    if (is_link($source)) {
        throw new RuntimeException('Updater refuses to back up symbolic links in managed paths.');
    }
    if (is_file($source)) {
        $size = filesize($source);
        if ($size === false || $size > 128 * 1024 * 1024) {
            throw new RuntimeException('Managed active file is too large for a bounded rollback snapshot.');
        }
        $destination = $backupRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        application_update_ensure_dir(dirname($destination));
        if (!is_file($destination)) {
            if (!copy($source, $destination)) {
                throw new RuntimeException('Could not prepare rollback snapshot file.');
            }
        }
        return true;
    }
    if (is_dir($source)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Updater refuses symbolic links in managed rollback paths.');
            }
            $suffix = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            $destination = $backupRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative . '/' . $suffix);
            if ($item->isDir()) {
                application_update_ensure_dir($destination);
            } else {
                application_update_ensure_dir(dirname($destination));
                if (!is_file($destination) && !copy($item->getPathname(), $destination)) {
                    throw new RuntimeException('Could not prepare rollback snapshot directory.');
                }
            }
        }
        return true;
    }
    return false;
}

/**
 * Build the durable rollback snapshot in bounded batches before activation.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when rollback data is complete and closed on disk.
 */
function application_update_job_backup_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $root = application_update_project_root();
    $backupRoot = $jobDir . '/rollback/original';
    $items = (array) ($job['checkpoints']['backup_items'] ?? []);
    $index = (int) ($job['checkpoints']['backup_index'] ?? 0);
    $createdPaths = (array) ($job['checkpoints']['created_paths'] ?? []);
    $processed = 0;

    while ($index < count($items) && $processed < 30 && application_update_budget_allows($budget, 0.8)) {
        $relative = (string) $items[$index];
        $existed = application_update_backup_path_to_directory($root, $backupRoot, $relative);
        if (!$existed) {
            $createdPaths[$relative] = true;
        }
        $index++;
        $processed++;
        $job['checkpoints']['backup_index'] = $index;
        $job['checkpoints']['created_paths'] = $createdPaths;
        $job['progress'] = ['current' => $index, 'total' => count($items), 'message' => 'Preparing durable rollback snapshot.', 'unit' => 'paths'];
        application_update_save_job($job);
    }

    if ($index >= count($items)) {
        $metadata = [
            'job_id' => (string) $job['id'],
            'created_at' => time(),
            'created_paths' => array_keys($createdPaths),
            'activation_files' => array_values((array) ($job['checkpoints']['activation_files'] ?? [])),
            'obsolete_paths' => array_values((array) ($job['checkpoints']['obsolete_paths'] ?? [])),
            'settings_before' => [
                'channel' => (string) app_setting('application_update_channel', 'stable'),
                'beta_commit' => (string) app_setting('application_update_beta_commit', ''),
                'beta_backup_path' => (string) app_setting('application_update_beta_backup_path', ''),
            ],
        ];
        application_update_write_json_atomic($jobDir . '/rollback/metadata.json', $metadata);
        $job['checkpoints']['backup_complete'] = true;
        application_update_save_job($job);
        return true;
    }
    return false;
}

/**
 * Return the durable activation marker path in the existing updater workspace.
 */
function application_update_activation_gate_path(): string
{
    return application_update_jobs_root() . '/activation.json';
}

/**
 * Persist the dependency-free activation marker before any managed production file is replaced.
 *
 * Replaying the same job keeps the existing marker. A marker owned by another
 * job is never overwritten because doing so could reopen a partially activated
 * release without proving that the earlier job reached activation_complete.
 *
 * @param array<string,mixed> $job Durable update job state.
 */
function application_update_activation_gate_begin(array $job): void
{
    $jobId = (string) ($job['id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException('Update activation job identifier is missing.');
    }

    $path = application_update_activation_gate_path();
    $markerExists = is_file($path);
    $existing = application_update_read_json($path);
    if ($markerExists && $existing === []) {
        throw new RuntimeException('Existing update activation gate state is unreadable.');
    }
    if ($existing !== []) {
        $existingJobId = (string) ($existing['job_id'] ?? '');
        if ($existingJobId === '' || !hash_equals($existingJobId, $jobId)) {
            throw new RuntimeException('Another update activation gate is already active.');
        }
    }

    $payload = [
        'schema' => 1,
        'job_id' => $jobId,
        'trigger' => (string) ($job['trigger'] ?? ''),
        'started_at' => (int) ($existing['started_at'] ?? time()),
        'updated_at' => time(),
    ];

    // The session name is not an authentication secret. Persisting it lets the early
    // gate recognize a pre-existing authenticated Admin session even when an automatic
    // update was initiated by an anonymous request. The session id itself is never stored.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionName = session_name();
        if ($sessionName !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sessionName) === 1) {
            $payload['admin_session_name'] = $sessionName;
        }
    } elseif (isset($existing['admin_session_name'])) {
        $payload['admin_session_name'] = (string) $existing['admin_session_name'];
    }

    application_update_write_json_atomic($path, $payload);
}

/**
 * Clear the activation marker only for the job that durably completed activation.
 *
 * Failure to unlink is intentionally non-fatal. The early gate independently
 * verifies job.json activation_complete and retries stale-marker removal before
 * allowing normal public traffic.
 */
function application_update_activation_gate_clear(string $jobId): void
{
    $path = application_update_activation_gate_path();
    $marker = application_update_read_json($path);
    if ($marker === [] || !hash_equals((string) ($marker['job_id'] ?? ''), $jobId)) {
        return;
    }
    @unlink($path);
}

/**
 * Verify every pre-activation prerequisite immediately before the critical section.
 *
 * @param array $job Job state.
 */
function application_update_job_assert_ready(array $job): void
{
    if (empty($job['checkpoints']['backup_complete'])) {
        throw new RuntimeException('Rollback snapshot is not complete.');
    }
    $jobDir = application_update_job_dir((string) $job['id']);
    if (!is_file($jobDir . '/rollback/metadata.json')) {
        throw new RuntimeException('Rollback metadata is missing.');
    }
    foreach ((array) ($job['checkpoints']['activation_files'] ?? []) as $relative) {
        $ready = $jobDir . '/ready/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
        if (!is_file($ready)) {
            throw new RuntimeException('Prepared activation file is missing.');
        }
    }
    application_update_assert_activation_schema_known('application_update.job_activation_ready');
}

/**
 * Return the priority used for the activation critical section.
 *
 * Dependencies are replaced before bootstrap and public entry points. The
 * section deliberately does not yield between files. If the host kills PHP,
 * replay detects already-committed files by hash and completes the remaining work.
 *
 * @param string $path Relative path.
 * @return int Sort priority.
 */
function application_update_activation_priority(string $path): int
{
    return match ($path) {
        'index.php' => 50,
        'install.php', 'reset.php' => 45,
        'public/index.php' => 40,
        'app/early_runtime.php' => 35,
        'app/bootstrap.php' => 30,
        'app/services/updates.php' => 20,
        default => 0,
    };
}

/**
 * Activate the fully prepared release in one minimally bounded retry-safe critical section.
 *
 * No remote I/O, extraction, hashing of the complete package, backup construction,
 * or migration execution happens here. Each file replacement uses a sibling
 * temporary file plus rename(), and replay treats a destination matching the
 * prepared release hash as already committed.
 *
 * @param array $job Job state, updated by reference.
 */
function application_update_job_activate(array &$job): void
{
    application_update_job_assert_ready($job);
    application_update_activation_gate_begin($job);
    $jobDir = application_update_job_dir((string) $job['id']);
    $root = application_update_project_root();
    $files = array_values((array) ($job['checkpoints']['activation_files'] ?? []));
    usort($files, static function (string $left, string $right): int {
        $comparison = application_update_activation_priority($left) <=> application_update_activation_priority($right);
        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });

    $activated = 0;
    foreach ($files as $relative) {
        $ready = $jobDir . '/ready/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $readyHash = hash_file('sha256', $ready);
        if ($readyHash === false) {
            throw new RuntimeException('Could not verify prepared activation file.');
        }
        if (is_file($destination)) {
            $destinationHash = hash_file('sha256', $destination);
            if ($destinationHash !== false && hash_equals($readyHash, $destinationHash)) {
                $activated++;
                continue;
            }
        } elseif (is_dir($destination)) {
            throw new RuntimeException('Cannot replace an active directory with a file.');
        }

        application_update_ensure_dir(dirname($destination));
        $temporary = dirname($destination) . '/.php-gallery-activate-' . bin2hex(random_bytes(6)) . '.tmp';
        if (!copy($ready, $temporary)) {
            throw new RuntimeException('Could not prepare active file replacement.');
        }
        if (!hash_equals($readyHash, (string) hash_file('sha256', $temporary))) {
            @unlink($temporary);
            throw new RuntimeException('Active file replacement failed integrity verification.');
        }
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Could not atomically replace an active application file.');
        }
        application_update_invalidate_opcache_for_path($destination);
        $activated++;
    }

    foreach ((array) ($job['checkpoints']['obsolete_paths'] ?? []) as $relative) {
        $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
        if (file_exists($path) || is_link($path)) {
            application_update_remove_path($path);
        }
    }

    $job['checkpoints']['activation_complete'] = true;
    $job['checkpoints']['activated_files'] = $activated;
    $job['progress'] = ['current' => $activated, 'total' => count($files), 'message' => 'Prepared release activated.', 'unit' => 'files'];
    application_update_save_job($job);
    application_update_activation_gate_clear((string) $job['id']);
}

/**
 * Run at most one migration file for this worker invocation.
 *
 * schema_migrations is the durable checkpoint. If PHP dies inside a migration,
 * that migration may replay on the next request. Migration definitions therefore
 * remain required to tolerate replay, while completed migration files never rerun.
 *
 * @param array $job Job state, updated by reference.
 * @return bool True when no migrations remain.
 */
function application_update_job_migrate_slice(array &$job): bool
{
    if ((string) ($job['operation'] ?? '') === 'rollback') {
        $job['progress'] = ['current' => 1, 'total' => 1, 'message' => 'Rollback does not reverse database migrations.', 'unit' => 'migration policy'];
        application_update_save_job($job);
        return true;
    }
    $result = run_migrations_bounded(1);
    $ran = array_values((array) ($result['ran'] ?? []));
    $allRan = array_values(array_merge((array) ($job['result']['migrations'] ?? []), $ran));
    $job['result']['migrations'] = array_values(array_unique($allRan));
    $job['progress'] = [
        'current' => count($job['result']['migrations']),
        'total' => count($job['result']['migrations']) + (int) ($result['remaining'] ?? 0),
        'message' => 'Applying database migrations.',
        'unit' => 'migrations',
    ];
    application_update_save_job($job);
    return (int) ($result['remaining'] ?? 0) === 0;
}

/**
 * Finalize application settings only after files and migrations are complete.
 *
 * @param array $job Job state, updated by reference.
 */
function application_update_job_finalize(array &$job): void
{
    $root = application_update_project_root();
    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    $operation = (string) ($job['operation'] ?? '');

    // Defense in depth: after the normal activation plan completes, verify the exact
    // application-owned Apache policy files against the same validated release. This
    // is normally a hash-only no-op, but it also repairs a policy file if a future
    // planner regression accidentally omits one while preserving rollback metadata.
    $serverPolicyReconciliation = $operation === 'rollback'
        ? [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'missing_from_release' => 0,
            'skipped_customized' => 0,
            'updated_files' => [],
            'skipped_customized_files' => [],
        ]
        : application_update_reconcile_server_policy_files_for_job($job, false);

    application_update_invalidate_opcache($root, $sourceRoot);
    application_patch_notes_clear_cache();
    delete_app_settings(['application_update_check_cache', 'application_update_check_status_json', 'application_update_check_cached_at']);

    if ($operation === 'rollback') {
        $sourceJobId = (string) ($job['parameters']['source_job_id'] ?? '');
        $metadata = application_update_read_json(application_update_job_dir($sourceJobId) . '/rollback/metadata.json');
        $settings = (array) ($metadata['settings_before'] ?? []);
        $channel = (string) ($settings['channel'] ?? 'stable');
        $commit = (string) ($settings['beta_commit'] ?? '');
        if ($channel === 'beta' && $commit !== '') {
            set_app_setting('application_update_channel', 'beta');
            set_app_setting('application_update_beta_commit', $commit);
            set_app_setting('application_update_beta_backup_path', (string) ($settings['beta_backup_path'] ?? ''));
        } else {
            delete_app_settings(['application_update_channel', 'application_update_beta_commit', 'application_update_beta_backup_path']);
        }
    } elseif ($operation === 'beta_install') {
        $commit = (string) ($job['parameters']['commit'] ?? '');
        set_app_setting('application_update_channel', 'beta');
        set_app_setting('application_update_beta_commit', $commit);
        set_app_setting('application_update_beta_backup_path', 'cache/updates/jobs/' . (string) $job['id'] . '/rollback');
    } else {
        delete_app_settings(['application_update_channel', 'application_update_beta_commit', 'application_update_beta_backup_path']);
    }

    $version = application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? (string) ($job['checkpoints']['validated_version'] ?? '');
    $reportedVersion = $operation === 'beta_install' ? (string) ($job['parameters']['commit'] ?? $version) : $version;

    // Request-local metadata caches are cheap to invalidate immediately. Persistent
    // version-sensitive cache directories are atomically moved out of their canonical
    // locations and physically deleted later by the bounded cleanup stage.
    $runtimeCacheActions = [];
    foreach ([
        'translation_runtime' => __NAMESPACE__ . '\\translation_clear_runtime_cache',
        'content_localization_request' => __NAMESPACE__ . '\\content_localization_reset_request_cache',
        'schema_inspection_request' => __NAMESPACE__ . '\\schema_inspection_reset_request_cache',
        'legacy_schema_request' => __NAMESPACE__ . '\\db_schema_helper_reset_request_cache',
        'smart_gallery_graph_request' => __NAMESPACE__ . '\\smart_gallery_graph_cache_clear',
        'thumbnail_maintenance_summary' => __NAMESPACE__ . '\\thumbnail_maintenance_summary_cache_clear',
        'admin_storage_statistics' => __NAMESPACE__ . '\\admin_storage_statistics_cache_clear',
        'gallery_map_runtime' => __NAMESPACE__ . '\\gallery_map_cache_clear_all',
    ] as $cacheName => $callback) {
        if (!function_exists($callback)) {
            $runtimeCacheActions[$cacheName] = 'unavailable';
            continue;
        }
        try {
            $callback();
            $runtimeCacheActions[$cacheName] = 'cleared';
        } catch (Throwable) {
            $runtimeCacheActions[$cacheName] = 'failed';
        }
    }
    clearstatcache(true);
    $persistentCacheInvalidation = application_update_invalidate_persistent_caches($root, (string) $job['id'], $reportedVersion);

    $job['result'] = array_merge((array) ($job['result'] ?? []), [
        'version' => $reportedVersion,
        'branch' => $operation === 'beta_install' ? 'beta' : ($operation === 'rollback' ? 'rollback' : (string) ($job['parameters']['branch'] ?? 'stable')),
        'rollback_of' => $operation === 'rollback' ? (string) ($job['parameters']['source_job_id'] ?? '') : '',
        'files_copied' => (int) ($job['checkpoints']['activated_files'] ?? 0),
        'removed_paths' => array_values((array) ($job['checkpoints']['obsolete_paths'] ?? [])),
        'removed_count' => count((array) ($job['checkpoints']['obsolete_paths'] ?? [])),
        'backup' => 'cache/updates/jobs/' . (string) $job['id'] . '/rollback',
        'migrations' => array_values((array) ($job['result']['migrations'] ?? [])),
        'server_policy_reconciliation' => $serverPolicyReconciliation,
        'cache_invalidation' => [
            'runtime' => $runtimeCacheActions,
            'persistent' => $persistentCacheInvalidation,
        ],
    ]);
    $job['checkpoints']['finalized'] = true;
    application_update_save_job($job);
}

/**
 * Stage current-job transient payloads for bounded deletion without touching rollback data.
 *
 * @return array{ok:bool,moved:int}
 */
function application_update_stage_current_job_cleanup_artifacts(array $job): array
{
    $jobId = (string) ($job['id'] ?? '');
    $jobDir = application_update_job_dir($jobId);
    $trashRoot = application_update_prune_trash_root(application_update_project_root())
        . DIRECTORY_SEPARATOR . 'current'
        . DIRECTORY_SEPARATOR . $jobId;
    $moved = 0;
    $ok = true;

    foreach (['extract', 'ready'] as $name) {
        $source = $jobDir . DIRECTORY_SEPARATOR . $name;
        $target = $trashRoot . DIRECTORY_SEPARATOR . $name;
        if ((is_dir($source) || is_link($source)) && !application_update_stage_path_for_prune($source, $target)) {
            $ok = false;
        } elseif (file_exists($target) || is_link($target)) {
            $moved++;
        }
    }

    $packageSource = $jobDir . DIRECTORY_SEPARATOR . 'package.zip';
    $packageTarget = $trashRoot . DIRECTORY_SEPARATOR . 'package.zip';
    if (is_file($packageSource) && !application_update_stage_path_for_prune($packageSource, $packageTarget)) {
        $ok = false;
    } elseif (is_file($packageTarget)) {
        $moved++;
    }

    return ['ok' => $ok, 'moved' => $moved];
}

/**
 * Return update-job identifiers that must not be pruned during this cleanup pass.
 *
 * @return array<string,bool> Protected job identifiers.
 */
function application_update_prune_protected_job_ids(array $job): array
{
    $protected = [];
    $currentId = (string) ($job['id'] ?? '');
    if ($currentId !== '') {
        $protected[$currentId] = true;
    }
    $active = application_update_active_job();
    if (is_array($active) && !empty($active['id'])) {
        $protected[(string) $active['id']] = true;
    }
    $last = application_update_last_job();
    if (is_array($last) && !empty($last['id'])) {
        $protected[(string) $last['id']] = true;
    }
    $sourceJobId = (string) ($job['parameters']['source_job_id'] ?? '');
    if ($sourceJobId !== '') {
        $protected[$sourceJobId] = true;
    }
    $betaBackup = trim((string) app_setting('application_update_beta_backup_path', ''));
    if (preg_match('#^cache/updates/jobs/(\d{14}-[a-f0-9]{12})/rollback/?$#', str_replace('\\', '/', $betaBackup), $match) === 1) {
        $protected[$match[1]] = true;
    }
    return $protected;
}

/**
 * Return whether an inactive historical update job is eligible for physical pruning.
 */
function application_update_job_prune_eligible(array $candidate, int $fallbackMtime): bool
{
    $status = (string) ($candidate['status'] ?? '');
    $timestamp = max(
        (int) ($candidate['finished_at'] ?? 0),
        (int) ($candidate['updated_at'] ?? 0),
        $fallbackMtime
    );
    $age = max(0, time() - $timestamp);

    if ($status === 'completed') {
        return true;
    }
    if (in_array($status, ['cancelled', 'failed'], true)) {
        return $age >= 7 * 86400;
    }
    // Preserve recent orphaned/running state for manual recovery. Truly abandoned
    // non-active workspaces are eventually reclaimable instead of living forever.
    return $status === 'running' && $age >= 30 * 86400;
}

/**
 * Atomically stage obsolete updater job directories for bounded deletion.
 *
 * @return array{moved:int,remaining:bool}
 */
function application_update_stage_stale_jobs_for_prune(array $job, int $maxMoves = 20): array
{
    $jobsRoot = application_update_jobs_root() . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($jobsRoot)) {
        return ['moved' => 0, 'remaining' => false];
    }
    $protected = application_update_prune_protected_job_ids($job);
    $trashRoot = application_update_prune_trash_root(application_update_project_root()) . DIRECTORY_SEPARATOR . 'jobs';
    $moved = 0;
    $remaining = false;

    foreach ((array) @scandir($jobsRoot) as $jobId) {
        if (!is_string($jobId) || preg_match('/^\d{14}-[a-f0-9]{12}$/', $jobId) !== 1 || isset($protected[$jobId])) {
            continue;
        }
        $source = $jobsRoot . DIRECTORY_SEPARATOR . $jobId;
        if (!is_dir($source)) {
            continue;
        }
        $candidate = application_update_read_json($source . DIRECTORY_SEPARATOR . 'job.json');
        $fallbackMtime = (int) (@filemtime($source) ?: time());
        if ($candidate !== [] && !application_update_job_prune_eligible($candidate, $fallbackMtime)) {
            continue;
        }
        if ($candidate === [] && time() - $fallbackMtime < 30 * 86400) {
            continue;
        }
        if ($moved >= max(1, $maxMoves)) {
            $remaining = true;
            continue;
        }
        $target = $trashRoot . DIRECTORY_SEPARATOR . $jobId;
        if (application_update_stage_path_for_prune($source, $target)) {
            $moved++;
        } else {
            $remaining = true;
        }
    }

    return ['moved' => $moved, 'remaining' => $remaining];
}

/**
 * Remove transient update artifacts and historical cache debt in bounded slices.
 *
 * Current-package/extraction data and version-sensitive cache paths have already been
 * atomically moved out of live locations. Physical deletion is deliberately capped to
 * a small slice even when the surrounding updater worker has a larger request budget.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker wall-clock budget.
 * @return bool True when the post-update prune queue is empty.
 */
function application_update_job_cleanup(array &$job, array $budget): bool
{
    $current = application_update_stage_current_job_cleanup_artifacts($job);
    $staleJobs = application_update_stage_stale_jobs_for_prune($job, 20);
    $legacy = application_update_stage_legacy_cache_artifacts(application_update_project_root(), 12);

    $remainingBudget = max(0.01, (float) ($budget['deadline'] ?? microtime(true)) - microtime(true) - 0.35);
    $sliceSeconds = min(0.18, $remainingBudget);
    $delete = application_update_delete_tree_slice(
        application_update_prune_trash_root(application_update_project_root()),
        180,
        $sliceSeconds
    );

    $job['checkpoints']['cache_cleanup_removed_entries'] = (int) ($job['checkpoints']['cache_cleanup_removed_entries'] ?? 0) + (int) ($delete['removed'] ?? 0);
    $job['checkpoints']['cache_cleanup_slices'] = (int) ($job['checkpoints']['cache_cleanup_slices'] ?? 0) + 1;
    $job['result']['cache_cleanup'] = [
        'strategy' => 'bounded updater-owned prune slices',
        'removed_entries' => (int) $job['checkpoints']['cache_cleanup_removed_entries'],
        'stale_jobs_staged_this_slice' => (int) ($staleJobs['moved'] ?? 0),
        'legacy_artifacts_staged_this_slice' => (int) ($legacy['moved'] ?? 0),
        'trash_empty' => (bool) ($delete['done'] ?? false),
        'slice_elapsed_ms' => (float) ($delete['elapsed_ms'] ?? 0.0),
        'per_slice_entry_cap' => 180,
        'per_slice_wall_clock_cap_ms' => 180,
        'slices' => (int) $job['checkpoints']['cache_cleanup_slices'],
        'max_slices_before_defer' => 20,
    ];
    $job['progress'] = [
        'current' => (int) $job['checkpoints']['cache_cleanup_removed_entries'],
        'total' => 0,
        'percent' => 99,
        'message' => 'Pruning stale update and application cache artifacts in a bounded slice.',
        'unit' => 'cache entries',
    ];

    $complete = !empty($current['ok'])
        && empty($staleJobs['remaining'])
        && empty($legacy['remaining'])
        && !empty($delete['done']);
    $deferred = !$complete && (int) $job['checkpoints']['cache_cleanup_slices'] >= 20;
    if ($complete || $deferred) {
        $job['checkpoints']['cleanup_complete'] = true;
        $job['result']['cache_cleanup']['completed'] = true;
        $job['result']['cache_cleanup']['deferred_remaining_trash'] = $deferred;
    }
    if ($deferred) {
        $complete = true;
    }
    application_update_save_job($job);
    return $complete;
}

/**
 * Advance one update job within a bounded normal-work slice.
 *
 * Activation is the only intentionally non-yielding stage. It consists only of
 * local prepared file replacements and deletions, with rollback data already
 * durable. A host termination during activation is recovered by replaying that
 * same stage on the next worker invocation.
 *
 * @param string $jobId Job identifier.
 * @param float $budgetSeconds Preferred normal-work budget.
 * @return array<string,mixed> Safe job state.
 */
function application_update_process_job(string $jobId, float $budgetSeconds = 8.0): array
{
    $jobDir = application_update_job_dir($jobId);
    $lock = application_update_acquire_lock($jobDir . '/worker.lock', 30);
    if ($lock === null) {
        return application_update_job_public_state(application_update_load_job($jobId));
    }

    try {
        $job = application_update_load_job($jobId);
        if (application_update_job_terminal($job)) {
            return application_update_job_public_state($job);
        }
        $active = application_update_active_job();
        if ($active === null || !hash_equals((string) ($active['id'] ?? ''), $jobId)) {
            // A non-active job must never mutate application files concurrently
            // with the globally active update, even if its id is addressed directly.
            return application_update_job_public_state($job);
        }
        if ((string) ($job['status'] ?? '') === 'failed') {
            // Caught failures require application_update_retry_job() so package-stage
            // retries can discard untrusted partial artifacts before execution resumes.
            return application_update_job_public_state($job);
        }
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        application_update_save_job($job);
        $budget = application_update_time_budget($budgetSeconds);

        while (application_update_budget_allows($budget, 0.75)) {
            $stage = (string) ($job['stage'] ?? 'download');
            $next = null;

            if ($stage === 'download') {
                if (!application_update_job_download_slice($job, $budget)) {
                    break;
                }
                $next = 'archive_validate';
            } elseif ($stage === 'archive_validate') {
                if (!application_update_job_validate_archive($job, $budget)) {
                    break;
                }
                $next = 'extract';
            } elseif ($stage === 'extract') {
                if (!application_update_job_extract_slice($job, $budget)) {
                    break;
                }
                $next = 'package_validate';
            } elseif ($stage === 'package_validate') {
                if (!application_update_job_validate_package_slice($job, $budget)) {
                    break;
                }
                $next = 'plan';
            } elseif ($stage === 'plan') {
                application_update_job_build_plan($job);
                $next = 'stage_files';
            } elseif ($stage === 'stage_files') {
                if (!application_update_job_stage_files_slice($job, $budget)) {
                    break;
                }
                $next = 'backup';
            } elseif ($stage === 'backup') {
                if (!application_update_job_backup_slice($job, $budget)) {
                    break;
                }
                $next = 'ready';
            } elseif ($stage === 'ready') {
                application_update_job_assert_ready($job);
                $next = 'activate';
            } elseif ($stage === 'activate') {
                application_update_job_activate($job);
                $next = 'migrate';
            } elseif ($stage === 'migrate') {
                if (!application_update_job_migrate_slice($job)) {
                    break;
                }
                $next = 'finalize';
            } elseif ($stage === 'finalize') {
                application_update_job_finalize($job);
                $next = 'cleanup';
            } elseif ($stage === 'cleanup') {
                if (!application_update_job_cleanup($job, $budget)) {
                    break;
                }
                $next = 'completed';
            } elseif ($stage === 'completed') {
                $job['status'] = 'completed';
                $job['finished_at'] = time();
                $job['progress'] = ['current' => 1, 'total' => 1, 'percent' => 100, 'message' => 'Update completed.', 'unit' => 'job'];
                application_update_save_job($job);
                application_update_set_last_job((string) $job['id']);
                application_update_clear_active_job_if((string) $job['id']);
                break;
            } else {
                throw new RuntimeException('Update job contains an unknown stage.');
            }

            if ($next !== null) {
                if (!application_update_job_transition_allowed($stage, $next)) {
                    throw new RuntimeException('Update job attempted an invalid stage transition.');
                }
                $job['stage'] = $next;
                $job['progress']['message'] = 'Checkpoint completed: ' . $stage . '.';
                application_update_save_job($job);
            }
        }

        return application_update_job_public_state($job);
    } catch (Throwable $exception) {
        $job = isset($job) && is_array($job) ? $job : application_update_load_job($jobId);
        $safe = application_update_safe_error($exception);
        $job['status'] = 'failed';
        $job['error'] = [
            'message' => $safe['message'],
            'reference' => $safe['reference'],
            'retryable' => $safe['retryable'],
            'stage' => (string) ($job['stage'] ?? ''),
            'at' => time(),
        ];
        application_update_save_job($job);
        return application_update_job_public_state($job);
    } finally {
        application_update_release_lock($lock);
    }
}

/**
 * Cancel a prepared update before activation has touched application files.
 *
 * Cancellation is intentionally forbidden once the job reaches activation. At
 * that point the safe choices are resume the same activation or start rollback
 * from the already-complete snapshot. Pre-activation cancellation removes bulky
 * transient artifacts but preserves the redacted job record for diagnostics.
 *
 * @param string $jobId Job identifier.
 * @return array<string,mixed> Safe cancelled job state.
 */
function application_update_cancel_job(string $jobId): array
{
    $startLock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15);
    if ($startLock === null) {
        return application_update_job_public_state(application_update_load_job($jobId));
    }
    $jobDir = application_update_job_dir($jobId);
    $workerLock = null;
    try {
        $active = application_update_active_job();
        if ($active !== null && !hash_equals((string) ($active['id'] ?? ''), $jobId)) {
            throw new RuntimeException('Another application update job is active.');
        }
        $workerLock = application_update_acquire_lock($jobDir . '/worker.lock', 30);
        if ($workerLock === null) {
            return application_update_job_public_state(application_update_load_job($jobId));
        }
        $job = application_update_load_job($jobId);
        if (application_update_job_terminal($job)) {
            return application_update_job_public_state($job);
        }
        $stages = application_update_job_stages();
        $stageIndex = array_search((string) ($job['stage'] ?? ''), $stages, true);
        $activateIndex = array_search('activate', $stages, true);
        if (!empty($job['checkpoints']['activation_complete'])
            || (is_int($stageIndex) && is_int($activateIndex) && $stageIndex >= $activateIndex)) {
            throw new RuntimeException('Update cannot be cancelled after activation has begun. Resume or rollback instead.');
        }

        foreach ([$jobDir . '/package.zip', $jobDir . '/extract', $jobDir . '/ready', $jobDir . '/rollback'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                application_update_remove_path($path);
            }
        }
        $job['status'] = 'cancelled';
        $job['finished_at'] = time();
        $job['result']['cancelled_before_activation'] = true;
        $job['progress'] = ['current' => 1, 'total' => 1, 'percent' => 100, 'message' => 'Prepared update cancelled before activation.', 'unit' => 'job'];
        $job['checkpoints']['backup_complete'] = false;
        application_update_save_job($job);
        if ($active !== null && hash_equals((string) ($active['id'] ?? ''), $jobId)) {
            application_update_set_active_job(null);
        }
        return application_update_job_public_state($job);
    } finally {
        if ($workerLock !== null) {
            application_update_release_lock($workerLock);
        }
        application_update_release_lock($startLock);
    }
}

/**
 * Retry a failed job from its last durable checkpoint.
 *
 * Package preparation failures restart package artifacts from download so an
 * incomplete or corrupt archive cannot be trusted on retry. Later failures keep
 * the fully validated/staged/backup state and replay only the failed stage.
 *
 * @param string $jobId Job identifier.
 * @return array<string,mixed> Safe job state.
 */
function application_update_retry_job(string $jobId): array
{
    $startLock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock', 15);
    if ($startLock === null) {
        return application_update_job_public_state(application_update_load_job($jobId));
    }
    $jobDir = application_update_job_dir($jobId);
    $lock = null;
    try {
        $active = application_update_active_job();
        if ($active !== null && !hash_equals((string) ($active['id'] ?? ''), $jobId)) {
            return application_update_job_public_state($active);
        }
        $lock = application_update_acquire_lock($jobDir . '/worker.lock', 30);
        if ($lock === null) {
            return application_update_job_public_state(application_update_load_job($jobId));
        }
        $job = application_update_load_job($jobId);
        if ((string) ($job['status'] ?? '') !== 'failed') {
            return application_update_job_public_state($job);
        }
        if (!application_update_job_error_retryable($job)) {
            return application_update_job_public_state($job);
        }
        $stage = (string) ($job['stage'] ?? '');
        if (in_array($stage, ['download', 'archive_validate', 'extract', 'package_validate'], true)) {
            foreach ([$jobDir . '/package.zip', $jobDir . '/extract', $jobDir . '/ready'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    application_update_remove_path($path);
                }
            }
            application_update_ensure_dir($jobDir . '/extract');
            application_update_ensure_dir($jobDir . '/ready');
            foreach (['archive_url', 'archive_etag', 'archive_last_modified', 'archive_expected_bytes', 'archive_validate_index', 'archive_entries', 'archive_uncompressed_bytes', 'extract_index', 'source_root', 'verify_index', 'manifest_files', 'validated_version'] as $key) {
                unset($job['checkpoints'][$key]);
            }
            $job['stage'] = 'download';
        }
        $job['status'] = 'running';
        $job['error'] = null;
        $job['progress'] = ['current' => 0, 'total' => 0, 'message' => 'Retry scheduled from the last safe checkpoint.'];
        application_update_save_job($job);
        application_update_set_active_job($jobId);
        return application_update_job_public_state($job);
    } finally {
        if ($lock !== null) {
            application_update_release_lock($lock);
        }
        application_update_release_lock($startLock);
    }
}

/**
 * Advance the active background stable update for a very small request-time slice.
 *
 * Caught failures are retried only through application_update_retry_job(), which
 * applies the stage-specific cleanup policy. A short backoff avoids hammering a
 * failing transport or filesystem on every incidental page request.
 *
 * @param float $budgetSeconds Background slice budget.
 * @return array<string,mixed>|null Safe job state, or null when no background job is active.
 */
function application_update_continue_background_job(float $budgetSeconds = 3.0): ?array
{
    $job = application_update_active_job();
    if ($job === null || (string) ($job['trigger'] ?? '') !== 'background') {
        return null;
    }

    if ((string) ($job['status'] ?? '') === 'failed') {
        if (time() - (int) ($job['updated_at'] ?? 0) < 60) {
            return application_update_job_public_state($job);
        }
        $job = application_update_retry_job((string) $job['id']);
    } else {
        $job = application_update_job_public_state($job);
    }

    if ((string) ($job['status'] ?? '') === 'running') {
        return application_update_process_job((string) $job['id'], $budgetSeconds);
    }
    return $job;
}
