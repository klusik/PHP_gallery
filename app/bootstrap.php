<?php

declare(strict_types=1);

const CMS_VERSION = '0.29';
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];

require __DIR__ . '/helpers.php';
require __DIR__ . '/database.php';
require __DIR__ . '/security.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/services.php';
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
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($base === '/public') {
        $base = '';
    } elseif (str_ends_with($base, '/public')) {
        $base = substr($base, 0, -7);
    }
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
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    send_security_headers();

    // Variable $route stores this steps working value.
    $route = cms_route_from_request();
    // Variable $page stores this steps working value.
    $page = $route['page'];
    $_GET['page'] = $page;
    foreach ($route['params'] as $name => $value) {
        $_GET[$name] = $value;
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
        'vote' => 'cms_vote',
        'theme_css' => 'cms_theme_css',
        'gallery_map_data' => 'cms_gallery_map_data',
        'download_gallery' => 'cms_download_gallery',
        'download_all' => 'cms_download_all',
        'admin' => 'cms_admin',
        'admin_login' => 'cms_admin_login',
        'admin_logout' => 'cms_admin_logout',
        'admin_theme' => 'cms_admin_theme',
        'admin_account' => 'cms_admin_account',
        'admin_update' => 'cms_admin_update',
        'admin_discover' => 'cms_admin_discover',
        'admin_import' => 'cms_admin_import',
        'admin_new_gallery' => 'cms_admin_new_gallery',
        'admin_upload' => 'cms_admin_upload',
        'admin_bulk_galleries' => 'cms_admin_bulk_galleries',
        'admin_run_migrations' => 'cms_admin_run_migrations',
        'admin_create_thumbnails' => 'cms_admin_create_thumbnails',
        'admin_save_gallery_collapse' => 'cms_admin_save_gallery_collapse',
        'admin_scan_images' => 'cms_admin_scan_images',
        'admin_logs' => 'cms_admin_logs',
        'admin_log_update' => 'cms_admin_log_update',
        'admin_edit_gallery' => 'cms_admin_edit_gallery',
        'admin_bulk_images' => 'cms_admin_bulk_images',
        'admin_edit_image' => 'cms_admin_edit_image',
        'admin_public_update_gallery' => 'cms_admin_public_update_gallery',
        'admin_public_update_image' => 'cms_admin_public_update_image',
        'setup' => 'cms_setup',
    ];

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
    if ($segments[0] === 'gallery' && isset($segments[1])) {
        if (count($segments) === 2) {
            return ['page' => 'gallery', 'params' => ['slug' => rawurldecode($segments[1])]];
        }
        return ['page' => 'gallery', 'params' => ['gallery_path' => rawurldecode(implode('/', array_slice($segments, 1)))]];
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

    return ['page' => 'home', 'params' => []];
}
