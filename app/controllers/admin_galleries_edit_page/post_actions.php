<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/post_actions.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles every POST action submitted from the gallery editor.
 *
 * Responsibilities:
 *   - Verify CSRF with a JSON-aware path for media-renamer AJAX requests
 *   - Dispatch named editor actions before the generic gallery save
 *   - Return the canonical mutation envelope for panel requests and redirect otherwise
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Every path either returns or calls redirect_to(), which never returns.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\ai_image_analysis_force_gallery_reprocess;
use function Gallery\Services\ai_image_analysis_schema_ready;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_lightbox_state_summary;
use function Gallery\Services\media_renamer_execute_gallery;
use function Gallery\Services\media_renamer_normalize_pattern;
use function Gallery\Services\smart_gallery_assign_children_to_gallery;
use function Gallery\Services\smart_gallery_validate_children_assignment;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

/**
 * Handle one gallery editor POST request.
 *
 * The caller returns immediately afterwards. Every branch either returns or
 * ends in redirect_to(), so this never falls through to page rendering.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_handle_post(array $gallery, array $capabilities): void
{
    // Media-renamer AJAX requests need JSON CSRF failures and diagnostics.
    // The normal verifier exits with plain text, which makes fetch() report
    // a confusing non-JSON response and leaves the admin without a log row.
    $isMediaRenamerPost = (string) ($_POST['action'] ?? '') === 'rename_files';
    if ($isMediaRenamerPost && function_exists('Gallery\\Controllers\\admin_media_renamer_verify_csrf_for_ajax')) {
        if (!admin_media_renamer_verify_csrf_for_ajax()) {
            return;
        }
    } else {
        verify_csrf();
    }
    // $returnTab stores the tab fragment used after saving the gallery editor form.
    $returnTab = admin_return_tab_from_post('admin-edit-identity');
    if ((string) ($_POST['action'] ?? '') === 'apply_exif_date_suggestion') {
        admin_apply_gallery_date_exif_suggestion($gallery);
        return;
    }
    if ((string) ($_POST['action'] ?? '') === 'apply_metadata_organizer_date_plan_batch') {
        admin_apply_gallery_metadata_organizer_date_plan_batch($gallery);
        return;
    }
    if ((string) ($_POST['action'] ?? '') === 'apply_metadata_organizer_date_plan') {
        admin_apply_gallery_metadata_organizer_date_plan($gallery, $returnTab);
        return;
    }
    if ((string) ($_POST['action'] ?? '') === 'cover' && isset($_POST['image_ids'])) {
        admin_save_gallery_title_picture($gallery, array_map('intval', $_POST['image_ids'] ?? []), $returnTab);
        return;
    }
    if ((string) ($_POST['action'] ?? '') === 'force_ai_reprocess') {
        admin_edit_gallery_handle_ai_reprocess($gallery);
        return;
    }
    if ((string) ($_POST['action'] ?? '') === 'rename_files') {
        admin_edit_gallery_handle_media_rename($gallery, (bool) $capabilities['media_renamer_feature_enabled']);
        return;
    }
    admin_edit_gallery_handle_save($gallery, $returnTab);
}

/**
 * Reset and re-queue AI image metadata for one gallery branch.
 *
 * Refuses when the feature is disabled or the AI analysis schema is not
 * available, using the canonical error envelope for panel requests.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 */
function admin_edit_gallery_handle_ai_reprocess(array $gallery): void
{
    $aiEditUrl = admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-api');
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('ai_image_metadata')) {
        $message = t('admin.gallery_editor.ai_reprocess_disabled', 'AI metadata is disabled in Admin > Features.');
        if (admin_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(admin_mutation_error_envelope($message, 'ai_metadata_disabled', null, ['redirect_url' => $aiEditUrl]), JSON_THROW_ON_ERROR);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to($aiEditUrl);
    }
    if (!function_exists('Gallery\\Services\\ai_image_analysis_force_gallery_reprocess') || !ai_image_analysis_schema_ready()) {
        $message = t('admin.gallery_editor.ai_reprocess_unavailable', 'AI metadata reset will be available after the AI image-analysis migration is applied.');
        if (admin_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(admin_mutation_error_envelope($message, 'ai_metadata_unavailable', null, ['redirect_url' => $aiEditUrl]), JSON_THROW_ON_ERROR);
            return;
        }
        flash_message('admin_notice', $message);
        redirect_to($aiEditUrl);
    }
    $resetResult = ai_image_analysis_force_gallery_reprocess((int) $gallery['id']);
    $message = t('admin.gallery_editor.ai_reprocess_queued', 'AI metadata reset for {images} photo(s) across {galleries} gallery node(s). Removed {metadata} metadata row(s), removed {jobs} old queue row(s), and queued {queued} fresh job(s). The next AI worker poll will claim them.', [
        'images' => (int) ($resetResult['images'] ?? 0),
        'galleries' => (int) ($resetResult['galleries'] ?? 0),
        'metadata' => (int) ($resetResult['metadata_deleted'] ?? 0),
        'jobs' => (int) ($resetResult['jobs_deleted'] ?? 0),
        'queued' => (int) ($resetResult['jobs_queued'] ?? 0),
    ]);
    if (admin_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(
            admin_mutation_success_envelope(
                $message,
                admin_mutation_descriptor('image.ai_metadata_reprocess', 'image', 'queue', []),
                admin_mutation_panel_metadata('gallery-edit', $aiEditUrl, true),
                [],
                ['redirect_url' => $aiEditUrl]
            ),
            [
                'background_queued' => true,
                'queue_result' => $resetResult,
                'edit_url' => $aiEditUrl,
            ]
        ), JSON_THROW_ON_ERROR);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to($aiEditUrl);
}

/**
 * Apply physical media renames for one gallery.
 *
 * Requires an explicit confirmation because the rename touches files on disk.
 * Failures are logged and reported without leaving the panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param bool $mediaRenamerFeatureEnabled Whether the renamer feature is enabled.
 */
function admin_edit_gallery_handle_media_rename(array $gallery, bool $mediaRenamerFeatureEnabled): void
{
    if (!$mediaRenamerFeatureEnabled) {
        flash_message('admin_notice', t('admin.media_renamer.feature_disabled', 'Media renamer is disabled in Admin > Features.'));
        redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], 'admin-edit-media'));
    }
    $renamerPattern = media_renamer_normalize_pattern((string) ($_POST['renamer_pattern'] ?? ''));
    $renamerReturnUrl = admin_edit_gallery_tab_url_with_renamer_pattern((int) $gallery['id'], $renamerPattern);
    $renameResult = null;
    if (empty($_POST['confirm_media_rename'])) {
        $notice = t('admin.media_renamer.confirm_required', 'Confirm that you reviewed the preview before applying physical renames.');
        if (admin_wants_json() && function_exists('Gallery\\Controllers\\admin_media_renamer_render_gallery_panel_html')) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => $notice,
                'panel_html' => admin_media_renamer_render_gallery_panel_html($gallery, $renamerPattern, $notice),
            ]);
            return;
        }
        flash_message('admin_notice', $notice);
        redirect_to($renamerReturnUrl);
    }
    try {
        $renameResult = media_renamer_execute_gallery((int) $gallery['id'], $renamerPattern);
        if (function_exists('Gallery\\Controllers\\admin_media_renamer_log_event')) {
            $completionSeverity = function_exists('Gallery\\Controllers\\admin_media_renamer_result_log_severity') ? admin_media_renamer_result_log_severity($renameResult) : 'info';
            admin_media_renamer_log_event($completionSeverity === 'warning' ? 'warning' : 'info', 'media_renamer.gallery_completed', 'Gallery media rename completed.', [
                'gallery_id' => (int) $gallery['id'],
                'gallery_path' => (string) ($gallery['folder_path'] ?? ''),
                'pattern' => $renamerPattern,
                'result' => function_exists('Gallery\\Controllers\\admin_media_renamer_loggable_result') ? admin_media_renamer_loggable_result($renameResult) : $renameResult,
            ], ['category' => 'media', 'severity' => $completionSeverity]);
        }
        $notice = t('admin.media_renamer.gallery_result_notice', 'Renamed {renamed} file(s), invalidated {derivatives} generated derivative cache file(s), saw {missing} missing file(s), skipped {skipped} row(s), updated {titles} derived title(s), and removed {archives} stale ZIP archive row(s).', [
            'renamed' => (string) (int) ($renameResult['renamed'] ?? 0),
            'derivatives' => (string) ((int) ($renameResult['derivatives_moved'] ?? 0) + (int) ($renameResult['derivatives_cleaned'] ?? 0)),
            'missing' => (string) (int) ($renameResult['missing'] ?? 0),
            'skipped' => (string) ((int) ($renameResult['skipped'] ?? 0) + (int) ($renameResult['collisions'] ?? 0)),
            'archives' => (string) (int) ($renameResult['zip_archives_deleted'] ?? 0),
            'titles' => (string) (int) ($renameResult['titles_updated'] ?? 0),
        ]);
        if ((int) ($renameResult['derivative_failures'] ?? 0) > 0) {
            $notice .= ' ' . t('admin.media_renamer.gallery_derivative_warnings', 'Generated derivative warnings: {count}.', [
                'count' => (string) (int) ($renameResult['derivative_failures'] ?? 0),
            ]);
        }
        $renameWarnings = array_slice(array_map('strval', (array) ($renameResult['warnings'] ?? [])), 0, 5);
        if ($renameWarnings) {
            $notice .= ' ' . t('admin.media_renamer.gallery_warnings', 'Warnings: {warnings}', [
                'warnings' => implode(' | ', $renameWarnings),
            ]);
        }
    } catch (Throwable $exception) {
        $notice = $exception->getMessage();
        if (function_exists('Gallery\\Controllers\\admin_media_renamer_log_exception')) {
            admin_media_renamer_log_exception('media_renamer.gallery_failed', 'Gallery media rename failed.', $exception, [
                'gallery_id' => (int) $gallery['id'],
                'gallery_path' => (string) ($gallery['folder_path'] ?? ''),
                'pattern' => $renamerPattern,
            ]);
        } elseif (function_exists('Gallery\\Services\\admin_log_event')) {
            admin_log_event('error', 'media_renamer.gallery_failed', 'Gallery media rename failed.', [
                'gallery_id' => (int) $gallery['id'],
                'error' => $exception->getMessage(),
            ], ['category' => 'media', 'severity' => 'error']);
        }
    }
    $updatedGallery = find_gallery((int) $gallery['id'], true) ?: $gallery;
    if (admin_wants_json() && function_exists('Gallery\Controllers\admin_media_renamer_render_gallery_panel_html')) {
        header('Content-Type: application/json');
        // $renamedImageIds stores only rows whose persisted filename/path changed.
        $renamedImageIds = [];
        foreach ((array) ($renameResult['details'] ?? []) as $detail) {
            if ((string) ($detail['status'] ?? '') !== 'renamed') {
                continue;
            }
            $imageId = (int) ($detail['image_id'] ?? 0);
            if ($imageId > 0 && !in_array($imageId, $renamedImageIds, true)) {
                $renamedImageIds[] = $imageId;
            }
        }
        // $mediaRenameContexts stays empty when the execution was a true no-op.
        $mediaRenameContexts = [];
        if ($renameResult !== null && $renamedImageIds !== []) {
            $imageState = gallery_lightbox_state_summary($updatedGallery, false, false);
            $revision = trim((string) ($imageState['revision'] ?? ''));
            $mediaRenameContexts[] = \Gallery\Core\admin_mutation_public_gallery_context(
                (int) $updatedGallery['id'],
                gallery_public_url($updatedGallery),
                $revision !== ''
                    ? admin_mutation_postcondition('gallery_image_revision', [
                        'gallery_id' => (int) $updatedGallery['id'],
                        'revision' => $revision,
                    ])
                    : null
            );
        }
        $payload = $renameResult !== null ? admin_mutation_success_envelope(
            $notice,
            admin_mutation_descriptor('image.media_rename', 'image', 'rename', $renamedImageIds),
            admin_mutation_panel_metadata('media-renamer', $renamerReturnUrl, true),
            $mediaRenameContexts,
            ['redirect_url' => $renamerReturnUrl]
        ) : admin_mutation_error_envelope($notice, 'media_rename_failed', null, ['redirect_url' => $renamerReturnUrl]);
        echo json_encode(array_merge($payload, [
            'message' => $notice,
            'panel_html' => admin_media_renamer_render_gallery_panel_html($updatedGallery, $renamerPattern, $notice, $renameResult),
            'gallery_id' => (int) $updatedGallery['id'],
        ]));
        return;
    }
    flash_message('admin_notice', $notice);
    redirect_to($renamerReturnUrl);
}

/**
 * Persist the generic gallery editor form.
 *
 * Smart Gallery attachment schema and graph validation run before any gallery
 * mutation so an invalid relationship cannot be partially applied.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $returnTab Tab fragment to return to after saving.
 */
function admin_edit_gallery_handle_save(array $gallery, string $returnTab): void
{
    try {
        // Preflight Smart Gallery attachment schema and graph validation before any gallery mutation.
        $smartGalleryChildrenInput = (array) ($_POST['smart_gallery_children'] ?? []);
        if (isset($_POST['smart_gallery_children_present'])) {
            $proposedSmartGalleryParentId = (int) ($_POST['parent_id'] ?? 0);
            if ($proposedSmartGalleryParentId > 0 && !find_gallery($proposedSmartGalleryParentId)) $proposedSmartGalleryParentId = 0;
            smart_gallery_validate_children_assignment((int) $gallery['id'], $smartGalleryChildrenInput, $proposedSmartGalleryParentId > 0 ? $proposedSmartGalleryParentId : null, true);
        }
        // $saveResult stores the shared gallery save outcome used by both page and panel workflows.
        $saveResult = admin_save_gallery_from_input($gallery, $_POST, $_FILES, $returnTab, true);
        if (isset($_POST['smart_gallery_children_present'])) {
            smart_gallery_assign_children_to_gallery((int) $gallery['id'], $smartGalleryChildrenInput);
        }
    } catch (Throwable $exception) {
        if (admin_wants_json()) {
            admin_panel_error_response($exception->getMessage());
            return;
        }
        $_SESSION['admin_gallery_error_' . (int) $gallery['id']] = $exception->getMessage();
        flash_message('admin_notice', $exception->getMessage());
        redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
    }
    // $gallery stores the saved gallery row from the shared persistence helper.
    $gallery = $saveResult['gallery'] ?? $gallery;
    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = (string) ($saveResult['notice'] ?? t('admin.gallery_editor.notice_saved', 'Gallery saved.'));
    if (admin_wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(admin_edit_gallery_success_response($gallery, $notice, $returnTab, $saveResult));
        return;
    }
    flash_message('admin_notice', $notice);
    redirect_to(admin_edit_gallery_tab_url((int) $gallery['id'], $returnTab));
}
