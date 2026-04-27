<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';
require __DIR__ . '/database.php';
require __DIR__ . '/security.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/services.php';
require __DIR__ . '/controllers.php';

function cms_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        $configFile = dirname(__DIR__) . '/config.example.php';
    }

    $config = require $configFile;
    return $config;
}

function cms_run(): void
{
    $config = cms_config();
    session_name((string) $config['admin_session_name']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $route = cms_route_from_request();
    $page = $route['page'];
    foreach ($route['params'] as $name => $value) {
        $_GET[$name] = $value;
    }
    $routes = [
        'home' => 'cms_home',
        'gallery' => 'cms_gallery',
        'media' => 'cms_media',
        'vote' => 'cms_vote',
        'download_gallery' => 'cms_download_gallery',
        'download_all' => 'cms_download_all',
        'admin' => 'cms_admin',
        'admin_login' => 'cms_admin_login',
        'admin_logout' => 'cms_admin_logout',
        'admin_discover' => 'cms_admin_discover',
        'admin_import' => 'cms_admin_import',
        'admin_scan_images' => 'cms_admin_scan_images',
        'admin_edit_gallery' => 'cms_admin_edit_gallery',
        'admin_edit_image' => 'cms_admin_edit_image',
        'setup' => 'cms_setup',
    ];

    $handler = $routes[$page] ?? 'cms_not_found';
    $handler();
}

function cms_route_from_request(): array
{
    if (isset($_GET['page'])) {
        return ['page' => (string) $_GET['page'], 'params' => []];
    }

    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    $scriptDir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir . '/')) {
        $path = substr($path, strlen($scriptDir) + 1);
    }
    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

    if ($segments === [] || $segments === ['index.php']) {
        return ['page' => 'home', 'params' => []];
    }
    if ($segments[0] === 'gallery' && isset($segments[1])) {
        return ['page' => 'gallery', 'params' => ['slug' => rawurldecode($segments[1])]];
    }
    if ($segments[0] === 'admin') {
        return ['page' => 'admin', 'params' => []];
    }

    return ['page' => 'home', 'params' => []];
}
