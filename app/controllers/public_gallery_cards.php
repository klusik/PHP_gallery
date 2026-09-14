<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_cards.php
 * Module Type: Controller
 *
 * Purpose:
 *   Builds public gallery cards and card-level admin controls.
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
 *   2026-09-14
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
use function Gallery\Services\gallery_trash_auto_purge_enabled;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_trash_enabled;
use function Gallery\Services\gallery_trash_retention_days;
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
use function Gallery\Services\smart_gallery_card_summaries;
use function Gallery\Services\smart_gallery_effective_presentation;
use function Gallery\Services\smart_gallery_thumbnail_sizes;
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
 * Return the stable responsive loading attributes still used by public gallery cover thumbnails.
 *
 * The first visible cards should load during the initial page render, because
 * lazy-loading a whole first row can leave empty thumbnail slots that then pop
 * in one by one. Later rows remain lazy. Selected-gallery photo cards now obtain
 * their responsive or progressive policy from public_thumbnail_rendering.php.
 *
 * @param int $index Index value.
 * @return string Text result for the caller.
 */
function public_thumbnail_loading_attributes(int $index): string
{
    return public_responsive_thumbnail_loading_attributes($index);
}

/**
 * Build preloaded rendering context for public gallery cards.
 *
 * The card renderer remains responsible for HTML output. This controller helper
 * only coordinates service-layer lookups so visible cards can share batched
 * child-gallery, branch-count, cover, collage, and thumbnail-bundle work.
 *
 * @param array $galleries Gallery rows to render.
 * @param bool $publicOnly Public only value.
 * @param bool $includeCounts Include branch image counts in the context.
 * @return array<int,array<string,mixed>> Context values keyed by gallery id.
 */
function public_gallery_card_rendering_contexts(array $galleries, bool $publicOnly, bool $includeCounts): array
{
    // $galleryIds stores visible gallery ids used for batched card context lookups.
    $galleryIds = array_values(array_unique(array_filter(array_map(static fn (array $gallery): int => (int) ($gallery['id'] ?? 0), $galleries), static fn (int $galleryId): bool => $galleryId > 0)));
    if (!$galleryIds) {
        return [];
    }

    child_galleries_tree_preload($galleryIds, $publicOnly);

    // $branchCounts stores optional contained image counts keyed by gallery id.
    $branchCounts = $includeCounts ? gallery_branch_image_counts($galleryIds, $publicOnly) : [];
    // $contexts stores preloaded rendering values keyed by gallery id.
    $contexts = [];
    // $thumbnailImages stores all cover and collage images whose thumbnail bundles can be warmed in one pass.
    $thumbnailImages = [];

    foreach ($galleries as $gallery) {
        // $galleryId stores the rendered gallery card id.
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }

        // $isProtectedPublicCard stores whether this public card must avoid exposing cover media.
        $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
        // $context stores preloaded values for this card.
        $context = [
            'branch_image_count' => (int) ($branchCounts[$galleryId] ?? 0),
            'cover_asset' => '',
            'cover' => null,
            'collage' => [],
        ];

        if (!$isProtectedPublicCard) {
            $context['cover_asset'] = gallery_cover_asset_url($gallery, $publicOnly);
            if ($context['cover_asset'] === '') {
                $context['cover'] = gallery_cover_image($galleryId, $publicOnly);
                if (is_array($context['cover'])) {
                    $thumbnailImages[(int) $context['cover']['id']] = $context['cover'];
                } else {
                    $context['collage'] = gallery_cover_collage_images($galleryId, $publicOnly);
                    foreach ($context['collage'] as $image) {
                        $thumbnailImages[(int) $image['id']] = $image;
                    }
                }
            }
        }

        $contexts[$galleryId] = $context;
    }

    thumbnail_bundles_preload(array_values($thumbnailImages));

    return $contexts;
}

/**
 * Handles render gallery card logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @param bool $showPublicReorderHandle Show public reorder handle value.
 * @param bool $showSubgalleryBadge Show subgallery badge value.
 * @param mixed $cardIndex Input used by this operation.
 * @param array $cardContext Preloaded card rendering context.
 * @param bool $pictureManagerEnabled Whether this physical gallery card participates in Picture manager selection.
 */
function render_gallery_card(array $gallery, bool $publicOnly, bool $showPublicReorderHandle = false, bool $showSubgalleryBadge = false, int $cardIndex = 0, array $cardContext = [], bool $pictureManagerEnabled = false): void
{
    // $isProtectedPublicCard stores an intermediate value used by the surrounding gallery workflow.
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // $descriptionLayout stores the visual layout selected by Theme or the gallery override.
    $descriptionLayout = array_key_exists('description_layout', $cardContext)
        ? gallery_description_layout_normalize($cardContext['description_layout'], gallery_effective_description_layout($gallery))
        : gallery_effective_description_layout($gallery);
    // $coverAsset stores an optional branding/cover asset resolved before rendering.
    $coverAsset = $isProtectedPublicCard ? '' : (array_key_exists('cover_asset', $cardContext) ? (string) $cardContext['cover_asset'] : public_render_profile_span('gallery_cover_asset_lookup', static fn (): string => gallery_cover_asset_url($gallery, $publicOnly)));
    // $cover stores the selected real image when no explicit asset is available.
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : (array_key_exists('cover', $cardContext) ? $cardContext['cover'] : public_render_profile_span('gallery_cover_image_lookup', static fn (): ?array => gallery_cover_image((int) $gallery['id'], $publicOnly)));
    // $showCountBadge stores whether this card should show the contained-picture badge.
    $showCountBadge = !$isProtectedPublicCard && $showSubgalleryBadge && gallery_effective_count_badge_enabled($gallery);
    // $branchImageCount stores this steps working value.
    $branchImageCount = $showCountBadge ? (array_key_exists('branch_image_count', $cardContext) ? (int) $cardContext['branch_image_count'] : public_render_profile_span('gallery_branch_image_count', static fn (): int => gallery_branch_image_count((int) $gallery['id'], $publicOnly))) : 0;
    // $galleryCardTags stores the tags shown in the card body. Own gallery tags win, then contained tags keep the old fallback useful.
    $galleryCardTags = $isProtectedPublicCard ? [] : public_render_profile_span('gallery_card_tag_lookup', static function () use ($gallery, $publicOnly): array {
        // $ownTags stores tags attached directly to the gallery record.
        $ownTags = tags_for_entity('gallery', (int) $gallery['id']);
        if ($ownTags) {
            return $ownTags;
        }
        return contained_tags_for_gallery($gallery, $publicOnly);
    });
    // $effectiveVisibility stores the normalized card visibility used for admin-only visual state markers.
    $effectiveVisibility = gallery_effective_visibility($gallery);
    // $showAdminUnpublishedMarker keeps unpublished galleries visible to admins while making their non-public state obvious.
    $showAdminUnpublishedMarker = current_user() && !admin_anonymous_preview_active() && $effectiveVisibility === 'unpublished';
    // $coverLoadingAttributes keeps above-the-fold gallery cards eager without forcing later rows to compete for bandwidth.
    $coverLoadingAttributes = public_thumbnail_loading_attributes($cardIndex);
    // $coverPictureHtml stores a stable existing thumbnail renderer result for the selected cover image.
    $coverPictureHtml = '';
    // $collagePictureHtml stores stable thumbnail renderer results for fallback collage images.
    $collagePictureHtml = [];
    if (!$isProtectedPublicCard && $coverAsset === '' && is_array($cover)) {
        $coverThumbnailBundle = public_render_profile_span('subgallery_cover_thumbnail_bundle', static fn (): array => thumbnail_bundle($cover));
        $coverPictureHtml = public_render_profile_with_thumbnail_purpose('subgallery cover stable picture', static fn (): string => thumbnail_picture_html($cover, 300, [300, 600, 800, 960], '(max-width: 299px) 300px, 800px', '', $coverLoadingAttributes, $coverThumbnailBundle));
    } elseif (!$isProtectedPublicCard && $coverAsset === '') {
        // $collage stores this steps working value.
        $collage = array_key_exists('collage', $cardContext) ? (array) $cardContext['collage'] : public_render_profile_span('gallery_cover_collage_lookup', static fn (): array => gallery_cover_collage_images((int) $gallery['id'], $publicOnly));
        foreach ($collage as $image) {
            // Collage images must not use progressive replacement. A metagallery card contains several child covers, and independent delayed srcset upgrades make the card repaint in visible waves. Render a stable srcset immediately and let the browser choose the best candidate once.
            $collageThumbnailBundle = public_render_profile_span('subgallery_collage_thumbnail_bundle', static fn (): array => thumbnail_bundle($image));
            $collagePictureHtml[] = public_render_profile_with_thumbnail_purpose('subgallery collage stable picture', static fn (): string => thumbnail_picture_html($image, 300, [300, 600, 800], '(max-width: 520px) 300px, 420px', '', $coverLoadingAttributes, $collageThumbnailBundle));
        }
    }

    // $hasHorizontalMeta keeps the exact legacy condition for showing the combined date/tag row.
    $hasHorizontalMeta = $descriptionLayout === 'horizontal'
        && !$isProtectedPublicCard
        && ($galleryCardTags || gallery_date_range_display_value($gallery['gallery_date'] ?? null, $gallery['gallery_date_end'] ?? null) !== '');
    // $horizontalMetaHtml, $dateHtml, and $tagListHtml reuse established presentation helpers as trusted fragments.
    $horizontalMetaHtml = '';
    $dateHtml = '';
    $tagListHtml = '';
    if ($hasHorizontalMeta) {
        ob_start();
        view_render_gallery_date(gallery_date_view_model($gallery), 'gallery-card-date');
        render_compact_tag_list($galleryCardTags);
        $horizontalMetaHtml = (string) ob_get_clean();
    } elseif (!$isProtectedPublicCard) {
        ob_start();
        view_render_gallery_date(gallery_date_view_model($gallery), 'gallery-card-date');
        $dateHtml = (string) ob_get_clean();
    }
    if (!$isProtectedPublicCard && $descriptionLayout !== 'horizontal') {
        ob_start();
        render_tag_list($galleryCardTags, t('gallery.containing_tags', 'Containing tags'));
        $tagListHtml = (string) ob_get_clean();
    }

    // $adminControlsHtml keeps card controls inside the card while each compatibility wrapper owns policy preparation.
    ob_start();
    render_public_gallery_admin_visibility_menu($gallery);
    render_public_gallery_admin_edit_link($gallery, 'card');
    render_public_gallery_admin_delete_form($gallery, 'card');
    $adminControlsHtml = (string) ob_get_clean();

    \Gallery\Views\view_render_public_gallery_card([
        'gallery_id' => (int) $gallery['id'],
        'title' => (string) ($gallery['title'] ?? ''),
        'url' => gallery_public_url($gallery),
        'visibility' => $effectiveVisibility,
        'updated_at' => (string) ($gallery['updated_at'] ?? ''),
        'description_layout' => $descriptionLayout,
        'description' => (string) ($gallery['description'] ?? ''),
        'description_links' => public_gallery_description_link_models((string) ($gallery['description'] ?? '')),
        'is_protected' => $isProtectedPublicCard,
        'show_reorder_handle' => $showPublicReorderHandle,
        'picture_manager_enabled' => $pictureManagerEnabled,
        'show_unpublished_marker' => $showAdminUnpublishedMarker,
        'show_count_badge' => $showCountBadge,
        'branch_image_count' => $branchImageCount,
        'cover_asset' => $coverAsset,
        'cover_loading_attributes' => $coverLoadingAttributes,
        'cover_picture_html' => $coverPictureHtml,
        'collage_picture_html' => $collagePictureHtml,
        'horizontal_meta_html' => $horizontalMetaHtml,
        'date_html' => $dateHtml,
        'tag_list_html' => $tagListHtml,
        'admin_controls_html' => $adminControlsHtml,
    ]);
}

/** Render a placed Smart Gallery with the established public gallery-card structure. */
function render_smart_gallery_card(array $smartGallery, int $cardIndex = 0, array $cardContext = []): void
{
    $smartGalleryId = (int) ($smartGallery['id'] ?? 0);
    if ($smartGalleryId <= 0) return;
    if ($cardContext === []) {
        $contexts = smart_gallery_card_summaries([$smartGallery], true);
        $cardContext = (array) ($contexts[$smartGalleryId] ?? []);
    }
    if ($cardContext === [] || (array_key_exists('valid', $cardContext) && empty($cardContext['valid']))) {
        return;
    }
    $count = max(0, (int) ($cardContext['count'] ?? 0));
    $cover = isset($cardContext['cover']) && is_array($cardContext['cover']) ? $cardContext['cover'] : null;
    $sourceGallery = isset($cardContext['source_gallery']) && is_array($cardContext['source_gallery']) ? $cardContext['source_gallery'] : [];
    $presentation = smart_gallery_effective_presentation($smartGallery);
    // $coverPictureHtml stores the existing responsive Smart Gallery thumbnail output.
    $coverPictureHtml = '';
    if (is_array($cover)) {
        $bundle = thumbnail_bundle($cover);
        $candidateSizes = $sourceGallery !== [] ? smart_gallery_thumbnail_sizes($presentation, $cover, $sourceGallery, [300, 600, 800, 960]) : [300, 600, 800];
        $fallbackSize = (int) ($candidateSizes[0] ?? 300);
        $coverPictureHtml = public_thumbnail_render_picture_html($cover, $fallbackSize, $candidateSizes, '(max-width: 520px) 300px, 420px', '', $cardIndex, $bundle, (string) $presentation['thumbnail_rendering_mode']);
    }

    \Gallery\Views\view_render_public_smart_gallery_card([
        'gallery_id' => $smartGalleryId,
        'title' => (string) ($smartGallery['title'] ?? ''),
        'description' => (string) ($smartGallery['description'] ?? ''),
        'url' => url_for('smart_gallery', ['slug' => (string) $smartGallery['slug']]),
        'count' => $count,
        'card_layout' => (string) ($presentation['card_layout'] ?? 'vertical'),
        'cover_picture_html' => $coverPictureHtml,
        'has_placement' => array_key_exists('placement', $smartGallery),
        'placement' => (string) ($smartGallery['placement'] ?? ''),
        'placement_order' => max(0, (int) ($smartGallery['placement_order'] ?? 0)),
    ]);
}

/**
 * Render the compact public-page child-gallery creation entry point for logged-in admins.
 *
 * This opens the upload controller in create-and-upload mode, so the admin can
 * create a child gallery and optionally upload photos in one panel workflow.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 */
function render_public_gallery_admin_add_child_link(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    \Gallery\Views\view_render_public_gallery_admin_add_child_link([
        'placement' => $placement,
        'title' => (string) ($gallery['title'] ?? ''),
        'url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]),
        'panel_url' => url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]),
    ]);
}

/**
 * Render the compact public-page gallery edit entry point for logged-in admins.
 *
 * The link keeps the full admin edit route as its href while enhancing the click
 * into the existing side-panel workflow when JavaScript is available.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 */
function render_public_gallery_admin_edit_link(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    \Gallery\Views\view_render_public_gallery_admin_edit_link([
        'placement' => $placement,
        'title' => (string) ($gallery['title'] ?? ''),
        'url' => url_for('admin_edit_gallery', ['id' => $gallery['id']]),
        'panel_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'panel' => 1]),
    ]);
}

/**
 * Render the compact public-page gallery delete entry point for logged-in admins.
 *
 * This uses the existing public admin update route and keeps the action as an
 * explicit POST so browsers without JavaScript still have a safe CSRF-protected
 * fallback. JavaScript adds the confirmation prompt before the form is submitted.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 */
function render_public_gallery_admin_delete_form(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    \Gallery\Views\view_render_public_gallery_admin_delete_form([
        'placement' => $placement,
        'name' => trim((string) ($gallery['title'] ?? 'gallery')),
        'gallery_id' => (int) $gallery['id'],
        'action_url' => url_for('admin_public_update_gallery'),
        'csrf_html' => csrf_field(),
        'trash_enabled' => gallery_trash_enabled(),
        'auto_purge_enabled' => gallery_trash_auto_purge_enabled(),
        'retention_days' => gallery_trash_retention_days(),
    ]);
}

/** Render the compact three-state visibility menu for one public gallery card. */
function render_public_gallery_admin_visibility_menu(array $gallery): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    $name = trim((string) ($gallery['title'] ?? 'gallery'));
    render_public_admin_visibility_menu('gallery', (int) ($gallery['id'] ?? 0), $name, gallery_effective_visibility($gallery), url_for('admin_public_update_gallery'));
}

/**
 * Render the compact public-page photo edit entry point for logged-in admins.
 *
 * The link falls back to the full admin edit image page and uses the current
 * side-panel loader when the gallery JavaScript is active.
 *
 * @param mixed $image Input used by this operation.
 */
function render_public_image_admin_edit_link(array $image): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    \Gallery\Views\view_render_public_image_admin_edit_link([
        'name' => $name,
        'url' => url_for('admin_edit_image', ['id' => $image['id']]),
        'panel_url' => url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1]),
    ]);
}

/**
 * Render the compact public-page photo delete entry point for logged-in admins.
 *
 * The form reuses the existing public admin image update route. The route removes
 * the image row from the CMS and redirects back to the current public context
 * when JavaScript is unavailable.
 *
 * @param mixed $image Input used by this operation.
 */
function render_public_image_admin_delete_form(array $image): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    \Gallery\Views\view_render_public_image_admin_delete_form([
        'name' => $name,
        'image_id' => (int) $image['id'],
        'action_url' => url_for('admin_public_update_image'),
        'csrf_html' => csrf_field(),
    ]);
}

/** Render the compact three-state visibility menu for one public image card. */
function render_public_image_admin_visibility_menu(array $image): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration')) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    $visibility = (string) ($image['visibility'] ?? 'draft');
    render_public_admin_visibility_menu('image', (int) ($image['id'] ?? 0), $name, $visibility === 'draft' ? 'unpublished' : $visibility, url_for('admin_public_update_image'));
}

/** Render shared accessible visibility-menu markup for a gallery or image card. */
function render_public_admin_visibility_menu(string $kind, int $entityId, string $name, string $visibility, string $actionUrl): void
{
    if ($entityId <= 0) return;
    \Gallery\Views\view_render_public_admin_visibility_menu([
        'kind' => $kind,
        'entity_id' => $entityId,
        'name' => $name,
        'visibility' => $visibility,
        'action_url' => $actionUrl,
        'csrf_html' => csrf_field(),
    ]);
}

