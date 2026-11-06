<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/media.php
 * Module Type: Controller part
 * Purpose: Serve scoped native photo and presentation reads over HTTPS.
 * Responsibilities: Validate canonical selectors, apply live authority, and
 *   emit bounded JSON or existing media without disclosing storage paths.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

require_once __DIR__ . '/../../services/app_linking_media.php';

use Throwable;
use function Gallery\Core\cms_runtime_kernel;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_media_gallery;
use function Gallery\Services\app_linking_media_open_photo;
use function Gallery\Services\app_linking_media_page;
use function Gallery\Services\app_linking_media_presentation;
use function Gallery\Services\app_linking_media_track;

/**
 * Parse one bounded native media selector without query ambiguity.
 *
 * @param string $query Raw canonical query supplied to the front controller.
 * @return array{gallery_id:int,kind:string,cursor:int,image_id:int,track_id:string}|null
 *     Exact target and request variant, or null for an unexpected query.
 */
function app_linking_media_query(string $query): ?array
{
    if (preg_match(
        '/\Apage=app_linking_media&gallery_id=([1-9][0-9]{0,18})'
            . '&kind=(photos|presentation|thumbnail|photo|track)'
            . '(?:&(after|image_id|track_id)=([a-f0-9]{1,32}))?\z/D',
        $query, $parts,
    ) !== 1 || (string) (int) $parts[1] !== $parts[1]) {
        return null;
    }
    $kind = $parts[2];
    $field = $parts[3] ?? '';
    $value = $parts[4] ?? '';
    $isId = preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) === 1
        && (string) (int) $value === $value;
    $valid = match ($kind) {
        'photos' => $field === '' || ($field === 'after' && $isId),
        'presentation' => $field === '',
        'thumbnail', 'photo' => $field === 'image_id' && $isId,
        'track' => $field === 'track_id' && strlen($value) === 32,
    };
    return $valid ? ['gallery_id' => (int) $parts[1], 'kind' => $kind,
        'cursor' => $field === 'after' ? (int) $value : 0,
        'image_id' => $field === 'image_id' ? (int) $value : 0,
        'track_id' => $field === 'track_id' ? $value : ''] : null;
}

/**
 * Emit one authorized media validator after the current access checks.
 *
 * @param string $etag Strong quoted hash for these response bytes.
 * @return bool True after sending a conditional response without a body.
 */
function app_linking_media_not_modified(string $etag): bool
{
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') !== $etag) {
        return false;
    }
    http_response_code(304);
    return true;
}

/**
 * Send bounded native media after checking the live bearer and public policy.
 *
 * @return void Emits JSON, authorized bytes, a validator, or opaque refusal.
 */
function cms_app_linking_media(): void
{
    $method = request_method();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        app_linking_json_error(405, 'invalid_request');
        return;
    }
    $photo = null;
    try {
        $issuer = app_linking_configured_issuer();
        $query = app_linking_media_query((string) ($_SERVER['QUERY_STRING'] ?? ''));
        if ($issuer === null || $query === null) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_galleries_ip', 'app_link_galleries_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $token = app_linking_bearer();
        $kind = $query['kind'];
        $presentation = in_array($kind, ['presentation', 'track'], true);
        $gallery = $token === null ? null : app_linking_media_gallery(
            $token, $issuer, $query['gallery_id'], $presentation,
        );
        if ($gallery === null) {
            $authenticated = $token !== null && app_linking_authenticate($token, $issuer) !== null;
            if (!$authenticated) {
                header('WWW-Authenticate: Bearer');
            }
            app_linking_json_error($authenticated ? 404 : 401,
                $authenticated ? 'target_unavailable' : 'invalid_token');
            return;
        }
        if ($presentation) {
            // Only a scoped, currently public gallery needs the music owner.
            cms_runtime_kernel()->load('gallery-pdf-documents');
        }
        clear_response_cache_headers();
        app_linking_security_headers();
        if ($kind === 'photos' || $kind === 'presentation') {
            $payload = $kind === 'photos' ? app_linking_media_page($gallery, $query['cursor'])
                : app_linking_media_presentation($gallery);
            header('Content-Type: application/json; charset=utf-8');
            if ($method === 'GET') {
                echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            return;
        }
        if ($kind === 'track') {
            $track = app_linking_media_track($gallery, $query['track_id']);
            if ($track === null) {
                app_linking_json_error(404, 'target_unavailable');
                return;
            }
            if (!app_linking_media_not_modified('"' . $track['track']['sha256'] . '"')) {
                gallery_music_stream_document($track);
            }
            return;
        }
        $photo = app_linking_media_open_photo($gallery, $query['image_id'], $kind === 'thumbnail');
        if ($photo === null) {
            app_linking_json_error(404, 'target_unavailable');
            return;
        }
        if (!app_linking_media_not_modified($photo['etag'])) {
            header('Content-Type: ' . $photo['mime']);
            header('Content-Length: ' . $photo['size']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ($method === 'GET') {
                fpassthru($photo['stream']);
            }
        }
    } catch (Throwable) {
        if (!headers_sent()) {
            app_linking_json_error(503, 'temporarily_unavailable');
        }
    } finally {
        if ($photo !== null) {
            fclose($photo['stream']);
        }
    }
}
