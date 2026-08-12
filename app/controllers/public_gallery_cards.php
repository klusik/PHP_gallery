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
 */
function render_gallery_card(array $gallery, bool $publicOnly, bool $showPublicReorderHandle = false, bool $showSubgalleryBadge = false, int $cardIndex = 0, array $cardContext = []): void
{
    // $isProtectedPublicCard stores an intermediate value used by the surrounding gallery workflow.
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // $descriptionLayout stores the visual layout selected by Theme or the gallery override.
    $descriptionLayout = array_key_exists('description_layout', $cardContext)
        ? gallery_description_layout_normalize($cardContext['description_layout'], gallery_effective_description_layout($gallery))
        : gallery_effective_description_layout($gallery);
    // Variable $cover stores this steps working value.
    $coverAsset = $isProtectedPublicCard ? '' : (array_key_exists('cover_asset', $cardContext) ? (string) $cardContext['cover_asset'] : public_render_profile_span('gallery_cover_asset_lookup', static fn (): string => gallery_cover_asset_url($gallery, $publicOnly)));
    // $cover stores an intermediate value used by the surrounding gallery workflow.
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : (array_key_exists('cover', $cardContext) ? $cardContext['cover'] : public_render_profile_span('gallery_cover_image_lookup', static fn (): ?array => gallery_cover_image((int) $gallery['id'], $publicOnly)));
    // $showCountBadge stores whether this card should show the contained-picture badge.
    $showCountBadge = !$isProtectedPublicCard && $showSubgalleryBadge && gallery_effective_count_badge_enabled($gallery);
    // Variable $branchImageCount stores this steps working value.
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
    // $descriptionPreview stores the card-length Markdown source. Full text remains visible in the opened gallery hero.
    $descriptionPreview = view_gallery_description_markdown_excerpt((string) ($gallery['description'] ?? ''));
    // $descriptionHtml stores the safe rendered card description.
    $descriptionHtml = view_gallery_description_markdown_html($descriptionPreview);
    // $effectiveVisibility stores the normalized card visibility used for admin-only visual state markers.
    $effectiveVisibility = gallery_effective_visibility($gallery);
    // $showAdminUnpublishedMarker keeps unpublished galleries visible to admins while making their non-public state obvious.
    $showAdminUnpublishedMarker = current_user() && !admin_anonymous_preview_active() && $effectiveVisibility === 'unpublished';
    $galleryCardClass = 'gallery-card is-gallery-description-' . $descriptionLayout . ($isProtectedPublicCard ? ' is-protected-gallery' : '') . ($showPublicReorderHandle ? ' has-public-reorder-handle' : '') . ($showAdminUnpublishedMarker ? ' is-admin-unpublished-gallery' : '');
    echo '<article class="' . e($galleryCardClass) . '" data-gallery-id="' . (int) $gallery['id'] . '" data-gallery-visibility="' . e($effectiveVisibility) . '" data-public-gallery-order-item data-public-order-id="' . (int) $gallery['id'] . '">';
    if ($showAdminUnpublishedMarker) {
        echo '<span class="admin-gallery-visibility-marker" title="' . e(t('gallery.card.unpublished_admin_hint', 'Only logged-in admins can see this gallery in listings.')) . '">' . e(t('gallery.visibility.unpublished', 'unpublished')) . '</span>';
    }
    if ($showPublicReorderHandle) {
        echo '<button type="button" class="public-reorder-handle public-gallery-reorder-handle" data-public-reorder-handle aria-label="' . e(t('gallery.reorder.drag_subgallery_aria', 'Drag subgallery to reorder visible subgalleries')) . '" title="' . e(t('gallery.reorder.drag_subgallery_title', 'Drag to reorder this visible subgallery')) . '"><span aria-hidden="true">↕</span><span>' . e(t('gallery.reorder.move_gallery', 'Move gallery')) . '</span></button>';
    }
    echo '<a class="gallery-card-media" href="' . e(gallery_public_url($gallery)) . '" aria-label="' . e(t('gallery.card.open_gallery', 'Open gallery {title}', ['title' => (string) $gallery['title']])) . '">';
    if ($showCountBadge) {
        echo '<span class="subgallery-stack-badge" aria-label="' . e(t('gallery.card.subgallery_image_count', 'Subgallery containing {count} images', ['count' => (int) $branchImageCount])) . '"><span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="subgallery-stack-count">' . (int) $branchImageCount . '</span></span>';
    }
    // $coverLoadingAttributes keeps above-the-fold gallery cards eager without forcing later rows to compete for bandwidth.
    $coverLoadingAttributes = public_thumbnail_loading_attributes($cardIndex);
    if ($isProtectedPublicCard) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">' . e(t('gallery.card.protected', 'Protected')) . '</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" ' . $coverLoadingAttributes . ' src="' . e($coverAsset) . '" alt="">';
    } elseif ($cover) {
        $coverThumbnailBundle = public_render_profile_span('subgallery_cover_thumbnail_bundle', static fn (): array => thumbnail_bundle($cover));
        echo public_render_profile_with_thumbnail_purpose('subgallery cover stable picture', static fn (): string => thumbnail_picture_html($cover, 300, [300, 600, 800, 960], '(max-width: 299px) 300px, 800px', '', $coverLoadingAttributes, $coverThumbnailBundle));
    } else {
        // Variable $collage stores this steps working value.
        $collage = array_key_exists('collage', $cardContext) ? (array) $cardContext['collage'] : public_render_profile_span('gallery_cover_collage_lookup', static fn (): array => gallery_cover_collage_images((int) $gallery['id'], $publicOnly));
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                // Collage images must not use progressive replacement. A metagallery card contains several child covers, and independent delayed srcset upgrades make the card repaint in visible waves. Render a stable srcset immediately and let the browser choose the best candidate once.
                $collageThumbnailBundle = public_render_profile_span('subgallery_collage_thumbnail_bundle', static fn (): array => thumbnail_bundle($image));
                echo public_render_profile_with_thumbnail_purpose('subgallery collage stable picture', static fn (): string => thumbnail_picture_html($image, 300, [300, 600, 800], '(max-width: 520px) 300px, 420px', '', $coverLoadingAttributes, $collageThumbnailBundle));
            }
            echo '</span>';
        }
    }
    echo '</a>';
    echo '<div class="gallery-card-body"><h2><a class="gallery-card-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></h2>';
    if ($descriptionLayout === 'horizontal' && !$isProtectedPublicCard && ($galleryCardTags || gallery_date_range_display_value($gallery['gallery_date'] ?? null, $gallery['gallery_date_end'] ?? null) !== '')) {
        echo '<div class="gallery-card-meta-row">';
        render_gallery_date($gallery, 'gallery-card-date');
        render_compact_tag_list($galleryCardTags);
        echo '</div>';
    } elseif (!$isProtectedPublicCard) {
        render_gallery_date($gallery, 'gallery-card-date');
    }
    if ($descriptionHtml !== '') {
        echo '<div class="gallery-card-description gallery-card-description-rich">' . $descriptionHtml . '</div>';
    }
    if ($isProtectedPublicCard) {
        echo '<p class="muted gallery-card-count">' . e(t('gallery.card.protected_gallery', 'Protected gallery')) . '</p>';
    } else {
        if ($showCountBadge) {
            echo '<p class="muted gallery-card-count gallery-card-count-visual-hidden">' . e(t('gallery.image_count', '{count} images', ['count' => (int) $branchImageCount])) . '</p>';
        }
        if ($descriptionLayout !== 'horizontal') {
            render_tag_list($galleryCardTags, t('gallery.containing_tags', 'Containing tags'));
        }
    }
    echo '</div>';
    render_public_gallery_admin_edit_link($gallery, 'card');
    render_public_gallery_admin_delete_form($gallery, 'card');
    echo '</article>';
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
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $label = $placement === 'hero' ? t('gallery.add_here', 'Add gallery here') : t('gallery.add_inside', 'Add gallery inside {title}', ['title' => (string) $gallery['title']]);
    $class = $placement === 'hero' ? 'public-admin-add-gallery-button public-admin-add-gallery-button-hero hero-icon-button' : 'public-admin-add-gallery-button public-admin-add-gallery-button-card';
    $url = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]);
    $panelUrl = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]);
    echo '<a class="' . e($class) . '" href="' . e($url) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="' . e(t('gallery.workflow', 'Gallery workflow')) . '" data-admin-side-panel-title="' . e(t('gallery.add_here', 'Add gallery here')) . '" data-gallery-side-panel-url="' . e($panelUrl) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">+</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $label = $placement === 'hero' ? t('gallery.edit_current', 'Edit current gallery') : t('gallery.edit_named', 'Edit gallery {title}', ['title' => (string) $gallery['title']]);
    $class = $placement === 'hero' ? 'public-admin-edit-button public-admin-edit-button-hero' : 'public-admin-edit-button public-admin-edit-button-card';
    echo '<a class="' . e($class) . '" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="gallery-edit" data-admin-side-panel-kicker="' . e(t('gallery.editor', 'Gallery editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit', 'Edit gallery')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $name = trim((string) ($gallery['title'] ?? 'gallery'));
    $label = $placement === 'hero' ? t('gallery.remove_current', 'Remove current gallery from CMS') : t('gallery.remove_named', 'Remove gallery {name} from CMS', ['name' => $name]);
    $class = $placement === 'hero' ? 'public-admin-delete-form public-admin-delete-form-hero' : 'public-admin-delete-form public-admin-delete-form-card';
    echo '<form class="' . e($class) . '" method="post" action="' . e(url_for('admin_public_update_gallery')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="gallery">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
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
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    $label = t('gallery.edit_photo_named', 'Edit photo {name}', ['name' => $name]);
    echo '<a class="public-admin-edit-button public-admin-edit-button-card public-admin-edit-button-photo" href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('gallery.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    $label = t('gallery.remove_photo_named', 'Remove photo {name} from CMS', ['name' => $name]);
    echo '<form class="public-admin-delete-form public-admin-delete-form-card public-admin-delete-form-photo" method="post" action="' . e(url_for('admin_public_update_image')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="photo">';
    echo csrf_field();
    echo '<input type="hidden" name="image_id" value="' . (int) $image['id'] . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}
