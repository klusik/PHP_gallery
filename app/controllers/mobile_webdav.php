<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/mobile_webdav.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles admin setup and WebDAV-compatible mobile photo upload requests.
 *
 * Responsibilities:
 *   - Render and persist PhotoSync-style upload credentials
 *   - Respond to minimal WebDAV discovery requests
 *   - Accept authenticated WebDAV PUT uploads into a configured gallery
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
 *   2026-06-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Services\MutationSchemaUnavailableException;
use Throwable;
use function Gallery\Core\base_url;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\mobile_webdav_absolute_url;
use function Gallery\Services\mobile_webdav_authenticated_token;
use function Gallery\Services\mobile_webdav_create_token;
use function Gallery\Services\mobile_webdav_delete_token;
use function Gallery\Services\mobile_webdav_filename_from_path;
use function Gallery\Services\mobile_webdav_ready;
use function Gallery\Services\mobile_webdav_schema_status;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\mutation_schema_assert_available;
use function Gallery\Services\upload_ingestion_schema_status;
use function Gallery\Services\mobile_webdav_store_put;
use function Gallery\Services\mobile_webdav_tokens;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

/**
 * Render and manage mobile WebDAV upload connections.
 */
function cms_admin_mobile_uploads(): void
{
    require_admin();
    if (request_method() === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $created = mobile_webdav_create_token((int) current_user()['id'], (int) ($_POST['gallery_id'] ?? 0), (string) ($_POST['label'] ?? ''));
                $_SESSION['mobile_webdav_created'] = $created;
                flash_message('admin_notice', t('mobile_webdav.notice_created', 'Mobile upload connection created. Copy the password now, it will not be shown again.'));
            } elseif ($action === 'delete') {
                mobile_webdav_delete_token((int) ($_POST['token_id'] ?? 0));
                flash_message('admin_notice', t('mobile_webdav.notice_deleted', 'Mobile upload connection deleted.'));
            }
        } catch (Throwable $exception) {
            flash_message('admin_notice', t('mobile_webdav.notice_failed', 'Mobile upload setup failed: {error}', ['error' => $exception->getMessage()]));
        }
        redirect_to(url_for('admin_mobile_uploads'));
    }

    $created = is_array($_SESSION['mobile_webdav_created'] ?? null) ? $_SESSION['mobile_webdav_created'] : null;
    unset($_SESSION['mobile_webdav_created']);
    render_header(t('mobile_webdav.title', 'Mobile uploads'));
    echo '<section class="hero"><h1>' . e(t('mobile_webdav.title', 'Mobile uploads')) . '</h1><p>' . e(t('mobile_webdav.intro', 'Create WebDAV-compatible upload connections for mobile photo-transfer apps such as PhotoSync.')) . '</p></section>';
    if ($notice = flash_message('admin_notice')) {
        echo '<div class="notice">' . e((string) $notice) . '</div>';
    }
    if (!mobile_webdav_ready()) {
        $schemaStatus = mobile_webdav_schema_status();
        $title = schema_inspection_is_unknown($schemaStatus)
            ? t('mobile_webdav.schema_unknown_title', 'Database schema temporarily unavailable')
            : t('mobile_webdav.migration_required_title', 'Database migration required');
        $help = schema_inspection_is_unknown($schemaStatus)
            ? t('mobile_webdav.schema_unknown_help', 'The mobile-upload schema could not be verified. Credential creation and upload authentication are paused until database metadata inspection succeeds.')
            : t('mobile_webdav.migration_required_help', 'Run database migrations from the dashboard before creating mobile upload connections.');
        echo '<section class="panel"><h2>' . e($title) . '</h2><p class="muted">' . e($help) . '</p></section>';
        render_footer();
        return;
    }
    if ($created) {
        render_mobile_webdav_created_credentials($created);
    }
    render_mobile_webdav_create_form();
    render_mobile_webdav_token_list(mobile_webdav_tokens());
    render_footer();
}

/**
 * Render credentials for a newly created token.
 *
 * @param array $created Created value.
 */
function render_mobile_webdav_created_credentials(array $created): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.created_title', 'New connection details')) . '</h2>';
    echo '<p class="notice">' . e(t('mobile_webdav.password_once', 'Copy this password now. It is stored hashed and cannot be shown again.')) . '</p>';
    echo '<dl class="admin-definition-list">';
    echo '<dt>' . e(t('mobile_webdav.server_url', 'Server URL')) . '</dt><dd><code>' . e((string) $created['url']) . '</code></dd>';
    echo '<dt>' . e(t('mobile_webdav.username', 'Username')) . '</dt><dd><code>' . e((string) $created['username']) . '</code></dd>';
    echo '<dt>' . e(t('mobile_webdav.password', 'Password')) . '</dt><dd><code>' . e((string) $created['password']) . '</code></dd>';
    echo '</dl></section>';
}

/**
 * Render the form used to create a scoped mobile connection.
 */
function render_mobile_webdav_create_form(): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.create_title', 'Create mobile upload connection')) . '</h2>';
    echo '<form method="post" action="' . e(url_for('admin_mobile_uploads')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="action" value="create">';
    echo '<label>' . e(t('mobile_webdav.label', 'Label')) . '<input type="text" name="label" value="PhotoSync iPhone" maxlength="190"></label>';
    echo '<label>' . e(t('admin.upload.gallery', 'Gallery')) . '<select name="gallery_id" required>' . gallery_options_for_select(0) . '</select></label>';
    echo '<button type="submit">' . e(t('mobile_webdav.create_button', 'Create connection')) . '</button>';
    echo '</form>';
    echo '<p class="muted">' . e(t('mobile_webdav.photosync_hint', 'In the mobile app, use WebDAV as the target and enable HEIC to JPEG conversion before transfer when available.')) . '</p>';
    echo '</section>';
}

/**
 * Render existing mobile WebDAV tokens.
 *
 * @param array $tokens Tokens value.
 */
function render_mobile_webdav_token_list(array $tokens): void
{
    echo '<section class="panel"><h2>' . e(t('mobile_webdav.existing_title', 'Existing connections')) . '</h2>';
    if (!$tokens) {
        echo '<p class="muted">' . e(t('mobile_webdav.none', 'No mobile upload connections exist yet.')) . '</p></section>';
        return;
    }
    echo '<table><thead><tr><th>' . e(t('mobile_webdav.label', 'Label')) . '</th><th>' . e(t('admin.upload.gallery', 'Gallery')) . '</th><th>' . e(t('mobile_webdav.server_url', 'Server URL')) . '</th><th>' . e(t('mobile_webdav.last_used', 'Last used')) . '</th><th>' . e(t('admin.common.actions', 'Actions')) . '</th></tr></thead><tbody>';
    foreach ($tokens as $token) {
        echo '<tr><td>' . e((string) $token['label']) . '</td><td>' . e((string) $token['gallery_title']) . '</td><td><code>' . e(mobile_webdav_absolute_url((string) $token['path_token'])) . '</code></td><td>' . e((string) ($token['last_used_at'] ?? '')) . '</td><td>';
        echo '<form method="post" action="' . e(url_for('admin_mobile_uploads')) . '" onsubmit="return confirm(\'' . e(t('mobile_webdav.confirm_delete', 'Delete this mobile upload connection?')) . '\');">' . csrf_field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="token_id" value="' . (int) $token['id'] . '"><button type="submit" class="secondary danger">' . e(t('admin.common.delete', 'Delete')) . '</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table></section>';
}

/**
 * Handle a minimal shared-hosting WebDAV endpoint for mobile upload clients.
 */
function cms_mobile_webdav(): void
{
    $pathToken = (string) ($_GET['token'] ?? '');
    $targetPath = (string) ($_GET['target_path'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'OPTIONS') {
        header('DAV: 1');
        header('Allow: OPTIONS, PROPFIND, PUT, MKCOL');
        http_response_code(204);
        return;
    }

    $webdavSchemaStatus = mobile_webdav_schema_status();
    if (!mobile_webdav_ready()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo schema_inspection_is_missing($webdavSchemaStatus)
            ? t('mobile_webdav.error_migration_required', 'Run database migrations before using mobile upload connections.')
            : t('mobile_webdav.error_schema_unknown', 'Mobile upload is temporarily unavailable because its database schema could not be verified.');
        return;
    }

    $token = mobile_webdav_authenticated_token($pathToken);
    if (!$token) {
        header('WWW-Authenticate: Basic realm="PHP Gallery Mobile Upload"');
        http_response_code(401);
        echo t('mobile_webdav.auth_required', 'Authentication required.');
        return;
    }

    if ($method === 'PROPFIND') {
        mobile_webdav_propfind_response($pathToken);
        return;
    }
    if ($method === 'MKCOL') {
        http_response_code(201);
        return;
    }
    if ($method !== 'PUT') {
        header('Allow: OPTIONS, PROPFIND, PUT, MKCOL');
        http_response_code(405);
        return;
    }

    try {
        mutation_schema_assert_available(
            upload_ingestion_schema_status(),
            'mobile_webdav.put_preflight',
            'Mobile upload requires the current gallery/image database schema. Run pending migrations first.',
            'Mobile upload is temporarily unavailable because the gallery/image database schema could not be verified. No upload body was committed.'
        );
    } catch (MutationSchemaUnavailableException $exception) {
        http_response_code($exception->state === 'unknown' ? 503 : 409);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $exception->getMessage();
        return;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'pg-webdav-');
    if (!is_string($tmpPath)) {
        http_response_code(500);
        echo t('mobile_webdav.error_temp_file', 'Could not create temporary upload file.');
        return;
    }
    $input = fopen('php://input', 'rb');
    $output = fopen($tmpPath, 'wb');
    if (!is_resource($input) || !is_resource($output)) {
        @unlink($tmpPath);
        http_response_code(500);
        echo t('mobile_webdav.error_read_body', 'Could not read upload body.');
        return;
    }
    stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);

    try {
        $filename = mobile_webdav_filename_from_path($targetPath);
        mobile_webdav_store_put($token, $filename, $tmpPath);
        http_response_code(201);
    } catch (Throwable $exception) {
        @unlink($tmpPath);
        admin_log_event('warning', 'mobile_webdav.upload_failed', 'Mobile WebDAV upload failed.', [
            'schema_state' => $exception instanceof MutationSchemaUnavailableException ? $exception->state : 'not_schema_policy',
        ]);
        http_response_code($exception instanceof MutationSchemaUnavailableException && $exception->state === 'unknown' ? 503 : 422);
        echo $exception->getMessage();
    }
}

/**
 * Return a minimal PROPFIND XML response for WebDAV connection tests.
 *
 * @param string $pathToken Path token filesystem path.
 */
function mobile_webdav_propfind_response(string $pathToken): void
{
    $href = base_url('webdav/' . rawurlencode($pathToken) . '/');
    http_response_code(207);
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<d:multistatus xmlns:d="DAV:"><d:response><d:href>' . e($href) . '</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
}
