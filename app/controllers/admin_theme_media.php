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
use function Gallery\Views\view_render_admin_theme_media_tab;

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
    // $themeBrandingDefinitions stores the supported Theme branding asset definitions.
    $themeBrandingDefinitions = theme_branding_asset_types();
    // $themeBannerDefinition stores the optional banner definition.
    $themeBannerDefinition = $themeBrandingDefinitions['banner'] ?? null;
    // $themeSeparatorDefinition stores the optional separator definition.
    $themeSeparatorDefinition = $themeBrandingDefinitions['separator'] ?? null;

    $banner = null;
    if (is_array($themeBannerDefinition)) {
        $bannerLabel = (string) ($themeBannerDefinition['label'] ?? '');
        $banner = [
            'label' => $bannerLabel,
            'description' => (string) ($themeBannerDefinition['description'] ?? ''),
            'asset_url' => theme_branding_asset_url('banner'),
            'current_alt' => t('admin.theme.media.current_branding_alt', 'Current {label}', ['label' => $bannerLabel]),
            'remove_label' => t('admin.theme.media.remove_branding_asset', 'Remove {label}', ['label' => $bannerLabel]),
        ];
    }

    $separator = null;
    if (is_array($themeSeparatorDefinition)) {
        $separatorLabel = (string) ($themeSeparatorDefinition['label'] ?? '');
        $separator = [
            'label' => $separatorLabel,
            'description' => (string) ($themeSeparatorDefinition['description'] ?? ''),
            'asset_url' => theme_branding_asset_url('separator'),
            'current_alt' => t('admin.theme.media.current_branding_alt', 'Current {label}', ['label' => $separatorLabel]),
            'remove_label' => t('admin.theme.media.remove_branding_asset', 'Remove {label}', ['label' => $separatorLabel]),
            'width' => theme_branding_separator_width_value($theme['branding_separator_width'] ?? null),
            'height' => theme_branding_separator_height_value($theme['branding_separator_height'] ?? null),
            'stretch' => theme_branding_separator_stretch_enabled($theme['branding_separator_stretch'] ?? null),
        ];
    }

    // $faviconUrl stores the current browser icon asset URL.
    $faviconUrl = favicon_asset_url();
    // $backgroundMaxSide stores the configured longest side for the optimized background.
    $backgroundMaxSide = theme_background_optimized_max_side_value($theme['background_optimized_max_side'] ?? null);
    // $themeBackgroundUrl stores the current global Theme background URL.
    $themeBackgroundUrl = theme_background_asset_url();
    // $themeOriginalUrl stores the explicit original-background URL when an original exists.
    $themeOriginalUrl = theme_background_original_path() !== null ? url_for('theme_background_asset') . '&variant=original' : '';
    // $themeOptimizedActive records whether the optimized WebP background exists.
    $themeOptimizedActive = theme_background_optimized_path() !== null;
    // $backgroundSizeTemplate stores the localized dynamic size label template.
    $backgroundSizeTemplate = t('admin.theme.media.background_optimized_size_value', '{size}px longest side');

    view_render_admin_theme_media_tab([
        'banner' => $banner,
        'separator' => $separator,
        'favicon_url' => $faviconUrl,
        'favicon_version' => $faviconUrl !== '' ? (string) app_setting('favicon_version', '1') : '1',
        'background' => [
            'asset_url' => $themeBackgroundUrl,
            'original_url' => $themeOriginalUrl,
            'optimized_active' => $themeOptimizedActive,
            'has_background' => $themeBackgroundUrl !== '',
            'optimized_max_side' => $backgroundMaxSide,
            'optimized_size_label' => t('admin.theme.media.background_optimized_size_value', '{size}px longest side', ['size' => (string) $backgroundMaxSide]),
            'opacity' => (int) ($theme['background_opacity'] ?? 65),
            'source' => theme_background_source(),
        ],
        'labels' => [
            'kicker' => t('admin.theme.media.kicker', 'Branding & media'),
            'title' => t('admin.theme.media.title', 'Header branding, separator, favicon, and backgrounds'),
            'description' => t('admin.theme.media.description', 'Manage the public header images first, then browser identity and the global gallery background fallback.'),
            'subtab_header' => t('admin.theme.subtab_header_images', 'Header images'),
            'subtab_favicon' => t('admin.theme.subtab_browser_icon', 'Browser icon'),
            'subtab_background' => t('admin.theme.subtab_background', 'Background'),
            'subtabs_label' => t('admin.theme.media.subtabs_label', 'Branding and media subsections'),
            'public_header_banner' => t('admin.theme.media.public_header_banner', 'Public header banner'),
            'public_header_banner_hint' => t('admin.theme.media.public_header_banner_hint', 'Upload the default public header banner here. It replaces the visible site title when no gallery-specific banner is configured.'),
            'public_header_separator' => t('admin.theme.media.public_header_separator', 'Public header separator'),
            'public_header_separator_hint' => t('admin.theme.media.public_header_separator_hint', 'Upload and size the decorative horizontal separator shown under the shared public header. Per-gallery separators still override this Theme fallback on their gallery page.'),
            'no_fallback_image' => t('admin.theme.media.no_fallback_image', 'No fallback image is stored yet.'),
            'upload_replacement' => t('admin.theme.media.upload_replacement', 'Upload replacement'),
            'accepted_formats' => t('admin.theme.media.accepted_formats_8mb', 'Accepted formats: JPG, PNG, GIF, WebP. Maximum size: 8 MB.'),
            'separator_width' => t('admin.theme.media.separator_width', 'Separator width'),
            'separator_width_hint' => t('admin.theme.media.separator_width_hint', 'Pixels. Use 0 to keep the current responsive page width.'),
            'separator_height' => t('admin.theme.media.separator_height', 'Separator height'),
            'separator_height_hint' => t('admin.theme.media.separator_height_hint', 'Pixels. With aspect ratio enabled this is a maximum; with stretching enabled this is the exact render height.'),
            'separator_stretch' => t('admin.theme.media.separator_stretch', 'Stretch to exact width and height'),
            'separator_stretch_hint' => t('admin.theme.media.separator_stretch_hint', 'Allows the separator image to scale non-proportionally instead of preserving its original aspect ratio.'),
            'favicon_legend' => t('admin.theme.media.favicon_legend', 'Favicon'),
            'current_favicon_alt' => t('admin.theme.media.current_favicon_alt', 'Current favicon'),
            'current_favicon_hint' => t('admin.theme.media.current_favicon_hint', 'Current favicon is generated as 32px, 48px, and 180px PNG variants.'),
            'no_favicon' => t('admin.theme.media.no_favicon', 'No favicon is stored yet. Browsers will use their default icon until one is saved.'),
            'favicon_source_image' => t('admin.theme.media.favicon_source_image', 'Favicon source image'),
            'favicon_source_hint' => t('admin.theme.media.favicon_source_hint', 'Upload a square-friendly photo or logo. The cropper saves a browser-ready square PNG favicon.'),
            'zoom' => t('admin.theme.media.zoom', 'Zoom'),
            'favicon_crop_hint' => t('admin.theme.media.favicon_crop_hint', 'Drag the image to place the square crop. The small preview shows the browser icon scale.'),
            'background_legend' => t('admin.theme.media.background_legend', 'Background'),
            'current_theme_background_alt' => t('admin.theme.media.current_theme_background_alt', 'Selected background preview'),
            'background_selected' => t('admin.theme.media.background_selected', 'Background selected'),
            'background_not_selected' => t('admin.theme.media.background_not_selected', 'No background selected'),
            'background_optimized_ready' => t('admin.theme.media.background_optimized_ready', 'Optimized WebP is active'),
            'background_serving_original' => t('admin.theme.media.background_serving_original', 'Serving the original image'),
            'no_theme_background' => t('admin.theme.media.no_theme_background', 'No global theme background image is stored yet.'),
            'theme_background_image' => t('admin.theme.media.theme_background_image', 'Choose background image'),
            'theme_background_image_hint' => t('admin.theme.media.theme_background_image_hint', 'Upload the image you want to keep as the original. The gallery can serve a smaller WebP copy for visitors.'),
            'background_optimized_size' => t('admin.theme.media.background_optimized_size', 'Optimized display size'),
            'background_optimized_size_template' => $backgroundSizeTemplate,
            'background_optimized_size_hint' => t('admin.theme.media.background_optimized_size_hint', 'Use 1920px for normal screens, 2560px or more for very large displays.'),
            'regenerate_optimized_background' => t('admin.theme.media.regenerate_optimized_background', 'Regenerate optimized background'),
            'generate_optimized_background' => t('admin.theme.media.generate_optimized_background', 'Generate optimized background'),
            'delete_optimized_background' => t('admin.theme.media.delete_optimized_background', 'Delete optimized copy'),
            'view_served_image' => t('admin.theme.media.view_served_image', 'View used image'),
            'view_original_image' => t('admin.theme.media.view_original_image', 'View original'),
            'background_transparency' => t('admin.theme.media.background_transparency', 'Background transparency'),
            'background_transparency_hint' => t('admin.theme.media.background_transparency_hint', 'Higher means more visible image, lower means more of the color underneath.'),
            'gallery_background_fallback' => t('admin.theme.media.gallery_background_fallback', 'Gallery background fallback'),
            'background_fallback_none' => t('admin.theme.media.background_fallback_none', 'No fallback set'),
            'background_fallback_upload' => t('admin.theme.media.background_fallback_upload', 'Upload new image'),
            'background_fallback_existing' => t('admin.theme.media.background_fallback_existing', 'Pick from existing gallery images'),
            'background_fallback_collage' => t('admin.theme.media.background_fallback_collage', 'Generate collage from public galleries'),
            'gallery_background_fallback_hint' => t('admin.theme.media.gallery_background_fallback_hint', 'Used when a gallery does not set its own background source.'),
            'reset_all_gallery_backgrounds' => t('admin.theme.media.reset_all_gallery_backgrounds', 'Reset all gallery backgrounds'),
            'remove_theme_background' => t('admin.theme.media.remove_theme_background', 'Remove theme background'),
            'remove_favicon' => t('admin.theme.media.remove_favicon', 'Remove favicon'),
        ],
    ]);
}
