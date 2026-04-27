<?php

declare(strict_types=1);

/**
 * Escape text for safe HTML output.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build an absolute or root-relative URL using the configured base URL.
 */
function base_url(string $path = ''): string
{
    $base = rtrim((string) cms_config()['base_url'], '/');
    if ($path === '') {
        return $base === '' ? 'index.php' : $base . '/';
    }
    if (str_starts_with($path, 'index.php')) {
        return ($base === '' ? '' : $base . '/') . $path;
    }
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

/**
 * Build a query-string route URL.
 */
function url_for(string $page, array $params = []): string
{
    $params = ['page' => $page] + $params;
    return base_url('index.php?' . http_build_query($params));
}

/**
 * Resolve public asset paths for either repository-root or public/ web roots.
 */
function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (str_ends_with($script, '/public/index.php') || str_ends_with($scriptFile, '/public/index.php')) {
        return base_url($path);
    }
    return base_url('public/' . $path);
}

/**
 * Send a 302 redirect and stop processing immediately.
 */
function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Normalize the current HTTP method for simple route guards.
 */
function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * Return the current timestamp in the format used by MySQL DATETIME columns.
 */
function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Convert human-entered titles/tag names into URL-safe slugs.
 */
function slugify(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $source = $ascii === false ? $text : $ascii;
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $source));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'gallery';
}

/**
 * Generate a unique gallery slug, optionally excluding an existing gallery ID.
 */
function unique_slug(PDO $pdo, string $title, ?int $excludeGalleryId = null): string
{
    $base = slugify($title);
    $slug = $base;
    $counter = 2;
    while (true) {
        $sql = 'SELECT id FROM galleries WHERE slug = ?';
        $params = [$slug];
        if ($excludeGalleryId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeGalleryId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

/**
 * Render the shared document header, navigation, theme variables, and CSS links.
 */
function render_header(string $title): void
{
    $user = current_user();
    $theme = theme_settings();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/styles.css')) . '">';
    $fontFamily = $theme['font'] === 'sans' ? 'Arial, Helvetica, sans-serif' : 'Georgia, Times New Roman, serif';
    echo '<style>:root{--accent:' . e((string) $theme['accent']) . ';--accent-dark:' . e((string) $theme['accent_dark']) . ';--paper:' . e((string) $theme['paper']) . ';--panel:' . e((string) $theme['panel']) . ';--radius:' . (int) $theme['radius'] . 'px;--font-family:' . e($fontFamily) . ';}</style>';
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    $page = (string) ($_GET['page'] ?? 'home');
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    echo '</head><body class="' . e($bodyClass) . '"><header class="site-header">';
    echo '<a class="brand" href="' . e(url_for('home')) . '">Gallery CMS</a><nav class="nav">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($user) {
        echo '<a href="' . e(url_for('admin')) . '">Admin</a>';
        echo '<a href="' . e(url_for('admin_theme')) . '">Theme</a>';
        echo '<a href="' . e(url_for('admin_logout')) . '">Logout</a>';
    } else {
        echo '<a href="' . e(url_for('admin_login')) . '">Admin login</a>';
    }
    echo '</nav></header><main class="site-main">';
}

/**
 * Render the shared footer and JavaScript include.
 */
function render_footer(): void
{
    echo '</main><footer class="site-footer muted">Plain PHP gallery CMS.</footer>';
    echo '<script src="' . e(asset_url('assets/gallery.js')) . '" defer></script>';
    echo '</body></html>';
}

/**
 * Image extensions accepted during filesystem scans.
 */
function supported_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

/**
 * Check whether a path points to one of the supported image formats.
 */
function is_supported_image_path(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), supported_image_extensions(), true);
}

/**
 * Normalize a user/filesystem relative path and reject traversal segments.
 */
function normalize_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            throw new RuntimeException('Invalid relative path.');
        }
        $segments[] = $segment;
    }
    return implode('/', $segments);
}

/**
 * Verify that a resolved path stays inside a resolved root directory.
 */
function path_inside(string $root, string $path): bool
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false) {
        return false;
    }
    return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $pathReal === $rootReal;
}
