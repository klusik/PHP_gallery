<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_theme.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
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
use function Gallery\Services\public_thumbnail_rendering_mode_save;
use function Gallery\Services\remove_stored_favicon;
use function Gallery\Services\reset_all_gallery_grid_overrides;
use function Gallery\Services\sanitize_hex_color;
use function Gallery\Services\save_theme_favorite_gallery_ids;
use function Gallery\Services\save_theme_favorite_gallery_slots;
use function Gallery\Services\set_app_setting;
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
 * Log the admin out of the current session.
 */


/**
 * Render and process visual theme settings.
 */
function cms_admin_theme(): void
{
    require_admin();
    // $gpsMapsFeatureEnabled stores whether GPS-related theme controls should be visible and saved.
    $gpsMapsFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('gallery_maps');
    // $lightboxModesFeatureEnabled stores whether lightbox browsing-mode theme controls should be visible and saved.
    $lightboxModesFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('lightbox_modes');

    if (isset($_GET['download_language_pack'])) {
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

    if (request_method() === 'POST') {
        verify_csrf();
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
            set_app_setting('site_name', $siteName !== '' ? substr($siteName, 0, 120) : 'Gallery CMS');
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
            // $previousPublicThumbnailRenderingMode stores the public picture-markup policy before this save.
            $previousPublicThumbnailRenderingMode = public_thumbnail_rendering_mode();
            // Public thumbnail renderer values are normalized in the service so unsupported POST data safely selects responsive mode.
            $nextPublicThumbnailRenderingMode = public_thumbnail_rendering_mode_save($_POST['public_thumbnail_rendering_mode'] ?? null);
            if ($nextPublicThumbnailRenderingMode !== $previousPublicThumbnailRenderingMode) {
                // The rendering mode changes selected-gallery server-rendered picture markup and browser activation markers.
                // Bump the existing public content revision so cache diagnostics reflect the new mode immediately.
                set_app_setting('theme_public_content_revision', (string) time());
            }
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
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = pagination_global_settings();
    // Variable $homeGridSettings stores the separate public home-page gallery grid.
    $homeGridSettings = main_page_gallery_grid_settings();
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
        'actions_html' => '<button type="submit" form="admin-theme-form">' . e(t('admin.theme.save_theme', 'Save theme')) . '</button>',
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

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.theme.appearance.kicker', 'Appearance'),
        'title' => t('admin.theme.appearance.title', 'Visual appearance'),
        'description' => t('admin.theme.appearance.description', 'Edit the core visual language. The preview mirrors colors, typography, radius, page width, and background transparency.'),
    ]);
    echo '<div class="admin-subtab-scope admin-theme-subtab-scope" data-admin-subtab-scope data-theme-preview-root data-theme-preview-background-url="' . e($themeBackgroundUrl) . '">';
    render_admin_subtabs([
        ['id' => 'admin-theme-appearance-subtab-colors', 'label' => t('admin.theme.subtab_colors_identity', 'Colors & identity')],
        ['id' => 'admin-theme-appearance-subtab-width-map', 'label' => t('admin.theme.subtab_width_map', 'Width & map pin')],
        ['id' => 'admin-theme-appearance-subtab-gallery-tags', 'label' => t('admin.theme.subtab_gallery_tags', 'Gallery tags')],
        ['id' => 'admin-theme-appearance-subtab-preview', 'label' => t('admin.theme.subtab_preview', 'Live preview')],
    ], 'admin-theme-appearance-subtab-colors', t('admin.theme.appearance.subtabs_label', 'Appearance subsections'));
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
        echo '<span class="admin-theme-description-body"><span class="admin-theme-description-title">' . e(t('admin.theme.layout.description_preview_title', 'Summer gallery')) . '</span><span class="admin-theme-description-meta">' . e(t('admin.theme.layout.description_preview_meta', '12 photos')) . '</span><span class="admin-theme-description-tags"><i>travel</i><i>family</i><i>2026</i></span><span class="admin-theme-description-line is-wide"></span><span class="admin-theme-description-line"></span></span>';
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
    echo '<option value="' . e(PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE) . '"' . ($publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_RESPONSIVE ? ' selected' : '') . '>' . e(t('admin.theme.layout.thumbnail_rendering_responsive_label', 'Responsive browser selection - Default')) . '</option>';
    echo '<option value="' . e(PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE) . '"' . ($publicThumbnailRenderingMode === PUBLIC_THUMBNAIL_RENDERING_PROGRESSIVE ? ' selected' : '') . '>' . e(t('admin.theme.layout.thumbnail_rendering_progressive_label', 'Progressive thumbnail sharpening - Beta')) . '</option>';
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

    ob_start();
    // $languagePacks stores language files detected from the app/lang directory.
    $languagePacks = translation_detected_language_packs();
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
    echo '<p class="muted"><strong>' . e(t('admin.theme.language.default_label', 'Default language')) . ':</strong> ' . e($defaultLanguage) . '</p>';
    echo '</fieldset>';
    echo '<fieldset class="form-grid admin-language-packs"><legend>' . e(t('admin.theme.language.detected_legend', 'Detected language packs')) . '</legend>';
    echo '<p class="muted">' . e(t('admin.theme.language.detected_hint', 'Language packs are loaded from app/lang/*.json first. Legacy app/lang/*.php files still work as fallback.')) . '</p>';
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
        // $statusLabel stores whether the language dictionary loaded at least one key.
        $statusLabel = !empty($languagePack['loaded']) ? t('admin.theme.language.status_loaded', 'Loaded') : t('admin.theme.language.status_empty', 'Empty or invalid');
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

    echo '<div class="panel admin-theme-save-panel"><div><strong>' . e(t('admin.theme.save_panel_title', 'Save changes')) . '</strong><p class="muted">' . e(t('admin.theme.save_panel_hint', 'All Theme tabs are saved together, so hidden tab settings are preserved when you submit the form.')) . '</p></div><div class="bulk-row"><button type="submit">' . e(t('admin.theme.save_theme', 'Save theme')) . '</button><button type="submit" class="secondary" name="reset_theme_overrides" value="1" formnovalidate>' . e(t('admin.theme.custom_css.reset_to_css', 'Reset to CSS')) . '</button></div></div></form>';
    render_footer();
}
