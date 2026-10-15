<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/observability.php
 * Module Type: Service Part
 * Purpose: Coalesce real automatic cache removals through the existing Admin log owner.
 * Responsibilities: Retain bounded counters across cycles, rate-limit confirmed summaries and omit file paths, secrets and idle checks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Aggregate committed removals and occasionally publish one confirmed Admin log event.
 * @param array{id?:string,removed?:int,bytes_removed?:int,candidate_bytes?:int} $before Cycle identity and counters captured before this burst; a new cycle starts at zero.
 * @param array{id?:string,removed?:int,bytes_removed?:int,candidate_bytes?:int} $after Actual committed cycle identity and counters after the burst, not an eligible-size estimate.
 * @return void Best-effort logging never invalidates successful reclamation; pending counters remain if the existing log owner reports failure.
 */
function cache_lifecycle_log_reclamation(array $before, array $after): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event_result')) return;
    try {
        cache_lifecycle_with_lock(static function () use ($before, $after): array {
            $path = cache_lifecycle_path('cache-lifecycle/log-summary.json');
            $record = cache_lifecycle_read_json($path);
            $sameCycle = (string) ($before['id'] ?? '') === (string) ($after['id'] ?? '');
            $removed = max(0, (int) ($after['removed'] ?? 0) - ($sameCycle ? (int) ($before['removed'] ?? 0) : 0));
            $bytes = max(0, (int) ($after['bytes_removed'] ?? 0) - ($sameCycle ? (int) ($before['bytes_removed'] ?? 0) : 0));
            $record['removed'] = max(0, (int) ($record['removed'] ?? 0)) + $removed;
            $record['bytes_removed'] = max(0, (int) ($record['bytes_removed'] ?? 0)) + $bytes;
            if ($record['removed'] === 0 && $record['bytes_removed'] === 0) return ['ok'=>true];
            $record['first_removed_at'] ??= cache_lifecycle_now();
            if (cache_lifecycle_now() - (int) ($record['last_log_attempt_at'] ?? $record['last_logged_at'] ?? 0) >= CACHE_LIFECYCLE_LOG_SECONDS) {
                // Commit the rate-limit claim before asking the existing logger
                // for a receipt; failed writes cannot generate a log storm.
                $record['last_log_attempt_at'] = cache_lifecycle_now();
                cache_lifecycle_write_json($path, $record);
                $receipt = admin_log_event_result('info', 'cache.automatic_reclaimed', 'Automatic cache cleanup removed verified expired data.',
                    ['removed_entries'=>$record['removed'],'bytes_removed'=>$record['bytes_removed'],
                        'from_at'=>(int) $record['first_removed_at'],'to_at'=>cache_lifecycle_now()],
                    ['category'=>'maintenance','severity'=>'info','subject_type'=>'cache','route_name'=>'site_maintenance_cron']);
                if (!empty($receipt['ok'])) {
                    $record['removed'] = 0;
                    $record['bytes_removed'] = 0;
                    $record['last_logged_at'] = cache_lifecycle_now();
                    unset($record['first_removed_at']);
                }
            }
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true];
        });
    } catch (\Throwable) {
        // Log failures cannot hide, undo or extend a successful cleanup burst.
    }
}
