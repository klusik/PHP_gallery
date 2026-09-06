<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/assets.php
 * Module Type: Service
 *
 * Purpose:
 *   Enumerates and addresses the transferable assets of a gallery.
 *
 * Responsibilities:
 *   - Enumerate original, thumbnail, and gallery-level assets
 *   - Match a requested asset reference against the manifest
 *   - Refuse assets outside the authorized source gallery subtree
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
