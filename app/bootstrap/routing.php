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
    if ($segments === ['viewer', 'login']) {
        return ['page' => 'viewer_login', 'params' => []];
    }
    if ($segments === ['viewer', 'register']) {
        return ['page' => 'viewer_register', 'params' => []];
    }
    if ($segments === ['viewer', 'resend']) {
        return ['page' => 'viewer_resend_verification', 'params' => []];
    }
    if ($segments === ['viewer', 'first-login']) {
        return ['page' => 'viewer_first_login_password', 'params' => []];
    }
    if ($segments === ['viewer', 'logout']) {
        return ['page' => 'viewer_logout', 'params' => []];
    }
    if ($segments === ['viewer', 'account']) {
        return ['page' => 'viewer_account', 'params' => []];
    }
    if ($segments === ['viewer', 'account', 'reauth']) {
        return ['page' => 'viewer_account_reauth', 'params' => []];
    }
    if ($segments === ['viewer', 'account', 'password']) {
        return ['page' => 'viewer_account_password', 'params' => []];
    }
    if ($segments === ['viewer', 'account', 'email']) {
        return ['page' => 'viewer_account_email', 'params' => []];
    }
    if ($segments === ['viewer', 'account', 'delete']) {
        return ['page' => 'viewer_account_delete', 'params' => []];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'email-change' && ($segments[2] ?? '') === 'verify' && isset($segments[3]) && count($segments) === 4) {
        return ['page' => 'viewer_email_change_verify', 'params' => ['token' => rawurldecode($segments[3])]];
    }
    if ($segments === ['viewer', 'email-change', 'confirm']) {
        return ['page' => 'viewer_email_change_confirm', 'params' => []];
    }
    if ($segments === ['viewer', 'favourites']) {
        return ['page' => 'viewer_favourites', 'params' => []];
    }
    if ($segments === ['viewer', 'favourite']) {
        return ['page' => 'viewer_favourite', 'params' => []];
    }
    if ($segments === ['viewer', 'collections']) {
        return ['page' => 'viewer_collections', 'params' => []];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'collection' && ($segments[2] ?? '') === 'share' && isset($segments[3]) && count($segments) === 4) {
        return ['page' => 'viewer_collection_share_exchange', 'params' => ['token' => rawurldecode($segments[3])]];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'shared' && isset($segments[2]) && count($segments) === 3 && preg_match('/^[1-9][0-9]*$/D', (string) $segments[2]) === 1) {
        return ['page' => 'viewer_collection_shared', 'params' => ['collection_id' => (string) $segments[2]]];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'collections' && isset($segments[2]) && preg_match('/^[1-9][0-9]*$/D', (string) $segments[2]) === 1) {
        $collectionId = (string) $segments[2];
        if (count($segments) === 3) {
            return ['page' => 'viewer_collection', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 4 && $segments[3] === 'rename') {
            return ['page' => 'viewer_collection_rename', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 4 && $segments[3] === 'delete') {
            return ['page' => 'viewer_collection_delete', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 4 && $segments[3] === 'items') {
            return ['page' => 'viewer_collection_item_add', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 5 && $segments[3] === 'items' && $segments[4] === 'remove') {
            return ['page' => 'viewer_collection_item_remove', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 4 && $segments[3] === 'reorder') {
            return ['page' => 'viewer_collection_reorder', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 4 && $segments[3] === 'share') {
            return ['page' => 'viewer_collection_share_replace', 'params' => ['collection_id' => $collectionId]];
        }
        if (count($segments) === 5 && $segments[3] === 'share' && $segments[4] === 'revoke') {
            return ['page' => 'viewer_collection_share_revoke', 'params' => ['collection_id' => $collectionId]];
        }
    }
    if ($segments === ['viewer', 'forgot-password']) {
        return ['page' => 'viewer_forgot_password', 'params' => []];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'invite' && isset($segments[2]) && count($segments) === 3) {
        return ['page' => 'viewer_invite', 'params' => ['token' => rawurldecode($segments[2])]];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'verify' && isset($segments[2]) && count($segments) === 3) {
        return ['page' => 'viewer_verify', 'params' => ['token' => rawurldecode($segments[2])]];
    }
    if (($segments[0] ?? '') === 'viewer' && ($segments[1] ?? '') === 'reset' && isset($segments[2]) && count($segments) === 3) {
        return ['page' => 'viewer_reset_password', 'params' => ['token' => rawurldecode($segments[2])]];
    }
    if ($segments === ['viewer', 'verify']) {
        return ['page' => 'viewer_verify', 'params' => []];
    }
    if ($segments === ['viewer', 'reset']) {
        return ['page' => 'viewer_reset_password', 'params' => []];
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
    if ($segments[0] === 'smart' && isset($segments[1])) {
        $params = ['slug' => rawurldecode($segments[1])];
        if (isset($segments[2]) && preg_match('/^[0-9]+$/', $segments[2]) === 1) {
            $params['photo_page'] = max(1, (int) $segments[2]);
        }
        return ['page' => 'smart_gallery', 'params' => $params];
    }
    if ($segments[0] === 'admin') {
        return ['page' => 'admin', 'params' => []];
    }

    return ['page' => 'not_found', 'params' => []];
}
