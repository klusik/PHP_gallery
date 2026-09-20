<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_uploads.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_gallery_is_rendered_in_context;
use function Gallery\Core\admin_mutation_gallery_membership_postcondition;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_upload_accept_value_for_mode;
use function Gallery\Services\admin_upload_auto_rename_enabled;
use function Gallery\Services\admin_upload_client_format_mode;
use function Gallery\Services\admin_upload_client_format_mode_normalize;
use function Gallery\Services\create_image_thumbnails_result;
use function Gallery\Services\browser_upload_browser_config;
use function Gallery\Services\browser_upload_server_upload_limit_bytes;
use function Gallery\Services\browser_upload_settings;
use function Gallery\Services\browser_upload_store_prepared_zip_batch;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_upload_entries;
use function Gallery\Services\gallery_upload_entries_or_empty;
use function Gallery\Services\gallery_lightbox_total_count;
use function Gallery\Services\heic_conversion_supported;
use function Gallery\Services\media_renamer_default_pattern;
use function Gallery\Services\raw_conversion_supported;
use function Gallery\Services\set_admin_upload_auto_rename_enabled;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\set_browser_upload_settings;
use function Gallery\Services\store_uploaded_gallery_images;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_upload_settings_page;
use function Gallery\Views\view_render_admin_upload_support_panel;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_settings_url;
use Gallery\Services\AdminOperationRefusal;
use function Gallery\Services\admin_operation_begin;
use function Gallery\Services\admin_operation_complete;
use function Gallery\Services\admin_operation_fail;
use function Gallery\Services\admin_operation_fingerprint;
use function Gallery\Services\admin_operation_form_key;
use function Gallery\Services\admin_operation_refresh_url;
use function Gallery\Services\admin_operation_require_key;
use function Gallery\Services\gallery_edit_writer_begin;
use function Gallery\Services\gallery_edit_writer_end;
use function Gallery\Services\mutation_schema_assert_available;
use function Gallery\Services\upload_ingestion_schema_status;
use function Gallery\Services\thumbnail_metadata_preflight_write_schema;

require_once dirname(__DIR__) . '/services/admin_operation_keys.php';

/**
 * Admin upload controller model.
 *
 * This module owns the admin upload endpoint and preserves the controller-namespace facade for the shared JSON mutation detector.
 */


/**
 * Preserve the historical controller-namespace JSON detector while delegating to the canonical helper.
 *
 * Existing Stage 2/3 controllers call Gallery\Controllers\admin_wants_json()
 * without imports. Keep this compatibility facade until those controllers migrate.
 *
 * @return bool True when the shared Admin mutation detector expects JSON.
 */
function admin_wants_json(): bool
{
    return \Gallery\Core\admin_wants_json();
}

/**
 * Return a safe same-origin URL supplied by the side-panel upload workflow.
 *
 * The value is used only as a refresh source after JSON uploads. Keeping this
 * validation server-side prevents a submitted form from turning the refresh
 * URL into an arbitrary external target.
 *
 * @param mixed $value Value to process.
 * @return string Text result for the caller.
 */
function admin_upload_safe_refresh_url(mixed $value): string
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return '';
    }
    $parts = parse_url($candidate);
    if ($parts === false) {
        return '';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== '') {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($requestHost === '' || $host !== $requestHost) {
            return '';
        }
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '' && $host === '') {
        return '';
    }
    return $candidate;
}


/**
 * Emit a JSON upload response and stop this request path cleanly.
 *
 * @param array $payload Payload value.
 * @param int $statusCode Status code value.
 */
function admin_upload_browser_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Throw instead of exiting when the browser JSON endpoint receives a bad CSRF token.
 */
function admin_upload_browser_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        throw new RuntimeException(t('admin.upload.error_invalid_csrf', 'Invalid CSRF token. Reload the admin page and try again.'));
    }
}

/**
 * Reject requests that PHP has already discarded because the multipart body exceeded limits.
 *
 * @return bool True when the condition matches.
 */
function admin_upload_browser_reject_discarded_body(): bool
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0 || $_POST !== [] || $_FILES !== []) {
        return false;
    }
    $uploadLimit = function_exists('Gallery\\Services\\browser_upload_server_upload_limit_bytes') ? browser_upload_server_upload_limit_bytes() : 0;
    admin_log_event('warning', 'gallery.browser_upload_rejected', 'Browser upload request body was discarded before PHP could read files.', [
        'content_length' => $contentLength,
        'upload_limit_bytes' => $uploadLimit,
    ]);
    $message = t('browser_upload.error_php_discarded_body', 'The prepared ZIP batch was larger than this PHP request can accept. Lower the browser ZIP ratio, maximum ZIP batch size, or maximum images per batch in Admin upload settings.');
    admin_upload_browser_json_response(array_merge(
        admin_mutation_error_envelope(
            $message,
            'browser_upload_body_too_large',
            admin_mutation_descriptor('image.upload', 'image', 'upload')
        ),
        [
            'content_length' => $contentLength,
            'upload_limit_bytes' => $uploadLimit,
        ]
    ), 413);
    return true;
}


/**
 * Normalize the dedicated upload settings tab used by the Admin settings page.
 *
 * @param string $tab Tab value.
 * @return string Text result for the caller.
 */
function admin_upload_settings_normalize_tab(string $tab): string
{
    return $tab === 'browser' ? 'browser' : 'general';
}

/**
 * Build the upload settings page model from current application settings.
 *
 * @param string $activeTab Active tab value.
 * @param string $notice Notice value.
 * @return array<string mixed>.
 */
function admin_upload_settings_view_model(string $activeTab, string $notice = ''): array
{
    $notices = [];
    if ($notice !== '') {
        $notices[] = [
            'kind' => 'success',
            'message' => $notice,
        ];
    }

    return [
        'active_tab' => admin_upload_settings_normalize_tab($activeTab),
        'notices' => $notices,
        'support' => admin_upload_support_model(),
        'client_format_mode' => admin_upload_client_format_mode(),
        'auto_rename_enabled' => admin_upload_auto_rename_enabled(),
        'browser_settings' => function_exists('Gallery\\Services\\browser_upload_settings') ? browser_upload_settings() : [],
        'central_settings_url' => admin_settings_url('uploads'),
    ];
}

/**
 * Return upload support capabilities for reusable Admin upload views.
 *
 * @return array<string bool>.
 */
function admin_upload_support_model(): array
{
    return [
        'heic' => heic_conversion_supported(),
        'raw' => raw_conversion_supported(),
    ];
}

/**
 * Persist general upload preferences from the dedicated Admin settings page.
 *
 * @param array $input Input value.
 */
function admin_upload_save_general_settings(array $input): void
{
    $clientFormatMode = admin_upload_client_format_mode_normalize($input['admin_upload_client_format_mode'] ?? 'server_supported');
    set_app_setting('admin_upload_client_format_mode', $clientFormatMode);
    set_admin_upload_auto_rename_enabled(!empty($input['admin_upload_auto_rename_enabled']));
    admin_log_event('info', 'settings.upload_general_updated', 'Admin updated general upload settings.', [
        'client_format_mode' => $clientFormatMode,
        'auto_rename_enabled' => admin_upload_auto_rename_enabled(),
    ]);
}

/**
 * Render and persist the dedicated Admin upload settings page.
 */
function cms_admin_upload_settings(): void
{
    require_admin();
    $activeTab = admin_upload_settings_normalize_tab((string) ($_GET['tab'] ?? 'general'));

    if (request_method() === 'POST') {
        verify_csrf();
        if (!empty($_POST['update_upload_general_settings']) || !empty($_POST['update_upload_preferences'])) {
            admin_upload_save_general_settings($_POST);
            flash_message('admin_notice', t('admin.upload_settings.notice_general_saved', 'General upload settings saved.'));
            redirect_to(url_for('admin_upload_settings', ['tab' => 'general', 'saved' => 'general']));
        }
        if (!empty($_POST['update_browser_upload_settings'])) {
            if (function_exists('Gallery\\Services\\set_browser_upload_settings')) {
                $settings = set_browser_upload_settings($_POST);
                admin_log_event('info', 'settings.browser_upload_updated', 'Admin updated browser upload settings.', [
                    'enabled' => !empty($settings['enabled']),
                    'default_worker_count' => (int) ($settings['default_worker_count'] ?? 0),
                    'max_worker_count' => (int) ($settings['max_worker_count'] ?? 0),
                    'hard_worker_cap' => (int) ($settings['hard_worker_cap'] ?? 0),
                    'zip_size_threshold_ratio' => (float) ($settings['zip_size_threshold_ratio'] ?? 0.0),
                    'max_items_per_batch' => (int) ($settings['max_items_per_batch'] ?? 0),
                    'max_zip_batch_bytes' => (int) ($settings['max_zip_batch_bytes'] ?? 0),
                ]);
            }
            flash_message('admin_notice', t('admin.upload_settings.notice_browser_saved', 'Browser upload settings saved.'));
            redirect_to(url_for('admin_upload_settings', ['tab' => 'browser', 'saved' => 'browser']));
        }
        redirect_to(url_for('admin_upload_settings', ['tab' => $activeTab]));
    }

    $notice = (string) flash_message('admin_notice');
    view_render_admin_upload_settings_page(admin_upload_settings_view_model($activeTab, $notice));
}


/**
 * Accept one browser-prepared upload batch.
 */
function cms_admin_upload_browser_batch(): void
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        admin_upload_browser_json_response(admin_mutation_error_envelope(
            t('admin.upload.error_session_expired', 'Your admin session expired. Please sign in again.'),
            'admin_session_expired',
            admin_mutation_descriptor('image.upload', 'image', 'upload')
        ), 401);
        return;
    }
    if (request_method() !== 'POST') {
        admin_upload_browser_json_response(admin_mutation_error_envelope(
            t('admin.upload.error_method_not_allowed', 'This upload endpoint accepts POST requests only.'),
            'method_not_allowed',
            admin_mutation_descriptor('image.upload', 'image', 'upload')
        ), 405);
        return;
    }
    if (admin_upload_browser_reject_discarded_body()) {
        return;
    }

    try {
        admin_upload_browser_verify_csrf();
        $settings = function_exists('Gallery\\Services\\browser_upload_settings') ? browser_upload_settings() : ['enabled' => false];
        if (empty($settings['enabled'])) {
            throw new RuntimeException(t('browser_upload.error_disabled', 'Browser-side upload is disabled in Admin settings.'));
        }
        $galleryId = (int) ($_POST['gallery_id'] ?? 0);
        $sessionId = substr((string) ($_POST['upload_session_id'] ?? ''), 0, 120);
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(12));
        }
        $batchIndex = max(0, (int) ($_POST['batch_index'] ?? 0));
        $preparedThumbnailsRequired = (string) ($_POST['prepared_thumbnails_required'] ?? '') === '1';
        $response = browser_upload_store_prepared_zip_batch($galleryId, $_FILES['zip_batch'] ?? [], $sessionId, $batchIndex, $preparedThumbnailsRequired);
        $callerRefreshUrl = admin_upload_safe_refresh_url($_POST['source_url'] ?? '');
        if ($callerRefreshUrl !== '') {
            $response['refresh_url'] = $callerRefreshUrl;
        }
        // $gallery stores the authoritative post-batch row used to attach the same canonical completion contract as classic uploads.
        $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
        if (is_array($gallery)) {
            // $imageIds stores stable identifiers returned by this batch before browser-side aggregation combines all batches.
            $imageIds = array_values(array_filter(array_map('intval', (array) ($response['image_ids'] ?? [])), static fn (int $imageId): bool => $imageId > 0));
            // $editUrl keeps the editor on its images tab after the batch while the drawer remains mounted.
            $editUrl = (string) ($response['edit_url'] ?? url_for('admin_edit_gallery', ['id' => $galleryId, 'tab' => 'admin-edit-images']) . '#admin-edit-images');
            // $renderUrl is the authoritative gallery route. The browser coordinator preserves any visible pagination/query state for this stable gallery id.
            $renderUrl = gallery_public_url($gallery);
            // $mutationEnvelope keeps browser batching on the canonical completion contract instead of reconstructing mutation semantics from workflow counters.
            $mutationEnvelope = admin_mutation_success_envelope(
                (string) ($response['message'] ?? t('admin.upload.complete', 'Upload complete.')),
                admin_mutation_descriptor('image.upload', 'image', 'upload', $imageIds),
                admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
                [
                    admin_mutation_public_gallery_context(
                        $galleryId,
                        $renderUrl,
                        admin_mutation_postcondition('gallery_image_count', [
                            'gallery_id' => $galleryId,
                            'count' => gallery_lightbox_total_count($gallery, false, false),
                        ])
                    ),
                ],
                ['redirect_url' => (string) ($response['redirect_url'] ?? $editUrl)]
            );
            $response = array_merge($mutationEnvelope, $response);
        }
        admin_upload_browser_json_response($response);
    } catch (Throwable $exception) {
        $errorContext = [
            'error' => $exception->getMessage(),
            'gallery_id' => (int) ($_POST['gallery_id'] ?? 0),
            'batch_index' => (int) ($_POST['batch_index'] ?? 0),
            'upload_session_id' => substr((string) ($_POST['upload_session_id'] ?? ''), 0, 120),
            'total_batches' => (int) ($_POST['total_batches'] ?? 0),
            'zip_upload_error' => (int) ($_FILES['zip_batch']['error'] ?? UPLOAD_ERR_NO_FILE),
            'zip_upload_size' => (int) ($_FILES['zip_batch']['size'] ?? 0),
            'zip_upload_name' => (string) ($_FILES['zip_batch']['name'] ?? ''),
            'prepared_thumbnails_required' => (string) ($_POST['prepared_thumbnails_required'] ?? '') === '1',
        ];
        if ($exception instanceof \Gallery\Services\BrowserUploadValidationException) {
            $errorContext['validation'] = $exception->details();
        }
        admin_log_event('error', 'gallery.browser_upload_failed', 'Browser-prepared upload batch failed.', $errorContext);
        $response = array_merge(
            admin_mutation_error_envelope(
                $exception->getMessage(),
                'browser_upload_batch_failed',
                admin_mutation_descriptor('image.upload', 'image', 'upload')
            ),
            ['retryable' => false]
        );
        if ($exception instanceof \Gallery\Services\BrowserUploadValidationException) {
            $response['error_context'] = $exception->details();
        }
        admin_upload_browser_json_response($response, 422);
    }
}

/**
 * Authenticate and dispatch replay-protected classic uploads or render their forms.
 *
 * Used by HTTP controller routing for this workflow.
 * @return void Send JSON, redirect after confirmed completion, or render a recoverable form error.
 */
function cms_admin_upload(): void
{
    // $isAjaxUpload stores an intermediate value used by the surrounding gallery workflow.
    $isAjaxUpload = request_method() === 'POST' && admin_wants_json();
    // $user stores an intermediate value used by the surrounding gallery workflow.
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        if ($isAjaxUpload) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(admin_mutation_error_envelope(
                    t('admin.upload.error_session_expired', 'Your admin session expired. Please sign in again.'),
                    'admin_session_expired'
                ));
            return;
        }
        // Preserve the upload URL for normal browser requests so login can resume from the same admin context.
        redirect_to(url_for('admin_login', ['return' => current_login_return_target()]));
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // $wantsJson stores an intermediate value used by the surrounding gallery workflow.
        $wantsJson = admin_wants_json();
        if ($wantsJson) {
            ob_start();
        }
        $operationClaim = null;
        $writerLock = null;
        header('Cache-Control: private, no-store');
        try {
            if (!empty($_POST['update_upload_preferences'])) {
                admin_upload_save_general_settings($_POST);
                if (!empty($_POST['update_browser_upload_settings']) && function_exists('Gallery\\Services\\set_browser_upload_settings')) {
                    set_browser_upload_settings($_POST);
                }
                flash_message('admin_notice', t('admin.upload_settings.notice_general_saved', 'General upload settings saved.'));
                redirect_to(url_for('admin_upload_settings', ['tab' => 'general', 'saved' => 'general']));
            }
            // $mode stores an intermediate value used by the surrounding gallery workflow.
            $mode = (string) ($_POST['upload_mode'] ?? 'existing');
            $operationKey = admin_operation_require_key($_POST['operation_key'] ?? null);
            if (!in_array($mode, ['new', 'existing'], true)) {
                throw new AdminOperationRefusal('operation_payload_invalid', 'The upload workflow is invalid.');
            }
            // $entries stores an intermediate value used by the surrounding gallery workflow.
            $entries = $mode === 'new' ? gallery_upload_entries_or_empty($_FILES['images'] ?? null) : gallery_upload_entries($_FILES['images'] ?? null);
            if ($mode !== 'new') {
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
                if (!$gallery) {
                    throw new RuntimeException(t('admin.upload.error_choose_existing_gallery', 'Choose an existing gallery.'));
                }
            }

            $operation = $mode === 'new' ? 'gallery.create_upload' : 'image.classic_upload';
            $operationInput = [
                'gallery' => $mode === 'new' ? admin_new_gallery_input_from_array($_POST) : null,
                'gallery_id' => $mode === 'existing' ? (int) $gallery['id'] : 0,
                'create_thumbnails' => !empty($_POST['create_thumbnails']),
            ];
            $operationClaim = admin_operation_begin((int) $user['id'], $operationKey, $operation, admin_operation_fingerprint($operation, $operationInput, $entries));
            if (!empty($operationClaim['replay'])) {
                $response = $operationClaim['response'];
                if ($wantsJson) {
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    return;
                }
                redirect_to((string) $response['fallback']['redirect_url']);
            }
            if ($entries) {
                // Combined creation must verify every ingestion dependency before
                // creating its folder; the upload service repeats its own boundary.
                mutation_schema_assert_available(
                    upload_ingestion_schema_status(),
                    'upload.operation_preflight',
                    'Image upload requires the current gallery/image database schema. Run pending migrations first.',
                    'Image upload is temporarily unavailable because its required database schema could not be verified. No target file was changed.'
                );
                thumbnail_metadata_preflight_write_schema('upload.operation_thumbnail_preflight');
            }
            // The create service takes a nested reentrant lease; this outer lease also
            // covers file ingestion, metadata/thumbnail writes, and durable completion.
            $writerLock = gallery_edit_writer_begin();
            if ($mode === 'new') {
                // $gallery stores an intermediate value used by the shared create-gallery workflow.
                $gallery = admin_create_gallery_from_input($_POST);
            } else {
                // The pre-claim lookup normalized identity only. Its cached row
                // cannot authorize paths or metadata after another writer finished.
                $gallery = find_gallery((int) $operationInput['gallery_id'], true);
                if (!$gallery) {
                    throw new AdminOperationRefusal('operation_result_unavailable', 'The upload destination no longer exists. No replacement gallery was created.');
                }
            }

            // $stored stores an intermediate value used by the surrounding gallery workflow.
            $stored = $entries ? store_uploaded_gallery_images((int) $gallery['id'], $entries) : [
                'uploaded' => 0,
                'scanned' => 0,
                'image_ids' => [],
                'filenames' => [],
                'scan_failed_filenames' => [],
                'upload_events' => [],
            ];
            // $scanFailedFilenames stores uploaded files that were written to disk but not imported into image rows.
            $scanFailedFilenames = array_values(array_filter(array_map('strval', (array) ($stored['scan_failed_filenames'] ?? []))));
            // $thumbnails stores an intermediate value used by the surrounding gallery workflow.
            $thumbnails = 0;
            // $thumbnailFailed stores required thumbnail or DNG display derivatives that failed during non-JavaScript uploads.
            $thumbnailFailed = 0;
            // $thumbnailErrors stores concise diagnostics for failed thumbnail generation.
            $thumbnailErrors = [];
            $thumbnailFailedFilenames = [];
            if (!$wantsJson && !empty($_POST['create_thumbnails'])) {
                foreach ((array) ($stored['image_ids'] ?? []) as $imageId) {
                    // $image stores the just-uploaded database image row.
                    $image = find_image((int) $imageId);
                    if (!$image) {
                        continue;
                    }
                    // $thumbnailResult stores created/skipped/failure counts for this source image.
                    $thumbnailResult = create_image_thumbnails_result($image, $gallery);
                    $thumbnails += (int) ($thumbnailResult['created'] ?? 0);
                    $thumbnailFailed += (int) ($thumbnailResult['failed'] ?? 0);
                    if ((int) ($thumbnailResult['failed'] ?? 0) > 0) {
                        $thumbnailFailedFilenames[] = (string) ($image['filename'] ?? $image['relative_path'] ?? '');
                    }
                    foreach ((array) ($thumbnailResult['errors'] ?? []) as $thumbnailError) {
                        $thumbnailErrors[] = (string) $thumbnailError;
                    }
                }
            }
            admin_log_event('info', 'gallery.images_uploaded', 'Admin uploaded images into a gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
                'thumbnails' => $thumbnails,
                'thumbnail_failed' => $thumbnailFailed,
                'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
                'scan_failed_filenames' => $scanFailedFilenames,
                'renamed' => (int) ($stored['renamed'] ?? 0),
                'rename_failures' => array_values((array) ($stored['rename_failures'] ?? [])),
            ]);
            if ($scanFailedFilenames) {
                admin_log_event('warning', 'gallery.upload_scan_incomplete', 'One or more uploaded files were stored on disk but not imported into image records.', [
                    'gallery_id' => (int) $gallery['id'],
                    'folder_path' => (string) $gallery['folder_path'],
                    'filenames' => $scanFailedFilenames,
                ]);
            }
            // $parentGalleryId stores the parent used by side-panel refreshes after create-and-upload.
            $parentGalleryId = (int) ($gallery['parent_id'] ?? 0);
            // $parentGallery stores the row that should refresh when a new child gallery appears.
            $parentGallery = $parentGalleryId > 0 ? find_gallery($parentGalleryId, true) : null;
            // $parentGalleryUrl stores the public parent URL, or stays empty for root-level galleries.
            $parentGalleryUrl = is_array($parentGallery) ? gallery_public_url($parentGallery) : '';
            // $refreshGalleryId stores the public page that should redraw after the upload workflow.
            $refreshGalleryId = $mode === 'new' ? $parentGalleryId : (int) $gallery['id'];
            // $refreshUrl stores the source URL for current-context refreshes without guessing on the client.
            $refreshUrl = $mode === 'new' ? ($parentGalleryUrl !== '' ? $parentGalleryUrl : url_for('home')) : gallery_public_url($gallery);
            // $callerRefreshUrl stores the public/admin page that opened the side-panel upload workflow.
            $callerRefreshUrl = admin_upload_safe_refresh_url($_POST['source_url'] ?? '');
            if ($mode !== 'new' && $callerRefreshUrl !== '') {
                // Existing-gallery uploads should refresh the exact page the admin was viewing, including photo_page or clean pagination paths.
                $refreshUrl = admin_operation_refresh_url($refreshUrl, $callerRefreshUrl);
            }
            // $editUrl stores the gallery editor target used after upload so the admin can continue managing photos immediately.
            $editUrl = url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'tab' => 'admin-edit-images']) . '#admin-edit-images';
            // $imageIds stores the stable uploaded image ids used by both the mutation descriptor and upload progress result.
            $imageIds = array_map('intval', $stored['image_ids'] ?? []);
            // $redirectUrl stores only the classic/direct-page fallback destination.
            $redirectUrl = url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'thumbnails' => $thumbnails, 'thumbnail_failed' => $thumbnailFailed, 'scan_failed' => count($scanFailedFilenames), 'tab' => 'admin-edit-images']) . '#admin-edit-images';
            // $mutationContext stores the public context that may be stale after this upload.
            $mutationContext = $mode === 'new'
                ? admin_mutation_public_gallery_context(
                    $parentGalleryId,
                    $refreshUrl,
                    admin_mutation_gallery_membership_postcondition(
                    (int) $gallery['id'],
                    $parentGalleryId,
                    admin_mutation_gallery_is_rendered_in_context($gallery, $parentGalleryId)
                )
                )
                : admin_mutation_public_gallery_context(
                    (int) $gallery['id'],
                    $refreshUrl,
                    admin_mutation_postcondition('gallery_image_count', [
                        'gallery_id' => (int) $gallery['id'],
                        'count' => gallery_lightbox_total_count($gallery, false, false),
                    ])
                );
            // $mutationEnvelope stores the Stage 1 canonical completion contract.
            $mutationEnvelope = admin_mutation_success_envelope(
                t('admin.operations.upload_complete', 'Upload complete.'),
                $mode === 'new'
                    ? admin_mutation_descriptor('gallery.create_with_upload', 'gallery', 'create', [(int) $gallery['id']])
                    : admin_mutation_descriptor('image.upload', 'image', 'upload', $imageIds),
                admin_mutation_panel_metadata('gallery-edit', $editUrl, true),
                [$mutationContext],
                ['redirect_url' => $redirectUrl]
            );
            // $response stores the canonical completion envelope plus workflow-specific upload result fields.
            $response = array_merge($mutationEnvelope, [
                'gallery_id' => (int) $gallery['id'],
                'gallery_ids' => [(int) $gallery['id']],
                'gallery_title' => (string) ($gallery['title'] ?? ''),
                'gallery_url' => gallery_public_url($gallery),
                'edit_url' => $editUrl,
                'parent_gallery_id' => $parentGalleryId,
                'parent_gallery_url' => $parentGalleryUrl,
                'refresh_gallery_id' => $refreshGalleryId,
                'refresh_url' => $refreshUrl,
                'created_gallery' => $mode === 'new',
                'image_ids' => $imageIds,
                'filenames' => array_values($stored['filenames'] ?? []),
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
                'thumbnails' => $thumbnails,
                'thumbnail_failed' => $thumbnailFailed,
                'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
                'scan_failed' => count($scanFailedFilenames),
                'thumbnail_failed_filenames' => $thumbnailFailedFilenames,
                'scan_failed_filenames' => $scanFailedFilenames,
                'renamed' => (int) ($stored['renamed'] ?? 0),
                'rename_warnings' => array_values((array) ($stored['rename_warnings'] ?? [])),
                'rename_failures' => array_values((array) ($stored['rename_failures'] ?? [])),
                'upload_events' => array_values((array) ($stored['upload_events'] ?? [])),
                'redirect_url' => $redirectUrl,
            ]);
            $response = admin_operation_complete($operationClaim, $response);
            gallery_edit_writer_end($writerLock);
            $writerLock = null;
            if ($wantsJson) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json');
                echo json_encode($response);
                return;
            }
            redirect_to($response['redirect_url']);
        } catch (Throwable $exception) {
            admin_operation_fail($operationClaim);
            if ($writerLock !== null) {
                try {
                    gallery_edit_writer_end($writerLock);
                } catch (Throwable) {
                    // The original failure remains bounded; connection exit releases its lease.
                }
                $writerLock = null;
            }
            if ($operationClaim && empty($operationClaim['replay']) && !$exception instanceof AdminOperationRefusal && !$exception instanceof \Gallery\Services\GalleryCatalogConflict) {
                $exception = new AdminOperationRefusal('operation_outcome_unknown', 'The upload may have stored files. Retain its key and reconcile the original attempt before starting another upload.');
            }
            admin_log_event('error', 'gallery.upload_failed', 'Admin image upload failed.', ['error' => $exception->getMessage()]);
            $operationRefusal = $exception instanceof AdminOperationRefusal;
            $catalogConflict = $exception instanceof \Gallery\Services\GalleryCatalogConflict;
            $status = $operationRefusal ? admin_operation_refusal_status($exception) : ($catalogConflict ? \Gallery\Core\HTTP_STATUS_CONFLICT : \Gallery\Core\HTTP_STATUS_UNPROCESSABLE_ENTITY);
            http_response_code($status);
            if ($wantsJson) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: application/json');
                echo json_encode(admin_mutation_error_envelope(
                    $exception->getMessage(),
                    $operationRefusal ? $exception->reason : ($catalogConflict ? 'gallery_catalog_conflict' : 'gallery_upload_failed'),
                    admin_mutation_descriptor('image.upload', 'image', 'upload')
                ));
                return;
            }
            $_SESSION['admin_upload_error'] = $exception->getMessage();
            // Render the failed form with its original key; a redirect would mint a new intent.
        }
    }

    // $prefillGalleryId stores the gallery that should be pre-selected when upload is opened from a public gallery page.
    $prefillGalleryId = request_method() === 'POST' ? max(0, (int) ($_POST['gallery_id'] ?? 0)) : selected_gallery_id_from_query('gallery_id');
    // $prefillParentId stores the parent gallery for the create-and-upload workflow.
    $prefillParentId = request_method() === 'POST' ? max(0, (int) ($_POST['parent_id'] ?? 0)) : selected_gallery_id_from_query('parent_id');
    // $prefillGallery stores the validated gallery record used for contextual helper text.
    $prefillGallery = $prefillGalleryId > 0 ? find_gallery($prefillGalleryId) : null;
    // $prefillParentGallery stores the validated parent row for create-and-upload helper text.
    $prefillParentGallery = $prefillParentId > 0 ? find_gallery($prefillParentId) : null;
    // $requestedUploadMode stores whether this screen should show existing-upload or create-and-upload UI.
    $requestedUploadMode = (string) (request_method() === 'POST' ? ($_POST['upload_mode'] ?? 'existing') : ($_GET['upload_mode'] ?? 'existing'));
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = (string) ($_SESSION['admin_upload_error'] ?? '');
    unset($_SESSION['admin_upload_error']);
    // $panelMode stores whether the upload screen is being rendered inside the reusable admin side panel.
    $panelMode = !empty($_GET['panel']);
    if ($panelMode) {
        render_admin_upload_side_panel($prefillGalleryId, $prefillGallery, $error, $requestedUploadMode, $prefillParentId, $prefillParentGallery);
        return;
    }
    $formFragments = [];
    if ($requestedUploadMode === 'new' || $prefillParentId > 0) {
        $formFragments[] = admin_upload_capture_html(static function () use ($prefillParentId): void {
            render_admin_upload_new_gallery_form($prefillParentId);
        });
    } else {
        $formFragments[] = admin_upload_capture_html(static function () use ($prefillGalleryId): void {
            render_admin_upload_existing_gallery_form($prefillGalleryId);
        });
        $formFragments[] = admin_upload_capture_html(static function () use ($prefillGalleryId): void {
            render_admin_upload_new_gallery_form($prefillGalleryId);
        });
    }
    \Gallery\Views\view_render_admin_upload_page([
        'title' => t('admin.upload.title', 'Upload photos'),
        'dashboard_url' => url_for('admin'),
        'dashboard_label' => t('admin.common.back_to_dashboard', 'Back to dashboard'),
        'new_gallery_url' => url_for('admin_new_gallery'),
        'new_gallery_label' => t('admin.upload.create_empty_gallery', 'Create empty gallery'),
        'notices' => array_values(array_filter([
            $prefillGallery ? t('admin.upload.target_preselected', 'Upload target pre-selected: {title}.', ['title' => (string) $prefillGallery['title']]) : '',
            $prefillParentGallery ? t('admin.upload.new_gallery_parent_notice', 'New gallery will be created inside: {title}.', ['title' => (string) $prefillParentGallery['title']]) : '',
            $error !== '' ? t('admin.upload.failed_value', 'Upload failed: {error}', ['error' => $error]) : '',
        ], static fn (string $notice): bool => $notice !== '')),
        'support_html' => admin_upload_capture_html(static function (): void { render_admin_upload_support_panel(); }),
        'form_fragments' => $formFragments,
    ]);
}

/**
 * Render the focused upload workflow inside the reusable admin side panel.
 *
 * @param int $prefillGalleryId Prefill gallery id identifier.
 * @param ?array $prefillGallery Prefill gallery value.
 * @param string $error Error value.
 * @param string $requestedUploadMode Requested upload mode value.
 * @param int $prefillParentId Prefill parent id identifier.
 * @param ?array $prefillParentGallery Prefill parent gallery value.
 */
function render_admin_upload_side_panel(int $prefillGalleryId, ?array $prefillGallery, string $error, string $requestedUploadMode = 'existing', int $prefillParentId = 0, ?array $prefillParentGallery = null): void
{
    $createAndUploadMode = $requestedUploadMode === 'new' || $prefillParentId > 0;
    if ($createAndUploadMode) {
        $formHtml = admin_upload_capture_html(static function () use ($prefillParentId): void {
            render_admin_upload_new_gallery_panel_form($prefillParentId);
        });
        $notices = array_values(array_filter([
            $prefillParentGallery ? t('admin.upload.new_gallery_parent_notice', 'New gallery will be created inside: {title}.', ['title' => (string) $prefillParentGallery['title']]) : '',
            $error !== '' ? t('admin.upload.create_or_upload_failed_value', 'Create or upload failed: {error}', ['error' => $error]) : '',
        ], static fn (string $notice): bool => $notice !== ''));
        $supportHtml = '';
    } else {
        $formHtml = admin_upload_capture_html(static function () use ($prefillGalleryId): void {
            render_admin_upload_existing_gallery_form($prefillGalleryId, true);
        });
        $notices = array_values(array_filter([
            $prefillGallery ? t('admin.upload.target_preselected', 'Upload target pre-selected: {title}.', ['title' => (string) $prefillGallery['title']]) : '',
            $error !== '' ? t('admin.upload.failed_value', 'Upload failed: {error}', ['error' => $error]) : '',
        ], static fn (string $notice): bool => $notice !== ''));
        $supportHtml = admin_upload_capture_html(static function (): void { render_admin_upload_support_panel(); });
    }

    \Gallery\Views\view_render_admin_upload_side_panel([
        'create_mode' => $createAndUploadMode,
        'notices' => $notices,
        'support_html' => $supportHtml,
        'form_html' => $formHtml,
    ]);
}


/** Capture trusted HTML produced by an existing upload presentation renderer. */
function admin_upload_capture_html(callable $renderer): string
{
    ob_start();
    $renderer();
    return (string) ob_get_clean();
}

/**
 * Return the upload accept attribute shared by upload page and side-panel forms.
 *
 * @return string Text result for the caller.
 */
function admin_upload_accept_value(): string
{
    return admin_upload_accept_value_for_mode(admin_upload_client_format_mode(), heic_conversion_supported(), raw_conversion_supported());
}

/**
 * Render the upload capability table used by the full admin upload page.
 */
function render_admin_upload_support_panel(): void
{
    view_render_admin_upload_support_panel(admin_upload_support_model());
}



/**
 * Render the browser-side upload checkbox.
 *
 * @param bool $panelMode Panel mode value.
 */
function render_admin_upload_browser_checkbox(bool $panelMode = false): void
{
    $config = function_exists('Gallery\Services\browser_upload_browser_config') ? browser_upload_browser_config() : ['enabled' => false];
    $encodedConfig = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedConfig)) {
        $encodedConfig = '{}';
    }
    \Gallery\Views\view_render_admin_upload_browser_checkbox([
        'disabled' => empty($config['enabled']),
        'class_name' => $panelMode ? 'admin-side-panel-browser-upload-toggle' : 'browser-upload-toggle',
        'encoded_config' => $encodedConfig,
    ]);
}

/** Return image accept hints plus ZIP only when browser-assisted upload is enabled. */
function admin_browser_upload_accept_value(): string
{
    $accept = admin_upload_accept_value();
    $config = function_exists('Gallery\\Services\\browser_upload_browser_config') ? browser_upload_browser_config() : ['enabled' => false];
    return !empty($config['enabled']) ? $accept . ',.zip,application/zip,application/x-zip-compressed' : $accept;
}

/**
 * Render the existing-gallery upload form without changing the upload endpoint.
 *
 * @param int $prefillGalleryId Prefill gallery id identifier.
 * @param bool $panelMode Panel mode value.
 * @return void Emit prepared upload markup with the target context and operation key.
 */
function render_admin_upload_existing_gallery_form(int $prefillGalleryId, bool $panelMode = false): void
{
    $targetGallery = $panelMode && $prefillGalleryId > 0 ? find_gallery($prefillGalleryId, true) : null;
    \Gallery\Views\view_render_admin_upload_existing_gallery_form([
        'panel_mode' => $panelMode,
        'action_url' => url_for('admin_upload'),
        'csrf_html' => csrf_field(),
        'operation_key' => admin_operation_form_key($_POST['operation_key'] ?? null),
        'gallery_id' => $panelMode ? $prefillGalleryId : 0,
        'target_title' => is_array($targetGallery) ? (string) ($targetGallery['title'] ?? ('#' . $prefillGalleryId)) : '',
        'gallery_options_html' => $panelMode && $prefillGalleryId > 0 ? '' : gallery_options_for_select($prefillGalleryId),
        'accept_value' => admin_browser_upload_accept_value(),
        'browser_checkbox_html' => admin_upload_capture_html(static function () use ($panelMode): void {
            render_admin_upload_browser_checkbox($panelMode);
        }),
    ]);
}

/**
 * Render the new-gallery upload form used by the direct admin upload page.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 */
function render_admin_upload_new_gallery_form(int $prefillParentId): void
{
    render_admin_upload_new_gallery_form_shell($prefillParentId, false);
}

/**
 * Render the new-gallery upload form used inside the public-page side panel.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 */
function render_admin_upload_new_gallery_panel_form(int $prefillParentId): void
{
    render_admin_upload_new_gallery_form_shell($prefillParentId, true);
}

/**
 * Render the shared create-and-upload form while preserving the existing upload route.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 * @param bool $panelMode Panel mode value.
 */
function render_admin_upload_new_gallery_form_shell(int $prefillParentId, bool $panelMode): void
{
    \Gallery\Views\view_render_admin_upload_new_gallery_form([
        'panel_mode' => $panelMode,
        'action_url' => url_for('admin_upload'),
        'csrf_html' => csrf_field(),
        'gallery_fields_html' => admin_upload_capture_html(static function () use ($prefillParentId, $panelMode): void {
            render_admin_new_gallery_fields($prefillParentId, $panelMode, 'upload');
        }),
        'accept_value' => admin_browser_upload_accept_value(),
        'browser_checkbox_html' => admin_upload_capture_html(static function () use ($panelMode): void {
            render_admin_upload_browser_checkbox($panelMode);
        }),
    ]);
}

