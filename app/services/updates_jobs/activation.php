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
 * Also opportunistically refreshes the cached GitHub update-check result so the
 * Admin update page reflects the newly activated version immediately, instead of
 * showing an empty "Force check" placeholder until the next manual check or the
 * next automatic hourly probe.
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

    // Immediately replace the just-deleted cache with a fresh GitHub result computed
    // against the now-active version, instead of leaving the update page with no
    // cached data until an administrator clicks Force check or the next automatic
    // probe runs. check_application_update() already catches its own remote/parsing
    // failures and returns a diagnostic array rather than throwing, but the call is
    // still wrapped defensively so a local failure while persisting the cached
    // setting can never fail an otherwise-successful, already-activated update.
    try {
        $freshUpdateStatus = check_application_update();
        cache_application_update_check($freshUpdateStatus);
    } catch (Throwable $exception) {
        // Non-fatal: the update itself already succeeded. Worst case, the admin
        // update page falls back to its existing "no cached data yet" placeholder
        // until the next manual or automatic check.
    }

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
