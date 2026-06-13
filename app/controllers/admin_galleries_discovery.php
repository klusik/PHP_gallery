<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_discovery.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles admin discovery, import, and create-gallery workflows.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use const Gallery\Services\ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE;
use const Gallery\Services\ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
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
use function Gallery\Services\admin_gallery_discovery_delete_requested_paths;
use function Gallery\Services\admin_gallery_discovery_job_status;
use function Gallery\Services\admin_gallery_discovery_move_requested_photos;
use function Gallery\Services\admin_gallery_discovery_process_job;
use function Gallery\Services\admin_gallery_discovery_start_job;
use function Gallery\Services\create_empty_gallery;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_count_badge_override_label;
use function Gallery\Services\gallery_count_badge_override_values;
use function Gallery\Services\gallery_count_badge_schema_ready;
use function Gallery\Services\gallery_date_schema_ready;
use function Gallery\Services\gallery_visibility_storage_value;
use function Gallery\Services\import_galleries;
use function Gallery\Services\import_galleries_without_thumbnails;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_date_range_fields;
use function Gallery\Views\view_render_admin_new_gallery_fields;
use function Gallery\Views\view_render_admin_new_gallery_side_panel;
use function Gallery\Views\view_render_gallery_description_formatting_hint;

/**
 * Render the Admin gallery discovery page or process its Ajax batches.
 *
 * The visible page no longer performs filesystem recursion during initial
 * rendering. Browser-side JavaScript starts or resumes a small-batch discovery
 * job and renders the import table when the scan is complete.
 */
function cms_admin_discover(): void
{
    require_admin();

    if (request_method() === 'POST' && admin_wants_json()) {
        cms_admin_discover_ajax();
        return;
    }

    if (request_method() === 'POST') {
        verify_csrf();
        redirect_to(url_for('admin_discover'));
    }

    $jobToken = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($_GET['job_token'] ?? '')) ?: '';

    render_header(t('admin.galleries.discover_title'));
    echo '<section class="hero"><h1>' . e(t('admin.galleries.discover_title')) . '</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a></nav></section>';
    render_admin_gallery_discovery_shell($jobToken);
    render_footer();
}

/**
 * Process one Admin gallery discovery Ajax action.
 */
function cms_admin_discover_ajax(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }

    $bufferLevel = ob_get_level();
    ob_start();
    try {
        verify_csrf();
        @set_time_limit(120);

        $action = (string) ($_POST['action'] ?? 'step');
        $token = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($_POST['job_token'] ?? '')) ?: '';
        $batchSize = max(1, min(ADMIN_GALLERY_DISCOVERY_MAX_BATCH_SIZE, (int) ($_POST['batch_size'] ?? ADMIN_GALLERY_DISCOVERY_DEFAULT_BATCH_SIZE)));

        if ($action === 'start') {
            $state = admin_gallery_discovery_start_job();
        } elseif ($action === 'status') {
            $state = admin_gallery_discovery_job_status($token);
        } else {
            $state = admin_gallery_discovery_process_job($token, $batchSize);
        }

        $payload = admin_gallery_discovery_controller_payload($state);
        $discardedOutput = (string) ob_get_clean();
        if (trim($discardedOutput) !== '') {
            admin_log_event('warning', 'gallery.discovery_response_output_discarded', 'Gallery discovery produced output before its JSON response.', [
                'discarded_output_preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500),
            ], ['category' => 'other', 'severity' => 'warning']);
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_gallery_discovery_json_response($payload);
    } catch (Throwable $exception) {
        $discardedOutput = (string) ob_get_clean();
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'gallery.discovery_failed', 'Gallery discovery Ajax request failed.', [
            'error' => $exception->getMessage(),
            'discarded_output_preview' => $discardedOutput !== '' ? mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500) : null,
        ], ['category' => 'other', 'severity' => 'error']);
        admin_gallery_discovery_json_response([
            'ok' => false,
            'status' => 'error',
            'done' => true,
            'error' => $exception->getMessage(),
            'message' => $exception->getMessage(),
        ]);
    }
}

/**
 * Build the JSON payload consumed by the Admin discovery browser module.
 *
 * @param array<string, mixed> $state Discovery service state.
 * @return array<string, mixed> JSON-safe controller payload.
 */
function admin_gallery_discovery_controller_payload(array $state): array
{
    $status = (string) ($state['status'] ?? 'running');
    $processed = (int) ($state['processed_directories'] ?? 0);
    $total = (int) ($state['discovered_directories'] ?? 0);
    $candidateCount = (int) ($state['candidate_count'] ?? 0);
    $metadataOnlyCount = (int) ($state['metadata_only_count'] ?? 0);
    $message = (string) ($state['message'] ?? '');

    if ($message === '') {
        if ($status === 'complete') {
            if ($candidateCount > 0) {
                $message = t('admin.galleries.discovery_done_with_candidates', 'Discovery complete. Found {count} folder(s) that need a decision.', ['count' => (string) $candidateCount]);
            } elseif ($metadataOnlyCount > 0) {
                $message = t('admin.galleries.discovery_done_metadata_only', 'Discovery complete. No importable photo folders found. Ignored {count} metadata-only folder(s).', ['count' => (string) $metadataOnlyCount]);
            } else {
                $message = t('admin.galleries.discover_none_found');
            }
        } elseif ($status === 'missing') {
            $message = t('admin.galleries.discovery_missing_job', 'Discovery progress expired. Start the scan again.');
        } elseif ($status === 'error') {
            $message = t('admin.galleries.discovery_failed', 'Gallery discovery failed.');
        } else {
            $message = t('admin.galleries.discovery_running', 'Scanning gallery folders...');
        }
    }

    $payload = [
        'ok' => !empty($state['ok']),
        'status' => $status,
        'done' => !empty($state['done']),
        'job_token' => (string) ($state['job_token'] ?? ''),
        'processed_directories' => $processed,
        'discovered_directories' => $total,
        'queued_directories' => (int) ($state['queued_directories'] ?? 0),
        'candidate_count' => $candidateCount,
        'metadata_only_count' => $metadataOnlyCount,
        'percent' => (float) ($state['percent'] ?? 0.0),
        'message' => $message,
        'result_url' => url_for('admin_discover', ['job_token' => (string) ($state['job_token'] ?? '')]),
        'errors' => is_array($state['errors'] ?? null) ? $state['errors'] : [],
    ];

    if (is_array($state['candidates'] ?? null)) {
        $payload['candidates'] = $state['candidates'];
    }

    return $payload;
}

/**
 * Emit a JSON response for Admin discovery endpoints.
 *
 * @param array<string, mixed> $payload Payload written to the browser.
 */
function admin_gallery_discovery_json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Render the dynamic discovery shell used by the browser-side progress module.
 *
 * @param string $jobToken Existing completed or running job token from the query string.
 */
function render_admin_gallery_discovery_shell(string $jobToken = ''): void
{
    echo '<section class="panel admin-discovery-panel" data-admin-discovery-panel data-discovery-endpoint="' . e(url_for('admin_discover')) . '" data-import-url="' . e(url_for('admin_import')) . '" data-csrf-token="' . e(csrf_token()) . '" data-job-token="' . e($jobToken) . '">';
    echo '<p class="muted">' . e(t('admin.galleries.discovery_intro', 'Discovery now runs in browser-driven batches, so large gallery folders no longer freeze the Admin page.')) . '</p>';
    echo '<div class="thumbnail-progress" data-admin-discovery-progress hidden><progress class="thumbnail-progress-bar" max="100" value="0" data-admin-discovery-progress-bar></progress><p class="muted" data-admin-discovery-status>' . e(t('admin.galleries.discovery_starting', 'Preparing gallery discovery...')) . '</p><p class="muted" data-admin-discovery-counts></p></div>';
    echo '<template data-admin-gallery-move-options><option value="">' . e(t('admin.galleries.discover_move_target_placeholder', 'Choose existing destination gallery')) . '</option>' . gallery_options_for_select(0) . '</template>';
    echo '<div data-admin-discovery-results></div>';
    echo '</section>';
}

/**
 * Handles cms admin import logic for the gallery application.
 */
function cms_admin_import(): void
{
    require_admin();
    verify_csrf();

    // $action stores the requested discovery follow-up operation from the dynamic table.
    $action = (string) ($_POST['discovery_action'] ?? 'import_in_place');
    // $wantsJson stores whether the browser-side thumbnail job expects a JSON result.
    $wantsJson = !empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    if ($wantsJson) {
        // $result stores the selected discovery action result returned to JavaScript.
        $result = admin_gallery_discovery_import_action_result($action, $_POST, false);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    // $result stores the selected discovery action result displayed after redirect.
    $result = admin_gallery_discovery_import_action_result($action, $_POST, !empty($_POST['create_thumbnails']));
    admin_gallery_discovery_flash_action_result($result);
    redirect_to(url_for('admin'));
}

/**
 * Execute the selected discovery follow-up action.
 *
 * @param string $action Posted discovery action name.
 * @param array<string, mixed> $post Posted form data.
 * @param bool $createThumbnails Create thumbnails during the synchronous non-Ajax import path.
 * @return array<string, mixed> Action result for flash messages or JSON.
 */
function admin_gallery_discovery_import_action_result(string $action, array $post, bool $createThumbnails): array
{
    // $folders stores selected discovered folder paths from the browser table.
    $folders = is_array($post['folders'] ?? null) ? $post['folders'] : [];

    if ($action === 'delete_from_disk') {
        return admin_gallery_discovery_delete_requested_paths($folders);
    }

    if ($action === 'move_photos') {
        return admin_gallery_discovery_move_requested_photos($folders, (int) ($post['target_gallery_id'] ?? 0));
    }

    // $result stores the legacy in-place import result.
    $result = $createThumbnails
        ? import_galleries($folders, true)
        : import_galleries_without_thumbnails($folders);
    $result['ok'] = true;
    $result['action'] = 'import_in_place';
    if (!isset($result['gallery_ids'])) {
        $result['gallery_ids'] = [];
    }
    return $result;
}

/**
 * Store a human-readable flash message for a discovery follow-up action.
 *
 * @param array<string, mixed> $result Action result returned by the service layer.
 */
function admin_gallery_discovery_flash_action_result(array $result): void
{
    if (empty($result['ok']) && !empty($result['error'])) {
        flash_message('admin_notice', (string) $result['error']);
        return;
    }

    $action = (string) ($result['action'] ?? 'import_in_place');
    if ($action === 'delete_from_disk') {
        flash_message('admin_notice', t('admin.galleries.discover_delete_result', 'Deleted {folders} folder(s) and {files} file(s) from disk. Skipped {skipped} item(s).', [
            'folders' => (string) (int) ($result['deleted_folders'] ?? 0),
            'files' => (string) (int) ($result['deleted_files'] ?? 0),
            'skipped' => (string) (int) ($result['skipped'] ?? 0),
        ]));
        return;
    }

    if ($action === 'move_photos') {
        flash_message('admin_notice', t('admin.galleries.discover_move_result', 'Moved {moved} photo file(s), scanned {images} image(s) into the destination gallery, and removed {folders} empty source folder(s).', [
            'moved' => (string) (int) ($result['moved'] ?? 0),
            'images' => (string) (int) ($result['scanned'] ?? 0),
            'folders' => (string) (int) ($result['source_folders_cleaned'] ?? 0),
        ]));
        return;
    }

    flash_message('admin_notice', t('admin.galleries.import_result', 'Imported {galleries} gallery folder(s), scanned {images} image(s), and created {thumbnails} thumbnail(s).', [
        'galleries' => (string) (int) ($result['imported'] ?? 0),
        'images' => (string) (int) ($result['scanned'] ?? 0),
        'thumbnails' => (string) (int) ($result['thumbnails'] ?? 0),
    ]));
}

/**
 * Handles cms admin new gallery logic for the gallery application.
 */
function cms_admin_new_gallery(): void
{
    require_admin();
    // $prefillParentId stores the gallery that should be pre-selected when this form is opened from a public gallery page.
    $prefillParentId = selected_gallery_id_from_query('parent_id');
    // $prefillParentGallery stores the validated parent gallery record used for contextual helper text.
    $prefillParentGallery = $prefillParentId > 0 ? find_gallery($prefillParentId) : null;
    // $isPanelRequest stores whether the create form should render as a reusable side-panel fragment.
    $isPanelRequest = admin_gallery_create_panel_request();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = admin_create_gallery_from_input($_POST);
            if (admin_wants_json()) {
                header('Content-Type: application/json');
                echo json_encode(admin_new_gallery_success_response($gallery));
                return;
            }
            flash_message('admin_notice', t('admin.galleries.folder_created'));
            redirect_to(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]));
        } catch (Throwable $exception) {
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
            admin_log_event('error', 'gallery.folder_create_failed', t('admin.galleries.log_empty_folder_failed'), ['error' => $error]);
            if (admin_wants_json()) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $error]);
                return;
            }
        }
    }

    if ($isPanelRequest) {
        header('Content-Type: text/html; charset=UTF-8');
        render_admin_new_gallery_side_panel($prefillParentId, $prefillParentGallery, $error);
        return;
    }

    render_header(t('admin.galleries.create_empty_title'));
    echo '<section class="hero"><h1>' . e(t('admin.galleries.create_empty_title')) . '</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a><a class="button secondary" href="' . e(url_for('admin_upload')) . '">' . e(t('admin.upload.title')) . '</a></nav></section>';
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.galleries.create_inside_notice', 'New gallery will be created inside: {gallery}.', ['gallery' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="panel"><form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="form-grid">' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, false);
    echo '<button type="submit">' . e(t('admin.galleries.create_folder_button')) . '</button></form></section>';
    render_footer();
}

/**
 * Return whether the create-gallery page is being requested as side-panel content.
 *
 * @return bool True when the condition matches.
 */
function admin_gallery_create_panel_request(): bool
{
    return admin_side_panel_request();
}

/**
 * Return whether the current admin route is being requested for side-panel use.
 *
 * @return bool True when the condition matches.
 */
function admin_side_panel_request(): bool
{
    return !empty($_GET['panel']) || !empty($_POST['panel']);
}

/**
 * Normalize create-gallery input for every admin workflow.
 *
 * @param array $input Input value.
 * @return array Structured result data for the caller.
 */
function admin_new_gallery_input_from_array(array $input): array
{
    // $normalized stores the create-gallery input contract used by all admin workflows.
    $normalized = [
        'title' => $input['title'] ?? '',
        'folder_name' => $input['folder_name'] ?? '',
        'description' => $input['description'] ?? '',
        'gallery_date' => $input['gallery_date'] ?? '',
        'gallery_date_end' => $input['gallery_date_end'] ?? '',
        'visibility' => gallery_visibility_storage_value((string) ($input['visibility'] ?? 'unpublished')),
        'parent_id' => $input['parent_id'] ?? 0,
        'voting_enabled' => $input['voting_enabled'] ?? 0,
        'show_filenames' => $input['show_filenames'] ?? 0,
        'count_badge_visibility' => $input['count_badge_visibility'] ?? 'inherit',
    ];
    if (array_key_exists('sort_order', $input)) {
        $normalized['sort_order'] = $input['sort_order'];
    }
    return $normalized;
}

/**
 * Read create-gallery POST values through the same input contract used by the direct admin page.
 *
 * @return array Structured result data for the caller.
 */
function admin_new_gallery_input_from_post(): array
{
    return admin_new_gallery_input_from_array($_POST);
}

/**
 * Create a gallery through the shared admin create implementation.
 *
 * @param array $input Input value.
 * @return array Structured result data for the caller.
 */
function admin_create_gallery_from_input(array $input): array
{
    // $gallery stores the persisted gallery returned by the service layer.
    $gallery = create_empty_gallery(admin_new_gallery_input_from_array($input));
    admin_log_event('info', 'gallery.folder_created', t('admin.galleries.log_empty_folder_created'), [
        'gallery_id' => (int) $gallery['id'],
        'folder_path' => (string) $gallery['folder_path'],
    ]);
    return $gallery;
}

/**
 * Build the JSON payload consumed by the progressive side-panel workflow.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function admin_new_gallery_success_response(array $gallery): array
{
    // $parentGalleryId stores the persisted parent selected by the admin create form.
    $parentGalleryId = (int) ($gallery['parent_id'] ?? 0);
    // $parentGallery stores the parent row used by the side-panel refresh contract.
    $parentGallery = $parentGalleryId > 0 ? find_gallery($parentGalleryId, true) : null;
    // $parentGalleryUrl stores the public parent URL when the gallery was created below another gallery.
    $parentGalleryUrl = is_array($parentGallery) ? gallery_public_url($parentGallery) : '';

    return [
        'ok' => true,
        'message' => t('admin.galleries.folder_created'),
        'gallery_id' => (int) $gallery['id'],
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'gallery_url' => gallery_public_url($gallery),
        'edit_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'created' => 1]),
        'parent_gallery_id' => $parentGalleryId,
        'parent_gallery_url' => $parentGalleryUrl,
        'refresh_url' => $parentGalleryUrl !== '' ? $parentGalleryUrl : url_for('home'),
        'refresh_gallery_id' => $parentGalleryId,
    ];
}

/**
 * Render compact Markdown formatting guidance for gallery description fields.
 */
function render_gallery_description_formatting_hint(): void
{
    if (function_exists('Gallery\\Views\\view_render_gallery_description_formatting_hint')) {
        view_render_gallery_description_formatting_hint();
        return;
    }
    echo '<details class="gallery-description-format-help"><summary><span aria-hidden="true">💡</span><span>' . e(t('admin.gallery_editor.description_format_hints', 'Formatting hints')) . '</span></summary><div class="gallery-description-format-help-popover">';
    echo '<p>' . e(t('admin.gallery_editor.description_format_intro', 'Basic Markdown is supported in public gallery descriptions.')) . '</p>';
    echo '<ul>';
    echo '<li><code>**' . e(t('admin.gallery_editor.description_format_bold_word', 'bold')) . '**</code> ' . e(t('admin.gallery_editor.description_format_bold', 'makes bold text')) . '</li>';
    echo '<li><code>*' . e(t('admin.gallery_editor.description_format_italic_word', 'italic')) . '*</code> ' . e(t('admin.gallery_editor.description_format_italic', 'makes italic text')) . '</li>';
    echo '<li><code>`code`</code> ' . e(t('admin.gallery_editor.description_format_code', 'uses inline code styling')) . '</li>';
    echo '<li><code>[Link](https://example.com)</code> ' . e(t('admin.gallery_editor.description_format_link', 'creates a safe external link')) . '</li>';
    echo '<li>' . e(t('admin.gallery_editor.description_format_newlines', 'A single Enter is preserved as a new line. Empty lines create separate paragraphs.')) . '</li>';
    echo '</ul></div></details>';
}

/**
 * Render create-gallery fields shared by full admin pages and panel fragments.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 * @param bool $panelMode Panel mode value.
 * @param string $workflow Workflow value.
 */
function render_admin_new_gallery_fields(int $prefillParentId, bool $panelMode, string $workflow = 'create'): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_new_gallery_fields')) {
        view_render_admin_new_gallery_fields($prefillParentId, $panelMode, $workflow);
        return;
    }
    // $isUploadWorkflow stores whether the shared create fields are embedded in the upload workflow.
    $isUploadWorkflow = $workflow === 'upload';
    if ($panelMode) {
        echo '<input type="hidden" name="panel" value="1">';
    }
    if ($panelMode) {
        $panelHelp = $isUploadWorkflow ? t('admin.upload.gallery_identity_help', 'Create an empty gallery, or select photos and upload them immediately.') : t('admin.gallery_editor.only_gallery_created_here', 'Only the gallery is created here.');
        $panelKicker = $isUploadWorkflow ? t('admin.upload.new_child_gallery', 'New child gallery') : t('admin.gallery_editor.new_gallery_kicker', 'New gallery');
        echo '<div class="admin-side-panel-card admin-side-panel-primary-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e($panelKicker) . '</p><h3>' . e(t('admin.gallery_editor.gallery_identity', 'Gallery identity')) . '</h3></div><p class="muted">' . e($panelHelp) . '</p></div><div class="admin-side-panel-field-grid">';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span><input name="title" required></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '</span><input name="folder_name" autocomplete="off"><small>' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.metric_visibility')) . '</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        if (function_exists('Gallery\\Views\\view_render_admin_gallery_date_range_fields')) {
            view_render_admin_gallery_date_range_fields([], true);
        } elseif (gallery_date_schema_ready()) {
            echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><input name="gallery_date" type="date"><small>' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</small></label>';
        } else {
            echo '<div class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><small>' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</small></div>';
        }
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '</span><select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.description', 'Description')) . '</span><textarea name="description" rows="4"></textarea></label>';
        render_gallery_description_formatting_hint();
        echo '</div><div class="admin-side-panel-toggle-row">';
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>' . e(t('admin.gallery_editor.enable_image_voting_short', 'Enable image voting')) . '</span></label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</span></label>';
        echo '</div></div>';
        if (gallery_count_badge_schema_ready()) {
            echo '<div class="admin-side-panel-card"><label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
            foreach (gallery_count_badge_override_values() as $countBadgeOption) {
                echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
            }
            echo '</select><small>' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</small></label></div>';
        }
        return;
    }
    echo '<label>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '<input name="title" required></label>';
    echo '<label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" autocomplete="off"><span class="muted">' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</span></label>';
    echo '<label>' . e(t('admin.gallery_editor.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    if (function_exists('Gallery\\Views\\view_render_admin_gallery_date_range_fields')) {
        view_render_admin_gallery_date_range_fields([], false);
    } elseif (gallery_date_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
    } else {
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
    }
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label>';
    if (gallery_count_badge_schema_ready()) {
        echo '<label>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '<select name="count_badge_visibility">';
        foreach (gallery_count_badge_override_values() as $countBadgeOption) {
            echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</span></label>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description"></textarea></label>';
    render_gallery_description_formatting_hint();
}

/**
 * Render the focused side-panel create workflow without the normal admin shell.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 * @param ?array $prefillParentGallery Prefill parent gallery value.
 * @param string $error Error value.
 */
function render_admin_new_gallery_side_panel(int $prefillParentId, ?array $prefillParentGallery, string $error): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_new_gallery_side_panel')) {
        view_render_admin_new_gallery_side_panel($prefillParentId, $prefillParentGallery, $error);
        return;
    }
    echo '<div class="admin-side-panel-stack" data-gallery-create-panel>';
    echo '<div class="admin-side-panel-copy"><p class="admin-kicker">' . e(t('admin.gallery_editor.gallery_workflow', 'Gallery workflow')) . '</p><h2>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</h2><p class="muted">' . e(t('admin.gallery_editor.create_gallery_empty_help', 'Create a new empty gallery in the selected parent. Photo upload stays in the separate upload workflow.')) . '</p></div>';
    if ($prefillParentGallery) {
        echo '<div class="notice">' . e(t('admin.gallery_editor.target_parent', 'Target parent: {title}.', ['title' => (string) $prefillParentGallery['title']])) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="admin-side-panel-form" data-gallery-panel-create-form>' . csrf_field();
    render_admin_new_gallery_fields($prefillParentId, true);
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</button><p class="muted">' . e(t('admin.gallery_editor.new_gallery_empty_help', 'The new gallery is created empty. Use Upload photos for media.')) . '</p></div>';
    echo '</form></section>';
    echo '</div>';
}
