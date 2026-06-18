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
 * Return lightweight server context useful for interpreting benchmark results.
 *
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_server_context(): array
{
    // $opcacheStatus stores optional OPcache runtime details when the extension exposes them.
    $opcacheStatus = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os_family' => PHP_OS_FAMILY,
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'opcache_enabled' => is_array($opcacheStatus) ? (bool) ($opcacheStatus['opcache_enabled'] ?? false) : null,
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
        'schema_version' => 1,
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
 * Load a benchmark log from disk.
 *
 * @param string $token Benchmark token.
 * @return array<string, mixed> Structured result data for the caller.
 */
function gallery_benchmark_load_log(string $token): array
{
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
    if ($token === '' || $runIndex < 1 || !gallery_benchmark_token_is_valid($token) || !current_user()) {
        return;
    }

    try {
        gallery_benchmark_update_log($token, static function (array $log) use ($gallery, $snapshot, $runIndex): array {
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
            $log['runs'][$targetIndex]['server_render'] = [
                'recorded_at' => gallery_benchmark_iso_timestamp(),
                'gallery' => gallery_benchmark_gallery_context($gallery),
                'request' => gallery_benchmark_request_context(),
                'snapshot' => $snapshot,
            ];
            $log['runs'][$targetIndex]['events'][] = [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => 'server_render_recorded',
                'message' => 'Gallery PHP render profile was recorded.',
            ];
            $log['events'][] = [
                'at' => gallery_benchmark_iso_timestamp(),
                'type' => 'server_render_recorded',
                'run_index' => $runIndex,
            ];
            return $log;
        });
    } catch (Throwable) {
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
    return gallery_benchmark_update_log($token, static function (array $log) use ($runIndex, $browserPayload): array {
        $targetIndex = gallery_benchmark_ensure_run_index($log, $runIndex);
        $log['runs'][$targetIndex]['browser_load'] = [
            'recorded_at' => gallery_benchmark_iso_timestamp(),
            'request' => gallery_benchmark_request_context(),
            'payload' => $browserPayload,
        ];
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
        }
        $browser = $run['browser_load']['payload'] ?? null;
        if (is_array($browser)) {
            $browserRuns++;
            $browserElapsedMs[] = (float) ($browser['iframe_elapsed_ms'] ?? 0.0);
        }
    }

    return [
        'runs_total' => (int) ($log['runs_total'] ?? count($runs)),
        'server_runs_recorded' => $serverRuns,
        'browser_runs_recorded' => $browserRuns,
        'server_total_ms' => gallery_benchmark_stats($serverTotalMs),
        'browser_iframe_elapsed_ms' => gallery_benchmark_stats($browserElapsedMs),
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
