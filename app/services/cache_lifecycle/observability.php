<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/observability.php
 * Module Type: Service Part
 * Purpose: Report bounded automatic cache failures and coalesced removals through the Admin log owner.
 * Responsibilities: Preserve confirmed counters, rate-limit failure and removal receipts, and omit private paths, messages, secrets and idle checks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Report actual automatic progress or a safe deferral without per-file or idle log noise.
 * @param array{phase?:string,bytes?:int,removed?:int,bytes_removed?:int,pending_bytes?:int,errors?:int,blocked?:int|bool,regions_total?:int,regions_completed?:int,regions_working?:int} $state Committed aggregate evidence; omitted fields stay absent for an admission refusal.
 * @param bool $progressed Whether this burst changed a durable cursor, observation, phase or removal receipt.
 * @param string $reason Owner-controlled outcome enum, never an exception message or request value.
 * @return void Publishes at most one status attempt per five minutes; logging cannot alter the worker outcome or authorize deletion.
 */
function cache_lifecycle_log_activity(array $state, bool $progressed, string $reason = ''): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event_result')
        || !$progressed && empty($state['errors']) && empty($state['blocked']) && in_array($reason, ['', 'cache_lifecycle_not_due', 'cache_visitor_throttled'], true)) return;
    try {
        $claim = cache_lifecycle_with_lock(static function (): array {
            $path = cache_lifecycle_control_directory() . '/activity-summary.json';
            $record = cache_lifecycle_read_json($path);
            if (cache_lifecycle_now() - (int) ($record['attempted_at'] ?? 0) < CACHE_LIFECYCLE_LOG_SECONDS) return ['ok'=>false];
            cache_lifecycle_write_json($path, ['attempted_at'=>cache_lifecycle_now()]);
            return ['ok'=>true];
        });
        if (empty($claim['ok'])) return;
        $context = ['progressed'=>$progressed,'phase'=>(string) ($state['phase'] ?? 'unobserved'),
            'reason'=>preg_match('/^[a-z0-9_]*$/D', $reason) === 1 ? $reason : 'cache_worker_refused'];
        foreach (['bytes','removed','bytes_removed','pending_bytes','errors','blocked','regions_total','regions_completed','regions_working'] as $field) {
            if (isset($state[$field])) $context[$field] = (int) $state[$field];
        }
        $level = !empty($state['errors']) || !empty($state['blocked']) ? 'warning' : 'info';
        admin_log_event_result($level, 'cache.automatic_status',
            'Automatic cache worker status; progress and confirmed removals are separate evidence.', $context,
            ['category'=>'system','severity'=>$level,'subject_type'=>'cache','route_name'=>'site_maintenance_cron']);
    } catch (\Throwable) {
        // A missing receipt cannot change work, retry, admission or checkpoint state.
    }
}

/**
 * Record a bounded automatic-worker failure through the existing Admin log owner.
 * @param string $stage Fixed visitor_admission, scheduled_burst or region_slice failure boundary.
 * @param string $source Entry-point enum identifying the admitted visitor, timer, external scheduler or regional worker.
 * @param \Throwable $exception Caught failure; only class, application source position and validated database codes are retained.
 * @return void Best-effort, rate-limited diagnostics preserve the original refusal and never expose messages, SQL, paths, tickets or credentials.
 */
function cache_lifecycle_log_failure(string $stage, string $source, \Throwable $exception): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event_result')
        || !in_array($stage, ['visitor_admission','scheduled_burst','region_slice'], true)) return;
    try {
        $source = in_array($source, ['public_visit','cache_chain','cli_cron','web_cron','windows_task','scheduled','region_worker'], true) ? $source : 'scheduled';
        $class = $exception::class;
        $context = ['stage'=>$stage,'source'=>$source,
            'exception_class'=>str_contains($class, chr(0)) || str_contains($class, '/') || str_contains($class, ':') ? 'Throwable' : $class,
            'source_file'=>basename($exception->getFile()),'source_line'=>$exception->getLine()];
        $cause = $exception instanceof \PDOException ? $exception : $exception->getPrevious();
        if ($cause instanceof \PDOException) {
            $sqlstate = (string) ($cause->errorInfo[0] ?? $cause->getCode());
            if (preg_match('/^[A-Z0-9]{5}$/', $sqlstate) === 1) $context['sqlstate'] = $sqlstate;
            if (isset($cause->errorInfo[1]) && is_numeric($cause->errorInfo[1])) $context['driver_code'] = (int) $cause->errorInfo[1];
        }
        $signature = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
        $claim = cache_lifecycle_with_lock(static function () use ($stage, $signature): array {
            $path = cache_lifecycle_control_directory() . '/failure-summary.json';
            $record = cache_lifecycle_read_json($path);
            $previous = is_array($record[$stage] ?? null) ? $record[$stage] : [];
            $age = cache_lifecycle_now() - (int) ($previous['attempted_at'] ?? 0);
            if ($age < CACHE_LIFECYCLE_REFRESH_SECONDS
                || ($previous['signature'] ?? '') === $signature && $age < CACHE_LIFECYCLE_LOG_SECONDS) return ['ok'=>false];
            // Claim before insertion so unavailable logging cannot create a retry storm.
            $record[$stage] = ['signature'=>$signature,'attempted_at'=>cache_lifecycle_now()];
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true];
        });
        if (!empty($claim['ok'])) admin_log_event_result('warning', 'cache.automatic_failed',
            'Automatic cache work stopped safely; diagnostic codes identify the failing stage.', $context,
            ['category'=>'system','severity'=>'warning','subject_type'=>'cache','route_name'=>'site_maintenance_cron']);
    } catch (\Throwable) {
        // Diagnostics must neither conceal the worker outcome nor affect its checkpoint.
    }
}

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
