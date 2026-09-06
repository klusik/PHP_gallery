<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/recovery.php
 * Module Type: Service
 *
 * Purpose:
 *   Reuses matching target files so a resumed job skips transfers.
 *
 * Responsibilities:
 *   - Detect target files whose checksum already matches the manifest
 *   - Recover gallery assets, originals, and thumbnails without re-download
 *   - Keep recovery conservative so a mismatch always re-transfers
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
