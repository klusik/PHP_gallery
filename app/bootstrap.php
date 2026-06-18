<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Core;

use function Gallery\Services\application_autoupdate_maybe_run;
use function Gallery\Services\auth_admin_session_lifetime_seconds;
use function Gallery\Services\feature_flag_render_disabled_route;
use function Gallery\Services\feature_flag_route_enabled;
use function Gallery\Services\find_gallery_by_public_path;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\seo_request_guard_enforce;
use function Gallery\Services\site_maintenance_register_request_trigger;
use function Gallery\Services\translation_bootstrap_request;

const CMS_VERSION = '0.83';
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];

require __DIR__ . '/helpers.php';
require __DIR__ . '/database.php';
require __DIR__ . '/security.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/services.php';
require __DIR__ . '/views.php';
require __DIR__ . '/integrity.php';
require __DIR__ . '/controllers.php';

/**
 * Return the expected application config path.
 *
 * @return string Text result for the caller.
 */
function cms_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

/**
 * Return true when the application has a real local configuration file.
 *
 * @return bool True when the condition matches.
 */
function cms_has_config(): bool
{
    return is_file(cms_config_path());
}

/**
 * Send first-run browser requests to the one-time installer.
 */
function cms_redirect_to_installer(): void
{
    // $base stores an intermediate value used by the surrounding gallery workflow.
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($base === '/public') {
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = '';
    } elseif (str_ends_with($base, '/public')) {
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = substr($base, 0, -7);
    }
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = ($base === '' ? '' : $base) . '/install.php';
    header('Location: ' . ($target === '/install.php' ? 'install.php' : $target));
    exit;
}

/**
 * Load the application configuration once per request.
 *
 * Production installs should provide config.php. The example config remains a
 * fallback for manual tooling, while browser requests without config.php are
 * redirected to the one-time installer before this function is called.
 *
 * @return array Structured result data for the caller.
 */
function cms_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // Variable $configFile stores this steps working value.
    $configFile = cms_config_path();
    if (!is_file($configFile)) {
        // Variable $configFile stores this steps working value.
        $configFile = dirname(__DIR__) . '/config.example.php';
    }

    // Variable $config stores this steps working value.
    $config = require $configFile;
    return $config;
}

/**
 * Start the session, resolve the requested route, and dispatch to a controller.
 *
 * The project intentionally uses a small route table instead of a framework so
 * it remains easy to run on shared hosting.
 */
function cms_run(): void
{
    if (!cms_has_config()) {
        cms_redirect_to_installer();
    }

    // Variable $config stores this steps working value.
    $config = cms_config();
    session_name((string) $config['admin_session_name']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // $adminSessionLifetime stores the browser cookie and PHP session lifetime for admin sessions.
        $adminSessionLifetime = function_exists('Gallery\\Services\\auth_admin_session_lifetime_seconds') ? auth_admin_session_lifetime_seconds() : 1209600;
        ini_set('session.gc_maxlifetime', (string) $adminSessionLifetime);
        ini_set('session.cookie_lifetime', (string) $adminSessionLifetime);
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => $adminSessionLifetime,
            'path' => '/',
            'secure' => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    // Variable $route stores this steps working value.
    $route = cms_route_from_request();
    // Variable $page stores this steps working value.
    $page = $route['page'];
    $_GET['page'] = $page;
    foreach ($route['params'] as $name => $value) {
        $_GET[$name] = $value;
    }
    if (function_exists('Gallery\\Services\\seo_request_guard_enforce')) {
        seo_request_guard_enforce($page);
    }
    translation_bootstrap_request($page);
    send_security_headers();
    application_autoupdate_maybe_run();
    if (function_exists('Gallery\\Services\\site_maintenance_register_request_trigger')) {
        site_maintenance_register_request_trigger($page);
    }
    // Variable $routes stores this steps working value.
    $routes = [
        'home' => '\\Gallery\\Controllers\\cms_home',
        'gallery' => '\\Gallery\\Controllers\\cms_gallery',
        'gallery_access' => '\\Gallery\\Controllers\\cms_gallery_access',
        'share' => '\\Gallery\\Controllers\\cms_share',
        'tag' => '\\Gallery\\Controllers\\cms_tag',
        'robots' => '\\Gallery\\Controllers\\cms_robots_txt',
        'sitemap' => '\\Gallery\\Controllers\\cms_sitemap_xml',
        'picture_game' => '\\Gallery\\Controllers\\cms_picture_game',
        'media' => '\\Gallery\\Controllers\\cms_media',
        'thumb' => '\\Gallery\\Controllers\\cms_thumb',
        'public_media' => '\\Gallery\\Controllers\\cms_public_media',
        'public_thumb' => '\\Gallery\\Controllers\\cms_public_thumb',
        'thumbnail_warmup' => '\\Gallery\\Controllers\\cms_thumbnail_warmup',
        'site_maintenance_cron' => '\\Gallery\\Controllers\\cms_site_maintenance_cron',
        'gallery_cover_asset' => '\\Gallery\\Controllers\\cms_gallery_cover_asset',
        'gallery_branding_asset' => '\\Gallery\\Controllers\\cms_gallery_branding_asset',
        'theme_background_asset' => '\\Gallery\\Controllers\\cms_theme_background_asset',
        'theme_branding_asset' => '\\Gallery\\Controllers\\cms_theme_branding_asset',
        'favicon_asset' => '\\Gallery\\Controllers\\cms_favicon_asset',
        'vote' => '\\Gallery\\Controllers\\cms_vote',
        'theme_css' => '\\Gallery\\Controllers\\cms_theme_css',
        'browser_i18n' => '\\Gallery\\Controllers\\cms_browser_i18n',
        'admin_browser_i18n' => '\\Gallery\\Controllers\\cms_browser_i18n',
        'gallery_map_data' => '\\Gallery\\Controllers\\cms_gallery_map_data',
        'gallery_lightbox_data' => '\\Gallery\\Controllers\\cms_gallery_lightbox_data',
        'public_search' => '\\Gallery\\Controllers\\cms_public_search',
        'navdata_lookup' => '\\Gallery\\Controllers\\cms_navdata_lookup',
        'picture_manager_move' => '\\Gallery\\Controllers\\cms_picture_manager_move',
        'picture_manager_copy' => '\\Gallery\\Controllers\\cms_picture_manager_copy',
        'picture_manager_create_gallery' => '\\Gallery\\Controllers\\cms_picture_manager_create_gallery',
        'picture_manager_download_selection' => '\\Gallery\\Controllers\\cms_picture_manager_download_selection',
        'download_gallery' => '\\Gallery\\Controllers\\cms_download_gallery',
        'download_all' => '\\Gallery\\Controllers\\cms_download_all',
        'admin' => '\\Gallery\\Controllers\\cms_admin',
        'admin_login' => '\\Gallery\\Controllers\\cms_admin_login',
        'admin_forgot_password' => '\\Gallery\\Controllers\\cms_admin_forgot_password',
        'admin_reset_password' => '\\Gallery\\Controllers\\cms_admin_reset_password',
        'admin_google_start' => '\\Gallery\\Controllers\\cms_admin_google_start',
        'admin_google_callback' => '\\Gallery\\Controllers\\cms_admin_google_callback',
        'admin_logout' => '\\Gallery\\Controllers\\cms_admin_logout',
        'admin_theme' => '\\Gallery\\Controllers\\cms_admin_theme',
        'admin_account' => '\\Gallery\\Controllers\\cms_admin_account',
        'admin_update' => '\\Gallery\\Controllers\\cms_admin_update',
        'admin_diagnostics' => '\\Gallery\\Controllers\\cms_admin_diagnostics',
        'admin_features' => '\\Gallery\\Controllers\\cms_admin_features',
        'admin_reset' => '\\Gallery\\Controllers\\cms_admin_reset',
        'admin_devmode' => '\\Gallery\\Controllers\\cms_admin_devmode',
        'admin_url_rewrite' => '\\Gallery\\Controllers\\cms_admin_url_rewrite',
        'admin_storage_statistics' => '\\Gallery\\Controllers\\cms_admin_storage_statistics',
        'admin_storage_statistics_update' => '\\Gallery\\Controllers\\cms_admin_storage_statistics_update',
        'admin_gallery_report' => '\\Gallery\\Controllers\\cms_admin_gallery_report',
        'admin_gallery_report_generate' => '\\Gallery\\Controllers\\cms_admin_gallery_report_generate',
        'admin_gallery_benchmark_start' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_start',
        'admin_gallery_benchmark_browser' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_browser',
        'admin_gallery_benchmark_status' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_status',
        'admin_gallery_benchmark_download' => '\\Gallery\\Controllers\\cms_admin_gallery_benchmark_download',
        'admin_database_usage_recompute' => '\\Gallery\\Controllers\\cms_admin_database_usage_recompute',
        'admin_public_search_settings' => '\\Gallery\\Controllers\\cms_admin_public_search_settings',
        'admin_exif_gps_settings' => '\\Gallery\\Controllers\\cms_admin_exif_gps_settings',
        'admin_seo_guard_settings' => '\\Gallery\\Controllers\\cms_admin_seo_guard_settings',
        'admin_site_maintenance_settings' => '\\Gallery\\Controllers\\cms_admin_site_maintenance_settings',
        'admin_discover' => '\\Gallery\\Controllers\\cms_admin_discover',
        'admin_import' => '\\Gallery\\Controllers\\cms_admin_import',
        'admin_new_gallery' => '\\Gallery\\Controllers\\cms_admin_new_gallery',
        'admin_upload' => '\\Gallery\\Controllers\\cms_admin_upload',
        'admin_upload_settings' => '\\Gallery\\Controllers\\cms_admin_upload_settings',
        'admin_upload_browser_batch' => '\\Gallery\\Controllers\\cms_admin_upload_browser_batch',
        'admin_upload_automation_token' => '\\Gallery\\Controllers\\cms_admin_upload_automation_token',
        'admin_mobile_uploads' => '\\Gallery\\Controllers\\cms_admin_mobile_uploads',
        'mobile_webdav' => '\\Gallery\\Controllers\\cms_mobile_webdav',
        'upload_automation_upload' => '\\Gallery\\Controllers\\cms_upload_automation_upload',
        'admin_api_manager' => '\\Gallery\\Controllers\\cms_admin_api_manager',
        'gallery_migration_manifest' => '\\Gallery\\Controllers\\cms_gallery_migration_manifest',
        'gallery_migration_asset' => '\\Gallery\\Controllers\\cms_gallery_migration_asset',
        'gallery_migration_receive_manifest' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_manifest',
        'gallery_migration_receive_asset' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_asset',
        'gallery_migration_receive_complete' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_complete',
        'gallery_migration_receive_status' => '\\Gallery\\Controllers\\cms_gallery_migration_receive_status',
        'admin_gallery_migration' => '\\Gallery\\Controllers\\cms_admin_gallery_migration',
        'admin_media_renamer' => '\\Gallery\\Controllers\\cms_admin_media_renamer',
        'admin_gallery_dates' => '\\Gallery\\Controllers\\cms_admin_gallery_dates',
        'admin_gallery_date_suggestion' => '\\Gallery\\Controllers\\cms_admin_gallery_date_suggestion',
        'admin_bulk_galleries' => '\\Gallery\\Controllers\\cms_admin_bulk_galleries',
        'admin_tags' => '\\Gallery\\Controllers\\cms_admin_tags',
        'admin_run_migrations' => '\\Gallery\\Controllers\\cms_admin_run_migrations',
        'admin_update_navdata' => '\\Gallery\\Controllers\\cms_admin_update_navdata',
        'admin_navdata' => '\\Gallery\\Controllers\\cms_admin_navdata',
        'admin_check_thumbnail_maintenance' => '\\Gallery\\Controllers\\cms_admin_check_thumbnail_maintenance',
        'admin_create_thumbnails' => '\\Gallery\\Controllers\\cms_admin_create_thumbnails',
        'admin_thumbnail_browser_source_chunk' => '\\Gallery\\Controllers\\cms_admin_thumbnail_browser_source_chunk',
        'admin_thumbnail_browser_upload_batch' => '\\Gallery\\Controllers\\cms_admin_thumbnail_browser_upload_batch',
        'admin_thumbnail_compatibility_settings' => '\\Gallery\\Controllers\\cms_admin_thumbnail_compatibility_settings',
        'admin_delete_legacy_jpg_thumbnails' => '\\Gallery\\Controllers\\cms_admin_delete_legacy_jpg_thumbnails',
        'admin_delete_thumbnails' => '\\Gallery\\Controllers\\cms_admin_delete_thumbnails',
        'admin_dismiss_thumbnail_notice' => '\\Gallery\\Controllers\\cms_admin_dismiss_thumbnail_notice',
        'admin_regenerate_paths' => '\\Gallery\\Controllers\\cms_admin_regenerate_paths',
        'admin_save_gallery_collapse' => '\\Gallery\\Controllers\\cms_admin_save_gallery_collapse',
        'admin_reorder_galleries' => '\\Gallery\\Controllers\\cms_admin_reorder_galleries',
        'admin_reorder_public_galleries' => '\\Gallery\\Controllers\\cms_admin_reorder_public_galleries',
        'admin_sort_public_subgalleries_by_date' => '\\Gallery\\Controllers\\cms_admin_sort_public_subgalleries_by_date',
        'admin_scan_images' => '\\Gallery\\Controllers\\cms_admin_scan_images',
        'admin_simbrief_description' => '\\Gallery\\Controllers\\cms_admin_simbrief_description',
        'admin_openai_text_assist' => '\\Gallery\\Controllers\\cms_admin_openai_text_assist',
        'admin_integrity' => '\\Gallery\\Controllers\\cms_admin_integrity',
        'admin_logs' => '\\Gallery\\Controllers\\cms_admin_logs',
        'admin_log_update' => '\\Gallery\\Controllers\\cms_admin_log_update',
        'admin_log_export' => '\\Gallery\\Controllers\\cms_admin_log_export',
        'admin_logs_export_zip' => '\\Gallery\\Controllers\\cms_admin_logs_export_zip',
        'admin_telemetry' => '\\Gallery\\Controllers\\cms_admin_telemetry',
        'admin_telemetry_settings' => '\\Gallery\\Controllers\\cms_admin_telemetry_settings',
        'admin_telemetry_maintenance' => '\\Gallery\\Controllers\\cms_admin_telemetry_maintenance',
        'admin_telemetry_export' => '\\Gallery\\Controllers\\cms_admin_telemetry_export',
        'telemetry_ingest' => '\\Gallery\\Controllers\\cms_telemetry_ingest',
        'usage_collect' => '\\Gallery\\Controllers\\cms_telemetry_ingest',
        'admin_edit_gallery' => '\\Gallery\\Controllers\\cms_admin_edit_gallery',
        'admin_metadata_organizer_preview_batch' => '\\Gallery\\Controllers\\cms_admin_metadata_organizer_preview_batch',
        'admin_metadata_organizer_apply_date_plan_batch' => '\\Gallery\\Controllers\\cms_admin_metadata_organizer_apply_date_plan_batch',
        'admin_bulk_images' => '\\Gallery\\Controllers\\cms_admin_bulk_images',
        'admin_reorder_images' => '\\Gallery\\Controllers\\cms_admin_reorder_images',
        'admin_edit_image' => '\\Gallery\\Controllers\\cms_admin_edit_image',
        'admin_public_update_gallery' => '\\Gallery\\Controllers\\cms_admin_public_update_gallery',
        'admin_public_update_image' => '\\Gallery\\Controllers\\cms_admin_public_update_image',
        'setup' => '\\Gallery\\Controllers\\cms_setup',
    ];

    if (function_exists('Gallery\\Services\\feature_flag_route_enabled') && !feature_flag_route_enabled($page)) {
        feature_flag_render_disabled_route($page);
        return;
    }

    // Variable $handler stores this steps working value.
    $handler = $routes[$page] ?? '\\Gallery\\Controllers\\cms_not_found';
    $handler();
}

/**
 * Convert either query-string routes or simple pretty URLs into a page name.
 *
 * Query-string routes remain compatible. Pretty URLs are a convenience layer
 * when Apache rewrite rules are available.
 *
 * @return array Structured result data for the caller.
 */
function cms_route_from_request(): array
{
    if (isset($_GET['page'])) {
        return ['page' => (string) $_GET['page'], 'params' => []];
    }

    // Variable $path stores this steps working value.
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    // Variable $basePath stores this steps working value.
    $basePath = trim(request_script_base_path(), '/');
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        // Variable $path stores this steps working value.
        $path = ltrim(substr($path, strlen($basePath)), '/');
    }
    // Variable $scriptDir stores this steps working value.
    $scriptDir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir . '/')) {
        // Variable $path stores this steps working value.
        $path = substr($path, strlen($scriptDir) + 1);
    }
    if (str_starts_with($path, 'public/')) {
        // Variable $path stores this steps working value.
        $path = substr($path, 7);
    }
    // Variable $segments stores this steps working value.
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

    if ($segments === [] || $segments === ['index.php']) {
        return ['page' => 'home', 'params' => []];
    }
    if ($segments === ['robots.txt']) {
        return ['page' => 'robots', 'params' => []];
    }
    if ($segments === ['sitemap.xml']) {
        return ['page' => 'sitemap', 'params' => []];
    }
    if ($segments === ['favicon.ico'] || $segments === ['favicon.png']) {
        return ['page' => 'favicon_asset', 'params' => ['s' => '32']];
    }
    if ($segments === ['api', 'upload']) {
        return ['page' => 'upload_automation_upload', 'params' => []];
    }
    if (($segments[0] ?? '') === 'webdav' && isset($segments[1])) {
        return [
            'page' => 'mobile_webdav',
            'params' => [
                'token' => rawurldecode($segments[1]),
                'target_path' => rawurldecode(implode('/', array_slice($segments, 2))),
            ],
        ];
    }
    if ($segments[0] === 'galleries' && isset($segments[1]) && preg_match('/^[0-9]+$/', $segments[1]) === 1) {
        return ['page' => 'home', 'params' => ['gallery_page' => max(1, (int) $segments[1])]];
    }
    if ($segments[0] === 'gallery' && isset($segments[1])) {
        // $gallerySegments stores an intermediate value used by the surrounding gallery workflow.
        $gallerySegments = array_slice($segments, 1);
        // $lastSegment stores an intermediate value used by the surrounding gallery workflow.
        $lastSegment = end($gallerySegments);
        if (is_string($lastSegment) && preg_match('/^thumb-([0-9]+)\.(jpg|webp)$/', $lastSegment, $thumbnailMatch)) {
            array_pop($gallerySegments);
            return [
                'page' => 'public_thumb',
                'params' => [
                    'public_path' => rawurldecode(implode('/', $gallerySegments)),
                    'size' => (int) $thumbnailMatch[1],
                    'format' => $thumbnailMatch[2],
                ],
            ];
        }
        if ($lastSegment === 'media' || $lastSegment === 'original') {
            array_pop($gallerySegments);
            return [
                'page' => 'public_media',
                'params' => ['public_path' => rawurldecode(implode('/', $gallerySegments))],
            ];
        }
        if (count($gallerySegments) >= 3) {
            // $typedPageSegment stores an optional clean pagination kind, such as galleries/2.
            $typedPageSegment = $gallerySegments[count($gallerySegments) - 2] ?? '';
            // $typedPageNumber stores an optional clean pagination page number.
            $typedPageNumber = (string) ($gallerySegments[count($gallerySegments) - 1] ?? '');
            if ($typedPageSegment === 'galleries' && preg_match('/^[0-9]+$/', $typedPageNumber) === 1) {
                // $fullPath stores the complete path so real child galleries keep priority over pagination suffixes.
                $fullPath = rawurldecode(implode('/', $gallerySegments));
                // $galleryPath stores the gallery path before the typed pagination suffix.
                $galleryPath = rawurldecode(implode('/', array_slice($gallerySegments, 0, -2)));
                if ($galleryPath !== '' && !find_gallery_by_public_path($fullPath) && find_gallery_by_public_path($galleryPath)) {
                    return ['page' => 'gallery', 'params' => ['public_path' => $galleryPath, 'gallery_page' => max(1, (int) $typedPageNumber)]];
                }
            }
        }
        if (is_string($lastSegment) && preg_match('/^[0-9]+$/', $lastSegment) === 1) {
            // $fullPath stores the complete path so numeric image slugs or child galleries keep working.
            $fullPath = rawurldecode(implode('/', $gallerySegments));
            // $fullResolved stores any real image match so numeric image slugs keep working.
            $fullResolved = resolve_public_gallery_path($fullPath, false);
            if (!find_gallery_by_public_path($fullPath) && empty($fullResolved['image'])) {
                // $galleryPath stores the gallery path before the clean photo pagination suffix.
                $galleryPath = rawurldecode(implode('/', array_slice($gallerySegments, 0, -1)));
                if ($galleryPath !== '' && find_gallery_by_public_path($galleryPath)) {
                    return ['page' => 'gallery', 'params' => ['public_path' => $galleryPath, 'photo_page' => max(1, (int) $lastSegment)]];
                }
            }
        }
        return ['page' => 'gallery', 'params' => ['public_path' => rawurldecode(implode('/', $gallerySegments))]];
    }
    if ($segments[0] === 'share' && isset($segments[1])) {
        return ['page' => 'share', 'params' => ['token' => rawurldecode($segments[1])]];
    }
    if ($segments[0] === 'tag' && isset($segments[1])) {
        return ['page' => 'tag', 'params' => ['slug' => rawurldecode($segments[1])]];
    }
    if ($segments[0] === 'admin') {
        return ['page' => 'admin', 'params' => []];
    }

    return ['page' => 'not_found', 'params' => []];
}
