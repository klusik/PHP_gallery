<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides gallery-to-gallery migration support over the existing API-key
 *   automation model.
 *
 * Responsibilities:
 *   - Build versioned gallery migration manifests
 *   - Validate exact-version compatibility for migration jobs
 *   - Persist small resumable migration job state files
 *   - Install originals, thumbnails, and gallery assets without regeneration
 *   - Keep outbound HTTP work bounded to one manifest or one ZIP package per request
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
 *   2026-09-05
 */

declare(strict_types=1);

namespace Gallery\Services;

use CURLFile;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_VERSION;
use function Gallery\Controllers\admin_edit_gallery_tab_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\unique_slug;

const GALLERY_MIGRATION_PROTOCOL_VERSION = 2;
const GALLERY_MIGRATION_TIMEOUT_SECONDS = 45;
const GALLERY_MIGRATION_RECONNECT_SECONDS = 30;
const GALLERY_MIGRATION_PACKAGE_MAX_ASSETS = 2048;

/**
 * Return a translated migration message while allowing isolated tests to run.
 *
 * @param string $key Translation key.
 * @param string $fallback English fallback text.
 * @param array $parameters Parameters value.
 * @return string Resolved text.
 */
function gallery_migration_t(string $key, string $fallback, array $parameters = []): string
{
    if (function_exists('Gallery\\Services\\t')) {
        return t($key, $fallback, $parameters);
    }

    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/**
 * Return compatibility details for a source and target version pair.
 *
 * @param string $sourceVersion Source version value.
 * @param string $targetVersion Target version value.
 * @return array{ok:bool,source_version:string,target_version:string,policy:string,message:string} Structured result data for the caller.
 */
function gallery_migration_compatibility_result(string $sourceVersion, string $targetVersion): array
{
    $sourceVersion = trim($sourceVersion);
    $targetVersion = trim($targetVersion);
    $ok = $sourceVersion !== '' && $targetVersion !== '' && $sourceVersion === $targetVersion;

    return [
        'ok' => $ok,
        'source_version' => $sourceVersion,
        'target_version' => $targetVersion,
        'policy' => 'exact',
        'message' => $ok
            ? gallery_migration_t('gallery_migration.compatibility_ok', 'Source and target versions match.')
            : gallery_migration_t(
                'gallery_migration.compatibility_failed',
                'Migration requires identical PHP Gallery versions for now. Source: {source}. Target: {target}.',
                ['source' => $sourceVersion !== '' ? $sourceVersion : 'unknown', 'target' => $targetVersion !== '' ? $targetVersion : 'unknown']
            ),
    ];
}

/**
 * Return true when a source version can migrate into a target version.
 *
 * @param string $sourceVersion Source version value.
 * @param string $targetVersion Target version value.
 * @return bool True when the condition matches.
 */
function gallery_migration_versions_compatible(string $sourceVersion, string $targetVersion): bool
{
    return gallery_migration_compatibility_result($sourceVersion, $targetVersion)['ok'];
}

/**
 * Return the app version sent in migration manifests and API responses.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_current_version(): string
{
    return function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : (defined('Gallery\\Core\\CMS_VERSION') ? CMS_VERSION : '');
}


/**
 * Clamp a reconnect or HTTP timeout value to a safe server-side range.
 *
 * @param ?int $seconds Seconds value.
 * @return int Integer result for the caller.
 */
function gallery_migration_timeout_seconds(?int $seconds = null): int
{
    if ($seconds === null || $seconds <= 0) {
        return GALLERY_MIGRATION_RECONNECT_SECONDS;
    }

    return max(5, min(300, $seconds));
}

/**
 * Read the admin-selected reconnect interval from the current request.
 *
 * @return int Integer result for the caller.
 */
function gallery_migration_request_timeout_seconds(): int
{
    return gallery_migration_timeout_seconds((int) ($_POST['reconnect_seconds'] ?? GALLERY_MIGRATION_RECONNECT_SECONDS));
}

/**
 * Return the private job-state directory.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_job_dir(): string
{
    return dirname(__DIR__, 2) . '/cache/gallery-migrations';
}

/**
 * Ensure the job-state directory exists.
 */
function gallery_migration_ensure_job_dir(): void
{
    $dir = gallery_migration_job_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_dir_failed', 'Could not create the gallery migration job directory.'));
    }
}

/**
 * Normalize a job identifier from a request or manifest.
 *
 * @param string $jobId Job id identifier.
 * @return string Text result for the caller.
 */
function gallery_migration_normalize_job_id(string $jobId): string
{
    $jobId = strtolower(trim($jobId));
    return preg_match('/^[a-f0-9]{16,64}$/', $jobId) === 1 ? $jobId : '';
}

/**
 * Build a stable job id for a target gallery and source manifest.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest value.
 * @return string Text result for the caller.
 */
function gallery_migration_job_id(int $targetGalleryId, array $manifest): string
{
    $seed = implode('|', [
        'gallery-migration-v2',
        (string) $targetGalleryId,
        (string) ($manifest['source_instance_id'] ?? ''),
        (string) ($manifest['source_gallery_id'] ?? ''),
        (string) ($manifest['migration_id'] ?? ''),
    ]);

    return substr(hash('sha256', $seed), 0, 32);
}

/**
 * Return the path for one job-state file.
 *
 * @param string $jobId Job id identifier.
 * @return string Text result for the caller.
 */
function gallery_migration_job_path(string $jobId): string
{
    $jobId = gallery_migration_normalize_job_id($jobId);
    if ($jobId === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.invalid_job', 'Invalid migration job id.'));
    }

    return gallery_migration_job_dir() . '/' . $jobId . '.json';
}

/**
 * Persist one migration job state.
 *
 * @param array $job Job value.
 */
function gallery_migration_save_job(array $job): void
{
    gallery_migration_ensure_job_dir();
    $jobId = gallery_migration_normalize_job_id((string) ($job['job_id'] ?? ''));
    if ($jobId === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.invalid_job', 'Invalid migration job id.'));
    }

    $job['updated_at'] = now_sql();
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents(gallery_migration_job_path($jobId), $json, LOCK_EX) === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_write_failed', 'Could not save migration job state.'));
    }
}

/**
 * Load one migration job state.
 *
 * @param string $jobId Job id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_load_job(string $jobId): array
{
    $path = gallery_migration_job_path($jobId);
    if (!is_file($path)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_missing', 'Migration job was not found. Start the migration again.'));
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_corrupt', 'Migration job state is unreadable.'));
    }

    return $decoded;
}

/**
 * Return a per-install source identifier that does not expose secrets.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_instance_id(): string
{
    $base = '';
    try {
        $base = (string) (cms_config()['base_url'] ?? '');
    } catch (Throwable) {
        $base = '';
    }

    return substr(hash('sha256', $base . '|' . dirname(__DIR__, 2)), 0, 16);
}

/**
 * Return manifest-safe gallery settings.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function gallery_migration_gallery_metadata(array $gallery): array
{
    $fields = [
        'title',
        'slug',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'gallery_date',
        'gallery_date_end',
        'description_layout',
        'count_badge_visibility',
        'picture_game_enabled',
        'gps_map_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'background_source',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'cover_image_path',
        'nsfw_enabled',
    ];

    $metadata = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $gallery)) {
            $metadata[$field] = $gallery[$field];
        }
    }

    $metadata['tags'] = function_exists('Gallery\\Services\\tag_names_for_entity') ? tag_names_for_entity('gallery', (int) ($gallery['id'] ?? 0)) : '';
    $metadata['cover_source_id'] = (int) ($gallery['cover_image_id'] ?? 0);
    if (content_localization_schema_ready('gallery')) {
        $metadata['content_language'] = content_language_normalize($gallery['content_language'] ?? null);
        $translationRows = content_translation_rows('gallery', [(int) ($gallery['id'] ?? 0)]);
        $metadata['translations'] = $translationRows[(int) ($gallery['id'] ?? 0)] ?? [];
    }
    return $metadata;
}

/**
 * Return manifest-safe image metadata.
 *
 * @param array $image Image row or image data.
 * @return array Structured result data for the caller.
 */
function gallery_migration_image_metadata(array $image): array
{
    $fields = [
        'relative_path',
        'filename',
        'title',
        'description',
        'width',
        'height',
        'mime_type',
        'file_size',
        'modified_at',
        'checksum_sha256',
        'sort_order',
        'visibility',
        'exif_taken_at',
        'exif_camera_make',
        'exif_camera_model',
        'exif_lens_model',
        'exif_focal_length',
        'exif_aperture',
        'exif_exposure_time',
        'exif_iso',
        'gps_lat',
        'gps_lng',
        'gps_altitude',
        'gps_extracted_at',
        'nsfw_enabled',
        'thumbnail_min_size',
        'thumbnail_max_size',
    ];

    $metadata = [
        'source_id' => (int) ($image['id'] ?? 0),
        'tags' => function_exists('Gallery\\Services\\tag_names_for_entity') ? tag_names_for_entity('image', (int) ($image['id'] ?? 0)) : '',
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $image)) {
            $metadata[$field] = $image[$field];
        }
    }
    if (content_localization_schema_ready('image')) {
        $metadata['content_language'] = content_language_normalize($image['content_language'] ?? null);
        $translationRows = content_translation_rows('image', [(int) ($image['id'] ?? 0)]);
        $metadata['translations'] = $translationRows[(int) ($image['id'] ?? 0)] ?? [];
    }

    return $metadata;
}

/**
 * Return a safe MIME type for one local asset.
 *
 * @param string $path Filesystem path.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
 */
function gallery_migration_asset_mime(string $path, string $fallback = 'application/octet-stream'): string
{
    $info = @getimagesize($path);
    if (is_array($info) && !empty($info['mime'])) {
        return (string) $info['mime'];
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return $fallback;
}

/**
 * Build an asset descriptor for one original image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return ?array Structured result data for the caller.
 */
function gallery_migration_original_asset(array $image, array $gallery): ?array
{
    try {
        $path = image_abs_path($image, $gallery);
    } catch (Throwable) {
        return null;
    }
    if (!is_file($path)) {
        return null;
    }

    return [
        'scope' => 'image',
        'source_gallery_id' => (int) ($gallery['id'] ?? 0),
        'kind' => 'original',
        'source_image_id' => (int) ($image['id'] ?? 0),
        'filename' => (string) ($image['filename'] ?? basename($path)),
        'relative_path' => normalize_relative_path((string) ($image['relative_path'] ?? basename($path))),
        'file_size' => filesize($path) ?: 0,
        'checksum_sha256' => (string) (($image['checksum_sha256'] ?? '') ?: (hash_file('sha256', $path) ?: '')),
        'mime_type' => gallery_migration_asset_mime($path, (string) ($image['mime_type'] ?? 'application/octet-stream')),
    ];
}

/**
 * Build asset descriptors for generated thumbnails that already exist.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return array<int array<string, mixed>>.
 */
function gallery_migration_thumbnail_assets(array $image, array $gallery): array
{
    $assets = [];
    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            try {
                $path = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            } catch (Throwable) {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $assets[] = [
                'scope' => 'image',
                'source_gallery_id' => (int) ($gallery['id'] ?? 0),
                'kind' => 'thumbnail',
                'source_image_id' => (int) ($image['id'] ?? 0),
                'size' => (int) $size,
                'format' => $format,
                'filename' => basename($path),
                'file_size' => filesize($path) ?: 0,
                'checksum_sha256' => hash_file('sha256', $path) ?: '',
                'mime_type' => gallery_migration_asset_mime($path, $format === 'webp' ? 'image/webp' : 'image/jpeg'),
            ];
        }
    }

    return $assets;
}

/**
 * Build descriptors for gallery-level visual assets stored under the gallery folder.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array<int array<string, mixed>>.
 */
function gallery_migration_gallery_assets(array $gallery): array
{
    $assets = [];
    $columns = ['cover_image_path', 'banner_image_path', 'logo_image_path', 'separator_image_path'];
    $galleryRoot = gallery_abs_path((string) ($gallery['folder_path'] ?? ''));
    foreach ($columns as $column) {
        $relativePath = normalize_relative_path((string) ($gallery[$column] ?? ''));
        if ($relativePath === '') {
            continue;
        }
        $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path) || !path_inside($galleryRoot, dirname($path))) {
            continue;
        }
        $assets[] = [
            'scope' => 'gallery',
            'source_gallery_id' => (int) ($gallery['id'] ?? 0),
            'kind' => $column,
            'relative_path' => $relativePath,
            'filename' => basename($path),
            'file_size' => filesize($path) ?: 0,
            'checksum_sha256' => hash_file('sha256', $path) ?: '',
            'mime_type' => gallery_migration_asset_mime($path),
        ];
    }

    return $assets;
}

/**
 * Return source asset descriptors for one image.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return array<int array<string, mixed>>.
 */
function gallery_migration_image_assets(array $image, array $gallery): array
{
    $assets = [];
    $original = gallery_migration_original_asset($image, $gallery);
    if ($original !== null) {
        $assets[] = $original;
    }

    return array_merge($assets, gallery_migration_thumbnail_assets($image, $gallery));
}

/**
 * Return a stored flight map payload for migration.
 *
 * @param int $galleryId Gallery identifier.
 * @return ?array Structured result data for the caller.
 */
function gallery_migration_flight_map_manifest(int $galleryId): ?array
{
    if (!function_exists('Gallery\\Services\\gallery_flight_map_row')) {
        return null;
    }

    $row = gallery_flight_map_row($galleryId);
    if (!$row) {
        return null;
    }

    return [
        'map_source_type' => (string) ($row['map_source_type'] ?? GALLERY_MAP_SOURCE_FLIGHT_PATH),
        'route_text' => (string) ($row['route_text'] ?? ''),
        'resolved_points_json' => (string) ($row['resolved_points_json'] ?? '[]'),
        'unresolved_points_json' => (string) ($row['unresolved_points_json'] ?? '[]'),
        'point_count' => (int) ($row['point_count'] ?? 0),
        'resolved_at' => (string) ($row['resolved_at'] ?? ''),
    ];
}


/**
 * Return source galleries included in one migration tree.
 *
 * @param int $galleryId Root gallery identifier.
 * @param bool $includeSubgalleries Include descendants value.
 * @return array<int,array<string,mixed>> Source galleries in parent-first order.
 */
function gallery_migration_source_galleries(int $galleryId, bool $includeSubgalleries): array
{
    $root = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$root) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.source_missing', 'Source gallery was not found.'));
    }
    if (!$includeSubgalleries) {
        return [$root];
    }

    $result = [];
    $queue = [$root];
    while ($queue) {
        $gallery = array_shift($queue);
        if (!is_array($gallery)) {
            continue;
        }
        $result[] = $gallery;
        foreach (child_galleries((int) ($gallery['id'] ?? 0), false) as $child) {
            if (is_array($child)) {
                $queue[] = $child;
            }
        }
    }

    return $result;
}

/**
 * Build one gallery entry inside a tree migration manifest.
 *
 * @param array $gallery Gallery row.
 * @param array $root Root gallery row.
 * @return array<string,mixed> Manifest gallery entry.
 */
function gallery_migration_gallery_manifest_entry(array $gallery, array $root): array
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    $rootId = (int) ($root['id'] ?? 0);
    $rootFolder = normalize_relative_path((string) ($root['folder_path'] ?? ''));
    $folder = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    $relativeFolder = '';
    if ($galleryId !== $rootId && $rootFolder !== '' && str_starts_with($folder, $rootFolder . '/')) {
        $relativeFolder = normalize_relative_path(substr($folder, strlen($rootFolder) + 1));
    }

    $images = gallery_images($galleryId, false);
    $manifestImages = [];
    foreach ($images as $image) {
        $imageManifest = gallery_migration_image_metadata($image);
        $imageManifest['source_gallery_id'] = $galleryId;
        $imageManifest['assets'] = gallery_migration_image_assets($image, $gallery);
        $manifestImages[] = $imageManifest;
    }

    return [
        'source_id' => $galleryId,
        'parent_source_id' => $galleryId === $rootId ? 0 : (int) ($gallery['parent_id'] ?? 0),
        'source_folder' => $folder,
        'folder_name' => $folder !== '' ? basename($folder) : (string) ($gallery['slug'] ?? $gallery['title'] ?? 'gallery'),
        'relative_folder' => $relativeFolder,
        'gallery' => gallery_migration_gallery_metadata($gallery),
        'gallery_assets' => gallery_migration_gallery_assets($gallery),
        'images' => $manifestImages,
        'flight_map' => gallery_migration_flight_map_manifest($galleryId),
    ];
}

/**
 * Return normalized gallery entries from a tree or legacy manifest.
 *
 * @param array $manifest Manifest value.
 * @return array<int,array<string,mixed>> Gallery entries.
 */
function gallery_migration_manifest_galleries(array $manifest): array
{
    $entries = array_values(array_filter((array) ($manifest['galleries'] ?? []), 'is_array'));
    if ($entries) {
        return $entries;
    }

    if (!isset($manifest['gallery']) || !is_array($manifest['gallery'])) {
        return [];
    }

    return [[
        'source_id' => (int) ($manifest['source_gallery_id'] ?? 0),
        'parent_source_id' => 0,
        'source_folder' => (string) ($manifest['source_gallery_folder'] ?? ''),
        'folder_name' => basename(normalize_relative_path((string) ($manifest['source_gallery_folder'] ?? ''))) ?: (string) (($manifest['gallery']['slug'] ?? $manifest['gallery']['title'] ?? 'gallery')),
        'relative_folder' => '',
        'gallery' => (array) $manifest['gallery'],
        'gallery_assets' => (array) ($manifest['gallery_assets'] ?? []),
        'images' => (array) ($manifest['images'] ?? []),
        'flight_map' => $manifest['flight_map'] ?? null,
    ]];
}

/**
 * Find one source gallery entry in a migration manifest.
 *
 * @param array $manifest Manifest value.
 * @param int $sourceGalleryId Source gallery identifier.
 * @return ?array<string,mixed> Gallery entry or null.
 */
function gallery_migration_manifest_gallery(array $manifest, int $sourceGalleryId): ?array
{
    foreach (gallery_migration_manifest_galleries($manifest) as $entry) {
        if ((int) ($entry['source_id'] ?? 0) === $sourceGalleryId) {
            return $entry;
        }
    }
    return null;
}

/**
 * Build a complete migration manifest for one source gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $includeSubgalleries Include descendant galleries value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_build_manifest(int $galleryId, bool $includeSubgalleries = true): array
{
    $sourceGalleries = gallery_migration_source_galleries($galleryId, $includeSubgalleries);
    $root = $sourceGalleries[0] ?? null;
    if (!is_array($root)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.source_missing', 'Source gallery was not found.'));
    }

    $entries = [];
    $imageCount = 0;
    foreach ($sourceGalleries as $gallery) {
        $entry = gallery_migration_gallery_manifest_entry($gallery, $root);
        $entries[] = $entry;
        $imageCount += count((array) ($entry['images'] ?? []));
    }

    $rootEntry = $entries[0];
    $manifest = [
        'protocol_version' => GALLERY_MIGRATION_PROTOCOL_VERSION,
        'app_version' => gallery_migration_current_version(),
        'source_instance_id' => gallery_migration_instance_id(),
        'source_gallery_id' => $galleryId,
        'source_gallery_folder' => (string) ($root['folder_path'] ?? ''),
        'include_subgalleries' => $includeSubgalleries,
        'generated_at' => now_sql(),
        'galleries' => $entries,
        // Root-level compatibility mirrors keep internal helpers and old diagnostics readable.
        'gallery' => (array) ($rootEntry['gallery'] ?? []),
        'gallery_assets' => (array) ($rootEntry['gallery_assets'] ?? []),
        'images' => (array) ($rootEntry['images'] ?? []),
        'flight_map' => $rootEntry['flight_map'] ?? null,
    ];
    $manifest['counts'] = [
        'galleries' => count($entries),
        'images' => $imageCount,
        'assets' => count(gallery_migration_manifest_asset_refs($manifest)),
    ];
    // The resumable job id must change whenever any imported state changes.
    // Hash the complete deterministic tree payload, including image metadata,
    // gallery assets, original checksums, and existing thumbnail checksums.
    $manifest['migration_id'] = substr(hash('sha256', json_encode([
        'version' => $manifest['app_version'],
        'source_gallery_id' => $galleryId,
        'include_subgalleries' => $includeSubgalleries,
        'galleries' => $entries,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), 0, 32);

    return $manifest;
}

/**
 * Return flat asset references in transfer order.
 *
 * @param array $manifest Manifest value.
 * @return array<int array<string, mixed>>.
 */
function gallery_migration_manifest_asset_refs(array $manifest): array
{
    $assets = [];
    foreach (gallery_migration_manifest_galleries($manifest) as $galleryEntry) {
        $sourceGalleryId = (int) ($galleryEntry['source_id'] ?? 0);
        foreach ((array) ($galleryEntry['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }
            foreach ((array) ($image['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
                $asset['label'] = (string) ($image['relative_path'] ?? $asset['filename'] ?? '');
                $assets[] = $asset;
            }
        }
        foreach ((array) ($galleryEntry['gallery_assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
            $asset['label'] = (string) ($asset['relative_path'] ?? $asset['filename'] ?? '');
            $assets[] = $asset;
        }
    }

    return $assets;
}

/**
 * Return flat asset references with stable transfer keys attached.
 *
 * @param array $manifest Manifest value.
 * @return array<int array<string, mixed>> Transfer assets with asset_key values.
 */
function gallery_migration_manifest_asset_refs_with_keys(array $manifest): array
{
    // $assets stores manifest rows enriched for browser-side resume decisions.
    $assets = [];
    foreach (gallery_migration_manifest_asset_refs($manifest) as $asset) {
        $asset['asset_key'] = gallery_migration_asset_key($asset);
        $assets[] = $asset;
    }

    return $assets;
}

/**
 * Find an image manifest by source id.
 *
 * @param array $manifest Manifest value.
 * @param int $sourceImageId Source image id identifier.
 * @return ?array Structured result data for the caller.
 */
function gallery_migration_manifest_image(array $manifest, int $sourceImageId): ?array
{
    foreach (gallery_migration_manifest_galleries($manifest) as $galleryEntry) {
        foreach ((array) ($galleryEntry['images'] ?? []) as $image) {
            if (is_array($image) && (int) ($image['source_id'] ?? 0) === $sourceImageId) {
                if (!isset($image['source_gallery_id'])) {
                    $image['source_gallery_id'] = (int) ($galleryEntry['source_id'] ?? 0);
                }
                return $image;
            }
        }
    }

    return null;
}

/**
 * Compare a submitted asset reference with one manifest asset.
 *
 * @param array $asset Asset value.
 * @param array $request Request data.
 * @return bool True when the condition matches.
 */
function gallery_migration_asset_matches(array $asset, array $request): bool
{
    if ((string) ($asset['scope'] ?? '') !== (string) ($request['scope'] ?? '')) {
        return false;
    }
    if ((string) ($asset['kind'] ?? '') !== (string) ($request['kind'] ?? '')) {
        return false;
    }
    $assetGalleryId = (int) ($asset['source_gallery_id'] ?? 0);
    $requestGalleryId = (int) ($request['source_gallery_id'] ?? 0);
    if ($requestGalleryId > 0 && $assetGalleryId !== $requestGalleryId) {
        return false;
    }
    if ((string) ($asset['scope'] ?? '') === 'image') {
        if ((int) ($asset['source_image_id'] ?? 0) !== (int) ($request['source_image_id'] ?? 0)) {
            return false;
        }
        if ((string) ($asset['kind'] ?? '') === 'thumbnail') {
            return (int) ($asset['size'] ?? 0) === (int) ($request['size'] ?? 0)
                && (string) ($asset['format'] ?? '') === (string) ($request['format'] ?? '');
        }
        return true;
    }

    return true;
}

/**
 * Find the manifest asset matching a submitted transfer reference.
 *
 * @param array $manifest Manifest value.
 * @param array $request Request data.
 * @return ?array Structured result data for the caller.
 */
function gallery_migration_manifest_asset(array $manifest, array $request): ?array
{
    foreach (gallery_migration_manifest_asset_refs($manifest) as $asset) {
        if (gallery_migration_asset_matches($asset, $request)) {
            return $asset;
        }
    }

    return null;
}

/**
 * Read an asset reference from request input.
 *
 * @param array $input Input value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_asset_ref_from_input(array $input): array
{
    $scope = (string) ($input['scope'] ?? 'image');
    $kind = (string) ($input['kind'] ?? '');
    $format = strtolower((string) ($input['format'] ?? ''));
    return [
        'scope' => in_array($scope, ['image', 'gallery'], true) ? $scope : '',
        'source_gallery_id' => (int) ($input['source_gallery_id'] ?? 0),
        'kind' => $kind,
        'source_image_id' => (int) ($input['source_image_id'] ?? $input['image_id'] ?? 0),
        'size' => (int) ($input['size'] ?? 0),
        'format' => in_array($format, ['jpg', 'webp'], true) ? $format : '',
    ];
}


/**
 * Return whether one source gallery belongs to the API-key authorized export tree.
 *
 * @param int $rootGalleryId Authorized root gallery id.
 * @param int $sourceGalleryId Requested source gallery id.
 * @param bool $includeSubgalleries Include descendants value.
 * @return bool True when the gallery is in scope.
 */
function gallery_migration_source_gallery_allowed(int $rootGalleryId, int $sourceGalleryId, bool $includeSubgalleries): bool
{
    if ($rootGalleryId <= 0 || $sourceGalleryId <= 0) {
        return false;
    }
    if ($rootGalleryId === $sourceGalleryId) {
        return true;
    }
    if (!$includeSubgalleries) {
        return false;
    }

    $root = find_gallery($rootGalleryId, true) ?: find_gallery($rootGalleryId);
    $candidate = find_gallery($sourceGalleryId, true) ?: find_gallery($sourceGalleryId);
    if (!$root || !$candidate) {
        return false;
    }
    $rootFolder = normalize_relative_path((string) ($root['folder_path'] ?? ''));
    $candidateFolder = normalize_relative_path((string) ($candidate['folder_path'] ?? ''));
    return $rootFolder !== '' && str_starts_with($candidateFolder, $rootFolder . '/');
}

/**
 * Resolve a source-side asset request to a local file descriptor.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $request Request data.
 * @param bool $includeSubgalleries Include descendant galleries value.
 * @return array{path:string,filename:string,mime_type:string} Structured result data for the caller.
 */
function gallery_migration_source_asset_descriptor(int $galleryId, array $request, bool $includeSubgalleries = true): array
{
    $sourceGalleryId = (int) ($request['source_gallery_id'] ?? 0);
    if ($sourceGalleryId <= 0) {
        $sourceGalleryId = $galleryId;
    }
    if (!gallery_migration_source_gallery_allowed($galleryId, $sourceGalleryId, $includeSubgalleries)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_scope', 'API key is not allowed to export the requested gallery.'));
    }

    $gallery = find_gallery($sourceGalleryId, true) ?: find_gallery($sourceGalleryId);
    if (!$gallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_missing', 'Gallery was not found.'));
    }

    $scope = (string) ($request['scope'] ?? '');
    $kind = (string) ($request['kind'] ?? '');
    if ($scope === 'gallery') {
        if (!in_array($kind, ['cover_image_path', 'banner_image_path', 'logo_image_path', 'separator_image_path'], true)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
        }
        $relativePath = normalize_relative_path((string) ($gallery[$kind] ?? ''));
        if ($relativePath === '') {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_missing', 'Requested migration asset is not available.'));
        }
        $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
        $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path) || !path_inside($galleryRoot, dirname($path))) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_missing', 'Requested migration asset is not available.'));
        }
        return ['path' => $path, 'filename' => basename($path), 'mime_type' => gallery_migration_asset_mime($path)];
    }

    if ($scope !== 'image') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }
    $image = find_image((int) ($request['source_image_id'] ?? 0));
    if (!$image || (int) ($image['gallery_id'] ?? 0) !== $sourceGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_missing', 'Requested source image was not found.'));
    }

    if ($kind === 'original') {
        $path = image_abs_path($image, $gallery);
    } elseif ($kind === 'thumbnail') {
        $path = thumbnail_abs_path($image, $gallery, (int) ($request['size'] ?? 0), (string) ($request['format'] ?? ''));
    } else {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }

    if (!is_file($path)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_missing', 'Requested migration asset is not available.'));
    }

    return ['path' => $path, 'filename' => basename($path), 'mime_type' => gallery_migration_asset_mime($path, (string) ($image['mime_type'] ?? 'application/octet-stream'))];
}


/**
 * Return the preferred migration ZIP package size for the receiving server.
 *
 * The existing browser-upload settings are reused so gallery migration follows
 * the same soft package-size policy as normal prepared upload ZIPs.
 *
 * @return int Preferred package bytes.
 */
function gallery_migration_package_target_bytes(): int
{
    if (function_exists('Gallery\\Services\\browser_upload_server_upload_limit_bytes')
        && function_exists('Gallery\\Services\\browser_upload_settings')
        && function_exists('Gallery\\Services\\browser_upload_effective_batch_target_bytes')) {
        $settings = browser_upload_settings();
        $uploadLimit = browser_upload_server_upload_limit_bytes();
        return browser_upload_effective_batch_target_bytes(
            $uploadLimit,
            (float) ($settings['zip_size_threshold_ratio'] ?? 0.8),
            (int) ($settings['max_zip_batch_bytes'] ?? 25165824)
        );
    }

    return 24 * 1024 * 1024;
}

/**
 * Return the hard ZIP upload ceiling for source-push requests.
 *
 * @return int Hard package bytes.
 */
function gallery_migration_package_hard_limit_bytes(): int
{
    if (function_exists('Gallery\\Services\\browser_upload_server_upload_limit_bytes')) {
        $limit = browser_upload_server_upload_limit_bytes();
        $reserve = max(262144, (int) floor($limit * 0.02));
        return max(1, $limit - $reserve);
    }

    return 64 * 1024 * 1024;
}

/**
 * Return asset groups that should remain atomic inside migration ZIP packages.
 *
 * Each original image stays with all already-generated thumbnails. Gallery-level
 * assets remain independent so one unusually large branding asset does not force
 * otherwise unrelated gallery assets into an oversized package.
 *
 * @param array $manifest Manifest value.
 * @return array<int,array<int,array<string,mixed>>> Asset groups.
 */
function gallery_migration_package_asset_groups(array $manifest): array
{
    $groups = [];
    foreach (gallery_migration_manifest_galleries($manifest) as $galleryEntry) {
        $sourceGalleryId = (int) ($galleryEntry['source_id'] ?? 0);
        foreach ((array) ($galleryEntry['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $group = [];
            foreach ((array) ($image['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
                $asset['label'] = (string) ($image['relative_path'] ?? $asset['filename'] ?? '');
                $group[] = $asset;
            }
            if ($group) {
                $groups[] = $group;
            }
        }

        foreach ((array) ($galleryEntry['gallery_assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
            $asset['label'] = (string) ($asset['relative_path'] ?? $asset['filename'] ?? '');
            $groups[] = [$asset];
        }
    }

    return $groups;
}

/**
 * Return a deterministic ZIP-entry name for one migration asset.
 *
 * @param array $asset Asset value.
 * @return string Archive entry name.
 */
function gallery_migration_package_entry_name(array $asset): string
{
    return 'assets/' . gallery_migration_asset_key($asset);
}

/**
 * Build the receiving server's deterministic migration package plan.
 *
 * @param array $manifest Manifest value.
 * @param ?int $targetBytes Preferred package bytes.
 * @param ?int $hardBytes Hard upload bytes.
 * @return array<int,array<string,mixed>> Package descriptors.
 */
function gallery_migration_package_plan(array $manifest, ?int $targetBytes = null, ?int $hardBytes = null): array
{
    $hardBytes = max(1, $hardBytes ?? gallery_migration_package_hard_limit_bytes());
    $targetBytes = min($hardBytes, max(1, $targetBytes ?? gallery_migration_package_target_bytes()));
    $packages = [];
    $current = [];
    $currentBytes = 0;

    $flush = static function () use (&$packages, &$current, &$currentBytes, $manifest): void {
        if (!$current) {
            return;
        }
        $keys = array_map(static fn (array $asset): string => gallery_migration_asset_key($asset), $current);
        $packageId = substr(hash('sha256', (string) ($manifest['migration_id'] ?? '') . '|' . implode('|', $keys)), 0, 24);
        $packages[] = [
            'package_id' => $packageId,
            'asset_keys' => $keys,
            'assets' => $current,
            'asset_count' => count($current),
            'source_bytes' => $currentBytes,
        ];
        $current = [];
        $currentBytes = 0;
    };

    foreach (gallery_migration_package_asset_groups($manifest) as $group) {
        $groupBytes = array_sum(array_map(static fn (array $asset): int => max(0, (int) ($asset['file_size'] ?? 0)), $group));
        if ($groupBytes > $hardBytes) {
            $label = (string) (($group[0]['label'] ?? $group[0]['filename'] ?? 'asset'));
            throw new RuntimeException(gallery_migration_t(
                'gallery_migration.error.package_too_large',
                'Migration package for {asset} is larger than the receiving server upload limit.',
                ['asset' => $label]
            ));
        }

        $groupCount = count($group);
        if ($groupCount > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
        }
        if ($current && (
            $currentBytes + $groupBytes > $targetBytes
            || count($current) + $groupCount > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS
        )) {
            $flush();
        }
        foreach ($group as $asset) {
            $current[] = $asset;
        }
        $currentBytes += $groupBytes;

        // An atomic image package may exceed the soft target, exactly like the
        // browser upload workflow. It is flushed alone while remaining below
        // the receiving server hard limit.
        if ($currentBytes > $targetBytes) {
            $flush();
        }
    }
    $flush();

    return $packages;
}

/**
 * Return one package descriptor from a target job.
 *
 * @param array $job Job value.
 * @param string $packageId Package identifier.
 * @return ?array<string,mixed> Package descriptor or null.
 */
function gallery_migration_job_package(array $job, string $packageId): ?array
{
    $packageId = strtolower(trim($packageId));
    foreach ((array) ($job['packages'] ?? []) as $package) {
        if (is_array($package) && hash_equals((string) ($package['package_id'] ?? ''), $packageId)) {
            return $package;
        }
    }
    return null;
}

/**
 * Build a store-only ZIP package from authorized source assets.
 *
 * @param int $rootGalleryId API-key authorized root gallery.
 * @param array $assets Asset descriptors requested by the receiver.
 * @param bool $includeSubgalleries Include descendants value.
 * @return string Temporary ZIP path.
 */
function gallery_migration_build_package_file(int $rootGalleryId, array $assets, bool $includeSubgalleries): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.zip_unavailable', 'PHP ZipArchive is required for gallery migration ZIP packages.'));
    }
    if (!$assets || count($assets) > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_zip_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $zipPath = $tmp . '.zip';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
    }

    try {
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
            }
            $key = gallery_migration_asset_key($asset);
            if ($key === '' || isset($seen[$key])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
            }
            $seen[$key] = true;
            $descriptor = gallery_migration_source_asset_descriptor($rootGalleryId, $asset, $includeSubgalleries);
            $expectedSize = max(0, (int) ($asset['file_size'] ?? 0));
            $actualSize = filesize($descriptor['path']);
            if ($expectedSize > 0 && ($actualSize === false || $actualSize !== $expectedSize)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_checksum_failed', 'Migration asset checksum does not match the manifest.'));
            }
            $expectedChecksum = strtolower((string) ($asset['checksum_sha256'] ?? ''));
            if ($expectedChecksum !== '' && strtolower((string) (hash_file('sha256', $descriptor['path']) ?: '')) !== $expectedChecksum) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_checksum_failed', 'Migration asset checksum does not match the manifest.'));
            }
            $entryName = gallery_migration_package_entry_name($asset);
            if (!$zip->addFile($descriptor['path'], $entryName)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
            }
            if (!method_exists($zip, 'setCompressionName') || !$zip->setCompressionName($entryName, ZipArchive::CM_STORE)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
            }
        }
    } catch (Throwable $exception) {
        $zip->close();
        @unlink($zipPath);
        throw $exception;
    }

    if (!$zip->close()) {
        @unlink($zipPath);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
    }

    return $zipPath;
}

/**
 * Decode asset descriptors posted to the authenticated source package endpoint.
 *
 * @param string $json JSON asset list.
 * @return array<int,array<string,mixed>> Asset descriptors.
 */
function gallery_migration_package_assets_from_json(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !$decoded || count($decoded) > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }
    $assets = [];
    foreach ($decoded as $asset) {
        if (!is_array($asset)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
        }
        $assets[] = $asset;
    }
    return $assets;
}

/**
 * Install every asset from one received migration ZIP package.
 *
 * Package extraction is explicit and entry-name based. No archive path is ever
 * extracted directly into a gallery folder.
 *
 * @param string $jobId Migration job id.
 * @param int $targetGalleryId Receiving parent gallery id.
 * @param string $packageId Package identifier.
 * @param string $zipPath Uploaded or downloaded ZIP path.
 * @return array<string,mixed> Package installation result.
 */
function gallery_migration_install_package_file(string $jobId, int $targetGalleryId, string $packageId, string $zipPath): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.zip_unavailable', 'PHP ZipArchive is required for gallery migration ZIP packages.'));
    }
    if (!is_file($zipPath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_missing', 'Migration ZIP package is not available.'));
    }

    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $package = gallery_migration_job_package($job, $packageId);
    if ($package === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_not_in_job', 'Migration ZIP package is not part of this job.'));
    }
    $assets = array_values(array_filter((array) ($package['assets'] ?? []), 'is_array'));

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }

    try {
        $expectedEntries = [];
        foreach ($assets as $asset) {
            $entryName = gallery_migration_package_entry_name($asset);
            if (isset($expectedEntries[$entryName])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $expectedEntries[$entryName] = $asset;
        }
        if ($zip->numFiles !== count($expectedEntries)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
        }

        $seenEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (!isset($expectedEntries[$name]) || isset($seenEntries[$name])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $seenEntries[$name] = true;
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $expectedSize = max(0, (int) ($expectedEntries[$name]['file_size'] ?? 0));
            $archiveSize = max(0, (int) ($stat['size'] ?? 0));
            if ($expectedSize > 0 && $archiveSize !== $expectedSize) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            if (isset($stat['comp_method']) && (int) $stat['comp_method'] !== ZipArchive::CM_STORE) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
        }

        $installedKeys = [];
        $currentJob = gallery_migration_load_job($jobId);
        $alreadyReceived = (array) ($currentJob['assets_received'] ?? []);
        foreach ($assets as $asset) {
            $assetKey = gallery_migration_asset_key($asset);
            if (isset($alreadyReceived[$assetKey])) {
                $installedKeys[] = $assetKey;
                continue;
            }
            $entryName = gallery_migration_package_entry_name($asset);
            $stream = $zip->getStream($entryName);
            if (!is_resource($stream)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_asset_');
            if ($tmp === false) {
                fclose($stream);
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                fclose($stream);
                @unlink($tmp);
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            try {
                $result = gallery_migration_install_asset_file($jobId, $targetGalleryId, $asset, $tmp);
                $installedKeys[] = (string) ($result['asset_key'] ?? gallery_migration_asset_key($asset));
            } finally {
                @unlink($tmp);
            }
        }
    } finally {
        $zip->close();
    }

    $updatedJob = gallery_migration_load_job($jobId);
    return [
        'ok' => true,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'asset_keys' => $installedKeys,
        'received' => count((array) ($updatedJob['assets_received'] ?? [])),
        'total_assets' => count(gallery_migration_manifest_asset_refs((array) ($updatedJob['manifest'] ?? []))),
    ];
}

/**
 * Create or update a target job from a source manifest.
 *
 * The selected target gallery is a receiving parent. The source root is always
 * created as a new child gallery below it, and source descendants are recreated
 * below that imported root. Persisted source-to-target ids make preparation
 * idempotent when the same migration job is resumed.
 *
 * @param int $targetGalleryId Receiving parent gallery id.
 * @param array $manifest Manifest value.
 * @param string $mode Mode value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_prepare_target_job(int $targetGalleryId, array $manifest, string $mode): array
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.prepare_target_job',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration is temporarily unavailable because the required database schema could not be verified. No migration job was started.'
    );
    $targetGallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$targetGallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.target_missing', 'Target gallery was not found.'));
    }

    gallery_migration_validate_manifest($manifest);
    $compatibility = gallery_migration_compatibility_result((string) ($manifest['app_version'] ?? ''), gallery_migration_current_version());
    if (!$compatibility['ok']) {
        admin_log_event('warning', 'gallery_migration.version_mismatch', 'Gallery migration rejected because source and target versions differ.', [
            'target_gallery_id' => $targetGalleryId,
            'source_version' => $compatibility['source_version'],
            'target_version' => $compatibility['target_version'],
        ]);
        throw new RuntimeException($compatibility['message']);
    }

    $jobId = gallery_migration_job_id($targetGalleryId, $manifest);
    $existingJob = gallery_migration_load_existing_compatible_job($jobId, $targetGalleryId, $manifest);
    $packages = gallery_migration_package_plan($manifest);
    $job = [
        'job_id' => $jobId,
        'mode' => $mode,
        'target_gallery_id' => $targetGalleryId,
        'manifest' => $manifest,
        'compatibility' => $compatibility,
        'gallery_map' => is_array($existingJob) ? (array) ($existingJob['gallery_map'] ?? []) : [],
        'imported_root_gallery_id' => is_array($existingJob) ? (int) ($existingJob['imported_root_gallery_id'] ?? 0) : 0,
        'image_map' => is_array($existingJob) ? (array) ($existingJob['image_map'] ?? []) : [],
        'assets_received' => is_array($existingJob) ? (array) ($existingJob['assets_received'] ?? []) : [],
        'packages' => $packages,
        'created_at' => is_array($existingJob) ? (string) ($existingJob['created_at'] ?? now_sql()) : now_sql(),
        'updated_at' => now_sql(),
    ];

    // Save the resumable shell before creating folders. If the request ends
    // between two child creations, the next preparation continues from the map.
    gallery_migration_save_job($job);
    $job = gallery_migration_prepare_target_tree($job);
    $job = gallery_migration_sync_received_assets($job);

    $counts = (array) ($manifest['counts'] ?? []);
    $counts['galleries'] = (int) ($counts['galleries'] ?? count(gallery_migration_manifest_galleries($manifest)));
    $counts['images'] = (int) ($counts['images'] ?? array_sum(array_map(
        static fn (array $entry): int => count((array) ($entry['images'] ?? [])),
        gallery_migration_manifest_galleries($manifest)
    )));
    $counts['assets'] = count(gallery_migration_manifest_asset_refs($manifest));
    $counts['packages'] = count($packages);
    $counts['received'] = count((array) ($job['assets_received'] ?? []));

    admin_log_event('info', 'gallery_migration.started', 'Gallery migration job started.', [
        'mode' => $mode,
        'job_id' => $jobId,
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'source_gallery_id' => (int) ($manifest['source_gallery_id'] ?? 0),
        'gallery_count' => $counts['galleries'],
        'asset_count' => $counts['assets'],
        'package_count' => $counts['packages'],
        'already_received' => $counts['received'],
        'resumed_existing_job' => is_array($existingJob),
    ]);

    return [
        'job_id' => $jobId,
        'compatibility' => $compatibility,
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => array_values(array_map('intval', (array) ($job['gallery_map'] ?? []))),
        'manifest' => $manifest,
        'packages' => $packages,
        'assets' => gallery_migration_manifest_asset_refs_with_keys($manifest),
        'counts' => $counts,
        'status' => gallery_migration_job_status_payload($job),
    ];
}

/**
 * Ensure the target gallery tree exists for one migration job.
 *
 * @param array $job Job value.
 * @return array<string,mixed> Updated job with a durable gallery_map.
 */
function gallery_migration_prepare_target_tree(array $job): array
{
    $manifest = (array) ($job['manifest'] ?? []);
    $targetParentId = (int) ($job['target_gallery_id'] ?? 0);
    $rootSourceId = (int) ($manifest['source_gallery_id'] ?? 0);
    $galleryMap = (array) ($job['gallery_map'] ?? []);

    foreach (gallery_migration_manifest_galleries($manifest) as $entry) {
        $sourceId = (int) ($entry['source_id'] ?? 0);
        $parentSourceId = (int) ($entry['parent_source_id'] ?? 0);
        $parentTargetId = $sourceId === $rootSourceId
            ? $targetParentId
            : (int) ($galleryMap[(string) $parentSourceId] ?? 0);
        if ($parentTargetId <= 0 || !(find_gallery($parentTargetId, true) ?: find_gallery($parentTargetId))) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }

        $mappedId = (int) ($galleryMap[(string) $sourceId] ?? 0);
        $mappedGallery = $mappedId > 0 ? (find_gallery($mappedId, true) ?: find_gallery($mappedId)) : null;
        if ($mappedGallery && (int) ($mappedGallery['parent_id'] ?? 0) !== $parentTargetId) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }
        if (!$mappedGallery) {
            $metadata = (array) ($entry['gallery'] ?? []);
            $created = create_empty_gallery([
                'title' => trim((string) ($metadata['title'] ?? '')) !== '' ? (string) $metadata['title'] : (string) ($entry['folder_name'] ?? 'Gallery'),
                'description' => (string) ($metadata['description'] ?? ''),
                'parent_id' => $parentTargetId,
                'folder_name' => (string) ($entry['folder_name'] ?? ''),
                'visibility' => 'unpublished',
            ]);
            $mappedId = (int) ($created['id'] ?? 0);
            if ($mappedId <= 0) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_create_failed', 'Could not create an imported gallery.'));
            }
            $galleryMap[(string) $sourceId] = $mappedId;
            $job['gallery_map'] = $galleryMap;
            if ($sourceId === $rootSourceId) {
                $job['imported_root_gallery_id'] = $mappedId;
            }
            gallery_migration_save_job($job);
        }

        gallery_migration_apply_gallery_metadata($mappedId, ['gallery' => (array) ($entry['gallery'] ?? [])], $sourceId !== $rootSourceId, false);
        gallery_migration_apply_flight_map($mappedId, ['flight_map' => $entry['flight_map'] ?? null]);
    }

    $job['gallery_map'] = $galleryMap;
    $job['imported_root_gallery_id'] = (int) ($galleryMap[(string) $rootSourceId] ?? $job['imported_root_gallery_id'] ?? 0);
    gallery_migration_save_job($job);
    return $job;
}

/**
 * Resolve the target gallery that corresponds to one source gallery in a job.
 *
 * @param array $job Job value.
 * @param int $sourceGalleryId Source gallery id.
 * @return int Target gallery id.
 */
function gallery_migration_target_gallery_id(array $job, int $sourceGalleryId): int
{
    $manifest = (array) ($job['manifest'] ?? []);
    if ($sourceGalleryId <= 0) {
        $sourceGalleryId = (int) ($manifest['source_gallery_id'] ?? 0);
    }
    $targetId = (int) (((array) ($job['gallery_map'] ?? []))[(string) $sourceGalleryId] ?? 0);
    if ($targetId <= 0 || !(find_gallery($targetId, true) ?: find_gallery($targetId))) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_target_map_missing', 'Imported gallery mapping is missing from the migration job.'));
    }
    return $targetId;
}

/**
 * Load a compatible existing job without turning resume misses into hard errors.
 *
 * The migration id is derived from the source gallery content. When the admin
 * restarts the same transfer, the same job id is produced and the target
 * gallery can answer which assets it already accepted.
 *
 * @param string $jobId Stable job id for the target gallery and manifest.
 * @param int $targetGalleryId Target gallery id.
 * @param array $manifest Manifest value.
 * @return array<string mixed>|null Existing job, or null when it cannot be safely reused.
 */
function gallery_migration_load_existing_compatible_job(string $jobId, int $targetGalleryId, array $manifest): ?array
{
    try {
        $job = gallery_migration_load_job($jobId);
    } catch (Throwable) {
        return null;
    }

    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        return null;
    }

    $oldManifest = (array) ($job['manifest'] ?? []);
    if ((string) ($oldManifest['migration_id'] ?? '') !== (string) ($manifest['migration_id'] ?? '')) {
        return null;
    }

    return $job;
}

/**
 * Validate the minimum manifest shape before applying anything.
 *
 * @param array $manifest Manifest value.
 */
function gallery_migration_validate_manifest(array $manifest): void
{
    if ((int) ($manifest['protocol_version'] ?? 0) !== GALLERY_MIGRATION_PROTOCOL_VERSION) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.protocol_unsupported', 'Unsupported migration protocol version.'));
    }
    if ((string) ($manifest['app_version'] ?? '') === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.version_missing', 'Migration manifest does not include an app version.'));
    }

    $rootSourceId = (int) ($manifest['source_gallery_id'] ?? 0);
    $entries = gallery_migration_manifest_galleries($manifest);
    if ($rootSourceId <= 0 || !$entries) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_payload_missing', 'Migration manifest does not include gallery data.'));
    }

    $ids = [];
    $rootCount = 0;
    foreach ($entries as $entry) {
        $sourceId = (int) ($entry['source_id'] ?? 0);
        $parentSourceId = (int) ($entry['parent_source_id'] ?? 0);
        if ($sourceId <= 0 || isset($ids[$sourceId]) || !isset($entry['gallery']) || !is_array($entry['gallery']) || !isset($entry['images']) || !is_array($entry['images'])) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }
        if ($sourceId === $rootSourceId) {
            if ($parentSourceId !== 0) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
            }
            $rootCount++;
        } elseif ($parentSourceId <= 0 || !isset($ids[$parentSourceId])) {
            // Manifests are intentionally parent-first. This also prevents
            // cycles and keeps target reconstruction deterministic.
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
        }
        $ids[$sourceId] = true;

        foreach ((array) $entry['images'] as $image) {
            if (!is_array($image) || (int) ($image['source_id'] ?? 0) <= 0) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.images_payload_missing', 'Migration manifest does not include valid image data.'));
            }
            $imageGalleryId = (int) ($image['source_gallery_id'] ?? $sourceId);
            if ($imageGalleryId !== $sourceId) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
            }
        }
    }

    if ($rootCount !== 1 || (empty($manifest['include_subgalleries']) && count($entries) !== 1)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_tree_invalid', 'Migration gallery tree is invalid or cannot be resumed safely.'));
    }
}

/**
 * Apply imported gallery metadata without moving its target folder or copying secrets.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest or gallery-entry value.
 * @param bool $includeSortOrder Preserve source sibling order value.
 * @param bool $includeVisibility Apply source visibility value.
 */
function gallery_migration_apply_gallery_metadata(int $targetGalleryId, array $manifest, bool $includeSortOrder = true, bool $includeVisibility = true): void
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.apply_gallery_metadata',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration metadata could not be applied because the database schema could not be verified.'
    );
    $metadata = (array) ($manifest['gallery'] ?? []);
    $fields = [];
    $values = [];
    $allowed = [
        'title',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'gallery_date',
        'gallery_date_end',
        'description_layout',
        'count_badge_visibility',
        'picture_game_enabled',
        'gps_map_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'background_source',
        'nsfw_enabled',
    ];

    foreach ($allowed as $column) {
        if (($column === 'sort_order' && !$includeSortOrder) || ($column === 'visibility' && !$includeVisibility)) {
            continue;
        }
        if (!array_key_exists($column, $metadata)) {
            continue;
        }
        if (!mutation_schema_optional_column_available('mutation.gallery_migration_gallery_metadata', 'galleries', $column, 'gallery_migration.apply_gallery_metadata')) {
            continue;
        }
        $fields[] = $column . ' = ?';
        $values[] = gallery_migration_gallery_column_value($column, $metadata[$column]);
    }

    if (array_key_exists('slug', $metadata)
        && mutation_schema_optional_column_available('mutation.gallery_migration_gallery_metadata', 'galleries', 'slug', 'gallery_migration.apply_gallery_metadata')) {
        $fields[] = 'slug = ?';
        $values[] = unique_slug(db(), (string) $metadata['slug'], $targetGalleryId);
    }
    if ($fields) {
        $fields[] = 'updated_at = ?';
        $values[] = now_sql();
        $values[] = $targetGalleryId;
        $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    if (function_exists('Gallery\\Services\\sync_entity_tags')) {
        sync_entity_tags('gallery', $targetGalleryId, (string) ($metadata['tags'] ?? ''));
    }
    if (content_localization_schema_ready('gallery') && (array_key_exists('content_language', $metadata) || array_key_exists('translations', $metadata))) {
        content_save_localizations('gallery', $targetGalleryId, $metadata['content_language'] ?? null, $metadata['translations'] ?? []);
    }
}

/**
 * Normalize a gallery metadata value for SQL storage.
 *
 * @param string $column Column value.
 * @param mixed $value Value to process.
 * @return mixed Result value for the caller.
 */
function gallery_migration_gallery_column_value(string $column, mixed $value): mixed
{
    if ($column === 'visibility') {
        return gallery_visibility_storage_value((string) $value);
    }
    if (in_array($column, ['voting_enabled', 'show_filenames', 'picture_game_enabled', 'gps_map_enabled', 'grid_use_for_subgalleries', 'nsfw_enabled'], true)) {
        return !empty($value) ? 1 : 0;
    }
    if (in_array($column, ['sort_order', 'grid_columns', 'grid_rows', 'thumbnail_min_size', 'thumbnail_max_size'], true)) {
        return $value === null || $value === '' ? null : (int) $value;
    }
    if (in_array($column, ['gallery_date', 'gallery_date_end'], true)) {
        return gallery_date_sidecar_value($value);
    }

    return $value;
}

/**
 * Apply stored flight route data without resolving it again on the target.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest value.
 */
function gallery_migration_apply_flight_map(int $targetGalleryId, array $manifest): void
{
    if (!function_exists('Gallery\\Services\\flight_map_schema_ready') || !flight_map_schema_ready()) {
        return;
    }
    $flightMap = $manifest['flight_map'] ?? null;
    if (!is_array($flightMap) || trim((string) ($flightMap['route_text'] ?? '')) === '') {
        return;
    }

    $resolved = json_decode((string) ($flightMap['resolved_points_json'] ?? '[]'), true);
    $unresolved = json_decode((string) ($flightMap['unresolved_points_json'] ?? '[]'), true);
    if (!is_array($resolved)) {
        $resolved = [];
    }
    if (!is_array($unresolved)) {
        $unresolved = [];
    }

    $now = now_sql();
    $stmt = db()->prepare("INSERT INTO gallery_flight_maps (
        gallery_id,
        map_source_type,
        route_text,
        resolved_points_json,
        unresolved_points_json,
        point_count,
        resolved_at,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        map_source_type = VALUES(map_source_type),
        route_text = VALUES(route_text),
        resolved_points_json = VALUES(resolved_points_json),
        unresolved_points_json = VALUES(unresolved_points_json),
        point_count = VALUES(point_count),
        resolved_at = VALUES(resolved_at),
        updated_at = VALUES(updated_at)");
    $stmt->execute([
        $targetGalleryId,
        (string) ($flightMap['map_source_type'] ?? GALLERY_MAP_SOURCE_FLIGHT_PATH),
        (string) ($flightMap['route_text'] ?? ''),
        json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($unresolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        count($resolved),
        (string) ($flightMap['resolved_at'] ?? '') !== '' ? (string) $flightMap['resolved_at'] : $now,
        $now,
        $now,
    ]);
    if (function_exists('Gallery\\Services\\flight_map_clear_runtime_cache')) {
        flight_map_clear_runtime_cache();
    }
}

/**
 * Install one received or pulled asset into its mapped target gallery.
 *
 * @param string $jobId Job id identifier.
 * @param int $targetGalleryId Receiving parent gallery id.
 * @param array $request Request data.
 * @param string $sourcePath Source filesystem path.
 * @return array Structured result data for the caller.
 */
function gallery_migration_install_asset_file(string $jobId, int $targetGalleryId, array $request, string $sourcePath): array
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.install_asset',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration asset installation is temporarily unavailable because the database schema could not be verified. No target asset was changed.'
    );
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $manifest = (array) ($job['manifest'] ?? []);
    $asset = gallery_migration_manifest_asset($manifest, $request);
    if ($asset === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_not_in_manifest', 'Submitted asset is not part of this migration manifest.'));
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_upload_missing', 'Uploaded migration asset is not available.'));
    }

    $checksum = (string) ($asset['checksum_sha256'] ?? '');
    if ($checksum !== '' && (hash_file('sha256', $sourcePath) ?: '') !== $checksum) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_checksum_failed', 'Migration asset checksum does not match the manifest.'));
    }

    $assetTargetGalleryId = gallery_migration_target_gallery_id($job, (int) ($asset['source_gallery_id'] ?? 0));
    $scope = (string) ($asset['scope'] ?? '');
    $result = $scope === 'gallery'
        ? gallery_migration_install_gallery_asset($assetTargetGalleryId, $asset, $sourcePath)
        : gallery_migration_install_image_asset($assetTargetGalleryId, $manifest, $asset, $sourcePath, $job);

    $key = gallery_migration_asset_key($asset);
    $job['assets_received'][$key] = [
        'received_at' => now_sql(),
        'target_gallery_id' => $assetTargetGalleryId,
        'result' => $result,
    ];
    if (!empty($result['source_image_id']) && !empty($result['image_id'])) {
        $job['image_map'][(string) (int) $result['source_image_id']] = (int) $result['image_id'];
    }
    gallery_migration_save_job($job);

    return [
        'ok' => true,
        'job_id' => $jobId,
        'asset_key' => $key,
        'target_gallery_id' => $assetTargetGalleryId,
        'result' => $result,
        'received' => count((array) ($job['assets_received'] ?? [])),
        'total_assets' => count(gallery_migration_manifest_asset_refs($manifest)),
    ];
}

/**
 * Return a stable key for one manifest asset.
 *
 * @param array $asset Asset value.
 * @return string Text result for the caller.
 */
function gallery_migration_asset_key(array $asset): string
{
    $parts = [
        (string) ((int) ($asset['source_gallery_id'] ?? 0)),
        (string) ($asset['scope'] ?? ''),
        (string) ($asset['kind'] ?? ''),
        (string) ((int) ($asset['source_image_id'] ?? 0)),
        (string) ((int) ($asset['size'] ?? 0)),
        (string) ($asset['format'] ?? ''),
        (string) ($asset['relative_path'] ?? ''),
    ];

    return substr(hash('sha256', implode('|', $parts)), 0, 24);
}

/**
 * Synchronize one job with assets that are already present on disk.
 *
 * A browser-side reconnect may happen after the server stored an asset but
 * before the browser received the JSON response. This function lets the target
 * answer from real state instead of relying only on the browser response.
 *
 * @param array $job Job value.
 * @return array<string,mixed> Updated job state.
 */
function gallery_migration_sync_received_assets(array $job): array
{
    $targetGalleryId = (int) ($job['target_gallery_id'] ?? 0);
    $manifest = (array) ($job['manifest'] ?? []);
    if ($targetGalleryId <= 0 || !$manifest) {
        return $job;
    }

    $changed = false;
    foreach (gallery_migration_manifest_asset_refs($manifest) as $asset) {
        $key = gallery_migration_asset_key($asset);
        if (isset($job['assets_received'][$key])) {
            continue;
        }
        try {
            $assetTargetGalleryId = gallery_migration_target_gallery_id($job, (int) ($asset['source_gallery_id'] ?? 0));
        } catch (Throwable) {
            continue;
        }
        $result = gallery_migration_recover_existing_asset($assetTargetGalleryId, $manifest, $asset, $job);
        if ($result === null) {
            continue;
        }

        $job['assets_received'][$key] = [
            'received_at' => now_sql(),
            'recovered' => true,
            'target_gallery_id' => $assetTargetGalleryId,
            'result' => $result,
        ];
        if (!empty($result['source_image_id']) && !empty($result['image_id'])) {
            $job['image_map'][(string) (int) $result['source_image_id']] = (int) $result['image_id'];
        }
        $changed = true;
    }

    if ($changed) {
        gallery_migration_save_job($job);
    }

    return $job;
}

/**
 * Return a result payload when a manifest asset already exists on the target.
 *
 * @param int $targetGalleryId Target gallery id.
 * @param array $manifest Manifest value.
 * @param array $asset Asset value.
 * @param array $job Job value.
 * @return array<string mixed>|null Existing-asset result, or null when the asset is still missing.
 */
function gallery_migration_recover_existing_asset(int $targetGalleryId, array $manifest, array $asset, array $job): ?array
{
    $scope = (string) ($asset['scope'] ?? '');
    if ($scope === 'gallery') {
        return gallery_migration_recover_existing_gallery_asset($targetGalleryId, $asset);
    }

    if ($scope !== 'image') {
        return null;
    }

    $sourceImageId = (int) ($asset['source_image_id'] ?? 0);
    $imageManifest = gallery_migration_manifest_image($manifest, $sourceImageId);
    if ($imageManifest === null) {
        return null;
    }

    if ((string) ($asset['kind'] ?? '') === 'original') {
        return gallery_migration_recover_existing_original($targetGalleryId, $imageManifest, $asset);
    }

    if ((string) ($asset['kind'] ?? '') === 'thumbnail') {
        return gallery_migration_recover_existing_thumbnail($targetGalleryId, $imageManifest, $asset, $job);
    }

    return null;
}

/**
 * Return whether one existing file matches an expected SHA-256 checksum.
 *
 * @param string $path Filesystem path.
 * @param string $expectedChecksum Expected checksum value.
 * @return bool True when the condition matches.
 */
function gallery_migration_existing_file_matches(string $path, string $expectedChecksum): bool
{
    if (!is_file($path)) {
        return false;
    }
    if ($expectedChecksum === '') {
        return true;
    }

    return strtolower((string) (hash_file('sha256', $path) ?: '')) === strtolower($expectedChecksum);
}

/**
 * Recover the received state for one gallery-level asset.
 *
 * @param int $targetGalleryId Target gallery id.
 * @param array $asset Asset value.
 * @return array<string mixed>|null Existing-asset result, or null when missing.
 */
function gallery_migration_recover_existing_gallery_asset(int $targetGalleryId, array $asset): ?array
{
    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$gallery) {
        return null;
    }

    $relativePath = normalize_relative_path((string) ($asset['relative_path'] ?? ''));
    if ($relativePath === '') {
        return null;
    }

    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!path_inside($galleryRoot, dirname($targetPath)) || !gallery_migration_existing_file_matches($targetPath, (string) ($asset['checksum_sha256'] ?? ''))) {
        return null;
    }

    $column = (string) ($asset['kind'] ?? '');
    if (in_array($column, ['cover_image_path', 'banner_image_path', 'logo_image_path', 'separator_image_path'], true)
        && mutation_schema_optional_column_available('mutation.gallery_migration_gallery_asset', 'galleries', $column, 'gallery_migration.recover_gallery_asset')) {
        db()->prepare('UPDATE galleries SET ' . $column . ' = ?, updated_at = ? WHERE id = ?')->execute([$relativePath, now_sql(), $targetGalleryId]);
        $updated = find_gallery($targetGalleryId, true) ?: $gallery;
        write_gallery_sidecar($updated);
    }

    return [
        'scope' => 'gallery',
        'kind' => $column,
        'relative_path' => $relativePath,
        'already_present' => true,
    ];
}

/**
 * Recover the received state for one original image.
 *
 * @param int $targetGalleryId Target gallery id.
 * @param array $imageManifest Image manifest value.
 * @param array $asset Asset value.
 * @return array<string mixed>|null Existing-asset result, or null when missing.
 */
function gallery_migration_recover_existing_original(int $targetGalleryId, array $imageManifest, array $asset): ?array
{
    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$gallery) {
        return null;
    }

    $relativePath = normalize_relative_path((string) ($imageManifest['relative_path'] ?? $asset['relative_path'] ?? $asset['filename'] ?? ''));
    if ($relativePath === '' || str_contains($relativePath, '/')) {
        return null;
    }

    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . $relativePath;
    if (!path_inside($galleryRoot, dirname($targetPath)) || !gallery_migration_existing_file_matches($targetPath, (string) ($asset['checksum_sha256'] ?? ''))) {
        return null;
    }

    $info = scan_image_file_metadata($targetPath, $relativePath);
    if ($info === null) {
        return null;
    }

    $imageId = gallery_migration_upsert_image_metadata($targetGalleryId, $imageManifest, $targetPath, $info);
    return [
        'scope' => 'image',
        'kind' => 'original',
        'source_image_id' => (int) ($asset['source_image_id'] ?? 0),
        'image_id' => $imageId,
        'already_present' => true,
    ];
}

/**
 * Recover the received state for one thumbnail.
 *
 * @param int $targetGalleryId Target gallery id.
 * @param array $imageManifest Image manifest value.
 * @param array $asset Asset value.
 * @param array $job Job value.
 * @return array<string mixed>|null Existing-asset result, or null when missing.
 */
function gallery_migration_recover_existing_thumbnail(int $targetGalleryId, array $imageManifest, array $asset, array $job): ?array
{
    $sourceImageId = (int) ($asset['source_image_id'] ?? 0);
    $imageId = (int) (($job['image_map'][(string) $sourceImageId] ?? 0));
    if ($imageId <= 0) {
        $targetImage = upload_automation_find_image_by_path_uncached($targetGalleryId, normalize_relative_path((string) ($imageManifest['relative_path'] ?? '')));
        $imageId = $targetImage ? (int) $targetImage['id'] : 0;
    }
    if ($imageId <= 0) {
        return null;
    }

    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    $image = find_image($imageId);
    if (!$gallery || !$image) {
        return null;
    }

    $size = (int) ($asset['size'] ?? 0);
    $format = (string) ($asset['format'] ?? '');
    if (!in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        return null;
    }

    $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
    if (!gallery_migration_existing_file_matches($targetPath, (string) ($asset['checksum_sha256'] ?? ''))) {
        return null;
    }

    return [
        'scope' => 'image',
        'kind' => 'thumbnail',
        'source_image_id' => $sourceImageId,
        'image_id' => $imageId,
        'size' => $size,
        'format' => $format,
        'already_present' => true,
    ];
}

/**
 * Return a JSON-safe status payload for one target-side migration job.
 *
 * @param array $job Job value.
 * @param ?array $asset Asset value.
 * @return array<string,mixed> Status payload.
 */
function gallery_migration_job_status_payload(array $job, ?array $asset = null): array
{
    $manifest = (array) ($job['manifest'] ?? []);
    $received = (array) ($job['assets_received'] ?? []);
    $assetKeys = array_keys($received);
    $specificKey = $asset === null ? '' : gallery_migration_asset_key($asset);
    $receivedPackages = 0;
    foreach ((array) ($job['packages'] ?? []) as $package) {
        if (!is_array($package)) {
            continue;
        }
        $packageKeys = array_values(array_filter((array) ($package['asset_keys'] ?? []), 'is_string'));
        if ($packageKeys && count(array_filter($packageKeys, static fn (string $key): bool => isset($received[$key]))) === count($packageKeys)) {
            $receivedPackages++;
        }
    }

    return [
        'ok' => true,
        'action' => 'status',
        'job_id' => (string) ($job['job_id'] ?? ''),
        'mode' => (string) ($job['mode'] ?? ''),
        'target_gallery_id' => (int) ($job['target_gallery_id'] ?? 0),
        'imported_root_gallery_id' => (int) ($job['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => array_values(array_map('intval', (array) ($job['gallery_map'] ?? []))),
        'received' => count($received),
        'total_assets' => count(gallery_migration_manifest_asset_refs($manifest)),
        'received_packages' => $receivedPackages,
        'total_packages' => count((array) ($job['packages'] ?? [])),
        'received_asset_keys' => array_values($assetKeys),
        'asset_key' => $specificKey,
        'asset_received' => $specificKey !== '' && isset($received[$specificKey]),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'server_time' => now_sql(),
    ];
}

/**
 * Load, synchronize, and summarize one target-side migration job.
 *
 * @param string $jobId Migration job id.
 * @param int $targetGalleryId Target gallery id.
 * @param ?array $request Request data.
 * @return array<string mixed> JSON-safe status payload.
 */
function gallery_migration_job_status_response(string $jobId, int $targetGalleryId, ?array $request = null): array
{
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }

    $job = gallery_migration_sync_received_assets($job);
    $manifest = (array) ($job['manifest'] ?? []);
    $asset = null;
    if (is_array($request)) {
        $asset = gallery_migration_manifest_asset($manifest, $request);
    }

    return gallery_migration_job_status_payload($job, $asset);
}

/**
 * Install one gallery-level asset under the target gallery folder.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $asset Asset value.
 * @param string $sourcePath Source filesystem path.
 * @return array Structured result data for the caller.
 */
function gallery_migration_install_gallery_asset(int $targetGalleryId, array $asset, string $sourcePath): array
{
    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$gallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.target_missing', 'Target gallery was not found.'));
    }
    $column = (string) ($asset['kind'] ?? '');
    if (!in_array($column, ['cover_image_path', 'banner_image_path', 'logo_image_path', 'separator_image_path'], true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }
    if (!mutation_schema_optional_column_available('mutation.gallery_migration_gallery_asset', 'galleries', $column, 'gallery_migration.install_gallery_asset')) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is not supported by the current database schema.'));
    }

    $relativePath = normalize_relative_path((string) ($asset['relative_path'] ?? ''));
    if ($relativePath === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }
    if (!is_supported_image_path($relativePath) || @getimagesize($sourcePath) === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_dir_failed', 'Could not create the target asset folder.'));
    }
    if (!path_inside($galleryRoot, $targetDir)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_path_unsafe', 'Migration asset path is outside the target gallery.'));
    }
    gallery_migration_copy_if_same_or_missing($sourcePath, $targetPath, (string) ($asset['checksum_sha256'] ?? ''));

    $stmt = db()->prepare('UPDATE galleries SET ' . $column . ' = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$relativePath, now_sql(), $targetGalleryId]);
    $updated = find_gallery($targetGalleryId, true) ?: $gallery;
    write_gallery_sidecar($updated);

    return ['scope' => 'gallery', 'kind' => $column, 'relative_path' => $relativePath];
}

/**
 * Install one image original or thumbnail asset.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $manifest Manifest value.
 * @param array $asset Asset value.
 * @param string $sourcePath Source filesystem path.
 * @param array $job Job value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_install_image_asset(int $targetGalleryId, array $manifest, array $asset, string $sourcePath, array $job): array
{
    $sourceImageId = (int) ($asset['source_image_id'] ?? 0);
    $imageManifest = gallery_migration_manifest_image($manifest, $sourceImageId);
    if ($imageManifest === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_missing_manifest', 'Migration image metadata is missing.'));
    }

    $kind = (string) ($asset['kind'] ?? '');
    if ($kind === 'original') {
        $imageId = gallery_migration_install_original($targetGalleryId, $imageManifest, $asset, $sourcePath);
        return ['scope' => 'image', 'kind' => 'original', 'source_image_id' => $sourceImageId, 'image_id' => $imageId];
    }

    if ($kind !== 'thumbnail') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_invalid', 'Requested migration asset is invalid.'));
    }

    $imageId = (int) (($job['image_map'][(string) $sourceImageId] ?? 0));
    if ($imageId <= 0) {
        $targetImage = upload_automation_find_image_by_path_uncached($targetGalleryId, normalize_relative_path((string) ($imageManifest['relative_path'] ?? '')));
        $imageId = $targetImage ? (int) $targetImage['id'] : 0;
    }
    if ($imageId <= 0) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.original_required', 'Install the original image before its thumbnails.'));
    }

    gallery_migration_install_thumbnail($targetGalleryId, $imageId, $asset, $sourcePath);
    return ['scope' => 'image', 'kind' => 'thumbnail', 'source_image_id' => $sourceImageId, 'image_id' => $imageId, 'size' => (int) ($asset['size'] ?? 0), 'format' => (string) ($asset['format'] ?? '')];
}

/**
 * Copy a source file to a target path, allowing idempotent retry.
 *
 * @param string $sourcePath Source filesystem path.
 * @param string $targetPath Target filesystem path.
 * @param string $expectedChecksum Expected checksum value.
 */
function gallery_migration_copy_if_same_or_missing(string $sourcePath, string $targetPath, string $expectedChecksum): void
{
    if (is_file($targetPath)) {
        if ($expectedChecksum === '' || (hash_file('sha256', $targetPath) ?: '') === $expectedChecksum) {
            return;
        }
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_conflict', 'A different file already exists at the target asset path.'));
    }

    if (!@copy($sourcePath, $targetPath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_store_failed', 'Could not store the migration asset.'));
    }
    @touch($targetPath, time());
}

/**
 * Install an original image file and upsert its metadata row.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $imageManifest Image manifest value.
 * @param array $asset Asset value.
 * @param string $sourcePath Source filesystem path.
 * @return int Integer result for the caller.
 */
function gallery_migration_install_original(int $targetGalleryId, array $imageManifest, array $asset, string $sourcePath): int
{
    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    if (!$gallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.target_missing', 'Target gallery was not found.'));
    }

    $relativePath = normalize_relative_path((string) ($imageManifest['relative_path'] ?? $asset['relative_path'] ?? $asset['filename'] ?? ''));
    if ($relativePath === '' || str_contains($relativePath, '/')) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_path_invalid', 'Migration supports direct gallery images only for now.'));
    }
    if (!is_supported_image_path($relativePath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_type_invalid', 'Migration original uses an unsupported image type.'));
    }

    $info = scan_image_file_metadata($sourcePath, $relativePath);
    if ($info === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_invalid', 'Migration original is not a valid image.'));
    }

    foreach (['filename', 'title', 'description', 'width', 'height', 'mime_type', 'file_size', 'modified_at', 'checksum_sha256', 'sort_order', 'visibility', 'exif_taken_at', 'exif_camera_make', 'exif_camera_model', 'exif_lens_model', 'exif_focal_length', 'exif_aperture', 'exif_exposure_time', 'exif_iso', 'gps_lat', 'gps_lng', 'gps_altitude', 'gps_extracted_at', 'nsfw_enabled', 'thumbnail_min_size', 'thumbnail_max_size'] as $column) {
        mutation_schema_optional_column_available('mutation.gallery_migration_image_metadata', 'images', $column, 'gallery_migration.install_original');
    }

    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . $relativePath;
    if (!path_inside($galleryRoot, dirname($targetPath))) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_path_unsafe', 'Migration image path is outside the target gallery.'));
    }
    gallery_migration_copy_if_same_or_missing($sourcePath, $targetPath, (string) ($asset['checksum_sha256'] ?? ''));

    return gallery_migration_upsert_image_metadata($targetGalleryId, $imageManifest, $targetPath, $info);
}

/**
 * Insert or update the target image row from manifest metadata.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param array $imageManifest Image manifest value.
 * @param string $targetPath Target filesystem path.
 * @param array $info Info value.
 * @return int Integer result for the caller.
 */
function gallery_migration_upsert_image_metadata(int $targetGalleryId, array $imageManifest, string $targetPath, array $info): int
{
    $relativePath = normalize_relative_path((string) ($imageManifest['relative_path'] ?? basename($targetPath)));
    $existing = upload_automation_find_image_by_path_uncached($targetGalleryId, $relativePath);
    $checksum = hash_file('sha256', $targetPath) ?: null;
    $modifiedAt = is_file($targetPath) ? date('Y-m-d H:i:s', filemtime($targetPath) ?: time()) : now_sql();
    $base = [
        'relative_path' => $relativePath,
        'relative_path_hash' => hash('sha256', $relativePath),
        'filename' => basename($relativePath),
        'title' => (string) ($imageManifest['title'] ?? pathinfo($relativePath, PATHINFO_FILENAME)),
        'description' => (string) ($imageManifest['description'] ?? ''),
        'content_language' => content_language_normalize($imageManifest['content_language'] ?? null),
        'width' => (int) ($imageManifest['width'] ?? $info['width'] ?? 0),
        'height' => (int) ($imageManifest['height'] ?? $info['height'] ?? 0),
        'mime_type' => (string) ($imageManifest['mime_type'] ?? $info['mime'] ?? ''),
        'file_size' => filesize($targetPath) ?: (int) ($imageManifest['file_size'] ?? 0),
        'modified_at' => (string) ($imageManifest['modified_at'] ?? $modifiedAt),
        'checksum_sha256' => $checksum,
        'sort_order' => (int) ($imageManifest['sort_order'] ?? next_gallery_image_sort_order($targetGalleryId)),
        'visibility' => gallery_migration_image_visibility((string) ($imageManifest['visibility'] ?? 'public')),
        'exif_taken_at' => $imageManifest['exif_taken_at'] ?? null,
        'exif_camera_make' => $imageManifest['exif_camera_make'] ?? null,
        'exif_camera_model' => $imageManifest['exif_camera_model'] ?? null,
        'exif_lens_model' => $imageManifest['exif_lens_model'] ?? null,
        'exif_focal_length' => $imageManifest['exif_focal_length'] ?? null,
        'exif_aperture' => $imageManifest['exif_aperture'] ?? null,
        'exif_exposure_time' => $imageManifest['exif_exposure_time'] ?? null,
        'exif_iso' => $imageManifest['exif_iso'] ?? null,
        'gps_lat' => $imageManifest['gps_lat'] ?? null,
        'gps_lng' => $imageManifest['gps_lng'] ?? null,
        'gps_altitude' => $imageManifest['gps_altitude'] ?? null,
        'gps_extracted_at' => $imageManifest['gps_extracted_at'] ?? null,
        'nsfw_enabled' => !empty($imageManifest['nsfw_enabled']) ? 1 : 0,
        'thumbnail_min_size' => $imageManifest['thumbnail_min_size'] ?? null,
        'thumbnail_max_size' => $imageManifest['thumbnail_max_size'] ?? null,
    ];

    $columns = [];
    foreach ($base as $column => $value) {
        if (in_array($column, ['relative_path', 'relative_path_hash'], true)
            || mutation_schema_optional_column_available('mutation.gallery_migration_image_metadata', 'images', $column, 'gallery_migration.upsert_image_metadata')) {
            $columns[$column] = $value;
        }
    }

    if ($existing) {
        $assignments = [];
        $values = [];
        foreach ($columns as $column => $value) {
            if (in_array($column, ['relative_path', 'relative_path_hash'], true)) {
                continue;
            }
            $assignments[] = $column . ' = ?';
            $values[] = $value;
        }
        $assignments[] = 'updated_at = ?';
        $values[] = now_sql();
        $values[] = (int) $existing['id'];
        db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?')->execute($values);
        $imageId = (int) $existing['id'];
    } else {
        $columns['gallery_id'] = $targetGalleryId;
        $columns['created_at'] = now_sql();
        $columns['updated_at'] = now_sql();
        $names = array_keys($columns);
        $stmt = db()->prepare('INSERT INTO images (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')');
        $stmt->execute(array_values($columns));
        $imageId = (int) db()->lastInsertId();
    }

    if (function_exists('Gallery\\Services\\sync_entity_tags')) {
        sync_entity_tags('image', $imageId, (string) ($imageManifest['tags'] ?? ''));
    }
    if (content_localization_schema_ready('image') && (array_key_exists('content_language', $imageManifest) || array_key_exists('translations', $imageManifest))) {
        content_save_localizations('image', $imageId, $imageManifest['content_language'] ?? null, $imageManifest['translations'] ?? []);
    }

    return $imageId;
}

/**
 * Normalize image visibility for target storage.
 *
 * @param string $visibility Visibility value.
 * @return string Text result for the caller.
 */
function gallery_migration_image_visibility(string $visibility): string
{
    $visibility = strtolower(trim($visibility));
    return in_array($visibility, ['draft', 'public', 'private'], true) ? $visibility : 'public';
}

/**
 * Install one generated thumbnail file without regenerating it.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @param int $imageId Image identifier.
 * @param array $asset Asset value.
 * @param string $sourcePath Source filesystem path.
 */
function gallery_migration_install_thumbnail(int $targetGalleryId, int $imageId, array $asset, string $sourcePath): void
{
    $gallery = find_gallery($targetGalleryId, true) ?: find_gallery($targetGalleryId);
    $image = find_image($imageId);
    if (!$gallery || !$image || (int) ($image['gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.image_missing', 'Requested source image was not found.'));
    }
    $size = (int) ($asset['size'] ?? 0);
    $format = (string) ($asset['format'] ?? '');
    if (!in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.thumbnail_invalid', 'Migration thumbnail has an unsupported size or format.'));
    }

    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.thumbnail_invalid', 'Migration thumbnail is not a valid image.'));
    }
    $mime = (string) $info['mime'];
    if (($format === 'jpg' && !in_array($mime, ['image/jpeg', 'image/pjpeg'], true)) || ($format === 'webp' && $mime !== 'image/webp')) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.thumbnail_mime_mismatch', 'Migration thumbnail MIME type does not match its manifest format.'));
    }
    if (max((int) ($info[0] ?? 0), (int) ($info[1] ?? 0)) > $size + 4) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.thumbnail_size_mismatch', 'Migration thumbnail is larger than its declared size.'));
    }
    thumbnail_metadata_preflight_write_schema('gallery_migration.install_thumbnail');

    gallery_thumbs_dir($gallery, true);
    $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.thumbnail_dir_failed', 'Could not create the target thumbnail folder.'));
    }
    gallery_migration_copy_if_same_or_missing($sourcePath, $targetPath, (string) ($asset['checksum_sha256'] ?? ''));
    if (function_exists('Gallery\\Services\\thumbnail_maintenance_summary_cache_clear')) {
        thumbnail_maintenance_summary_cache_clear();
    }
}

/**
 * Complete a migration job and refresh derived caches.
 *
 * @param string $jobId Job id identifier.
 * @param int $targetGalleryId Receiving parent gallery id.
 * @return array Structured result data for the caller.
 */
function gallery_migration_complete_job(string $jobId, int $targetGalleryId): array
{
    mutation_schema_assert_available(
        gallery_migration_schema_status(),
        'gallery_migration.complete_job',
        'Gallery migration requires the current gallery/image database schema. Run pending migrations first.',
        'Gallery migration completion is temporarily unavailable because the database schema could not be verified. The resumable job was left intact.'
    );
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $job = gallery_migration_sync_received_assets($job);

    $manifest = (array) ($job['manifest'] ?? []);
    $totalAssets = count(gallery_migration_manifest_asset_refs($manifest));
    $receivedAssets = count((array) ($job['assets_received'] ?? []));
    if ($receivedAssets < $totalAssets) {
        throw new RuntimeException(gallery_migration_t(
            'gallery_migration.error.incomplete_job',
            'Migration is incomplete: {received} of {total} assets are present on the target.',
            ['received' => $receivedAssets, 'total' => $totalAssets]
        ));
    }

    $imageMap = (array) ($job['image_map'] ?? []);
    $galleryIds = [];
    foreach (gallery_migration_manifest_galleries($manifest) as $entry) {
        $sourceGalleryId = (int) ($entry['source_id'] ?? 0);
        $mappedGalleryId = gallery_migration_target_gallery_id($job, $sourceGalleryId);
        $galleryIds[] = $mappedGalleryId;
        $metadata = (array) ($entry['gallery'] ?? []);
        gallery_migration_apply_gallery_metadata($mappedGalleryId, ['gallery' => $metadata], $sourceGalleryId !== (int) ($manifest['source_gallery_id'] ?? 0), true);
        $coverSourceId = (int) ($metadata['cover_source_id'] ?? 0);
        if ($coverSourceId > 0 && !empty($imageMap[(string) $coverSourceId])) {
            db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?')->execute([
                (int) $imageMap[(string) $coverSourceId],
                now_sql(),
                $mappedGalleryId,
            ]);
        }
        $gallery = find_gallery($mappedGalleryId, true) ?: find_gallery($mappedGalleryId);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
    }

    if (function_exists('Gallery\\Services\\regenerate_public_paths') && public_path_schema_ready()) {
        regenerate_public_paths();
    }
    if (function_exists('Gallery\\Services\\gallery_map_cache_clear_all')) {
        gallery_map_cache_clear_all();
    }

    $job['completed_at'] = now_sql();
    gallery_migration_save_job($job);

    $importedRootId = (int) ($job['imported_root_gallery_id'] ?? 0);
    $importedRoot = $importedRootId > 0 ? (find_gallery($importedRootId, true) ?: find_gallery($importedRootId)) : null;
    admin_log_event('info', 'gallery_migration.completed', 'Gallery migration job completed.', [
        'job_id' => (string) ($job['job_id'] ?? ''),
        'target_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => $importedRootId,
        'gallery_count' => count($galleryIds),
        'assets_received' => $receivedAssets,
        'total_assets' => $totalAssets,
    ]);

    return [
        'ok' => true,
        'job_id' => (string) ($job['job_id'] ?? ''),
        'target_gallery_id' => $targetGalleryId,
        'target_parent_gallery_id' => $targetGalleryId,
        'imported_root_gallery_id' => $importedRootId,
        'gallery_ids' => array_values(array_unique($galleryIds)),
        'assets_received' => $receivedAssets,
        'total_assets' => $totalAssets,
        'gallery_url' => $importedRoot ? gallery_public_url($importedRoot) : '',
        'edit_url' => $importedRootId > 0 ? admin_edit_gallery_tab_url($importedRootId, 'admin-edit-api') : '',
    ];
}

/**
 * Normalize an admin-entered instance URL into a base app URL.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function gallery_migration_normalize_instance_base(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_required', 'Enter the source or target PHP Gallery URL.'));
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = preg_match('~^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?(?:/|$)~i', $url) === 1
            ? 'http://' . $url
            : 'https://' . $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_invalid', 'Enter a valid HTTP or HTTPS PHP Gallery URL.'));
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_scheme', 'Only HTTP and HTTPS migration URLs are supported.'));
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_credentials', 'Do not include credentials in the migration URL.'));
    }

    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    $path = preg_replace('~/index\.php$~i', '', $path) ?? $path;
    $path = rtrim($path, '/');
    return $scheme . '://' . $host . $port . $path;
}

/**
 * Build a front-controller endpoint URL for another PHP Gallery instance.
 *
 * @param string $instanceUrl Instance url URL.
 * @param string $page Page number or page data.
 * @param array $params Params value.
 * @return string Text result for the caller.
 */
function gallery_migration_endpoint_url(string $instanceUrl, string $page, array $params = []): string
{
    $base = gallery_migration_normalize_instance_base($instanceUrl);
    $query = array_merge(['page' => $page], $params);
    return $base . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Fetch JSON from a remote migration endpoint.
 *
 * @param string $url URL used by this workflow.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_get_json(string $url, string $apiKey, ?int $timeoutSeconds = null): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $response = http_fetch_response_with_headers($url, $timeout, [
        'Accept: application/json',
        'X-Gallery-API-Key: ' . $apiKey,
    ]);
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration request failed.'));
    }

    return $decoded;
}

/**
 * POST form fields to a remote migration endpoint and decode JSON.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Fields value.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_post_form_json(string $url, array $fields, string $apiKey, ?int $timeoutSeconds = null): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'X-Gallery-API-Key: ' . $apiKey,
    ];
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($responseBody === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'Remote migration request failed with status ' . $status . '.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_failed', 'Remote migration request failed.'));
        }
    }

    $decoded = json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration request failed.'));
    }

    return $decoded;
}

/**
 * POST one local file to a remote target migration endpoint.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Fields value.
 * @param string $filePath File path filesystem path.
 * @param string $fileName File name value.
 * @param string $mimeType Mime type value.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @param string $fileField Multipart file field name.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_post_file_json(string $url, array $fields, string $filePath, string $fileName, string $mimeType, string $apiKey, ?int $timeoutSeconds = null, string $fileField = 'asset'): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    if (!function_exists('curl_init') || !class_exists(CURLFile::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.curl_required', 'PHP cURL is required for source-push asset transfer.'));
    }
    if (!is_file($filePath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_missing', 'Requested migration asset is not available.'));
    }

    $fields[$fileField] = new CURLFile($filePath, $mimeType, $fileName);
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Gallery-API-Key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($responseBody === false || $status >= 400) {
        throw new RuntimeException($error !== '' ? $error : 'Remote migration asset upload failed with status ' . $status . '.');
    }

    $decoded = json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration asset upload failed.'));
    }

    return $decoded;
}

/**
 * POST form fields to a remote endpoint and stream the binary response to a temporary file.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Form fields.
 * @param string $apiKey API key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return string Temporary file path.
 */
function gallery_migration_http_post_form_to_file(string $url, array $fields, string $apiKey, ?int $timeoutSeconds = null): string
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    if (!function_exists('curl_init')) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.curl_required', 'PHP cURL is required for gallery migration ZIP transfer.'));
    }
    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_package_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        @unlink($tmp);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $handle = curl_init($url);
    if ($handle === false) {
        fclose($out);
        @unlink($tmp);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/zip, application/octet-stream',
            'X-Gallery-API-Key: ' . $apiKey,
        ],
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
    $error = curl_error($handle);
    curl_close($handle);
    fclose($out);
    if ($ok === false || $status >= 400 || ($contentType !== '' && str_contains($contentType, 'application/json'))) {
        $remoteMessage = '';
        $decoded = json_decode((string) @file_get_contents($tmp), true);
        if (is_array($decoded)) {
            $remoteMessage = (string) ($decoded['error'] ?? $decoded['message'] ?? '');
        }
        @unlink($tmp);
        throw new RuntimeException($remoteMessage !== '' ? $remoteMessage : ($error !== '' ? $error : 'Remote migration ZIP download failed with status ' . $status . '.'));
    }
    return $tmp;
}

/**
 * Fetch one remote asset to a temporary file.
 *
 * @param string $url URL used by this workflow.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function gallery_migration_http_get_to_file(string $url, string $apiKey, ?int $timeoutSeconds = null): string
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }

    if (function_exists('curl_init')) {
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            @unlink($tmp);
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
        }
        $handle = curl_init($url);
        if ($handle === false) {
            fclose($out);
            @unlink($tmp);
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
        }
        curl_setopt_array($handle, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
                'X-Gallery-API-Key: ' . $apiKey,
            ],
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($out);
        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            throw new RuntimeException($error !== '' ? $error : 'Remote migration asset download failed with status ' . $status . '.');
        }
        return $tmp;
    }

    try {
        $body = http_fetch_with_headers($url, $timeout, [
            'Accept: application/octet-stream',
            'X-Gallery-API-Key: ' . $apiKey,
        ]);
        file_put_contents($tmp, $body);
        return $tmp;
    } catch (Throwable $exception) {
        @unlink($tmp);
        throw $exception;
    }
}
