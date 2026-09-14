<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_bulk_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates reusable bulk gallery state changes without exposing SQL to controllers.
 *
 * Responsibilities:
 *   - Normalize selected gallery identifiers and expand subtree-scoped operations
 *   - Apply coupled voting/Picture Game domain rules through gallery models
 *   - Refresh gallery sidecars for bulk fields represented in sidecar metadata
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
 *   - HTTP feature/schema checks remain controller responsibilities for this Stage 2 migration.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_model_set_gps_map_enabled;
use function Gallery\Models\gallery_model_set_picture_game_enabled;
use function Gallery\Models\gallery_model_set_show_filenames;
use function Gallery\Models\gallery_model_set_visibility;
use function Gallery\Models\gallery_model_set_voting_enabled;

/**
 * Normalize gallery identifiers for model operations.
 *
 * @param array<int,mixed> $galleryIds Candidate gallery identifiers.
 * @return array<int,int> Unique positive identifiers in caller order.
 */
function gallery_bulk_normalize_ids(array $galleryIds): array
{
    return array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $galleryId): bool => $galleryId > 0)));
}

/**
 * Expand selected gallery roots to unique subtree identifiers.
 *
 * @param array<int,mixed> $galleryIds Selected gallery root identifiers.
 * @return array<int,int> Unique positive identifiers for all selected subtrees.
 */
function gallery_bulk_expand_subtree_ids(array $galleryIds): array
{
    $expandedIds = [];
    foreach (gallery_bulk_normalize_ids($galleryIds) as $galleryId) {
        $expandedIds = array_merge($expandedIds, gallery_subtree_ids($galleryId));
    }
    return gallery_bulk_normalize_ids($expandedIds);
}

/**
 * Rewrite sidecars for gallery rows changed by a bulk state mutation.
 *
 * @param array<int,int> $galleryIds Gallery identifiers.
 * @param bool $bypassCache Whether refreshed gallery rows must bypass lookup cache.
 */
function gallery_bulk_refresh_sidecars(array $galleryIds, bool $bypassCache = false): void
{
    foreach ($galleryIds as $galleryId) {
        $gallery = find_gallery($galleryId, $bypassCache);
        if ($gallery) {
            write_gallery_sidecar($gallery);
        }
    }
}

/**
 * Update visibility on the selected gallery rows and refresh their sidecars.
 *
 * @param array<int,mixed> $galleryIds Selected gallery identifiers.
 * @param string $visibility Storage visibility value.
 * @return array<int,int> Normalized changed gallery identifiers.
 */
function gallery_bulk_set_visibility(array $galleryIds, string $visibility): array
{
    $ids = gallery_bulk_normalize_ids($galleryIds);
    if ($ids === []) {
        return [];
    }
    gallery_model_set_visibility($ids, $visibility, now_sql());
    gallery_bulk_refresh_sidecars($ids);
    return $ids;
}

/**
 * Update GPS-map state for selected gallery subtrees and refresh sidecars.
 *
 * @param array<int,mixed> $galleryIds Selected gallery roots.
 * @param ?bool $enabled True/false for explicit state, null for inheritance.
 * @return array<int,int> Expanded changed gallery identifiers.
 */
function gallery_bulk_set_gps_map_enabled(array $galleryIds, ?bool $enabled): array
{
    $ids = gallery_bulk_expand_subtree_ids($galleryIds);
    if ($ids === []) {
        return [];
    }
    gallery_model_set_gps_map_enabled($ids, $enabled, now_sql());
    gallery_bulk_refresh_sidecars($ids, true);
    return $ids;
}

/**
 * Update voting state for selected gallery subtrees.
 *
 * Disabling voting also disables Picture Game to preserve the existing policy.
 *
 * @param array<int,mixed> $galleryIds Selected gallery roots.
 * @param bool $enabled Whether image voting is enabled.
 * @return array<int,int> Expanded changed gallery identifiers.
 */
function gallery_bulk_set_voting_enabled(array $galleryIds, bool $enabled): array
{
    $ids = gallery_bulk_expand_subtree_ids($galleryIds);
    if ($ids === []) {
        return [];
    }
    $now = now_sql();
    gallery_model_set_voting_enabled($ids, $enabled, $now);
    if (!$enabled) {
        gallery_model_set_picture_game_enabled($ids, false, $now);
    }
    return $ids;
}

/**
 * Update filename-display state for selected gallery subtrees and refresh sidecars.
 *
 * @param array<int,mixed> $galleryIds Selected gallery roots.
 * @param bool $enabled Whether filenames are displayed.
 * @return array<int,int> Expanded changed gallery identifiers.
 */
function gallery_bulk_set_show_filenames(array $galleryIds, bool $enabled): array
{
    $ids = gallery_bulk_expand_subtree_ids($galleryIds);
    if ($ids === []) {
        return [];
    }
    gallery_model_set_show_filenames($ids, $enabled, now_sql());
    gallery_bulk_refresh_sidecars($ids);
    return $ids;
}

/**
 * Update Picture Game state for selected gallery subtrees.
 *
 * Enabling Picture Game also enables image voting to preserve the existing policy.
 *
 * @param array<int,mixed> $galleryIds Selected gallery roots.
 * @param bool $enabled Whether Picture Game is enabled.
 * @return array<int,int> Expanded changed gallery identifiers.
 */
function gallery_bulk_set_picture_game_enabled(array $galleryIds, bool $enabled): array
{
    $ids = gallery_bulk_expand_subtree_ids($galleryIds);
    if ($ids === []) {
        return [];
    }
    $now = now_sql();
    gallery_model_set_picture_game_enabled($ids, $enabled, $now);
    if ($enabled) {
        gallery_model_set_voting_enabled($ids, true, $now);
    }
    return $ids;
}
