<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/lightbox_metadata.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable data access helpers for lazy public lightbox metadata.
 *
 * Responsibilities:
 *   - Count gallery photos using the same ordering rules as public gallery pages
 *   - Fetch small ordered windows of image rows for asynchronous lightbox navigation
 *   - Compute stable zero-based positions for direct image links and visible cards
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
 *   2026-05-17
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Models\lightbox_metadata_model_fetch_images;
use function Gallery\Models\lightbox_metadata_model_image_position;
use function Gallery\Models\lightbox_metadata_model_state_summary;
use function Gallery\Models\lightbox_metadata_model_total_count;


/**
 * Return true when the current public lightbox request must hide NSFW image rows.
 *
 * @param array $gallery Gallery database row used for inherited NSFW checks.
 * @param bool $publicOnly True when the current request uses anonymous visitor visibility.
 * @return bool True when restricted image rows must not be exposed to the browser.
 */
function gallery_lightbox_excludes_restricted_nsfw(array $gallery, bool $publicOnly): bool
{
    if (!$publicOnly || !nsfw_guard_schema_ready()) {
        return false;
    }
    return !visitor_can_access_nsfw_content();
}

/**
 * Return true when a whole gallery is NSFW-gated for the current public lightbox request.
 *
 * @param array $gallery Gallery database row used for inherited NSFW checks.
 * @param bool $publicOnly True when the current request uses anonymous visitor visibility.
 * @param bool $excludeRestrictedNsfw True when restricted image rows should be removed.
 * @return bool True when no image metadata may be exposed.
 */
function gallery_lightbox_gallery_restricted_by_nsfw(array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw): bool
{
    return $publicOnly
        && $excludeRestrictedNsfw
        && nsfw_guard_schema_ready()
        && gallery_nsfw_requirement($gallery) !== null;
}

/**
 * Build the semantic visibility scope shared by lightbox model queries.
 *
 * @return array{public_only:bool,exclude_restricted_nsfw:bool}
 */
function gallery_lightbox_image_scope(bool $publicOnly, bool $excludeRestrictedNsfw = false): array
{
    return [
        'public_only' => $publicOnly,
        'exclude_restricted_nsfw' => $excludeRestrictedNsfw && nsfw_guard_schema_ready(),
    ];
}

/**
 * Return the full top-level image count plus a gallery-wide image-state revision.
 *
 * The revision is the maximum persisted image updated_at timestamp across the same
 * visibility scope used by the caller. It lets mutation completion verify changes
 * that may live on a different pagination page without loading every image row.
 *
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be included.
 * @param bool $excludeRestrictedNsfw True when restricted NSFW rows should be removed.
 * @return array{count:int,revision:string} Full count and aggregate image revision.
 */
function gallery_lightbox_state_summary(array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw = false): array
{
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return ['count' => 0, 'revision' => ''];
    }

    $scope = gallery_lightbox_image_scope($publicOnly, $excludeRestrictedNsfw);
    return lightbox_metadata_model_state_summary((int) $gallery['id'], $scope['public_only'], $scope['exclude_restricted_nsfw']);
}

/**
 * Count ordered top-level photos in one gallery.
 *
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be counted.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return int Number of rows matching the request visibility rules.
 */
function gallery_lightbox_total_count(array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw = false): int
{
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return 0;
    }

    $scope = gallery_lightbox_image_scope($publicOnly, $excludeRestrictedNsfw);
    return lightbox_metadata_model_total_count((int) $gallery['id'], $scope['public_only'], $scope['exclude_restricted_nsfw']);
}

/**
 * Fetch one ordered window of image rows with aggregate vote scores.
 *
 * The public gallery page uses this for the visible photo page. The JSON endpoint
 * uses the same helper for lazy lightbox metadata, keeping order and visibility
 * rules identical between server-rendered cards and asynchronous navigation.
 *
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be selected.
 * @param int $offset Zero-based row offset.
 * @param int|null $limit Maximum rows to return, or null for all rows.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return array<int,array<string,mixed>> Ordered image rows.
 */
function gallery_lightbox_fetch_images(array $gallery, bool $publicOnly, int $offset = 0, ?int $limit = null, bool $excludeRestrictedNsfw = false): array
{
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return [];
    }

    $scope = gallery_lightbox_image_scope($publicOnly, $excludeRestrictedNsfw);
    return lightbox_metadata_model_fetch_images(
        (int) $gallery['id'],
        $scope['public_only'],
        max(0, $offset),
        $limit,
        $scope['exclude_restricted_nsfw']
    );
}

/**
 * Return the zero-based ordered position of one image under the supplied visibility rules.
 *
 * @param array $image Image database row whose gallery-local position is needed.
 * @param array $gallery Gallery database row.
 * @param bool $publicOnly True when only public image rows should be considered.
 * @param bool $excludeRestrictedNsfw True when lightbox-ineligible NSFW rows should be removed.
 * @return int Zero-based position, or -1 when the image cannot be part of the requested list.
 */
function gallery_lightbox_image_position(array $image, array $gallery, bool $publicOnly, bool $excludeRestrictedNsfw = false): int
{
    if ((int) ($image['gallery_id'] ?? 0) !== (int) ($gallery['id'] ?? 0)) {
        return -1;
    }
    if ($publicOnly && (string) ($image['visibility'] ?? '') !== 'public') {
        return -1;
    }
    if ($excludeRestrictedNsfw && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
        return -1;
    }
    if (gallery_lightbox_gallery_restricted_by_nsfw($gallery, $publicOnly, $excludeRestrictedNsfw)) {
        return -1;
    }

    $scope = gallery_lightbox_image_scope($publicOnly, $excludeRestrictedNsfw);
    return lightbox_metadata_model_image_position(
        (int) $gallery['id'],
        $image,
        $scope['public_only'],
        $scope['exclude_restricted_nsfw']
    );
}
