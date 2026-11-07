<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/create.php
 * Module Type: Controller part
 * Purpose: Admit linked gallery creation and read-only receipt requests.
 * Responsibilities:
 *   - Validate bounded JSON, current bearer and explicit creation scope.
 *   - Return safe receipts and preserve ambiguous outcomes for reconciliation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use Gallery\Services\LinkedGalleryVisibilityConflict;
use Gallery\Services\LinkedGalleryScheduledPublicationActive;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_change_gallery_visibility;
use function Gallery\Services\app_linking_create_gallery;
use function Gallery\Services\app_linking_create_abandon;
use function Gallery\Services\app_linking_create_input;
use function Gallery\Services\app_linking_create_scope_allowed;
use function Gallery\Services\app_linking_create_status;
use function Gallery\Services\app_linking_scope_allowed;
use function Gallery\Services\app_linking_visibility_scope_allowed;
use const Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID;

/**
 * Bound an entire linked gallery creation or status JSON request.
 * Type: int. Units: bytes. Scope: one request body.
 * Consumers: app_linking_create_request.
 * Rationale: 8192 accommodates escaped UTF-8 titles and fixed metadata.
 */
const APP_LINKING_CREATE_REQUEST_MAX_BYTES = 8192;

/**
 * Admit one creation endpoint with the common public-linking security policy.
 *
 * @param string $page Fixed dispatcher route.
 * @return array{token:string,issuer:string}|null Authorized context or a sent refusal.
 */
function app_linking_create_admission(string $page): ?array
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
    if (!app_linking_admit_public('app_link_galleries_ip', 'app_link_galleries_global')) {
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
    if (!app_linking_create_scope_allowed($identity['scope'])) {
        app_linking_json_error(403, 'insufficient_scope');
        return null;
    }
    return ['token' => $token, 'issuer' => $issuer];
}

/**
 * Parse a bounded JSON object with the exact endpoint fields.
 *
 * @param bool $status True for the read-only receipt request.
 * @return array<string,mixed>|null Decoded request or null after a sent refusal.
 */
function app_linking_create_request(bool $status): ?array
{
    $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($type !== 'application/json') {
        app_linking_json_error(415, 'unsupported_media_type');
        return null;
    }
    $raw = file_get_contents('php://input', false, null, 0, APP_LINKING_CREATE_REQUEST_MAX_BYTES + 1);
    if (!is_string($raw) || $raw === '' || strlen($raw) > APP_LINKING_CREATE_REQUEST_MAX_BYTES) {
        app_linking_json_error(400, 'invalid_request');
        return null;
    }
    try {
        $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $payload = null;
    }
    $fields = $status ? ['operation_key'] : ['operation_key', 'title', 'parent_id'];
    if (!is_array($payload) || array_is_list($payload)
        || count($payload) !== count($fields)
        || array_diff(array_keys($payload), $fields) !== []
        || !is_string($payload['operation_key'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $payload['operation_key']) !== 1) {
        app_linking_json_error(400, 'invalid_request');
        return null;
    }
    return $payload;
}

/**
 * Create one explicitly authorized gallery with its durable operation key.
 *
 * @return void Sends a safe receipt or a conservative unknown-outcome refusal.
 */
function cms_app_linking_gallery_create(): void
{
    try {
        $auth = app_linking_create_admission('app_linking_gallery_create');
        if ($auth === null || ($request = app_linking_create_request(false)) === null) {
            return;
        }
        try {
            $input = app_linking_create_input($request);
        } catch (Throwable) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        try {
            $result = app_linking_create_gallery(
                $auth['token'], $auth['issuer'], $request['operation_key'], $input,
            );
        } catch (Throwable) {
            app_linking_json_error(409, 'operation_outcome_unknown');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Read a previous create receipt without claiming or repeating the operation.
 *
 * @return void Sends a safe receipt, unknown, or a bounded refusal.
 */
function cms_app_linking_gallery_create_status(): void
{
    try {
        $auth = app_linking_create_admission('app_linking_gallery_create_status');
        if ($auth === null || ($request = app_linking_create_request(true)) === null) {
            return;
        }
        $result = app_linking_create_status(
            $auth['token'], $auth['issuer'], $request['operation_key'],
        );
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}

/**
 * Close one retained create key without claiming its effects are absent.
 *
 * @return void Sends a durable tombstone, existing status or bounded refusal.
 */
function cms_app_linking_gallery_create_abandon(): void
{
    try {
        $auth = app_linking_create_admission('app_linking_gallery_create_abandon');
        if ($auth === null || ($request = app_linking_create_request(false)) === null) {
            return;
        }
        try {
            $input = app_linking_create_input($request);
        } catch (Throwable) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        try {
            $result = app_linking_create_abandon(
                $auth['token'], $auth['issuer'], $request['operation_key'], $input,
            );
        } catch (Throwable) {
            app_linking_json_error(503, 'operation_outcome_unknown');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'operation_outcome_unknown');
    }
}

/**
 * Change one stored visibility using an exact precondition and fresh bearer.
 *
 * @return void Sends the acknowledged stored projection or a bounded refusal.
 */
function cms_app_linking_gallery_visibility(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        if ($issuer === null || !app_linking_fixed_query('app_linking_gallery_visibility')) {
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
        if (!app_linking_visibility_scope_allowed($identity['scope'])
            || !app_linking_scope_allowed(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'])) {
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
            $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $input = null;
        }
        if (!is_array($input) || array_is_list($input) || count($input) !== 3
            || array_diff(array_keys($input), ['gallery_id', 'expected_revision', 'visibility']) !== []
            || !is_int($input['gallery_id'] ?? null) || $input['gallery_id'] <= 0
            || !is_string($input['expected_revision'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,19}\z/D', $input['expected_revision']) !== 1
            || !is_string($input['visibility'] ?? null)
            || !in_array($input['visibility'], ['public', 'private', 'unpublished'], true)) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        try {
            $gallery = app_linking_change_gallery_visibility(
                $token, $issuer, $input['gallery_id'],
                $input['expected_revision'], $input['visibility'],
            );
        } catch (LinkedGalleryVisibilityConflict) {
            app_linking_json_error(409, 'revision_conflict');
            return;
        } catch (LinkedGalleryScheduledPublicationActive) {
            app_linking_json_error(409, 'scheduled_publication_active');
            return;
        } catch (Throwable) {
            app_linking_json_error(503, 'operation_outcome_unknown');
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['gallery' => $gallery], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'operation_outcome_unknown');
    }
}
