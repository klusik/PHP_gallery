<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_gallery_pages.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public gallery page shells from controller-prepared view models.
 *
 * Responsibilities:
 *   - Render the public gallery index/home presentation
 *   - Preserve mutation-verification metadata on public gallery containers
 *   - Compose prepared search, pagination, card, profiler, and navigation fragments
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
 *   - Translation, escaping, shared layout, and sibling view renderers are
 *     presentation dependencies.
 *   - The controller owns gallery lookup, pagination policy, feature policy,
 *     profiling orchestration, card preparation, and telemetry registration.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the public root gallery index.
 *
 * @param array<string,mixed> $viewModel Controller-prepared home-page state.
 */
function view_render_public_gallery_home(array $viewModel): void
{
    render_header((string) ($viewModel['site_name'] ?? ''));

    $physicalCount = (int) ($viewModel['physical_gallery_count'] ?? 0);
    $smartCount = (int) ($viewModel['smart_gallery_count'] ?? 0);
    $revision = (string) ($viewModel['physical_gallery_revision'] ?? '');
    $canonicalUrl = (string) ($viewModel['canonical_url'] ?? '');
    echo '<div data-public-gallery-index data-public-root-gallery-count="' . $physicalCount . '" data-public-root-gallery-revision="' . e($revision) . '" data-public-root-smart-gallery-count="' . $smartCount . '" data-admin-mutation-canonical-url="' . e($canonicalUrl) . '" hidden></div>';

    view_render_public_search_bar((array) ($viewModel['search_bar'] ?? []));

    if ((int) ($viewModel['gallery_count'] ?? 0) > 0) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
        echo (string) ($viewModel['pagination_html'] ?? '');
        echo '<section class="grid public-home-gallery-grid' . e((string) ($viewModel['grid_class'] ?? '')) . '" data-public-gallery-index-grid data-public-root-gallery-count="' . $physicalCount . '" data-public-root-gallery-revision="' . e($revision) . '" data-public-root-smart-gallery-count="' . $smartCount . '" data-public-gallery-page="' . (int) ($viewModel['current_page'] ?? 1) . '" data-public-gallery-total-pages="' . (int) ($viewModel['total_pages'] ?? 1) . '">';
        echo (string) ($viewModel['cards_html'] ?? '');
        echo '</section>';
        echo (string) ($viewModel['pagination_html'] ?? '');
        echo '</div>';
        echo (string) ($viewModel['back_to_top_html'] ?? '');
        echo '</div>';
    }

    echo (string) ($viewModel['render_profile_html'] ?? '');
    render_footer();
}

/**
 * Render one ordered Smart Gallery attachment area around physical gallery content.
 *
 * @param array<string,mixed> $viewModel Controller-prepared attachment-group state.
 */
function view_render_public_smart_gallery_attachment_group(array $viewModel): void
{
    $cardsHtml = (string) ($viewModel['cards_html'] ?? '');
    if ($cardsHtml === '') {
        return;
    }

    $placement = (string) ($viewModel['placement'] ?? 'bottom');
    $placement = $placement === 'top' ? 'top' : 'bottom';
    $label = $placement === 'top'
        ? t('smart_gallery.public_group_top', 'Smart Galleries above gallery content')
        : t('smart_gallery.public_group_bottom', 'Smart Galleries below gallery content');

    echo '<section class="panel public-smart-gallery-attachment-panel public-smart-gallery-attachment-' . e($placement) . '" data-smart-gallery-attachment-group="' . e($placement) . '" aria-label="' . e($label) . '">';
    echo '<div class="grid' . e((string) ($viewModel['grid_class'] ?? '')) . '">';
    echo $cardsHtml;
    echo '</div></section>';
}

/**
 * Render the selected-gallery hero from controller-prepared state.
 *
 * @param array<string,mixed> $viewModel Controller-prepared hero state.
 */
function view_render_public_gallery_hero(array $viewModel): void
{
    echo '<section class="hero" data-public-gallery-id="' . (int) ($viewModel['gallery_id'] ?? 0) . '" data-public-gallery-visibility="' . e((string) ($viewModel['visibility'] ?? '')) . '" data-public-gallery-updated-at="' . e((string) ($viewModel['updated_at'] ?? '')) . '" data-public-cover-image-id="' . max(0, (int) ($viewModel['cover_image_id'] ?? 0)) . '" data-public-image-count="' . (int) ($viewModel['image_count'] ?? 0) . '" data-public-image-revision="' . e((string) ($viewModel['image_revision'] ?? '')) . '" data-public-subgallery-count="' . (int) ($viewModel['subgallery_count'] ?? 0) . '" data-public-subgallery-revision="' . e((string) ($viewModel['subgallery_revision'] ?? '')) . '" data-public-smart-gallery-count="' . (int) ($viewModel['smart_gallery_count'] ?? 0) . '" data-admin-mutation-canonical-url="' . e((string) ($viewModel['canonical_url'] ?? '')) . '">';
    // Keep the title, date, description, and breadcrumbs in one primary column so long descriptions do not become a narrow middle strip.
    echo '<div class="hero-topbar">';
    echo '<div class="hero-primary">';
    echo (string) ($viewModel['branding_header_html'] ?? '');
    echo (string) ($viewModel['breadcrumbs_html'] ?? '');
    echo '</div>';
    echo '<div class="hero-meta">';
    echo '<div class="hero-actions" aria-label="' . e(t('gallery.actions', 'Gallery actions')) . '">';

    if (!empty($viewModel['show_count_badge'])) {
        $count = max(0, (int) ($viewModel['branch_image_count'] ?? 0));
        // Keep the branch count in the same responsive action row while its accessible label explains that descendants are included.
        echo '<div class="gallery-hero-count-badge" aria-label="' . e(t('gallery.hero.branch_image_count_aria', 'Image count for this gallery and its subgalleries: {count}', ['count' => $count])) . '" title="' . e(t('gallery.hero.branch_image_count_hint', 'Includes images from this gallery and its subgalleries.')) . '">';
        echo '<span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span>';
        echo '<span class="gallery-hero-count-value">' . $count . '</span>';
        echo '</div>';
    }

    echo (string) ($viewModel['admin_actions_html'] ?? '');

    if (!empty($viewModel['download_enabled'])) {
        $downloadLabel = t('gallery.download', 'Download gallery');
        echo '<form class="public-download-legacy-form" method="post" action="' . e((string) ($viewModel['download_url'] ?? '')) . '"><input type="hidden" name="id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '"><input type="hidden" name="capability" value="' . e((string) ($viewModel['download_capability'] ?? '')) . '"><button type="submit" class="button hero-icon-button hero-download-button" data-gallery-download data-gallery-download-start-url="' . e((string) ($viewModel['download_start_url'] ?? '')) . '" aria-label="' . e($downloadLabel) . '" title="' . e($downloadLabel) . '"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">' . e($downloadLabel) . '</span></button></form>';
    }
    if (!empty($viewModel['map_available'])) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e((string) ($viewModel['map_url'] ?? '')) . '" data-gallery-map-title="' . e((string) ($viewModel['title'] ?? '')) . '">' . e(t('gallery.show_map', 'Show gallery map')) . '</button>';
    }
    if (!empty($viewModel['picture_game_available'])) {
        $pictureGameLabel = t('gallery.play_picture_game', 'Play picture game');
        echo '<a class="button secondary hero-icon-button hero-picture-game-button" href="' . e((string) ($viewModel['picture_game_url'] ?? '')) . '" aria-label="' . e($pictureGameLabel) . '" title="' . e($pictureGameLabel) . '"><span aria-hidden="true">&#127918;</span><span class="visually-hidden">' . e($pictureGameLabel) . '</span></a>';
    }
    echo '</div></div>';

    $heroTagCount = max(0, (int) ($viewModel['tag_count'] ?? 0));
    if ($heroTagCount > 0) {
        $visibleLimit = max(0, (int) ($viewModel['tag_visible_limit'] ?? 0));
        $displayAll = !empty($viewModel['tag_display_all']);
        echo '<div class="hero-tags" aria-label="' . e(t('gallery.tags', 'Gallery tags')) . '" data-hero-tags data-hero-tag-visible-limit="' . $visibleLimit . '" data-hero-tag-display-all="' . ($displayAll ? '1' : '0') . '" data-hero-tag-scrollbar-enabled="' . (!empty($viewModel['tag_scrollbar_enabled']) ? '1' : '0') . '" data-hero-tag-scrollbar-rows="' . max(1, (int) ($viewModel['tag_scrollbar_rows'] ?? 1)) . '">';
        echo '<div class="hero-tags-content" data-hero-tags-content>';
        echo (string) ($viewModel['gallery_tags_html'] ?? '');
        echo (string) ($viewModel['contained_tags_html'] ?? '');
        echo '</div>';
        if (!$displayAll && $heroTagCount > $visibleLimit) {
            // The browser toggles visibility in-place. No navigation or server request is required to expose the complete collection.
            echo '<div class="hero-tags-controls"><button type="button" class="button secondary hero-tags-toggle" data-hero-tags-toggle hidden data-show-all-label="' . e(t('gallery.show_all_tags', 'Display all tags')) . '" data-show-fewer-label="' . e(t('gallery.show_fewer_tags', 'Show fewer tags')) . '" aria-expanded="false">' . e(t('gallery.show_all_tags', 'Display all tags')) . '</button></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';
}

/**
 * Render the physical child-gallery section.
 *
 * @param array<string,mixed> $viewModel Controller-prepared subgallery state.
 */
function view_render_public_subgallery_section(array $viewModel): void
{
    if (empty($viewModel['visible'])) {
        return;
    }
    $galleryId = (int) ($viewModel['gallery_id'] ?? 0);
    $totalCount = max(0, (int) ($viewModel['total_count'] ?? 0));
    $revision = (string) ($viewModel['revision'] ?? '');
    $currentPage = max(1, (int) ($viewModel['current_page'] ?? 1));
    $totalPages = max(1, (int) ($viewModel['total_pages'] ?? 1));

    echo '<section class="panel public-subgallery-panel" data-public-subgallery-section data-public-context-gallery-id="' . $galleryId . '" data-public-subgallery-total-count="' . $totalCount . '" data-public-subgallery-revision="' . e($revision) . '" data-public-gallery-page="' . $currentPage . '" data-public-gallery-total-pages="' . $totalPages . '" aria-label="' . e(t('public.subgalleries', 'Subgalleries')) . '">';
    echo (string) ($viewModel['sort_toolbar_html'] ?? '');
    echo (string) ($viewModel['reorder_toolbar_html'] ?? '');
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo '<div class="grid' . e((string) ($viewModel['grid_class'] ?? '')) . '" data-public-reorder-list="gallery" data-public-subgallery-grid data-public-context-gallery-id="' . $galleryId . '" data-public-subgallery-total-count="' . $totalCount . '" data-public-subgallery-revision="' . e($revision) . '" data-public-gallery-page="' . $currentPage . '" data-public-gallery-total-pages="' . $totalPages . '">';
    echo (string) ($viewModel['cards_html'] ?? '');
    echo '</div>';
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo '</section>';
}

/**
 * Render one restricted NSFW photo card without exposing thumbnail/media URLs.
 *
 * @param array<string,mixed> $viewModel Controller-prepared restricted-photo state.
 */
function view_render_public_gallery_nsfw_image_card(array $viewModel): void
{
    echo '<article class="image-card nsfw-card" data-public-photo-order-item data-public-order-id="' . (int) ($viewModel['image_id'] ?? 0) . '" data-public-image-visibility="' . e((string) ($viewModel['visibility'] ?? '')) . '" data-public-image-nsfw="' . (!empty($viewModel['nsfw']) ? '1' : '0') . '" data-public-image-updated-at="' . e((string) ($viewModel['updated_at'] ?? '')) . '"><div class="image-stage nsfw-stage"><a class="nsfw-placeholder" href="' . e((string) ($viewModel['image_url'] ?? '')) . '"><strong>' . e(t('public.nsfw_photo_title', '18+ photo')) . '</strong><span>' . e(t('public.nsfw_photo_message', 'Confirm your age to view this restricted photo.')) . '</span></a></div>';
    echo (string) ($viewModel['admin_controls_html'] ?? '');
    echo '</article>';
}

/**
 * Render one normal public photo card.
 *
 * @param array<string,mixed> $viewModel Controller-prepared photo-card state.
 */
function view_render_public_gallery_image_card(array $viewModel): void
{
    echo '<article class="' . e((string) ($viewModel['class'] ?? 'image-card')) . '" data-public-photo-order-item data-public-order-id="' . (int) ($viewModel['image_id'] ?? 0) . '" data-public-image-visibility="' . e((string) ($viewModel['visibility'] ?? '')) . '" data-public-image-nsfw="' . (!empty($viewModel['nsfw']) ? '1' : '0') . '" data-public-image-updated-at="' . e((string) ($viewModel['updated_at'] ?? '')) . '"';
    if (!empty($viewModel['picture_manager_enabled'])) {
        echo ' data-picture-manager-image data-picture-manager-image-id="' . (int) ($viewModel['image_id'] ?? 0) . '" data-picture-manager-index="' . (int) ($viewModel['display_index'] ?? 0) . '" data-picture-manager-share-url="' . e((string) ($viewModel['preview_url'] ?? '')) . '" data-picture-manager-share-filename="' . e((string) ($viewModel['share_filename'] ?? '')) . '" data-picture-manager-share-title="' . e((string) ($viewModel['display_title'] ?? '')) . '"';
    }
    echo (string) ($viewModel['lightbox_attributes'] ?? '');
    if (!empty($viewModel['viewer_favourite_available'])) {
        echo ' data-viewer-favourite="' . (!empty($viewModel['viewer_favourite']) ? '1' : '0') . '"';
    }
    echo '>';

    if (!empty($viewModel['reorder_enabled'])) {
        echo '<button type="button" class="public-reorder-handle public-photo-reorder-handle" data-public-reorder-handle aria-label="' . e(t('public.reorder.drag_photo_label', 'Drag photo to reorder visible photos')) . '" title="' . e(t('public.reorder.drag_photo_title', 'Drag to reorder this visible photo')) . '"><span aria-hidden="true">↕</span><span>' . e(t('public.reorder.move_photo', 'Move photo')) . '</span></button>';
    }
    if (!empty($viewModel['picture_manager_enabled'])) {
        $selectLabel = t('picture_manager.select_photo', 'Select photo');
        echo '<button type="button" class="picture-manager-select-button" data-picture-manager-select aria-pressed="false" aria-label="' . e($selectLabel) . '" title="' . e($selectLabel) . '"><span aria-hidden="true">✓</span><span class="visually-hidden">' . e($selectLabel) . '</span></button>';
    }

    echo '<div class="image-stage">';
    echo '<a class="image-preview-link" href="' . e((string) ($viewModel['image_url'] ?? '')) . '">' . (string) ($viewModel['thumbnail_html'] ?? '') . '</a>';
    if (!empty($viewModel['map_available'])) {
        $mapLabel = t('public.show_photo_location', 'Show photo location');
        echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="' . e($mapLabel) . '" title="' . e($mapLabel) . '">&#128205;</button>';
    }
    echo (string) ($viewModel['favourite_html'] ?? '');
    echo (string) ($viewModel['collection_html'] ?? '');
    echo (string) ($viewModel['vote_html'] ?? '');

    if (!empty($viewModel['has_public_meta'])) {
        echo '<div class="image-meta image-meta-overlay">';
        $displayTitle = (string) ($viewModel['display_title'] ?? '');
        if ($displayTitle !== '') {
            echo '<h2>' . e($displayTitle) . '</h2>';
        }
        $description = trim((string) ($viewModel['description'] ?? ''));
        if ($description !== '') {
            echo '<p>' . e($description) . '</p>';
        }
        echo (string) ($viewModel['tags_html'] ?? '');
        echo '</div>';
    }
    echo '</div>';
    echo (string) ($viewModel['admin_controls_html'] ?? '');
    echo '</article>';
}

/**
 * Render the selected-gallery photo section.
 *
 * @param array<string,mixed> $viewModel Controller-prepared photo-section state.
 */
function view_render_public_gallery_image_section(array $viewModel): void
{
    if (empty($viewModel['visible'])) {
        return;
    }

    echo (string) ($viewModel['reorder_toolbar_html'] ?? '');
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo '<section class="grid gallery-image-grid' . e((string) ($viewModel['grid_class'] ?? '')) . '" data-public-reorder-list="photo" data-gallery-image-list data-public-context-gallery-id="' . (int) ($viewModel['gallery_id'] ?? 0) . '" data-public-image-total-count="' . (int) ($viewModel['total_count'] ?? 0) . '" data-public-image-revision="' . e((string) ($viewModel['revision'] ?? '')) . '" data-public-image-page="' . max(1, (int) ($viewModel['current_page'] ?? 1)) . '" data-public-image-total-pages="' . max(1, (int) ($viewModel['total_pages'] ?? 1)) . '"';
    if (!empty($viewModel['lightbox_enabled'])) {
        echo ' data-lightbox-config data-lightbox-endpoint="' . e((string) ($viewModel['lightbox_endpoint'] ?? '')) . '" data-lightbox-total="' . max(0, (int) ($viewModel['lightbox_total'] ?? 0)) . '" data-lightbox-window-size="60" data-lightbox-browsing-mode="' . e((string) ($viewModel['lightbox_browsing_mode'] ?? '')) . '" data-lightbox-maps-enabled="' . (!empty($viewModel['lightbox_maps_enabled']) ? '1' : '0') . '" data-lightbox-gallery-map-url="' . e((string) ($viewModel['gallery_map_url'] ?? '')) . '" data-lightbox-gallery-map-title="' . e((string) ($viewModel['gallery_map_title'] ?? '')) . '"';
    }
    echo '>';
    echo (string) ($viewModel['cards_html'] ?? '');
    echo '</section>';
    echo (string) ($viewModel['pagination_html'] ?? '');
}

/**
 * Render one selected public gallery page from controller-prepared state.
 *
 * @param array<string,mixed> $viewModel Controller-prepared selected-gallery state.
 */
function view_render_public_gallery_detail(array $viewModel): void
{
    $gallery = (array) ($viewModel['gallery'] ?? []);
    render_header((string) ($viewModel['page_title'] ?? ''), $gallery, !empty($viewModel['public_only']));

    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    view_render_public_gallery_hero((array) ($viewModel['hero'] ?? []));
    echo (string) ($viewModel['branding_separator_html'] ?? '');
    echo (string) ($viewModel['preview_toolbar_html'] ?? '');
    view_render_public_search_bar((array) ($viewModel['search_bar'] ?? []));
    echo (string) ($viewModel['picture_manager_toolbar_html'] ?? '');

    if (!empty($viewModel['has_list_content'])) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    echo (string) ($viewModel['top_smart_group_html'] ?? '');
    view_render_public_subgallery_section((array) ($viewModel['subgallery_section'] ?? []));
    view_render_public_gallery_image_section((array) ($viewModel['image_section'] ?? []));
    echo (string) ($viewModel['bottom_smart_group_html'] ?? '');
    if (!empty($viewModel['has_list_content'])) {
        echo '</div>';
        echo (string) ($viewModel['back_to_top_html'] ?? '');
        echo '</div>';
    }

    echo (string) ($viewModel['lightbox_html'] ?? '');
    echo (string) ($viewModel['render_profile_html'] ?? '');
    render_footer();
}
