<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_media.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders Theme branding and media controls for header assets, favicon, and global background behavior.
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
 * Render the Theme branding and media tab.
 *
 * @param array $theme Current theme settings.
 */
function render_admin_theme_media_tab(array $theme): void
{
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.media.kicker', 'Branding & media'),
        'title' => t('admin.theme.media.title', 'Header branding, separator, favicon, and backgrounds'),
        'description' => t('admin.theme.media.description', 'Manage the public header images first, then browser identity and the global gallery background fallback.'),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-media-subtab-header', 'label' => t('admin.theme.subtab_header_images', 'Header images')],
        ['id' => 'admin-theme-media-subtab-favicon', 'label' => t('admin.theme.subtab_browser_icon', 'Browser icon')],
        ['id' => 'admin-theme-media-subtab-background', 'label' => t('admin.theme.subtab_background', 'Background')],
    ], 'admin-theme-media-subtab-header', t('admin.theme.media.subtabs_label', 'Branding and media subsections'));
    ob_start();
    echo '<div class="theme-tab-card-grid">';
    $themeBrandingDefinitions = theme_branding_asset_types();
    $themeBannerDefinition = $themeBrandingDefinitions['banner'] ?? null;
    if ($themeBannerDefinition !== null) {
        // $bannerAssetUrl stores the current global fallback banner URL.
        $bannerAssetUrl = theme_branding_asset_url('banner');
        echo '<fieldset class="form-grid admin-theme-branding-assets" id="admin-theme-branding-banner"><legend>' . e(t('admin.theme.media.public_header_banner', 'Public header banner')) . '</legend>';
        echo '<p class="muted">' . e(t('admin.theme.media.public_header_banner_hint', 'Upload the default public header banner here. It replaces the visible site title when no gallery-specific banner is configured.')) . '</p>';
        echo '<div class="admin-branding-asset">';
        echo '<div class="admin-branding-copy"><strong>' . e((string) $themeBannerDefinition['label']) . '</strong><span class="muted">' . e((string) $themeBannerDefinition['description']) . '</span></div>';
        if ($bannerAssetUrl !== '') {
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-theme-branding-preview-banner" src="' . e($bannerAssetUrl) . '" alt="' . e(t('admin.theme.media.current_branding_alt', 'Current {label}', ['label' => (string) $themeBannerDefinition['label']])) . '"><button type="submit" class="secondary" name="reset_theme_branding_banner" value="1" formnovalidate>' . e(t('admin.theme.media.remove_branding_asset', 'Remove {label}', ['label' => (string) $themeBannerDefinition['label']])) . '</button></div>';
        } else {
            echo '<p class="muted">' . e(t('admin.theme.media.no_fallback_image', 'No fallback image is stored yet.')) . '</p>';
        }
        echo '<label>' . e(t('admin.theme.media.upload_replacement', 'Upload replacement')) . '<input type="file" name="theme_branding_banner" accept="image/png,image/jpeg,image/gif,image/webp,image/*"><span class="muted">' . e(t('admin.theme.media.accepted_formats_8mb', 'Accepted formats: JPG, PNG, GIF, WebP. Maximum size: 8 MB.')) . '</span></label>';
        echo '</div>';
        echo '</fieldset>';
    }

    $themeSeparatorDefinition = $themeBrandingDefinitions['separator'] ?? null;
    if ($themeSeparatorDefinition !== null) {
        // $separatorAssetUrl stores the current global fallback separator URL.
        $separatorAssetUrl = theme_branding_asset_url('separator');
        $brandingSeparatorWidth = theme_branding_separator_width_value($theme['branding_separator_width'] ?? null);
        $brandingSeparatorHeight = theme_branding_separator_height_value($theme['branding_separator_height'] ?? null);
        $brandingSeparatorStretch = theme_branding_separator_stretch_enabled($theme['branding_separator_stretch'] ?? null);
        echo '<fieldset class="form-grid admin-theme-branding-assets" id="admin-theme-branding-separator"><legend>' . e(t('admin.theme.media.public_header_separator', 'Public header separator')) . '</legend>';
        echo '<p class="muted">' . e(t('admin.theme.media.public_header_separator_hint', 'Upload and size the decorative horizontal separator shown under the shared public header. Per-gallery separators still override this Theme fallback on their gallery page.')) . '</p>';
        echo '<div class="admin-branding-asset">';
        echo '<div class="admin-branding-copy"><strong>' . e((string) $themeSeparatorDefinition['label']) . '</strong><span class="muted">' . e((string) $themeSeparatorDefinition['description']) . '</span></div>';
        if ($separatorAssetUrl !== '') {
            echo '<div class="admin-branding-current"><img class="admin-branding-preview admin-theme-branding-preview-separator" src="' . e($separatorAssetUrl) . '" alt="' . e(t('admin.theme.media.current_branding_alt', 'Current {label}', ['label' => (string) $themeSeparatorDefinition['label']])) . '"><button type="submit" class="secondary" name="reset_theme_branding_separator" value="1" formnovalidate>' . e(t('admin.theme.media.remove_branding_asset', 'Remove {label}', ['label' => (string) $themeSeparatorDefinition['label']])) . '</button></div>';
        } else {
            echo '<p class="muted">' . e(t('admin.theme.media.no_fallback_image', 'No fallback image is stored yet.')) . '</p>';
        }
        echo '<label>' . e(t('admin.theme.media.upload_replacement', 'Upload replacement')) . '<input type="file" name="theme_branding_separator" accept="image/png,image/jpeg,image/gif,image/webp,image/*"><span class="muted">' . e(t('admin.theme.media.accepted_formats_8mb', 'Accepted formats: JPG, PNG, GIF, WebP. Maximum size: 8 MB.')) . '</span></label>';
        echo '<div class="admin-branding-separator-size">';
        echo '<label>' . e(t('admin.theme.media.separator_width', 'Separator width')) . '<input type="number" name="theme_branding_separator_width" min="0" max="3840" step="1" value="' . $brandingSeparatorWidth . '"><span class="muted">' . e(t('admin.theme.media.separator_width_hint', 'Pixels. Use 0 to keep the current responsive page width.')) . '</span></label>';
        echo '<label>' . e(t('admin.theme.media.separator_height', 'Separator height')) . '<input type="number" name="theme_branding_separator_height" min="8" max="512" step="1" value="' . $brandingSeparatorHeight . '"><span class="muted">' . e(t('admin.theme.media.separator_height_hint', 'Pixels. With aspect ratio enabled this is a maximum; with stretching enabled this is the exact render height.')) . '</span></label>';
        echo '<label class="checkbox-label admin-branding-separator-stretch"><input type="checkbox" name="theme_branding_separator_stretch" value="1"' . ($brandingSeparatorStretch ? ' checked' : '') . '> ' . e(t('admin.theme.media.separator_stretch', 'Stretch to exact width and height')) . '<span class="muted">' . e(t('admin.theme.media.separator_stretch_hint', 'Allows the separator image to scale non-proportionally instead of preserving its original aspect ratio.')) . '</span></label>';
        echo '</div>';
        echo '</div>';
        echo '</fieldset>';
    }
    echo '</div>';
    $mediaHeaderHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-header', $mediaHeaderHtml, true);

    ob_start();
    echo '<fieldset class="form-grid" id="admin-favicon"><legend>' . e(t('admin.theme.media.favicon_legend', 'Favicon')) . '</legend>';
    // $faviconUrl stores an intermediate value used by the surrounding gallery workflow.
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        // $faviconVersion stores an intermediate value used by the surrounding gallery workflow.
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<div class="favicon-current"><img src="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '" alt="' . e(t('admin.theme.media.current_favicon_alt', 'Current favicon')) . '"><p class="muted">' . e(t('admin.theme.media.current_favicon_hint', 'Current favicon is generated as 32px, 48px, and 180px PNG variants.')) . '</p></div>';
    } else {
        echo '<p class="muted">' . e(t('admin.theme.media.no_favicon', 'No favicon is stored yet. Browsers will use their default icon until one is saved.')) . '</p>';
    }
    echo '<label>' . e(t('admin.theme.media.favicon_source_image', 'Favicon source image')) . '<input type="file" name="favicon_source" accept="image/png,image/jpeg,image/gif,image/webp,image/*" data-favicon-input><span class="muted">' . e(t('admin.theme.media.favicon_source_hint', 'Upload a square-friendly photo or logo. The cropper saves a browser-ready square PNG favicon.')) . '</span></label>';
    echo '<input type="hidden" name="favicon_cropped_png" value="" data-favicon-cropped>';
    echo '<div class="favicon-cropper" data-favicon-cropper hidden><div class="favicon-crop-stage"><canvas width="256" height="256" data-favicon-canvas></canvas></div><label>' . e(t('admin.theme.media.zoom', 'Zoom')) . '<input type="range" min="1" max="3" step="0.01" value="1" data-favicon-zoom></label><div class="favicon-preview-row"><canvas width="48" height="48" data-favicon-preview></canvas><span class="muted">' . e(t('admin.theme.media.favicon_crop_hint', 'Drag the image to place the square crop. The small preview shows the browser icon scale.')) . '</span></div></div>';
    echo '</fieldset>';
    $mediaFaviconHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-favicon', $mediaFaviconHtml, false);

    ob_start();
    echo '<fieldset class="form-grid admin-theme-background-card" id="admin-backgrounds"><legend>' . e(t('admin.theme.media.background_legend', 'Background')) . '</legend>';
    $backgroundMaxSide = theme_background_optimized_max_side_value($theme['background_optimized_max_side'] ?? null);
    $themeOriginalUrl = theme_background_original_path() !== null ? url_for('theme_background_asset') . '&variant=original' : '';
    $themeOptimizedActive = theme_background_optimized_path() !== null;
    $themeHasBackground = $themeBackgroundUrl !== '';
    echo '<div class="admin-theme-background-preview">';
    if ($themeHasBackground) {
        echo '<a class="admin-theme-background-thumb" href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener"><img src="' . e($themeBackgroundUrl) . '" alt="' . e(t('admin.theme.media.current_theme_background_alt', 'Selected background preview')) . '"></a>';
    } else {
        echo '<div class="admin-theme-background-thumb admin-theme-background-thumb-empty" aria-hidden="true"><span></span></div>';
    }
    echo '<div class="admin-theme-background-copy"><strong>' . e($themeHasBackground ? t('admin.theme.media.background_selected', 'Background selected') : t('admin.theme.media.background_not_selected', 'No background selected')) . '</strong>';
    if ($themeHasBackground && $themeOptimizedActive) {
        echo '<span class="admin-theme-background-status is-ready">' . e(t('admin.theme.media.background_optimized_ready', 'Optimized WebP is active')) . '</span>';
    } elseif ($themeHasBackground) {
        echo '<span class="admin-theme-background-status">' . e(t('admin.theme.media.background_serving_original', 'Serving the original image')) . '</span>';
    } else {
        echo '<span class="muted">' . e(t('admin.theme.media.no_theme_background', 'No global theme background image is stored yet.')) . '</span>';
    }
    echo '</div></div>';
    echo '<label>' . e(t('admin.theme.media.theme_background_image', 'Choose background image')) . '<input type="file" name="theme_background" accept="image/*"><span class="muted">' . e(t('admin.theme.media.theme_background_image_hint', 'Upload the image you want to keep as the original. The gallery can serve a smaller WebP copy for visitors.')) . '</span></label>';
    echo '<label class="admin-theme-background-size">' . e(t('admin.theme.media.background_optimized_size', 'Optimized display size')) . ' <span class="muted" data-theme-background-optimized-size-display data-theme-background-optimized-size-template="' . e(t('admin.theme.media.background_optimized_size_value', '{size}px longest side')) . '">' . e(t('admin.theme.media.background_optimized_size_value', '{size}px longest side', ['size' => (string) $backgroundMaxSide])) . '</span><input type="range" name="theme_background_optimized_max_side" min="1024" max="3840" step="128" value="' . $backgroundMaxSide . '" data-theme-background-optimized-size><span class="muted">' . e(t('admin.theme.media.background_optimized_size_hint', 'Use 1920px for normal screens, 2560px or more for very large displays.')) . '</span></label>';
    echo '<div class="admin-theme-background-actions">';
    echo '<button type="submit" class="secondary" name="generate_theme_background_optimized" value="1" formnovalidate' . (!$themeHasBackground ? ' disabled' : '') . '>' . e($themeOptimizedActive ? t('admin.theme.media.regenerate_optimized_background', 'Regenerate optimized background') : t('admin.theme.media.generate_optimized_background', 'Generate optimized background')) . '</button>';
    echo '<button type="submit" class="secondary" name="delete_theme_background_optimized" value="1" formnovalidate' . (!$themeOptimizedActive ? ' disabled' : '') . '>' . e(t('admin.theme.media.delete_optimized_background', 'Delete optimized copy')) . '</button>';
    if ($themeHasBackground) {
        echo '<a class="button secondary" href="' . e($themeBackgroundUrl) . '" target="_blank" rel="noopener">' . e(t('admin.theme.media.view_served_image', 'View used image')) . '</a>';
    }
    if ($themeOriginalUrl !== '') {
        echo '<a class="button secondary" href="' . e($themeOriginalUrl) . '" target="_blank" rel="noopener">' . e(t('admin.theme.media.view_original_image', 'View original')) . '</a>';
    }
    echo '</div>';
    echo '<label>' . e(t('admin.theme.media.background_transparency', 'Background transparency')) . ' <span data-theme-background-opacity-display>' . (int) ($theme['background_opacity'] ?? 65) . '%</span><input type="range" name="theme_background_opacity" min="0" max="100" value="' . (int) ($theme['background_opacity'] ?? 65) . '" data-theme-override-control data-theme-background-opacity><span class="muted">' . e(t('admin.theme.media.background_transparency_hint', 'Higher means more visible image, lower means more of the color underneath.')) . '</span></label>';
    echo '<label>' . e(t('admin.theme.media.gallery_background_fallback', 'Gallery background fallback')) . '<select name="theme_background_source" data-theme-override-control><option value=""' . (theme_background_source() === null ? ' selected' : '') . '>' . e(t('admin.theme.media.background_fallback_none', 'No fallback set')) . '</option><option value="upload"' . (theme_background_source() === 'upload' ? ' selected' : '') . '>' . e(t('admin.theme.media.background_fallback_upload', 'Upload new image')) . '</option><option value="existing"' . (theme_background_source() === 'existing' ? ' selected' : '') . '>' . e(t('admin.theme.media.background_fallback_existing', 'Pick from existing gallery images')) . '</option><option value="collage"' . (theme_background_source() === 'collage' ? ' selected' : '') . '>' . e(t('admin.theme.media.background_fallback_collage', 'Generate collage from public galleries')) . '</option></select><span class="muted">' . e(t('admin.theme.media.gallery_background_fallback_hint', 'Used when a gallery does not set its own background source.')) . '</span></label>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_backgrounds" value="1" formnovalidate>' . e(t('admin.theme.media.reset_all_gallery_backgrounds', 'Reset all gallery backgrounds')) . '</button><button type="submit" class="secondary" name="reset_theme_background" value="1" formnovalidate>' . e(t('admin.theme.media.remove_theme_background', 'Remove theme background')) . '</button><button type="submit" class="secondary" name="reset_favicon" value="1" formnovalidate>' . e(t('admin.theme.media.remove_favicon', 'Remove favicon')) . '</button></div>';
    echo '</fieldset>';
    $mediaBackgroundHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-media-subtab-background', $mediaBackgroundHtml, false);
    echo '</div>';
    $mediaHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-media', $mediaHtml, false);

}