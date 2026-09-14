<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_home.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles the public home page and public search request flow.
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
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\anonymous_preview_url;
use function Gallery\Core\append_cms_footer_script;
use function Gallery\Core\append_cms_head_extras;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\flash_message;
use function Gallery\Core\css_value;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_alt_text;
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
use function Gallery\Services\feature_capability_effective_enabled;
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
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_responsive_thumbnail_loading_attributes;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_db;
use function Gallery\Services\public_home_physical_galleries;
use function Gallery\Services\public_render_profile_set_gallery;
use function Gallery\Services\public_render_profile_span;
use function Gallery\Services\public_render_profile_snapshot;
use function Gallery\Services\public_render_profile_start;
use function Gallery\Services\public_render_profile_panel_model;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\gallery_date_view_model;
use function Gallery\Views\view_render_gallery_date;
use function Gallery\Views\view_render_pagination_controls;
use function Gallery\Views\view_render_public_render_profile_panel;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\tags_for_entity;
use function Gallery\Services\sort_public_hero_tag_groups;
use function Gallery\Services\smart_galleries_for_placement;
use function Gallery\Services\smart_gallery_card_summaries;
use function Gallery\Services\theme_hero_tag_display_all_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_rows;
use function Gallery\Services\theme_hero_tag_sort_mode;
use function Gallery\Services\theme_hero_tag_visible_limit;
use function Gallery\Services\telemetry_append_public_script;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\thumbnail_bundles_preload;
use function Gallery\Services\thumbnail_picture_html;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\visitor_can_access_nsfw_content;
use function Gallery\Views\view_gallery_description_markdown_excerpt;
use function Gallery\Views\view_gallery_description_markdown_html;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_public_seo_tags;
use function Gallery\Views\view_render_public_search_bar;
use function Gallery\Services\admin_log_event;

/**
 * Public gallery controller model.
 *
 * This module renders the public home page, gallery pages, gallery access gate, share redirects, gallery cards, lightbox markup, and public inline admin edit forms.
 */
const PUBLIC_SUBGALLERY_DATE_SORT_PARAM = 'subgallery_date_sort';

/**
 * Render the public gallery landing page and its filtered gallery listing.
 */
function cms_home(): void
{
    public_render_profile_start('home');
    // $galleries stores public physical root galleries loaded through the model-backed lookup service.
    $galleries = public_render_profile_db('home_gallery_query', static fn (): array => public_home_physical_galleries());
    $galleries = array_merge($galleries, smart_galleries_for_placement(null, true));
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = main_page_gallery_grid_settings();
    // $allHomeGalleries stores the full front-page gallery list before optional slicing.
    $allHomeGalleries = $galleries;
    // $homeGalleryCount stores the full front-page gallery count before optional slicing.
    $homeGalleryCount = count($allHomeGalleries);
    // $homePhysicalGalleryCount and $homeSmartGalleryCount expose pagination-safe context counts for Admin mutation verification.
    $homePhysicalGalleries = array_values(array_filter($allHomeGalleries, static fn (array $gallery): bool => empty($gallery['__smart_gallery'])));
    $homePhysicalGalleryCount = count($homePhysicalGalleries);
    $homeSmartGalleryCount = max(0, $homeGalleryCount - $homePhysicalGalleryCount);
    // $homePhysicalGalleryRevision verifies an updated root card even when pagination hides that card on the current page.
    $homePhysicalGalleryRevision = '';
    foreach ($homePhysicalGalleries as $homePhysicalGallery) {
        $candidateRevision = trim((string) ($homePhysicalGallery['updated_at'] ?? ''));
        if ($candidateRevision > $homePhysicalGalleryRevision) {
            $homePhysicalGalleryRevision = $candidateRevision;
        }
    }
    // Variable $galleryPagination stores this steps working value.
    $galleryPagination = pagination_model($homeGalleryCount, pagination_current_page('gallery_page'), (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', null, static fn (int $pageNumber): string => pagination_home_gallery_clean_url($pageNumber));
    if (!empty($paginationSettings['enabled'])) {
        // $galleries stores the public home gallery list after optional pagination slicing.
        // Always slice from the immutable full result set so the rendered cards and
        // the pagination controls are based on the same source. This avoids a
        // controls-only front page after Theme pagination settings change.
        $galleries = pagination_slice_items($allHomeGalleries, $galleryPagination);
        if ($homeGalleryCount > 0 && $galleries === []) {
            // A stale or malformed front-page pagination request must not render a controls-only page.
            $galleryPagination = pagination_model($homeGalleryCount, 1, (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', null, static fn (int $pageNumber): string => pagination_home_gallery_clean_url($pageNumber));
            $galleries = pagination_slice_items($allHomeGalleries, $galleryPagination);
        }
    } else {
        // Keep the non-paginated branch explicit so later code never depends on
        // a list variable that was already touched by another page mode.
        $galleries = $allHomeGalleries;
    }

    // $paginationHtml preserves the established pagination renderer while the page shell moves into the view layer.
    $paginationHtml = '';
    // $cardsHtml stores already-rendered card fragments produced through the public card MVC wrappers.
    $cardsHtml = '';
    // $backToTopHtml stores the shared navigation control without duplicating its renderer.
    $backToTopHtml = '';
    if ($homeGalleryCount > 0) {
        ob_start();
        view_render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], t('gallery.pagination.gallery_pages', 'Gallery pages'));
        $paginationHtml = (string) ob_get_clean();

        public_render_profile_count('rendered_subgalleries', count($galleries));
        $physicalGalleries = array_values(array_filter($galleries, static fn (array $gallery): bool => empty($gallery['__smart_gallery'])));
        $smartGalleries = array_values(array_filter($galleries, static fn (array $gallery): bool => !empty($gallery['__smart_gallery'])));
        $galleryCardContexts = public_render_profile_span('home_gallery_card_context_preload', static fn (): array => public_gallery_card_rendering_contexts($physicalGalleries, true, true));
        $smartGalleryCardContexts = public_render_profile_span('home_smart_gallery_card_context_preload', static fn (): array => smart_gallery_card_summaries($smartGalleries, true));
        ob_start();
        public_render_profile_span('render_home_gallery_cards', static function () use ($galleries, $galleryCardContexts, $smartGalleryCardContexts): void {
            foreach ($galleries as $index => $gallery) {
                if (!empty($gallery['__smart_gallery'])) {
                    render_smart_gallery_card($gallery, $index, $smartGalleryCardContexts[(int) $gallery['id']] ?? []);
                } else {
                    render_gallery_card($gallery, true, false, true, $index, $galleryCardContexts[(int) $gallery['id']] ?? []);
                }
            }
        });
        $cardsHtml = (string) ob_get_clean();

        ob_start();
        render_back_to_top_button();
        $backToTopHtml = (string) ob_get_clean();
    }

    // $renderProfileHtml captures the optional profiler panel before the page view emits final markup.
    ob_start();
    view_render_public_render_profile_panel(public_render_profile_panel_model());
    $renderProfileHtml = (string) ob_get_clean();

    telemetry_append_public_script([
        'route_name' => 'home',
        'page_kind' => 'home',
    ]);

    \Gallery\Views\view_render_public_gallery_home([
        'site_name' => site_name(),
        'canonical_url' => url_for('home'),
        'gallery_count' => $homeGalleryCount,
        'physical_gallery_count' => $homePhysicalGalleryCount,
        'physical_gallery_revision' => $homePhysicalGalleryRevision,
        'smart_gallery_count' => $homeSmartGalleryCount,
        'search_bar' => public_search_bar_view_model(),
        'pagination_html' => $paginationHtml,
        'grid_class' => pagination_grid_columns_class($paginationSettings),
        'current_page' => (int) ($galleryPagination['current_page'] ?? 1),
        'total_pages' => (int) ($galleryPagination['total_pages'] ?? 1),
        'cards_html' => $cardsHtml,
        'back_to_top_html' => $backToTopHtml,
        'render_profile_html' => $renderProfileHtml,
    ]);
}

