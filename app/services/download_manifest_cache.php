<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/download_manifest_cache.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides bounded revision-keyed metadata caching and diagnostics for public download manifests.
 *
 * Responsibilities:
 *   - Cache only normalized content metadata, never bearer capabilities or visitor identity
 *   - Keep cache identity derived from content-affecting resource state
 *   - Publish cache entries atomically under the private application cache tree
 *   - Record per-request manifest cost for Admin test-run diagnostics and Server-Timing
 *   - Remove expired/corrupt manifest metadata during scheduled maintenance
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
 *   - Capability tokens must never be written into this cache.
 *
 * Last Updated:
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\path_inside;

const DOWNLOAD_MANIFEST_CACHE_FORMAT = 1;
const DOWNLOAD_MANIFEST_RESOURCE_GALLERY = 'gallery';
const DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY = 'smart_gallery';

/**
 * Return the current Admin test-run SQL query counter when instrumentation is active.
 *
 * @return ?int Current query count, or null when request instrumentation is inactive.
 */
function download_manifest_profile_current_db_query_count(): ?int
{
    $request = $GLOBALS['admin_test_run_request'] ?? null;
    if (!is_array($request) || !is_array($request['db'] ?? null)) {
        return null;
    }
    return max(0, (int) ($request['db']['query_count_total'] ?? 0));
}

/**
 * Return the mutable request-local manifest profile state.
 *
 * @return array<string,mixed>
 */
function &download_manifest_profile_state(): array
{
    static $state = [];
    return $state;
}

/**
 * Start profiling one authorized manifest build after capability/resource checks.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 */
function download_manifest_profile_begin(string $resourceType, int $resourceId): void
{
    $state =& download_manifest_profile_state();
    $state = [
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'started_at' => microtime(true),
        'memory_start_bytes' => memory_get_usage(true),
        'db_query_start' => download_manifest_profile_current_db_query_count(),
        'cache' => 'none',
        'gallery_rows' => 0,
        'image_rows' => 0,
        'filesystem_checks' => 0,
        'filesystem_size_reads' => 0,
        'filesystem_realpath_checks' => 0,
        'finished' => false,
    ];
}

/**
 * Increment one non-negative manifest profile counter.
 *
 * @param string $counter Counter key owned by the manifest profiler.
 * @param int $amount Non-negative increment amount.
 */
function download_manifest_profile_count(string $counter, int $amount = 1): void
{
    $state =& download_manifest_profile_state();
    if ($state === [] || $amount <= 0) {
        return;
    }
    $state[$counter] = max(0, (int) ($state[$counter] ?? 0)) + $amount;
}

/**
 * Record whether the current manifest used a reusable metadata cache entry.
 *
 * @param string $status Cache status: hit, miss, or bypass.
 */
function download_manifest_profile_set_cache(string $status): void
{
    $state =& download_manifest_profile_state();
    if ($state === []) {
        return;
    }
    $state['cache'] = in_array($status, ['hit', 'miss', 'bypass'], true) ? $status : 'none';
}

/**
 * Finish the current profile and attach it to an active Admin test run when available.
 */
function download_manifest_profile_finish(): void
{
    $state =& download_manifest_profile_state();
    if ($state === [] || !empty($state['finished'])) {
        return;
    }

    $endedAt = microtime(true);
    $dbEnd = download_manifest_profile_current_db_query_count();
    $dbStart = $state['db_query_start'];
    $state['elapsed_ms'] = max(0.0, ($endedAt - (float) $state['started_at']) * 1000.0);
    $state['memory_delta_bytes'] = max(0, memory_get_usage(true) - (int) $state['memory_start_bytes']);
    $state['memory_peak_bytes'] = memory_get_peak_usage(true);
    $state['db_queries'] = ($dbStart !== null && $dbEnd !== null) ? max(0, $dbEnd - (int) $dbStart) : null;
    $state['finished'] = true;

    if (function_exists(__NAMESPACE__ . '\\admin_test_run_active')
        && admin_test_run_active()
        && function_exists(__NAMESPACE__ . '\\admin_test_run_record_component')) {
        admin_test_run_record_component('download_manifest_profile', download_manifest_profile_snapshot());
    }
}

/**
 * Return a credential-free manifest profile snapshot.
 *
 * @return array<string,mixed>
 */
function download_manifest_profile_snapshot(): array
{
    $state =& download_manifest_profile_state();
    if ($state === []) {
        return [];
    }

    return [
        'resource_type' => (string) ($state['resource_type'] ?? ''),
        'resource_id' => max(0, (int) ($state['resource_id'] ?? 0)),
        'cache' => (string) ($state['cache'] ?? 'none'),
        'db_queries' => isset($state['db_queries']) ? (int) $state['db_queries'] : null,
        'gallery_rows' => max(0, (int) ($state['gallery_rows'] ?? 0)),
        'image_rows' => max(0, (int) ($state['image_rows'] ?? 0)),
        'filesystem_checks' => max(0, (int) ($state['filesystem_checks'] ?? 0)),
        'filesystem_size_reads' => max(0, (int) ($state['filesystem_size_reads'] ?? 0)),
        'filesystem_realpath_checks' => max(0, (int) ($state['filesystem_realpath_checks'] ?? 0)),
        'elapsed_ms' => max(0.0, (float) ($state['elapsed_ms'] ?? 0.0)),
        'memory_delta_bytes' => max(0, (int) ($state['memory_delta_bytes'] ?? 0)),
        'memory_peak_bytes' => max(0, (int) ($state['memory_peak_bytes'] ?? memory_get_peak_usage(true))),
    ];
}

/**
 * Emit non-sensitive response timing/cache diagnostics for one completed manifest request.
 */
function download_manifest_profile_emit_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $snapshot = download_manifest_profile_snapshot();
    if ($snapshot === []) {
        return;
    }

    $cache = (string) ($snapshot['cache'] ?? 'none');
    header('X-PHP-Gallery-Manifest-Cache: ' . $cache);
    $parts = ['download-manifest;dur=' . number_format((float) ($snapshot['elapsed_ms'] ?? 0.0), 2, '.', '')];
    if ($snapshot['db_queries'] !== null) {
        $parts[] = 'download-manifest-db;desc="' . (int) $snapshot['db_queries'] . ' queries"';
    }
    $parts[] = 'download-manifest-fs;desc="' . (int) ($snapshot['filesystem_checks'] ?? 0) . ' checks"';
    header('Server-Timing: ' . implode(', ', $parts));
}

/**
 * Normalize and validate a manifest cache resource type.
 *
 * @param string $resourceType Candidate resource type.
 * @return string Validated resource type.
 */
function download_manifest_cache_resource_type(string $resourceType): string
{
    if (!in_array($resourceType, [DOWNLOAD_MANIFEST_RESOURCE_GALLERY, DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY], true)) {
        throw new \InvalidArgumentException('Invalid download manifest cache resource type.');
    }
    return $resourceType;
}

/**
 * Return the private manifest metadata cache root.
 *
 * @return string Absolute private cache path.
 */
function download_manifest_cache_root(): string
{
    $path = zip_cache_dir() . DIRECTORY_SEPARATOR . '.download-manifests';
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new \RuntimeException('Unable to initialize download manifest cache.');
    }
    return $path;
}

/**
 * Return the configured retention age for one manifest cache resource type.
 *
 * @param string $resourceType Valid manifest cache resource type.
 * @return int Retention age in seconds.
 */
function download_manifest_cache_retention_seconds(string $resourceType): int
{
    $resourceType = download_manifest_cache_resource_type($resourceType);
    $key = $resourceType === DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY
        ? 'download.manifest_cache_smart_retention_seconds'
        : 'download.manifest_cache_physical_retention_seconds';
    return max(60, (int) cms_runtime_limit($key));
}

/**
 * Return the maximum accepted size of one cached manifest metadata file.
 *
 * @return int Maximum metadata file size in bytes.
 */
function download_manifest_cache_max_entry_bytes(): int
{
    return max(64 * 1024, (int) cms_runtime_limit('download.manifest_cache_max_entry_bytes'));
}

/**
 * Build the canonical cache path from content identity only.
 *
 * Capability tokens, host names, request IDs, client data, and unrelated query
 * parameters cannot influence this identity.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 * @param string $revision Content-only SHA-256 revision.
 * @return string Absolute cache-entry path.
 */
function download_manifest_cache_path(string $resourceType, int $resourceId, string $revision): string
{
    $resourceType = download_manifest_cache_resource_type($resourceType);
    $revision = strtolower(trim($revision));
    if ($resourceId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
        throw new \InvalidArgumentException('Invalid download manifest cache identity.');
    }

    $dir = download_manifest_cache_root() . DIRECTORY_SEPARATOR . $resourceType . DIRECTORY_SEPARATOR . $resourceId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('Unable to initialize download manifest cache resource directory.');
    }
    return $dir . DIRECTORY_SEPARATOR . $revision . '.json';
}

/**
 * Validate normalized capability-free cached manifest data.
 *
 * @param mixed $payload Decoded candidate payload.
 * @return ?array{files:array<int,array{name:string,size:int,image_id:int,version:string}>,total_files:int,total_bytes:int}
 */
function download_manifest_cache_normalize_payload(mixed $payload): ?array
{
    if (!is_array($payload) || !is_array($payload['files'] ?? null)) {
        return null;
    }
    $maxFiles = max(1, (int) cms_runtime_limit('download.manifest_max_files'));
    if (count($payload['files']) > $maxFiles) {
        return null;
    }

    $files = [];
    $totalBytes = 0;
    foreach ($payload['files'] as $file) {
        if (!is_array($file)) {
            return null;
        }
        $name = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? -1);
        $imageId = (int) ($file['image_id'] ?? 0);
        $version = strtolower((string) ($file['version'] ?? ''));
        if ($name === '' || $size < 0 || $imageId <= 0 || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1) {
            return null;
        }
        if ($size > PHP_INT_MAX - $totalBytes) {
            return null;
        }
        $totalBytes += $size;
        $files[] = [
            'name' => $name,
            'size' => $size,
            'image_id' => $imageId,
            'version' => $version,
        ];
    }

    if ((int) ($payload['total_files'] ?? -1) !== count($files)
        || (int) ($payload['total_bytes'] ?? -1) !== $totalBytes) {
        return null;
    }

    return [
        'files' => $files,
        'total_files' => count($files),
        'total_bytes' => $totalBytes,
    ];
}

/**
 * Read one reusable normalized manifest metadata entry.
 *
 * Authorization must already have been evaluated by the caller for the current
 * request. Cached metadata never substitutes for visitor authorization.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 * @param string $revision Content-only SHA-256 revision.
 * @return ?array{files:array<int,array{name:string,size:int,image_id:int,version:string}>,total_files:int,total_bytes:int}
 */
function download_manifest_cache_read(string $resourceType, int $resourceId, string $revision): ?array
{
    try {
        $path = download_manifest_cache_path($resourceType, $resourceId, $revision);
    } catch (Throwable) {
        download_manifest_profile_set_cache('bypass');
        return null;
    }
    download_manifest_profile_count('filesystem_checks');
    download_manifest_profile_count('filesystem_realpath_checks', 2);
    download_manifest_profile_count('filesystem_checks', 2);
    if (!is_file($path) || !path_inside(download_manifest_cache_root(), $path)) {
        download_manifest_profile_set_cache('miss');
        return null;
    }

    download_manifest_profile_count('filesystem_size_reads');
    download_manifest_profile_count('filesystem_checks', 2);
    $size = @filesize($path);
    if ($size === false || $size <= 0 || $size > download_manifest_cache_max_entry_bytes()) {
        @unlink($path);
        download_manifest_profile_set_cache('miss');
        return null;
    }
    $mtime = @filemtime($path);
    if ($mtime === false || $mtime < time() - download_manifest_cache_retention_seconds($resourceType)) {
        @unlink($path);
        download_manifest_profile_set_cache('miss');
        return null;
    }

    $json = @file_get_contents($path);
    if ($json === false || strlen($json) > download_manifest_cache_max_entry_bytes()) {
        download_manifest_profile_set_cache('miss');
        return null;
    }
    try {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        @unlink($path);
        download_manifest_profile_set_cache('miss');
        return null;
    }
    if (!is_array($decoded)
        || (int) ($decoded['format'] ?? 0) !== DOWNLOAD_MANIFEST_CACHE_FORMAT
        || (string) ($decoded['resource_type'] ?? '') !== $resourceType
        || (int) ($decoded['resource_id'] ?? 0) !== $resourceId
        || !hash_equals($revision, strtolower((string) ($decoded['revision'] ?? '')))) {
        @unlink($path);
        download_manifest_profile_set_cache('miss');
        return null;
    }

    $payload = download_manifest_cache_normalize_payload($decoded['payload'] ?? null);
    if ($payload === null) {
        @unlink($path);
        download_manifest_profile_set_cache('miss');
        return null;
    }

    download_manifest_profile_set_cache('hit');
    return $payload;
}

/**
 * Atomically publish one normalized capability-free manifest metadata entry.
 *
 * Cache write failure is intentionally non-fatal because the caller already has
 * a fully authorized in-memory manifest payload that can be returned directly.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 * @param string $revision Content-only SHA-256 revision.
 * @param array<string,mixed> $payload Candidate normalized metadata payload.
 */
function download_manifest_cache_write(string $resourceType, int $resourceId, string $revision, array $payload): void
{
    $payload = download_manifest_cache_normalize_payload($payload);
    if ($payload === null) {
        return;
    }

    try {
        $path = download_manifest_cache_path($resourceType, $resourceId, $revision);
        $encoded = json_encode([
            'format' => DOWNLOAD_MANIFEST_CACHE_FORMAT,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'revision' => $revision,
            'created_at' => time(),
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > download_manifest_cache_max_entry_bytes()) {
            return;
        }

        $partial = $path . '.partial-' . bin2hex(random_bytes(8));
        try {
            if (@file_put_contents($partial, $encoded, LOCK_EX) === false) {
                return;
            }
            @chmod($partial, 0664);
            if (is_file($path)) {
                @unlink($partial);
                return;
            }
            if (!@rename($partial, $path)) {
                @unlink($partial);
            }
        } finally {
            if (isset($partial) && is_file($partial)) {
                @unlink($partial);
            }
        }
    } catch (Throwable) {
        // Cache failure is an optimization failure only. The authorized manifest
        // already exists in memory and must remain usable without an unsafe fallback.
    }
}

/**
 * Invalidate one exact cached revision after a verified source snapshot mismatch.
 *
 * The caller must already have authorized the current source. The cache entry is
 * removed only when it itself confirms the expected image/version/size tuple, so
 * a capability holder cannot evict metadata merely by forging a size parameter.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 * @param string $revision Content-only SHA-256 revision from the generated source URL.
 * @param int $imageId Authorized source image identifier.
 * @param string $version Expected stable source version from the generated source URL.
 * @param int $expectedSize Expected source size from the generated source URL.
 * @param int $actualSize Current independently resolved source size.
 * @return bool True when the matching stale cache entry was removed.
 */
function download_manifest_cache_invalidate_source_mismatch(
    string $resourceType,
    int $resourceId,
    string $revision,
    int $imageId,
    string $version,
    int $expectedSize,
    int $actualSize
): bool {
    if ($expectedSize === $actualSize || $imageId <= 0 || $expectedSize < 0 || $actualSize < 0) {
        return false;
    }

    $revision = strtolower(trim($revision));
    $version = strtolower(trim($version));
    if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1 || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1) {
        return false;
    }

    $payload = download_manifest_cache_read($resourceType, $resourceId, $revision);
    if ($payload === null) {
        return false;
    }
    $matchesSnapshot = false;
    foreach ($payload['files'] as $file) {
        if ((int) $file['image_id'] === $imageId
            && hash_equals((string) $file['version'], $version)
            && (int) $file['size'] === $expectedSize) {
            $matchesSnapshot = true;
            break;
        }
    }
    if (!$matchesSnapshot) {
        return false;
    }

    try {
        $path = download_manifest_cache_path($resourceType, $resourceId, $revision);
        return is_file($path) && @unlink($path);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Remove cached revisions for one authorized resource after server-side revalidation fails.
 *
 * This is used only by the deliberate legacy POST builder after it has recomputed
 * actual source membership/bytes. It prevents a stale metadata entry from causing
 * the same controlled result-changed failure on every subsequent retry.
 *
 * @param string $resourceType Stable manifest resource type.
 * @param int $resourceId Authorized resource identifier.
 * @return int Number of managed metadata/partial files removed.
 */
function download_manifest_cache_invalidate_resource(string $resourceType, int $resourceId): int
{
    if ($resourceId <= 0) {
        return 0;
    }
    try {
        $resourceType = download_manifest_cache_resource_type($resourceType);
        $root = download_manifest_cache_root();
        $directory = $root . DIRECTORY_SEPARATOR . $resourceType . DIRECTORY_SEPARATOR . $resourceId;
        if (!is_dir($directory) || !path_inside($root, $directory)) {
            return 0;
        }
    } catch (Throwable) {
        return 0;
    }

    $deleted = 0;
    try {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (preg_match('/^[a-f0-9]{64}\.json$/D', $name) !== 1 && !str_contains($name, '.partial-')) {
                continue;
            }
            $path = $entry->getPathname();
            if (path_inside($root, $path) && @unlink($path)) {
                $deleted++;
            }
        }
        @rmdir($directory);
        @rmdir(dirname($directory));
    } catch (Throwable) {
        return $deleted;
    }
    return $deleted;
}

/**
 * Remove expired/corrupt manifest cache files with a bounded filesystem scan.
 *
 * @param ?int $now Optional deterministic Unix timestamp for maintenance/testing.
 * @return array{files_deleted:int,partials_deleted:int,entries_scanned:int,scan_truncated:bool}
 */
function cleanup_download_manifest_cache(?int $now = null): array
{
    $now ??= time();
    $result = ['files_deleted' => 0, 'partials_deleted' => 0, 'entries_scanned' => 0, 'scan_truncated' => false];
    try {
        $root = download_manifest_cache_root();
    } catch (Throwable) {
        return $result;
    }

    $maxScan = max(100, (int) cms_runtime_limit('download.manifest_cache_cleanup_max_entries'));
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($result['entries_scanned'] >= $maxScan) {
            $result['scan_truncated'] = true;
            break;
        }
        $result['entries_scanned']++;
        $path = $entry->getPathname();
        if ($entry->isDir()) {
            @rmdir($path);
            continue;
        }
        if (!$entry->isFile() || !path_inside($root, $path)) {
            continue;
        }

        $name = $entry->getFilename();
        if (str_contains($name, '.partial-')) {
            if ($entry->getMTime() < $now - 3600 && @unlink($path)) {
                $result['partials_deleted']++;
            }
            continue;
        }
        if (!preg_match('/^[a-f0-9]{64}\.json$/D', $name)) {
            if (@unlink($path)) {
                $result['files_deleted']++;
            }
            continue;
        }

        $resourceType = basename(dirname(dirname($path)));
        if (!in_array($resourceType, [DOWNLOAD_MANIFEST_RESOURCE_GALLERY, DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY], true)) {
            if (@unlink($path)) {
                $result['files_deleted']++;
            }
            continue;
        }
        if ($entry->getMTime() < $now - download_manifest_cache_retention_seconds($resourceType)
            && @unlink($path)) {
            $result['files_deleted']++;
        }
    }

    return $result;
}
