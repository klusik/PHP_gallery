<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/routing.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns conversion of query-string and clean public request paths into page identifiers and route parameters.
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

namespace Gallery\Core;

use function Gallery\Services\find_gallery_by_public_path;
use function Gallery\Services\resolve_public_gallery_path;

/**
 * Convert either query-string routes or simple pretty URLs into a page name.
 *
 * Query-string routes remain compatible. Pretty URLs are a convenience layer
 * when Apache rewrite rules are available.
 *
 * @return array Structured result data for the caller.
 */
function cms_route_from_request(): array
{
    if (isset($_GET['page'])) {
        return ['page' => (string) $_GET['page'], 'params' => []];
    }

    // Variable $path stores this steps working value.
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
    // Variable $basePath stores this steps working value.
    $basePath = trim(request_script_base_path(), '/');
    if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
        // Variable $path stores this steps working value.
        $path = ltrim(substr($path, strlen($basePath)), '/');
    }
    // Variable $scriptDir stores this steps working value.
    $scriptDir = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir . '/')) {
        // Variable $path stores this steps working value.
        $path = substr($path, strlen($scriptDir) + 1);
    }
    if (str_starts_with($path, 'public/')) {
        // Variable $path stores this steps working value.
        $path = substr($path, 7);
    }
    // Variable $segments stores this steps working value.
    $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

    if ($segments === [] || $segments === ['index.php']) {
        return ['page' => 'home', 'params' => []];
    }
    if ($segments === ['robots.txt']) {
        return ['page' => 'robots', 'params' => []];
    }
    if ($segments === ['sitemap.xml']) {
        return ['page' => 'sitemap', 'params' => []];
    }
    if ($segments === ['favicon.ico'] || $segments === ['favicon.png']) {
        return ['page' => 'favicon_asset', 'params' => ['s' => '32']];
    }
    if ($segments === ['api', 'upload']) {
        return ['page' => 'upload_automation_upload', 'params' => []];
    }
    if (($segments[0] ?? '') === 'webdav' && isset($segments[1])) {
        return [
            'page' => 'mobile_webdav',
            'params' => [
                'token' => rawurldecode($segments[1]),
                'target_path' => rawurldecode(implode('/', array_slice($segments, 2))),
            ],
        ];
    }
    if ($segments[0] === 'galleries' && isset($segments[1]) && preg_match('/^[0-9]+$/', $segments[1]) === 1) {
        return ['page' => 'home', 'params' => ['gallery_page' => max(1, (int) $segments[1])]];
    }
    if ($segments[0] === 'gallery' && isset($segments[1])) {
        // $gallerySegments stores an intermediate value used by the surrounding gallery workflow.
        $gallerySegments = array_slice($segments, 1);
        // $lastSegment stores an intermediate value used by the surrounding gallery workflow.
        $lastSegment = end($gallerySegments);
        if (is_string($lastSegment) && preg_match('/^thumb-([0-9]+)\.(jpg|webp)$/', $lastSegment, $thumbnailMatch)) {
            array_pop($gallerySegments);
            return [
                'page' => 'public_thumb',
                'params' => [
                    'public_path' => rawurldecode(implode('/', $gallerySegments)),
                    'size' => (int) $thumbnailMatch[1],
                    'format' => $thumbnailMatch[2],
                ],
            ];
        }
        if ($lastSegment === 'media' || $lastSegment === 'original') {
            array_pop($gallerySegments);
            return [
                'page' => 'public_media',
                'params' => ['public_path' => rawurldecode(implode('/', $gallerySegments))],
            ];
        }
        if (count($gallerySegments) >= 3) {
            // $typedPageSegment stores an optional clean pagination kind, such as galleries/2.
            $typedPageSegment = $gallerySegments[count($gallerySegments) - 2] ?? '';
            // $typedPageNumber stores an optional clean pagination page number.
            $typedPageNumber = (string) ($gallerySegments[count($gallerySegments) - 1] ?? '');
            if ($typedPageSegment === 'galleries' && preg_match('/^[0-9]+$/', $typedPageNumber) === 1) {
                // $fullPath stores the complete path so real child galleries keep priority over pagination suffixes.
                $fullPath = rawurldecode(implode('/', $gallerySegments));
                // $galleryPath stores the gallery path before the typed pagination suffix.
                $galleryPath = rawurldecode(implode('/', array_slice($gallerySegments, 0, -2)));
                if ($galleryPath !== '' && !find_gallery_by_public_path($fullPath) && find_gallery_by_public_path($galleryPath)) {
                    return ['page' => 'gallery', 'params' => ['public_path' => $galleryPath, 'gallery_page' => max(1, (int) $typedPageNumber)]];
                }
            }
        }
        if (is_string($lastSegment) && preg_match('/^[0-9]+$/', $lastSegment) === 1) {
            // $fullPath stores the complete path so numeric image slugs or child galleries keep working.
            $fullPath = rawurldecode(implode('/', $gallerySegments));
            // $fullResolved stores any real image match so numeric image slugs keep working.
            $fullResolved = resolve_public_gallery_path($fullPath, false);
            if (!find_gallery_by_public_path($fullPath) && empty($fullResolved['image'])) {
                // $galleryPath stores the gallery path before the clean photo pagination suffix.
                $galleryPath = rawurldecode(implode('/', array_slice($gallerySegments, 0, -1)));
                if ($galleryPath !== '' && find_gallery_by_public_path($galleryPath)) {
                    return ['page' => 'gallery', 'params' => ['public_path' => $galleryPath, 'photo_page' => max(1, (int) $lastSegment)]];
                }
            }
        }
        return ['page' => 'gallery', 'params' => ['public_path' => rawurldecode(implode('/', $gallerySegments))]];
    }
    if ($segments[0] === 'share' && isset($segments[1])) {
        return ['page' => 'share', 'params' => ['token' => rawurldecode($segments[1])]];
    }
    if ($segments[0] === 'tag' && isset($segments[1])) {
        return ['page' => 'tag', 'params' => ['slug' => rawurldecode($segments[1])]];
    }
    if ($segments[0] === 'admin') {
        return ['page' => 'admin', 'params' => []];
    }

    return ['page' => 'not_found', 'params' => []];
}