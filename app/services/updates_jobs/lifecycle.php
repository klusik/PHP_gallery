<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/lifecycle.php
 * Module Type: Service
 *
 * Purpose:
 *   Starts, advances, cancels, and retries update jobs.
 *
 * Responsibilities:
 *   - Validate operation parameters before a job is created
 *   - Advance one job by a single bounded slice per request
 *   - Start rollback jobs and support cancel and retry
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
