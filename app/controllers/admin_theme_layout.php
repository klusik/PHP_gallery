<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_layout.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders Theme layout controls for favorite shortcuts, card design, thumbnail rendering, pagination, lightbox mode, and public grids.
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
use function Gallery\Views\view_render_admin_theme_layout_tab;

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
 * Render the Theme layout tab.
 *
 * @param array $theme Current theme settings.
 * @param array $paginationSettings Global pagination settings.
 * @param array $homeGridSettings Main-page gallery grid settings.
 * @param string $publicThumbnailRenderingMode Public thumbnail renderer mode.
 * @param bool $lightboxModesFeatureEnabled Whether lightbox mode settings are enabled.
 */
function render_admin_theme_layout_tab(array $theme, array $paginationSettings, array $homeGridSettings, string $publicThumbnailRenderingMode, bool $lightboxModesFeatureEnabled): void
{
    // $favoriteGalleryIds stores configured Theme header shortcuts.
    $favoriteGalleryIds = function_exists('Gallery\\Services\\theme_favorite_gallery_ids') ? theme_favorite_gallery_ids() : [];
    // $favoriteShortcuts stores presentation-only shortcut rows for the view.
    $favoriteShortcuts = [];
    for ($favoriteIndex = 0; $favoriteIndex < THEME_FAVORITE_GALLERIES_MAX; $favoriteIndex++) {
        // $selectedFavoriteShortcut stores the configured shortcut for one visible slot.
        $selectedFavoriteShortcut = $favoriteGalleryIds[$favoriteIndex] ?? '';
        // $selectedFavoriteType stores whether this slot targets nothing, the main page, or a gallery.
        $selectedFavoriteType = $selectedFavoriteShortcut === THEME_FAVORITE_GALLERIES_HOME_TOKEN ? THEME_FAVORITE_GALLERIES_HOME_TOKEN : ((int) $selectedFavoriteShortcut > 0 ? 'gallery' : '');
        // $selectedFavoriteGalleryId stores the configured gallery ID when this slot targets a gallery.
        $selectedFavoriteGalleryId = $selectedFavoriteType === 'gallery' ? (int) $selectedFavoriteShortcut : 0;
        $pickerHtml = '';
        $fallbackOptionsHtml = '';
        if (function_exists('Gallery\\Controllers\\render_gallery_search_picker')) {
            $pickerHtml = render_gallery_search_picker('theme_favorite_gallery_ids[]', $selectedFavoriteGalleryId, 0, [
                'id' => 'theme-favorite-gallery-' . ($favoriteIndex + 1),
                'placeholder' => t('admin.theme.layout.favorite_gallery_placeholder', 'Search gallery by name or path'),
                'disable_prefill' => true,
            ]);
        } else {
            $fallbackOptionsHtml = gallery_options_for_select($selectedFavoriteGalleryId);
        }
        $favoriteShortcuts[] = [
            'selected_type' => $selectedFavoriteType,
            'slot_label' => t('admin.theme.layout.favorite_gallery_slot', 'Shortcut {number}', ['number' => $favoriteIndex + 1]),
            'picker_html' => $pickerHtml,
            'fallback_options_html' => $fallbackOptionsHtml,
        ];
    }

    // $currentDescriptionLayout stores the normalized Theme gallery description layout.
    $currentDescriptionLayout = gallery_description_layout_normalize((string) ($theme['gallery_description_layout'] ?? 'vertical'));
    // $descriptionLayouts stores presentation-only layout options and preview text.
    $descriptionLayouts = [];
    foreach (gallery_description_layout_options() as $descriptionLayoutOption) {
        $descriptionLayouts[] = [
            'value' => $descriptionLayoutOption,
            'label' => gallery_description_layout_label($descriptionLayoutOption),
            'selected' => $currentDescriptionLayout === $descriptionLayoutOption,
            'summary' => $descriptionLayoutOption === 'horizontal'
                ? t('admin.theme.layout.description_layout_horizontal_summary', 'Image first, then a compact story card below it.')
                : t('admin.theme.layout.description_layout_vertical_summary', 'Image and text side by side, close to the classic gallery look.'),
        ];
    }

    // $lightboxOptions stores presentation-only browsing-mode rows when the feature is enabled.
    $lightboxOptions = [];
    if ($lightboxModesFeatureEnabled) {
        foreach (gallery_lightbox_browsing_mode_options() as $lightboxModeOption) {
            $lightboxOptions[] = [
                'value' => $lightboxModeOption,
                'label' => gallery_lightbox_browsing_mode_label($lightboxModeOption),
                'selected' => ($theme['lightbox_browsing_mode'] ?? 'single') === $lightboxModeOption,
            ];
        }
    }

    view_render_admin_theme_layout_tab([
        'favorite_shortcuts' => $favoriteShortcuts,
        'home_token' => THEME_FAVORITE_GALLERIES_HOME_TOKEN,
        'description_layouts' => $descriptionLayouts,
        'gallery_count_badge_enabled' => ((string) ($theme['gallery_count_badge_enabled'] ?? '1')) === '1',
        'thumbnail_modes' => [
            [
                'value' => PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE,
                'selected' => $publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE,
                'label' => t('admin.theme.layout.thumbnail_rendering_progressive_label', 'Progressive thumbnail sharpening - Default'),
            ],
            [
                'value' => PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE,
                'selected' => $publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE,
                'label' => t('admin.theme.layout.thumbnail_rendering_responsive_label', 'Responsive browser selection - Legacy'),
            ],
        ],
        'pagination' => $paginationSettings,
        'home_grid' => $homeGridSettings,
        'max_columns' => CMS_PAGINATION_MAX_COLUMNS,
        'max_rows' => CMS_PAGINATION_MAX_ROWS,
        'lightbox_modes_enabled' => $lightboxModesFeatureEnabled,
        'lightbox_options' => $lightboxOptions,
        'labels' => [
            'kicker' => t('admin.theme.layout.kicker', 'Layout'),
            'title' => t('admin.theme.layout.title', 'Pagination and gallery grids'),
            'description' => t('admin.theme.layout.description', 'Tune the default public grid while keeping per-gallery overrides available from gallery editing.'),
            'subtab_shortcuts' => t('admin.theme.subtab_shortcuts', 'Header shortcuts'),
            'subtab_cards' => t('admin.theme.subtab_cards_badges', 'Cards & badges'),
            'subtab_grids' => t('admin.theme.subtab_grids_lightbox', 'Grids & lightbox'),
            'subtabs_label' => t('admin.theme.layout.subtabs_label', 'Layout subsections'),
            'favorite_galleries_legend' => t('admin.theme.layout.favorite_galleries_legend', 'Favorite gallery shortcuts'),
            'favorite_galleries_hint' => t('admin.theme.layout.favorite_galleries_hint', 'Choose up to three shortcuts to show as direct buttons in the top header navigation. Each slot can point to the main page or to one gallery. Leave all three empty to hide the old Galleries button completely.'),
            'favorite_gallery_type' => t('admin.theme.layout.favorite_gallery_type', 'Shortcut target'),
            'favorite_gallery_empty' => t('admin.theme.layout.favorite_gallery_empty', 'No shortcut'),
            'favorite_gallery_home' => t('admin.theme.layout.favorite_gallery_home', 'Main page'),
            'favorite_gallery_gallery' => t('admin.theme.layout.favorite_gallery_gallery', 'Gallery'),
            'favorite_gallery_gallery_hint' => t('admin.theme.layout.favorite_gallery_gallery_hint', 'Gallery picker is used only when the shortcut target is Gallery.'),
            'favorite_galleries_visibility_hint' => t('admin.theme.layout.favorite_galleries_visibility_hint', 'Deleted galleries and duplicate selections are ignored on save. Anonymous visitors only see configured favorites that remain public and listed. Main page shortcuts stay visible to all visitors.'),
            'description_layout_legend' => t('admin.theme.layout.description_layout_legend', 'Gallery description format'),
            'description_layout_hint' => t('admin.theme.layout.description_layout_hint', 'Choose how gallery intro cards should feel on public pages. The preview uses your current Theme colors, corners, and typography.'),
            'description_layout_label' => t('admin.theme.layout.description_layout_label', 'Default gallery-card layout'),
            'description_preview_title' => t('admin.theme.layout.description_preview_title', 'Summer gallery'),
            'description_preview_meta' => t('admin.theme.layout.description_preview_meta', '12 photos'),
            'description_preview_tag_travel' => t('admin.theme.layout.description_preview_tag_travel', 'travel'),
            'description_preview_tag_family' => t('admin.theme.layout.description_preview_tag_family', 'family'),
            'count_badge_legend' => t('admin.theme.layout.count_badge_legend', 'Contained-picture badge'),
            'show_count_badge' => t('admin.theme.layout.show_count_badge', 'Show stacked-picture image count on gallery cards and opened gallery heroes'),
            'count_badge_hint' => t('admin.theme.layout.count_badge_hint', 'Enabled by default. Individual galleries can inherit this setting or override it in the gallery editor.'),
            'thumbnail_rendering_legend' => t('admin.theme.layout.thumbnail_rendering_legend', 'Public thumbnail rendering'),
            'thumbnail_rendering_label' => t('admin.theme.layout.thumbnail_rendering_label', 'Selected-gallery photo cards'),
            'thumbnail_rendering_responsive_title' => t('admin.theme.layout.thumbnail_rendering_responsive_title', 'Responsive browser selection:'),
            'thumbnail_rendering_responsive_help' => t('admin.theme.layout.thumbnail_rendering_responsive_help', 'The complete responsive candidate set is exposed immediately and the browser selects the most appropriate available thumbnail.'),
            'thumbnail_rendering_progressive_title' => t('admin.theme.layout.thumbnail_rendering_progressive_title', 'Progressive thumbnail sharpening:'),
            'thumbnail_rendering_progressive_help' => t('admin.theme.layout.thumbnail_rendering_progressive_help', 'A small thumbnail is presented first. Larger thumbnails are activated later for relevant visible or near-visible cards, prioritizing initial page responsiveness over earliest full sharpness.'),
            'thumbnail_rendering_transfer_note' => t('admin.theme.layout.thumbnail_rendering_transfer_note', 'Progressive rendering can transfer both the small thumbnail and a larger replacement, potentially increasing total transferred bytes while improving perceived initial responsiveness.'),
            'thumbnail_rendering_scope_note' => t('admin.theme.layout.thumbnail_rendering_scope_note', 'This setting applies to photo cards in a selected gallery. Gallery cover and collage thumbnails keep responsive browser selection.'),
            'pagination_legend' => t('admin.theme.layout.pagination_legend', 'Pagination'),
            'enable_pagination' => t('admin.theme.layout.enable_pagination', 'Enable pagination'),
            'columns_per_page' => t('admin.theme.layout.columns_per_page', 'Columns per page'),
            'rows_per_page' => t('admin.theme.layout.rows_per_page', 'Rows per page'),
            'items_per_page_preview' => t('admin.theme.layout.items_per_page_preview', 'Items per page preview:'),
            'pagination_hint' => t('admin.theme.layout.pagination_hint', 'These values remain the fallback for galleries that do not define or inherit a custom grid.'),
            'lightbox_mode_legend' => t('admin.theme.layout.lightbox_mode_legend', 'Public lightbox browsing mode'),
            'lightbox_mode_label' => t('admin.theme.layout.lightbox_mode_label', 'Default browsing mode'),
            'lightbox_mode_hint' => t('admin.theme.layout.lightbox_mode_hint', 'Single image keeps the classic viewer. Picture strip adds compact nearby thumbnails below the photo. 3D carousel places a few neighboring photos behind the main image with depth and scale. Individual galleries may inherit this value or override it.'),
            'main_page_grid_legend' => t('admin.theme.layout.main_page_grid_legend', 'Main page gallery grid'),
            'main_page_columns' => t('admin.theme.layout.main_page_columns', 'Main page columns'),
            'main_page_rows' => t('admin.theme.layout.main_page_rows', 'Main page rows'),
            'main_page_grid_hint' => t('admin.theme.layout.main_page_grid_hint', 'This affects only the front page where top-level galleries are listed. It can use a different grid than gallery pages and inherited subgallery pages.'),
            'reset_gallery_grids_confirm' => t('admin.theme.layout.reset_gallery_grids_confirm', 'Reset all custom per-gallery grid settings? The global Theme grid and main page grid will stay unchanged.'),
            'reset_all_gallery_grids' => t('admin.theme.layout.reset_all_gallery_grids', 'Reset all custom gallery grids'),
            'reset_gallery_grids_hint' => t('admin.theme.layout.reset_gallery_grids_hint', 'This clears every per-gallery custom grid and resets subgallery inheritance flags to default. It also removes matching grid keys from gallery.json files, so future scans cannot re-import stale custom grid settings.'),
        ],
    ]);
}
