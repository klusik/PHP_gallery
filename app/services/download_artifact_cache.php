<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/download_artifact_cache.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides immutable managed storage for completed legacy server-download artifacts.
 *
 * Responsibilities:
 *   - Keep completed legacy ZIPs separate from transient/admin ZIP cache entries
 *   - Publish archive plus bounded metadata atomically under a canonical build identity
 *   - Enforce cache-budget and free-space safety before expensive archive creation
 *   - Coordinate active serving, build partials, reservations, and maintenance cleanup safely
 *   - Remove only managed legacy-download cache state
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
 *   - Artifact metadata never stores capability tokens, visitor identifiers, or filesystem paths.
 *   - The cache is an optimization only. Every request is authorized before artifact lookup.
 *
 * Last Updated:
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\path_inside;

const LEGACY_DOWNLOAD_ARTIFACT_CACHE_FORMAT_VERSION = 1;
const LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME = 'archive.zip';
const LEGACY_DOWNLOAD_ARTIFACT_METADATA_NAME = 'metadata.json';
const LEGACY_DOWNLOAD_ARTIFACT_LEASE_NAME = 'artifact.lock';

/**
 * Return the private managed root for immutable legacy download artifacts.
 *
 * The configured ZIP cache is already protected by cache/.htaccess on standard
 * installations. Keeping this subtree below it preserves that protection without
 * introducing a public route or predictable filesystem-backed URL.
 */
function legacy_download_artifact_cache_root(): string
{
    $path = zip_cache_dir() . DIRECTORY_SEPARATOR . 'legacy-artifacts';
    legacy_download_artifact_ensure_directory($path);

    // Preserve Apache denial even when a deployment points zip_cache_path at a
    // custom web-reachable directory rather than the standard protected cache/ tree.
    $guardPath = $path . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($guardPath) && @file_put_contents($guardPath, "Require all denied\n") === false) {
        throw new LegacyDownloadBuildException(
            'artifact_cache_guard_failed',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
    return $path;
}

/** Return the private internal-state directory used for cache reservations and capacity locking. */
function legacy_download_artifact_state_dir(): string
{
    $path = legacy_download_artifact_cache_root() . DIRECTORY_SEPARATOR . '.state';
    legacy_download_artifact_ensure_directory($path);
    return $path;
}

/** Ensure one managed cache directory exists or fail with a stable build error. */
function legacy_download_artifact_ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new LegacyDownloadBuildException(
            'artifact_cache_dir_unavailable',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
}

/** Normalize and validate the resource types allowed to create managed public legacy artifacts. */
function legacy_download_artifact_resource_type(string $resourceType): string
{
    $resourceType = trim($resourceType);
    if (!in_array($resourceType, ['gallery', 'smart_gallery'], true)) {
        throw new LegacyDownloadBuildException(
            'artifact_resource_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
    return $resourceType;
}

/** Validate one canonical SHA-256 identity field used in managed artifact paths. */
function legacy_download_artifact_sha256(string $value, string $reason): string
{
    $value = strtolower(trim($value));
    if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
        throw new LegacyDownloadBuildException(
            $reason,
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
    return $value;
}

/**
 * Return canonical filesystem paths for one immutable artifact identity.
 *
 * @return array{directory:string,archive:string,metadata:string,lease:string,parent:string}
 */
function legacy_download_artifact_paths(string $resourceType, int $resourceId, string $resourceRevision, string $buildKey): array
{
    $resourceType = legacy_download_artifact_resource_type($resourceType);
    $resourceRevision = legacy_download_artifact_sha256($resourceRevision, 'artifact_revision_invalid');
    $buildKey = legacy_download_artifact_sha256($buildKey, 'build_key_invalid');
    if ($resourceId <= 0) {
        throw new LegacyDownloadBuildException(
            'artifact_resource_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    $parent = legacy_download_artifact_cache_root()
        . DIRECTORY_SEPARATOR . $resourceType
        . DIRECTORY_SEPARATOR . (string) $resourceId
        . DIRECTORY_SEPARATOR . $resourceRevision;
    $directory = $parent . DIRECTORY_SEPARATOR . $buildKey;

    return [
        'directory' => $directory,
        'archive' => $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME,
        'metadata' => $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_METADATA_NAME,
        'lease' => $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_LEASE_NAME,
        'parent' => $parent,
    ];
}

/** Return the configured retention age for completed physical-gallery legacy artifacts. */
function legacy_download_artifact_physical_retention_seconds(): int
{
    return max(3600, (int) cms_runtime_limit('download.legacy_artifact_physical_retention_seconds'));
}

/** Return the configured retention age for result-fingerprinted Smart Gallery artifacts. */
function legacy_download_artifact_smart_retention_seconds(): int
{
    return max(3600, (int) cms_runtime_limit('download.legacy_artifact_smart_retention_seconds'));
}

/** Return the retention age for abandoned unpublished build directories. */
function legacy_download_artifact_partial_retention_seconds(): int
{
    return max(3600, (int) cms_runtime_limit('download.legacy_artifact_partial_retention_seconds'));
}

/** Return the safe age after which inactive coordination files may be removed. */
function legacy_download_artifact_lock_retention_seconds(): int
{
    return max(3600, (int) cms_runtime_limit('download.legacy_artifact_lock_retention_seconds'));
}

/** Return the maximum aggregate bytes allowed for managed completed artifacts and reservations. */
function legacy_download_artifact_cache_max_bytes(): int
{
    return max(64 * 1024 * 1024, (int) cms_runtime_limit('download.legacy_artifact_cache_max_bytes'));
}

/** Return the configured filesystem free-space floor preserved before a new legacy build starts. */
function legacy_download_artifact_free_space_margin_bytes(): int
{
    return max(16 * 1024 * 1024, (int) cms_runtime_limit('download.legacy_artifact_free_space_margin_bytes'));
}

/**
 * Return bounded metadata for a completed managed artifact, or null if any invariant fails.
 *
 * @return array<string,mixed>|null
 */
function legacy_download_artifact_metadata_read(array $paths): ?array
{
    $archive = (string) ($paths['archive'] ?? '');
    $metadataPath = (string) ($paths['metadata'] ?? '');
    if (!is_file($archive) || !is_file($metadataPath)) {
        return null;
    }

    $archiveSize = filesize($archive);
    if ($archiveSize === false || $archiveSize <= 0) {
        return null;
    }

    $raw = @file_get_contents($metadataPath);
    if (!is_string($raw) || $raw === '' || strlen($raw) > 8192) {
        return null;
    }

    try {
        $metadata = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($metadata)) {
        return null;
    }

    if ((int) ($metadata['format_version'] ?? 0) !== LEGACY_DOWNLOAD_ARTIFACT_CACHE_FORMAT_VERSION
        || preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['build_key'] ?? '')) !== 1
        || !in_array((string) ($metadata['resource_type'] ?? ''), ['gallery', 'smart_gallery'], true)
        || (int) ($metadata['resource_id'] ?? 0) <= 0
        || preg_match('/^[a-f0-9]{64}$/D', (string) ($metadata['resource_revision'] ?? '')) !== 1
        || (int) ($metadata['created_at'] ?? 0) <= 0
        || (int) ($metadata['archive_size'] ?? 0) !== (int) $archiveSize
        || (int) ($metadata['expected_file_count'] ?? -1) < 0) {
        return null;
    }

    return $metadata;
}

/** Return true only when the exact canonical identity has a fully published reusable artifact. */
function legacy_download_artifact_is_reusable(string $resourceType, int $resourceId, string $resourceRevision, string $buildKey): bool
{
    $paths = legacy_download_artifact_paths($resourceType, $resourceId, $resourceRevision, $buildKey);
    $metadata = legacy_download_artifact_metadata_read($paths);
    if ($metadata === null) {
        return false;
    }

    $retention = (string) $metadata['resource_type'] === 'smart_gallery'
        ? legacy_download_artifact_smart_retention_seconds()
        : legacy_download_artifact_physical_retention_seconds();
    if ((int) $metadata['created_at'] < time() - $retention) {
        return false;
    }

    return hash_equals($buildKey, (string) $metadata['build_key'])
        && hash_equals($resourceRevision, (string) $metadata['resource_revision'])
        && $resourceType === (string) $metadata['resource_type']
        && $resourceId === (int) $metadata['resource_id'];
}

/** Return the completed archive path for an exact reusable identity, otherwise null. */
function legacy_download_artifact_find(string $resourceType, int $resourceId, string $resourceRevision, string $buildKey): ?string
{
    if (!legacy_download_artifact_is_reusable($resourceType, $resourceId, $resourceRevision, $buildKey)) {
        return null;
    }
    return legacy_download_artifact_paths($resourceType, $resourceId, $resourceRevision, $buildKey)['archive'];
}

/** Return the number of ZIP entries expected from the normalized build-entry list. */
function legacy_download_artifact_expected_entry_count(array $entries): int
{
    $count = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $zipPath = trim((string) ($entry['zip_path'] ?? ''));
        if ($zipPath === '') {
            continue;
        }
        if (($entry['type'] ?? 'file') === 'directory') {
            $count++;
            continue;
        }
        $absolute = (string) ($entry['absolute'] ?? '');
        if ($absolute !== '' && is_file($absolute)) {
            $count++;
        }
    }
    return $count;
}

/** Verify the just-created ZIP has the exact expected entry count before publication. */
function legacy_download_artifact_verify_archive(string $archivePath, int $expectedEntryCount): int
{
    $archiveSize = is_file($archivePath) ? filesize($archivePath) : false;
    if ($archiveSize === false || $archiveSize <= 0) {
        throw new LegacyDownloadBuildException(
            'artifact_archive_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    if (!class_exists(ZipArchive::class)) {
        throw new LegacyDownloadBuildException(
            'zip_unavailable',
            t('download.error.zip_unavailable', 'ZipArchive is not available.')
        );
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
        throw new LegacyDownloadBuildException(
            'artifact_archive_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
    try {
        if ($zip->numFiles !== $expectedEntryCount) {
            throw new LegacyDownloadBuildException(
                'artifact_entry_count_mismatch',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }
    } finally {
        $zip->close();
    }

    return (int) $archiveSize;
}

/** Return a conservative reservation size for a store-only ZIP before it is created. */
function legacy_download_artifact_reservation_bytes(int $sourceBytes, int $fileCount): int
{
    $sourceBytes = max(0, $sourceBytes);
    $fileCount = max(0, $fileCount);
    $overhead = 1024 * 1024 + min(64 * 1024 * 1024, $fileCount * 1024);
    if ($sourceBytes > PHP_INT_MAX - $overhead) {
        return PHP_INT_MAX;
    }
    return max(1, $sourceBytes + $overhead);
}

/** Return a saturating integer sum for cache-byte accounting. */
function legacy_download_artifact_saturating_add(int $left, int $right): int
{
    $left = max(0, $left);
    $right = max(0, $right);
    if ($right > PHP_INT_MAX - $left) {
        return PHP_INT_MAX;
    }
    return $left + $right;
}

/** Return aggregate completed archive bytes under the managed artifact root. */
function legacy_download_artifact_completed_bytes(): int
{
    $root = legacy_download_artifact_cache_root();
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || $entry->getFilename() !== LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME) {
            continue;
        }
        $size = $entry->getSize();
        if ($size > PHP_INT_MAX - $bytes) {
            return PHP_INT_MAX;
        }
        $bytes += $size;
    }
    return $bytes;
}

/** Return aggregate active reservation bytes recorded by build owners. */
function legacy_download_artifact_reserved_bytes(?string $excludeBuildKey = null): int
{
    $stateDir = legacy_download_artifact_state_dir();
    $bytes = 0;
    foreach (glob($stateDir . DIRECTORY_SEPARATOR . 'reservation-*.json') ?: [] as $path) {
        $basename = basename($path);
        if (preg_match('/^reservation-([a-f0-9]{64})\.json$/D', $basename, $match) !== 1) {
            continue;
        }
        if ($excludeBuildKey !== null && hash_equals($excludeBuildKey, $match[1])) {
            continue;
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $reserved = is_array($data) ? max(0, (int) ($data['reserved_bytes'] ?? 0)) : 0;
        if ($reserved > PHP_INT_MAX - $bytes) {
            return PHP_INT_MAX;
        }
        $bytes += $reserved;
    }
    return $bytes;
}

/** Record one distinct operational capacity-refusal event without exposing cache paths. */
function legacy_download_artifact_log_capacity_refusal(string $resourceType, int $resourceId, string $reason, int $reservedBytes, int $managedBytes, int $budgetBytes, ?int $freeBytes): void
{
    if (!function_exists(__NAMESPACE__ . '\\admin_log_event')) {
        return;
    }

    admin_log_event('warning', 'download.legacy_cache_capacity_refused', 'Legacy download artifact build was refused by cache capacity policy.', [
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'reason' => $reason,
        'requested_reservation_bytes' => $reservedBytes,
        'managed_cache_bytes' => $managedBytes,
        'cache_budget_bytes' => $budgetBytes,
        'filesystem_free_bytes' => $freeBytes,
        'free_space_margin_bytes' => legacy_download_artifact_free_space_margin_bytes(),
    ], [
        'category' => 'security',
        'severity' => 'warning',
        'subject_type' => $resourceType,
        'subject_id' => $resourceId,
    ]);
}

/**
 * Reserve bounded managed-cache capacity before an expensive artifact build starts.
 *
 * @return string Absolute reservation-file path owned by this build.
 */
function legacy_download_artifact_capacity_reserve(string $resourceType, int $resourceId, string $buildKey, int $sourceBytes, int $fileCount): string
{
    $resourceType = legacy_download_artifact_resource_type($resourceType);
    $buildKey = legacy_download_artifact_sha256($buildKey, 'build_key_invalid');
    $reservedBytes = legacy_download_artifact_reservation_bytes($sourceBytes, $fileCount);
    $stateDir = legacy_download_artifact_state_dir();
    $capacityLockPath = $stateDir . DIRECTORY_SEPARATOR . 'capacity.lock';
    $capacityHandle = @fopen($capacityLockPath, 'c');
    if ($capacityHandle === false) {
        throw new LegacyDownloadBuildException(
            'artifact_capacity_lock_failed',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    try {
        if (!@flock($capacityHandle, LOCK_EX | LOCK_NB)) {
            throw new LegacyDownloadBuildBusyException(
                'artifact_capacity_check_busy',
                t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'),
                legacy_download_busy_retry_after_seconds()
            );
        }

        $managedBytes = legacy_download_artifact_saturating_add(legacy_download_artifact_completed_bytes(), legacy_download_artifact_reserved_bytes($buildKey));
        $budgetBytes = legacy_download_artifact_cache_max_bytes();
        if ($reservedBytes > $budgetBytes || $managedBytes > $budgetBytes - $reservedBytes) {
            legacy_download_artifact_log_capacity_refusal($resourceType, $resourceId, 'cache_budget_exceeded', $reservedBytes, $managedBytes, $budgetBytes, null);
            throw new LegacyDownloadBuildCapacityException(
                'artifact_cache_budget_exceeded',
                t('download.progress.legacy_capacity', 'The server download cache is temporarily full. Please retry later or use the browser download method.')
            );
        }

        $freeRaw = @disk_free_space(legacy_download_artifact_cache_root());
        $freeBytes = is_float($freeRaw) || is_int($freeRaw) ? max(0, (int) $freeRaw) : null;
        $marginBytes = legacy_download_artifact_free_space_margin_bytes();
        if ($freeBytes !== null && ($reservedBytes > $freeBytes || $marginBytes > $freeBytes - $reservedBytes)) {
            legacy_download_artifact_log_capacity_refusal($resourceType, $resourceId, 'free_space_margin', $reservedBytes, $managedBytes, $budgetBytes, $freeBytes);
            throw new LegacyDownloadBuildCapacityException(
                'artifact_free_space_margin',
                t('download.progress.legacy_capacity', 'The server download cache is temporarily full. Please retry later or use the browser download method.')
            );
        }

        $reservationPath = $stateDir . DIRECTORY_SEPARATOR . 'reservation-' . $buildKey . '.json';
        $payload = json_encode([
            'build_key' => $buildKey,
            'reserved_bytes' => $reservedBytes,
            'created_at' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($reservationPath, $payload, LOCK_EX) === false) {
            throw new LegacyDownloadBuildException(
                'artifact_reservation_write_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }
        return $reservationPath;
    } finally {
        @flock($capacityHandle, LOCK_UN);
        fclose($capacityHandle);
    }
}

/** Release one owned cache-capacity reservation. */
function legacy_download_artifact_capacity_release(?string $reservationPath): void
{
    if (!is_string($reservationPath) || $reservationPath === '' || !is_file($reservationPath)) {
        return;
    }
    if (path_inside(legacy_download_artifact_state_dir(), $reservationPath)) {
        @unlink($reservationPath);
    }
}

/** Remove a managed directory tree only after confirming it resolves below the artifact root. */
function legacy_download_artifact_remove_tree(string $directory): bool
{
    $root = legacy_download_artifact_cache_root();
    if (!is_dir($directory) || !path_inside($root, $directory)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($directory);
}

/** Acquire a non-blocking exclusive artifact lease so an invalid old destination can be replaced safely. */
function legacy_download_artifact_remove_invalid_destination(array $paths): void
{
    $directory = (string) ($paths['directory'] ?? '');
    if (!is_dir($directory)) {
        return;
    }

    $leasePath = (string) ($paths['lease'] ?? '');
    $handle = @fopen($leasePath, 'c');
    if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new LegacyDownloadBuildBusyException(
            'artifact_in_use',
            t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'),
            legacy_download_busy_retry_after_seconds()
        );
    }

    $tombstone = dirname($directory) . DIRECTORY_SEPARATOR . '.stale-' . basename($directory) . '-' . bin2hex(random_bytes(6));
    try {
        if (!@rename($directory, $tombstone)) {
            throw new LegacyDownloadBuildException(
                'artifact_invalid_remove_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    if (is_dir($tombstone)) {
        legacy_download_artifact_remove_tree($tombstone);
    }
}

/**
 * Build and atomically publish one immutable legacy artifact plus metadata.
 *
 * Publication renames a complete sibling directory only after create_zip() has
 * closed the archive, archive sanity succeeds, and bounded metadata is durable.
 */
function legacy_download_artifact_build(
    string $resourceType,
    int $resourceId,
    string $resourceRevision,
    string $buildKey,
    array $entries,
    int $sourceBytes,
    int $expectedFileCount
): string {
    $paths = legacy_download_artifact_paths($resourceType, $resourceId, $resourceRevision, $buildKey);
    $existing = legacy_download_artifact_find($resourceType, $resourceId, $resourceRevision, $buildKey);
    if ($existing !== null) {
        return $existing;
    }

    legacy_download_artifact_ensure_directory($paths['parent']);
    if (is_dir($paths['directory'])) {
        legacy_download_artifact_remove_invalid_destination($paths);
    }

    $reservationPath = null;
    $partialDirectory = $paths['parent'] . DIRECTORY_SEPARATOR . '.' . $buildKey . '.partial-' . bin2hex(random_bytes(8));
    legacy_download_artifact_ensure_directory($partialDirectory);
    $partialArchive = $partialDirectory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME;
    $partialMetadata = $partialDirectory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_METADATA_NAME;
    $partialLease = $partialDirectory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_LEASE_NAME;

    try {
        $reservationPath = legacy_download_artifact_capacity_reserve($resourceType, $resourceId, $buildKey, $sourceBytes, $expectedFileCount);
        $expectedEntryCount = legacy_download_artifact_expected_entry_count($entries);
        create_zip($partialArchive, $entries);
        $archiveSize = legacy_download_artifact_verify_archive($partialArchive, $expectedEntryCount);

        if (@file_put_contents($partialLease, '') === false) {
            throw new LegacyDownloadBuildException(
                'artifact_lease_create_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }

        $metadata = [
            'format_version' => LEGACY_DOWNLOAD_ARTIFACT_CACHE_FORMAT_VERSION,
            'build_key' => $buildKey,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'resource_revision' => $resourceRevision,
            'created_at' => time(),
            'archive_size' => $archiveSize,
            'expected_file_count' => max(0, $expectedFileCount),
        ];
        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $metadataTemp = $partialMetadata . '.tmp';
        if (@file_put_contents($metadataTemp, $metadataJson, LOCK_EX) === false || !@rename($metadataTemp, $partialMetadata)) {
            @unlink($metadataTemp);
            throw new LegacyDownloadBuildException(
                'artifact_metadata_write_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }

        $partialPaths = [
            'archive' => $partialArchive,
            'metadata' => $partialMetadata,
        ];
        if (legacy_download_artifact_metadata_read($partialPaths) === null) {
            throw new LegacyDownloadBuildException(
                'artifact_metadata_invalid',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }

        if (!@rename($partialDirectory, $paths['directory'])) {
            $winner = legacy_download_artifact_find($resourceType, $resourceId, $resourceRevision, $buildKey);
            if ($winner !== null) {
                return $winner;
            }
            throw new LegacyDownloadBuildException(
                'artifact_publish_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }
        $partialDirectory = '';

        $published = legacy_download_artifact_find($resourceType, $resourceId, $resourceRevision, $buildKey);
        if ($published === null) {
            throw new LegacyDownloadBuildException(
                'artifact_publish_validation_failed',
                t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
            );
        }
        return $published;
    } finally {
        legacy_download_artifact_capacity_release($reservationPath);
        if ($partialDirectory !== '' && is_dir($partialDirectory)) {
            legacy_download_artifact_remove_tree($partialDirectory);
        }
    }
}

/**
 * Stream one managed artifact while holding a shared lease against maintenance deletion.
 *
 * Authorization has already happened in the controller before this function is
 * called. The lease controls only cache lifecycle, never access authorization.
 */
function send_legacy_download_artifact(string $filePath, string $downloadName): never
{
    $root = legacy_download_artifact_cache_root();
    if (!is_file($filePath) || basename($filePath) !== LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME || !path_inside($root, $filePath)) {
        http_response_code(404);
        exit(t('download.error.not_found', 'Download not found.'));
    }

    $leasePath = dirname($filePath) . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_LEASE_NAME;
    $leaseHandle = @fopen($leasePath, 'c');
    if ($leaseHandle === false || !@flock($leaseHandle, LOCK_SH)) {
        if (is_resource($leaseHandle)) {
            fclose($leaseHandle);
        }
        http_response_code(503);
        header('Retry-After: ' . legacy_download_busy_retry_after_seconds());
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        exit(t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'));
    }

    $size = filesize($filePath);
    if ($size === false || $size <= 0) {
        @flock($leaseHandle, LOCK_UN);
        fclose($leaseHandle);
        http_response_code(404);
        exit(t('download.error.not_found', 'Download not found.'));
    }

    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . $size);
    readfile($filePath);
    @flock($leaseHandle, LOCK_UN);
    fclose($leaseHandle);
    exit;
}

/** Try to remove one expired completed artifact only if no request is serving it. */
function legacy_download_artifact_cleanup_completed_directory(string $directory, int $cutoff): bool
{
    $metadataPath = $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_METADATA_NAME;
    $archivePath = $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_ARCHIVE_NAME;
    $leasePath = $directory . DIRECTORY_SEPARATOR . LEGACY_DOWNLOAD_ARTIFACT_LEASE_NAME;
    $metadata = legacy_download_artifact_metadata_read(['archive' => $archivePath, 'metadata' => $metadataPath]);
    $createdAt = is_array($metadata) ? (int) ($metadata['created_at'] ?? 0) : 0;
    $mtime = @filemtime($directory) ?: 0;
    $ageAnchor = $createdAt > 0 ? $createdAt : $mtime;
    if ($ageAnchor > 0 && $ageAnchor >= $cutoff) {
        return false;
    }

    $handle = @fopen($leasePath, 'c');
    if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return false;
    }

    $tombstone = dirname($directory) . DIRECTORY_SEPARATOR . '.expired-' . basename($directory) . '-' . bin2hex(random_bytes(6));
    $renamed = false;
    try {
        $renamed = @rename($directory, $tombstone);
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
    if (!$renamed) {
        return false;
    }

    if (is_dir($tombstone)) {
        legacy_download_artifact_remove_tree($tombstone);
    }
    return true;
}

/** Remove empty resource/revision parent directories left after eligible artifact cleanup. */
function legacy_download_artifact_cleanup_empty_parents(string $root): void
{
    foreach (['gallery', 'smart_gallery'] as $resourceType) {
        $typeDir = $root . DIRECTORY_SEPARATOR . $resourceType;
        if (!is_dir($typeDir)) {
            continue;
        }
        $resourceDirs = glob($typeDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($resourceDirs as $resourceDir) {
            $revisionDirs = glob($resourceDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            foreach ($revisionDirs as $revisionDir) {
                $entries = @scandir($revisionDir);
                if (is_array($entries) && count($entries) === 2) {
                    @rmdir($revisionDir);
                }
            }
            $entries = @scandir($resourceDir);
            if (is_array($entries) && count($entries) === 2) {
                @rmdir($resourceDir);
            }
        }
        $entries = @scandir($typeDir);
        if (is_array($entries) && count($entries) === 2) {
            @rmdir($typeDir);
        }
    }
}

/**
 * Run bounded-safe cleanup for managed legacy download artifacts and coordination state.
 *
 * Only known files below legacy-artifacts/ and inactive Stage 5 build-lock files are
 * considered. Active serving leases, active build locks, and active reservations are skipped.
 *
 * @return array<string,int>
 */
function cleanup_legacy_download_artifact_cache(?int $now = null): array
{
    $now = $now ?? time();
    $root = legacy_download_artifact_cache_root();
    $deletedArtifacts = 0;
    $deletedPartials = 0;
    $deletedReservations = 0;
    $deletedBuildLocks = 0;

    foreach (['gallery' => legacy_download_artifact_physical_retention_seconds(), 'smart_gallery' => legacy_download_artifact_smart_retention_seconds()] as $resourceType => $retention) {
        $typeDir = $root . DIRECTORY_SEPARATOR . $resourceType;
        if (!is_dir($typeDir)) {
            continue;
        }
        $resourceDirs = glob($typeDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        foreach ($resourceDirs as $resourceDir) {
            $revisionDirs = glob($resourceDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
            foreach ($revisionDirs as $revisionDir) {
                $children = glob($revisionDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
                foreach ($children as $artifactDir) {
                    $basename = basename($artifactDir);
                    if (preg_match('/^[a-f0-9]{64}$/D', $basename) !== 1) {
                        continue;
                    }
                    if (legacy_download_artifact_cleanup_completed_directory($artifactDir, $now - $retention)) {
                        $deletedArtifacts++;
                    }
                }

                foreach (glob($revisionDir . DIRECTORY_SEPARATOR . '.*.partial-*', GLOB_ONLYDIR) ?: [] as $partialDir) {
                    $basename = basename($partialDir);
                    if (preg_match('/^\.([a-f0-9]{64})\.partial-[a-f0-9]+$/D', $basename, $match) !== 1) {
                        continue;
                    }
                    $mtime = @filemtime($partialDir) ?: 0;
                    if ($mtime <= 0 || $mtime >= $now - legacy_download_artifact_partial_retention_seconds()) {
                        continue;
                    }
                    $buildHandle = null;
                    try {
                        $buildHandle = legacy_download_build_lock_acquire($match[1]);
                    } catch (LegacyDownloadBuildBusyException) {
                        continue;
                    } catch (Throwable) {
                        continue;
                    }
                    try {
                        if (legacy_download_artifact_remove_tree($partialDir)) {
                            $deletedPartials++;
                        }
                    } finally {
                        legacy_download_build_lock_release($buildHandle);
                    }
                }

                // A tombstone exists only after an exclusive artifact lease successfully
                // detached an invalid/expired final directory. Retry deletion later if the
                // original request could not remove every file immediately.
                $tombstones = array_merge(
                    glob($revisionDir . DIRECTORY_SEPARATOR . '.expired-*', GLOB_ONLYDIR) ?: [],
                    glob($revisionDir . DIRECTORY_SEPARATOR . '.stale-*', GLOB_ONLYDIR) ?: []
                );
                foreach ($tombstones as $tombstone) {
                    $mtime = @filemtime($tombstone) ?: 0;
                    if ($mtime > 0 && $mtime < $now - legacy_download_artifact_partial_retention_seconds()) {
                        legacy_download_artifact_remove_tree($tombstone);
                    }
                }
            }
        }
    }

    $stateDir = legacy_download_artifact_state_dir();
    foreach (glob($stateDir . DIRECTORY_SEPARATOR . 'reservation-*.json') ?: [] as $reservationPath) {
        if (preg_match('/^reservation-([a-f0-9]{64})\.json$/D', basename($reservationPath), $match) !== 1) {
            continue;
        }
        $mtime = @filemtime($reservationPath) ?: 0;
        if ($mtime <= 0 || $mtime >= $now - legacy_download_artifact_lock_retention_seconds()) {
            continue;
        }
        $buildHandle = null;
        try {
            $buildHandle = legacy_download_build_lock_acquire($match[1]);
        } catch (LegacyDownloadBuildBusyException) {
            continue;
        } catch (Throwable) {
            continue;
        }
        try {
            if (@unlink($reservationPath)) {
                $deletedReservations++;
            }
        } finally {
            legacy_download_build_lock_release($buildHandle);
        }
    }

    $buildStateDir = legacy_download_build_state_dir();
    foreach (glob($buildStateDir . DIRECTORY_SEPARATOR . 'build-*.lock') ?: [] as $lockPath) {
        if (preg_match('/^build-([a-f0-9]{64})\.lock$/D', basename($lockPath), $match) !== 1) {
            continue;
        }
        $mtime = @filemtime($lockPath) ?: 0;
        if ($mtime <= 0 || $mtime >= $now - legacy_download_artifact_lock_retention_seconds()) {
            continue;
        }
        $handle = @fopen($lockPath, 'c');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            continue;
        }
        $tombstone = $lockPath . '.stale-' . bin2hex(random_bytes(6));
        $renamed = @rename($lockPath, $tombstone);
        @flock($handle, LOCK_UN);
        fclose($handle);
        if ($renamed) {
            @unlink($tombstone);
            $deletedBuildLocks++;
        }
    }

    legacy_download_artifact_cleanup_empty_parents($root);

    return [
        'artifacts_deleted' => $deletedArtifacts,
        'partials_deleted' => $deletedPartials,
        'reservations_deleted' => $deletedReservations,
        'build_locks_deleted' => $deletedBuildLocks,
    ];
}
