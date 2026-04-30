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
 * Return whether the current request reached the app through HTTPS.
 */
function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

/**
 * Return the current request host without a port.
 */
function request_host_name(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?: '';
}

/**
 * Return the base path implied by the current front controller request.
 */
function request_script_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(str_replace('/index.php', '', $script), '/');
    if ($dir === '/public') {
        return '';
    }
    if (str_ends_with($dir, '/public')) {
        return substr($dir, 0, -7);
    }
    return $dir === '/' ? '' : $dir;
}

/**
 * Keep configured absolute URLs compatible with the current HTTPS request.
 */
function request_aware_base_url(string $base): string
{
    if ($base === '') {
        return $base;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['host'])) {
        return $base;
    }
    $configuredHost = strtolower((string) ($parts['host'] ?? ''));
    if ($configuredHost === '' || $configuredHost !== request_host_name()) {
        return $base;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    if (request_is_https() && $scheme === 'http') {
        $scheme = 'https';
    }

    $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
    $scriptBasePath = request_script_base_path();
    if ($configuredPath !== '' && $scriptBasePath !== '' && !str_starts_with($scriptBasePath . '/', $configuredPath . '/')) {
        $configuredPath = $scriptBasePath;
    } elseif ($configuredPath !== '' && $scriptBasePath === '') {
        $configuredPath = '';
    }

    $url = $scheme . '://' . $configuredHost;
    if (!empty($parts['port']) && (int) $parts['port'] !== 80 && (int) $parts['port'] !== 443) {
        $url .= ':' . (int) $parts['port'];
    }
    $url .= $configuredPath;
    return rtrim($url, '/');
}

/**
 * Build an absolute or root-relative URL using the configured base URL.
 */
function base_url(string $path = ''): string
{
    // Variable $base stores this steps working value.
    $base = request_aware_base_url(rtrim((string) cms_config()['base_url'], '/'));
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
    // Variable $params stores this steps working value.
    $params = ['page' => $page] + $params;
    return base_url('index.php?' . http_build_query($params));
}

/**
 * Resolve public asset paths for either repository-root or public/ web roots.
 */
function asset_url(string $path): string
{
    // Variable $path stores this steps working value.
    $path = ltrim($path, '/');
    // Variable $script stores this steps working value.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // Variable $scriptFile stores this steps working value.
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
    // Variable $ascii stores this steps working value.
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    // Variable $source stores this steps working value.
    $source = $ascii === false ? $text : $ascii;
    // Variable $slug stores this steps working value.
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $source));
    // Variable $slug stores this steps working value.
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'gallery';
}

/**
 * Generate a unique gallery slug, optionally excluding an existing gallery ID.
 */
function unique_slug(PDO $pdo, string $title, ?int $excludeGalleryId = null): string
{
    // Variable $base stores this steps working value.
    $base = slugify($title);
    // Variable $slug stores this steps working value.
    $slug = $base;
    // Variable $counter stores this steps working value.
    $counter = 2;
    while (true) {
        // Variable $sql stores this steps working value.
        $sql = 'SELECT id FROM galleries WHERE slug = ?';
        // Variable $params stores this steps working value.
        $params = [$slug];
        if ($excludeGalleryId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeGalleryId;
        }
        // Variable $stmt stores this steps working value.
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }
        // Variable $slug stores this steps working value.
        $slug = $base . '-' . $counter;
        $counter++;
    }
}

/**
 * Render the shared document header, navigation, theme variables, and CSS links.
 */
function render_header(string $title): void
{
    // Variable $user stores this steps working value.
    $user = current_user();
    // Variable $siteName stores this steps working value.
    $siteName = site_name();
    // Variable $theme stores this steps working value.
    $theme = theme_settings();
    echo '<!doctype html><html lang="cs" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/styles.css')) . '">';
    // Variable $customCss stores this steps working value.
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    // Variable $page stores this steps working value.
    $page = (string) ($_GET['page'] ?? 'home');
    // Variable $bodyClass stores this steps working value.
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    echo '</head><body class="' . e($bodyClass) . '"><header class="site-header">';
    echo '<a class="brand" href="' . e(url_for('home')) . '">' . e($siteName) . '</a><nav class="nav">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($user) {
        $updatePending = application_update_pending();
        $updateClass = $updatePending ? ' class="is-update-pending"' : '';
        $updateLabel = $updatePending ? 'Updates (1)' : 'Updates';
        echo '<a href="' . e(url_for('admin')) . '">Admin</a>';
        echo '<a href="' . e(url_for('admin_theme')) . '">Theme</a>';
        echo '<a href="' . e(url_for('admin_account')) . '">Account</a>';
        echo '<a' . $updateClass . ' href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
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
    echo '</main><footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery on GitHub</a>';
    echo '</footer>';
    // Variable $scriptPath stores this steps working value.
    $scriptPath = dirname(__DIR__) . '/public/assets/gallery.js';
    echo '<script src="' . e(asset_url('assets/gallery.js')) . '?v=' . (is_file($scriptPath) ? filemtime($scriptPath) : time()) . '" defer></script>';
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
    // Variable $path stores this steps working value.
    $path = str_replace('\\', '/', $path);
    // Variable $segments stores this steps working value.
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
    // Variable $rootReal stores this steps working value.
    $rootReal = realpath($root);
    // Variable $pathReal stores this steps working value.
    $pathReal = realpath($path);
    if ($rootReal === false || $pathReal === false) {
        return false;
    }
    return str_starts_with($pathReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $pathReal === $rootReal;
}

/**
 * Build a short cache key for the generated theme stylesheet URL.
 */
function theme_cache_key(array $theme): string
{
    return substr(hash('sha256', json_encode($theme, JSON_UNESCAPED_SLASHES)), 0, 12);
}

/**
 * Escape a CSS custom property value used by the generated theme stylesheet.
 */
function css_value(string $value): string
{
    return str_replace(['\\', ';', '{', '}'], '', $value);
}
