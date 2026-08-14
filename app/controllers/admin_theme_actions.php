<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme_actions.php
 * Module Type: Controller
 *
 * Purpose:
 *   Processes Theme downloads, POST mutations, uploads, resets, and persistence without rendering the page.
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
use function Gallery\Services\translation_public_language_selector_normalize_languages;
use function Gallery\Services\translation_save_public_language_selector_settings;
use function Gallery\Services\translation_save_public_language_selector_design;
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
 * Download the requested language pack or redirect when the request is invalid.
 */
function admin_theme_download_language_pack(): void
{
    // $downloadLanguage stores the normalized language code requested for export.
    $downloadLanguage = translation_normalize_language_code((string) $_GET['download_language_pack']);
    if ($downloadLanguage !== '' && translation_language_allowed($downloadLanguage)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $downloadLanguage . '.json"');
        echo translation_language_pack_json_text($downloadLanguage);
        return;
    }
    redirect_to(url_for('admin_theme', ['language_error' => 'invalid_language']) . '#admin-theme-tab-language');

}

/**
 * Process a Theme POST request and redirect back to the Theme page.
 *
 * @param bool $gpsMapsFeatureEnabled Whether GPS map appearance settings are enabled.
 * @param bool $lightboxModesFeatureEnabled Whether lightbox mode settings are enabled.
 */
function admin_theme_process_post(bool $gpsMapsFeatureEnabled, bool $lightboxModesFeatureEnabled): void
{
    verify_csrf();
    if (!empty($_POST['public_language_selector_settings_present'])) {
        $viewerSelectorEnabled = !empty($_POST['public_language_selector_enabled']);
        $viewerSelectorLanguages = translation_public_language_selector_normalize_languages($_POST['public_language_selector_languages'] ?? []);
        if ($viewerSelectorLanguages === []) {
            $_SESSION['cms_public_language_selector_errors'] = [
                'languages' => t('admin.theme.language.viewer_languages_required', 'Enable at least one viewer language.'),
            ];
            $_SESSION['cms_public_language_selector_submitted'] = [
                'enabled' => $viewerSelectorEnabled,
                'languages' => [],
                'design' => $_POST['public_language_selector_design'] ?? [],
            ];
            redirect_to(url_for('admin_theme', ['language_error' => 'viewer_languages_required']) . '#admin-theme-tab-language');
        }
        translation_save_public_language_selector_settings($viewerSelectorEnabled, $viewerSelectorLanguages);
        translation_save_public_language_selector_design($_POST['public_language_selector_design'] ?? []);
    }
    if (isset($_POST['cms_language'])) {
        translation_set_active_language((string) $_POST['cms_language']);
    }
    if (isset($_POST['public_language'])) {
        translation_set_public_language((string) $_POST['public_language']);
    }
    if (!empty($_POST['save_language_pack'])) {
        // $languageEditCode stores the language pack currently edited in the admin UI.
        $languageEditCode = translation_normalize_language_code((string) ($_POST['language_pack_code'] ?? ''));
        // $languageJson stores the submitted editable JSON dictionary.
        $languageJson = (string) ($_POST['language_pack_json'] ?? '');
        if ($languageEditCode === '' || !translation_language_allowed($languageEditCode)) {
            redirect_to(url_for('admin_theme', ['language_error' => 'invalid_language']) . '#admin-theme-tab-language');
        }
        $saveResult = translation_save_language_json($languageEditCode, $languageJson);
        if (empty($saveResult['saved'])) {
            $_SESSION['cms_language_editor_errors'] = $saveResult['errors'] ?? [t('admin.theme.language.error_save_failed', 'Language pack could not be saved.')];
            redirect_to(url_for('admin_theme', ['language_error' => 'save_failed', 'edit_language' => $languageEditCode]) . '#admin-theme-tab-language');
        }
        redirect_to(url_for('admin_theme', ['language_saved' => 1, 'edit_language' => $languageEditCode]) . '#admin-theme-tab-language');
    }
    if (!empty($_POST['import_language_pack'])) {
        // $languageImportCode stores the target language pack for uploaded JSON.
        $languageImportCode = translation_normalize_language_code((string) ($_POST['language_pack_code'] ?? ''));
        if ($languageImportCode === '' || !translation_language_allowed($languageImportCode)) {
            redirect_to(url_for('admin_theme', ['language_error' => 'invalid_language']) . '#admin-theme-tab-language');
        }
        if (empty($_FILES['language_pack_file']['tmp_name']) || !is_uploaded_file($_FILES['language_pack_file']['tmp_name'])) {
            $_SESSION['cms_language_editor_errors'] = [t('admin.theme.language.error_import_missing', 'Choose a JSON file before importing.')];
            redirect_to(url_for('admin_theme', ['language_error' => 'import_missing', 'edit_language' => $languageImportCode]) . '#admin-theme-tab-language');
        }
        $importJson = (string) file_get_contents((string) $_FILES['language_pack_file']['tmp_name']);
        $importResult = translation_save_language_json($languageImportCode, $importJson);
        if (empty($importResult['saved'])) {
            $_SESSION['cms_language_editor_errors'] = $importResult['errors'] ?? [t('admin.theme.language.error_import_failed', 'Language pack import failed.')];
            redirect_to(url_for('admin_theme', ['language_error' => 'import_failed', 'edit_language' => $languageImportCode]) . '#admin-theme-tab-language');
        }
        redirect_to(url_for('admin_theme', ['language_imported' => 1, 'edit_language' => $languageImportCode]) . '#admin-theme-tab-language');
    }
    if (!empty($_POST['clear_translation_diagnostics'])) {
        translation_clear_missing_diagnostics();
        redirect_to(url_for('admin_theme', ['saved' => 1]) . '#admin-theme-tab-language');
    }
    if (!empty($_POST['reset_custom_css'])) {
        if (is_file(custom_css_path())) {
            unlink(custom_css_path());
        }
        set_app_setting('custom_css_preset', '');
    } elseif (!empty($_POST['reset_favicon'])) {
        remove_stored_favicon();
    } elseif (!empty($_POST['reset_theme_background'])) {
        // $path stores an intermediate value used by the surrounding gallery workflow.
        theme_background_clear_stored_files();
        set_app_setting('theme_background_path', '');
        set_app_setting('theme_background_original_path', '');
        set_app_setting('theme_background_optimized_path', '');
    } elseif (!empty($_POST['generate_theme_background_optimized'])) {
        // $backgroundMaxSide stores the requested optimized background longest side.
        $backgroundMaxSide = theme_background_optimized_max_side_value($_POST['theme_background_optimized_max_side'] ?? null);
        set_app_setting('theme_background_optimized_max_side', (string) $backgroundMaxSide);
        theme_background_regenerate_optimized($backgroundMaxSide);
    } elseif (!empty($_POST['delete_theme_background_optimized'])) {
        // $optimizedPath stores the generated derivative so the original upload can stay untouched.
        $optimizedPath = theme_background_optimized_path();
        if ($optimizedPath !== null) {
            $optimizedAbsolute = dirname(__DIR__, 2) . '/' . ltrim($optimizedPath, '/');
            if (is_file($optimizedAbsolute)) {
                @unlink($optimizedAbsolute);
            }
        }
        set_app_setting('theme_background_optimized_path', '');
    } elseif (!empty($_POST['reset_theme_branding_banner'])) {
        delete_theme_branding_asset('banner');
    } elseif (!empty($_POST['reset_theme_branding_separator'])) {
        delete_theme_branding_asset('separator');
    } elseif (!empty($_POST['reset_all_gallery_backgrounds'])) {
        if (gallery_background_source_schema_ready()) {
            db()->exec("UPDATE galleries SET background_source = NULL, updated_at = " . db()->quote(now_sql()) . " WHERE background_source IS NOT NULL");
        }
    } elseif (!empty($_POST['reset_theme_overrides'])) {
        clear_theme_overrides();
    } elseif (!empty($_POST['reset_all_gallery_grid_overrides'])) {
        // $resetResult stores how many custom gallery-grid settings were cleared from each persistence layer.
        $resetResult = reset_all_gallery_grid_overrides();
        // The redirect flag keeps the operation idempotent and avoids resubmitting the destructive reset on refresh.
        redirect_to(url_for('admin_theme', [
            'grid_reset' => 1,
            'db_rows' => (int) $resetResult['database_rows'],
            'sidecars' => (int) $resetResult['sidecars'],
        ]));
    } else {
        // Variable $siteName stores this steps working value.
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        set_site_name($siteName);
        // $themeControlsChanged stores an intermediate value used by the surrounding gallery workflow.
        $themeControlsChanged = (string) ($_POST['theme_controls_changed'] ?? '') === '1';
        // Variable $preset stores the posted skin selector value.
        $preset = (string) ($_POST['custom_css_preset'] ?? '');
        // $currentPreset stores the previously active preset. Saving unrelated
        // Theme controls must not re-copy the same skin and trigger a reset of
        // visual overrides on every submit.
        $currentPreset = (string) app_setting('custom_css_preset', '');
        // Variable $presetPath stores this steps working value.
        $presetPath = custom_css_preset_path($preset);
        // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
        $customCssChanged = false;
        if ($presetPath !== null && $preset !== $currentPreset) {
            copy($presetPath, custom_css_path());
            set_app_setting('custom_css_preset', $preset);
            // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
            $customCssChanged = true;
        }
        if (!empty($_FILES['custom_css']['tmp_name']) && is_uploaded_file($_FILES['custom_css']['tmp_name'])) {
            // Variable $name stores this steps working value.
            $name = strtolower((string) ($_FILES['custom_css']['name'] ?? ''));
            if (str_ends_with($name, '.css')) {
                move_uploaded_file($_FILES['custom_css']['tmp_name'], custom_css_path());
                set_app_setting('custom_css_preset', 'uploaded');
                // $customCssChanged stores an intermediate value used by the surrounding gallery workflow.
                $customCssChanged = true;
            }
        }
        if (!empty($_FILES['favicon_source']['tmp_name']) && is_uploaded_file($_FILES['favicon_source']['tmp_name'])) {
            // $name stores an intermediate value used by the surrounding gallery workflow.
            $name = strtolower((string) ($_FILES['favicon_source']['name'] ?? ''));
            if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                // $info stores an intermediate value used by the surrounding gallery workflow.
                $info = @getimagesize((string) $_FILES['favicon_source']['tmp_name']);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException('The uploaded favicon source is not a valid image.');
                }
                store_uploaded_favicon($_FILES['favicon_source'], (string) ($_POST['favicon_cropped_png'] ?? '') ?: null);
            }
        }
        if (!empty($_FILES['theme_background']['tmp_name']) && is_uploaded_file($_FILES['theme_background']['tmp_name'])) {
            // Variable $name stores this steps working value.
            $name = strtolower((string) ($_FILES['theme_background']['name'] ?? ''));
            if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
                // $info stores an intermediate value used by the surrounding gallery workflow.
                $info = @getimagesize((string) $_FILES['theme_background']['tmp_name']);
                if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                    throw new RuntimeException('The uploaded theme background is not a valid image.');
                }
                store_uploaded_theme_background($_FILES['theme_background'], theme_background_optimized_max_side_value($_POST['theme_background_optimized_max_side'] ?? null));
            }
        }
        foreach (array_keys(theme_branding_asset_types()) as $themeBrandingKind) {
            // $uploadField stores the file input name for one global Theme fallback branding asset.
            $uploadField = 'theme_branding_' . $themeBrandingKind;
            if (!empty($_FILES[$uploadField]['tmp_name']) && is_uploaded_file($_FILES[$uploadField]['tmp_name'])) {
                store_uploaded_theme_branding_asset((string) $themeBrandingKind, $_FILES[$uploadField]);
            }
        }
        set_app_setting('theme_background_opacity', (string) max(0, min(100, (int) ($_POST['theme_background_opacity'] ?? 65))));
        // $backgroundMaxSide stores the requested optimized background longest side.
        $backgroundMaxSide = theme_background_optimized_max_side_value($_POST['theme_background_optimized_max_side'] ?? null);
        // $previousBackgroundMaxSide stores the saved value so resize-only changes can rebuild the derivative.
        $previousBackgroundMaxSide = theme_background_optimized_max_side_value(app_setting('theme_background_optimized_max_side', '1920'));
        set_app_setting('theme_background_optimized_max_side', (string) $backgroundMaxSide);
        if ($backgroundMaxSide !== $previousBackgroundMaxSide && empty($_FILES['theme_background']['tmp_name'])) {
            theme_background_regenerate_optimized($backgroundMaxSide);
        }
        // $themeBackgroundSource stores an intermediate value used by the surrounding gallery workflow.
        $themeBackgroundSource = (string) ($_POST['theme_background_source'] ?? '');
        set_app_setting('theme_background_source', in_array($themeBackgroundSource, ['upload', 'existing', 'collage'], true) ? $themeBackgroundSource : '');
        if ($gpsMapsFeatureEnabled) {
            set_app_setting('theme_gps_pin_enabled', !empty($_POST['theme_gps_pin_enabled']) ? '1' : '0');
            set_app_setting('theme_gps_pin_background_enabled', !empty($_POST['theme_gps_pin_background_enabled']) ? '1' : '0');
            set_app_setting('theme_gps_pin_size', (string) theme_gps_pin_size_value($_POST['theme_gps_pin_size'] ?? null));
            set_app_setting('theme_gps_pin_background_size', (string) theme_gps_pin_background_size_value($_POST['theme_gps_pin_background_size'] ?? null));
            if (!empty($_POST['reset_gps_pin_size'])) {
                set_app_setting('theme_gps_pin_enabled', '1');
                set_app_setting('theme_gps_pin_background_enabled', '1');
                set_app_setting('theme_gps_pin_size', '26');
                set_app_setting('theme_gps_pin_background_size', '22');
            }
            // The GPS pin controls are part of the theme editor even when no color/font override changed.
            // Mark the form as changed so the save flow consistently persists the full appearance state.
            $themeControlsChanged = $themeControlsChanged || isset($_POST['theme_gps_pin_enabled']) || isset($_POST['theme_gps_pin_background_enabled']) || isset($_POST['theme_gps_pin_size']) || isset($_POST['theme_gps_pin_background_size']) || !empty($_POST['reset_gps_pin_size']);
        }
        set_app_setting('theme_page_width', theme_page_width_mode((string) ($_POST['theme_page_width'] ?? 'default')));
        set_app_setting('theme_page_width_custom', (string) theme_page_width_custom_value($_POST['theme_page_width_custom'] ?? null));
        // Hero-tag controls change public HTML ordering and disclosure data attributes, so compare them before saving.
        $previousHeroTagSettings = [
            'visible_limit' => theme_hero_tag_visible_limit(),
            'display_all' => theme_hero_tag_display_all_enabled(),
            'scrollbar_enabled' => theme_hero_tag_scrollbar_enabled(),
            'scrollbar_rows' => theme_hero_tag_scrollbar_rows(),
            'sort_mode' => theme_hero_tag_sort_mode(),
        ];
        // Server-side normalization protects direct POST requests from bypassing slider and select constraints.
        $nextHeroTagSettings = [
            'visible_limit' => theme_hero_tag_visible_limit_value($_POST['theme_hero_tag_visible_limit'] ?? null),
            'display_all' => !empty($_POST['theme_hero_tag_display_all']),
            'scrollbar_enabled' => !empty($_POST['theme_hero_tag_scrollbar_enabled']),
            'scrollbar_rows' => theme_hero_tag_scrollbar_rows_value($_POST['theme_hero_tag_scrollbar_rows'] ?? null),
            'sort_mode' => theme_hero_tag_sort_mode_normalize($_POST['theme_hero_tag_sort_mode'] ?? 'usage'),
        ];
        set_app_setting('theme_hero_tag_visible_limit', (string) $nextHeroTagSettings['visible_limit']);
        set_app_setting('theme_hero_tag_display_all', $nextHeroTagSettings['display_all'] ? '1' : '0');
        set_app_setting('theme_hero_tag_scrollbar_enabled', $nextHeroTagSettings['scrollbar_enabled'] ? '1' : '0');
        set_app_setting('theme_hero_tag_scrollbar_rows', (string) $nextHeroTagSettings['scrollbar_rows']);
        set_app_setting('theme_hero_tag_sort_mode', $nextHeroTagSettings['sort_mode']);
        if ($nextHeroTagSettings !== $previousHeroTagSettings) {
            // Sorting changes server-rendered tag order while the remaining values change public disclosure metadata.
            set_app_setting('theme_public_content_revision', (string) time());
        }
        if (function_exists('Gallery\\Services\\save_theme_favorite_gallery_slots')) {
            save_theme_favorite_gallery_slots($_POST['theme_favorite_gallery_types'] ?? [], $_POST['theme_favorite_gallery_ids'] ?? []);
        } elseif (function_exists('Gallery\\Services\\save_theme_favorite_gallery_ids')) {
            save_theme_favorite_gallery_ids($_POST['theme_favorite_gallery_ids'] ?? []);
        }
        set_app_setting('theme_branding_separator_width', (string) theme_branding_separator_width_value($_POST['theme_branding_separator_width'] ?? null));
        set_app_setting('theme_branding_separator_height', (string) theme_branding_separator_height_value($_POST['theme_branding_separator_height'] ?? null));
        set_app_setting('theme_branding_separator_stretch', !empty($_POST['theme_branding_separator_stretch']) ? '1' : '0');
        // $previousDescriptionLayout stores the rendered public-card layout before this save.
        $previousDescriptionLayout = theme_gallery_description_layout();
        // $nextDescriptionLayout stores the submitted public-card layout after validation.
        $nextDescriptionLayout = gallery_description_layout_normalize($_POST['theme_gallery_description_layout'] ?? 'vertical');
        set_app_setting('theme_gallery_description_layout', $nextDescriptionLayout);
        if ($nextDescriptionLayout !== $previousDescriptionLayout) {
            // The description layout changes public-card HTML classes, not only CSS.
            // Bump a content revision so public HTML caches and diagnostics can see the change immediately.
            set_app_setting('theme_public_content_revision', (string) time());
        }
        set_app_setting('theme_gallery_count_badge_enabled', !empty($_POST['theme_gallery_count_badge_enabled']) ? '1' : '0');
        // Public thumbnail renderer values and their public-content revision side effect share one service path.
        public_thumbnail_rendering_mode_save_with_revision($_POST['public_thumbnail_rendering_mode'] ?? null);
        if ($lightboxModesFeatureEnabled) {
            // $previousLightboxBrowsingMode stores the currently rendered lightbox mode before this save.
            $previousLightboxBrowsingMode = theme_lightbox_browsing_mode();
            // $nextLightboxBrowsingMode stores the submitted public lightbox browsing-mode default after validation.
            $nextLightboxBrowsingMode = gallery_lightbox_browsing_mode_normalize($_POST['theme_lightbox_browsing_mode'] ?? 'single');
            set_app_setting('theme_lightbox_browsing_mode', $nextLightboxBrowsingMode);
            if ($nextLightboxBrowsingMode !== $previousLightboxBrowsingMode) {
                // The lightbox browsing mode changes public data attributes and optional strip rendering behavior.
                // Bump a content revision so public HTML caches and diagnostics can see the change immediately.
                set_app_setting('theme_public_content_revision', (string) time());
            }
        }
        // Pagination settings are saved independently from color/font overrides so enabling pagination does not force a CSS override state.
        set_app_setting('pagination_enabled', !empty($_POST['pagination_enabled']) ? '1' : '0');
        set_app_setting('pagination_columns', (string) pagination_dimension_value($_POST['pagination_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS));
        set_app_setting('pagination_rows', (string) pagination_dimension_value($_POST['pagination_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS));
        set_app_setting('home_gallery_grid_columns', (string) pagination_dimension_value($_POST['home_gallery_grid_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS));
        set_app_setting('home_gallery_grid_rows', (string) pagination_dimension_value($_POST['home_gallery_grid_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS));
        set_app_setting('tag_page_gallery_grid_columns', (string) pagination_dimension_value($_POST['tag_page_gallery_grid_columns'] ?? CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS));
        set_app_setting('tag_page_gallery_grid_rows', (string) pagination_dimension_value($_POST['tag_page_gallery_grid_rows'] ?? CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS));
        set_app_setting('tag_page_gallery_description_layout', gallery_description_layout_normalize($_POST['tag_page_gallery_description_layout'] ?? theme_gallery_description_layout(), theme_gallery_description_layout()));
        if ($themeControlsChanged) {
            set_app_setting('theme_accent', sanitize_hex_color((string) $_POST['theme_accent'], '#a5481c'));
            set_app_setting('theme_accent_dark', sanitize_hex_color((string) $_POST['theme_accent_dark'], '#713414'));
            set_app_setting('theme_paper', sanitize_hex_color((string) $_POST['theme_paper'], '#f8f4ec'));
            set_app_setting('theme_panel', sanitize_hex_color((string) $_POST['theme_panel'], '#fffaf0'));
            set_app_setting('theme_gallery_panel', sanitize_hex_color((string) $_POST['theme_gallery_panel'], '#fffaf0'));
            set_app_setting('theme_header_text', sanitize_hex_color((string) $_POST['theme_header_text'], '#0f172a'));
            set_app_setting('theme_hero_text', sanitize_hex_color((string) $_POST['theme_hero_text'], '#0f172a'));
            set_app_setting('theme_radius', (string) max(0, min(32, (int) $_POST['theme_radius'])));
            set_app_setting('theme_font', in_array($_POST['theme_font'] ?? '', ['serif', 'sans'], true) ? (string) $_POST['theme_font'] : 'serif');
        } elseif ($customCssChanged) {
            clear_theme_overrides();
        }
    }
    redirect_to(url_for('admin_theme', ['saved' => 1]));

}
