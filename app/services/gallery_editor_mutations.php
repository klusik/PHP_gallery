<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_editor_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides gallery editor persistence use-cases above the gallery model layer.
 *
 * Responsibilities:
 *   - Generate unique gallery slugs without exposing database handles to controllers
 *   - Persist semantic gallery field maps through the gallery model
 *   - Persist title-picture changes and refresh sidecar state
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
 *   - Request parsing and response rendering remain controller responsibilities.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/gallery_edit_concurrency.php';

use function Gallery\Core\now_sql;
use function Gallery\Core\slugify;
use function Gallery\Models\gallery_model_clear_background_sources;
use function Gallery\Models\gallery_model_slug_exists;
use function Gallery\Models\gallery_model_update_fields;

/**
 * Generate a unique gallery slug while excluding the gallery currently edited.
 *
 * @param string $value User supplied slug or title fallback.
 * @param int $excludeGalleryId Existing gallery identifier excluded from collisions.
 * @param ?callable(string):bool $collision Optional model-read adapter for a legacy caller-owned connection.
 * @return string Unique normalized gallery slug.
 */
function gallery_editor_unique_slug(string $value, int $excludeGalleryId = 0, ?callable $collision = null): string
{
    $base = slugify($value);
    $candidate = $base;
    $counter = 2;
    while ($collision !== null ? $collision($candidate) : gallery_model_slug_exists($candidate, $excludeGalleryId)) {
        $candidate = $base . '-' . $counter;
        $counter++;
    }
    return $candidate;
}

/**
 * Persist semantic gallery editor fields and update the row timestamp.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $fields Editable gallery column-value map.
 */
function gallery_editor_update_fields(int $galleryId, array $fields): void
{
    gallery_model_update_fields($galleryId, $fields, now_sql());
}

/**
 * Persist a gallery title picture and return the refreshed gallery row.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $coverImageId Image identifier used as title picture.
 * @param array<string,mixed> $fallbackGallery Legacy caller snapshot retained for signature compatibility, never used for filesystem writes.
 * @return array<string,mixed> Authoritatively reloaded gallery row.
 */
function gallery_editor_set_cover_image(int $galleryId, int $coverImageId, array $fallbackGallery): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return gallery_editor_set_cover_image_owned($galleryId, $coverImageId, $fallbackGallery);
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Persist a gallery cover and publish its refreshed sidecar.
 *
 * Internal implementation: enter through gallery_editor_set_cover_image() so
 * reads, early returns and failure cleanup remain inside the same writer lease.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $coverImageId Image identifier selected as title picture.
 * @param array<string,mixed> $fallbackGallery Legacy snapshot retained only for signature compatibility; never used to choose a filesystem path.
 * @return array<string,mixed> Fresh gallery row after the cover update; missing rows or changed image ownership raise a conflict.
 * @author Rudolf Klusal
 */
function gallery_editor_set_cover_image_owned(int $galleryId, int $coverImageId, array $fallbackGallery): array
{
    $gallery = find_gallery($galleryId, true);
    $image = find_image($coverImageId, true);
    if (!$gallery || !$image || (int) ($image['gallery_id'] ?? 0) !== $galleryId) {
        throw new GalleryEditConflict(gallery_edit_comparison($gallery ?? []));
    }
    gallery_model_update_fields($galleryId, ['cover_image_id' => $coverImageId], now_sql());
    $updated = find_gallery($galleryId, true);
    if (!$updated) {
        throw new GalleryEditConflict([]);
    }
    write_gallery_sidecar($updated);
    return $updated;
}

/**
 * Clear all explicit gallery background-source overrides.
 *
 * @return int Number of rows reported as changed.
 */
function gallery_clear_all_background_sources(): int
{
    return gallery_model_clear_background_sources(now_sql());
}
