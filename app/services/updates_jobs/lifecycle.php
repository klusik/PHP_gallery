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
 * @param array<string,scalar|null> $parameters Caller parameters accepted for the selected update operation.
 * @return array{source_job_id?:string,commit?:string,branch?:string,clean_unexpected_files?:bool,target_version?:string} Safe normalized operation parameters.
 */
function application_update_job_parameters(
    string $operation,
    array $parameters,
): array {
    if (!in_array(
        $operation,
        [
            'stable_update',
            'beta_install',
            'stable_restore',
            'clean_reinstall',
            'rollback',
        ],
        true,
    )) {
        throw new RuntimeException('Unsupported update operation.');
    }

    if ($operation === 'rollback') {
        $sourceJobId = trim((string) ($parameters['source_job_id'] ?? ''));
        if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $sourceJobId)) {
            throw new RuntimeException(
                'Rollback source job identifier is invalid.',
            );
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
        $normalized['target_version'] = trim(
            (string) $parameters['target_version'],
        );
    }
    return $normalized;
}

/**
 * Start a durable update job or return the already-active job.
 *
 * @param string $operation Update operation.
 * @param array<string,scalar|null> $parameters Operation parameters accepted for the selected update operation.
 * @param string $trigger api, admin, or background.
 * @return array{id:string,operation:string,trigger:string,status:string,stage:string,stage_percent:int,progress:array<string,int|string|float|bool|null>,created_at:int,updated_at:int,started_at:int,finished_at:int,attempts:int,error:?array{message?:string,reference?:string,retryable?:bool},result:array<string,int|string|float|bool|null|list<string>|array<string,int|string|float|bool|null|list<string>>>,can_resume:bool,can_cancel:bool,can_rollback:bool,background:bool,runtime_limits:array<string,int|string|bool>} Public-safe projection of the already-active or newly-started durable update job.
 */
function application_update_start_job(
    string $operation,
    array $parameters = [],
    string $trigger = 'api',
): array {
    if (
        function_exists(__NAMESPACE__ . '\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')
    ) {
        throw new RuntimeException('Built-in Update Installer is disabled.');
    }
    if ($operation !== 'rollback' && !class_exists(ZipArchive::class)) {
        throw new RuntimeException(
            'The PHP ZipArchive extension is required for application updates.',
        );
    }

    $startLock = application_update_acquire_lock(
        application_update_jobs_root() . '/start.lock',
        15,
    );
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

        $parameters = application_update_job_parameters(
            $operation,
            $parameters,
        );
        $job = application_update_create_job($operation, $parameters, $trigger);
        application_update_set_active_job((string) $job['id']);
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
function application_update_start_rollback_job(
    string $sourceJobId,
    string $trigger = 'api',
): array {
    if (
        function_exists(__NAMESPACE__ . '\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')
    ) {
        throw new RuntimeException('Built-in Update Installer is disabled.');
    }
    $sourceJobId = trim($sourceJobId);
    if (!preg_match('/^\d{14}-[0-9a-f]{12}$/', $sourceJobId)) {
        throw new RuntimeException(
            'Rollback source job identifier is invalid.',
        );
    }
    $observed = application_update_load_job($sourceJobId);
    if (($observed['status'] ?? '') !== 'completed'
        && ($observed['recovery_schema'] ?? 0) === 1) {
        application_update_assert_rollback_database($observed);
        if (!application_update_failed_recovery_due($observed, time())) {
            throw new RuntimeException('Failed update safety recovery is waiting for its bounded retry/timeout policy.');
        }
        return application_update_recover_failed_job($sourceJobId);
    }
    $startLock = application_update_acquire_lock(
        application_update_jobs_root() . '/start.lock',
        15,
    );
    if ($startLock === null) {
        throw new RuntimeException('Another update request is being prepared.');
    }
    try {
        $source = application_update_load_job($sourceJobId);
        if (!empty($source['result']['superseded_by'])) {
            throw new RuntimeException('This update was superseded by a recovery job. Review that job first.');
        }
        if (empty($source['checkpoints']['backup_complete'])) {
            throw new RuntimeException('Rollback snapshot is not complete.');
        }
        $sourceStage = (string) ($source['stage'] ?? '');
        $eligible =
            in_array(
                $sourceStage,
                ['activate', 'migrate', 'finalize', 'cleanup', 'completed'],
                true,
            )
            || !empty($source['checkpoints']['activation_complete']);
        if (!$eligible) {
            throw new RuntimeException(
                'Rollback is not required before activation begins.',
            );
        }
        $active = application_update_active_job();
        if (
            $active !== null
            && (string) ($active['id'] ?? '') !== $sourceJobId
        ) {
            return application_update_job_public_state($active);
        }
        if ($active !== null) {
            if ((string) ($source['status'] ?? '') !== 'failed') {
                throw new RuntimeException(
                    'Running update must reach a checkpoint before rollback can start.',
                );
            }
            $source['status'] = 'cancelled';
            $source['finished_at'] = time();
            $source['result']['cancelled_for_rollback'] = true;
            application_update_save_job($source);
            application_update_set_active_job(null);
        }

        $job = application_update_create_job('rollback',
            ['source_job_id' => $sourceJobId],
            in_array($trigger, ['api', 'admin'], true) ? $trigger : 'api');
        $jobId = (string) $job['id'];
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
function application_update_process_job(
    string $jobId,
    float $budgetSeconds = 8.0,
): array {
    if (
        function_exists(__NAMESPACE__ . '\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')
    ) {
        throw new RuntimeException('Built-in Update Installer is disabled.');
    }
    $jobDir = application_update_job_dir($jobId);
    $lock = application_update_acquire_lock($jobDir . '/worker.lock', 30);
    if ($lock === null) {
        return application_update_job_public_state(application_update_load_job(
            $jobId,
        ));
    }

    try {
        $job = application_update_load_job($jobId);
        if (application_update_job_terminal($job)) {
            return application_update_job_public_state($job);
        }
        $active = application_update_active_job();
        if (
            $active === null
            || !hash_equals((string) ($active['id'] ?? ''), $jobId)
        ) {
            // A non-active job must never mutate application files concurrently
            // with the globally active update, even if its id is addressed directly.
            return application_update_job_public_state($job);
        }
        if ((string) ($job['status'] ?? '') === 'failed') {
            // Caught failures require application_update_retry_job() so package-stage
            // retries can discard untrusted partial artifacts before execution resumes.
            return application_update_job_public_state($job);
        }
        if (($job['status'] ?? '') === 'paused') {
            return application_update_job_public_state($job);
        }
        if (application_update_manual_deployment_detected($job)) {
            throw new RuntimeException('A newer manual deployment must be completed instead of resuming this package.');
        }
        if (!empty($job['slice_open'])) {
            // Holding worker.lock proves the prior process no longer owns it.
            // A killed slice must enter the same bounded failure policy, rather
            // than silently replaying one checkpoint forever.
            throw new RuntimeException('Previous update worker was interrupted before its slice closed.');
        }
        $job['slice_open'] = true;
        $job['slice_started_at'] = time();
        $job['slice_recovery_at'] = time() + APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS;
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        application_update_save_job($job);
        $budget = application_update_time_budget($budgetSeconds);

        while (application_update_budget_allows($budget, 0.75)) {
            if (is_file($jobDir . '/pause-requested.json')) {
                $job['status'] = 'paused';
                break;
            }
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
                if (!application_update_job_validate_package_slice(
                    $job,
                    $budget,
                )) {
                    break;
                }
                if (($job['operation'] ?? '') === 'manual_deploy') {
                    $job['checkpoints']['manual_deployment_verified'] = true;
                    if (!application_update_manual_deployment_gate($job)) {
                        break;
                    }
                    $next = 'migrate';
                } else {
                    $next = 'plan';
                }
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
                if (!application_update_recovery_ready_slice($job, $budget)) {
                    break;
                }
                application_update_job_assert_ready($job);
                $next = 'activate';
            } elseif ($stage === 'activate') {
                if (!application_update_job_activate($job)) {
                    break;
                }
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
                if (($job['operation'] ?? '') !== 'manual_deploy'
                    && !application_update_job_cleanup($job, $budget)) {
                    break;
                }
                $next = 'completed';
            } elseif ($stage === 'completed') {
                $job['status'] = 'completed';
                $job['finished_at'] = time();
                $job['progress'] = [
                    'current' => 1,
                    'total' => 1,
                    'percent' => 100,
                    'message' => 'Update completed.',
                    'unit' => 'job',
                ];
                application_update_save_job($job);
                application_update_runtime_window_close($job);
                application_update_activation_gate_clear((string) $job['id']);
                application_update_set_last_job((string) $job['id']);
                application_update_clear_active_job_if((string) $job['id']);
                break;
            } else {
                throw new RuntimeException(
                    'Update job contains an unknown stage.',
                );
            }

            if ($next !== null) {
                $manualForward = ($job['operation'] ?? '') === 'manual_deploy'
                    && $stage === 'package_validate' && $next === 'migrate'
                    && !empty($job['checkpoints']['manual_deployment_verified'])
                    && !empty($job['checkpoints']['runtime_guard_open']);
                if (!$manualForward && !application_update_job_transition_allowed($stage, $next)) {
                    throw new RuntimeException(
                        'Update job attempted an invalid stage transition.',
                    );
                }
                $job['stage'] = $next;
                // The new stage must never inherit the prior stage's 100%.
                $job['progress'] = [
                    'current' => 0,
                    'total' => 0,
                    'percent' => 0,
                    'message' => 'Starting checkpoint: ' . $next . '.',
                ];
                application_update_save_job($job);
            }
        }

        $job['slice_open'] = false;
        if (($job['status'] ?? '') === 'running'
            && is_file($jobDir . '/pause-requested.json')) {
            $job['status'] = 'paused';
        }
        application_update_save_job($job);
        return application_update_job_public_state($job);
    } catch (Throwable $exception) {
        $job = isset($job) && is_array($job)
            ? $job
            : application_update_load_job($jobId);
        $safe = application_update_safe_error($exception);
        application_update_record_failure_diagnostic($job, $exception, $safe['reference']);
        $job['status'] = 'failed';
        $job['slice_open'] = false;
        $job['error'] = [
            'message' => $safe['message'],
            'reference' => $safe['reference'],
            'retryable' => $safe['retryable'],
            'stage' => (string) ($job['stage'] ?? ''),
            'at' => time(),
        ];
        // Persist bounded evidence independently of successful slice counts.
        // Retry cannot erase or reset a recurring failure's automatic budget.
        $job['failures'][] = $job['error'];
        $job['result']['first_failure'] ??= $job['error'];
        $job['failure_first_at'] ??= $job['error']['at'];
        $retries = max(0, (int) ($job['auto_retries'] ?? 0));
        $job['recovery_not_before'] = $safe['retryable']
            && $retries < APPLICATION_UPDATE_AUTO_RETRY_LIMIT
                ? $job['error']['at'] + APPLICATION_UPDATE_AUTO_RETRY_DELAY_SECONDS * (2 ** $retries)
                : (int) $job['failure_first_at'] + APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS;
        if (($job['operation'] ?? '') === 'rollback'
            && (!$safe['retryable'] || $retries >= APPLICATION_UPDATE_AUTO_RETRY_LIMIT)) {
            $job['recovery_blocked'] = 'rollback_failed';
        }
        $job['failures'] = array_slice(
            $job['failures'],
            -(APPLICATION_UPDATE_AUTO_RETRY_LIMIT + 1),
        );
        application_update_save_job($job);
        return application_update_job_public_state($job);
    } finally {
        application_update_release_lock($lock);
    }
}

/**
 * Cancel a prepared update before activation has touched application files.
 *
 * Cancellation is intentionally forbidden for unknown stages or once the job reaches activation. At
 * that point the safe choices are resume the same activation or start rollback
 * from the already-complete snapshot. Pre-activation cancellation removes bulky
 * transient artifacts but preserves the redacted job record for diagnostics.
 *
 * @param string $jobId Job identifier.
 * @return array<string,mixed> Public job state with id/operation/trigger/status/stage strings, stage_percent/attempts/timestamps integers, progress current/total/percent/message/unit fields when available, boolean can_resume/can_cancel/can_rollback/background, safe error metadata or null, result JSON fields and runtime_limits; busy locks return the unchanged public job, while unsafe cancellation throws.
 */
function application_update_cancel_job(string $jobId): array
{
    $startLock = application_update_acquire_lock(
        application_update_jobs_root() . '/start.lock',
        15,
    );
    if ($startLock === null) {
        return application_update_job_public_state(application_update_load_job(
            $jobId,
        ));
    }
    $jobDir = application_update_job_dir($jobId);
    $workerLock = null;
    try {
        $active = application_update_active_job();
        if (
            $active !== null
            && !hash_equals((string) ($active['id'] ?? ''), $jobId)
        ) {
            throw new RuntimeException(
                'Another application update job is active.',
            );
        }
        $workerLock = application_update_acquire_lock(
            $jobDir . '/worker.lock',
            30,
        );
        if ($workerLock === null) {
            return application_update_job_public_state(application_update_load_job(
                $jobId,
            ));
        }
        $job = application_update_load_job($jobId);
        if (application_update_job_terminal($job)) {
            return application_update_job_public_state($job);
        }
        if (!empty($job['result']['superseded_by'])) {
            return application_update_job_public_state($job);
        }
        if (!empty($job['automatic_rollback']) || ($job['operation'] ?? '') === 'manual_deploy') {
            throw new RuntimeException('Automatic rollback must be continued to a safe checkpoint, not cancelled.');
        }
        $stages = application_update_job_stages();
        $stageIndex = array_search(
            (string) ($job['stage'] ?? ''),
            $stages,
            true,
        );
        $activateIndex = array_search('activate', $stages, true);
        if (
            !empty($job['checkpoints']['activation_complete'])
            || !is_int($stageIndex)
            || !is_int($activateIndex)
            || $stageIndex >= $activateIndex
        ) {
            throw new RuntimeException(
                'Update cannot be cancelled after activation has begun. Resume or rollback instead.',
            );
        }

        foreach ([
            $jobDir . '/package.zip',
            $jobDir . '/extract',
            $jobDir . '/ready',
            $jobDir . '/rollback',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                application_update_remove_path($path);
            }
        }
        $job['status'] = 'cancelled';
        $job['finished_at'] = time();
        $job['result']['cancelled_before_activation'] = true;
        $job['progress'] = [
            'current' => 1,
            'total' => 1,
            'percent' => 100,
            'message' => 'Prepared update cancelled before activation.',
            'unit' => 'job',
        ];
        $job['checkpoints']['backup_complete'] = false;
        application_update_save_job($job);
        if (
            $active !== null
            && hash_equals((string) ($active['id'] ?? ''), $jobId)
        ) {
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
 * @param bool $automatic Whether bounded unattended retry admission is required.
 * @return array<string,mixed> Public job state, with result.last_failure retaining the preceding safe error message/reference, stage, at epoch and retryable flag when a retry clears the current error.
 */
function application_update_retry_job(string $jobId, bool $automatic = false): array
{
    if (
        function_exists(__NAMESPACE__
            . '\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')
    ) {
        throw new RuntimeException('Built-in Update Installer is disabled.');
    }
    $startLock = application_update_acquire_lock(
        application_update_jobs_root() . '/start.lock',
        15,
    );
    if ($startLock === null) {
        return application_update_job_public_state(application_update_load_job(
            $jobId,
        ));
    }
    $jobDir = application_update_job_dir($jobId);
    $lock = null;
    try {
        $active = application_update_active_job();
        if (
            $active !== null
            && !hash_equals((string) ($active['id'] ?? ''), $jobId)
        ) {
            return application_update_job_public_state($active);
        }
        $lock = application_update_acquire_lock($jobDir . '/worker.lock', 30);
        if ($lock === null) {
            return application_update_job_public_state(application_update_load_job(
                $jobId,
            ));
        }
        $job = application_update_load_job($jobId);
        if ((string) ($job['status'] ?? '') !== 'failed') {
            return application_update_job_public_state($job);
        }
        if (!application_update_job_error_retryable($job)) {
            return application_update_job_public_state($job);
        }
        if ($automatic && !application_update_auto_retry_due($job, time())) {
            return application_update_job_public_state($job);
        }
        if ($automatic) {
            $job['auto_retries'] = max(0, (int) ($job['auto_retries'] ?? 0)) + 1;
            // Retain the original trigger while letting safety repair finish
            // more than one slice of an already failed manual operation.
            $job['automatic_repair_continuation'] = true;
        }
        $stage = (string) ($job['stage'] ?? '');
        // Retain owner-redacted failure evidence when retry clears the live error.
        $job['result']['last_failure'] = (array) (
            application_update_job_public_state($job)['error'] ?? []
        );
        if (in_array(
            $stage,
            ['download', 'archive_validate', 'extract', 'package_validate'],
            true,
        )) {
            if (empty($job['checkpoints']['archive_commit'])
                && !empty($job['checkpoints']['archive_url'])) {
                // Legacy moving-branch downloads cannot establish the original
                // package identity. Keep their artifacts for a new linked job.
                return application_update_job_public_state($job);
            }
            foreach ([
                $jobDir . '/package.zip',
                $jobDir . '/extract',
                $jobDir . '/ready',
            ] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    application_update_remove_path($path);
                }
            }
            application_update_ensure_dir($jobDir . '/extract');
            application_update_ensure_dir($jobDir . '/ready');
            foreach ([
                'archive_url',
                'archive_etag',
                'archive_last_modified',
                'archive_expected_bytes',
                'archive_validate_index',
                'archive_entries',
                'archive_uncompressed_bytes',
                'extract_index',
                'source_root',
                'verify_index',
                'manifest_files',
                'validated_version',
                'recovery_manifest_identity',
                'recovery_hashes',
                'recovery_verify_index',
                'recovery_ready_index',
                'recovery_ready_verified',
                'recovery_backup_identity',
            ] as $key) {
                unset($job['checkpoints'][$key]);
            }
            $job['stage'] = 'download';
        }
        $job['status'] = 'running';
        $job['error'] = null;
        $job['progress'] = [
            'current' => 0,
            'total' => 0,
            'message' => function_exists(__NAMESPACE__ . '\\t')
                ? t('admin.updates.job_retry_scheduled')
                : 'Continuing from the last saved step.',
        ];
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
 * Advance background work or safety recovery for any failed/interrupted origin.
 *
 * Caught failures use bounded retry and timeout admission. Discovery is
 * independent of the stopped job; safe rollback has its own preserved identity.
 *
 * @param float $budgetSeconds Background slice budget.
 * @return array<string,mixed>|null Safe retry, rollback or stopped job; null for idle or an ordinary paused manual job.
 */
function application_update_continue_background_job(float $budgetSeconds = 3.0): ?array
{
    $job = application_update_active_job();
    if ($job === null) {
        return null;
    }

    if ((string) ($job['status'] ?? '') === 'failed') {
        return application_update_recover_failed_job((string) $job['id'], $budgetSeconds);
    }
    if (($job['trigger'] ?? '') !== 'background'
        && empty($job['automatic_repair_continuation'])) {
        if (($job['status'] ?? '') === 'running' && !empty($job['slice_open'])
            && (int) ($job['slice_recovery_at'] ?? PHP_INT_MAX) <= time()) {
            return application_update_process_job((string) $job['id'], $budgetSeconds);
        }
        return null;
    }
    $job = application_update_job_public_state($job);

    if ((string) ($job['status'] ?? '') === 'running') {
        return application_update_process_job(
            (string) $job['id'],
            $budgetSeconds,
        );
    }
    return $job;
}


// Recovery responsibilities stay with their existing updater domain owner.

/**
 * Request checkpoint-safe pausing or explicitly resume the same preserved job.
 * @param string $jobId Existing active job identity.
 * @param bool $resume Whether to resume rather than request a pause.
 * @return array<string,mixed> Public current job; busy resume preserves ownership.
 */
function application_update_control_pause(string $jobId, bool $resume = false): array
{
    $start = application_update_acquire_lock(application_update_jobs_root() . '/start.lock');
    if ($start === null) {
        return application_update_job_public_state(application_update_load_job($jobId));
    }
    $worker = null;
    try {
        $job = application_update_load_job($jobId);
        if ((application_update_active_job()['id'] ?? '') !== $jobId
            || application_update_job_terminal($job)) {
            return application_update_job_public_state($job);
        }
        $path = application_update_job_dir($jobId) . '/pause-requested.json';
        if (!$resume) {
            application_update_write_json_atomic($path, ['job_id' => $jobId, 'at' => time()]);
        } else {
            $worker = application_update_acquire_lock(application_update_job_dir($jobId) . '/worker.lock');
            if ($worker === null || ($job['status'] ?? '') !== 'paused') {
                return application_update_job_public_state($job);
            }
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Could not resume the paused update checkpoint.');
            }
            $job['status'] = 'running';
            application_update_save_job($job);
        }
        return application_update_job_public_state($job);
    } finally {
        application_update_release_lock($worker);
        application_update_release_lock($start);
    }
}

/**
 * Remember failed source identities without changing their job or package.
 * @param array<string,mixed> $source Failed original update job.
 * @return void Stores bounded commit exclusions and a stable-version floor.
 */
function application_update_quarantine_failed_source(array $source): void
{
    $commit = (string) ($source['checkpoints']['archive_commit'] ?? '');
    if (preg_match('/^[a-f0-9]{40}$/D', $commit) === 1) {
        $stored = json_decode(app_setting('application_update_failed_commits', '[]'), true);
        $commits = is_array($stored) ? $stored : [];
        $commits = array_values(array_filter($commits, static fn(mixed $value): bool =>
            is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1));
        $commits = array_values(array_unique(array_merge([$commit], $commits)));
        set_app_setting('application_update_failed_commits', (string) json_encode(
            array_slice($commits, 0, APPLICATION_UPDATE_FAILED_COMMIT_LIMIT),
            JSON_THROW_ON_ERROR,
        ));
    }
    if (($source['operation'] ?? '') === 'stable_update') {
        $version = application_update_normalize_version((string) (
            $source['parameters']['target_version']
                ?? $source['checkpoints']['validated_version'] ?? ''
        ));
        $previous = application_update_normalize_version(app_setting(
            'application_update_failed_stable_version', '',
        ));
        if ($version !== null && ($previous === null || version_compare($version, $previous, '>'))) {
            set_app_setting('application_update_failed_stable_version', $version);
        }
    }
    application_update_write_json_atomic(application_update_jobs_root() . '/last-failed-job.json', [
        'job_id' => (string) $source['id'], 'updated_at' => time(),
    ]);
}

/**
 * Retry or reserve one automatic rollback after a mature original failure.
 * @param string $jobId Original failed job, regardless of its launch mode.
 * @param float $budgetSeconds Maximum retry worker slice.
 * @return array<string,mixed> Public retry, rollback, released or safely blocked job.
 */
function application_update_recover_failed_job(
    string $jobId,
    float $budgetSeconds = APPLICATION_UPDATE_CHAIN_SLICE_SECONDS,
): array
{
    $source = application_update_load_job($jobId);
    if (($source['operation'] ?? '') === 'manual_deploy'
        || application_update_manual_deployment_detected($source)) {
        // FTP completion is explicit forward work. Never restore an old backup
        // over a newly uploaded release or abandon its preserved gate.
        return application_update_job_public_state($source);
    }
    if (function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('built_in_update_installer')) {
        return application_update_job_public_state($source);
    }
    if (application_update_auto_retry_due($source, time())) {
        $retried = application_update_retry_job($jobId, true);
        return ($retried['status'] ?? '') === 'running'
            ? application_update_process_job($jobId, $budgetSeconds) : $retried;
    }
    if (!application_update_failed_recovery_due($source, time())) {
        return application_update_job_public_state($source);
    }
    $start = application_update_acquire_lock(application_update_jobs_root() . '/start.lock');
    if ($start === null) {
        return application_update_job_public_state($source);
    }
    $worker = null;
    $runtime = null;
    try {
        $active = application_update_active_job();
        if (($active['id'] ?? '') !== $jobId) {
            return application_update_job_public_state($active ?? $source);
        }
        $worker = application_update_acquire_lock(application_update_job_dir($jobId) . '/worker.lock');
        if ($worker === null) {
            return application_update_job_public_state($source);
        }
        $source = application_update_load_job($jobId);
        if (!application_update_failed_recovery_due($source, time())) {
            return application_update_job_public_state($source);
        }
        $boundary = application_update_recovery_boundary($source);
        if ($boundary === 'activation_not_started_candidate') {
            $runtime = application_update_acquire_lock(
                application_update_jobs_root() . '/runtime-use.lock',
            );
            if ($runtime === null) {
                return application_update_job_public_state($source);
            }
            try {
                application_update_assert_activation_unchanged($source);
                application_update_runtime_window_close($source);
                $source['result']['activation_unchanged_verified'] = true;
                $boundary = 'pre_activation';
            } catch (Throwable $exception) {
                $source['recovery_blocked'] = 'activation_unchanged_unverified';
                $source['result']['recovery_failure'] = application_update_safe_error($exception);
                application_update_save_job($source);
                return application_update_job_public_state($source);
            }
        }
        if ($boundary === 'pre_activation') {
            application_update_quarantine_failed_source($source);
            $source['result']['recovery_released'] = true;
            $source['result']['recovery_outcome'] = 'unchanged_before_activation';
            $source['finished_at'] = time();
            application_update_save_job($source);
            application_update_set_active_job(null);
            return application_update_job_public_state($source);
        }
        if ($boundary !== 'rollback_safe') {
            $source['recovery_blocked'] = $boundary;
            application_update_save_job($source);
            return application_update_job_public_state($source);
        }
        $safetyArea = 'database_unverified';
        try {
            application_update_assert_rollback_database($source);
            $safetyArea = 'backup_unverified';
            $metadataPath = application_update_job_dir($jobId) . '/rollback/metadata.json';
            $metadata = application_update_read_json($metadataPath);
            if (($metadata['job_id'] ?? '') !== $jobId
                || ($source['checkpoints']['backup_metadata_identity'] ?? '')
                    !== application_update_manifest_hash($metadataPath)
                || !is_array($metadata['hashes'] ?? null)
                || $metadata['hashes'] !== ($source['checkpoints']['backup_hashes'] ?? null)) {
                throw new RuntimeException('Recovery backup metadata is missing or corrupt.');
            }
        } catch (Throwable $exception) {
            $source['recovery_blocked'] = $safetyArea;
            $source['result']['recovery_failure'] = application_update_safe_error($exception);
            application_update_save_job($source);
            return application_update_job_public_state($source);
        }
        $reservation = $source['automatic_rollback_reservation'] ?? null;
        if ($reservation === null) {
            $reservation = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
            if (is_dir(application_update_job_dir($reservation))) {
                throw new RuntimeException('Recovery rollback identity already exists.');
            }
            $source['automatic_rollback_reservation'] = $reservation;
            application_update_save_job($source);
        }
        $rollbackId = (string) $reservation;
        $parameters = ['source_job_id' => $jobId];
        if (is_file(application_update_job_state_path($rollbackId))) {
            $rollback = application_update_load_job($rollbackId);
            if (($rollback['operation'] ?? '') !== 'rollback'
                || ($rollback['parameters'] ?? []) !== $parameters
                || ($rollback['trigger'] ?? '') !== 'background'
                || ($rollback['status'] ?? '') !== 'running') {
                throw new RuntimeException('Recovery rollback reservation is inconsistent.');
            }
        } else {
            $rollback = application_update_create_job('rollback', $parameters,
                'background', $rollbackId);
        }
        $rollback['automatic_rollback'] = true;
        $rollback['recovery'] = ['source_job_id' => $jobId,
            'boundary' => 'rollback_safe', 'depth' => 1, 'kind' => 'automatic_rollback'];
        $rollback['checkpoints']['recovery_hashes'] = $metadata['hashes'];
        $rollback['checkpoints']['recovery_manifest_identity'] =
            (string) ($metadata['hashes']['app/core-manifest.json'] ?? '');
        $rollback['result']['recovery_source_job_id'] = $jobId;
        application_update_save_job($rollback);
        application_update_quarantine_failed_source($source);
        $source['result']['superseded_by'] = $rollbackId;
        application_update_save_job($source);
        application_update_set_active_job($rollbackId);
        return application_update_job_public_state($rollback);
    } finally {
        application_update_release_lock($runtime);
        application_update_release_lock($worker);
        application_update_release_lock($start);
    }
}
