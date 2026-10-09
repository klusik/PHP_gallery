<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/public_visual_preview.php
 * Module Type: Preview Security Service
 * Purpose: Define the authenticated, read-only request boundary for the live visual CSS preview.
 * Responsibilities:
 *   - Classify preview requests against the approved public render and asset routes.
 *   - Keep preview route decisions independent from HTTP response rendering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return whether a query identifies the exact live visual-preview request mode.
 * @param array<string,mixed> $query Parsed request query values.
 * @return bool True only for the supported visual-preview marker.
 */
function public_visual_preview_is_active(array $query): bool
{
    return ($query['preview'] ?? null) === 'visual';
}

/**
 * Add a valid same-origin visual-preview referrer context to one resource query.
 * @param array<string,mixed> $query Normalized query already parsed for the requested resource.
 * @param string $method HTTP method of the resource request.
 * @param string $referer Browser Referer value supplied for the resource request.
 * @param string $requestOrigin Exact origin of the current request, including a non-default port when present.
 * @param string $mountPath Actual application mount path, empty for a root installation.
 * @return array{query:array<string,mixed>,inherited:bool,anonymous:bool} Query and whether trusted preview/audience context was inherited.
 */
function public_visual_preview_inherit_referrer_context(
    array $query,
    string $method,
    string $referer,
    string $requestOrigin,
    string $mountPath
): array {
    $unchanged = ['query' => $query, 'inherited' => false, 'anonymous' => false];
    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true) || $referer === '' || $requestOrigin === '') {
        return $unchanged;
    }

    $originParts = parse_url($requestOrigin);
    $refererParts = parse_url($referer);
    if (!is_array($originParts) || !is_array($refererParts)
        || !isset($originParts['scheme'], $originParts['host'], $refererParts['scheme'], $refererParts['host'])) {
        return $unchanged;
    }
    foreach (['user', 'pass', 'path', 'query', 'fragment'] as $forbiddenOriginPart) {
        if (array_key_exists($forbiddenOriginPart, $originParts)) {
            return $unchanged;
        }
    }
    foreach (['user', 'pass', 'fragment'] as $forbiddenRefererPart) {
        if (array_key_exists($forbiddenRefererPart, $refererParts)) {
            return $unchanged;
        }
    }

    $normalizeOrigin = static function (array $parts): ?array {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return [$scheme, $host, (int) ($parts['port'] ?? $defaultPort)];
    };
    $requestOriginTuple = $normalizeOrigin($originParts);
    $refererOriginTuple = $normalizeOrigin($refererParts);
    if ($requestOriginTuple === null || $requestOriginTuple !== $refererOriginTuple) {
        return $unchanged;
    }

    $mountPath = '/' . trim(str_replace('\\', '/', $mountPath), '/');
    if ($mountPath === '/') {
        $mountPath = '';
    }
    $refererPath = (string) ($refererParts['path'] ?? '');
    if ($refererPath === '' || $refererPath[0] !== '/' || ($mountPath !== ''
        && $refererPath !== $mountPath && !str_starts_with($refererPath, $mountPath . '/'))) {
        return $unchanged;
    }

    $previewValues = [];
    $audienceValues = [];
    foreach (explode('&', (string) ($refererParts['query'] ?? '')) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
        $name = urldecode($rawName);
        $value = urldecode($rawValue);
        if ($name === 'preview') {
            $previewValues[] = $value;
        } elseif ($name === 'view_as') {
            $audienceValues[] = $value;
        }
    }
    if (count($previewValues) !== 1 || $previewValues[0] !== 'visual'
        || count($audienceValues) > 1
        || ($audienceValues !== [] && $audienceValues[0] !== 'anonymous')) {
        return $unchanged;
    }

    $anonymous = $audienceValues === ['anonymous'];
    if (array_key_exists('preview', $query) && $query['preview'] !== 'visual') {
        return $unchanged;
    }
    $inheritedQuery = $query;
    $inheritedQuery['preview'] = 'visual';
    if ($anonymous) {
        // A stylesheet or CSS URL cannot upgrade an anonymous preview to the Admin audience.
        $inheritedQuery['view_as'] = 'anonymous';
    }
    return ['query' => $inheritedQuery, 'inherited' => true, 'anonymous' => $anonymous];
}

/**
 * Return the decision for a request carrying the visual-preview marker.
 * @param string $page Canonical route identifier resolved by the application router.
 * @param string $method HTTP request method supplied by the dispatch boundary.
 * @param bool $isAdmin Whether the current authenticated principal has the administrator role.
 * @param array<string,mixed> $query Parsed request query values used to reject unsafe asset variants and benchmark writes.
 * @return array{active:bool,allowed:bool,status:int,cache_control:string,reason:string} Preview classification, authorization result, response status, cache policy, and bounded reason code.
 */
function public_visual_preview_request_decision(string $page, string $method, bool $isAdmin, array $query): array
{
    $marker = $query['preview'] ?? null;
    if ($marker === null) {
        return [
            'active' => false,
            'allowed' => true,
            'status' => 200,
            'cache_control' => '',
            'reason' => 'not_preview',
        ];
    }

    $method = strtoupper($method);
    $base = [
        'active' => true,
        'allowed' => false,
        'status' => 404,
        'cache_control' => 'private, no-store, max-age=0',
        'reason' => 'route_not_allowed',
    ];
    if ($marker !== 'visual') {
        return $base;
    }
    if (!$isAdmin) {
        $base['reason'] = 'administrator_required';
        return $base;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $base['status'] = 405;
        $base['reason'] = 'read_only_method_required';
        return $base;
    }
    if (isset($query['benchmark_token']) || isset($query['benchmark_run']) || isset($query['benchmark'])) {
        $base['reason'] = 'benchmark_context_refused';
        return $base;
    }
    if ($page === 'theme_background_asset' && (string) ($query['variant'] ?? '') === 'original') {
        $base['reason'] = 'original_background_refused';
        return $base;
    }
    if ($page === 'media' && (string) ($query['ofp'] ?? '') === '1') {
        $base['reason'] = 'attachment_download_refused';
        return $base;
    }

    $allowedPages = [
        'home',
        'gallery',
        'theme_css',
        'theme_background_asset',
        'theme_branding_asset',
        'favicon_asset',
        'gallery_cover_asset',
        'gallery_branding_asset',
        'media',
        'public_media',
        'thumb',
        'public_thumb',
    ];
    if (!in_array($page, $allowedPages, true)) {
        return $base;
    }

    return [
        'active' => true,
        'allowed' => true,
        'status' => 200,
        'cache_control' => 'private, no-store, max-age=0',
        'reason' => 'allowed_read_only_route',
    ];
}
