<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_gallery_lightbox.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public gallery lightbox attributes, hidden sources, and shell markup.
 *
 * Responsibilities:
 *   - Render lightbox presentation from controller-prepared data
 *   - Escape all attribute payloads at the presentation boundary
 *   - Keep gallery policy, image lookup, GPS resolution, and vote persistence outside the view
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
 *   - Translation and escaping are presentation-only helpers used by this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Build the shared data attributes consumed by the public lightbox.
 *
 * @param array<string, mixed> $viewModel Prepared lightbox-image attribute model.
 * @return string Rendered attribute string.
 */
function view_lightbox_image_data_attributes(array $viewModel): string
{
    $mapPoint = $viewModel['map_point'] ?? null;
    $mapPointAttribute = is_array($mapPoint) ? ' data-map-point="' . e(json_encode($mapPoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '';
    $votingAttribute = !empty($viewModel['voting_allowed']) ? ' data-voting-allowed="1"' : '';
    $lightboxIndex = $viewModel['lightbox_index'] ?? null;
    $indexAttribute = is_int($lightboxIndex) ? ' data-lightbox-index="' . max(0, $lightboxIndex) . '"' : '';
    $qualityCandidates = (array) ($viewModel['quality_candidates'] ?? []);
    $qualityAttribute = $qualityCandidates !== []
        ? ' data-lightbox-quality-sources="' . e(json_encode($qualityCandidates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
        : '';
    $sourceGalleryTitle = trim((string) ($viewModel['source_gallery_title'] ?? ''));
    $sourceGalleryBreadcrumb = trim((string) ($viewModel['source_gallery_breadcrumb'] ?? $sourceGalleryTitle));
    $sourceGalleryUrl = trim((string) ($viewModel['source_gallery_url'] ?? ''));
    $sourceGalleryAttribute = $sourceGalleryTitle !== '' && $sourceGalleryUrl !== ''
        ? ' data-source-gallery-id="' . (int) ($viewModel['source_gallery_id'] ?? 0) . '"'
            . ' data-source-gallery-title="' . e($sourceGalleryTitle) . '"'
            . ' data-source-gallery-breadcrumb="' . e($sourceGalleryBreadcrumb) . '"'
            . ' data-source-gallery-url="' . e($sourceGalleryUrl) . '"'
        : '';

    return (string) ($viewModel['source_attribute'] ?? '')
        . ' data-image-id="' . (int) ($viewModel['image_id'] ?? 0) . '"'
        . $indexAttribute
        . ' data-gallery-id="' . (int) ($viewModel['gallery_id'] ?? 0) . '"'
        . ' data-full-src="' . e((string) ($viewModel['media_url'] ?? '')) . '"'
        . ' data-preview-src="' . e((string) ($viewModel['preview_url'] ?? '')) . '"'
        . $qualityAttribute
        . ' data-page-url="' . e((string) ($viewModel['page_url'] ?? '')) . '"'
        . ' data-gallery-url="' . e((string) ($viewModel['gallery_url'] ?? '')) . '"'
        . ' data-title="' . e((string) ($viewModel['title'] ?? '')) . '"'
        . ' data-description="' . e((string) ($viewModel['description'] ?? '')) . '"'
        . ' data-score="' . (int) ($viewModel['score'] ?? 0) . '"'
        . ' data-user-vote="' . (int) ($viewModel['vote'] ?? 0) . '"'
        . ' data-image-width="' . (int) ($viewModel['image_width'] ?? 0) . '"'
        . ' data-image-height="' . (int) ($viewModel['image_height'] ?? 0) . '"'
        . $votingAttribute
        . $mapPointAttribute
        . $sourceGalleryAttribute;
}

/**
 * Render an inert vote template for one hidden lightbox source.
 *
 * @param string $voteFormHtml Prepared vote-form HTML.
 */
function view_render_lightbox_vote_template(string $voteFormHtml): void
{
    if ($voteFormHtml === '') {
        return;
    }
    echo '<template data-lightbox-vote-template>' . $voteFormHtml . '</template>';
}

/**
 * Render hidden ordered lightbox data for paginated galleries.
 *
 * @param array<int, array<string, mixed>> $nodes Prepared hidden source nodes.
 */
function view_render_lightbox_source_nodes(array $nodes): void
{
    echo '<div class="lightbox-source-list" hidden aria-hidden="true">';
    foreach ($nodes as $node) {
        echo '<div ' . (string) ($node['attributes'] ?? '') . '>';
        view_render_lightbox_vote_template((string) ($node['vote_form_html'] ?? ''));
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Render the public lightbox shell.
 *
 * @param array<string, mixed> $viewModel Prepared lightbox presentation model.
 */
function view_render_lightbox(array $viewModel): void
{
    $votingAllowed = !empty($viewModel['voting_allowed']);
    $mapsAllowed = !empty($viewModel['maps_allowed']);
    $slideshowAllowed = !empty($viewModel['slideshow_allowed']);
    $lightboxBrowsingMode = (string) ($viewModel['browsing_mode'] ?? 'single');
    $galleryMapUrl = (string) ($viewModel['gallery_map_url'] ?? '');
    $galleryMapTitle = (string) ($viewModel['gallery_map_title'] ?? '');
    $viewerFavouriteHtml = (string) ($viewModel['viewer_favourite_html'] ?? '');

    $votePanelHtml = $votingAllowed ? '<div class="lightbox-vote-panel" data-lightbox-vote-panel hidden></div>' : '';
    $galleryMapAttributes = $galleryMapUrl !== ''
        ? ' data-lightbox-gallery-map-url="' . e($galleryMapUrl) . '" data-lightbox-gallery-map-title="' . e($galleryMapTitle) . '"'
        : '';
    $zoomControlsHtml = '<span class="lightbox-zoom-controls" data-lightbox-zoom-controls role="group" aria-label="' . e(t('lightbox.zoom_controls', 'Image zoom')) . '"><button type="button" data-lightbox-action="zoom-out" aria-label="' . e(t('lightbox.zoom_out', 'Zoom out')) . '" title="' . e(t('lightbox.zoom_out', 'Zoom out')) . '">−</button><button type="button" data-lightbox-action="zoom-reset" aria-label="' . e(t('lightbox.zoom_reset', 'Reset zoom')) . '" title="' . e(t('lightbox.zoom_reset', 'Reset zoom')) . '"><span data-lightbox-zoom-status aria-hidden="true">100%</span></button><button type="button" data-lightbox-action="zoom-in" aria-label="' . e(t('lightbox.zoom_in', 'Zoom in')) . '" title="' . e(t('lightbox.zoom_in', 'Zoom in')) . '">+</button></span>';
    $zoomHudControlsHtml = '<span class="lightbox-zoom-controls lightbox-zoom-controls-hud lightbox-hud" data-lightbox-zoom-controls role="group" aria-label="' . e(t('lightbox.zoom_controls', 'Image zoom')) . '"><button type="button" data-lightbox-action="zoom-out" aria-label="' . e(t('lightbox.zoom_out', 'Zoom out')) . '" title="' . e(t('lightbox.zoom_out', 'Zoom out')) . '">−</button><button type="button" data-lightbox-action="zoom-reset" aria-label="' . e(t('lightbox.zoom_reset', 'Reset zoom')) . '" title="' . e(t('lightbox.zoom_reset', 'Reset zoom')) . '"><span data-lightbox-zoom-status aria-hidden="true">100%</span></button><button type="button" data-lightbox-action="zoom-in" aria-label="' . e(t('lightbox.zoom_in', 'Zoom in')) . '" title="' . e(t('lightbox.zoom_in', 'Zoom in')) . '">+</button></span>';
    $slideshowToolbarHtml = $slideshowAllowed ? '<button type="button" class="lightbox-slideshow-link" data-lightbox-action="slideshow" aria-label="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" title="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" aria-pressed="false">S ' . e(t('lightbox.slideshow', 'slideshow')) . '</button>' : '';
    $slideshowHudHtml = $slideshowAllowed ? '<button type="button" class="lightbox-slideshow-button lightbox-hud" data-lightbox-action="slideshow" aria-label="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" title="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" aria-pressed="false">S</button>' : '';
    $helpShortcuts = $slideshowAllowed ? t('lightbox.help_shortcuts', '←/→ photos, Shift+←/→ ±10 photos, +/− zoom, 0 reset, F fullscreen, M map, S slideshow, X close') : t('lightbox.help_shortcuts_no_slideshow', '←/→ photos, Shift+←/→ ±10 photos, +/− zoom, 0 reset, F fullscreen, M map, X close');

    echo '<div class="lightbox" data-lightbox data-lightbox-browsing-mode="' . e($lightboxBrowsingMode) . '" data-lightbox-maps-enabled="' . ($mapsAllowed ? '1' : '0') . '" data-lightbox-slideshow-enabled="' . ($slideshowAllowed ? '1' : '0') . '"' . $galleryMapAttributes . ' data-lightbox-slideshow-visible-ms="2000" data-lightbox-slideshow-transition-ms="1000" data-lightbox-zoom-status-template="' . e(t('lightbox.zoom_status', 'Zoom {percent}')) . '" hidden>';
    echo '<button class="lightbox-close lightbox-hud" type="button" data-lightbox-action="close">' . e(t('lightbox.close', 'Close')) . '</button>';
    echo '<span class="lightbox-mobile-counter lightbox-hud" data-lightbox-counter aria-hidden="true"></span>';
    echo '<button type="button" class="lightbox-nav lightbox-previous lightbox-hud" data-lightbox-action="previous" aria-label="' . e(t('lightbox.previous_image', 'Previous image')) . '">&lt;</button>';
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage data-lightbox-zoom-viewport aria-label="' . e(t('lightbox.toggle_fullscreen_image', 'Toggle fullscreen image')) . '"><span class="lightbox-initial-loader" data-lightbox-initial-loader data-lightbox-loading-count-template="' . e(t('lightbox.initial_loader_count', 'Preparing photo {current} of {total}')) . '" role="status" aria-live="polite" hidden><span class="lightbox-initial-loader-label" data-lightbox-initial-loader-label>' . e(t('lightbox.initial_loader', 'Preparing gallery...')) . '</span><span class="lightbox-initial-loader-track" aria-hidden="true"><span class="lightbox-initial-loader-fill" data-lightbox-initial-loader-fill></span></span><span class="lightbox-initial-loader-count" data-lightbox-initial-loader-count></span></span><span class="lightbox-zoom-surface" data-lightbox-zoom-surface><img decoding="async" data-lightbox-img alt=""></span></button><div class="lightbox-strip" data-lightbox-strip aria-label="' . e(t('lightbox.picture_strip_label', 'Nearby photos')) . '" hidden><div class="lightbox-strip-track" data-lightbox-strip-track></div></div><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span>' . $zoomControlsHtml . '<button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F ' . e(t('lightbox.fullscreen', 'fullscreen')) . '</button>' . $slideshowToolbarHtml . '<button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; ' . e(t('lightbox.map', 'Map')) . '</button><button type="button" class="lightbox-help-button" data-lightbox-action="help" aria-expanded="false" aria-label="' . e(t('lightbox.help_label', 'Show keyboard shortcuts')) . '" title="' . e(t('lightbox.help_label', 'Show keyboard shortcuts')) . '">?</button>' . $viewerFavouriteHtml . $votePanelHtml . '</div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p><p class="lightbox-source-gallery" data-lightbox-source-gallery data-source-gallery-label="' . e(t('smart_gallery.source_gallery', 'Source gallery')) . '" hidden></p><div class="lightbox-help-panel" data-lightbox-help-panel hidden><strong>' . e(t('lightbox.help_title', 'Controls')) . '</strong><span>' . e($helpShortcuts) . '</span></div></figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="' . e(t('lightbox.close_map_split', 'Close map split')) . '">' . e(t('lightbox.close_map', 'Close map')) . '</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div><div class="lightbox-map-split-nav" aria-label="' . e(t('lightbox.map_photo_navigation', 'Photo navigation while map is open')) . '"><button type="button" class="lightbox-map-split-nav-button" data-lightbox-action="previous">' . e(t('lightbox.previous_photo_short', '← Previous photo')) . '</button><button type="button" class="lightbox-map-split-nav-button" data-lightbox-action="next">' . e(t('lightbox.next_photo_short', 'Next photo →')) . '</button></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="' . e(t('lightbox.next_image', 'Next image')) . '">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F</button>';
    echo $slideshowHudHtml;
    echo '<button type="button" class="lightbox-map-hud-button lightbox-hud" data-lightbox-map aria-label="' . e(t('lightbox.map', 'Map')) . '" title="' . e(t('lightbox.map', 'Map')) . '" hidden>M</button>';
    echo $zoomHudControlsHtml;
    echo '<span class="visually-hidden" data-lightbox-zoom-announcement role="status" aria-live="polite">' . e(t('lightbox.zoom_status', 'Zoom {percent}', ['percent' => '100%'])) . '</span>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">&#9974;</button>';
    echo '</div>';
}
