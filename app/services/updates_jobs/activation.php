<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/activation.php
 * Module Type: Service
 *
 * Purpose:
 *   Replaces active application files behind the activation gate.
 *
 * Responsibilities:
 *   - Hold the activation gate so no request runs against a half-updated tree
 *   - Assert readiness and replace files in a deterministic priority order
 *   - Run migrations and finalize the job after activation
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
 * Resolve the sole maintenance marker and import old installed-worker state.
 * @return string Absolute installation-root maintenance.lock path.
 */
function application_update_activation_gate_path(): string
{
    $root = application_update_project_root();
    \Gallery\EarlyRuntime\import_legacy_activation_gate($root);
    return \Gallery\EarlyRuntime\activation_gate_path($root);
}

/**
 * Persist the dependency-free activation marker before any managed production file is replaced.
 *
 * Replaying the same job keeps the existing marker. A marker owned by another
 * job is never overwritten because doing so could reopen a partially activated
 * release without proving that the earlier job reached activation_complete.
 *
 * @param array{id:string,trigger?:string,admin_session_name?:string,checkpoints?:array{runtime_guard_open?:bool,recovery_admission_closed?:bool},...<string,mixed>} $job Durable owner with session-name and admission receipts.
 * @return void Persists or verifies the activation ownership marker for this job.
 */
function application_update_activation_gate_begin(array $job): void
{
    $jobId = (string) ($job['id'] ?? '');
    if ($jobId === '') {
        throw new RuntimeException(
            'Update activation job identifier is missing.',
        );
    }

    $path = application_update_activation_gate_path();
    $markerExists = is_file($path);
    $existing = application_update_read_json($path);
    if ($markerExists && $existing === []) {
        throw new RuntimeException(
            'Existing update activation gate state is unreadable.',
        );
    }
    if ($existing !== []) {
        $existingJobId = (string) ($existing['job_id'] ?? '');
        if ($existingJobId === '' || !hash_equals($existingJobId, $jobId)) {
            throw new RuntimeException(
                'Another update activation gate is already active.',
            );
        }
    }

    $payload = [
        'schema' => 1,
        'job_id' => $jobId,
        'trigger' => (string) ($job['trigger'] ?? ''),
        'started_at' => (int) ($existing['started_at'] ?? time()),
        'updated_at' => time(),
        'recovery_isolation' => !empty($job['checkpoints']['runtime_guard_open'])
            || !empty($job['checkpoints']['recovery_admission_closed']),
    ];

    // The session name is not an authentication secret. Persisting it lets the early
    // gate recognize a pre-existing authenticated Admin session even when an automatic
    // update was initiated by an anonymous request. The session id itself is never stored.
    // Background workers have no active browser session. Keep the creator's
    // session name so authenticated Admin status remains reachable while gated.
    $sessionName = session_status() === PHP_SESSION_ACTIVE ? session_name()
        : (string) ($existing['admin_session_name'] ?? $job['admin_session_name'] ?? '');
    if ($sessionName !== ''
        && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sessionName) === 1) {
        $payload['admin_session_name'] = $sessionName;
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
    if (
        $marker === []
        || !hash_equals((string) ($marker['job_id'] ?? ''), $jobId)
    ) {
        return;
    }
    @unlink($path);
}

/**
 * Verify every pre-activation prerequisite immediately before the critical section.
 *
 * @param array<string,mixed> $job Durable update job with id, status and checkpoints for prepared source, backup and verification; no raw request data.
 * @return void Refuses activation when package, backup, runtime or gallery-write enforcement is not verified.
 */
function application_update_job_assert_ready(array &$job): void
{
    if (empty($job['checkpoints']['backup_complete'])) {
        throw new RuntimeException('Rollback snapshot is not complete.');
    }
    $jobDir = application_update_job_dir((string) $job['id']);
    if (!is_file($jobDir . '/rollback/metadata.json')) {
        throw new RuntimeException('Rollback metadata is missing.');
    }
    foreach ((array) (
        $job['checkpoints']['activation_files'] ?? []
    ) as $relative) {
        $relative = (string) $relative;
        application_update_assert_safe_target($jobDir, 'ready/' . $relative);
        $ready =
            $jobDir
            . '/ready/'
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($ready)) {
            throw new RuntimeException('Prepared activation file is missing.');
        }
    }
    if ((!empty($job['automatic_rollback'])
            || (($job['recovery_schema'] ?? 0) === 1 && ($job['operation'] ?? '') !== 'rollback'))
        && empty($job['checkpoints']['recovery_ready_verified'])) {
        throw new RuntimeException('Recovery readiness verification is incomplete.');
    }
    application_update_assert_activation_schema_known(
        'application_update.job_activation_ready',
    );
    application_update_assert_gallery_edit_enforcement(
        (string) ($job['checkpoints']['source_root'] ?? ''),
    );
    application_update_recovery_assert_compatible(
        $job,
        (string) ($job['checkpoints']['source_root'] ?? ''),
    );
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
 * @param array<string,mixed> $job Job state with an id and activation_files/obsolete_paths checkpoints, updated by reference as activation progress is saved.
 * @return bool True after activation; false yields while another runtime holds a shared lease.
 */
function application_update_job_activate(array &$job): bool
{
    if (($job['operation'] ?? '') !== 'rollback') {
        // Close admission before waiting for the exclusive lease: new visitors
        // must not continuously replace readers that have already finished.
        // This receipt does not claim that activation or migration has started.
        $job['checkpoints']['recovery_admission_closed'] = true;
        application_update_save_job($job);
        application_update_activation_gate_begin($job);
    }
    $runtimeLock = application_update_acquire_lock(application_update_jobs_root() . '/runtime-use.lock');
    if ($runtimeLock === null) {
        $job['checkpoints']['runtime_lease_busy'] = true;
        $job['progress'] = [
            'current' => 0, 'total' => 0, 'percent' => 0,
            'message' => 'Waiting for other website requests to finish.',
        ];
        application_update_save_job($job);
        return false;
    }
    try {
        unset($job['checkpoints']['runtime_lease_busy']);
        application_update_runtime_window_begin($job);
        application_update_job_assert_ready($job);
        application_update_rollback_gate_takeover($job);
        // Commit intent before the gate or any rename. Old jobs without this
        // receipt require a complete original-byte comparison for release.
        $job['checkpoints']['activation_started'] = true;
        application_update_save_job($job);
        application_update_activation_gate_begin($job);
        $jobDir = application_update_job_dir((string) $job['id']);
        $root = application_update_project_root();
        $files = array_values(
            (array) ($job['checkpoints']['activation_files'] ?? []),
        );
        usort($files, static function (string $left, string $right): int {
            $comparison = application_update_activation_priority(
                $left,
            ) <=> application_update_activation_priority($right);
            return $comparison !== 0 ? $comparison : strcmp($left, $right);
        });

        $activated = 0;
        foreach ($files as $relative) {
            application_update_assert_safe_target($jobDir, 'ready/' . $relative);
            application_update_assert_safe_target($root, $relative, true);
            $ready =
                $jobDir
                . '/ready/'
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $destination =
                $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $readyHash = hash_file('sha256', $ready);
            if ($readyHash === false) {
                throw new RuntimeException(
                    'Could not verify prepared activation file.',
                );
            }
            if (!empty($job['automatic_rollback'])
                || (($job['recovery_schema'] ?? 0) === 1 && ($job['operation'] ?? '') !== 'rollback')) {
                $expected = (!empty($job['recovery_override'])
                    || !empty($job['checkpoints']['publisher_integrity_disabled']))
                    ? (string) ($job['checkpoints']['recovery_hashes'][$relative] ?? '')
                    : ($relative === 'app/core-manifest.json'
                    ? (string) ($job['checkpoints']['recovery_manifest_identity'] ?? '')
                    : (string) ($job['checkpoints']['recovery_hashes'][$relative] ?? ''));
                if ($expected === ''
                    || !application_update_recovery_hash_matches($expected, $ready)) {
                    throw new RuntimeException('Recovery prepared file failed integrity verification.');
                }
            }
            if (is_file($destination)) {
                $destinationHash = hash_file('sha256', $destination);
                if (
                    $destinationHash !== false
                    && hash_equals($readyHash, $destinationHash)
                ) {
                    $activated++;
                    continue;
                }
            } elseif (is_dir($destination)) {
                throw new RuntimeException(
                    'Cannot replace an active directory with a file.',
                );
            }

            application_update_ensure_dir(dirname($destination));
            $temporary =
                dirname($destination)
                . '/.php-gallery-activate-'
                . bin2hex(random_bytes(6))
                . '.tmp';
            $temporaryRelative = dirname(str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative,
            ));
            $temporaryRelative =
                (
                    $temporaryRelative === '.'
                        ? ''
                        : str_replace(DIRECTORY_SEPARATOR, '/', $temporaryRelative)
                        . '/'
                )
                . basename($temporary);
            application_update_assert_safe_target($root, $temporaryRelative, true);
            if (!copy($ready, $temporary)) {
                throw new RuntimeException(
                    'Could not prepare active file replacement.',
                );
            }
            if (!hash_equals(
                $readyHash,
                (string) hash_file('sha256', $temporary),
            )) {
                @unlink($temporary);
                throw new RuntimeException(
                    'Active file replacement failed integrity verification.',
                );
            }
            application_update_assert_safe_target($root, $relative, true);
            application_update_assert_safe_target($root, $temporaryRelative);
            if (!rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException(
                    'Could not atomically replace an active application file.',
                );
            }
            application_update_invalidate_opcache_for_path($destination);
            $activated++;
        }

        foreach ((array) (
            $job['checkpoints']['obsolete_paths'] ?? []
        ) as $relative) {
            $relative = (string) $relative;
            application_update_assert_safe_target($root, $relative, true);
            $path = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (file_exists($path) || is_link($path)) {
                application_update_assert_safe_target($root, $relative);
                application_update_remove_path($path);
            }
        }

        $job['checkpoints']['activation_complete'] = true;
        $job['checkpoints']['activated_files'] = $activated;
        $job['progress'] = [
            'current' => $activated,
            'total' => count($files),
            'message' => 'Prepared release activated.',
            'unit' => 'files',
        ];
        application_update_save_job($job);
        if (empty($job['checkpoints']['runtime_guard_open'])) {
            application_update_activation_gate_clear((string) $job['id']);
        }
        return true;
    } finally {
        application_update_release_lock($runtimeLock);
    }
}

/**
 * Run at most one migration file for this worker invocation.
 *
 * schema_migrations is the durable checkpoint. If PHP dies inside a migration,
 * that migration may replay on the next request. Migration definitions therefore
 * remain required to tolerate replay, while completed migration files never rerun.
 *
 * @param array{id:string,stage:string,...<string,mixed>} $job Durable update job state, updated by reference.
 * @return bool True when no migrations remain.
 */
function application_update_job_migrate_slice(array &$job): bool
{
    if ((string) ($job['operation'] ?? '') === 'rollback') {
        $job['progress'] = [
            'current' => 1,
            'total' => 1,
            'message' => 'Rollback does not reverse database migrations.',
            'unit' => 'migration policy',
        ];
        application_update_save_job($job);
        return true;
    }
    // Commit intent before the runner can bootstrap its ledger or execute DDL.
    // A crash after this write is deliberately unverified for file recovery.
    $job['checkpoints']['migrations_started'] = true;
    application_update_save_job($job);
    $result = run_migrations_bounded(1);
    $ran = array_values((array) ($result['ran'] ?? []));
    $allRan = array_values(array_merge(
        (array) ($job['result']['migrations'] ?? []),
        $ran,
    ));
    $job['result']['migrations'] = array_values(array_unique($allRan));
    $job['progress'] = [
        'current' => count($job['result']['migrations']),
        'total' =>
            count($job['result']['migrations'])
                + (int) ($result['remaining'] ?? 0),
        'message' => 'Applying database migrations.',
        'unit' => 'migrations',
    ];
    application_update_save_job($job);
    $complete = (int) ($result['remaining'] ?? 0) === 0;
    if ($complete) {
        $job['checkpoints']['migrations_complete'] = true;
        application_update_save_job($job);
    }
    return $complete;
}

/**
 * Finalize application settings only after files and migrations are complete.
 *
 * Reconcile cached release discovery with the verified installed package locally.
 * Remote discovery is reserved for explicit or due background checks.
 *
 * @param array<string,mixed> $job Job state, updated by reference.
 * @return void Completes channel and cache reconciliation after activation.
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
        : application_update_reconcile_server_policy_files_for_job($job, false, $root);

    application_update_invalidate_opcache($root, $sourceRoot);
    application_patch_notes_clear_cache();
    $installedVersion =
        application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? (string) (
            $job['checkpoints']['validated_version'] ?? ''
        );

    // Finalization must never repeat remote discovery. The verified package tells
    // us what was installed; reconcile the already known release locally.
    try {
        $freshUpdateStatus = application_update_installed_status(
            $installedVersion,
            (string) ($job['parameters']['branch'] ?? ''),
        );
        delete_app_settings([
            'application_update_check_cache',
            'application_update_check_status_json',
            'application_update_check_cached_at',
        ]);
        cache_application_update_check($freshUpdateStatus);
    } catch (Throwable $exception) {
        // Non-fatal: the update itself already succeeded. Worst case, the admin
        // update page falls back to its existing "no cached data yet" placeholder
        // until the next manual or automatic check.
    }

    if ($operation === 'rollback') {
        $sourceJobId = (string) ($job['parameters']['source_job_id'] ?? '');
        $metadata = application_update_read_json(
            application_update_job_dir($sourceJobId)
                . '/rollback/metadata.json',
        );
        $settings = (array) ($metadata['settings_before'] ?? []);
        $channel = (string) ($settings['channel'] ?? 'stable');
        $commit = (string) ($settings['beta_commit'] ?? '');
        if ($channel === 'beta' && $commit !== '') {
            set_app_setting('application_update_channel', 'beta');
            set_app_setting('application_update_beta_commit', $commit);
            set_app_setting(
                'application_update_beta_backup_path',
                (string) ($settings['beta_backup_path'] ?? ''),
            );
        } else {
            delete_app_settings([
                'application_update_channel',
                'application_update_beta_commit',
                'application_update_beta_backup_path',
            ]);
        }
    } elseif ($operation === 'beta_install') {
        $commit = (string) ($job['parameters']['commit'] ?? '');
        set_app_setting('application_update_channel', 'beta');
        set_app_setting('application_update_beta_commit', $commit);
        set_app_setting(
            'application_update_beta_backup_path',
            'cache/updates/jobs/' . (string) $job['id'] . '/rollback',
        );
    } else {
        delete_app_settings([
            'application_update_channel',
            'application_update_beta_commit',
            'application_update_beta_backup_path',
        ]);
    }

    $version =
        application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? (string) (
            $job['checkpoints']['validated_version'] ?? ''
        );
    $reportedVersion = $operation === 'beta_install'
        ? (string) ($job['parameters']['commit'] ?? $version)
        : $version;

    // Request-local metadata caches are cheap to invalidate immediately. Persistent
    // version-sensitive cache directories are atomically moved out of their canonical
    // locations and physically deleted later by the bounded cleanup stage.
    $runtimeCacheActions = [];
    foreach ([
        'translation_runtime' =>
            __NAMESPACE__ . '\\translation_clear_runtime_cache',
        'content_localization_request' =>
            __NAMESPACE__ . '\\content_localization_reset_request_cache',
        'schema_inspection_request' =>
            __NAMESPACE__ . '\\schema_inspection_reset_request_cache',
        'legacy_schema_request' =>
            __NAMESPACE__ . '\\db_schema_helper_reset_request_cache',
        'smart_gallery_graph_request' =>
            __NAMESPACE__ . '\\smart_gallery_graph_cache_clear',
        'thumbnail_maintenance_summary' =>
            __NAMESPACE__ . '\\thumbnail_maintenance_summary_cache_clear',
        'admin_storage_statistics' =>
            __NAMESPACE__ . '\\admin_storage_statistics_cache_clear',
        'gallery_map_runtime' =>
            __NAMESPACE__ . '\\gallery_map_cache_clear_all',
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
    $persistentCacheInvalidation = application_update_invalidate_persistent_caches(
        $root,
        (string) $job['id'],
        $reportedVersion,
    );

    $job['result'] = array_merge((array) ($job['result'] ?? []), [
        'version' => $reportedVersion,
        'branch' => $operation === 'beta_install'
            ? 'beta'
            : (
                $operation === 'rollback'
                    ? 'rollback'
                    : (string) ($job['parameters']['branch'] ?? 'stable')
            ),
        'rollback_of' => $operation === 'rollback'
            ? (string) ($job['parameters']['source_job_id'] ?? '')
            : '',
        'files_copied' => (int) ($job['checkpoints']['activated_files'] ?? 0),
        'removed_paths' => array_values(
            (array) ($job['checkpoints']['obsolete_paths'] ?? []),
        ),
        'removed_count' => count(
            (array) ($job['checkpoints']['obsolete_paths'] ?? []),
        ),
        'backup' => $operation === 'manual_deploy' ? ''
            : 'cache/updates/jobs/' . (string) $job['id'] . '/rollback',
        'migrations' => array_values(
            (array) ($job['result']['migrations'] ?? []),
        ),
        'server_policy_reconciliation' => $serverPolicyReconciliation,
        'cache_invalidation' => [
            'runtime' => $runtimeCacheActions,
            'persistent' => $persistentCacheInvalidation,
        ],
    ]);
    $job['checkpoints']['finalized'] = true;
    if (!empty($job['automatic_rollback'])) {
        $source = application_update_load_job((string) $job['parameters']['source_job_id']);
        $source['result']['recovery_released'] = true;
        $source['result']['recovery_rollback_job_id'] = (string) $job['id'];
        $source['result']['recovery_restored_at'] = time();
        $source['result']['recovery_outcome'] = 'previous_files_restored';
        $source['result']['restored_version'] = $version;
        $source['finished_at'] = time();
        application_update_save_job($source);
        $job['result']['recovery_outcome'] = 'previous_files_restored';
    }
    application_update_save_job($job);
}

/**
 * Commit a usable runtime before resumable physical cleanup begins.
 *
 * The durable checkpoint is written before closing the rollback observation
 * window and removing the owned maintenance marker. Replay may finish those
 * retirements, but may never infer that the previous isolated rollback window
 * remains safe after public admission. No database migration is reversed.
 * @param array{id:string,operation:string,stage:string,checkpoints:array{finalized?:bool,migrations_complete?:bool,runtime_committed?:bool,...<string,mixed>},...<string,mixed>} $job Finalized owner, updated with its cleanup stage and runtime commit.
 * @return void Persists the usable-runtime boundary and retires only its gate.
 * @throws RuntimeException When finalization or forward migrations are unfinished.
 */
function application_update_runtime_commit(array &$job): void
{
    if (empty($job['checkpoints']['finalized'])
        || (($job['operation'] ?? '') !== 'rollback'
            && empty($job['checkpoints']['migrations_complete']))) {
        throw new RuntimeException('Runtime cannot reopen before migrations and finalization complete.');
    }
    $job['stage'] = 'cleanup';
    $job['checkpoints']['runtime_committed'] = true;
    application_update_save_job($job);
    application_update_runtime_window_close($job);
    application_update_activation_gate_clear((string) $job['id']);
}

/**
 * Transfer a partial source activation gate only to its verified rollback.
 * @param array<string,mixed> $job Active automatic rollback with complete ready verification.
 * @return void Preserves the gate until the same rollback durably finishes activation.
 */
function application_update_rollback_gate_takeover(array $job): void
{
    if (empty($job['automatic_rollback']) || !is_file(application_update_activation_gate_path())) {
        return;
    }
    $lock = application_update_acquire_lock(application_update_jobs_root() . '/start.lock');
    if ($lock === null) {
        throw new RuntimeException('Rollback activation ownership is busy.');
    }
    try {
        $marker = application_update_read_json(application_update_activation_gate_path());
        $owner = (string) ($marker['job_id'] ?? '');
        if ($owner === (string) $job['id']) {
            return;
        }
        if (($job['checkpoints']['recovery_ready_verified'] ?? false) !== true
            || $owner !== (string) ($job['parameters']['source_job_id'] ?? '')
            || (application_update_active_job()['id'] ?? '') !== (string) $job['id']) {
            throw new RuntimeException('Rollback activation ownership cannot be verified.');
        }
        application_update_assert_rollback_database(application_update_load_job($owner));
        $marker['job_id'] = (string) $job['id'];
        $marker['trigger'] = 'background';
        $marker['updated_at'] = time();
        application_update_write_json_atomic(application_update_activation_gate_path(), $marker);
    } finally {
        application_update_release_lock($lock);
    }
}


// Recovery responsibilities stay with their existing updater domain owner.

/**
 * Detect an installed replacement that must not resume an older package.
 * @param array{operation?:string,installed_version_at_start?:string,checkpoints?:array<string,mixed>,...<string,mixed>} $job Saved installer intent and validated package version.
 * @return bool True for a newer manual tree or an unidentifiable legacy intent.
 */
function application_update_manual_deployment_detected(array $job): bool
{
    $installed = application_update_version_from_local_bootstrap(
        application_update_project_root() . '/app/bootstrap.php',
    );
    $target = (string) ($job['checkpoints']['validated_version'] ?? '');
    if (($job['operation'] ?? '') === 'manual_deploy') {
        // Another FTP overlay invalidates even a previously verified manual
        // completion. Its old migration/finalization stage must not run blind.
        return $installed !== null && $target !== ''
            && version_compare($installed, $target, '!=');
    }
    if ($installed === null || ($target !== '' && version_compare($installed, $target, '=='))) {
        return false;
    }
    if (isset($job['installed_version_at_start'])) {
        return $job['installed_version_at_start'] !== ''
            && version_compare($installed, (string) $job['installed_version_at_start'], '>');
    }
    // Old jobs did not record their starting release. After a normal old-engine
    // activation their validated target equals installed; an empty target here
    // cannot authorize replacement of an externally deployed complete tree.
    return $target === '' || version_compare($installed, $target, '>');
}

/**
 * Start explicit forward completion of an FTP overlay, retaining old evidence.
 * @param bool $restart Whether the Admin explicitly requests a fresh replacement.
 * @return array<string,mixed> Public replacement, existing manual owner, or waiting state.
 */
function application_update_start_manual_deployment(bool $restart = false): array
{
    return application_update_start_recovery_replacement('manual_deploy', $restart);
}

/**
 * Replace stopped or running updater ownership with an explicit Admin recovery.
 *
 * Worker locks let an in-flight rename or migration finish before takeover.
 * Admission closes before runtime readers drain; activation/migrations retain
 * exclusive runtime ownership before changing installed files or schema.
 * Old jobs, packages, backups and gate receipts remain available for diagnosis.
 * This permanently supports repairs after overwrite-only FTP deployment and
 * forced stable installation, without pretending the old database can roll back.
 *
 * @param string $operation manual_deploy for installed files or stable_restore for GitHub stable.
 * @param bool $restart Whether to supersede an existing manual completion too.
 * @return array<string,mixed> Safe new job or old state with replacement_waiting and replacement_action.
 * @throws RuntimeException When ownership or durable storage cannot be acquired safely.
 */
function application_update_start_recovery_replacement(
    string $operation,
    bool $restart = true,
): array
{
    if (!in_array($operation, ['manual_deploy', 'stable_restore'], true)) {
        throw new RuntimeException('Unsupported recovery replacement operation.');
    }
    if (!feature_capability_effective_enabled('built_in_update_installer')) {
        throw new RuntimeException('Built-in Update Installer is disabled.');
    }
    $start = application_update_acquire_lock(application_update_jobs_root() . '/start.lock');
    if ($start === null) {
        throw new RuntimeException('Updater replacement ownership is busy. Try again.');
    }
    $locks = [];
    try {
        $source = application_update_active_job();
        $markers = [];
        $owners = $source !== null ? [(string) $source['id']] : [];
        foreach (['activation.json' => application_update_activation_gate_path(),
            'rollback-window.json' => application_update_jobs_root() . '/rollback-window.json'] as $name => $path) {
            if (is_file($path)) {
                $bytes = file_get_contents($path);
                if ($bytes === false) {
                    throw new RuntimeException('Could not preserve existing updater marker bytes.');
                }
                $markers[$name] = $bytes;
                $marker = application_update_read_json($path);
                $owner = (string) ($marker['job_id'] ?? '');
                if (preg_match('/^\d{14}-[a-f0-9]{12}$/D', $owner) === 1) {
                    $owners[] = $owner;
                    if ($source === null && is_file(application_update_job_state_path($owner))) {
                        $source = application_update_load_job($owner);
                    }
                }
            }
        }
        if (!$restart && $operation === 'manual_deploy'
            && ($source['operation'] ?? '') === 'manual_deploy'
            && ($source['status'] ?? '') === 'running') {
            return application_update_job_public_state($source);
        }
        $action = $operation === 'manual_deploy' ? 'reset_updater' : 'beta_revert';
        foreach (array_unique($owners) as $owner) {
            $worker = application_update_acquire_lock(application_update_job_dir($owner) . '/worker.lock');
            if ($worker === null) {
                // A separate marker requests a checkpoint pause without rewriting
                // job.json while its current worker still owns that file.
                application_update_write_json_atomic(application_update_job_dir($owner)
                    . '/pause-requested.json', ['requested_at' => time()]);
                $waiting = application_update_job_public_state($source
                    ?? application_update_load_job($owner));
                $waiting['replacement_waiting'] = true;
                $waiting['replacement_action'] = $action;
                $waiting['replacement_waiting_on'] = 'worker';
                return $waiting;
            }
            $locks[] = $worker;
        }
        if ($source !== null) {
            $source = application_update_load_job((string) $source['id']);
        }
        $reservation = (string) ($source['replacement_reservations'][$operation] ?? '');
        if ($reservation !== '' && is_file(application_update_job_state_path($reservation))) {
            $reserved = application_update_load_job($reservation);
            if (($reserved['status'] ?? '') !== 'running') {
                $reservation = '';
            }
        }
        if ($reservation === '') {
            $reservation = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
            if ($source !== null) {
                $source['replacement_reservations'][$operation] = $reservation;
                application_update_save_job($source);
            }
        }
        if (is_file(application_update_job_state_path($reservation))) {
            $job = application_update_load_job($reservation);
            if (!array_key_exists('replaces_job_id', $job)
                && ($job['operation'] ?? '') === $operation
                && ($job['trigger'] ?? '') === 'admin'
                && ($job['status'] ?? '') === 'running'
                && ($job['stage'] ?? '') === 'download'
                && ($job['attempts'] ?? -1) === 0) {
                // create_job persists its initial state before this owner can
                // add the link. The parent's exact reservation proves that
                // zero-slice child belongs to this interrupted takeover.
                $job['replaces_job_id'] = (string) ($source['id'] ?? '');
            }
            if (($job['operation'] ?? '') !== $operation
                || ($job['replaces_job_id'] ?? '') !== ($source['id'] ?? '')) {
                throw new RuntimeException('Updater replacement reservation is inconsistent.');
            }
        } else {
            $parameters = $operation === 'manual_deploy' ? []
                : application_update_job_parameters('stable_restore', []);
            $job = application_update_create_job($operation, $parameters, 'admin', $reservation);
        }
        $job['recovery_override'] = true;
        $job['replaces_job_id'] = (string) ($source['id'] ?? '');
        $job['stage'] = $operation === 'manual_deploy' ? 'package_validate' : 'download';
        $job['manual_deployment_source_job_id'] = (string) ($source['id'] ?? '');
        // Publish the complete reservation/link before either global marker.
        // A killed takeover can then adopt the same fully identified child.
        application_update_save_job($job);
        foreach ($markers as $name => $bytes) {
            $receipt = application_update_job_dir($job['id']) . '/superseded-' . $name;
            if (!is_file($receipt) && file_put_contents($receipt, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('Could not preserve replaced updater marker.');
            }
        }
        // Close admission before waiting for ordinary readers. Taking the
        // exclusive runtime lease first can starve forever on an active site.
        // Worker locks exclude old updater writes; the actual activation or
        // FTP migration still takes that exclusive lease after readers drain.
        application_update_write_json_atomic(application_update_jobs_root() . '/rollback-window.json', [
            'job_id' => $job['id'], 'admin_session_name' => $job['admin_session_name'],
            'opened_at' => time(),
        ]);
        application_update_write_json_atomic(application_update_activation_gate_path(), [
            'schema' => 1, 'job_id' => $job['id'], 'trigger' => 'admin',
            'admin_session_name' => $job['admin_session_name'],
            'recovery_isolation' => true, 'started_at' => time(), 'updated_at' => time(),
        ]);
        $job['checkpoints']['recovery_admission_closed'] = true;
        $job['checkpoints']['runtime_guard_open'] = false;
        $job['checkpoints']['runtime_guard_protocol'] = 1;
        application_update_save_job($job);
        foreach (array_unique($owners) as $owner) {
            if ($owner === $job['id']) {
                // A crash may already have transferred one global marker to
                // this reservation. Its child is not a predecessor to cancel.
                continue;
            }
            if (!is_file(application_update_job_state_path($owner))) {
                continue;
            }
            $previous = application_update_load_job($owner);
            $previous['replacement_previous_status'] ??= $previous['status'];
            $previous['status'] = 'cancelled';
            $previous['finished_at'] = time();
            $previous['result']['superseded_by'] = $job['id'];
            application_update_save_job($previous);
        }
        application_update_set_active_job($job['id']);
        if (function_exists(__NAMESPACE__ . '\\admin_log_event')) {
            try {
                admin_log_event('info', 'update.recovery_replacement_started',
                    'Admin recovery replaced the previous updater ownership.',
                    ['job_id' => $job['id'], 'operation' => $operation,
                        'manifest_policy' => 'not_required_for_explicit_recovery',
                        'previous_jobs' => array_values(array_unique($owners))],
                    ['category' => 'update', 'severity' => 'notice']);
            } catch (Throwable) {
                // Durable links and preserved markers do not depend on logging.
            }
        }
        return application_update_job_public_state($job);
    } finally {
        foreach (array_reverse($locks) as $lock) {
            application_update_release_lock($lock);
        }
        application_update_release_lock($start);
    }
}

/**
 * Gate forward migrations for accepted FTP files without a publisher manifest.
 * @param array{id:string,manual_deployment_source_job_id?:string,checkpoints:array<string,mixed>,...<string,mixed>} $job Explicit manual deployment, updated after old readers finish.
 * @return bool False while an ordinary request owns the runtime; true when gated.
 */
function application_update_manual_deployment_gate(array &$job): bool
{
    $runtime = application_update_acquire_lock(application_update_jobs_root() . '/runtime-use.lock');
    if ($runtime === null) {
        $job['checkpoints']['runtime_lease_busy'] = true;
        application_update_save_job($job);
        return false;
    }
    try {
        if (empty($job['checkpoints']['manual_deployment_accepted'])) {
            throw new RuntimeException('Manual deployment has not been accepted for forward completion.');
        }
        $sourceId = (string) ($job['manual_deployment_source_job_id'] ?? '');
        foreach (['activation.json' => application_update_activation_gate_path(),
            'rollback-window.json' => application_update_jobs_root() . '/rollback-window.json'] as $name => $path) {
            if (!is_file($path)) {
                continue;
            }
            $marker = application_update_read_json($path);
            if (!in_array($marker['job_id'] ?? '', [$job['id'], $sourceId], true)
                || ($marker['job_id'] ?? '') === '') {
                throw new RuntimeException('Manual deployment gate owner is unverified.');
            }
            if (($marker['job_id'] ?? '') === $sourceId) {
                // Retain the original marker bytes as evidence; never discard
                // the old job, ledger, package, backup or local extra files.
                application_update_write_json_atomic(application_update_job_dir($sourceId)
                    . '/manual-deployment-' . $name, $marker);
            }
        }
        application_update_write_json_atomic(application_update_jobs_root() . '/rollback-window.json', [
            'job_id' => $job['id'], 'admin_session_name' => $job['admin_session_name'] ?? '',
            'opened_at' => time(),
        ]);
        $job['checkpoints']['runtime_guard_open'] = true;
        $job['checkpoints']['runtime_guard_protocol'] = 1;
        unset($job['checkpoints']['runtime_lease_busy']);
        // Only the matching preserved old gate may be transferred to this
        // verified forward-completion job. Public admission remains closed.
        application_update_write_json_atomic(application_update_activation_gate_path(), [
            'schema' => 1, 'job_id' => $job['id'], 'trigger' => 'admin',
            'admin_session_name' => $job['admin_session_name'] ?? '',
            'recovery_isolation' => true, 'started_at' => time(), 'updated_at' => time(),
        ]);
        application_update_save_job($job);
        return true;
    } finally {
        application_update_release_lock($runtime);
    }
}

/**
 * Observe migration evidence without running migrations or interpreting PHP.
 * @param string $root Installed or validated package root for applied files.
 * @param bool $includeHashes Whether installed migration bytes should also be captured.
 * @return array{format:int,state:string,versions:list<string>,ledger:string,hashes:array<string,string|null>,migration_manifest:string,schema:string} Complete ledger fingerprint, applied bytes or proven historical absence, installed manifest identity and actual schema.
 * @throws RuntimeException When evidence is unknown, oversized or incomplete.
 */
function application_update_recovery_database_evidence(string $root, bool $includeHashes = true): array
{
    // Bypass request-local observations at this irreversible boundary.
    schema_inspection_reset_request_cache();
    $state = (string) (application_update_activation_schema_status()['state']
        ?? 'unknown');
    if (!in_array($state, ['available', 'missing'], true)) {
        throw new RuntimeException('Recovery database evidence is unknown.');
    }
    $ledger = $state === 'missing' ? []
        : \Gallery\Models\schema_inspection_model_migration_ledger(
            APPLICATION_UPDATE_RECOVERY_MIGRATION_LIMIT,
        );
    $versions = array_column($ledger, 'version');
    $manifest = [];
    $manifestIdentity = '';
    if ($includeHashes && is_file($root . '/app/core-manifest.json')) {
        application_update_assert_safe_target($root, 'app/core-manifest.json');
        $manifestSize = filesize($root . '/app/core-manifest.json');
        if ($manifestSize === false || $manifestSize > APPLICATION_UPDATE_RECOVERY_MANIFEST_BYTES) {
            throw new RuntimeException('Recovery installed migration inventory is oversized.');
        }
        $manifest = application_update_read_json($root . '/app/core-manifest.json');
        if (($manifest['algorithm'] ?? '') !== 'sha256'
            || ($manifest['hash_mode'] ?? '') !== 'normalized-text-sha256'
            || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Recovery installed migration inventory is invalid.');
        }
        $manifestIdentity = application_update_manifest_hash($root . '/app/core-manifest.json');
    }
    $hashes = [];
    foreach ($versions as $version) {
        if (!$includeHashes) {
            continue;
        }
        if (preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', $version) !== 1) {
            throw new RuntimeException('Recovery migration identity is invalid.');
        }
        $relative = 'database/migrations/' . $version . '.php';
        application_update_assert_safe_target($root, $relative, true);
        if (!file_exists($root . '/' . $relative)) {
            // DB-2 supports applied rows retired from this installed release.
            // The installed manifest, never the incoming package, proves that
            // absence was intentional. Missing declared files still fail.
            if ($manifestIdentity === ''
                || array_key_exists($relative, $manifest['files'])) {
                throw new ApplicationUpdateMigrationEvidenceException('missing_file', $version);
            }
            $hashes[$version] = null;
            continue;
        }
        $hash = application_update_manifest_hash($root . '/' . $relative);
        if (isset($manifest['files'][$relative])
            && $manifest['files'][$relative] !== $hash) {
            throw new ApplicationUpdateMigrationEvidenceException('changed_file', $version);
        }
        $hashes[$version] = application_update_recovery_raw_hash($root . '/' . $relative);
    }
    return [
        'format' => 2, // Evidence revision; unversioned saved witnesses remain readable.
        'state' => $state,
        'versions' => $versions,
        'ledger' => hash('sha256', json_encode($ledger, JSON_THROW_ON_ERROR)),
        'hashes' => $hashes,
        'migration_manifest' => $manifestIdentity,
        'schema' => \Gallery\Models\schema_inspection_model_recovery_schema(
            APPLICATION_UPDATE_RECOVERY_SCHEMA_ROWS,
        ),
    ];
}

/**
 * Classify the proven boundary of a failed source, without changing its state.
 * @param array<string,mixed> $job Failed source and durable activation evidence.
 * @param string $successorId Optional owner of a replayed successor gate.
 * @return string pre_activation, activation_not_started_candidate (requires locked byte proof), rollback_safe, or a stable blocking reason.
 */
function application_update_recovery_boundary(array $job, string $successorId = ''): string
{
    $stage = (string) ($job['stage'] ?? '');
    $stages = application_update_job_stages();
    $index = array_search($stage, $stages, true);
    $activation = array_search('activate', $stages, true);
    $marker = application_update_activation_gate_path();
    $gate = is_file($marker) ? application_update_read_json($marker) : null;
    if ($gate !== null && (!is_array($gate) || $gate === []
        || (string) ($gate['job_id'] ?? '') === ''
        || !in_array((string) ($gate['job_id'] ?? ''),
            [(string) ($job['id'] ?? ''), $successorId], true))) {
        return 'activation_owner_unverified';
    }
    if (!is_int($index) || !is_int($activation)) {
        return 'unknown_checkpoint';
    }
    if ($index < $activation && $gate === null
        && empty($job['checkpoints']['activation_complete'])) {
        return 'pre_activation';
    }
    if ($stage === 'activate' && $gate === null
        && empty($job['checkpoints']['activation_complete'])
        && empty($job['checkpoints']['activation_started'])
        && ($job['checkpoints']['migrations_started'] ?? null) === false) {
        return 'activation_not_started_candidate';
    }
    // A missing witness is never upgraded into proof that a migration did not run.
    $noMigrationRan = ($job['checkpoints']['migrations_complete'] ?? false) === true
        && ($job['result']['migrations'] ?? null) === [];
    if (($job['checkpoints']['migrations_started'] ?? null) !== false
        && !$noMigrationRan) {
        return 'migration_state_unverified';
    }
    if (($job['checkpoints']['backup_complete'] ?? false) !== true
        || empty($job['checkpoints']['database_before_activation'])) {
        return 'backup_or_database_unverified';
    }
    return 'rollback_safe';
}

/**
 * Prove an old activate checkpoint never changed its planned active files.
 *
 * The caller holds start, worker and exclusive runtime locks. Gate absence is
 * only a candidate: complete original bytes and created-path absence must also
 * match. This supports old producers without inventing activation receipts.
 * @param array{id:string,checkpoints:array<string,mixed>,...<string,mixed>} $job Failed source with complete original snapshot evidence.
 * @return void Refuses uncertain boundaries, altered files or incomplete backups.
 */
function application_update_assert_activation_unchanged(array $job): void
{
    if (application_update_recovery_boundary($job) !== 'activation_not_started_candidate'
        || ($job['checkpoints']['backup_complete'] ?? false) !== true) {
        throw new RuntimeException('Recovery unchanged activation is unverified.');
    }
    $directory = application_update_job_dir($job['id']);
    $metadataPath = $directory . '/rollback/metadata.json';
    $metadata = application_update_read_json($metadataPath);
    $hashes = $job['checkpoints']['backup_hashes'] ?? null;
    $created = $job['checkpoints']['created_paths'] ?? null;
    $items = $job['checkpoints']['backup_items'] ?? null;
    if (($metadata['job_id'] ?? '') !== $job['id']
        || ($job['checkpoints']['backup_metadata_identity'] ?? '')
            !== application_update_manifest_hash($metadataPath)
        || !is_array($hashes) || !is_array($created) || !is_array($items)
        || ($metadata['hashes'] ?? null) !== $hashes
        || ($metadata['created_paths'] ?? null) !== array_keys($created)
        || ($metadata['activation_files'] ?? null)
            !== ($job['checkpoints']['activation_files'] ?? null)
        || ($metadata['obsolete_paths'] ?? null)
            !== ($job['checkpoints']['obsolete_paths'] ?? null)
        || array_diff($metadata['activation_files'], $items) !== []
        || array_diff($items, array_merge(array_keys($hashes), array_keys($created))) !== []
        || array_diff(array_keys($hashes), $items) !== []
        || array_diff(array_keys($created), $items) !== []
        || array_intersect(array_keys($hashes), array_keys($created)) !== []) {
        throw new RuntimeException('Recovery original activation evidence is incomplete.');
    }
    $root = application_update_project_root();
    foreach ($items as $relative) {
        application_update_assert_safe_target($root, $relative, true);
        if (array_key_exists($relative, $created)) {
            if ($created[$relative] !== true || file_exists($root . '/' . $relative)) {
                throw new RuntimeException('Recovery created activation path is present.');
            }
            continue;
        }
        application_update_assert_safe_target($directory, 'rollback/original/' . $relative);
        if (!is_string($hashes[$relative])
            || !application_update_recovery_hash_matches($hashes[$relative], $directory . '/rollback/original/' . $relative)
            || !is_file($root . '/' . $relative)
            || !application_update_recovery_hash_matches($hashes[$relative], $root . '/' . $relative)) {
            throw new RuntimeException('Recovery original activation bytes changed.');
        }
    }
    foreach ($metadata['obsolete_paths'] as $relative) {
        application_update_assert_safe_target($root, $relative, true);
        if (is_file($root . '/' . $relative) && !in_array($relative, $items, true)) {
            throw new RuntimeException('Recovery obsolete file evidence is incomplete.');
        }
    }
    $windowPath = application_update_jobs_root() . '/rollback-window.json';
    if (is_file($windowPath)
        && (application_update_read_json($windowPath)['job_id'] ?? '') !== $job['id']) {
        throw new RuntimeException('Recovery runtime window ownership is unverified.');
    }
}

/**
 * Require proven rollback compatibility and immutable source backup metadata.
 * @param array{automatic_rollback?:bool,id:string,recovery?:array{source_job_id?:string},checkpoints:array<string,mixed>,...<string,mixed>} $job Ordinary package or linked automatic rollback.
 * @param string $sourceRoot Validated package or indexed original snapshot root.
 * @return void Refuses unknown DB/runtime state or missing backup evidence; bounded byte verification belongs to the ready slice.
 */
function application_update_recovery_assert_compatible(array $job, string $sourceRoot): void
{
    if (empty($job['automatic_rollback'])) {
        return;
    }
    $sourceId = (string) ($job['recovery']['source_job_id'] ?? '');
    $source = application_update_load_job($sourceId);
    if (($source['result']['superseded_by'] ?? '') !== ($job['id'] ?? '')
        || application_update_recovery_boundary($source, (string) $job['id']) !== 'rollback_safe') {
        throw new RuntimeException('Recovery rollback ownership or checkpoint changed.');
    }
    application_update_assert_rollback_database($source);
    $directory = application_update_job_dir($sourceId);
    if ($sourceRoot !== $directory . '/rollback/original') {
        throw new RuntimeException('Recovery rollback source identity changed.');
    }
    $metadata = application_update_read_json($directory . '/rollback/metadata.json');
    $hashes = $metadata['hashes'] ?? null;
    if (($metadata['job_id'] ?? '') !== $sourceId
        || ($source['checkpoints']['backup_metadata_identity'] ?? '')
            !== application_update_manifest_hash($directory . '/rollback/metadata.json')
        || !is_array($hashes)
        || $hashes !== ($source['checkpoints']['backup_hashes'] ?? null)) {
        throw new RuntimeException('Recovery rollback evidence is missing or corrupt.');
    }
    foreach ((array) ($source['checkpoints']['backup_items'] ?? []) as $relative) {
        if (empty($source['checkpoints']['created_paths'][$relative])
            && !isset($hashes[$relative])) {
            throw new RuntimeException('Recovery rollback evidence is incomplete.');
        }
    }
    foreach ($hashes as $relative => $hash) {
        application_update_assert_safe_target($directory, 'rollback/original/' . $relative);
        if (!is_file($directory . '/rollback/original/' . $relative)) {
            throw new RuntimeException('Recovery rollback file is missing.');
        }
    }
    if (!empty($job['checkpoints']['recovery_ready_verified'])
        && ($job['checkpoints']['recovery_backup_identity'] ?? '')
            !== application_update_manifest_hash($directory . '/rollback/metadata.json')) {
        throw new RuntimeException('Recovery rollback evidence changed after verification.');
    }
}

/**
 * Verify prepared files and retained source backups in resumable ready slices.
 * @param array{id:string,checkpoints:array<string,mixed>,...<string,mixed>} $job Recovery job with staged manifest hashes and a durable cursor.
 * @param array{deadline:float,seconds:float,...<string,int|float|string>} $budget Bounded worker opportunity.
 * @return bool True after all bytes pass; false preserves a ready-stage cursor.
 * @throws RuntimeException When any immutable evidence or file hash changes.
 */
function application_update_recovery_ready_slice(array &$job, array $budget): bool
{
    if (!empty($job['recovery_override'])
        || !empty($job['checkpoints']['publisher_integrity_disabled'])) {
        // Staging already committed each complete copy and recorded its actual
        // raw bytes. A disabled publisher pass or explicit recovery needs no
        // second package-wide sweep before the actual activation copy checks.
        $job['checkpoints']['recovery_ready_verified'] = true;
        application_update_save_job($job);
        return true;
    }
    if (empty($job['automatic_rollback'])
        && !(($job['recovery_schema'] ?? 0) === 1 && ($job['operation'] ?? '') !== 'rollback')) {
        return true;
    }
    application_update_recovery_assert_compatible($job,
        (string) ($job['checkpoints']['source_root'] ?? ''));
    $directory = application_update_job_dir((string) $job['id']);
    $items = [];
    foreach ((array) ($job['checkpoints']['activation_files'] ?? []) as $relative) {
        $expected = $relative === 'app/core-manifest.json'
            ? (string) ($job['checkpoints']['recovery_manifest_identity'] ?? '')
            : (string) ($job['checkpoints']['recovery_hashes'][$relative] ?? '');
        $items[] = [$directory, 'ready/' . $relative, $expected];
    }
    if (!empty($job['automatic_rollback'])) {
        $sourceDirectory = application_update_job_dir((string) $job['recovery']['source_job_id']);
        $metadata = application_update_read_json($sourceDirectory . '/rollback/metadata.json');
        $identity = application_update_manifest_hash($sourceDirectory . '/rollback/metadata.json');
        if (($job['checkpoints']['recovery_backup_identity'] ?? $identity) !== $identity) {
            throw new RuntimeException('Recovery rollback evidence changed during verification.');
        }
        $job['checkpoints']['recovery_backup_identity'] = $identity;
        foreach ((array) ($metadata['hashes'] ?? []) as $relative => $hash) {
            $items[] = [$sourceDirectory, 'rollback/original/' . $relative, (string) $hash];
        }
    }
    $index = (int) ($job['checkpoints']['recovery_ready_index'] ?? 0);
    $processed = 0;
    while ($index < count($items)
        && $processed < APPLICATION_UPDATE_RECOVERY_VERIFY_FILES
        && application_update_budget_allows($budget, 0.7)) {
        [$base, $relative, $expected] = $items[$index];
        application_update_assert_safe_target($base, $relative);
        if ($expected === '' || !is_file($base . '/' . $relative)
            || !application_update_recovery_hash_matches($expected, $base . '/' . $relative)) {
            throw new RuntimeException('Recovery safety file failed integrity verification.');
        }
        $index++;
        $processed++;
    }
    $job['checkpoints']['recovery_ready_index'] = $index;
    $job['checkpoints']['recovery_ready_verified'] = $index === count($items);
    $job['progress'] = [
        'current' => $index, 'total' => count($items),
        'message' => 'Verifying recovery files and retained backups.', 'unit' => 'files',
    ];
    application_update_save_job($job);
    return $job['checkpoints']['recovery_ready_verified'];
}

/**
 * Check the unchanged database required before restoring previous files.
 * @param array<string,mixed> $source Original job with a pre-activation witness.
 * @return void Refuses committed public runtime, entered/unproven migrations or changed database evidence.
 */
function application_update_assert_rollback_database(array $source): void
{
    if (!empty($source['checkpoints']['runtime_committed'])) {
        throw new RuntimeException('Recovery runtime was already committed for public use.');
    }
    $id = (string) $source['id'];
    $directory = application_update_job_dir($id);
    $window = application_update_read_json(application_update_jobs_root() . '/rollback-window.json');
    if (($source['checkpoints']['runtime_guard_protocol'] ?? 0) !== 1
        || empty($source['checkpoints']['runtime_guard_open'])
        || ($window['job_id'] ?? '') !== $id
        || is_file($directory . '/runtime-used.json')
        || is_file($directory . '/runtime-window-closed.json')) {
        throw new RuntimeException('Recovery database writes cannot be excluded: runtime isolation is unverified.');
    }
    $baseline = $source['checkpoints']['database_before_activation'] ?? null;
    if (!is_array($baseline)) {
        throw new RuntimeException('Recovery database baseline is missing.');
    }
    $started = $source['checkpoints']['migrations_started'] ?? null;
    $noMigrationsRan = ($source['checkpoints']['migrations_complete'] ?? false) === true
        && ($source['result']['migrations'] ?? null) === [];
    if ($started !== false && !$noMigrationsRan) {
        throw new RuntimeException('Recovery database migration effects are unverified.');
    }
    $hashes = $baseline['hashes'] ?? null;
    if (!is_array($hashes) || array_keys($hashes) !== ($baseline['versions'] ?? null)) {
        throw new RuntimeException('Recovery applied migration file evidence is incomplete.');
    }
    $format = $baseline['format'] ?? 1;
    if (!in_array($format, [1, 2], true)) {
        throw new RuntimeException('Recovery database evidence format is unsupported.');
    }
    $manifest = [];
    if ($format === 2 && ($baseline['migration_manifest'] ?? '') !== '') {
        $relative = 'app/core-manifest.json';
        $manifestRoot = isset($source['checkpoints']['backup_hashes'][$relative])
            ? $directory . '/rollback/original' : application_update_project_root();
        application_update_assert_safe_target($manifestRoot, $relative);
        if (!hash_equals((string) $baseline['migration_manifest'],
            application_update_manifest_hash($manifestRoot . '/' . $relative))) {
            throw new RuntimeException('Recovery original migration inventory changed.');
        }
        $manifest = application_update_read_json($manifestRoot . '/' . $relative);
    }
    foreach ($hashes as $version => $expected) {
        if (preg_match('/^[0-9]{12}_[a-z0-9_]+$/D', (string) $version) !== 1) {
            throw new RuntimeException('Recovery applied migration identity is invalid.');
        }
        $relative = 'database/migrations/' . $version . '.php';
        if ($expected === null && $format === 2) {
            $created = !empty($source['checkpoints']['created_paths'][$relative]);
            application_update_assert_safe_target(application_update_project_root(), $relative, true);
            if (!is_array($manifest['files'] ?? null)
                || array_key_exists($relative, $manifest['files'])
                || isset($source['checkpoints']['backup_hashes'][$relative])
                || (!$created && file_exists(application_update_project_root() . '/' . $relative))) {
                throw new RuntimeException('Recovery historical migration absence changed.');
            }
            continue;
        }
        $fileRoot = isset($source['checkpoints']['backup_hashes'][$relative])
            ? $directory . '/rollback/original' : application_update_project_root();
        application_update_assert_safe_target($fileRoot, $relative);
        if (!is_string($expected)
            || !application_update_recovery_hash_matches($expected, $fileRoot . '/' . $relative)) {
            throw new RuntimeException('Recovery applied migration file changed.');
        }
    }
    // Active files may be a mixed tree. Their hashes cannot establish database
    // rollback safety; the original backup hashes establish restored file bytes.
    unset($baseline['hashes'], $baseline['migration_manifest']);
    $actual = application_update_recovery_database_evidence(
        application_update_project_root(), false,
    );
    unset($actual['hashes'], $actual['migration_manifest']);
    if ($format === 1) {
        // Old witnesses prove versions/schema and all applied bytes, but never
        // invent a timestamp baseline or historical-absence proof retroactively.
        unset($actual['format'], $actual['ledger']);
        unset($baseline['format']);
    }
    if ($actual !== $baseline) {
        throw new RuntimeException('Recovery database state changed.');
    }
}

/**
 * Open a rollback observation window while holding exclusive runtime ownership.
 * @param array<string,mixed> $job Active job with verified staged package checkpoints.
 * @return void Persists pre-activation DB and cooperative isolation evidence once.
 */
function application_update_runtime_window_begin(array &$job): void
{
    if (!empty($job['automatic_rollback'])) {
        application_update_assert_rollback_database(application_update_load_job(
            (string) $job['parameters']['source_job_id'],
        ));
        $job['checkpoints']['runtime_guard_open'] = true;
        return;
    }
    if (($job['recovery_schema'] ?? 0) !== 1) {
        return;
    }
    $path = application_update_jobs_root() . '/rollback-window.json';
    $existing = application_update_read_json($path);
    if ($existing !== [] && ($existing['job_id'] ?? '') !== $job['id']) {
        throw new RuntimeException('Another recovery runtime window is active.');
    }
    if (!empty($job['recovery_override'])) {
        $gate = application_update_read_json(application_update_activation_gate_path());
        if (($existing['job_id'] ?? '') !== $job['id']
            || ($gate['job_id'] ?? '') !== $job['id']
            || empty($gate['recovery_isolation'])) {
            throw new RuntimeException('Explicit recovery admission is missing. Reset the updater.');
        }
        // Called under the actual exclusive activation lease: prior readers
        // have now drained. Never inspect a publisher manifest or manufacture
        // an old database witness for this forward-only operation.
        $job['checkpoints']['runtime_guard_open'] = true;
        $job['checkpoints']['runtime_guard_protocol'] = 1;
        application_update_save_job($job);
        return;
    }
    if (!empty($job['checkpoints']['runtime_guard_open'])) {
        if (($existing['job_id'] ?? '') !== $job['id']) {
            throw new RuntimeException('Recovery runtime window is missing.');
        }
        return;
    }
    $root = (string) ($job['checkpoints']['source_root'] ?? '');
    $early = is_file($root . '/app/early_runtime.php')
        ? (string) file_get_contents($root . '/app/early_runtime.php', false, null,
            0, APPLICATION_UPDATE_RECOVERY_PROTOCOL_BYTES) : '';
    $bootstrap = is_file($root . '/app/bootstrap.php')
        ? (string) file_get_contents($root . '/app/bootstrap.php', false, null,
            0, APPLICATION_UPDATE_RECOVERY_PROTOCOL_BYTES) : '';
    // A trusted package's protocol declaration is an authored compatibility
    // promise, independently checked after complete package byte verification.
    // Legacy packages may still install, but cannot promise automatic rollback.
    $job['checkpoints']['runtime_guard_protocol'] =
        str_contains($early, 'const UPDATER_RUNTIME_ISOLATION_PROTOCOL = 1;')
        && str_contains($bootstrap, 'updater_runtime_enter(') ? 1 : 0;
    $job['checkpoints']['database_before_activation'] =
        application_update_recovery_database_evidence(application_update_project_root());
    $historicalVersions = array_keys(array_filter(
        $job['checkpoints']['database_before_activation']['hashes'],
        static fn(?string $hash): bool => $hash === null,
    ));
    if ($historicalVersions !== []
        && function_exists(__NAMESPACE__ . '\\admin_log_event')) {
        try {
            admin_log_event('info', 'update.historical_migrations_preserved',
                'Historical applied migrations are supported; activation continues.',
                ['job_id' => (string) $job['id'], 'versions' => $historicalVersions],
                ['category' => 'update', 'severity' => 'notice']);
        } catch (Throwable) {
            // Optional diagnostic logging never blocks a supported upgrade.
        }
    }
    application_update_write_json_atomic($path, [
        'job_id' => (string) $job['id'],
        'admin_session_name' => (string) ($job['admin_session_name'] ?? ''),
        'opened_at' => time(),
    ]);
    $job['checkpoints']['runtime_guard_open'] = true;
    application_update_save_job($job);
}

/**
 * Retire a completed isolation window without losing the historical safety receipt.
 * @param array<string,mixed> $job Completed original or automatic rollback job.
 * @return void Closes only the matching window; preserves evidence before unlink.
 */
function application_update_runtime_window_close(array $job): void
{
    $sourceId = !empty($job['automatic_rollback'])
        ? (string) $job['parameters']['source_job_id'] : (string) $job['id'];
    $path = application_update_jobs_root() . '/rollback-window.json';
    $window = application_update_read_json($path);
    if (($window['job_id'] ?? '') !== $sourceId) {
        return;
    }
    application_update_write_json_atomic(application_update_job_dir($sourceId)
        . '/runtime-window-closed.json', ['completed_job_id' => (string) $job['id'], 'at' => time()]);
    if (!@unlink($path) && is_file($path)) {
        throw new RuntimeException('Recovery runtime window could not be closed.');
    }
}
