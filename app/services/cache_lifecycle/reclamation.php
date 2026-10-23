<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/reclamation.php
 * Module Type: Service Part
 * Purpose: Revalidate candidates, drain detached debt and admit bounded external scheduler bursts.
 * Responsibilities: Respect producer leases/pins, retain compact restart state and count successful deletion bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Approve the current measured preview while refusing a stale scan identity.
 * @param string $id Exact preview identity reviewed by the administrator or selected by the configured scheduled retention owner.
 * @return array<string,mixed> Cleanup state or ok:false/stale_preview refusal; no files are deleted by approval.
 */
function cache_lifecycle_cleanup_start(string $id): array
{
    return cache_lifecycle_with_lock(static function () use ($id): array {
        $control = cache_lifecycle_job_directory();
        $state = cache_lifecycle_read_json($control . '/state.json');
        if (
            ($state['phase'] ?? '') !== 'preview'
            || !hash_equals((string) ($state['id'] ?? ''), $id)
        ) {
            return ['ok' => false, 'reason' => 'stale_cache_preview'];
        }
        $state['phase'] = 'cleanup';
        cache_lifecycle_write_json($control . '/state.json', $state);
        return ['ok' => true] + $state;
    });
}

/**
 * Acquire a candidate's producer lease and atomically detach only unchanged expired proof.
 * @param array{path:string,owner:string,bytes:int,files:int,newest:int,root_mtime:int,root_ctime:int,proof:string,proof_hash:string} $record Reviewed measured candidate, always revalidated at execution.
 * @param string $destination Guarded private trash destination for the current lifecycle job.
 * @param string $reason Safe outcome enum returned by reference; empty on successful detachment.
 * @return bool True only after safe atomic detachment; false for fresh/changed/pinned/active/unavailable candidates.
 */
function cache_lifecycle_stage_candidate(
    array $record,
    string $destination,
    string &$reason = '',
): bool {
    $reason = 'candidate_changed_or_unproven';
    $path = cache_lifecycle_path($record['path']);
    cache_lifecycle_assert_path($destination);
    for (
        $parent = dirname($record['path']);
        $parent !== '.';
        $parent = dirname($parent)
    ) {
        if (file_exists(cache_lifecycle_path($parent . '/.keep'))) {
            $reason = 'candidate_pinned';
            return false;
        }
    }
    if (file_exists(cache_lifecycle_path('.keep'))) {
        $reason = 'candidate_pinned';
        return false;
    }
    $stat = @lstat($path);
    if (
        !is_array($stat)
        || is_file($path . '/.keep')
        || (int) $stat['mtime'] !== $record['root_mtime']
        || (int) $stat['ctime'] !== $record['root_ctime']
    )
        return false;
    $flat =
        in_array($record['owner'], ['downloads', 'partials'], true)
        && $record['proof'] === $record['path'];
    $archive =
        $record['owner'] === 'updates' && $record['proof'] === $record['path'];
    $proof = $flat
        ? cache_lifecycle_flat_download_proof($record['path'])
        : (
            $archive
                ? cache_lifecycle_update_archive_proof($record['path'])
                : cache_lifecycle_read_json(cache_lifecycle_path(
                    $record['proof'],
                ))
        );
    if (
        $proof === []
        || $proof === null
        || !hash_equals($record['proof_hash'], hash('sha256', json_encode(
            $proof,
            JSON_THROW_ON_ERROR,
        )))
    )
        return false;
    $counts = [
        'bytes' => $record['bytes'],
        'files' => $record['files'],
        'dirs' => 0,
        'newest' => $record['newest'],
        'pinned' => false,
        'unsafe' => false,
    ];
    $singleFile =
        $flat
        || $archive
        || in_array($record['owner'], ['github', 'drafts', 'downloads'], true);
    if (
        (
            $singleFile
                ? cache_lifecycle_file_candidate(
                    $record['path'],
                    cache_lifecycle_now(),
                )
                : cache_lifecycle_directory_candidate(
                    $record['path'],
                    $counts,
                    cache_lifecycle_now(),
                )
        ) !== $record['owner']
    )
        return false;
    $control = cache_lifecycle_control_directory();
    cache_lifecycle_assert_path($control . '/leases');
    if (!is_dir($control . '/leases'))
        mkdir($control . '/leases', 0775, true);
    $leasePath = cache_lifecycle_lease_path($record['path']);
    $lease = @fopen($leasePath, 'c');
    if ($lease === false) {
        $reason = 'producer_lease_unavailable';
        return false;
    }
    $startLock =
        $workerLock = $downloadLease = $legacyLease = $flatLease = null;
    try {
        if (!flock($lease, LOCK_EX | LOCK_NB)) {
            $reason = 'producer_lease_busy';
            return false;
        }
        // Preserve an in-flight lease from an earlier live checkout of this
        // feature's initial per-identity implementation. Usage is unknown; retain
        // this read-only check until every such producer has exited.
        $legacyPath =
            $control . '/leases/' . hash('sha256', $record['path']) . '.lock';
        cache_lifecycle_assert_path($legacyPath);
        if (is_file($legacyPath)) {
            $legacyLease = @fopen($legacyPath, 'rb');
            if (
                !is_resource($legacyLease)
                || !flock($legacyLease, LOCK_EX | LOCK_NB)
            )
                return false;
        }
        if ($flat) {
            $identity = cache_lifecycle_flat_download_identity($record['path']);
            $base = $identity['base'];
            if (file_exists(cache_lifecycle_path($base . '.keep')))
                return false;
            $baseLeasePath = cache_lifecycle_lease_path($base);
            if ($baseLeasePath !== $leasePath) {
                $flatLease = @fopen($baseLeasePath, 'c');
                if (
                    !is_resource($flatLease)
                    || !flock($flatLease, LOCK_EX | LOCK_NB)
                ) {
                    $reason = 'download_lease_busy_or_unavailable';
                    return false;
                }
            }
            $lock = cache_lifecycle_path($base . '.lock');
            if (is_file($lock)) {
                $downloadLease = @fopen($lock, 'rb');
                if (
                    !is_resource($downloadLease)
                    || !flock($downloadLease, LOCK_EX | LOCK_NB)
                ) {
                    $reason = 'download_lease_busy_or_unavailable';
                    return false;
                }
            }
            if (
                cache_lifecycle_file_candidate(
                    $record['path'],
                    cache_lifecycle_now(),
                ) !== $record['owner']
            )
                return false;
        } elseif ($record['owner'] === 'downloads') {
            $downloadLease = @fopen(
                cache_lifecycle_path(
                    dirname($record['path']) . '/artifact.lock',
                ),
                'rb',
            );
            if (
                !is_resource($downloadLease)
                || !flock($downloadLease, LOCK_EX | LOCK_NB)
            ) {
                $reason = 'download_lease_busy_or_unavailable';
                return false;
            }
        }
        if ($record['owner'] === 'updates') {
            $startLock = @fopen(
                cache_lifecycle_path('updates/start.lock'),
                'c',
            );
            if (
                !is_resource($startLock)
                || !flock($startLock, LOCK_EX | LOCK_NB)
            ) {
                $reason = 'updater_start_busy_or_unavailable';
                return false;
            }
            if ($archive) {
                if (!cache_lifecycle_update_archive_disposable($record['path']))
                    return false;
            } else {
                $workerLock = @fopen(
                    cache_lifecycle_path($record['path'] . '/worker.lock'),
                    'c',
                );
                if (
                    !is_resource($workerLock)
                    || !flock($workerLock, LOCK_EX | LOCK_NB)
                ) {
                    $reason = 'updater_worker_busy_or_unavailable';
                    return false;
                }
                if (!cache_lifecycle_update_disposable(basename(
                    $record['path'],
                )))
                    return false;
            }
        }
        clearstatcache(true, $path);
        if (
            is_file($path . '/.keep')
            || ($singleFile ? !is_file($path) : !is_dir($path))
        )
            return false;
        $stat = @lstat($path);
        if (
            !is_array($stat)
            || (int) $stat['mtime'] !== $record['root_mtime']
            || (int) $stat['ctime'] !== $record['root_ctime']
        )
            return false;
        if ($record['owner'] === 'workspaces')
            cache_lifecycle_workspace_observe($record['path']);
        $proof = $flat
            ? cache_lifecycle_flat_download_proof($record['path'])
            : (
                $archive
                    ? cache_lifecycle_update_archive_proof($record['path'])
                    : cache_lifecycle_read_json(cache_lifecycle_path(
                        $record['proof'],
                    ))
            );
        if (!hash_equals($record['proof_hash'], hash('sha256', json_encode(
            $proof,
            JSON_THROW_ON_ERROR,
        ))))
            return false;
        if (!is_dir(dirname($destination)))
            mkdir(dirname($destination), 0775, true);
        // Windows cannot rename a directory with an open child lock. Completed/
        // cancelled workers only read payloads; the held start lock excludes every
        // restart/retry/rollback transition while we release that child handle.
        if (is_resource($workerLock)) {
            flock($workerLock, LOCK_UN);
            fclose($workerLock);
            $workerLock = null;
        }
        $moved = @rename($path, $destination);
        $reason = $moved ? '' : 'candidate_detach_failed';
        return $moved;
    } finally {
        foreach ([
            $workerLock,
            $startLock,
            $downloadLease,
            $legacyLease,
            $flatLease,
        ] as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        flock($lease, LOCK_UN);
        fclose($lease);
    }
}

/**
 * Drain one approved reclamation slice through the existing updater deletion primitive.
 * @param string $expectedId Optional exact approved scan identity; scheduled calls omit it.
 * @param float $maxSeconds Admission seconds, lowered for visitor work and capped by the canonical slice limit.
 * @param int $maxEntries Observation cap, always bounded by the deletion owner's 180 operations.
 * @return array<string,mixed> Persisted phase/cursor and actual removed/bytes_removed counters, with explicit skipped/error counts; blocked filesystem work remains resumable.
 */
function cache_lifecycle_cleanup_slice(
    string $expectedId = '',
    float $maxSeconds = CACHE_LIFECYCLE_SLICE_SECONDS,
    int $maxEntries = 180,
): array {
    return cache_lifecycle_with_lock(static function () use (
        $expectedId,
        $maxSeconds,
        $maxEntries,
    ): array {
        $control = cache_lifecycle_job_directory();
        $state = cache_lifecycle_read_json($control . '/state.json');
        if (
            $expectedId !== ''
            && !hash_equals((string) ($state['id'] ?? ''), $expectedId)
        )
            return ['ok' => false, 'reason' => 'stale_cache_preview'];
        $streaming =
            !empty($state['scheduled'])
            && ($state['phase'] ?? '') === 'inventory';
        if (($state['phase'] ?? '') !== 'cleanup' && !$streaming)
            return ['ok' => true] + $state;
        $state['cleanup_blocked'] = false;
        $deadline =
            microtime(true)
            + max(0.01, min(CACHE_LIFECYCLE_SLICE_SECONDS, $maxSeconds));
        $maxEntries = max(1, min(180, $maxEntries));
        $admitted = 0;
        while ($admitted < $maxEntries && microtime(true) < $deadline) {
            if (!is_array($state['current'] ?? null)) {
                // Only committed records can cross the scan/reclamation boundary.
                // An uncommitted append remains invisible until inventory recovery.
                if (
                    $state['plan_offset']
                    >= (int) ($state['plan_write_offset'] ?? 0)
                ) {
                    if (!$streaming) {
                        $state['phase'] = 'complete';
                        // Start the next-cycle interval after deletion finishes,
                        // rather than an earlier inventory completion that may
                        // already be overdue while this queue was draining.
                        $state['completed_at'] = cache_lifecycle_now();
                    }
                    @rmdir($control . '/work/trash');
                    break;
                }
                cache_lifecycle_assert_path($control . '/work/plan.jsonl');
                $stream = fopen($control . '/work/plan.jsonl', 'rb');
                if ($stream === false)
                    throw new \RuntimeException(
                        'Cache cleanup plan is unavailable.',
                    );
                try {
                    if (fseek($stream, $state['plan_offset']) !== 0)
                        throw new \RuntimeException(
                            'Cache cleanup plan seek failed.',
                        );
                    $line = fgets($stream, 8192);
                    $nextOffset = ftell($stream);
                    if (
                        $nextOffset === false
                        || (
                            $line === false
                            && !feof($stream)
                        )
                    )
                        throw new \RuntimeException(
                            'Cache cleanup plan read failed.',
                        );
                } finally {
                    fclose($stream);
                }
                if (
                    $line === false
                    || $nextOffset > (int) $state['plan_write_offset']
                )
                    throw new \RuntimeException(
                        'Cache preview journal lost committed records.',
                    );
                $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                if (
                    !is_array($record)
                    || !isset(
                        $record['path'],
                        $record['owner'],
                        $record['proof_hash'],
                    )
                )
                    throw new \RuntimeException(
                        'Cache preview candidate is invalid.',
                    );
                $target =
                    $control . '/work/trash/' . hash('sha256', $record['path']);
                cache_lifecycle_assert_path($target);
                $admitted++;
                // A prior crash may have detached the reviewed directory before
                // its cursor commit; the deterministic target lets us recover it.
                $reason = '';
                if (
                    !file_exists($target)
                    && !cache_lifecycle_stage_candidate(
                        $record,
                        $target,
                        $reason,
                    )
                ) {
                    if ($reason === 'candidate_detach_failed') {
                        $state['cleanup_blocked'] = true;
                        $state['errors']++;
                        $state['last_error'] = $reason;
                        break;
                    }
                    $state['skipped']++;
                    $state['settled_bytes'] =
                        (int) ($state['settled_bytes'] ?? 0) + $record['bytes'];
                    $state['settled_candidates'] =
                        (int) ($state['settled_candidates'] ?? 0) + 1;
                    $state['last_skip_reason'] = $reason;
                    $state['plan_offset'] = $nextOffset;
                    continue;
                }
                $state['current'] = [
                    'target' => basename($target),
                    'next_offset' => $nextOffset,
                    'bytes' => $record['bytes'],
                    'removed_before' => (int) $state['bytes_removed'],
                    'workspace_age' => $record['owner'] === 'workspaces'
                        ? $record['proof']
                        : '',
                    'workspace_path' => $record['owner'] === 'workspaces'
                        ? $record['path']
                        : '',
                ];
                $state['cursor'] = [];
                cache_lifecycle_write_json($control . '/state.json', $state);
            }
            $target = $control . '/work/trash/' . $state['current']['target'];
            cache_lifecycle_path(
                'cache-lifecycle/work/trash/' . $state['current']['target'],
            );
            if ($admitted >= $maxEntries || microtime(true) >= $deadline)
                break;
            $slice = application_update_delete_tree_slice(
                $target,
                $maxEntries - $admitted,
                max(0.01, $deadline - microtime(true)),
                $state['cursor'],
            );
            $state['removed'] += $slice['removed'];
            $state['bytes_removed'] += $slice['bytes_removed'];
            $state['cursor'] = $slice['cursor'];
            $admitted += $slice['visited'];
            if ($slice['blocked']) {
                $state['cleanup_blocked'] = true;
                $state['errors']++;
                $state['last_error'] = $slice['failure_code'];
                break;
            }
            if ($slice['done']) {
                // Remove the small age record only after its detached payload is
                // gone; a replacement root keeps its evidence for the next scan.
                if (
                    ($state['current']['workspace_age'] ?? '') !== ''
                    && !file_exists(cache_lifecycle_path(
                        $state['current']['workspace_path'],
                    ))
                ) {
                    $agePath = cache_lifecycle_path(
                        $state['current']['workspace_age'],
                    );
                    if (is_file($agePath) && !unlink($agePath))
                        throw new \RuntimeException(
                            'Workspace age evidence could not be retired.',
                        );
                }
                $state['plan_offset'] = $state['current']['next_offset'];
                $state['settled_bytes'] =
                    (int) ($state['settled_bytes'] ?? 0)
                    + (int) ($state['current']['bytes'] ?? 0);
                $state['settled_candidates'] =
                    (int) ($state['settled_candidates'] ?? 0) + 1;
                $state['current'] = null;
                $state['cursor'] = [];
            } else
                break;
        }
        cache_lifecycle_write_json($control . '/state.json', $state);
        return [
            'ok' => !$state['cleanup_blocked'],
            'reason' => $state['cleanup_blocked'] ? $state['last_error'] : '',
            'slice_observations' => $admitted,
        ] + $state;
    });
}

/**
 * Execute one independent retention tick, continuing measured previews and approved deletion debt.
 * @param bool $force Whether an explicit maintainer call bypasses the thirty-second throttle.
 * @param string $burstId Exact cycle admitted by the bounded runner; only matching unfinished work may continue inside that invocation.
 * @param float $maxSeconds Combined retirement/payload admission seconds; each inner owner remains capped independently.
 * @param list<string> $continuations Already admitted unfinished region keys in fair turn order; retry and completed-region due dates remain enforced.
 * @param float $catalogDeadline Absolute microtime deadline admitted by the background burst, zero for the normal slice deadline.
 * @return CacheLifecycleCheckpoint Actual lifecycle state or safe throttle/busy marker; scheduling remains independent of thumbnail work.
 */
function cache_lifecycle_scheduled_slice(
    bool $force = false,
    string $burstId = '',
    float $maxSeconds = CACHE_LIFECYCLE_COMBINED_SECONDS,
    array $continuations = [],
    float $catalogDeadline = 0.0,
): array {
    if (!feature_capability_effective_enabled('automatic_cache_cleanup'))
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'automatic_cache_cleanup_disabled',
        ];
    $deadline =
        microtime(true)
        + max(0.02, min(CACHE_LIFECYCLE_COMBINED_SECONDS, $maxSeconds));
    $state = cache_lifecycle_read_json(cache_lifecycle_path(
        'cache-lifecycle/state.json',
    ));
    if (
        cache_lifecycle_registry_enabled()
        && (
            $state === []
            || ($state['phase'] ?? '') === 'complete'
        )
    ) {
        cache_lifecycle_registry_initialize($state);
        return cache_lifecycle_registry_slice(
            $maxSeconds,
            $force,
            $continuations,
            $catalogDeadline,
        );
    }
    if (($state['phase'] ?? '') === 'complete') {
        // A completed deployed checkpoint may retain the former hourly marker.
        // Only its timing is rebased; unfinished plans and cursors stay intact.
        $state['next_due'] =
            (int) ($state['completed_at'] ?? 0)
            + CACHE_LIFECYCLE_REFRESH_SECONDS;
    } elseif (!empty($state['scheduled']) && empty($state['cleanup_blocked'])) {
        $state['next_due'] = min(
            (int) ($state['next_due'] ?? 0),
            (int) ($state['last_observed_at'] ?? 0)
                + CACHE_LIFECYCLE_REFRESH_SECONDS,
        );
    }
    $continuing =
        $burstId !== ''
        && hash_equals((string) ($state['id'] ?? ''), $burstId)
        && ($state['phase'] ?? '') !== 'complete';
    if (
        !$force
        && !$continuing
        && (int) ($state['next_due'] ?? 0) > cache_lifecycle_now()
    )
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'cache_lifecycle_not_due',
        ];
    // Reclaim a bounded private-journal slice without starving payload work.
    $retired = cache_lifecycle_retired_slice(min(
        CACHE_LIFECYCLE_SLICE_SECONDS,
        $maxSeconds / 4,
    ));
    if (empty($retired['ok']))
        return $retired;
    if (
        !empty($retired['pending'])
        && (
            $state === []
            || ($state['phase'] ?? '') === 'complete'
        )
    ) {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'cache_retired_journals_pending',
        ];
    }
    if ($state === [] || ($state['phase'] ?? '') === 'complete') {
        $state = cache_lifecycle_inventory_start(true);
    }
    if (
        !empty($state['busy'])
        || empty($state['ok'])
        && !isset($state['schema'])
    )
        return $state;
    if (
        in_array($state['phase'] ?? '', ['inventory', 'preview'], true)
        && empty($state['scheduled'])
    ) {
        if (
            (
                cache_lifecycle_now()
                - (int) (
                    $state['last_manual_at']
                    ?? $state['started_at']
                    ?? cache_lifecycle_now()
                )
            ) < CACHE_LIFECYCLE_MANUAL_IDLE_SECONDS
        ) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'manual_cache_preview_pending',
            ];
        }
        // Replace an idle unapproved plan with a new scheduled identity, never
        // approve the manual identity or discard a detached deletion cursor.
        $state = cache_lifecycle_inventory_start(true);
        if (empty($state['ok']))
            return $state;
    }
    if (
        !cache_lifecycle_registry_enabled()
        && ($state['phase'] ?? '') === 'cleanup'
        && !empty($state['scheduled'])
        && !empty($state['queue_index_ready'])
        && (
            is_array($state['current'] ?? null)
            || (int) ($state['plan_offset'] ?? 0)
            < (int) ($state['plan_write_offset'] ?? 0)
        )
        && (cache_lifecycle_now() - (int) ($state['completed_at'] ?? 0))
            >= CACHE_LIFECYCLE_REFRESH_SECONDS
    ) {
        // Serve existing deletion debt before refreshing its observation. With
        // sparse arrivals, scan/approval alone can consume every short burst and
        // otherwise starve even the final candidate forever.
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.01)
            return ['ok' => true, 'reason' => 'cache_admission_budget']
                + $state;
        $state = cache_lifecycle_cleanup_slice('', $remaining);
        if (empty($state['ok']) || !empty($state['cleanup_blocked']))
            return $state;
        $restart = cache_lifecycle_with_lock(static function (): array {
            $path = cache_lifecycle_control_directory() . '/state.json';
            $current = cache_lifecycle_read_json($path);
            if (
                ($current['phase'] ?? '') !== 'cleanup'
                || empty($current['scheduled'])
                || !is_array($current['current'] ?? null)
                && (int) ($current['plan_offset'] ?? 0)
                    >= (int) ($current['plan_write_offset'] ?? 0)
            )
                return ['ok' => true] + $current;
            // Only outstanding deletion debt needs a concurrent fresh scan.
            // A settled queue must reach complete before its next cycle starts.
            // Keep the queue, detachment cursor, counters and cycle identity.
            // Exact path markers prevent duplicate/overlapping queue accounting;
            // policy roots already prohibit parent/child producer candidates.
            $current['phase'] = 'inventory';
            $current['stack'] = [cache_lifecycle_frame('')];
            $current['started_at'] = cache_lifecycle_now();
            $current['observation_round'] =
                (int) ($current['observation_round'] ?? 1) + 1;
            foreach ($current['totals'] as &$total) {
                foreach ($total as &$counter)
                    $counter = 0;
                unset($counter);
            }
            unset($total);
            cache_lifecycle_write_json($path, $current);
            return ['ok' => true] + $current;
        });
        if (empty($restart['ok']))
            return $restart;
        $state = $restart;
    }
    $remaining = $deadline - microtime(true);
    if ($remaining < 0.01)
        return ['ok' => true, 'reason' => 'cache_admission_budget'] + $state;
    if (($state['phase'] ?? '') === 'inventory') {
        if (
            (
                !empty($state['current'])
                || (int) $state['plan_offset']
                < (int) $state['plan_write_offset']
            )
            && ($state['turn'] ?? 'scan') === 'cleanup'
        ) {
            $state = cache_lifecycle_cleanup_slice('', $remaining);
        } else
            $state = cache_lifecycle_inventory_slice(
                '',
                $remaining,
                CACHE_LIFECYCLE_SLICE_ENTRIES,
                $catalogDeadline,
            );
    } elseif (($state['phase'] ?? '') === 'preview')
        $state = cache_lifecycle_cleanup_start((string) $state['id']);
    elseif (($state['phase'] ?? '') === 'cleanup')
        $state = cache_lifecycle_cleanup_slice('', $remaining);
    if (isset($state['schema'])) {
        // Reuse the single state lock for the due marker; do not overwrite a
        // concurrently advanced inventory with a stale return value.
        cache_lifecycle_with_lock(static function (): array {
            $path = cache_lifecycle_control_directory() . '/state.json';
            $current = cache_lifecycle_read_json($path);
            $current['next_due'] =
                cache_lifecycle_now()
                + (
                    !empty($current['cleanup_blocked'])
                        ? CACHE_LIFECYCLE_MANUAL_IDLE_SECONDS
                        : CACHE_LIFECYCLE_REFRESH_SECONDS
                );
            $current['turn'] = ($current['turn'] ?? 'scan') === 'scan'
                ? 'cleanup'
                : 'scan';
            cache_lifecycle_write_json($path, $current);
            return ['ok' => true];
        });
    }
    return $state;
}

/**
 * Reclaim superseded private catalogs so the inventory itself cannot accumulate indefinitely.
 * @param float $maxSeconds Retirement admission seconds, capped by the canonical slice limit.
 * @return array{ok:bool,pending:bool,removed:int,bytes_removed:int}|array<string,mixed> Actual private-journal removals and pending debt, or lock refusal; no user candidates reside in retired journals.
 */
function cache_lifecycle_retired_slice(float $maxSeconds = CACHE_LIFECYCLE_SLICE_SECONDS): array
{
    return cache_lifecycle_with_lock(static function () use (
        $maxSeconds,
    ): array {
        $control = cache_lifecycle_job_directory();
        $path = $control . '/retired';
        cache_lifecycle_assert_path($path);
        if (!is_dir($path))
            return [
                'ok' => true,
                'pending' => false,
                'removed' => 0,
                'bytes_removed' => 0,
            ];
        $record = cache_lifecycle_read_json($control . '/retired.json');
        $slice = application_update_delete_tree_slice(
            $path,
            180,
            min(CACHE_LIFECYCLE_SLICE_SECONDS, $maxSeconds),
            is_array($record['cursor'] ?? null) ? $record['cursor'] : [],
        );
        cache_lifecycle_write_json($control . '/retired.json', [
            'cursor' => $slice['cursor'],
        ]);
        return [
            'ok' => !$slice['blocked'],
            'pending' => !$slice['done'],
            'removed' => $slice['removed'],
            'bytes_removed' => $slice['bytes_removed'],
        ];
    });
}

/**
 * Record an external arrival that yielded to another installation owner.
 * @param string $source External source normalized to a fixed safe enum.
 * @param string $reason Owner-controlled busy/blocked enum, never request text or secrets.
 * @return array<string,mixed> Evidence commit result or nonblocking lifecycle lock refusal; no inventory or payload action is performed.
 */
function cache_lifecycle_scheduler_deferred(
    string $source,
    string $reason,
): array {
    $result = cache_lifecycle_with_lock(static function () use (
        $source,
        $reason,
    ): array {
        $path = cache_lifecycle_control_directory() . '/scheduler.json';
        $record = cache_lifecycle_read_json($path);
        if ((int) ($record['last_attempt_at'] ?? 0) < cache_lifecycle_now())
            $record['previous_attempt_at'] = (int) (
                $record['last_attempt_at'] ?? 0
            );
        $record['last_attempt_at'] = cache_lifecycle_now();
        $record['first_attempt_at'] ??= cache_lifecycle_now();
        $record['arrivals'] = (int) ($record['arrivals'] ?? 0) + 1;
        $record['source'] = in_array(
            $source,
            [
                'cli_cron',
                'web_cron',
                'windows_task',
                'public_visit',
                'cache_chain',
            ],
            true,
        )
            ? $source
            : 'scheduled';
        $record['reason'] = $reason;
        $record['ok'] = $reason !== 'cache_visitor_exception';
        cache_lifecycle_write_json($path, $record);
        return ['ok' => true];
    });
    if (!empty($result['ok']))
        cache_lifecycle_log_activity(['phase' => 'deferred'], false, $reason);
    return $result;
}

/**
 * Execute several short lifecycle slices under one bounded external invocation.
 * @param int $seconds Requested admission budget, clamped to one through ten seconds.
 * @param string $source Caller source; only a fixed non-secret source enum is persisted.
 * @param int $maxSlices Requested operation cap, clamped to one through CACHE_LIFECYCLE_RUN_SLICES; callers may lower admission for observable short bursts.
 * @return array<string,mixed> Owner state or safe failure/skip, plus done, slices, elapsed_ms and stable cycle id; scheduler evidence records attempts separately from successful progress.
 */
function cache_lifecycle_scheduled_run(
    int $seconds,
    string $source,
    int $maxSlices = CACHE_LIFECYCLE_RUN_SLICES,
): array {
    $path = cache_lifecycle_control_directory() . '/scheduler.lock';
    cache_lifecycle_assert_path($path);
    $lock = @fopen($path, 'c');
    if ($lock === false)
        return [
            'ok' => false,
            'blocked' => true,
            'reason' => 'cache_scheduler_lock_unavailable',
        ];
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB))
            return [
                'ok' => true,
                'busy' => true,
                'skipped' => true,
                'reason' => 'cache_scheduler_busy',
            ];
        return cache_lifecycle_scheduled_burst($seconds, $source, $maxSlices);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Observe optional filesystem capacity without requiring hosting-disabled PHP functions.
 * @param callable(string):(int|float|false)|null $probe Optional capacity provider for the guarded cache directory; null discovers the native function only when available.
 * @return array{known:bool,bytes:int|null,admitted:bool} Known low capacity refuses admission; unavailable/invalid/throwing measurements remain unknown and permit the already checkpoint-validated worker under its metadata and write guards.
 */
function cache_lifecycle_checkpoint_capacity(?callable $probe = null): array
{
    if ($probe === null && \function_exists('disk_free_space')) {
        $probe = static fn(string $path): float|false => @\disk_free_space(
            $path,
        );
    }
    $raw = false;
    if ($probe !== null) {
        try {
            $raw = $probe(cache_lifecycle_project_root() . '/cache');
        } catch (
            \Throwable
        ) { /* Hosting capacity is optional; committed writes remain mandatory. */
        }
    }
    $known =
        (is_int($raw) || is_float($raw))
        && is_finite((float) $raw)
        && $raw >= 0;
    $bytes = $known ? ($raw >= PHP_INT_MAX ? PHP_INT_MAX : (int) $raw) : null;
    return [
        'known' => $known,
        'bytes' => $bytes,
        'admitted' => !$known || $bytes >= CACHE_LIFECYCLE_RESERVE_BYTES,
    ];
}

/**
 * Advance the admitted scheduler burst while its invocation lock remains held.
 * @param float $seconds Requested admission budget in seconds; external callers supply one through ten, independent chain workers five, and direct visitors 40 through 600 ms.
 * @param string $source External caller source normalized before persistence.
 * @param int $maxSlices Maximum slices requested by the admitted caller; the canonical upper bound always remains enforced.
 * @param callable(string):(int|float|false)|null $freeSpaceProbe Optional hosting capacity provider; ordinary visitors/cron use native discovery, and disposable fixtures can reproduce known-low or unavailable measurements.
 * @param bool $foregroundLimited Whether a starved autonomous caller admits only its single forty-millisecond fallback slice despite foreground activity.
 * @param bool $force Bypass only the owning observation due threshold; all resource and mutation guards remain active.
 * @return CacheLifecycleCheckpoint|array{ok:bool,blocked?:bool,busy?:bool,skipped?:bool,reason?:string,exception_class?:string,slices?:int,elapsed_ms?:int,done?:bool,progressed?:bool,id?:string,free_space_known?:bool,free_bytes?:int|null} Lifecycle checkpoint or safe admission/failure marker. Admitted results add actual slice/timing/progress and capacity evidence; early lock refusals never imply work and unknown capacity is not a measured reserve.
 */
function cache_lifecycle_scheduled_burst(
    float $seconds,
    string $source,
    int $maxSlices = CACHE_LIFECYCLE_RUN_SLICES,
    ?callable $freeSpaceProbe = null,
    bool $foregroundLimited = false,
    bool $force = false,
): array {
    // Bound request admission while preserving each owner's shorter I/O slice.
    // Type: float. Units: seconds. Scope: one external or visitor cache invocation.
    // Consumers: burst deadline. Rationale: external work is at most five seconds; visitors use shorter 40-600 ms admission and never extend the parent's execution time.
    $budget = $foregroundLimited
        ? 0.04
        : max(0.04, min((float) CACHE_LIFECYCLE_CHAIN_WORK_SECONDS, $seconds));
    $started = microtime(true);
    $deadline = $started + $budget;
    $maxSlices = $foregroundLimited
        ? 1
        : max(1, min(CACHE_LIFECYCLE_RUN_SLICES, $maxSlices));
    $source = in_array(
        $source,
        ['cli_cron', 'web_cron', 'windows_task', 'public_visit', 'cache_chain'],
        true,
    )
        ? $source
        : 'scheduled';
    $visitor = $source === 'public_visit';
    $signalPath = cache_lifecycle_path('site-maintenance/visitor-signal');
    $foregroundAware = $visitor || $source === 'cache_chain';
    $signal = $foregroundAware
        ? @file_get_contents($signalPath, false, null, 0, 64)
        : false;
    $memoryStarted = memory_get_usage(true);
    $memoryLimit = image_decode_memory_limit_bytes(ini_get('memory_limit'));
    $admission = cache_lifecycle_with_lock(static function () use (
        $source,
    ): array {
        $control = cache_lifecycle_control_directory();
        $record = cache_lifecycle_read_json($control . '/scheduler.json');
        $previous = (int) ($record['last_attempt_at'] ?? 0);
        if ($previous < cache_lifecycle_now())
            $record['previous_attempt_at'] = $previous;
        $record['last_attempt_at'] = cache_lifecycle_now();
        $record['first_attempt_at'] ??= cache_lifecycle_now();
        $record['arrivals'] = (int) ($record['arrivals'] ?? 0) + 1;
        $record['source'] = $source;
        $record['interrupted'] = !empty($record['running']);
        $record['running'] = true;
        cache_lifecycle_write_json($control . '/scheduler.json', $record);
        $state = cache_lifecycle_read_json($control . '/state.json');
        if (
            cache_lifecycle_registry_enabled()
            && (
                $state === []
                || ($state['phase'] ?? '') === 'complete'
            )
        ) {
            $state = cache_lifecycle_registry_summary();
        }
        return ['ok' => true, 'state' => $state];
    });
    if (empty($admission['ok']))
        return $admission;
    $before = (array) $admission['state'];
    $result = [
        'ok' => true,
        'skipped' => true,
        'reason' => 'cache_lifecycle_not_due',
    ];
    $slices = 0;
    $registryProgressed = false;
    $continuations = [];
    $id = '';
    $capacity = ['known' => false, 'bytes' => null, 'admitted' => true];
    try {
        // The scheduler checkpoint above has already committed successfully.
        // Unknown host capacity is not zero space: preserve bounded journals and
        // the mandatory candidate/cursor commit before payload unlink instead.
        $capacity = cache_lifecycle_checkpoint_capacity($freeSpaceProbe);
        if (!$capacity['admitted']) {
            $result = [
                'ok' => false,
                'blocked' => true,
                'reason' => 'cache_checkpoint_space_unavailable',
            ];
        } else {
            while (
                $slices < $maxSlices
                && (microtime(true) + 0.02) < $deadline
            ) {
                if (
                    $foregroundAware
                    && (
                        !$foregroundLimited
                        || $slices > 0
                    )
                    && @file_get_contents($signalPath, false, null, 0, 64)
                        !== $signal
                ) {
                    $result = [
                        'ok' => true,
                        'skipped' => true,
                        'reason' => 'foreground_request_arrived',
                    ];
                    break;
                }
                // Retain headroom rather than allocating another bounded state
                // when this invocation already consumed eight MiB.
                // Type: int. Units: bytes. Scope: incremental worker allocation.
                // Consumers: burst admission. Rationale: the one-MiB state and 4096-name catalog fit inside eight MiB; exceeding it yields with durable checkpoints.
                if (
                    (memory_get_usage(true) - $memoryStarted) >= 8388608
                    || $memoryLimit === null
                    || $memoryLimit > 0
                    && ($memoryLimit - memory_get_usage(true)) < 8388608
                ) {
                    $result = [
                        'ok' => true,
                        'skipped' => true,
                        'reason' => 'cache_memory_budget',
                    ];
                    break;
                }
                $remaining = $deadline - microtime(true);
                $result = cache_lifecycle_scheduled_slice(
                    $force,
                    $id,
                    min(
                        $visitor ? 0.06 : CACHE_LIFECYCLE_COMBINED_SECONDS,
                        $remaining,
                    ),
                    $continuations,
                    $visitor || $foregroundLimited
                        ? 0.0
                        : min(
                            $deadline,
                            microtime(true) + CACHE_LIFECYCLE_CATALOG_SECONDS,
                        ),
                );
                $slices++;
                if (!empty($result['registry'])) {
                    $registryProgressed =
                        $registryProgressed
                        || !empty($result['registry_progressed']);
                    if (empty($result['ok']) || !empty($result['skipped']))
                        break;
                    $key = (string) ($result['region_key'] ?? '');
                    if ($key !== '') {
                        $continuations = array_values(array_filter(
                            $continuations,
                            static fn(string $prior): bool => $prior !== $key,
                        ));
                        $continuations[] = $key;
                    }
                    continue;
                }
                if (
                    empty($result['ok'])
                    || !empty($result['busy'])
                    || ($result['skipped'] ?? false) === true
                    || ($result['phase'] ?? '') === 'complete'
                    || !empty($result['cleanup_blocked'])
                )
                    break;
                $id = (string) ($result['id'] ?? '');
                if ($id === '')
                    break;
                if (
                    $visitor
                    && max(
                        0,
                        (int) ($result['candidate_bytes'] ?? 0)
                            - (int) ($result['settled_bytes'] ?? 0),
                    ) > CACHE_LIFECYCLE_SMALL_BYTES
                ) {
                    $deadline = min($deadline, $started + 0.12);
                    $maxSlices = min($maxSlices, 2);
                }
            }
        }
    } catch (\Throwable $exception) {
        // Retain the exception type without exporting messages, paths or credentials.
        cache_lifecycle_log_failure('scheduled_burst', $source, $exception);
        $result = [
            'ok' => false,
            'blocked' => true,
            'reason' => 'cache_worker_exception',
            'exception_class' => $exception::class,
        ];
    }
    $result['slices'] = $slices;
    $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
    $result['done'] = ($result['phase'] ?? '') === 'complete';
    $result['free_space_known'] = $capacity['known'];
    $result['free_bytes'] = $capacity['bytes'];
    $recorded = cache_lifecycle_with_lock(static function () use (
        $result,
        $before,
        $registryProgressed,
    ): array {
        $control = cache_lifecycle_control_directory();
        $current = cache_lifecycle_read_json($control . '/state.json');
        $registry =
            cache_lifecycle_registry_enabled()
            && ($current === [] || ($current['phase'] ?? '') === 'complete');
        if ($registry)
            $current = cache_lifecycle_registry_summary();
        $record = cache_lifecycle_read_json($control . '/scheduler.json');
        $progress =
            $registryProgressed
            || !empty($current['last_observed_at'])
            && (
                ($before['stack'] ?? []) !== ($current['stack'] ?? [])
                || ($before['totals'] ?? []) !== ($current['totals'] ?? [])
                || ($before['plan_offset'] ?? 0)
                !== ($current['plan_offset'] ?? 0)
                || ($before['removed'] ?? 0) !== ($current['removed'] ?? 0)
                || ($before['phase'] ?? '') !== ($current['phase'] ?? '')
            );
        if ($progress)
            $record['last_progress_at'] = cache_lifecycle_now();
        $record['running'] = false;
        $record['cycle_id'] = (string) ($current['id'] ?? '');
        $record['ok'] = !empty($result['ok']);
        $record['reason'] = (string) ($result['reason'] ?? '');
        $exceptionClass = (string) ($result['exception_class'] ?? '');
        // Anonymous exception class identities can include their source path.
        $record['exception_class'] = str_contains($exceptionClass, chr(0))
        || str_contains($exceptionClass, '/')
        || str_contains($exceptionClass, ':')
            ? 'Throwable'
            : $exceptionClass;
        $record['free_space_known'] = $result['free_space_known'];
        $record['free_bytes'] = $result['free_bytes'];
        $record['slices'] = (int) $result['slices'];
        $record['elapsed_ms'] = (int) $result['elapsed_ms'];
        $record['mode'] = $record['source'] === 'public_visit'
            ? 'visitor'
            : 'external';
        // Inventory's blocked directory count is partial coverage, not a failed
        // worker. Only an explicit boolean failure requests a retry delay.
        if ($current !== [] && ($result['blocked'] ?? false) === true)
            $current['next_due'] =
                cache_lifecycle_now() + CACHE_LIFECYCLE_REFRESH_SECONDS;
        if (!$registry && $current !== [])
            cache_lifecycle_write_json($control . '/state.json', $current);
        cache_lifecycle_write_json($control . '/scheduler.json', $record);
        return [
            'ok' => true,
            'progressed' => $progress,
            'id' => (string) ($current['id'] ?? ''),
        ];
    });
    if (empty($recorded['ok']))
        return $recorded;
    $result['progressed'] = $recorded['progressed'];
    $result['id'] = $recorded['id'];
    $after = cache_lifecycle_registry_enabled() && !empty($before['registry'])
        ? cache_lifecycle_registry_summary()
        : cache_lifecycle_read_json(cache_lifecycle_path(
            'cache-lifecycle/state.json',
        ));
    cache_lifecycle_log_reclamation($before, $after);
    cache_lifecycle_log_activity(
        $after,
        $result['progressed'],
        (string) ($result['reason'] ?? ''),
    );
    return $result;
}
