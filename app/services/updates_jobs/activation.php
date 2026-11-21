<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/activation.php
 * Module Type: Service
 *
 * Purpose:
 *   Replaces prepared application files during live operation.
 *
 * Responsibilities:
 *   - Publish prepared files atomically while ordinary requests remain admitted
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
 * Promise that this owner invalidates rewrite observations before marker mutations.
 * Type: boolean.
 * Units: writer-contract support flag. Scope: this loaded implementation only.
 * Consumers: request-local rewrite observation compatibility detection.
 * Rationale: loading a compliant writer is read-only; invoking a potentially
 *     mutating boundary permanently latches bypass before writes or failures.
 */
const UPDATE_ACTIVATION_INVALIDATES_REWRITE_OBSERVATIONS = true;

/**
 * Refuse packages that would reinstall the old visitor maintenance gate.
 *
 * Trusted GitHub archives may select historical commits. Inspect the canonical
 * early runtime both during package validation and when resuming prepared work.
 *
 * @param string $sourceRoot Validated package or staged-file root.
 * @return void Accepts sources without the historical gate.
 * @throws RuntimeException When the early runtime is unreadable or gates traffic.
 */
function application_update_assert_online_package(string $sourceRoot): void
{
    $relative = 'app/early_runtime.php';
    $path = $sourceRoot . '/' . $relative;
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    application_update_assert_safe_target($sourceRoot, $relative);
    $size = filesize($path);
    if ($size === false || $size > APPLICATION_UPDATE_RECOVERY_PROTOCOL_BYTES) {
        throw new RuntimeException('The update archive early runtime cannot be verified.');
    }
    $source = file_get_contents($path);
    if ($source === false || str_contains($source, 'function emit_activation_unavailable(')) {
        throw new RuntimeException('The update archive would restore the visitor maintenance gate.');
    }
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
    if (($job['recovery_schema'] ?? 0) === 1
        && empty($job['checkpoints']['recovery_ready_verified'])) {
        throw new RuntimeException('Recovery readiness verification is incomplete.');
    }
    application_update_assert_activation_schema_known(
        'application_update.job_activation_ready',
    );
    application_update_assert_gallery_edit_enforcement(
        (string) ($job['checkpoints']['source_root'] ?? ''),
    );
    application_update_assert_online_package($jobDir . '/ready');
}

/**
 * Return the priority used for the activation critical section.
 *
 * Dependencies are replaced before the module map, bootstrap and public entry
 * points. The worker replaces one complete file at a time. If the host kills PHP,
 * replay detects already-committed files by hash and completes the remaining work.
 *
 * @param string $path Relative path.
 * @return int Sort priority.
 */
function application_update_activation_priority(string $path): int
{
    // Structural ordering scales from dependency leaves to public entry points.
    return match ($path) {
        'index.php' => 50,
        'install.php', 'reset.php' => 45,
        'public/index.php' => 40,
        'app/early_runtime.php' => 35,
        'app/bootstrap.php' => 30,
        'app/runtime/modules.php' => 25,
        'app/services/updates.php' => 20,
        default => 0,
    };
}

/**
 * Publish prepared files individually while ordinary requests remain admitted.
 *
 * No remote I/O, extraction, hashing of the complete package, backup construction,
 * or migration execution happens here. Each file replacement uses a sibling
 * temporary file plus rename(), and replay treats a destination matching the
 * prepared release hash as already committed.
 *
 * @param array{
 *     id:string,
 *     operation?:string,
 *     trigger?:string,
 *     admin_session_name?:string,
 *     automatic_rollback?:bool,
 *     recovery_schema?:int,
 *     recovery_override?:bool,
 *     parameters?:array{source_job_id?:string},
 *     recovery?:array{source_job_id?:string},
 *     checkpoints:array{
 *         source_root?:string,
 *         backup_complete?:bool,
 *         activation_files?:list<string>,
 *         obsolete_paths?:list<string>,
 *         activation_started?:bool,
 *         activation_complete?:bool,
 *         activated_files?:int,
 *         recovery_ready_verified?:bool,
 *         recovery_backup_identity?:string,
 *         publisher_integrity_disabled?:bool,
 *         recovery_hashes?:array<string,string>,
 *         recovery_manifest_identity?:string,
 *         database_before_activation?:array{
 *             format:int,state:string,versions:list<string>,ledger:string,
 *             hashes:array<string,string|null>,migration_manifest:string,
 *             schema:string
 *         }
 *     },
 *     progress?:array{
 *         current:int,total:int,percent?:int|float,message:string,unit?:string
 *     }
 * } $job Durable fields consumed or updated by activation and its admission,
 *     recovery-readiness and activation evidence delegates. Preserves other durable
 *     job fields while saving checkpoints and file-count progress by reference.
 * @return bool True after the complete activation, preserving the worker's existing stage API.
 * @throws RuntimeException When the prepared package, backup or replacement is unsafe.
 */
function application_update_job_activate(array &$job): bool
{
    // Never reuse marker observations after a writer starts, including partial
    // failure or a live update that leaves an older settings owner in memory.
    $GLOBALS['cms_url_rewrite_writer_active'] = true;
    unset($GLOBALS['cms_url_rewrite_request_observation']);

    if (($job['operation'] ?? '') === 'rollback') {
        throw new RuntimeException('File rollback is unavailable during online updates.');
    }
    unset($job['checkpoints']['runtime_lease_busy']);
    application_update_job_assert_ready($job);
    if (($job['recovery_schema'] ?? 0) === 1 && empty($job['recovery_override'])
        && !isset($job['checkpoints']['database_before_activation'])) {
        application_update_capture_activation_evidence($job);
    }
    // Persist intent before the first rename. A restart can verify and skip
    // matching destinations without assuming it was the first worker.
    $job['checkpoints']['activation_started'] = true;
    application_update_save_job($job);
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
        if (($job['recovery_schema'] ?? 0) === 1) {
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
    return true;
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
    $serverPolicyReconciliation = application_update_reconcile_server_policy_files_for_job(
        $job,
        false,
        $root,
    );

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

    if ($operation === 'beta_install') {
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
            ? 'beta' : (string) ($job['parameters']['branch'] ?? 'stable'),
        'rollback_of' => '',
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
    application_update_save_job($job);
}

/**
 * Record forward installation completion before optional physical cleanup.
 *
 * @param array{id:string,operation:string,stage:string,checkpoints:array{finalized?:bool,migrations_complete?:bool,runtime_committed?:bool,...<string,mixed>},...<string,mixed>} $job Finalized owner, updated with its cleanup stage and completion receipt.
 * @return void Persists completion of the files, migrations and finalization.
 * @throws RuntimeException When finalization or forward migrations are unfinished.
 */
function application_update_runtime_commit(array &$job): void
{
    if (empty($job['checkpoints']['finalized'])
        || empty($job['checkpoints']['migrations_complete'])) {
        throw new RuntimeException('Update cannot commit before migrations and finalization complete.');
    }
    $job['stage'] = 'cleanup';
    $job['checkpoints']['runtime_committed'] = true;
    application_update_save_job($job);
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
 * Ordinary requests keep using the installation while the replacement runs.
 * Old jobs, packages, backups and marker receipts remain available for diagnosis.
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
        $legacyPaths = [
            'maintenance.lock' => application_update_project_root() . '/maintenance.lock',
            'activation.json' => application_update_jobs_root() . '/activation.json',
            'rollback-window.json' => application_update_jobs_root() . '/rollback-window.json',
        ];
        foreach ($legacyPaths as $name => $path) {
            if (is_file($path) && !is_link($path)
                && filesize($path) <= APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES) {
                $bytes = @file_get_contents($path);
                if ($bytes === false) {
                    continue;
                }
                $markers[$name] = $bytes;
                $marker = json_decode($bytes, true);
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
        // A killed takeover can adopt this durably reserved child on replay.
        application_update_save_job($job);
        foreach ($markers as $name => $bytes) {
            $receipt = application_update_job_dir($job['id']) . '/superseded-' . $name;
            if (!is_file($receipt) && file_put_contents($receipt, $bytes, LOCK_EX) === false) {
                throw new RuntimeException('Could not preserve replaced updater marker.');
            }
        }
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
            if (application_update_job_terminal($previous)) {
                continue;
            }
            $previous['replacement_previous_status'] ??= $previous['status'];
            $previous['status'] = 'cancelled';
            $previous['finished_at'] = time();
            $previous['result']['superseded_by'] = $job['id'];
            application_update_save_job($previous);
        }
        application_update_set_active_job($job['id']);
        foreach ($markers as $name => $bytes) {
            $path = $legacyPaths[$name];
            $receipt = application_update_job_dir($job['id']) . '/superseded-' . $name;
            // Keep a marker if another worker changed it or we could not save
            // the identical bytes. The web ignores either outcome.
            if (is_file($path) && !is_link($path)
                && @file_get_contents($path) === $bytes
                && @file_get_contents($receipt) === $bytes) {
                @unlink($path);
            }
        }
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
 * Classify a failed source as safe to release or requiring forward repair.
 *
 * @param array<string,mixed> $job Failed source and durable activation checkpoints.
 * @return string Pre-activation, original-byte proof required, or a stable repair reason.
 */
function application_update_recovery_boundary(array $job): string
{
    $stage = (string) ($job['stage'] ?? '');
    $stages = application_update_job_stages();
    $index = array_search($stage, $stages, true);
    $activation = array_search('activate', $stages, true);
    if (!is_int($index) || !is_int($activation)) {
        return 'unknown_checkpoint';
    }
    if (empty($job['checkpoints']['activation_started'])
        && empty($job['checkpoints']['activation_complete'])) {
        if ($index < $activation) {
            return 'pre_activation';
        }
        if ($stage === 'activate' && ($job['checkpoints']['migrations_started'] ?? null) === false) {
            return 'activation_not_started_candidate';
        }
    }
    return 'forward_repair_required';
}

/**
 * Prove an old activate checkpoint never changed its planned active files.
 *
 * The caller holds start and worker ownership. Complete original bytes and
 * created-path absence must match before the failed job releases ownership.
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
}

/**
 * Verify prepared files in resumable ready slices.
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
    if (($job['recovery_schema'] ?? 0) !== 1) {
        return true;
    }
    $directory = application_update_job_dir((string) $job['id']);
    $items = [];
    foreach ((array) ($job['checkpoints']['activation_files'] ?? []) as $relative) {
        $expected = $relative === 'app/core-manifest.json'
            ? (string) ($job['checkpoints']['recovery_manifest_identity'] ?? '')
            : (string) ($job['checkpoints']['recovery_hashes'][$relative] ?? '');
        $items[] = [$directory, 'ready/' . $relative, $expected];
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
        'message' => 'Verifying prepared files.', 'unit' => 'files',
    ];
    application_update_save_job($job);
    return $job['checkpoints']['recovery_ready_verified'];
}

/**
 * Record installed migration and schema evidence before replacement.
 *
 * @param array{id:string,checkpoints:array<string,mixed>,...<string,mixed>} $job Prepared job receiving its database evidence.
 * @return void Records verified schema and migration metadata on the job.
 */
function application_update_capture_activation_evidence(array &$job): void
{
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
}
