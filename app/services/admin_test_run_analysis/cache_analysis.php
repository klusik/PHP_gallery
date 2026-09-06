<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/cache_analysis.php
 * Module Type: Service
 *
 * Purpose:
 *   Analyses cache inventories, cache policy, and opcache capability.
 *
 * Responsibilities:
 *   - Inventory cache families in a single bounded filesystem pass
 *   - Apply per-family retention policy to the collected entries
 *   - Detect conflicting or missing cache-control response headers
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
 *   - Loaded by app/services/admin_test_run_analysis.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_run_analysis.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return OPcache diagnostic capability without causing a restrict_api warning when it is knowably forbidden.
 *
 * @return array<string,mixed>
 */
function admin_test_run_opcache_capability(): array
{
    $extensionLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
    $enabled = filter_var((string) ini_get('opcache.enable'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $restrictApi = trim((string) ini_get('opcache.restrict_api'));
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    $normalizedRestriction = str_replace('\\', '/', $restrictApi);
    $restricted = false;
    if ($restrictApi !== '') {
        $restricted = !str_starts_with($script, $normalizedRestriction);
    }
    $result = [
        'extension_loaded' => $extensionLoaded,
        'enabled' => $enabled,
        'status_access' => !$extensionLoaded ? 'unavailable' : ($restricted ? 'restricted' : 'unavailable'),
        'restrict_api_configured' => $restrictApi !== '',
        'status' => null,
    ];
    if (!$extensionLoaded || !function_exists('opcache_get_status')) {
        return $result;
    }
    if ($restricted) {
        $result['status_access'] = 'restricted';
        $result['note'] = 'opcache_get_status() was not called because opcache.restrict_api does not allow this script path.';
        return $result;
    }
    $status = @opcache_get_status(false);
    if (!is_array($status)) {
        $lastError = error_get_last();
        if (is_array($lastError) && str_contains(strtolower((string) ($lastError['message'] ?? '')), 'restrict_api')) {
            $result['status_access'] = 'restricted';
            $result['note'] = 'Host runtime rejected OPcache status access through restrict_api.';
        } else {
            $result['status_access'] = 'unavailable';
        }
        return $result;
    }
    $result['status_access'] = 'available';
    $result['status'] = [
        'opcache_enabled' => !empty($status['opcache_enabled']),
        'cache_full' => !empty($status['cache_full']),
        'restart_pending' => !empty($status['restart_pending']),
        'restart_in_progress' => !empty($status['restart_in_progress']),
        'memory_usage' => $status['memory_usage'] ?? null,
        'interned_strings_usage' => $status['interned_strings_usage'] ?? null,
        'opcache_statistics' => $status['opcache_statistics'] ?? null,
    ];
    return $result;
}

/**
 * Return a very light cache preflight with no recursive traversal.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_preflight(?string $root = null): array
{
    $started = microtime(true);
    $root = $root ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache';
    $known = ['updates', 'admin-test-runs', 'github-api', 'thumbnail-warmup', 'site-maintenance', 'zips', 'gallery-migrations'];
    $families = [];
    foreach ($known as $name) {
        $path = $root . DIRECTORY_SEPARATOR . $name;
        $families[$name] = [
            'exists' => is_dir($path),
            'mtime' => is_dir($path) ? (@filemtime($path) ?: null) : null,
        ];
    }
    return [
        'mode' => 'non_recursive_preflight',
        'root_exists' => is_dir($root),
        'root_mtime' => is_dir($root) ? (@filemtime($root) ?: null) : null,
        'families' => $families,
        'elapsed_ms' => (microtime(true) - $started) * 1000,
        'recursive_entries_visited' => 0,
    ];
}

/**
 * Return semantic cache-family metadata for one first-level cache directory name.
 *
 * @return array<string,string>
 */
function admin_test_run_cache_family_policy(string $family): array
{
    $policies = [
        'updates' => [
            'semantic_name' => 'cache/updates',
            'retention_policy' => 'Updater-owned durable jobs/artifacts. Successful updates use the updater state machine for logical invalidation, generation advance, stale marking/moving, and bounded physical cleanup.',
            'reclaimability' => 'Do not infer deletability from age alone. An update that installed the cleanup feature may itself have run under the previous updater lifecycle.',
        ],
        'admin-test-runs' => [
            'semantic_name' => 'cache/admin-test-runs',
            'retention_policy' => 'Final reports are bounded by Admin Test Run count/size retention. Successful finalization removes intermediate sidecars with bounded cleanup.',
            'reclaimability' => 'Completed runs beyond retention are reclaimable by the Test Run retention policy; failed/incomplete runs keep forensic sidecars.',
        ],
        'github-api' => [
            'semantic_name' => 'cache/github-api',
            'retention_policy' => 'GitHub API response cache managed by the GitHub/update subsystem.',
            'reclaimability' => 'Unknown files are not assumed deletable by diagnostics.',
        ],
        'thumbnail-warmup' => [
            'semantic_name' => 'cache/thumbnail-warmup',
            'retention_policy' => 'Warmup lock/cooldown state is application-managed and intentionally small.',
            'reclaimability' => 'Diagnostics do not delete warmup state.',
        ],
        'site-maintenance' => [
            'semantic_name' => 'cache/site-maintenance',
            'retention_policy' => 'Site-maintenance lock/request-trigger state is application-managed.',
            'reclaimability' => 'Diagnostics do not delete maintenance state.',
        ],
        'zips' => [
            'semantic_name' => 'cache/zips',
            'retention_policy' => 'Generated gallery download archives use the existing ZIP-cache lifecycle.',
            'reclaimability' => 'Diagnostics do not infer arbitrary ZIP cache files are stale.',
        ],
        'gallery-migrations' => [
            'semantic_name' => 'cache/gallery-migrations',
            'retention_policy' => 'Migration state/artifacts are managed by the gallery migration subsystem.',
            'reclaimability' => 'Diagnostics do not delete migration state.',
        ],
        'other' => [
            'semantic_name' => 'cache/other',
            'retention_policy' => 'Unknown or uncategorized application cache data.',
            'reclaimability' => 'No automatic reclaimability assumption is made.',
        ],
    ];
    return $policies[$family] ?? $policies['other'];
}

/**
 * Initialize one cache-family accumulator.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_family_accumulator(string $family): array
{
    $policy = admin_test_run_cache_family_policy($family);
    return [
        'semantic_name' => $policy['semantic_name'],
        'files' => 0,
        'directories' => 0,
        'bytes' => 0,
        'oldest_artifact' => null,
        'newest_artifact' => null,
        'retention_policy' => $policy['retention_policy'],
        'probable_reclaimable_or_stale' => $policy['reclaimability'],
    ];
}

/**
 * Add one file/directory observation to a cache-family accumulator.
 *
 * @param array<string,mixed> $family Family accumulator.
 */
function admin_test_run_cache_family_add(array &$family, bool $isDirectory, int $bytes, int $mtime, string $relativePath): void
{
    if ($isDirectory) {
        $family['directories']++;
        return;
    }
    $family['files']++;
    $family['bytes'] += max(0, $bytes);
    if ($mtime <= 0) {
        return;
    }
    $artifact = ['relative_path' => substr(str_replace('\\', '/', $relativePath), 0, 1000), 'mtime' => $mtime, 'at' => gmdate('c', $mtime)];
    if (!is_array($family['oldest_artifact']) || $mtime < (int) ($family['oldest_artifact']['mtime'] ?? PHP_INT_MAX)) {
        $family['oldest_artifact'] = $artifact;
    }
    if (!is_array($family['newest_artifact']) || $mtime > (int) ($family['newest_artifact']['mtime'] ?? 0)) {
        $family['newest_artifact'] = $artifact;
    }
}

/**
 * Traverse cache exactly once and derive root plus subtree totals from that one walk.
 *
 * @return array<string,mixed>
 */
function admin_test_run_cache_inventory_single_pass(?string $root = null, ?int $entryCap = null, ?float $timeBudgetMs = null): array
{
    $root = $root ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cache';
    $entryCap = $entryCap ?? (defined(__NAMESPACE__ . '\\ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP') ? ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP : 25000);
    $timeBudgetMs = $timeBudgetMs ?? (defined(__NAMESPACE__ . '\\ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS') ? ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS : 250.0);
    $entryCap = max(100, $entryCap);
    $timeBudgetMs = max(10.0, $timeBudgetMs);
    $started = microtime(true);
    $deadline = $started + ($timeBudgetMs / 1000.0);
    $known = ['updates', 'admin-test-runs', 'github-api', 'thumbnail-warmup', 'site-maintenance', 'zips', 'gallery-migrations'];
    $families = ['application_cache' => admin_test_run_cache_family_accumulator('other')];
    $families['application_cache']['semantic_name'] = 'cache/';
    $families['application_cache']['retention_policy'] = 'Aggregate application cache total derived from this same single traversal; it is not scanned separately.';
    $families['application_cache']['probable_reclaimable_or_stale'] = 'Use per-family semantics; aggregate cache bytes are not assumed reclaimable.';
    foreach ($known as $name) {
        $families[$name] = admin_test_run_cache_family_accumulator($name);
    }
    $families['other'] = admin_test_run_cache_family_accumulator('other');

    $result = [
        'mode' => 'single_pass_bounded_recursive_inventory',
        'root' => str_replace('\\', '/', $root),
        'exists' => is_dir($root),
        'traversal_count' => is_dir($root) ? 1 : 0,
        'entry_cap' => $entryCap,
        'time_budget_ms' => $timeBudgetMs,
        'entries_visited' => 0,
        'truncated' => false,
        'truncation_reason' => '',
        'families' => $families,
        'scan_elapsed_ms' => 0.0,
    ];
    if (!is_dir($root)) {
        $result['scan_elapsed_ms'] = (microtime(true) - $started) * 1000;
        return $result;
    }

    $stack = [[$root, '']];
    while ($stack !== []) {
        if (microtime(true) >= $deadline) {
            $result['truncated'] = true;
            $result['truncation_reason'] = 'time_budget';
            break;
        }
        [$directory, $relativeDirectory] = array_pop($stack);
        $items = @scandir($directory);
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $result['entries_visited']++;
            if ((int) $result['entries_visited'] > $entryCap) {
                $result['truncated'] = true;
                $result['truncation_reason'] = 'entry_cap';
                break 2;
            }
            if (((int) $result['entries_visited'] & 63) === 0 && microtime(true) >= $deadline) {
                $result['truncated'] = true;
                $result['truncation_reason'] = 'time_budget';
                break 2;
            }
            $relative = $relativeDirectory === '' ? $name : $relativeDirectory . '/' . $name;
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            $isDirectory = is_dir($path) && !is_link($path);
            $topLevel = explode('/', $relative, 2)[0];
            $familyKey = in_array($topLevel, $known, true) ? $topLevel : 'other';
            $bytes = $isDirectory ? 0 : max(0, (int) (@filesize($path) ?: 0));
            $mtime = max(0, (int) (@filemtime($path) ?: 0));
            admin_test_run_cache_family_add($result['families']['application_cache'], $isDirectory, $bytes, $mtime, $relative);
            admin_test_run_cache_family_add($result['families'][$familyKey], $isDirectory, $bytes, $mtime, $relative);
            if ($isDirectory) {
                $stack[] = [$path, $relative];
            }
        }
    }
    $result['scan_elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Analyze Cache-Control values from PHP header lines or browser provider-header maps.
 *
 * @param array<mixed> $headers Header lines or name/value map.
 * @return array{conflict:bool,values:array<int,string>,directives:array<string,array<int,string>>,reasons:array<int,string>}
 */
function admin_test_run_cache_control_analysis(array $headers): array
{
    $values = [];
    foreach ($headers as $name => $header) {
        if (is_string($name) && strtolower(trim($name)) === 'cache-control' && is_scalar($header)) {
            $values[] = trim((string) $header);
            continue;
        }
        if (!is_string($header) || stripos(ltrim($header), 'cache-control:') !== 0) {
            continue;
        }
        $values[] = trim(substr(ltrim($header), strlen('cache-control:')));
    }

    $directives = [];
    foreach ($values as $value) {
        foreach (explode(',', strtolower($value)) as $directive) {
            $directive = trim($directive);
            if ($directive === '') {
                continue;
            }
            [$directiveName, $directiveValue] = array_pad(explode('=', $directive, 2), 2, '');
            $directiveName = trim($directiveName);
            $directiveValue = trim($directiveValue, " \t\n\r\0\x0B\"");
            if ($directiveName === '') {
                continue;
            }
            $directives[$directiveName][] = $directiveValue;
        }
    }

    $reasons = [];
    if (isset($directives['public'], $directives['private'])) {
        $reasons[] = 'public_and_private';
    }
    if (isset($directives['no-store']) && (isset($directives['public']) || isset($directives['immutable']))) {
        $reasons[] = 'no_store_with_cacheable_directive';
    }
    foreach ($directives as $directiveName => $directiveValues) {
        $distinct = array_values(array_unique($directiveValues));
        if (count($distinct) > 1) {
            $reasons[] = 'conflicting_duplicate_' . $directiveName;
        }
    }

    return [
        'conflict' => $reasons !== [],
        'values' => $values,
        'directives' => $directives,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

/**
 * Return true when response headers contain contradictory cache directives.
 *
 * @param array<mixed> $headers Header lines or name/value map.
 */
function admin_test_run_cache_control_conflict(array $headers): bool
{
    return !empty(admin_test_run_cache_control_analysis($headers)['conflict']);
}
