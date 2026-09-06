<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/manifest.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds, reads, and validates the migration manifest.
 *
 * Responsibilities:
 *   - Build the manifest for a gallery subtree including flight-map data
 *   - Look up galleries, images, and asset references inside a manifest
 *   - Validate a received manifest before any target mutation
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
