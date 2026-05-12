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

/**
 * Admin upload controller model.
 * 
 * This module owns the admin upload endpoint and JSON response detection used by asynchronous upload flows.
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
        redirect_to(url_for('admin_login'));
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // $wantsJson stores an intermediate value used by the surrounding gallery workflow.
        $wantsJson = admin_wants_json();
        if ($wantsJson) {
            ob_start();
        }
        try {
            // $mode stores an intermediate value used by the surrounding gallery workflow.
            $mode = (string) ($_POST['upload_mode'] ?? 'existing');
            // $entries stores an intermediate value used by the surrounding gallery workflow.
            $entries = $mode === 'new' ? gallery_upload_entries_or_empty($_FILES['images'] ?? null) : gallery_upload_entries($_FILES['images'] ?? null);
            if ($mode === 'new') {
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = create_empty_gallery([
                    'title' => $_POST['title'] ?? '',
                    'folder_name' => $_POST['folder_name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'visibility' => gallery_visibility_storage_value((string) ($_POST['visibility'] ?? 'unpublished')),
                    'gallery_date' => $_POST['gallery_date'] ?? '',
                    'parent_id' => $_POST['parent_id'] ?? 0,
                    'voting_enabled' => $_POST['voting_enabled'] ?? 0,
                    'show_filenames' => $_POST['show_filenames'] ?? 0,
                    'count_badge_visibility' => $_POST['count_badge_visibility'] ?? 'inherit',
                ]);
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
            // $response stores an intermediate value used by the surrounding gallery workflow.
            $response = [
                'ok' => true,
                'gallery_id' => (int) $gallery['id'],
                'gallery_ids' => [(int) $gallery['id']],
                'gallery_title' => (string) ($gallery['title'] ?? ''),
                'gallery_url' => gallery_public_url($gallery),
                'edit_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned']]),
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
 */
function admin_upload_accept_value(): string
{
    // $acceptTypes stores an intermediate value used by the surrounding gallery workflow.
    $acceptTypes = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
    if (heic_conversion_supported()) {
        $acceptTypes[] = '.heic';
        $acceptTypes[] = '.heif';
    }
    if (raw_conversion_supported()) {
        $acceptTypes[] = '.dng';
    }
    $acceptTypes[] = 'image/*';
    return implode(',', $acceptTypes);
}

/**
 * Render the upload capability table used by the full admin upload page.
 */
function render_admin_upload_support_panel(): void
{
    // $heicSupported stores an intermediate value used by the surrounding gallery workflow.
    $heicSupported = heic_conversion_supported();
    // $rawSupported stores an intermediate value used by the surrounding gallery workflow.
    $rawSupported = raw_conversion_supported();
    echo '<section class="panel compact-support"><h2>' . e(t('admin.upload.support_title', 'Upload support')) . '</h2><table class="support-matrix"><thead><tr><th>' . e(t('admin.upload.type', 'Type')) . '</th><th>JPG</th><th>PNG</th><th>GIF</th><th>WebP</th><th>HEIC</th><th>DNG</th></tr></thead><tbody><tr>';
    echo '<th scope="row">' . e(t('admin.upload.available', 'Available')) . '</th>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="' . ($heicSupported ? 'support-yes' : 'support-no') . '">' . ($heicSupported ? '✓' : '✕') . '</td>';
    echo '<td class="' . ($rawSupported ? 'support-yes' : 'support-no') . '">' . ($rawSupported ? '✓' : '✕') . '</td>';
    echo '</tr></tbody></table></section>';
}

/**
 * Render the existing-gallery upload form without changing the upload endpoint.
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
    if ($panelMode) {
        echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.upload_images', 'Upload images')) . '</button><p class="muted">' . e(t('admin.upload.progress_top_panel', 'Progress appears at the top of this panel.')) . '</p></div>';
    } else {
        echo '<button type="submit">' . e(t('admin.upload.upload_images', 'Upload images')) . '</button>';
    }
    echo '</form></section>';
}

/**
 * Render the new-gallery upload form used by the direct admin upload page.
 */
function render_admin_upload_new_gallery_form(int $prefillParentId): void
{
    render_admin_upload_new_gallery_form_shell($prefillParentId, false);
}

/**
 * Render the new-gallery upload form used inside the public-page side panel.
 */
function render_admin_upload_new_gallery_panel_form(int $prefillParentId): void
{
    render_admin_upload_new_gallery_form_shell($prefillParentId, true);
}

/**
 * Render the shared create-and-upload form while preserving the existing upload route.
 */
function render_admin_upload_new_gallery_form_shell(int $prefillParentId, bool $panelMode): void
{
    // $acceptValue stores an intermediate value used by the surrounding gallery workflow.
    $acceptValue = admin_upload_accept_value();
    if (!$panelMode) {
        echo '<section class="panel"><h2>' . e(t('admin.upload.create_and_upload_title', 'Create gallery and upload photos')) . '</h2>';
        echo '<form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
        echo '<input type="hidden" name="upload_mode" value="new">';
        echo '<label>' . e(t('admin.upload.gallery_name', 'Gallery name')) . '<input name="title" required></label>';
        echo '<label>' . e(t('admin.upload.folder_name', 'Folder name')) . '<input name="folder_name" autocomplete="off"><span class="muted">' . e(t('admin.upload.folder_name_help', 'Leave empty to derive it from the gallery name.')) . '</span></label>';
        echo '<label>' . e(t('admin.upload.parent_gallery', 'Parent gallery')) . '<select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.upload.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
        echo '<label>' . e(t('admin.upload.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        if (gallery_date_schema_ready()) {
            echo '<label>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
        } else {
            echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
        }
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> ' . e(t('admin.upload.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> ' . e(t('admin.upload.show_file_names', 'Show file names')) . '</label>';
        if (gallery_count_badge_schema_ready()) {
            echo '<label>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '<select name="count_badge_visibility">';
            foreach (gallery_count_badge_override_values() as $countBadgeOption) {
                echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
            }
            echo '</select><span class="muted">' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</span></label>';
        }
        echo '<label>' . e(t('admin.upload.description', 'Description')) . '<textarea name="description"></textarea></label>';
        echo '<label>' . e(t('admin.upload.images', 'Images')) . '<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required><span class="muted">' . e(t('admin.upload.choose_one_or_more_images', 'Choose one or more images.')) . '</span></label>';
        echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> ' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</label>';
        echo '<button type="submit">' . e(t('admin.upload.create_gallery_and_upload', 'Create gallery and upload')) . '</button></form></section>';
        return;
    }

    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<div class="admin-side-panel-progress-anchor" data-gallery-panel-progress-anchor></div>';
    echo '<form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="admin-side-panel-form" data-gallery-upload-form data-gallery-panel-close-on-success="1">' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="new">';
    echo '<input type="hidden" name="panel" value="1">';

    echo '<div class="admin-side-panel-card admin-side-panel-primary-card">';
    echo '<div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.new_child_gallery', 'New child gallery')) . '</p><h3>' . e(t('admin.upload.gallery_identity', 'Gallery identity')) . '</h3></div><p class="muted">' . e(t('admin.upload.gallery_identity_help', 'Create an empty gallery, or select photos and upload them immediately.')) . '</p></div>';
    echo '<div class="admin-side-panel-field-grid">';
    echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.upload.gallery_name', 'Gallery name')) . '</span><input name="title" required></label>';
    echo '<label class="admin-side-panel-field"><span>' . e(t('admin.upload.folder_name', 'Folder name')) . '</span><input name="folder_name" autocomplete="off"><small>' . e(t('admin.upload.folder_name_help', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
    echo '<label class="admin-side-panel-field"><span>' . e(t('admin.upload.visibility', 'Visibility')) . '</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    if (gallery_date_schema_ready()) {
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><input name="gallery_date" type="date"><small>' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</small></label>';
    } else {
        echo '<div class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><small>' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</small></div>';
    }
    echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.upload.parent_gallery', 'Parent gallery')) . '</span><select name="parent_id"><option value="0"' . ($prefillParentId === 0 ? ' selected' : '') . '>' . e(t('admin.upload.no_parent', 'No parent')) . '</option>' . gallery_parent_options_for_new($prefillParentId) . '</select></label>';
    echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.upload.description', 'Description')) . '</span><textarea name="description" rows="4"></textarea></label>';
    echo '</div>';
    echo '<div class="admin-side-panel-toggle-row">';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>' . e(t('admin.upload.enable_image_voting_short', 'Enable image voting')) . '</span></label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>' . e(t('admin.upload.show_file_names', 'Show file names')) . '</span></label>';
    echo '</div>';
    echo '</div>';

    if (gallery_count_badge_schema_ready()) {
        echo '<div class="admin-side-panel-card"><label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
        foreach (gallery_count_badge_override_values() as $countBadgeOption) {
            echo '<option value="' . e($countBadgeOption) . '"' . ($countBadgeOption === 'inherit' ? ' selected' : '') . '>' . e(gallery_count_badge_override_label($countBadgeOption)) . '</option>';
        }
        echo '</select><small>' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture icon and image count on this gallery card.')) . '</small></label></div>';
    }

    echo '<div class="admin-side-panel-card admin-side-panel-upload-card">';
    echo '<div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e(t('admin.upload.optional_photos', 'Optional photos')) . '</p><h3>' . e(t('admin.upload.upload_now', 'Upload now')) . '</h3></div><p class="muted">' . e(t('admin.upload.optional_photos_help', 'Leave this empty to create only the gallery.')) . '</p></div>';
    echo '<label class="admin-side-panel-file-drop"><span class="admin-side-panel-file-title">' . e(t('admin.upload.choose_images', 'Choose images')) . '</span><input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple><span class="muted">' . e(t('admin.upload.multiple_files_help', 'Multiple files are supported. The existing upload pipeline and thumbnail generation are reused.')) . '</span></label>';
    echo '<label class="admin-side-panel-thumbnail-toggle"><input type="checkbox" name="create_thumbnails" value="1" checked> <span>' . e(t('admin.upload.create_thumbnails_after_upload', 'Create optimized thumbnails after upload')) . '</span></label>';
    echo '</div>';

    echo '<div class="admin-side-panel-actions">';
    echo '<button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.upload.create_gallery', 'Create gallery')) . '</button>';
    echo '<p class="muted">' . e(t('admin.upload.progress_top_panel_during_upload', 'Progress appears at the top of this panel during upload.')) . '</p>';
    echo '</div>';
    echo '</form></section>';
}

/**
 * Handles admin wants json logic for the gallery application.
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

