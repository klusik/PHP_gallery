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
    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.layout.kicker', 'Layout'),
        'title' => t('admin.theme.layout.title', 'Pagination and gallery grids'),
        'description' => t('admin.theme.layout.description', 'Tune the default public grid while keeping per-gallery overrides available from gallery editing.'),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope>';
    render_admin_subtabs([
        ['id' => 'admin-theme-layout-subtab-shortcuts', 'label' => t('admin.theme.subtab_shortcuts', 'Header shortcuts')],
        ['id' => 'admin-theme-layout-subtab-cards', 'label' => t('admin.theme.subtab_cards_badges', 'Cards & badges')],
        ['id' => 'admin-theme-layout-subtab-grids', 'label' => t('admin.theme.subtab_grids_lightbox', 'Grids & lightbox')],
    ], 'admin-theme-layout-subtab-shortcuts', t('admin.theme.layout.subtabs_label', 'Layout subsections'));
    ob_start();
    echo '<div class="theme-tab-card-grid">';
    $favoriteGalleryIds = function_exists('Gallery\\Services\\theme_favorite_gallery_ids') ? theme_favorite_gallery_ids() : [];
    echo '<fieldset class="form-grid admin-theme-favorite-galleries" id="admin-theme-favorite-galleries"><legend>' . e(t('admin.theme.layout.favorite_galleries_legend', 'Favorite gallery shortcuts')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.layout.favorite_galleries_hint', 'Choose up to three shortcuts to show as direct buttons in the top header navigation. Each slot can point to the main page or to one gallery. Leave all three empty to hide the old Galleries button completely.')) . '</p>';
    echo '<div class="admin-theme-favorite-gallery-list">';
    for ($favoriteIndex = 0; $favoriteIndex < THEME_FAVORITE_GALLERIES_MAX; $favoriteIndex++) {
        // $selectedFavoriteShortcut stores the configured shortcut for one visible slot.
        $selectedFavoriteShortcut = $favoriteGalleryIds[$favoriteIndex] ?? '';
        // $selectedFavoriteType stores whether this slot targets nothing, the main page, or a gallery.
        $selectedFavoriteType = $selectedFavoriteShortcut === THEME_FAVORITE_GALLERIES_HOME_TOKEN ? THEME_FAVORITE_GALLERIES_HOME_TOKEN : ((int) $selectedFavoriteShortcut > 0 ? 'gallery' : '');
        // $selectedFavoriteGalleryId stores the configured gallery ID when this slot targets a gallery.
        $selectedFavoriteGalleryId = $selectedFavoriteType === 'gallery' ? (int) $selectedFavoriteShortcut : 0;
        echo '<div class="admin-theme-favorite-gallery-slot"><strong>' . e(t('admin.theme.layout.favorite_gallery_slot', 'Shortcut {number}', ['number' => $favoriteIndex + 1])) . '</strong>';
        echo '<label>' . e(t('admin.theme.layout.favorite_gallery_type', 'Shortcut target')) . '<select name="theme_favorite_gallery_types[]">';
        echo '<option value=""' . ($selectedFavoriteType === '' ? ' selected' : '') . '>' . e(t('admin.theme.layout.favorite_gallery_empty', 'No shortcut')) . '</option>';
        echo '<option value="' . e(THEME_FAVORITE_GALLERIES_HOME_TOKEN) . '"' . ($selectedFavoriteType === THEME_FAVORITE_GALLERIES_HOME_TOKEN ? ' selected' : '') . '>' . e(t('admin.theme.layout.favorite_gallery_home', 'Main page')) . '</option>';
        echo '<option value="gallery"' . ($selectedFavoriteType === 'gallery' ? ' selected' : '') . '>' . e(t('admin.theme.layout.favorite_gallery_gallery', 'Gallery')) . '</option>';
        echo '</select></label>';
        if (function_exists('Gallery\\Controllers\\render_gallery_search_picker')) {
            echo render_gallery_search_picker('theme_favorite_gallery_ids[]', $selectedFavoriteGalleryId, 0, [
                'id' => 'theme-favorite-gallery-' . ($favoriteIndex + 1),
                'placeholder' => t('admin.theme.layout.favorite_gallery_placeholder', 'Search gallery by name or path'),
                'disable_prefill' => true,
            ]);
        } else {
            echo '<select name="theme_favorite_gallery_ids[]"><option value="">' . e(t('admin.theme.layout.favorite_gallery_empty', 'No shortcut')) . '</option>' . gallery_options_for_select($selectedFavoriteGalleryId) . '</select>';
        }
        echo '<small class="muted">' . e(t('admin.theme.layout.favorite_gallery_gallery_hint', 'Gallery picker is used only when the shortcut target is Gallery.')) . '</small>';
        echo '</div>';
    }
    echo '</div>';
    echo '<p class="muted">' . e(t('admin.theme.layout.favorite_galleries_visibility_hint', 'Deleted galleries and duplicate selections are ignored on save. Anonymous visitors only see configured favorites that remain public and listed. Main page shortcuts stay visible to all visitors.')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutShortcutsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-shortcuts', $layoutShortcutsHtml, true);

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    echo '<fieldset class="form-grid admin-theme-description-layout" id="admin-gallery-description-layout"><legend>' . e(t('admin.theme.layout.description_layout_legend', 'Gallery description format')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.layout.description_layout_hint', 'Choose how gallery intro cards should feel on public pages. The preview uses your current Theme colors, corners, and typography.')) . '</p>';
    echo '<label class="admin-theme-description-select">' . e(t('admin.theme.layout.description_layout_label', 'Default gallery-card layout')) . '<select name="theme_gallery_description_layout" data-theme-description-layout-select>';
    $currentDescriptionLayout = gallery_description_layout_normalize((string) ($theme['gallery_description_layout'] ?? 'vertical'));
    foreach (gallery_description_layout_options() as $descriptionLayoutOption) {
        echo '<option value="' . e($descriptionLayoutOption) . '"' . ($currentDescriptionLayout === $descriptionLayoutOption ? ' selected' : '') . '>' . e(gallery_description_layout_label($descriptionLayoutOption)) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="admin-theme-description-layout-picker" data-theme-description-layout-picker>';
    foreach (gallery_description_layout_options() as $descriptionLayoutOption) {
        $isHorizontalPreview = $descriptionLayoutOption === 'horizontal';
        echo '<button type="button" class="admin-theme-description-card" data-theme-description-layout-option="' . e($descriptionLayoutOption) . '" aria-pressed="' . ($currentDescriptionLayout === $descriptionLayoutOption ? 'true' : 'false') . '">';
        echo '<span class="admin-theme-description-card-copy"><strong>' . e(gallery_description_layout_label($descriptionLayoutOption)) . '</strong><span>' . e($isHorizontalPreview ? t('admin.theme.layout.description_layout_horizontal_summary', 'Image first, then a compact story card below it.') : t('admin.theme.layout.description_layout_vertical_summary', 'Image and text side by side, close to the classic gallery look.')) . '</span></span>';
        echo '<span class="admin-theme-description-card-preview is-' . e($descriptionLayoutOption) . '" aria-hidden="true">';
        echo '<span class="admin-theme-description-media"><span></span></span>';
        echo '<span class="admin-theme-description-body"><span class="admin-theme-description-title">' . e(t('admin.theme.layout.description_preview_title', 'Summer gallery')) . '</span><span class="admin-theme-description-meta">' . e(t('admin.theme.layout.description_preview_meta', '12 photos')) . '</span><span class="admin-theme-description-tags"><i>' . e(t('admin.theme.layout.description_preview_tag_travel', 'travel')) . '</i><i>' . e(t('admin.theme.layout.description_preview_tag_family', 'family')) . '</i><i>2026</i></span><span class="admin-theme-description-line is-wide"></span><span class="admin-theme-description-line"></span></span>';
        echo '</span>';
        echo '</button>';
    }
    echo '</div>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-gallery-count-badge"><legend>' . e(t('admin.theme.layout.count_badge_legend', 'Contained-picture badge')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="theme_gallery_count_badge_enabled" value="1"' . (((string) ($theme['gallery_count_badge_enabled'] ?? '1')) === '1' ? ' checked' : '') . '> ' . e(t('admin.theme.layout.show_count_badge', 'Show stacked-picture icon and image count on gallery cards')) . '</label>';
    echo '<p class="muted">' . e(t('admin.theme.layout.count_badge_hint', 'Enabled by default. Individual galleries can inherit this setting or override it in the gallery editor.')) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid" id="admin-public-thumbnail-rendering"><legend>' . e(t('admin.theme.layout.thumbnail_rendering_legend', 'Public thumbnail rendering')) . '</legend>';
    echo '<label>' . e(t('admin.theme.layout.thumbnail_rendering_label', 'Selected-gallery photo cards')) . '<select name="public_thumbnail_rendering_mode" aria-describedby="admin-public-thumbnail-rendering-help admin-public-thumbnail-rendering-transfer">';
    echo '<option value="' . e(PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE) . '"' . ($publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE ? ' selected' : '') . '>' . e(t('admin.theme.layout.thumbnail_rendering_progressive_label', 'Progressive thumbnail sharpening - Default')) . '</option>';
    echo '<option value="' . e(PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE) . '"' . ($publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE ? ' selected' : '') . '>' . e(t('admin.theme.layout.thumbnail_rendering_responsive_label', 'Responsive browser selection - Legacy')) . '</option>';
    echo '</select></label>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-help"><strong>' . e(t('admin.theme.layout.thumbnail_rendering_responsive_title', 'Responsive browser selection:')) . '</strong> ' . e(t('admin.theme.layout.thumbnail_rendering_responsive_help', 'The complete responsive candidate set is exposed immediately and the browser selects the most appropriate available thumbnail.')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('admin.theme.layout.thumbnail_rendering_progressive_title', 'Progressive thumbnail sharpening:')) . '</strong> ' . e(t('admin.theme.layout.thumbnail_rendering_progressive_help', 'A small thumbnail is presented first. Larger thumbnails are activated later for relevant visible or near-visible cards, prioritizing initial page responsiveness over earliest full sharpness.')) . '</p>';
    echo '<p class="muted" id="admin-public-thumbnail-rendering-transfer">' . e(t('admin.theme.layout.thumbnail_rendering_transfer_note', 'Progressive rendering can transfer both the small thumbnail and a larger replacement, potentially increasing total transferred bytes while improving perceived initial responsiveness.')) . '</p>';
    echo '<p class="muted">' . e(t('admin.theme.layout.thumbnail_rendering_scope_note', 'This setting applies to photo cards in a selected gallery. Gallery cover and collage thumbnails keep responsive browser selection.')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutCardsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-cards', $layoutCardsHtml, false);

    ob_start();
    echo '<div class="theme-tab-card-grid">';
    echo '<fieldset class="form-grid" id="admin-pagination"><legend>' . e(t('admin.theme.layout.pagination_legend', 'Pagination')) . '</legend>';
    echo '<label class="checkbox-label"><input type="checkbox" name="pagination_enabled" value="1"' . (!empty($paginationSettings['enabled']) ? ' checked' : '') . '> ' . e(t('admin.theme.layout.enable_pagination', 'Enable pagination')) . '</label>';
    echo '<label>' . e(t('admin.theme.layout.columns_per_page', 'Columns per page')) . ' <span class="muted" data-pagination-columns-display>' . (int) $paginationSettings['columns'] . '</span><input type="range" name="pagination_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $paginationSettings['columns'] . '" data-pagination-columns></label>';
    echo '<label>' . e(t('admin.theme.layout.rows_per_page', 'Rows per page')) . ' <span class="muted" data-pagination-rows-display>' . (int) $paginationSettings['rows'] . '</span><input type="range" name="pagination_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $paginationSettings['rows'] . '" data-pagination-rows></label>';
    echo '<p class="muted">' . e(t('admin.theme.layout.items_per_page_preview', 'Items per page preview:')) . ' <span data-pagination-items-preview>' . (int) $paginationSettings['items_per_page'] . '</span></p>';
    echo '<p class="muted">' . e(t('admin.theme.layout.pagination_hint', 'These values remain the fallback for galleries that do not define or inherit a custom grid.')) . '</p>';
    echo '</fieldset>';
    if ($lightboxModesFeatureEnabled) {
        echo '<fieldset class="form-grid" id="admin-lightbox-mode"><legend>' . e(t('admin.theme.layout.lightbox_mode_legend', 'Public lightbox browsing mode')) . '</legend>';
        echo '<label>' . e(t('admin.theme.layout.lightbox_mode_label', 'Default browsing mode')) . '<select name="theme_lightbox_browsing_mode">';
        foreach (gallery_lightbox_browsing_mode_options() as $lightboxModeOption) {
            echo '<option value="' . e($lightboxModeOption) . '"' . (($theme['lightbox_browsing_mode'] ?? 'single') === $lightboxModeOption ? ' selected' : '') . '>' . e(gallery_lightbox_browsing_mode_label($lightboxModeOption)) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('admin.theme.layout.lightbox_mode_hint', 'Single image keeps the classic viewer. Picture strip adds compact nearby thumbnails below the photo. 3D carousel places a few neighboring photos behind the main image with depth and scale. Individual galleries may inherit this value or override it.')) . '</span></label>';
        echo '</fieldset>';
    }
    echo '<fieldset class="form-grid" id="admin-home-grid"><legend>' . e(t('admin.theme.layout.main_page_grid_legend', 'Main page gallery grid')) . '</legend>';
    echo '<label>' . e(t('admin.theme.layout.main_page_columns', 'Main page columns')) . ' <span class="muted" data-home-grid-columns-display>' . (int) $homeGridSettings['columns'] . '</span><input type="range" name="home_gallery_grid_columns" min="1" max="' . CMS_PAGINATION_MAX_COLUMNS . '" value="' . (int) $homeGridSettings['columns'] . '" data-home-grid-columns></label>';
    echo '<label>' . e(t('admin.theme.layout.main_page_rows', 'Main page rows')) . ' <span class="muted" data-home-grid-rows-display>' . (int) $homeGridSettings['rows'] . '</span><input type="range" name="home_gallery_grid_rows" min="1" max="' . CMS_PAGINATION_MAX_ROWS . '" value="' . (int) $homeGridSettings['rows'] . '" data-home-grid-rows></label>';
    echo '<p class="muted">' . e(t('admin.theme.layout.main_page_grid_hint', 'This affects only the front page where top-level galleries are listed. It can use a different grid than gallery pages and inherited subgallery pages.')) . '</p>';
    echo '<div class="bulk-row"><button type="submit" class="secondary" name="reset_all_gallery_grid_overrides" value="1" formnovalidate onclick="return confirm(&quot;' . e(t('admin.theme.layout.reset_gallery_grids_confirm', 'Reset all custom per-gallery grid settings? The global Theme grid and main page grid will stay unchanged.')) . '&quot;);">' . e(t('admin.theme.layout.reset_all_gallery_grids', 'Reset all custom gallery grids')) . '</button></div>';
    echo '<p class="muted">' . e(t('admin.theme.layout.reset_gallery_grids_hint', 'This clears every per-gallery custom grid and resets subgallery inheritance flags to default. It also removes matching grid keys from gallery.json files, so future scans cannot re-import stale custom grid settings.')) . '</p>';
    echo '</fieldset>';
    echo '</div>';
    $layoutGridsHtml = ob_get_clean();
    render_admin_subtab_panel('admin-theme-layout-subtab-grids', $layoutGridsHtml, false);
    echo '</div>';
    $layoutHtml = ob_get_clean();
    render_admin_tab_panel('admin-theme-tab-layout', $layoutHtml, false);

}