<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides opt-in, administrator-triggered full request test runs for public and Smart Galleries.
 *
 * Responsibilities:
 *   - Create short-lived test-run contexts without exposing diagnostics to anonymous visitors
 *   - Record request/bootstrap/session/maintenance/dispatch/database/process lifecycle details
 *   - Track all PHP requests caused by one browser test through sidecar files and measure concurrency
 *   - Inventory safe application caches, updater/maintenance states, locks, PHP runtime resources, and worker caps
 *   - Persist a detailed JSON report and optional ZIP download artifact under the application cache directory
 *   - Keep the diagnostic runner bounded so the test itself does not become a load generator
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
 *   - Test runs never sleep to simulate throttling and never intentionally create parallel PHP probes.
 *
 * Last Updated:
 *   2026-08-21
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

const ADMIN_TEST_RUN_COOKIE = 'gallery_admin_test_run';
const ADMIN_TEST_RUN_TTL_SECONDS = 600;
const ADMIN_TEST_RUN_SCHEMA_VERSION = 2;
const ADMIN_TEST_RUN_DIAGNOSTICS_VERSION = '20260821-admin-test-run-v1.1.3';
const ADMIN_TEST_RUN_MAX_DB_EVENTS_PER_REQUEST = 2500;
const ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP = 25000;
const ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS = 250.0;
const ADMIN_TEST_RUN_MAX_REPORTS = 20;
const ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES = 209715200;
const ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP = 2000;
const ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS = 80.0;

/**
 * Return the storage root for detailed Admin test runs.
 */
function admin_test_run_root(): string
{
    $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-test-runs';
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create Admin test-run cache directory.');
    }
    return $root;
}

/**
 * Validate one opaque test-run token.
 */
function admin_test_run_token_valid(string $token): bool
{
    return preg_match('/^[a-f0-9]{32}$/D', strtolower($token)) === 1;
}

/**
 * Return a non-secret short identifier for display/report filenames.
 */
function admin_test_run_public_run_id(string $token): string
{
    return substr(hash('sha256', 'admin-test-run-id:' . $token), 0, 8);
}

/**
 * Return the directory for one test-run token.
 */
function admin_test_run_directory(string $token, bool $create = false): string
{
    $token = strtolower(trim($token));
    if (!admin_test_run_token_valid($token)) {
        throw new RuntimeException('Invalid Admin test-run token.');
    }
    $directory = admin_test_run_root() . DIRECTORY_SEPARATOR . $token;
    if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create Admin test-run directory.');
    }
    return $directory;
}

/**
 * Return one test-run metadata path.
 */
function admin_test_run_meta_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'meta.json';
}

/**
 * Return one final JSON report path.
 */
function admin_test_run_report_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'report.json';
}

/**
 * Return one optional ZIP artifact path.
 */
function admin_test_run_zip_path(string $token): string
{
    return admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'report.zip';
}

/**
 * Return the request-sidecar directory for one run.
 */
function admin_test_run_requests_directory(string $token, bool $create = false): string
{
    $directory = admin_test_run_directory($token, $create) . DIRECTORY_SEPARATOR . 'requests';
    if ($create && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create Admin test-run request directory.');
    }
    return $directory;
}

/**
 * Atomically write one JSON diagnostics file.
 *
 * @param array<string,mixed> $payload Structured JSON-safe payload.
 */
function admin_test_run_write_json(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode Admin test-run JSON.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('Could not write Admin test-run JSON.');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish Admin test-run JSON.');
    }
}

/**
 * Read one JSON file as an associative array.
 *
 * @return array<string,mixed>
 */
function admin_test_run_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return the current opaque test-run token from the short-lived HttpOnly cookie.
 */
function admin_test_run_cookie_token(): string
{
    $token = strtolower(trim((string) ($_COOKIE[ADMIN_TEST_RUN_COOKIE] ?? '')));
    return admin_test_run_token_valid($token) ? $token : '';
}

/**
 * Return metadata for the active cookie context when it is still valid.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_active_context(): ?array
{
    static $cachedToken = null;
    static $cachedContext = null;
    $token = admin_test_run_cookie_token();
    if ($token === '') {
        return null;
    }
    if ($cachedToken === $token) {
        return is_array($cachedContext) ? $cachedContext : null;
    }
    $cachedToken = $token;
    $meta = admin_test_run_read_json(admin_test_run_meta_path($token));
    $createdAt = (int) ($meta['created_at_unix'] ?? 0);
    $finalized = !empty($meta['finalized_at']);
    if ($createdAt <= 0 || time() - $createdAt > ADMIN_TEST_RUN_TTL_SECONDS || $finalized) {
        $cachedContext = null;
        return null;
    }
    $cachedContext = $meta;
    return $meta;
}

/**
 * Return whether detailed request instrumentation is active for this request.
 */
function admin_test_run_active(): bool
{
    return admin_test_run_active_context() !== null;
}

/**
 * Normalize a local request target and remove previous test-run control parameters.
 */
function admin_test_run_normalize_target(string $target): string
{
    $target = trim($target);
    if ($target === '' || str_contains($target, "\r") || str_contains($target, "\n")) {
        return '/';
    }
    $parts = parse_url($target);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return '/';
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/') {
        $path = '/';
    }
    parse_str((string) ($parts['query'] ?? ''), $query);
    foreach (['test_run_token', 'test_run_cache_bust', 'test_run_phase', 'test_run_starter_request_id'] as $key) {
        unset($query[$key]);
    }
    $result = $path;
    if ($query) {
        $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    if (!empty($parts['fragment'])) {
        $result .= '#' . rawurlencode((string) $parts['fragment']);
    }
    return $result;
}

/**
 * Append query parameters to one normalized local target.
 *
 * @param array<string,string|int> $params Query parameters.
 */
function admin_test_run_target_with_params(string $target, array $params): string
{
    $target = admin_test_run_normalize_target($target);
    $fragment = '';
    $fragmentPos = strpos($target, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($target, $fragmentPos);
        $target = substr($target, 0, $fragmentPos);
    }
    $separator = str_contains($target, '?') ? '&' : '?';
    return $target . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $fragment;
}

/**
 * Return a compact type count for currently open PHP resources.
 *
 * @return array<string,int>
 */
function admin_test_run_resource_counts(): array
{
    $counts = [];
    if (!function_exists('get_resources')) {
        return $counts;
    }
    foreach (get_resources() as $resource) {
        $type = get_resource_type($resource);
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

/**
 * Return a privacy-safe process/runtime snapshot for one request stage.
 *
 * @return array<string,mixed>
 */
function admin_test_run_runtime_snapshot(string $stage): array
{
    $realpathEntries = function_exists('realpath_cache_get') ? realpath_cache_get() : [];
    $realpathSummary = [];
    foreach ($realpathEntries as $path => $entry) {
        $realpathSummary[] = [
            'path_hash' => substr(hash('sha256', (string) $path), 0, 16),
            'is_dir' => !empty($entry['is_dir']),
            'expires' => (int) ($entry['expires'] ?? 0),
        ];
        if (count($realpathSummary) >= 500) {
            break;
        }
    }
    $beforeOpcacheError = error_get_last();
    $opcache = function_exists(__NAMESPACE__ . '\\admin_test_run_opcache_capability')
        ? admin_test_run_opcache_capability()
        : [
            'extension_loaded' => extension_loaded('Zend OPcache') || function_exists('opcache_get_status'),
            'enabled' => filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'status_access' => function_exists('opcache_get_status') ? 'unavailable' : 'unavailable',
            'status' => null,
        ];
    $afterOpcacheError = error_get_last();
    if ($afterOpcacheError !== $beforeOpcacheError
        && is_array($afterOpcacheError)
        && str_contains(strtolower((string) ($afterOpcacheError['message'] ?? '')), 'restrict_api')) {
        $GLOBALS['admin_test_run_diagnostic_last_error'] = $afterOpcacheError;
        $GLOBALS['admin_test_run_preserved_last_error'] = $beforeOpcacheError;
    }
    return [
        'stage' => $stage,
        'at_unix' => microtime(true),
        'pid' => function_exists('getmypid') ? getmypid() : null,
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os_family' => PHP_OS_FAMILY,
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'memory_usage_bytes' => memory_get_usage(true),
        'memory_usage_real_bytes' => memory_get_usage(false),
        'memory_peak_bytes' => memory_get_peak_usage(true),
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'max_input_time' => (string) ini_get('max_input_time'),
        'max_input_vars' => (string) ini_get('max_input_vars'),
        'max_file_uploads' => (string) ini_get('max_file_uploads'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'default_socket_timeout' => (string) ini_get('default_socket_timeout'),
        'output_buffering' => (string) ini_get('output_buffering'),
        'zlib_output_compression' => (string) ini_get('zlib.output_compression'),
        'display_errors' => (string) ini_get('display_errors'),
        'log_errors' => (string) ini_get('log_errors'),
        'loaded_extensions' => get_loaded_extensions(),
        'pdo_drivers' => class_exists('PDO') ? \PDO::getAvailableDrivers() : [],
        'host_load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : null,
        'response_detach_capabilities' => [
            'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
            'litespeed_finish_request' => function_exists('litespeed_finish_request'),
        ],
        'session_status' => session_status(),
        'session_id_present' => session_id() !== '',
        'session_save_handler' => (string) ini_get('session.save_handler'),
        'output_buffer_level' => ob_get_level(),
        'headers_sent' => headers_sent(),
        'included_file_count' => count(get_included_files()),
        'open_resources' => admin_test_run_resource_counts(),
        'gc_status' => function_exists('gc_status') ? gc_status() : null,
        'rusage' => function_exists('getrusage') ? getrusage() : null,
        'realpath_cache' => [
            'configured_size' => (string) ini_get('realpath_cache_size'),
            'ttl' => (string) ini_get('realpath_cache_ttl'),
            'used_bytes' => function_exists('realpath_cache_size') ? realpath_cache_size() : null,
            'entry_count' => is_array($realpathEntries) ? count($realpathEntries) : 0,
            'sample' => $realpathSummary,
        ],
        'opcache' => $opcache,
    ];
}

/**
 * Return a bounded recursive size/count inventory for one cache directory.
 *
 * @return array<string,mixed>
 */
function admin_test_run_directory_inventory(string $path): array
{
    $startedAt = microtime(true);
    $result = [
        'exists' => is_dir($path),
        'path' => str_replace('\\', '/', $path),
        'files' => 0,
        'directories' => 0,
        'bytes' => 0,
        'newest_mtime' => 0,
        'oldest_mtime' => 0,
        'truncated' => false,
        'entry_cap' => ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP,
        'scan_elapsed_ms' => 0.0,
    ];
    if (!is_dir($path)) {
        $result['scan_elapsed_ms'] = (microtime(true) - $startedAt) * 1000;
        return $result;
    }
    $stack = [$path];
    $seen = 0;
    while ($stack) {
        $directory = array_pop($stack);
        $items = @scandir($directory);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $seen++;
            if ($seen > ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP) {
                $result['truncated'] = true;
                break 2;
            }
            $itemPath = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $result['directories']++;
                $stack[] = $itemPath;
                continue;
            }
            if (!is_file($itemPath)) {
                continue;
            }
            $result['files']++;
            $size = @filesize($itemPath);
            if (is_int($size) || is_float($size)) {
                $result['bytes'] += max(0, (int) $size);
            }
            $mtime = @filemtime($itemPath);
            if (is_int($mtime) && $mtime > 0) {
                $result['newest_mtime'] = max((int) $result['newest_mtime'], $mtime);
                if ((int) $result['oldest_mtime'] === 0 || $mtime < (int) $result['oldest_mtime']) {
                    $result['oldest_mtime'] = $mtime;
                }
            }
        }
    }
    $result['scan_elapsed_ms'] = (microtime(true) - $startedAt) * 1000;
    return $result;
}

/**
 * Inventory application-owned cache families without traversing user gallery media.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_inventory(): array
{
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_cache_inventory_single_pass')) {
        return admin_test_run_cache_inventory_single_pass();
    }
    return [
        'mode' => 'unavailable',
        'traversal_count' => 0,
        'truncated' => true,
        'truncation_reason' => 'analysis_service_not_loaded',
        'scan_elapsed_ms' => 0.0,
        'families' => [],
    ];
}

/**
 * Check whether a lock file is currently held without waiting for it.
 *
 * @return array<string,mixed>
 */
function admin_test_run_lock_snapshot(string $path): array
{
    $exists = is_file($path);
    $snapshot = [
        'path' => str_replace('\\', '/', $path),
        'exists' => $exists,
        'mtime' => $exists ? (@filemtime($path) ?: null) : null,
        'size' => $exists ? (@filesize($path) ?: 0) : 0,
        'busy' => null,
        'probe_error' => '',
        'owner_hash' => '',
        'acquired_at' => null,
        'expires_at' => null,
    ];
    if (!$exists) {
        return $snapshot;
    }
    $handle = @fopen($path, 'r');
    if (!is_resource($handle)) {
        $snapshot['probe_error'] = 'open_failed';
        return $snapshot;
    }
    if (@flock($handle, LOCK_EX | LOCK_NB)) {
        $snapshot['busy'] = false;
        @flock($handle, LOCK_UN);
    } else {
        $snapshot['busy'] = true;
    }
    @rewind($handle);
    $raw = @fread($handle, 4096);
    @fclose($handle);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $owner = (string) ($decoded['owner'] ?? $decoded['token'] ?? '');
            if ($owner !== '') {
                $snapshot['owner_hash'] = substr(hash('sha256', $owner), 0, 12);
            }
            $snapshot['acquired_at'] = $decoded['acquired_at'] ?? $decoded['started_at'] ?? null;
            $snapshot['expires_at'] = $decoded['expires_at'] ?? null;
        }
    }
    return $snapshot;
}

/**
 * Return known worker caps, budgets, durable jobs, and locks that can affect PHP worker usage.
 *
 * @return array<string,mixed>
 */
function admin_test_run_subsystem_snapshot(): array
{
    $snapshot = [
        'at_unix' => microtime(true),
        'application_version' => defined('Gallery\Core\CMS_VERSION') ? constant('Gallery\Core\CMS_VERSION') : '',
        'browser_upload' => null,
        'updates' => null,
        'site_maintenance' => null,
        'admin_log_archives' => null,
        'features' => [],
        'capabilities' => [
            'zip_archive' => class_exists(ZipArchive::class),
            'opcache_status' => function_exists('opcache_get_status'),
            'realpath_cache_status' => function_exists('realpath_cache_get'),
            'resource_enumeration' => function_exists('get_resources'),
            'system_load_average' => function_exists('sys_getloadavg'),
            'server_process_limit_directly_observable_from_php' => false,
            'note' => 'PHP can measure requests that actually overlap during the run, but shared-host FPM/Apache worker-pool hard limits are not generally exposed to application code.',
        ],
        'locks' => [],
        'warnings' => [],
    ];
    try {
        if (function_exists(__NAMESPACE__ . '\\feature_flag_definitions')) {
            foreach (feature_flag_definitions() as $key => $definition) {
                $snapshot['features'][(string) $key] = [
                    'enabled' => feature_flag_enabled((string) $key),
                    'default_enabled' => feature_flag_default_enabled((string) $key),
                    'group' => (string) ($definition['group'] ?? ''),
                ];
            }
        }
    } catch (Throwable $exception) {
        $snapshot['features'] = ['error' => $exception->getMessage()];
    }
    try {
        if (function_exists(__NAMESPACE__ . '\\browser_upload_settings')) {
            $settings = browser_upload_settings();
            $snapshot['browser_upload'] = [
                'enabled' => !empty($settings['enabled']),
                'default_worker_count' => (int) ($settings['default_worker_count'] ?? 0),
                'max_worker_count' => (int) ($settings['max_worker_count'] ?? 0),
                'hard_worker_cap' => (int) ($settings['hard_worker_cap'] ?? 0),
                'max_items_per_batch' => (int) ($settings['max_items_per_batch'] ?? 0),
                'max_zip_batch_bytes' => (int) ($settings['max_zip_batch_bytes'] ?? 0),
            ];
            if ((int) ($settings['max_worker_count'] ?? 0) > 16) {
                $snapshot['warnings'][] = 'Browser upload maximum worker count exceeds 16; this is client-side preparation parallelism, but the value is intentionally highlighted for review.';
            }
        }
    } catch (Throwable $exception) {
        $snapshot['browser_upload'] = ['error' => $exception->getMessage()];
    }
    try {
        $activeJob = function_exists(__NAMESPACE__ . '\\application_update_active_job') ? application_update_active_job() : null;
        $lastJob = function_exists(__NAMESPACE__ . '\\application_update_last_job') ? application_update_last_job() : null;
        $snapshot['updates'] = [
            'autoupdate_enabled' => function_exists(__NAMESPACE__ . '\\application_autoupdate_enabled') ? application_autoupdate_enabled() : null,
            'autoupdate_status' => function_exists(__NAMESPACE__ . '\\application_autoupdate_status') ? application_autoupdate_status() : null,
            'active_job' => is_array($activeJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($activeJob) : $activeJob,
            'last_job' => is_array($lastJob) && function_exists(__NAMESPACE__ . '\\application_update_job_public_state') ? application_update_job_public_state($lastJob) : $lastJob,
            'request_time_background_continue_budget_seconds' => 3.0,
            'admin_continue_or_retry_budget_seconds' => 7.0,
            'failed_background_retry_backoff_seconds' => 60,
        ];
        if (function_exists(__NAMESPACE__ . '\\application_update_jobs_root')) {
            $jobsRoot = application_update_jobs_root();
            $snapshot['locks']['update_start'] = admin_test_run_lock_snapshot($jobsRoot . DIRECTORY_SEPARATOR . 'start.lock');
            if (is_array($activeJob) && !empty($activeJob['id']) && function_exists(__NAMESPACE__ . '\\application_update_job_dir')) {
                $snapshot['locks']['update_worker'] = admin_test_run_lock_snapshot(application_update_job_dir((string) $activeJob['id']) . DIRECTORY_SEPARATOR . 'worker.lock');
            }
        }
    } catch (Throwable $exception) {
        $snapshot['updates'] = ['error' => $exception->getMessage()];
    }
    try {
        $snapshot['site_maintenance'] = function_exists(__NAMESPACE__ . '\\site_maintenance_status') ? site_maintenance_status() : null;
        if (function_exists(__NAMESPACE__ . '\\site_maintenance_lock_path')) {
            $snapshot['locks']['site_maintenance'] = admin_test_run_lock_snapshot(site_maintenance_lock_path());
        }
    } catch (Throwable $exception) {
        $snapshot['site_maintenance'] = ['error' => $exception->getMessage()];
    }
    try {
        $snapshot['admin_log_archives'] = function_exists(__NAMESPACE__ . '\\admin_log_archive_status') ? admin_log_archive_status() : null;
        $archiveLock = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-log-archive-maintenance.lock';
        $snapshot['locks']['admin_log_archive'] = admin_test_run_lock_snapshot($archiveLock);
    } catch (Throwable $exception) {
        $snapshot['admin_log_archives'] = ['error' => $exception->getMessage()];
    }
    foreach ($snapshot['locks'] as $name => $lock) {
        if (is_array($lock) && ($lock['busy'] ?? null) === true) {
            $snapshot['warnings'][] = 'Lock busy during snapshot: ' . $name;
        }
    }
    return $snapshot;
}

/**
 * Clear safe request/summary caches before a diagnostic reload without deleting generated media or updater state.
 *
 * @return array<string,mixed>
 */
function admin_test_run_clear_safe_caches(): array
{
    $started = microtime(true);
    $actions = [];
    $callbacks = [
        'thumbnail_maintenance_summary' => __NAMESPACE__ . '\\thumbnail_maintenance_summary_cache_clear_diagnostic',
        'admin_storage_statistics' => __NAMESPACE__ . '\\admin_storage_statistics_cache_clear',
        'gallery_map_runtime' => __NAMESPACE__ . '\\gallery_map_cache_clear_all',
        'smart_gallery_graph_request' => __NAMESPACE__ . '\\smart_gallery_graph_cache_clear',
        'translation_runtime' => __NAMESPACE__ . '\\translation_clear_runtime_cache',
        'content_localization_request' => __NAMESPACE__ . '\\content_localization_reset_request_cache',
        'schema_inspection_request' => __NAMESPACE__ . '\\schema_inspection_reset_request_cache',
        'app_settings_request' => __NAMESPACE__ . '\\app_settings_reset_request_cache',
    ];
    foreach ($callbacks as $name => $callback) {
        if (!function_exists($callback)) {
            $actions[$name] = ['available' => false, 'ok' => null, 'elapsed_ms' => 0.0];
            continue;
        }
        $actionStarted = microtime(true);
        try {
            $callbackResult = $callback();
            $actions[$name] = ['available' => true, 'ok' => true, 'elapsed_ms' => (microtime(true) - $actionStarted) * 1000];
            if (is_array($callbackResult) && $callbackResult !== []) {
                $actions[$name]['details'] = $callbackResult;
            }
        } catch (Throwable $exception) {
            $actions[$name] = ['available' => true, 'ok' => false, 'elapsed_ms' => (microtime(true) - $actionStarted) * 1000, 'error' => $exception->getMessage()];
        }
    }
    $statStarted = microtime(true);
    clearstatcache(true);
    $actions['php_stat_cache'] = ['available' => true, 'ok' => true, 'elapsed_ms' => (microtime(true) - $statStarted) * 1000];
    return [
        'scope' => 'safe application/request metadata caches only; generated thumbnails, download ZIPs, update payloads, and OPcache are intentionally preserved',
        'actions' => $actions,
        'total_ms' => (microtime(true) - $started) * 1000,
    ];
}

/**
 * Remove old completed test-run directories so diagnostics cannot grow without bound.
 */
function admin_test_run_cleanup_old_reports(): array
{
    $root = admin_test_run_root();
    $started = microtime(true);
    $deadline = $started + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    $items = @scandir($root);
    $result = [
        'retention_max_count' => ADMIN_TEST_RUN_MAX_REPORTS,
        'retention_max_bytes' => ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES,
        'deleted_runs' => 0,
        'deleted_entries' => 0,
        'bytes_before' => 0,
        'truncated' => false,
        'elapsed_ms' => 0.0,
    ];
    if (!is_array($items)) {
        return $result;
    }
    $runs = [];
    foreach ($items as $name) {
        if (!admin_test_run_token_valid((string) $name)) {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) {
            continue;
        }
        $size = 0;
        foreach (['report.json', 'report.zip', 'meta.json', 'browser.json'] as $file) {
            $filePath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_file($filePath)) {
                $size += max(0, (int) (@filesize($filePath) ?: 0));
            }
        }
        $runs[] = [
            'name' => $name,
            'path' => $path,
            'mtime' => (int) (@filemtime($path) ?: 0),
            'size_hint' => $size,
            'finalized' => is_file($path . DIRECTORY_SEPARATOR . 'report.json'),
        ];
        $result['bytes_before'] += $size;
    }
    usort($runs, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    $retainedBytes = 0;
    foreach ($runs as $index => $run) {
        $retainedBytes += (int) $run['size_hint'];
        $overCount = $index >= ADMIN_TEST_RUN_MAX_REPORTS;
        $overBytes = $retainedBytes > ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES;
        if ((!$overCount && !$overBytes) || empty($run['finalized'])) {
            continue;
        }
        if (microtime(true) >= $deadline || (int) $result['deleted_entries'] >= ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP) {
            $result['truncated'] = true;
            break;
        }
        $deleted = admin_test_run_delete_tree_bounded((string) $run['path'], $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['deleted_entries']);
        $result['deleted_entries'] += (int) ($deleted['entries'] ?? 0);
        if (!is_dir((string) $run['path'])) {
            $result['deleted_runs']++;
        } else {
            $result['truncated'] = true;
            break;
        }
    }
    $result['elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Recursively remove one application-owned test-run directory.
 */
function admin_test_run_delete_tree(string $path): void
{
    $deadline = microtime(true) + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    admin_test_run_delete_tree_bounded($path, $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP);
}

/**
 * Remove a bounded number of entries from one application-owned diagnostics tree.
 *
 * @return array{entries:int,complete:bool}
 */
function admin_test_run_delete_tree_bounded(string $path, float $deadline, int $entryBudget): array
{
    $entries = 0;
    $entryBudget = max(1, $entryBudget);
    if (!file_exists($path) && !is_link($path)) {
        return ['entries' => 0, 'complete' => true];
    }
    if (!is_dir($path) || is_link($path)) {
        $ok = @unlink($path);
        return ['entries' => $ok ? 1 : 0, 'complete' => $ok];
    }
    $stack = [[$path, false]];
    while ($stack !== [] && $entries < $entryBudget && microtime(true) < $deadline) {
        [$current, $visited] = array_pop($stack);
        if (!is_dir($current) || is_link($current)) {
            if (@unlink($current)) {
                $entries++;
            }
            continue;
        }
        if ($visited) {
            if (@rmdir($current)) {
                $entries++;
            }
            continue;
        }
        $stack[] = [$current, true];
        $items = @scandir($current);
        if (!is_array($items)) {
            continue;
        }
        foreach (array_reverse($items) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $stack[] = [$current . DIRECTORY_SEPARATOR . $name, false];
        }
    }
    return ['entries' => $entries, 'complete' => !file_exists($path)];
}

/**
 * Remove intermediate request/browser sidecars after final artifacts exist.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cleanup_intermediates(string $token): array
{
    $started = microtime(true);
    $deadline = $started + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    $result = ['attempted' => true, 'entries_deleted' => 0, 'complete' => true, 'elapsed_ms' => 0.0];
    foreach ([
        admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'browser.json',
        admin_test_run_requests_directory($token, false),
    ] as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $deleted = admin_test_run_delete_tree_bounded($path, $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['entries_deleted']);
        $result['entries_deleted'] += (int) ($deleted['entries'] ?? 0);
        if (empty($deleted['complete'])) {
            $result['complete'] = false;
            break;
        }
    }
    $result['elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Return a constant-time runtime snapshot for starter metadata before the measured reload.
 *
 * @return array<string,mixed>
 */
function admin_test_run_runtime_preflight(string $stage): array
{
    return [
        'stage' => $stage,
        'at_unix' => microtime(true),
        'pid' => function_exists('getmypid') ? getmypid() : null,
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'memory_usage_bytes' => memory_get_usage(true),
        'memory_peak_bytes' => memory_get_peak_usage(true),
        'included_file_count' => count(get_included_files()),
        'opcache' => [
            'extension_loaded' => extension_loaded('Zend OPcache') || function_exists('opcache_get_status'),
            'enabled' => filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'status_access' => 'deferred_to_post_measurement_inventory',
        ],
    ];
}


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
 * Return whether the current authenticated Admin owns one Test Run metadata/report payload.
 *
 * @param array<string,mixed> $payload Metadata or report payload.
 */
function admin_test_run_owned_by_current_admin(array $payload): bool
{
    $user = current_user();
    return is_array($user)
        && (string) ($user['role'] ?? '') === 'admin'
        && (int) ($user['id'] ?? 0) > 0
        && (int) ($payload['admin']['id'] ?? 0) === (int) ($user['id'] ?? 0);
}

/**
 * Persist starter request correlation after the authenticated starter trace is adopted.
 */
function admin_test_run_set_starter_request_id(string $token, string $requestId): void
{
    $meta = admin_test_run_read_json(admin_test_run_meta_path($token));
    if (!$meta || !preg_match('/^[a-z0-9_.:-]{8,160}$/iD', $requestId)) {
        return;
    }
    $meta['starter_request_id'] = $requestId;
    $meta['events'][] = ['at' => gmdate('c'), 'type' => 'starter_request_correlated', 'request_id' => $requestId];
    admin_test_run_write_json(admin_test_run_meta_path($token), $meta);
}

/**
 * Set the short-lived HttpOnly cookie that makes same-origin PHP subrequests join one run.
 */
function admin_test_run_set_cookie(string $token): void
{
    setcookie(ADMIN_TEST_RUN_COOKIE, $token, [
        'expires' => time() + ADMIN_TEST_RUN_TTL_SECONDS,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[ADMIN_TEST_RUN_COOKIE] = $token;
}

/**
 * Expire the active diagnostic context cookie.
 */
function admin_test_run_clear_cookie(): void
{
    setcookie(ADMIN_TEST_RUN_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[ADMIN_TEST_RUN_COOKIE]);
}

/**
 * Return a bounded UTF-8-aware text prefix without requiring mbstring.
 */
function admin_test_run_text_prefix(string $value, int $length): string
{
    $length = max(0, $length);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
    return substr($value, 0, $length);
}

/**
 * Return a request-safe SQL shape with values removed.
 */
function admin_test_run_sql_shape(string $sql): string
{
    $shape = preg_replace('/\'[^\']*\'|"[^"]*"|\b0x[0-9a-f]+\b|\b-?\d+(?:\.\d+)?\b/i', '?', $sql) ?? $sql;
    $shape = preg_replace('/\s+/', ' ', trim($shape)) ?? trim($shape);
    return admin_test_run_text_prefix($shape, 2000);
}

/**
 * Return one useful application callsite for a traced DB operation.
 *
 * @return array<string,mixed>
 */
function admin_test_run_callsite(): array
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
        $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
        if ($file === '' || str_ends_with($file, '/app/database.php') || str_ends_with($file, '/app/services/admin_test_runs.php')) {
            continue;
        }
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }
        return [
            'file' => $file,
            'line' => (int) ($frame['line'] ?? 0),
            'function' => (string) ($frame['function'] ?? ''),
        ];
    }
    return [];
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
 * Record observation-only lifecycle events from existing updater/maintenance subsystems.
 *
 * @param array<string,mixed> $context Safe event context.
 */
function admin_test_run_record_maintenance_event(string $subsystem, string $event, array $context = []): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    $GLOBALS['admin_test_run_request']['maintenance_events'][] = [
        'subsystem' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $subsystem) ?: 'unknown',
        'event' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $event) ?: 'event',
        'at_unix' => microtime(true),
        'context' => $context,
    ];
}


/**
 * Add a high-resolution lifecycle mark to the active request.
 *
 * @param array<string,mixed> $context Structured context.
 */
function admin_test_run_mark(string $name, array $context = []): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    $state = &$GLOBALS['admin_test_run_request'];
    $now = microtime(true);
    $state['marks'][] = [
        'name' => preg_replace('/[^a-z0-9_.:-]+/i', '_', $name) ?: 'mark',
        'at_unix' => $now,
        'offset_from_request_ms' => max(0.0, ($now - (float) $state['request_time_unix']) * 1000),
        'offset_from_instrumentation_ms' => max(0.0, ($now - (float) $state['instrumentation_enter_unix']) * 1000),
        'memory_usage_bytes' => memory_get_usage(true),
        'context' => $context,
    ];
}

/**
 * Attach one named diagnostic component snapshot to the current request.
 *
 * @param array<string,mixed> $payload Component payload.
 */
function admin_test_run_record_component(string $name, array $payload): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request'])) {
        return;
    }
    if (function_exists(__NAMESPACE__ . '\\admin_test_run_sanitize_browser_value')) {
        $sanitized = admin_test_run_sanitize_browser_value($payload);
        $payload = is_array($sanitized) ? $sanitized : [];
    }
    $GLOBALS['admin_test_run_request']['components'][$name] = $payload;
}

/**
 * Record the shared PDO connection attempt.
 */
function admin_test_run_record_db_connection(float $elapsedMs, bool $ok, string $driver, ?string $error = null): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request']) || !empty($GLOBALS['admin_test_run_request']['finished'])) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['connection_attempts'][] = [
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'driver' => $driver,
        'error' => $error,
    ];
}

/**
 * Record one PDO prepare operation separately from statement execution.
 */
function admin_test_run_record_db_prepare(string $sql, float $elapsedMs, bool $ok, ?string $error = null): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request']) || !empty($GLOBALS['admin_test_run_request']['finished'])) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['prepare_events'][] = [
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'fingerprint' => function_exists(__NAMESPACE__ . '\\telemetry_sql_fingerprint') ? telemetry_sql_fingerprint($sql) : substr(hash('sha256', admin_test_run_sql_shape($sql)), 0, 16),
        'shape' => admin_test_run_sql_shape($sql),
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
}

/**
 * Record a database transaction lifecycle event.
 */
function admin_test_run_record_db_transaction(string $operation, float $elapsedMs, bool $ok, ?string $error = null): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request']) || !empty($GLOBALS['admin_test_run_request']['finished'])) {
        return;
    }
    $GLOBALS['admin_test_run_request']['db']['transaction_events'][] = [
        'at_unix' => microtime(true),
        'operation' => $operation,
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
}

/**
 * Record one PDO operation without persisting raw parameter values.
 */
function admin_test_run_record_db_query(string $sql, float $elapsedMs, bool $ok, int $rowCount = 0, ?string $error = null): void
{
    if (!isset($GLOBALS['admin_test_run_request']) || !is_array($GLOBALS['admin_test_run_request']) || !empty($GLOBALS['admin_test_run_request']['finished'])) {
        return;
    }
    $dbState = &$GLOBALS['admin_test_run_request']['db'];
    $dbState['query_count_total']++;
    $dbState['query_total_ms'] += $elapsedMs;
    $dbState['query_max_ms'] = max((float) $dbState['query_max_ms'], $elapsedMs);
    if (!$ok) {
        $dbState['failed_count']++;
    }
    if ((int) $dbState['query_count_recorded'] >= ADMIN_TEST_RUN_MAX_DB_EVENTS_PER_REQUEST) {
        $dbState['query_events_truncated'] = true;
        return;
    }
    $dbState['query_count_recorded']++;
    $dbState['queries'][] = [
        'sequence' => (int) $dbState['query_count_total'],
        'at_unix' => microtime(true),
        'elapsed_ms' => $elapsedMs,
        'ok' => $ok,
        'operation' => function_exists(__NAMESPACE__ . '\\telemetry_sql_operation') ? telemetry_sql_operation($sql) : strtolower(strtok(ltrim($sql), " \t\n\r") ?: 'other'),
        'table' => function_exists(__NAMESPACE__ . '\\telemetry_sql_table_name') ? telemetry_sql_table_name($sql) : '',
        'fingerprint' => function_exists(__NAMESPACE__ . '\\telemetry_sql_fingerprint') ? telemetry_sql_fingerprint($sql) : substr(hash('sha256', admin_test_run_sql_shape($sql)), 0, 16),
        'shape' => admin_test_run_sql_shape($sql),
        'row_count' => $rowCount,
        'error' => $error,
        'callsite' => admin_test_run_callsite(),
    ];
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
 * Read every completed and active request sidecar for a run.
 *
 * @return array{completed:array<int,array<string,mixed>>,active:array<int,array<string,mixed>>}
 */
function admin_test_run_request_records(string $token): array
{
    $directory = admin_test_run_requests_directory($token, false);
    $completed = [];
    $active = [];
    if (!is_dir($directory)) {
        return ['completed' => [], 'active' => []];
    }
    $items = @scandir($directory);
    if (!is_array($items)) {
        return ['completed' => [], 'active' => []];
    }
    foreach ($items as $name) {
        if (!str_ends_with($name, '.json')) {
            continue;
        }
        $payload = admin_test_run_read_json($directory . DIRECTORY_SEPARATOR . $name);
        if (!$payload) {
            continue;
        }
        unset($payload['token']);
        if (str_ends_with($name, '.active.json')) {
            $active[] = $payload;
        } else {
            $completed[] = $payload;
        }
    }
    usort($completed, static fn (array $a, array $b): int => ((float) ($a['request_time_unix'] ?? 0)) <=> ((float) ($b['request_time_unix'] ?? 0)));
    return ['completed' => $completed, 'active' => $active];
}

/**
 * Build request concurrency statistics from completed request intervals.
 *
 * @param array<int,array<string,mixed>> $requests Completed request records.
 * @return array<string,mixed>
 */
function admin_test_run_concurrency_summary(array $requests): array
{
    $events = [];
    $pids = [];
    $duration = 0.0;
    foreach ($requests as $request) {
        $start = (float) ($request['request_time_unix'] ?? 0.0);
        $end = (float) ($request['finished_at_unix'] ?? 0.0);
        if ($start > 0.0 && $end >= $start) {
            $events[] = ['at' => $start, 'delta' => 1, 'type' => 'start', 'id' => (string) ($request['request_id'] ?? '')];
            $events[] = ['at' => $end, 'delta' => -1, 'type' => 'end', 'id' => (string) ($request['request_id'] ?? '')];
            $duration += max(0.0, ($end - $start) * 1000);
        }
        $pid = (int) ($request['process']['pid'] ?? 0);
        if ($pid > 0) {
            $pids[$pid] = ($pids[$pid] ?? 0) + 1;
        }
    }
    usort($events, static function (array $a, array $b): int {
        if ($a['at'] === $b['at']) {
            return $a['delta'] <=> $b['delta'];
        }
        return $a['at'] <=> $b['at'];
    });
    $active = 0;
    $peak = 0;
    $peakAt = null;
    foreach ($events as $event) {
        $active += (int) $event['delta'];
        if ($active > $peak) {
            $peak = $active;
            $peakAt = $event['at'];
        }
    }
    return [
        'completed_request_count' => count($requests),
        'peak_concurrent_php_requests' => $peak,
        'peak_at_unix' => $peakAt,
        'distinct_pids' => count($pids),
        'requests_by_pid' => $pids,
        'aggregate_php_request_wall_ms' => $duration,
        'diagnostic_runner_intended_probe_concurrency' => 1,
        'danger_threshold' => 32,
        'dangerous_peak_detected' => $peak >= 32,
    ];
}

/**
 * Summarize database activity across every traced PHP request.
 *
 * @param array<int,array<string,mixed>> $requests Completed request records.
 * @return array<string,mixed>
 */
function admin_test_run_db_summary(array $requests): array
{
    $queryCount = 0;
    $failed = 0;
    $totalMs = 0.0;
    $maxMs = 0.0;
    $byOperation = [];
    $byTable = [];
    $slowest = [];
    $prepareCount = 0;
    $prepareFailedCount = 0;
    $prepareTotalMs = 0.0;
    $prepareMaxMs = 0.0;
    $transactionEvents = [];
    $transactionFailedCount = 0;
    $transactionBalances = [];
    foreach ($requests as $request) {
        $dbState = is_array($request['db'] ?? null) ? $request['db'] : [];
        $queryCount += (int) ($dbState['query_count_total'] ?? 0);
        $failed += (int) ($dbState['failed_count'] ?? 0);
        $totalMs += (float) ($dbState['query_total_ms'] ?? 0.0);
        $maxMs = max($maxMs, (float) ($dbState['query_max_ms'] ?? 0.0));
        foreach ((array) ($dbState['prepare_events'] ?? []) as $prepare) {
            if (!is_array($prepare)) continue;
            $prepareCount++;
            if (empty($prepare['ok'])) $prepareFailedCount++;
            $prepareTotalMs += (float) ($prepare['elapsed_ms'] ?? 0.0);
            $prepareMaxMs = max($prepareMaxMs, (float) ($prepare['elapsed_ms'] ?? 0.0));
        }
        $requestId = (string) ($request['request_id'] ?? '');
        $transactionBalance = 0;
        foreach ((array) ($dbState['transaction_events'] ?? []) as $transaction) {
            if (!is_array($transaction)) continue;
            $transactionEvents[] = array_merge(['request_id' => $requestId], $transaction);
            if (empty($transaction['ok'])) {
                $transactionFailedCount++;
                continue;
            }
            $operation = (string) ($transaction['operation'] ?? '');
            if ($operation === 'begin') $transactionBalance++;
            if (($operation === 'commit' || $operation === 'rollback') && $transactionBalance > 0) $transactionBalance--;
        }
        if ($transactionBalance !== 0) {
            $transactionBalances[$requestId] = $transactionBalance;
        }
        foreach ((array) ($dbState['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $operation = (string) ($query['operation'] ?? 'other');
            $table = (string) ($query['table'] ?? '');
            $byOperation[$operation] = ($byOperation[$operation] ?? 0) + 1;
            if ($table !== '') {
                $byTable[$table] = ($byTable[$table] ?? 0) + 1;
            }
            $slowest[] = [
                'elapsed_ms' => (float) ($query['elapsed_ms'] ?? 0.0),
                'operation' => $operation,
                'table' => $table,
                'fingerprint' => (string) ($query['fingerprint'] ?? ''),
                'shape' => (string) ($query['shape'] ?? ''),
                'callsite' => $query['callsite'] ?? [],
                'request_id' => (string) ($request['request_id'] ?? ''),
            ];
        }
    }
    arsort($byOperation);
    arsort($byTable);
    usort($slowest, static fn (array $a, array $b): int => $b['elapsed_ms'] <=> $a['elapsed_ms']);
    return [
        'query_count' => $queryCount,
        'failed_count' => $failed,
        'total_query_ms' => $totalMs,
        'max_query_ms' => $maxMs,
        'average_query_ms' => $queryCount > 0 ? $totalMs / $queryCount : 0.0,
        'prepare_count' => $prepareCount,
        'prepare_failed_count' => $prepareFailedCount,
        'prepare_total_ms' => $prepareTotalMs,
        'prepare_max_ms' => $prepareMaxMs,
        'transaction_events' => $transactionEvents,
        'transaction_failed_count' => $transactionFailedCount,
        'unclosed_transaction_balance_by_request' => $transactionBalances,
        'all_traced_transactions_closed' => $transactionBalances === [],
        'by_operation' => $byOperation,
        'by_table' => $byTable,
        'slowest_queries' => array_slice($slowest, 0, 100),
    ];
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

/**
 * Build an optional ZIP containing the final JSON report.
 *
 * @param array<string,mixed> $report Final report payload.
 */
function admin_test_run_build_zip(string $token, array $report): void
{
    $zipPath = admin_test_run_zip_path($token);
    @unlink($zipPath);
    if (!class_exists(ZipArchive::class)) {
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return;
    }
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json)) {
            $zip->addFromString(admin_test_run_download_filename($report, false), $json . "\n");
        }
    } finally {
        $zip->close();
    }
}

/**
 * Return a filesystem-safe download name for one test-run report.
 */
function admin_test_run_download_filename(array $report, bool $zip = true): string
{
    $page = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($report['target']['page'] ?? 'gallery')) ?: 'gallery';
    $created = (string) ($report['created_at'] ?? gmdate('c'));
    $stamp = preg_replace('/[^0-9]/', '', $created) ?: gmdate('YmdHis');
    $stamp = substr($stamp, 0, 14);
    $token = preg_replace('/[^a-z0-9]+/i', '', (string) ($report['run_id'] ?? '')) ?: 'run';
    $token = substr($token, 0, 8);
    return 'php-gallery-test-run-' . $page . '-' . $stamp . '-' . $token . ($zip ? '.zip' : '.json');
}

/**
 * Return recent finalized reports for the Admin diagnostics panel.
 *
 * @return array<int,array<string,mixed>>
 */
function admin_test_run_recent_reports(int $limit = 10): array
{
    $root = admin_test_run_root();
    $items = @scandir($root);
    if (!is_array($items)) {
        return [];
    }
    $reports = [];
    foreach ($items as $name) {
        if (!admin_test_run_token_valid((string) $name)) {
            continue;
        }
        $report = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'report.json');
        if (!$report || !admin_test_run_owned_by_current_admin($report)) {
            continue;
        }
        $reports[] = [
            'token' => $name,
            'created_at' => (string) ($report['created_at'] ?? ''),
            'finalized_at' => (string) ($report['finalized_at'] ?? ''),
            'target_page' => (string) ($report['target']['page'] ?? ''),
            'target' => (string) ($report['target']['request_target'] ?? ''),
            'request_count' => (int) ($report['request_lifecycle']['completed_count'] ?? 0),
            'peak_concurrency' => (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0),
            'all_closed' => !empty($report['request_lifecycle']['all_completed_cleanly']),
            'zip_available' => is_file($root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'report.zip'),
        ];
    }
    usort($reports, static fn (array $a, array $b): int => strcmp($b['finalized_at'], $a['finalized_at']));
    return array_slice($reports, 0, max(1, min(50, $limit)));
}

/**
 * Return the latest finalized run for the current target, if any.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_latest_for_target(string $target): ?array
{
    $target = admin_test_run_normalize_target($target);
    foreach (admin_test_run_recent_reports(20) as $report) {
        if (admin_test_run_normalize_target((string) ($report['target'] ?? '')) === $target) {
            return $report;
        }
    }
    return null;
}

/**
 * Render the opt-in Admin test-run panel near existing benchmark/testing controls.
 */
function render_admin_test_run_panel(): void
{
    $user = current_user();
    $page = (string) ($_GET['page'] ?? 'home');
    if (!$user || !in_array($page, ['gallery', 'smart_gallery'], true) || !feature_flag_enabled('admin_test_runs')) {
        return;
    }
    $target = admin_test_run_normalize_target((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    $activeToken = admin_test_run_cookie_token();
    $active = admin_test_run_active();
    $latest = admin_test_run_latest_for_target($target);
    $currentRequestId = admin_test_run_current_request_id();
    $starterRequestId = preg_match('/^[a-z0-9_.:-]{8,160}$/iD', (string) ($_GET['test_run_starter_request_id'] ?? ''))
        ? (string) $_GET['test_run_starter_request_id']
        : '';
    echo '<section class="panel admin-full-test-run" data-admin-test-run-panel data-test-run-active="' . ($active ? '1' : '0') . '"';
    if ($activeToken !== '') {
        echo ' data-test-run-token="' . htmlspecialchars($activeToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    if ($currentRequestId !== '') {
        echo ' data-current-request-id="' . htmlspecialchars($currentRequestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    if ($starterRequestId !== '') {
        echo ' data-starter-request-id="' . htmlspecialchars($starterRequestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    echo ' data-finish-url="' . htmlspecialchars(url_for('admin_test_run_finish'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-finalize-url="' . htmlspecialchars(url_for('admin_test_run_finalize'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-probe-url="' . htmlspecialchars(url_for('admin_test_run_probe'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-static-probe-url="' . htmlspecialchars((function_exists('Gallery\\Core\\asset_url') ? \Gallery\Core\asset_url('assets/gallery-benchmark-static-probe.txt') : '/public/assets/gallery-benchmark-static-probe.txt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    echo ' data-csrf-token="' . htmlspecialchars(\Gallery\Core\csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . htmlspecialchars(t('admin.test_run.kicker', 'Deep diagnostics'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p><h2>' . htmlspecialchars(t('admin.test_run.title', 'Full Admin test run'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2></div><div class="admin-hero-actions">';
    if ($latest) {
        echo '<a class="button secondary" href="' . htmlspecialchars(url_for('admin_test_run_download', ['token' => $latest['token']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars(t('admin.test_run.download_latest', 'Download latest test run'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }
    echo '</div></div>';
    echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.help', 'Opt-in administrator diagnostics. A run clears safe application caches, forcibly reloads this gallery, records PHP lifecycle/database/cache/process/concurrency details for every same-origin PHP request, performs only sequential verification probes, and produces a downloadable JSON/ZIP report.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    if ($active) {
        echo '<div class="notice" data-admin-test-run-status>' . htmlspecialchars(t('admin.test_run.running', 'Test run is active. Browser and PHP probes will finalize automatically after the page load settles.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    } elseif ($latest) {
        echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.latest_summary', 'Latest: {time}; PHP requests: {requests}; peak concurrency: {peak}; all requests closed: {closed}.', [
            'time' => (string) $latest['finalized_at'],
            'requests' => (string) $latest['request_count'],
            'peak' => (string) $latest['peak_concurrency'],
            'closed' => $latest['all_closed'] ? 'yes' : 'no',
        ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    } else {
        echo '<p class="muted">' . htmlspecialchars(t('admin.test_run.none', 'No completed full test run is stored for this gallery yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    echo '</section>';
}
