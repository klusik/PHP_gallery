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
 *   2026-10-03
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
 * @return void Emits the shared Theme form with one floating save action.
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
        'actions_html' => '<a class="button secondary" href="' . e((string) ($viewModel['settings_url'] ?? '')) . '">' . e((string) ($labels['open_centralized'] ?? 'Open centralized settings')) . '</a>',
    ]);

    render_admin_tabs($tabs, 'admin-theme-tab-appearance');

    echo '<form id="admin-theme-form" method="post" enctype="multipart/form-data" class="form-grid admin-theme-form" data-theme-form>' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="theme_controls_changed" value="0" data-theme-controls-changed>';
    echo '<input type="hidden" name="theme_active_tab" value="admin-theme-tab-appearance" data-theme-active-tab><input type="hidden" name="theme_active_appearance_subtab" value="admin-theme-appearance-subtab-colors" data-theme-active-appearance-subtab>';

    foreach (['appearance', 'media', 'layout', 'language', 'custom_css'] as $fragmentKey) {
        echo (string) ($tabFragments[$fragmentKey] ?? '');
    }

    // One viewport-anchored action remains available while editing any Theme tab.
    echo '<div class="panel admin-theme-save-panel"><span class="admin-theme-save-hint" title="' . e((string) ($labels['save_panel_hint'] ?? '')) . '">' . e((string) ($labels['save_panel_title'] ?? 'Save changes')) . '</span><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate title="' . e((string) ($labels['reset_to_css'] ?? 'Reset to CSS')) . '">' . e((string) ($labels['reset_to_css'] ?? 'Reset to CSS')) . '</button><button type="submit" title="' . e((string) ($labels['save_panel_hint'] ?? '')) . '">' . e((string) ($labels['save_theme'] ?? 'Save theme')) . '</button></div></form>';
}

/**
 * Render the shared miniature public Theme preview from presentation-only data.
 *
 * @param array<string,mixed> $preview Prepared site name, Theme values, and optional background URL.
 * @return void
 */
function view_render_admin_theme_live_preview(array $preview): void
{
    $siteName = trim((string) ($preview['site_name'] ?? ''));
    $siteName = $siteName !== '' ? $siteName : 'Gallery CMS';
    $pageWidthMode = (string) ($preview['page_width'] ?? 'default');
    if (!in_array($pageWidthMode, ['default', 'wide', 'custom', 'full'], true)) {
        $pageWidthMode = 'default';
    }
    $customPageWidth = max(1024, min(2048, (int) ($preview['page_width_custom'] ?? 1440)));
    $descriptionLayout = in_array((string) ($preview['gallery_description_layout'] ?? 'vertical'), ['vertical', 'horizontal'], true)
        ? (string) $preview['gallery_description_layout']
        : 'vertical';
    $countBadgeEnabled = (string) ($preview['gallery_count_badge_enabled'] ?? '1') !== '0';
    $paginationEnabled = (string) ($preview['pagination_enabled'] ?? '0') === '1';
    $homeGridColumns = max(1, min(12, (int) ($preview['home_gallery_grid_columns'] ?? $preview['pagination_columns'] ?? 3)));
    $homeGridRows = max(1, min(50, (int) ($preview['home_gallery_grid_rows'] ?? $preview['pagination_rows'] ?? 3)));
    $tagGridColumns = max(1, min(12, (int) ($preview['tag_page_gallery_grid_columns'] ?? $preview['pagination_columns'] ?? 3)));
    $tagGridRows = max(1, min(50, (int) ($preview['tag_page_gallery_grid_rows'] ?? $preview['pagination_rows'] ?? 3)));
    $lightboxMode = trim((string) ($preview['lightbox_browsing_mode'] ?? 'single')) ?: 'single';
    $thumbnailMode = trim((string) ($preview['public_thumbnail_rendering_mode'] ?? 'progressive')) ?: 'progressive';

    echo '<aside class="theme-live-preview" aria-label="' . e(t('admin.theme.appearance.live_preview_label', 'Live theme preview')) . '" data-theme-live-preview>';
    // The preview starts from the saved or staged page-width mode and custom pixel value before JavaScript runs.
    echo '<div class="theme-preview-page" data-theme-preview-page data-preview-width="' . e($pageWidthMode) . '" style="--preview-custom-width-scale: ' . number_format(($customPageWidth - 1024) / 1024, 4, '.', '') . '; --preview-grid-columns: ' . min(4, $homeGridColumns) . ';">';
    echo '<div class="theme-preview-background"><span data-theme-preview-background-image></span></div>';
    echo '<header class="theme-preview-header"><strong data-theme-preview-brand>' . e($siteName) . '</strong><nav><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_home', 'Home')) . '</span><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_galleries', 'Galleries')) . '</span></nav></header>';
    echo '<section class="theme-preview-hero"><p>' . e(t('admin.theme.appearance.preview_open_gallery', 'Open gallery')) . '</p><h2 data-theme-preview-hero-title>' . e(t('admin.theme.appearance.preview_gallery_title', 'Aircraft Weekend')) . '</h2><span class="theme-preview-tag">' . e(t('admin.theme.appearance.preview_tag', 'travel')) . '</span></section>';
    echo '<div class="theme-preview-grid" data-theme-preview-grid>';
    for ($index = 0; $index < 4; $index++) {
        $galleryCard = $index % 2 === 0;
        echo '<article class="theme-preview-card' . ($galleryCard ? ' theme-preview-gallery-card' : '') . '"' . ($galleryCard ? ' data-theme-preview-description-card data-description-layout="' . e($descriptionLayout) . '"' : '') . '>';
        echo '<div class="theme-preview-media">';
        if ($galleryCard) {
            echo '<span class="theme-preview-count-badge" data-theme-preview-count-badge-sample' . ($countBadgeEnabled ? '' : ' hidden') . '>12</span>';
        } else {
            echo '<span class="photo-map-pin theme-preview-gps-pin" data-theme-gps-pin-sample aria-hidden="true">&#128205;</span>';
        }
        echo '</div>';
        echo '<div class="theme-preview-card-copy"><h3>' . e($galleryCard ? t('admin.theme.appearance.preview_subgallery_card', 'Subgallery card') : t('admin.theme.appearance.preview_photo_card', 'Photo card')) . '</h3><p>' . e($galleryCard ? t('admin.theme.appearance.preview_panel_background', 'Panel background') : t('admin.theme.appearance.preview_open_gallery_panel', 'Open gallery panel')) . '</p></div>';
        echo '</article>';
    }
    echo '</div>';
    echo '<div class="theme-preview-pagination" data-theme-preview-pagination' . ($paginationEnabled ? '' : ' hidden') . '><span>1</span><span>2</span><span>3</span></div>';
    echo '<div class="theme-preview-settings" aria-live="polite">';
    echo '<span><strong>' . e(t('admin.theme.layout.main_page_grid_legend', 'Main page gallery grid')) . '</strong> <i data-theme-preview-home-grid-state>' . $homeGridColumns . ' × ' . $homeGridRows . '</i></span>';
    echo '<span><strong>' . e(t('admin.theme.appearance.tag_page_legend', 'Public tag page layout')) . '</strong> <i data-theme-preview-tag-grid-state>' . $tagGridColumns . ' × ' . $tagGridRows . '</i></span>';
    echo '<span><strong>' . e(t('admin.theme.layout.lightbox_mode_legend', 'Public lightbox browsing mode')) . '</strong> <i data-theme-preview-lightbox-state>' . e(str_replace('_', ' ', $lightboxMode)) . '</i></span>';
    echo '<span><strong>' . e(t('admin.theme.layout.thumbnail_rendering_legend', 'Public thumbnail rendering')) . '</strong> <i data-theme-preview-thumbnail-state>' . e(str_replace('_', ' ', $thumbnailMode)) . '</i></span>';
    echo '</div></div>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.preview_hint', 'Preview updates while editing. It is intentionally small, but uses the same colors, font mode, corner radius, and background transparency controls as the public theme.')) . '</p></aside>';
}

/**
 * Render the Theme Custom CSS tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared labels, preset options, and safe active stylesheet metadata.
 * @return void Emits explicit stylesheet replacement controls and independently scoped reset actions.
 */
function view_render_admin_theme_custom_css_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $presets = (array) ($viewModel['presets'] ?? []);
    $currentCss = (array) ($viewModel['current_css'] ?? []);
    $errors = (array) ($viewModel['errors'] ?? []);

    ob_start();
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope theme-custom-css-workspace" data-admin-subtab-scope><header class="theme-custom-css-heading"><h2>' . e((string) ($labels['kicker'] ?? 'Custom CSS')) . '</h2><p>' . e(t('admin.theme.custom_css.compact_hint', 'Keep your current stylesheet, or explicitly replace it with a preset or file.')) . '</p></header>';
    render_admin_subtabs([
        ['id' => 'admin-theme-css-subtab-source', 'label' => (string) ($labels['subtab_source'] ?? 'CSS source')],
        ['id' => 'admin-theme-css-subtab-reset', 'label' => (string) ($labels['subtab_reset'] ?? 'Reset actions')],
    ], 'admin-theme-css-subtab-source', (string) ($labels['subtabs_label'] ?? 'Custom CSS subsections'));

    ob_start();
    foreach ($errors as $error) {
        echo '<p class="notice error theme-custom-css-error" role="alert">' . e((string) $error) . '</p>';
    }
    echo '<section class="theme-custom-css-status" id="admin-custom-css"><div><h3>' . e(t('admin.theme.custom_css.current_legend', 'Current stylesheet')) . '</h3><strong>' . e((string) ($currentCss['status_label'] ?? '')) . '</strong>';
    $metadata = [];
    foreach (['preset_label', 'size_label', 'modified_label'] as $field) {
        $value = trim((string) ($currentCss[$field] ?? ''));
        if ($value !== '') {
            $metadata[] = $value;
        }
    }
    if ($metadata !== []) {
        echo '<p class="theme-custom-css-hint">' . e(implode(' · ', $metadata)) . '</p>';
    }
    echo '</div>';
    $publicUrl = trim((string) ($currentCss['public_url'] ?? ''));
    if ($publicUrl !== '') {
        echo '<a class="button secondary" href="' . e($publicUrl) . '" target="_blank" rel="noopener">' . e(t('admin.theme.custom_css.view_stylesheet', 'View stylesheet')) . '</a>';
    }
    echo '</section><div class="theme-custom-css-sources">';
    echo '<fieldset class="theme-custom-css-card"><legend>' . e(t('admin.theme.custom_css.preset_legend', 'Preset')) . '</legend><label class="admin-visually-hidden" for="theme-custom-css-preset">' . e((string) ($labels['skin_label'] ?? 'Custom CSS skin')) . '</label><select id="theme-custom-css-preset" name="custom_css_preset"><option value="" selected>' . e((string) ($labels['keep_current'] ?? 'Keep current custom CSS')) . '</option>';
    foreach ($presets as $preset) {
        if (!is_array($preset)) {
            continue;
        }
        $filename = (string) ($preset['filename'] ?? '');
        if ($filename === '') {
            continue;
        }
        echo '<option value="' . e($filename) . '">' . e((string) ($preset['label'] ?? $filename)) . '</option>';
    }
    echo '</select><p class="theme-custom-css-hint">' . e(t('admin.theme.custom_css.preset_action_hint', 'Choose a preset only when you want to replace the current stylesheet.')) . '</p></fieldset>';
    echo '<fieldset class="theme-custom-css-card"><legend>' . e((string) ($labels['file_label'] ?? 'Custom CSS file')) . '</legend><label class="admin-visually-hidden" for="theme-custom-css-file">' . e((string) ($labels['file_label'] ?? 'Custom CSS file')) . '</label><input id="theme-custom-css-file" type="file" name="custom_css" accept=".css,text/css"><p class="theme-custom-css-hint">' . e(t('admin.theme.custom_css.upload_action_hint', 'A CSS upload replaces the current stylesheet when you save Theme.')) . '</p></fieldset></div>';
    echo '<p class="theme-custom-css-hint theme-custom-css-priority">' . e(t('admin.theme.custom_css.upload_priority_hint', 'If you upload a file and select a preset, the uploaded file takes priority.')) . '</p>';
    echo '<details class="theme-custom-css-details"><summary>' . e(t('admin.theme.custom_css.load_order_title', 'How styles are applied')) . '</summary><p>' . e(t('admin.theme.custom_css.load_order_hint', 'Built-in styles load first, then custom CSS, then saved Theme overrides. Applies to public pages and administration wherever selectors match.')) . '</p></details>';
    $customCssSourceHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-css-subtab-source', $customCssSourceHtml, true);

    ob_start();
    echo '<div class="theme-custom-css-resets"><section class="theme-custom-css-reset-row"><div><h3>' . e(t('admin.theme.custom_css.reset_appearance_title', 'Clear saved appearance overrides')) . '</h3><p class="theme-custom-css-hint">' . e(t('admin.theme.custom_css.reset_appearance_hint', 'Clears saved color, font, radius, background and map-pin overrides. Keeps the stylesheet and page width.')) . '</p></div><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e((string) ($labels['reset_to_css'] ?? 'Reset to CSS')) . '</button></section>';
    echo '<section class="theme-custom-css-reset-row"><div><h3>' . e(t('admin.theme.custom_css.reset_stylesheet_title', 'Remove custom stylesheet')) . '</h3><p class="theme-custom-css-hint">' . e(t('admin.theme.custom_css.reset_stylesheet_hint', 'Removes the active CSS file and preset selection. Keeps saved Theme preferences.')) . '</p></div><button type="submit" class="secondary" name="reset_custom_css" value="1" formnovalidate>' . e((string) ($labels['reset_custom_css'] ?? 'Reset custom CSS')) . '</button></section></div>';
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
 * @return void Emits compact shortcut, card, grid, lightbox, and reset groups with canonical controls.
 */
function view_render_admin_theme_layout_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $favoriteShortcuts = (array) ($viewModel['favorite_shortcuts'] ?? []);
    $pagination = (array) ($viewModel['pagination'] ?? []);
    $homeGrid = (array) ($viewModel['home_grid'] ?? []);
    $lightboxOptions = (array) ($viewModel['lightbox_options'] ?? []);
    $thumbnailModes = (array) ($viewModel['thumbnail_modes'] ?? []);

    ob_start();
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope theme-layout-workspace" data-admin-subtab-scope><header class="theme-layout-heading"><h2>' . e((string) ($labels['compact_title'] ?? 'Layout')) . '</h2><p>' . e((string) ($labels['compact_hint'] ?? 'Set header shortcuts, card details and default grids.')) . '</p></header>';
    render_admin_subtabs([
        ['id' => 'admin-theme-layout-subtab-shortcuts', 'label' => (string) ($labels['subtab_shortcuts'] ?? 'Header shortcuts')],
        ['id' => 'admin-theme-layout-subtab-cards', 'label' => (string) ($labels['subtab_cards'] ?? 'Cards & badges')],
        ['id' => 'admin-theme-layout-subtab-grids', 'label' => (string) ($labels['subtab_grids'] ?? 'Grids & lightbox')],
    ], 'admin-theme-layout-subtab-shortcuts', (string) ($labels['subtabs_label'] ?? 'Layout subsections'));

    ob_start();
    echo '<div class="theme-layout-grid">';
    echo '<fieldset class="theme-layout-card theme-layout-shortcuts-card admin-theme-favorite-galleries" id="admin-theme-favorite-galleries"><legend>' . e((string) ($labels['favorite_galleries_legend'] ?? 'Favorite gallery shortcuts')) . '</legend>';
    echo '<div class="theme-layout-card-heading"><p class="theme-layout-hint">' . e((string) ($labels['shortcuts_compact_hint'] ?? 'Choose up to three header shortcuts. Gallery targets use the picker.')) . '</p>';
    echo view_admin_theme_appearance_help((string) ($labels['favorite_galleries_legend'] ?? 'Favorite gallery shortcuts'), trim((string) ($labels['favorite_galleries_hint'] ?? '') . ' ' . (string) ($labels['favorite_gallery_gallery_hint'] ?? '') . ' ' . (string) ($labels['favorite_galleries_visibility_hint'] ?? ''))) . '</div>';
    echo '<div class="admin-theme-favorite-gallery-list theme-layout-shortcuts">';
    foreach ($favoriteShortcuts as $shortcutIndex => $shortcut) {
        if (!is_array($shortcut)) {
            continue;
        }
        $selectedType = (string) ($shortcut['selected_type'] ?? '');
        echo '<div class="admin-theme-favorite-gallery-slot theme-layout-shortcut"><strong>' . e((string) ($shortcut['slot_label'] ?? '')) . '</strong>';
        echo '<label><span class="theme-layout-shortcut-target-label">' . e((string) ($labels['favorite_gallery_type'] ?? 'Shortcut target')) . '</span><select name="theme_favorite_gallery_types[]" aria-label="' . e((string) ($shortcut['slot_label'] ?? '') . ': ' . (string) ($labels['favorite_gallery_type'] ?? 'Shortcut target')) . '">';
        echo '<option value=""' . ($selectedType === '' ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_empty'] ?? 'No shortcut')) . '</option>';
        echo '<option value="' . e((string) ($viewModel['home_token'] ?? 'home')) . '"' . ($selectedType === (string) ($viewModel['home_token'] ?? 'home') ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_home'] ?? 'Main page')) . '</option>';
        echo '<option value="gallery"' . ($selectedType === 'gallery' ? ' selected' : '') . '>' . e((string) ($labels['favorite_gallery_gallery'] ?? 'Gallery')) . '</option>';
        echo '</select></label>';
        $pickerId = 'theme-favorite-gallery-' . ((int) $shortcutIndex + 1);
        echo '<div class="theme-layout-picker"><label class="theme-layout-shortcut-picker-label" for="' . e($pickerId) . '">' . e((string) ($labels['favorite_gallery_gallery'] ?? 'Gallery')) . '</label>';
        $pickerHtml = (string) ($shortcut['picker_html'] ?? '');
        if ($pickerHtml !== '') {
            echo $pickerHtml;
        } else {
            echo '<select id="' . e($pickerId) . '" name="theme_favorite_gallery_ids[]"><option value="">' . e((string) ($labels['favorite_gallery_empty'] ?? 'No shortcut')) . '</option>' . (string) ($shortcut['fallback_options_html'] ?? '') . '</select>';
        }
        echo '</div></div>';
    }
    echo '</div>';
    echo '</fieldset>';
    echo '</div>';
    $layoutShortcutsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-shortcuts', $layoutShortcutsHtml, true);

    ob_start();
    echo '<div class="theme-layout-grid">';
    echo '<fieldset class="theme-layout-card" id="admin-gallery-count-badge"><legend>' . e((string) ($labels['count_badge_legend'] ?? 'Contained-picture badge')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_gallery_count_badge_enabled" value="1"' . (!empty($viewModel['gallery_count_badge_enabled']) ? ' checked' : '') . '> ' . e((string) ($labels['show_count_badge'] ?? '')) . '</label>';
    echo '<p class="theme-layout-hint">' . e((string) ($labels['count_badge_hint'] ?? '')) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="theme-layout-card" id="admin-public-thumbnail-rendering"><legend>' . e((string) ($labels['thumbnail_rendering_legend'] ?? 'Public thumbnail rendering')) . '</legend>';
    echo '<label>' . e((string) ($labels['thumbnail_rendering_label'] ?? 'Selected-gallery photo cards')) . '<select name="public_thumbnail_rendering_mode" aria-describedby="admin-public-thumbnail-rendering-help admin-public-thumbnail-rendering-transfer">';
    foreach ($thumbnailModes as $mode) {
        if (!is_array($mode)) {
            continue;
        }
        echo '<option value="' . e((string) ($mode['value'] ?? '')) . '"' . (!empty($mode['selected']) ? ' selected' : '') . '>' . e((string) ($mode['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';
    echo '<p class="theme-layout-hint">' . e((string) ($labels['thumbnail_rendering_scope_note'] ?? '')) . '</p><details class="theme-layout-details"><summary>' . e((string) ($labels['renderer_details'] ?? 'Rendering details')) . '</summary>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-help"><strong>' . e((string) ($labels['thumbnail_rendering_responsive_title'] ?? '')) . '</strong> ' . e((string) ($labels['thumbnail_rendering_responsive_help'] ?? '')) . '</p>';
    echo '<p class="muted"><strong>' . e((string) ($labels['thumbnail_rendering_progressive_title'] ?? '')) . '</strong> ' . e((string) ($labels['thumbnail_rendering_progressive_help'] ?? '')) . '</p>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-transfer">' . e((string) ($labels['thumbnail_rendering_transfer_note'] ?? '')) . '</p>';
    echo '</details>';
    echo '</fieldset>';
    echo '</div>';
    $layoutCardsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-cards', $layoutCardsHtml, false);

    ob_start();
    echo '<div class="theme-layout-grid">';
    echo '<fieldset class="theme-layout-card" id="admin-pagination"><legend>' . e((string) ($labels['pagination_legend'] ?? 'Pagination')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="pagination_enabled" value="1"' . (!empty($pagination['enabled']) ? ' checked' : '') . '> ' . e((string) ($labels['enable_pagination'] ?? 'Enable pagination')) . '</label>';
    echo '<div class="theme-layout-slider-row"><label for="theme-layout-pagination-columns">' . e((string) ($labels['columns_per_page'] ?? 'Columns per page')) . '</label><input id="theme-layout-pagination-columns" type="range" name="pagination_columns" min="1" max="' . (int) ($viewModel['max_columns'] ?? 1) . '" value="' . (int) ($pagination['columns'] ?? 0) . '" data-pagination-columns><output for="theme-layout-pagination-columns" data-pagination-columns-display>' . (int) ($pagination['columns'] ?? 0) . '</output></div>';
    echo '<div class="theme-layout-slider-row"><label for="theme-layout-pagination-rows">' . e((string) ($labels['rows_per_page'] ?? 'Rows per page')) . '</label><input id="theme-layout-pagination-rows" type="range" name="pagination_rows" min="1" max="' . (int) ($viewModel['max_rows'] ?? 1) . '" value="' . (int) ($pagination['rows'] ?? 0) . '" data-pagination-rows><output for="theme-layout-pagination-rows" data-pagination-rows-display>' . (int) ($pagination['rows'] ?? 0) . '</output></div>';
    echo '<p class="theme-layout-count">' . e((string) ($labels['items_per_page_preview'] ?? 'Items per page preview:')) . ' <strong data-pagination-items-preview>' . (int) ($pagination['items_per_page'] ?? 0) . '</strong></p>';
    echo '<p class="theme-layout-hint">' . e((string) ($labels['pagination_hint'] ?? '')) . '</p>';
    echo '</fieldset>';

    echo '<fieldset class="theme-layout-card" id="admin-home-grid"><legend>' . e((string) ($labels['main_page_grid_legend'] ?? 'Main page gallery grid')) . '</legend>';
    echo '<div class="theme-layout-card-heading"><p class="theme-layout-hint">' . e((string) ($labels['home_grid_compact_hint'] ?? 'Only the main page; gallery-page grids are configured separately.')) . '</p>' . view_admin_theme_appearance_help((string) ($labels['main_page_grid_legend'] ?? 'Main page gallery grid'), (string) ($labels['main_page_grid_hint'] ?? '')) . '</div>';
    echo '<div class="theme-layout-slider-row"><label for="theme-layout-home-columns">' . e((string) ($labels['main_page_columns'] ?? 'Main page columns')) . '</label><input id="theme-layout-home-columns" type="range" name="home_gallery_grid_columns" min="1" max="' . (int) ($viewModel['max_columns'] ?? 1) . '" value="' . (int) ($homeGrid['columns'] ?? 0) . '" data-home-grid-columns><output for="theme-layout-home-columns" data-home-grid-columns-display>' . (int) ($homeGrid['columns'] ?? 0) . '</output></div>';
    echo '<div class="theme-layout-slider-row"><label for="theme-layout-home-rows">' . e((string) ($labels['main_page_rows'] ?? 'Main page rows')) . '</label><input id="theme-layout-home-rows" type="range" name="home_gallery_grid_rows" min="1" max="' . (int) ($viewModel['max_rows'] ?? 1) . '" value="' . (int) ($homeGrid['rows'] ?? 0) . '" data-home-grid-rows><output for="theme-layout-home-rows" data-home-grid-rows-display>' . (int) ($homeGrid['rows'] ?? 0) . '</output></div>';
    echo '<p class="theme-layout-count">' . e((string) ($labels['items_per_page_preview'] ?? 'Items per page preview:')) . ' <strong data-home-grid-items-preview>' . ((int) ($homeGrid['columns'] ?? 0) * (int) ($homeGrid['rows'] ?? 0)) . '</strong></p>';
    echo '</fieldset>';
    if (!empty($viewModel['lightbox_modes_enabled'])) {
        echo '<fieldset class="theme-layout-card theme-layout-lightbox" id="admin-lightbox-mode"><legend>' . e((string) ($labels['lightbox_mode_legend'] ?? 'Public lightbox browsing mode')) . '</legend>';
        echo '<div class="theme-layout-control-row"><div class="theme-layout-label"><label for="theme-layout-lightbox-mode">' . e((string) ($labels['lightbox_mode_label'] ?? 'Default browsing mode')) . '</label>' . view_admin_theme_appearance_help((string) ($labels['lightbox_mode_legend'] ?? 'Public lightbox browsing mode'), (string) ($labels['lightbox_mode_hint'] ?? '')) . '</div><select id="theme-layout-lightbox-mode" name="theme_lightbox_browsing_mode">';
        foreach ($lightboxOptions as $option) {
            if (!is_array($option)) {
                continue;
            }
            echo '<option value="' . e((string) ($option['value'] ?? '')) . '"' . (!empty($option['selected']) ? ' selected' : '') . '>' . e((string) ($option['label'] ?? '')) . '</option>';
        }
        echo '</select></div><p class="theme-layout-hint">' . e((string) ($labels['lightbox_compact_hint'] ?? 'Default for the photo viewer; individual galleries may override it.')) . '</p>';
        echo '</fieldset>';
    }

    echo '</div>';
    echo '<details class="theme-layout-details theme-layout-reset"><summary>' . e((string) ($labels['reset_grid_details'] ?? 'Reset individual gallery grids')) . '</summary>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_grid_overrides" value="1" formnovalidate onclick="return confirm(&quot;' . e((string) ($labels['reset_gallery_grids_confirm'] ?? '')) . '&quot;);">' . e((string) ($labels['reset_all_gallery_grids'] ?? 'Reset all custom gallery grids')) . '</button></div>';
    echo '<p class="muted">' . e((string) ($labels['reset_gallery_grids_hint'] ?? '')) . '</p>';
    echo '</details>';
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
 * @return void Emits the compact Media panel inside the shared Theme form.
 */
function view_render_admin_theme_media_tab(array $viewModel): void
{
    $labels = (array) ($viewModel['labels'] ?? []);
    $banner = $viewModel['banner'] ?? null;
    $separator = $viewModel['separator'] ?? null;
    $background = (array) ($viewModel['background'] ?? []);
    $backgroundSource = $background['source'] ?? null;

    ob_start();
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope theme-media-workspace" data-admin-subtab-scope><header class="theme-media-heading"><h2>' . e((string) ($labels['compact_title'] ?? 'Site images')) . '</h2><p>' . e((string) ($labels['compact_hint'] ?? 'Manage your header, browser icon and background.')) . '</p></header>';
    render_admin_subtabs([
        ['id' => 'admin-theme-media-subtab-header', 'label' => (string) ($labels['subtab_header'] ?? 'Header images')],
        ['id' => 'admin-theme-media-subtab-favicon', 'label' => (string) ($labels['subtab_favicon'] ?? 'Browser icon')],
        ['id' => 'admin-theme-media-subtab-background', 'label' => (string) ($labels['subtab_background'] ?? 'Background')],
    ], 'admin-theme-media-subtab-header', (string) ($labels['subtabs_label'] ?? 'Branding and media subsections'));

    ob_start();
    echo '<div class="theme-media-header-grid">';
    foreach (['banner' => $banner, 'separator' => $separator] as $kind => $asset) {
        if (!is_array($asset)) continue;
        $title = (string) ($labels['public_header_' . $kind] ?? $asset['label'] ?? '');
        $assetUrl = (string) ($asset['asset_url'] ?? '');
        $uploadId = 'theme-media-upload-' . $kind;
        echo '<fieldset class="theme-media-card admin-theme-branding-assets" id="admin-theme-branding-' . e($kind) . '"><legend>' . e($title) . '</legend><div class="theme-media-asset"><div class="theme-media-asset-preview">';
        if ($assetUrl !== '') {
            echo '<img class="admin-branding-preview admin-theme-branding-preview-' . e($kind) . '" src="' . e($assetUrl) . '" alt="' . e((string) ($asset['current_alt'] ?? '')) . '">';
        } else {
            echo '<p class="muted">' . e((string) ($labels['no_fallback_image'] ?? 'No fallback image is stored yet.')) . '</p>';
        }
        echo '</div><div class="theme-media-asset-controls">';
        echo '<p class="theme-media-hint">' . e((string) ($labels['public_header_' . $kind . '_compact_hint'] ?? '')) . '</p>';
        echo view_admin_theme_appearance_help($title, trim((string) ($labels['public_header_' . $kind . '_hint'] ?? '') . ' ' . (string) ($asset['description'] ?? '')));
        echo '<label for="' . e($uploadId) . '">' . e((string) ($labels['upload_replacement'] ?? 'Upload replacement')) . '</label><input id="' . e($uploadId) . '" type="file" name="theme_branding_' . e($kind) . '" accept="image/png,image/jpeg,image/gif,image/webp"><p class="theme-media-hint">' . e((string) ($labels['accepted_formats'] ?? '')) . '</p>';
        if ($assetUrl !== '') {
            echo '<div class="theme-media-actions"><button type="submit" class="secondary" name="reset_theme_branding_' . e($kind) . '" value="1" formnovalidate aria-label="' . e((string) ($asset['remove_label'] ?? '')) . '" title="' . e((string) ($asset['remove_label'] ?? '')) . '">' . e((string) ($labels['remove_image'] ?? 'Remove image')) . '</button></div>';
        }
        echo '</div></div>';
        if ($kind === 'separator') {
            echo '<div class="theme-media-dimensions"><div class="theme-media-control-row"><label for="theme-media-separator-width">' . e((string) ($labels['separator_width'] ?? 'Separator width')) . '</label><input id="theme-media-separator-width" type="number" name="theme_branding_separator_width" min="0" max="3840" step="1" value="' . (int) ($asset['width'] ?? 0) . '"><span>px</span>' . view_admin_theme_appearance_help((string) ($labels['separator_width'] ?? 'Separator width'), (string) ($labels['separator_width_hint'] ?? '')) . '</div>';
            echo '<div class="theme-media-control-row"><label for="theme-media-separator-height">' . e((string) ($labels['separator_height'] ?? 'Separator height')) . '</label><input id="theme-media-separator-height" type="number" name="theme_branding_separator_height" min="8" max="512" step="1" value="' . (int) ($asset['height'] ?? 0) . '"><span>px</span>' . view_admin_theme_appearance_help((string) ($labels['separator_height'] ?? 'Separator height'), (string) ($labels['separator_height_hint'] ?? '')) . '</div>';
            echo '<div class="theme-media-control-row"><label class="checkbox-label"><input type="checkbox" name="theme_branding_separator_stretch" value="1"' . (!empty($asset['stretch']) ? ' checked' : '') . '> ' . e((string) ($labels['separator_stretch'] ?? 'Stretch to exact width and height')) . '</label>' . view_admin_theme_appearance_help((string) ($labels['separator_stretch'] ?? 'Stretch to exact width and height'), (string) ($labels['separator_stretch_hint'] ?? '')) . '</div></div>';
        }
        echo '</fieldset>';
    }
    echo '</div>';
    $mediaHeaderHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-header', $mediaHeaderHtml, true);

    ob_start();
    echo '<fieldset class="theme-media-card" id="admin-favicon"><legend>' . e((string) ($labels['favicon_legend'] ?? 'Favicon')) . '</legend><div class="theme-media-asset"><div class="theme-media-asset-preview">';
    $faviconUrl = (string) ($viewModel['favicon_url'] ?? '');
    if ($faviconUrl !== '') {
        echo '<img width="48" height="48" src="' . e($faviconUrl) . '&s=48&v=' . e((string) ($viewModel['favicon_version'] ?? '1')) . '" alt="' . e((string) ($labels['current_favicon_alt'] ?? 'Current favicon')) . '">';
    } else {
        echo '<p class="muted">' . e((string) ($labels['no_favicon'] ?? '')) . '</p>';
    }
    echo '</div><div class="theme-media-asset-controls"><label for="theme-media-favicon-source">' . e((string) ($labels['favicon_source_image'] ?? 'Favicon source image')) . '</label><input id="theme-media-favicon-source" type="file" name="favicon_source" accept="image/png,image/jpeg,image/gif,image/webp" data-favicon-input>';
    echo view_admin_theme_appearance_help((string) ($labels['favicon_legend'] ?? 'Favicon'), trim((string) ($labels['favicon_source_hint'] ?? '') . ' ' . (string) ($labels['current_favicon_hint'] ?? '')));
    echo '<div class="theme-media-actions"><button type="submit" class="secondary" name="reset_favicon" value="1" formnovalidate>' . e((string) ($labels['remove_favicon'] ?? 'Remove favicon')) . '</button></div></div></div>';
    echo '<input type="hidden" name="favicon_cropped_png" value="" data-favicon-cropped>';
    echo '<div class="favicon-cropper theme-media-favicon-cropper" data-favicon-cropper hidden><div class="favicon-crop-stage"><canvas width="256" height="256" data-favicon-canvas></canvas></div><label>' . e((string) ($labels['zoom'] ?? 'Zoom')) . '<input type="range" min="1" max="3" step="0.01" value="1" data-favicon-zoom></label><div class="favicon-preview-row"><canvas width="48" height="48" data-favicon-preview></canvas><span class="theme-media-hint">' . e((string) ($labels['favicon_crop_hint'] ?? '')) . '</span></div></div></fieldset>';
    $mediaFaviconHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-favicon', $mediaFaviconHtml, false);

    ob_start();
    $themeBackgroundUrl = (string) ($background['asset_url'] ?? '');
    $themeOriginalUrl = (string) ($background['original_url'] ?? '');
    $themeOptimizedActive = !empty($background['optimized_active']);
    $themeHasBackground = !empty($background['has_background']);
    echo '<div class="theme-media-background-grid"><fieldset class="theme-media-card admin-theme-background-card" id="admin-backgrounds"><legend>' . e((string) ($labels['background_legend'] ?? 'Background')) . '</legend><div class="theme-media-asset"><div class="theme-media-asset-preview theme-media-background-preview">';
    if ($themeHasBackground) {
        echo '<a href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener"><img src="' . e($themeBackgroundUrl) . '" alt="' . e((string) ($labels['current_theme_background_alt'] ?? 'Selected background preview')) . '"></a>';
    } else {
        echo '<p class="muted">' . e((string) ($labels['background_not_selected'] ?? 'No background selected')) . '</p>';
    }
    echo '</div><div class="theme-media-asset-controls"><strong>' . e($themeHasBackground ? (string) ($labels['background_selected'] ?? 'Background selected') : (string) ($labels['background_not_selected'] ?? 'No background selected')) . '</strong>';
    if ($themeHasBackground) {
        echo '<span class="admin-theme-background-status' . ($themeOptimizedActive ? ' is-ready' : '') . '">' . e((string) ($labels[$themeOptimizedActive ? 'background_optimized_ready' : 'background_serving_original'] ?? '')) . '</span>';
    } else {
        echo '<span class="theme-media-hint">' . e((string) ($labels['no_theme_background'] ?? '')) . '</span>';
    }
    echo '<label for="theme-media-background-file">' . e((string) ($labels['theme_background_image'] ?? 'Choose background image')) . '</label><input id="theme-media-background-file" type="file" name="theme_background" accept="image/png,image/jpeg,image/gif,image/webp">';
    echo view_admin_theme_appearance_help((string) ($labels['background_legend'] ?? 'Background'), (string) ($labels['theme_background_image_hint'] ?? ''));
    echo '<div class="theme-media-actions"><button type="submit" class="secondary" name="reset_theme_background" value="1" formnovalidate>' . e((string) ($labels['remove_theme_background'] ?? 'Remove theme background')) . '</button></div></div></div>';
    echo '<div class="theme-media-slider-row"><label for="theme-media-background-opacity">' . e((string) ($labels['background_transparency'] ?? 'Background visibility')) . '</label><input id="theme-media-background-opacity" type="range" name="theme_background_opacity" min="0" max="100" value="' . (int) ($background['opacity'] ?? 65) . '" data-theme-override-control data-theme-background-opacity><output for="theme-media-background-opacity" data-theme-background-opacity-display>' . (int) ($background['opacity'] ?? 65) . '%</output></div><p class="theme-media-hint">' . e((string) ($labels['background_transparency_hint'] ?? '')) . '</p></fieldset>';
    echo '<fieldset class="theme-media-card theme-media-optimization"><legend>' . e((string) ($labels['optimization_legend'] ?? 'Background optimization')) . '</legend><div class="theme-media-slider-row"><div class="theme-media-slider-label"><label for="theme-media-background-size">' . e((string) ($labels['background_optimized_size'] ?? 'Optimized display size')) . '</label>' . view_admin_theme_appearance_help((string) ($labels['background_optimized_size'] ?? 'Optimized display size'), (string) ($labels['background_optimized_size_hint'] ?? '')) . '</div><input id="theme-media-background-size" type="range" name="theme_background_optimized_max_side" min="1024" max="3840" step="128" value="' . (int) ($background['optimized_max_side'] ?? 1920) . '" data-theme-background-optimized-size><output for="theme-media-background-size" data-theme-background-optimized-size-display data-theme-background-optimized-size-template="' . e((string) ($labels['background_optimized_size_template'] ?? '{size}px longest side')) . '">' . e((string) ($background['optimized_size_label'] ?? '')) . '</output></div>';

    echo '<p class="theme-media-hint">' . e((string) ($labels['optimization_saved_hint'] ?? 'Save a new image before regenerating its optimized copy.')) . '</p><div class="theme-media-actions admin-theme-background-actions"><button type="submit" class="secondary" name="generate_theme_background_optimized" value="1" formnovalidate' . (!$themeHasBackground ? ' disabled' : '') . '>' . e($themeOptimizedActive ? (string) ($labels['regenerate_optimized_background'] ?? 'Regenerate optimized background') : (string) ($labels['generate_optimized_background'] ?? 'Generate optimized background')) . '</button><button type="submit" class="secondary" name="delete_theme_background_optimized" value="1" formnovalidate' . (!$themeOptimizedActive ? ' disabled' : '') . '>' . e((string) ($labels['delete_optimized_background'] ?? 'Delete optimized copy')) . '</button></div><div class="theme-media-actions">';
    if ($themeHasBackground) {
        echo '<a class="button secondary" href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener">' . e((string) ($labels['view_served_image'] ?? 'View used image')) . '</a>';
    }
    if ($themeOriginalUrl !== '') {
        echo '<a class="button secondary" href="' . e($themeOriginalUrl) . '" target="_blank" rel="noopener">' . e((string) ($labels['view_original_image'] ?? 'View original')) . '</a>';
    }
    echo '</div></fieldset></div><fieldset class="theme-media-card theme-media-fallback"><legend>' . e((string) ($labels['gallery_background_fallback'] ?? 'Gallery background fallback')) . '</legend><div class="theme-media-control-row"><label for="theme-media-background-source">' . e((string) ($labels['background_default_source'] ?? 'Default source')) . '</label><select id="theme-media-background-source" name="theme_background_source" data-theme-override-control><option value=""' . ($backgroundSource === null ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_none'] ?? 'No fallback set')) . '</option><option value="upload"' . ($backgroundSource === 'upload' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_upload'] ?? 'Uploaded gallery cover')) . '</option><option value="existing"' . ($backgroundSource === 'existing' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_existing'] ?? 'Gallery cover photo')) . '</option><option value="collage"' . ($backgroundSource === 'collage' ? ' selected' : '') . '>' . e((string) ($labels['background_fallback_collage'] ?? 'Gallery collage photo')) . '</option></select></div><p class="theme-media-hint">' . e((string) ($labels['gallery_background_fallback_hint'] ?? '')) . '</p>';
    echo '<details class="theme-media-details"><summary>' . e((string) ($labels['maintenance_title'] ?? 'Maintenance')) . '</summary><p class="theme-media-hint">' . e((string) ($labels['reset_backgrounds_hint'] ?? 'Clear individual background choices for every gallery so they follow the Theme fallback.')) . '</p><button type="submit" class="secondary" name="reset_all_gallery_backgrounds" value="1" formnovalidate>' . e((string) ($labels['reset_all_gallery_backgrounds'] ?? 'Reset all gallery backgrounds')) . '</button></details></fieldset>';
    $mediaBackgroundHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-background', $mediaBackgroundHtml, false);
    echo '</div>';
    $mediaHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-media', $mediaHtml, false);
}

/**
 * Render keyboard-accessible supporting copy without expanding every appearance row.
 *
 * @param string $label Specific preference name used to identify its help affordance.
 * @param string $hint Translated explanatory text without trusted markup.
 * @return string Escaped native disclosure markup.
 */
function view_admin_theme_appearance_help(string $label, string $hint): string
{
    return '<details class="theme-appearance-help"><summary aria-label="' . e(t('admin.theme.appearance.control_help', 'About {label}', ['label' => $label])) . '"><span aria-hidden="true">?</span></summary><p>' . e($hint) . '</p></details>';
}

/**
 * Render one canonical named color swatch and its unnamed editable HEX companion.
 *
 * @param string $key Controller-prepared theme color identity, used only for stable IDs and preview hooks.
 * @param string $name Existing canonical POST field name.
 * @param string $label Translated preference label.
 * @param string $hint Translated supporting explanation.
 * @param string $value Prepared validated six-digit color including its leading hash.
 * @return void Emits a compact color row with one submitted value.
 */
function view_render_admin_theme_appearance_color_row(string $key, string $name, string $label, string $hint, string $value): void
{
    $id = 'theme-appearance-color-' . $key;
    $value = strtolower($value);
    echo '<div class="theme-color-row" data-theme-color-row><label for="' . e($id) . '">' . e($label) . '</label>';
    echo view_admin_theme_appearance_help($label, $hint);
    echo '<input id="' . e($id) . '" type="color" name="' . e($name) . '" value="' . e($value) . '" data-theme-override-control data-theme-preview-color="' . e($key) . '">';
    // HEX editing becomes available only after JavaScript can synchronize the submitted swatch.
    echo '<input id="' . e($id . '-hex') . '" type="text" value="' . e($value) . '" data-theme-color-hex hidden aria-label="' . e($label . ' HEX') . '" pattern="#?[0-9a-fA-F]{6}" maxlength="7" required spellcheck="false" autocomplete="off" data-theme-color-invalid-message="' . e(t('admin.theme.appearance.hex_invalid', 'Enter a six-digit HEX color, for example #2563eb.')) . '"></div>';
}

/**
 * Render a compact card-layout preference using prepared canonical option labels.
 *
 * @param string $name Existing POST field identifying the global or tag-page preference.
 * @param string $label Translated label describing the preference scope.
 * @param string $hint Short translated explanation of its override behavior.
 * @param string $selected Prepared saved layout value.
 * @param list<array{value:string,label:string}> $options Canonical prepared layout choices.
 * @param bool $global Whether this is the global layout control with its historical selection hook.
 * @return void Emits one named select and its associated visible scope description.
 */
function view_render_admin_theme_appearance_card_layout_row(string $name, string $label, string $hint, string $selected, array $options, bool $global): void
{
    $id = 'theme-appearance-' . str_replace('_', '-', $name);
    echo '<div class="theme-appearance-control-row theme-appearance-card-layout-row"><div class="theme-appearance-control-copy"><label for="' . e($id) . '">' . e($label) . '</label><p>' . e($hint) . '</p></div><select id="' . e($id) . '" name="' . e($name) . '"' . ($global ? ' data-theme-description-layout-select data-theme-preview-description-layout' : ' data-theme-preview-tag-description-layout') . '>';
    foreach ($options as $option) {
        echo '<option value="' . e($option['value']) . '"' . ($selected === $option['value'] ? ' selected' : '') . '>' . e($option['label']) . '</option>';
    }
    echo '</select></div>';
}

/**
 * Render the compact three-section Appearance editor with one shared live preview.
 *
 * @param array<string, mixed> $viewModel Controller-prepared appearance state.
 * @return void
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
    $globalDescriptionLayout = (string) ($viewModel['theme_gallery_description_layout'] ?? $viewModel['preview']['gallery_description_layout'] ?? 'vertical');
    $descriptionLayouts = (array) ($viewModel['description_layouts'] ?? []);
    $heroTagVisibleLimit = (int) ($viewModel['hero_tag_visible_limit'] ?? 20);
    $heroTagDisplayAll = !empty($viewModel['hero_tag_display_all']);
    $heroTagScrollbarEnabled = !empty($viewModel['hero_tag_scrollbar_enabled']);
    $heroTagScrollbarRows = (int) ($viewModel['hero_tag_scrollbar_rows'] ?? 5);
    $heroTagSortMode = (string) ($viewModel['hero_tag_sort_mode'] ?? 'usage');
    $galleryInfoMotionMs = (int) ($viewModel['gallery_info_motion_ms'] ?? 320);
    $adminSidePanelMotionMs = (int) ($viewModel['admin_side_panel_motion_ms'] ?? 260);
    $siteName = (string) ($viewModel['site_name'] ?? '');
    $adminTagsUrl = (string) ($viewModel['admin_tags_url'] ?? '');
    $appearanceSubtab = (string) ($viewModel['active_subtab'] ?? 'admin-theme-appearance-subtab-colors');
    $maxColumns = (int) ($viewModel['max_columns'] ?? 1);
    $maxRows = (int) ($viewModel['max_rows'] ?? 1);
    if ($appearanceSubtab === 'admin-theme-appearance-subtab-preview') {
        $appearanceSubtab = 'admin-theme-appearance-subtab-colors';
    }
    ob_start();
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope theme-appearance-workspace" data-admin-subtab-scope data-theme-preview-root data-theme-preview-context="' . ($appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags' ? 'tag' : 'home') . '" data-theme-preview-background-url="' . e($themeBackgroundUrl) . '">';
    echo '<header class="theme-appearance-heading"><h2>' . e(t('admin.theme.appearance.title', 'Visual appearance')) . '</h2><p>' . e(t('admin.theme.appearance.compact_description', 'Adjust your site’s colors, proportions and gallery tags.')) . '</p></header>';
    echo '<div class="theme-appearance-settings" id="admin-theme-appearance-settings">';
    render_admin_subtabs([
        ['id' => 'admin-theme-appearance-subtab-colors', 'label' => t('admin.theme.subtab_colors_identity', 'Colors & identity')],
        ['id' => 'admin-theme-appearance-subtab-width-map', 'label' => t('admin.theme.subtab_width_map', 'Width & map pin')],
        ['id' => 'admin-theme-appearance-subtab-gallery-tags', 'label' => t('admin.theme.subtab_cards_tags', 'Cards & tags')],
        ['id' => 'admin-theme-appearance-subtab-animations', 'label' => t('admin.theme.subtab_animations', 'Animations')],
    ], $appearanceSubtab, t('admin.theme.appearance.subtabs_label', 'Appearance subsections'));
    ob_start();
    echo '<fieldset class="theme-appearance-group theme-appearance-identity"><legend>' . e(t('admin.theme.appearance.identity_legend', 'Site identity')) . '</legend>';
    echo '<label class="theme-appearance-site-name">' . e(t('admin.theme.appearance.site_name', 'Site name')) . '<input name="site_name" value="' . e($siteName) . '" maxlength="120" required data-theme-preview-site-name></label>';
    echo '<label>' . e(t('admin.theme.appearance.font_style', 'Font style')) . '<select name="theme_font" data-theme-override-control data-theme-preview-font><option value="serif"' . ($theme['font'] === 'serif' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.font_serif', 'Classic serif')) . '</option><option value="sans"' . ($theme['font'] === 'sans' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.font_sans', 'Clean sans-serif')) . '</option></select></label>';
    echo '<div class="theme-appearance-slider-row"><label for="theme-appearance-radius">' . e(t('admin.theme.appearance.rounded_corners', 'Rounded corners')) . '</label><input id="theme-appearance-radius" type="range" name="theme_radius" min="0" max="32" value="' . (int) $theme['radius'] . '" data-theme-override-control data-theme-preview-radius><output for="theme-appearance-radius" data-theme-radius-display>' . (int) $theme['radius'] . 'px</output></div></fieldset>';
    $palettes = [
        ['label' => t('admin.theme.appearance.palette_accents', 'Accents'), 'colors' => [
            ['accent', 'theme_accent', t('admin.theme.appearance.accent_color', 'Accent color'), t('admin.theme.appearance.accent_color_hint', 'Buttons, selected pagination, and important links.')],
            ['accent_dark', 'theme_accent_dark', t('admin.theme.appearance.dark_accent', 'Dark accent'), t('admin.theme.appearance.dark_accent_hint', 'Hover states, outlines, and secondary actions.')],
        ]],
        ['label' => t('admin.theme.appearance.palette_backgrounds', 'Backgrounds'), 'colors' => [
            ['paper', 'theme_paper', t('admin.theme.appearance.page_background', 'Page background'), t('admin.theme.appearance.page_background_hint', 'The base page tone behind all content.')],
            ['panel', 'theme_panel', t('admin.theme.appearance.panel_background', 'Panel background'), t('admin.theme.appearance.panel_background_hint', 'Cards, panels, and normal gallery tiles.')],
            ['gallery_panel', 'theme_gallery_panel', t('admin.theme.appearance.open_gallery_panel', 'Open gallery panel'), t('admin.theme.appearance.open_gallery_panel_hint', 'Gallery-specific cards and image panels.')],
        ]],
        ['label' => t('admin.theme.appearance.palette_text', 'Text'), 'colors' => [
            ['header_text', 'theme_header_text', t('admin.theme.appearance.header_title_color', 'Header title color'), t('admin.theme.appearance.header_title_color_hint', 'Main site title in the public header.')],
            ['hero_text', 'theme_hero_text', t('admin.theme.appearance.gallery_title_color', 'Gallery title color'), t('admin.theme.appearance.gallery_title_color_hint', 'Open gallery title and hero text.')],
        ]],
    ];
    echo '<div class="theme-appearance-palettes">';
    foreach ($palettes as $palette) {
        echo '<fieldset class="theme-appearance-group theme-appearance-palette"><legend>' . e($palette['label']) . '</legend>';
        foreach ($palette['colors'] as $color) {
            view_render_admin_theme_appearance_color_row($color[0], $color[1], $color[2], $color[3], (string) ($theme[$color[0]] ?? '#000000'));
        }
        echo '</fieldset>';
    }
    echo '</div>';
    $appearanceColorsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-colors', $appearanceColorsHtml, $appearanceSubtab === 'admin-theme-appearance-subtab-colors');

    ob_start();
    echo '<fieldset class="theme-appearance-group theme-appearance-width-settings"><legend>' . e(t('admin.theme.appearance.page_width', 'Page width')) . '</legend>';
    $pageWidthLabel = t('admin.theme.appearance.page_width', 'Page width');
    echo '<div class="theme-appearance-control-row"><label for="theme-appearance-page-width">' . e($pageWidthLabel) . '</label>' . view_admin_theme_appearance_help($pageWidthLabel, t('admin.theme.appearance.page_width_hint', 'Controls the public page container. Full width follows the available screen width dynamically.')) . '<select id="theme-appearance-page-width" name="theme_page_width" data-theme-preview-width data-theme-page-width-select>';
    foreach (['default' => t('admin.theme.appearance.page_width_default', 'Default'), 'wide' => t('admin.theme.appearance.page_width_wide', 'Wider'), 'custom' => t('admin.theme.appearance.page_width_custom', 'Custom'), 'full' => t('admin.theme.appearance.page_width_full', 'Full width')] as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($pageWidthMode === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></div><div class="theme-custom-width-control theme-appearance-slider-row" data-theme-custom-width-control' . ($pageWidthMode === 'custom' ? '' : ' hidden') . '><label for="theme-appearance-width-slider">' . e(t('admin.theme.appearance.custom_page_width', 'Custom page width')) . '</label><input id="theme-appearance-width-slider" type="range" name="theme_page_width_custom_slider" min="1024" max="2048" step="1" value="' . $customPageWidth . '" data-theme-custom-width-slider><input type="number" name="theme_page_width_custom" min="1024" max="2048" step="1" value="' . $customPageWidth . '" inputmode="numeric" aria-label="' . e(t('admin.theme.appearance.custom_width_pixels', 'Custom width in pixels')) . '" data-theme-preview-custom-width data-theme-custom-width-number><output for="theme-appearance-width-slider" data-theme-custom-width-display>' . $customPageWidth . 'px</output></div></fieldset>';
    if ($gpsMapsFeatureEnabled) {
        echo '<fieldset class="theme-appearance-group theme-gps-pin-settings"><legend>' . e(t('admin.theme.appearance.gps_pin_legend', 'GPS pin')) . '</legend>';
        echo '<label class="checkbox-label"><input type="checkbox" name="theme_gps_pin_enabled" value="1"' . ($gpsPinEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-enabled> ' . e(t('admin.theme.appearance.show_gps_pin', 'Show GPS pin on photo cards')) . '</label>';
        echo '<label class="checkbox-label"><input type="checkbox" name="theme_gps_pin_background_enabled" value="1"' . ($gpsPinBackgroundEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-background-enabled> ' . e(t('admin.theme.appearance.show_pin_background', 'Show pin background underlay')) . '</label>';
        echo '<div class="theme-appearance-slider-row"><label for="theme-appearance-pin-size">' . e(t('admin.theme.appearance.pin_size', 'Pin size')) . '</label><input id="theme-appearance-pin-size" type="range" name="theme_gps_pin_size" min="14" max="48" step="1" value="' . $gpsPinSize . '" data-theme-override-control data-theme-gps-pin-size><output for="theme-appearance-pin-size" data-theme-gps-pin-size-display>' . $gpsPinSize . 'px</output></div>';
        echo '<div class="theme-appearance-slider-row"><label for="theme-appearance-pin-background-size">' . e(t('admin.theme.appearance.pin_background_size', 'Background size')) . '</label><input id="theme-appearance-pin-background-size" type="range" name="theme_gps_pin_background_size" min="0" max="48" step="1" value="' . $gpsPinBackgroundSize . '" data-theme-override-control data-theme-gps-pin-background-size><output for="theme-appearance-pin-background-size" data-theme-gps-pin-background-size-display>' . $gpsPinBackgroundSize . 'px</output></div>';
        echo '<div class="theme-appearance-inline-actions"><div class="theme-gps-pin-preview" data-theme-gps-pin-preview aria-label="' . e(t('admin.theme.appearance.gps_pin_preview_label', 'GPS pin preview')) . '"><span class="photo-map-pin" data-theme-gps-pin-sample aria-hidden="true">&#128205;</span></div><button type="submit" class="secondary" name="reset_gps_pin_size" value="1" formnovalidate>' . e(t('admin.theme.appearance.reset_pin_size', 'Reset pin size')) . '</button></div></fieldset>';
    }
    $appearanceWidthMapHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-width-map', $appearanceWidthMapHtml, $appearanceSubtab === 'admin-theme-appearance-subtab-width-map');

    ob_start();
    echo '<fieldset class="theme-appearance-group theme-appearance-card-layout-settings" id="admin-gallery-description-layout"><legend>' . e(t('admin.theme.appearance.preview_scope_galleries', 'Gallery cards')) . '</legend>';
    view_render_admin_theme_appearance_card_layout_row(
        'theme_gallery_description_layout',
        t('admin.theme.layout.description_layout_label', 'Default gallery-card layout'),
        t('admin.theme.appearance.default_card_layout_hint', 'Default for gallery cards; individual galleries can override it.'),
        $globalDescriptionLayout,
        $descriptionLayouts,
        true
    );
    view_render_admin_theme_appearance_card_layout_row(
        'tag_page_gallery_description_layout',
        t('admin.theme.appearance.tag_card_layout', 'Tag-page card layout'),
        t('admin.theme.appearance.tag_card_layout_hint', 'Applies only to public tag pages.'),
        $tagPageDescriptionLayout,
        $descriptionLayouts,
        false
    );
    echo '</fieldset>';
    echo '<fieldset class="theme-appearance-group admin-theme-tag-page-settings"><legend>' . e(t('admin.theme.appearance.tag_page_compact_legend', 'Tag-page layout')) . '</legend><p class="theme-appearance-group-hint">' . e(t('admin.theme.appearance.tag_page_compact_hint', 'Choose how galleries appear on public tag pages.')) . '</p>';
    echo '<div class="theme-appearance-slider-row"><label for="theme-appearance-tag-columns">' . e(t('admin.theme.appearance.tag_page_columns', 'Galleries per row')) . '</label><input id="theme-appearance-tag-columns" type="range" name="tag_page_gallery_grid_columns" min="1" max="' . $maxColumns . '" value="' . (int) $tagPageGridSettings['columns'] . '" data-theme-preview-tag-grid-columns><output for="theme-appearance-tag-columns" data-theme-tag-grid-columns-display>' . (int) $tagPageGridSettings['columns'] . '</output></div>';
    echo '<div class="theme-appearance-slider-row"><label for="theme-appearance-tag-rows">' . e(t('admin.theme.appearance.tag_page_rows', 'Rows per page')) . '</label><input id="theme-appearance-tag-rows" type="range" name="tag_page_gallery_grid_rows" min="1" max="' . $maxRows . '" value="' . (int) $tagPageGridSettings['rows'] . '" data-theme-preview-tag-grid-rows><output for="theme-appearance-tag-rows" data-theme-tag-grid-rows-display>' . (int) $tagPageGridSettings['rows'] . '</output></div>';
    $capacityTemplate = t('admin.theme.appearance.tag_page_capacity', 'Tag-page capacity: {count} galleries per page.', ['count' => '{count}']);
    echo '<span class="theme-appearance-capacity" data-theme-tag-grid-capacity data-theme-tag-grid-capacity-template="' . e($capacityTemplate) . '">' . e(t('admin.theme.appearance.tag_page_capacity', 'Tag-page capacity: {count} galleries per page.', ['count' => (int) $tagPageGridSettings['items_per_page']])) . '</span>';
    echo '</fieldset>';
    echo '<fieldset class="theme-appearance-group admin-theme-hero-tag-settings"><legend>' . e(t('admin.theme.appearance.hero_tags_compact_legend', 'Gallery tags')) . '</legend><p class="theme-appearance-group-hint">' . e(t('admin.theme.appearance.hero_tags_compact_hint', 'Applies to gallery cards and the headers of opened galleries.')) . '</p>';
    $tagOrderLabel = t('admin.theme.appearance.hero_tag_sort', 'Tag order');
    echo '<div class="theme-appearance-control-row"><label for="theme-appearance-hero-tag-sort">' . e($tagOrderLabel) . '</label>' . view_admin_theme_appearance_help($tagOrderLabel, t('admin.theme.appearance.hero_tag_sort_hint', 'Usage counts direct gallery and photo assignments across the installation; equal counts are ordered alphabetically.')) . '<select id="theme-appearance-hero-tag-sort" name="theme_hero_tag_sort_mode"><option value="usage"' . ($heroTagSortMode === 'usage' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.hero_tag_sort_usage', 'Most used first')) . '</option><option value="alphabetical"' . ($heroTagSortMode === 'alphabetical' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.hero_tag_sort_alphabetical', 'Alphabetical')) . '</option></select></div>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_hero_tag_display_all" value="1"' . ($heroTagDisplayAll ? ' checked' : '') . ' data-theme-hero-tag-display-all> ' . e(t('admin.theme.appearance.hero_tag_display_all', 'Display every tag immediately')) . '</label>';
    echo '<div class="theme-number-slider-control theme-appearance-slider-row" data-theme-hero-tag-limit-controls' . ($heroTagDisplayAll ? ' hidden' : '') . '><label for="theme-appearance-hero-tag-limit">' . e(t('admin.theme.appearance.hero_tag_visible_limit', 'Tags before “Display all tags”')) . '</label><input id="theme-appearance-hero-tag-limit" type="range" name="theme_hero_tag_visible_limit_slider" min="1" max="200" step="1" value="' . $heroTagVisibleLimit . '" data-theme-hero-tag-limit-slider><input type="number" name="theme_hero_tag_visible_limit" min="1" max="200" step="1" value="' . $heroTagVisibleLimit . '" inputmode="numeric" aria-label="' . e(t('admin.theme.appearance.hero_tag_visible_limit_number', 'Visible tag count')) . '" data-theme-hero-tag-limit-number><output for="theme-appearance-hero-tag-limit" data-theme-hero-tag-limit-display>' . $heroTagVisibleLimit . '</output>' . view_admin_theme_appearance_help(t('admin.theme.appearance.hero_tag_visible_limit_number', 'Visible tag count'), t('admin.theme.appearance.hero_tag_visible_limit_hint', 'Default: 20 tags. When more tags exist, “Display all tags” expands them in-place with JavaScript and does not reload the page.')) . '</div>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_hero_tag_scrollbar_enabled" value="1"' . ($heroTagScrollbarEnabled ? ' checked' : '') . ' data-theme-hero-tag-scrollbar-enabled> ' . e(t('admin.theme.appearance.hero_tag_scrollbar_enabled', 'Use a scrollbar for long tag lists')) . '</label>';
    echo '<div class="theme-number-slider-control theme-appearance-slider-row" data-theme-hero-tag-scrollbar-controls' . ($heroTagScrollbarEnabled ? '' : ' hidden') . '><label for="theme-appearance-hero-tag-rows">' . e(t('admin.theme.appearance.hero_tag_scrollbar_rows', 'Rows before scrolling')) . '</label><input id="theme-appearance-hero-tag-rows" type="range" name="theme_hero_tag_scrollbar_rows_slider" min="1" max="12" step="1" value="' . $heroTagScrollbarRows . '" data-theme-hero-tag-scrollbar-rows-slider><input type="number" name="theme_hero_tag_scrollbar_rows" min="1" max="12" step="1" value="' . $heroTagScrollbarRows . '" inputmode="numeric" aria-label="' . e(t('admin.theme.appearance.hero_tag_scrollbar_rows_number', 'Maximum visible tag rows')) . '" data-theme-hero-tag-scrollbar-rows-number><output for="theme-appearance-hero-tag-rows" data-theme-hero-tag-scrollbar-rows-display>' . $heroTagScrollbarRows . '</output>' . view_admin_theme_appearance_help(t('admin.theme.appearance.hero_tag_scrollbar_rows_number', 'Maximum visible tag rows'), t('admin.theme.appearance.hero_tag_scrollbar_rows_hint', 'Default: 5 rows. Scrolling is enabled only when the tags actually wrap onto more rows at the current screen width. Disable the scrollbar option to let the hero grow naturally.')) . '</div>';
    echo '<div class="theme-appearance-inline-actions"><a class="button secondary" href="' . e($adminTagsUrl) . '">' . e(t('admin.theme.appearance.open_tag_metadata', 'Manage tag metadata')) . '</a></div></fieldset>';
    $appearanceGalleryTagsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-gallery-tags', $appearanceGalleryTagsHtml, $appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags');

    ob_start();
    echo '<fieldset class="theme-appearance-group theme-appearance-motion-settings"><legend>' . e(t('admin.theme.appearance.motion_legend', 'Interface animations')) . '</legend><p class="theme-appearance-group-hint">' . e(t('admin.theme.appearance.motion_hint', 'Set the duration for each animation. Use 0 ms to turn that animation off.')) . '</p>';
    echo '<div class="theme-motion-control"><div class="theme-motion-control-description"><label for="theme-gallery-info-motion-slider">' . e(t('admin.theme.appearance.gallery_info_motion_duration', 'Gallery tag panel')) . '</label><p class="theme-appearance-group-hint">' . e(t('admin.theme.appearance.gallery_info_motion_hint', 'Opening and closing speed for the public information panel on gallery cards.')) . '</p></div><input id="theme-gallery-info-motion-slider" type="range" min="0" max="800" step="10" value="' . $galleryInfoMotionMs . '" data-theme-override-control data-theme-gallery-info-motion-slider><div class="theme-motion-control-value"><input type="number" name="theme_gallery_info_motion_ms" min="0" max="800" step="10" value="' . $galleryInfoMotionMs . '" inputmode="numeric" aria-label="' . e(t('admin.theme.appearance.gallery_info_motion_duration', 'Gallery tag panel')) . '" data-theme-override-control data-theme-gallery-info-motion-number><span class="theme-motion-control-unit">ms</span><button type="button" class="button secondary small" data-theme-gallery-info-motion-reset data-theme-motion-default="320">' . e(t('admin.theme.appearance.motion_reset_default', 'Default (320 ms)', ['duration' => 320])) . '</button></div></div>';
    echo '<div class="theme-motion-control"><div class="theme-motion-control-description"><label for="theme-admin-side-panel-motion-slider">' . e(t('admin.theme.appearance.admin_panel_motion_duration', 'Admin side panel')) . '</label><p class="theme-appearance-group-hint">' . e(t('admin.theme.appearance.admin_panel_motion_hint', 'Opening and closing speed for the Edit gallery and Add gallery side panel.')) . '</p></div><input id="theme-admin-side-panel-motion-slider" type="range" min="0" max="800" step="10" value="' . $adminSidePanelMotionMs . '" data-theme-override-control data-theme-admin-side-panel-motion-slider><div class="theme-motion-control-value"><input type="number" name="theme_admin_side_panel_motion_ms" min="0" max="800" step="10" value="' . $adminSidePanelMotionMs . '" inputmode="numeric" aria-label="' . e(t('admin.theme.appearance.admin_panel_motion_duration', 'Admin side panel')) . '" data-theme-override-control data-theme-admin-side-panel-motion-number><span class="theme-motion-control-unit">ms</span><button type="button" class="button secondary small" data-theme-admin-side-panel-motion-reset data-theme-motion-default="260">' . e(t('admin.theme.appearance.motion_reset_default', 'Default (260 ms)', ['duration' => 260])) . '</button></div></div></fieldset>';
    $appearanceAnimationsHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-animations', $appearanceAnimationsHtml, $appearanceSubtab === 'admin-theme-appearance-subtab-animations');
    // The keyboard-accessible separator is enabled only after its resize behavior is attached.
    echo '</div><div class="theme-appearance-resizer" data-theme-appearance-resizer hidden role="separator" aria-orientation="vertical" tabindex="0" aria-controls="admin-theme-appearance-settings admin-theme-appearance-subtab-preview" aria-valuemin="0" aria-valuemax="100" aria-valuenow="45" aria-label="' . e(t('admin.theme.appearance.resize_label', 'Resize settings and preview')) . '" title="' . e(t('admin.theme.appearance.resize_hint', 'Drag to resize. Use arrow keys, or double-click to reset.')) . '" data-theme-resize-value="' . e(t('admin.theme.appearance.resize_value', '{settings}% settings / {preview}% preview', ['settings' => '{settings}', 'preview' => '{preview}'])) . '"></div>';
    $previewGlobalDescriptionLayout = $globalDescriptionLayout;
    $previewDescriptionLayout = $appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags' ? $tagPageDescriptionLayout : $previewGlobalDescriptionLayout;
    $previewDescriptionLabel = $previewDescriptionLayout;
    foreach ($descriptionLayouts as $descriptionLayout) {
        if ((string) ($descriptionLayout['value'] ?? '') === $previewDescriptionLayout) {
            $previewDescriptionLabel = (string) ($descriptionLayout['label'] ?? $previewDescriptionLayout);
            break;
        }
    }
    $previewHomeLabel = t('admin.theme.appearance.preview_scope_galleries', 'Gallery cards');
    $previewTagLabel = t('admin.theme.appearance.preview_scope_tags', 'Tag pages');
    $previewScopeLabel = $appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags' ? $previewTagLabel : $previewHomeLabel;
    echo '<aside class="theme-appearance-preview" id="admin-theme-appearance-subtab-preview"><div class="theme-appearance-preview-heading"><h3>' . e(t('admin.theme.subtab_preview', 'Live preview')) . '</h3><select data-theme-preview-context-select aria-label="' . e(t('admin.theme.appearance.preview_target', 'Preview target')) . '"><option value="home"' . ($appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags' ? '' : ' selected') . '>' . e($previewHomeLabel) . '</option><option value="tag"' . ($appearanceSubtab === 'admin-theme-appearance-subtab-gallery-tags' ? ' selected' : '') . '>' . e($previewTagLabel) . '</option></select></div><p class="theme-appearance-preview-context" data-theme-preview-context-label data-theme-preview-context-home-label="' . e($previewHomeLabel) . '" data-theme-preview-context-tag-label="' . e($previewTagLabel) . '" aria-live="polite">' . e($previewScopeLabel . ' · ' . $previewDescriptionLabel) . '</p><p class="theme-appearance-preview-hint">' . e(t('admin.theme.appearance.preview_unsaved_hint', 'Sample preview; changes appear before you save.')) . '</p>';
    view_render_admin_theme_live_preview(array_replace((array) ($viewModel['preview'] ?? $theme), [
        'site_name' => $siteName,
        'page_width' => $pageWidthMode,
        'page_width_custom' => $customPageWidth,
        'gallery_description_layout' => $previewDescriptionLayout,
    ]));
    echo '</aside></div>';
    $appearanceHtml = (string) ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-appearance', $appearanceHtml, true);
}

/**
 * Render the Theme Language tab from controller-prepared presentation data.
 *
 * @param array<string, mixed> $viewModel Controller-prepared language state.
 * @return void Emits scoped language defaults, viewer design, pack editing, and diagnostics panels.
 */
function view_render_admin_theme_language_tab(array $viewModel): void
{
    $activeSubtab = (string) ($viewModel['active_subtab'] ?? 'admin-theme-language-subtab-settings');
    if (!in_array($activeSubtab, ['admin-theme-language-subtab-settings', 'admin-theme-language-subtab-design', 'admin-theme-language-subtab-editor', 'admin-theme-language-subtab-diagnostics'], true)) {
        $activeSubtab = 'admin-theme-language-subtab-settings';
    }
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
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope theme-language-workspace" data-admin-subtab-scope><header class="theme-language-heading"><h2>' . e(t('admin.theme.language.compact_title', 'Language')) . '</h2><p>' . e(t('admin.theme.language.compact_hint', 'Choose interface languages and customize the visitor switcher.')) . '</p></header>';
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

    render_admin_subtabs([
        ['id' => 'admin-theme-language-subtab-settings', 'label' => t('admin.theme.language.subtab_languages', 'Languages')],
        ['id' => 'admin-theme-language-subtab-design', 'label' => t('admin.theme.language.subtab_viewer_switcher', 'Viewer switcher')],
        ['id' => 'admin-theme-language-subtab-editor', 'label' => t('admin.theme.language.subtab_translation_packs', 'Translation packs')],
        ['id' => 'admin-theme-language-subtab-diagnostics', 'label' => t('admin.theme.subtab_language_diagnostics', 'Diagnostics')],
    ], $activeSubtab, t('admin.theme.language.subtabs_label', 'Language subsections'));
    ob_start();
    echo '<div class="theme-language-defaults">';
    echo '<fieldset class="theme-language-card admin-language-settings"><legend>' . e(t('admin.theme.language.admin_label', 'Admin interface language')) . '</legend>';
    echo '<label for="theme-language-admin"><span class="admin-visually-hidden">' . e(t('admin.theme.language.admin_label', 'Admin interface language')) . '</span><select id="theme-language-admin" name="cms_language" aria-describedby="theme-language-admin-scope">';
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
    echo '</select></label><p id="theme-language-admin-scope" class="theme-language-hint">' . e(t('admin.theme.language.admin_compact_hint', 'Only your Admin session and browser.')) . '</p></fieldset>';
    echo '<fieldset class="theme-language-card admin-language-settings"><legend>' . e(t('admin.theme.language.public_default_label', 'Public site default')) . '</legend><label for="theme-language-public"><span class="admin-visually-hidden">' . e(t('admin.theme.language.public_default_label', 'Public site default')) . '</span><select id="theme-language-public" name="public_language" aria-describedby="theme-language-public-scope">';
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
    echo '</select></label><p id="theme-language-public-scope" class="theme-language-hint">' . e(t('admin.theme.language.public_compact_hint', 'Used by public visitors until they choose their own language.')) . '</p></fieldset></div>';
    echo '<p class="theme-language-fallback">' . e(t('admin.theme.language.fallback_compact_hint', 'Translation fallback: {language}.', ['language' => strtoupper($defaultLanguage)])) . '</p>';
    view_render_public_language_selector_settings_panel(array_replace($selectorState, ['parts' => 'choices', 'theme_editor' => true, 'compact' => true]));
    $languageSettingsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-settings', $languageSettingsHtml, $activeSubtab === 'admin-theme-language-subtab-settings');
    ob_start();
    view_render_public_language_selector_settings_panel(array_replace($selectorState, ['parts' => 'design', 'theme_editor' => true]));
    $languageDesignHtml = (string) ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-design', $languageDesignHtml, $activeSubtab === 'admin-theme-language-subtab-design');

    ob_start();
    echo '<fieldset class="theme-language-card admin-language-packs"><legend>' . e(t('admin.theme.language.detected_legend', 'Supported language packs')) . '</legend>';
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
    echo '<fieldset class="theme-language-card admin-language-editor"><legend>' . e(t('admin.theme.language.editor_legend', 'Language pack editor')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.editor_hint', 'Edit the JSON language pack directly. The save action validates JSON and accepts only string values.')) . '</p>';
    echo '<div class="theme-language-editor-toolbar"><label>' . e(t('admin.theme.language.editor_select', 'Language pack to edit')) . '<select name="language_pack_code" onchange="if (this.selectedOptions[0].dataset.editUrl) window.location.href=this.selectedOptions[0].dataset.editUrl;">';
    foreach ($languagePacks as $languagePack) {
        // $languageCode stores one editor-select option value.
        $languageCode = (string) ($languagePack['code'] ?? '');
        if ($languageCode === '') {
            continue;
        }
        // $languageName stores one editor-select option label.
        $languageName = (string) ($languagePack['name'] ?? strtoupper($languageCode));
        echo '<option value="' . e($languageCode) . '" data-edit-url="' . e((string) ($languagePack['edit_url'] ?? '')) . '"' . ($languageEditCode === $languageCode ? ' selected' : '') . '>' . e($languageName) . ' (' . e($languageCode) . ')</option>';
    }
    echo '</select></label>';
    echo '<div class="admin-language-coverage-summary">';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_translated', 'Translated')) . ':</strong> ' . e(t('admin.theme.language.coverage_ratio', '{translated} / {total}', ['translated' => (int) $languageCoverage['translated_count'], 'total' => (int) $languageCoverage['default_count']])) . '</span>';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_missing', 'Missing')) . ':</strong> ' . e(t('admin.theme.language.count_value', '{count}', ['count' => (int) $languageCoverage['missing_count']])) . '</span>';
    echo '<span><strong>' . e(t('admin.theme.language.coverage_extra', 'Extra')) . ':</strong> ' . e(t('admin.theme.language.count_value', '{count}', ['count' => (int) $languageCoverage['extra_count']])) . '</span>';
    echo '</div><button type="submit" name="save_language_pack" value="1" formnovalidate>' . e(t('admin.theme.language.save_pack', 'Save language pack')) . '</button><a class="button secondary" href="' . e($exportUrl) . '">' . e(t('admin.theme.language.export_pack', 'Export JSON')) . '</a></div>';
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
    echo '<label>' . e(t('admin.theme.language.json_label', 'JSON language data')) . '<textarea name="language_pack_json" class="admin-language-json-editor" spellcheck="false" rows="12">' . e($languagePackJson) . '</textarea></label>';
    echo '<p class="theme-language-hint">' . e(t('admin.theme.language.pack_action_preferences_hint', 'Save language pack updates this JSON and pending language preferences. Save theme does not save JSON edits.')) . '</p>';
    echo '<details class="theme-language-details theme-language-import"><summary>' . e(t('admin.theme.language.import_label', 'Import replacement JSON')) . '</summary>';
    echo '<label>' . e(t('admin.theme.language.import_label', 'Import replacement JSON')) . '<input type="file" name="language_pack_file" accept="application/json,.json"></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="import_language_pack" value="1" formnovalidate onclick="return confirm(&quot;' . e(t('admin.theme.language.import_confirm', 'Replace this language pack with the uploaded JSON file?')) . '&quot;);">' . e(t('admin.theme.language.import_pack', 'Import JSON')) . '</button></div>';
    echo '</details></fieldset>';
    echo '<details class="theme-language-details admin-language-conventions"><summary>' . e(t('admin.theme.language.conventions_legend', 'Key naming conventions')) . '</summary>';
    echo '<p class="muted">' . e(t('admin.theme.language.conventions_hint', 'Use stable dotted keys grouped by UI area. Keep wording editable in JSON and keep variable placeholders wrapped in braces.')) . '</p>';
    echo '<ul class="admin-language-convention-list">';
    echo '<li><code>gallery.*</code> ' . e(t('admin.theme.language.convention_gallery', 'public gallery pages and visitor-facing gallery actions')) . '</li>';
    echo '<li><code>admin.*</code> ' . e(t('admin.theme.language.convention_admin', 'shared admin labels and actions')) . '</li>';
    echo '<li><code>theme.*</code> ' . e(t('admin.theme.language.convention_theme', 'theme controls outside the language tab')) . '</li>';
    echo '<li><code>language.*</code> ' . e(t('admin.theme.language.convention_language', 'language-pack editing and diagnostics')) . '</li>';
    echo '<li><code>telemetry.*</code> ' . e(t('admin.theme.language.convention_telemetry', 'anonymous telemetry pages and reports')) . '</li>';
    echo '<li><code>logs.*</code> ' . e(t('admin.theme.language.convention_logs', 'operational logs and log export')) . '</li>';
    echo '</ul></details>';
    $languageEditorHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-language-subtab-editor', $languageEditorHtml, $activeSubtab === 'admin-theme-language-subtab-editor');

    ob_start();
    echo '<fieldset class="theme-language-card admin-language-diagnostics"><legend>' . e(t('admin.theme.language.diagnostics_legend', 'Missing translation diagnostics')) . '</legend>';
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
    render_admin_subtab_panel('admin-theme-language-subtab-diagnostics', $languageDiagnosticsHtml, $activeSubtab === 'admin-theme-language-subtab-diagnostics');
    echo '</div>';
    $languageHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-language', $languageHtml, false);

}
