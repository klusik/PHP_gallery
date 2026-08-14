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

namespace Gallery\Controllers;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\telemetry_request_id;

const PUBLIC_SCHEMA_UNAVAILABLE_EVENT = 'security.public_schema_inspection_unavailable';
const NSFW_GUARD_SCHEMA_UNAVAILABLE_EVENT = 'security.nsfw_schema_inspection_unavailable';

/**
 * Select the safe response representation for an NSFW schema failure.
 *
 * Route classification is centralized so binary media endpoints never receive
 * an HTML document and structured browser clients receive valid JSON.
 *
 * @param string $page Resolved route identifier.
 * @return string One of html, json, or text.
 */
function public_schema_unavailable_response_format(string $page): string
{
    if (in_array($page, ['gallery_lightbox_data', 'gallery_map_data', 'public_search'], true)) {
        return 'json';
    }
    if (in_array($page, ['media', 'thumb', 'public_media', 'public_thumb', 'thumbnail_warmup', 'gallery_cover_asset', 'gallery_branding_asset', 'download_gallery', 'sitemap', 'robots'], true)) {
        return 'text';
    }
    return 'html';
}

/**
 * Backward-compatible NSFW response-format wrapper retained for focused tests.
 *
 * @param string $page Resolved route identifier.
 * @return string One of html, json, or text.
 */
function nsfw_guard_unavailable_response_format(string $page): string
{
    return public_schema_unavailable_response_format($page);
}

/**
 * Record one bounded operational event for a protected public schema 503 response.
 *
 * Logging is best-effort because the same database incident may also make the
 * Admin log table unavailable. Context is restricted to stable application
 * identifiers and never includes SQL, credentials, tokens, session values, or
 * raw exception messages.
 *
 * @param string $feature Stable capability key.
 * @param string $page Resolved route identifier.
 * @param string $format Normalized response representation.
 * @param string $requestId Safe request correlation identifier.
 * @param string $schemaState Bounded missing/unknown state.
 * @param string $errorCode Stable bounded failure reason.
 */
function public_schema_log_unavailable(string $feature, string $page, string $format, string $requestId, string $schemaState = 'unknown', string $errorCode = 'inspection_failed'): void
{
    if (!function_exists('Gallery\Services\admin_log_event')) {
        return;
    }

    $feature = preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $feature) === 1 ? $feature : 'public_schema';
    $schemaState = in_array($schemaState, ['missing', 'unknown'], true) ? $schemaState : 'unknown';
    $errorCode = preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1 ? $errorCode : 'inspection_failed';
    try {
        admin_log_event(
            'error',
            $feature === 'nsfw_guard' ? NSFW_GUARD_SCHEMA_UNAVAILABLE_EVENT : PUBLIC_SCHEMA_UNAVAILABLE_EVENT,
            'Protected public request was refused because required schema policy could not be verified.',
            [
                'feature' => $feature,
                'schema_state' => $schemaState,
                'error_code' => $errorCode,
                'route' => substr($page, 0, 80),
                'response_format' => $format,
            ],
            [
                'category' => 'security',
                'severity' => 'error',
                'request_id' => $requestId,
                'route_name' => substr($page, 0, 80),
            ]
        );
    } catch (\Throwable) {
        // A secondary observability failure must never weaken the protective response.
    }
}

/**
 * Backward-compatible NSFW logging wrapper.
 *
 * @param string $page Resolved route identifier.
 * @param string $format Normalized response representation.
 * @param string $requestId Safe request correlation identifier.
 */
function nsfw_guard_log_schema_unavailable(string $page, string $format, string $requestId): void
{
    public_schema_log_unavailable('nsfw_guard', $page, $format, $requestId);
}

/**
 * Return a generic 503 response when protected public schema policy cannot be verified.
 *
 * The response intentionally avoids normal page layout rendering because that
 * layout can request additional gallery metadata and re-enter the same policy.
 * No SQL details, schema names, tokens, paths, or exception messages are sent
 * to the visitor.
 *
 * @param string $page Resolved route identifier.
 * @param string $feature Stable capability key for bounded diagnostics.
 * @param string $schemaState Bounded missing/unknown state.
 * @param string $errorCode Stable bounded failure reason.
 */
function cms_public_schema_unavailable(string $page, string $feature, string $schemaState = 'unknown', string $errorCode = 'inspection_failed'): void
{
    // $requestId stores the safe correlation identifier shown to the visitor.
    $requestId = function_exists('Gallery\Services\telemetry_request_id') ? telemetry_request_id() : '';
    // $message stores the translated public wording shared by every representation.
    $message = t('public.schema_temporarily_unavailable', 'The gallery is temporarily unavailable. Please try again later.');
    // $format stores the route-appropriate response representation.
    $format = public_schema_unavailable_response_format($page);

    public_schema_log_unavailable($feature, $page, $format, $requestId, $schemaState, $errorCode);

    http_response_code(503);
    clear_response_cache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('Retry-After: 60');
    header('X-Robots-Tag: noindex, nofollow');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'service_unavailable',
            'message' => $message,
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($format === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        if ($requestId !== '') {
            echo "\n" . t('public.request_reference', 'Reference: {request_id}', ['request_id' => $requestId]);
        }
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e(t('public.service_unavailable_title', 'Temporarily unavailable')) . '</title></head><body><main><h1>' . e(t('public.service_unavailable_title', 'Temporarily unavailable')) . '</h1><p>' . e($message) . '</p>';
    if ($requestId !== '') {
        echo '<p>' . e(t('public.request_reference', 'Reference: {request_id}', ['request_id' => $requestId])) . '</p>';
    }
    echo '</main></body></html>';
}

/**
 * Backward-compatible NSFW-specific response wrapper.
 *
 * @param string $page Resolved route identifier.
 */
function cms_nsfw_guard_schema_unavailable(string $page): void
{
    cms_public_schema_unavailable($page, 'nsfw_guard');
}

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
    clear_response_cache_headers();
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
 * Remove inherited PHP/session cache headers before an asset route sets its own policy.
 */
function clear_response_cache_headers(): void
{
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
}

/**
 * Send a cache policy after removing inherited PHP/session cache headers.
 *
 * @param string $cacheControl Cache control value.
 */
function send_asset_cache_control(string $cacheControl): void
{
    clear_response_cache_headers();
    header('Cache-Control: ' . $cacheControl);
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

