<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_benchmark.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides admin-triggered public gallery benchmark logging.
 *
 * Responsibilities:
 *   - Create durable benchmark log files for one gallery
 *   - Record server-side public render profiler snapshots
 *   - Record browser-side navigation and resource timing summaries
 *   - Provide downloadable structured logs for later analysis
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\current_user;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\request_is_https;

/**
 * Return the directory used for gallery benchmark logs.
 *
 * @return string Text result for the caller.
 */
function gallery_benchmark_directory(): string
{
    // $directory stores the private data path used for benchmark artifacts.
    $directory = dirname(__DIR__, 2) . '/data/gallery-benchmarks';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Benchmark log directory could not be created.');
    }
    return $directory;
}

/**
 * Return whether a benchmark token has the expected opaque identifier format.
 *
 * @param string $token Benchmark token.
 * @return bool True when the condition matches.
 */
function gallery_benchmark_token_is_valid(string $token): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
}

/**
 * Return the absolute JSON log path for a benchmark token.
 *
 * @param string $token Benchmark token.
 * @return string Text result for the caller.
 */
function gallery_benchmark_log_path(string $token): string
{
    if (!gallery_benchmark_token_is_valid($token)) {
        throw new RuntimeException('Invalid benchmark token.');
    }
    return gallery_benchmark_directory() . '/gallery-benchmark-' . $token . '.json';
}

/**
 * Return the current timestamp formatted for machine-readable logs.
 *
 * @return string Text result for the caller.
 */
function gallery_benchmark_iso_timestamp(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Return the diagnostics build identifier shared by PHP and the browser runner.
 *
 * @return string Stable diagnostics build identifier.
 */
function gallery_benchmark_diagnostics_version(): string
{
    return '20260820-benchmark-diagnostics-v4.2';
}


/**
 * Return the short-lived browser cookie used to associate lightbox media with a benchmark run.
 *
 * @return string Stable cookie name.
 */
function gallery_benchmark_media_cookie_name(): string
{
    return 'gallery_benchmark_media_context';
}

/**
 * Resolve benchmark identity from the short-lived media diagnostics cookie.
 *
 * The cookie is written only by the benchmark lightbox module. It does not grant
 * access to media and is ignored unless the opaque token already has a benchmark
 * log on disk. This lets PHP-backed image requests be correlated without changing
 * image URLs or defeating the browser cache with benchmark query parameters.
 *
 * @return array{token:string,run_index:int}|null Benchmark identity or null.
 */
function gallery_benchmark_media_context_from_cookie(): ?array
{
    static $cachedRaw = null;
    static $cachedContext = null;
    static $cacheReady = false;

    $raw = trim((string) ($_COOKIE[gallery_benchmark_media_cookie_name()] ?? ''));
    if ($cacheReady && $cachedRaw === $raw) {
        return $cachedContext;
    }
    $cachedRaw = $raw;
    $cachedContext = null;
    $cacheReady = true;
    if ($raw === '' || preg_match('/^([a-f0-9]{32}):(\d{1,2})$/', $raw, $matches) !== 1) {
        return null;
    }
    $token = strtolower((string) $matches[1]);
    $runIndex = (int) $matches[2];
    if ($runIndex < 1 || $runIndex > 20 || !gallery_benchmark_token_is_valid($token)) {
        return null;
    }
    try {
        if (!is_file(gallery_benchmark_log_path($token))) {
            return null;
        }
    } catch (Throwable) {
        return null;
    }
    $cachedContext = ['token' => $token, 'run_index' => $runIndex];
    return $cachedContext;
}

/**
 * Return the sidecar directory for benchmark-only PHP media request traces.
 *
 * Each concurrent media request writes its own tiny JSON file. Avoiding the main
 * benchmark log lock here is essential because diagnostics must not serialize the
 * very image requests whose worker concurrency is being measured.
 *
 * @param string $token Benchmark token.
 * @param bool $create Whether the directory may be created.
 * @return string Sidecar directory path, or an empty string when unavailable.
 */
function gallery_benchmark_media_sidecar_directory(string $token, bool $create = false): string
{
    if (!gallery_benchmark_token_is_valid($token)) {
        return '';
    }
    $directory = gallery_benchmark_directory() . '/media-' . $token;
    if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return '';
    }
    return $directory;
}

/**
 * Start benchmark-only tracing for one PHP-backed thumbnail or media request.
 *
 * @param string $route Stable media route name.
 * @param array<string, scalar|null> $context Small non-sensitive request context.
 * @return string|null Unique request id, or null outside an active benchmark.
 */
function gallery_benchmark_media_request_begin(string $route, array $context = []): ?string
{
    $benchmark = gallery_benchmark_media_context_from_cookie();
    if ($benchmark === null) {
        return null;
    }
    $directory = gallery_benchmark_media_sidecar_directory($benchmark['token'], true);
    if ($directory === '') {
        return null;
    }
    try {
        $requestId = sprintf('%d-%s', function_exists('getmypid') ? (int) getmypid() : 0, bin2hex(random_bytes(6)));
    } catch (Throwable) {
        $requestId = sprintf('%d-%s', function_exists('getmypid') ? (int) getmypid() : 0, str_replace('.', '', uniqid('', true)));
    }
    $requestTime = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : microtime(true);
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safeContext[(string) $key] = $value;
        }
    }
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $state = [
        'request_id' => $requestId,
        'token' => $benchmark['token'],
        'run_index' => $benchmark['run_index'],
        'route' => substr(trim($route), 0, 80),
        'request_time_unix' => $requestTime,
        'controller_enter_unix' => microtime(true),
        'request_path' => is_string($requestPath) ? substr($requestPath, 0, 900) : '',
        'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 1200),
        'request_method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 12),
        'pid' => function_exists('getmypid') ? getmypid() : null,
        'context' => $safeContext,
        'marks' => [],
        'finished' => false,
    ];
    if (!isset($GLOBALS['gallery_benchmark_media_request_states']) || !is_array($GLOBALS['gallery_benchmark_media_request_states'])) {
        $GLOBALS['gallery_benchmark_media_request_states'] = [];
    }
    $GLOBALS['gallery_benchmark_media_request_states'][$requestId] = $state;
    register_shutdown_function(static function () use ($requestId): void {
        gallery_benchmark_media_request_finish($requestId, ['finish_reason' => 'shutdown']);
    });
    gallery_benchmark_media_request_mark($requestId, 'controller_enter');
    return $requestId;
}

/**
 * Add one milestone to a benchmark-only media request trace.
 *
 * @param string|null $requestId Media request id.
 * @param string $name Stable milestone name.
 * @param array<string, scalar|null> $context Small non-sensitive diagnostic values.
 */
function gallery_benchmark_media_request_mark(?string $requestId, string $name, array $context = []): void
{
    if ($requestId === null || $requestId === '' || !isset($GLOBALS['gallery_benchmark_media_request_states'][$requestId])) {
        return;
    }
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safeContext[(string) $key] = $value;
        }
    }
    foreach ($safeContext as $key => $value) {
        $GLOBALS['gallery_benchmark_media_request_states'][$requestId]['context'][$key] = $value;
    }
    $GLOBALS['gallery_benchmark_media_request_states'][$requestId]['marks'][] = [
        'name' => substr(trim($name), 0, 80),
        'at_unix' => microtime(true),
        'context' => $safeContext,
    ];
}

/**
 * Finish one benchmark-only media request trace and write its independent sidecar.
 *
 * This function is safe to call explicitly after readfile() and again from the
 * shutdown handler. Only the first call writes a sidecar.
 *
 * @param string|null $requestId Media request id.
 * @param array<string, scalar|null> $context Final non-sensitive values.
 */
function gallery_benchmark_media_request_finish(?string $requestId, array $context = []): void
{
    if ($requestId === null || $requestId === '' || !isset($GLOBALS['gallery_benchmark_media_request_states'][$requestId])) {
        return;
    }
    $state = &$GLOBALS['gallery_benchmark_media_request_states'][$requestId];
    if (!empty($state['finished'])) {
        return;
    }
    $state['finished'] = true;
    $safeFinal = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safeFinal[(string) $key] = $value;
        }
    }
    $now = microtime(true);
    $requestTime = (float) ($state['request_time_unix'] ?? $now);
    $marks = isset($state['marks']) && is_array($state['marks']) ? $state['marks'] : [];
    $serializedMarks = [];
    $markTimes = [];
    foreach ($marks as $mark) {
        if (!is_array($mark)) {
            continue;
        }
        $name = (string) ($mark['name'] ?? '');
        $at = isset($mark['at_unix']) && is_numeric($mark['at_unix']) ? (float) $mark['at_unix'] : 0.0;
        if ($name === '' || $at <= 0.0) {
            continue;
        }
        $markTimes[$name] = $at;
        $serializedMarks[] = [
            'name' => $name,
            'offset_ms' => max(0.0, ($at - $requestTime) * 1000),
            'context' => isset($mark['context']) && is_array($mark['context']) ? $mark['context'] : [],
        ];
    }
    $duration = static function (string $start, string $end) use ($markTimes): ?float {
        if (!isset($markTimes[$start], $markTimes[$end])) {
            return null;
        }
        return max(0.0, ($markTimes[$end] - $markTimes[$start]) * 1000);
    };
    $lastError = error_get_last();
    $record = [
        'request_id' => (string) ($state['request_id'] ?? $requestId),
        'run_index' => (int) ($state['run_index'] ?? 0),
        'route' => (string) ($state['route'] ?? ''),
        'request_path' => (string) ($state['request_path'] ?? ''),
        'request_uri' => (string) ($state['request_uri'] ?? ''),
        'request_method' => (string) ($state['request_method'] ?? 'GET'),
        'request_time_unix' => $requestTime,
        'controller_enter_unix' => (float) ($state['controller_enter_unix'] ?? $requestTime),
        'shutdown_unix' => $now,
        'total_php_ms' => max(0.0, ($now - $requestTime) * 1000),
        'pid' => $state['pid'] ?? null,
        'context' => isset($state['context']) && is_array($state['context']) ? $state['context'] : [],
        'final' => $safeFinal,
        'derived_ms' => [
            'request_to_controller' => max(0.0, (((float) ($state['controller_enter_unix'] ?? $requestTime)) - $requestTime) * 1000),
            'authorize' => $duration('controller_enter', 'authorized'),
            'session_release' => $duration('session_release_begin', 'session_release_end'),
            'post_release_to_file_ready' => isset($markTimes['session_release_end'], $markTimes['file_ready'])
                ? max(0.0, ($markTimes['file_ready'] - $markTimes['session_release_end']) * 1000)
                : null,
            'stream' => $duration('stream_begin', 'stream_end'),
        ],
        'connection' => [
            'aborted' => function_exists('connection_aborted') ? connection_aborted() === 1 : null,
            'status' => function_exists('connection_status') ? connection_status() : null,
            'http_status' => http_response_code(),
        ],
        'process' => [
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ],
        'request_trace' => gallery_benchmark_request_trace_snapshot(),
        'last_error_type' => is_array($lastError) ? (int) ($lastError['type'] ?? 0) : null,
        'marks' => $serializedMarks,
    ];
    $directory = gallery_benchmark_media_sidecar_directory((string) ($state['token'] ?? ''), true);
    if ($directory !== '') {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $requestId) . '.json', $json . "\n");
        }
    }
    unset($GLOBALS['gallery_benchmark_media_request_states'][$requestId]);
}

/**
 * Return whether the current request belongs to an active browser benchmark run.
 *
 * This check deliberately does not require an authenticated session because it
 * is also used to measure how long session_start() itself waits. The opaque
 * benchmark token still has to match the expected generated format and the run
 * index must be positive before any trace state is allocated.
 *
 * @return bool True when benchmark request tracing should be collected.
 */
function gallery_benchmark_request_trace_enabled(): bool
{
    $token = strtolower(trim((string) ($_GET['benchmark_token'] ?? '')));
    $runIndex = (int) ($_GET['benchmark_run'] ?? 0);
    if ($runIndex > 0 && gallery_benchmark_token_is_valid($token)) {
        return true;
    }
    return gallery_benchmark_media_context_from_cookie() !== null;
}

/**
 * Record one request-lifecycle milestone for benchmark-only diagnostics.
 *
 * @param string $name Stable milestone name.
 * @param array<string, scalar|null> $context Small diagnostic context values.
 */
function gallery_benchmark_trace_mark(string $name, array $context = []): void
{
    if (!gallery_benchmark_request_trace_enabled()) {
        return;
    }
    $name = trim($name);
    if ($name === '') {
        return;
    }
    if (!isset($GLOBALS['gallery_benchmark_request_trace']) || !is_array($GLOBALS['gallery_benchmark_request_trace'])) {
        $requestTime = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
        $GLOBALS['gallery_benchmark_request_trace'] = [
            'request_time_unix' => $requestTime,
            'marks' => [],
        ];
    }
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (!is_scalar($value) && $value !== null) {
            continue;
        }
        $safeContext[(string) $key] = $value;
    }
    $GLOBALS['gallery_benchmark_request_trace']['marks'][] = [
        'name' => $name,
        'at_unix' => microtime(true),
        'context' => $safeContext,
    ];
}

/**
 * Return one benchmark request trace with useful derived phase durations.
 *
 * @return array<string, mixed> Structured request-lifecycle diagnostics.
 */
function gallery_benchmark_request_trace_snapshot(): array
{
    if (!gallery_benchmark_request_trace_enabled()) {
        return [];
    }
    $trace = isset($GLOBALS['gallery_benchmark_request_trace']) && is_array($GLOBALS['gallery_benchmark_request_trace'])
        ? $GLOBALS['gallery_benchmark_request_trace']
        : [];
    $requestTime = isset($trace['request_time_unix']) && is_numeric($trace['request_time_unix'])
        ? (float) $trace['request_time_unix']
        : (isset($_SERVER['REQUEST_TIME_FLOAT']) && is_numeric($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true));
    $marks = isset($trace['marks']) && is_array($trace['marks']) ? $trace['marks'] : [];
    $markTimes = [];
    $serializedMarks = [];
    foreach ($marks as $mark) {
        if (!is_array($mark)) {
            continue;
        }
        $name = (string) ($mark['name'] ?? '');
        $at = isset($mark['at_unix']) && is_numeric($mark['at_unix']) ? (float) $mark['at_unix'] : 0.0;
        if ($name === '' || $at <= 0.0) {
            continue;
        }
        $markTimes[$name] = $at;
        $serializedMarks[] = [
            'name' => $name,
            'offset_ms' => max(0.0, ($at - $requestTime) * 1000),
            'context' => isset($mark['context']) && is_array($mark['context']) ? $mark['context'] : [],
        ];
    }

    $duration = static function (string $start, string $end) use ($markTimes): ?float {
        if (!isset($markTimes[$start], $markTimes[$end])) {
            return null;
        }
        return max(0.0, ($markTimes[$end] - $markTimes[$start]) * 1000);
    };
    $now = microtime(true);
    $sessionIdPresent = session_status() === PHP_SESSION_ACTIVE && session_id() !== '';

    $browserRequestMs = isset($_GET['benchmark_browser_request_ms']) && is_numeric($_GET['benchmark_browser_request_ms'])
        ? (float) $_GET['benchmark_browser_request_ms']
        : null;

    return [
        'request_time_unix' => $requestTime,
        'snapshot_at_unix' => $now,
        'request_age_ms' => max(0.0, ($now - $requestTime) * 1000),
        'benchmark_phase' => substr(trim((string) ($_GET['benchmark_phase'] ?? '')), 0, 80),
        'browser_correlation_input' => [
            'browser_request_wall_ms' => $browserRequestMs,
            'method' => 'duration_difference_no_clock_sync',
        ],
        'derived_ms' => [
            'request_to_cms_run' => isset($markTimes['cms_run_enter']) ? max(0.0, ($markTimes['cms_run_enter'] - $requestTime) * 1000) : null,
            'config_load' => $duration('config_load_start', 'config_load_end'),
            'session_start' => $duration('session_start_begin', 'session_start_end'),
            'request_initialize' => $duration('request_initialize_start', 'request_initialize_end'),
            'request_maintenance' => $duration('request_maintenance_start', 'request_maintenance_end'),
            'dispatch' => $duration('dispatch_start', 'dispatch_end'),
            'dispatch_until_snapshot' => isset($markTimes['dispatch_start']) ? max(0.0, ($now - $markTimes['dispatch_start']) * 1000) : null,
        ],
        'session' => [
            'status' => session_status(),
            'id_present' => $sessionIdPresent,
            'save_handler' => (string) ini_get('session.save_handler'),
            'use_strict_mode' => (string) ini_get('session.use_strict_mode'),
            'cookie_present' => isset($_COOKIE[session_name()]),
        ],
        'process' => [
            'pid' => function_exists('getmypid') ? getmypid() : null,
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'included_file_count' => count(get_included_files()),
        ],
        'marks' => $serializedMarks,
    ];
}

/**
 * Return lightweight server context useful for interpreting benchmark results.
 *
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_server_context(): array
{
    // $opcacheStatus stores optional OPcache runtime details when the extension exposes them.
    $opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
    // $loadAverage stores host load only when the operating system exposes it.
    $loadAverage = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os_family' => PHP_OS_FAMILY,
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'opcache_enabled' => is_array($opcacheStatus) ? (bool) ($opcacheStatus['opcache_enabled'] ?? false) : null,
        'opcache_validate_timestamps' => (string) ini_get('opcache.validate_timestamps'),
        'opcache_revalidate_freq' => (string) ini_get('opcache.revalidate_freq'),
        'realpath_cache_size' => (string) ini_get('realpath_cache_size'),
        'realpath_cache_ttl' => (string) ini_get('realpath_cache_ttl'),
        'session_save_handler' => (string) ini_get('session.save_handler'),
        'session_use_strict_mode' => (string) ini_get('session.use_strict_mode'),
        'host_load_average' => is_array($loadAverage) ? array_values($loadAverage) : null,
        'https' => function_exists('Gallery\Core\request_is_https') ? request_is_https() : null,
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'http_host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    ];
}

/**
 * Return a compact request context for benchmark log metadata.
 *
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_request_context(): array
{
    return [
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'remote_addr_present' => (string) ($_SERVER['REMOTE_ADDR'] ?? '') !== '',
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ];
}

/**
 * Return a normalized gallery descriptor for benchmark metadata.
 *
 * @param array<string, mixed> $gallery Gallery row or gallery data.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_gallery_context(array $gallery): array
{
    return [
        'id' => (int) ($gallery['id'] ?? 0),
        'title' => (string) ($gallery['title'] ?? ''),
        'slug' => (string) ($gallery['slug'] ?? ''),
        'folder_path' => (string) ($gallery['folder_path'] ?? ''),
        'url_path' => (string) ($gallery['url_path'] ?? ''),
        'visibility' => (string) ($gallery['visibility'] ?? ''),
        'public_url' => function_exists('Gallery\\Core\\gallery_public_url') ? gallery_public_url($gallery) : '',
    ];
}

/**
 * Return a stable run skeleton for one planned benchmark pass.
 *
 * @param int $runIndex One-based run number.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_run_skeleton(int $runIndex): array
{
    return [
        'run_index' => $runIndex,
        'planned_at' => gallery_benchmark_iso_timestamp(),
        'server_render' => null,
        'server_completion' => null,
        'post_lightbox_probe' => null,
        'auxiliary_requests' => [],
        'media_requests' => [],
        'browser_load' => null,
        'events' => [],
    ];
}

/**
 * Create a new benchmark log and return its initial payload.
 *
 * @param array<string, mixed> $gallery Gallery row or gallery data.
 * @param int $runsTotal Number of repeated page loads.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_start(array $gallery, int $runsTotal = 5): array
{
    // $runsTotal stores the bounded number of browser-driven benchmark passes.
    $runsTotal = max(1, min(20, $runsTotal));
    // $token stores an opaque identifier used by the iframe benchmark requests.
    $token = bin2hex(random_bytes(16));
    // $user stores the authenticated admin who started the benchmark.
    $user = current_user();
    // $runs stores preallocated rows so partial logs show missing stages clearly.
    $runs = [];
    for ($index = 1; $index <= $runsTotal; $index++) {
        $runs[] = gallery_benchmark_run_skeleton($index);
    }

    $log = [
        'schema_version' => 4,
        'diagnostics_version' => gallery_benchmark_diagnostics_version(),
        'kind' => 'public_gallery_benchmark',
        'token' => $token,
        'created_at' => gallery_benchmark_iso_timestamp(),
        'updated_at' => gallery_benchmark_iso_timestamp(),
        'runs_total' => $runsTotal,
        'gallery' => gallery_benchmark_gallery_context($gallery),
        'started_by' => [
            'id' => $user ? (int) ($user['id'] ?? 0) : null,
            'username' => $user ? (string) ($user['username'] ?? '') : '',
        ],
        'server_context' => gallery_benchmark_server_context(),
        'initial_request' => gallery_benchmark_request_context(),
        'events' => [
            [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => 'benchmark_started',
                'message' => 'Admin started browser-driven gallery benchmark.',
            ],
        ],
        'runs' => $runs,
        'summary' => [],
    ];
    gallery_benchmark_write_log($token, $log);
    return $log;
}


/**
 * Merge completed media-request sidecars into the durable benchmark log.
 *
 * @param string $token Benchmark token.
 * @return array<string, mixed>|null Updated log, or null when no sidecars exist.
 */
function gallery_benchmark_merge_media_sidecars(string $token): ?array
{
    $directory = gallery_benchmark_media_sidecar_directory($token, false);
    if ($directory === '' || !is_dir($directory)) {
        return null;
    }
    $paths = glob($directory . '/*.json') ?: [];
    if ($paths === []) {
        return null;
    }
    $records = [];
    $acceptedPaths = [];
    foreach (array_slice($paths, 0, 500) as $path) {
        $contents = @file_get_contents($path);
        $record = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($record)) {
            continue;
        }
        $runIndex = (int) ($record['run_index'] ?? 0);
        $requestId = (string) ($record['request_id'] ?? '');
        if ($runIndex < 1 || $runIndex > 20 || $requestId === '') {
            continue;
        }
        $records[] = $record;
        $acceptedPaths[] = $path;
    }
    if ($records === []) {
        return null;
    }
    usort($records, static fn (array $left, array $right): int => ((float) ($left['request_time_unix'] ?? 0.0)) <=> ((float) ($right['request_time_unix'] ?? 0.0)));
    $log = gallery_benchmark_update_log($token, static function (array $log) use ($records): array {
        $expectedGalleryId = (int) ($log['gallery']['id'] ?? 0);
        foreach ($records as $record) {
            $runIndex = (int) ($record['run_index'] ?? 0);
            $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
            $recordGalleryId = (int) ($record['context']['gallery_id'] ?? 0);
            if ($expectedGalleryId > 0 && $recordGalleryId > 0 && $recordGalleryId !== $expectedGalleryId) {
                continue;
            }
            if (!isset($log['runs'][$targetIndex]['media_requests']) || !is_array($log['runs'][$targetIndex]['media_requests'])) {
                $log['runs'][$targetIndex]['media_requests'] = [];
            }
            $requestId = (string) ($record['request_id'] ?? '');
            $alreadyPresent = false;
            foreach ($log['runs'][$targetIndex]['media_requests'] as $existing) {
                if (is_array($existing) && (string) ($existing['request_id'] ?? '') === $requestId) {
                    $alreadyPresent = true;
                    break;
                }
            }
            if (!$alreadyPresent) {
                $log['runs'][$targetIndex]['media_requests'][] = $record;
            }
        }
        foreach ($log['runs'] as &$run) {
            if (is_array($run)) {
                gallery_benchmark_correlate_media_requests($run);
            }
        }
        unset($run);
        return $log;
    });
    foreach ($acceptedPaths as $path) {
        @unlink($path);
    }
    @rmdir($directory);
    return $log;
}

/**
 * Load a benchmark log from disk.
 *
 * @param string $token Benchmark token.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_load_log(string $token): array
{
    gallery_benchmark_merge_media_sidecars($token);
    $path = gallery_benchmark_log_path($token);
    if (!is_file($path)) {
        throw new RuntimeException('Benchmark log was not found.');
    }
    $contents = file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        throw new RuntimeException('Benchmark log is empty or unreadable.');
    }
    $payload = json_decode($contents, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Benchmark log contains invalid JSON.');
    }
    return $payload;
}

/**
 * Persist a benchmark log to disk with stable JSON formatting.
 *
 * @param string $token Benchmark token.
 * @param array<string, mixed> $log Benchmark log payload.
 */
function gallery_benchmark_write_log(string $token, array $log): void
{
    $path = gallery_benchmark_log_path($token);
    $log['updated_at'] = gallery_benchmark_iso_timestamp();
    $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Benchmark log could not be encoded.');
    }
    if (file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Benchmark log could not be written.');
    }
}

/**
 * Mutate a benchmark log under a file lock.
 *
 * @param string $token Benchmark token.
 * @param callable(array<string, mixed>):array<string, mixed> $callback Callback invoked by this workflow.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_update_log(string $token, callable $callback): array
{
    $path = gallery_benchmark_log_path($token);
    if (!is_file($path)) {
        throw new RuntimeException('Benchmark log was not found.');
    }
    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Benchmark log could not be opened.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Benchmark log could not be locked.');
        }
        $contents = stream_get_contents($handle);
        $log = is_string($contents) && trim($contents) !== '' ? json_decode($contents, true) : [];
        if (!is_array($log)) {
            $log = [];
        }
        $log = $callback($log);
        $log['updated_at'] = gallery_benchmark_iso_timestamp();
        $log['summary'] = gallery_benchmark_build_summary($log);
        $json = json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Benchmark log could not be encoded.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $json . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        return $log;
    } finally {
        fclose($handle);
    }
}

/**
 * Return a one-based run row reference, creating missing rows when necessary.
 *
 * @param array<string, mixed> $log Benchmark log payload.
 * @param int $runIndex One-based run number.
 * @return int Zero-based array index.
 */
function gallery_benchmark_ensure_run_index(array &$log, int $runIndex): int
{
    $runIndex = max(1, $runIndex);
    if (!isset($log['runs']) || !is_array($log['runs'])) {
        $log['runs'] = [];
    }
    while (count($log['runs']) < $runIndex) {
        $log['runs'][] = gallery_benchmark_run_skeleton(count($log['runs']) + 1);
    }
    if (!isset($log['runs'][$runIndex - 1]) || !is_array($log['runs'][$runIndex - 1])) {
        $log['runs'][$runIndex - 1] = gallery_benchmark_run_skeleton($runIndex);
    }
    $log['runs'][$runIndex - 1]['run_index'] = $runIndex;
    return $runIndex - 1;
}

/**
 * Record the current public render profiler snapshot in an active benchmark log.
 *
 * @param array<string, mixed> $gallery Gallery row or gallery data.
 * @param array<string, mixed> $snapshot Public render profiler snapshot.
 */
function gallery_benchmark_record_public_render(array $gallery, array $snapshot): void
{
    $token = strtolower(trim((string) ($_GET['benchmark_token'] ?? '')));
    $runIndex = (int) ($_GET['benchmark_run'] ?? 0);
    $phase = substr(trim((string) ($_GET['benchmark_phase'] ?? '')), 0, 80);
    if ($token === '' || $runIndex < 1 || !gallery_benchmark_token_is_valid($token) || !current_user()) {
        return;
    }

    try {
        gallery_benchmark_update_log($token, static function (array $log) use ($gallery, $snapshot, $runIndex, $phase): array {
            $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
            $galleryId = (int) ($gallery['id'] ?? 0);
            $expectedGalleryId = (int) ($log['gallery']['id'] ?? 0);
            if ($expectedGalleryId > 0 && $galleryId !== $expectedGalleryId) {
                $log['runs'][$targetIndex]['events'][] = [
                    'at' => gallery_benchmark_iso_timestamp(),
                    'type' => 'gallery_mismatch',
                    'message' => 'Benchmark render ignored because the gallery id did not match the started benchmark.',
                    'actual_gallery_id' => $galleryId,
                    'expected_gallery_id' => $expectedGalleryId,
                ];
                return $log;
            }
            $renderPayload = [
                'recorded_at' => gallery_benchmark_iso_timestamp(),
                'gallery' => gallery_benchmark_gallery_context($gallery),
                'request' => gallery_benchmark_request_context(),
                'request_trace' => gallery_benchmark_request_trace_snapshot(),
                'snapshot' => $snapshot,
            ];
            if ($phase === 'post_lightbox_probe') {
                if (!isset($log['runs'][$targetIndex]['post_lightbox_probe']) || !is_array($log['runs'][$targetIndex]['post_lightbox_probe'])) {
                    $log['runs'][$targetIndex]['post_lightbox_probe'] = [];
                }
                $log['runs'][$targetIndex]['post_lightbox_probe']['server_render'] = $renderPayload;
            } else {
                $log['runs'][$targetIndex]['server_render'] = $renderPayload;
            }
            $log['runs'][$targetIndex]['events'][] = [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => $phase === 'post_lightbox_probe' ? 'post_lightbox_probe_server_render_recorded' : 'server_render_recorded',
                'message' => $phase === 'post_lightbox_probe'
                    ? 'Post-lightbox gallery probe PHP render profile was recorded.'
                    : 'Gallery PHP render profile was recorded.',
            ];
            $log['events'][] = [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => $phase === 'post_lightbox_probe' ? 'post_lightbox_probe_server_render_recorded' : 'server_render_recorded',
                'run_index' => $runIndex,
            ];
            return $log;
        });
    } catch (Throwable) {
    }
}

/**
 * Append one benchmark-only auxiliary request such as a lazy lightbox metadata fetch.
 *
 * @param array<string, mixed> $gallery Gallery row or gallery data.
 * @param array<string, mixed> $snapshot Public render profiler snapshot.
 * @param string $type Stable auxiliary request type.
 */
function gallery_benchmark_record_auxiliary_render(array $gallery, array $snapshot, string $type): void
{
    $token = strtolower(trim((string) ($_GET['benchmark_token'] ?? '')));
    $runIndex = (int) ($_GET['benchmark_run'] ?? 0);
    $type = substr(trim($type), 0, 80);
    if ($token === '' || $runIndex < 1 || $type === '' || !gallery_benchmark_token_is_valid($token) || !current_user()) {
        return;
    }

    try {
        gallery_benchmark_update_log($token, static function (array $log) use ($gallery, $snapshot, $runIndex, $type): array {
            $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
            $galleryId = (int) ($gallery['id'] ?? 0);
            $expectedGalleryId = (int) ($log['gallery']['id'] ?? 0);
            if ($expectedGalleryId > 0 && $galleryId !== $expectedGalleryId) {
                return $log;
            }
            if (!isset($log['runs'][$targetIndex]['auxiliary_requests']) || !is_array($log['runs'][$targetIndex]['auxiliary_requests'])) {
                $log['runs'][$targetIndex]['auxiliary_requests'] = [];
            }
            $log['runs'][$targetIndex]['auxiliary_requests'][] = [
                'recorded_at' => gallery_benchmark_iso_timestamp(),
                'type' => $type,
                'request' => gallery_benchmark_request_context(),
                'request_trace' => gallery_benchmark_request_trace_snapshot(),
                'snapshot' => $snapshot,
            ];
            $log['runs'][$targetIndex]['events'][] = [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => 'auxiliary_request_recorded',
                'request_type' => $type,
                'message' => 'Benchmark auxiliary request profile was recorded.',
            ];
            return $log;
        });
    } catch (Throwable) {
    }
}

/**
 * Record the final request trace after a benchmark gallery controller returns.
 *
 * @param string $page Resolved route name.
 */
function gallery_benchmark_record_request_completion(string $page): void
{
    if ($page !== 'gallery') {
        return;
    }
    $token = strtolower(trim((string) ($_GET['benchmark_token'] ?? '')));
    $runIndex = (int) ($_GET['benchmark_run'] ?? 0);
    $phase = substr(trim((string) ($_GET['benchmark_phase'] ?? '')), 0, 80);
    if ($token === '' || $runIndex < 1 || !gallery_benchmark_token_is_valid($token) || !current_user()) {
        return;
    }
    try {
        gallery_benchmark_update_log($token, static function (array $log) use ($runIndex, $phase): array {
            $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
            $payload = [
                'recorded_at' => gallery_benchmark_iso_timestamp(),
                'request_trace' => gallery_benchmark_request_trace_snapshot(),
                'memory_usage_bytes' => memory_get_usage(true),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ];
            if ($phase === 'post_lightbox_probe') {
                if (!isset($log['runs'][$targetIndex]['post_lightbox_probe']) || !is_array($log['runs'][$targetIndex]['post_lightbox_probe'])) {
                    $log['runs'][$targetIndex]['post_lightbox_probe'] = [];
                }
                $log['runs'][$targetIndex]['post_lightbox_probe']['server_completion'] = $payload;
            } else {
                $log['runs'][$targetIndex]['server_completion'] = $payload;
            }
            return $log;
        });
    } catch (Throwable) {
    }
}

/**
 * Correlate PHP-backed media sidecars with browser Resource Timing rows.
 *
 * @param array<string, mixed> $run One benchmark run, modified in place.
 */
function gallery_benchmark_correlate_media_requests(array &$run): void
{
    $mediaRequests = isset($run['media_requests']) && is_array($run['media_requests']) ? $run['media_requests'] : [];
    $browserPayload = $run['browser_load']['payload'] ?? null;
    if ($mediaRequests === [] || !is_array($browserPayload)) {
        return;
    }
    $resources = $browserPayload['lightbox_scenario']['resource_delta']['detailed'] ?? [];
    if (!is_array($resources) || $resources === []) {
        return;
    }
    $candidates = [];
    foreach ($resources as $index => $resource) {
        if (!is_array($resource)) {
            continue;
        }
        $name = (string) ($resource['name'] ?? '');
        $path = parse_url($name, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            continue;
        }
        $candidates[$index] = ['row' => $resource, 'path' => $path, 'used' => false];
    }
    foreach ($mediaRequests as $mediaIndex => $mediaRequest) {
        if (!is_array($mediaRequest)) {
            continue;
        }
        $requestPath = (string) ($mediaRequest['request_path'] ?? '');
        if ($requestPath === '') {
            continue;
        }
        $bestIndex = null;
        foreach ($candidates as $candidateIndex => $candidate) {
            if (empty($candidate['used']) && $candidate['path'] === $requestPath) {
                $bestIndex = $candidateIndex;
                break;
            }
        }
        if ($bestIndex === null) {
            continue;
        }
        $candidates[$bestIndex]['used'] = true;
        $row = $candidates[$bestIndex]['row'];
        $browserTtfbMs = isset($row['ttfb_ms']) && is_numeric($row['ttfb_ms']) ? (float) $row['ttfb_ms'] : null;
        $streamBeginOffsetMs = null;
        foreach (($mediaRequest['marks'] ?? []) as $mark) {
            if (is_array($mark) && ($mark['name'] ?? '') === 'stream_begin' && isset($mark['offset_ms']) && is_numeric($mark['offset_ms'])) {
                $streamBeginOffsetMs = (float) $mark['offset_ms'];
                break;
            }
        }
        $run['media_requests'][$mediaIndex]['browser_correlation'] = [
            'resource_name' => substr((string) ($row['name'] ?? ''), 0, 900),
            'cache_kind' => (string) ($row['cache_kind'] ?? ''),
            'browser_duration_ms' => isset($row['duration']) && is_numeric($row['duration']) ? (float) $row['duration'] : null,
            'browser_ttfb_ms' => $browserTtfbMs,
            'browser_queue_or_stall_ms' => isset($row['queue_or_stall_ms']) && is_numeric($row['queue_or_stall_ms']) ? (float) $row['queue_or_stall_ms'] : null,
            'php_request_to_stream_begin_ms' => $streamBeginOffsetMs,
            'outside_php_before_stream_estimate_ms' => ($browserTtfbMs !== null && $streamBeginOffsetMs !== null)
                ? max(0.0, $browserTtfbMs - $streamBeginOffsetMs)
                : null,
            'correlation_method' => 'request_path_order_and_duration_difference',
            'transfer_size' => isset($row['transferSize']) && is_numeric($row['transferSize']) ? (int) $row['transferSize'] : null,
        ];
    }
}

/**
 * Record browser navigation timing for one benchmark iframe load.
 *
 * @param string $token Benchmark token.
 * @param int $runIndex One-based run number.
 * @param array<string, mixed> $browserPayload Browser timing data.
 * @return array<string, mixed> Updated benchmark log.
 */
function gallery_benchmark_record_browser_load(string $token, int $runIndex, array $browserPayload): array
{
    gallery_benchmark_merge_media_sidecars($token);
    return gallery_benchmark_update_log($token, static function (array $log) use ($runIndex, $browserPayload): array {
        $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
        $log['runs'][$targetIndex]['browser_load'] = [
            'recorded_at' => gallery_benchmark_iso_timestamp(),
            'request' => gallery_benchmark_request_context(),
            'payload' => $browserPayload,
        ];
        gallery_benchmark_correlate_media_requests($log['runs'][$targetIndex]);
        $log['runs'][$targetIndex]['events'][] = [
            'at' => gallery_benchmark_iso_timestamp(),
            'type' => 'browser_load_recorded',
            'message' => 'Browser navigation and resource timing was recorded.',
        ];
        $log['events'][] = [
            'at' => gallery_benchmark_iso_timestamp(),
            'type' => 'browser_load_recorded',
            'run_index' => $runIndex,
        ];
        return $log;
    });
}

/**
 * Build a benchmark summary from all completed run stages.
 *
 * @param array<string, mixed> $log Benchmark log payload.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_build_summary(array $log): array
{
    $runs = isset($log['runs']) && is_array($log['runs']) ? $log['runs'] : [];
    $serverRuns = 0;
    $browserRuns = 0;
    $serverTotalMs = [];
    $browserElapsedMs = [];
    $dbQueries = [];
    $filesystemChecks = [];
    $thumbnailFallbackSearches = [];
    $thumbnailFallbackChecks = [];
    $thumbnailBundleMisses = [];
    $browserTtfbMs = [];
    $phpRequestToCmsRunMs = [];
    $phpSessionStartMs = [];
    $postLightboxProbeElapsedMs = [];
    $postLightboxProbeServerMs = [];
    $browserOutsidePhpBeforeResponseMs = [];
    $postProbeOutsidePhpBeforeResponseMs = [];
    $auxiliaryRequestCounts = [];
    $mediaRequestCounts = [];
    $mediaRequestPhpMs = [];
    $mediaRequestStreamMs = [];
    $mediaRequestOutsidePhpBeforeStreamMs = [];
    $mediaRequestAbortedCounts = [];
    $mediaRequestIncompleteCounts = [];
    $mediaPeakConcurrentPhp = [];
    $mediaDistinctPids = [];
    $beforeLightboxStaticProbeMs = [];
    $beforeLightboxPhpProbeMs = [];
    $beforeLightboxPhpOutsideMs = [];
    $afterLightboxStaticProbeMs = [];
    $afterLightboxPhpProbeMs = [];
    $afterLightboxPhpOutsideMs = [];
    foreach ($runs as $run) {
        if (!is_array($run)) {
            continue;
        }
        $server = $run['server_render']['snapshot'] ?? null;
        if (is_array($server)) {
            $serverRuns++;
            $serverTotalMs[] = (float) ($server['total_ms'] ?? 0.0);
            $counters = isset($server['counters']) && is_array($server['counters']) ? $server['counters'] : [];
            $dbQueries[] = (int) ($counters['db_queries'] ?? 0);
            $filesystemChecks[] = (int) ($counters['filesystem_checks'] ?? 0);
            $thumbnailFallbackSearches[] = (int) ($counters['thumbnail_fallback_searches'] ?? 0);
            $thumbnailFallbackChecks[] = (int) ($counters['thumbnail_fallback_checks'] ?? 0);
            $thumbnailBundleMisses[] = (int) ($counters['thumbnail_bundle_cache_misses'] ?? 0);
            $trace = $run['server_render']['request_trace']['derived_ms'] ?? null;
            if (is_array($trace)) {
                if (isset($trace['request_to_cms_run']) && is_numeric($trace['request_to_cms_run'])) {
                    $phpRequestToCmsRunMs[] = (float) $trace['request_to_cms_run'];
                }
                if (isset($trace['session_start']) && is_numeric($trace['session_start'])) {
                    $phpSessionStartMs[] = (float) $trace['session_start'];
                }
            }
        }
        $browser = $run['browser_load']['payload'] ?? null;
        if (is_array($browser)) {
            $browserRuns++;
            $browserElapsedMs[] = (float) ($browser['iframe_elapsed_ms'] ?? 0.0);
            $timingBreakdown = isset($browser['timing_breakdown']) && is_array($browser['timing_breakdown']) ? $browser['timing_breakdown'] : [];
            if (isset($timingBreakdown['ttfb_ms']) && is_numeric($timingBreakdown['ttfb_ms'])) {
                $browserTtfbMs[] = (float) $timingBreakdown['ttfb_ms'];
            }
            $requestAgeMs = $run['server_completion']['request_trace']['request_age_ms'] ?? null;
            if (isset($timingBreakdown['ttfb_ms']) && is_numeric($timingBreakdown['ttfb_ms']) && is_numeric($requestAgeMs)) {
                $browserOutsidePhpBeforeResponseMs[] = max(0.0, (float) $timingBreakdown['ttfb_ms'] - (float) $requestAgeMs);
            }
            $scenario = isset($browser['lightbox_scenario']) && is_array($browser['lightbox_scenario']) ? $browser['lightbox_scenario'] : [];
            $layerProbes = isset($scenario['layer_probes']) && is_array($scenario['layer_probes']) ? $scenario['layer_probes'] : [];
            $beforeLayer = isset($layerProbes['before_lightbox']) && is_array($layerProbes['before_lightbox']) ? $layerProbes['before_lightbox'] : [];
            $afterLayer = isset($layerProbes['after_lightbox']) && is_array($layerProbes['after_lightbox']) ? $layerProbes['after_lightbox'] : [];
            $beforeStaticMs = $beforeLayer['static']['browser_round_trip_ms'] ?? null;
            $beforePhpMs = $beforeLayer['php']['browser_round_trip_ms'] ?? null;
            $beforePhpOutsideMs = $beforeLayer['php']['outside_php_estimate_ms'] ?? null;
            $afterStaticMs = $afterLayer['static']['browser_round_trip_ms'] ?? null;
            $afterPhpMs = $afterLayer['php']['browser_round_trip_ms'] ?? null;
            $afterPhpOutsideMs = $afterLayer['php']['outside_php_estimate_ms'] ?? null;
            if (is_numeric($beforeStaticMs)) {
                $beforeLightboxStaticProbeMs[] = (float) $beforeStaticMs;
            }
            if (is_numeric($beforePhpMs)) {
                $beforeLightboxPhpProbeMs[] = (float) $beforePhpMs;
            }
            if (is_numeric($beforePhpOutsideMs)) {
                $beforeLightboxPhpOutsideMs[] = (float) $beforePhpOutsideMs;
            }
            if (is_numeric($afterStaticMs)) {
                $afterLightboxStaticProbeMs[] = (float) $afterStaticMs;
            }
            if (is_numeric($afterPhpMs)) {
                $afterLightboxPhpProbeMs[] = (float) $afterPhpMs;
            }
            if (is_numeric($afterPhpOutsideMs)) {
                $afterLightboxPhpOutsideMs[] = (float) $afterPhpOutsideMs;
            }
            $probe = isset($scenario['post_close_probe']) && is_array($scenario['post_close_probe']) ? $scenario['post_close_probe'] : [];
            if (isset($probe['elapsed_ms']) && is_numeric($probe['elapsed_ms'])) {
                $postLightboxProbeElapsedMs[] = (float) $probe['elapsed_ms'];
            }
        }
        $postProbeServer = $run['post_lightbox_probe']['server_render']['snapshot'] ?? null;
        if (is_array($postProbeServer) && isset($postProbeServer['total_ms']) && is_numeric($postProbeServer['total_ms'])) {
            $postLightboxProbeServerMs[] = (float) $postProbeServer['total_ms'];
        }
        $postProbeHeadersMs = $run['browser_load']['payload']['lightbox_scenario']['post_close_probe']['headers_received_ms'] ?? null;
        $postProbeRequestAgeMs = $run['post_lightbox_probe']['server_completion']['request_trace']['request_age_ms'] ?? null;
        if (is_numeric($postProbeHeadersMs) && is_numeric($postProbeRequestAgeMs)) {
            $postProbeOutsidePhpBeforeResponseMs[] = max(0.0, (float) $postProbeHeadersMs - (float) $postProbeRequestAgeMs);
        }
        $auxiliaryRequestCounts[] = isset($run['auxiliary_requests']) && is_array($run['auxiliary_requests']) ? count($run['auxiliary_requests']) : 0;

        $mediaRequests = isset($run['media_requests']) && is_array($run['media_requests']) ? $run['media_requests'] : [];
        $mediaRequestCounts[] = count($mediaRequests);
        $aborted = 0;
        $incomplete = 0;
        $pids = [];
        $intervals = [];
        foreach ($mediaRequests as $mediaRequest) {
            if (!is_array($mediaRequest)) {
                continue;
            }
            if (isset($mediaRequest['total_php_ms']) && is_numeric($mediaRequest['total_php_ms'])) {
                $mediaRequestPhpMs[] = (float) $mediaRequest['total_php_ms'];
            }
            $streamMs = $mediaRequest['derived_ms']['stream'] ?? null;
            if (is_numeric($streamMs)) {
                $mediaRequestStreamMs[] = (float) $streamMs;
            } else {
                $incomplete++;
            }
            $mediaOutsidePhpMs = $mediaRequest['browser_correlation']['outside_php_before_stream_estimate_ms'] ?? null;
            if (is_numeric($mediaOutsidePhpMs)) {
                $mediaRequestOutsidePhpBeforeStreamMs[] = (float) $mediaOutsidePhpMs;
            }
            if (!empty($mediaRequest['connection']['aborted'])) {
                $aborted++;
            }
            $pid = $mediaRequest['pid'] ?? null;
            if (is_int($pid) || (is_numeric($pid) && (int) $pid > 0)) {
                $pids[(int) $pid] = true;
            }
            $start = isset($mediaRequest['request_time_unix']) && is_numeric($mediaRequest['request_time_unix']) ? (float) $mediaRequest['request_time_unix'] : 0.0;
            $end = isset($mediaRequest['shutdown_unix']) && is_numeric($mediaRequest['shutdown_unix']) ? (float) $mediaRequest['shutdown_unix'] : 0.0;
            if ($start > 0.0 && $end >= $start) {
                $intervals[] = ['at' => $start, 'delta' => 1];
                $intervals[] = ['at' => $end, 'delta' => -1];
            }
        }
        usort($intervals, static fn (array $left, array $right): int => $left['at'] === $right['at'] ? ($right['delta'] <=> $left['delta']) : ($left['at'] <=> $right['at']));
        $active = 0;
        $peak = 0;
        foreach ($intervals as $interval) {
            $active += (int) $interval['delta'];
            $peak = max($peak, $active);
        }
        $mediaRequestAbortedCounts[] = $aborted;
        $mediaRequestIncompleteCounts[] = $incomplete;
        $mediaPeakConcurrentPhp[] = $peak;
        $mediaDistinctPids[] = count($pids);
    }

    return [
        'runs_total' => (int) ($log['runs_total'] ?? count($runs)),
        'server_runs_recorded' => $serverRuns,
        'browser_runs_recorded' => $browserRuns,
        'server_total_ms' => gallery_benchmark_stats($serverTotalMs),
        'browser_iframe_elapsed_ms' => gallery_benchmark_stats($browserElapsedMs),
        'browser_ttfb_ms' => gallery_benchmark_stats($browserTtfbMs),
        'php_request_to_cms_run_ms' => gallery_benchmark_stats($phpRequestToCmsRunMs),
        'php_session_start_ms' => gallery_benchmark_stats($phpSessionStartMs),
        'browser_outside_php_before_response_estimate_ms' => gallery_benchmark_stats($browserOutsidePhpBeforeResponseMs),
        'post_lightbox_probe_elapsed_ms' => gallery_benchmark_stats($postLightboxProbeElapsedMs),
        'post_lightbox_probe_server_ms' => gallery_benchmark_stats($postLightboxProbeServerMs),
        'post_lightbox_probe_outside_php_before_response_estimate_ms' => gallery_benchmark_stats($postProbeOutsidePhpBeforeResponseMs),
        'auxiliary_request_count' => gallery_benchmark_int_stats($auxiliaryRequestCounts),
        'media_request_count' => gallery_benchmark_int_stats($mediaRequestCounts),
        'media_request_php_ms' => gallery_benchmark_stats($mediaRequestPhpMs),
        'media_request_stream_ms' => gallery_benchmark_stats($mediaRequestStreamMs),
        'media_request_outside_php_before_stream_estimate_ms' => gallery_benchmark_stats($mediaRequestOutsidePhpBeforeStreamMs),
        'media_request_aborted_count' => gallery_benchmark_int_stats($mediaRequestAbortedCounts),
        'media_request_incomplete_count' => gallery_benchmark_int_stats($mediaRequestIncompleteCounts),
        'media_peak_concurrent_php_requests' => gallery_benchmark_int_stats($mediaPeakConcurrentPhp),
        'media_distinct_php_pids' => gallery_benchmark_int_stats($mediaDistinctPids),
        'before_lightbox_static_probe_ms' => gallery_benchmark_stats($beforeLightboxStaticProbeMs),
        'before_lightbox_php_probe_ms' => gallery_benchmark_stats($beforeLightboxPhpProbeMs),
        'before_lightbox_php_outside_ms' => gallery_benchmark_stats($beforeLightboxPhpOutsideMs),
        'after_lightbox_static_probe_ms' => gallery_benchmark_stats($afterLightboxStaticProbeMs),
        'after_lightbox_php_probe_ms' => gallery_benchmark_stats($afterLightboxPhpProbeMs),
        'after_lightbox_php_outside_ms' => gallery_benchmark_stats($afterLightboxPhpOutsideMs),
        'db_queries' => gallery_benchmark_int_stats($dbQueries),
        'filesystem_checks' => gallery_benchmark_int_stats($filesystemChecks),
        'thumbnail_fallback_searches' => gallery_benchmark_int_stats($thumbnailFallbackSearches),
        'thumbnail_fallback_checks' => gallery_benchmark_int_stats($thumbnailFallbackChecks),
        'thumbnail_bundle_cache_misses' => gallery_benchmark_int_stats($thumbnailBundleMisses),
    ];
}

/**
 * Return min, max, average, and latest values for floating point samples.
 *
 * @param array<int, float> $values Numeric values.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_stats(array $values): array
{
    if ($values === []) {
        return ['count' => 0, 'min' => null, 'max' => null, 'avg' => null, 'latest' => null];
    }
    return [
        'count' => count($values),
        'min' => min($values),
        'max' => max($values),
        'avg' => array_sum($values) / count($values),
        'latest' => $values[array_key_last($values)],
    ];
}

/**
 * Return min, max, average, and latest values for integer samples.
 *
 * @param array<int, int> $values Numeric values.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_int_stats(array $values): array
{
    $stats = gallery_benchmark_stats(array_map(static fn (int $value): float => (float) $value, $values));
    foreach (['min', 'max', 'latest'] as $key) {
        if ($stats[$key] !== null) {
            $stats[$key] = (int) $stats[$key];
        }
    }
    return $stats;
}

/**
 * Return a safe download file name for a benchmark log.
 *
 * @param array<string, mixed> $log Benchmark log payload.
 * @return string Text result for the caller.
 */
function gallery_benchmark_download_filename(array $log): string
{
    $galleryId = (int) ($log['gallery']['id'] ?? 0);
    $created = preg_replace('/[^0-9]/', '', (string) ($log['created_at'] ?? gallery_benchmark_iso_timestamp())) ?: gmdate('YmdHis');
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($log['token'] ?? 'benchmark')) ?: 'benchmark';
    return 'php-gallery-benchmark-gallery-' . $galleryId . '-' . substr($created, 0, 14) . '-' . substr($token, 0, 8) . '.json';
}
