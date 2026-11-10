<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/upload.php
 * Module Type: Controller part
 * Purpose: Handle bounded delegated upload and inventory HTTP actions.
 * Responsibilities:
 *   - Require current issuer-bound upload scope and the existing capability.
 *   - Recheck the authorized target under the gallery lock before storing.
 *   - Keep legacy API keys, server paths and ambiguous recovery separate.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_inventory_confirm_indexed;
use function Gallery\Services\app_linking_recovery_probe;
use function Gallery\Services\app_linking_scope_base;
use function Gallery\Services\app_linking_upload_target;
use function Gallery\Services\app_linking_upload_feature_enabled;
use function Gallery\Services\app_linking_upload_rate_admission;
use function Gallery\Services\request_client_ip;
use function Gallery\Services\app_linking_watch_key_open;
use function Gallery\Services\app_linking_watch_key_close;
use function Gallery\Services\gallery_upload_entries;
use function Gallery\Services\upload_automation_bool;
use function Gallery\Services\upload_automation_client_thumbnail_entries;
use function Gallery\Services\upload_automation_gallery_inventory_response;
use function Gallery\Services\upload_automation_image_client_ids;
use function Gallery\Services\upload_automation_inventory_candidates;
use function Gallery\Services\upload_automation_sim_camera_metadata;
use function Gallery\Services\upload_automation_store_images;
use function Gallery\Services\upload_automation_uploaded_files;
use const Gallery\Services\APP_LINKING_UPLOAD_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE;

/**
 * Admit one delegated upload action without touching legacy API-key handling.
 *
 * @param string $page Exact fixed route selected by the dispatcher.
 * @return array{issuer:string,token:string,identity:array{grant_id:int,user_id:int,scope:string}}|null Verified request context, or
 *     null after a bounded JSON refusal has been sent.
 */
function app_linking_upload_admission(string $page): ?array
{
    $issuer = app_linking_configured_issuer();
    if ($issuer === null || !app_linking_fixed_query($page)) {
        app_linking_json_error(400, 'invalid_request');
        return null;
    }
    if (request_method() !== 'POST') {
        header('Allow: POST');
        app_linking_json_error(405, 'invalid_request');
        return null;
    }
    if (!app_linking_upload_feature_enabled()) {
        app_linking_json_error(403, 'feature_disabled');
        return null;
    }
    if (!app_linking_admit_public(
        'app_link_transfer_ip', 'app_link_transfer_global', true,
    )) {
        app_linking_json_error(429, 'temporarily_unavailable');
        return null;
    }
    $token = app_linking_bearer();
    $identity = $token === null ? null : app_linking_authenticate($token, $issuer);
    if ($identity === null) {
        header('WWW-Authenticate: Bearer');
        app_linking_json_error(401, 'invalid_token');
        return null;
    }
    if (!in_array(app_linking_scope_base($identity['scope']), [
        APP_LINKING_UPLOAD_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
        APP_LINKING_UPLOAD_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
        APP_LINKING_UPLOAD_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
    ], true)) {
        app_linking_json_error(403, 'insufficient_scope');
        return null;
    }
    return ['issuer' => $issuer, 'token' => $token, 'identity' => $identity];
}

/**
 * Apply cost-specific budgets after authentication and target authorization.
 *
 * @param array{issuer:string,token:string,identity:array{grant_id:int,user_id:int,scope:string}} $auth
 *     Verified current delegated authentication context.
 * @param string $operation Exact inventory or upload operation.
 * @param int $galleryId Server-verified authorized target identifier.
 * @param int $originalCount Validated submitted original count for upload,
 *     or one request unit for inventory.
 * @return bool True when work may proceed; false after a bounded JSON refusal.
 */
function app_linking_admit_upload_target(
    array $auth,
    string $operation,
    int $galleryId,
    int $originalCount = 1,
): bool {
    $decision = app_linking_upload_rate_admission(
        $operation,
        $auth['identity']['grant_id'],
        $auth['identity']['user_id'],
        $galleryId,
        request_client_ip(),
        $originalCount,
    );
    if ($decision['allowed']) {
        return true;
    }
    if ($decision['retry_after_seconds'] > 0) {
        header('Retry-After: ' . $decision['retry_after_seconds']);
    }
    header('X-PHP-Gallery-Upload-Phase: rejected-before-store-v1');
    app_linking_json_error(
        $decision['reason'] === 'locked' ? 429 : 503,
        'temporarily_unavailable',
    );
    return false;
}

/**
 * Return bounded checksum inventory for one explicitly authorized gallery.
 *
 * A deep request offers only bounded positive file evidence. An unknown result
 * never proves upload failure and never authorizes an automatic retry.
 *
 * @return void Sends a JSON inventory or a bounded failure without credentials.
 */
function cms_app_linking_inventory(): void
{
    try {
        $auth = app_linking_upload_admission('app_linking_inventory');
        if ($auth === null) {
            return;
        }
        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($type !== 'application/json') {
            app_linking_json_error(415, 'unsupported_media_type');
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, APP_LINKING_INVENTORY_MAX_BYTES + 1);
        if (!is_string($raw) || $raw === '') {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (strlen($raw) > APP_LINKING_INVENTORY_MAX_BYTES) {
            app_linking_json_error(413, 'request_too_large');
            return;
        }
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (is_array($payload) && in_array($payload['action'] ?? null, [
            'watch_open', 'watch_close',
        ], true)) {
            $rawWatchKey = $_SERVER['HTTP_X_PHP_GALLERY_WATCH_KEY'] ?? null;
            if ($rawWatchKey !== null || array_diff(array_keys($payload), [
                'action', 'gallery_id', 'key_hash',
            ]) !== [] || !is_int($payload['gallery_id'] ?? null)
                || $payload['gallery_id'] <= 0
                || !is_string($payload['key_hash'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $payload['key_hash']) !== 1) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $galleryId = $payload['gallery_id'];
            if ($payload['action'] === 'watch_open') {
                $success = app_linking_watch_key_open(
                    $auth['identity'], $auth['token'], $auth['issuer'],
                    $galleryId, $payload['key_hash'],
                );
                if (!$success) {
                    app_linking_json_error(404, 'target_unavailable');
                    return;
                }
            } else {
                app_linking_watch_key_close(
                    $auth['identity']['grant_id'], $galleryId, $payload['key_hash'],
                );
            }
            clear_response_cache_headers();
            app_linking_security_headers();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'action' => $payload['action'],
                'gallery_id' => $galleryId,
            ], JSON_THROW_ON_ERROR);
            return;
        }
        if (!is_array($payload) || array_diff(array_keys($payload), [
            'action', 'gallery_id', 'files', 'deep_check',
        ]) !== [] || ($payload['action'] ?? null) !== 'inventory'
            || !is_int($payload['gallery_id'] ?? null) || $payload['gallery_id'] <= 0
            || !is_array($payload['files'] ?? null) || !array_is_list($payload['files'])
            || count($payload['files']) > APP_LINKING_INVENTORY_MAX_FILES
            || (array_key_exists('deep_check', $payload) && !is_bool($payload['deep_check']))) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $inventorySizes = [];
        foreach ($payload['files'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), [
                'client_id', 'filename', 'size', 'sha256',
            ]) !== [] || !is_string($row['client_id'] ?? null)
                || preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $row['client_id']) !== 1
                || !is_string($row['filename'] ?? null) || $row['filename'] === ''
                || strlen($row['filename']) > 1024
                || !is_int($row['size'] ?? null) || $row['size'] < 0
                || !is_string($row['sha256'] ?? null)
                || preg_match('/\A[a-fA-F0-9]{64}\z/D', $row['sha256']) !== 1) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $hash = strtolower($row['sha256']);
            if (isset($inventorySizes[$hash]) && $inventorySizes[$hash] !== $row['size']) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $inventorySizes[$hash] = $row['size'];
        }
        $galleryId = $payload['gallery_id'];
        $watchKey = $_SERVER['HTTP_X_PHP_GALLERY_WATCH_KEY'] ?? null;
        if ($watchKey !== null && (!is_string($watchKey)
            || preg_match('/\Apgw_[a-f0-9]{64}\z/D', $watchKey) !== 1)) {
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        $gallery = app_linking_upload_target($auth['token'], $auth['issuer'], $galleryId, $watchKey);
        if ($gallery === null) {
            $current = app_linking_authenticate($auth['token'], $auth['issuer']);
            app_linking_json_error(
                $current === null ? 401 : 404,
                $current === null ? 'invalid_token' : 'target_unavailable',
            );
            return;
        }
        if (!app_linking_admit_upload_target($auth, 'inventory', $galleryId)) {
            return;
        }
        if (($payload['deep_check'] ?? false) === true) {
            if (count($payload['files']) !== 1) {
                app_linking_json_error(400, 'invalid_request');
                return;
            }
            $candidate = $payload['files'][0];
            $outcome = app_linking_recovery_probe(
                $galleryId,
                $gallery,
                strtolower($candidate['sha256']),
                $candidate['size'],
                static function () use ($auth, $galleryId, $watchKey): ?array {
                    return app_linking_upload_feature_enabled()
                        ? app_linking_upload_target(
                            $auth['token'], $auth['issuer'], $galleryId, $watchKey,
                        ) : null;
                },
            );
            if ($outcome === null) {
                app_linking_json_error(401, 'invalid_token');
                return;
            }
            app_linking_security_headers();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'action' => 'recovery',
                'gallery_id' => $galleryId,
                'result' => $outcome,
            ], JSON_THROW_ON_ERROR);
            return;
        }
        $candidates = upload_automation_inventory_candidates($payload);
        $response = upload_automation_gallery_inventory_response(
            $galleryId, $gallery, $candidates, false, true,
        );
        if ($response['existing'] !== []) {
            try {
                $response = app_linking_inventory_confirm_indexed(
                    $galleryId, $gallery, $candidates, $response,
                    static function () use ($auth, $galleryId, $watchKey): ?array {
                        return app_linking_upload_feature_enabled()
                            ? app_linking_upload_target($auth['token'], $auth['issuer'], $galleryId, $watchKey)
                            : null;
                    },
                );
            } catch (Throwable) {
                $response = null;
            }
            if ($response === null) {
                app_linking_json_error(503, 'inventory_unknown');
                return;
            }
        }
        if (!app_linking_upload_feature_enabled()
            || app_linking_upload_target($auth['token'], $auth['issuer'], $galleryId, $watchKey) === null) {
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        $response['inventory_evidence'] = 'verified_file_v1';
        // The legacy folder identifier is not part of delegated authority.
        unset($response['gallery_folder']);
        foreach ($response['existing'] as &$match) {
            // Only a basename is returned, never the server's physical path.
            $name = basename(str_replace('\\', '/', $match['remote_filename']));
            $match['remote_filename'] = $name;
            $match['remote_relative_path'] = $name;
        }
        unset($match);
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Validate the server-provided multipart length without integer overflow.
 *
 * PHP normally parses multipart bodies before this controller executes, so
 * this checks only the declared size. The web server and PHP must enforce
 * consistent framing and their own body limits while accepting the request.
 *
 * @return 'length_required'|'invalid_request'|'request_too_large'|null
 *     Fixed refusal identifier, or null for an admissible declared size.
 */
function app_linking_upload_content_length_error(): ?string
{
    $length = $_SERVER['CONTENT_LENGTH'] ?? null;
    if ($length === null || $length === '') {
        return 'length_required';
    }
    if (!is_string($length) || preg_match('/\A[1-9][0-9]*\z/D', $length) !== 1) {
        return 'invalid_request';
    }
    $transferEncoding = $_SERVER['HTTP_TRANSFER_ENCODING'] ?? '';
    $alternateLength = $_SERVER['HTTP_CONTENT_LENGTH'] ?? $length;
    if (!is_string($transferEncoding) || $transferEncoding !== ''
        || !is_string($alternateLength) || !hash_equals($length, $alternateLength)) {
        return 'invalid_request';
    }
    $maximum = APP_LINKING_UPLOAD_MAX_BODY_BYTES;
    if (strlen($length) > strlen($maximum)
        || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0)) {
        return 'request_too_large';
    }
    return null;
}

/**
 * Store uploaded originals using the existing gallery-locked upload workflow.
 *
 * @return void Sends a bounded result or a generic authorization/storage error.
 */
function cms_app_linking_upload(): void
{
    try {
        $auth = app_linking_upload_admission('app_linking_upload');
        if ($auth === null) {
            return;
        }
        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($type !== 'multipart/form-data') {
            app_linking_json_error(415, 'unsupported_media_type');
            return;
        }
        $lengthError = app_linking_upload_content_length_error();
        if ($lengthError !== null) {
            $status = $lengthError === 'length_required' ? 411
                : ($lengthError === 'request_too_large' ? 413 : 400);
            app_linking_json_error($status, $lengthError);
            return;
        }
        $rawId = $_POST['gallery_id'] ?? null;
        if (!is_string($rawId) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $rawId) !== 1
            || (string) (int) $rawId !== $rawId
            || (isset($_POST['action']) && $_POST['action'] !== 'upload')
            || isset($_POST['api_key'])) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        $galleryId = (int) $rawId;
        $watchKey = $_SERVER['HTTP_X_PHP_GALLERY_WATCH_KEY'] ?? null;
        if ($watchKey !== null && (!is_string($watchKey)
            || preg_match('/\Apgw_[a-f0-9]{64}\z/D', $watchKey) !== 1)) {
            app_linking_json_error(401, 'invalid_token');
            return;
        }
        $gallery = app_linking_upload_target(
            $auth['token'], $auth['issuer'], $galleryId, $watchKey,
        );
        if ($gallery === null) {
            $current = app_linking_authenticate($auth['token'], $auth['issuer']);
            app_linking_json_error(
                $current === null ? 401 : 404,
                $current === null ? 'invalid_token' : 'target_unavailable',
            );
            return;
        }
        $files = upload_automation_uploaded_files(
            isset($_FILES['images']) && is_array($_FILES['images']) ? $_FILES['images'] : null,
            isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null,
        );
        $names = is_array($files) ? ($files['name'] ?? null) : null;
        $thumbs = $_FILES['client_thumbnails'] ?? null;
        $thumbnailNames = is_array($thumbs) ? ($thumbs['name'] ?? []) : [];
        if (!is_array($names) || count($names) > APP_LINKING_UPLOAD_MAX_FILES
            || !is_array($thumbnailNames)
            || count($thumbnailNames) > APP_LINKING_UPLOAD_MAX_THUMBNAILS) {
            app_linking_json_error(413, 'request_too_large');
            return;
        }
        if (!app_linking_admit_upload_target(
            $auth, 'upload', $galleryId, max(1, count($names)),
        )) {
            return;
        }
        foreach ([
            'image_client_ids' => APP_LINKING_UPLOAD_MAX_FILES,
            'thumbnail_client_ids' => APP_LINKING_UPLOAD_MAX_THUMBNAILS,
            'thumbnail_sizes' => APP_LINKING_UPLOAD_MAX_THUMBNAILS,
            'thumbnail_formats' => APP_LINKING_UPLOAD_MAX_THUMBNAILS,
        ] as $field => $limit) {
            $values = $_POST[$field] ?? [];
            $values = is_array($values) ? $values : [$values];
            if (count($values) > $limit) {
                app_linking_json_error(413, 'request_too_large');
                return;
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    app_linking_json_error(400, 'invalid_request');
                    return;
                }
            }
        }
        $clientIds = upload_automation_image_client_ids($_POST['image_client_ids'] ?? []);
        $thumbnails = upload_automation_client_thumbnail_entries(
            is_array($thumbs) ? $thumbs : null,
            $_POST['thumbnail_client_ids'] ?? [],
            $_POST['thumbnail_sizes'] ?? [],
            $_POST['thumbnail_formats'] ?? [],
        );
        try {
            $entries = gallery_upload_entries($files);
        } catch (Throwable) {
            // No file has been moved at this validation boundary.
            app_linking_json_error(422, 'invalid_file');
            return;
        }
        $camera = upload_automation_sim_camera_metadata($_POST);
        $storedResult = upload_automation_store_images(
            $galleryId, $gallery, $entries, $thumbnails, $clientIds, $camera,
            static function () use ($auth, $galleryId, $gallery, $watchKey): bool {
                if (!app_linking_upload_feature_enabled()) {
                    return false;
                }
                $current = app_linking_upload_target(
                    $auth['token'], $auth['issuer'], $galleryId, $watchKey,
                );
                return $current !== null
                    && ($current['folder_path'] ?? null) === ($gallery['folder_path'] ?? null);
            },
            upload_automation_bool($_POST['create_thumbnails'] ?? '1', true),
            static fn(): ?array => app_linking_upload_feature_enabled()
                ? app_linking_upload_target(
                    $auth['token'], $auth['issuer'], $galleryId, $watchKey,
                ) : null,
        );
        $stored = $storedResult['stored'];
        $derivatives = $storedResult['derivatives'];
        $cameraResult = $storedResult['sim_camera_metadata'];
        $clientThumbs = $storedResult['client_thumbnails'];
        // Detailed exceptions can contain server paths; never expose them here.
        $postErrors = array_merge($storedResult['post_upload_errors'], $derivatives['post_upload_errors']);
        $result = [
            'ok' => true,
            'upload_evidence' => 'fresh_insert_v1',
            'gallery_id' => $galleryId,
            'gallery_title' => (string) ($gallery['title'] ?? ''),
            'uploaded' => (int) ($stored['uploaded'] ?? 0),
            'scanned' => (int) ($stored['scanned'] ?? 0),
            'image_ids' => array_map('intval', (array) ($stored['image_ids'] ?? [])),
            'filenames' => array_values((array) ($stored['filenames'] ?? [])),
            'renamed' => (int) ($stored['renamed'] ?? 0),
            'rename_warnings' => empty($stored['rename_warnings']) ? [] : ['Some rename warnings occurred.'],
            'rename_failures' => empty($stored['rename_failures']) ? [] : ['Some renames failed.'],
            'thumbnails' => $derivatives['created'],
            'thumbnail_failed' => $derivatives['failed'],
            'thumbnail_errors' => $derivatives['errors'] === [] ? [] : ['Some derivatives failed.'],
            'post_upload_errors' => $postErrors === [] ? [] : ['Some post-upload checks failed.'],
            'sim_camera_metadata' => [
                'attached' => (int) ($cameraResult['attached'] ?? 0),
                'skipped' => (int) ($cameraResult['skipped'] ?? 0),
                'error' => empty($cameraResult['error']) ? '' : 'Camera metadata was not applied.',
            ],
            'client_thumbnails' => [
                'installed' => (int) ($clientThumbs['installed'] ?? 0),
                'skipped' => (int) ($clientThumbs['skipped'] ?? 0),
                'failed' => (int) ($clientThumbs['failed'] ?? 0),
                'errors' => empty($clientThumbs['errors']) ? [] : ['Some client thumbnails failed.'],
            ],
        ];
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        // Failure after a write is ambiguous; clients must not replay blindly.
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}
