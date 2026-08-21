<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/seo_request_guard.php
 * Module Type: Service
 *
 * Purpose:
 *   Defends public crawler-facing routes against query-string spam and duplicate URL pollution.
 *
 * Responsibilities:
 *   - Keep public GET parameter validation centralized
 *   - Reject suspicious public query strings before controllers render content
 *   - Emit stable fallback canonical URLs for public pages that do not own a richer SEO model
 *   - Keep rejection logging sampled so crawler abuse cannot flood the admin log
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
 *   2026-06-07
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\canonical_url_for_gallery;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\public_base_url;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;

const CMS_SEO_REQUEST_GUARD_LOG_LIMIT_PER_DAY = 25;

/**
 * Return whether public query-string spam protection is active.
 *
 * @return bool True when the condition matches.
 */
function seo_request_guard_enabled(): bool
{
    return app_setting('seo_request_guard_enabled', '1') === '1';
}

/**
 * Persist whether public query-string spam protection is active.
 *
 * @param bool $enabled Enabled flag.
 */
function set_seo_request_guard_enabled(bool $enabled): void
{
    set_app_setting('seo_request_guard_enabled', $enabled ? '1' : '0');
}

/**
 * Return whether sampled rejected-query events should be written into Admin logs.
 *
 * @return bool True when the condition matches.
 */
function seo_request_guard_logging_enabled(): bool
{
    return app_setting('seo_request_guard_logging_enabled', '1') === '1';
}

/**
 * Persist whether sampled rejected-query events should be written into Admin logs.
 *
 * @param bool $enabled Enabled flag.
 */
function set_seo_request_guard_logging_enabled(bool $enabled): void
{
    set_app_setting('seo_request_guard_logging_enabled', $enabled ? '1' : '0');
}

/**
 * Return a compact status model for Admin dashboard rendering.
 *
 * @return array<string mixed>.
 */
function seo_request_guard_status(): array
{
    return [
        'enabled' => seo_request_guard_enabled(),
        'logging_enabled' => seo_request_guard_logging_enabled(),
        'log_day' => (string) app_setting('seo_request_guard_log_day', ''),
        'log_count' => max(0, (int) app_setting('seo_request_guard_log_count', '0')),
        'log_limit' => CMS_SEO_REQUEST_GUARD_LOG_LIMIT_PER_DAY,
    ];
}

/**
 * Return route names where strict public query validation must not run.
 *
 * @param string $page Page number or page data.
 * @return bool True when the condition matches.
 */
function seo_request_guard_route_is_exempt(string $page): bool
{
    if (str_starts_with($page, 'admin')) {
        return true;
    }
    // Viewer account routes own strict bearer/pre-auth validation and may carry security tokens.
    // Exempt them so the SEO guard cannot reject or log complete secret-bearing query URLs.
    if (str_starts_with($page, 'viewer_')) {
        return true;
    }

    return in_array($page, [
        'setup',
        'mobile_webdav',
        'site_maintenance_cron',
        'upload_automation_upload',
        'gallery_migration_receive_manifest',
        'gallery_migration_receive_asset',
        'gallery_migration_receive_complete',
        'gallery_migration_receive_status',
    ], true);
}

/**
 * Return known analytics parameters that do not change rendered content.
 *
 * @return array<string bool>.
 */
function seo_request_guard_ignored_tracking_parameters(): array
{
    return [
        'utm_source' => true,
        'utm_medium' => true,
        'utm_campaign' => true,
        'utm_content' => true,
        'utm_term' => true,
        'gclid' => true,
        'gbraid' => true,
        'wbraid' => true,
        'fbclid' => true,
        'msclkid' => true,
    ];
}

/**
 * Return public query parameters accepted by each route.
 *
 * @param string $page Page number or page data.
 * @return array<int string>.
 */
function seo_request_guard_allowed_parameters_for_page(string $page): array
{
    $global = ['page', 'lang'];
    $map = [
        'home' => ['gallery_page', 'view_as'],
        'gallery' => ['public_path', 'gallery_path', 'slug', 'gallery_page', 'photo_page', 'share', 'token', 'q', 'view_as', 'benchmark_token', 'benchmark_run', 'benchmark_cache_bust', 'benchmark_phase'],
        'smart_gallery' => ['slug', 'photo_page', 'view_as'],
        'tag' => ['slug', 'view_as'],
        'public_search' => ['q', 'context_only', 'gallery_id'],
        'gallery_lightbox_data' => ['id', 'limit', 'offset', 'view_as', 'benchmark_token', 'benchmark_run', 'benchmark_phase'],
        'smart_gallery_lightbox_data' => ['id', 'limit', 'offset', 'view_as'],
        'gallery_map_data' => ['id', 'view_as'],
        'picture_game' => ['id', 'view_as'],
        'vote' => ['id'],
        'gallery_access' => ['id', 'token', 'share', 'return'],
        'share' => ['token', 'id', 'view_as'],
        'media' => ['id', 'v'],
        'thumb' => ['id', 'size', 'format', 'v'],
        'public_media' => ['public_path', 'v'],
        'public_thumb' => ['public_path', 'size', 'format', 'v'],
        'gallery_cover_asset' => ['id', 'v'],
        'gallery_branding_asset' => ['id', 'kind', 'v'],
        'theme_background_asset' => ['variant', 'v'],
        'theme_branding_asset' => ['kind', 'v'],
        'favicon_asset' => ['s', 'v'],
        'theme_css' => ['v'],
        'browser_i18n' => ['scope', 'lang', 'v'],
        'download_gallery' => ['id', 'token', 'share'],
        'download_smart_gallery' => ['id'],
        'robots' => [],
        'sitemap' => [],
        'not_found' => [],
    ];

    return array_values(array_unique(array_merge($global, $map[$page] ?? [])));
}

/**
 * Return unexpected public query keys for the current request.
 *
 * @param string $page Page number or page data.
 * @return array<int string>.
 */
function seo_request_guard_unexpected_query_parameters(string $page): array
{
    $allowed = array_fill_keys(seo_request_guard_allowed_parameters_for_page($page), true);
    $tracking = seo_request_guard_ignored_tracking_parameters();
    $unexpected = [];

    foreach (array_keys($_GET) as $rawName) {
        $name = (string) $rawName;
        $lowerName = strtolower($name);
        if (isset($allowed[$name]) || isset($tracking[$lowerName])) {
            continue;
        }
        $unexpected[] = $name;
    }

    sort($unexpected, SORT_STRING);
    return $unexpected;
}

/**
 * Enforce public GET query-string safety before route handlers render content.
 *
 * @param string $page Page number or page data.
 */
function seo_request_guard_enforce(string $page): void
{
    if (request_method() !== 'GET' || !seo_request_guard_enabled()) {
        return;
    }
    if (seo_request_guard_route_is_exempt($page)) {
        return;
    }
    if (current_user() !== null) {
        return;
    }

    $unexpected = seo_request_guard_unexpected_query_parameters($page);
    if (!$unexpected) {
        return;
    }

    seo_request_guard_reject($page, $unexpected);
}

/**
 * Reject a suspicious public query without invoking the normal public renderer.
 *
 * @param string $page Page number or page data.
 * @param array $unexpected Unexpected value.
 */
function seo_request_guard_reject(string $page, array $unexpected): void
{
    seo_request_guard_log_rejection($page, $unexpected);

    http_response_code(404);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    echo "Not found.\n";
    exit;
}

/**
 * Log sampled query rejections without allowing crawler floods to fill the log table.
 *
 * @param string $page Page number or page data.
 * @param array $unexpected Unexpected value.
 */
function seo_request_guard_log_rejection(string $page, array $unexpected): void
{
    if (!seo_request_guard_logging_enabled() || !function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }

    $today = gmdate('Y-m-d');
    $storedDay = (string) app_setting('seo_request_guard_log_day', '');
    $count = max(0, (int) app_setting('seo_request_guard_log_count', '0'));
    try {
        if ($storedDay !== $today) {
            set_app_setting('seo_request_guard_log_day', $today);
            set_app_setting('seo_request_guard_log_count', '0');
            $count = 0;
        }

        $limit = CMS_SEO_REQUEST_GUARD_LOG_LIMIT_PER_DAY;
        if ($count > $limit) {
            return;
        }

        set_app_setting('seo_request_guard_log_count', (string) ($count + 1));
    } catch (Throwable) {
        return;
    }
    if ($count === $limit) {
        admin_log_event('warning', 'seo.request_guard_log_limit_reached', 'SEO request guard reached its daily sampled log limit.', [
            'day' => $today,
            'limit' => $limit,
        ], ['category' => 'security', 'severity' => 'warning', 'route_name' => $page]);
        return;
    }

    admin_log_event('warning', 'seo.request_guard_rejected', 'Rejected suspicious public query string before rendering.', [
        'page' => $page,
        'unexpected_parameters' => $unexpected,
        'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 500),
        'remote_addr' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ], ['category' => 'security', 'severity' => 'warning', 'route_name' => $page]);
}

/**
 * Build a fallback canonical URL for public pages that do not already emit one.
 *
 * @param string $page Page number or page data.
 * @param ?array $currentGallery Current gallery value.
 * @return string Text result for the caller.
 */
function seo_request_guard_public_canonical_url(string $page, ?array $currentGallery = null): string
{
    if ($page === 'home') {
        return rtrim(public_base_url(), '/') . '/';
    }
    if ($page === 'gallery' && $currentGallery !== null) {
        return canonical_url_for_gallery($currentGallery);
    }
    if ($page === 'smart_gallery') {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        if ($slug !== '') {
            return absolute_public_url(url_for('smart_gallery', ['slug' => $slug]));
        }
    }
    if ($page === 'tag') {
        $slug = trim((string) ($_GET['slug'] ?? ''));
        if ($slug !== '') {
            return absolute_public_url(url_for('tag', ['slug' => $slug]));
        }
    }

    return '';
}

/**
 * Return canonical head HTML unless the page already supplied a canonical tag.
 *
 * @param string $page Page number or page data.
 * @param ?array $currentGallery Current gallery value.
 * @param string $existingHeadHtml Existing head html HTML markup.
 * @return string Text result for the caller.
 */
function seo_request_guard_canonical_head_html(string $page, ?array $currentGallery, string $existingHeadHtml): string
{
    if (stripos($existingHeadHtml, 'rel="canonical"') !== false || stripos($existingHeadHtml, "rel='canonical'") !== false) {
        return '';
    }

    $canonical = seo_request_guard_public_canonical_url($page, $currentGallery);
    if ($canonical === '') {
        return '';
    }

    return '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
}
