<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/cleanup.php
 * Module Type: Service
 *
 * Purpose:
 *   Prunes finished job artifacts under explicit budgets.
 *
 * Responsibilities:
 *   - Stage the current job artifacts for removal after finalization
 *   - Protect job identifiers that must not be pruned yet
 *   - Prune stale jobs under an explicit move budget
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
 * Read updater-GC evidence without scanning trees, creating locks or deleting data.
 * @return array{pending:bool,bytes_reclaimed:int,blocked:bool,next_due:int,updated_at:int} Last committed actual reclamation and current queue presence; pending is not a size estimate.
 */
function application_update_cache_cleanup_status(): array
{
    $root = application_update_project_root();
    application_update_assert_safe_target(
        $root,
        'cache/updates/cache-gc.json',
        true,
    );
    application_update_assert_safe_target(
        $root,
        'cache/updates/prune-trash',
        true,
    );
    $statePath = $root . '/cache/updates/cache-gc.json';
    $state = [];
    if (is_file($statePath)) {
        if (
            (int) @filesize($statePath)
            > APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES
        ) {
            throw new RuntimeException(
                'Updater cache cleanup checkpoint is oversized.',
            );
        }
        $state = application_update_read_json($statePath);
        if ($state === []) {
            throw new RuntimeException(
                'Updater cache cleanup checkpoint is unreadable.',
            );
        }
    }
    return [
        'pending' => is_dir(application_update_prune_trash_root($root)),
        'bytes_reclaimed' => max(0, (int) ($state['total_bytes_removed'] ?? 0)),
        'blocked' => !empty($state['blocked']),
        'next_due' => max(0, (int) ($state['next_due'] ?? 0)),
        'updated_at' => max(0, (int) ($state['updated_at'] ?? 0)),
    ];
}

/**
 * Resume already-detached updater trash without staging live workspaces or rollback data.
 *
 * The shared start lock excludes new updater starts and other GC workers. Any active
 * pointer (including unreadable/corrupt state) blocks deletion; this deliberately
 * does not use active_job(), whose lookup may clear an invalid pointer. Progress
 * survives worker death because successful removals shrink the queue, and the
 * relative directory stack is committed atomically after each successful slice.
 *
 * @param bool $force Whether an approved Maintenance Center/CLI call bypasses the due-time throttle.
 * @param string $runId Optional manual-request correlation ID enabling bounded lifecycle diagnostics.
 * @return array{ok:bool,skipped:bool,reason:string,done:bool,removed:int,bytes_removed:int,visited:int,blocked:bool,elapsed_ms:float,total_bytes_removed:int,next_due:int,failure_code:string,failure_operation:string,object_token:string} Safe evidence without raw paths or checkpoint data.
 */
function application_update_run_scheduled_cache_cleanup(
    bool $force = false,
    string $runId = '',
): array {
    $result = [
        'ok' => true,
        'skipped' => false,
        'reason' => '',
        'done' => false,
        'removed' => 0,
        'bytes_removed' => 0,
        'visited' => 0,
        'blocked' => false,
        'elapsed_ms' => 0.0,
        'total_bytes_removed' => 0,
        'next_due' => 0,
        'failure_code' => '',
        'failure_operation' => '',
        'object_token' => '',
    ];
    if ($runId !== '') {
        cache_cleanup_diagnostic_event($runId, 'owner_entered', [
            'forced' => $force,
        ]);
    }
    $root = application_update_project_root();
    $workspace = $root . '/cache/updates';
    if (!file_exists($workspace) && !is_link($workspace)) {
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'skipped', [
                'reason' => 'no_updater_cache',
                'done' => true,
            ]);
        }
        return array_replace($result, [
            'skipped' => true,
            'reason' => 'no_updater_cache',
            'done' => true,
        ]);
    }
    // Reuse the updater's component-by-component link/path guard before opening
    // locks, checkpoints or a deletion root inside a writable runtime tree.
    application_update_assert_safe_target($root, 'cache/updates');
    foreach ([
        'start.lock',
        'cache-gc.json',
        'prune-trash',
        'active-job.json',
    ] as $name) {
        application_update_assert_safe_target(
            $root,
            'cache/updates/' . $name,
            true,
        );
    }
    if ($runId !== '') {
        cache_cleanup_diagnostic_event($runId, 'paths_verified');
    }
    $lock = application_update_acquire_lock($workspace . '/start.lock', 15);
    if ($lock === null) {
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'skipped', [
                'reason' => 'updater_or_gc_busy',
                'lock' => 'updater_start',
            ]);
        }
        return array_replace($result, [
            'skipped' => true,
            'reason' => 'updater_or_gc_busy',
        ]);
    }
    try {
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'lock_acquired', [
                'lock' => 'updater_start',
            ]);
        }
        if (
            file_exists($workspace . '/active-job.json')
            || is_link($workspace . '/active-job.json')
        ) {
            if ($runId !== '') {
                cache_cleanup_diagnostic_event($runId, 'skipped', [
                    'reason' => 'active_updater_pointer',
                ]
                    + application_update_cache_blocking_job_diagnostic($root));
            }
            return array_replace($result, [
                'skipped' => true,
                'reason' => 'active_updater_pointer',
            ]);
        }
        $statePath = $workspace . '/cache-gc.json';
        // The module-owned byte limit bounds state decoding for all GC consumers.
        if (
            is_file($statePath)
            && (int) @filesize($statePath)
                > APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES
        ) {
            throw new RuntimeException(
                'Updater cache cleanup checkpoint is oversized.',
            );
        }
        $state = application_update_read_json($statePath);
        if (is_file($statePath) && $state === []) {
            throw new RuntimeException(
                'Updater cache cleanup checkpoint is unreadable.',
            );
        }
        $result['total_bytes_removed'] = max(
            0,
            (int) ($state['total_bytes_removed'] ?? 0),
        );
        $result['next_due'] = max(0, (int) ($state['next_due'] ?? 0));
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'checkpoint_loaded', [
                'cursor_depth' => is_array($state['cursor'] ?? null)
                    ? count($state['cursor'])
                    : 0,
                'total_bytes_removed' => $result['total_bytes_removed'],
                'next_due' => $result['next_due'],
            ]);
        }
        if (!$force && $result['next_due'] > time()) {
            if ($runId !== '') {
                cache_cleanup_diagnostic_event($runId, 'skipped', [
                    'reason' => 'cache_gc_not_due',
                ]);
            }
            return array_replace($result, [
                'skipped' => true,
                'reason' => 'cache_gc_not_due',
                'done' => !empty($state['done']),
            ]);
        }
        // Bound scheduled reclamation independently of the thumbnail worker.
        // Type: int and float. Units: observations/attempts and seconds.
        // Scope: one locked GC slice. Consumers: updater-owned prune queue.
        // Rationale: reuse the updater's 180-entry/180-ms shared-hosting budget.
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'slice_started', [
                'entry_cap' => 180,
                'time_cap_ms' => 180,
            ]);
        }
        $slice = application_update_delete_tree_slice(
            application_update_prune_trash_root($root),
            180,
            0.18,
            is_array($state['cursor'] ?? null) ? $state['cursor'] : [],
        );
        $result = array_replace($result, array_intersect_key($slice, $result));
        $result['ok'] = !$slice['blocked'];
        $result['reason'] = $slice['blocked']
            ? 'cache_gc_io_or_cursor_blocked'
            : '';
        $result['total_bytes_removed'] += $slice['bytes_removed'];
        if ($runId !== '') {
            cache_cleanup_diagnostic_event(
                $runId,
                $slice['blocked'] ? 'blocked' : 'slice_finished',
                array_diff_key($slice, ['cursor' => true]),
            );
        }
        // Retry debt every minute; idle/error passes wait fifteen minutes.
        // Type: int. Units: seconds. Scope: scheduled updater GC.
        // Consumers: cron and bounded after-response maintenance.
        // Rationale: drain successful debt promptly while backing off idle/failed I/O.
        $result['next_due'] =
            time() + ($slice['done'] || $slice['blocked'] ? 900 : 60);
        application_update_write_json_atomic(
            $statePath,
            $result
                + [
                    'cursor' => $slice['cursor'],
                    'updated_at' => time(),
                ],
        );
        if ($runId !== '') {
            cache_cleanup_diagnostic_event(
                $runId,
                'checkpoint_committed',
                $result,
            );
        }
        return $result;
    } finally {
        application_update_release_lock($lock);
        if ($runId !== '') {
            cache_cleanup_diagnostic_event($runId, 'lock_released', [
                'lock' => 'updater_start',
            ]);
        }
    }
}

/**
 * Read bounded blocking-job metadata without clearing any pointer or advancing work.
 * @param string $root Canonical installation root already protected by the updater start lock.
 * @return array{updater_pointer_state:string,updater_job_id?:string,updater_status?:string,updater_stage?:string,updater_updated_at?:int} Validated identity and enum state, or a safe unreadable marker.
 */
function application_update_cache_blocking_job_diagnostic(string $root): array
{
    $result = ['updater_pointer_state' => 'pointer_unreadable_or_invalid'];
    // Reuse the GC metadata cap for two diagnostic-only reads. Raw state and
    // oversized files remain private; the presence of a pointer still blocks GC.
    $read = static function (string $relative) use ($root): array {
        application_update_assert_safe_target($root, $relative, true);
        $path = $root . '/' . $relative;
        if (!is_file($path) || is_link($path))
            return [];
        $json = @file_get_contents(
            $path,
            false,
            null,
            0,
            APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES + 1,
        );
        if (
            !is_string($json)
            || strlen($json) > APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES
        )
            return [];
        $state = json_decode($json, true);
        return is_array($state) ? $state : [];
    };
    try {
        $pointer = $read('cache/updates/active-job.json');
        $jobId = (string) ($pointer['job_id'] ?? '');
        if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $jobId) !== 1)
            return $result;
        $result['updater_job_id'] = $jobId;
        $result['updater_pointer_state'] = 'job_unreadable_or_invalid';
        $job = $read('cache/updates/jobs/' . $jobId . '/job.json');
        if (($job['id'] ?? null) !== $jobId)
            return $result;
        $status = (string) ($job['status'] ?? '');
        $result['updater_status'] = in_array(
            $status,
            ['running', 'failed', 'completed', 'cancelled'],
            true,
        )
            ? $status
            : 'unknown';
        $stage = (string) ($job['stage'] ?? '');
        $result['updater_stage'] = in_array(
            $stage,
            application_update_job_stages(),
            true,
        )
            ? $stage
            : 'unknown';
        $result['updater_updated_at'] = max(0, (int) ($job['updated_at'] ?? 0));
        $result['updater_pointer_state'] = in_array(
            $status,
            ['completed', 'cancelled'],
            true,
        )
            ? 'terminal_job_requires_owner_review'
            : 'nonterminal_job';
        return $result;
    } catch (\Throwable) {
        $result['updater_pointer_state'] = 'unsafe_or_unreadable_job_metadata';
        return $result;
    }
}

/**
 * Observe worker-lock ownership without creating files, changing metadata or advancing a job.
 * @param string $jobId Valid updater job identifier selected by the authenticated controller.
 * @param int $updatedAt Persisted checkpoint update epoch, or zero when absent.
 * @return array{observed_at:int,checkpoint_age_seconds:int|null,worker_lock:string} Server-clock checkpoint age and instantaneous held/available/missing/unreadable/unsafe/unavailable lock observation; availability is not a lease or OS process proof.
 */
function application_update_job_worker_diagnostic(
    string $jobId,
    int $updatedAt,
): array {
    $now = time();
    $result = [
        'observed_at' => $now,
        'checkpoint_age_seconds' => $updatedAt > 0
            ? max(0, $now - $updatedAt)
            : null,
        'worker_lock' => 'unavailable',
    ];
    $handle = null;
    try {
        $root = application_update_project_root();
        application_update_job_dir($jobId);
        $relative = 'cache/updates/jobs/' . $jobId . '/worker.lock';
        application_update_assert_safe_target($root, $relative, true);
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            $result['worker_lock'] = 'missing';
            return $result;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $result['worker_lock'] = 'unreadable';
            return $result;
        }
        $wouldBlock = 0;
        if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            $result['worker_lock'] = 'available';
            flock($handle, LOCK_UN);
        } elseif ($wouldBlock !== 0) {
            $result['worker_lock'] = 'held';
        }
        return $result;
    } catch (\Throwable) {
        $result['worker_lock'] = 'unsafe';
        return $result;
    } finally {
        if (is_resource($handle))
            fclose($handle);
    }
}

/**
 * Stage current-job transient payloads for bounded deletion without touching rollback data.
 *
 * @param array{id:string,...<string,mixed>} $job Durable current update job.
 * @return array{ok:bool,moved:int} Whether cleanup artifacts were staged and how many paths were moved.
 */
function application_update_stage_current_job_cleanup_artifacts(array $job): array
{
    $jobId = (string) ($job['id'] ?? '');
    $jobDir = application_update_job_dir($jobId);
    $trashRoot =
        application_update_prune_trash_root(application_update_project_root())
        . DIRECTORY_SEPARATOR
        . 'current'
        . DIRECTORY_SEPARATOR
        . $jobId;
    $moved = 0;
    $ok = true;

    foreach (['extract', 'ready'] as $name) {
        $source = $jobDir . DIRECTORY_SEPARATOR . $name;
        $target = $trashRoot . DIRECTORY_SEPARATOR . $name;
        if (
            (
                is_dir($source)
                || is_link($source)
            )
            && !application_update_stage_path_for_prune($source, $target)
        ) {
            $ok = false;
        } elseif (file_exists($target) || is_link($target)) {
            $moved++;
        }
    }

    $packageSource = $jobDir . DIRECTORY_SEPARATOR . 'package.zip';
    $packageTarget = $trashRoot . DIRECTORY_SEPARATOR . 'package.zip';
    if (
        is_file($packageSource)
        && !application_update_stage_path_for_prune(
            $packageSource,
            $packageTarget,
        )
    ) {
        $ok = false;
    } elseif (is_file($packageTarget)) {
        $moved++;
    }

    return ['ok' => $ok, 'moved' => $moved];
}

/**
 * Return update-job identifiers that must not be pruned during this cleanup pass.
 *
 * @param array{id?:string,parameters?:array{source_job_id?:string},...<string,mixed>} $job Current job whose own and rollback-source artifacts remain protected.
 * @return array<string,bool> Job identifiers mapped to true when protected from pruning.
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
    $betaBackup = trim((string) app_setting(
        'application_update_beta_backup_path',
        '',
    ));
    if (
        preg_match(
            '#^cache/updates/jobs/(\d{14}-[a-f0-9]{12})/rollback/?$#',
            str_replace('\\', '/', $betaBackup),
            $match,
        ) === 1
    ) {
        $protected[$match[1]] = true;
    }
    return $protected;
}

/**
 * Permit contextual pruning only for verified terminal work outside protected recovery references.
 * @param array<string,mixed> $candidate Persisted updater state with id/status; protected IDs are filtered by the caller.
 * @param int $fallbackMtime Historical directory modification time retained for the compatibility signature, never deletion authority.
 * @return bool True for completed/cancelled work; failed, running and unproven recovery remain protected without an age deadline.
 */
function application_update_job_prune_eligible(
    array $candidate,
    int $fallbackMtime,
): bool {
    return (
        preg_match(
            '/^[0-9]{14}-[a-f0-9]{12}$/D',
            (string) ($candidate['id'] ?? ''),
        ) === 1
            && in_array(
                $candidate['status'] ?? '',
                ['completed', 'cancelled'],
                true,
            )
    );
}

/**
 * Atomically stage obsolete updater job directories for bounded deletion.
 *
 * @param array<string,mixed> $job Current updater state with id and parameters.source_job_id, used to protect current/active/last/selected recovery references.
 * @param int $maxMoves Maximum job directories admitted for detachment during this cleanup pass.
 * @return array{moved:int,remaining:bool} Successful directory detachments and whether eligible work remains after the move cap or a refused mutation.
 */
function application_update_stage_stale_jobs_for_prune(
    array $job,
    int $maxMoves = 20,
): array {
    $jobsRoot = application_update_jobs_root() . DIRECTORY_SEPARATOR . 'jobs';
    if (!is_dir($jobsRoot)) {
        return ['moved' => 0, 'remaining' => false];
    }
    $protected = application_update_prune_protected_job_ids($job);
    $trashRoot =
        application_update_prune_trash_root(application_update_project_root())
        . DIRECTORY_SEPARATOR
        . 'jobs';
    $moved = 0;
    $remaining = false;

    foreach ((array) @scandir($jobsRoot) as $jobId) {
        if (
            !is_string($jobId)
            || preg_match('/^\d{14}-[a-f0-9]{12}$/', $jobId) !== 1
            || isset($protected[$jobId])
        ) {
            continue;
        }
        $source = $jobsRoot . DIRECTORY_SEPARATOR . $jobId;
        if (!is_dir($source)) {
            continue;
        }
        $candidate = application_update_read_json(
            $source . DIRECTORY_SEPARATOR . 'job.json',
        );
        $fallbackMtime = (int) (@filemtime($source) ?: time());
        if (
            $candidate !== []
            && !application_update_job_prune_eligible(
                $candidate,
                $fallbackMtime,
            )
        ) {
            continue;
        }
        if ($candidate === [] || ($candidate['id'] ?? '') !== $jobId) {
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
 * @param array<string,mixed> $job Updater state with id, parameters, checkpoints, result and progress, updated by reference; checkpoints retain the relative cache_cleanup_cursor directory stack.
 * @param array{deadline:float} $budget Worker deadline in monotonic wall-clock seconds from microtime(true).
 * @return bool True when physical cleanup is complete or its stage defers remaining debt after twenty slices; result.cache_cleanup.completed distinguishes these outcomes.
 */
function application_update_job_cleanup(array &$job, array $budget): bool
{
    $current = application_update_stage_current_job_cleanup_artifacts($job);
    $staleJobs = application_update_stage_stale_jobs_for_prune($job, 20);
    $legacy = application_update_stage_legacy_cache_artifacts(
        application_update_project_root(),
        12,
    );

    $remainingBudget = max(
        0.01,
        (float) ($budget['deadline'] ?? microtime(true)) - microtime(true)
            - 0.35,
    );
    $sliceSeconds = min(0.18, $remainingBudget);
    $delete = application_update_delete_tree_slice(
        application_update_prune_trash_root(application_update_project_root()),
        180,
        $sliceSeconds,
        is_array($job['checkpoints']['cache_cleanup_cursor'] ?? null)
            ? $job['checkpoints']['cache_cleanup_cursor']
            : [],
    );
    $job['checkpoints']['cache_cleanup_cursor'] = $delete['cursor'];

    $job['checkpoints']['cache_cleanup_removed_entries'] =
        (int) ($job['checkpoints']['cache_cleanup_removed_entries'] ?? 0)
        + (int) ($delete['removed'] ?? 0);
    $job['checkpoints']['cache_cleanup_slices'] =
        (int) ($job['checkpoints']['cache_cleanup_slices'] ?? 0) + 1;
    $job['result']['cache_cleanup'] = [
        'strategy' => 'bounded updater-owned prune slices',
        'removed_entries' =>
            (int) $job['checkpoints']['cache_cleanup_removed_entries'],
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

    $complete =
        !empty($current['ok'])
        && empty($staleJobs['remaining'])
        && empty($legacy['remaining'])
        && !empty($delete['done']);
    $deferred =
        !$complete && (int) $job['checkpoints']['cache_cleanup_slices'] >= 20;
    if ($complete || $deferred) {
        $job['checkpoints']['cleanup_complete'] = true;
        // The updater stage may finish with deferred debt; physical cleanup is
        // complete only when all staging owners and the deletion queue are done.
        $job['result']['cache_cleanup']['completed'] = $complete;
        $job['result']['cache_cleanup']['deferred_remaining_trash'] = $deferred;
    }
    if ($deferred) {
        $complete = true;
    }
    application_update_save_job($job);
    return $complete;
}
