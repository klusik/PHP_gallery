<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/simbrief_ofp_attachments.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Resolve an imported SimBrief OFP PDF associated with a physical gallery.
 *
 * Responsibilities:
 *   - Trust the locally persisted OFP manifest, never a visitor-supplied path
 *   - Refuse symlinks, cross-gallery traversal and malformed PDF attachments
 *   - Keep PDF discoverability independent from editable gallery descriptions
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
 *   2026-10-07
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Determine whether a request may read the original gallery-owned OFP PDF.
 *
 * An authenticated administrator may inspect unpublished/private source
 * galleries. Every other visitor follows the exact direct-gallery visitor
 * access policy: public and unpublished (unlisted) galleries can be visited
 * by direct URL, including their original OFP attachments, but password,
 * share-token and NSFW requirements still apply. Private galleries without
 * a valid visitor grant remain inaccessible. A generated private OFP-page
 * child retains its own independent visibility restrictions.
 *
 * @param array<string,mixed> $gallery Physical gallery containing the PDF.
 * @param bool $isAdmin Whether the caller has verified administrator authority
 *                      outside anonymous-preview mode.
 * @return bool True only when PDF download and inline reading are authorized.
 */
function simbrief_ofp_visitor_access_allowed(array $gallery, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }
    // This is the same no-admin-bypass decision used by the public gallery.
    // An unpublished gallery is unlisted, not a private gallery: direct URLs
    // can access its PDF whenever the gallery itself is visitor-accessible.
    return visitor_can_access_gallery_without_admin_bypass($gallery);
}

/**
 * Resolve the valid locally stored original OFP PDF for one gallery.
 *
 * The manifest is a local attachment index. Never accept a file or URL supplied
 * by a caller. The safe legacy path is supported for OFPs imported before #103.
 *
 * @param array<string,mixed> $gallery Physical gallery row.
 * @return ?string Validated absolute PDF path or null when unavailable.
 */
function simbrief_ofp_local_pdf_path(array $gallery): ?string
{
    $relative = trim((string) ($gallery['folder_path'] ?? ''));
    if ($relative === '' || (int) ($gallery['id'] ?? 0) <= 0) {
        return null;
    }
    if (!function_exists('Gallery\\Services\\gallery_abs_path')
        || !function_exists('Gallery\\Services\\galleries_root')
        || !function_exists('Gallery\\Core\\path_inside')) {
        return null;
    }
    $root = realpath(gallery_abs_path($relative));
    $galleriesRoot = realpath(galleries_root());
    if ($root === false || $galleriesRoot === false || !\Gallery\Core\path_inside($galleriesRoot, $root)) {
        return null;
    }
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp-manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath) || (int) filesize($manifestPath) > 32768) {
        return null;
    }
    $json = @file_get_contents($manifestPath);
    $manifest = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($manifest)
        || ($manifest['format'] ?? '') !== 'php_gallery_simbrief_ofp_manifest_v1'
        || ($manifest['ofp_pdf_file'] ?? '') !== 'simbrief-ofp.pdf') {
        return null;
    }
    $candidate = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp.pdf';
    if (!is_file($candidate) || is_link($candidate)) {
        return null;
    }
    $path = realpath($candidate);
    $size = $path !== false ? @filesize($path) : false;
    if ($path === false || !\Gallery\Core\path_inside($root, $path)
        || $size === false || $size < 8 || $size > 26214400) {
        return null;
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }
    try {
        $magic = fread($handle, 5);
    } finally {
        fclose($handle);
    }
    return $magic === '%PDF-' ? $path : null;
}

/**
 * Check that a server-side PDF renderer is available without spawning commands.
 *
 * @return bool Whether this host can attempt optional OFP conversion.
 */
function simbrief_ofp_conversion_supported(): bool
{
    if (!class_exists(\Imagick::class)) {
        return false;
    }
    try {
        return in_array('PDF', \Imagick::queryFormats('PDF'), true)
            && in_array('JPEG', \Imagick::queryFormats('JPEG'), true);
    } catch (\Throwable) {
        return false;
    }
}

