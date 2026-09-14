<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_language.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders language selection, pack inventory, pack editor, coverage information, and translation diagnostics.
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
use const Gallery\Services\CMS_ADMIN_LANGUAGE_COOKIE;
use const Gallery\Services\CMS_LANGUAGE_COOKIE;
use function Gallery\Services\translation_clear_missing_diagnostics;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_detected_language_packs;
use function Gallery\Services\translation_language_allowed;
use function Gallery\Services\translation_language_coverage;
use function Gallery\Services\translation_language_pack_json_text;
use function Gallery\Services\translation_missing_diagnostics;
use function Gallery\Services\translation_normalize_language_code;
use function Gallery\Services\translation_public_language;
use function Gallery\Services\translation_public_language_selector_enabled;
use function Gallery\Services\translation_public_language_selector_languages;
use function Gallery\Services\translation_public_language_selector_design;
use function Gallery\Services\translation_public_language_selector_view_data;
use function Gallery\Services\translation_supported_languages;
use function Gallery\Services\translation_save_language_json;
use function Gallery\Services\translation_set_active_language;
use function Gallery\Services\translation_set_public_language;
use function Gallery\Views\view_render_admin_hero;
use function Gallery\Views\view_render_admin_tab_intro;
use function Gallery\Views\view_render_admin_theme_language_tab;
use function Gallery\Views\view_render_public_language_selector_settings_panel;

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
 * Render the Theme language tab.
 */
function render_admin_theme_language_tab(): void
{
    // $supportedLanguages stores the language codes currently exposed to Admin and public selectors.
    $supportedLanguages = translation_supported_languages();
    // $languagePacks stores only detected packs that are currently selectable.
    $languagePacks = array_values(array_filter(
        translation_detected_language_packs(),
        static fn (array $pack): bool => in_array((string) ($pack['code'] ?? ''), $supportedLanguages, true)
    ));
    // $adminLanguage stores the language selected for the admin interface.
    $adminLanguage = translation_admin_language(
        (string) ($_COOKIE[CMS_ADMIN_LANGUAGE_COOKIE] ?? ''),
        (string) ($_COOKIE[CMS_LANGUAGE_COOKIE] ?? '')
    );
    // $publicLanguage stores the saved default language for anonymous public visitors.
    $publicLanguage = translation_public_language();
    // $activeLanguage stores the current admin language used for this admin request.
    $activeLanguage = $adminLanguage;
    // $defaultLanguage stores the configured fallback language.
    $defaultLanguage = translation_default_language();
    // $missingTranslations stores missing translation diagnostics collected for the current admin session.
    $missingTranslations = translation_missing_diagnostics();
    // $languageEditCode stores which language pack is shown in the editor.
    $languageEditCode = translation_normalize_language_code((string) ($_GET['edit_language'] ?? $activeLanguage));
    if ($languageEditCode === '' || !translation_language_allowed($languageEditCode)) {
        $languageEditCode = $defaultLanguage;
    }
    // $languageEditorErrors stores validation errors from the last language editor submit.
    $languageEditorErrors = $_SESSION['cms_language_editor_errors'] ?? [];
    unset($_SESSION['cms_language_editor_errors']);
    if (!is_array($languageEditorErrors)) {
        $languageEditorErrors = [];
    }
    // $viewerSettingsErrors stores validation messages from the shared selector settings panel.
    $viewerSettingsErrors = $_SESSION['cms_public_language_selector_errors'] ?? [];
    unset($_SESSION['cms_public_language_selector_errors']);
    if (!is_array($viewerSettingsErrors)) {
        $viewerSettingsErrors = [];
    }
    // $viewerSettingsSubmitted preserves checkbox state after a rejected Theme save.
    $viewerSettingsSubmitted = $_SESSION['cms_public_language_selector_submitted'] ?? [];
    unset($_SESSION['cms_public_language_selector_submitted']);
    if (!is_array($viewerSettingsSubmitted)) {
        $viewerSettingsSubmitted = [];
    }
    // $languageCoverage stores the key coverage comparison against the default language.
    $languageCoverage = translation_language_coverage($languageEditCode);

    $presentedLanguagePacks = [];
    foreach ($languagePacks as $languagePack) {
        $packCode = (string) ($languagePack['code'] ?? '');
        $packCoverage = translation_language_coverage($packCode);
        $hasJson = !empty($languagePack['has_json']);
        $hasPhp = !empty($languagePack['has_php']);
        $formatLabel = $hasJson && $hasPhp
            ? t('admin.theme.language.format_mixed', 'JSON + PHP fallback')
            : ($hasJson ? t('admin.theme.language.format_json', 'JSON') : t('admin.theme.language.format_php', 'PHP fallback'));
        if (empty($languagePack['loaded'])) {
            $statusLabel = t('admin.theme.language.status_empty', 'Empty or invalid');
        } elseif ($packCode === $defaultLanguage) {
            $statusLabel = t('admin.theme.language.status_source', 'Source / default');
        } elseif ((int) ($packCoverage['missing_count'] ?? 0) === 0) {
            $statusLabel = t('admin.theme.language.status_complete', 'Complete');
        } elseif ((int) ($packCoverage['translated_count'] ?? 0) === 0) {
            $statusLabel = t('admin.theme.language.status_skeleton', 'Skeleton, fallback: {language}', ['language' => strtoupper($defaultLanguage)]);
        } else {
            $statusLabel = t('admin.theme.language.status_partial', 'Partial, fallback: {language}', ['language' => strtoupper($defaultLanguage)]);
        }
        $languagePack['coverage'] = $packCoverage;
        $languagePack['format_label'] = $formatLabel;
        $languagePack['status_label'] = $statusLabel;
        $presentedLanguagePacks[] = $languagePack;
    }

    $selectorState = array_replace(translation_public_language_selector_view_data(), [
        'id_prefix' => 'admin-theme-public-language-selector',
        'enabled_name' => 'public_language_selector_enabled',
        'languages_name' => 'public_language_selector_languages[]',
        'design_name' => 'public_language_selector_design',
        'marker_name' => 'public_language_selector_settings_present',
        'enabled' => $viewerSettingsSubmitted !== []
            ? !empty($viewerSettingsSubmitted['enabled'])
            : translation_public_language_selector_enabled(),
        'languages' => $viewerSettingsSubmitted !== []
            ? (array) ($viewerSettingsSubmitted['languages'] ?? [])
            : translation_public_language_selector_languages(),
        'design' => $viewerSettingsSubmitted !== []
            ? (array) ($viewerSettingsSubmitted['design'] ?? [])
            : translation_public_language_selector_design(),
        'errors' => $viewerSettingsErrors,
    ]);

    view_render_admin_theme_language_tab([
        'language_packs' => $presentedLanguagePacks,
        'admin_language' => $adminLanguage,
        'public_language' => $publicLanguage,
        'default_language' => $defaultLanguage,
        'missing_translations' => $missingTranslations,
        'language_edit_code' => $languageEditCode,
        'language_editor_errors' => $languageEditorErrors,
        'language_coverage' => $languageCoverage,
        'language_pack_json' => translation_language_pack_json_text($languageEditCode),
        'selector_state' => $selectorState,
        'saved_notice' => !empty($_GET['language_saved']),
        'imported_notice' => !empty($_GET['language_imported']),
        'editor_base_url' => url_for('admin_theme'),
        'export_url' => url_for('admin_theme', ['download_language_pack' => $languageEditCode]),
    ]);
}
