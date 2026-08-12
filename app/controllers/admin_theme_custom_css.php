<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_custom_css.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders custom CSS preset, upload, and reset controls.
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
 * Render the Theme custom CSS tab.
 */
function render_admin_theme_custom_css_tab(): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.custom_css.kicker', 'Custom CSS'),
        'title' => t('admin.theme.custom_css.title', 'Skins and manual CSS'),
        'description' => t('admin.theme.custom_css.description', 'Use a preset skin or upload a stylesheet that loads after built-in CSS and saved theme controls.'),
    ]);
    // Variable $selectedPreset stores this steps working value.
    $selectedPreset = (string) app_setting('custom_css_preset', '');
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-css-subtab-source', 'label' => t('admin.theme.subtab_css_source', 'CSS source')],
        ['id' => 'admin-theme-css-subtab-reset', 'label' => t('admin.theme.subtab_css_reset', 'Reset actions')],
    ], 'admin-theme-css-subtab-source', t('admin.theme.custom_css.subtabs_label', 'Custom CSS subsections'));
    ob_start();
    echo '<div id="admin-custom-css"></div><fieldset class="form-grid"><legend>' . e(t('admin.theme.custom_css.legend', 'Custom CSS')) . '</legend><label>' . e(t('admin.theme.custom_css.skin_label', 'Custom CSS skin')) . '<select name="custom_css_preset"><option value="">' . e(t('admin.theme.custom_css.keep_current', 'Keep current custom CSS')) . '</option>';
    foreach (custom_css_presets() as $filename => $path) {
        // Variable $label stores this steps working value.
        $label = ucwords(str_replace(['-', '_'], ' ', pathinfo((string) $filename, PATHINFO_FILENAME)));
        echo '<option value="' . e((string) $filename) . '"' . ($selectedPreset === $filename ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><span class="muted">' . e(t('admin.theme.custom_css.skin_hint', 'Selecting a skin copies it from custom_css/ into the active custom stylesheet.')) . '</span></label>';
    echo '<label>' . e(t('admin.theme.custom_css.file_label', 'Custom CSS file')) . '<input type="file" name="custom_css" accept=".css,text/css"></label>';
    echo '<p class="muted">' . e(t('admin.theme.custom_css.file_hint', 'Uploaded CSS is saved as public/assets/custom.css and loaded after the built-in stylesheet and theme controls.')) . '</p>';
    echo '</fieldset>';
    $customCssSourceHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-css-subtab-source', $customCssSourceHtml, true);

    ob_start();
    echo '<fieldset class="form-grid"><legend>' . e(t('admin.theme.custom_css.reset_legend', 'Reset actions')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.custom_css.reset_hint', 'Reset saved color overrides or remove the uploaded custom stylesheet without changing other Theme form values.')) . '</p>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e(t('admin.theme.custom_css.reset_to_css', 'Reset to CSS')) . '</button><button type="submit" class="secondary" name="reset_custom_css" value="1" formnovalidate>' . e(t('admin.theme.custom_css.reset_custom_css', 'Reset custom CSS')) . '</button></div></fieldset>';
    $customCssResetHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-css-subtab-reset', $customCssResetHtml, false);
    echo '</div>';
    $customCssHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-custom-css', $customCssHtml, false);

}