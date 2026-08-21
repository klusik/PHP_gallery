<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/diagnostics/admin_test_run_early.php
 * Module Type: Diagnostics Helper
 *
 * Purpose:
 *   Captures ultra-early, low-overhead bootstrap timing before the normal service layer is available.
 *
 * Responsibilities:
 *   - Detect a plausible active Admin Test Run from its short-lived opaque cookie without loading the application
 *   - Detect the authenticated Test Run starter route early enough to time bootstrap work before its controller runs
 *   - Record bounded phase timing, memory deltas, included-file deltas, and last-error changes in memory
 *   - Preserve a minimal bootstrap-failure sidecar for a valid active Test Run when the full diagnostics service never loads
 *   - Hand the early trace to the normal Admin Test Run service once authenticated request instrumentation begins
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This file intentionally has no database, session, configuration, or service dependency.
 *   - Candidate detection performs at most one small metadata-file read and never recursively scans the filesystem.
 *
 * Last Updated:
 *   2026-08-21
 */

declare(strict_types=1);

namespace Gallery\Diagnostics;

use Throwable;

const ADMIN_TEST_RUN_EARLY_COOKIE = 'gallery_admin_test_run';
const ADMIN_TEST_RUN_EARLY_TTL_SECONDS = 600;

/**
 * Return the earliest server-provided request timestamp available to PHP.
 */
function admin_test_run_early_request_time(): float
{
    if (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])) {
        return (float) $_SERVER['REQUEST_TIME_FLOAT'];
    }
    if (isset($_SERVER['REQUEST_TIME']) && is_numeric($_SERVER['REQUEST_TIME'])) {
        return (float) $_SERVER['REQUEST_TIME'];
    }
    return microtime(true);
}

/**
 * Return a privacy-safe fingerprint for the current last PHP error.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_early_last_error(): ?array
{
    $error = error_get_last();
    if (!is_array($error)) {
        return null;
    }
    return [
        'type' => (int) ($error['type'] ?? 0),
        'message' => substr((string) ($error['message'] ?? ''), 0, 1000),
        'file' => basename((string) ($error['file'] ?? '')),
        'line' => (int) ($error['line'] ?? 0),
    ];
}

/**
 * Return true when the raw request is the Test Run starter route.
 */
function admin_test_run_early_is_starter_request(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return false;
    }
    if ((string) ($_GET['page'] ?? '') === 'admin_test_run_start') {
        return true;
    }
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') {
        parse_str($query, $params);
        if ((string) ($params['page'] ?? '') === 'admin_test_run_start') {
            return true;
        }
    }
    return str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'page=admin_test_run_start');
}

/**
 * Return active Test Run metadata for a plausible cookie without bootstrapping the application.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_early_cookie_context(string $projectRoot): ?array
{
    $token = strtolower(trim((string) ($_COOKIE[ADMIN_TEST_RUN_EARLY_COOKIE] ?? '')));
    if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
        return null;
    }
    $path = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-test-runs'
        . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . 'meta.json';
    if (!is_file($path) || (@filesize($path) ?: 0) > 512 * 1024) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta)) {
        return null;
    }
    $createdAt = (int) ($meta['created_at_unix'] ?? 0);
    if ($createdAt <= 0 || time() - $createdAt > ADMIN_TEST_RUN_EARLY_TTL_SECONDS || !empty($meta['finalized_at'])) {
        return null;
    }
    if (!hash_equals($token, strtolower((string) ($meta['token'] ?? '')))) {
        return null;
    }
    return ['token' => $token, 'meta_path' => $path];
}

/**
 * Initialize the in-memory early trace when this request can plausibly participate in a Test Run.
 */
function admin_test_run_early_init(string $projectRoot): void
{
    if (isset($GLOBALS['gallery_admin_test_run_early']) && is_array($GLOBALS['gallery_admin_test_run_early'])) {
        return;
    }
    $cookieContext = admin_test_run_early_cookie_context($projectRoot);
    $starterCandidate = admin_test_run_early_is_starter_request();
    if ($cookieContext === null && !$starterCandidate) {
        return;
    }

    $requestTime = admin_test_run_early_request_time();
    $now = microtime(true);
    $requestId = sprintf(
        '%s-%s-%s',
        (string) (function_exists('getmypid') ? getmypid() : 0),
        str_replace('.', '', sprintf('%.6f', $now)),
        bin2hex(random_bytes(4))
    );
    $state = [
        'enabled' => true,
        'candidate_reason' => $cookieContext !== null ? 'active_cookie_context' : 'starter_route_candidate',
        'project_root' => rtrim($projectRoot, '/\\'),
        'token' => (string) ($cookieContext['token'] ?? ''),
        'request_id' => $requestId,
        'request_time_unix' => $requestTime,
        'initialized_at_unix' => $now,
        'adopted' => false,
        'phases' => [],
        'open_phases' => [],
        'marks' => [],
    ];
    $GLOBALS['gallery_admin_test_run_early'] = $state;

    admin_test_run_early_phase_record('entrypoint', $requestTime, $now, null, null, true, null);
    register_shutdown_function(static function (): void {
        admin_test_run_early_shutdown_fallback();
    });
}

/**
 * Record one already-completed early phase.
 */
function admin_test_run_early_phase_record(
    string $name,
    float $startedAt,
    float $endedAt,
    ?int $memoryStart,
    ?int $includedStart,
    bool $completed,
    ?array $error
): void {
    if (!isset($GLOBALS['gallery_admin_test_run_early']) || !is_array($GLOBALS['gallery_admin_test_run_early'])) {
        return;
    }
    $GLOBALS['gallery_admin_test_run_early']['phases'][] = [
        'name' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $name) ?: 'phase',
        'started_at_unix' => $startedAt,
        'ended_at_unix' => $endedAt,
        'duration_ms' => max(0.0, ($endedAt - $startedAt) * 1000),
        'memory_start_bytes' => $memoryStart,
        'memory_end_bytes' => memory_get_usage(true),
        'memory_delta_bytes' => $memoryStart === null ? null : memory_get_usage(true) - $memoryStart,
        'included_files_start' => $includedStart,
        'included_files_end' => count(get_included_files()),
        'included_files_delta' => $includedStart === null ? null : count(get_included_files()) - $includedStart,
        'completed' => $completed,
        'error_or_warning' => $error,
    ];
}

/**
 * Start one bootstrap phase with constant-time process observations only.
 */
function admin_test_run_early_phase_start(string $name): void
{
    if (!isset($GLOBALS['gallery_admin_test_run_early']) || !is_array($GLOBALS['gallery_admin_test_run_early'])
        || !empty($GLOBALS['gallery_admin_test_run_early']['adopted'])) {
        return;
    }
    $GLOBALS['gallery_admin_test_run_early']['open_phases'][$name] = [
        'started_at_unix' => microtime(true),
        'memory_start_bytes' => memory_get_usage(true),
        'included_files_start' => count(get_included_files()),
        'last_error_before' => admin_test_run_early_last_error(),
    ];
}

/**
 * Finish one bootstrap phase and record whether a new PHP warning/error appeared.
 */
function admin_test_run_early_phase_end(string $name, bool $completed = true, ?Throwable $exception = null): void
{
    if (!isset($GLOBALS['gallery_admin_test_run_early']) || !is_array($GLOBALS['gallery_admin_test_run_early'])
        || !empty($GLOBALS['gallery_admin_test_run_early']['adopted'])) {
        return;
    }
    $open = $GLOBALS['gallery_admin_test_run_early']['open_phases'][$name] ?? null;
    if (!is_array($open)) {
        return;
    }
    unset($GLOBALS['gallery_admin_test_run_early']['open_phases'][$name]);
    $afterError = admin_test_run_early_last_error();
    $beforeError = $open['last_error_before'] ?? null;
    $phaseError = null;
    if ($exception !== null) {
        $phaseError = [
            'type' => get_class($exception),
            'message' => substr($exception->getMessage(), 0, 1000),
        ];
    } elseif ($afterError !== $beforeError) {
        $phaseError = $afterError;
    }
    admin_test_run_early_phase_record(
        $name,
        (float) ($open['started_at_unix'] ?? microtime(true)),
        microtime(true),
        (int) ($open['memory_start_bytes'] ?? memory_get_usage(true)),
        (int) ($open['included_files_start'] ?? count(get_included_files())),
        $completed,
        $phaseError
    );
}

/**
 * Add one low-cost lifecycle mark before the full diagnostics service has adopted the trace.
 *
 * @param array<string,mixed> $context Structured non-secret context.
 */
function admin_test_run_early_mark(string $name, array $context = []): void
{
    if (!isset($GLOBALS['gallery_admin_test_run_early']) || !is_array($GLOBALS['gallery_admin_test_run_early'])
        || !empty($GLOBALS['gallery_admin_test_run_early']['adopted'])) {
        return;
    }
    $now = microtime(true);
    $GLOBALS['gallery_admin_test_run_early']['marks'][] = [
        'name' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $name) ?: 'mark',
        'at_unix' => $now,
        'offset_from_request_ms' => max(0.0, ($now - (float) $GLOBALS['gallery_admin_test_run_early']['request_time_unix']) * 1000),
        'memory_usage_bytes' => memory_get_usage(true),
        'included_file_count' => count(get_included_files()),
        'context' => $context,
    ];
}

/**
 * Bind a newly authenticated starter trace to its newly-created Test Run token.
 */
function admin_test_run_early_bind_token(string $token): void
{
    if (!isset($GLOBALS['gallery_admin_test_run_early']) || !is_array($GLOBALS['gallery_admin_test_run_early'])) {
        return;
    }
    if (preg_match('/^[a-f0-9]{32}$/D', strtolower($token)) !== 1) {
        return;
    }
    $GLOBALS['gallery_admin_test_run_early']['token'] = strtolower($token);
    $GLOBALS['gallery_admin_test_run_early']['candidate_reason'] = 'authenticated_starter';
}

/**
 * Return a copy of the current early trace for adoption by the full diagnostics service.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_early_snapshot(): ?array
{
    $state = $GLOBALS['gallery_admin_test_run_early'] ?? null;
    if (!is_array($state)) {
        return null;
    }
    $copy = $state;
    unset($copy['project_root'], $copy['open_phases']);
    return $copy;
}

/**
 * Mark the trace as adopted so the fallback shutdown writer does not duplicate the full request sidecar.
 */
function admin_test_run_early_mark_adopted(): void
{
    if (isset($GLOBALS['gallery_admin_test_run_early']) && is_array($GLOBALS['gallery_admin_test_run_early'])) {
        $GLOBALS['gallery_admin_test_run_early']['adopted'] = true;
    }
}

/**
 * Persist a minimal early-failure sidecar only when a valid Test Run token was already known.
 */
function admin_test_run_early_shutdown_fallback(): void
{
    $state = $GLOBALS['gallery_admin_test_run_early'] ?? null;
    if (!is_array($state) || !empty($state['adopted'])) {
        return;
    }
    $token = strtolower((string) ($state['token'] ?? ''));
    if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
        return;
    }
    foreach ((array) ($state['open_phases'] ?? []) as $name => $open) {
        if (!is_array($open)) {
            continue;
        }
        admin_test_run_early_phase_record(
            (string) $name,
            (float) ($open['started_at_unix'] ?? microtime(true)),
            microtime(true),
            (int) ($open['memory_start_bytes'] ?? memory_get_usage(true)),
            (int) ($open['included_files_start'] ?? count(get_included_files())),
            false,
            admin_test_run_early_last_error()
        );
    }
    $state = $GLOBALS['gallery_admin_test_run_early'];
    $root = (string) ($state['project_root'] ?? '');
    if ($root === '') {
        return;
    }
    $directory = $root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-test-runs'
        . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR . 'requests';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }
    $requestId = (string) ($state['request_id'] ?? 'early-' . bin2hex(random_bytes(4)));
    $early = admin_test_run_early_snapshot();
    if (is_array($early)) {
        unset($early['token']);
    }
    $payload = [
        'request_id' => $requestId,
        'kind' => 'early_bootstrap_failure',
        'request_time_unix' => (float) ($state['request_time_unix'] ?? admin_test_run_early_request_time()),
        'finished_at_unix' => microtime(true),
        'finished' => false,
        'early_bootstrap' => $early,
        'last_error' => admin_test_run_early_last_error(),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($json)) {
        @file_put_contents($directory . DIRECTORY_SEPARATOR . $requestId . '.early.json', $json . "\n", LOCK_EX);
    }
}
