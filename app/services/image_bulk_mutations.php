<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/image_bulk_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates reusable bulk image row mutations without exposing SQL to controllers.
 *
 * Responsibilities:
 *   - Normalize selected image identifiers
 *   - Persist bulk image visibility and NSFW state through models
 *   - Expose direct image-row counts needed by safe rollback decisions
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
 *   - Filesystem image mutations remain in the existing gallery mutation services.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Core\now_sql;
use function Gallery\Models\image_model_count_for_gallery;
use function Gallery\Models\image_model_set_nsfw_enabled;
use function Gallery\Models\image_model_set_visibility;

/**
 * Normalize image identifiers for model operations.
 *
 * @param array<int,mixed> $imageIds Candidate image identifiers.
 * @return array<int,int> Unique positive identifiers in caller order.
 */
function image_bulk_normalize_ids(array $imageIds): array
{
    return array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $imageId): bool => $imageId > 0)));
}

/**
 * Persist visibility for selected images.
 *
 * @param array<int,mixed> $imageIds Selected image identifiers.
 * @param string $visibility Image visibility value.
 * @return array<int,int> Normalized changed image identifiers.
 */
function image_bulk_set_visibility(array $imageIds, string $visibility): array
{
    if (!in_array($visibility, ['draft', 'public', 'private'], true)) {
        throw new InvalidArgumentException('Unsupported image visibility value.');
    }
    $ids = image_bulk_normalize_ids($imageIds);
    if ($ids === []) {
        return [];
    }
    image_model_set_visibility($ids, $visibility, now_sql());
    return $ids;
}

/**
 * Persist NSFW Guard state for selected images.
 *
 * @param array<int,mixed> $imageIds Selected image identifiers.
 * @param bool $enabled Whether NSFW Guard is enabled.
 * @return array<int,int> Normalized changed image identifiers.
 */
function image_bulk_set_nsfw_enabled(array $imageIds, bool $enabled): array
{
    $ids = image_bulk_normalize_ids($imageIds);
    if ($ids === []) {
        return [];
    }
    image_model_set_nsfw_enabled($ids, $enabled, now_sql());
    return $ids;
}

/**
 * Count direct image rows currently stored in one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Number of direct image rows.
 */
function gallery_direct_image_count(int $galleryId): int
{
    return image_model_count_for_gallery($galleryId);
}
