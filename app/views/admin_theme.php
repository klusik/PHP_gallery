<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_theme.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Theme administration page and tab presentation from controller-prepared data.
 *
 * Responsibilities:
 *   - Render the Theme page shell without request or service lookups
 *   - Render the Custom CSS Theme tab from a presentation-only view model
 *   - Compose trusted child-tab fragments prepared by controllers
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
 *   - Do not read request globals from this view.
 *   - Do not call models or domain services from this view.
 *   - Trusted HTML fragments must be prepared by project renderers in controllers before invocation.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_admin_subtab_panel;
use function Gallery\Core\render_admin_subtabs;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Services\t;

/**
 * Render the Theme administration body around controller-prepared tab fragments.
 *
 * @param array<string, mixed> $viewModel Controller-prepared labels, URLs, CSRF field, and trusted tab fragments.
 */
function view_render_admin_theme_page(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $gridResetNotice = $viewModel['grid_reset_notice'] ?? null;
    $tabs = (array) ($viewModel['tabs'] ?? []);
    $tabFragments = (array) ($viewModel['tab_fragments'] ?? []);

    if (is_string($gridResetNotice) && $gridResetNotice !== '') {
        echo '<section class="panel notice"><p>' . e($gridResetNotice) . '</p></section>';
    }

    view_render_admin_hero([
        'title' => (string) ($labels['title'] ?? 'Theme'),
        'description' => (string) ($labels['description'] ?? ''),
        'class' => 'admin-theme-hero',
        'actions_html' => '<a class="button secondary" href="' . e((string) ($viewModel['settings_url'] ?? '')) . '">' . e((string) ($labels['open_centralized'] ?? 'Open centralized settings')) . '</a><button type="submit" form="admin-theme-form">' . e((string) ($labels['save_theme'] ?? 'Save theme')) . '</button>',
    ]);

    render_admin_tabs($tabs, 'admin-theme-tab-appearance');

    echo '<form id="admin-theme-form" method="post" enctype="multipart/form-data" class="form-grid admin-theme-form" data-theme-form>' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="theme_controls_changed" value="0" data-theme-controls-changed>';

    foreach (['appearance', 'media', 'layout', 'language', 'custom_css'] as $fragmentKey) {
        echo (string) ($tabFragments[$fragmentKey] ?? '');
    }

    echo '<div class="panel admin-theme-save-panel"><div><strong>' . e((string) ($labels['save_panel_title'] ?? 'Save changes')) . '</strong><p class="muted">' . e((string) ($labels['save_panel_hint'] ?? '')) . '</p></div><div class="bulk-row"><button type="submit">' . e((string) ($labels['save_theme'] ?? 'Save theme')) . '</button><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e((string) ($labels['reset_to_css'] ?? 'Reset to CSS')) . '</button></div></div></form>';
}

/**
 * Render the Theme Custom CSS tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared labels and preset options.
 */
function view_render_admin_theme_custom_css_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $presets = (array) ($viewModel['presets'] ?? []);

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => (string) ($labels['kicker'] ?? 'Custom CSS'),
        'title' => (string) ($labels['title'] ?? 'Skins and manual CSS'),
        'description' => (string) ($labels['description'] ?? ''),
    ]);

    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-css-subtab-source', 'label' => (string) ($labels['subtab_source'] ?? 'CSS source')],
        ['id' => 'admin-theme-css-subtab-reset', 'label' => (string) ($labels['subtab_reset'] ?? 'Reset actions')],
    ], 'admin-theme-css-subtab-source', (string) ($labels['subtabs_label'] ?? 'Custom CSS subsections'));

    ob_start();
    echo '<div id="admin-custom-css"></div><fieldset class="form-grid"><legend>' . e((string) ($labels['legend'] ?? 'Custom CSS')) . '</legend><label>' . e((string) ($labels['skin_label'] ?? 'Custom CSS skin')) . '<select name="custom_css_preset"><option value="">' . e((string) ($labels['keep_current'] ?? 'Keep current custom CSS')) . '</option>';
    foreach ($presets as $preset) {
        if (!is_array($preset)) {
            continue;
        }
        $filename = (string) ($preset['filename'] ?? '');
        if ($filename === '') {
            continue;
        }
        echo '<option value="' . e($filename) . '"' . (!empty($preset['selected']) ? ' selected' : '') . '>' . e((string) ($preset['label'] ?? $filename)) . '</option>';
    }
    echo '</select><span class="muted">' . e((string) ($labels['skin_hint'] ?? '')) . '</span></label>';
    echo '<label>' . e((string) ($labels['file_label'] ?? 'Custom CSS file')) . '<input type="file" name="custom_css" accept=".css,text/css"></label>';
    echo '<p class="muted">' . e((string) ($labels['file_hint'] ?? '')) . '</p>';
    echo '</fieldset>';
    $customCssSourceHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-css-subtab-source', $customCssSourceHtml, true);

    ob_start();
    echo '<fieldset class="form-grid"><legend>' . e((string) ($labels['reset_legend'] ?? 'Reset actions')) . '</legend>';
    echo '<p class="muted">' . e((string) ($labels['reset_hint'] ?? '')) . '</p>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e((string) ($labels['reset_to_css'] ?? 'Reset to CSS')) . '</button><button type="submit" class="secondary" name="reset_custom_css" value="1" formnovalidate>' . e((string) ($labels['reset_custom_css'] ?? 'Reset custom CSS')) . '</button></div></fieldset>';
    $customCssResetHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-css-subtab-reset', $customCssResetHtml, false);

    echo '</div>';
    $customCssHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-custom-css', $customCssHtml, false);
}

/**
 * Render the Theme Layout tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared layout state, labels, and trusted picker fragments.
 */
function view_render_admin_theme_layout_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $favoriteShortcuts = (array) ($viewModel['favorite_shortcuts'] ?? []);
    $descriptionLayouts = (array) ($viewModel['description_layouts'] ?? []);
    $pagination = (array) ($viewModel['pagination'] ?? []);
    $homeGrid = (array) ($viewModel['home_grid'] ?? []);
    $lightboxOptions = (array) ($viewModel['lightbox_options'] ?? []);
    $thumbnailModes = (array) ($viewModel['thumbnail_modes'] ?? []);

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => (string) ($labels['kicker'] ?? 'Layout'),
        'title' => (string) ($labels['title'] ?? 'Pagination and gallery grids'),
        'description' => (string) ($labels['description'] ?? ''),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-layout-subtab-shortcuts', 'label' => (string) ($labels['subtab_shortcuts'] ?? 'Header shortcuts')],
        ['id' => 'admin-theme-layout-subtab-cards', 'label' => (string) ($labels['subtab_cards'] ?? 'Cards & badges')],
        ['id' => 'admin-theme-layout-subtab-grids', 'label' => (string) ($labels['subtab_grids'] ?? 'Grids & lightbox')],
    ], 'admin-theme-layout-subtab-shortcuts', (string) ($labels['subtabs_label'] ?? 'Layout subsections'));

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    echo '<fieldset class="form-grid admin-theme-favorite-galleries" id="admin-theme-favorite-galleries"><legend>' . e((string) ($labels['favorite_galleries_legend'] ?? 'Favorite gallery shortcuts')) . '</legend>';
    echo '<p class="muted">' . e((string) ($labels['favorite_galleries_hint'] ?? '')) . '</p>';
    echo '<div class="admin-theme-favorite-gallery-list">';
    foreach ($favoriteShortcuts as $shortcut) {
        if (!is_array($shortcut)) {
            continue;
        }
        $selectedType = (string) ($shortcut['selected_type'] ?? '');
        echo '<div class="admin-theme-favorite-gallery-slot"><strong>' . e((string) ($shortcut['slot_label'] ?? '')) . '</strong>';
        echo '<label>' . e((string) ($labels['favorite_gallery_type'] ?? 'Shortcut target')) . '<select name="theme_favorite_gallery_types[]">';
        echo '<option value=""' . ($selectedType === '' ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_empty'] ?? 'No shortcut')) . '</option>';
        echo '<option value="' . e((string) ($viewModel['home_token'] ?? 'home')) . '"' . ($selectedType === (string) ($viewModel['home_token'] ?? 'home') ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_home'] ?? 'Main page')) . '</option>';
        echo '<option value="gallery"' . ($selectedType === 'gallery' ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_gallery'] ?? 'Gallery')) . '</option>';
        echo '</select></label>';
        $pickerHtml = (string) ($shortcut['picker_html'] ?? '');
        if ($pickerHtml !== '') {
            echo $pickerHtml;
        } else {
            echo '<select name="theme_favorite_gallery_ids[]"><option value="">' . e((string) ($labels['favorite_gallery_empty'] ?? 'No shortcut')) . '</option>' . (string) ($shortcut['fallback_options_html'] ?? '') . '</select>';
        }
        echo '<small class="muted">' . e((string) ($labels['favorite_gallery_gallery_hint'] ?? '')) . '</small>';
        echo '</div>';
    }
    echo '</div>';
    echo '<p class="muted">' . e((string) ($labels['favorite_galleries_visibility_hint'] ?? '')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutShortcutsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-shortcuts', $layoutShortcutsHtml, true);

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    echo '<fieldset class="form-grid admin-theme-description-layout" id="admin-gallery-description-layout"><legend>' . e((string) ($labels['description_layout_legend'] ?? 'Gallery description format')) . '</legend>';
    echo '<p class="muted">' . e((string) ($labels['description_layout_hint'] ?? '')) . '</p>';
    echo '<label class="admin-theme-description-select">' . e((string) ($labels['description_layout_label'] ?? 'Default gallery-card layout')) . '<select name="theme_gallery_description_layout" data-theme-description-layout-select>';
    foreach ($descriptionLayouts as $layout) {
        if (!is_array($layout)) {
            continue;
        }
        echo '<option value="' . e((string) ($layout['value'] ?? '')) . '"' . (!empty($layout['selected']) ? ' selected' : '') . '>' . e((string) ($layout['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="admin-theme-description-layout-picker" data-theme-description-layout-picker>';
    foreach ($descriptionLayouts as $layout) {
        if (!is_array($layout)) {
            continue;
        }
        $layoutValue = (string) ($layout['value'] ?? '');
        echo '<button type="button" class="admin-theme-description-card" data-theme-description-layout-option="' . e($layoutValue) . '" aria-pressed="' . (!empty($layout['selected']) ? 'true' : 'false') . '">';
        echo '<span class="admin-theme-description-card-copy"><strong>' . e((string) ($layout['label'] ?? '')) . '</strong><span>' . e((string) ($layout['summary'] ?? '')) . '</span></span>';
        echo '<span class="admin-theme-description-card-preview is-' . e($layoutValue) . '" aria-hidden="true">';
        echo '<span class="admin-theme-description-media"><span></span></span>';
        echo '<span class="admin-theme-description-body"><span class="admin-theme-description-title">' . e((string) ($labels['description_preview_title'] ?? 'Summer gallery')) . '</span><span class="admin-theme-description-meta">' . e((string) ($labels['description_preview_meta'] ?? '12 photos')) . '</span><span class="admin-theme-description-tags"><i>' . e((string) ($labels['description_preview_tag_travel'] ?? 'travel')) . '</i><i>' . e((string) ($labels['description_preview_tag_family'] ?? 'family')) . '</i><i>2026</i></span><span class="admin-theme-description-line is-wide"></span><span class="admin-theme-description-line"></span></span>';
        echo '</span>';
        echo '</button>';
    }
    echo '</div>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-gallery-count-badge"><legend>' . e((string) ($labels['count_badge_legend'] ?? 'Contained-picture badge')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_gallery_count_badge_enabled" value="1"' . (!empty($viewModel['gallery_count_badge_enabled']) ? ' checked' : '') . '> ' . e((string) ($labels['show_count_badge'] ?? '')) . '</label>';
    echo '<p class="muted">' . e((string) ($labels['count_badge_hint'] ?? '')) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-public-thumbnail-rendering"><legend>' . e((string) ($labels['thumbnail_rendering_legend'] ?? 'Public thumbnail rendering')) . '</legend>';
    echo '<label>' . e((string) ($labels['thumbnail_rendering_label'] ?? 'Selected-gallery photo cards')) . '<select name="public_thumbnail_rendering_mode" aria-describedby="admin-public-thumbnail-rendering-help admin-public-thumbnail-rendering-transfer">';
    foreach ($thumbnailModes as $mode) {
        if (!is_array($mode)) {
            continue;
        }
        echo '<option value="' . e((string) ($mode['value'] ?? '')) . '"' . (!empty($mode['selected']) ? ' selected' : '') . '>' . e((string) ($mode['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-help"><strong>' . e((string) ($labels['thumbnail_rendering_responsive_title'] ?? '')) . '</strong> ' . e((string) ($labels['thumbnail_rendering_responsive_help'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e((string) ($labels['thumbnail_rendering_progressive_title'] ?? '')) . '</strong> ' . e((string) ($labels['thumbnail_rendering_progressive_help'] ?? '')) . '</p>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-transfer">' . e((string) ($labels['thumbnail_rendering_transfer_note'] ?? '')) . '</p>';
    echo '<p class="muted">' . e((string) ($labels['thumbnail_rendering_scope_note'] ?? '')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutCardsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-cards', $layoutCardsHtml, false);

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    echo '<fieldset class="form-grid" id="admin-pagination"><legend>' . e((string) ($labels['pagination_legend'] ?? 'Pagination')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="pagination_enabled" value="1"' . (!empty($pagination['enabled']) ? ' checked' : '') . '> ' . e((string) ($labels['enable_pagination'] ?? 'Enable pagination')) . '</label>';
    echo '<label>' . e((string) ($labels['columns_per_page'] ?? 'Columns per page')) . ' <span class="muted" data-pagination-columns-display>' . (int) ($pagination['columns'] ?? 0) . '</span><input type="range" name="pagination_columns" min="1" max="' . (int) ($viewModel['max_columns'] ?? 1) . '" value="' . (int) ($pagination['columns'] ?? 0) . '" data-pagination-columns></label>';
    echo '<label>' . e((string) ($labels['rows_per_page'] ?? 'Rows per page')) . ' <span class="muted" data-pagination-rows-display>' . (int) ($pagination['rows'] ?? 0) . '</span><input type="range" name="pagination_rows" min="1" max="' . (int) ($viewModel['max_rows'] ?? 1) . '" value="' . (int) ($pagination['rows'] ?? 0) . '" data-pagination-rows></label>';
    echo '<p class="muted">' . e((string) ($labels['items_per_page_preview'] ?? 'Items per page preview:')) . ' <span data-pagination-items-preview>' . (int) ($pagination['items_per_page'] ?? 0) . '</span></p>';
    echo '<p class="muted">' . e((string) ($labels['pagination_hint'] ?? '')) . '</p>';
    echo '</fieldset>';

    if (!empty($viewModel['lightbox_modes_enabled'])) {
        echo '<fieldset class="form-grid" id="admin-lightbox-mode"><legend>' . e((string) ($labels['lightbox_mode_legend'] ?? 'Public lightbox browsing mode')) . '</legend>';
        echo '<label>' . e((string) ($labels['lightbox_mode_label'] ?? 'Default browsing mode')) . '<select name="theme_lightbox_browsing_mode">';
        foreach ($lightboxOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select><span class="muted">' . e((string) ($labels['lightbox_mode_hint'] ?? '')) . '</span></label>';
        echo '</fieldset>';
    }

    echo '<fieldset class="form-grid" id="admin-home-grid"><legend>' . e((string) ($labels['main_page_grid_legend'] ?? 'Main page gallery grid')) . '</legend>';
    echo '<label>' . e((string) ($labels['main_page_columns'] ?? 'Main page columns')) . ' <span class="muted" data-home-grid-columns-display>' . (int) ($homeGrid['columns'] ?? 0) . '</span><input type="range" name="home_gallery_grid_columns" min="1" max="' . (int) ($viewModel['max_columns'] ?? 1) . '" value="' . (int) ($homeGrid['columns'] ?? 0) . '" data-home-grid-columns></label>';
    echo '<label>' . e((string) ($labels['main_page_rows'] ?? 'Main page rows')) . ' <span class="muted" data-home-grid-rows-display>' . (int) ($homeGrid['rows'] ?? 0) . '</span><input type="range" name="home_gallery_grid_rows" min="1" max="' . (int) ($viewModel['max_rows'] ?? 1) . '" value="' . (int) ($homeGrid['rows'] ?? 0) . '" data-home-grid-rows></label>';
    echo '<p class="muted">' . e((string) ($labels['main_page_grid_hint'] ?? '')) . '</p>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_grid_overrides" value="1" formnovalidate onclick="return confirm(&quot;' . e((string) ($labels['reset_gallery_grids_confirm'] ?? '')) . '&quot;);">' . e((string) ($labels['reset_all_gallery_grids'] ?? 'Reset all custom gallery grids')) . '</button></div>';
    echo '<p class="muted">' . e((string) ($labels['reset_gallery_grids_hint'] ?? '')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutGridsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-grids', $layoutGridsHtml, false);

    echo '</div>';
    $layoutHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-layout', $layoutHtml, false);
}

/**
 * Render the Theme Branding & Media tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared branding, favicon, background, and label state.
 */
function view_render_admin_theme_media_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $banner = $viewModel['banner'] ?? null;
    $separator = $viewModel['separator'] ?? null;
    $background = (array) ($viewModel['background'] ?? []);
    $backgroundSource = $background['source'] ?? null;

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => (string) ($labels['kicker'] ?? 'Branding & media'),
        'title' => (string) ($labels['title'] ?? 'Header branding, separator, favicon, and backgrounds'),
        'description' => (string) ($labels['description'] ?? ''),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-media-subtab-header', 'label' => (string) ($labels['subtab_header'] ?? 'Header images')],
        ['id' => 'admin-theme-media-subtab-favicon', 'label' => (string) ($labels['subtab_favicon'] ?? 'Browser icon')],
        ['id' => 'admin-theme-media-subtab-background', 'label' => (string) ($labels['subtab_background'] ?? 'Background')],
    ], 'admin-theme-media-subtab-header', (string) ($labels['subtabs_label'] ?? 'Branding and media subsections'));

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    if (is_array($banner)) {
        $bannerLabel = (string) ($banner['label'] ?? '');
        $bannerAssetUrl = (string) ($banner['asset_url'] ?? '');
        echo '<fieldset class="form-grid admin-theme-branding-assets" id="admin-theme-branding-banner"><legend>' . e((string) ($labels['public_header_banner'] ?? 'Public header banner')) . '</legend>';
        echo '<p class="muted">' . e((string) ($labels['public_header_banner_hint'] ?? '')) . '</p>';
        echo '<div class="admin-branding-asset">';
        echo '<div class="admin-branding-copy"><strong>' . e($bannerLabel) . '</strong><span class="muted">' . e((string) ($banner['description'] ?? '')) . '</span></div>';
        if ($bannerAssetUrl !== '') {
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-theme-branding-preview-banner" src="' . e($bannerAssetUrl) . '" alt="' . e((string) ($banner['current_alt'] ?? '')) . '"><button type="submit" class="secondary" name="reset_theme_branding_banner" value="1" formnovalidate>' . e((string) ($banner['remove_label'] ?? '')) . '</button></div>';
        } else {
            echo '<p class="muted">' . e((string) ($labels['no_fallback_image'] ?? 'No fallback image is stored yet.')) . '</p>';
        }
        echo '<label>' . e((string) ($labels['upload_replacement'] ?? 'Upload replacement')) . '<input type="file" name="theme_branding_banner" accept="image/png,image/jpeg,image/gif,image/webp,image/*"><span class="muted">' . e((string) ($labels['accepted_formats'] ?? '')) . '</span></label>';
        echo '</div>';
        echo '</fieldset>';
    }

    if (is_array($separator)) {
        $separatorLabel = (string) ($separator['label'] ?? '');
        $separatorAssetUrl = (string) ($separator['asset_url'] ?? '');
        echo '<fieldset class="form-grid admin-theme-branding-assets" id="admin-theme-branding-separator"><legend>' . e((string) ($labels['public_header_separator'] ?? 'Public header separator')) . '</legend>';
        echo '<p class="muted">' . e((string) ($labels['public_header_separator_hint'] ?? '')) . '</p>';
        echo '<div class="admin-branding-asset">';
        echo '<div class="admin-branding-copy"><strong>' . e($separatorLabel) . '</strong><span class="muted">' . e((string) ($separator['description'] ?? '')) . '</span></div>';
        if ($separatorAssetUrl !== '') {
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-theme-branding-preview-separator" src="' . e($separatorAssetUrl) . '" alt="' . e((string) ($separator['current_alt'] ?? '')) . '"><button type="submit" class="secondary" name="reset_theme_branding_separator" value="1" formnovalidate>' . e((string) ($separator['remove_label'] ?? '')) . '</button></div>';
        } else {
            echo '<p class="muted">' . e((string) ($labels['no_fallback_image'] ?? 'No fallback image is stored yet.')) . '</p>';
        }
        echo '<label>' . e((string) ($labels['upload_replacement'] ?? 'Upload replacement')) . '<input type="file" name="theme_branding_separator" accept="image/png,image/jpeg,image/gif,image/webp,image/*"><span class="muted">' . e((string) ($labels['accepted_formats'] ?? '')) . '</span></label>';
        echo '<div class="admin-branding-separator-size">';
        echo '<label>' . e((string) ($labels['separator_width'] ?? 'Separator width')) . '<input type="number" name="theme_branding_separator_width" min="0" max="3840" step="1" value="' . (int) ($separator['width'] ?? 0) . '"><span class="muted">' . e((string) ($labels['separator_width_hint'] ?? '')) . '</span></label>';
        echo '<label>' . e((string) ($labels['separator_height'] ?? 'Separator height')) . '<input type="number" name="theme_branding_separator_height" min="8" max="512" step="1" value="' . (int) ($separator['height'] ?? 0) . '"><span class="muted">' . e((string) ($labels['separator_height_hint'] ?? '')) . '</span></label>';
        echo '<label class="checkbox-label admin-branding-separator-stretch"><input type="checkbox" name="theme_branding_separator_stretch" value="1"' . (!empty($separator['stretch']) ? ' checked' : '') . '> ' . e((string) ($labels['separator_stretch'] ?? 'Stretch to exact width and height')) . '<span class="muted">' . e((string) ($labels['separator_stretch_hint'] ?? '')) . '</span></label>';
        echo '</div>';
        echo '</div>';
        echo '</fieldset>';
    }
    echo '</div>';
    $mediaHeaderHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-header', $mediaHeaderHtml, true);

    ob_start();
    echo '<fieldset class="form-grid" id="admin-favicon"><legend>' . e((string) ($labels['favicon_legend'] ?? 'Favicon')) . '</legend>';
    $faviconUrl = (string) ($viewModel['favicon_url'] ?? '');
    if ($faviconUrl !== '') {
        echo '<div class="favicon-current"><img src="' . e($faviconUrl) . '&s=48&v=' . e((string) ($viewModel['favicon_version'] ?? '1')) . '" alt="' . e((string) ($labels['current_favicon_alt'] ?? 'Current favicon')) . '"><p class="muted">' . e((string) ($labels['current_favicon_hint'] ?? '')) . '</p></div>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['no_favicon'] ?? '')) . '</p>';
    }
    echo '<label>' . e((string) ($labels['favicon_source_image'] ?? 'Favicon source image')) . '<input type="file" name="favicon_source" accept="image/png,image/jpeg,image/gif,image/webp,image/*" data-favicon-input><span class="muted">' . e((string) ($labels['favicon_source_hint'] ?? '')) . '</span></label>';
    echo '<input type="hidden" name="favicon_cropped_png" value="" data-favicon-cropped>';
    echo '<div class="favicon-cropper" data-favicon-cropper hidden><div class="favicon-crop-stage"><canvas width="256" height="256" data-favicon-canvas></canvas></div><label>' . e((string) ($labels['zoom'] ?? 'Zoom')) . '<input type="range" min="1" max="3" step="0.01" value="1" data-favicon-zoom></label><div class="favicon-preview-row"><canvas width="48" height="48" data-favicon-preview></canvas><span class="muted">' . e((string) ($labels['favicon_crop_hint'] ?? '')) . '</span></div></div>';
    echo '</fieldset>';
    $mediaFaviconHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-favicon', $mediaFaviconHtml, false);

    ob_start();
    echo '<fieldset class="form-grid admin-theme-background-card" id="admin-backgrounds"><legend>' . e((string) ($labels['background_legend'] ?? 'Background')) . '</legend>';
    $themeBackgroundUrl = (string) ($background['asset_url'] ?? '');
    $themeOriginalUrl = (string) ($background['original_url'] ?? '');
    $themeOptimizedActive = !empty($background['optimized_active']);
    $themeHasBackground = !empty($background['has_background']);
    echo '<div class="admin-theme-background-preview">';
    if ($themeHasBackground) {
        echo '<a class="admin-theme-background-thumb" href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener"><img src="' . e($themeBackgroundUrl) . '" alt="' . e((string) ($labels['current_theme_background_alt'] ?? 'Selected background preview')) . '"></a>';
    } else {
        echo '<div class="admin-theme-background-thumb admin-theme-background-thumb-empty" aria-hidden="true"><span></span></div>';
    }
    echo '<div class="admin-theme-background-copy"><strong>' . e($themeHasBackground ? (string) ($labels['background_selected'] ?? 'Background selected') : (string) ($labels['background_not_selected'] ?? 'No background selected')) . '</strong>';
    if ($themeHasBackground && $themeOptimizedActive) {
        echo '<span class="admin-theme-background-status is-ready">' . e((string) ($labels['background_optimized_ready'] ?? 'Optimized WebP is active')) . '</span>';
    } elseif ($themeHasBackground) {
        echo '<span class="admin-theme-background-status">' . e((string) ($labels['background_serving_original'] ?? 'Serving the original image')) . '</span>';
    } else {
        echo '<span class="muted">' . e((string) ($labels['no_theme_background'] ?? '')) . '</span>';
    }
    echo '</div></div>';
    echo '<label>' . e((string) ($labels['theme_background_image'] ?? 'Choose background image')) . '<input type="file" name="theme_background" accept="image/*"><span class="muted">' . e((string) ($labels['theme_background_image_hint'] ?? '')) . '</span></label>';
    echo '<label class="admin-theme-background-size">' . e((string) ($labels['background_optimized_size'] ?? 'Optimized display size')) . ' <span class="muted" data-theme-background-optimized-size-display data-theme-background-optimized-size-template="' . e((string) ($labels['background_optimized_size_template'] ?? '{size}px longest side')) . '">' . e((string) ($background['optimized_size_label'] ?? '')) . '</span><input type="range" name="theme_background_optimized_max_side" min="1024" max="3840" step="128" value="' . (int) ($background['optimized_max_side'] ?? 1920) . '" data-theme-background-optimized-size><span class="muted">' . e((string) ($labels['background_optimized_size_hint'] ?? '')) . '</span></label>';
    echo '<div class="admin-theme-background-actions">';
    echo '<button type="submit" class="secondary" name="generate_theme_background_optimized" value="1" formnovalidate' . (!$themeHasBackground ? ' disabled' : '') . '>' . e($themeOptimizedActive ? (string) ($labels['regenerate_optimized_background'] ?? 'Regenerate optimized background') : (string) ($labels['generate_optimized_background'] ?? 'Generate optimized background')) . '</button>';
    echo '<button type="submit" class="secondary" name="delete_theme_background_optimized" value="1" formnovalidate' . (!$themeOptimizedActive ? ' disabled' : '') . '>' . e((string) ($labels['delete_optimized_background'] ?? 'Delete optimized copy')) . '</button>';
    if ($themeHasBackground) {
        echo '<a class="button secondary" href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener">' . e((string) ($labels['view_served_image'] ?? 'View used image')) . '</a>';
    }
    if ($themeOriginalUrl !== '') {
        echo '<a class="button secondary" href="' . e($themeOriginalUrl) . '" target="_blank" rel="noopener">' . e((string) ($labels['view_original_image'] ?? 'View original')) . '</a>';
    }
    echo '</div>';
    echo '<label>' . e((string) ($labels['background_transparency'] ?? 'Background transparency')) . ' <span data-theme-background-opacity-display>' . (int) ($background['opacity'] ?? 65) . '%</span><input type="range" name="theme_background_opacity" min="0" max="100" value="' . (int) ($background['opacity'] ?? 65) . '" data-theme-override-control data-theme-background-opacity><span class="muted">' . e((string) ($labels['background_transparency_hint'] ?? '')) . '</span></label>';
    echo '<label>' . e((string) ($labels['gallery_background_fallback'] ?? 'Gallery background fallback')) . '<select name="theme_background_source" data-theme-override-control><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_none'] ?? 'No fallback set')) . '</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_upload'] ?? 'Upload new image')) . '</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_existing'] ?? 'Pick from existing gallery images')) . '</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_collage'] ?? 'Generate collage from public galleries')) . '</option></select><span class="muted">' . e((string) ($labels['gallery_background_fallback_hint'] ?? '')) . '</span></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_backgrounds" value="1" formnovalidate>' . e((string) ($labels['reset_all_gallery_backgrounds'] ?? 'Reset all gallery backgrounds')) . '</button><button type="submit" class="secondary" name="reset_theme_background" value="1" formnovalidate>' . e((string) ($labels['remove_theme_background'] ?? 'Remove theme background')) . '</button><button type="submit" class="secondary" name="reset_favicon" value="1" formnovalidate>' . e((string) ($labels['remove_favicon'] ?? 'Remove favicon')) . '</button></div>';
    echo '</fieldset>';
    $mediaBackgroundHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-background', $mediaBackgroundHtml, false);

    echo '</div>';
    $mediaHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-media', $mediaHtml, false);
}

/**
 * Render the Theme Appearance tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared appearance state.
 */
function view_render_admin_theme_appearance_tab(array $viewModel): void
{
    $theme = (array) ($viewModel['theme'] ?? []);
    $themeBackgroundUrl = (string) ($viewModel['theme_background_url'] ?? '');
    $gpsMapsFeatureEnabled = !empty($viewModel['gps_maps_feature_enabled']);
    $gpsPinEnabled = !empty($viewModel['gps_pin_enabled']);
    $gpsPinBackgroundEnabled = !empty($viewModel['gps_pin_background_enabled']);
    $gpsPinSize = (int) ($viewModel['gps_pin_size'] ?? 0);
    $gpsPinBackgroundSize = (int) ($viewModel['gps_pin_background_size'] ?? 0);
    $pageWidthMode = (string) ($viewModel['page_width_mode'] ?? 'default');
    $customPageWidth = (int) ($viewModel['custom_page_width'] ?? 1024);
    $tagPageGridSettings = (array) ($viewModel['tag_page_grid_settings'] ?? []);
    $tagPageDescriptionLayout = (string) ($viewModel['tag_page_description_layout'] ?? '');
    $descriptionLayouts = (array) ($viewModel['description_layouts'] ?? []);
    $heroTagVisibleLimit = (int) ($viewModel['hero_tag_visible_limit'] ?? 20);
    $heroTagDisplayAll = !empty($viewModel['hero_tag_display_all']);
    $heroTagScrollbarEnabled = !empty($viewModel['hero_tag_scrollbar_enabled']);
    $heroTagScrollbarRows = (int) ($viewModel['hero_tag_scrollbar_rows'] ?? 5);
    $heroTagSortMode = (string) ($viewModel['hero_tag_sort_mode'] ?? 'usage');
    $siteName = (string) ($viewModel['site_name'] ?? '');
    $adminTagsUrl = (string) ($viewModel['admin_tags_url'] ?? '');
    $appearanceSubtab = (string) ($viewModel['active_subtab'] ?? 'admin-theme-appearance-subtab-colors');
    $maxColumns = (int) ($viewModel['max_columns'] ?? 1);
    $maxRows = (int) ($viewModel['max_rows'] ?? 1);
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.appearance.kicker', 'Appearance'),
        'title' => t('admin.theme.appearance.title', 'Visual appearance'),
        'description' => t('admin.theme.appearance.description', 'Edit the core visual language. The preview mirrors colors, typography, radius, page width, and background transparency.'),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope data-theme-preview-root data-theme-preview-background-url="' . e($themeBackgroundUrl) . '">';
    render_admin_subtabs([
        ['id' => 'admin-theme-appearance-subtab-colors', 'label' => t('admin.theme.subtab_colors_identity', 'Colors & identity')],
        ['id' => 'admin-theme-appearance-subtab-width-map', 'label' => t('admin.theme.subtab_width_map', 'Width & map pin')],
        ['id' => 'admin-theme-appearance-subtab-gallery-tags', 'label' => t('admin.theme.subtab_gallery_tags', 'Gallery tags')],
        ['id' => 'admin-theme-appearance-subtab-preview', 'label' => t('admin.theme.subtab_preview', 'Live preview')],
    ], $appearanceSubtab, t('admin.theme.appearance.subtabs_label', 'Appearance subsections'));
    ob_start();
    echo '<fieldset class="form-grid admin-theme-appearance-controls-panel"><legend>' . e(t('admin.theme.appearance.legend', 'Visual appearance')) . '</legend>';
    echo '<div class="theme-appearance-controls">';
    echo '<label>' . e(t('admin.theme.appearance.site_name', 'Site name')) . '<input name="site_name" value="' . e($siteName) . '" maxlength="120" required data-theme-preview-site-name></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.accent_color', 'Accent color')) . '<input type="color" name="theme_accent" value="' . e((string) $theme['accent']) . '" data-theme-override-control data-theme-preview-color="accent"><span class="muted">' . e(t('admin.theme.appearance.accent_color_hint', 'Buttons, selected pagination, and important links.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.dark_accent', 'Dark accent')) . '<input type="color" name="theme_accent_dark" value="' . e((string) $theme['accent_dark']) . '" data-theme-override-control data-theme-preview-color="accent_dark"><span class="muted">' . e(t('admin.theme.appearance.dark_accent_hint', 'Hover states, outlines, and secondary actions.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.page_background', 'Page background')) . '<input type="color" name="theme_paper" value="' . e((string) $theme['paper']) . '" data-theme-override-control data-theme-preview-color="paper"><span class="muted">' . e(t('admin.theme.appearance.page_background_hint', 'The base page tone behind all content.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.panel_background', 'Panel background')) . '<input type="color" name="theme_panel" value="' . e((string) $theme['panel']) . '" data-theme-override-control data-theme-preview-color="panel"><span class="muted">' . e(t('admin.theme.appearance.panel_background_hint', 'Cards, panels, and normal gallery tiles.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.open_gallery_panel', 'Open gallery panel')) . '<input type="color" name="theme_gallery_panel" value="' . e((string) $theme['gallery_panel']) . '" data-theme-override-control data-theme-preview-color="gallery_panel"><span class="muted">' . e(t('admin.theme.appearance.open_gallery_panel_hint', 'Gallery-specific cards and image panels.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.header_title_color', 'Header title color')) . '<input type="color" name="theme_header_text" value="' . e((string) $theme['header_text']) . '" data-theme-override-control data-theme-preview-color="header_text"><span class="muted">' . e(t('admin.theme.appearance.header_title_color_hint', 'Main site title in the public header.')) . '</span></label>';
    echo '<label class="theme-color-control">' . e(t('admin.theme.appearance.gallery_title_color', 'Gallery title color')) . '<input type="color" name="theme_hero_text" value="' . e((string) $theme['hero_text']) . '" data-theme-override-control data-theme-preview-color="hero_text"><span class="muted">' . e(t('admin.theme.appearance.gallery_title_color_hint', 'Open gallery title and hero text.')) . '</span></label>';
    echo '<label>' . e(t('admin.theme.appearance.rounded_corners', 'Rounded corners')) . ' <span class="muted" data-theme-radius-display>' . (int) $theme['radius'] . 'px</span><input type="range" name="theme_radius" min="0" max="32" value="' . (int) $theme['radius'] . '" data-theme-override-control data-theme-preview-radius></label>';
    echo '<label>' . e(t('admin.theme.appearance.font_style', 'Font style')) . '<select name="theme_font" data-theme-override-control data-theme-preview-font><option value="serif"' . ($theme['font'] === 'serif' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.font_serif', 'Classic serif')) . '</option><option value="sans"' . ($theme['font'] === 'sans' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.font_sans', 'Clean sans-serif')) . '</option></select></label>';
    echo '</div></fieldset>';
    $appearanceColorsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-colors', $appearanceColorsHtml, true);

    ob_start();
    echo '<fieldset class="form-grid admin-theme-width-map-panel"><legend>' . e(t('admin.theme.appearance.width_map_legend', 'Width and map pin')) . '</legend>';
    if ($gpsMapsFeatureEnabled) {
        echo '<fieldset class="theme-gps-pin-settings"><legend>' . e(t('admin.theme.appearance.gps_pin_legend', 'GPS pin')) . '</legend>';
        echo '<label class="checkbox-label"> <input type="checkbox" name="theme_gps_pin_enabled" value="1"' . ($gpsPinEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-enabled> ' . e(t('admin.theme.appearance.show_gps_pin', 'Show GPS pin on photo cards')) . '</label>';
        echo '<label class="checkbox-label"> <input type="checkbox" name="theme_gps_pin_background_enabled" value="1"' . ($gpsPinBackgroundEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-background-enabled> ' . e(t('admin.theme.appearance.show_pin_background', 'Show pin background underlay')) . '</label>';
        echo '<label>' . e(t('admin.theme.appearance.pin_size', 'Pin size')) . ' <span class="muted" data-theme-gps-pin-size-display>' . $gpsPinSize . 'px</span><input type="range" name="theme_gps_pin_size" min="14" max="48" step="1" value="' . $gpsPinSize . '" data-theme-override-control data-theme-gps-pin-size></label>';
        echo '<label>' . e(t('admin.theme.appearance.pin_background_size', 'Background size')) . ' <span class="muted" data-theme-gps-pin-background-size-display>' . $gpsPinBackgroundSize . 'px</span><input type="range" name="theme_gps_pin_background_size" min="0" max="48" step="1" value="' . $gpsPinBackgroundSize . '" data-theme-override-control data-theme-gps-pin-background-size></label>';
        echo '<div class="theme-gps-pin-preview" data-theme-gps-pin-preview aria-label="' . e(t('admin.theme.appearance.gps_pin_preview_label', 'GPS pin preview')) . '"><span class="photo-map-pin" data-theme-gps-pin-sample aria-hidden="true">&#128205;</span><span class="muted">' . e(t('admin.theme.appearance.gps_pin_preview_hint', 'Live preview of the photo pin.')) . '</span></div>';
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_gps_pin_size" value="1" formnovalidate>' . e(t('admin.theme.appearance.reset_pin_size', 'Reset pin size')) . '</button></div>';
        echo '</fieldset>';
    }
    echo '<label>' . e(t('admin.theme.appearance.page_width', 'Page width')) . '<select name="theme_page_width" data-theme-preview-width data-theme-page-width-select><option value="default"' . ($pageWidthMode === 'default' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_default', 'Default')) . '</option><option value="wide"' . ($pageWidthMode === 'wide' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_wide', 'Wider')) . '</option><option value="custom"' . ($pageWidthMode === 'custom' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_custom', 'Custom')) . '</option><option value="full"' . ($pageWidthMode === 'full' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_full', 'Full width')) . '</option></select><span class="muted">' . e(t('admin.theme.appearance.page_width_hint', 'Controls the public page container. Full width follows the available screen width dynamically.')) . '</span></label>';
    echo '<div class="theme-custom-width-control" data-theme-custom-width-control' . ($pageWidthMode === 'custom' ? '' : ' hidden') . '>';
    echo '<label>' . e(t('admin.theme.appearance.custom_page_width', 'Custom page width')) . ' <span class="muted" data-theme-custom-width-display>' . $customPageWidth . 'px</span><input type="range" name="theme_page_width_custom_slider" min="1024" max="2048" step="1" value="' . $customPageWidth . '" data-theme-custom-width-slider></label>';
    echo '<label>' . e(t('admin.theme.appearance.custom_width_pixels', 'Custom width in pixels')) . '<input type="number" name="theme_page_width_custom" min="1024" max="2048" step="1" value="' . $customPageWidth . '" inputmode="numeric" data-theme-preview-custom-width data-theme-custom-width-number><span class="muted">' . e(t('admin.theme.appearance.custom_width_pixels_hint', 'Allowed range: 1024 to 2048 px.')) . '</span></label>';
    echo '</div>';
    echo '</fieldset>';
    $appearanceWidthMapHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-width-map', $appearanceWidthMapHtml, false);

    ob_start();
    echo '<fieldset class="form-grid admin-theme-tag-page-settings"><legend>' . e(t('admin.theme.appearance.tag_page_legend', 'Public tag page layout')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.tag_page_hint', 'Overrides the normal Theme grid and gallery-card design when visitors open a public tag page. The site-wide pagination switch still controls whether long tag results are split into pages.')) . '</p>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_columns', 'Galleries per row')) . ' <span class="muted">' . (int) $tagPageGridSettings['columns'] . '</span><input type="range" name="tag_page_gallery_grid_columns" min="1" max="' . $maxColumns . '" value="' . (int) $tagPageGridSettings['columns'] . '"></label>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_rows', 'Rows per page')) . ' <span class="muted">' . (int) $tagPageGridSettings['rows'] . '</span><input type="range" name="tag_page_gallery_grid_rows" min="1" max="' . $maxRows . '" value="' . (int) $tagPageGridSettings['rows'] . '"></label>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.tag_page_capacity', 'Tag-page capacity: {count} galleries per page.', ['count' => (int) $tagPageGridSettings['items_per_page']])) . '</p>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_card_layout', 'Gallery-card design')) . '<select name="tag_page_gallery_description_layout">';
    foreach ($descriptionLayouts as $descriptionLayout) {
        $descriptionLayoutOption = (string) ($descriptionLayout['value'] ?? '');
        echo '<option value="' . e($descriptionLayoutOption) . '"' . ($tagPageDescriptionLayout === $descriptionLayoutOption ? ' selected' : '') . '>' . e((string) ($descriptionLayout['label'] ?? $descriptionLayoutOption)) . '</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.theme.appearance.tag_page_card_layout_hint', 'This choice overrides both the Theme default and individual gallery-card layout on tag result pages only.')) . '</span></label>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid admin-theme-hero-tag-settings"><legend>' . e(t('admin.theme.appearance.hero_tags_legend', 'Gallery hero tags')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.hero_tags_hint', 'Controls the tag collection shown below an open gallery title. Every tag remains in the server-rendered HTML; the visible limit and expand/collapse behavior are applied in the browser without reloading the page.')) . '</p>';
    echo '<label>' . e(t('admin.theme.appearance.hero_tag_sort', 'Tag order')) . '<select name="theme_hero_tag_sort_mode"><option value="usage"' . ($heroTagSortMode === 'usage' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.hero_tag_sort_usage', 'Most used first')) . '</option><option value="alphabetical"' . ($heroTagSortMode === 'alphabetical' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.hero_tag_sort_alphabetical', 'Alphabetical')) . '</option></select><span class="muted">' . e(t('admin.theme.appearance.hero_tag_sort_hint', 'Usage counts direct gallery and photo assignments across the installation; equal counts are ordered alphabetically.')) . '</span></label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_hero_tag_display_all" value="1"' . ($heroTagDisplayAll ? ' checked' : '') . ' data-theme-hero-tag-display-all> ' . e(t('admin.theme.appearance.hero_tag_display_all', 'Display every tag immediately')) . '</label>';
    echo '<div class="theme-number-slider-control" data-theme-hero-tag-limit-controls' . ($heroTagDisplayAll ? ' hidden' : '') . '>';
    echo '<label>' . e(t('admin.theme.appearance.hero_tag_visible_limit', 'Tags before “Display all tags”')) . '<input type="range" name="theme_hero_tag_visible_limit_slider" min="1" max="200" step="1" value="' . $heroTagVisibleLimit . '" data-theme-hero-tag-limit-slider></label>';
    echo '<label>' . e(t('admin.theme.appearance.hero_tag_visible_limit_number', 'Visible tag count')) . '<input type="number" name="theme_hero_tag_visible_limit" min="1" max="200" step="1" value="' . $heroTagVisibleLimit . '" inputmode="numeric" data-theme-hero-tag-limit-number><span class="muted" data-theme-hero-tag-limit-display>' . $heroTagVisibleLimit . '</span></label>';
    echo '</div>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.hero_tag_visible_limit_hint', 'Default: 20 tags. When more tags exist, “Display all tags” expands them in-place with JavaScript and does not reload the page.')) . '</p>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_hero_tag_scrollbar_enabled" value="1"' . ($heroTagScrollbarEnabled ? ' checked' : '') . ' data-theme-hero-tag-scrollbar-enabled> ' . e(t('admin.theme.appearance.hero_tag_scrollbar_enabled', 'Use a scrollbar for long tag lists')) . '</label>';
    echo '<div class="theme-number-slider-control" data-theme-hero-tag-scrollbar-controls' . ($heroTagScrollbarEnabled ? '' : ' hidden') . '>';
    echo '<label>' . e(t('admin.theme.appearance.hero_tag_scrollbar_rows', 'Rows before scrolling')) . '<input type="range" name="theme_hero_tag_scrollbar_rows_slider" min="1" max="12" step="1" value="' . $heroTagScrollbarRows . '" data-theme-hero-tag-scrollbar-rows-slider></label>';
    echo '<label>' . e(t('admin.theme.appearance.hero_tag_scrollbar_rows_number', 'Maximum visible tag rows')) . '<input type="number" name="theme_hero_tag_scrollbar_rows" min="1" max="12" step="1" value="' . $heroTagScrollbarRows . '" inputmode="numeric" data-theme-hero-tag-scrollbar-rows-number><span class="muted" data-theme-hero-tag-scrollbar-rows-display>' . $heroTagScrollbarRows . '</span></label>';
    echo '</div>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.hero_tag_scrollbar_rows_hint', 'Default: 5 rows. Scrolling is enabled only when the tags actually wrap onto more rows at the current screen width. Disable the scrollbar option to let the hero grow naturally.')) . '</p>';
    echo '<div class="bulk-row"><a class="button secondary" href="' . e($adminTagsUrl) . '">' . e(t('admin.theme.appearance.open_tag_metadata', 'Manage tag metadata')) . '</a></div>';
    echo '</fieldset>';
    $appearanceGalleryTagsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-gallery-tags', $appearanceGalleryTagsHtml, false);

    ob_start();
    echo '<aside class="theme-live-preview" aria-label="' . e(t('admin.theme.appearance.live_preview_label', 'Live theme preview')) . '" data-theme-live-preview>';
    // The preview starts from the saved page-width mode and custom pixel value before JavaScript runs.
    echo '<div class="theme-preview-page" data-theme-preview-page data-preview-width="' . e($pageWidthMode) . '" style="--preview-custom-width-scale: ' . number_format(($customPageWidth - 1024) / 1024, 4, '.', '') . ';">';
    echo '<div class="theme-preview-background"><span data-theme-preview-background-image></span></div>';
    echo '<header class="theme-preview-header"><strong data-theme-preview-brand>' . e($siteName) . '</strong><nav><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_home', 'Home')) . '</span><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_galleries', 'Galleries')) . '</span></nav></header>';
    echo '<section class="theme-preview-hero"><p>' . e(t('admin.theme.appearance.preview_open_gallery', 'Open gallery')) . '</p><h2 data-theme-preview-hero-title>' . e(t('admin.theme.appearance.preview_gallery_title', 'Aircraft Weekend')) . '</h2><span class="theme-preview-tag">' . e(t('admin.theme.appearance.preview_tag', 'travel')) . '</span></section>';
    echo '<div class="theme-preview-grid"><article class="theme-preview-card"><div></div><h3>' . e(t('admin.theme.appearance.preview_subgallery_card', 'Subgallery card')) . '</h3><p>' . e(t('admin.theme.appearance.preview_panel_background', 'Panel background')) . '</p></article><article class="theme-preview-card theme-preview-gallery-card"><div></div><h3>' . e(t('admin.theme.appearance.preview_photo_card', 'Photo card')) . '</h3><p>' . e(t('admin.theme.appearance.preview_open_gallery_panel', 'Open gallery panel')) . '</p></article></div>';
    echo '<div class="theme-preview-pagination"><span>1</span><span>2</span><span>3</span></div>';
    echo '</div>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.preview_hint', 'Preview updates while editing. It is intentionally small, but uses the same colors, font mode, corner radius, and background transparency controls as the public theme.')) . '</p>';
    echo '</aside>';
    $appearancePreviewHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-preview', $appearancePreviewHtml, false);
    echo '</div>';
    $appearanceHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-appearance', $appearanceHtml, true);

}

/**
 * Render the Theme Language tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared language state.
 */
function view_render_admin_theme_language_tab(array $viewModel): void
{
    $languagePacks = (array) ($viewModel['language_packs'] ?? []);
    $adminLanguage = (string) ($viewModel['admin_language'] ?? '');
    $publicLanguage = (string) ($viewModel['public_language'] ?? '');
    $defaultLanguage = (string) ($viewModel['default_language'] ?? '');
    $missingTranslations = (array) ($viewModel['missing_translations'] ?? []);
    $languageEditCode = (string) ($viewModel['language_edit_code'] ?? '');
    $languageEditorErrors = (array) ($viewModel['language_editor_errors'] ?? []);
    $languageCoverage = (array) ($viewModel['language_coverage'] ?? []);
    $languagePackJson = (string) ($viewModel['language_pack_json'] ?? '');
    $selectorState = (array) ($viewModel['selector_state'] ?? []);
    $editorBaseUrl = (string) ($viewModel['editor_base_url'] ?? '');
    $exportUrl = (string) ($viewModel['export_url'] ?? '');
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.language.kicker', 'Language'),
        'title' => t('admin.theme.language.title', 'Language and translation packs'),
        'description' => t('admin.theme.language.description', 'Choose the admin interface language, choose the public visitor language, and inspect installed language packs before translating more areas.'),
    ]);
    if (!empty($viewModel['saved_notice'])) {
        echo '<section class="panel notice"><p>' . e(t('admin.theme.language.saved_notice', 'Language pack saved.')) . '</p></section>';
    }
    if (!empty($viewModel['imported_notice'])) {
        echo '<section class="panel notice"><p>' . e(t('admin.theme.language.imported_notice', 'Language pack imported.')) . '</p></section>';
    }
    if (!empty($languageEditorErrors)) {
        echo '<section class="panel warning"><strong>' . e(t('admin.theme.language.validation_failed', 'Language pack validation failed.')) . '</strong><ul>';
        foreach ($languageEditorErrors as $languageEditorError) {
            echo '<li>' . e((string) $languageEditorError) . '</li>';
        }
        echo '</ul></section>';
    }
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-language-subtab-settings', 'label' => t('admin.theme.subtab_language_settings', 'Settings & packs')],
        ['id' => 'admin-theme-language-subtab-editor', 'label' => t('admin.theme.subtab_language_editor', 'Pack editor')],
        ['id' => 'admin-theme-language-subtab-diagnostics', 'label' => t('admin.theme.subtab_language_diagnostics', 'Diagnostics')],
    ], 'admin-theme-language-subtab-settings', t('admin.theme.language.subtabs_label', 'Language subsections'));
    ob_start();
    echo '<div class="theme-tab-card-grid admin-language-tab-grid">';
    echo '<fieldset class="form-grid admin-language-settings"><legend>' . e(t('admin.theme.language.settings_legend', 'Language settings')) . '</legend>';
    echo '<label>' . e(t('admin.theme.language.admin_label', 'Admin interface language')) . '<select name="cms_language">';
    foreach ($languagePacks as $languagePack) {
        // $languageCode stores one selectable language code.
        $languageCode = (string) ($languagePack['code'] ?? '');
        if ($languageCode === '') {
            continue;
        }
        // $languageName stores the human-readable language pack name.
        $languageName = (string) ($languagePack['name'] ?? strtoupper($languageCode));
        echo '<option value="' . e($languageCode) . '"' . ($adminLanguage === $languageCode ? ' selected' : '') . '>' . e($languageName) . ' (' . e($languageCode) . ')</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.theme.language.admin_hint', 'Saved to your admin session and admin browser cookie. It does not force the public visitor language.')) . '</span></label>';
    echo '<label>' . e(t('admin.theme.language.public_label', 'Public visitor language')) . '<select name="public_language">';
    foreach ($languagePacks as $languagePack) {
        // $languageCode stores one public language option value.
        $languageCode = (string) ($languagePack['code'] ?? '');
        if ($languageCode === '') {
            continue;
        }
        // $languageName stores the human-readable public language option label.
        $languageName = (string) ($languagePack['name'] ?? strtoupper($languageCode));
        echo '<option value="' . e($languageCode) . '"' . ($publicLanguage === $languageCode ? ' selected' : '') . '>' . e($languageName) . ' (' . e($languageCode) . ')</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.theme.language.public_hint', 'Saved globally. Anonymous users and public gallery pages use this language by default.')) . '</span></label>';
    view_render_public_language_selector_settings_panel($selectorState);
    echo '<p class="muted"><strong>' . e(t('admin.theme.language.default_label', 'Default language')) . ':</strong> ' . e($defaultLanguage) . '</p>';
    echo '<p class="muted">' . e(t('admin.theme.language.fallback_note', 'English is the source and fallback language. English, Czech, German, and Swedish are the maintained selectable catalogs and are expected to stay complete; fallback protects against accidental gaps.')) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid admin-language-packs"><legend>' . e(t('admin.theme.language.detected_legend', 'Supported language packs')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.detected_hint', 'Supported language packs are loaded from app/lang/*.json first. Additional dormant files do not become selectable automatically. Legacy app/lang/*.php files still work as fallback.')) . '</p>';
    echo '<div class="admin-language-table-wrap"><table class="admin-table admin-language-table"><thead><tr><th>' . e(t('admin.theme.language.pack_language', 'Language')) . '</th><th>' . e(t('admin.theme.language.pack_code', 'Code')) . '</th><th>' . e(t('admin.theme.language.pack_format', 'Format')) . '</th><th>' . e(t('admin.theme.language.pack_strings', 'Strings')) . '</th><th>' . e(t('admin.theme.language.coverage', 'Coverage')) . '</th><th>' . e(t('admin.theme.language.pack_status', 'Status')) . '</th></tr></thead><tbody>';
    foreach ($languagePacks as $languagePack) {
        // Presentation metadata is prepared by the controller so this view does not inspect translation services.
        $packCode = (string) ($languagePack['code'] ?? '');
        $packCoverage = (array) ($languagePack['coverage'] ?? []);
        $formatLabel = (string) ($languagePack['format_label'] ?? '');
        $statusLabel = (string) ($languagePack['status_label'] ?? '');
        echo '<tr><td>' . e((string) ($languagePack['name'] ?? '')) . '</td><td><code>' . e($packCode) . '</code></td><td>' . e($formatLabel) . '</td><td>' . (int) ($languagePack['string_count'] ?? 0) . '</td><td>' . e(t('admin.theme.language.coverage_ratio', '{translated} / {total}', ['translated' => (int) $packCoverage['translated_count'], 'total' => (int) $packCoverage['default_count']])) . '</td><td>' . e($statusLabel) . '</td></tr>';
    }
    echo '</tbody></table></div></fieldset>';
    echo '</div>';

    echo '<fieldset class="form-grid admin-language-conventions"><legend>' . e(t('admin.theme.language.conventions_legend', 'Key naming conventions')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.conventions_hint', 'Use stable dotted keys grouped by UI area. Keep wording editable in JSON and keep variable placeholders wrapped in braces.')) . '</p>';
    echo '<ul class="admin-language-convention-list">';
    echo '<li><code>gallery.*</code> ' . e(t('admin.theme.language.convention_gallery', 'public gallery pages and visitor-facing gallery actions')) . '</li>';
    echo '<li><code>admin.*</code> ' . e(t('admin.theme.language.convention_admin', 'shared admin labels and actions')) . '</li>';
    echo '<li><code>theme.*</code> ' . e(t('admin.theme.language.convention_theme', 'theme controls outside the language tab')) . '</li>';
    echo '<li><code>language.*</code> ' . e(t('admin.theme.language.convention_language', 'language-pack editing and diagnostics')) . '</li>';
    echo '<li><code>telemetry.*</code> ' . e(t('admin.theme.language.convention_telemetry', 'anonymous telemetry pages and reports')) . '</li>';
    echo '<li><code>logs.*</code> ' . e(t('admin.theme.language.convention_logs', 'operational logs and log export')) . '</li>';
    echo '</ul></fieldset>';
    $languageSettingsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-settings', $languageSettingsHtml, true);

    ob_start();
    echo '<fieldset class="form-grid admin-language-editor"><legend>' . e(t('admin.theme.language.editor_legend', 'Language pack editor')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.editor_hint', 'Edit the JSON language pack directly. The save action validates JSON and accepts only string values.')) . '</p>';
    echo '<label>' . e(t('admin.theme.language.editor_select', 'Language pack to edit')) . '<select name="language_pack_code" onchange="if (this.value) window.location.href=\'' . e($editorBaseUrl) . '?edit_language=\' + encodeURIComponent(this.value) + \'#admin-theme-tab-language\';">';
    foreach ($languagePacks as $languagePack) {
        // $languageCode stores one editor-select option value.
        $languageCode = (string) ($languagePack['code'] ?? '');
        if ($languageCode === '') {
            continue;
        }
        // $languageName stores one editor-select option label.
        $languageName = (string) ($languagePack['name'] ?? strtoupper($languageCode));
        echo '<option value="' . e($languageCode) . '"' . ($languageEditCode === $languageCode ? ' selected' : '') . '>' . e($languageName) . ' (' . e($languageCode) . ')</option>';
    }
    echo '</select></label>';
    echo '<div class="admin-language-coverage-summary">';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_translated', 'Translated')) . ':</strong> ' . e(t('admin.theme.language.coverage_ratio', '{translated} / {total}', ['translated' => (int) $languageCoverage['translated_count'], 'total' => (int) $languageCoverage['default_count']])) . '</span>';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_missing', 'Missing')) . ':</strong> ' . e(t('admin.theme.language.count_value', '{count}', ['count' => (int) $languageCoverage['missing_count']])) . '</span>';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_extra', 'Extra')) . ':</strong> ' . e(t('admin.theme.language.count_value', '{count}', ['count' => (int) $languageCoverage['extra_count']])) . '</span>';
    echo '</div>';
    if (!empty($languageCoverage['missing_keys']) || !empty($languageCoverage['extra_keys'])) {
        echo '<details class="admin-language-key-details"><summary>' . e(t('admin.theme.language.show_key_differences', 'Show missing and extra keys')) . '</summary>';
        if (!empty($languageCoverage['missing_keys'])) {
            echo '<p class="muted"><strong>' . e(t('admin.theme.language.missing_keys', 'Missing keys')) . '</strong></p><code class="admin-language-key-list">' . e(implode("\n", array_slice((array) $languageCoverage['missing_keys'], 0, 80))) . '</code>';
        }
        if (!empty($languageCoverage['extra_keys'])) {
            echo '<p class="muted"><strong>' . e(t('admin.theme.language.extra_keys', 'Extra keys')) . '</strong></p><code class="admin-language-key-list">' . e(implode("\n", array_slice((array) $languageCoverage['extra_keys'], 0, 80))) . '</code>';
        }
        echo '</details>';
    }
    echo '<label>' . e(t('admin.theme.language.json_label', 'JSON language data')) . '<textarea name="language_pack_json" class="admin-language-json-editor" spellcheck="false" rows="20">' . e($languagePackJson) . '</textarea></label>';
    echo '<div class="bulk-row"><button type="submit" name="save_language_pack" value="1" formnovalidate>' . e(t('admin.theme.language.save_pack', 'Save language pack')) . '</button><a class="button secondary" href="' . e($exportUrl) . '">' . e(t('admin.theme.language.export_pack', 'Export JSON')) . '</a></div>';
    echo '<label>' . e(t('admin.theme.language.import_label', 'Import replacement JSON')) . '<input type="file" name="language_pack_file" accept="application/json,.json"></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="import_language_pack" value="1" formnovalidate onclick="return confirm(&quot;' . e(t('admin.theme.language.import_confirm', 'Replace this language pack with the uploaded JSON file?')) . '&quot;);">' . e(t('admin.theme.language.import_pack', 'Import JSON')) . '</button></div>';
    echo '</fieldset>';
    $languageEditorHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-editor', $languageEditorHtml, false);

    ob_start();
    echo '<fieldset class="form-grid admin-language-diagnostics"><legend>' . e(t('admin.theme.language.diagnostics_legend', 'Missing translation diagnostics')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.diagnostics_hint', 'These diagnostics are visible only to admins and help find strings that still need language keys.')) . '</p>';
    if (!$missingTranslations) {
        echo '<p class="muted">' . e(t('admin.theme.language.diagnostics_empty', 'No missing translations have been recorded in this admin session.')) . '</p>';
    } else {
        echo '<div class="admin-language-table-wrap"><table class="admin-table admin-language-table"><thead><tr><th>' . e(t('admin.theme.language.diagnostics_key', 'Key')) . '</th><th>' . e(t('admin.theme.language.diagnostics_active', 'Active language')) . '</th><th>' . e(t('admin.theme.language.diagnostics_fallback', 'Fallback used')) . '</th><th>' . e(t('admin.theme.language.diagnostics_seen', 'Last seen')) . '</th></tr></thead><tbody>';
        foreach ($missingTranslations as $missingTranslation) {
            echo '<tr><td><code>' . e((string) ($missingTranslation['key'] ?? '')) . '</code></td><td>' . e((string) ($missingTranslation['active_language'] ?? '')) . '</td><td>' . e((string) ($missingTranslation['fallback_used'] ?? '')) . '</td><td>' . e((string) ($missingTranslation['last_seen'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="clear_translation_diagnostics" value="1" formnovalidate>' . e(t('admin.theme.language.clear_diagnostics', 'Clear diagnostics')) . '</button></div>';
    }
    echo '</fieldset>';
    $languageDiagnosticsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-diagnostics', $languageDiagnosticsHtml, false);
    echo '</div>';
    $languageHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-language', $languageHtml, false);

}
