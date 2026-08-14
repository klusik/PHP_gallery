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
use function Gallery\Services\translation_supported_languages;
use function Gallery\Services\translation_save_language_json;
use function Gallery\Services\translation_set_active_language;
use function Gallery\Services\translation_set_public_language;
use function Gallery\Views\view_render_admin_hero;
use function Gallery\Views\view_render_admin_tab_intro;
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
    ob_start();
    // $supportedLanguages stores the language codes currently exposed to Admin and public selectors.
    $supportedLanguages = translation_supported_languages();
    // $languagePacks stores only detected packs that are currently selectable.
    $languagePacks = array_values(array_filter(
        translation_detected_language_packs(),
        static fn (array $pack): bool => in_array((string) ($pack['code'] ?? ''), $supportedLanguages, true)
    ));
    // $adminLanguage stores the language selected for the admin interface.
    $adminLanguage = translation_admin_language();
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

    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.language.kicker', 'Language'),
        'title' => t('admin.theme.language.title', 'Language and translation packs'),
        'description' => t('admin.theme.language.description', 'Choose the admin interface language, choose the public visitor language, and inspect installed language packs before translating more areas.'),
    ]);
    if (!empty($_GET['language_saved'])) {
        echo '<section class="panel notice"><p>' . e(t('admin.theme.language.saved_notice', 'Language pack saved.')) . '</p></section>';
    }
    if (!empty($_GET['language_imported'])) {
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
    view_render_public_language_selector_settings_panel([
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
    echo '<p class="muted"><strong>' . e(t('admin.theme.language.default_label', 'Default language')) . ':</strong> ' . e($defaultLanguage) . '</p>';
    echo '<p class="muted">' . e(t('admin.theme.language.fallback_note', 'English is the source and fallback language. English, Czech, German, and Swedish are the maintained selectable catalogs and are expected to stay complete; fallback protects against accidental gaps.')) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid admin-language-packs"><legend>' . e(t('admin.theme.language.detected_legend', 'Supported language packs')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.detected_hint', 'Supported language packs are loaded from app/lang/*.json first. Additional dormant files do not become selectable automatically. Legacy app/lang/*.php files still work as fallback.')) . '</p>';
    echo '<div class="admin-language-table-wrap"><table class="admin-table admin-language-table"><thead><tr><th>' . e(t('admin.theme.language.pack_language', 'Language')) . '</th><th>' . e(t('admin.theme.language.pack_code', 'Code')) . '</th><th>' . e(t('admin.theme.language.pack_format', 'Format')) . '</th><th>' . e(t('admin.theme.language.pack_strings', 'Strings')) . '</th><th>' . e(t('admin.theme.language.coverage', 'Coverage')) . '</th><th>' . e(t('admin.theme.language.pack_status', 'Status')) . '</th></tr></thead><tbody>';
    foreach ($languagePacks as $languagePack) {
        // $hasJson stores whether the editable JSON dictionary exists.
        $hasJson = !empty($languagePack['has_json']);
        // $hasPhp stores whether the legacy PHP dictionary exists.
        $hasPhp = !empty($languagePack['has_php']);
        // $packCode stores the language code for coverage display.
        $packCode = (string) ($languagePack['code'] ?? '');
        // $packCoverage stores default-key coverage for one language pack.
        $packCoverage = translation_language_coverage($packCode);
        // $formatLabel stores the display label for available dictionary files.
        $formatLabel = $hasJson && $hasPhp ? t('admin.theme.language.format_mixed', 'JSON + PHP fallback') : ($hasJson ? t('admin.theme.language.format_json', 'JSON') : t('admin.theme.language.format_php', 'PHP fallback'));
        // $statusLabel stores translation maturity without pretending that a skeleton pack is complete.
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
    echo '<label>' . e(t('admin.theme.language.editor_select', 'Language pack to edit')) . '<select name="language_pack_code" onchange="if (this.value) window.location.href=\'' . e(url_for('admin_theme')) . '?edit_language=\' + encodeURIComponent(this.value) + \'#admin-theme-tab-language\';">';
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
    echo '<label>' . e(t('admin.theme.language.json_label', 'JSON language data')) . '<textarea name="language_pack_json" class="admin-language-json-editor" spellcheck="false" rows="20">' . e(translation_language_pack_json_text($languageEditCode)) . '</textarea></label>';
    echo '<div class="bulk-row"><button type="submit" name="save_language_pack" value="1" formnovalidate>' . e(t('admin.theme.language.save_pack', 'Save language pack')) . '</button><a class="button secondary" href="' . e(url_for('admin_theme', ['download_language_pack' => $languageEditCode])) . '">' . e(t('admin.theme.language.export_pack', 'Export JSON')) . '</a></div>';
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
