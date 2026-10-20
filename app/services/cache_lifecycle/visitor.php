<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/visitor.php
 * Module Type: Service Part
 * Purpose: Admit short visitor-driven opportunities to the canonical cache lifecycle.
 * Responsibilities: Preserve foreground priority, thirty-second due dates, worker exclusion and bounded visitor/autonomous admission.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Admit one visitor burst with foreground priority and shared scheduler exclusion.
 * @param bool $detached Whether a direct visitor's response was finished; false retains a 40 ms direct admission budget. Independent chained requests use their own budget without implying this primitive exists.
 * @param bool $chained Whether an authenticated independent HTTP worker owns this invocation; true admits up to five seconds without making a browser wait.
 * @return CacheLifecycleCheckpoint|array{ok:bool,blocked?:bool,busy?:bool,skipped?:bool,reason?:string,slices?:int,elapsed_ms?:int,done?:bool,progressed?:bool,id?:string,free_space_known?:bool,free_bytes?:int|null,exception_class?:string} Canonical checkpoint or admission outcome; admitted bursts report actual counters and timing without substituting fixture data.
 */
function cache_lifecycle_visitor_run(
    bool $detached,
    bool $chained = false,
): array {
    if (!feature_capability_effective_enabled('automatic_cache_cleanup'))
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'automatic_cache_cleanup_disabled',
        ];
    $directory = cache_lifecycle_path('site-maintenance');
    if (
        !is_dir($directory)
        && !@mkdir($directory, 0775, true)
        && !is_dir($directory)
    )
        return [
            'ok' => false,
            'blocked' => true,
            'reason' => 'cache_visitor_storage_unavailable',
        ];
    foreach (['visitor.lock', 'visitor-attempt', 'visitor-signal'] as $name)
        cache_lifecycle_assert_path($directory . '/' . $name);
    $path = cache_lifecycle_control_directory() . '/scheduler.lock';
    cache_lifecycle_assert_path($path);
    $scheduler = @fopen($path, 'c');
    $foreground = @fopen($directory . '/visitor.lock', 'c');
    try {
        if (!is_resource($scheduler) || !is_resource($foreground))
            return [
                'ok' => false,
                'blocked' => true,
                'reason' => 'cache_visitor_lock_unavailable',
            ];
        if (!flock($scheduler, LOCK_EX | LOCK_NB))
            return [
                'ok' => true,
                'busy' => true,
                'skipped' => true,
                'reason' => 'cache_scheduler_busy',
            ];
        clearstatcache(true, $directory . '/visitor-attempt');
        if (
            is_file($directory . '/visitor-attempt')
            && (
                cache_lifecycle_now()
                - (int) filemtime($directory . '/visitor-attempt')
            ) < CACHE_LIFECYCLE_REFRESH_SECONDS
        )
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'cache_visitor_throttled',
            ];
        if (!@touch($directory . '/visitor-attempt', cache_lifecycle_now()))
            return [
                'ok' => false,
                'blocked' => true,
                'reason' => 'cache_visitor_checkpoint_unavailable',
            ];
        $foregroundLimited = !flock($foreground, LOCK_EX | LOCK_NB);
        if ($foregroundLimited) {
            $record = cache_lifecycle_read_json(cache_lifecycle_path(
                'cache-lifecycle/scheduler.json',
            ));
            $lastProgress = (int) ($record['last_progress_at'] ?? 0)
            ?: (int) ($record['first_attempt_at'] ?? cache_lifecycle_now());
            if (
                !$chained
                || (cache_lifecycle_now() - $lastProgress)
                    < CACHE_LIFECYCLE_HEALTH_SECONDS
            ) {
                cache_lifecycle_scheduler_deferred(
                    $chained ? 'cache_chain' : 'public_visit',
                    'foreground_requests_active',
                );
                return [
                    'ok' => true,
                    'busy' => true,
                    'skipped' => true,
                    'reason' => 'foreground_requests_active',
                ];
            }
        }
        if (is_array(\Gallery\Models\maintenance_center_model_mutation_owner(
            true,
        ))) {
            cache_lifecycle_scheduler_deferred(
                $chained ? 'cache_chain' : 'public_visit',
                'central_maintenance_active',
            );
            return [
                'ok' => true,
                'busy' => true,
                'skipped' => true,
                'reason' => 'central_maintenance_active',
            ];
        }
        cache_lifecycle_assert_path($directory . '/worker.lock');
        $siteLock = @fopen($directory . '/worker.lock', 'c');
        try {
            if (
                !is_resource($siteLock)
                || !flock($siteLock, LOCK_EX | LOCK_NB)
            ) {
                cache_lifecycle_scheduler_deferred(
                    $chained ? 'cache_chain' : 'public_visit',
                    'maintenance_already_running',
                );
                return [
                    'ok' => true,
                    'busy' => true,
                    'skipped' => true,
                    'reason' => 'maintenance_already_running',
                ];
            }
            $state = cache_lifecycle_read_json(cache_lifecycle_path(
                'cache-lifecycle/state.json',
            ));
            $pending = max(
                0,
                (int) ($state['candidate_bytes'] ?? 0)
                    - (int) ($state['settled_bytes'] ?? 0),
            );
            // Bound worker occupancy independently of payload bytes.
            // Type: float. Units: seconds. Scope: one automatic invocation.
            // Consumers: canonical burst deadline. Rationale: independent workers get five seconds; after ninety seconds without progress a foreground-contended chain gets only forty milliseconds. Direct visits retain 600/120 ms after response finishing or forty ms otherwise; blocking I/O can overrun admission.
            $seconds = $foregroundLimited
                ? 0.04
                : (
                    $chained
                        ? (float) CACHE_LIFECYCLE_CHAIN_WORK_SECONDS
                        : (
                            !$detached
                                ? 0.04
                                : (
                                    $pending <= CACHE_LIFECYCLE_SMALL_BYTES
                                        ? 0.60
                                        : 0.12
                                )
                        )
                );
            // Cap admissions even on a very fast filesystem.
            // Type: int. Units: slices. Scope: one automatic invocation.
            // Consumers: canonical burst loop. Rationale: independent workers retain thirty-two slices, but the starved foreground fallback gets at most one within its deadline. Direct small visits get eight and large/fallback visits two.
            $slices = $foregroundLimited
                ? 1
                : (
                    $chained
                        ? CACHE_LIFECYCLE_RUN_SLICES
                        : (
                            $detached && $pending <= CACHE_LIFECYCLE_SMALL_BYTES
                                ? 8
                                : 2
                        )
                );
            return cache_lifecycle_scheduled_burst(
                $seconds,
                $chained ? 'cache_chain' : 'public_visit',
                $slices,
                null,
                $foregroundLimited,
            );
        } finally {
            if (is_resource($siteLock)) {
                flock($siteLock, LOCK_UN);
                fclose($siteLock);
            }
        }
    } catch (\Throwable $exception) {
        cache_lifecycle_log_failure(
            'visitor_admission',
            $chained ? 'cache_chain' : 'public_visit',
            $exception,
        );
        try {
            cache_lifecycle_scheduler_deferred(
                $chained ? 'cache_chain' : 'public_visit',
                'cache_visitor_exception',
            );
        } catch (\Throwable) {
        }
        return [
            'ok' => false,
            'blocked' => true,
            'reason' => 'cache_visitor_exception',
        ];
    } finally {
        foreach ([$foreground, $scheduler] as $handle)
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
    }
}
