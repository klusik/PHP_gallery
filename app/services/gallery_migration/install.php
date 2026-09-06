<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/install.php
 * Module Type: Service
 *
 * Purpose:
 *   Installs received asset files and registers image rows.
 *
 * Responsibilities:
 *   - Write received originals, thumbnails, and gallery assets into place
 *   - Register or update the matching image metadata rows
 *   - Keep already-identical target files untouched
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
 *   - Loaded by app/services/gallery_migration.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/gallery_migration.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
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
