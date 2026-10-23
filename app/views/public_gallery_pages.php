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
 * @param array{site_name?:string,canonical_url?:string,gallery_count?:int,
 *     physical_gallery_count?:int,physical_gallery_revision?:string,
 *     smart_gallery_count?:int,creation_action?:array{placement:string,
 *         title:string,url:string,panel_url:string}|null,show_first_gallery?:bool,
 *     search_bar?:array{enabled?:bool,search_id?:string,context_id?:string,
 *         gallery_id?:int,aria_label?:string,placeholder?:string,search_url?:string,
 *         min_length?:int,delay_ms?:int,media_delay_ms?:int,
 *         descriptive_delay_ms?:int,deep_delay_ms?:int,loading_label?:string,
 *         empty_label?:string,error_label?:string,context_label?:string,
 *         clear_label?:string},
 *     pagination_html?:string,grid_class?:string,current_page?:int,
 *     total_pages?:int,cards_html?:string,back_to_top_html?:string,
 *     render_profile_html?:string,public_widgets?:array<string,list<array{
 *         widget_id:string,title:string,body_html:string,appearance:string,
 *         source_language:string,placement_mode:string,floating_anchor:string,
 *         x_permille:int,y_permille:int,width_px:int,sort_order:int}>>} $viewModel
 *     Controller-prepared heading, discovery controls, cards and widget zones.
 * @return void Emit public content and prepared inline actions without resolving authorization or catalog state.
 */
function view_render_public_gallery_home(array $viewModel): void
{
    render_header((string) ($viewModel['site_name'] ?? ''));
    echo '<h1 class="visually-hidden">'
        . e((string) ($viewModel['site_name'] ?? '')) . '</h1>';
    $widgets = (array) ($viewModel['public_widgets'] ?? []);
    view_render_public_widget_region($widgets, 'content_top');
    $hasRails = !empty($widgets['left_rail']) || !empty($widgets['right_rail']);
    if ($hasRails) {
        $railMode = !empty($widgets['left_rail'])
        && !empty($widgets['right_rail'])
            ? 'both'
            : (!empty($widgets['left_rail']) ? 'left' : 'right');
        echo
            '<div class="public-widget-rails-container"><div class="public-widget-content-layout public-widget-content-layout--'
                . $railMode
                . '"><div class="public-widget-primary">';
    }

    $physicalCount = (int) ($viewModel['physical_gallery_count'] ?? 0);
    $smartCount = (int) ($viewModel['smart_gallery_count'] ?? 0);
    $revision = (string) ($viewModel['physical_gallery_revision'] ?? '');
    $canonicalUrl = (string) ($viewModel['canonical_url'] ?? '');
    $creationAction = $viewModel['creation_action'] ?? null;
    if (is_array($creationAction)) {
        echo
            '<div class="public-home-toolbar"><div class="hero-actions public-home-actions" aria-label="'
                . e(t('gallery.actions', 'Gallery actions'))
                . '">';
        view_render_public_gallery_admin_add_child_link($creationAction);
        echo '</div>';
        view_render_public_search_bar((array) ($viewModel['search_bar'] ?? []));
        echo '</div>';
    } else {
        view_render_public_search_bar((array) ($viewModel['search_bar'] ?? []));
    }

    // Keep the root frame mounted while empty so the shared completion coordinator
    // can replace onboarding and verification metadata after the first creation.
    echo
        '<div class="gallery-list-frame" data-back-to-top-scope><div class="gallery-list-content" data-back-to-top-list>';
    echo
        '<div data-public-gallery-index data-public-root-gallery-count="'
            . $physicalCount
            . '" data-public-root-gallery-revision="'
            . e($revision)
            . '" data-public-root-smart-gallery-count="'
            . $smartCount
            . '" data-admin-mutation-canonical-url="'
            . e($canonicalUrl)
            . '" hidden></div>';
    if (is_array($creationAction) && !empty($viewModel['show_first_gallery'])) {
        echo
            '<section class="public-home-empty" data-public-home-first-gallery><h2>'
                . e(t('gallery.home.empty_title', 'No galleries yet'))
                . '</h2><p class="muted">'
                . e(t(
                    'gallery.home.empty_help',
                    'Start with a name. You can add photos and adjust the gallery in the side panel.',
                ))
                . '</p>';
        view_render_public_gallery_admin_add_child_link(array_merge($creationAction, [
            'label' => t('gallery.home.add_first', 'Add your first gallery'),
            'show_label' => true,
        ]));
        echo '</section>';
    }
    view_render_public_widget_region($widgets, 'home_before_grid');
    if ((int) ($viewModel['gallery_count'] ?? 0) > 0) {
        echo (string) ($viewModel['pagination_html'] ?? '');
        echo
            '<section class="grid public-home-gallery-grid'
                . e((string) ($viewModel['grid_class'] ?? ''))
                . '" data-public-gallery-index-grid data-public-root-gallery-count="'
                . $physicalCount
                . '" data-public-root-gallery-revision="'
                . e($revision)
                . '" data-public-root-smart-gallery-count="'
                . $smartCount
                . '" data-public-gallery-page="'
                . (int) ($viewModel['current_page'] ?? 1)
                . '" data-public-gallery-total-pages="'
                . (int) ($viewModel['total_pages'] ?? 1)
                . '">';
        echo (string) ($viewModel['cards_html'] ?? '');
        echo '</section>';
        echo (string) ($viewModel['pagination_html'] ?? '');
    }
    view_render_public_widget_region($widgets, 'home_after_grid');
    echo '</div>' . (string) ($viewModel['back_to_top_html'] ?? '') . '</div>';
    if ($hasRails) {
        echo '</div>';
        view_render_public_widget_region($widgets, 'left_rail');
        view_render_public_widget_region($widgets, 'right_rail');
        echo '</div></div>';
    }
    view_render_public_widget_region($widgets, 'content_bottom');
    view_render_public_widget_region($widgets, 'floating');
    echo (string) ($viewModel['render_profile_html'] ?? '');
    render_footer(view_public_widget_region_html($widgets, 'footer'));
}

/**
 * Render one ordered Smart Gallery attachment area around physical gallery content.
 *
 * @param array<string,mixed> $viewModel Controller-prepared attachment-group state.
 * @return void Writes the attachment group when it has rendered cards.
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
        ? t(
            'smart_gallery.public_group_top',
            'Smart Galleries above gallery content',
        )
        : t(
            'smart_gallery.public_group_bottom',
            'Smart Galleries below gallery content',
        );

    echo
        '<section class="public-smart-gallery-attachment-panel public-smart-gallery-attachment-'
            . e($placement)
            . '" data-smart-gallery-attachment-group="'
            . e($placement)
            . '" aria-label="'
            . e($label)
            . '">';
    echo
        '<div class="grid'
            . e((string) ($viewModel['grid_class'] ?? ''))
            . '">';
    echo $cardsHtml;
    echo '</div></section>';
}

/**
 * Render the selected-gallery hero from controller-prepared state.
 *
 * @param array{
 *     gallery_id?:int,
 *     visibility?:string,
 *     updated_at?:string,
 *     cover_image_id?:int,
 *     cover_selection_revision?:string,
 *     image_count?:int,
 *     image_revision?:string,
 *     subgallery_count?:int,
 *     subgallery_revision?:string,
 *     smart_gallery_count?:int,
 *     canonical_url?:string,
 *     title?:string,
 *     branding_header_html?:string,
 *     description_documents_html?:string,
 *     breadcrumbs_html?:string,
 *     show_count_badge?:bool,
 *     branch_image_count?:int,
 *     media_counts?:array{music:int,pdf:int},
 *     count_badge?:array{
 *         visible?:bool,
 *         admin?:bool,
 *         mode?:string,
 *         icons?:bool,
 *         public_count?:int,
 *         all_count?:?int
 *     },
 *     admin_actions_html?:string,
 *     download_enabled?:bool,
 *     download_url?:string,
 *     download_start_url?:string,
 *     download_capability?:string,
 *     map_available?:bool,
 *     map_url?:string,
 *     picture_game_available?:bool,
 *     picture_game_url?:string,
 *     tag_count?:int,
 *     tag_visible_limit?:int,
 *     tag_display_all?:bool,
 *     tag_scrollbar_enabled?:bool,
 *     tag_scrollbar_rows?:int,
 *     tag_groups?:list<array{
 *         items:list<array{name:string,href:?string}>,
 *         label:?string
 *     }>,
 *     cooperative_links?:list<array{url:string,label:string}>
 * } $viewModel Controller-prepared hero state, including policy-gated media
 *     counts and escaped-or-trusted presentation fragments.
 * @return void Render the hero and prepared actions.
 */
function view_render_public_gallery_hero(array $viewModel): void
{
    echo
        '<section class="hero" data-public-gallery-id="'
            . (int) ($viewModel['gallery_id'] ?? 0)
            . '" data-public-gallery-visibility="'
            . e((string) ($viewModel['visibility'] ?? ''))
            . '" data-public-gallery-updated-at="'
            . e((string) ($viewModel['updated_at'] ?? ''))
            . '" data-public-cover-image-id="'
            . max(0, (int) ($viewModel['cover_image_id'] ?? 0))
            . '" data-public-cover-selection-revision="'
            . e((string) ($viewModel['cover_selection_revision'] ?? ''))
            . '" data-public-image-count="'
            . (int) ($viewModel['image_count'] ?? 0)
            . '" data-public-image-revision="'
            . e((string) ($viewModel['image_revision'] ?? ''))
            . '" data-public-subgallery-count="'
            . (int) ($viewModel['subgallery_count'] ?? 0)
            . '" data-public-subgallery-revision="'
            . e((string) ($viewModel['subgallery_revision'] ?? ''))
            . '" data-public-smart-gallery-count="'
            . (int) ($viewModel['smart_gallery_count'] ?? 0)
            . '" data-admin-mutation-canonical-url="'
            . e((string) ($viewModel['canonical_url'] ?? ''))
            . '">';
    // Title/date and header actions retain their existing columns. Only the
    // description/document row uses the full available width below them.
    echo '<div class="hero-topbar">';
    echo '<div class="hero-primary">';
    echo (string) ($viewModel['branding_header_html'] ?? '');
    echo '</div>';
    echo '<div class="hero-meta">';
    echo
        '<div class="hero-actions" aria-label="'
            . e(t('gallery.actions', 'Gallery actions'))
            . '">';

    if (!empty($viewModel['show_count_badge'])) {
        $mediaCounts = (array) ($viewModel['media_counts'] ?? []);
        $pdfFileCount = max(0, (int) ($mediaCounts['pdf'] ?? 0));
        $musicFileCount = max(0, (int) ($mediaCounts['music'] ?? 0));
        if ($pdfFileCount > 0 || $musicFileCount > 0) {
            echo '<div class="gallery-hero-media-counts">';
            view_render_gallery_media_count_badges($mediaCounts, true);
            echo '</div>';
        }
        $count = max(0, (int) ($viewModel['branch_image_count'] ?? 0));
        $badge = (array) ($viewModel['count_badge'] ?? []);
        if (!empty($badge['admin'])) {
            echo
                '<div class="gallery-hero-count-badge gallery-count-badge-admin" aria-label="'
                    . e(view_gallery_count_admin_label($badge))
                    . '" title="'
                    . e(t(
                        'gallery.hero.branch_image_count_hint',
                        'Includes images from this gallery and its subgalleries.',
                    ))
                    . '">';
            view_gallery_count_admin_values($badge);
            echo '</div>';
        } else {
            // Retain the pre-existing access-filtered visitor badge exactly.
            echo
                '<div class="gallery-hero-count-badge" aria-label="'
                    . e(t(
                        'gallery.hero.branch_image_count_aria',
                        'Image count for this gallery and its subgalleries: {count}',
                        ['count' => $count],
                    ))
                    . '" title="'
                    . e(t(
                        'gallery.hero.branch_image_count_hint',
                        'Includes images from this gallery and its subgalleries.',
                    ))
                    . '">';
            echo
                '<span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span>';
            echo '<span class="gallery-hero-count-value">' . $count . '</span>';
            echo '</div>';
        }
    }

    foreach ($viewModel['cooperative_links'] ?? [] as $link) {
        echo
            '<p><a class="button secondary" href="'
                . e($link['url'])
                . '">'
                . e($link['label'])
                . '</a></p>';
    }
    echo (string) ($viewModel['admin_actions_html'] ?? '');

    if (!empty($viewModel['download_enabled'])) {
        $downloadLabel = t('gallery.download', 'Download gallery');
        echo
            '<form class="public-download-legacy-form" method="post" action="'
                . e((string) ($viewModel['download_url'] ?? ''))
                . '"><input type="hidden" name="id" value="'
                . (int) ($viewModel['gallery_id'] ?? 0)
                . '"><input type="hidden" name="capability" value="'
                . e((string) ($viewModel['download_capability'] ?? ''))
                . '"><button type="submit" class="button hero-icon-button hero-download-button" data-gallery-download data-gallery-download-start-url="'
                . e((string) ($viewModel['download_start_url'] ?? ''))
                . '" aria-label="'
                . e($downloadLabel)
                . '" title="'
                . e($downloadLabel)
                . '"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">'
                . e($downloadLabel)
                . '</span></button></form>';
    }
    if (!empty($viewModel['map_available'])) {
        echo
            '<button type="button" class="button secondary map-button" data-gallery-map-url="'
                . e((string) ($viewModel['map_url'] ?? ''))
                . '" data-gallery-map-title="'
                . e((string) ($viewModel['title'] ?? ''))
                . '">'
                . e(t('gallery.show_map', 'Show gallery map'))
                . '</button>';
    }
    if (!empty($viewModel['picture_game_available'])) {
        $pictureGameLabel = t('gallery.play_picture_game', 'Play picture game');
        echo
            '<a class="button secondary hero-icon-button hero-picture-game-button" href="'
                . e((string) ($viewModel['picture_game_url'] ?? ''))
                . '" aria-label="'
                . e($pictureGameLabel)
                . '" title="'
                . e($pictureGameLabel)
                . '"><span aria-hidden="true">&#127918;</span><span class="visually-hidden">'
                . e($pictureGameLabel)
                . '</span></a>';
    }
    echo '</div></div></div>';
    echo (string) ($viewModel['description_documents_html'] ?? '');
    echo (string) ($viewModel['breadcrumbs_html'] ?? '');

    view_render_public_hero_tags([
        'groups' => (array) ($viewModel['tag_groups'] ?? []),
        'tag_count' => (int) ($viewModel['tag_count'] ?? 0),
        'visible_limit' => (int) ($viewModel['tag_visible_limit'] ?? 20),
        'display_all' => !empty($viewModel['tag_display_all']),
        'scrollbar_enabled' => !empty($viewModel['tag_scrollbar_enabled']),
        'scrollbar_rows' => (int) ($viewModel['tag_scrollbar_rows'] ?? 5),
    ]);
    echo '</section>';
}

/**
 * Render the transparent child-gallery listing from controller-prepared state.
 * @param array{visible?:bool,gallery_id?:int,total_count?:int,revision?:string,current_page?:int,total_pages?:int,sort_toolbar_html?:string,reorder_toolbar_html?:string,pagination_html?:string,grid_class?:string,cards_html?:string} $viewModel Prepared visibility, gallery identity and revision, pagination metadata, toolbar markup, grid class, and rendered child cards.
 * @return void Emit the listing and its controls, or no markup when the section is hidden.
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

    echo
        '<section class="public-subgallery-panel" data-public-subgallery-section data-public-context-gallery-id="'
            . $galleryId
            . '" data-public-subgallery-total-count="'
            . $totalCount
            . '" data-public-subgallery-revision="'
            . e($revision)
            . '" data-public-gallery-page="'
            . $currentPage
            . '" data-public-gallery-total-pages="'
            . $totalPages
            . '" aria-label="'
            . e(t('public.subgalleries', 'Subgalleries'))
            . '">';
    echo (string) ($viewModel['sort_toolbar_html'] ?? '');
    echo (string) ($viewModel['reorder_toolbar_html'] ?? '');
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo
        '<div class="grid'
            . e((string) ($viewModel['grid_class'] ?? ''))
            . '" data-public-reorder-list="gallery" data-public-subgallery-grid data-public-context-gallery-id="'
            . $galleryId
            . '" data-public-subgallery-total-count="'
            . $totalCount
            . '" data-public-subgallery-revision="'
            . e($revision)
            . '" data-public-gallery-page="'
            . $currentPage
            . '" data-public-gallery-total-pages="'
            . $totalPages
            . '">';
    echo (string) ($viewModel['cards_html'] ?? '');
    echo '</div>';
    echo (string) ($viewModel['pagination_html'] ?? '');
    echo '</section>';
}

/**
 * Render one restricted NSFW photo card without exposing thumbnail/media URLs.
 *
 * @param array<string,mixed> $viewModel Controller-prepared restricted-photo state.
 * @return void Writes the restricted photo card and its prepared admin controls.
 */
function view_render_public_gallery_nsfw_image_card(array $viewModel): void
{
    echo
        '<article class="image-card nsfw-card" data-public-photo-order-item data-public-order-id="'
            . (int) ($viewModel['image_id'] ?? 0)
            . '" data-public-image-visibility="'
            . e((string) ($viewModel['visibility'] ?? ''))
            . '" data-public-image-nsfw="'
            . (!empty($viewModel['nsfw']) ? '1' : '0')
            . '" data-public-image-updated-at="'
            . e((string) ($viewModel['updated_at'] ?? ''))
            . '"><div class="image-stage nsfw-stage"><a class="nsfw-placeholder" href="'
            . e((string) ($viewModel['image_url'] ?? ''))
            . '"><strong>'
            . e(t('public.nsfw_photo_title', '18+ photo'))
            . '</strong><span>'
            . e(t(
                'public.nsfw_photo_message',
                'Confirm your age to view this restricted photo.',
            ))
            . '</span></a></div>';
    echo (string) ($viewModel['admin_controls_html'] ?? '');
    echo '</article>';
}

/**
 * Render one normal public photo card.
 *
 * @param array{
 *     class:string,image_id:int,source_gallery_id:int,
 *     quick_tag_enabled:bool,
 *     quick_photo_tags:list<array{id:int,name:string,slug:string}>,
 *     visibility:string,nsfw:bool,updated_at:string,
 *     picture_manager_enabled:bool,display_index:int,preview_url:string,
 *     share_filename:string,display_title:string,lightbox_attributes:string,
 *     viewer_favourite_available:bool,viewer_favourite:bool,
 *     reorder_enabled:bool,image_url:string,thumbnail_html:string,
 *     map_available:bool,favourite_html:string,collection_html:string,
 *     vote_html:string,has_public_meta:bool,description:string,tags_html:string,
 *     admin_controls_html:string,quick_tag_url?:string,quick_tag_csrf?:string
 * } $viewModel Controller-prepared public photo card fields, including the
 *     physical image identity, direct tags, access state and inline controls.
 * @return void Render semantic photo-card markup.
 */
function view_render_public_gallery_image_card(array $viewModel): void
{
    echo
        '<article class="'
            . e((string) ($viewModel['class'] ?? 'image-card'))
            . '" data-public-photo-order-item data-public-order-id="'
            . (int) ($viewModel['image_id'] ?? 0)
            . '" data-public-image-visibility="'
            . e((string) ($viewModel['visibility'] ?? ''))
            . '" data-public-image-nsfw="'
            . (!empty($viewModel['nsfw']) ? '1' : '0')
            . '" data-public-image-updated-at="'
            . e((string) ($viewModel['updated_at'] ?? ''))
            . '"';
    if (!empty($viewModel['picture_manager_enabled'])) {
        echo
            ' data-picture-manager-image data-picture-manager-image-id="'
                . (int) ($viewModel['image_id'] ?? 0)
                . '" data-picture-manager-index="'
                . (int) ($viewModel['display_index'] ?? 0)
                . '" data-picture-manager-share-url="'
                . e((string) ($viewModel['preview_url'] ?? ''))
                . '" data-picture-manager-share-filename="'
                . e((string) ($viewModel['share_filename'] ?? ''))
                . '" data-picture-manager-share-title="'
                . e((string) ($viewModel['display_title'] ?? ''))
                . '"';
    }
    echo (string) ($viewModel['lightbox_attributes'] ?? '');
    if (!empty($viewModel['viewer_favourite_available'])) {
        echo
            ' data-viewer-favourite="'
                . (!empty($viewModel['viewer_favourite']) ? '1' : '0')
                . '"';
    }
    echo '><div class="public-photo-action-row">';

    if (!empty($viewModel['picture_manager_enabled'])) {
        $selectLabel = t('picture_manager.select_photo', 'Select photo');
        echo
            '<button type="button" class="picture-manager-select-button" data-picture-manager-select aria-pressed="false" aria-label="'
                . e($selectLabel)
                . '" title="'
                . e($selectLabel)
                . '"><span aria-hidden="true">✓</span><span class="visually-hidden">'
                . e($selectLabel)
                . '</span></button>';
    }
    if (!empty($viewModel['reorder_enabled'])) {
        echo
            '<button type="button" class="public-reorder-handle public-photo-reorder-handle" data-public-reorder-handle aria-label="'
                . e(t(
                    'public.reorder.drag_photo_label',
                    'Drag photo to reorder visible photos',
                ))
                . '" title="'
                . e(t(
                    'public.reorder.drag_photo_title',
                    'Drag to reorder this visible photo',
                ))
                . '">' . view_admin_menu_icon('move') . '<span class="visually-hidden">'
                . e(t('public.reorder.move_photo', 'Move photo'))
                . '</span></button>';
    }
    echo (string) ($viewModel['admin_controls_html'] ?? '');
    view_render_quick_photo_tag_button($viewModel);
    echo '</div>';

    echo '<div class="image-stage">';
    echo
        '<a class="image-preview-link" href="'
            . e((string) ($viewModel['image_url'] ?? ''))
            . '">'
            . (string) ($viewModel['thumbnail_html'] ?? '')
            . '</a>';
    if (!empty($viewModel['map_available'])) {
        $mapLabel = t('public.show_photo_location', 'Show photo location');
        echo
            '<button type="button" class="photo-map-pin" data-photo-map aria-label="'
                . e($mapLabel)
                . '" title="'
                . e($mapLabel)
                . '">&#128205;</button>';
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
        if (trim((string) ($viewModel['tags_html'] ?? '')) !== '') {
            echo '<div class="public-photo-tag-footer">'
                . (string) $viewModel['tags_html']
                . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '</article>';
}

/**
 * Render the compact direct-photo-tag action when this photo is editable.
 *
 * @param array{image_id?:int,source_gallery_id?:int,quick_tag_enabled?:bool,
 *     quick_photo_tags?:list<array{id:int,name:string,slug?:string}>,
 *     quick_tag_url?:string,quick_tag_csrf?:string} $viewModel Prepared photo
 *     identity, direct image-tag state, and authorized inline mutation details.
 * @return void Emits nothing when this principal cannot edit the photo.
 */
function view_render_quick_photo_tag_button(array $viewModel): void
{
    if (empty($viewModel['quick_tag_enabled'])) {
        return;
    }
    $imageId = max(0, (int) ($viewModel['image_id'] ?? 0));
    if ($imageId <= 0) {
        return;
    }
    $tags = array_values(array_filter(
        (array) ($viewModel['quick_photo_tags'] ?? []),
        static fn(mixed $tag): bool => is_array($tag)
            && (int) ($tag['id'] ?? 0) > 0
            && trim((string) ($tag['name'] ?? '')) !== '',
    ));
    $labelKey = $tags === []
        ? 'quick_photo_tags.add'
        : 'quick_photo_tags.edit';
    $labelFallback = $tags === [] ? 'Add photo tags' : 'Edit photo tags';
    $label = t($labelKey, $labelFallback);
    $tagsJson = json_encode(
        $tags,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    echo
        '<button type="button" class="public-admin-card-action-button'
            . ' quick-photo-tag-trigger quick-photo-tag-trigger-card'
            . ($tags === [] ? ' is-empty' : ' is-active')
            . '" data-quick-photo-tag-trigger data-quick-photo-tag-card'
            . ' data-image-id="'
            . $imageId
            . '" data-source-gallery-id="'
            . max(0, (int) ($viewModel['source_gallery_id'] ?? 0))
            . '" data-quick-tag-enabled="1" data-quick-tag-url="'
            . e((string) ($viewModel['quick_tag_url'] ?? ''))
            . '" data-quick-tag-csrf="'
            . e((string) ($viewModel['quick_tag_csrf'] ?? ''))
            . '" data-quick-photo-tags="'
            . e($tagsJson)
            . '" aria-pressed="'
            . ($tags === [] ? 'false' : 'true')
            . '" aria-haspopup="dialog" aria-expanded="false" aria-label="'
            . e($label)
            . '" title="'
            . e($label)
            . '">'
            . view_admin_menu_icon('tags')
            . '<span class="visually-hidden">'
            . e($label)
            . '</span></button>';
}

/**
 * Render the shared quick-tag popover and its image-only suggestion catalog.
 *
 * @param array{quick_tag_enabled?:bool,quick_tag_url?:string,
 *     quick_tag_csrf?:string,
 *     quick_tag_catalog?:list<array{id:int,name:string,slug:string}>} $viewModel
 *     Page-level authorization and image-tag catalog prepared by the controller.
 * @return void Emits the inert popover only for an authorized inline editor.
 */
function view_render_quick_photo_tag_popover(array $viewModel): void
{
    if (empty($viewModel['quick_tag_enabled'])) {
        return;
    }
    $catalog = array_values(array_filter(
        (array) ($viewModel['quick_tag_catalog'] ?? []),
        static fn(mixed $tag): bool => is_array($tag)
            && (int) ($tag['id'] ?? 0) > 0
            && trim((string) ($tag['name'] ?? '')) !== '',
    ));
    $catalogJson = json_encode(
        $catalog,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
    $inputLabel = t('quick_photo_tags.input', 'Search or add a photo tag');
    $title = t('quick_photo_tags.title', 'Photo tags');
    $addLabel = t('quick_photo_tags.add', 'Add photo tags');
    $closeLabel = t('quick_photo_tags.close', 'Close photo tags');

    echo
        '<section class="quick-photo-tag-popover" data-quick-photo-tag-popover'
            . ' data-quick-tag-url="'
            . e((string) ($viewModel['quick_tag_url'] ?? ''))
            . '" data-quick-tag-csrf="'
            . e((string) ($viewModel['quick_tag_csrf'] ?? ''))
            . '" data-quick-photo-tag-catalog="'
            . e($catalogJson)
            . '" role="dialog" aria-label="'
            . e($title)
            . '"'
            . ' aria-describedby="quick-photo-tag-status" hidden>'
            . '<div class="quick-photo-tag-entry"><label class="visually-hidden"'
            . ' for="quick-photo-tag-input">'
            . e($inputLabel)
            . '</label><input id="quick-photo-tag-input" type="text"'
            . ' data-quick-photo-tag-input autocomplete="off"'
            . ' aria-autocomplete="list" aria-controls="quick-photo-tag-options"'
            . ' aria-expanded="false" placeholder="'
            . e($inputLabel)
            . '"><button type="button" data-quick-photo-tag-add'
            . ' aria-label="'
            . e($addLabel)
            . '" title="'
            . e($addLabel)
            . '">+</button><button type="button" data-quick-photo-tag-close'
            . ' aria-label="'
            . e($closeLabel)
            . '" title="'
            . e($closeLabel)
            . '">×</button></div>'
            . '<div class="quick-photo-tag-options" id="quick-photo-tag-options"'
            . ' data-quick-photo-tag-options role="listbox" hidden></div>'
            . '<p class="quick-photo-tag-status" data-quick-photo-tag-status'
            . ' id="quick-photo-tag-status" role="status" aria-live="polite"></p>'
            . '</section>';
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
    echo
        '<section class="grid gallery-image-grid'
            . e((string) ($viewModel['grid_class'] ?? ''))
            . '" data-public-reorder-list="photo" data-gallery-image-list data-public-context-gallery-id="'
            . (int) ($viewModel['gallery_id'] ?? 0)
            . '" data-public-image-total-count="'
            . (int) ($viewModel['total_count'] ?? 0)
            . '" data-public-image-revision="'
            . e((string) ($viewModel['revision'] ?? ''))
            . '" data-public-image-page="'
            . max(1, (int) ($viewModel['current_page'] ?? 1))
            . '" data-public-image-total-pages="'
            . max(1, (int) ($viewModel['total_pages'] ?? 1))
            . '"';
    if (!empty($viewModel['lightbox_enabled'])) {
        echo
            ' data-lightbox-config data-lightbox-endpoint="'
                . e((string) ($viewModel['lightbox_endpoint'] ?? ''))
                . '" data-lightbox-total="'
                . max(0, (int) ($viewModel['lightbox_total'] ?? 0))
                . '" data-lightbox-window-size="60" data-lightbox-browsing-mode="'
                . e((string) ($viewModel['lightbox_browsing_mode'] ?? ''))
                . '" data-lightbox-maps-enabled="'
                . (!empty($viewModel['lightbox_maps_enabled']) ? '1' : '0')
                . '" data-lightbox-gallery-map-url="'
                . e((string) ($viewModel['gallery_map_url'] ?? ''))
                . '" data-lightbox-gallery-map-title="'
                . e((string) ($viewModel['gallery_map_title'] ?? ''))
                . '"';
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
 * @return void Render the gallery page and its optional cooperation links.
 */
function view_render_public_gallery_detail(array $viewModel): void
{
    $gallery = (array) ($viewModel['gallery'] ?? []);
    render_header(
        (string) ($viewModel['page_title'] ?? ''),
        $gallery,
        !empty($viewModel['public_only']),
    );
    $widgets = (array) ($viewModel['public_widgets'] ?? []);

    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    view_render_public_widget_region($widgets, 'content_top');
    $hasRails = !empty($widgets['left_rail']) || !empty($widgets['right_rail']);
    if ($hasRails) {
        $railMode = !empty($widgets['left_rail'])
        && !empty($widgets['right_rail'])
            ? 'both'
            : (!empty($widgets['left_rail']) ? 'left' : 'right');
        echo
            '<div class="public-widget-rails-container"><div class="public-widget-content-layout public-widget-content-layout--'
                . $railMode
                . '"><div class="public-widget-primary">';
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
    view_render_public_subgallery_section(
        (array) ($viewModel['subgallery_section'] ?? []),
    );
    view_render_public_gallery_image_section(
        (array) ($viewModel['image_section'] ?? []),
    );
    view_render_quick_photo_tag_popover($viewModel);
    echo (string) ($viewModel['bottom_smart_group_html'] ?? '');
    if (!empty($viewModel['has_list_content'])) {
        echo '</div>';
        echo (string) ($viewModel['back_to_top_html'] ?? '');
        echo '</div>';
    }

    if ($hasRails) {
        echo '</div>';
        view_render_public_widget_region($widgets, 'left_rail');
        view_render_public_widget_region($widgets, 'right_rail');
        echo '</div></div>';
    }
    view_render_public_widget_region($widgets, 'content_bottom');
    view_render_public_widget_region($widgets, 'floating');
    echo (string) ($viewModel['lightbox_html'] ?? '');
    echo (string) ($viewModel['render_profile_html'] ?? '');
    render_footer(view_public_widget_region_html($widgets, 'footer'));
}
