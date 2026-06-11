<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/http_helpers.php
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

/**
 * HTTP controller helper model.
 *
 * This module contains small response helpers shared by public and admin controllers, such as conditional file headers and the public back-to-top control renderer.
 *
 * @param string $path Filesystem path.
 * @param string $cacheControl Cache control value.
 */
function send_conditional_file_headers(string $path, string $cacheControl): void
{
    // $mtime stores an intermediate value used by the surrounding gallery workflow.
    $mtime = (int) filemtime($path);
    // $size stores an intermediate value used by the surrounding gallery workflow.
    $size = (int) filesize($path);
    // $etag stores an intermediate value used by the surrounding gallery workflow.
    $etag = '"' . sha1($path . '|' . $mtime . '|' . $size) . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: ' . $cacheControl);

    // $clientEtag stores an intermediate value used by the surrounding gallery workflow.
    $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    // $clientModifiedSince stores an intermediate value used by the surrounding gallery workflow.
    $clientModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($clientEtag === $etag || ($clientModifiedSince !== '' && (int) strtotime($clientModifiedSince) >= $mtime)) {
        http_response_code(304);
        exit;
    }
}

/**
 * Handles render back to top button logic for the gallery application.
 */
function render_back_to_top_button(): void
{
    echo '<button type="button" class="back-to-top-button" data-back-to-top-button hidden aria-label="' . e(t('public.back_to_top_label', 'Go back to top')) . '" title="' . e(t('public.back_to_top_label', 'Go back to top')) . '"><span aria-hidden="true">↑</span><span>' . e(t('public.back_to_top_short', 'Top')) . '</span></button>';
}

/**
 * Handles cms not found logic for the gallery application.
 */
function cms_not_found(): void
{
    http_response_code(404);
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
    }
    render_header(t('public.not_found_title', 'Not found'));
    echo '<section class="panel"><h1>' . e(t('public.not_found_title', 'Not found')) . '</h1><p>' . e(t('public.not_found_message', 'The requested page was not found.')) . '</p></section>';
    render_footer();
}

