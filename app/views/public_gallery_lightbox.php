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
 * @param array{voting_allowed?:bool, maps_allowed?:bool,
 *     slideshow_allowed?:bool, browsing_mode?:string, gallery_map_url?:string,
 *     gallery_map_title?:string, viewer_favourite_html?:string,
 *     presentation?:array{autoplay?:bool,music?:bool,interval?:string,transition?:string}} $viewModel
 *     Prepared visibility flags, browsing mode, map labels/URL and escaped
 *     favourite markup.
 * @return void Emits the existing viewer shell and permitted runtime controls.
 */
function view_render_lightbox(array $viewModel): void
{
    $votingAllowed = !empty($viewModel['voting_allowed']);
    $mapsAllowed = !empty($viewModel['maps_allowed']);
    $slideshowAllowed = !empty($viewModel['slideshow_allowed']);
    $presentation = (array) ($viewModel['presentation'] ?? []);
    $presentationAttributes = ' data-presentation-autoplay="'
        . (($presentation['autoplay'] ?? true) ? '1' : '0') . '"'
        . ' data-presentation-music="'
        . (($presentation['music'] ?? true) ? '1' : '0') . '"'
        . ' data-presentation-interval="'
        . e((string) ($presentation['interval'] ?? 'medium')) . '"'
        . ' data-presentation-transition="'
        . e((string) ($presentation['transition'] ?? 'medium')) . '"';
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
    $slideshowSpeedHtml = '';
    if ($slideshowAllowed) {
        $slideshowSpeedHtml = '<div class="lightbox-slideshow-speeds lightbox-hud" data-lightbox-slideshow-speeds hidden>'
            . '<span class="visually-hidden" id="lightbox-slideshow-speed-help">' . e(t('lightbox.slideshow_speed_help', 'Changes take effect immediately. Use arrow keys to choose Fast, Medium or Slow.')) . '</span>';
        foreach ([
            ['value' => 'interval', 'label' => t('lightbox.slideshow_photo_speed', 'Photo change speed')],
            ['value' => 'transition', 'label' => t('lightbox.slideshow_transition_speed', 'Transition speed')],
        ] as $speedControl) {
            $controlId = 'lightbox-slideshow-speed-' . $speedControl['value'];
            $slideshowSpeedHtml .= '<label class="lightbox-slideshow-speed">'
                . '<span id="' . e($controlId) . '-label" title="' . e($speedControl['label']) . '">' . e($speedControl['label']) . '</span>'
                . '<input type="range" min="0" max="2" step="1" value="1" data-lightbox-slideshow-speed="' . e($speedControl['value']) . '"'
                . ' aria-labelledby="' . e($controlId) . '-label" aria-describedby="lightbox-slideshow-speed-help"'
                . ' aria-valuetext="' . e(t('lightbox.slideshow_speed_medium', 'Medium')) . '">'
                . '<span class="lightbox-slideshow-speed-options" aria-hidden="true">'
                . '<span data-lightbox-speed-option>' . e(t('lightbox.slideshow_speed_slow', 'Slow')) . '</span>'
                . '<span data-lightbox-speed-option class="is-selected">' . e(t('lightbox.slideshow_speed_medium', 'Medium')) . '</span>'
                . '<span data-lightbox-speed-option>' . e(t('lightbox.slideshow_speed_fast', 'Fast')) . '</span>'
                . '</span></label>';
        }
        $slideshowSpeedHtml .= '</div>';
    }
    $helpShortcuts = $slideshowAllowed ? t('lightbox.help_shortcuts', '←/→ photos, Shift+←/→ ±10 photos, +/− zoom, 0 reset, F fullscreen, M map, S slideshow, X close') : t('lightbox.help_shortcuts_no_slideshow', '←/→ photos, Shift+←/→ ±10 photos, +/− zoom, 0 reset, F fullscreen, M map, X close');

    echo '<div class="lightbox" data-lightbox data-lightbox-browsing-mode="' . e($lightboxBrowsingMode) . '" data-lightbox-maps-enabled="' . ($mapsAllowed ? '1' : '0') . '" data-lightbox-slideshow-enabled="' . ($slideshowAllowed ? '1' : '0') . '"' . $galleryMapAttributes . $presentationAttributes . ' data-lightbox-slideshow-visible-ms="2000" data-lightbox-slideshow-transition-ms="1000" data-lightbox-zoom-status-template="' . e(t('lightbox.zoom_status', 'Zoom {percent}')) . '" hidden>';
    // Activation needs an explicit action even when the link hides its HUD.
    echo '<section class="presentation-consent" data-presentation-consent'
        . ' role="group" aria-labelledby="presentation-consent-title" hidden>'
        . '<h2 id="presentation-consent-title">'
        . e(t('presentation.confirm_start', 'Start presentation')) . '</h2>'
        . '<p>' . e(t('presentation.activation_help',
            'Your browser needs a click to start with the link settings.')) . '</p>'
        . '<div class="presentation-consent-actions"><button type="button"'
        . ' data-presentation-confirm>' . view_music_control_icon('play')
        . '<span>' . e(t('presentation.confirm_start', 'Start presentation'))
        . '</span></button><button type="button" data-presentation-cancel'
        . ' aria-label="' . e(t('lightbox.close', 'Close')) . '"'
        . ' title="' . e(t('lightbox.close', 'Close')) . '">×</button>'
        . '</div></section>';
    echo '<button class="lightbox-close lightbox-hud" type="button" data-lightbox-action="close">' . e(t('lightbox.close', 'Close')) . '</button>';
    echo '<span class="lightbox-mobile-counter lightbox-hud" data-lightbox-counter aria-hidden="true"></span>';
    echo '<button type="button" class="lightbox-nav lightbox-previous lightbox-hud" data-lightbox-action="previous" aria-label="' . e(t('lightbox.previous_image', 'Previous image')) . '">&lt;</button>';
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage data-lightbox-zoom-viewport aria-label="' . e(t('lightbox.toggle_fullscreen_image', 'Toggle fullscreen image')) . '"><span class="lightbox-initial-loader" data-lightbox-initial-loader data-lightbox-loading-count-template="' . e(t('lightbox.initial_loader_count', 'Preparing photo {current} of {total}')) . '" role="status" aria-live="polite" hidden><span class="lightbox-initial-loader-label" data-lightbox-initial-loader-label>' . e(t('lightbox.initial_loader', 'Preparing gallery...')) . '</span><span class="lightbox-initial-loader-track" aria-hidden="true"><span class="lightbox-initial-loader-fill" data-lightbox-initial-loader-fill></span></span><span class="lightbox-initial-loader-count" data-lightbox-initial-loader-count></span></span><span class="lightbox-zoom-surface" data-lightbox-zoom-surface><img decoding="async" data-lightbox-img alt=""></span></button><div class="lightbox-strip" data-lightbox-strip aria-label="' . e(t('lightbox.picture_strip_label', 'Nearby photos')) . '" hidden><div class="lightbox-strip-track" data-lightbox-strip-track></div></div><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span>' . $zoomControlsHtml . '<button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F ' . e(t('lightbox.fullscreen', 'fullscreen')) . '</button>' . $slideshowToolbarHtml . '<button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; ' . e(t('lightbox.map', 'Map')) . '</button><button type="button" class="lightbox-help-button" data-lightbox-action="help" aria-expanded="false" aria-label="' . e(t('lightbox.help_label', 'Show keyboard shortcuts')) . '" title="' . e(t('lightbox.help_label', 'Show keyboard shortcuts')) . '">?</button>' . $viewerFavouriteHtml . $votePanelHtml . '</div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p><p class="lightbox-source-gallery" data-lightbox-source-gallery data-source-gallery-label="' . e(t('smart_gallery.source_gallery', 'Source gallery')) . '" hidden></p><div class="lightbox-help-panel" data-lightbox-help-panel hidden><strong>' . e(t('lightbox.help_title', 'Controls')) . '</strong><span>' . e($helpShortcuts) . '</span></div></figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="' . e(t('lightbox.close_map_split', 'Close map split')) . '">' . e(t('lightbox.close_map', 'Close map')) . '</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div><div class="lightbox-map-split-nav" aria-label="' . e(t('lightbox.map_photo_navigation', 'Photo navigation while map is open')) . '"><button type="button" class="lightbox-map-split-nav-button" data-lightbox-action="previous">' . e(t('lightbox.previous_photo_short', '← Previous photo')) . '</button><button type="button" class="lightbox-map-split-nav-button" data-lightbox-action="next">' . e(t('lightbox.next_photo_short', 'Next photo →')) . '</button></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="' . e(t('lightbox.next_image', 'Next image')) . '">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F</button>';
    echo $slideshowHudHtml;
    echo $slideshowSpeedHtml;
    echo view_music_player_controls(true);
    echo '<button type="button" class="lightbox-map-hud-button lightbox-hud" data-lightbox-map aria-label="' . e(t('lightbox.map', 'Map')) . '" title="' . e(t('lightbox.map', 'Map')) . '" hidden>M</button>';
    echo $zoomHudControlsHtml;
    echo '<span class="visually-hidden" data-lightbox-zoom-announcement role="status" aria-live="polite">' . e(t('lightbox.zoom_status', 'Zoom {percent}', ['percent' => '100%'])) . '</span>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">&#9974;</button>';
    echo '</div>';
}

/**
 * Return one conventional compact player symbol from the fixed presentation set.
 * @param string $action Fixed playback, download or editor action symbol.
 * @return string Decorative SVG markup; unknown actions return an empty string.
 */
function view_music_control_icon(string $action): string
{
    $shape = match ($action) {
        'play' => '<path d="m8 5 11 7-11 7Z" fill="currentColor" stroke="none"/>',
        'stop' => '<rect x="6" y="6" width="12" height="12" rx="1"'
            . ' fill="currentColor" stroke="none"/>',
        'previous' => '<path d="M5 5v14m13-14L7 12l11 7Z"/>',
        'next' => '<path d="M19 5v14M6 5l11 7-11 7Z"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5"/>',
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M5 16v5h14v-5"/>',
        'save' => '<path d="M4 3h14l3 3v15H3V3ZM7 3v6h10V3M7 21v-8h10v8"/>',
        'share' => '<path d="M12 15V2m-5 5 5-5 5 5M7 11H4v10h16V11h-3"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/>'
            . '<path d="M16 8V3H3v13h5"/>',
        'document' => '<path d="M5 3h9l5 5v13H5ZM14 3v6h5M8 13h8m-8 4h6"/>',
        'music' => '<path d="M9 18V5l11-2v13M9 9l11-2"/>'
            . '<ellipse cx="6" cy="18" rx="3" ry="2"/>'
            . '<ellipse cx="17" cy="16" rx="3" ry="2"/>',
        'locked' => '<rect x="5" y="10" width="14" height="11" rx="2"/>'
            . '<path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'up' => '<path d="M12 20V4m-6 6 6-6 6 6"/>',
        'down' => '<path d="M12 4v16m-6-6 6 6 6-6"/>',
        'delete' => '<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7"/>',
        default => '',
    };
    return $shape === '' ? ''
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.7" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $shape . '</svg>';
}

/**
 * Render one configurable link disclosure without a new persistence surface.
 * @param string $url Controller-prepared localized presentation snapshot URL.
 * @param string $identity Safe unique public/editor identity for fragment reuse.
 * @return string Native disclosure, playback controls and selectable copy field.
 */
function view_presentation_share(string $url, string $identity): string
{
    $label = t('presentation.share');
    $html = '<details class="gallery-media-widget presentation-share-widget"'
        . ' data-gallery-media-widget data-presentation-share-widget>'
        . '<summary class="music-icon" aria-label="' . e($label)
        . '" title="' . e($label) . '" aria-expanded="false">'
        . view_music_control_icon('share') . '</summary>'
        . '<section class="gallery-media-panel presentation-share-panel" id="'
        . e('presentation-share-' . $identity) . '" data-gallery-media-panel'
        . ' data-presentation-share-panel data-presentation-url="' . e($url)
        . '"><header><h3>' . e($label) . '</h3><button type="button"'
        . ' class="gallery-media-close" data-media-close aria-label="'
        . e(t('lightbox.close')) . '">×</button></header>'
        . '<div class="gallery-media-body"><div class="presentation-share-options">';
    foreach (['autoplay' => 'presentation.autoplay',
        'music' => 'presentation.music',
        'fullscreen' => 'lightbox.fullscreen',
        'exclusive' => 'presentation.exclusive'] as $key => $translation) {
        $compact = match ($key) {
            'autoplay' => 'presentation.autoplay_short',
            'music' => 'music.title',
            default => $translation,
        };
        $html .= '<label class="gallery-presentation-setting" title="'
            . e(t($translation)) . '"><span>' . e(t($compact))
            . '</span><input type="checkbox" role="switch"'
            . ' aria-label="' . e(t($translation)) . '"'
            . ' data-share-option="' . $key . '"'
            . ($key === 'exclusive' ? '' : ' checked') . '></label>';
    }
    $html .= '</div>';
    foreach (['interval' => 'lightbox.slideshow_photo_speed',
        'transition' => 'lightbox.slideshow_transition_speed'] as $key => $translation) {
        $html .= '<label class="presentation-share-speed" title="'
            . e(t($translation)) . '"><span>'
            . e(t('presentation.' . $key . '_short'))
            . '</span><input type="range" min="0" max="2"'
            . ' aria-label="' . e(t($translation)) . '"'
            . ' step="1" value="1" data-share-option="' . $key . '">'
            . '<output data-share-speed-label="' . $key . '"></output>';
        foreach (['slow', 'medium', 'fast'] as $speed) {
            $html .= '<span hidden data-share-speed="' . $speed . '">'
                . e(t('lightbox.slideshow_speed_' . $speed)) . '</span>';
        }
        $html .= '</label>';
    }
    $html .= '<div class="presentation-share-link-row">'
        . '<label class="presentation-share-link"><span class="visually-hidden">'
        . e(t('presentation.link')) . '</span><input type="url" readonly'
        . ' data-presentation-copy value="' . e($url) . '"></label>'
        . '<button type="button"'
        . ' class="music-icon" data-presentation-copy-button title="'
        . e(t('presentation.copy')) . '" aria-label="'
        . e(t('presentation.copy')) . '">' . view_music_control_icon('copy')
        . '</button></div><output role="status" aria-live="polite"'
        . ' data-presentation-status data-copied="'
        . e(t('presentation.copied')) . '" data-copy-failed="'
        . e(t('presentation.copy_manually')) . '"></output>'
        . '<p class="presentation-share-help muted">'
        . e(t('presentation.exclusive_help')) . '</p>'
        . '</div></section></details>';
    return $html;
}

/**
 * Render shared icon-only playlist controls for a table or the photo viewer.
 * @param bool $viewer Whether this group belongs to the viewer HUD rather than a table.
 * @return string Accessible compact controls with no automatic playback.
 */
function view_music_player_controls(bool $viewer): string
{
    $html = '<div class="music-player' . ($viewer ? ' lightbox-music lightbox-hud' : '')
        . '" ' . ($viewer ? 'data-lightbox-music hidden' : 'data-gallery-music-controls')
        . ' data-music-playback-error="' . e(t('music.playback_error')) . '"'
        . ' data-music-activation-required="'
        . e(t('presentation.enable_sound')) . '">'
        . '<div class="music-player-actions" role="group" aria-label="'
        . e(t('music.title')) . '">';
    foreach (['previous' => 'music.previous', 'play' => 'music.play',
        'stop' => 'music.stop', 'next' => 'music.next'] as $action => $key) {
        $html .= '<button type="button" class="music-icon" data-music-action="'
            . e($action) . '" title="' . e(t($key)) . '" aria-label="' . e(t($key)) . '"'
            . ($action === 'stop' ? ' disabled' : '') . '>'
            . view_music_control_icon($action) . '</button>';
    }
    return $html . '</div><span class="music-player-title" data-music-current-title></span>'
        . '<output data-music-playback-status role="status"></output></div>';
}

/**
 * Open an accessible compact attachment disclosure with an owned popup body.
 * @param string $id Unique gallery/surface presentation identity.
 * @param string $title Localized document or playlist label.
 * @param int $count Number of authorized entries supplied by the controller.
 * @param string $icon Trusted decorative SVG markup, or empty for no symbol.
 * @return void Emit the native disclosure, popup header and scroll container.
 */
function view_media_panel_start(
    string $id,
    string $title,
    int $count,
    string $icon = ''
): void
{
    echo '<details class="gallery-media-widget" data-gallery-media-widget>'
        . '<summary aria-controls="' . e($id) . '">'
        . $icon . '<span>' . e($title) . '</span>'
        . '<span class="gallery-media-count">' . $count . '</span>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="m9 5 7 7-7 7"/></svg></summary>'
        . '<div class="gallery-media-panel" id="' . e($id) . '"'
        . ' data-gallery-media-panel role="region" aria-labelledby="'
        . e($id) . '-title">'
        . '<header><h3 id="' . e($id) . '-title">' . e($title) . '</h3>'
        . '<button type="button" class="gallery-media-close"'
        . ' data-media-close aria-label="' . e(t('lightbox.close'))
        . '" title="' . e(t('lightbox.close')) . '">'
        . '<span aria-hidden="true">&#215;</span></button></header>'
        . '<div class="gallery-media-body">';
}

/**
 * Close only the structural containers opened by the shared media renderer.
 * @return void Emit the scroll-body, panel and native disclosure closing tags.
 */
function view_media_panel_end(): void
{
    echo '</div></div></details>';
}

/**
 * Render the common responsive description, media and presentation hero row.
 * @param array{description_html:string,media_html:string,
 *     presentation_url?:string,presentation_music?:bool} $model Escaped/rendered
 *     description and authorized attachment fragments, plus the prepared URL.
 * @return void Emit the same applet layout without discovering source policy
 *     or loading a caller's Markdown/link-metadata dependencies.
 */
function view_render_gallery_media_hero(array $model): void
{
    $descriptionHtml = $model['description_html'];
    $mediaHtml = $model['media_html'];
    $presentationUrl = (string) ($model['presentation_url'] ?? '');
    if ($presentationUrl !== '') {
        $mediaHtml .= view_public_gallery_presentation(
            $presentationUrl, !empty($model['presentation_music'])
        );
    }
    $hasDescription = trim($descriptionHtml) !== '';
    if ($hasDescription && $mediaHtml !== '') {
        echo '<div class="gallery-description-documents">';
    }
    if ($hasDescription) {
        echo '<div class="hero-description gallery-description-rich">'
            . $descriptionHtml . '</div>';
    }
    if ($mediaHtml !== '') {
        echo '<div class="gallery-attachment-applets"'
            . ' data-gallery-attachment-applets>' . $mediaHtml . '</div>';
    }
    if ($hasDescription && $mediaHtml !== '') echo '</div>';
}

/**
 * Render a soundtrack presentation launcher and configurable share applet.
 * @param string $url Complete canonical, localized presentation launch URL.
 * @param bool $hasMusic Whether authorized music is available for this launch.
 * @return string Escaped launcher and native share disclosure.
 */
function view_public_gallery_presentation(string $url, bool $hasMusic): string
{
    return '<div class="gallery-presentation" data-gallery-presentation>'
        . '<p class="gallery-presentation-caption">'
        . e(t($hasMusic ? 'presentation.description'
            : 'presentation.description_silent')) . '</p>'
        . '<div class="gallery-presentation-actions">'
        . '<a class="button secondary" href="' . e($url) . '"'
        . ' data-gallery-presentation-start>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
        . '<path d="m8 4 13 8-13 8Z"/></svg>'
        . e(t('presentation.start')) . '</a>'
        . view_presentation_share($url, 'public-' . sha1($url))
        . '</div></div>';
}
