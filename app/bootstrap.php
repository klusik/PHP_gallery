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

const CMS_VERSION = '0.76';
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
 */
function cms_config_path(): string
{
    return dirname(__DIR__) . '/config.php';
}

/**
 * Return true when the application has a real local configuration file.
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
        $adminSessionLifetime = function_exists('auth_admin_session_lifetime_seconds') ? auth_admin_session_lifetime_seconds() : 1209600;
        ini_set('session.gc_maxlifetime', (string) $adminSessionLifetime);
        ini_set('session.cookie_lifetime', (string) $adminSessionLifetime);
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
    if (function_exists('seo_request_guard_enforce')) {
        seo_request_guard_enforce($page);
    }
    translation_bootstrap_request($page);
    send_security_headers();
    application_autoupdate_maybe_run();
    if (function_exists('site_maintenance_register_request_trigger')) {
        site_maintenance_register_request_trigger($page);
    }
    // Variable $routes stores this steps working value.
    $routes = [
        'home' => 'cms_home',
        'gallery' => 'cms_gallery',
        'gallery_access' => 'cms_gallery_access',
        'share' => 'cms_share',
        'tag' => 'cms_tag',
        'robots' => 'cms_robots_txt',
        'sitemap' => 'cms_sitemap_xml',
        'picture_game' => 'cms_picture_game',
        'media' => 'cms_media',
        'thumb' => 'cms_thumb',
        'public_media' => 'cms_public_media',
        'public_thumb' => 'cms_public_thumb',
        'thumbnail_warmup' => 'cms_thumbnail_warmup',
        'site_maintenance_cron' => 'cms_site_maintenance_cron',
        'gallery_cover_asset' => 'cms_gallery_cover_asset',
        'gallery_branding_asset' => 'cms_gallery_branding_asset',
        'theme_background_asset' => 'cms_theme_background_asset',
        'theme_branding_asset' => 'cms_theme_branding_asset',
        'favicon_asset' => 'cms_favicon_asset',
        'vote' => 'cms_vote',
        'theme_css' => 'cms_theme_css',
        'gallery_map_data' => 'cms_gallery_map_data',
        'gallery_lightbox_data' => 'cms_gallery_lightbox_data',
        'public_search' => 'cms_public_search',
        'navdata_lookup' => 'cms_navdata_lookup',
        'picture_manager_move' => 'cms_picture_manager_move',
        'picture_manager_copy' => 'cms_picture_manager_copy',
        'picture_manager_create_gallery' => 'cms_picture_manager_create_gallery',
        'picture_manager_download_selection' => 'cms_picture_manager_download_selection',
        'download_gallery' => 'cms_download_gallery',
        'download_all' => 'cms_download_all',
        'admin' => 'cms_admin',
        'admin_login' => 'cms_admin_login',
        'admin_forgot_password' => 'cms_admin_forgot_password',
        'admin_reset_password' => 'cms_admin_reset_password',
        'admin_google_start' => 'cms_admin_google_start',
        'admin_google_callback' => 'cms_admin_google_callback',
        'admin_logout' => 'cms_admin_logout',
        'admin_theme' => 'cms_admin_theme',
        'admin_account' => 'cms_admin_account',
        'admin_update' => 'cms_admin_update',
        'admin_diagnostics' => 'cms_admin_diagnostics',
        'admin_features' => 'cms_admin_features',
        'admin_reset' => 'cms_admin_reset',
        'admin_devmode' => 'cms_admin_devmode',
        'admin_url_rewrite' => 'cms_admin_url_rewrite',
        'admin_storage_statistics' => 'cms_admin_storage_statistics',
        'admin_storage_statistics_update' => 'cms_admin_storage_statistics_update',
        'admin_public_search_settings' => 'cms_admin_public_search_settings',
        'admin_exif_gps_settings' => 'cms_admin_exif_gps_settings',
        'admin_seo_guard_settings' => 'cms_admin_seo_guard_settings',
        'admin_site_maintenance_settings' => 'cms_admin_site_maintenance_settings',
        'admin_discover' => 'cms_admin_discover',
        'admin_import' => 'cms_admin_import',
        'admin_new_gallery' => 'cms_admin_new_gallery',
        'admin_upload' => 'cms_admin_upload',
        'admin_upload_automation_token' => 'cms_admin_upload_automation_token',
        'admin_mobile_uploads' => 'cms_admin_mobile_uploads',
        'mobile_webdav' => 'cms_mobile_webdav',
        'upload_automation_upload' => 'cms_upload_automation_upload',
        'admin_api_manager' => 'cms_admin_api_manager',
        'gallery_migration_manifest' => 'cms_gallery_migration_manifest',
        'gallery_migration_asset' => 'cms_gallery_migration_asset',
        'gallery_migration_receive_manifest' => 'cms_gallery_migration_receive_manifest',
        'gallery_migration_receive_asset' => 'cms_gallery_migration_receive_asset',
        'gallery_migration_receive_complete' => 'cms_gallery_migration_receive_complete',
        'gallery_migration_receive_status' => 'cms_gallery_migration_receive_status',
        'admin_gallery_migration' => 'cms_admin_gallery_migration',
        'admin_media_renamer' => 'cms_admin_media_renamer',
        'admin_gallery_dates' => 'cms_admin_gallery_dates',
        'admin_gallery_date_suggestion' => 'cms_admin_gallery_date_suggestion',
        'admin_bulk_galleries' => 'cms_admin_bulk_galleries',
        'admin_tags' => 'cms_admin_tags',
        'admin_run_migrations' => 'cms_admin_run_migrations',
        'admin_update_navdata' => 'cms_admin_update_navdata',
        'admin_navdata' => 'cms_admin_navdata',
        'admin_create_thumbnails' => 'cms_admin_create_thumbnails',
        'admin_thumbnail_compatibility_settings' => 'cms_admin_thumbnail_compatibility_settings',
        'admin_delete_legacy_jpg_thumbnails' => 'cms_admin_delete_legacy_jpg_thumbnails',
        'admin_delete_thumbnails' => 'cms_admin_delete_thumbnails',
        'admin_dismiss_thumbnail_notice' => 'cms_admin_dismiss_thumbnail_notice',
        'admin_regenerate_paths' => 'cms_admin_regenerate_paths',
        'admin_save_gallery_collapse' => 'cms_admin_save_gallery_collapse',
        'admin_reorder_galleries' => 'cms_admin_reorder_galleries',
        'admin_reorder_public_galleries' => 'cms_admin_reorder_public_galleries',
        'admin_scan_images' => 'cms_admin_scan_images',
        'admin_simbrief_description' => 'cms_admin_simbrief_description',
        'admin_openai_text_assist' => 'cms_admin_openai_text_assist',
        'admin_integrity' => 'cms_admin_integrity',
        'admin_logs' => 'cms_admin_logs',
        'admin_log_update' => 'cms_admin_log_update',
        'admin_log_export' => 'cms_admin_log_export',
        'admin_logs_export_zip' => 'cms_admin_logs_export_zip',
        'admin_telemetry' => 'cms_admin_telemetry',
        'admin_telemetry_settings' => 'cms_admin_telemetry_settings',
        'admin_telemetry_maintenance' => 'cms_admin_telemetry_maintenance',
        'admin_telemetry_export' => 'cms_admin_telemetry_export',
        'telemetry_ingest' => 'cms_telemetry_ingest',
        'usage_collect' => 'cms_telemetry_ingest',
        'admin_edit_gallery' => 'cms_admin_edit_gallery',
        'admin_bulk_images' => 'cms_admin_bulk_images',
        'admin_reorder_images' => 'cms_admin_reorder_images',
        'admin_edit_image' => 'cms_admin_edit_image',
        'admin_public_update_gallery' => 'cms_admin_public_update_gallery',
        'admin_public_update_image' => 'cms_admin_public_update_image',
        'setup' => 'cms_setup',
    ];

    if (function_exists('feature_flag_route_enabled') && !feature_flag_route_enabled($page)) {
        feature_flag_render_disabled_route($page);
        return;
    }

    // Variable $handler stores this steps working value.
    $handler = $routes[$page] ?? 'cms_not_found';
    $handler();
}

/**
 * Convert either query-string routes or simple pretty URLs into a page name.
 *
 * Query-string routes remain compatible. Pretty URLs are a convenience layer
 * when Apache rewrite rules are available.
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
