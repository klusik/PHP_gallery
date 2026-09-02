<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/gallery_migration.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles gallery migration API endpoints and admin AJAX orchestration.
 *
 * Responsibilities:
 *   - Authenticate public migration endpoints with existing gallery API keys
 *   - Expose source manifests and source assets
 *   - Accept target migration manifests, assets, and completion events
 *   - Drive source-push and target-pull migration steps from the admin UI
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
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use const Gallery\Services\GALLERY_MIGRATION_PROTOCOL_VERSION;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\current_user;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_upload_automation_token;
use function Gallery\Services\gallery_migration_asset_ref_from_input;
use function Gallery\Services\gallery_migration_build_manifest;
use function Gallery\Services\gallery_migration_complete_job;
use function Gallery\Services\gallery_migration_current_version;
use function Gallery\Services\gallery_migration_endpoint_url;
use function Gallery\Services\gallery_migration_http_get_json;
use function Gallery\Services\gallery_migration_http_get_to_file;
use function Gallery\Services\gallery_migration_http_post_file_json;
use function Gallery\Services\gallery_migration_http_post_form_json;
use function Gallery\Services\gallery_migration_install_asset_file;
use function Gallery\Services\gallery_migration_job_status_response;
use function Gallery\Services\gallery_migration_manifest_asset_refs;
use function Gallery\Services\gallery_migration_manifest_asset_refs_with_keys;
use function Gallery\Services\gallery_migration_prepare_target_job;
use function Gallery\Services\gallery_migration_request_timeout_seconds;
use function Gallery\Services\gallery_migration_source_asset_descriptor;
use function Gallery\Services\gallery_migration_t;
use function Gallery\Services\mark_upload_automation_token_used;
use function Gallery\Services\t;
use function Gallery\Services\upload_automation_request_token;
use function Gallery\Services\upload_automation_schema_ready;
use function Gallery\Views\view_render_admin_gallery_migration_panel;
use function Gallery\Services\admin_log_event;

/**
 * Emit one JSON response for migration routes.
 *
 * @param array $payload Payload value.
 * @param int $status Status value.
 */
function gallery_migration_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Resolve the existing gallery-scoped API key for a migration API request.
 *
 * @return array{token_row:array<string,mixed>,gallery:array<string,mixed>} Structured result data for the caller.
 */
function gallery_migration_api_gallery(): array
{
    if (!upload_automation_schema_ready()) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_unavailable', 'Upload automation API keys are not installed. Run pending migrations first.'));
    }

    $token = upload_automation_request_token();
    $tokenRow = find_upload_automation_token($token);
    if (!$tokenRow) {
        admin_log_event('warning', 'gallery_migration.unauthorized', 'Gallery migration request used a missing or invalid API key.', [
            'remote_addr_present' => isset($_SERVER['REMOTE_ADDR']) && (string) $_SERVER['REMOTE_ADDR'] !== '',
            'user_agent_present' => isset($_SERVER['HTTP_USER_AGENT']) && (string) $_SERVER['HTTP_USER_AGENT'] !== '',
        ]);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.invalid_api_key', 'Invalid or revoked API key.'));
    }

    $galleryId = (int) ($tokenRow['gallery_id'] ?? 0);
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.gallery_missing', 'Gallery was not found.'));
    }

    return ['token_row' => $tokenRow, 'gallery' => $gallery];
}

/**
 * Return the source manifest authorized by the supplied API key.
 */
function cms_gallery_migration_manifest(): void
{
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $requestedGalleryId = (int) ($_GET['gallery_id'] ?? $_POST['gallery_id'] ?? 0);
        if ($requestedGalleryId > 0 && $requestedGalleryId !== (int) ($gallery['id'] ?? 0)) {
            gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.api_key_scope', 'API key is not allowed to export the requested gallery.')], 403);
            return;
        }

        $manifest = gallery_migration_build_manifest((int) $gallery['id']);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        admin_log_event('info', 'gallery_migration.manifest_exported', 'Gallery migration manifest exported through API.', [
            'gallery_id' => (int) $gallery['id'],
            'image_count' => count((array) ($manifest['images'] ?? [])),
            'asset_count' => count(gallery_migration_manifest_asset_refs($manifest)),
        ]);

        gallery_migration_json([
            'ok' => true,
            'app_version' => gallery_migration_current_version(),
            'protocol_version' => GALLERY_MIGRATION_PROTOCOL_VERSION,
            'manifest' => $manifest,
        ]);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 401);
    }
}

/**
 * Stream one source asset authorized by the supplied API key.
 */
function cms_gallery_migration_asset(): void
{
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $request = gallery_migration_asset_ref_from_input(array_merge($_GET, $_POST));
        $descriptor = gallery_migration_source_asset_descriptor((int) $gallery['id'], $request);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));

        header('Content-Type: ' . $descriptor['mime_type']);
        header('Content-Length: ' . (string) filesize($descriptor['path']));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $descriptor['filename']) . '"');
        readfile($descriptor['path']);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 404);
    }
}

/**
 * Receive and prepare a target migration job authorized by target gallery API key.
 */
function cms_gallery_migration_receive_manifest(): void
{
    if (request_method() !== 'POST') {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $manifestJson = (string) ($_POST['manifest_json'] ?? '');
        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.manifest_invalid', 'Migration manifest is invalid.'));
        }

        $prepared = gallery_migration_prepare_target_job((int) $gallery['id'], $manifest, 'source_push');
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        gallery_migration_json([
            'ok' => true,
            'app_version' => gallery_migration_current_version(),
            'job_id' => $prepared['job_id'],
            'compatibility' => $prepared['compatibility'],
            'target_gallery_id' => (int) $gallery['id'],
            'assets' => $prepared['assets'],
            'counts' => $prepared['counts'],
        ]);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Receive one target migration asset authorized by target gallery API key.
 */
function cms_gallery_migration_receive_asset(): void
{
    if (request_method() !== 'POST') {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $file = $_FILES['asset'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_upload_missing', 'Uploaded migration asset is not available.'));
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_upload_missing', 'Uploaded migration asset is not available.'));
        }

        $result = gallery_migration_install_asset_file(
            (string) ($_POST['job_id'] ?? ''),
            (int) $gallery['id'],
            gallery_migration_asset_ref_from_input($_POST),
            $tmpName
        );
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        gallery_migration_json($result);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Complete a target migration job authorized by target gallery API key.
 */
function cms_gallery_migration_receive_complete(): void
{
    if (request_method() !== 'POST') {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $result = gallery_migration_complete_job((string) ($_POST['job_id'] ?? ''), (int) $gallery['id']);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        gallery_migration_json($result);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Return target-side migration status for reconnect and resume checks.
 */
function cms_gallery_migration_receive_status(): void
{
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $input = array_merge($_GET, $_POST);
        $request = gallery_migration_asset_ref_from_input($input);
        $result = gallery_migration_job_status_response((string) ($input['job_id'] ?? ''), (int) $gallery['id'], $request);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        gallery_migration_json($result);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Handle browser-driven admin migration steps.
 */
function cms_admin_gallery_migration(): void
{
    // $user stores the authenticated administrator for this JSON-only browser workflow.
    $user = current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
        gallery_migration_json(admin_mutation_error_envelope(
            t('auth.admin_required', 'Admin access is required.'),
            'auth.admin_required',
            gallery_migration_admin_mutation_descriptor((string) ($_POST['action'] ?? ''), (int) ($_POST['gallery_id'] ?? 0))
        ), 403);
        return;
    }
    if (request_method() !== 'POST') {
        gallery_migration_json(admin_mutation_error_envelope(
            gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.'),
            'gallery_migration.method_not_allowed',
            gallery_migration_admin_mutation_descriptor((string) ($_POST['action'] ?? ''), (int) ($_POST['gallery_id'] ?? 0))
        ), 405);
        return;
    }
    if (!upload_automation_token_csrf_valid()) {
        gallery_migration_json(admin_mutation_error_envelope(
            t('security.invalid_csrf', 'Invalid CSRF token.'),
            'security.invalid_csrf',
            gallery_migration_admin_mutation_descriptor((string) ($_POST['action'] ?? ''), (int) ($_POST['gallery_id'] ?? 0))
        ), 400);
        return;
    }

    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        gallery_migration_json(admin_mutation_error_envelope(
            t('admin.gallery_not_found', 'Gallery not found.'),
            'gallery.not_found',
            gallery_migration_admin_mutation_descriptor((string) ($_POST['action'] ?? ''), $galleryId)
        ), 404);
        return;
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        $payload = match ($action) {
            'pull_manifest' => gallery_migration_admin_pull_manifest($galleryId),
            'pull_asset' => gallery_migration_admin_pull_asset($galleryId),
            'pull_complete' => gallery_migration_admin_pull_complete($galleryId),
            'pull_status' => gallery_migration_job_status_response((string) ($_POST['job_id'] ?? ''), $galleryId, gallery_migration_asset_ref_from_input($_POST)),
            'push_manifest' => gallery_migration_admin_push_manifest($galleryId),
            'push_asset' => gallery_migration_admin_push_asset($galleryId),
            'push_status' => gallery_migration_admin_push_status(),
            'push_complete' => gallery_migration_admin_push_complete($galleryId),
            default => throw new RuntimeException(gallery_migration_t('gallery_migration.error.action_invalid', 'Unsupported migration action.')),
        };
        gallery_migration_json($payload);
    } catch (Throwable $exception) {
        gallery_migration_json(admin_mutation_error_envelope(
            $exception->getMessage(),
            'gallery_migration.step_failed',
            gallery_migration_admin_mutation_descriptor((string) ($_POST['action'] ?? ''), $galleryId)
        ), 422);
    }
}

/**
 * Fetch a remote source manifest and prepare the local target gallery.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_pull_manifest(int $targetGalleryId): array
{
    $sourceUrl = (string) ($_POST['source_url'] ?? '');
    $sourceApiKey = trim((string) ($_POST['source_api_key'] ?? ''));
    if ($sourceApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $endpoint = gallery_migration_endpoint_url($sourceUrl, 'gallery_migration_manifest');
    $response = gallery_migration_http_get_json($endpoint, $sourceApiKey, gallery_migration_request_timeout_seconds());
    $manifest = $response['manifest'] ?? null;
    if (!is_array($manifest)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.manifest_invalid', 'Migration manifest is invalid.'));
    }

    $prepared = gallery_migration_prepare_target_job($targetGalleryId, $manifest, 'target_pull');
    $payload = [
        'ok' => true,
        'mode' => 'target_pull',
        'job_id' => $prepared['job_id'],
        'compatibility' => $prepared['compatibility'],
        'assets' => $prepared['assets'],
        'counts' => $prepared['counts'],
        'message' => gallery_migration_t('gallery_migration.pull_manifest_ready', 'Source manifest accepted. Asset transfer can start.'),
    ];
    return gallery_migration_admin_local_completion_payload($targetGalleryId, $payload);
}

/**
 * Pull one source asset into the local target gallery.
 *
 * @param int $targetGalleryId Target gallery id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_pull_asset(int $targetGalleryId): array
{
    $sourceUrl = (string) ($_POST['source_url'] ?? '');
    $sourceApiKey = trim((string) ($_POST['source_api_key'] ?? ''));
    if ($sourceApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $request = gallery_migration_asset_ref_from_input($_POST);
    $endpoint = gallery_migration_endpoint_url($sourceUrl, 'gallery_migration_asset', array_filter([
        'scope' => $request['scope'],
        'kind' => $request['kind'],
        'source_image_id' => $request['source_image_id'],
        'size' => $request['size'],
        'format' => $request['format'],
    ], static fn ($value): bool => $value !== '' && $value !== 0));
    $tmp = gallery_migration_http_get_to_file($endpoint, $sourceApiKey, gallery_migration_request_timeout_seconds());
    try {
        return gallery_migration_install_asset_file((string) ($_POST['job_id'] ?? ''), $targetGalleryId, $request, $tmp);
    } finally {
        @unlink($tmp);
    }
}

/**
 * Complete a browser-driven pull into the local gallery and return canonical invalidation metadata.
 *
 * @param int $targetGalleryId Local target gallery id.
 * @return array Structured completion payload for the migration browser workflow.
 */
function gallery_migration_admin_pull_complete(int $targetGalleryId): array
{
    $payload = gallery_migration_complete_job((string) ($_POST['job_id'] ?? ''), $targetGalleryId);
    $payload['message'] = gallery_migration_t('gallery_migration.complete_local', 'Gallery migration completed successfully.');
    return gallery_migration_admin_local_completion_payload($targetGalleryId, $payload);
}

/**
 * Send the local source manifest to a remote target gallery.
 *
 * @param int $sourceGalleryId Source gallery id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_push_manifest(int $sourceGalleryId): array
{
    $targetUrl = (string) ($_POST['target_url'] ?? '');
    $targetApiKey = trim((string) ($_POST['target_api_key'] ?? ''));
    if ($targetApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $manifest = gallery_migration_build_manifest($sourceGalleryId);
    $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_manifest');
    $response = gallery_migration_http_post_form_json($endpoint, [
        'manifest_json' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ], $targetApiKey, gallery_migration_request_timeout_seconds());

    return [
        'ok' => true,
        'mode' => 'source_push',
        'job_id' => (string) ($response['job_id'] ?? ''),
        'compatibility' => $response['compatibility'] ?? null,
        'assets' => gallery_migration_manifest_asset_refs_with_keys($manifest),
        'counts' => $response['counts'] ?? ($manifest['counts'] ?? ['assets' => count(gallery_migration_manifest_asset_refs($manifest))]),
        'status' => $response['status'] ?? null,
        'message' => gallery_migration_t('gallery_migration.push_manifest_ready', 'Target accepted the manifest. Asset transfer can start.'),
    ];
}

/**
 * Push one local source asset to the remote target gallery.
 *
 * @param int $sourceGalleryId Source gallery id identifier.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_push_asset(int $sourceGalleryId): array
{
    $targetUrl = (string) ($_POST['target_url'] ?? '');
    $targetApiKey = trim((string) ($_POST['target_api_key'] ?? ''));
    if ($targetApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $request = gallery_migration_asset_ref_from_input($_POST);
    $descriptor = gallery_migration_source_asset_descriptor($sourceGalleryId, $request);
    $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_asset');
    return gallery_migration_http_post_file_json($endpoint, [
        'job_id' => (string) ($_POST['job_id'] ?? ''),
        'scope' => (string) $request['scope'],
        'kind' => (string) $request['kind'],
        'source_image_id' => (string) (int) $request['source_image_id'],
        'size' => (string) (int) $request['size'],
        'format' => (string) $request['format'],
    ], $descriptor['path'], $descriptor['filename'], $descriptor['mime_type'], $targetApiKey, gallery_migration_request_timeout_seconds());
}

/**
 * Ask the remote target gallery which migration assets it already received.
 *
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_push_status(): array
{
    $targetUrl = (string) ($_POST['target_url'] ?? '');
    $targetApiKey = trim((string) ($_POST['target_api_key'] ?? ''));
    if ($targetApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $request = gallery_migration_asset_ref_from_input($_POST);
    $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_status');
    return gallery_migration_http_post_form_json($endpoint, [
        'job_id' => (string) ($_POST['job_id'] ?? ''),
        'scope' => (string) $request['scope'],
        'kind' => (string) $request['kind'],
        'source_image_id' => (string) (int) $request['source_image_id'],
        'size' => (string) (int) $request['size'],
        'format' => (string) $request['format'],
    ], $targetApiKey, gallery_migration_request_timeout_seconds());
}

/**
 * Ask the remote target gallery to finalize a pushed migration.
 *
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_push_complete(int $sourceGalleryId): array
{
    $targetUrl = (string) ($_POST['target_url'] ?? '');
    $targetApiKey = trim((string) ($_POST['target_api_key'] ?? ''));
    if ($targetApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_complete');
    $payload = gallery_migration_http_post_form_json($endpoint, ['job_id' => (string) ($_POST['job_id'] ?? '')], $targetApiKey, gallery_migration_request_timeout_seconds());
    $payload['message'] = (string) ($payload['message'] ?? gallery_migration_t('gallery_migration.complete_remote', 'Remote gallery migration completed successfully.'));
    return array_merge($payload, admin_mutation_success_envelope(
        (string) $payload['message'],
        gallery_migration_admin_mutation_descriptor('push_complete', $sourceGalleryId),
        null,
        [],
        []
    ));
}

/**
 * Add the canonical side-panel mutation envelope to a local target-pull result.
 *
 * Gallery Migration owns progress/result markup while transfer is running. The envelope also
 * identifies the owning gallery-editor fragment because imported metadata makes the other editor
 * tabs stale. The browser decides when to refresh that fragment, while canonical render mode
 * handles imported slug/path changes without rewriting the visible browser URL behind the drawer.
 *
 * @param int $galleryId Local target gallery id.
 * @param array<string,mixed> $payload Existing migration step/completion payload.
 * @return array<string,mixed> Existing fields plus canonical mutation completion metadata.
 */
function gallery_migration_admin_local_completion_payload(int $galleryId, array $payload): array
{
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    $renderUrl = $gallery ? gallery_public_url($gallery) : (string) ($payload['gallery_url'] ?? '');
    $contexts = [];
    if ($galleryId > 0 && $renderUrl !== '') {
        $updatedAt = trim((string) ($gallery['updated_at'] ?? ''));
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            $renderUrl,
            $updatedAt !== ''
                ? admin_mutation_postcondition('gallery_updated_at', ['gallery_id' => $galleryId, 'updated_at' => $updatedAt])
                : admin_mutation_postcondition('gallery_identity', ['gallery_id' => $galleryId]),
            'canonical'
        );

        // The parent/root card can change when imported title, visibility, slug, or display metadata changes.
        $parentId = (int) ($gallery['parent_id'] ?? 0);
        $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
        $contexts[] = admin_mutation_public_gallery_context(
            $parentId,
            is_array($parent) ? gallery_public_url($parent) : url_for('home'),
            $updatedAt !== ''
                ? admin_mutation_postcondition('gallery_updated_at', ['gallery_id' => $galleryId, 'updated_at' => $updatedAt])
                : null
        );
    }
    $message = (string) ($payload['message'] ?? gallery_migration_t('gallery_migration.local_state_changed', 'Local gallery migration state changed.'));
    $panelUrl = url_for('admin_edit_gallery', ['id' => $galleryId, 'panel' => 1]);
    $envelope = admin_mutation_success_envelope(
        $message,
        gallery_migration_admin_mutation_descriptor('pull_complete', $galleryId),
        admin_mutation_panel_metadata('gallery-edit', $panelUrl, true),
        $contexts,
        []
    );

    // Existing transfer fields stay available to the migration progress UI during Stage 3 migration.
    return array_merge($payload, $envelope, [
        'gallery_id' => $galleryId,
    ]);
}

/**
 * Build typed mutation metadata for one browser-driven migration direction.
 *
 * Remote target ids live in another installation namespace and therefore are never emitted
 * as local stable entity ids for push operations.
 *
 * @param string $action Migration step/action name.
 * @param int $galleryId Local source or target gallery id.
 * @return array{type:string,entity:string,action:string,entity_ids:array<int,int>}
 */
function gallery_migration_admin_mutation_descriptor(string $action, int $galleryId): array
{
    $isPull = str_starts_with($action, 'pull_');
    return admin_mutation_descriptor(
        $isPull ? 'gallery.migration.pull' : 'gallery.migration.push',
        $isPull ? 'gallery' : 'remote_gallery',
        $isPull ? 'import' : 'export',
        $isPull && $galleryId > 0 ? [$galleryId] : []
    );
}

/**
 * Render the gallery migration controls for the API tab.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_admin_gallery_migration_panel(array $gallery): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_gallery_migration_panel')) {
        view_render_admin_gallery_migration_panel($gallery);
    }
}
