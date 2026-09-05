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
use function Gallery\Services\gallery_migration_build_package_file;
use function Gallery\Services\gallery_migration_complete_job;
use function Gallery\Services\gallery_migration_current_version;
use function Gallery\Services\gallery_migration_endpoint_url;
use function Gallery\Services\gallery_migration_http_get_json;
use function Gallery\Services\gallery_migration_http_get_to_file;
use function Gallery\Services\gallery_migration_http_post_file_json;
use function Gallery\Services\gallery_migration_http_post_form_json;
use function Gallery\Services\gallery_migration_http_post_form_to_file;
use function Gallery\Services\gallery_migration_install_asset_file;
use function Gallery\Services\gallery_migration_install_package_file;
use function Gallery\Services\gallery_migration_job_status_response;
use function Gallery\Services\gallery_migration_job_package;
use function Gallery\Services\gallery_migration_load_job;
use function Gallery\Services\gallery_migration_manifest_asset_refs;
use function Gallery\Services\gallery_migration_manifest_asset_refs_with_keys;
use function Gallery\Services\gallery_migration_package_assets_from_json;
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
 * Read the recursive migration checkbox from request input.
 *
 * Missing input keeps the documented default enabled for direct API clients.
 *
 * @param array $input Request values.
 * @return bool True when source descendants should be included.
 */
function gallery_migration_include_subgalleries(array $input): bool
{
    if (!array_key_exists('include_subgalleries', $input)) {
        return true;
    }
    return !in_array(strtolower(trim((string) $input['include_subgalleries'])), ['0', 'false', 'off', 'no'], true);
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
        $input = array_merge($_GET, $_POST);
        $requestedGalleryId = (int) ($input['gallery_id'] ?? 0);
        if ($requestedGalleryId > 0 && $requestedGalleryId !== (int) ($gallery['id'] ?? 0)) {
            gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.api_key_scope', 'API key is not allowed to export the requested gallery.')], 403);
            return;
        }

        $includeSubgalleries = gallery_migration_include_subgalleries($input);
        $manifest = gallery_migration_build_manifest((int) $gallery['id'], $includeSubgalleries);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));
        admin_log_event('info', 'gallery_migration.manifest_exported', 'Gallery migration manifest exported through API.', [
            'gallery_id' => (int) $gallery['id'],
            'include_subgalleries' => $includeSubgalleries,
            'gallery_count' => (int) (($manifest['counts']['galleries'] ?? 1)),
            'image_count' => (int) (($manifest['counts']['images'] ?? count((array) ($manifest['images'] ?? [])))),
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
 * Build and stream one source ZIP package authorized by the supplied API key.
 */
function cms_gallery_migration_package(): void
{
    if (request_method() !== 'POST') {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    $zipPath = '';
    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $includeSubgalleries = gallery_migration_include_subgalleries($_POST);
        $assets = gallery_migration_package_assets_from_json((string) ($_POST['assets_json'] ?? ''));
        $zipPath = gallery_migration_build_package_file((int) $gallery['id'], $assets, $includeSubgalleries);
        mark_upload_automation_token_used((int) ($authorized['token_row']['id'] ?? 0));

        header('Content-Type: application/zip');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Content-Disposition: attachment; filename="php-gallery-migration-package.zip"');
        readfile($zipPath);
    } catch (Throwable $exception) {
        gallery_migration_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    } finally {
        if ($zipPath !== '') {
            @unlink($zipPath);
        }
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
            'imported_root_gallery_id' => (int) ($prepared['imported_root_gallery_id'] ?? 0),
            'gallery_ids' => $prepared['gallery_ids'] ?? [],
            'packages' => $prepared['packages'] ?? [],
            'assets' => $prepared['assets'] ?? [],
            'counts' => $prepared['counts'],
            'status' => $prepared['status'] ?? null,
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
 * Receive one target migration ZIP package authorized by target gallery API key.
 */
function cms_gallery_migration_receive_package(): void
{
    if (request_method() !== 'POST') {
        gallery_migration_json(['ok' => false, 'error' => gallery_migration_t('gallery_migration.error.method_not_allowed', 'Method not allowed.')], 405);
        return;
    }

    try {
        $authorized = gallery_migration_api_gallery();
        $gallery = $authorized['gallery'];
        $file = $_FILES['package'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_missing', 'Migration ZIP package is not available.'));
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_missing', 'Migration ZIP package is not available.'));
        }

        $result = gallery_migration_install_package_file(
            (string) ($_POST['job_id'] ?? ''),
            (int) $gallery['id'],
            (string) ($_POST['package_id'] ?? ''),
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
            'pull_package' => gallery_migration_admin_pull_package($galleryId),
            'pull_complete' => gallery_migration_admin_pull_complete($galleryId),
            'pull_status' => gallery_migration_job_status_response((string) ($_POST['job_id'] ?? ''), $galleryId, gallery_migration_asset_ref_from_input($_POST)),
            'push_manifest' => gallery_migration_admin_push_manifest($galleryId),
            'push_asset' => gallery_migration_admin_push_asset($galleryId),
            'push_package' => gallery_migration_admin_push_package($galleryId),
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
 * Fetch a remote source manifest and prepare a new child tree below the local target gallery.
 *
 * @param int $targetGalleryId Receiving parent gallery id.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_pull_manifest(int $targetGalleryId): array
{
    $sourceUrl = (string) ($_POST['source_url'] ?? '');
    $sourceApiKey = trim((string) ($_POST['source_api_key'] ?? ''));
    if ($sourceApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $includeSubgalleries = gallery_migration_include_subgalleries($_POST);
    $endpoint = gallery_migration_endpoint_url($sourceUrl, 'gallery_migration_manifest', [
        'include_subgalleries' => $includeSubgalleries ? '1' : '0',
    ]);
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
        'imported_root_gallery_id' => (int) ($prepared['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => $prepared['gallery_ids'] ?? [],
        'packages' => $prepared['packages'] ?? [],
        'assets' => $prepared['assets'] ?? [],
        'counts' => $prepared['counts'],
        'status' => $prepared['status'] ?? null,
        'message' => gallery_migration_t('gallery_migration.pull_manifest_ready', 'Source manifest accepted. ZIP package transfer can start.'),
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
        'source_gallery_id' => $request['source_gallery_id'],
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
 * Pull one source ZIP package and install it into the prepared local target tree.
 *
 * @param int $targetGalleryId Receiving parent gallery id.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_pull_package(int $targetGalleryId): array
{
    $sourceUrl = (string) ($_POST['source_url'] ?? '');
    $sourceApiKey = trim((string) ($_POST['source_api_key'] ?? ''));
    if ($sourceApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $jobId = (string) ($_POST['job_id'] ?? '');
    $packageId = (string) ($_POST['package_id'] ?? '');
    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $package = gallery_migration_job_package($job, $packageId);
    if ($package === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_not_in_job', 'Migration ZIP package is not part of this job.'));
    }
    $manifest = (array) ($job['manifest'] ?? []);
    $endpoint = gallery_migration_endpoint_url($sourceUrl, 'gallery_migration_package');
    $tmp = gallery_migration_http_post_form_to_file($endpoint, [
        'assets_json' => json_encode((array) ($package['assets'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        'include_subgalleries' => !empty($manifest['include_subgalleries']) ? '1' : '0',
    ], $sourceApiKey, gallery_migration_request_timeout_seconds());
    try {
        return gallery_migration_install_package_file($jobId, $targetGalleryId, $packageId, $tmp);
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
 * Send the local source manifest to a remote receiving parent gallery.
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

    $includeSubgalleries = gallery_migration_include_subgalleries($_POST);
    $manifest = gallery_migration_build_manifest($sourceGalleryId, $includeSubgalleries);
    $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_manifest');
    $response = gallery_migration_http_post_form_json($endpoint, [
        'manifest_json' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ], $targetApiKey, gallery_migration_request_timeout_seconds());

    return [
        'ok' => true,
        'mode' => 'source_push',
        'job_id' => (string) ($response['job_id'] ?? ''),
        'compatibility' => $response['compatibility'] ?? null,
        'imported_root_gallery_id' => (int) ($response['imported_root_gallery_id'] ?? 0),
        'gallery_ids' => $response['gallery_ids'] ?? [],
        'packages' => $response['packages'] ?? [],
        'assets' => gallery_migration_manifest_asset_refs_with_keys($manifest),
        'counts' => $response['counts'] ?? ($manifest['counts'] ?? ['assets' => count(gallery_migration_manifest_asset_refs($manifest))]),
        'status' => $response['status'] ?? null,
        'message' => gallery_migration_t('gallery_migration.push_manifest_ready', 'Target accepted the manifest. ZIP package transfer can start.'),
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
        'source_gallery_id' => (string) (int) $request['source_gallery_id'],
        'kind' => (string) $request['kind'],
        'source_image_id' => (string) (int) $request['source_image_id'],
        'size' => (string) (int) $request['size'],
        'format' => (string) $request['format'],
    ], $descriptor['path'], $descriptor['filename'], $descriptor['mime_type'], $targetApiKey, gallery_migration_request_timeout_seconds());
}

/**
 * Build one local source ZIP package and push it to the remote target job.
 *
 * @param int $sourceGalleryId Source root gallery id.
 * @return array Structured result data for the caller.
 */
function gallery_migration_admin_push_package(int $sourceGalleryId): array
{
    $targetUrl = (string) ($_POST['target_url'] ?? '');
    $targetApiKey = trim((string) ($_POST['target_api_key'] ?? ''));
    if ($targetApiKey === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.api_key_required', 'Enter the migration API key.'));
    }

    $assets = gallery_migration_package_assets_from_json((string) ($_POST['assets_json'] ?? ''));
    $zipPath = gallery_migration_build_package_file($sourceGalleryId, $assets, gallery_migration_include_subgalleries($_POST));
    try {
        $endpoint = gallery_migration_endpoint_url($targetUrl, 'gallery_migration_receive_package');
        return gallery_migration_http_post_file_json($endpoint, [
            'job_id' => (string) ($_POST['job_id'] ?? ''),
            'package_id' => (string) ($_POST['package_id'] ?? ''),
        ], $zipPath, 'php-gallery-migration-package.zip', 'application/zip', $targetApiKey, gallery_migration_request_timeout_seconds(), 'package');
    } finally {
        @unlink($zipPath);
    }
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
        'source_gallery_id' => (string) (int) $request['source_gallery_id'],
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
 * Add canonical invalidation metadata to a local target-pull result.
 *
 * The selected gallery remains the receiving parent. Imported source metadata
 * belongs to a newly created child tree, so the receiving parent public context
 * is invalidated without replacing the editor fragment currently hosting the
 * migration tool.
 *
 * @param int $galleryId Local receiving parent gallery id.
 * @param array<string,mixed> $payload Existing migration step/completion payload.
 * @return array<string,mixed> Existing fields plus canonical mutation metadata.
 */
function gallery_migration_admin_local_completion_payload(int $galleryId, array $payload): array
{
    $parent = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    $contexts = [];
    if ($parent) {
        $contexts[] = admin_mutation_public_gallery_context(
            $galleryId,
            gallery_public_url($parent),
            admin_mutation_postcondition('gallery_identity', ['gallery_id' => $galleryId]),
            'canonical'
        );
    }

    $importedRootId = (int) ($payload['imported_root_gallery_id'] ?? 0);
    $importedRoot = $importedRootId > 0 ? (find_gallery($importedRootId, true) ?: find_gallery($importedRootId)) : null;
    if ($importedRoot) {
        $contexts[] = admin_mutation_public_gallery_context(
            $importedRootId,
            gallery_public_url($importedRoot),
            admin_mutation_postcondition('gallery_identity', ['gallery_id' => $importedRootId]),
            'canonical'
        );
    }

    $message = (string) ($payload['message'] ?? gallery_migration_t('gallery_migration.local_state_changed', 'Local gallery migration state changed.'));
    $entityIds = array_values(array_filter(array_map('intval', (array) ($payload['gallery_ids'] ?? [])), static fn (int $id): bool => $id > 0));
    $descriptor = admin_mutation_descriptor('gallery', 'gallery', 'pull_complete', $entityIds ?: [$galleryId]);
    $envelope = admin_mutation_success_envelope($message, $descriptor, null, $contexts, []);

    return array_merge($payload, $envelope, [
        'gallery_id' => $galleryId,
        'target_parent_gallery_id' => $galleryId,
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
