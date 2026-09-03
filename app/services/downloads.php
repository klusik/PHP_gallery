<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/downloads.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use DirectoryIterator;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\slugify;
use function Gallery\Core\image_public_asset_version;
use function Gallery\Core\url_for;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_zip_signature;
use function Gallery\Services\image_abs_path;
use function Gallery\Services\gallery_abs_path;
use function Gallery\Services\image_public_display_file;
use function Gallery\Services\picture_manager_normalize_image_ids;
use function Gallery\Services\picture_manager_owned_images_for_selection;
use function Gallery\Services\public_image_visible_to_current_visitor;
use function Gallery\Services\t;
use function Gallery\Services\visitor_can_access_gallery;


/**
 * Stable public gallery-download initialization failure.
 */
final class GalleryDownloadManifestException extends RuntimeException
{
    private string $reason;

    /**
     * Create one stable manifest-preparation failure.
     *
     * @param string $message Visitor-safe localized message.
     * @param string $reason Stable internal diagnostic reason.
     */
    public function __construct(string $message, string $reason = 'manifest_failed')
    {
        parent::__construct($message);
        $this->reason = preg_match('/^[a-z_]{1,48}$/D', $reason) === 1 ? $reason : 'manifest_failed';
    }

    /** Return the stable diagnostic reason without parsing localized text. */
    public function reason(): string
    {
        return $this->reason;
    }
}

/**
 * Stable archive-writer failure shared by gallery ZIP builders.
 */
final class GalleryZipBuildException extends RuntimeException
{
    private string $reason;

    /**
     * Create one archive-writer failure.
     *
     * @param string $reason Stable internal diagnostic reason.
     * @param string $message Visitor-safe localized message.
     */
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = preg_match('/^[a-z_]{1,48}$/D', $reason) === 1 ? $reason : 'archive_build_failed';
    }

    /** Return the stable diagnostic reason without parsing localized text. */
    public function reason(): string
    {
        return $this->reason;
    }
}

/**
 * Stable coordination failure for bounded legacy server ZIP preparation.
 */
class LegacyDownloadBuildException extends RuntimeException
{
    private string $reason;

    /**
     * Create one stable legacy-build coordination failure.
     *
     * @param string $reason Stable internal diagnostic reason.
     * @param string $message Visitor-safe localized message.
     */
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = preg_match('/^[a-z_]{1,48}$/D', $reason) === 1 ? $reason : 'legacy_build_failed';
    }

    /** Return the stable diagnostic reason without parsing localized text. */
    public function reason(): string
    {
        return $this->reason;
    }
}

/**
 * Retryable legacy-build refusal when another build owns the required capacity.
 */
final class LegacyDownloadBuildBusyException extends LegacyDownloadBuildException
{
    private int $retryAfterSeconds;

    /**
     * Create one retryable build-admission refusal.
     *
     * @param string $reason Stable internal diagnostic reason.
     * @param string $message Visitor-safe localized message.
     * @param int $retryAfterSeconds Retry-After delay advertised to the client.
     */
    public function __construct(string $reason, string $message, int $retryAfterSeconds)
    {
        parent::__construct($reason, $message);
        $this->retryAfterSeconds = max(1, min(60, $retryAfterSeconds));
    }

    /** Return the bounded Retry-After delay in seconds. */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}

/**
 * Stable non-retry-immediate refusal when the managed legacy artifact cache cannot
 * safely reserve enough configured capacity or filesystem free space.
 */
final class LegacyDownloadBuildCapacityException extends LegacyDownloadBuildException
{
}

/** Return a stable reason for generic physical-gallery legacy ZIP failures. */
function gallery_zip_failure_reason(Throwable $exception): string
{
    if ($exception instanceof GalleryZipBuildException || $exception instanceof LegacyDownloadBuildException) {
        return $exception->reason();
    }
    return 'archive_build_failed';
}

/**
 * Normalize one archive-relative path for a browser-generated ZIP.
 *
 * The database/filesystem path is never returned verbatim without this second
 * ZIP-specific normalization. Control characters, Windows drive separators,
 * traversal segments, and empty segments are removed or replaced while UTF-8
 * display names remain intact.
 */
function gallery_download_safe_zip_path(string $path, string $fallback = 'photo'): string
{
    $path = str_replace('\\', '/', $path);
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment) ?? '';
        $segment = str_replace(':', '_', trim($segment));
        $segment = trim($segment, " .\t\n\r\0\x0B");
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = $segment;
    }
    if ($segments === []) {
        return $fallback;
    }
    return implode('/', $segments);
}

/**
 * Make one archive entry path unique using deterministic numeric suffixes.
 *
 * @param array<string,bool> $usedNames Case-insensitive entry-name set.
 */
function gallery_download_unique_zip_path(string $path, array &$usedNames): string
{
    $path = gallery_download_safe_zip_path($path);
    $directory = dirname($path);
    $directory = $directory === '.' ? '' : $directory . '/';
    $basename = basename($path);
    $extension = pathinfo($basename, PATHINFO_EXTENSION);
    $stem = pathinfo($basename, PATHINFO_FILENAME);
    if ($stem === '') {
        $stem = 'photo';
    }
    $suffix = $extension !== '' ? '.' . $extension : '';
    $candidate = $directory . $stem . $suffix;
    $counter = 2;
    while (isset($usedNames[strtolower($candidate)])) {
        $candidate = $directory . $stem . '-' . $counter . $suffix;
        $counter++;
    }
    $usedNames[strtolower($candidate)] = true;
    return $candidate;
}

/**
 * Return a bounded, authorized gallery subtree for one browser download manifest.
 *
 * This mirrors gallery_zip_gallery_rows() but caps the raw SQL result before
 * fetchAll() can materialize an arbitrarily large subtree.
 *
 * @param array<string,mixed> $gallery Root gallery row.
 * @return array<int,array<string,mixed>> Authorized gallery rows.
 */
function gallery_download_gallery_rows(array $gallery): array
{
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    if ($folderPath === '') {
        return [];
    }

    $manifestGalleryLimit = max(1, (int) cms_runtime_limit('download.manifest_max_galleries'));
    $limit = $manifestGalleryLimit + 1;
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id LIMIT ' . $limit);
    $stmt->execute([$folderPath, $folderPath . '/%']);
    $candidates = $stmt->fetchAll();
    download_manifest_profile_count('gallery_rows', count($candidates));
    if (count($candidates) > $manifestGalleryLimit) {
        throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
    }

    $rows = [];
    foreach ($candidates as $candidate) {
        if (visitor_can_access_gallery($candidate)) {
            $rows[] = $candidate;
        }
    }
    return $rows;
}

/**
 * Return the bounded image rows authorized for one physical-gallery manifest.
 *
 * This step intentionally uses only database metadata plus the canonical visitor
 * visibility checks. Filesystem existence/containment/size work is deferred until
 * a revision-keyed cache miss requires normalized manifest metadata to be built.
 *
 * @param array<int,array<string,mixed>> $galleries Authorized gallery subtree rows.
 * @return array<int,array{gallery:array<string,mixed>,image:array<string,mixed>}> Authorized manifest items.
 */
function gallery_download_manifest_authorized_items(array $galleries): array
{
    $galleryIds = gallery_zip_gallery_ids($galleries);
    if ($galleryIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $manifestFileLimit = max(1, (int) cms_runtime_limit('download.manifest_max_files'));
    $limit = $manifestFileLimit + 1;
    $stmt = db()->prepare("SELECT * FROM images WHERE gallery_id IN ($placeholders) AND visibility = 'public' ORDER BY gallery_id, sort_order, filename, relative_path, id LIMIT $limit");
    $stmt->execute($galleryIds);
    $candidateImages = $stmt->fetchAll();
    download_manifest_profile_count('image_rows', count($candidateImages));
    if (count($candidateImages) > $manifestFileLimit) {
        throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
    }

    $imagesByGallery = [];
    foreach ($candidateImages as $image) {
        $imagesByGallery[(int) $image['gallery_id']][] = $image;
    }

    $items = [];
    foreach ($galleries as $sourceGallery) {
        foreach ($imagesByGallery[(int) $sourceGallery['id']] ?? [] as $image) {
            if (!public_image_visible_to_current_visitor($image, $sourceGallery)) {
                continue;
            }
            if (count($items) >= $manifestFileLimit) {
                throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
            }
            $items[] = ['gallery' => $sourceGallery, 'image' => $image];
        }
    }
    return $items;
}

/**
 * Build the physical-manifest content revision from already-authorized database metadata.
 *
 * The identity excludes capability tokens, request/query data, client identity, and
 * filesystem paths. Current request authorization has already reduced the item set,
 * while source version/path/size metadata describes only content that affects the
 * progressive ZIP manifest itself.
 *
 * @param int $rootGalleryId Authorized root gallery identifier.
 * @param array<int,array{gallery:array<string,mixed>,image:array<string,mixed>}> $items Authorized ordered manifest items.
 * @return string Content-only SHA-256 manifest revision.
 */
function gallery_download_manifest_revision(int $rootGalleryId, array $items): string
{
    $identity = [];
    foreach ($items as $item) {
        $sourceGallery = $item['gallery'];
        $image = $item['image'];
        $identity[] = [
            'image_id' => (int) ($image['id'] ?? 0),
            'gallery_id' => (int) ($sourceGallery['id'] ?? 0),
            'source_folder' => normalize_relative_path((string) ($sourceGallery['folder_path'] ?? '')),
            'relative_path' => normalize_relative_path((string) ($image['relative_path'] ?? '')),
            'source_version' => image_public_asset_version($image),
            'database_size' => max(0, (int) ($image['file_size'] ?? 0)),
        ];
    }

    return hash('sha256', json_encode([
        'format' => 'physical-progressive-manifest-v1',
        'root_gallery_id' => $rootGalleryId,
        'items' => $identity,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Build normalized capability-free manifest metadata after a cache miss.
 *
 * Source existence, containment, and byte size are deliberately verified here.
 * Cache hits avoid repeating this per-source realpath/stat work, while every later
 * source request independently re-authorizes and re-resolves the source path again.
 *
 * @param array<int,array{gallery:array<string,mixed>,image:array<string,mixed>}> $items
 * @return array{files:array<int,array{name:string,size:int,image_id:int,version:string}>,total_files:int,total_bytes:int}
 */
function gallery_download_manifest_build_cached_payload(array $items): array
{
    $files = [];
    $usedNames = [];
    $totalBytes = 0;
    foreach ($items as $item) {
        $sourceGallery = $item['gallery'];
        $image = $item['image'];
        $absolute = image_abs_path($image, $sourceGallery);
        $galleryRoot = gallery_abs_path((string) $sourceGallery['folder_path']);

        download_manifest_profile_count('filesystem_checks');
        download_manifest_profile_count('filesystem_realpath_checks', 2);
        download_manifest_profile_count('filesystem_checks', 2);
        if (!is_file($absolute) || !path_inside($galleryRoot, $absolute)) {
            throw new GalleryDownloadManifestException(t('download.progress.source_missing', 'A source file required for this download is unavailable.'), 'source_missing');
        }
        download_manifest_profile_count('filesystem_size_reads');
        download_manifest_profile_count('filesystem_checks');
        $size = filesize($absolute);
        if ($size === false || $size < 0) {
            throw new GalleryDownloadManifestException(t('download.progress.source_size_failed', 'A source file size could not be determined.'), 'source_size_failed');
        }
        if ($size > PHP_INT_MAX - $totalBytes) {
            throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
        }

        $relativePath = normalize_relative_path((string) $image['relative_path']);
        $zipPath = gallery_download_unique_zip_path((string) $sourceGallery['folder_path'] . '/' . $relativePath, $usedNames);
        $files[] = [
            'name' => $zipPath,
            'size' => (int) $size,
            'image_id' => (int) $image['id'],
            'version' => image_public_asset_version($image),
        ];
        $totalBytes += (int) $size;
    }

    return [
        'files' => $files,
        'total_files' => count($files),
        'total_bytes' => $totalBytes,
    ];
}

/**
 * Add current-request source URLs to normalized cached metadata.
 *
 * Bearer capabilities are injected only here and are therefore never persisted
 * in a global reusable cache file.
 *
 * @param array{files:array<int,array{name:string,size:int,image_id:int,version:string}>,total_files:int,total_bytes:int} $payload Normalized cached manifest metadata.
 * @param string $sourceRoute Progressive source route name.
 * @param string $resourceIdKey Source-route resource-ID parameter name.
 * @param int $resourceId Authorized resource identifier.
 * @param string $manifestRevision Content-only revision for stale-source self-healing.
 * @param ?string $sourceCapability Optional query-transport compatibility capability.
 * @return array<int,array{name:string,size:int,url:string}> Browser-safe response files.
 */
function gallery_download_manifest_response_files(array $payload, string $sourceRoute, string $resourceIdKey, int $resourceId, string $manifestRevision, ?string $sourceCapability): array
{
    $files = [];
    foreach ($payload['files'] as $file) {
        $sourceParams = [
            $resourceIdKey => $resourceId,
            'image_id' => (int) $file['image_id'],
            'v' => (string) $file['version'],
            'mr' => $manifestRevision,
            's' => (int) $file['size'],
        ];
        if ($sourceCapability !== null && $sourceCapability !== '') {
            $sourceParams['capability'] = $sourceCapability;
        }
        $files[] = [
            'name' => (string) $file['name'],
            'size' => (int) $file['size'],
            'url' => url_for($sourceRoute, $sourceParams),
        ];
    }
    return $files;
}

/**
 * Return the authorized source-file manifest used by the browser ZIP builder.
 *
 * Repeated requests first derive a content-only revision from bounded database
 * metadata and current visitor authorization. A cache hit then avoids per-source
 * filesystem traversal/stat work. Cached data never contains a capability token,
 * visitor identifier, host name, or arbitrary query parameter.
 *
 * @param array<string,mixed> $gallery Authorized root gallery row.
 * @return array<string,mixed> Browser-safe manifest.
 */
function gallery_download_manifest(array $gallery, ?string $sourceCapability = null): array
{
    if (!visitor_can_access_gallery($gallery)) {
        throw new GalleryDownloadManifestException(t('download.progress.not_available', 'This gallery is not available for download.'), 'not_available');
    }

    $galleryId = (int) ($gallery['id'] ?? 0);
    $galleries = gallery_download_gallery_rows($gallery);
    $items = gallery_download_manifest_authorized_items($galleries);
    $revision = gallery_download_manifest_revision($galleryId, $items);
    $payload = download_manifest_cache_read(DOWNLOAD_MANIFEST_RESOURCE_GALLERY, $galleryId, $revision);
    if ($payload === null) {
        $payload = gallery_download_manifest_build_cached_payload($items);
        download_manifest_cache_write(DOWNLOAD_MANIFEST_RESOURCE_GALLERY, $galleryId, $revision, $payload);
    }

    $downloadName = slugify((string) ($gallery['title'] ?? ''));
    if ($downloadName === '') {
        $downloadName = 'gallery-' . $galleryId;
    }

    return [
        'ok' => true,
        'filename' => $downloadName . '.zip',
        'files' => gallery_download_manifest_response_files($payload, 'download_gallery_file', 'gallery_id', $galleryId, $revision, $sourceCapability),
        'total_files' => (int) $payload['total_files'],
        'total_bytes' => (int) $payload['total_bytes'],
        'memory_fallback_warning_bytes' => max(1, (int) cms_runtime_limit('download.memory_fallback_warning_bytes')),
        'memory_fallback_max_bytes' => max(1, (int) cms_runtime_limit('download.memory_fallback_max_bytes')),
        'zip64' => true,
    ];
}

/**
 * Return whether a no-JavaScript legacy server ZIP is small enough to build safely.
 *
 * @param array<string,mixed> $manifest Browser manifest for the same gallery.
 */
function gallery_download_legacy_manifest_is_safe(array $manifest): bool
{
    return (int) ($manifest['total_files'] ?? 0) <= max(1, (int) cms_runtime_limit('download.legacy_max_files'))
        && (int) ($manifest['total_bytes'] ?? 0) <= max(1, (int) cms_runtime_limit('download.legacy_max_source_bytes'));
}

/**
 * Resolve and authorize one original source file referenced by a gallery manifest.
 *
 * @return array{gallery:array<string,mixed>,image:array<string,mixed>,path:string,filename:string,size:int,version:string}|null
 */
function gallery_download_authorized_source(int $rootGalleryId, int $imageId): ?array
{
    $rootGallery = find_gallery($rootGalleryId);
    $image = find_image($imageId);
    if (!$rootGallery || !$image || !visitor_can_access_gallery($rootGallery)) {
        return null;
    }
    $sourceGallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    if (!$sourceGallery || !public_image_visible_to_current_visitor($image, $sourceGallery)) {
        return null;
    }

    $rootFolder = normalize_relative_path((string) ($rootGallery['folder_path'] ?? ''));
    $sourceFolder = normalize_relative_path((string) ($sourceGallery['folder_path'] ?? ''));
    if ($sourceFolder !== $rootFolder
        && ($rootFolder === '' || !str_starts_with($sourceFolder, $rootFolder . '/'))) {
        return null;
    }

    $path = image_abs_path($image, $sourceGallery);
    $galleryRoot = gallery_abs_path((string) $sourceGallery['folder_path']);
    if (!is_file($path) || !path_inside($galleryRoot, $path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size < 0) {
        return null;
    }

    return [
        'gallery' => $sourceGallery,
        'image' => $image,
        'path' => $path,
        'filename' => basename((string) ($image['filename'] ?? $path)),
        'size' => (int) $size,
        'version' => image_public_asset_version($image),
    ];
}


/**
 * Return the browser manifest for one current visitor-authorized Smart Gallery.
 *
 * Membership and ordering come from the canonical Smart Gallery query service.
 * Source payloads are never read here; only database metadata and filesystem
 * stat information are collected before the browser starts its streamed ZIP.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @return array<int,array{gallery:array<string,mixed>,image:array<string,mixed>}> Current authorized ordered result items.
 */
function smart_gallery_download_manifest_authorized_items(array $smartGallery): array
{
    $total = smart_gallery_count_images($smartGallery, true);
    $manifestFileLimit = max(1, (int) cms_runtime_limit('download.manifest_max_files'));
    if ($total > $manifestFileLimit) {
        throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
    }

    $items = [];
    for ($offset = 0; $offset < $total; $offset += SMART_GALLERY_QUERY_MAX_PAGE_SIZE) {
        $batch = smart_gallery_query_images($smartGallery, true, SMART_GALLERY_QUERY_MAX_PAGE_SIZE, $offset);
        download_manifest_profile_count('image_rows', count($batch));
        if ($batch === []) {
            break;
        }
        $sourceGalleries = smart_gallery_source_galleries($batch);
        download_manifest_profile_count('gallery_rows', count($sourceGalleries));
        foreach ($batch as $image) {
            if (count($items) >= $manifestFileLimit) {
                throw new GalleryDownloadManifestException(t('download.progress.manifest_too_large', 'This gallery contains too many files for one browser download.'), 'manifest_too_large');
            }
            $sourceGallery = $sourceGalleries[(int) ($image['gallery_id'] ?? 0)] ?? null;
            if (!$sourceGallery || !public_image_visible_to_current_visitor($image, $sourceGallery)) {
                throw new GalleryDownloadManifestException(t('download.progress.not_available', 'This gallery is not available for download.'), 'not_available');
            }
            $items[] = ['gallery' => $sourceGallery, 'image' => $image];
        }
    }

    // A changing rule/result set between COUNT and paginated reads must not
    // silently produce a partial archive. The browser can retry a fresh manifest.
    if (count($items) !== $total) {
        throw new GalleryDownloadManifestException(t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'), 'source_changed');
    }
    return $items;
}

/**
 * Build the dynamic Smart Gallery result fingerprint used by manifest caching.
 *
 * Only ordered result content belongs in this identity. An unrelated query string,
 * capability nonce, client identity, or request URL therefore cannot force a new
 * cache key. If rules change but yield exactly the same ordered source result,
 * reusing the same normalized metadata is safe.
 *
 * @param int $smartGalleryId Published Smart Gallery identifier.
 * @param array<int,array{gallery:array<string,mixed>,image:array<string,mixed>}> $items Current ordered authorized result items.
 * @return string Content-only SHA-256 result fingerprint.
 */
function smart_gallery_download_manifest_revision(int $smartGalleryId, array $items): string
{
    $identity = [];
    foreach ($items as $item) {
        $sourceGallery = $item['gallery'];
        $image = $item['image'];
        $identity[] = [
            'image_id' => (int) ($image['id'] ?? 0),
            'gallery_id' => (int) ($sourceGallery['id'] ?? 0),
            'source_folder' => normalize_relative_path((string) ($sourceGallery['folder_path'] ?? '')),
            'relative_path' => normalize_relative_path((string) ($image['relative_path'] ?? '')),
            'source_version' => image_public_asset_version($image),
            'database_size' => max(0, (int) ($image['file_size'] ?? 0)),
        ];
    }

    return hash('sha256', json_encode([
        'format' => 'smart-progressive-manifest-v1',
        'smart_gallery_id' => $smartGalleryId,
        'items' => $identity,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Return the browser manifest for one current visitor-authorized Smart Gallery.
 *
 * The canonical dynamic query is still executed on every request so current
 * membership/authorization remains authoritative. Result-fingerprinted metadata
 * caching removes repeated source realpath/stat work without freezing Smart
 * Gallery semantics or persisting bearer capabilities.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @return array<string,mixed> Browser-safe progressive-download manifest.
 */
function smart_gallery_download_manifest(array $smartGallery, ?string $sourceCapability = null): array
{
    if ((int) ($smartGallery['id'] ?? 0) <= 0
        || (int) ($smartGallery['enabled'] ?? 0) !== 1
        || (string) ($smartGallery['visibility'] ?? '') !== 'public'
        || empty(smart_gallery_effective_presentation($smartGallery)['download_enabled'])) {
        throw new GalleryDownloadManifestException(t('download.progress.not_available', 'This gallery is not available for download.'), 'not_available');
    }

    $smartGalleryId = (int) $smartGallery['id'];
    $items = smart_gallery_download_manifest_authorized_items($smartGallery);
    $revision = smart_gallery_download_manifest_revision($smartGalleryId, $items);
    $payload = download_manifest_cache_read(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId, $revision);
    if ($payload === null) {
        $payload = gallery_download_manifest_build_cached_payload($items);
        download_manifest_cache_write(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId, $revision, $payload);
    }

    $downloadName = slugify((string) ($smartGallery['title'] ?? ''));
    if ($downloadName === '') {
        $downloadName = 'smart-gallery-' . $smartGalleryId;
    }

    return [
        'ok' => true,
        'filename' => $downloadName . '.zip',
        'files' => gallery_download_manifest_response_files($payload, 'download_smart_gallery_file', 'smart_gallery_id', $smartGalleryId, $revision, $sourceCapability),
        'total_files' => (int) $payload['total_files'],
        'total_bytes' => (int) $payload['total_bytes'],
        'memory_fallback_warning_bytes' => max(1, (int) cms_runtime_limit('download.memory_fallback_warning_bytes')),
        'memory_fallback_max_bytes' => max(1, (int) cms_runtime_limit('download.memory_fallback_max_bytes')),
        'zip64' => true,
    ];
}

/**
 * Resolve one Smart Gallery manifest source and re-authorize current membership.
 *
 * The image id is never treated as proof of membership. The current published
 * Smart Gallery rules are recompiled for only the image's already-authorized
 * source gallery, then the image id is matched inside that canonical predicate.
 * This keeps each media request bounded without enumerating every gallery.
 *
 * @return array{gallery:array<string,mixed>,image:array<string,mixed>,path:string,filename:string,size:int,version:string}|null
 */
function smart_gallery_download_authorized_source(int $smartGalleryId, int $imageId): ?array
{
    $smartGallery = smart_gallery_find_public_by_id($smartGalleryId);
    if (!$smartGallery || empty(smart_gallery_effective_presentation($smartGallery)['download_enabled'])) {
        return null;
    }

    $image = find_image($imageId);
    if (!$image) {
        return null;
    }
    $sourceGallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    if (!$sourceGallery
        || !gallery_is_public_listed($sourceGallery)
        || !public_image_visible_to_current_visitor($image, $sourceGallery)) {
        return null;
    }

    $query = smart_gallery_result_query_for_accessible_ids(
        $smartGallery,
        true,
        [(int) $sourceGallery['id']]
    );
    $params = $query['params'];
    $params[] = $imageId;
    $stmt = db()->prepare(
        'SELECT 1 FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE '
        . $query['where']
        . ' AND i.id = ? LIMIT 1'
    );
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) {
        return null;
    }

    $path = image_abs_path($image, $sourceGallery);
    $galleryRoot = gallery_abs_path((string) $sourceGallery['folder_path']);
    if (!is_file($path) || !path_inside($galleryRoot, $path)) {
        return null;
    }
    $size = filesize($path);
    if ($size === false || $size < 0) {
        return null;
    }

    return [
        'gallery' => $sourceGallery,
        'image' => $image,
        'path' => $path,
        'filename' => basename((string) ($image['filename'] ?? $path)),
        'size' => (int) $size,
        'version' => image_public_asset_version($image),
    ];
}

/**
 * Stable Smart Gallery ZIP failure carrying only an allowlisted diagnostic reason.
 */
final class SmartGalleryZipBuildException extends RuntimeException
{
    private string $reason;

    /**
     * Create one Smart Gallery ZIP failure.
     *
     * @param string $reason Stable internal reason code.
     * @param string $message Visitor-safe localized exception message.
     */
    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = preg_match('/^[a-z_]{1,48}$/D', $reason) === 1 ? $reason : 'archive_build_failed';
    }

    /** Return the stable diagnostic reason without exposing the exception message. */
    public function reason(): string
    {
        return $this->reason;
    }
}

/**
 * Download and ZIP service model.
 *
 * This module owns generated ZIP archives, cache expiry checks, gallery archive
 * entry collection, and safe download streaming. It was separated from
 * app/services.php because archive generation is a self-contained runtime
 * concern with its own cache lifecycle.
 *
 * Function names and signatures are intentionally unchanged. Existing
 * controllers can continue calling build_gallery_zip(), build_all_zip(),
 * cleanup_expired_zip_cache(), and send_download() without behaviour changes.
 */

/**
 * Ensure the ZIP cache folder exists and return its normalized path.
 *
 * @return string Text result for the caller.
 */
function zip_cache_dir(): string
{
    // Variable $path stores this steps working value.
    $path = (string) cms_config()['zip_cache_path'];
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
}

/**
 * Return the private filesystem directory used by legacy build coordination.
 *
 * Lock files are intentionally persistent. The kernel-owned flock state, not the
 * file contents, is the lease, so a crashed PHP process cannot leave a live lock.
 *
 * @return string Absolute coordination-directory path.
 */
function legacy_download_build_state_dir(): string
{
    $path = zip_cache_dir() . DIRECTORY_SEPARATOR . '.legacy-build-state';
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new LegacyDownloadBuildException(
            'coordination_dir_unavailable',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }
    return $path;
}

/**
 * Return the bounded Retry-After delay used for temporary legacy build pressure.
 *
 * @return int Delay in seconds.
 */
function legacy_download_busy_retry_after_seconds(): int
{
    return max(1, min(60, (int) cms_runtime_limit('download.legacy_busy_retry_after_seconds')));
}

/**
 * Build the canonical single-flight key for one legacy archive result.
 *
 * Only archive-content identity belongs in this key. Capability tokens, request
 * IDs, client identity, User-Agent, and unrelated query parameters are excluded.
 *
 * @param string $resourceType Stable resource type.
 * @param int $resourceId Exact resource identifier.
 * @param string $resourceRevision Stable content revision or result fingerprint.
 * @param array<string,bool|int|float|string|null> $archiveOptions Content-affecting archive options.
 * @return string SHA-256 build key.
 */
function legacy_download_build_key(string $resourceType, int $resourceId, string $resourceRevision, array $archiveOptions = []): string
{
    $resourceType = trim($resourceType);
    $resourceRevision = strtolower(trim($resourceRevision));
    if ($resourceType === '' || $resourceId <= 0 || preg_match('/^[a-f0-9]{64}$/D', $resourceRevision) !== 1) {
        throw new LegacyDownloadBuildException(
            'build_key_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    ksort($archiveOptions, SORT_STRING);
    return hash('sha256', json_encode([
        'format' => 'legacy-zip-build-v1',
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'resource_revision' => $resourceRevision,
        'archive_options' => $archiveOptions,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Acquire the non-blocking single-flight lock for one canonical legacy build.
 *
 * @param string $buildKey Canonical build key from legacy_download_build_key().
 * @return resource Owned lock handle.
 */
function legacy_download_build_lock_acquire(string $buildKey)
{
    if (preg_match('/^[a-f0-9]{64}$/D', $buildKey) !== 1) {
        throw new LegacyDownloadBuildException(
            'build_key_invalid',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    $path = legacy_download_build_state_dir() . DIRECTORY_SEPARATOR . 'build-' . $buildKey . '.lock';
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        throw new LegacyDownloadBuildException(
            'build_lock_open_failed',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new LegacyDownloadBuildBusyException(
            'same_build_in_progress',
            t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'),
            legacy_download_busy_retry_after_seconds()
        );
    }
    @touch($path);
    return $handle;
}

/**
 * Acquire one global non-blocking legacy build-admission slot.
 *
 * @return resource Owned slot handle.
 */
function legacy_download_build_slot_acquire()
{
    $slotCount = max(1, (int) cms_runtime_limit('download.legacy_max_concurrent_builds'));
    $directory = legacy_download_build_state_dir();
    $openedAny = false;

    for ($slot = 1; $slot <= $slotCount; $slot++) {
        $path = $directory . DIRECTORY_SEPARATOR . sprintf('slot-%03d.lock', $slot);
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            continue;
        }
        $openedAny = true;
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            @touch($path);
            return $handle;
        }
        fclose($handle);
    }

    if (!$openedAny) {
        throw new LegacyDownloadBuildException(
            'build_slots_unavailable',
            t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.')
        );
    }

    throw new LegacyDownloadBuildBusyException(
        'global_build_capacity_busy',
        t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'),
        legacy_download_busy_retry_after_seconds()
    );
}

/**
 * Release one owned flock handle without deleting the shared lock file.
 *
 * @param mixed $handle Owned lock or slot handle.
 */
function legacy_download_build_lock_release(mixed $handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * Return the number of seconds a generated ZIP file may remain in cache.
 *
 * @return int Integer result for the caller.
 */
function zip_cache_ttl_seconds(): int
{
    return 7 * 24 * 60 * 60;
}

/**
 * Return the configured aggregate source-byte ceiling for one Smart Gallery ZIP.
 *
 * Existing installations that do not yet have the config key use a conservative
 * 2 GiB default so archive generation remains bounded after an application update.
 */
function smart_gallery_zip_max_source_bytes(): int
{
    $config = cms_config();
    $configured = $config['smart_gallery_zip_max_source_bytes'] ?? cms_runtime_limit('download.smart_gallery_zip_max_source_bytes');
    if (!is_numeric($configured)) {
        return max(1, (int) cms_runtime_limit('download.smart_gallery_zip_max_source_bytes'));
    }
    $bytes = (int) $configured;
    return $bytes > 0 ? $bytes : max(1, (int) cms_runtime_limit('download.smart_gallery_zip_max_source_bytes'));
}

/** Return a bounded reason code suitable for persistent Smart Gallery download logs. */
function smart_gallery_zip_failure_reason(Throwable $exception): string
{
    if ($exception instanceof SmartGalleryZipBuildException) {
        return $exception->reason();
    }
    return gallery_zip_failure_reason($exception);
}

/**
 * Build one Smart Gallery ZIP under an exclusive signature lock and publish it atomically.
 *
 * @param string $filePath Final cache path.
 * @param array $entries Archive entries accepted by create_zip().
 */
function smart_gallery_zip_create_atomically(string $filePath, array $entries): void
{
    if (zip_cache_file_is_fresh($filePath)) {
        return;
    }

    $lockPath = $filePath . '.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false) {
        throw new SmartGalleryZipBuildException('lock_open_failed', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
    }

    $partialPath = '';
    try {
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new LegacyDownloadBuildBusyException(
                'artifact_publish_in_progress',
                t('download.progress.legacy_busy', 'Download preparation is temporarily busy. Please retry in a few seconds.'),
                legacy_download_busy_retry_after_seconds()
            );
        }

        // Another request may have completed the same signature while this request waited.
        if (zip_cache_file_is_fresh($filePath)) {
            return;
        }

        $partialPath = $filePath . '.partial-' . bin2hex(random_bytes(8));
        create_zip($partialPath, $entries);

        // A stale destination may exist from an earlier cache generation. The signature
        // lock guarantees no other Smart Gallery builder owns this final path now.
        if (is_file($filePath) && !@unlink($filePath)) {
            throw new SmartGalleryZipBuildException('stale_archive_remove_failed', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
        }
        if (!@rename($partialPath, $filePath)) {
            throw new SmartGalleryZipBuildException('archive_publish_failed', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
        }
        $partialPath = '';
    } finally {
        if ($partialPath !== '' && is_file($partialPath)) {
            @unlink($partialPath);
        }
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

/**
 * Return true when a cached ZIP path is still inside the ZIP cache and fresh enough to reuse.
 *
 * @param string $filePath File path filesystem path.
 * @param ?int $now Now value.
 * @return bool True when the condition matches.
 */
function zip_cache_file_is_fresh(string $filePath, ?int $now = null): bool
{
    if ($now === null) {
        // $now stores an intermediate value used by the surrounding gallery workflow.
        $now = time();
    }
    if (!is_file($filePath) || !path_inside(zip_cache_dir(), $filePath)) {
        return false;
    }
    // Variable $modifiedAt stores this steps working value.
    $modifiedAt = filemtime($filePath);
    if ($modifiedAt === false) {
        return false;
    }
    return ($now - $modifiedAt) <= zip_cache_ttl_seconds();
}

/**
 * Remove expired generated ZIP files and database rows from the ZIP cache.
 *
 * @param ?int $now Now value.
 * @return array Structured result data for the caller.
 */
function cleanup_expired_zip_cache(?int $now = null): array
{
    if ($now === null) {
        // $now stores an intermediate value used by the surrounding gallery workflow.
        $now = time();
    }
    // Variable $dir stores this steps working value.
    $dir = zip_cache_dir();
    // Variable $cutoff stores this steps working value.
    $cutoff = $now - zip_cache_ttl_seconds();
    // Variable $deletedFiles stores this steps working value.
    $deletedFiles = 0;
    // Variable $deletedRows stores this steps working value.
    $deletedRows = 0;

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id, file_path FROM zip_archives');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $archive) {
        // Variable $filePath stores this steps working value.
        $filePath = (string) $archive['file_path'];
        // Variable $removeRow stores this steps working value.
        $removeRow = false;
        if (!path_inside($dir, $filePath)) {
            // $removeRow stores an intermediate value used by the surrounding gallery workflow.
            $removeRow = true;
        } elseif (!is_file($filePath)) {
            // $removeRow stores an intermediate value used by the surrounding gallery workflow.
            $removeRow = true;
        } else {
            // Variable $modifiedAt stores this steps working value.
            $modifiedAt = filemtime($filePath);
            if ($modifiedAt === false || $modifiedAt < $cutoff) {
                if (@unlink($filePath)) {
                    $deletedFiles++;
                }
                // $removeRow stores an intermediate value used by the surrounding gallery workflow.
                $removeRow = true;
            }
        }
        if ($removeRow) {
            // Variable $delete stores this steps working value.
            $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
            $delete->execute([(int) $archive['id']]);
            $deletedRows += $delete->rowCount();
        }
    }

    // Variable $iterator stores this steps working value.
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $entry) {
        if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'zip') {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = $entry->getPathname();
        if ($entry->getMTime() < $cutoff && path_inside($dir, $path) && @unlink($path)) {
            $deletedFiles++;
        }
    }

    return ['files' => $deletedFiles, 'rows' => $deletedRows, 'ttl_seconds' => zip_cache_ttl_seconds()];
}

/**
 * Return descendant galleries for one gallery, including the gallery itself.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function gallery_zip_gallery_rows(array $gallery, bool $publicOnly): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);

    // Variable $rows stores this steps working value.
    $rows = [];
    foreach ($stmt->fetchAll() as $candidate) {
        if ($publicOnly && !visitor_can_access_gallery($candidate)) {
            continue;
        }
        $rows[] = $candidate;
    }
    return $rows;
}

/**
 * Return gallery IDs from rows as integer values.
 *
 * @param array $galleries Galleries value.
 * @return array Structured result data for the caller.
 */
function gallery_zip_gallery_ids(array $galleries): array
{
    // Variable $ids stores this steps working value.
    $ids = [];
    foreach ($galleries as $gallery) {
        $ids[] = (int) $gallery['id'];
    }
    return $ids;
}

/**
 * Build a content signature for the admin "all galleries" ZIP cache entry.
 *
 * @return string Text result for the caller.
 */
function all_zip_signature(): string
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT g.folder_path, g.updated_at AS gallery_updated_at, i.relative_path, i.file_size, i.modified_at, i.visibility
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id
        ORDER BY g.folder_path, i.relative_path")->fetchAll();
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
}

/**
 * Build one physical-gallery ZIP to a unique partial path and publish it atomically.
 *
 * @param string $filePath Final cache path.
 * @param array<int,array<string,mixed>> $entries Archive entries accepted by create_zip().
 */
function gallery_zip_create_atomically(string $filePath, array $entries): void
{
    if (zip_cache_file_is_fresh($filePath)) {
        return;
    }

    $partialPath = $filePath . '.partial-' . bin2hex(random_bytes(8));
    try {
        create_zip($partialPath, $entries);

        // A concurrent non-legacy caller may have published the same deterministic
        // signature while this request was writing its unique partial file.
        if (zip_cache_file_is_fresh($filePath)) {
            return;
        }
        if (is_file($filePath) && !@unlink($filePath)) {
            throw new GalleryZipBuildException('stale_archive_remove_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
        }
        if (!@rename($partialPath, $filePath)) {
            throw new GalleryZipBuildException('archive_publish_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
        }
        $partialPath = '';
    } finally {
        if ($partialPath !== '' && is_file($partialPath)) {
            @unlink($partialPath);
        }
    }
}

/**
 * Create or reuse a ZIP archive for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Public only value.
 * @param ?string $contentSignature Optional precomputed canonical content signature.
 * @return string Text result for the caller.
 */
function build_gallery_zip(int $galleryId, bool $publicOnly, ?string $contentSignature = null): string
{
    cleanup_expired_zip_cache();
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(t('gallery.error.not_found', 'Gallery not found.'));
    }
    // Variable $signature stores this steps working value.
    $signature = $contentSignature ?? gallery_zip_signature($galleryId, $publicOnly);
    if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
        throw new GalleryZipBuildException('signature_invalid', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
    }
    // Variable $scope stores this steps working value.
    $scope = 'gallery';
    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'gallery-' . $galleryId . '-' . $signature . '.zip';
    if (zip_cache_file_is_fresh($filePath)) {
        return $filePath;
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id = ? AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$scope, $galleryId, $signature]);
    // Variable $cached stores this steps working value.
    $cached = $stmt->fetch();
    if ($cached && zip_cache_file_is_fresh((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }
    if ($cached) {
        // Variable $cachedPath stores this steps working value.
        $cachedPath = (string) $cached['file_path'];
        if (path_inside(zip_cache_dir(), $cachedPath) && is_file($cachedPath)) {
            @unlink($cachedPath);
        }
        // Variable $delete stores this steps working value.
        $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
        $delete->execute([(int) $cached['id']]);
    }

    gallery_zip_create_atomically($filePath, gallery_zip_entries($gallery, $publicOnly));
    // Variable $insert stores this steps working value.
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$scope, $galleryId, $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Build the current visitor-authorized file fingerprint for a physical legacy ZIP.
 *
 * Image-specific access policy, including NSFW filtering, is already reflected in
 * the manifest. Capability tokens and unrelated URL/query values are deliberately
 * ignored so they cannot split one true archive revision into separate build keys.
 *
 * @param int $galleryId Public gallery identifier.
 * @param array<string,mixed> $manifest Already-authorized bounded legacy manifest.
 * @return string SHA-256 authorized file-result fingerprint.
 */
function gallery_legacy_result_fingerprint(int $galleryId, array $manifest): string
{
    if ($galleryId <= 0) {
        throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.'));
    }

    $items = [];
    foreach ((array) ($manifest['files'] ?? []) as $file) {
        if (!is_array($file)) {
            throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.'));
        }
        $query = parse_url((string) ($file['url'] ?? ''), PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }
        $imageId = max(0, (int) ($params['image_id'] ?? 0));
        $version = strtolower(trim((string) ($params['v'] ?? '')));
        $zipPath = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? -1);
        if ($imageId <= 0 || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1 || $zipPath === '' || $size < 0) {
            throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.'));
        }
        $items[] = [
            'image_id' => $imageId,
            'source_version' => $version,
            'zip_path' => $zipPath,
            'source_bytes' => $size,
        ];
    }

    return hash('sha256', json_encode([
        'format' => 'gallery-authorized-result-v1',
        'gallery_id' => $galleryId,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Recompute actual source bytes for already-authorized physical legacy entries.
 *
 * Progressive manifest metadata may be reused from a revision-keyed cache, but
 * legacy hard byte limits must be enforced against current filesystem sizes before
 * any server ZIP is built. Missing/unreadable files are treated as a changed result.
 *
 * @param array<int,array<string,mixed>> $entries Current authorized legacy ZIP entries.
 * @return int Current aggregate source bytes for file entries only.
 */
function gallery_legacy_entries_source_bytes(array $entries): int
{
    $totalBytes = 0;
    foreach ($entries as $entry) {
        if (($entry['type'] ?? 'file') !== 'file') {
            continue;
        }
        $absolute = (string) ($entry['absolute'] ?? '');
        $galleryRoot = (string) ($entry['gallery_root'] ?? '');
        if ($absolute === '' || $galleryRoot === '' || !is_file($absolute) || !path_inside($galleryRoot, $absolute)) {
            throw new GalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }
        $size = filesize($absolute);
        if ($size === false || $size < 0) {
            throw new GalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }
        if ($size > PHP_INT_MAX - $totalBytes) {
            throw new GalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
        }
        $totalBytes += (int) $size;
    }
    return $totalBytes;
}

/**
 * Build or reuse the bounded public legacy ZIP for one physical gallery.
 *
 * The canonical content signature is computed before acquiring the single-flight
 * lock. A completed archive bypasses global build admission entirely.
 *
 * @param int $galleryId Public gallery identifier.
 * @param array<string,mixed> $manifest Already-authorized bounded legacy manifest.
 * @return string Absolute completed ZIP path.
 */
function build_legacy_gallery_zip(int $galleryId, array $manifest): string
{
    if (!gallery_download_legacy_manifest_is_safe($manifest)) {
        throw new GalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
    }

    // The structural signature captures empty directories/subgallery membership,
    // while the manifest fingerprint captures the current visitor-authorized files.
    $manifestFingerprint = gallery_legacy_result_fingerprint($galleryId, $manifest);
    $gallerySignature = gallery_zip_signature($galleryId, true);
    $revision = hash('sha256', implode('|', [
        'legacy-gallery-revision-v1',
        $gallerySignature,
        $manifestFingerprint,
    ]));
    $buildKey = legacy_download_build_key('gallery', $galleryId, $revision, [
        'compression' => 'store',
        'public_only' => true,
        'zip_layout' => 'gallery_tree_v1',
    ]);
    $artifact = legacy_download_artifact_find('gallery', $galleryId, $revision, $buildKey);
    if ($artifact !== null) {
        return $artifact;
    }

    $buildLock = null;
    $buildSlot = null;
    try {
        $buildLock = legacy_download_build_lock_acquire($buildKey);

        // The winning request may have completed between the first cache probe and
        // this non-blocking lock acquisition. Reuse it without consuming a slot.
        $artifact = legacy_download_artifact_find('gallery', $galleryId, $revision, $buildKey);
        if ($artifact !== null) {
            return $artifact;
        }

        $buildSlot = legacy_download_build_slot_acquire();
        $gallery = find_gallery($galleryId);
        if (!$gallery) {
            throw new GalleryZipBuildException('gallery_not_found', t('gallery.error.not_found', 'Gallery not found.'));
        }

        // Recheck the cheap structural signature after admission so a mutation that
        // happened between manifest preparation and build never publishes under the
        // previous immutable revision directory.
        if (!hash_equals($gallerySignature, gallery_zip_signature($galleryId, true))) {
            throw new GalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }

        $entries = gallery_zip_entries($gallery, true);
        $fileCount = 0;
        foreach ($entries as $entry) {
            if (($entry['type'] ?? 'file') === 'file') {
                $fileCount++;
            }
        }
        $expectedFileCount = max(0, (int) ($manifest['total_files'] ?? 0));
        if ($fileCount !== $expectedFileCount) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_GALLERY, $galleryId);
            throw new GalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }

        $actualSourceBytes = gallery_legacy_entries_source_bytes($entries);
        $expectedSourceBytes = max(0, (int) ($manifest['total_bytes'] ?? 0));
        $legacyMaxSourceBytes = max(1, (int) cms_runtime_limit('download.legacy_max_source_bytes'));
        if ($actualSourceBytes > $legacyMaxSourceBytes) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_GALLERY, $galleryId);
            throw new GalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
        }
        if ($actualSourceBytes !== $expectedSourceBytes) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_GALLERY, $galleryId);
            throw new GalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }

        return legacy_download_artifact_build(
            'gallery',
            $galleryId,
            $revision,
            $buildKey,
            $entries,
            $actualSourceBytes,
            $expectedFileCount
        );
    } finally {
        legacy_download_build_lock_release($buildSlot);
        legacy_download_build_lock_release($buildLock);
    }
}


/**
 * Build a content signature for a transient selected-photo ZIP archive.
 *
 * @param array<string,mixed> $gallery Source gallery row.
 * @param array<int,array<string,mixed>> $images Selected source images in visual order.
 * @return string Stable signature for the current selected files.
 */
function selected_images_zip_signature(array $gallery, array $images): string
{
    // $parts stores the minimal file metadata needed to invalidate stale archives.
    $parts = [
        'scope' => 'selected_images',
        'gallery_id' => (int) ($gallery['id'] ?? 0),
        'items' => [],
    ];
    foreach ($images as $image) {
        // $absolute stores the original source path used for modification metadata.
        $absolute = image_abs_path($image, $gallery);
        $parts['items'][] = [
            'id' => (int) ($image['id'] ?? 0),
            'relative_path' => normalize_relative_path((string) ($image['relative_path'] ?? '')),
            'file_size' => is_file($absolute) ? (int) filesize($absolute) : 0,
            'modified_at' => is_file($absolute) ? (int) filemtime($absolute) : 0,
            'updated_at' => (string) ($image['updated_at'] ?? ''),
        ];
    }
    return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES));
}

/**
 * Return a safe filename for one selected-photo ZIP entry.
 *
 * @param array<string,mixed> $image Source image database row.
 * @param array{path:string,mime:string,filename:string,variant:string} $displayFile Browser-displayable file selected for the archive.
 * @param array<string,bool> $usedNames Names already added to this archive.
 * @return string Unique archive filename.
 */
function selected_images_zip_entry_name(array $image, array $displayFile, array &$usedNames): string
{
    // $sourceName stores the display derivative filename or the original source name.
    $sourceName = basename((string) ($displayFile['filename'] ?: ($image['filename'] ?? 'photo')));
    // $extension stores a conservative image extension for files without a usable suffix.
    $extension = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = match ((string) ($displayFile['mime'] ?? '')) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
    // $stem stores the cleaned filename stem that remains readable after extraction.
    $stem = slugify(pathinfo($sourceName, PATHINFO_FILENAME));
    if ($stem === '') {
        $stem = 'photo-' . (int) ($image['id'] ?? 0);
    }

    // $candidate stores the current unique filename proposal.
    $candidate = $stem . '.' . $extension;
    // $counter stores a suffix for duplicate names coming from nested gallery paths.
    $counter = 2;
    while (isset($usedNames[strtolower($candidate)])) {
        $candidate = $stem . '-' . $counter . '.' . $extension;
        $counter++;
    }
    $usedNames[strtolower($candidate)] = true;
    return $candidate;
}

/**
 * Produce ZIP entries for a selected group of browser-displayable photo files.
 *
 * @param array<string,mixed> $gallery Source gallery row.
 * @param array<int,array<string,mixed>> $images Selected source images in visual order.
 * @return array<int,array<string,string>> Archive entries for create_zip().
 */
function selected_images_zip_entries(array $gallery, array $images): array
{
    // $entries stores the files added to the transient selection archive.
    $entries = [];
    // $usedNames stores lowercase entry names so duplicates can be disambiguated.
    $usedNames = [];
    foreach ($images as $image) {
        // $displayFile stores a browser-displayable file, converting DNG sources when possible.
        $displayFile = image_public_display_file($image, $gallery, true);
        if ($displayFile === null || !is_file((string) $displayFile['path'])) {
            continue;
        }
        $entries[] = [
            'type' => 'file',
            'absolute' => (string) $displayFile['path'],
            'zip_path' => 'selected-photos/' . selected_images_zip_entry_name($image, $displayFile, $usedNames),
        ];
    }
    return $entries;
}

/**
 * Create or reuse a transient ZIP archive containing only selected photos.
 *
 * The archive is not recorded in zip_archives because that table intentionally
 * supports only gallery-wide and site-wide cache scopes. The normal cache-folder
 * cleanup still removes the file after the ZIP TTL expires.
 *
 * @param int $sourceGalleryId Gallery that owns the selected images.
 * @param array<int> $imageIds Selected image IDs submitted by the public Picture manager.
 * @return string Absolute path to the generated ZIP archive.
 */
function build_selected_images_zip(int $sourceGalleryId, array $imageIds): string
{
    cleanup_expired_zip_cache();
    // $sourceGallery stores the gallery currently shown in the public manager.
    $sourceGallery = find_gallery($sourceGalleryId, true);
    if (!$sourceGallery) {
        throw new RuntimeException('Source gallery was not found.');
    }
    // $normalizedIds stores unique positive IDs in submitted order.
    $normalizedIds = picture_manager_normalize_image_ids($imageIds);
    if (!$normalizedIds) {
        throw new RuntimeException('Select at least one photo first.');
    }
    // $failures stores invalid selected image messages.
    $failures = [];
    // $images stores selected rows sorted in the visible source order.
    $images = picture_manager_owned_images_for_selection($sourceGalleryId, $normalizedIds, $failures);
    if ($failures) {
        throw new RuntimeException(implode(' ', array_slice($failures, 0, 5)));
    }
    if (!$images) {
        throw new RuntimeException('No selected photos could be archived.');
    }

    // $signature stores a transient archive cache key for this exact selection.
    $signature = selected_images_zip_signature($sourceGallery, $images);
    // $filePath stores the cache path for this selected-photo archive.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'selected-' . $sourceGalleryId . '-' . $signature . '.zip';
    if (zip_cache_file_is_fresh($filePath)) {
        return $filePath;
    }

    // $entries stores browser-displayable media files ready for extraction or manual sharing.
    $entries = selected_images_zip_entries($sourceGallery, $images);
    if (!$entries) {
        throw new RuntimeException('No selected photos have a browser-displayable file available.');
    }
    create_zip($filePath, $entries);
    return $filePath;
}

/**
 * Hash one ordered Smart Gallery result identity used by legacy ZIP coordination.
 *
 * @param int $smartGalleryId Exact Smart Gallery identifier.
 * @param array<int,array{image_id:int,source_version:string,zip_path:string,source_bytes:int}> $items Ordered result identity.
 * @return string SHA-256 result fingerprint.
 */
function smart_gallery_legacy_result_fingerprint_items(int $smartGalleryId, array $items): string
{
    if ($smartGalleryId <= 0) {
        throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
    }

    return hash('sha256', json_encode([
        'format' => 'smart-gallery-result-v1',
        'smart_gallery_id' => $smartGalleryId,
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * Build a bounded Smart Gallery result fingerprint from an authorized manifest.
 *
 * The fingerprint intentionally ignores the capability token and every unrelated
 * URL/query value. It includes only ordered source identity/version, ZIP entry
 * name, and source size because those fields determine the archive contents.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @param array<string,mixed> $manifest Already-authorized bounded legacy manifest.
 * @return string SHA-256 result fingerprint.
 */
function smart_gallery_legacy_result_fingerprint(array $smartGallery, array $manifest): string
{
    $items = [];
    foreach ((array) ($manifest['files'] ?? []) as $file) {
        if (!is_array($file)) {
            throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
        }
        $query = parse_url((string) ($file['url'] ?? ''), PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }
        $imageId = max(0, (int) ($params['image_id'] ?? 0));
        $version = strtolower(trim((string) ($params['v'] ?? '')));
        $zipPath = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? -1);
        if ($imageId <= 0 || preg_match('/^[a-f0-9]{16}$/D', $version) !== 1 || $zipPath === '' || $size < 0) {
            throw new LegacyDownloadBuildException('result_fingerprint_invalid', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
        }
        $items[] = [
            'image_id' => $imageId,
            'source_version' => $version,
            'zip_path' => $zipPath,
            'source_bytes' => $size,
        ];
    }

    return smart_gallery_legacy_result_fingerprint_items((int) ($smartGallery['id'] ?? 0), $items);
}

/**
 * Build the current public Smart Gallery archive entries and canonical result signature.
 *
 * The result is fetched in bounded pages and revalidated against an optional
 * precomputed fingerprint before any caller publishes an archive.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @param ?string $contentSignature Optional precomputed canonical result fingerprint.
 * @return array{entries:array<int,array<string,mixed>>,signature:string,source_bytes:int,file_count:int}
 */
function smart_gallery_zip_build_context(array $smartGallery, ?string $contentSignature = null): array
{
    $total = smart_gallery_count_images($smartGallery, true);
    if ($total > max(1, (int) cms_runtime_limit('download.smart_gallery_zip_max_images'))) {
        throw new SmartGalleryZipBuildException('image_count_limit', t('smart_gallery.download_too_large', 'This Smart Gallery is too large for one server-generated ZIP. Narrow its rules or use pagination to download source galleries individually.'));
    }

    $images = [];
    for ($offset = 0; $offset < $total; $offset += SMART_GALLERY_QUERY_MAX_PAGE_SIZE) {
        $batch = smart_gallery_query_images($smartGallery, true, SMART_GALLERY_QUERY_MAX_PAGE_SIZE, $offset);
        if ($batch === []) {
            break;
        }
        array_push($images, ...$batch);
    }
    $sourceGalleries = smart_gallery_source_galleries($images);
    $entries = [];
    $fingerprintItems = [];
    $usedNames = [];
    $sourceBytes = 0;
    $maxSourceBytes = smart_gallery_zip_max_source_bytes();
    foreach ($images as $image) {
        $source = $sourceGalleries[(int) ($image['gallery_id'] ?? 0)] ?? null;
        if (!$source || !public_image_visible_to_current_visitor($image, $source)) {
            continue;
        }
        $absolute = image_abs_path($image, $source);
        $sourceRoot = gallery_abs_path((string) ($source['folder_path'] ?? ''));
        if (!is_file($absolute) || !path_inside($sourceRoot, $absolute)) {
            continue;
        }
        $relative = normalize_relative_path((string) ($image['relative_path'] ?? ''));
        $zipPath = gallery_download_unique_zip_path((string) ($source['folder_path'] ?? '') . '/' . $relative, $usedNames);
        if ($zipPath === '') {
            continue;
        }
        $fileSize = filesize($absolute);
        if ($fileSize === false) {
            throw new SmartGalleryZipBuildException('source_size_unavailable', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
        }
        if ($fileSize > $maxSourceBytes - $sourceBytes) {
            throw new SmartGalleryZipBuildException('source_bytes_limit', t('smart_gallery.download_too_large', 'This Smart Gallery is too large for one server-generated ZIP. Narrow its rules or use pagination to download source galleries individually.'));
        }
        $sourceBytes += $fileSize;
        $entries[] = ['type' => 'file', 'absolute' => $absolute, 'zip_path' => $zipPath];
        $fingerprintItems[] = [
            'image_id' => (int) ($image['id'] ?? 0),
            'source_version' => image_public_asset_version($image),
            'zip_path' => $zipPath,
            'source_bytes' => (int) $fileSize,
        ];
    }

    $currentSignature = smart_gallery_legacy_result_fingerprint_items((int) ($smartGallery['id'] ?? 0), $fingerprintItems);
    if ($contentSignature !== null && !hash_equals($contentSignature, $currentSignature)) {
        throw new SmartGalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
    }
    $signature = $contentSignature ?? $currentSignature;
    if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
        throw new SmartGalleryZipBuildException('signature_invalid', t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.'));
    }

    return [
        'entries' => $entries,
        'signature' => $signature,
        'source_bytes' => $sourceBytes,
        'file_count' => count($entries),
    ];
}

/**
 * Create or reuse a transient ZIP archive for one public Smart Gallery result set.
 *
 * Membership is fetched only through the canonical Smart Gallery service in
 * bounded pages. The archive preserves source-gallery paths so duplicate photo
 * filenames remain unambiguous without exposing server filesystem paths.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @param ?string $contentSignature Optional precomputed canonical result fingerprint.
 * @return string Absolute path to the generated ZIP archive.
 */
function build_smart_gallery_zip(array $smartGallery, ?string $contentSignature = null): string
{
    cleanup_expired_zip_cache();
    $context = smart_gallery_zip_build_context($smartGallery, $contentSignature);
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'smart-gallery-' . (int) ($smartGallery['id'] ?? 0) . '-' . $context['signature'] . '.zip';
    smart_gallery_zip_create_atomically($filePath, $context['entries']);
    return $filePath;
}

/**
 * Build or reuse the bounded public legacy ZIP for one Smart Gallery.
 *
 * @param array<string,mixed> $smartGallery Published Smart Gallery definition.
 * @param array<string,mixed> $manifest Already-authorized bounded legacy manifest.
 * @return string Absolute completed ZIP path.
 */
function build_legacy_smart_gallery_zip(array $smartGallery, array $manifest): string
{
    if (!gallery_download_legacy_manifest_is_safe($manifest)) {
        throw new SmartGalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
    }

    $smartGalleryId = (int) ($smartGallery['id'] ?? 0);
    $revision = smart_gallery_legacy_result_fingerprint($smartGallery, $manifest);
    $buildKey = legacy_download_build_key('smart_gallery', $smartGalleryId, $revision, [
        'compression' => 'store',
        'public_only' => true,
        'zip_layout' => 'source_gallery_tree_v1',
    ]);
    $artifact = legacy_download_artifact_find('smart_gallery', $smartGalleryId, $revision, $buildKey);
    if ($artifact !== null) {
        return $artifact;
    }

    $buildLock = null;
    $buildSlot = null;
    try {
        $buildLock = legacy_download_build_lock_acquire($buildKey);
        $artifact = legacy_download_artifact_find('smart_gallery', $smartGalleryId, $revision, $buildKey);
        if ($artifact !== null) {
            return $artifact;
        }

        $buildSlot = legacy_download_build_slot_acquire();
        try {
            $context = smart_gallery_zip_build_context($smartGallery, $revision);
        } catch (SmartGalleryZipBuildException $exception) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId);
            if (in_array($exception->reason(), ['image_count_limit', 'source_bytes_limit'], true)) {
                throw new SmartGalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
            }
            throw $exception;
        }

        $expectedFileCount = max(0, (int) ($manifest['total_files'] ?? 0));
        if ((int) $context['file_count'] !== $expectedFileCount) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId);
            throw new SmartGalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }
        $actualSourceBytes = max(0, (int) $context['source_bytes']);
        $expectedSourceBytes = max(0, (int) ($manifest['total_bytes'] ?? 0));
        $legacyMaxSourceBytes = max(1, (int) cms_runtime_limit('download.legacy_max_source_bytes'));
        if ($actualSourceBytes > $legacyMaxSourceBytes) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId);
            throw new SmartGalleryZipBuildException('legacy_limits_exceeded', t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.'));
        }
        if ($actualSourceBytes !== $expectedSourceBytes) {
            download_manifest_cache_invalidate_resource(DOWNLOAD_MANIFEST_RESOURCE_SMART_GALLERY, $smartGalleryId);
            throw new SmartGalleryZipBuildException('result_changed', t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.'));
        }

        return legacy_download_artifact_build(
            'smart_gallery',
            $smartGalleryId,
            $revision,
            $buildKey,
            $context['entries'],
            $actualSourceBytes,
            $expectedFileCount
        );
    } finally {
        legacy_download_build_lock_release($buildSlot);
        legacy_download_build_lock_release($buildLock);
    }
}

/**
 * Create or reuse a ZIP archive containing every imported gallery.
 *
 * @return string Text result for the caller.
 */
function build_all_zip(): string
{
    cleanup_expired_zip_cache();
    // Variable $signature stores this steps working value.
    $signature = all_zip_signature();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM zip_archives WHERE scope = ? AND gallery_id IS NULL AND content_signature = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute(['all', $signature]);
    // Variable $cached stores this steps working value.
    $cached = $stmt->fetch();
    if ($cached && zip_cache_file_is_fresh((string) $cached['file_path'])) {
        return (string) $cached['file_path'];
    }
    if ($cached) {
        // Variable $cachedPath stores this steps working value.
        $cachedPath = (string) $cached['file_path'];
        if (path_inside(zip_cache_dir(), $cachedPath) && is_file($cachedPath)) {
            @unlink($cachedPath);
        }
        // Variable $delete stores this steps working value.
        $delete = db()->prepare('DELETE FROM zip_archives WHERE id = ?');
        $delete->execute([(int) $cached['id']]);
    }

    // Variable $galleries stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute();
    $galleries = $stmt->fetchAll();
    // Variable $entries stores this steps working value.
    $entries = gallery_zip_entries_from_galleries($galleries, false);
    // Variable $filePath stores this steps working value.
    $filePath = zip_cache_dir() . DIRECTORY_SEPARATOR . 'all-' . $signature . '.zip';
    create_zip($filePath, $entries);
    // Variable $insert stores this steps working value.
    $insert = db()->prepare('INSERT INTO zip_archives (scope, gallery_id, file_path, content_signature, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?)');
    $insert->execute(['all', $filePath, $signature, now_sql(), now_sql()]);
    return $filePath;
}

/**
 * Produce the directories and files that should be stored in a gallery ZIP.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function gallery_zip_entries(array $gallery, bool $publicOnly): array
{
    return gallery_zip_entries_from_galleries(gallery_zip_gallery_rows($gallery, $publicOnly), $publicOnly);
}

/**
 * Produce ZIP entries from already selected gallery rows.
 *
 * @param array $galleries Galleries value.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function gallery_zip_entries_from_galleries(array $galleries, bool $publicOnly): array
{
    // Variable $directories stores this steps working value.
    $directories = [];
    // Variable $files stores this steps working value.
    $files = [];
    // Variable $galleryById stores this steps working value.
    $galleryById = [];

    foreach ($galleries as $gallery) {
        // Variable $folderPath stores this steps working value.
        $folderPath = normalize_relative_path((string) $gallery['folder_path']);
        if ($folderPath === '') {
            continue;
        }
        $directories[$folderPath] = $folderPath;
        $galleryById[(int) $gallery['id']] = $gallery;
    }

    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_zip_gallery_ids($galleries);
    if ($galleryIds) {
        // Variable $placeholders stores this steps working value.
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        // Variable $imageVisibilitySql stores this steps working value.
        $imageVisibilitySql = $publicOnly ? " AND visibility = 'public'" : '';
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare("SELECT * FROM images WHERE gallery_id IN ($placeholders)" . $imageVisibilitySql . ' ORDER BY gallery_id, sort_order, filename, relative_path');
        $stmt->execute($galleryIds);
        foreach ($stmt->fetchAll() as $image) {
            // Variable $imageGallery stores this steps working value.
            $imageGallery = $galleryById[(int) $image['gallery_id']] ?? null;
            if (!$imageGallery) {
                continue;
            }
            if ($publicOnly && !public_image_visible_to_current_visitor($image, $imageGallery)) {
                continue;
            }
            // Variable $absolute stores this steps working value.
            $absolute = image_abs_path($image, $imageGallery);
            if (!is_file($absolute)) {
                continue;
            }
            // Variable $relativePath stores this steps working value.
            $relativePath = normalize_relative_path((string) $image['relative_path']);
            // Variable $zipPath stores this steps working value.
            $zipPath = normalize_relative_path((string) $imageGallery['folder_path'] . '/' . $relativePath);
            $files[$zipPath] = [
                'type' => 'file',
                'absolute' => $absolute,
                'zip_path' => $zipPath,
                // Keep the originating gallery root so bounded legacy builds can
                // revalidate containment even when manifest metadata was cached.
                'gallery_root' => gallery_abs_path((string) $imageGallery['folder_path']),
            ];

            // Variable $parentDirectory stores this steps working value.
            $parentDirectory = dirname(str_replace('\\', '/', $zipPath));
            while ($parentDirectory !== '.' && $parentDirectory !== '') {
                $directories[$parentDirectory] = $parentDirectory;
                // $parentDirectory stores an intermediate value used by the surrounding gallery workflow.
                $parentDirectory = dirname($parentDirectory);
            }
        }
    }

    // Variable $entries stores this steps working value.
    $entries = [];
    ksort($directories, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($directories as $directory) {
        $entries[] = [
            'type' => 'directory',
            'zip_path' => rtrim($directory, '/') . '/',
        ];
    }
    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($files as $file) {
        $entries[] = $file;
    }
    return $entries;
}

/**
 * Write a ZIP archive to disk.
 *
 * @param string $filePath File path filesystem path.
 * @param array $entries Entries value.
 */
function create_zip(string $filePath, array $entries): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new GalleryZipBuildException('zip_unavailable', t('download.error.zip_unavailable', 'ZipArchive is not available.'));
    }
    // Variable $zip stores this steps working value.
    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        if (is_file($filePath)) {
            @unlink($filePath);
        }
        throw new GalleryZipBuildException('zip_create_failed', t('download.error.zip_create_failed', 'Unable to create ZIP archive.'));
    }

    $zipIsOpen = true;
    try {
        foreach ($entries as $entry) {
            // Variable $zipPath stores this steps working value.
            $zipPath = normalize_relative_path((string) ($entry['zip_path'] ?? ''));
            if ($zipPath === '') {
                continue;
            }
            if (($entry['type'] ?? 'file') === 'directory') {
                if (!$zip->addEmptyDir(rtrim($zipPath, '/') . '/')) {
                    throw new GalleryZipBuildException('zip_entry_add_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
                }
                continue;
            }
            // Variable $absolute stores this steps working value.
            $absolute = (string) ($entry['absolute'] ?? '');
            if ($absolute !== '' && is_file($absolute)) {
                if (!$zip->addFile($absolute, $zipPath)) {
                    throw new GalleryZipBuildException('zip_entry_add_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
                }
                if (!$zip->setCompressionName($zipPath, ZipArchive::CM_STORE)) {
                    throw new GalleryZipBuildException('zip_entry_compression_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
                }
            }
        }

        if (!$zip->close()) {
            $zipIsOpen = false;
            throw new GalleryZipBuildException('zip_close_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
        }
        $zipIsOpen = false;

        $size = is_file($filePath) ? filesize($filePath) : false;
        if ($size === false || $size <= 0) {
            throw new GalleryZipBuildException('zip_finalize_failed', t('download.error.zip_finalize_failed', 'Unable to finalize ZIP archive.'));
        }
    } catch (Throwable $exception) {
        if ($zipIsOpen) {
            @$zip->close();
        }
        if (is_file($filePath)) {
            @unlink($filePath);
        }
        throw $exception;
    }
}

/**
 * Stream a ZIP file to the browser and stop processing.
 *
 * @param string $filePath File path filesystem path.
 * @param string $downloadName Download name value.
 */
function send_download(string $filePath, string $downloadName): never
{
    if (!is_file($filePath) || !path_inside(zip_cache_dir(), $filePath)) {
        http_response_code(404);
        exit(t('download.error.not_found', 'Download not found.'));
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}
