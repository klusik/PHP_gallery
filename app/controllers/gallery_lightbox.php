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

namespace Gallery\Controllers;

use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\current_user;
use function Gallery\Core\csrf_token;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\url_for;
use function Gallery\Services\current_user_is_known_under_18;
use function Gallery\Services\current_viewer;
use function Gallery\Services\current_votes_for_images;
use function Gallery\Services\content_localize_entities;
use function Gallery\Services\content_localize_entity;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_allows_gps_maps;
use function Gallery\Services\gallery_benchmark_record_auxiliary_render;
use function Gallery\Services\gallery_benchmark_request_trace_enabled;
use function Gallery\Services\gallery_benchmark_trace_mark;
use function Gallery\Services\gallery_lightbox_fetch_images;
use function Gallery\Services\gallery_lightbox_total_count;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\image_has_gps;
use function Gallery\Services\image_map_point;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_render_profile_snapshot;
use function Gallery\Services\public_render_profile_start;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_bundles_preload;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\lightbox_zoom_quality_candidates;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\visitor_can_access_nsfw_content;
use function Gallery\Services\viewer_favourites_for_image_ids;
use function Gallery\Services\viewer_favourites_storage_available;
use function Gallery\Services\viewer_source_image_can_reference;

/**
 * Send a JSON response for the lazy lightbox endpoint.
 *
 * @param array $payload JSON-serializable response payload.
 * @param int $status HTTP status code.
 */
function gallery_lightbox_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Release the PHP session lock before expensive read-only lightbox metadata work.
 *
 * Gallery authorization, viewer identity, vote/favourite state, NSFW access, and
 * the CSRF token required by rendered vote forms must be captured first. Closing
 * the session afterwards keeps the in-memory values readable while allowing a
 * pagination or reload request from the same visitor to run concurrently.
 */
function cms_release_gallery_lightbox_session_lock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
            gallery_benchmark_trace_mark('lightbox_session_release_begin');
        }
        session_write_close();
        if (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
            gallery_benchmark_trace_mark('lightbox_session_release_end');
        }
    }
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
    $mediaUrl = url_for('media', ['id' => $image['id']]);
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
        'quality_sources' => lightbox_zoom_quality_candidates($image, $previewUrl, $mediaUrl, $thumbnailBundle),
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
 */
function cms_gallery_lightbox_data(): void
{
    $benchmarkTracing = function_exists('Gallery\\Services\\gallery_benchmark_request_trace_enabled') && gallery_benchmark_request_trace_enabled();
    if ($benchmarkTracing) {
        gallery_benchmark_trace_mark('lightbox_controller_enter');
        public_render_profile_start('gallery_lightbox', (int) ($_GET['id'] ?? 0));
    }
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
    $contentLanguage = translation_active_language();
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    $votesById = current_votes_for_images($imageIds);
    $viewerPrincipal = current_viewer();
    $viewerFavouriteStates = $viewerPrincipal !== null && viewer_favourites_storage_available()
        ? viewer_favourites_for_image_ids((int) $viewerPrincipal['id'], $imageIds)
        : null;
    $viewerFavouriteRequiresSourceRecheck = is_array($viewerFavouriteStates) && $viewer !== null;
    $mapsAllowed = gallery_allows_gps_maps($gallery);
    $votingAllowed = gallery_voting_allowed($gallery);
    $nsfwAllowed = visitor_can_access_nsfw_content();
    if ($votingAllowed) {
        csrf_token();
    }

    cms_release_gallery_lightbox_session_lock();

    $gallery = content_localize_entity('gallery', $gallery, $contentLanguage);
    $images = content_localize_entities('image', $images, $contentLanguage);
    $renderImages = [];
    foreach ($images as $rowIndex => $image) {
        if ($publicOnly && image_nsfw_restricted($image, $gallery) && !$nsfwAllowed) {
            continue;
        }
        $renderImages[$rowIndex] = $image;
    }
    thumbnail_bundles_preload(array_values($renderImages));
    $items = [];

    foreach ($renderImages as $rowIndex => $image) {
        $items[] = gallery_lightbox_json_item($image, $gallery, $offset + $rowIndex, $mapsAllowed, $votingAllowed, $votesById);
        if (is_array($viewerFavouriteStates)
            && (!$viewerFavouriteRequiresSourceRecheck || viewer_source_image_can_reference((int) $image['id']))) {
            $lastItemIndex = array_key_last($items);
            if ($lastItemIndex !== null) {
                $items[$lastItemIndex]['viewer_favourite'] = !empty($viewerFavouriteStates[(int) $image['id']]);
            }
        }
    }

    if ($benchmarkTracing) {
        gallery_benchmark_trace_mark('lightbox_payload_ready', [
            'offset' => $offset,
            'limit' => $limit,
            'items' => count($items),
        ]);
        gallery_benchmark_record_auxiliary_render($gallery, public_render_profile_snapshot(), 'lightbox_metadata');
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
