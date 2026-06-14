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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
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
use function Gallery\Services\heic_conversion_supported;
use function Gallery\Services\raw_conversion_supported;
use function Gallery\Services\set_admin_upload_auto_rename_enabled;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\set_browser_upload_settings;
use function Gallery\Services\store_uploaded_gallery_images;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_upload_settings_page;
use function Gallery\Views\view_render_admin_upload_support_panel;
use function Gallery\Services\admin_log_event;

/**
 * Admin upload controller model.
 * 
 * This module owns the admin upload endpoint and JSON response detection used by asynchronous upload flows.
 */


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
    admin_upload_browser_json_response([
        'ok' => false,
        'error' => t('browser_upload.error_php_discarded_body', 'The prepared ZIP batch was larger than this PHP request can accept. Lower the browser ZIP ratio, maximum ZIP batch size, or maximum images per batch in Admin upload settings.'),
        'content_length' => $contentLength,
        'upload_limit_bytes' => $uploadLimit,
    ], 413);
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
        admin_upload_browser_json_response(['ok' => false, 'error' => t('admin.upload.error_session_expired', 'Your admin session expired. Please sign in again.')], 401);
        return;
    }
    if (request_method() !== 'POST') {
        admin_upload_browser_json_response(['ok' => false, 'error' => t('admin.upload.error_method_not_allowed', 'This upload endpoint accepts POST requests only.')], 405);
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
        $response = browser_upload_store_prepared_zip_batch($galleryId, $_FILES['zip_batch'] ?? [], $sessionId, $batchIndex);
        $callerRefreshUrl = admin_upload_safe_refresh_url($_POST['source_url'] ?? '');
        if ($callerRefreshUrl !== '') {
            $response['refresh_url'] = $callerRefreshUrl;
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
        ];
        if ($exception instanceof \Gallery\Services\BrowserUploadValidationException) {
            $errorContext['validation'] = $exception->details();
        }
        admin_log_event('error', 'gallery.browser_upload_failed', 'Browser-prepared upload batch failed.', $errorContext);
        $response = [
            'ok' => false,
            'error' => $exception->getMessage(),
            'retryable' => false,
        ];
        if ($exception instanceof \Gallery\Services\BrowserUploadValidationException) {
            $response['error_context'] = $exception->details();
        }
        admin_upload_browser_json_response($response, 422);
    }
}

/**
 * Handle cms admin upload.
 *
 * Used by HTTP controller routing for this workflow.
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
            echo json_encode(['ok' => false, 'error' => t('admin.upload.error_session_expired', 'Your admin session expired. Please sign in again.')]);
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
            // $entries stores an intermediate value used by the surrounding gallery workflow.
            $entries = $mode === 'new' ? gallery_upload_entries_or_empty($_FILES['images'] ?? null) : gallery_upload_entries($_FILES['images'] ?? null);
            if ($mode === 'new') {
                // $gallery stores an intermediate value used by the shared create-gallery workflow.
                $gallery = admin_create_gallery_from_input($_POST);
            } else {
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
                if (!$gallery) {
                    throw new RuntimeException(t('admin.upload.error_choose_existing_gallery', 'Choose an existing gallery.'));
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
                $refreshUrl = $callerRefreshUrl;
            }
            // $editUrl stores the gallery editor target used after upload so the admin can continue managing photos immediately.
            $editUrl = url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'tab' => 'admin-edit-images']) . '#admin-edit-images';
            // $response stores an intermediate value used by the surrounding gallery workflow.
            $response = [
                'ok' => true,
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
                'image_ids' => array_map('intval', $stored['image_ids'] ?? []),
                'filenames' => array_values($stored['filenames'] ?? []),
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
                'thumbnails' => $thumbnails,
                'thumbnail_failed' => $thumbnailFailed,
                'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
                'scan_failed' => count($scanFailedFilenames),
                'scan_failed_filenames' => $scanFailedFilenames,
                'renamed' => (int) ($stored['renamed'] ?? 0),
                'rename_warnings' => array_values((array) ($stored['rename_warnings'] ?? [])),
                'rename_failures' => array_values((array) ($stored['rename_failures'] ?? [])),
                'upload_events' => array_values((array) ($stored['upload_events'] ?? [])),
                'redirect_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'thumbnails' => $thumbnails, 'thumbnail_failed' => $thumbnailFailed, 'scan_failed' => count($scanFailedFilenames), 'tab' => 'admin-edit-images']) . '#admin-edit-images',
            ];
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
            admin_log_event('error', 'gallery.upload_failed', 'Admin image upload failed.', ['error' => $exception->getMessage()]);
            if ($wantsJson) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $exception->getMessage()]);
                return;
            }
            $_SESSION['admin_upload_error'] = $exception->getMessage();
            redirect_to(url_for('admin_upload'));
        }
    }

    // $prefillGalleryId stores the gallery that should be pre-selected when upload is opened from a public gallery page.
    $prefillGalleryId = selected_gallery_id_from_query('gallery_id');
    // $prefillParentId stores the parent gallery for the create-and-upload workflow.
    $prefillParentId = selected_gallery_id_from_query('parent_id');
    // $prefillGallery stores the validated gallery record used for contextual helper text.
    $prefillGallery = $prefillGalleryId > 0 ? find_gallery($prefillGalleryId) : null;
    // $prefillParentGallery stores the validated parent row for create-and-upload helper text.
    $prefillParentGallery = $prefillParentId > 0 ? find_gallery($prefillParentId) : null;
    // $requestedUploadMode stores whether this screen should show existing-upload or create-and-upload UI.
    $requestedUploadMode = (string) ($_GET['upload_mode'] ?? 'existing');
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = (string) ($_SESSION['admin_upload_error'] ?? '');
    unset($_SESSION['admin_upload_error']);
    // $panelMode stores whether the upload screen is being rendered inside the reusable admin side panel.
    $panelMode = !empty($_GET['panel']);
    if ($panelMode) {
        render_admin_upload_side_panel($prefillGalleryId, $prefillGallery, $error, $requestedUploadMode, $prefillParentId, $prefillParentGallery);
        return;
    }
    render_header(t('admin.upload.title', 'Upload photos'));
    echo '<section class="hero"><h1>' . e(t('admin.upload.title', 'Upload photos')) . '</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard', 'Back to dashboard')) . '</a><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">' . e(t('admin.upload.create_empty_gallery', 'Create empty gallery')) . '</a></nav></section>';
    if ($prefillGallery) {
        echo '<div class="notice">' . e(t('admin.upload.target_preselected', 'Upload target pre-selected: {title}.', ['title' => (string) $prefillGallery['title']])) . '</div>';
    }
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.upload.new_gallery_parent_notice', 'New gallery will be created inside: {title}.', ['title' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.upload.failed_value', 'Upload failed: {error}', ['error' => $error])) . '</div>';
    }
    render_admin_upload_support_panel();
    if ($requestedUploadMode === 'new' || $prefillParentId > 0) {
        render_admin_upload_new_gallery_form($prefillParentId);
    } else {
        render_admin_upload_existing_gallery_form($prefillGalleryId);
        render_admin_upload_new_gallery_form($prefillGalleryId);
    }
    render_footer();
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
    echo '<div class="admin-side-panel-stack" data-admin-upload-panel>';
    if ($createAndUploadMode) {
        echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('gallery.workflow', 'Gallery workflow')) . '</p><h2>' . e(t('admin.upload.create_gallery_here', 'Create gallery here')) . '</h2><p class="muted">' . e(t('admin.upload.create_gallery_here_help', 'Create a child gallery and upload photos in the same workflow. Photos are optional, so the gallery can still be created empty when needed.')) . '</p></div>';
        if ($prefillParentGallery) {
            echo '<div class="notice">' . e(t('admin.upload.new_gallery_parent_notice', 'New gallery will be created inside: {title}.', ['title' => (string) $prefillParentGallery['title']])) . '</div>';
        }
        if ($error !== '') {
            echo '<div class="notice">' . e(t('admin.upload.create_or_upload_failed_value', 'Create or upload failed: {error}', ['error' => $error])) . '</div>';
        }
        render_admin_upload_new_gallery_panel_form($prefillParentId);
        echo '</div>';
        return;
    }
    echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.upload.workflow', 'Upload workflow')) . '</p><h2>' . e(t('admin.upload.title', 'Upload photos')) . '</h2><p class="muted">' . e(t('admin.upload.existing_panel_help', 'Add photos to an existing gallery without leaving the drawer.')) . '</p></div>';
    if ($prefillGallery) {
        echo '<div class="notice">' . e(t('admin.upload.target_preselected', 'Upload target pre-selected: {title}.', ['title' => (string) $prefillGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.upload.failed_value', 'Upload failed: {error}', ['error' => $error])) . '</div>';
    }
    render_admin_upload_support_panel();
    render_admin_upload_existing_gallery_form($prefillGalleryId, true);
    echo '</div>';
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
    $config = function_exists('Gallery\\Services\\browser_upload_browser_config') ? browser_upload_browser_config() : ['enabled' => false];
    $encodedConfig = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedConfig)) {
        $encodedConfig = '{}';
    }
    $disabled = empty($config['enabled']);
    $checked = $disabled ? '' : ' checked';
    $className = $panelMode ? 'admin-side-panel-browser-upload-toggle' : 'browser-upload-toggle';
    echo '<label class="' . e($className) . '"><input type="checkbox" name="browser_client_upload" value="1" data-browser-upload-toggle data-browser-upload-config="' . e($encodedConfig) . '"' . $checked . ($disabled ? ' disabled' : '') . '> <span>' . e(t('admin.upload.browser_client_upload_label', 'Prepare thumbnails and ZIP batches in this browser')) . '</span><span class="muted">' . e(t('admin.upload.browser_client_upload_help', 'Checked by default. If browser preparation fails before any server-side write starts, the upload automatically uses the normal server fallback. Uncheck this to use the standard server-side upload path immediately.')) . '</span></label>';
}

/**
 * Render the existing-gallery upload form without changing the upload endpoint.
 *
 * @param int $prefillGalleryId Prefill gallery id identifier.
 * @param bool $panelMode Panel mode value.
 */
function render_admin_upload_existing_gallery_form(int $prefillGalleryId, bool $panelMode = false): void
{
    // $acceptValue stores an intermediate value used by the surrounding gallery workflow.
    $acceptValue = admin_upload_accept_value();
    if ($panelMode) {
        echo '<section class="admin-side-panel-card admin-side-panel-upload-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.existing_gallery', 'Existing gallery')) . '</p><h3>' . e(t('admin.upload.upload_existing_title', 'Upload into an existing gallery')) . '</h3></div><p class="muted">' . e(t('admin.upload.upload_existing_help', 'Choose a gallery and upload photos without leaving the drawer.')) . '</p></div><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="admin-side-panel-form" data-gallery-upload-form data-gallery-panel-close-on-success="1">' . csrf_field();
        echo '<input type="hidden" name="panel" value="1">';
    } else {
        echo '<section class="panel"><h2>' . e(t('admin.upload.upload_existing_title', 'Upload into existing gallery')) . '</h2><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
    }
    echo '<input type="hidden" name="upload_mode" value="existing">';
    if ($panelMode && $prefillGalleryId > 0) {
        $targetGallery = find_gallery($prefillGalleryId, true);
        echo '<input type="hidden" name="gallery_id" value="' . (int) $prefillGalleryId . '">';
        echo '<div class="admin-side-panel-target"><span>' . e(t('admin.upload.target_gallery', 'Target gallery')) . '</span><strong>' . e((string) ($targetGallery['title'] ?? ('#' . $prefillGalleryId))) . '</strong></div>';
    } else {
        echo '<label' . ($panelMode ? ' class="admin-side-panel-field admin-side-panel-field-wide"' : '') . '><span>' . e(t('admin.upload.gallery', 'Gallery')) . '</span><select name="gallery_id" required>' . gallery_options_for_select($prefillGalleryId) . '</select></label>';
    }
    echo '<label' . ($panelMode ? ' class="admin-side-panel-file-drop"' : '') . '><span class="admin-side-panel-file-title">' . e(t('admin.upload.images', 'Images')) . '</span><input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple' . ($panelMode ? ' required' : ' required') . '><span class="muted">' . e(t('admin.upload.choose_images_for_gallery', 'Choose one or more images for this gallery.')) . '</span></label>';
    echo '<label' . ($panelMode ? ' class="admin-side-panel-thumbnail-toggle"' : '') . '><input type="checkbox" name="create_thumbnails" value="1" checked> <span>' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</span></label>';
    render_admin_upload_browser_checkbox($panelMode);
    if ($panelMode) {
        echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.upload_images', 'Upload images')) . '</button><p class="muted">' . e(t('admin.upload.progress_top_panel', 'Progress appears at the top of this panel.')) . '</p></div>';
    } else {
        echo '<button type="submit">' . e(t('admin.upload.upload_images', 'Upload images')) . '</button>';
    }
    echo '</form></section>';
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
    // $acceptValue stores an intermediate value used by the surrounding gallery workflow.
    $acceptValue = admin_upload_accept_value();
    if (!$panelMode) {
        echo '<section class="panel"><h2>' . e(t('admin.upload.create_and_upload_title', 'Create gallery and upload photos')) . '</h2>';
        echo '<form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
        echo '<input type="hidden" name="upload_mode" value="new">';
        render_admin_new_gallery_fields($prefillParentId, false, 'upload');
        echo '<label>' . e(t('admin.upload.images', 'Images')) . '<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required><span class="muted">' . e(t('admin.upload.choose_one_or_more_images', 'Choose one or more images.')) . '</span></label>';
        echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> ' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</label>';
        render_admin_upload_browser_checkbox(false);
        echo '<button type="submit">' . e(t('admin.upload.create_gallery_and_upload', 'Create gallery and upload')) . '</button></form></section>';
        return;
    }

    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<div class="admin-side-panel-progress-anchor" data-gallery-panel-progress-anchor></div>';
    echo '<form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="admin-side-panel-form" data-gallery-upload-form data-gallery-panel-close-on-success="1">' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="new">';
    render_admin_new_gallery_fields($prefillParentId, true, 'upload');

    echo '<div class="admin-side-panel-card admin-side-panel-upload-card">';
    echo '<div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.optional_photos', 'Optional photos')) . '</p><h3>' . e(t('admin.upload.upload_now', 'Upload now')) . '</h3></div><p class="muted">' . e(t('admin.upload.optional_photos_help', 'Leave this empty to create only the gallery.')) . '</p></div>';
    echo '<label class="admin-side-panel-file-drop"><span class="admin-side-panel-file-title">' . e(t('admin.upload.choose_images', 'Choose images')) . '</span><input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple><span class="muted">' . e(t('admin.upload.multiple_files_help', 'Multiple files are supported. The existing upload pipeline and thumbnail generation are reused.')) . '</span></label>';
    echo '<label class="admin-side-panel-thumbnail-toggle"><input type="checkbox" name="create_thumbnails" value="1" checked> <span>' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</span></label>';
    render_admin_upload_browser_checkbox(true);
    echo '</div>';

    echo '<div class="admin-side-panel-actions">';
    echo '<button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.create_gallery', 'Create gallery')) . '</button>';
    echo '<p class="muted">' . e(t('admin.upload.progress_top_panel_during_upload', 'Progress appears at the top of this panel during upload.')) . '</p>';
    echo '</div>';
    echo '</form></section>';
}

/**
 * Handles admin wants json logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function admin_wants_json(): bool
{
    return !empty($_POST['ajax'])
        || !empty($_GET['ajax'])
        || !empty($_POST['panel'])
        || !empty($_GET['panel'])
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

