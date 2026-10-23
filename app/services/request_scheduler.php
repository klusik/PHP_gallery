<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/request_scheduler.php
 * Module Type: Service
 * Purpose: Centralize visitor scheduling without taking over domain policies.
 * Responsibilities: Admit one bounded owner, preserve leases, expose passive state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Recover a private request that was accepted but never reached a worker.
 * Type: int. Units: seconds. Scope: one installation scheduler handoff.
 * Consumers: scheduler admission and private ticket validation.
 * Rationale: 180 seconds covers the existing maximum 120-second maintenance
 * slice plus transport delay; a held flock still excludes a live worker.
 */
const REQUEST_SCHEDULER_LEASE_SECONDS = 180;

/**
 * Match the existing private-worker credential format with 256 bits of entropy.
 * Type: int. Units: random bytes. Scope: scheduler tickets and hosting tokens.
 * Consumers: ticket admission and explicit hosting credential rotation.
 * Rationale: 32 random bytes encode to the existing fixed 64-hex-character wire
 * format; this is not a user-configurable reduction in credential strength.
 */
const REQUEST_SCHEDULER_TOKEN_BYTES = 32;


/**
 * Bound private scheduler coordination reads.
 * Type: int. Units: bytes. Scope: the seven-task dispatch receipt and ticket.
 * Consumers: request_scheduler_read_state.
 * Rationale: 16 KiB covers fixed IDs and scalar receipts without reading
 * arbitrary growing files during visitor requests or one-second Admin polls.
 */
const REQUEST_SCHEDULER_STATE_BYTES = 16384;

/**
 * Back off a failed private transport without changing any domain schedule.
 * Type: int. Units: seconds. Scope: one task's failed scheduler handoff.
 * Consumers: passive task projection and the next visitor/hosting admission.
 * Rationale: 30 seconds prevents a blocked loopback from adding a socket attempt
 * to every page view; manual timing overrides may explicitly retry sooner.
 */
const REQUEST_SCHEDULER_RETRY_SECONDS = 30;

/**
 * Bound authenticated hosting calls before domain observations or transport.
 * Type: int. Units: seconds. Scope: optional hosting URL per installation.
 * Consumers: host-call admission and Retry-After responses.
 * Rationale: 60 seconds permits the documented one-minute hosting cadence and
 * rejects repeated calls cheaply; visitor/manual scheduling remains independent.
 */
const REQUEST_SCHEDULER_HOST_INTERVAL_SECONDS = 60;


require_once __DIR__ . '/request_scheduler_trigger.php';
require_once __DIR__ . '/request_scheduler/status.php';
require_once __DIR__ . '/request_scheduler/execution.php';

/**
 * Resolve a fixed scheduler coordination path without creating any storage.
 * @param string $name Fixed scheduler filename selected by this service.
 * @return string Guarded absolute path under the installation cache.
 */
function request_scheduler_path(string $name): string
{
    if (!in_array($name, ['state.json', 'worker.lock'], true)) {
        throw new \InvalidArgumentException('Invalid scheduler state path.');
    }
    $root = defined('CACHE_LIFECYCLE_TEST_ROOT')
        ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT')
        : dirname(__DIR__, 2);
    $relative = 'cache/request-scheduler/' . $name;
    application_update_assert_safe_target($root, $relative, true);
    return $root . '/' . $relative;
}

/**
 * Read existing scheduler evidence without initialization or write side effects.
 * @return array{ticket?:string,task?:string,force?:bool,expires_at?:int,host_next_at?:int,turns?:array<string,int>,receipts?:array<string,array{attempt_at:int,outcome:string}>} Existing private handoff, hosting throttle and bounded dispatch receipts, or empty for absent storage.
 */
function request_scheduler_read_state(): array
{
    $path = request_scheduler_path('state.json');
    if (!is_file($path)) {
        return [];
    }
    $json = @file_get_contents(
        $path, false, null, 0, REQUEST_SCHEDULER_STATE_BYTES + 1,
    );
    if (!is_string($json) || strlen($json) > REQUEST_SCHEDULER_STATE_BYTES) {
        throw new \RuntimeException('Scheduler state unavailable.');
    }
    $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($state)) {
        throw new \RuntimeException('Invalid scheduler state.');
    }
    return $state;
}

/**
 * Publish coordination state atomically while holding the scheduler flock.
 * @param array{ticket?:string,task?:string,force?:bool,expires_at?:int,host_next_at?:int,turns?:array<string,int>,receipts?:array<string,array{attempt_at:int,outcome:string}>} $state Complete bounded coordination state, including the independent hosting throttle.
 * @return void Replaces the receipt or throws, preserving the prior complete file.
 */
function request_scheduler_write_state(array $state): void
{
    $path = request_scheduler_path('state.json');
    $temporary = tempnam(dirname($path), 'scheduler-');
    if ($temporary === false) {
        throw new \RuntimeException('Scheduler storage unavailable.');
    }
    try {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($json) > REQUEST_SCHEDULER_STATE_BYTES
            || file_put_contents($temporary, $json, LOCK_EX) === false
            || !rename($temporary, $path)) {
            throw new \RuntimeException('Scheduler receipt unavailable.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * Acquire the installation scheduler without waiting for another request.
 * @return resource|false Exclusive open flock, or false for busy/unavailable storage.
 */
function request_scheduler_lock(): mixed
{
    $path = request_scheduler_path('worker.lock');
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true)
        && !is_dir($directory)) {
        return false;
    }
    $lock = @fopen($path, 'c');
    if (!is_resource($lock)) {
        return false;
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return false;
    }
    return $lock;
}

/**
 * Release an acquired scheduler lock, including exceptional owner exits.
 * @param resource $lock Exclusive handle returned by request_scheduler_lock.
 * @return void Releases the flock and closes its handle.
 */
function request_scheduler_unlock(mixed $lock): void
{
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * Read or explicitly replace the narrowly scoped optional hosting credential.
 * @param string $action read, rotate or revoke; mutation callers must enforce Admin CSRF.
 * @return string Existing/new hexadecimal secret, or empty after revocation/absence.
 */
function request_scheduler_host_token(string $action = 'read'): string
{
    if ($action === 'rotate') {
        $token = bin2hex(random_bytes(REQUEST_SCHEDULER_TOKEN_BYTES));
        set_app_setting('request_scheduler_host_token', $token);
        return $token;
    }
    if ($action === 'revoke') {
        set_app_setting('request_scheduler_host_token', '');
        return '';
    }
    if ($action !== 'read') {
        throw new \InvalidArgumentException('Invalid scheduler token action.');
    }
    $token = (string) app_setting('request_scheduler_host_token', '');
    return preg_match('/^[a-f0-9]{64}$/D', $token) === 1 ? $token : '';
}

/**
 * Admit an already authenticated hosting call at most once per minute.
 * @return bool True after atomically reserving its window; false for busy or
 * throttled calls. Preserves task tickets/receipts and never invokes an owner.
 */
function request_scheduler_claim_host_call(): bool
{
    $lock = request_scheduler_lock();
    if (!is_resource($lock)) {
        return false;
    }
    try {
        $state = request_scheduler_read_state();
        $now = time();
        if ((int) ($state['host_next_at'] ?? 0) > $now) {
            return false;
        }
        $state['host_next_at'] = $now + REQUEST_SCHEDULER_HOST_INTERVAL_SECONDS;
        request_scheduler_write_state($state);
        return true;
    } finally {
        request_scheduler_unlock($lock);
    }
}

/**
 * Build the optional URL through the installation's canonical route builder.
 * @return string Absolute rewrite/subdirectory-safe URL, or empty before explicit credential creation.
 */
function request_scheduler_host_url(): string
{
    $token = request_scheduler_host_token();
    return $token === '' ? '' : \Gallery\Core\absolute_public_url(
        \Gallery\Core\url_for('request_scheduler_cron', ['token' => $token]));
}
