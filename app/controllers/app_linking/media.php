<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/media.php
 * Module Type: Controller part
 * Purpose: Serve scoped native media on a verified installation transport.
 * Responsibilities: Validate canonical selectors, apply live authority, and
 *   emit bounded JSON or existing media without disclosing storage paths.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

require_once __DIR__ . '/../../services/app_linking_media.php';

use Throwable;
use const Gallery\Services\APP_LINKING_MEDIA_CATALOGUE_SIZE;
use function Gallery\Core\cms_runtime_kernel;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_access_token_hash;
use function Gallery\Services\app_linking_admin_media_change_photo_visibility;
use function Gallery\Services\app_linking_admin_media_delete_photos;
use function Gallery\Services\app_linking_admin_media_gallery;
use function Gallery\Services\app_linking_admin_media_gallery_info;
use function Gallery\Services\app_linking_admin_media_revision;
use function Gallery\Services\app_linking_admin_media_info;
use function Gallery\Services\app_linking_admin_media_photo_info;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_media_gallery;
use function Gallery\Services\app_linking_media_open_photo;
use function Gallery\Services\app_linking_media_page;
use function Gallery\Services\app_linking_media_presentation;
use function Gallery\Services\app_linking_media_track;
use function Gallery\Services\app_linking_scope_allows_admin_media;
use function Gallery\Services\app_linking_visibility_scope_allowed;
use function Gallery\Services\gallery_public_export_policy;
use function Gallery\Services\gallery_allows_gps_maps;

/**
 * Bound reuse of an already-authorized public photo on one private client.
 * Type: int. Units: seconds. Scope: opt-in native photo and thumbnail reads.
 * Consumers: cms_app_linking_media and the WinApp media cache.
 * Rationale: 60 seconds covers adjacent navigation while bounding the delay
 *     before the client must recheck the live grant and current visibility.
 */
const APP_LINKING_PHOTO_CACHE_FRESH_SECONDS = 60;

/**
 * Bound one native permanent-deletion request.
 * Type: int. Units: photo IDs per request. Scope: one gallery mutation.
 * Consumers: app_linking_media_photo_delete. Rationale: 64 bounds validation,
 *     filesystem work, and an ambiguous-response recovery decision.
 */
const APP_LINKING_MEDIA_DELETE_BATCH_MAX = 64;

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
            . '&kind=(photos|presentation|thumbnail|photo|track|info|gallery_info|photo_info|revision)'
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
        'presentation', 'info', 'gallery_info', 'revision' => $field === '',
        'photo_info' => $field === 'image_id' && $isId,
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
 * Send bounded native media after checking the live bearer and scoped policy.
 *
 * The fixed POST query is reserved for photo visibility writes. Read
 * selectors retain their exact GET/HEAD syntax and separate binary admission.
 * Unauthenticated reads never reach export policy or the filesystem.
 *
 * @return void Emits JSON, authorized bytes, a validator, or opaque refusal.
 */
function cms_app_linking_media(): void
{
    $method = request_method();
    $fixedQuery = app_linking_fixed_query('app_linking_media');
    if ($method === 'POST' && $fixedQuery) {
        app_linking_media_photo_mutation();
        return;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header($fixedQuery ? 'Allow: POST' : 'Allow: GET, HEAD');
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
        $token = app_linking_bearer();
        $kind = $query['kind'];
        // Bound credential lookups before trusting the bearer. Only canonical
        // access tokens can enter the separate photo and thumbnail budget.
        $photoRequest = in_array($kind, ['photo', 'thumbnail'], true)
            && $token !== null && app_linking_access_token_hash($token) !== null;
        if (!app_linking_admit_public(
            $photoRequest ? 'app_link_photo_ip' : 'app_link_galleries_ip',
            $photoRequest ? 'app_link_photo_global' : 'app_link_galleries_global',
            true,
        )) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $identity = $token === null ? null : app_linking_authenticate($token, $issuer);
        if ($identity === null) {
            if ($photoRequest && !app_linking_admit_public(
                'app_link_galleries_ip', 'app_link_galleries_global', true,
            )) {
                app_linking_json_error(429, 'temporarily_unavailable');
                return;
            }
            header('WWW-Authenticate: Bearer');
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        $presentation = in_array($kind, ['presentation', 'track'], true);
        $adminMedia = !$presentation && app_linking_scope_allows_admin_media($identity['scope']);
        if (in_array($kind, ['info', 'gallery_info', 'photo_info', 'revision'], true) && !$adminMedia) {
            app_linking_json_error(404, 'target_unavailable');
            return;
        }
        $gallery = $adminMedia
            ? app_linking_admin_media_gallery($identity, $token, $issuer, $query['gallery_id'])
            : app_linking_media_gallery($identity['scope'], $query['gallery_id'], $presentation);
        if ($gallery === null) {
            app_linking_json_error(404, 'target_unavailable');
            return;
        }
        if ($presentation) {
            // Only a scoped, currently public gallery needs the music owner.
            cms_runtime_kernel()->load('gallery-pdf-documents');
        }
        clear_response_cache_headers();
        app_linking_security_headers();
        $publicGallery = $adminMedia && !in_array($kind, ['info', 'gallery_info', 'photo_info', 'revision'], true)
            && gallery_public_export_policy($query['gallery_id'])['allowed'];
        if (in_array($kind, ['photos', 'presentation', 'info', 'gallery_info', 'photo_info', 'revision'], true)) {
            $payload = match ($kind) {
                'photos' => app_linking_media_page(
                    $gallery, $query['cursor'],
                    ($_SERVER['HTTP_X_PHP_GALLERY_PHOTO_PAGE'] ?? '')
                        === (string) APP_LINKING_MEDIA_CATALOGUE_SIZE,
                    $adminMedia, $publicGallery,
                ),
                'revision' => app_linking_admin_media_revision($query['gallery_id']),
                'info' => app_linking_admin_media_info($query['gallery_id']),
                'gallery_info' => app_linking_admin_media_gallery_info($gallery),
                'photo_info' => app_linking_admin_media_photo_info(
                    $query['gallery_id'], $query['image_id'],
                    gallery_allows_gps_maps($gallery),
                ),
                'presentation' => app_linking_media_presentation($gallery),
            };
            if ($payload === null) {
                app_linking_json_error(404, 'target_unavailable');
                return;
            }
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
                gallery_music_stream_document(
                    $track,
                    nativeCache: ($_SERVER['HTTP_X_PHP_GALLERY_TRACK_CACHE'] ?? '') === '1',
                );
            }
            return;
        }
        $photo = app_linking_media_open_photo(
            $gallery, $query['image_id'], $kind === 'thumbnail', $adminMedia, $publicGallery,
        );
        if ($photo === null) {
            app_linking_json_error(404, 'target_unavailable');
            return;
        }
        header('Vary: Authorization, X-PHP-Gallery-Photo-Cache', false);
        if ($photo['etag'] !== null
            && ($_SERVER['HTTP_X_PHP_GALLERY_PHOTO_CACHE'] ?? '') === '1') {
            header_remove('Pragma');
            header('X-PHP-Gallery-Photo-Cache: 1');
            header('Cache-Control: private, max-age='
                . APP_LINKING_PHOTO_CACHE_FRESH_SECONDS . ', must-revalidate');
        }
        if ($photo['etag'] === null) {
            header('X-PHP-Gallery-Media-Restricted: 1');
        }
        if ($photo['etag'] === null || !app_linking_media_not_modified($photo['etag'])) {
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

/**
 * Apply one fixed native photo mutation with its current approved authority.
 *
 * @return void Emits a confirmed descriptor, a pre-write conflict or an opaque error.
 */
function app_linking_media_photo_mutation(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_media')) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'POST') {
            header('Allow: POST');
            app_linking_json_error(405, 'invalid_request');
            return;
        }
        if (!app_linking_admit_public('app_link_galleries_ip', 'app_link_galleries_global')) {
            app_linking_json_error(429, 'temporarily_unavailable');
            return;
        }
        $token = app_linking_bearer();
        $identity = $token === null ? null : app_linking_authenticate($token, $issuer);
        if ($identity === null) {
            header('WWW-Authenticate: Bearer');
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        if (!app_linking_scope_allows_admin_media($identity['scope'])) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }

        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($type !== 'application/json') {
            app_linking_json_error(415, 'unsupported_media_type');
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, APP_LINKING_CREATE_REQUEST_MAX_BYTES + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > APP_LINKING_CREATE_REQUEST_MAX_BYTES) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        try {
            // Preserve JSON array versus object identity for the deletion batch.
            $decoded = json_decode($raw, false, 3, JSON_THROW_ON_ERROR);
            $input = $decoded instanceof \stdClass ? (array) $decoded : null;
        } catch (Throwable) {
            $input = null;
        }
        if (!is_array($input) || (isset($input['action']) && !is_string($input['action']))) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $action = $input['action'] ?? null;
        if ($action === 'delete') {
            app_linking_media_photo_delete($identity, $token, $issuer, $input);
            return;
        }
        if (!app_linking_visibility_scope_allowed($identity['scope'])) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }
        if (array_key_exists('action', $input)
            || count($input) !== 4
            || !is_int($input['gallery_id'] ?? null) || $input['gallery_id'] <= 0
            || !is_int($input['image_id'] ?? null) || $input['image_id'] <= 0
            || !in_array($input['expected_visibility'] ?? null, ['draft', 'public', 'private'], true)
            || !in_array($input['visibility'] ?? null, ['draft', 'public', 'private'], true)
            || $input['visibility'] === $input['expected_visibility']) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $photo = app_linking_admin_media_change_photo_visibility(
            $identity, $token, $issuer, $input['gallery_id'], $input['image_id'],
            $input['expected_visibility'], $input['visibility'],
        );
        if ($photo === null) {
            app_linking_json_error(409, 'revision_conflict');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['photo' => $photo], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable) {
        app_linking_json_error(503, 'operation_outcome_unknown');
    }
}

/**
 * Permanently delete an exact batch of direct photos under the live Admin link.
 *
 * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Current bearer identity.
 * @param string $token Raw bearer supplied by WinApp.
 * @param string $issuer Verified exact installation issuer.
 * @param array<string,mixed> $input Decoded JSON object from the fixed POST.
 * @return void Emits deleted IDs or an opaque refusal; clients must not blindly retry unknown outcomes.
 */
function app_linking_media_photo_delete(
    array $identity,
    string $token,
    string $issuer,
    array $input,
): void {
    if (!app_linking_scope_allows_admin_media($identity['scope'])) {
        app_linking_json_error(403, 'insufficient_scope');
        return;
    }
    $ids = $input['image_ids'] ?? null;
    if (count($input) !== 3 || !is_int($input['gallery_id'] ?? null)
        || $input['gallery_id'] <= 0 || !is_array($ids) || $ids === []
        || count($ids) > APP_LINKING_MEDIA_DELETE_BATCH_MAX
        || array_is_list($ids) === false) {
        app_linking_json_error(400, 'invalid_request');
        return;
    }
    foreach ($ids as $id) {
        if (!is_int($id) || $id <= 0) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
    }
    if (count(array_unique($ids)) !== count($ids)) {
        app_linking_json_error(400, 'invalid_request');
        return;
    }
    try {
        cms_runtime_kernel()->load('domain-admin-images-bulk');
        $deleted = app_linking_admin_media_delete_photos(
            $identity, $token, $issuer, $input['gallery_id'], $ids,
        );
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['deleted_ids' => $deleted], JSON_THROW_ON_ERROR);
    } catch (\DomainException) {
        app_linking_json_error(409, 'target_unavailable');
    } catch (Throwable) {
        app_linking_json_error(503, 'operation_outcome_unknown');
    }
}
