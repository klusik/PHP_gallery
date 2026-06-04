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
 * Return the current browser request URI as a safe post-login return target.
 *
 * The value is intentionally stored as a relative URI from REQUEST_URI rather
 * than as a full absolute URL. That keeps the login workflow tied to this same
 * installation and avoids trusting a host supplied by the browser.
 */
function current_login_return_target(): string
{
    // $requestUri stores the path and query string that the visitor is viewing now.
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '') {
        return url_for('home');
    }

    return sanitize_login_return_target($requestUri, url_for('home'));
}

/**
 * Validate a submitted post-login return target and fall back when it is unsafe.
 *
 * Only same-site relative URLs are accepted. Absolute URLs, protocol-relative
 * URLs, login/logout routes, setup routes, and malformed values are ignored so
 * the login form cannot be abused as an open redirect.
 */
function sanitize_login_return_target(string $target, string $fallback = ''): string
{
    // $fallback stores the route used when no trustworthy return target exists.
    $fallback = $fallback !== '' ? $fallback : url_for('admin');
    // $target stores the trimmed value from either the query string or POST body.
    $target = trim($target);
    if ($target === '') {
        return $fallback;
    }

    // Reject control characters before parsing so headers cannot be polluted.
    if (preg_match('/[\x00-\x1F\x7F]/', $target)) {
        return $fallback;
    }

    // Only accept relative URLs. This prevents redirects to another domain.
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) || str_starts_with($target, '//')) {
        return $fallback;
    }

    // Convert plain relative paths to root-relative form for consistent parsing.
    if (!str_starts_with($target, '/')) {
        $target = '/' . ltrim($target, '/');
    }

    // $parts stores the parsed URL components used to inspect the local route.
    $parts = parse_url($target);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    // $path stores the requested path without query data.
    $path = (string) ($parts['path'] ?? '/');
    // $query stores the requested query string, if any.
    $query = (string) ($parts['query'] ?? '');
    // $queryParams stores parsed query arguments used for route-level exclusions.
    $queryParams = [];
    if ($query !== '') {
        parse_str($query, $queryParams);
    }

    // $page stores the front-controller page name when the URL uses index.php routing.
    $page = (string) ($queryParams['page'] ?? '');
    // Do not return to authentication, setup, or password-reset pages after login.
    if (in_array($page, ['admin_login', 'admin_logout', 'admin_forgot_password', 'admin_reset_password', 'setup'], true)) {
        return $fallback;
    }

    // Clean URL installations can also expose auth routes without a page query.
    // Keep this conservative because these paths should never be a post-login target.
    $lowerPath = strtolower($path);
    foreach (['admin_login', 'admin_logout', 'admin_forgot_password', 'admin_reset_password', 'setup'] as $unsafePathPart) {
        if (str_contains($lowerPath, $unsafePathPart)) {
            return $fallback;
        }
    }

    return $target;
}

/**
 * Build a query-string route URL.
 */
function url_for(string $page, array $params = []): string
{
    if ($page === 'tag' && isset($params['slug']) && count($params) === 1 && url_rewrite_should_emit_clean_urls()) {
        return base_url('tag/' . rawurlencode((string) $params['slug']));
    }
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
 * Return true when a logged-in admin explicitly requested the public page as an anonymous visitor.
 *
 * The request keeps the admin session intact, but public controllers can use this
 * read-only flag to apply anonymous visibility, access gates, and navigation.
 */
function admin_anonymous_preview_active(): bool
{
    if (!current_user()) {
        return false;
    }
    return (string) ($_GET['view_as'] ?? '') === 'anonymous';
}

/**
 * Add or remove the anonymous preview query flag for the supplied URL.
 */
function anonymous_preview_url(string $url, bool $enabled): string
{
    // $parts stores the parsed route while preserving clean gallery paths.
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    // $query stores the existing query values that should survive the preview toggle.
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    if ($enabled) {
        $query['view_as'] = 'anonymous';
    } else {
        unset($query['view_as']);
    }

    // $rebuilt stores the URL rebuilt with the original scheme, host, port, path, and fragment.
    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= (string) ($parts['path'] ?? '');
    if ($query) {
        $rebuilt .= '?' . http_build_query($query);
    }
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }
    return $rebuilt;
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
    if (!url_rewrite_should_emit_clean_urls()) {
        return url_for('gallery', ['public_path' => $urlPath]);
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
    $urlPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($urlPath === '') {
        $urlPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
    }
    if ($urlPath === '') {
        $urlPath = (string) ($gallery['slug'] ?? 'gallery');
    }
    if (!url_rewrite_should_emit_clean_urls()) {
        return url_for('gallery', ['public_path' => trim($urlPath . '/' . $slug, '/')]);
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
    if (function_exists('view_render_meta_tag')) {
        view_render_meta_tag($attributeName, $attributeValue, $content);
        return;
    }
    echo '<meta ' . $attributeName . '="' . e($attributeValue) . '" content="' . e($content) . '">' . "\n";
}

/**
 * Emit one link tag followed by a newline so crawler diagnostics are readable.
 */
function render_link_tag(string $rel, string $href): void
{
    if (function_exists('view_render_link_tag')) {
        view_render_link_tag($rel, $href);
        return;
    }
    echo '<link rel="' . e($rel) . '" href="' . e($href) . '">' . "\n";
}

/**
 * Render SEO tags for a gallery page.
 */
function render_public_seo_tags(array $gallery, array $images = []): void
{
    if (function_exists('view_render_public_seo_tags')) {
        view_render_public_seo_tags($gallery, $images);
        return;
    }
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
 *
 * The caller intentionally passes only the images rendered on the current public
 * page. Full-gallery lightbox ordering is handled by hidden source nodes in the
 * body, while crawler metadata stays capped to the visible pagination slice so
 * large galleries do not perform thumbnail resolution for every image during a
 * normal request.
 */
function render_gallery_json_ld(array $gallery, array $images = []): void
{
    if (function_exists('view_render_gallery_json_ld')) {
        view_render_gallery_json_ld($gallery, $images);
        return;
    }
    // $items stores an intermediate value used by the surrounding gallery workflow.
    $items = [];
    // $position stores an intermediate value used by the surrounding gallery workflow.
    $position = 1;
    // $jsonLdImages stores a conservative visible-page subset for crawler metadata.
    $jsonLdImages = array_slice($images, 0, 20);
    public_render_profile_count('seo_json_ld_images', count($jsonLdImages));

    foreach ($jsonLdImages as $image) {
        if (image_nsfw_restricted($image, $gallery)) {
            continue;
        }
        $imageName = image_alt_text($image, $gallery, $position);
        $item = [
            '@type' => 'ImageObject',
            'position' => $position++,
            'name' => $imageName,
            'description' => trim((string) ($image['description'] ?? '')) !== '' ? trim((string) $image['description']) : $imageName,
            'contentUrl' => absolute_public_url(public_render_profile_with_thumbnail_purpose('seo json-ld visible content 1200', static fn (): string => thumbnail_url($image, 1200, 'jpg'))),
            'thumbnailUrl' => absolute_public_url(public_render_profile_with_thumbnail_purpose('seo json-ld thumbnail 800', static fn (): string => thumbnail_url($image, 800, 'jpg'))),
            'url' => absolute_public_url(image_public_url($image, $gallery)),
        ];
        if (!empty($image['width'])) {
            $item['width'] = (int) $image['width'];
        }
        if (!empty($image['height'])) {
            $item['height'] = (int) $image['height'];
        }
        if (function_exists('public_sitemap_lastmod')) {
            $dateModified = public_sitemap_lastmod(public_sitemap_image_last_modified($image));
            if ($dateModified !== null) {
                $item['dateModified'] = $dateModified;
            }
        }
        $items[] = $item;
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
 * Output the sitemap XML with public gallery URLs and image sitemap metadata.
 */
function output_sitemap_xml(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $entries = function_exists('public_sitemap_entries')
        ? public_sitemap_entries()
        : array_map(static fn (string $url): array => ['loc' => $url, 'images' => []], public_gallery_sitemap_entries());

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    foreach ($entries as $entry) {
        $loc = trim((string) ($entry['loc'] ?? ''));
        if ($loc === '') {
            continue;
        }
        echo '<url>';
        echo '<loc>' . e($loc) . '</loc>';
        if (!empty($entry['lastmod'])) {
            echo '<lastmod>' . e((string) $entry['lastmod']) . '</lastmod>';
        }
        if (!empty($entry['priority'])) {
            echo '<priority>' . e((string) $entry['priority']) . '</priority>';
        }
        foreach (($entry['images'] ?? []) as $image) {
            $imageLoc = trim((string) ($image['loc'] ?? ''));
            if ($imageLoc === '') {
                continue;
            }
            echo '<image:image>';
            echo '<image:loc>' . e($imageLoc) . '</image:loc>';
            if (!empty($image['title'])) {
                echo '<image:title>' . e((string) $image['title']) . '</image:title>';
            }
            if (!empty($image['caption'])) {
                echo '<image:caption>' . e((string) $image['caption']) . '</image:caption>';
            }
            echo '</image:image>';
        }
        echo '</url>';
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
    if (function_exists('view_admin_menu_structure')) {
        return view_admin_menu_structure();
    }
    // $updatePending stores an intermediate value used by the surrounding gallery workflow.
    $updatePending = function_exists('application_update_pending') ? application_update_pending() : false;
    // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
    $updateLabel = function_exists('application_update_nav_label') ? application_update_nav_label($updatePending) : t('admin.menu.updates', 'Updates');
    return [
        [
            'label' => t('admin.menu.dashboard', 'Dashboard'),
            'items' => [
                ['label' => t('admin.menu.overview', 'Overview'), 'page' => 'admin', 'url' => url_for('admin')],
            ],
        ],
        [
            'label' => t('admin.menu.galleries', 'Galleries'),
            'items' => [
                ['label' => t('admin.menu.all_galleries', 'All galleries'), 'page' => 'admin', 'url' => url_for('admin') . '#admin-tab-galleries'],
                ['label' => t('admin.menu.create_gallery', 'Create gallery'), 'page' => 'admin_new_gallery', 'url' => url_for('admin_new_gallery')],
                ['label' => t('admin.menu.upload_photos', 'Upload photos'), 'page' => 'admin_upload', 'url' => url_for('admin_upload')],
                ['label' => t('admin.menu.api_manager', 'API manager'), 'page' => 'admin_api_manager', 'url' => url_for('admin_api_manager'), 'feature' => 'upload_api'],
                ['label' => t('admin.menu.edit_tags', 'Edit tags'), 'page' => 'admin_tags', 'url' => url_for('admin_tags')],
            ],
        ],
        [
            'label' => t('admin.menu.appearance', 'Appearance'),
            'items' => [
                ['label' => t('admin.menu.theme', 'Theme'), 'page' => 'admin_theme', 'url' => url_for('admin_theme')],
                ['label' => t('admin.menu.features', 'Features'), 'page' => 'admin_features', 'url' => url_for('admin_features')],
            ],
        ],
        [
            'label' => t('admin.menu.maintenance', 'Maintenance'),
            'items' => [
                ['label' => t('admin.menu.logs', 'Logs'), 'page' => 'admin_logs', 'url' => url_for('admin_logs')],
                ['label' => t('admin.menu.telemetry', 'Telemetry'), 'page' => 'admin_telemetry', 'url' => url_for('admin_telemetry'), 'feature' => 'telemetry'],
                ['label' => t('admin.menu.integrity', 'Integrity'), 'page' => 'admin_integrity', 'url' => url_for('admin_integrity')],
                ['label' => $updateLabel, 'page' => 'admin_update', 'url' => url_for('admin_update'), 'highlight' => $updatePending],
            ],
        ],
        [
            'label' => t('admin.menu.account', 'Account'),
            'items' => [
                ['label' => t('admin.menu.profile', 'Profile'), 'page' => 'admin_account', 'url' => url_for('admin_account')],
                ['label' => t('admin.menu.logout', 'Logout'), 'page' => 'admin_logout', 'url' => url_for('admin_logout')],
            ],
        ],
    ];
}
/**
 * Return true when one admin menu item should be marked as active.
 */
function admin_menu_item_is_active(array $item, string $currentPage): bool
{
    if (function_exists('view_admin_menu_item_is_active')) {
        return view_admin_menu_item_is_active($item, $currentPage);
    }
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
        return str_contains((string) ($item['url'] ?? ''), '#admin-tab-galleries');
    }
    return false;
}


/**
 * Render a reusable admin tab list.
 *
 * Each tab accepts id, label, optional badge, optional href, and optional active.
 * The generated anchors keep normal hash navigation available when JavaScript is
 * unavailable, while the browser module upgrades them to in-page tab controls.
 *
 * @param array<int, array<string, mixed>> $tabs Tab definitions.
 * @param string $activeId Preferred active tab id. The first tab is used when empty.
 * @return void
 */
function render_admin_tabs(array $tabs, string $activeId = ''): void
{
    if (function_exists('view_render_admin_tabs')) {
        view_render_admin_tabs($tabs, $activeId);
        return;
    }
    // $resolvedActiveId stores the tab id that should be announced as selected.
    $resolvedActiveId = $activeId;
    if ($resolvedActiveId === '') {
        foreach ($tabs as $tab) {
            if (!empty($tab['active']) && !empty($tab['id'])) {
                $resolvedActiveId = (string) $tab['id'];
                break;
            }
        }
    }
    if ($resolvedActiveId === '' && isset($tabs[0]['id'])) {
        $resolvedActiveId = (string) $tabs[0]['id'];
    }

    echo '<nav class="admin-tabs" data-admin-tabs aria-label="' . e(t('admin.tabs.aria_sections', 'Admin sections')) . '">';
    echo '<div class="admin-tab-list" role="tablist">';
    foreach ($tabs as $tab) {
        // $tabId stores the panel id controlled by this tab.
        $tabId = trim((string) ($tab['id'] ?? ''));
        if ($tabId === '') {
            continue;
        }
        // $tabLabel stores the visible tab label.
        $tabLabel = (string) ($tab['label'] ?? $tabId);
        // $tabHref stores the normal link target used without JavaScript.
        $tabHref = (string) ($tab['href'] ?? ('#' . $tabId));
        // $isActive stores whether this tab is selected in server-rendered markup.
        $isActive = $tabId === $resolvedActiveId;
        // $controlId stores the accessible id for the tab control.
        $controlId = $tabId . '-control';
        echo '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" id="' . e($controlId) . '" href="' . e($tabHref) . '" role="tab" aria-controls="' . e($tabId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-tab-target="' . e($tabId) . '">';
        echo '<span>' . e($tabLabel) . '</span>';
        if (array_key_exists('badge', $tab) && $tab['badge'] !== null && $tab['badge'] !== '') {
            echo '<span class="admin-tab-badge">' . e((string) $tab['badge']) . '</span>';
        }
        echo '</a>';
    }
    echo '</div></nav>';
}

/**
 * Render one reusable admin tab panel.
 *
 * Panels are intentionally visible in the raw server response. JavaScript hides
 * inactive panels after it reads the current hash, so the page remains usable
 * when scripting is unavailable.
 *
 * @param string $id Panel id referenced by the matching tab.
 * @param string $contentHtml Trusted admin HTML rendered by the caller.
 * @param bool $active Whether the panel should start selected.
 * @return void
 */
function render_admin_tab_panel(string $id, string $contentHtml, bool $active = false): void
{
    if (function_exists('view_render_admin_tab_panel')) {
        view_render_admin_tab_panel($id, $contentHtml, $active);
        return;
    }
    // $controlId stores the generated tab id used by aria-labelledby.
    $controlId = $id . '-control';
    echo '<section class="panel admin-tab-panel' . ($active ? ' is-active' : '') . '" id="' . e($id) . '" role="tabpanel" aria-labelledby="' . e($controlId) . '" data-admin-tab-panel>';
    echo $contentHtml;
    echo '</section>';
}

/**
 * Render the persistent admin sidebar used by all authenticated admin pages.
 */
function render_admin_sidebar(string $currentPage): void
{
    if (function_exists('view_render_admin_sidebar')) {
        view_render_admin_sidebar($currentPage);
        return;
    }
    echo '<aside class="admin-sidebar" aria-label="' . e(t('admin.menu.aria_navigation', 'Admin navigation')) . '">';
    echo '<div class="admin-sidebar-title">' . e(t('admin.menu.title', 'Admin')) . '</div>';
    foreach (admin_menu_structure() as $group) {
        echo '<section class="admin-menu-group">';
        echo '<h2>' . e((string) $group['label']) . '</h2>';
        echo '<nav class="admin-menu-links">';
        foreach ((array) $group['items'] as $item) {
            // $featureKey stores the optional feature gate assigned to this menu item.
            $featureKey = (string) ($item['feature'] ?? '');
            if ($featureKey !== '' && function_exists('feature_flag_enabled') && !feature_flag_enabled($featureKey)) {
                continue;
            }
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
    if (function_exists('view_render_missing_admin_email_notice')) {
        view_render_missing_admin_email_notice($user, $currentPage);
        return;
    }
    if (!$user || $currentPage === 'admin_login' || $currentPage === 'admin_logout' || $currentPage === 'setup') {
        return;
    }
    if (trim((string) ($user['email'] ?? '')) !== '') {
        return;
    }

    echo '<div class="notice admin-account-notice">';
    echo '<strong>' . e(t('admin.account.notice_recovery_email_missing_title', 'Recovery email missing.')) . '</strong> ';
    echo e(t('admin.account.notice_recovery_email_missing_body', 'Add an email address to your account so username-or-email login works and the app is ready for future account recovery.'));
    echo ' <a href="' . e(url_for('admin_account')) . '">' . e(t('admin.account.open_account_settings', 'Open account settings')) . '</a>';
    echo '</div>';
}


/**
 * Return optional artwork for the shared public header.
 */
function public_header_branding_model(string $siteName, ?array $currentGallery = null, bool $publicOnly = true, string $bodyClass = 'public-page'): array
{
    if (function_exists('view_public_header_branding_model')) {
        return view_public_header_branding_model($siteName, $currentGallery, $publicOnly, $bodyClass);
    }
    // $model stores URLs used by render_header without forcing callers to know the branding precedence.
    $model = [
        'banner_url' => '',
        'logo_url' => '',
        'separator_url' => '',
    ];
    if ($bodyClass !== 'public-page') {
        return $model;
    }
    if ($currentGallery !== null && function_exists('gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
        // Per-gallery artwork overrides Theme fallback artwork on that gallery page.
        $model['banner_url'] = gallery_branding_asset_url($currentGallery, 'banner', $publicOnly);
        $model['logo_url'] = gallery_branding_asset_url($currentGallery, 'logo', $publicOnly);
        $model['separator_url'] = gallery_branding_asset_url($currentGallery, 'separator', $publicOnly);
    }
    if ($model['banner_url'] === '' && function_exists('theme_branding_asset_url')) {
        $model['banner_url'] = theme_branding_asset_url('banner');
    }
    if ($model['separator_url'] === '' && function_exists('theme_branding_asset_url')) {
        $model['separator_url'] = theme_branding_asset_url('separator');
    }
    return $model;
}

/**
 * Render configured favorite gallery shortcut links for fallback header rendering.
 *
 * @param array<int, array<string, mixed>> $items Resolved favorite gallery navigation items.
 * @return string Favorite gallery anchor markup, or an empty string when none are configured.
 */
function favorite_gallery_nav_html(array $items): string
{
    // $html stores the compact anchor list inserted into the shared header nav.
    $html = '';
    foreach ($items as $item) {
        // $url stores the final public gallery URL for one configured shortcut.
        $url = trim((string) ($item['url'] ?? ''));
        // $title stores the button label, normally the gallery title.
        $title = trim((string) ($item['title'] ?? ''));
        if ($url === '' || $title === '') {
            continue;
        }
        $html .= '<a class="nav-favorite-gallery" href="' . e($url) . '">' . e($title) . '</a>';
    }
    return $html;
}

/**
 * Render the shared document header, navigation, theme variables, and CSS links.
 */
function render_header(string $title, ?array $currentGallery = null, bool $publicOnly = true): void
{
    if (function_exists('view_render_header')) {
        view_render_header($title, $currentGallery, $publicOnly);
        return;
    }
    // Variable $user stores this steps working value.
    $user = current_user();
    // Variable $anonymousPreview stores whether this public request should hide authenticated navigation.
    $anonymousPreview = admin_anonymous_preview_active();
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
    echo '<!doctype html><html lang="' . e(function_exists('translation_active_language') ? translation_active_language() : 'en') . '" translate="no"><head><meta charset="utf-8">';
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
    // Built-in stylesheets are linked directly with per-file cache keys.
    // This avoids stale browser caches for CSS files that were previously loaded through @import.
    $styleFiles = [
        'assets/styles/base.css',
        'assets/styles/public.css',
        'assets/styles/lightbox.css',
        'assets/styles/admin.css',
        'assets/styles/admin-layout.css',
        'assets/styles/admin-dashboard.css',
        'assets/styles/admin-theme-preview.css',
        'assets/styles/admin-reordering.css',
        'assets/styles/admin-media-tools.css',
        'assets/styles/admin-theme-editor.css',
        'assets/styles/admin-gallery-list.css',
        'assets/styles/admin-patch-notes.css',
        'assets/styles/admin-update.css',
        'assets/styles/admin-tags.css',
        'assets/styles/side-panel.css',
        'assets/styles/utilities.css',
        'assets/styles.css',
    ];
    foreach ($styleFiles as $styleFile) {
        $stylePath = dirname(__DIR__) . '/public/' . $styleFile;
        if (!is_file($stylePath)) {
            continue;
        }
        echo '<link rel="stylesheet" href="' . e(asset_url($styleFile)) . '?v=' . filemtime($stylePath) . '">';
    }
    // Variable $customCss stores this steps working value.
    $customCss = custom_css_url();
    if ($customCss) {
        echo '<link rel="stylesheet" href="' . e($customCss) . '?v=' . filemtime(custom_css_path()) . '">';
    }
    echo '<link rel="stylesheet" href="' . e(url_for('theme_css')) . '&v=' . rawurlencode((string) theme_cache_key($theme)) . '">';
    $mobileGalleryStyle = 'assets/styles/mobile-gallery.css';
    $mobileGalleryStylePath = dirname(__DIR__) . '/public/' . $mobileGalleryStyle;
    if (is_file($mobileGalleryStylePath)) {
        echo '<link rel="stylesheet" href="' . e(asset_url($mobileGalleryStyle)) . '?v=' . filemtime($mobileGalleryStylePath) . '">';
    }
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
    // $headerBranding stores optional artwork that replaces the visible site title.
    $headerBranding = public_header_branding_model($siteName, $currentGallery, $publicOnly, $bodyClass);
    echo '<header class="site-header">';
    echo '<a class="brand' . ($headerBranding['banner_url'] !== '' ? ' brand-with-banner' : '') . '" href="' . e(url_for('home')) . '">';
    if ($headerBranding['logo_url'] !== '') {
        echo '<img class="brand-logo" src="' . e($headerBranding['logo_url']) . '" alt="" aria-hidden="true" decoding="async">';
    }
    if ($headerBranding['banner_url'] !== '') {
        echo '<span class="visually-hidden">' . e($siteName) . '</span><img class="brand-banner" src="' . e($headerBranding['banner_url']) . '" alt="" aria-hidden="true" decoding="async">';
    } else {
        echo e($siteName);
    }
    echo '</a><nav class="nav">';
    // $favoritePublicOnly stores whether shortcuts should be restricted to public listed galleries.
    $favoritePublicOnly = !$user || $anonymousPreview;
    // $favoriteGalleryItems stores resolved gallery shortcuts for the top navigation.
    $favoriteGalleryItems = function_exists('theme_favorite_gallery_navigation_items') ? theme_favorite_gallery_navigation_items($favoritePublicOnly) : [];
    echo favorite_gallery_nav_html($favoriteGalleryItems);
    if ($user && !$anonymousPreview) {
        if ($bodyClass === 'public-page') {
            // $updatePending stores an intermediate value used by the surrounding gallery workflow.
            $updatePending = application_update_pending();
            // $updateClass stores an intermediate value used by the surrounding gallery workflow.
            $updateClass = $updatePending ? ' class="is-update-pending"' : '';
            // $updateLabel stores an intermediate value used by the surrounding gallery workflow.
            $updateLabel = application_update_nav_label($updatePending);
            echo '<a href="' . e(url_for('admin')) . '">' . e(t('nav.admin', 'Admin')) . '</a>';
            echo '<a' . $updateClass . ' href="' . e(url_for('admin_update')) . '">' . e($updateLabel) . '</a>';
        }
        echo '<a href="' . e(url_for('admin_logout')) . '">' . e(t('nav.logout', 'Logout')) . '</a>';
    } else {
        echo '<a href="' . e(url_for('admin_login', ['return' => current_login_return_target()])) . '">' . e(t('nav.admin_login', 'Admin login')) . '</a>';
    }
    echo '</nav></header>';
    if ($headerBranding['separator_url'] !== '') {
        echo '<div class="site-branding-separator" aria-hidden="true"><img src="' . e($headerBranding['separator_url']) . '" alt="" decoding="async"></div>';
    }
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
 * Return translated strings used by browser-side modules.
 */
function cms_browser_i18n_strings(): array
{
    if (function_exists('view_cms_browser_i18n_strings')) {
        return view_cms_browser_i18n_strings();
    }
    return [
        'admin.bulk.select_gallery_delete' => t('js.admin.bulk.select_gallery_delete', 'Select at least one gallery to delete.'),
        'admin.bulk.delete_galleries_title' => t('js.admin.bulk.delete_galleries_title', 'Delete these gallery folders and all subgalleries?'),
        'admin.bulk.delete_galleries_detail' => t('js.admin.bulk.delete_galleries_detail', 'This removes the folders from disk and deletes their database records. This cannot be undone.'),
        'admin.bulk.gallery_fallback' => t('js.admin.bulk.gallery_fallback', 'Gallery {id}'),
        'admin.bulk.image_fallback' => t('js.admin.bulk.image_fallback', 'Image {id}'),
        'admin.bulk.selected_photo_fallback' => t('js.admin.bulk.selected_photo_fallback', 'Selected photo'),
        'admin.bulk.photo_selected_one' => t('js.admin.bulk.photo_selected_one', '1 photo selected'),
        'admin.bulk.photo_selected_many' => t('js.admin.bulk.photo_selected_many', '{count} photos selected'),
        'admin.bulk.select_photos_first' => t('js.admin.bulk.select_photos_first', 'Select one or more photos first.'),
        'admin.bulk.choose_move_action_summary' => t('js.admin.bulk.choose_move_action_summary', '{count} selected. Choose one of the move actions above.'),
        'admin.bulk.choose_destination_summary' => t('js.admin.bulk.choose_destination_summary', '{count} selected. Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery_summary' => t('js.admin.bulk.enter_new_gallery_summary', '{count} selected. Enter the new gallery title.'),
        'admin.bulk.existing_gallery' => t('js.admin.bulk.existing_gallery', 'existing gallery'),
        'admin.bulk.new_gallery' => t('js.admin.bulk.new_gallery', 'new gallery'),
        'admin.bulk.move_summary' => t('js.admin.bulk.move_summary', '{count} selected. Move originals, thumbnails, and generated display files to the {target_type}: {target}.'),
        'admin.bulk.choose_move_type' => t('js.admin.bulk.choose_move_type', 'Choose whether to move to an existing gallery or a new gallery.'),
        'admin.bulk.select_photo_move' => t('js.admin.bulk.select_photo_move', 'Select at least one photo to move.'),
        'admin.bulk.select_photo_delete' => t('js.admin.bulk.select_photo_delete', 'Select at least one photo to delete.'),
        'admin.bulk.choose_destination' => t('js.admin.bulk.choose_destination', 'Choose the destination gallery.'),
        'admin.bulk.enter_new_gallery' => t('js.admin.bulk.enter_new_gallery', 'Enter the new gallery title.'),
        'admin.bulk.move_photo_one' => t('js.admin.bulk.move_photo_one', 'Move this photo?'),
        'admin.bulk.move_photo_many' => t('js.admin.bulk.move_photo_many', 'Move these photos?'),
        'admin.bulk.move_photo_detail' => t('js.admin.bulk.move_photo_detail', 'This physically moves the original files, generated thumbnails, and display derivatives. The source gallery will no longer contain them.'),
        'admin.bulk.delete_photo_one' => t('js.admin.bulk.delete_photo_one', 'Delete this photo from the gallery?'),
        'admin.bulk.delete_photo_many' => t('js.admin.bulk.delete_photo_many', 'Delete these photos from the gallery?'),
        'admin.bulk.delete_photo_detail' => t('js.admin.bulk.delete_photo_detail', 'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.'),
        'admin.openai.js_missing_form' => t('js.admin.openai.missing_form', 'The editor form could not be found.'),
        'admin.openai.js_missing_textarea' => t('js.admin.openai.missing_textarea', 'The description field could not be found.'),
        'admin.openai.js_requires_text' => t('js.admin.openai.requires_text', 'This action needs existing description text first.'),
        'admin.openai.js_replace_confirm' => t('js.admin.openai.replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the edited item.'),
        'admin.openai.js_visual_confirm' => t('js.admin.openai.visual_confirm', 'This action will send one or more small generated thumbnails, not the original files, to OpenAI. Continue?'),
        'admin.openai.js_not_configured' => t('js.admin.openai.not_configured', 'OpenAI text assistance is not configured correctly on this page.'),
        'admin.openai.js_generating' => t('js.admin.openai.generating', 'Generating OpenAI text suggestion...'),
        'admin.openai.js_failed' => t('js.admin.openai.failed', 'OpenAI text generation failed.'),
        'admin.openai.js_empty' => t('js.admin.openai.empty', 'OpenAI returned an empty suggestion.'),
        'admin.openai.js_generated' => t('js.admin.openai.generated', 'Suggestion inserted. Save the edited item to keep it.'),
        'admin.openai.js_invalid_json' => t('js.admin.openai.invalid_json', 'The server returned an invalid OpenAI response.'),
        'admin.openai.js_html_response' => t('js.admin.openai.html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'admin.openai.js_bulk_counting' => t('js.admin.openai.bulk_counting', 'Counting photos for bulk description...'),
        'admin.openai.js_bulk_no_photos' => t('js.admin.openai.bulk_no_photos', 'This gallery has no photos to describe.'),
        'admin.openai.js_bulk_confirm' => t('js.admin.openai.bulk_confirm', 'This will generate and save descriptions for {count} photo(s), one OpenAI request per photo. Existing descriptions may be replaced. Type {count} to continue.'),
        'admin.openai.js_bulk_cancelled' => t('js.admin.openai.bulk_cancelled', 'Bulk photo description cancelled.'),
        'admin.openai.js_bulk_progress' => t('js.admin.openai.bulk_progress', 'Generating photo descriptions: {done}/{total} complete, {failed} failed.'),
        'admin.openai.js_bulk_done' => t('js.admin.openai.bulk_done', 'Bulk photo descriptions finished: {done}/{total} saved, {failed} failed.'),
        'admin.thumbnails.delete_not_configured' => t('js.admin.thumbnails.delete_not_configured', 'Thumbnail deletion is not configured correctly. No files were deleted.'),
        'admin.thumbnails.delete_prompt_intro' => t('js.admin.thumbnails.delete_prompt_intro', 'This will delete all generated thumbnail files for every gallery.'),
        'admin.thumbnails.delete_prompt_originals' => t('js.admin.thumbnails.delete_prompt_originals', 'Original photos and gallery records will not be deleted.'),
        'admin.thumbnails.delete_prompt_regenerate' => t('js.admin.thumbnails.delete_prompt_regenerate', 'The next public/admin view can regenerate thumbnails when needed.'),
        'admin.thumbnails.delete_prompt_confirm' => t('js.admin.thumbnails.delete_prompt_confirm', 'Type {word} to confirm.'),
        'admin.thumbnails.delete_cancelled' => t('js.admin.thumbnails.delete_cancelled', 'Thumbnail deletion cancelled. No thumbnail files were deleted.'),
        'admin.operations.scanning' => t('js.admin.operations.scanning', 'Scanning...'),
        'admin.operations.scan_detail' => t('js.admin.operations.scan_detail', 'Scanning existing galleries and checking for new gallery folders...'),
        'admin.operations.working' => t('js.admin.operations.working', 'Working...'),
        'admin.operations.upload_thumbnail_failed' => t('js.admin.operations.upload_thumbnail_failed', 'Upload finished, but {count} thumbnail or DNG display derivative(s) failed.'),
        'admin.operations.upload_complete' => t('js.admin.operations.upload_complete', 'Upload and thumbnail job complete.'),
        'admin.operations.uploaded_scanning_complete' => t('js.admin.operations.uploaded_scanning_complete', 'Uploaded {count} images. Scanning complete.'),
        'admin.operations.upload_failed' => t('js.admin.operations.upload_failed', 'Upload failed.'),
        'votes.liked' => t('js.votes.liked', 'Liked'),
        'votes.no_like' => t('js.votes.no_like', 'No like'),
        'thumbnail_bounds.auto_min' => t('thumbnail_bounds.auto_min', 'Auto min'),
        'thumbnail_bounds.auto_max' => t('thumbnail_bounds.auto_max', 'Auto max'),
        'admin.date_picker.open' => t('js.admin.date_picker.open', 'Open calendar'),
        'admin.date_picker.today' => t('js.admin.date_picker.today', 'Today'),
        'admin.date_picker.delete' => t('js.admin.date_picker.delete', 'Delete'),
        'admin.simbrief.js_missing_form' => t('admin.simbrief.js_missing_form', 'The gallery form could not be found.'),
        'admin.simbrief.js_missing_textarea' => t('admin.simbrief.js_missing_textarea', 'The description field could not be found.'),
        'admin.simbrief.js_missing_identifier' => t('admin.simbrief.js_missing_identifier', 'Enter a SimBrief Pilot ID or pilot name first.'),
        'admin.simbrief.js_replace_confirm' => t('admin.simbrief.js_replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the gallery.'),
        'admin.simbrief.js_not_configured' => t('admin.simbrief.js_not_configured', 'SimBrief generation is not configured correctly on this page.'),
        'admin.simbrief.js_generating' => t('admin.simbrief.js_generating', 'Fetching SimBrief data and generating draft...'),
        'admin.simbrief.js_failed' => t('admin.simbrief.js_failed', 'SimBrief generation failed.'),
        'admin.simbrief.js_empty' => t('admin.simbrief.js_empty', 'SimBrief returned flight data, but no description could be generated.'),
        'admin.simbrief.js_generated' => t('admin.simbrief.js_generated', 'Draft generated. The latest OFP was saved with the gallery and the route map was updated.'),
        'admin.simbrief.js_ofp_saved' => t('admin.simbrief.js_ofp_saved', 'OFP saved with this gallery.'),
        'admin.simbrief.js_route_saved' => t('admin.simbrief.js_route_saved', 'Route map updated with {points} OFP point(s).'),
        'admin.simbrief.js_invalid_json' => t('admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.'),
        'admin.simbrief.js_html_response' => t('admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'),
        'lightbox.no_gps_title' => t('lightbox.no_gps_title', 'No GPS EXIF data'),
        'lightbox.no_gps_detail' => t('lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'),
    ];
}

/**
 * Render translated strings for browser-side modules before the ES module entrypoint loads.
 */
function render_browser_i18n_script(): void
{
    if (function_exists('view_render_browser_i18n_script')) {
        view_render_browser_i18n_script();
        return;
    }
    $payload = [
        'language' => translation_active_language(),
        'strings' => cms_browser_i18n_strings(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{"language":"en","strings":{}}';
    }
    echo '<script>window.PHP_GALLERY_I18N = ' . $json . ';</script>';
}

/**
 * Render the shared footer and JavaScript include.
 */
function render_footer(): void
{
    if (function_exists('view_render_footer')) {
        view_render_footer();
        return;
    }
    // $page stores an intermediate value used by the surrounding gallery workflow.
    $page = (string) ($_GET['page'] ?? 'home');
    // $hasAdminShell stores an intermediate value used by the surrounding gallery workflow.
    $hasAdminShell = (str_starts_with($page, 'admin') || $page === 'setup') && current_user();
    echo '</main>' . ($hasAdminShell ? '</div>' : '') . '<footer class="site-footer muted">';
    echo '<a class="site-footer-link" href="' . e(cms_github_project_url()) . '" target="_blank" rel="noopener noreferrer">PHP Gallery (' . e(cms_current_version()) . ')</a>';
    echo '</footer>';
    // Variable $scriptPath stores this steps working value.
    $scriptPath = dirname(__DIR__) . '/public/assets/gallery.js';
    $scriptVersionPaths = [
        $scriptPath,
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/lightbox-votes.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/tag-suggestions.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/votes.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-operations.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-core.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-side-panel.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-date-picker.js',
        dirname(__DIR__) . '/public/assets/gallery-modules/admin-simbrief-description.js',
    ];
    $scriptVersion = 0;
    foreach ($scriptVersionPaths as $versionPath) {
        if (is_file($versionPath)) {
            $scriptVersion = max($scriptVersion, filemtime($versionPath));
        }
    }
    render_browser_i18n_script();
    echo '<script type="module" src="' . e(asset_url('assets/gallery.js')) . '?v=' . ($scriptVersion > 0 ? $scriptVersion : time()) . '"></script>';
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
    if (function_exists('dng_conversion_supported') && dng_conversion_supported()) {
        $extensions[] = 'dng';
    }
    return $extensions;
}

/**
 * Return whether a filesystem path uses the Adobe Digital Negative extension.
 */
function is_dng_image_path(string $path): bool
{
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng';
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
    // $assetRevision changes when the generated-theme controller changes, so immutable browser caches do not keep stale generated CSS.
    $assetRevision = is_file(__DIR__ . '/controllers/theme_assets.php') ? (string) filemtime(__DIR__ . '/controllers/theme_assets.php') : '0';
    // $cachePayload combines user settings with the implementation revision that affects the emitted CSS rules.
    $cachePayload = ['theme' => $theme, 'asset_revision' => $assetRevision];
    return substr(hash('sha256', json_encode($cachePayload, JSON_UNESCAPED_SLASHES)), 0, 12);
}

/**
 * Escape a CSS custom property value used by the generated theme stylesheet.
 */
function css_value(string $value): string
{
    return str_replace(['\\', ';', '{', '}'], '', $value);
}
