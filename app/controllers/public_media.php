<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_media.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

namespace Gallery\Controllers;

use finfo;
use InvalidArgumentException;
use function Gallery\Core\current_user;
use function Gallery\Core\output_sitemap_xml;
use function Gallery\Services\current_user_is_known_under_18;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_abs_path;
use function Gallery\Services\gallery_access_requirement;
use function Gallery\Services\gallery_branding_asset_abs_path;
use function Gallery\Services\gallery_branding_asset_kind;
use function Gallery\Services\gallery_branding_mime_extension;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_cover_path;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\image_create_from_path;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\image_public_display_file;
use function Gallery\Services\public_image_visible_to_current_visitor;
use function Gallery\Services\public_media_needs_private_cache;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\thumbnail_ensure_image_thumbnail_variant_file;
use function Gallery\Services\thumbnail_response_file_geometry_status;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\visitor_can_access_nsfw_content;

/**
 * Public media controller model.
 * 
 * This module streams thumbnails, media files, cover assets, robots.txt, and sitemap XML. Theme CSS, theme background assets, and favicon assets intentionally remain in the legacy controller file for now.
 */

/**
 * Return generated thumbnail geometry status for response headers and cache policy.
 *
 * Invalid geometry is handled by the response resolver before streaming.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $path Filesystem path.
 * @return array<string mixed>.
 */
function cms_thumbnail_file_geometry_status_for_response(array $image, array $gallery, int $size, string $path): array
{
    if (function_exists('Gallery\\Services\\thumbnail_response_file_geometry_status')) {
        return thumbnail_response_file_geometry_status($image, $gallery, $size, $path);
    }

    return ['valid' => true, 'reason' => 'geometry_validation_unavailable'];
}

/**
 * Return true when a generated thumbnail has valid geometry.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function cms_thumbnail_file_has_valid_geometry(array $image, array $gallery, int $size, string $path): bool
{
    // $status stores the reusable geometry decision for callers that still need a boolean.
    $status = cms_thumbnail_file_geometry_status_for_response($image, $gallery, $size, $path);
    return !empty($status['valid']);
}


/**
 * Resolve a thumbnail response file and repair invalid geometry before streaming.
 *
 * Public thumbnail URLs may touch the filesystem because the browser is asking
 * for a specific derivative. Invalid aspect-ratio variants are deleted and are
 * never streamed back to the client.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $size Size value.
 * @param string $format Format value.
 * @return array{path:string,geometry_status:array<string,mixed>}|null Structured result data for the caller.
 */
function cms_resolve_thumbnail_response_file(array $image, array $gallery, int $size, string $format): ?array
{
    if (function_exists('Gallery\\Services\\thumbnail_ensure_image_thumbnail_variant_file')) {
        return thumbnail_ensure_image_thumbnail_variant_file($image, $gallery, $size, $format);
    }

    return null;
}

/**
 * Handle cms thumb.
 *
 * Used by HTTP controller routing for this workflow.
 */
function cms_thumb(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    // Variable $size stores this steps working value.
    $size = (int) ($_GET['size'] ?? 0);
    // Variable $format stores this steps working value.
    $format = (string) ($_GET['format'] ?? 'jpg');
    if (!$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (!current_user() && !public_image_visible_to_current_visitor($image, $gallery)) || (current_user_is_known_under_18() && image_nsfw_restricted($image, $gallery))) {
        cms_not_found();
        return;
    }
    // $responseFile stores the valid derivative selected for this response.
    $responseFile = cms_resolve_thumbnail_response_file($image, $gallery, $size, $format);
    if ($responseFile === null) {
        cms_not_found();
        return;
    }
    // $path stores this steps working value.
    $path = $responseFile['path'];
    // $geometryStatus stores whether this response is a clean derivative.
    $geometryStatus = $responseFile['geometry_status'];
    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('X-Gallery-Thumbnail-Geometry: ' . (!empty($geometryStatus['valid']) ? 'valid' : 'invalid'));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    // $cacheControl stores an intermediate value used by the surrounding gallery workflow.
    $cacheControl = !empty($geometryStatus['valid'])
        ? (public_media_needs_private_cache($gallery, $image) ? 'private, max-age=300' : 'public, max-age=31536000, immutable')
        : 'private, no-cache, max-age=0, must-revalidate';
    send_conditional_file_headers($path, $cacheControl);
    // $bytes stores the response body size sent to the browser.
    $bytes = (int) filesize($path);
    header('Content-Length: ' . $bytes);
    readfile($path);
}

/**
 * Handles cms public thumb logic for the gallery application.
 */
function cms_public_thumb(): void
{
    // $resolved stores an intermediate value used by the surrounding gallery workflow.
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = $resolved['gallery'];
    // $image stores an intermediate value used by the surrounding gallery workflow.
    $image = $resolved['image'];
    // $size stores an intermediate value used by the surrounding gallery workflow.
    $size = (int) ($_GET['size'] ?? 0);
    // $format stores an intermediate value used by the surrounding gallery workflow.
    $format = (string) ($_GET['format'] ?? 'jpg');

    if (!$gallery || !$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    if ((!current_user() && !public_image_visible_to_current_visitor($image, $gallery)) || (current_user_is_known_under_18() && image_nsfw_restricted($image, $gallery))) {
        cms_not_found();
        return;
    }

    // $responseFile stores the valid derivative selected for this response.
    $responseFile = cms_resolve_thumbnail_response_file($image, $gallery, $size, $format);
    if ($responseFile === null) {
        cms_not_found();
        return;
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = $responseFile['path'];

    // $geometryStatus stores whether this response is a clean derivative.
    $geometryStatus = $responseFile['geometry_status'];
    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('X-Gallery-Thumbnail-Geometry: ' . (!empty($geometryStatus['valid']) ? 'valid' : 'invalid'));
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    // $cacheControl stores an intermediate value used by the surrounding gallery workflow.
    $cacheControl = !empty($geometryStatus['valid'])
        ? (public_media_needs_private_cache($gallery, $image) ? 'private, max-age=300' : 'public, max-age=31536000, immutable')
        : 'private, no-cache, max-age=0, must-revalidate';
    send_conditional_file_headers($path, $cacheControl);
    // $bytes stores the response body size sent to the browser.
    $bytes = (int) filesize($path);
    header('Content-Length: ' . $bytes);
    readfile($path);
}

/**
 * Handles cms public media logic for the gallery application.
 */
function cms_public_media(): void
{
    // $resolved stores an intermediate value used by the surrounding gallery workflow.
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = $resolved['gallery'];
    // $image stores an intermediate value used by the surrounding gallery workflow.
    $image = $resolved['image'];

    if (!$gallery || !$image) {
        cms_not_found();
        return;
    }
    if ((!current_user() && !public_image_visible_to_current_visitor($image, $gallery)) || (current_user_is_known_under_18() && image_nsfw_restricted($image, $gallery))) {
        cms_not_found();
        return;
    }

    // $displayFile stores the browser-displayable file selected for public viewing.
    $displayFile = image_public_display_file($image, $gallery, true);
    if ($displayFile === null) {
        cms_not_found();
        return;
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = (string) $displayFile['path'];
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = (string) $displayFile['mime'];

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $displayFile['filename']) . '"');
    // $cacheControl stores an intermediate value used by the surrounding gallery workflow.
    $cacheControl = public_media_needs_private_cache($gallery, $image) ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    // $bytes stores the response body size counted for anonymous media telemetry.
    $bytes = (int) filesize($path);
    if (function_exists('telemetry_record_media_served_event')) {
        \telemetry_record_media_served_event($image, $gallery, 'media.image.served', $bytes, (string) $displayFile['variant'], 'miss');
    }
    header('Content-Length: ' . $bytes);
    readfile($path);
}

/**
 * Handles cms gallery cover asset logic for the gallery application.
 */
function cms_gallery_cover_asset(): void
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    if (!current_user() && !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    if ((!current_user() || current_user_is_known_under_18()) && gallery_nsfw_requirement($gallery) !== null && !visitor_can_access_nsfw_content()) {
        cms_not_found();
        return;
    }
    // $coverPath stores an intermediate value used by the surrounding gallery workflow.
    $coverPath = gallery_cover_path($gallery);
    if ($coverPath === null) {
        cms_not_found();
        return;
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $coverPath);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    // $finfo stores an intermediate value used by the surrounding gallery workflow.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    if (extension_loaded('gd')) {
        // $info stores an intermediate value used by the surrounding gallery workflow.
        $info = @getimagesize($path);
        // $source stores an intermediate value used by the surrounding gallery workflow.
        $source = $info !== false ? image_create_from_path($path, (string) ($info['mime'] ?? $mime)) : false;
        if ($info !== false && $source && ((int) $info[0] > 800 || (int) $info[1] > 800)) {
            // $scale stores an intermediate value used by the surrounding gallery workflow.
            $scale = min(1.0, 800 / max((int) $info[0], (int) $info[1]));
            // $targetWidth stores an intermediate value used by the surrounding gallery workflow.
            $targetWidth = max(1, (int) round((int) $info[0] * $scale));
            // $targetHeight stores an intermediate value used by the surrounding gallery workflow.
            $targetHeight = max(1, (int) round((int) $info[1] * $scale));
            // $target stores an intermediate value used by the surrounding gallery workflow.
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            // $white stores an intermediate value used by the surrounding gallery workflow.
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, (int) $info[0], (int) $info[1]);
            imageinterlace($target, true);
            header('Content-Type: image/jpeg');
            header('X-Content-Type-Options: nosniff');
            send_asset_cache_control(public_media_needs_private_cache($gallery) ? 'private, max-age=300' : 'public, max-age=86400');
            imagejpeg($target, null, 82);
            imagedestroy($target);
            imagedestroy($source);
            return;
        }
        if ($source) {
            imagedestroy($source);
        }
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    send_asset_cache_control(public_media_needs_private_cache($gallery) ? 'private, max-age=300' : 'public, max-age=86400');
    readfile($path);
}


/**
 * Stream one stored gallery branding asset.
 */
function cms_gallery_branding_asset(): void
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || !gallery_branding_schema_ready()) {
        cms_not_found();
        return;
    }
    try {
        // $kind stores an intermediate value used by the surrounding gallery workflow.
        $kind = gallery_branding_asset_kind((string) ($_GET['kind'] ?? ''));
    } catch (InvalidArgumentException) {
        cms_not_found();
        return;
    }
    if (!current_user() && !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    if ((!current_user() || current_user_is_known_under_18()) && gallery_nsfw_requirement($gallery) !== null && !visitor_can_access_nsfw_content()) {
        cms_not_found();
        return;
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = gallery_branding_asset_abs_path($gallery, $kind);
    if ($path === null) {
        cms_not_found();
        return;
    }
    // $finfo stores an intermediate value used by the surrounding gallery workflow.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (gallery_branding_mime_extension($mime) === null) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    // $cacheControl stores a conservative policy for protected or restricted galleries.
    $cacheControl = (gallery_access_requirement($gallery) || gallery_nsfw_requirement($gallery)) && (!current_user() || current_user_is_known_under_18()) ? 'private, max-age=300' : 'public, max-age=86400';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
}


/**
 * Handles cms media logic for the gallery application.
 */
function cms_media(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (!current_user() && !public_image_visible_to_current_visitor($image, $gallery)) || (current_user_is_known_under_18() && image_nsfw_restricted($image, $gallery))) {
        cms_not_found();
        return;
    }
    // Variable $displayFile stores the browser-displayable file selected for public viewing.
    $displayFile = image_public_display_file($image, $gallery, true);
    if ($displayFile === null) {
        cms_not_found();
        return;
    }
    // Variable $path stores this steps working value.
    $path = (string) $displayFile['path'];
    // Variable $mime stores this steps working value.
    $mime = (string) $displayFile['mime'];
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $displayFile['filename']) . '"');
    // $cacheControl stores an intermediate value used by the surrounding gallery workflow.
    $cacheControl = public_media_needs_private_cache($gallery, $image) ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    // $bytes stores the response body size counted for anonymous media telemetry.
    $bytes = (int) filesize($path);
    if (function_exists('telemetry_record_media_served_event')) {
        \telemetry_record_media_served_event($image, $gallery, 'media.image.served', $bytes, (string) $displayFile['variant'], 'miss');
    }
    header('Content-Length: ' . $bytes);
    readfile($path);
}

/**
 * Handles cms robots txt logic for the gallery application.
 */
function cms_robots_txt(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /index.php?page=admin\n";
    echo "Disallow: /index.php?page=admin_\n";
    echo "Disallow: /admin/\n";
    foreach (['l', 'mod', 'uri', 'name'] as $spamParameter) {
        echo "Disallow: /*?" . $spamParameter . "=\n";
        echo "Disallow: /*&" . $spamParameter . "=\n";
    }
    echo "Sitemap: " . public_base_url() . "/sitemap.xml\n";
}

/**
 * Handles cms sitemap xml logic for the gallery application.
 */
function cms_sitemap_xml(): void
{
    output_sitemap_xml();
}
