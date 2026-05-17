<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/gallery_lightbox.php
 * Module Type: Controller
 *
 * Purpose:
 *   Streams lazy JSON metadata for public lightbox navigation.
 *
 * Responsibilities:
 *   - Enforce the same gallery access rules as the public gallery page
 *   - Return small ordered windows of image metadata for asynchronous navigation
 *   - Keep visitor-specific vote state out of shared caches
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

/**
 * Send a JSON response for the lazy lightbox endpoint.
 *
 * @param array $payload JSON-serializable response payload.
 * @param int $status HTTP status code.
 * @return void
 */
function gallery_lightbox_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Convert one image row into the browser metadata shape used by lazy lightbox navigation.
 *
 * @param array $image Image database row with a score column.
 * @param array $gallery Gallery database row.
 * @param int $index Zero-based gallery-local lightbox index.
 * @param bool $mapsAllowed True when GPS metadata may be exposed for this gallery.
 * @param bool $votingAllowed True when vote controls should be available.
 * @param array<int,int> $votesById Current viewer vote state keyed by image id.
 * @return array<string,mixed> JSON-ready item payload.
 */
function gallery_lightbox_json_item(array $image, array $gallery, int $index, bool $mapsAllowed, bool $votingAllowed, array $votesById): array
{
    $thumbnailBundle = public_render_profile_with_thumbnail_purpose('lazy lightbox bundle discovery', static fn (): array => thumbnail_bundle($image));
    $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
    $previewUrl = public_render_profile_with_thumbnail_purpose('lazy lightbox preview 1600', static fn (): string => thumbnail_bundle_url($thumbnailBundle, 1600));
    $mapPoint = $mapsAllowed && image_has_gps($image)
        ? public_render_profile_with_thumbnail_purpose('lazy lightbox map preview 300', static fn (): array => image_map_point($image, $gallery, true, $thumbnailBundle))
        : null;
    $imageId = (int) $image['id'];
    $score = (int) ($image['score'] ?? 0);
    $vote = $votesById[$imageId] ?? 0;

    return [
        'id' => $imageId,
        'index' => $index,
        'gallery_id' => (int) $gallery['id'],
        'full_src' => $mediaUrl,
        'preview_src' => $previewUrl,
        'page_url' => image_public_url($image, $gallery),
        'gallery_url' => gallery_public_url($gallery),
        'title' => public_image_display_title($image, $gallery),
        'description' => (string) ($image['description'] ?? ''),
        'score' => $score,
        'user_vote' => $vote,
        'width' => (int) ($image['width'] ?? 0),
        'height' => (int) ($image['height'] ?? 0),
        'map_point' => $mapPoint,
        'voting_allowed' => $votingAllowed,
        'vote_form_html' => render_vote_form_html($imageId, $score, $vote, $votingAllowed),
    ];
}

/**
 * Return an asynchronous ordered metadata window for the public gallery lightbox.
 *
 * @return void
 */
function cms_gallery_lightbox_data(): void
{
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    $anonymousPreview = admin_anonymous_preview_active();
    $viewer = $anonymousPreview ? null : (current_user_is_known_under_18() ? null : current_user());
    $publicOnly = !$viewer;

    if (!$gallery) {
        gallery_lightbox_json_response(['ok' => false, 'error' => 'not_found'], 404);
        return;
    }
    if (!$viewer && !visitor_can_access_gallery($gallery)) {
        gallery_lightbox_json_response(['ok' => false, 'error' => 'forbidden'], 403);
        return;
    }

    $limit = max(1, min(80, (int) ($_GET['limit'] ?? 60)));
    $total = gallery_lightbox_total_count($gallery, $publicOnly, true);
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    if ($total > 0 && $offset >= $total) {
        $offset = max(0, $total - $limit);
    }

    $images = gallery_lightbox_fetch_images($gallery, $publicOnly, $offset, $limit, true);
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    $votesById = current_votes_for_images($imageIds);
    $mapsAllowed = gallery_allows_gps_maps($gallery);
    $votingAllowed = gallery_voting_allowed($gallery);
    $items = [];

    foreach ($images as $rowIndex => $image) {
        if ($publicOnly && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
            continue;
        }
        $items[] = gallery_lightbox_json_item($image, $gallery, $offset + $rowIndex, $mapsAllowed, $votingAllowed, $votesById);
    }

    gallery_lightbox_json_response([
        'ok' => true,
        'gallery_id' => (int) $gallery['id'],
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'count' => count($items),
        'items' => $items,
    ]);
}
