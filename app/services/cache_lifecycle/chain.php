<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle/chain.php
 * Module Type: Service Part
 * Purpose: Continue automatic cache work through authenticated independent HTTP timer hops.
 * Responsibilities: Lease single-use tickets, exclude overlapping workers, bound waiting and retain honest arrival/failure evidence without browser polling.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Open the dedicated kernel lock shared by chain startup and timer requests.
 * @return resource|false Guarded chain lock, or false if storage cannot be opened; callers use nonblocking acquisition and always close it.
 */
function cache_lifecycle_chain_lock(): mixed
{
    try {
        $path = cache_lifecycle_control_directory() . '/chain.lock';
        cache_lifecycle_assert_path($path);
        return @fopen($path, 'c');
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Deliver one committed ticket without treating a socket write as worker arrival.
 * @param string $ticket Exact sixty-four-character private hexadecimal continuation ticket.
 * @return bool True when the maintenance transport wrote its complete request; false preserves a dispatch failure and permits later leased recovery.
 */
function cache_lifecycle_chain_deliver(string $ticket): bool
{
    try {
        if (function_exists(__NAMESPACE__ . '\\site_maintenance_fire_cache_chain') && site_maintenance_fire_cache_chain($ticket)) return true;
    } catch (\Throwable) {
        // Arbitrary transport diagnostics may contain URLs or credentials.
    }
    $lock = cache_lifecycle_chain_lock();
    try {
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) return false;
        $path = cache_lifecycle_path('cache-lifecycle/chain.json');
        $record = cache_lifecycle_read_json($path);
        if (hash_equals((string) ($record['ticket'] ?? ''), $ticket)) {
            $record['ticket'] = '';
            $record['queued_until'] = 0;
            $record['running'] = false;
            $record['last_error'] = 'cache_chain_dispatch_unavailable';
            cache_lifecycle_write_json($path, $record);
        }
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    return false;
}

/**
 * Start or recover one autonomous installation-wide chain after a suitable visit.
 * @return array{ok:bool,queued:bool,reason:string} New dispatch receipt, existing active/queued ownership, disabled state or safe storage/transport refusal; receipt alone never proves execution.
 */
function cache_lifecycle_chain_start(): array
{
    if (!feature_capability_effective_enabled('automatic_cache_cleanup')) return ['ok'=>true,'queued'=>false,'reason'=>'automatic_cache_cleanup_disabled'];
    $lock = cache_lifecycle_chain_lock();
    $ticket = '';
    try {
        if (!is_resource($lock)) return ['ok'=>false,'queued'=>false,'reason'=>'cache_chain_lock_unavailable'];
        if (!flock($lock, LOCK_EX | LOCK_NB)) return ['ok'=>true,'queued'=>false,'reason'=>'cache_chain_active'];
        $path = cache_lifecycle_path('cache-lifecycle/chain.json');
        $record = cache_lifecycle_read_json($path);
        $now = cache_lifecycle_now();
        if (!cache_lifecycle_chain_runtime_available()) {
            $record['ticket'] = '';
            $record['queued_until'] = 0;
            $record['running'] = false;
            $record['last_error'] = 'cache_chain_single_worker_runtime';
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true,'queued'=>false,'reason'=>$record['last_error']];
        }
        if ((string) ($record['ticket'] ?? '') !== '' && (int) ($record['queued_until'] ?? 0) > $now) {
            return ['ok'=>true,'queued'=>false,'reason'=>'cache_chain_already_queued'];
        }
        if ($now - (int) ($record['last_dispatch_at'] ?? 0) < CACHE_LIFECYCLE_REFRESH_SECONDS) {
            return ['ok'=>true,'queued'=>false,'reason'=>'cache_chain_dispatch_throttled'];
        }
        $ticket = bin2hex(random_bytes(32));
        $record['ticket'] = $ticket;
        $record['started_at'] ??= $now;
        $record['next_start_at'] = $now;
        $record['last_dispatch_at'] = $now;
        $record['queued_until'] = $now + CACHE_LIFECYCLE_CHAIN_LEASE_SECONDS;
        $record['running'] = false;
        $record['last_error'] = '';
        cache_lifecycle_write_json($path, $record);
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    $queued = cache_lifecycle_chain_deliver($ticket);
    return ['ok'=>$queued,'queued'=>$queued,'reason'=>$queued ? 'cache_chain_queued' : 'cache_chain_dispatch_unavailable'];
}

/**
 * Consume one authenticated ticket, wait within the declared runtime and hand off the next request.
 * @param string $ticket Single-use private hexadecimal ticket selected by the token-protected controller, never a URL or filesystem path.
 * @return array{ok:bool,queued:bool,reason:string,worked:bool} Safe timer/worker outcome; worked denotes an admitted owner attempt, queued a complete request write, and neither implies progress or successful deletion.
 */
function cache_lifecycle_chain_run(string $ticket): array
{
    $refused = ['ok'=>false,'queued'=>false,'reason'=>'cache_chain_ticket_refused','worked'=>false];
    if (preg_match('/^[a-f0-9]{64}$/', $ticket) !== 1) return $refused;
    $lock = cache_lifecycle_chain_lock();
    $nextTicket = '';
    $worked = false;
    $ok = true;
    $reason = 'cache_chain_wait';
    try {
        if (!is_resource($lock)) return ['ok'=>false,'queued'=>false,'reason'=>'cache_chain_lock_unavailable','worked'=>false];
        if (!flock($lock, LOCK_EX | LOCK_NB)) return ['ok'=>true,'queued'=>false,'reason'=>'cache_chain_active','worked'=>false];
        $path = cache_lifecycle_path('cache-lifecycle/chain.json');
        $record = cache_lifecycle_read_json($path);
        $now = cache_lifecycle_now();
        if (!hash_equals((string) ($record['ticket'] ?? ''), $ticket) || (int) ($record['queued_until'] ?? 0) < $now) return $refused;
        $record['ticket'] = '';
        $record['running'] = true;
        $record['last_arrival_at'] = $now;
        $record['queued_until'] = $now + CACHE_LIFECYCLE_CHAIN_LEASE_SECONDS;
        $record['last_error'] = '';
        cache_lifecycle_write_json($path, $record);
        if (!cache_lifecycle_chain_runtime_available()) {
            $record['running'] = false;
            $record['queued_until'] = 0;
            $record['last_error'] = 'cache_chain_single_worker_runtime';
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true,'queued'=>false,'reason'=>$record['last_error'],'worked'=>false];
        }
        if (!feature_capability_effective_enabled('automatic_cache_cleanup')) {
            $record['running'] = false;
            $record['queued_until'] = 0;
            $record['last_error'] = 'automatic_cache_cleanup_disabled';
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true,'queued'=>false,'reason'=>'automatic_cache_cleanup_disabled','worked'=>false];
        }
        // Leave eight seconds for bounded work, connection dispatch and cleanup.
        // Type: int. Units: seconds. Scope: this independent HTTP timer request.
        // Consumers: wait admission. Rationale: the worker does not extend a hosting execution limit; shorter limits split the timer into additional bounded hops.
        $limit = max(0, (int) ini_get('max_execution_time'));
        $waitRoom = $limit === 0 ? CACHE_LIFECYCLE_CHAIN_WAIT_SECONDS : min(CACHE_LIFECYCLE_CHAIN_WAIT_SECONDS, $limit - 8);
        if ($waitRoom < 1) {
            $record['running'] = false;
            $record['queued_until'] = 0;
            $record['last_error'] = 'cache_chain_runtime_budget_unavailable';
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>false,'queued'=>false,'reason'=>$record['last_error'],'worked'=>false];
        }
        $wait = min($waitRoom, max(0, (int) ($record['next_start_at'] ?? 0) - $now));
        if ($wait > 0) sleep($wait);
        if (!feature_capability_effective_enabled('automatic_cache_cleanup')) {
            $record['running'] = false;
            $record['queued_until'] = 0;
            $record['last_error'] = 'automatic_cache_cleanup_disabled';
            cache_lifecycle_write_json($path, $record);
            return ['ok'=>true,'queued'=>false,'reason'=>'automatic_cache_cleanup_disabled','worked'=>false];
        }
        $started = cache_lifecycle_now();
        if ($started >= (int) ($record['next_start_at'] ?? 0)) {
            $work = cache_lifecycle_visitor_run(true, true);
            $worked = true;
            $ok = !empty($work['ok']);
            $reason = (string) ($work['reason'] ?? 'cache_chain_work');
            $record['last_work_at'] = $started;
            $record['next_start_at'] = max(cache_lifecycle_now(), $started + CACHE_LIFECYCLE_REFRESH_SECONDS);
            $record['last_error'] = $ok ? '' : $reason;
        }
        $nextTicket = bin2hex(random_bytes(32));
        $record['ticket'] = $nextTicket;
        $record['running'] = false;
        $record['last_dispatch_at'] = cache_lifecycle_now();
        $record['queued_until'] = cache_lifecycle_now() + CACHE_LIFECYCLE_CHAIN_LEASE_SECONDS;
        cache_lifecycle_write_json($path, $record);
    } catch (\Throwable) {
        try {
            if (isset($record, $path)) {
                $record['ticket'] = '';
                $record['running'] = false;
                $record['queued_until'] = 0;
                $record['last_error'] = 'cache_chain_exception';
                cache_lifecycle_write_json($path, $record);
            }
        } catch (\Throwable) { /* Failed storage leaves the original bounded lease recoverable. */ }
        return ['ok'=>false,'queued'=>false,'reason'=>'cache_chain_exception','worked'=>$worked];
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    // The parent relinquishes its live chain lock before the child can arrive.
    $queued = cache_lifecycle_chain_deliver($nextTicket);
    return ['ok'=>$ok && $queued,'queued'=>$queued,'reason'=>$queued ? $reason : 'cache_chain_dispatch_unavailable','worked'=>$worked];
}
