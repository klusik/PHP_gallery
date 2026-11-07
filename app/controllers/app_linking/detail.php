<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/app_linking/detail.php
 * Module Type: Controller part
 * Purpose: Return explicitly scoped native-client gallery detail.
 * Responsibilities:
 *   - Admit only canonical HTTPS, GET and exact gallery identifiers.
 *   - Recheck the live bearer and serialize only stored non-secret fields.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\request_method;
use function Gallery\Services\app_linking_authenticate;
use function Gallery\Services\app_linking_gallery_detail;
use function Gallery\Services\app_linking_scope_base;
use const Gallery\Services\APP_LINKING_READ_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_SCOPE;
use const Gallery\Services\APP_LINKING_READ_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_READ_UPLOAD_CREATE_SCOPE;
use const Gallery\Services\APP_LINKING_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_CREATE_VISIBILITY_SCOPE;
use const Gallery\Services\APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE;

/**
 * Return only one authorized gallery's explicitly consented stored settings.
 *
 * @return void Sends a no-store JSON projection or generic bounded refusal.
 */
function cms_app_linking_gallery_detail(): void
{
    try {
        $issuer = app_linking_configured_issuer();
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($issuer === null || preg_match(
            '/\Apage=app_linking_gallery_detail&gallery_id=([1-9][0-9]{0,18})\z/D',
            $query,
            $matches,
        ) !== 1 || (string) (int) $matches[1] !== $matches[1]) {
            app_linking_json_error(400, 'invalid_request');
            return;
        }
        if (request_method() !== 'GET') {
            header('Allow: GET');
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
        if (!in_array(app_linking_scope_base($identity['scope']), [
            APP_LINKING_READ_SCOPE, APP_LINKING_READ_UPLOAD_SCOPE,
            APP_LINKING_READ_CREATE_SCOPE, APP_LINKING_READ_UPLOAD_CREATE_SCOPE,
            APP_LINKING_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_VISIBILITY_SCOPE,
            APP_LINKING_CREATE_VISIBILITY_SCOPE, APP_LINKING_UPLOAD_CREATE_VISIBILITY_SCOPE,
        ], true)) {
            app_linking_json_error(403, 'insufficient_scope');
            return;
        }
        $gallery = app_linking_gallery_detail($token, $issuer, (int) $matches[1]);
        if ($gallery === null) {
            $current = app_linking_authenticate($token, $issuer);
            app_linking_json_error(
                $current === null ? 401 : 404,
                $current === null ? 'invalid_token' : 'target_unavailable',
            );
            return;
        }
        app_linking_security_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['gallery' => $gallery], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        app_linking_json_error(503, 'temporarily_unavailable');
    }
}
