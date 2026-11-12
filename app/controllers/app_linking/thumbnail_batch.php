<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/thumbnail_batch.php
 * Module Type: Controller part
 * Purpose: Serve an authorized bounded native thumbnail batch.
 * Responsibilities: Admit exact requests, recheck live authority and frame bytes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

require_once __DIR__ . '/../../services/app_linking_thumbnail_batch.php';

use Throwable;
use const Gallery\Services\APP_LINKING_BATCH_LIMITS;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_batch_admit;
use function Gallery\Services\app_linking_batch_frames;
use function Gallery\Services\app_linking_batch_gallery;
use function Gallery\Services\app_linking_media_photo_admitted;
use function Gallery\Services\app_linking_passive_authorized;
use function Gallery\Services\app_linking_passive_begin;
use function Gallery\Services\app_linking_scope_allows_admin_media;
use function Gallery\Services\app_linking_thumbnail_batch_enabled;
use function Gallery\Services\app_linking_scope_allowed;
use const Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID;
use const Gallery\Services\APP_LINKING_GALLERIES_SCOPE;
use const Gallery\Services\APP_LINKING_MEDIA_SUFFIX;

/**
 * Parse the deliberately canonical JSON representation without duplicate keys.
 *
 * @param string $raw Complete bounded UTF-8 request body.
 * @return array{gallery_id:int,image_ids:list<string>}|null Valid request or refusal.
 */
function app_linking_batch_parse(string $raw): ?array
{
    // The v1 server accepts the documented compact field order only. This
    // structural grammar rejects duplicate keys without lossy JSON decoding.
    if (preg_match('/\A\{"v":1,"gallery_id":"([1-9][0-9]{0,18})",'
        . '"image_ids":\[("[1-9][0-9]{0,18}"(?:,"[1-9][0-9]{0,18}")*)\]\}\z/D',
        $raw, $parts) !== 1 || (string) (int) $parts[1] !== $parts[1]) {
        return null;
    }
    $ids = explode(',', str_replace('"', '', $parts[2]));
    if (count($ids) > APP_LINKING_BATCH_LIMITS['count']
        || count(array_unique($ids)) !== count($ids)) {
        return null;
    }
    foreach ($ids as $id) {
        if ((string) (int) $id !== $id) {
            return null;
        }
    }
    return ['gallery_id' => (int) $parts[1], 'image_ids' => $ids];
}

/**
 * Serve the exact read-only thumbnail batch transport.
 *
 * @return void Emits an opaque error or a bounded framed response.
 */
function cms_app_linking_thumbnail_batch(): void
{
    $started = hrtime(true);
    $outputStarted = false;
    try {
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if (!app_linking_fixed_query('app_linking_thumbnail_batch')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_galleries_ip', 'app_link_galleries_global', true)) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        if (!app_linking_thumbnail_batch_enabled()) {
            app_linking_json_error(404, 'unsupported_capability');
            return;
        }
        if (strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? ''))) !== 'application/json') {
            app_linking_json_error(415, 'unsupported_media_type');
            return;
        }
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) || isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, APP_LINKING_BATCH_LIMITS['request'] + 1);
        if (!is_string($raw) || strlen($raw) > APP_LINKING_BATCH_LIMITS['request']) {
            app_linking_json_error(413, 'invalid_request');
            return;
        }
        $request = app_linking_batch_parse($raw);
        if ($request === null) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (!app_linking_scope_allowed(
            APP_LINKING_WINAPP_CLIENT_ID, APP_LINKING_GALLERIES_SCOPE . APP_LINKING_MEDIA_SUFFIX,
        )) {
            app_linking_json_error(404, 'unsupported_capability');
            return;
        }
        app_linking_passive_begin('thumbnail_batch');
        $issuer = app_linking_configured_issuer();
        $token = app_linking_bearer();
        $identity = $issuer === null || $token === null ? null : app_linking_authenticate($token, $issuer);
        if ($identity === null) {
            header('WWW-Authenticate: Bearer');
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        if (!app_linking_scope_allows_admin_media($identity['scope'])
            && !str_contains($identity['scope'], ' media:read')) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }
        $admission = app_linking_batch_admit($identity['user_id'], count($request['image_ids']));
        if (!$admission['allowed']) {
            if ($admission['retry_after_seconds'] > 0) {
                header('Retry-After: ' . $admission['retry_after_seconds']);
            }
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $admin = app_linking_scope_allows_admin_media($identity['scope']);
        $preload = $_SERVER['HTTP_X_PHP_GALLERY_PRELOAD'] ?? null;
        if ($preload !== null && ($preload !== 'admin-v1' || !$admin)) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $gallery = app_linking_batch_gallery($identity, $token, $issuer, $request['gallery_id']);
        if ($gallery === null) {
            app_linking_json_error(404, 'target_unavailable');
            return;
        }
        foreach ($request['image_ids'] as $id) {
            if (!app_linking_media_photo_admitted($gallery, (int) $id, $admin)) {
                app_linking_json_error(404, 'target_unavailable');
                return;
            }
        }
        app_linking_passive_authorized();
        clear_response_cache_headers();
        app_linking_security_headers();
        header('Cache-Control: private, no-store');
        header('Vary: Authorization, X-PHP-Gallery-Preload');
        header('Content-Type: application/vnd.php-gallery.thumbnail-batch.v1');
        // Header metrics cover authorization and preflight only, not streaming.
        app_linking_passive_emit_header();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        foreach (app_linking_batch_frames($identity, $token, $issuer, $request, $admin, $preload, $started) as $chunk) {
            if ($chunk === '') {
                // Ask the SAPI to forward the item without removing host buffers.
                flush();
                continue;
            }
            $outputStarted = true;
            echo $chunk;
        }
    } catch (Throwable) {
        if (!$outputStarted && !headers_sent()) {
            app_linking_json_error(503, 'temporarily_unavailable');
        }
    }
}
