<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

function url_for(string $page, array $params = []): string
{
    $params = ['page' => $page] + $params;
    return base_url('index.php?' . http_build_query($params));
}

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

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function slugify(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $source = $ascii === false ? $text : $ascii;
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $source));
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'gallery';
}

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

function render_header(string $title): void
{
    $user = current_user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/styles.css')) . '">';
    echo '</head><body><header class="site-header">';
    echo '<a class="brand" href="' . e(url_for('home')) . '">Gallery CMS</a><nav class="nav">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($user) {
        echo '<a href="' . e(url_for('admin')) . '">Admin</a>';
        echo '<a href="' . e(url_for('admin_logout')) . '">Logout</a>';
    } else {
        echo '<a href="' . e(url_for('admin_login')) . '">Admin login</a>';
    }
    echo '</nav></header><main class="site-main">';
}

function render_footer(): void
{
    echo '</main><footer class="site-footer muted">Plain PHP gallery CMS.</footer>';
    echo '<script src="' . e(asset_url('assets/gallery.js')) . '" defer></script>';
    echo '</body></html>';
}

function supported_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function is_supported_image_path(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), supported_image_extensions(), true);
}

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

function path_inside(string $root, string $path): bool
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false) {
        return false;
    }
    return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $pathReal === $rootReal;
}
