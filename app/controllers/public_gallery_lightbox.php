<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_lightbox.php
 * Module Type: Controller
 *
 * Purpose:
 *   Builds lightbox data attributes, vote markup, source nodes, and lightbox shell rendering.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\anonymous_preview_url;
use function Gallery\Core\append_cms_footer_script;
use function Gallery\Core\append_cms_head_extras;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\flash_message;
use function Gallery\Core\css_value;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_media_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\slugify;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\child_galleries;
use function Gallery\Services\child_galleries_tree_preload;
use function Gallery\Services\contained_tags_for_gallery;
use function Gallery\Services\current_user_is_known_under_18;
use function Gallery\Services\current_votes_for_images;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_gallery_by_folder_path;
use function Gallery\Services\find_gallery_by_slug;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_access_lifetime_seconds;
use function Gallery\Services\gallery_benchmark_record_public_render;
use function Gallery\Services\gallery_access_requirement;
use function Gallery\Services\gallery_access_schema_ready;
use function Gallery\Services\gallery_allows_direct_public_request;
use function Gallery\Services\gallery_allows_gps_maps;
use function Gallery\Services\gallery_background_asset_url;
use function Gallery\Services\gallery_branch_image_count;
use function Gallery\Services\gallery_branch_image_counts;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_breadcrumb_ancestors;
use function Gallery\Services\gallery_cover_asset_url;
use function Gallery\Services\gallery_cover_collage_images;
use function Gallery\Services\gallery_cover_image;
use function Gallery\Services\gallery_date_range_display_value;
use function Gallery\Services\gallery_effective_count_badge_enabled;
use function Gallery\Services\gallery_effective_description_layout;
use function Gallery\Services\gallery_description_layout_normalize;
use function Gallery\Services\gallery_effective_grid_settings;
use function Gallery\Services\gallery_effective_lightbox_browsing_mode;
use function Gallery\Services\gallery_count_dated_rows;
use function Gallery\Services\gallery_effective_visibility;
use function Gallery\Services\gallery_has_map_payload;
use function Gallery\Services\gallery_lightbox_browsing_mode_normalize;
use function Gallery\Services\gallery_sort_row_has_start_date;
use function Gallery\Services\gallery_sort_rows_by_date_preserving_undated_positions;
use function Gallery\Services\gallery_lightbox_excludes_restricted_nsfw;
use function Gallery\Services\gallery_lightbox_fetch_images;
use function Gallery\Services\gallery_lightbox_image_position;
use function Gallery\Services\gallery_lightbox_total_count;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\grant_gallery_public_access;
use function Gallery\Services\grant_nsfw_guard_access;
use function Gallery\Services\image_has_gps;
use function Gallery\Services\image_map_point;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\likely_gallery_destination_id;
use function Gallery\Services\main_page_gallery_grid_settings;
use function Gallery\Services\pagination_current_page;
use function Gallery\Services\pagination_gallery_clean_url;
use function Gallery\Services\pagination_grid_columns_class;
use function Gallery\Services\pagination_home_gallery_clean_url;
use function Gallery\Services\pagination_model;
use function Gallery\Services\pagination_photo_thumbnail_sizes_attribute;
use function Gallery\Services\pagination_slice_items;
use function Gallery\Services\picture_game_available;
use function Gallery\Services\public_gallery_media_manifest;
use function Gallery\Services\public_gallery_listing_sql_fragment;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_home_search_enabled;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_responsive_thumbnail_loading_attributes;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_db;
use function Gallery\Services\public_render_profile_set_gallery;
use function Gallery\Services\public_render_profile_span;
use function Gallery\Services\public_render_profile_snapshot;
use function Gallery\Services\public_render_profile_start;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_search_normalize_query;
use function Gallery\Services\public_search_query_length;
use function Gallery\Services\public_search_results;
use function Gallery\Services\gallery_date_view_model;
use function Gallery\Views\view_render_gallery_date;
use function Gallery\Views\view_render_pagination_controls;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\tags_for_entity;
use function Gallery\Services\sort_public_hero_tag_groups;
use function Gallery\Services\theme_hero_tag_display_all_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_rows;
use function Gallery\Services\theme_hero_tag_sort_mode;
use function Gallery\Services\theme_hero_tag_visible_limit;
use function Gallery\Services\telemetry_append_public_script;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\thumbnail_bundles_preload;
use function Gallery\Services\lightbox_zoom_quality_candidates;
use function Gallery\Services\thumbnail_picture_html;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\visitor_can_access_nsfw_content;
use function Gallery\Views\view_gallery_description_markdown_excerpt;
use function Gallery\Views\view_gallery_description_markdown_html;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_public_seo_tags;
use function Gallery\Views\view_lightbox_image_data_attributes;
use function Gallery\Views\view_render_lightbox;
use function Gallery\Views\view_render_lightbox_source_nodes;
use function Gallery\Views\view_render_lightbox_vote_template;
use function Gallery\Services\admin_log_event;

/**
 * Build the shared data attributes consumed by the public lightbox.
 *
 * Keeping visible cards and hidden pagination sources on the same attribute
 * contract prevents the lightbox from having a separate pagination-specific path.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param string $mediaUrl Media url URL.
 * @param string $previewUrl Preview url URL.
 * @param string $imagePageUrl Image page url URL.
 * @param string $displayTitle Display title value.
 * @param int $score Score value.
 * @param int $vote Vote value.
 * @param ?array $imageMapPoint Image map point value.
 * @param string $sourceAttribute Source attribute value.
 * @param bool $votingAllowed Voting allowed value.
 * @param ?int $lightboxIndex Lightbox index value.
 * @param ?array $thumbnailBundle Optional request-local thumbnail bundle.
 * @param ?array $sourceGalleryContext Optional Smart Gallery physical-source context.
 * @return string Text result for the caller.
 */
function lightbox_image_data_attributes(array $image, array $gallery, string $mediaUrl, string $previewUrl, string $imagePageUrl, string $displayTitle, int $score, int $vote, ?array $imageMapPoint, string $sourceAttribute, bool $votingAllowed = true, ?int $lightboxIndex = null, ?array $thumbnailBundle = null, ?array $sourceGalleryContext = null): string
{
    // $qualityCandidates stores only already-authorized thumbnail/media URLs with bounded source dimensions.
    $qualityCandidates = lightbox_zoom_quality_candidates($image, $previewUrl, $mediaUrl, $thumbnailBundle);
    return view_lightbox_image_data_attributes([
        'source_attribute' => $sourceAttribute,
        'image_id' => (int) ($image['id'] ?? 0),
        'lightbox_index' => $lightboxIndex,
        'gallery_id' => (int) ($gallery['id'] ?? 0),
        'media_url' => $mediaUrl,
        'preview_url' => $previewUrl,
        'quality_candidates' => $qualityCandidates,
        'page_url' => $imagePageUrl,
        'gallery_url' => gallery_public_url($gallery),
        'title' => $displayTitle,
        'description' => (string) ($image['description'] ?? ''),
        'score' => $score,
        'vote' => $vote,
        'image_width' => (int) ($image['width'] ?? 0),
        'image_height' => (int) ($image['height'] ?? 0),
        'voting_allowed' => $votingAllowed,
        'map_point' => $imageMapPoint,
        'source_gallery_id' => (int) ($sourceGalleryContext['id'] ?? 0),
        'source_gallery_title' => (string) ($sourceGalleryContext['title'] ?? ''),
        'source_gallery_breadcrumb' => (string) ($sourceGalleryContext['breadcrumb'] ?? $sourceGalleryContext['title'] ?? ''),
        'source_gallery_url' => (string) ($sourceGalleryContext['url'] ?? ''),
    ]);
}

/**
 * Render an inert vote template for hidden lightbox source nodes.
 *
 * Visible photo cards already contain the active gallery-card voting form. Hidden
 * pagination source nodes need the same server-rendered widget without creating
 * another live form on the page. The browser does not submit or bind controls
 * inside <template>, so the lightbox can safely clone it when that image opens.
 *
 * @param int $imageId Image identifier.
 * @param int $score Score value.
 * @param int $vote Vote value.
 * @param bool $votingAllowed Voting allowed value.
 */
function render_lightbox_vote_template(int $imageId, int $score, int $vote, bool $votingAllowed): void
{
    // $voteFormHtml stores the exact same server-rendered widget used by visible gallery cards.
    $voteFormHtml = render_vote_form_html($imageId, $score, $vote, $votingAllowed);
    view_render_lightbox_vote_template($voteFormHtml);
}

/**
 * Render hidden ordered lightbox data for paginated galleries.
 *
 * Pagination limits visible photo cards, but fullscreen navigation should still
 * move through the complete sorted gallery. These hidden nodes are metadata only
 * and do not affect the public grid layout.
 *
 * @param array $allImages All images value.
 * @param array $gallery Gallery row or gallery data.
 * @param bool $mapsAllowed Maps allowed value.
 * @param array $votesById Votes by id identifier.
 */
function render_lightbox_source_nodes(array $allImages, array $gallery, bool $mapsAllowed, array $votesById): void
{
    $nodes = [];
    foreach ($allImages as $image) {
        if (!current_user() && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
            continue;
        }
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = image_public_media_url($image, $gallery);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Hidden source nodes are metadata for fullscreen order only. Keep their
        // preview empty so paginated galleries do not resolve a large thumbnail
        // for every non-rendered image during normal page render.
        $previewUrl = '';
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $mapsAllowed && image_has_gps($image) ? public_render_profile_with_thumbnail_purpose('hidden source map metadata no thumb', static fn (): array => image_map_point($image, $gallery, false)) : null;
        // $sourceAttribute stores a separate marker from visible cards so JavaScript can preserve the full image order.
        $sourceAttribute = 'data-lightbox-source';
        // $votingAllowed stores whether this hidden source needs a reusable server-rendered vote widget.
        $votingAllowed = gallery_voting_allowed($gallery);
        $nodes[] = [
            'attributes' => lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, $sourceAttribute, $votingAllowed),
            'vote_form_html' => render_vote_form_html((int) $image['id'], (int) $image['score'], $vote, $votingAllowed),
        ];
    }
    view_render_lightbox_source_nodes($nodes);
}

/**
 * Handles render lightbox logic for the gallery application.
 *
 * @param mixed $votingAllowed Input used by this operation.
 * @param bool $mapsAllowed Maps allowed value.
 * @param string $galleryMapUrl Gallery map url URL.
 * @param string $galleryMapTitle Gallery map title value.
 * @param string $lightboxBrowsingMode Lightbox browsing mode value.
 * @param bool $slideshowAllowed Whether slideshow controls and keyboard activation are available.
 */
function render_lightbox(bool $votingAllowed = true, bool $mapsAllowed = false, string $galleryMapUrl = '', string $galleryMapTitle = '', string $lightboxBrowsingMode = 'single', bool $slideshowAllowed = true): void
{
    // $lightboxBrowsingMode stores the effective public mode already resolved by the gallery controller.
    $lightboxBrowsingMode = gallery_lightbox_browsing_mode_normalize($lightboxBrowsingMode);
    // Viewer favourites are optional personalized controls and never alter gallery authorization.
    $viewerFavouriteHtml = render_viewer_favourite_lightbox_form_html();
    view_render_lightbox([
        'voting_allowed' => $votingAllowed,
        'maps_allowed' => $mapsAllowed,
        'gallery_map_url' => $galleryMapUrl,
        'gallery_map_title' => $galleryMapTitle,
        'browsing_mode' => $lightboxBrowsingMode,
        'slideshow_allowed' => $slideshowAllowed,
        'viewer_favourite_html' => $viewerFavouriteHtml,
    ]);
}
