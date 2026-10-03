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

use Gallery\Services\MobileWebdavBodyException;
use Gallery\Services\MutationSchemaUnavailableException;
use Throwable;
use function Gallery\Core\base_url;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_error_envelope;
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
use function Gallery\Services\mobile_webdav_ready;
use function Gallery\Services\mobile_webdav_schema_status;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\mutation_schema_assert_available;
use function Gallery\Services\upload_ingestion_schema_status;
use function Gallery\Services\mobile_webdav_store_put_stream;
use function Gallery\Services\mobile_webdav_tokens;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\feature_capability_effective_enabled;

/**
 * Render and manage mobile WebDAV upload connections.
 *
 * @return void Emits the legacy page, Settings mutation JSON, or a fallback redirect.
 */
function cms_admin_mobile_uploads(): void
{
    require_admin();
    if (request_method() === 'POST') {
        verify_csrf();
        $settingsContext = (string) ($_POST['return_context'] ?? '') === 'settings_uploads';
        $redirectUrl = $settingsContext ? url_for('admin_settings', ['section' => 'uploads']) : url_for('admin_mobile_uploads');
        $json = $settingsContext && admin_wants_json();
        $action = strtolower(trim((string) ($_POST['action'] ?? '')));
        $entityIds = [];
        $created = null;
        $ok = false;
        try {
            if (!in_array($action, ['create', 'delete'], true) || !feature_capability_effective_enabled('mobile_webdav')) {
                throw new \InvalidArgumentException('Unavailable mobile upload action.');
            }
            if ($action === 'create') {
                $created = mobile_webdav_create_token((int) current_user()['id'], (int) ($_POST['gallery_id'] ?? 0), (string) ($_POST['label'] ?? ''));
                $entityIds = [(int) $created['id']];
                $message = t('mobile_webdav.notice_created', 'Mobile upload connection created. Copy the password now, it will not be shown again.');
            } elseif ($action === 'delete') {
                $tokenId = (int) ($_POST['token_id'] ?? 0);
                if ($tokenId <= 0) {
                    throw new \InvalidArgumentException('Invalid mobile upload identifier.');
                }
                mobile_webdav_delete_token($tokenId);
                $entityIds = [$tokenId];
                $message = t('mobile_webdav.notice_deleted', 'Mobile upload connection deleted.');
            }
            $ok = true;
        } catch (Throwable $exception) {
            $message = t('mobile_webdav.notice_failed_safe', 'The mobile upload connection could not be changed. Check the gallery selection and database availability, then try again.');
        }
        if ($json) {
            $mutation = admin_mutation_descriptor('mobile_upload.' . (in_array($action, ['create', 'delete'], true) ? $action : 'invalid'), 'mobile_upload', $action, $entityIds);
            $payload = $ok
                ? admin_mutation_success_envelope($message, $mutation, null, [], ['redirect_url' => $redirectUrl])
                : admin_mutation_error_envelope($message, 'mobile_upload_failed', $mutation, ['redirect_url' => $redirectUrl]);
            $viewModel = admin_mobile_uploads_view_model($created, $message);
            ob_start();
            \Gallery\Views\view_render_admin_mobile_uploads_settings($viewModel);
            $payload['html'] = (string) ob_get_clean();
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: private, no-store');
            http_response_code($ok ? 200 : 422);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($created !== null) {
            $_SESSION['mobile_webdav_created'] = $created;
        }
        flash_message('admin_notice', $message);
        redirect_to($redirectUrl);
    }

    $viewModel = admin_mobile_uploads_settings_view_model();
    $viewModel['notice'] = (string) (flash_message('admin_notice') ?? '');
    \Gallery\Views\view_render_admin_mobile_uploads($viewModel);
}

/**
 * Prepare connection management for either the existing page or Settings uploads.
 *
 * @return array<string,mixed> Prepared state with one-time credentials consumed from the session.
 */
function admin_mobile_uploads_settings_view_model(): array
{
    $created = is_array($_SESSION['mobile_webdav_created'] ?? null) ? $_SESSION['mobile_webdav_created'] : null;
    unset($_SESSION['mobile_webdav_created']);
    return admin_mobile_uploads_view_model($created);
}

/**
 * Prepare bounded connection state without probing an effectively disabled capability.
 *
 * @param array<string,mixed>|null $created Authorized one-time credentials from this request.
 * @param string $notice Optional mutation feedback owned by the mobile fragment.
 * @return array<string,mixed> Presentation state, with no credentials or token rows when disabled.
 */
function admin_mobile_uploads_view_model(?array $created = null, string $notice = ''): array
{
    if (!feature_capability_effective_enabled('mobile_webdav')) {
        return [
            'disabled' => true,
            'ready' => false,
            'unavailable_title' => t('mobile_webdav.disabled_title', 'Mobile uploads are disabled'),
            'unavailable_help' => t('mobile_webdav.disabled_help', 'Enable Mobile uploads in Features to manage WebDAV connections. Existing connections are preserved.'),
            'tokens' => [],
            'created' => null,
        ];
    }
    try {
        return admin_mobile_uploads_available_view_model($created, $notice);
    } catch (Throwable $exception) {
        // Inventory reads must not turn an already persisted mutation into a failed response.
        return [
            'ready' => false,
            'notice' => $notice,
            'created' => $created,
            'tokens' => [],
            'unavailable_title' => t('mobile_webdav.inventory_unavailable_title', 'Connection management temporarily unavailable'),
            'unavailable_help' => t('mobile_webdav.inventory_unavailable_help', 'Connection management could not be refreshed. Copy any new password shown here before reloading this page.'),
        ];
    }
}

/**
 * Prepare available-capability inventory and forms behind the safe presentation boundary.
 *
 * @param array<string,mixed>|null $created Authorized one-time credentials to preserve if inventory fails.
 * @param string $notice Existing mutation feedback.
 * @return array<string,mixed> Schema, inventory and form presentation state.
 */
function admin_mobile_uploads_available_view_model(?array $created, string $notice): array
{
    $ready = mobile_webdav_ready();
    $unavailableTitle = '';
    $unavailableHelp = '';
    if (!$ready) {
        $schemaStatus = mobile_webdav_schema_status();
        $unavailableTitle = schema_inspection_is_unknown($schemaStatus)
            ? t('mobile_webdav.schema_unknown_title', 'Database schema temporarily unavailable')
            : t('mobile_webdav.migration_required_title', 'Database migration required');
        $unavailableHelp = schema_inspection_is_unknown($schemaStatus)
            ? t('mobile_webdav.schema_unknown_help', 'The mobile-upload schema could not be verified. Credential creation and upload authentication are paused until database metadata inspection succeeds.')
            : t('mobile_webdav.migration_required_help', 'Run database migrations from the dashboard before creating mobile upload connections.');
    }
    $tokens = $ready ? mobile_webdav_tokens() : [];
    foreach ($tokens as &$tokenRow) {
        $tokenRow['absolute_url'] = mobile_webdav_absolute_url((string) $tokenRow['path_token']);
    }
    unset($tokenRow);
    $confirmMessage = t('mobile_webdav.confirm_delete', 'Delete this mobile upload connection?');
    return [
        'notice' => $notice,
        'ready' => $ready,
        'unavailable_title' => $unavailableTitle,
        'unavailable_help' => $unavailableHelp,
        'created' => $created,
        'create_form' => [
            'action_url' => url_for('admin_mobile_uploads'),
            'csrf_html' => csrf_field(),
            'gallery_options_html' => gallery_options_for_select(0),
        ],
        'tokens' => $tokens,
        'token_list' => [
            'action_url' => url_for('admin_mobile_uploads'),
            'csrf_html' => csrf_field(),
            'confirm_json' => json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""',
        ],
    ];
}

/**
 * Render credentials for a newly created token.
 *
 * @param array $created Created value.
 */
function render_mobile_webdav_created_credentials(array $created): void
{
    \Gallery\Views\view_render_mobile_webdav_created_credentials($created);
}

/**
 * Render the form used to create a scoped mobile connection.
 */
function render_mobile_webdav_create_form(): void
{
    \Gallery\Views\view_render_mobile_webdav_create_form([
        'action_url' => url_for('admin_mobile_uploads'),
        'csrf_html' => csrf_field(),
        'gallery_options_html' => gallery_options_for_select(0),
    ]);
}

/**
 * Render existing mobile WebDAV tokens.
 *
 * @param array $tokens Tokens value.
 */
function render_mobile_webdav_token_list(array $tokens): void
{
    foreach ($tokens as &$tokenRow) {
        $tokenRow['absolute_url'] = mobile_webdav_absolute_url((string) $tokenRow['path_token']);
    }
    unset($tokenRow);
    $confirmMessage = t('mobile_webdav.confirm_delete', 'Delete this mobile upload connection?');
    \Gallery\Views\view_render_mobile_webdav_token_list($tokens, [
        'action_url' => url_for('admin_mobile_uploads'),
        'csrf_html' => csrf_field(),
        'confirm_json' => json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""',
    ]);
}

/**
 * Handle a minimal shared-hosting WebDAV endpoint for mobile upload clients.
 *
 * @return void Emits the authenticated protocol response; opens and closes only the transport input stream.
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

    $token = mobile_webdav_authenticated_token(
        $pathToken,
        (string) ($_SERVER['PHP_AUTH_USER'] ?? ''),
        (string) ($_SERVER['PHP_AUTH_PW'] ?? ''),
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    );
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

    $input = @fopen('php://input', 'rb');
    if (!is_resource($input)) {
        http_response_code(500);
        echo t('mobile_webdav.error_read_body', 'Could not read upload body.');
        return;
    }

    try {
        mobile_webdav_store_put_stream($token, $targetPath, $input);
        http_response_code(201);
    } catch (MobileWebdavBodyException $exception) {
        http_response_code(500);
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        admin_log_event('warning', 'mobile_webdav.upload_failed', 'Mobile WebDAV upload failed.', [
            'schema_state' => $exception instanceof MutationSchemaUnavailableException ? $exception->state : 'not_schema_policy',
        ]);
        http_response_code($exception instanceof MutationSchemaUnavailableException && $exception->state === 'unknown' ? 503 : 422);
        echo $exception->getMessage();
    } finally {
        fclose($input);
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
    \Gallery\Views\view_render_mobile_webdav_propfind_xml($href);
}
