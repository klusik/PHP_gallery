<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_page.php
 * Module Type: Controller
 *
 * Purpose:
 *   Coordinates Theme page rendering and delegates each Theme tab to its focused renderer.
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
 * Render the Theme administration page.
 *
 * @param bool $gpsMapsFeatureEnabled Whether GPS map appearance settings are enabled.
 * @param bool $lightboxModesFeatureEnabled Whether lightbox mode settings are enabled.
 */
function render_admin_theme_page(bool $gpsMapsFeatureEnabled, bool $lightboxModesFeatureEnabled): void
{
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = pagination_global_settings();
    // Variable $homeGridSettings stores the separate public home-page gallery grid.
    $homeGridSettings = main_page_gallery_grid_settings();
    // $tagPageGridSettings stores the independent public tag-page gallery grid.
    $tagPageGridSettings = tag_page_gallery_grid_settings();
    // $tagPageDescriptionLayout stores the card design used only on public tag pages.
    $tagPageDescriptionLayout = tag_page_gallery_description_layout();
    // $publicThumbnailRenderingMode stores the validated public photo-card rendering mode shown by the Layout form.
    $publicThumbnailRenderingMode = public_thumbnail_rendering_mode();
    render_header(t('admin.theme.page_title', 'Theme'));
    if (!empty($_GET['grid_reset'])) {
        // $databaseRows stores how many database gallery rows reported a custom-grid reset.
        $databaseRows = max(0, (int) ($_GET['db_rows'] ?? 0));
        // $sidecars stores how many gallery.json files had stale custom-grid metadata removed.
        $sidecars = max(0, (int) ($_GET['sidecars'] ?? 0));
        echo '<section class="panel notice"><p>' . e(t('admin.theme.grid_reset_notice', 'Custom gallery grid settings were reset. Database rows changed: {db_rows}. Sidecar files cleaned: {sidecars}.', ['db_rows' => $databaseRows, 'sidecars' => $sidecars])) . '</p></section>';
    }
    // $themeBackgroundUrl stores the current global background asset so the live preview can mirror the public page before saving.
    $themeBackgroundUrl = theme_background_asset_url();
    view_render_admin_hero([
        'title' => t('admin.theme.title', 'Theme'),
        'description' => t('admin.theme.description', 'Control the public gallery appearance, media identity, layout, and custom stylesheet from one focused workspace.'),
        'class' => 'admin-theme-hero',
        'actions_html' => '<a class="button secondary" href="' . e(admin_settings_url('appearance')) . '">' . e(t('admin.settings.open_centralized', 'Open centralized settings')) . '</a><button type="submit" form="admin-theme-form">' . e(t('admin.theme.save_theme', 'Save theme')) . '</button>',
    ]);

    $themeTabs = [
        ['id' => 'admin-theme-tab-appearance', 'label' => t('admin.theme.tab_appearance', 'Appearance')],
        ['id' => 'admin-theme-tab-media', 'label' => t('admin.theme.tab_media', 'Branding & media')],
        ['id' => 'admin-theme-tab-layout', 'label' => t('admin.theme.tab_layout', 'Layout')],
        ['id' => 'admin-theme-tab-language', 'label' => t('admin.theme.language.tab_label', 'Language')],
        ['id' => 'admin-theme-tab-custom-css', 'label' => t('admin.theme.tab_custom_css', 'Custom CSS')],
    ];
    render_admin_tabs($themeTabs, 'admin-theme-tab-appearance');

    echo '<form id="admin-theme-form" method="post" enctype="multipart/form-data" class="form-grid admin-theme-form" data-theme-form>' . csrf_field();
    echo '<input type="hidden" name="theme_controls_changed" value="0" data-theme-controls-changed>';


    render_admin_theme_appearance_tab($theme, $themeBackgroundUrl, $gpsMapsFeatureEnabled, $tagPageGridSettings, $tagPageDescriptionLayout);
    render_admin_theme_media_tab($theme);
    render_admin_theme_layout_tab($theme, $paginationSettings, $homeGridSettings, $publicThumbnailRenderingMode, $lightboxModesFeatureEnabled);
    render_admin_theme_language_tab();
    render_admin_theme_custom_css_tab();

    echo '<div class="panel admin-theme-save-panel"><div><strong>' . e(t('admin.theme.save_panel_title', 'Save changes')) . '</strong><p class="muted">' . e(t('admin.theme.save_panel_hint', 'All Theme tabs are saved together, so hidden tab settings are preserved when you submit the form.')) . '</p></div><div class="bulk-row"><button type="submit">' . e(t('admin.theme.save_theme', 'Save theme')) . '</button><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e(t('admin.theme.custom_css.reset_to_css', 'Reset to CSS')) . '</button></div></div></form>';
    render_footer();

}