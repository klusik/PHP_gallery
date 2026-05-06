<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers.php
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
    // $https stores an intermediate value used by the surrounding gallery workflow.
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // $forwardedProto stores an intermediate value used by the surrounding gallery workflow.
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
    // $host stores an intermediate value used by the surrounding gallery workflow.
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?: '';
}

/**
 * Return the base path implied by the current front controller request.
 */
function request_script_base_path(): string
{
    // $script stores an intermediate value used by the surrounding gallery workflow.
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    // $dir stores an intermediate value used by the surrounding gallery workflow.
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
    // $parts stores an intermediate value used by the surrounding gallery workflow.
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['host'])) {
        return $base;
    }
    // $configuredHost stores an intermediate value used by the surrounding gallery workflow.
    $configuredHost = strtolower((string) ($parts['host'] ?? ''));
    if ($configuredHost === '' || $configuredHost !== request_host_name()) {
        return $base;
    }

    // $scheme stores an intermediate value used by the surrounding gallery workflow.
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    if (request_is_https() && $scheme === 'http') {
        // $scheme stores an intermediate value used by the surrounding gallery workflow.
        $scheme = 'https';
    }

    // $configuredPath stores an intermediate value used by the surrounding gallery workflow.
    $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
    // $scriptBasePath stores an intermediate value used by the surrounding gallery workflow.
    $scriptBasePath = request_script_base_path();
    if ($configuredPath !== '' && $scriptBasePath !== '' && !str_starts_with($scriptBasePath . '/', $configuredPath . '/')) {
        // $configuredPath stores an intermediate value used by the surrounding gallery workflow.
        $configuredPath = $scriptBasePath;
    } elseif ($configuredPath !== '' && $scriptBasePath === '') {
        // $configuredPath stores an intermediate value used by the surrounding gallery workflow.
        $configuredPath = '';
    }

    // $url stores an intermediate value used by the surrounding gallery workflow.
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
    // $configured stores an intermediate value used by the surrounding gallery workflow.
    $configured = rtrim(request_aware_base_url(rtrim((string) cms_config()['base_url'], '/')), '/');
    if ($configured !== '') {
        return $configured;
    }
    // $host stores an intermediate value used by the surrounding gallery workflow.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    // $scheme stores an intermediate value used by the surrounding gallery workflow.
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
        // $parts stores an intermediate value used by the surrounding gallery workflow.
        $parts = parse_url(public_base_url());
        // $origin stores an intermediate value used by the surrounding gallery workflow.
        $origin = (string) ($parts['scheme'] ?? 'http') . '://' . (string) ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin . $url;
    }
    return public_base_url() . '/' . ltrim($url, '/');
}

/**
 * Encode one relative public path while preserving slashes.
 */
function public_path_segment(string $path): string
{
    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = trim(str_replace('\\', '/', $path), '/');
    if ($normalizedPath === '') {
        return rawurlencode('gallery');
    }

    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = array_values(array_filter(explode('/', $normalizedPath), static fn (string $segment): bool => $segment !== ''));
    return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
}

/**
 * Encode one relative gallery path for clean public URLs while preserving slashes.
 */
function gallery_public_path_segment(string $folderPath): string
{
    return public_path_segment($folderPath);
}

/**
 * Build the preferred public URL for one gallery, using its clean public path when available.
 */
function gallery_public_url(array $gallery): string
{
    // $urlPath stores an intermediate value used by the surrounding gallery workflow.
    $urlPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($urlPath === '') {
        // $urlPath stores an intermediate value used by the surrounding gallery workflow.
        $urlPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
    }
    if ($urlPath === '') {
        // $urlPath stores an intermediate value used by the surrounding gallery workflow.
        $urlPath = (string) ($gallery['slug'] ?? 'gallery');
    }
    return public_base_url() . '/gallery/' . public_path_segment($urlPath) . '/';
}

/**
 * Build the preferred public URL for one image detail page.
 */
function image_public_url(array $image, array $gallery): string
{
    // $slug stores an intermediate value used by the surrounding gallery workflow.
    $slug = trim((string) ($image['url_slug'] ?? ''));
    if ($slug === '') {
        // $slug stores an intermediate value used by the surrounding gallery workflow.
        $slug = slugify(pathinfo((string) ($image['filename'] ?? 'image'), PATHINFO_FILENAME));
    } else {
        // $slug stores an intermediate value used by the surrounding gallery workflow.
        $slug = slugify($slug);
    }
    return rtrim(gallery_public_url($gallery), '/') . '/' . rawurlencode($slug) . '/';
}


/**
 * Build the preferred clean public media URL for one original image file.
 */
function image_public_media_url(array $image, array $gallery): string
{
    return rtrim(image_public_url($image, $gallery), '/') . '/media';
}

/**
 * Build the preferred clean public thumbnail URL for one generated image variant.
 */
function image_public_thumbnail_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    // $format stores an intermediate value used by the surrounding gallery workflow.
    $format = $format === 'webp' ? 'webp' : 'jpg';
    return rtrim(image_public_url($image, $gallery), '/') . '/thumb-' . $size . '.' . $format;
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
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    return $metadata['title'];
}

/**
 * Return the best public description for one gallery page.
 */
function gallery_seo_description(array $gallery): string
{
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    return $metadata['description'];
}

/**
 * Build safe alt text for one gallery image.
 */
function image_alt_text(array $image, array $gallery, int $index = 1): string
{
    // $caption stores an intermediate value used by the surrounding gallery workflow.
    $caption = trim((string) ($image['description'] ?? ''));
    if ($caption !== '') {
        return $caption;
    }
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = trim((string) ($image['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    // $filename stores an intermediate value used by the surrounding gallery workflow.
    $filename = trim((string) ($image['filename'] ?? ''));
    if ($filename !== '') {
        return trim(preg_replace('/[-_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)) ?: $filename);
    }
    return (string) ($gallery['title'] ?? 'Gallery') . ' image ' . $index;
}

/**
 * Build the strongest social-preview image candidate for a gallery page.
 *
 * Link-preview crawlers are much stricter than normal browsers. Discord,
 * WhatsApp, Facebook, Slack, X/Twitter, and similar consumers behave most
 * consistently when the first image is a public JPEG URL with a stable absolute
 * URL, a real Content-Type, explicit pixel dimensions, and alt text. This helper
 * prefers an existing generated JPEG thumbnail because it is smaller than the
 * original upload and does not depend on WebP support in the crawler.
 *
 * @return array{url:string,secure_url:string,type:string,width:int,height:int,alt:string}|null
 */
function gallery_social_preview_image(array $gallery, array $images = []): ?array
{
    if (gallery_nsfw_requirement($gallery) !== null) {
        return null;
    }
    // $candidates stores images in priority order while avoiding duplicate ids.
    $candidates = [];
    // $seenIds stores ids that were already added to the candidate list.
    $seenIds = [];

    foreach ($images as $image) {
        // $imageId stores the normalized image id used for duplicate protection.
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0 || isset($seenIds[$imageId]) || (int) ($image['nsfw_enabled'] ?? 0) === 1) {
            continue;
        }
        $seenIds[$imageId] = true;
        $candidates[] = $image;
    }

    // $cover stores the configured or inferred direct cover image for this gallery.
    $cover = gallery_cover_image((int) ($gallery['id'] ?? 0), true);
    if ($cover) {
        // $coverId stores the normalized cover image id for duplicate protection.
        $coverId = (int) ($cover['id'] ?? 0);
        if ($coverId > 0 && !isset($seenIds[$coverId]) && !image_nsfw_restricted($cover, find_gallery((int) ($cover['gallery_id'] ?? 0)) ?: $gallery)) {
            $seenIds[$coverId] = true;
            array_unshift($candidates, $cover);
        }
    }

    foreach (gallery_cover_collage_images((int) ($gallery['id'] ?? 0), true, 4) as $descendantCover) {
        // $descendantCoverId stores the normalized image id used for duplicate protection.
        $descendantCoverId = (int) ($descendantCover['id'] ?? 0);
        if ($descendantCoverId <= 0 || isset($seenIds[$descendantCoverId]) || (int) ($descendantCover['nsfw_enabled'] ?? 0) === 1) {
            continue;
        }
        $seenIds[$descendantCoverId] = true;
        $candidates[] = $descendantCover;
    }

    foreach ($candidates as $candidate) {
        // $preview stores the crawler-safe metadata for one generated JPEG thumbnail.
        $preview = social_preview_image_from_thumbnail($candidate, $gallery, 1280);
        if ($preview !== null) {
            return $preview;
        }
    }

    return null;
}

/**
 * Build crawler-facing metadata for one generated JPEG thumbnail.
 *
 * @return array{url:string,secure_url:string,type:string,width:int,height:int,alt:string}|null
 */
function social_preview_image_from_thumbnail(array $image, array $currentGallery, int $preferredSize = 1280): ?array
{
    // $imageGallery stores the real gallery for the candidate image. Descendant
    // cover images can belong to child galleries, so using only the current
    // gallery would produce an invalid thumbnail path for parent galleries.
    $imageGallery = find_gallery((int) ($image['gallery_id'] ?? 0));
    if (!$imageGallery || image_nsfw_restricted($image, $imageGallery)) {
        return null;
    }

    try {
        // $fallback stores the closest existing JPEG thumbnail, preferring a
        // wider image so large-preview consumers can render a rich card.
        $fallback = thumbnail_existing_fallback($image, $imageGallery, $preferredSize, 'jpg');
        if ($fallback === null || (string) ($fallback['format'] ?? '') !== 'jpg') {
            return null;
        }
        // $thumbnailPath stores the local file so dimensions can be emitted in
        // the Open Graph metadata instead of asking crawlers to infer them.
        $thumbnailPath = thumbnail_abs_path($image, $imageGallery, (int) $fallback['size'], 'jpg');
    } catch (RuntimeException) {
        return null;
    }

    if (!is_file($thumbnailPath)) {
        return null;
    }

    // $imageSize stores the actual thumbnail dimensions from disk.
    $imageSize = @getimagesize($thumbnailPath);
    if ($imageSize === false || (int) $imageSize[0] < 200 || (int) $imageSize[1] < 200) {
        return null;
    }

    // $url stores the absolute public URL that social crawlers receive.
    $url = absolute_public_url(thumbnail_serving_url($image, $imageGallery, (int) $fallback['size'], 'jpg'));
    // $alt stores descriptive text for Open Graph and Twitter image metadata.
    $alt = image_alt_text($image, $currentGallery);

    // $versionedUrl stores a stable cache-busting URL. Discord caches embed
    // images aggressively, so using the thumbnail modification time makes the
    // preview refresh when the underlying thumbnail is regenerated without
    // changing normal visitor URLs.
    $versionedUrl = social_preview_cache_busted_url($url, $thumbnailPath);

    return [
        'url' => $versionedUrl,
        'secure_url' => preg_replace('#^http://#i', 'https://', $versionedUrl) ?: $versionedUrl,
        'type' => 'image/jpeg',
        'width' => (int) $imageSize[0],
        'height' => (int) $imageSize[1],
        'alt' => $alt,
    ];
}

/**
 * Add a deterministic version marker to one social preview image URL.
 *
 * Discord, Slack, Facebook, and other crawlers cache fetched preview images. A
 * version marker based on the generated thumbnail file keeps the URL stable for
 * normal sharing, but changes when the thumbnail is rebuilt.
 */
function social_preview_cache_busted_url(string $url, string $filePath): string
{
    // $modifiedAt stores the thumbnail timestamp used as a cheap content version.
    $modifiedAt = is_file($filePath) ? (string) filemtime($filePath) : '';
    if ($modifiedAt === '') {
        return $url;
    }

    // $separator stores the correct query separator for URLs that already carry
    // parameters, such as the legacy index.php?page=thumb route.
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'v=' . rawurlencode($modifiedAt);
}

/**
 * Emit one meta tag followed by a newline so crawler diagnostics are readable.
 */
function render_meta_tag(string $attributeName, string $attributeValue, string $content): void
{
    echo '<meta ' . $attributeName . '="' . e($attributeValue) . '" content="' . e($content) . '">' . "\n";
}

/**
 * Emit one link tag followed by a newline so crawler diagnostics are readable.
 */
function render_link_tag(string $rel, string $href): void
{
    echo '<link rel="' . e($rel) . '" href="' . e($href) . '">' . "\n";
}

/**
 * Render SEO tags for a gallery page.
 */
function render_public_seo_tags(array $gallery, array $images = []): void
{
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = gallery_seo_title($gallery);
    // $description stores an intermediate value used by the surrounding gallery workflow.
    $description = gallery_seo_description($gallery);
    // $canonical stores an intermediate value used by the surrounding gallery workflow.
    $canonical = canonical_url_for_gallery($gallery);
    // $previewImage stores crawler-safe social image metadata when available.
    $previewImage = gallery_social_preview_image($gallery, $images);
    // $ogImage stores an intermediate value used by the surrounding gallery workflow.
    $ogImage = $previewImage['url'] ?? '';

    render_link_tag('canonical', $canonical);
    render_meta_tag('name', 'description', $description);
    render_meta_tag('property', 'og:type', 'website');
    render_meta_tag('property', 'og:title', $title);
    render_meta_tag('property', 'og:description', $description);
    render_meta_tag('property', 'og:url', $canonical);
    render_meta_tag('property', 'og:site_name', site_name());
    render_meta_tag('property', 'og:locale', 'cs_CZ');
    if ($previewImage !== null) {
        render_meta_tag('property', 'og:image', $previewImage['url']);
        render_meta_tag('property', 'og:image:url', $previewImage['url']);
        if (str_starts_with((string) $previewImage['secure_url'], 'https://')) {
            render_meta_tag('property', 'og:image:secure_url', $previewImage['secure_url']);
        }
        render_meta_tag('property', 'og:image:type', $previewImage['type']);
        render_meta_tag('property', 'og:image:width', (string) $previewImage['width']);
        render_meta_tag('property', 'og:image:height', (string) $previewImage['height']);
        render_meta_tag('property', 'og:image:alt', $previewImage['alt']);
        render_meta_tag('name', 'image', $previewImage['url']);
        render_meta_tag('itemprop', 'image', $previewImage['url']);
    }
    render_meta_tag('name', 'twitter:card', $ogImage !== '' ? 'summary_large_image' : 'summary');
    render_meta_tag('name', 'twitter:title', $title);
    render_meta_tag('name', 'twitter:description', $description);
    render_meta_tag('name', 'twitter:url', $canonical);
    if ($previewImage !== null) {
        render_meta_tag('name', 'twitter:image', $previewImage['url']);
        render_meta_tag('name', 'twitter:image:src', $previewImage['url']);
        render_meta_tag('name', 'twitter:image:alt', $previewImage['alt']);
    }
}

/**
 * Render JSON-LD for one gallery page.
 */
function render_gallery_json_ld(array $gallery, array $images = []): void
{
    // $items stores an intermediate value used by the surrounding gallery workflow.
    $items = [];
    // $position stores an intermediate value used by the surrounding gallery workflow.
    $position = 1;
    foreach ($images as $image) {
        if (image_nsfw_restricted($image, $gallery)) {
            continue;
        }
        $items[] = [
            '@type' => 'ImageObject',
            'position' => $position++,
            'name' => image_alt_text($image, $gallery, $position - 1),
            'contentUrl' => absolute_public_url(thumbnail_url($image, 800)),
            'url' => absolute_public_url(image_public_url($image, $gallery)),
        ];
    }
    // $jsonLd stores an intermediate value used by the surrounding gallery workflow.
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'ImageGallery',
        'name' => gallery_seo_title($gallery),
        'description' => gallery_seo_description($gallery),
        'url' => canonical_url_for_gallery($gallery),
        'image' => $items,
    ];
    // $metadata stores an intermediate value used by the surrounding gallery workflow.
    $metadata = public_gallery_metadata($gallery);
    if (!empty($metadata['tags'])) {
        $jsonLd['keywords'] = $metadata['tags'];
    }
    // $json stores an intermediate value used by the surrounding gallery workflow.
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
    // $base stores an intermediate value used by the surrounding gallery workflow.
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
 * Store or retrieve a one-time flash message in the active session.
 */
function flash_message(string $key, ?string $message = null): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $message;
    }

    if ($message !== null) {
        $_SESSION['flash_messages'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash_messages'][$key])) {
        return null;
    }

    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = (string) $_SESSION['flash_messages'][$key];
    unset($_SESSION['flash_messages'][$key]);
    return $value;
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
 * Return the canonical admin menu model used by the dashboard and admin shell.
 */
function admin_menu_structure(): array
{
    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = function_exists('application_update_pending') ? application_update_pending() : false;
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = function_exists('application_update_nav_label') ? application_update_nav_label($updatePending) : 'Updates';
    return [
        [
            'label' => 'Dashboard',
            'items' => [
                ['label' => 'Overview', 'page' => 'admin', 'url' => url_for('admin')],
            ],
        ],
        [
            'label' => 'Galleries',
            'items' => [
                ['label' => 'All galleries', 'page' => 'admin', 'url' => url_for('admin') . '#admin-galleries'],
                ['label' => 'Create gallery', 'page' => 'admin_new_gallery', 'url' => url_for('admin_new_gallery')],
                ['label' => 'Upload photos', 'page' => 'admin_upload', 'url' => url_for('admin_upload')],
                ['label' => 'Ordering', 'page' => 'admin', 'url' => url_for('admin') . '#admin-ordering'],
            ],
        ],
        [
            'label' => 'Media',
            'items' => [
                ['label' => 'Thumbnails', 'page' => 'admin', 'url' => url_for('admin') . '#admin-thumbnails'],
                ['label' => 'Cache', 'page' => 'admin', 'url' => url_for('admin') . '#admin-cache'],
                ['label' => 'ZIP downloads', 'page' => 'download_all', 'url' => url_for('download_all')],
            ],
        ],
        [
            'label' => 'Appearance',
            'items' => [
                ['label' => 'Theme', 'page' => 'admin_theme', 'url' => url_for('admin_theme') . '#admin-theme'],
                ['label' => 'Custom CSS', 'page' => 'admin_theme', 'url' => url_for('admin_theme') . '#admin-custom-css'],
                ['label' => 'Favicon', 'page' => 'admin_theme', 'url' => url_for('admin_theme') . '#admin-favicon'],
                ['label' => 'Backgrounds', 'page' => 'admin_theme', 'url' => url_for('admin_theme') . '#admin-backgrounds'],
            ],
        ],
        [
            'label' => 'Maintenance',
            'items' => [
                ['label' => 'Health check', 'page' => 'admin_integrity', 'url' => url_for('admin_integrity')],
                ['label' => 'Logs', 'page' => 'admin_logs', 'url' => url_for('admin_logs')],
                ['label' => 'Telemetry', 'page' => 'admin_telemetry', 'url' => url_for('admin_telemetry')],
                ['label' => 'Integrity', 'page' => 'admin_integrity', 'url' => url_for('admin_integrity')],
                ['label' => 'Migrations', 'page' => 'admin', 'url' => url_for('admin') . '#admin-migrations'],
                ['label' => $updateLabel, 'page' => 'admin_update', 'url' => url_for('admin_update'), 'highlight' => $updatePending],
            ],
        ],
        [
            'label' => 'Account',
            'items' => [
                ['label' => 'Profile', 'page' => 'admin_account', 'url' => url_for('admin_account')],
                ['label' => 'Logout', 'page' => 'admin_logout', 'url' => url_for('admin_logout')],
            ],
        ],
    ];
}

/**
 * Return true when one admin menu item should be marked as active.
 */
function admin_menu_item_is_active(array $item, string $currentPage): bool
{
    // $itemPage stores an intermediate value used by the surrounding gallery workflow.
    $itemPage = (string) ($item['page'] ?? '');
    if ($itemPage === '') {
        return false;
    }
    if ($currentPage === $itemPage) {
        if ($itemPage === 'admin') {
            return !str_contains((string) ($item['url'] ?? ''), '#');
        }
        return true;
    }
    if ($itemPage === 'admin' && in_array($currentPage, ['admin_edit_gallery', 'admin_edit_image'], true)) {
        return str_contains((string) ($item['url'] ?? ''), '#admin-galleries');
    }
    return false;
}

/**
 * Render the persistent admin sidebar used by all authenticated admin pages.
 */
function render_admin_sidebar(string $currentPage): void
{
    echo '<aside class="admin-sidebar" aria-label="Admin navigation">';
    echo '<div class="admin-sidebar-title">Admin</div>';
    foreach (admin_menu_structure() as $group) {
        echo '<section class="admin-menu-group">';
        echo '<h2>' . e((string) $group['label']) . '</h2>';
        echo '<nav class="admin-menu-links">';
        foreach ((array) $group['items'] as $item) {
            // $activeClass stores an intermediate value used by the surrounding gallery workflow.
            $activeClass = admin_menu_item_is_active($item, $currentPage) ? ' is-active' : '';
            // $highlightClass stores an intermediate value used by the surrounding gallery workflow.
            $highlightClass = !empty($item['highlight']) ? ' is-update-pending' : '';
            echo '<a class="admin-menu-link' . e($activeClass . $highlightClass) . '" href="' . e((string) $item['url']) . '">' . e((string) $item['label']) . '</a>';
        }
        echo '</nav></section>';
    }
    echo '</aside>';
}


/**
 * Render the admin notice that asks existing admins to add a recovery email.
 */
function render_missing_admin_email_notice(?array $user, string $currentPage): void
{
    if (!$user || $currentPage === 'admin_login' || $currentPage === 'admin_logout' || $currentPage === 'setup') {
        return;
    }
    if (trim((string) ($user['email'] ?? '')) !== '') {
        return;
    }

    echo '<div class="notice admin-account-notice">';
    echo '<strong>Recovery email missing.</strong> Add an email address to your account so username-or-email login works and the app is ready for future account recovery.';
    echo ' <a href="' . e(url_for('admin_account')) . '">Open account settings</a>';
    echo '</div>';
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
    // $pageWidthClass stores a public layout class selected in Theme settings.
    // Admin pages intentionally keep their own workspace width so dense tables remain practical.
    $pageWidthClass = $bodyClass === 'public-page' ? ' page-width-' . theme_page_width_mode((string) ($theme['page_width'] ?? 'default')) : '';
    echo '<!doctype html><html lang="cs" translate="no"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title === $siteName ? $siteName : $title . ' - ' . $siteName) . '</title>';
    // Variable $faviconUrl stores this steps working value.
    $faviconUrl = favicon_asset_url();
    if ($faviconUrl !== '') {
        // $faviconVersion stores an intermediate value used by the surrounding gallery workflow.
        $faviconVersion = (string) app_setting('favicon_version', '1');
        echo '<link rel="icon" type="image/png" sizes="32x32" href="' . e($faviconUrl) . '&s=32&v=' . e($faviconVersion) . '">';
        echo '<link rel="icon" type="image/png" sizes="48x48" href="' . e($faviconUrl) . '&s=48&v=' . e($faviconVersion) . '">';
        echo '<link rel="apple-touch-icon" sizes="180x180" href="' . e($faviconUrl) . '&s=180&v=' . e($faviconVersion) . '">';
    }
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
    // $devModeActive stores an intermediate value used by the surrounding gallery workflow.
    $devModeActive = $user && dev_mode_enabled();
    echo '</head><body class="' . e($bodyClass . $pageWidthClass) . '"' . ($devModeActive ? ' data-dev-mode="1"' : '') . '>';
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
        if ($bodyClass === 'public-page') {
            // $updatePending stores an intermediate value used by the surrounding gallery workflow.
            $updatePending = application_update_pending();
            // $updateClass stores an intermediate value used by the surrounding gallery workflow.
            $updateClass = $updatePending ? ' class="is-update-pending"' : '';
            // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
            $updateLabel = application_update_nav_label($updatePending);
            echo '<a href="' . e(url_for('admin')) . '">Admin</a>';
            echo '<a' . $updateClass . ' href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
        }
        echo '<a href="' . e(url_for('admin_logout')) . '">Logout</a>';
    } else {
        echo '<a href="' . e(url_for('admin_login')) . '">Admin login</a>';
    }
    echo '</nav></header>';
    if ($bodyClass === 'admin-page' && $user) {
        echo '<div class="admin-shell">';
        render_admin_sidebar($page);
        echo '<main class="site-main admin-content">';
        render_missing_admin_email_notice($user, $page);
    } else {
        echo '<main class="site-main">';
    }
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
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = (string) ($GLOBALS['cms_head_extras'] ?? '');
    $GLOBALS['cms_head_extras'] = '';
    return $html;
}

/**
 * Append an inline footer script for the next rendered page.
 */
function append_cms_footer_script(string $script): void
{
    $GLOBALS['cms_footer_scripts'] = (array) ($GLOBALS['cms_footer_scripts'] ?? []);
    $GLOBALS['cms_footer_scripts'][] = $script;
}


/**
 * Append raw footer HTML for the next rendered page.
 */
function append_cms_footer_html(string $html): void
{
    $GLOBALS['cms_footer_html'] = (array) ($GLOBALS['cms_footer_html'] ?? []);
    $GLOBALS['cms_footer_html'][] = $html;
}

/**
 * Return buffered footer scripts and clear them after rendering.
 */
function cms_footer_scripts_html(): string
{
    // $scripts stores an intermediate value used by the surrounding gallery workflow.
    $scripts = (array) ($GLOBALS['cms_footer_scripts'] ?? []);
    $GLOBALS['cms_footer_scripts'] = [];
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '';
    foreach ($scripts as $script) {
        $html .= '<script>' . $script . '</script>';
    }
    // $footerHtml stores raw footer HTML snippets prepared by trusted server-side code.
    $footerHtml = (array) ($GLOBALS['cms_footer_html'] ?? []);
    $GLOBALS['cms_footer_html'] = [];
    foreach ($footerHtml as $snippet) {
        $html .= (string) $snippet;
    }
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

    // $bootstrapPath stores an intermediate value used by the surrounding gallery workflow.
    $bootstrapPath = dirname(__DIR__) . '/app/bootstrap.php';
    // $bootstrap stores an intermediate value used by the surrounding gallery workflow.
    $bootstrap = is_file($bootstrapPath) ? (string) file_get_contents($bootstrapPath) : '';
    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match)) {
        // $version stores an intermediate value used by the surrounding gallery workflow.
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
    // $page stores an intermediate value used by the surrounding gallery workflow.
    $page = (string) ($_GET['page'] ?? 'home');
    // $hasAdminShell stores an intermediate value used by the surrounding gallery workflow.
    $hasAdminShell = (str_starts_with($page, 'admin') || $page === 'setup') && current_user();
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    echo '</footer>';
    // Variable $scriptPath stores this steps working value.
    $scriptPath = dirname(__DIR__) . '/public/assets/gallery.js';
    echo '<script type="module" src="' . e(asset_url('assets/gallery.js')) . '?v=' . (is_file($scriptPath) ? filemtime($scriptPath) : time()) . '"></script>';
    echo cms_footer_scripts_html();
    echo '</body></html>';
}

/**
 * Image extensions accepted during filesystem scans.
 */
function supported_image_extensions(): array
{
    // $extensions stores an intermediate value used by the surrounding gallery workflow.
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
