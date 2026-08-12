<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_appearance.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Theme appearance tab, including colors, page width, GPS pin controls, tag-page settings, hero tags, and live preview.
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
use const Gallery\Services\CMS_PAGINATION_DEFAULT_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_DEFAULT_ROWS;
use const Gallery\Services\CMS_PAGINATION_MAX_COLUMNS;
use const Gallery\Services\CMS_PAGINATION_MAX_ROWS;
use const Gallery\Services\THEME_FAVORITE_GALLERIES_HOME_TOKEN;
use const Gallery\Services\THEME_FAVORITE_GALLERIES_MAX;
use const Gallery\Services\PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE;
use const Gallery\Services\PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE;
use function Gallery\Core\csrf_field;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_admin_subtab_panel;
use function Gallery\Core\render_admin_subtabs;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Core\render_admin_tabs;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\app_setting;
use function Gallery\Services\clear_theme_overrides;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_preset_path;
use function Gallery\Services\custom_css_presets;
use function Gallery\Services\delete_theme_branding_asset;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\gallery_background_source_schema_ready;
use function Gallery\Services\gallery_description_layout_label;
use function Gallery\Services\gallery_description_layout_normalize;
use function Gallery\Services\gallery_description_layout_options;
use function Gallery\Services\gallery_lightbox_browsing_mode_label;
use function Gallery\Services\gallery_lightbox_browsing_mode_normalize;
use function Gallery\Services\gallery_lightbox_browsing_mode_options;
use function Gallery\Services\main_page_gallery_grid_settings;
use function Gallery\Services\pagination_dimension_value;
use function Gallery\Services\pagination_global_settings;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_thumbnail_rendering_mode_save_with_revision;
use function Gallery\Services\remove_stored_favicon;
use function Gallery\Services\reset_all_gallery_grid_overrides;
use function Gallery\Services\sanitize_hex_color;
use function Gallery\Services\save_theme_favorite_gallery_ids;
use function Gallery\Services\save_theme_favorite_gallery_slots;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\set_site_name;
use function Gallery\Services\site_name;
use function Gallery\Services\store_uploaded_favicon;
use function Gallery\Services\store_uploaded_theme_background;
use function Gallery\Services\store_uploaded_theme_branding_asset;
use function Gallery\Services\t;
use function Gallery\Services\theme_background_asset_url;
use function Gallery\Services\theme_background_clear_stored_files;
use function Gallery\Services\theme_background_optimized_max_side_value;
use function Gallery\Services\theme_background_optimized_path;
use function Gallery\Services\theme_background_original_path;
use function Gallery\Services\theme_background_regenerate_optimized;
use function Gallery\Services\theme_background_source;
use function Gallery\Services\theme_branding_asset_types;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_branding_separator_height_value;
use function Gallery\Services\theme_branding_separator_stretch_enabled;
use function Gallery\Services\theme_branding_separator_width_value;
use function Gallery\Services\theme_favorite_gallery_ids;
use function Gallery\Services\theme_gallery_description_layout;
use function Gallery\Services\theme_gps_pin_background_size_value;
use function Gallery\Services\theme_gps_pin_size_value;
use function Gallery\Services\theme_lightbox_browsing_mode;
use function Gallery\Services\theme_hero_tag_display_all_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_rows;
use function Gallery\Services\theme_hero_tag_scrollbar_rows_value;
use function Gallery\Services\theme_hero_tag_sort_mode;
use function Gallery\Services\theme_hero_tag_sort_mode_normalize;
use function Gallery\Services\theme_hero_tag_visible_limit;
use function Gallery\Services\theme_hero_tag_visible_limit_value;
use function Gallery\Services\tag_page_gallery_description_layout;
use function Gallery\Services\tag_page_gallery_grid_settings;
use function Gallery\Services\theme_page_width_custom_value;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\translation_admin_language;
use function Gallery\Services\translation_clear_missing_diagnostics;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_detected_language_packs;
use function Gallery\Services\translation_language_allowed;
use function Gallery\Services\translation_language_coverage;
use function Gallery\Services\translation_language_pack_json_text;
use function Gallery\Services\translation_missing_diagnostics;
use function Gallery\Services\translation_normalize_language_code;
use function Gallery\Services\translation_public_language;
use function Gallery\Services\translation_supported_languages;
use function Gallery\Services\translation_save_language_json;
use function Gallery\Services\translation_set_active_language;
use function Gallery\Services\translation_set_public_language;
use function Gallery\Views\view_render_admin_hero;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Admin theme controller.
 *
 * Renders and processes the visual theme configuration page. The code remains
 * intentionally close to the original controller so existing POST field names,
 * uploads, reset actions, and redirects keep behaving exactly as before.
 */

/**
 * Send validators for a streamed file and stop on a matching browser cache entry.
 */



/**
 * Render the public scroll helper next to a listing without joining the listing grid.
 */


/**
 * Public homepage showing top-level public galleries.
 */


/**
 * Public gallery detail page with breadcrumbs, subgalleries, images, tags, and votes.
 */


/**
 * Render gallery ancestor links for public navigation.
 */


/**
 * Render the password prompt for a protected public gallery.
 */


/**
 * Process a public protected-gallery password unlock.
 */


/**
 * Resolve a share token and redirect to its protected gallery.
 */


/**
 * Build the canonical copyable share URL for one gallery/token pair.
 */


/**
 * Render one gallery card, including direct cover or child-cover collage.
 */


/**
 * Render logged-in admin metadata controls directly on public gallery pages.
 */


/**
 * Render logged-in admin metadata controls for a public image card.
 */


/**
 * Render the lightbox shell used by public gallery JavaScript.
 */


/**
 * Stream a generated thumbnail after the same visibility checks as originals.
 */



/**
 * Stream a generated thumbnail addressed through the clean public image URL.
 */


/**
 * Stream an original image addressed through the clean public image URL.
 */


/**
 * Stream an uploaded gallery thumbnail asset.
 */


/**
 * Stream a protected image file after checking gallery/image visibility.
 */


/**
 * Serve robots.txt for search engines.
 */


/**
 * Serve sitemap.xml for public gallery pages.
 */


/**
 * Render and process the admin login form.
 */

/**
 * Render the Theme appearance tab.
 *
 * @param array $theme Current theme settings.
 * @param string $themeBackgroundUrl Current theme background URL.
 * @param bool $gpsMapsFeatureEnabled Whether GPS map appearance settings are enabled.
 * @param array $tagPageGridSettings Tag-page grid settings.
 * @param string $tagPageDescriptionLayout Tag-page gallery-card layout.
 */
function render_admin_theme_appearance_tab(array $theme, string $themeBackgroundUrl, bool $gpsMapsFeatureEnabled, array $tagPageGridSettings, string $tagPageDescriptionLayout): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.appearance.kicker', 'Appearance'),
        'title' => t('admin.theme.appearance.title', 'Visual appearance'),
        'description' => t('admin.theme.appearance.description', 'Edit the core visual language. The preview mirrors colors, typography, radius, page width, and background transparency.'),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope data-theme-preview-root data-theme-preview-background-url="' . e($themeBackgroundUrl) . '">';
    // $appearanceSubtab stores an optional deep-link target supplied by contextual Admin links.
    $appearanceSubtab = (string) ($_GET['appearance_subtab'] ?? '');
    $appearanceSubtabOptions = [
        'admin-theme-appearance-subtab-colors',
        'admin-theme-appearance-subtab-width-map',
        'admin-theme-appearance-subtab-gallery-tags',
        'admin-theme-appearance-subtab-preview',
    ];
    if (!in_array($appearanceSubtab, $appearanceSubtabOptions, true)) {
        $appearanceSubtab = 'admin-theme-appearance-subtab-colors';
    }
    render_admin_subtabs([
        ['id' => 'admin-theme-appearance-subtab-colors', 'label' => t('admin.theme.subtab_colors_identity', 'Colors & identity')],
        ['id' => 'admin-theme-appearance-subtab-width-map', 'label' => t('admin.theme.subtab_width_map', 'Width & map pin')],
        ['id' => 'admin-theme-appearance-subtab-gallery-tags', 'label' => t('admin.theme.subtab_gallery_tags', 'Gallery tags')],
        ['id' => 'admin-theme-appearance-subtab-preview', 'label' => t('admin.theme.subtab_preview', 'Live preview')],
    ], $appearanceSubtab, t('admin.theme.appearance.subtabs_label', 'Appearance subsections'));
    ob_start();
    echo '<fieldset class="form-grid admin-theme-appearance-controls-panel"><legend>' . e(t('admin.theme.appearance.legend', 'Visual appearance')) . '</legend>';
    echo '<div class="theme-appearance-controls">';
    echo '<label>' . e(t('admin.theme.appearance.site_name', 'Site name')) . '<input name="site_name" value="' . e(site_name()) . '" maxlength="120" required data-theme-preview-site-name></label>';
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
        // $gpsPinEnabled stores the current visibility state for the EXIF GPS pin overlay.
        $gpsPinEnabled = ((string) ($theme['gps_pin_enabled'] ?? '1')) === '1';
        // $gpsPinBackgroundEnabled stores whether the pin underlay should be visible.
        $gpsPinBackgroundEnabled = ((string) ($theme['gps_pin_background_enabled'] ?? '1')) === '1';
        // $gpsPinSize stores the configured pin diameter in pixels.
        $gpsPinSize = theme_gps_pin_size_value($theme['gps_pin_size'] ?? null);
        // $gpsPinBackgroundSize stores the configured badge diameter in pixels.
        $gpsPinBackgroundSize = theme_gps_pin_background_size_value($theme['gps_pin_background_size'] ?? null);
        echo '<fieldset class="theme-gps-pin-settings"><legend>' . e(t('admin.theme.appearance.gps_pin_legend', 'GPS pin')) . '</legend>';
        echo '<label class="checkbox-label"> <input type="checkbox" name="theme_gps_pin_enabled" value="1"' . ($gpsPinEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-enabled> ' . e(t('admin.theme.appearance.show_gps_pin', 'Show GPS pin on photo cards')) . '</label>';
        echo '<label class="checkbox-label"> <input type="checkbox" name="theme_gps_pin_background_enabled" value="1"' . ($gpsPinBackgroundEnabled ? ' checked' : '') . ' data-theme-override-control data-theme-gps-pin-background-enabled> ' . e(t('admin.theme.appearance.show_pin_background', 'Show pin background underlay')) . '</label>';
        echo '<label>' . e(t('admin.theme.appearance.pin_size', 'Pin size')) . ' <span class="muted" data-theme-gps-pin-size-display>' . $gpsPinSize . 'px</span><input type="range" name="theme_gps_pin_size" min="14" max="48" step="1" value="' . $gpsPinSize . '" data-theme-override-control data-theme-gps-pin-size></label>';
        echo '<label>' . e(t('admin.theme.appearance.pin_background_size', 'Background size')) . ' <span class="muted" data-theme-gps-pin-background-size-display>' . $gpsPinBackgroundSize . 'px</span><input type="range" name="theme_gps_pin_background_size" min="0" max="48" step="1" value="' . $gpsPinBackgroundSize . '" data-theme-override-control data-theme-gps-pin-background-size></label>';
        echo '<div class="theme-gps-pin-preview" data-theme-gps-pin-preview aria-label="' . e(t('admin.theme.appearance.gps_pin_preview_label', 'GPS pin preview')) . '"><span class="photo-map-pin" data-theme-gps-pin-sample aria-hidden="true">&#128205;</span><span class="muted">' . e(t('admin.theme.appearance.gps_pin_preview_hint', 'Live preview of the photo pin.')) . '</span></div>';
        echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_gps_pin_size" value="1" formnovalidate>' . e(t('admin.theme.appearance.reset_pin_size', 'Reset pin size')) . '</button></div>';
        echo '</fieldset>';
    }
    // $pageWidthMode stores the normalized layout preset selected for the public page container.
    $pageWidthMode = theme_page_width_mode((string) ($theme['page_width'] ?? 'default'));
    // $customPageWidth stores the saved custom container width in pixels. It is always rendered so switching presets does not discard it.
    $customPageWidth = theme_page_width_custom_value($theme['page_width_custom'] ?? null);
    echo '<label>' . e(t('admin.theme.appearance.page_width', 'Page width')) . '<select name="theme_page_width" data-theme-preview-width data-theme-page-width-select><option value="default"' . ($pageWidthMode === 'default' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_default', 'Default')) . '</option><option value="wide"' . ($pageWidthMode === 'wide' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_wide', 'Wider')) . '</option><option value="custom"' . ($pageWidthMode === 'custom' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_custom', 'Custom')) . '</option><option value="full"' . ($pageWidthMode === 'full' ? ' selected' : '') . '>' . e(t('admin.theme.appearance.page_width_full', 'Full width')) . '</option></select><span class="muted">' . e(t('admin.theme.appearance.page_width_hint', 'Controls the public page container. Full width follows the available screen width dynamically.')) . '</span></label>';
    echo '<div class="theme-custom-width-control" data-theme-custom-width-control' . ($pageWidthMode === 'custom' ? '' : ' hidden') . '>';
    echo '<label>' . e(t('admin.theme.appearance.custom_page_width', 'Custom page width')) . ' <span class="muted" data-theme-custom-width-display>' . $customPageWidth . 'px</span><input type="range" name="theme_page_width_custom_slider" min="1024" max="2048" step="1" value="' . $customPageWidth . '" data-theme-custom-width-slider></label>';
    echo '<label>' . e(t('admin.theme.appearance.custom_width_pixels', 'Custom width in pixels')) . '<input type="number" name="theme_page_width_custom" min="1024" max="2048" step="1" value="' . $customPageWidth . '" inputmode="numeric" data-theme-preview-custom-width data-theme-custom-width-number><span class="muted">' . e(t('admin.theme.appearance.custom_width_pixels_hint', 'Allowed range: 1024 to 2048 px.')) . '</span></label>';
    echo '</div>';
    echo '</fieldset>';
    $appearanceWidthMapHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-width-map', $appearanceWidthMapHtml, false);

    ob_start();
    // Hero tag display policy is global because the same public hero component is used for every open gallery.
    $heroTagVisibleLimit = theme_hero_tag_visible_limit();
    $heroTagDisplayAll = theme_hero_tag_display_all_enabled();
    $heroTagScrollbarEnabled = theme_hero_tag_scrollbar_enabled();
    $heroTagScrollbarRows = theme_hero_tag_scrollbar_rows();
    $heroTagSortMode = theme_hero_tag_sort_mode();
    echo '<fieldset class="form-grid admin-theme-tag-page-settings"><legend>' . e(t('admin.theme.appearance.tag_page_legend', 'Public tag page layout')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.tag_page_hint', 'Overrides the normal Theme grid and gallery-card design when visitors open a public tag page. The site-wide pagination switch still controls whether long tag results are split into pages.')) . '</p>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_columns', 'Galleries per row')) . ' <span class="muted">' . (int) $tagPageGridSettings['columns'] . '</span><input type="range" name="tag_page_gallery_grid_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $tagPageGridSettings['columns'] . '"></label>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_rows', 'Rows per page')) . ' <span class="muted">' . (int) $tagPageGridSettings['rows'] . '</span><input type="range" name="tag_page_gallery_grid_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $tagPageGridSettings['rows'] . '"></label>';
    echo '<p class="muted">' . e(t('admin.theme.appearance.tag_page_capacity', 'Tag-page capacity: {count} galleries per page.', ['count' => (int) $tagPageGridSettings['items_per_page']])) . '</p>';
    echo '<label>' . e(t('admin.theme.appearance.tag_page_card_layout', 'Gallery-card design')) . '<select name="tag_page_gallery_description_layout">';
    foreach (gallery_description_layout_options() as $descriptionLayoutOption) {
        echo '<option value="' . e($descriptionLayoutOption) . '"' . ($tagPageDescriptionLayout === $descriptionLayoutOption ? ' selected' : '') . '>' . e(gallery_description_layout_label($descriptionLayoutOption)) . '</option>';
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
    echo '<div class="bulk-row"><a class="button secondary" href="' . e(url_for('admin_tags')) . '">' . e(t('admin.theme.appearance.open_tag_metadata', 'Manage tag metadata')) . '</a></div>';
    echo '</fieldset>';
    $appearanceGalleryTagsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-appearance-subtab-gallery-tags', $appearanceGalleryTagsHtml, false);

    ob_start();
    echo '<aside class="theme-live-preview" aria-label="' . e(t('admin.theme.appearance.live_preview_label', 'Live theme preview')) . '" data-theme-live-preview>';
    // The preview starts from the saved page-width mode and custom pixel value before JavaScript runs.
    echo '<div class="theme-preview-page" data-theme-preview-page data-preview-width="' . e($pageWidthMode) . '" style="--preview-custom-width-scale: ' . number_format(($customPageWidth - 1024) / 1024, 4, '.', '') . ';">';
    echo '<div class="theme-preview-background"><span data-theme-preview-background-image></span></div>';
    echo '<header class="theme-preview-header"><strong data-theme-preview-brand>' . e(site_name()) . '</strong><nav><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_home', 'Home')) . '</span><span class="theme-preview-link">' . e(t('admin.theme.appearance.preview_galleries', 'Galleries')) . '</span></nav></header>';
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