<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_page.php
 * Module Type: Controller
 *
 * Purpose:
 *   Coordinates rendering of a selected public gallery.
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
 *   2026-08-11
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
use function Gallery\Services\content_localize_entities;
use function Gallery\Services\content_localize_entity;
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
use function Gallery\Services\render_gallery_date;
use function Gallery\Services\render_pagination_controls;
use function Gallery\Services\render_public_render_profile_panel;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\tags_for_entity;
use function Gallery\Services\sort_public_hero_tag_groups;
use function Gallery\Services\smart_galleries_for_placement;
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
use function Gallery\Services\admin_log_event;

/**
 * Handles cms gallery logic for the gallery application.
 */
function cms_gallery(): void
{
    public_render_profile_start('gallery');
    // Variable $anonymousPreview stores whether a logged-in admin asked to render the page with anonymous visitor rules.
    $anonymousPreview = admin_anonymous_preview_active();
    // Variable $viewer stores this steps working value.
    $viewer = $anonymousPreview ? null : (current_user_is_known_under_18() ? null : current_user());
    // Variable $gallery stores this steps working value.
    $gallery = null;
    // Variable $requestedImage stores this steps working value.
    $requestedImage = null;
    if (isset($_GET['public_path'])) {
        // $resolved stores an intermediate value used by the surrounding gallery workflow.
        $resolved = resolve_public_gallery_path((string) $_GET['public_path'], !$viewer);
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = $resolved['gallery'];
        // $requestedImage stores an intermediate value used by the surrounding gallery workflow.
        $requestedImage = $resolved['image'];
    }
    if (!$gallery && isset($_GET['gallery_path'])) {
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = find_gallery_by_folder_path((string) $_GET['gallery_path']);
        } catch (RuntimeException) {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = null;
        }
    }
    if (!$gallery && isset($_GET['slug'])) {
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = find_gallery_by_folder_path((string) $_GET['slug']);
        } catch (RuntimeException) {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = null;
        }
    }
    if (!$gallery) {
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = find_gallery_by_slug((string) ($_GET['slug'] ?? ''));
    }
    if (!$gallery || (!$viewer && !gallery_allows_direct_public_request($gallery) && !visitor_can_access_gallery($gallery))) {
        cms_not_found();
        return;
    }
    if (!$viewer && !visitor_can_access_gallery($gallery)) {
        render_gallery_access_gate($gallery);
        return;
    }
    if (!$viewer && $requestedImage && image_nsfw_restricted($requestedImage, $gallery) && !visitor_can_access_nsfw_content()) {
        render_gallery_access_gate($gallery, '', $requestedImage);
        return;
    }
    public_render_profile_set_gallery((int) $gallery['id']);
    // Variable $publicOnly stores this steps working value.
    $publicOnly = !$viewer;
    // $lightboxExcludesRestrictedNsfw stores whether the async lightbox list must omit restricted rows.
    $lightboxExcludesRestrictedNsfw = gallery_lightbox_excludes_restricted_nsfw($gallery, $publicOnly);
    // $imageTotalCount stores the complete top-level photo count used by public pagination.
    $imageTotalCount = public_render_profile_db('gallery_image_count_query', static fn (): int => gallery_lightbox_total_count($gallery, $publicOnly, false));
    // $lightboxTotalCount stores the async lightbox count after visitor-specific restrictions are applied.
    $lightboxTotalCount = public_render_profile_db('gallery_lightbox_count_query', static fn (): int => gallery_lightbox_total_count($gallery, $publicOnly, true));
    // Variable $images stores the visible page photo rows. It is filled after pagination knows the requested offset.
    $images = [];
    // Variable $allImages stores only the current visible photo set. Full-gallery lightbox data is loaded asynchronously.
    $allImages = [];
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = [];
    // Variable $votesById stores this steps working value.
    $votesById = [];
    // Variable $children stores this steps working value.
    public_render_profile_count('gallery_scan_calls');
    $children = public_render_profile_span('child_gallery_lookup', static fn (): array => child_galleries((int) $gallery['id'], $publicOnly));
    $smartChildren = smart_galleries_for_placement((int) $gallery['id'], $publicOnly);
    // $adminSubgalleryDateSortEnabled stores whether this viewer may use the date sort overlay.
    $adminSubgalleryDateSortEnabled = current_user() && !admin_anonymous_preview_active();
    // $subgalleryDateSortMode stores the requested preview date sort mode for this page.
    $subgalleryDateSortMode = $adminSubgalleryDateSortEnabled ? public_subgallery_date_sort_mode() : '';
    // $datedSubgalleryCount stores how many direct children have a start date available for sorting.
    $datedSubgalleryCount = public_count_dated_subgalleries($children);
    if ($subgalleryDateSortMode !== '' && $datedSubgalleryCount > 1) {
        // $children stores the same direct children with only dated rows reordered by their start date.
        $children = public_sort_subgalleries_by_date($children, $subgalleryDateSortMode);
    }
    // Variable $allChildren stores the complete sorted child-gallery list before optional pagination slicing.
    $children = array_merge($children, $smartChildren);
    $allChildren = $children;
    // Variable $photoMapsAllowed stores whether individual photos may expose EXIF GPS points.
    $photoMapsAllowed = gallery_allows_gps_maps($gallery);
    // Variable $galleryMapAvailable stores whether the map button should be shown without building the full payload.
    $galleryMapAvailable = gallery_has_map_payload($gallery, $publicOnly, true);
    // Variable $mapsAllowed stores whether the shared map UI can be opened from any source.
    $mapsAllowed = $photoMapsAllowed || $galleryMapAvailable;
    // Variable $galleryMapUrl stores the lazily loaded gallery map endpoint.
    $galleryMapUrl = $galleryMapAvailable ? url_for('gallery_map_data', ['id' => $gallery['id']]) : '';
    // Variable $votingAllowed stores this steps working value.
    $votingAllowed = gallery_voting_allowed($gallery);
    // $lightboxBrowsingMode stores the effective public lightbox mode resolved from Theme plus gallery override.
    $lightboxBrowsingMode = gallery_effective_lightbox_browsing_mode($gallery);
    // Variable $pictureGameAvailable stores this steps working value.
    $pictureGameAvailable = public_render_profile_span('picture_game_lookup', static fn (): bool => picture_game_available($gallery));
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = public_render_profile_span('gallery_grid_settings', static fn (): array => gallery_effective_grid_settings($gallery));
    // Variable $galleryPaginationPath stores the gallery-level URL path used for clean pagination links.
    $galleryPaginationPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($galleryPaginationPath === '') {
        // $galleryPaginationPath stores a legacy folder-path fallback for installs without regenerated public paths.
        $galleryPaginationPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
    }
    if ($galleryPaginationPath === '') {
        // $galleryPaginationPath stores the final slug fallback for unusual root-level gallery records.
        $galleryPaginationPath = (string) ($gallery['slug'] ?? '');
    }
    // Variable $galleryPaginationQuery stores this steps working value.
    $galleryPaginationQuery = ['page' => 'gallery', 'public_path' => $galleryPaginationPath];
    // Variable $childPagination stores this steps working value.
    $childPagination = pagination_model(count($children), pagination_current_page('gallery_page'), (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', $galleryPaginationQuery, static fn (int $pageNumber): string => pagination_gallery_clean_url($gallery, $pageNumber, 'subgalleries'));
    // Variable $photoCurrentPage stores this steps working value.
    $photoCurrentPage = pagination_current_page('photo_page');
    if (!empty($paginationSettings['enabled']) && $requestedImage) {
        // $requestedImagePosition stores the zero-based sorted position of the direct-linked photo.
        $requestedImagePosition = gallery_lightbox_image_position($requestedImage, $gallery, $publicOnly, false);
        if ($requestedImagePosition >= 0) {
            // $photoCurrentPage stores the page that contains the explicitly requested image.
            $photoCurrentPage = (int) floor($requestedImagePosition / (int) $paginationSettings['items_per_page']) + 1;
        }
    }
    // Variable $photoPagination stores this steps working value.
    $photoPagination = pagination_model($imageTotalCount, $photoCurrentPage, (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'photo_page', $galleryPaginationQuery, static fn (int $pageNumber): string => pagination_gallery_clean_url($gallery, $pageNumber, 'photos'));
    if (!empty($paginationSettings['enabled'])) {
        // $children stores the subgallery list after any requested date sort has already been applied.
        $children = pagination_slice_items($children, $childPagination);
        // $images stores only the visible photo page so large galleries do not render full-gallery metadata.
        $images = public_render_profile_db('gallery_image_page_query', static fn (): array => gallery_lightbox_fetch_images($gallery, $publicOnly, (int) $photoPagination['offset'], (int) $photoPagination['limit'], false));
    } else {
        // $images stores all rows only when the gallery grid explicitly has pagination disabled.
        $images = public_render_profile_db('gallery_image_full_query', static fn (): array => gallery_lightbox_fetch_images($gallery, $publicOnly, 0, null, false));
    }
    // Resolve optional content overlays only after access policy and pagination have selected safe rows.
    $contentLanguage = translation_active_language();
    $gallery = content_localize_entity('gallery', $gallery, $contentLanguage);
    $physicalChildrenForLocalization = array_values(array_filter($children, static fn (array $child): bool => empty($child['__smart_gallery'])));
    $localizedChildrenById = [];
    foreach (content_localize_entities('gallery', $physicalChildrenForLocalization, $contentLanguage) as $localizedChild) {
        $localizedChildrenById[(int) ($localizedChild['id'] ?? 0)] = $localizedChild;
    }
    foreach ($children as &$child) {
        if (empty($child['__smart_gallery']) && isset($localizedChildrenById[(int) ($child['id'] ?? 0)])) {
            $child = $localizedChildrenById[(int) $child['id']];
        }
    }
    unset($child);
    $images = content_localize_entities('image', $images, $contentLanguage);
    // Variable $allImages stores only the visible image set for crawler metadata and social preview fallback.
    $allImages = $images;
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // $publicMediaManifest stores request-local thumbnail data for visible cards.
    $publicMediaManifest = $images ? public_gallery_media_manifest($images, $gallery) : [];
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = public_render_profile_span('image_tag_lookup', static fn (): array => tags_for_entities('image', $imageIds));
    // Variable $votesById stores this steps working value.
    $votesById = public_render_profile_span('image_vote_lookup', static fn (): array => current_votes_for_images($imageIds));
    // Variable $backgroundAssetUrl stores this steps working value.
    $backgroundAssetUrl = public_render_profile_span('background_asset_lookup', static fn (): string => gallery_background_asset_url($gallery, $publicOnly));
    // Variable $seo stores this steps working value.
    $seo = public_render_profile_span('seo_metadata_lookup', static fn (): array => public_gallery_metadata($gallery));
    ob_start();
    view_render_public_seo_tags($gallery, $allImages);
    view_render_gallery_json_ld($gallery, $images, $publicMediaManifest);
    append_cms_head_extras((string) ob_get_clean());
    if ($backgroundAssetUrl !== '') {
        append_cms_head_extras('<style>.theme-background-image{background-image:url("' . css_value($backgroundAssetUrl) . '");}</style>');
    }

    render_header((string) $seo['title'], $gallery, $publicOnly);
    // $publicNotice stores one-time admin feedback for public-page actions.
    $publicNotice = (string) flash_message('public_notice');
    if ($publicNotice !== '') {
        echo '<div class="notice">' . e($publicNotice) . '</div>';
    }
    // $heroTagGroups keeps direct gallery tags and inherited/contained tags semantically separate while sharing one display policy.
    $heroTagGroups = [
        'gallery' => tags_for_entity('gallery', (int) $gallery['id']),
        'contained' => $children
            ? public_render_profile_span('contained_tag_lookup', static fn (): array => contained_tags_for_gallery($gallery, $publicOnly))
            : [],
    ];
    // Sort each group according to the global Theme setting without mixing direct and contained tags.
    $heroTagGroups = public_render_profile_span(
        'hero_tag_sort',
        static fn (): array => sort_public_hero_tag_groups($heroTagGroups, theme_hero_tag_sort_mode())
    );
    // $heroTagVisibleLimit is the browser-side collapse boundary; every tag remains in the server HTML for no-JS access.
    $heroTagVisibleLimit = theme_hero_tag_visible_limit();
    // $heroTagDisplayAll disables the disclosure behavior while retaining the same server-rendered markup.
    $heroTagDisplayAll = theme_hero_tag_display_all_enabled();
    // $heroTagScrollbarEnabled allows the browser to constrain the content only when wrapping exceeds the configured row count.
    $heroTagScrollbarEnabled = theme_hero_tag_scrollbar_enabled();
    // $heroTagScrollbarRows is interpreted as rendered visual rows after responsive wrapping, not as a fixed CSS height.
    $heroTagScrollbarRows = theme_hero_tag_scrollbar_rows();
    // $heroTagCount determines whether an expand control is useful at all.
    $heroTagCount = count($heroTagGroups['gallery']) + count($heroTagGroups['contained']);

    echo '<section class="hero">';
    // Keep the title, date, description, and breadcrumbs in one primary column so long descriptions do not become a narrow middle strip.
    echo '<div class="hero-topbar">';
    echo '<div class="hero-primary">';
    render_public_gallery_branding_header($gallery, $seo, $publicOnly);
    render_breadcrumbs($gallery);
    echo '</div>';
    echo '<div class="hero-meta">';
    echo '<div class="hero-actions" aria-label="' . e(t('gallery.actions', 'Gallery actions')) . '">';
    render_public_gallery_admin_delete_form($gallery, 'hero');
    render_public_gallery_admin_edit_link($gallery, 'hero');
    render_public_gallery_admin_add_child_link($gallery, 'hero');
    echo '<a class="button hero-icon-button hero-download-button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '" aria-label="' . e(t('gallery.download', 'Download gallery')) . '" title="' . e(t('gallery.download', 'Download gallery')) . '"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">' . e(t('gallery.download', 'Download gallery')) . '</span></a>';
    if ($galleryMapAvailable) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e($galleryMapUrl) . '" data-gallery-map-title="' . e((string) $gallery['title']) . '">' . e(t('gallery.show_map', 'Show gallery map')) . '</button>';
    }
    if ($pictureGameAvailable) {
        echo '<a class="button secondary hero-icon-button hero-picture-game-button" href="' . e(url_for('picture_game', ['id' => $gallery['id']])) . '" aria-label="' . e(t('gallery.play_picture_game', 'Play picture game')) . '" title="' . e(t('gallery.play_picture_game', 'Play picture game')) . '"><span aria-hidden="true">&#127918;</span><span class="visually-hidden">' . e(t('gallery.play_picture_game', 'Play picture game')) . '</span></a>';
    }
    echo '</div>';
    echo '</div>';
    if ($heroTagCount > 0) {
        echo '<div class="hero-tags" aria-label="' . e(t('gallery.tags', 'Gallery tags')) . '" data-hero-tags data-hero-tag-visible-limit="' . $heroTagVisibleLimit . '" data-hero-tag-display-all="' . ($heroTagDisplayAll ? '1' : '0') . '" data-hero-tag-scrollbar-enabled="' . ($heroTagScrollbarEnabled ? '1' : '0') . '" data-hero-tag-scrollbar-rows="' . $heroTagScrollbarRows . '">';
        echo '<div class="hero-tags-content" data-hero-tags-content>';
        render_tag_list($heroTagGroups['gallery']);
        render_tag_list($heroTagGroups['contained'], t('gallery.containing_tags', 'Containing tags'));
        echo '</div>';
        if (!$heroTagDisplayAll && $heroTagCount > $heroTagVisibleLimit) {
            // The browser toggles visibility in-place. No navigation or server request is required to expose the complete collection.
            echo '<div class="hero-tags-controls"><button type="button" class="button secondary hero-tags-toggle" data-hero-tags-toggle hidden data-show-all-label="' . e(t('gallery.show_all_tags', 'Display all tags')) . '" data-show-fewer-label="' . e(t('gallery.show_fewer_tags', 'Show fewer tags')) . '" aria-expanded="false">' . e(t('gallery.show_all_tags', 'Display all tags')) . '</button></div>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
    render_public_gallery_branding_separator($gallery, $publicOnly);
    render_public_gallery_preview_toolbar($gallery);
    render_public_search_bar($gallery);
    // Variable $publicPageReorderEnabled stores whether the logged-in admin can reorder visible public-page cards.
    $publicPageReorderEnabled = current_user() && !admin_anonymous_preview_active();
    // $publicSubgalleryReorderEnabled stores whether subgallery cards can expose drag ordering handles.
    $publicSubgalleryReorderEnabled = $publicPageReorderEnabled && $subgalleryDateSortMode === '' && !$smartChildren;
    // $pictureManagerEnabled stores whether the logged-in viewer can select and manage visible photos.
    $pictureManagerEnabled = current_user() && !admin_anonymous_preview_active() && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('picture_manager'));
    if ($children || $images) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    if ($children) {
        echo '<section class="panel public-subgallery-panel" data-public-subgallery-section aria-label="' . e(t('public.subgalleries', 'Subgalleries')) . '">';
        render_public_subgallery_date_sort_toolbar($gallery, $subgalleryDateSortMode, $datedSubgalleryCount, count($allChildren));
        if ($subgalleryDateSortMode === '') {
            render_public_page_reorder_toolbar('gallery', $gallery, !empty($paginationSettings['enabled']) ? $childPagination : [], count($children), count($allChildren));
        }
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], t('pagination.subgallery_pages', 'Subgallery pages'));
        echo '<div class="grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="gallery" data-public-subgallery-grid>';
        public_render_profile_count('rendered_subgalleries', count($children));
        $physicalChildren = array_values(array_filter($children, static fn (array $child): bool => empty($child['__smart_gallery'])));
        $subgalleryCardContexts = public_render_profile_span('subgallery_card_context_preload', static fn (): array => public_gallery_card_rendering_contexts($physicalChildren, true, true));
        public_render_profile_span('render_subgallery_cards', static function () use ($children, $publicSubgalleryReorderEnabled, $subgalleryCardContexts): void {
            foreach ($children as $index => $child) {
                if (!empty($child['__smart_gallery'])) {
                    render_smart_gallery_card($child, $index);
                } else {
                    render_gallery_card($child, true, $publicSubgalleryReorderEnabled && count($children) > 1, true, $index, $subgalleryCardContexts[(int) $child['id']] ?? []);
                }
            }
        });
        echo '</div>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], t('pagination.subgallery_pages', 'Subgallery pages'));
        echo '</section>';
    }
    // Variable $publicPhotoReorderEnabled stores whether visible photo cards should render drag handles.
    $publicPhotoReorderEnabled = $publicPageReorderEnabled && count($images) > 1;
    // $lightboxFeatureEnabled stores whether cards should open in the JavaScript lightbox instead of plain image URLs.
    $lightboxFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes');
    // $publicThumbnailRenderingMode stores the validated site-level picture strategy for selected-gallery photo cards.
    $publicThumbnailRenderingMode = public_thumbnail_rendering_mode();
    if ($images) {
        if ($pictureManagerEnabled) {
            render_picture_manager_toolbar($gallery, count($children) > 0);
        }
        render_public_page_reorder_toolbar('photo', $gallery, !empty($paginationSettings['enabled']) ? $photoPagination : [], count($images), $imageTotalCount);
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], t('pagination.photo_pages', 'Photo pages'));
        // Pin lazy single-photo metadata to the same language as the rendered page.
        // This avoids a direct-photo lightbox falling back to a stale/default
        // session language while its server-rendered card is already localized.
        $lightboxEndpointParams = [
            'id' => (int) $gallery['id'],
            'lang' => $contentLanguage,
        ];
        if ($anonymousPreview) {
            $lightboxEndpointParams['view_as'] = 'anonymous';
        }
        $lightboxConfigAttributes = $lightboxFeatureEnabled ? ' data-lightbox-config data-lightbox-endpoint="' . e(url_for('gallery_lightbox_data', $lightboxEndpointParams)) . '" data-lightbox-total="' . (int) $lightboxTotalCount . '" data-lightbox-window-size="60" data-lightbox-browsing-mode="' . e($lightboxBrowsingMode) . '" data-lightbox-maps-enabled="' . ($mapsAllowed ? '1' : '0') . '" data-lightbox-gallery-map-url="' . e($galleryMapUrl) . '" data-lightbox-gallery-map-title="' . e((string) $gallery['title']) . '"' : '';
        echo '<section class="grid gallery-image-grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="photo" data-gallery-image-list' . $lightboxConfigAttributes . '>';
    }
    public_render_profile_count('rendered_images', count($images));
    public_render_profile_span('render_image_cards', static function () use ($images, $gallery, $publicOnly, $photoMapsAllowed, $imageTagsById, $votesById, $votingAllowed, $paginationSettings, $photoPagination, $publicPhotoReorderEnabled, $pictureManagerEnabled, $lightboxExcludesRestrictedNsfw, $lightboxFeatureEnabled, $publicMediaManifest, $publicThumbnailRenderingMode): void {
    foreach ($images as $index => $image) {
        // Variable $imageNeedsNsfwGate stores whether this card must avoid exposing thumbnail/media URLs.
        $imageNeedsNsfwGate = $publicOnly && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content();
        if ($imageNeedsNsfwGate) {
            echo '<article class="image-card nsfw-card"><div class="image-stage nsfw-stage"><a class="nsfw-placeholder" href="' . e(image_public_url($image, $gallery)) . '"><strong>' . e(t('public.nsfw_photo_title', '18+ photo')) . '</strong><span>' . e(t('public.nsfw_photo_message', 'Confirm your age to view this restricted photo.')) . '</span></a></div>';
            render_public_image_admin_edit_link($image);
            render_public_image_admin_delete_form($image);
            echo '</article>';
            continue;
        }
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // $mediaManifestEntry stores thumbnail data prepared once for the visible image set.
        $mediaManifestEntry = is_array($publicMediaManifest[(int) $image['id']] ?? null) ? $publicMediaManifest[(int) $image['id']] : [];
        // Variable $thumbnailBundle stores all generated variants for this visible card during this request.
        $thumbnailBundle = is_array($mediaManifestEntry['bundle'] ?? null)
            ? $mediaManifestEntry['bundle']
            : public_render_profile_with_thumbnail_purpose('image card bundle discovery fallback', static fn (): array => thumbnail_bundle($image));
        // Variable $previewUrl stores this steps working value.
        $previewUrl = (string) ($mediaManifestEntry['preview_url'] ?? '');
        if ($previewUrl === '') {
            $previewUrl = public_render_profile_with_thumbnail_purpose('image card lightbox preview 1600 fallback', static fn (): string => thumbnail_bundle_url($thumbnailBundle, 1600));
        }
        // Variable $imageTags stores this steps working value.
        $imageTags = $imageTagsById[(int) $image['id']] ?? [];
        // Variable $imageHasPublicGps stores this steps working value.
        $imageHasPublicGps = $photoMapsAllowed && image_has_gps($image);
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $imageHasPublicGps ? public_render_profile_with_thumbnail_purpose('image card map preview 300', static fn (): array => image_map_point($image, $gallery, true, $thumbnailBundle)) : null;
        // Variable $displayIndex stores this steps working value.
        $displayIndex = $index + 1 + (!empty($paginationSettings['enabled']) ? (int) $photoPagination['offset'] : 0);
        // $lightboxIndex stores the zero-based index in the async lightbox order.
        $lightboxIndex = $lightboxExcludesRestrictedNsfw ? gallery_lightbox_image_position($image, $gallery, $publicOnly, true) : $displayIndex - 1;
        // Variable $altText stores this steps working value.
        $altText = image_alt_text($image, $gallery, $displayIndex);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        $imageCardClass = 'image-card' . ($publicPhotoReorderEnabled ? ' has-public-reorder-handle' : '') . ($pictureManagerEnabled ? ' has-picture-manager-select' : '');
        $pictureManagerAttributes = $pictureManagerEnabled ? ' data-picture-manager-image data-picture-manager-image-id="' . (int) $image['id'] . '" data-picture-manager-index="' . (int) $displayIndex . '" data-picture-manager-share-url="' . e($previewUrl) . '" data-picture-manager-share-filename="' . e(picture_manager_share_filename($image, $displayTitle)) . '" data-picture-manager-share-title="' . e($displayTitle) . '"' : '';
        $lightboxAttributes = $lightboxFeatureEnabled ? ' ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, 'data-lightbox-image', $votingAllowed, $lightboxIndex >= 0 ? $lightboxIndex : null, $thumbnailBundle) : '';
        echo '<article class="' . e($imageCardClass) . '" data-public-photo-order-item data-public-order-id="' . (int) $image['id'] . '"' . $pictureManagerAttributes . $lightboxAttributes . '>';
        if ($publicPhotoReorderEnabled) {
            echo '<button type="button" class="public-reorder-handle public-photo-reorder-handle" data-public-reorder-handle aria-label="' . e(t('public.reorder.drag_photo_label', 'Drag photo to reorder visible photos')) . '" title="' . e(t('public.reorder.drag_photo_title', 'Drag to reorder this visible photo')) . '"><span aria-hidden="true">↕</span><span>' . e(t('public.reorder.move_photo', 'Move photo')) . '</span></button>';
        }
        if ($pictureManagerEnabled) {
            echo '<button type="button" class="picture-manager-select-button" data-picture-manager-select aria-pressed="false" aria-label="' . e(t('picture_manager.select_photo', 'Select photo')) . '" title="' . e(t('picture_manager.select_photo', 'Select photo')) . '"><span aria-hidden="true">✓</span><span class="visually-hidden">' . e(t('picture_manager.select_photo', 'Select photo')) . '</span></button>';
        }
        echo '<div class="image-stage">';
        // $thumbnailSizesAttribute stores a responsive image hint derived from the configured grid.
        $thumbnailSizesAttribute = pagination_photo_thumbnail_sizes_attribute($paginationSettings);
        // $publicThumbnailRenderingMode selects only the photo picture strategy; loading policy stays centralized with the renderer.
        echo '<a class="image-preview-link" href="' . e($imagePageUrl) . '">' . public_render_profile_with_thumbnail_purpose('image card public thumbnail picture', static fn (): string => public_thumbnail_render_picture_html($image, 300, [300, 600, 800, 960], $thumbnailSizesAttribute, $altText, $index, $thumbnailBundle, $publicThumbnailRenderingMode)) . '</a>';
        if ($imageMapPoint) {
            echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="' . e(t('public.show_photo_location', 'Show photo location')) . '" title="' . e(t('public.show_photo_location', 'Show photo location')) . '">&#128205;</button>';
        }
        render_vote_form((int) $image['id'], (int) $image['score'], $vote, $votingAllowed);
        // Variable $hasPublicImageMeta stores whether the anonymous-facing metadata overlay has visible content.
        // Empty metadata is not rendered, because hidden file names should not leave a blank bar under the photo.
        // The metadata is rendered inside .image-stage so long descriptions do not increase card height or break the grid rhythm.
        $hasPublicImageMeta = $displayTitle !== '' || trim((string) $image['description']) !== '' || $imageTags;
        if ($hasPublicImageMeta) {
            echo '<div class="image-meta image-meta-overlay">';
            if ($displayTitle !== '') {
                echo '<h2>' . e($displayTitle) . '</h2>';
            }
            if (trim((string) $image['description']) !== '') {
                echo '<p>' . e($image['description']) . '</p>';
            }
            render_tag_list($imageTags);
            echo '</div>';
        }
        echo '</div>';
        render_public_image_admin_edit_link($image);
        render_public_image_admin_delete_form($image);
        echo '</article>';
    }
    });
    if ($images) {
        echo '</section>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], t('pagination.photo_pages', 'Photo pages'));
    }
    if ($children || $images) {
        echo '</div>';
        render_back_to_top_button();
        echo '</div>';
    }
    if ($lightboxFeatureEnabled) {
        render_lightbox($votingAllowed, $mapsAllowed, $galleryMapUrl, (string) $gallery['title'], $lightboxBrowsingMode);
    }
    if ($requestedImage && $lightboxFeatureEnabled) {
        append_cms_footer_script('document.addEventListener("DOMContentLoaded",function(){var selector="[data-lightbox-image][data-image-id=\"' . (int) $requestedImage['id'] . '\"], [data-lightbox-source][data-image-id=\"' . (int) $requestedImage['id'] . '\"]";var card=document.querySelector(selector);if(card){card.click();}});');
    }
    render_public_render_profile_panel();
    telemetry_append_public_script([
        'route_name' => 'gallery',
        'page_kind' => 'gallery',
        'gallery_id' => (int) $gallery['id'],
        'image_id' => $requestedImage ? (int) $requestedImage['id'] : null,
    ]);
    render_footer();
    gallery_benchmark_record_public_render($gallery, public_render_profile_snapshot());
}



/**
 * Render the anonymous preview control for logged-in admins viewing a public gallery page.
 */
