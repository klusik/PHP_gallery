<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_request.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides request, base URL, login return target, and low-level URL construction helpers.
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
 *   2026-08-11
 */

declare(strict_types=1);

namespace Gallery\Core;

use PDO;
use RuntimeException;
use function Gallery\Services\app_setting;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\cms_github_project_url;
use function Gallery\Services\custom_css_path;
use function Gallery\Services\custom_css_url;
use function Gallery\Services\dev_mode_enabled;
use function Gallery\Services\dng_conversion_supported;
use function Gallery\Services\favicon_asset_url;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_cover_collage_images;
use function Gallery\Services\gallery_cover_image;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\heic_conversion_supported;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_gallery_sitemap_entries;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_sitemap_entries;
use function Gallery\Services\public_sitemap_image_last_modified;
use function Gallery\Services\public_sitemap_lastmod;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\theme_branding_asset_url;
use function Gallery\Services\theme_favorite_gallery_navigation_items;
use function Gallery\Services\theme_page_width_mode;
use function Gallery\Services\theme_settings;
use function Gallery\Services\thumbnail_abs_path;
use function Gallery\Services\thumbnail_bound_filter_sizes;
use function Gallery\Services\thumbnail_existing_fallback;
use function Gallery\Services\thumbnail_metadata_select_renderable_variant;
use function Gallery\Services\thumbnail_serving_url;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\translation_default_language;
use function Gallery\Services\translation_load_language;
use function Gallery\Services\url_rewrite_should_emit_clean_urls;
use function Gallery\Views\view_admin_menu_item_is_active;
use function Gallery\Views\view_admin_menu_structure;
use function Gallery\Views\view_cms_browser_i18n_strings;
use function Gallery\Views\view_public_header_branding_model;
use function Gallery\Views\view_render_admin_sidebar;
use function Gallery\Views\view_render_admin_subtab_panel;
use function Gallery\Views\view_render_admin_subtabs;
use function Gallery\Views\view_render_admin_tab_panel;
use function Gallery\Views\view_render_admin_tabs;
use function Gallery\Views\view_render_browser_i18n_script;
use function Gallery\Views\view_render_footer;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_header;
use function Gallery\Views\view_render_link_tag;
use function Gallery\Views\view_render_meta_tag;
use function Gallery\Views\view_render_missing_admin_email_notice;
use function Gallery\Views\view_render_public_seo_tags;

/**
 * Escape text for safe HTML output.
 *
 * @param ?string $value Value to process.
 * @return string Text result for the caller.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return whether the current request reached the app through HTTPS.
 *
 * @return bool True when the condition matches.
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
 *
 * @return string Text result for the caller.
 */
function request_host_name(): string
{
    // $host stores an intermediate value used by the surrounding gallery workflow.
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return preg_replace('/:\d+$/', '', $host) ?: '';
}

/**
 * Return the base path implied by the current front controller request.
 *
 * @return string Text result for the caller.
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
 *
 * @param string $base Base value.
 * @return string Text result for the caller.
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
 *
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
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
 *
 * @param string $target Target value.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
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
 *
 * @param string $page Page number or page data.
 * @param array $params Params value.
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
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
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
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
 *
 * @param string $path Filesystem path.
 * @return string Text result for the caller.
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
 *
 * @return bool True when the condition matches.
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
 *
 * @param string $url URL used by this workflow.
 * @param bool $enabled Enabled flag.
 * @return string Text result for the caller.
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
