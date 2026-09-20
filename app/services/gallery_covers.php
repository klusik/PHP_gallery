<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_covers.php
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;
use function Gallery\Models\gallery_model_cover_image_path_column_exists;
use function Gallery\Models\gallery_model_set_cover_image_id;
use function Gallery\Models\gallery_model_set_cover_image_path;
use function Gallery\Models\image_model_first_direct_cover_candidate;

/**
 * Gallery cover model.
 *
 * This module resolves gallery cover images, cover asset URLs, collage candidates, and sidecar cover choices. It avoids the separate theme background and favicon storage paths.
 *
 * @param int $galleryId Gallery identifier.
 */
function ensure_gallery_cover(int $galleryId): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery || !empty($gallery['cover_image_id'])) {
        return;
    }
    $cover = image_model_first_direct_cover_candidate($galleryId, false);
    if (!$cover) {
        return;
    }
    gallery_model_set_cover_image_id($galleryId, (int) $cover['id'], now_sql());
}

/**
 * Handles gallery cover path logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_path(array $gallery): ?string
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim((string) ($gallery['cover_image_path'] ?? ''));
    return $path !== '' ? $path : null;
}

/**
 * Return whether the shared schema observation confirms cover-asset storage.
 *
 * @return bool Result produced by this operation.
 */
function gallery_cover_asset_schema_ready(): bool
{
    // Reuse the canonical request snapshot and its migration invalidation.
    return schema_inspection_is_available(schema_inspection_column('galleries', 'cover_image_path'));
}

/**
 * Handles set gallery cover path logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $relativePath Input used by this operation.
 */
function set_gallery_cover_path(int $galleryId, ?string $relativePath): void
{
    if (!gallery_cover_asset_schema_ready()) {
        return;
    }
    gallery_model_set_cover_image_path(
        $galleryId,
        $relativePath !== null && $relativePath !== '' ? $relativePath : null,
        now_sql()
    );
}

/**
 * Handles gallery cover image logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_image(int $galleryId, bool $publicOnly): ?array
{
    return gallery_direct_cover_image($galleryId, $publicOnly);
}

/**
 * Handles gallery direct cover image logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_direct_cover_image(int $galleryId, bool $publicOnly): ?array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return $cache[$cacheKey] = null;
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover && !str_contains((string) $cover['relative_path'], '/') && (!$publicOnly || $cover['visibility'] === 'public')) {
            return $cache[$cacheKey] = $cover;
        }
    }
    return $cache[$cacheKey] = image_model_first_direct_cover_candidate($galleryId, $publicOnly);
}

/**
 * Handles gallery cover asset url logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_asset_url(array $gallery, bool $publicOnly): string
{
    if ($publicOnly && gallery_access_requirement($gallery) !== null) {
        return '';
    }
    // $coverPath stores an intermediate value used by the surrounding gallery workflow.
    $coverPath = gallery_cover_path($gallery);
    if ($coverPath === null) {
        return '';
    }
    return url_for('gallery_cover_asset', ['id' => (int) $gallery['id']]);
}

/**
 * Handles gallery cover collage images logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @param mixed $limit Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_collage_images(int $galleryId, bool $publicOnly, int $limit = 4): array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0') . ':' . $limit;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $images stores this steps working value.
    $images = [];
    // Parent galleries without direct images borrow covers from child galleries.
    foreach (child_galleries($galleryId, $publicOnly) as $child) {
        if ($publicOnly && gallery_access_requirement($child) !== null) {
            continue;
        }
        // Variable $cover stores this steps working value.
        $cover = gallery_direct_cover_image((int) $child['id'], $publicOnly);
        if ($cover) {
            $images[(int) $cover['id']] = $cover;
        }
        if (count($images) >= $limit) {
            break;
        }
        foreach (gallery_cover_collage_images((int) $child['id'], $publicOnly, $limit - count($images)) as $descendantCover) {
            $images[(int) $descendantCover['id']] = $descendantCover;
            if (count($images) >= $limit) {
                break 2;
            }
        }
    }
    return $cache[$cacheKey] = array_values($images);
}

/**
 * Handles gallery cover choices logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_choices(int $galleryId, bool $publicOnly): array
{
    // $choices stores an intermediate value used by the surrounding gallery workflow.
    $choices = [];
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = find_gallery($galleryId);
    if (!$root) {
        return [];
    }
    // $stack stores an intermediate value used by the surrounding gallery workflow.
    $stack = [$root];
    while ($stack) {
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = array_shift($stack);
        // $cover stores an intermediate value used by the surrounding gallery workflow.
        $cover = gallery_direct_cover_image((int) $gallery['id'], $publicOnly);
        if ($cover) {
            $choices[] = [
                'gallery_id' => (int) $gallery['id'],
                'gallery_title' => (string) $gallery['title'],
                'image' => $cover,
            ];
        }
        foreach (child_galleries((int) $gallery['id'], $publicOnly) as $child) {
            $stack[] = $child;
        }
    }
    return $choices;
}

/**
 * Handles apply gallery cover from sidecar logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 */
function apply_gallery_cover_from_sidecar(array $gallery): void
{
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar(gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json');
    if (empty($metadata['cover']) || !is_string($metadata['cover'])) {
        return;
    }
    try {
        // Variable $coverPath stores this steps working value.
        $coverPath = normalize_relative_path($metadata['cover']);
    } catch (RuntimeException) {
        return;
    }
    // Variable $image stores this steps working value.
    $image = find_image_by_path((int) $gallery['id'], $coverPath);
    if (!$image) {
        return;
    }
    gallery_model_set_cover_image_id((int) $gallery['id'], (int) $image['id'], now_sql());
}

