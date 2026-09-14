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
 * @return string Unique normalized gallery slug.
 */
function gallery_editor_unique_slug(string $value, int $excludeGalleryId = 0): string
{
    $base = slugify($value);
    $candidate = $base;
    $counter = 2;
    while (gallery_model_slug_exists($candidate, $excludeGalleryId)) {
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
 * @param array<string,mixed> $fallbackGallery Existing gallery row used if reload fails.
 * @return array<string,mixed> Refreshed or fallback gallery row.
 */
function gallery_editor_set_cover_image(int $galleryId, int $coverImageId, array $fallbackGallery): array
{
    gallery_model_update_fields($galleryId, ['cover_image_id' => $coverImageId], now_sql());
    $updated = find_gallery($galleryId, true) ?: find_gallery($galleryId) ?: $fallbackGallery;
    if ($updated) {
        write_gallery_sidecar($updated);
    }
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
