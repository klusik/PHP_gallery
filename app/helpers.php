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
    // Variable $basePath stores this steps working value.
    $basePath = request_script_base_path();
    if ($path === '') {
        return $base === '' ? ($basePath === '' ? '/' : $basePath . '/') : $base . '/';
    }
    if (str_starts_with($path, 'index.php')) {
        return ($base === '' ? ($basePath === '' ? '/' : $basePath . '/') : $base . '/') . $path;
    }
    return ($base === '' ? ($basePath === '' ? '' : $basePath) : $base) . '/' . ltrim($path, '/');
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
 * Build the public base URL for canonical and sitemap output.
 */
function public_base_url(): string
{
    $configured = rtrim(request_aware_base_url(rtrim((string) cms_config()['base_url'], '/')), '/');
    if ($configured !== '') {
        return $configured;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme = request_is_https() ? 'https' : 'http';
    return rtrim($scheme . '://' . $host . request_script_base_path(), '/');
}

/**
 * Convert an app URL to an absolute public URL for crawler-facing metadata.
 */
function absolute_public_url(string $url): string
{
    if (preg_match('#^https?://#i', $url) === 1) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        $parts = parse_url(public_base_url());
        $origin = (string) ($parts['scheme'] ?? 'http') . '://' . (string) ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin . $url;
    }
    return public_base_url() . '/' . ltrim($url, '/');
}

/**
 * Encode one relative gallery path for clean public URLs while preserving slashes.
 */
function gallery_public_path_segment(string $folderPath): string
{
    $normalizedPath = trim(str_replace('\\', '/', $folderPath), '/');
    if ($normalizedPath === '') {
        return rawurlencode('gallery');
    }

    $segments = array_values(array_filter(explode('/', $normalizedPath), static fn (string $segment): bool => $segment !== ''));
    return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
}

/**
 * Build the preferred public URL for one gallery, using its full folder path.
 */
function gallery_public_url(array $gallery): string
{
    $folderPath = (string) ($gallery['folder_path'] ?? '');
    if ($folderPath === '') {
        $folderPath = (string) ($gallery['slug'] ?? 'gallery');
    }
    return public_base_url() . '/gallery/' . gallery_public_path_segment($folderPath) . '/';
}

/**
 * Build the canonical public URL for one gallery.
 */
function canonical_url_for_gallery(array $gallery): string
{
    return gallery_public_url($gallery);
}

/**
 * Return the best public title for one gallery page.
 */
function gallery_seo_title(array $gallery): string
{
    $metadata = public_gallery_metadata($gallery);
    return $metadata['title'];
}

/**
 * Return the best public description for one gallery page.
 */
function gallery_seo_description(array $gallery): string
{
    $metadata = public_gallery_metadata($gallery);
    return $metadata['description'];
}

/**
 * Build safe alt text for one gallery image.
 */
function image_alt_text(array $image, array $gallery, int $index = 1): string
{
    $caption = trim((string) ($image['description'] ?? ''));
    if ($caption !== '') {
        return $caption;
    }
    $title = trim((string) ($image['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    $filename = trim((string) ($image['filename'] ?? ''));
    if ($filename !== '') {
        return trim(preg_replace('/[-_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)) ?: $filename);
    }
    return (string) ($gallery['title'] ?? 'Gallery') . ' image ' . $index;
}

/**
 * Render SEO tags for a gallery page.
 */
function render_public_seo_tags(array $gallery, array $images = []): void
{
    $title = gallery_seo_title($gallery);
    $description = gallery_seo_description($gallery);
    $canonical = canonical_url_for_gallery($gallery);
    $ogImage = '';
    foreach ($images as $image) {
        $preview = thumbnail_url($image, 800);
        if ($preview !== '') {
            $ogImage = absolute_public_url($preview);
            break;
        }
    }
    echo '<link rel="canonical" href="' . e($canonical) . '">';
    echo '<meta name="description" content="' . e($description) . '">';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:title" content="' . e($title) . '">';
    echo '<meta property="og:description" content="' . e($description) . '">';
    echo '<meta property="og:url" content="' . e($canonical) . '">';
    echo '<meta property="og:site_name" content="' . e(site_name()) . '">';
    if ($ogImage !== '') {
        echo '<meta property="og:image" content="' . e($ogImage) . '">';
    }
    echo '<meta name="twitter:card" content="' . ($ogImage !== '' ? 'summary_large_image' : 'summary') . '">';
    echo '<meta name="twitter:title" content="' . e($title) . '">';
    echo '<meta name="twitter:description" content="' . e($description) . '">';
    if ($ogImage !== '') {
        echo '<meta name="twitter:image" content="' . e($ogImage) . '">';
    }
}

/**
 * Render JSON-LD for one gallery page.
 */
function render_gallery_json_ld(array $gallery, array $images = []): void
{
    $items = [];
    $position = 1;
    foreach ($images as $image) {
        $items[] = [
            '@type' => 'ImageObject',
            'position' => $position++,
            'name' => image_alt_text($image, $gallery, $position - 1),
            'contentUrl' => absolute_public_url(thumbnail_url($image, 800)),
            'url' => absolute_public_url(base_url('index.php?page=media&id=' . (int) $image['id'])),
        ];
    }
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ImageGallery',
        'name' => gallery_seo_title($gallery),
        'description' => gallery_seo_description($gallery),
        'url' => canonical_url_for_gallery($gallery),
        'image' => $items,
    ];
    $metadata = public_gallery_metadata($gallery);
    if (!empty($metadata['tags'])) {
        $jsonLd['keywords'] = $metadata['tags'];
    }
    $json = json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    echo '<script type="application/ld+json">' . str_replace('</', '<\/', $json) . '</script>';
}

/**
 * Output the sitemap XML with public gallery URLs.
 */
function output_sitemap_xml(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $base = public_base_url();
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>' . e($base . '/') . '</loc></url>';
    foreach (public_gallery_sitemap_entries() as $url) {
        echo '<url><loc>' . e($url) . '</loc></url>';
    }
    echo '</urlset>';
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
    if (str_ends_with($script, '/public/index.php')) {
        return base_url('public/' . $path);
    }
    // Variable $scriptFile stores this steps working value.
    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (str_ends_with($scriptFile, '/public/index.php')) {
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
    // Variable $page stores this steps working value.
    $page = (string) ($_GET['page'] ?? 'home');
    // Variable $bodyClass stores this steps working value.
    $bodyClass = str_starts_with($page, 'admin') || $page === 'setup' ? 'admin-page' : 'public-page';
    echo '<!doctype html><html lang="cs" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    if ($bodyClass === 'admin-page') {
        echo '<meta name="robots" content="noindex,nofollow">';
    }
    // Main stylesheet gets a build-busting version so structural CSS changes
    // are not masked by browser cache.
    $stylesPath = dirname(__DIR__) . '/public/assets/styles.css';
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/styles.css')) . '?v=' . (is_file($stylesPath) ? filemtime($stylesPath) : time()) . '">';
    // Variable $customCss stores this steps working value.
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    echo cms_head_extras_html();
    echo '</head><body class="' . e($bodyClass) . '">';
    if ($bodyClass === 'public-page') {
        echo '<div class="theme-background-shell" aria-hidden="true">';
        echo '<div class="theme-background-base"></div>';
        echo '<div class="theme-background-image"></div>';
        echo '</div>';
    }
    echo '<header class="site-header">';
    echo '<a class="brand" href="' . e(url_for('home')) . '">' . e($siteName) . '</a><nav class="nav">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($user) {
        $updatePending = application_update_pending();
        $updateClass = $updatePending ? ' class="is-update-pending"' : '';
        $updateLabel = application_update_nav_label($updatePending);
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
 * Replace extra head HTML for the next rendered page.
 */
function set_cms_head_extras(string $html): void
{
    $GLOBALS['cms_head_extras'] = $html;
}

/**
 * Append extra head HTML for the next rendered page.
 */
function append_cms_head_extras(string $html): void
{
    $GLOBALS['cms_head_extras'] = (string) ($GLOBALS['cms_head_extras'] ?? '') . $html;
}

/**
 * Return buffered head extras and clear them after rendering.
 */
function cms_head_extras_html(): string
{
    $html = (string) ($GLOBALS['cms_head_extras'] ?? '');
    $GLOBALS['cms_head_extras'] = '';
    return $html;
}

/**
 * Read the current application version directly from app/bootstrap.php.
 */
function cms_current_version(): string
{
    static $version = null;
    if ($version !== null) {
        return $version;
    }

    $bootstrapPath = dirname(__DIR__) . '/app/bootstrap.php';
    $bootstrap = is_file($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match)) {
        $version = trim((string) $match[1]);
        return $version;
    }

    return $version = CMS_VERSION;
}

/**
 * Render the shared footer and JavaScript include.
 */
function render_footer(): void
{
    echo '</main><footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
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
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (function_exists('heic_conversion_supported') && heic_conversion_supported()) {
        $extensions[] = 'heic';
        $extensions[] = 'heif';
    }
    if (function_exists('raw_conversion_supported') && raw_conversion_supported()) {
        $extensions[] = 'dng';
    }
    return $extensions;
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
