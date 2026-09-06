<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/lifecycle.php
 * Module Type: Service
 *
 * Purpose:
 *   Drives run creation, per-request begin/finish, and finalization.
 *
 * Responsibilities:
 *   - Create a run and register the starting request
 *   - Open and close each recorded request including detached responses
 *   - Finalize a run into its stored report and browser payload
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
 *   - Loaded by app/services/admin_test_runs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_runs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Create a new detailed test-run context after an authenticated Admin request.
 *
 * @return array<string,mixed>
 */
function admin_test_run_create(string $target, string $targetPage): array
{
    $user = current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
        throw new RuntimeException('Admin authentication is required.');
    }
    $createStarted = microtime(true);
    $phases = [];
    $phase = static function (string $name, callable $callback) use (&$phases) {
        $started = microtime(true);
        $memory = memory_get_usage(true);
        try {
            $value = $callback();
            $phases[$name] = [
                'completed' => true,
                'duration_ms' => (microtime(true) - $started) * 1000,
                'memory_delta_bytes' => memory_get_usage(true) - $memory,
            ];
            return $value;
        } catch (Throwable $exception) {
            $phases[$name] = [
                'completed' => false,
                'duration_ms' => (microtime(true) - $started) * 1000,
                'memory_delta_bytes' => memory_get_usage(true) - $memory,
                'error' => admin_test_run_text_prefix($exception->getMessage(), 1000),
            ];
            throw $exception;
        }
    };

    $token = $phase('diagnostic_context_creation', static function (): string {
        $created = bin2hex(random_bytes(16));
        admin_test_run_directory($created, true);
        admin_test_run_requests_directory($created, true);
        return $created;
    });
    $cacheBefore = $phase('cache_state_preflight_before_clear', static fn (): array => function_exists(__NAMESPACE__ . '\\admin_test_run_cache_preflight') ? admin_test_run_cache_preflight() : []);
    $clearResult = $phase('safe_cache_invalidation', static fn (): array => admin_test_run_clear_safe_caches());
    $cacheAfter = $phase('cache_state_preflight_after_clear', static fn (): array => function_exists(__NAMESPACE__ . '\\admin_test_run_cache_preflight') ? admin_test_run_cache_preflight() : []);
    $createdAtUnix = microtime(true);
    $meta = [
        'schema_version' => ADMIN_TEST_RUN_SCHEMA_VERSION,
        'diagnostics_version' => ADMIN_TEST_RUN_DIAGNOSTICS_VERSION,
        'kind' => 'admin_full_test_run',
        'token' => $token,
        'created_at' => gmdate('c'),
        'created_at_unix' => $createdAtUnix,
        'expires_at_unix' => time() + ADMIN_TEST_RUN_TTL_SECONDS,
        'admin' => [
            'id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
        ],
        'target' => [
            'request_target' => admin_test_run_normalize_target($target),
            'page' => preg_replace('/[^a-z0-9_]+/i', '', $targetPage) ?: 'gallery',
        ],
        'runner_policy' => [
            'diagnostic_probe_concurrency' => 1,
            'optional_concurrency_probe_enabled' => false,
            'browser_probe_sequence' => ['static_probe', 'php_probe', 'warm_full_render', 'optional_lightbox_metadata', 'optional_first_thumbnail'],
            'intentional_sleep_seconds' => 0,
            'max_context_lifetime_seconds' => ADMIN_TEST_RUN_TTL_SECONDS,
        ],
        'starter_preparation' => [
            'started_at_unix' => $createStarted,
            'completed_at_unix' => microtime(true),
            'duration_ms' => (microtime(true) - $createStarted) * 1000,
            'phases' => $phases,
        ],
        'initial_runtime' => admin_test_run_runtime_preflight('test_run_created_preflight'),
        'cache_inventory_before_clear' => $cacheBefore,
        'cache_clear' => $clearResult,
        'cache_inventory_after_clear' => $cacheAfter,
        'detailed_cache_inventory_deferred_until_after_primary_request' => true,
        'subsystems_before' => ['deferred' => true, 'reason' => 'Avoid pre-measurement maintenance/updater/database inventory observer effect.'],
        'events' => [[
            'at' => gmdate('c'),
            'type' => 'test_run_created',
            'message' => 'Admin created a bounded full test run; only light cache preflight and safe invalidation ran before the measured reload.',
        ]],
        'starter_request_id' => '',
        'finalized_at' => null,
    ];
    $phase('report_state_creation', static function () use ($token, &$meta): void {
        admin_test_run_write_json(admin_test_run_meta_path($token), $meta);
    });
    $meta['starter_preparation']['phases'] = $phases;
    $meta['starter_preparation']['completed_at_unix'] = microtime(true);
    $meta['starter_preparation']['duration_ms'] = (microtime(true) - $createStarted) * 1000;
    admin_test_run_write_json(admin_test_run_meta_path($token), $meta);
    return $meta;
}

/**
 * Start detailed instrumentation for the current PHP request when the context cookie is active.
 */
function admin_test_run_request_begin(): void
{
    admin_test_run_request_begin_for_token('', 'request');
}

/**
 * Start detailed instrumentation using an active cookie context or an authenticated starter token.
 */
function admin_test_run_request_begin_for_token(string $token = '', string $kind = 'request'): string
{
    if (isset($GLOBALS['admin_test_run_request']) && is_array($GLOBALS['admin_test_run_request'])) {
        return (string) ($GLOBALS['admin_test_run_request']['request_id'] ?? '');
    }
    $context = null;
    if ($token !== '') {
        if (!admin_test_run_token_valid($token)) {
            return '';
        }
        $context = admin_test_run_read_json(admin_test_run_meta_path($token));
    } else {
        if (!admin_test_run_active()) {
            return '';
        }
        $context = admin_test_run_active_context();
        $token = is_array($context) ? (string) ($context['token'] ?? admin_test_run_cookie_token()) : '';
    }
    if (!is_array($context) || !admin_test_run_token_valid($token) || !empty($context['finalized_at'])) {
        return '';
    }
    $early = function_exists('Gallery\\Diagnostics\\admin_test_run_early_snapshot')
        ? \Gallery\Diagnostics\admin_test_run_early_snapshot()
        : null;
    $requestId = is_array($early) && !empty($early['request_id'])
        ? (string) $early['request_id']
        : sprintf('%s-%s-%s', (string) (function_exists('getmypid') ? getmypid() : 0), str_replace('.', '', sprintf('%.6f', microtime(true))), bin2hex(random_bytes(4)));
    $requestTime = is_array($early) && is_numeric($early['request_time_unix'] ?? null)
        ? (float) $early['request_time_unix']
        : (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));
    if (is_array($early)) {
        unset($early['token']);
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_sanitize_url')) {
        $uri = admin_test_run_sanitize_url($uri);
    }
    $state = [
        'request_id' => $requestId,
        'token' => $token,
        'kind' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $kind) ?: 'request',
        'started_at' => gmdate('c'),
        'request_time_unix' => $requestTime,
        'instrumentation_enter_unix' => microtime(true),
        'early_bootstrap' => $early,
        'request' => [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'uri' => $uri,
            'script_name' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
            'protocol' => (string) ($_SERVER['SERVER_PROTOCOL'] ?? ''),
            'https' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
            'query_keys' => array_values(array_map('strval', array_keys($_GET))),
            'cookie_names' => array_values(array_map('strval', array_keys($_COOKIE))),
        ],
        'process' => admin_test_run_runtime_snapshot('request_begin'),
        'marks' => [],
        'maintenance_events' => [],
        'response_lifecycle' => [
            'logical_response_finished_at_unix' => null,
            'logical_response_finish_reason' => '',
            'fastcgi_finish_request_called' => false,
            'litespeed_finish_request_called' => false,
            'detach_events' => [],
            'response_to_shutdown_ms' => null,
        ],
        'db' => [
            'connection_attempts' => [],
            'prepare_events' => [],
            'transaction_events' => [],
            'queries' => [],
            'query_count_total' => 0,
            'query_count_recorded' => 0,
            'query_events_truncated' => false,
            'query_total_ms' => 0.0,
            'query_max_ms' => 0.0,
            'failed_count' => 0,
        ],
        'components' => [],
        'finished' => false,
    ];
    $GLOBALS['admin_test_run_request'] = $state;
    $activePath = admin_test_run_requests_directory($token, true) . DIRECTORY_SEPARATOR . $requestId . '.active.json';
    admin_test_run_write_json($activePath, [
        'request_id' => $requestId,
        'kind' => $state['kind'],
        'started_at' => $state['started_at'],
        'request_time_unix' => $requestTime,
        'pid' => $state['process']['pid'] ?? null,
        'uri' => $state['request']['uri'],
    ]);
    if (!headers_sent()) {
        header('X-Gallery-Test-Request-ID: ' . $requestId);
    }
    if (function_exists('Gallery\\Diagnostics\\admin_test_run_early_mark_adopted')) {
        \Gallery\Diagnostics\admin_test_run_early_mark_adopted();
    }
    register_shutdown_function(static function (): void {
        if (empty($GLOBALS['admin_test_run_final_shutdown_registered'])) {
            admin_test_run_request_finish('shutdown_fallback');
        } else {
            admin_test_run_mark('shutdown_sequence_entered');
        }
    });
    admin_test_run_mark('instrumentation_begin');
    return $requestId;
}

/**
 * Return the current participating diagnostic request ID.
 */
function admin_test_run_current_request_id(): string
{
    return isset($GLOBALS['admin_test_run_request']) && is_array($GLOBALS['admin_test_run_request'])
        ? (string) ($GLOBALS['admin_test_run_request']['request_id'] ?? '')
        : '';
}

/**
 * Register the final shutdown observer after maintenance callbacks so worker-tail work is included.
 */
function admin_test_run_register_final_shutdown_observer(): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request']) || !empty($GLOBALS['admin_test_run_final_shutdown_registered'])) {
        return;
    }
    $GLOBALS['admin_test_run_final_shutdown_registered'] = true;
    register_shutdown_function(static function (): void {
        admin_test_run_request_finish('final_shutdown_observer');
    });
}

/**
 * Mark the time at which application code considers the response complete, without detaching the worker.
 */
function admin_test_run_response_logical_finish(string $reason = 'response_complete'): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    $state = &$GLOBALS['admin_test_run_request'];
    if (!empty($state['response_lifecycle']['logical_response_finished_at_unix'])) {
        return;
    }
    $now = microtime(true);
    $state['response_lifecycle']['logical_response_finished_at_unix'] = $now;
    $state['response_lifecycle']['logical_response_finish_reason'] = $reason;
    $state['response_lifecycle']['logical_response_finish_offset_ms'] = max(0.0, ($now - (float) $state['request_time_unix']) * 1000);
    admin_test_run_mark('response_logically_finished', ['reason' => $reason]);
    if (!headers_sent()) {
        $duration = max(0.0, ($now - (float) $state['request_time_unix']) * 1000);
        header('Server-Timing: gallery-php;dur=' . number_format($duration, 3, '.', ''), false);
    }
}

/**
 * Record an actual response-detach API call used by an existing maintenance subsystem.
 */
function admin_test_run_note_response_detach(string $mechanism, string $stage = 'called'): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    $mechanism = strtolower($mechanism);
    $now = microtime(true);
    $GLOBALS['admin_test_run_request']['response_lifecycle']['detach_events'][] = [
        'mechanism' => $mechanism,
        'stage' => $stage,
        'at_unix' => $now,
    ];
    if ($stage === 'called') {
        $GLOBALS['admin_test_run_request']['response_lifecycle']['detach_called_at_unix'] = $now;
        $GLOBALS['admin_test_run_request']['response_lifecycle']['detach_mechanism'] = $mechanism;
        if ($mechanism === 'fastcgi_finish_request') {
            $GLOBALS['admin_test_run_request']['response_lifecycle']['fastcgi_finish_request_called'] = true;
        }
        if ($mechanism === 'litespeed_finish_request') {
            $GLOBALS['admin_test_run_request']['response_lifecycle']['litespeed_finish_request_called'] = true;
        }
        admin_test_run_response_logical_finish($mechanism . '_called');
    }
    admin_test_run_mark('response_detach.' . $mechanism . '.' . $stage);
}

/**
 * Return response headers while redacting credential-bearing values.
 *
 * @return array<int,string> Safe response header lines.
 */
function admin_test_run_response_headers(): array
{
    $headers = [];
    foreach (headers_list() as $header) {
        $line = (string) $header;
        $name = strtolower(trim((string) strtok($line, ':')));
        if ($name === '' || preg_match('/cookie|authorization|token|csrf|api[-_]?key|secret|password|session/i', $name)) {
            $headers[] = ($name !== '' ? $name : 'header') . ': [REDACTED]';
            continue;
        }
        $line = function_exists(__NAMESPACE__ . '\\admin_test_run_sanitize_text') ? admin_test_run_sanitize_text($line) : $line;
        $headers[] = admin_test_run_text_prefix($line, 4000);
    }
    return $headers;
}

/**
 * Finish and persist the active PHP request sidecar exactly once.
 */
function admin_test_run_request_finish(string $reason = 'normal'): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    $state = &$GLOBALS['admin_test_run_request'];
    if (!empty($state['finished'])) {
        return;
    }
    if (empty($state['response_lifecycle']['logical_response_finished_at_unix'])) {
        admin_test_run_response_logical_finish('shutdown_response_completion_estimate');
    }
    $finishedAt = microtime(true);
    admin_test_run_mark('request_finish', ['reason' => $reason]);
    $state['finished'] = true;
    $lastError = error_get_last();
    $diagnosticError = $GLOBALS['admin_test_run_diagnostic_last_error'] ?? null;
    if (is_array($diagnosticError) && $lastError === $diagnosticError) {
        $lastError = $GLOBALS['admin_test_run_preserved_last_error'] ?? null;
    }
    $state['finished_at'] = gmdate('c');
    $state['finished_at_unix'] = $finishedAt;
    $state['finish_reason'] = $reason;
    $state['duration_from_request_ms'] = max(0.0, ($finishedAt - (float) $state['request_time_unix']) * 1000);
    $state['duration_instrumented_ms'] = max(0.0, ($finishedAt - (float) $state['instrumentation_enter_unix']) * 1000);
    $logicalFinish = $state['response_lifecycle']['logical_response_finished_at_unix'] ?? null;
    $state['response_lifecycle']['shutdown_at_unix'] = $finishedAt;
    $state['response_lifecycle']['response_to_shutdown_ms'] = is_numeric($logicalFinish)
        ? max(0.0, ($finishedAt - (float) $logicalFinish) * 1000)
        : null;
    $state['http_status'] = http_response_code();
    $state['response'] = [
        'headers' => admin_test_run_response_headers(),
        'header_count' => count(headers_list()),
        'output_buffer_level' => ob_get_level(),
        'current_output_buffer_bytes' => ($bufferLength = ob_get_length()) === false ? null : $bufferLength,
    ];
    $state['connection'] = [
        'aborted' => connection_aborted() !== 0,
        'status' => connection_status(),
    ];
    $state['last_error'] = is_array($lastError) ? [
        'type' => (int) ($lastError['type'] ?? 0),
        'message' => admin_test_run_text_prefix((string) ($lastError['message'] ?? ''), 1000),
        'file' => basename((string) ($lastError['file'] ?? '')),
        'line' => (int) ($lastError['line'] ?? 0),
    ] : null;
    $state['process_end'] = admin_test_run_runtime_snapshot('request_end');
    $token = (string) $state['token'];
    $requestId = (string) $state['request_id'];
    try {
        $directory = admin_test_run_requests_directory($token, true);
        $persist = $state;
        unset($persist['token']);
        if (function_exists(__NAMESPACE__ . '\\admin_test_run_sanitize_browser_value')) {
            $sanitized = admin_test_run_sanitize_browser_value($persist);
            $persist = is_array($sanitized) ? $sanitized : $persist;
        }
        admin_test_run_write_json($directory . DIRECTORY_SEPARATOR . $requestId . '.json', $persist);
        @unlink($directory . DIRECTORY_SEPARATOR . $requestId . '.active.json');
    } catch (Throwable) {
        // Diagnostics must never break the user-facing request during shutdown.
    }
}

/**
 * Persist browser-collected timings/probes before final report assembly.
 *
 * @param array<string,mixed> $payload Browser payload.
 */
function admin_test_run_store_browser_payload(string $token, array $payload): void
{
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_sanitize_browser_value')) {
        $sanitized = admin_test_run_sanitize_browser_value($payload);
        $payload = is_array($sanitized) ? $sanitized : [];
    }
    admin_test_run_write_json(admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'browser.json', $payload);
}

/**
 * Build and persist the final detailed report for one run.
 *
 * @return array<string,mixed>
 */
function admin_test_run_finalize(string $token): array
{
    $meta = admin_test_run_read_json(admin_test_run_meta_path($token));
    if (!$meta) {
        throw new RuntimeException('Admin test-run metadata was not found.');
    }
    $records = admin_test_run_request_records($token);
    $requests = $records['completed'];
    $active = $records['active'];
    $browser = admin_test_run_read_json(admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'browser.json');
    $endedAt = microtime(true);
    $cacheAfterRun = admin_test_run_cache_inventory();
    $sqlHotspots = function_exists(__NAMESPACE__ . '\\admin_test_run_sql_hotspot_analysis') ? admin_test_run_sql_hotspot_analysis($requests) : [];
    $cron = function_exists(__NAMESPACE__ . '\\admin_test_run_cron_and_maintenance_snapshot') ? admin_test_run_cron_and_maintenance_snapshot($requests) : [];
    $postResponse = function_exists(__NAMESPACE__ . '\\admin_test_run_post_response_summary') ? admin_test_run_post_response_summary($requests) : [];
    $sessionContention = function_exists(__NAMESPACE__ . '\\admin_test_run_session_lock_contention_summary') ? admin_test_run_session_lock_contention_summary($requests) : [];
    $browserCache = function_exists(__NAMESPACE__ . '\\admin_test_run_browser_cache_summary') ? admin_test_run_browser_cache_summary($browser) : [];
    $correlation = function_exists(__NAMESPACE__ . '\\admin_test_run_browser_php_correlation') ? admin_test_run_browser_php_correlation($browser, $requests) : [];
    $report = [
        'schema_version' => ADMIN_TEST_RUN_SCHEMA_VERSION,
        'diagnostics_version' => ADMIN_TEST_RUN_DIAGNOSTICS_VERSION,
        'kind' => 'admin_full_test_run',
        'run_id' => admin_test_run_public_run_id($token),
        'token_hash' => substr(hash('sha256', $token), 0, 16),
        'created_at' => $meta['created_at'] ?? null,
        'finalized_at' => gmdate('c'),
        'duration_seconds' => max(0.0, $endedAt - (float) ($meta['created_at_unix'] ?? $endedAt)),
        'target' => $meta['target'] ?? [],
        'admin' => $meta['admin'] ?? [],
        'runner_policy' => $meta['runner_policy'] ?? [],
        'starter' => [
            'request_id' => (string) ($meta['starter_request_id'] ?? ''),
            'preparation' => $meta['starter_preparation'] ?? [],
        ],
        'cache' => [
            'before_clear_preflight' => $meta['cache_inventory_before_clear'] ?? [],
            'clear_result' => $meta['cache_clear'] ?? [],
            'after_clear_preflight' => $meta['cache_inventory_after_clear'] ?? [],
            'after_run' => $cacheAfterRun,
            'observer_effect_note' => 'Detailed recursive inventory is single-pass, bounded, and deferred until after the primary measured gallery request.',
        ],
        'runtime_initial' => $meta['initial_runtime'] ?? [],
        'runtime_finalizer' => admin_test_run_runtime_snapshot('finalizer'),
        'subsystems_before' => $meta['subsystems_before'] ?? [],
        'subsystems_after' => [
            'captured_by' => 'cron_and_maintenance',
            'observation_only' => true,
            'tasks' => $cron['tasks'] ?? [],
            'note' => 'Legacy subsystem reporting is derived from the dedicated read-only cron/maintenance inventory so finalization does not invoke updater pointer cleanup or maintenance status routines with write side effects.',
        ],
        'cron_and_maintenance' => $cron,
        'request_concurrency' => admin_test_run_concurrency_summary($requests),
        'session_lock_contention' => $sessionContention,
        'database_summary' => admin_test_run_db_summary($requests),
        'sql_hotspots' => $sqlHotspots,
        'post_response_worker_tail' => $postResponse,
        'browser_cache_summary' => $browserCache,
        'browser_php_correlation' => $correlation,
        'request_lifecycle' => [
            'completed_count' => count($requests),
            'active_unfinished_count' => count($active),
            'all_completed_cleanly' => count($active) === 0 && count(array_filter($requests, static fn (array $request): bool => empty($request['finished']))) === 0,
            'unfinished_sidecars' => $active,
        ],
        'browser' => $browser,
        'requests' => $requests,
        'analysis_flags' => [],
        'storage_cleanup' => ['performed_after_initial_final_artifacts' => true],
    ];
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_redact_exact_token')) {
        $redacted = admin_test_run_redact_exact_token($report, $token);
        $report = is_array($redacted) ? $redacted : $report;
    }
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_redact_storage_run_ids')) {
        $redacted = admin_test_run_redact_storage_run_ids($report);
        $report = is_array($redacted) ? $redacted : $report;
    }
    $report['analysis_flags'] = function_exists(__NAMESPACE__ . '\\admin_test_run_analysis_flags') ? admin_test_run_analysis_flags($report) : [];

    // Publish final artifacts before deleting sidecars so failed finalization retains forensic material.
    admin_test_run_write_json(admin_test_run_report_path($token), $report);
    admin_test_run_build_zip($token, $report);
    $cleanup = admin_test_run_cleanup_intermediates($token);
    $retention = admin_test_run_cleanup_old_reports();
    $report['storage_cleanup'] = [
        'intermediate_sidecars' => $cleanup,
        'retention' => $retention,
    ];
    admin_test_run_write_json(admin_test_run_report_path($token), $report);
    admin_test_run_build_zip($token, $report);

    $meta['finalized_at'] = $report['finalized_at'];
    $meta['events'][] = [
        'at' => $report['finalized_at'],
        'type' => 'test_run_finalized',
        'message' => 'Detailed v1.1.3 JSON report was finalized and intermediate sidecars were cleaned after artifact publication.',
    ];
    admin_test_run_write_json(admin_test_run_meta_path($token), $meta);
    return $report;
}
