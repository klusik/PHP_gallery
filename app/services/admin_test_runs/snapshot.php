<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/snapshot.php
 * Module Type: Service
 *
 * Purpose:
 *   Captures bounded runtime, cache, lock, and subsystem snapshots.
 *
 * Responsibilities:
 *   - Capture PHP runtime and resource counters at named stages
 *   - Inventory cache directories and lock files under explicit budgets
 *   - Clear only caches that are safe to rebuild during a run
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
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
        $archiveLock = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-log-archive-maintenance.lock';
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
