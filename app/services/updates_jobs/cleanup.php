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
