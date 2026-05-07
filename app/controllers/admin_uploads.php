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
            echo json_encode(['ok' => false, 'error' => 'Your admin session expired. Please sign in again.']);
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
            // $entries stores an intermediate value used by the surrounding gallery workflow.
            $entries = gallery_upload_entries($_FILES['images'] ?? null);
            // $mode stores an intermediate value used by the surrounding gallery workflow.
            $mode = (string) ($_POST['upload_mode'] ?? 'existing');
            if ($mode === 'new') {
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = create_empty_gallery([
                    'title' => $_POST['title'] ?? '',
                    'folder_name' => $_POST['folder_name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'visibility' => gallery_visibility_storage_value((string) ($_POST['visibility'] ?? 'unpublished')),
                    'parent_id' => $_POST['parent_id'] ?? 0,
                ]);
            } else {
                // $gallery stores an intermediate value used by the surrounding gallery workflow.
                $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
                if (!$gallery) {
                    throw new RuntimeException('Choose an existing gallery.');
                }
            }

            // $stored stores an intermediate value used by the surrounding gallery workflow.
            $stored = store_uploaded_gallery_images((int) $gallery['id'], $entries);
            // $thumbnails stores an intermediate value used by the surrounding gallery workflow.
            $thumbnails = 0;
            if (!$wantsJson && !empty($_POST['create_thumbnails'])) {
                // $thumbnails stores an intermediate value used by the surrounding gallery workflow.
                $thumbnails = create_gallery_thumbnails((int) $gallery['id']);
            }
            admin_log_event('info', 'gallery.images_uploaded', 'Admin uploaded images into a gallery folder.', [
                'gallery_id' => (int) $gallery['id'],
                'folder_path' => (string) $gallery['folder_path'],
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
            ]);
            // $response stores an intermediate value used by the surrounding gallery workflow.
            $response = [
                'ok' => true,
                'gallery_id' => (int) $gallery['id'],
                'gallery_ids' => [(int) $gallery['id']],
                'image_ids' => array_map('intval', $stored['image_ids'] ?? []),
                'filenames' => array_values($stored['filenames'] ?? []),
                'uploaded' => (int) $stored['uploaded'],
                'scanned' => (int) $stored['scanned'],
                'thumbnails' => $thumbnails,
                'redirect_url' => url_for('admin_edit_gallery', ['id' => $gallery['id'], 'uploaded' => (int) $stored['uploaded'], 'scanned' => (int) $stored['scanned'], 'thumbnails' => $thumbnails]),
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
    // $prefillGallery stores the validated gallery record used for contextual helper text.
    $prefillGallery = $prefillGalleryId > 0 ? find_gallery($prefillGalleryId) : null;
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = (string) ($_SESSION['admin_upload_error'] ?? '');
    unset($_SESSION['admin_upload_error']);
    // $heicSupported stores an intermediate value used by the surrounding gallery workflow.
    $heicSupported = heic_conversion_supported();
    // $rawSupported stores an intermediate value used by the surrounding gallery workflow.
    $rawSupported = raw_conversion_supported();
    render_header('Upload photos');
    echo '<section class="hero"><h1>Upload photos</h1><nav class="nav"><a class="button secondary" href="' . e(url_for('admin')) . '">Back to dashboard</a><a class="button secondary" href="' . e(url_for('admin_new_gallery')) . '">Create empty gallery</a></nav></section>';
    if ($prefillGallery) {
        echo '<div class="notice">Upload target pre-selected: ' . e((string) $prefillGallery['title']) . '.</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">Upload failed: ' . e($error) . '</div>';
    }
    echo '<section class="panel compact-support"><h2>Upload support</h2><table class="support-matrix"><thead><tr><th>Type</th><th>JPG</th><th>PNG</th><th>GIF</th><th>WebP</th><th>HEIC</th><th>DNG</th></tr></thead><tbody><tr>';
    echo '<th scope="row">Available</th>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="support-yes">✓</td>';
    echo '<td class="' . ($heicSupported ? 'support-yes' : 'support-no') . '">' . ($heicSupported ? '✓' : '✕') . '</td>';
    echo '<td class="' . ($rawSupported ? 'support-yes' : 'support-no') . '">' . ($rawSupported ? '✓' : '✕') . '</td>';
    echo '</tr></tbody></table></section>';
    // $acceptTypes stores an intermediate value used by the surrounding gallery workflow.
    $acceptTypes = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];
    if ($heicSupported) {
        $acceptTypes[] = '.heic';
        $acceptTypes[] = '.heif';
    }
    if ($rawSupported) {
        $acceptTypes[] = '.dng';
    }
    $acceptTypes[] = 'image/*';
    // $acceptValue stores an intermediate value used by the surrounding gallery workflow.
    $acceptValue = implode(',', $acceptTypes);
    echo '<section class="panel"><h2>Upload into existing gallery</h2><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="existing">';
    echo '<label>Gallery<select name="gallery_id" required>' . gallery_options_for_select($prefillGalleryId) . '</select></label>';
    echo '<label>Images<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required></label>';
    echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails after upload</label>';
    echo '<button type="submit">Upload images</button></form></section>';
    echo '<section class="panel"><h2>Create gallery from upload</h2><form method="post" action="' . e(url_for('admin_upload')) . '" enctype="multipart/form-data" class="form-grid" data-gallery-upload-form>' . csrf_field();
    echo '<input type="hidden" name="upload_mode" value="new">';
    echo '<label>Gallery name<input name="title" required></label>';
    echo '<label>Folder name<input name="folder_name" autocomplete="off"><span class="muted">Leave empty to derive it from the gallery name.</span></label>';
    echo '<label>Parent gallery<select name="parent_id"><option value="0"' . ($prefillGalleryId === 0 ? ' selected' : '') . '>No parent</option>' . gallery_parent_options_for_new($prefillGalleryId) . '</select></label>';
    echo '<label>Visibility<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> Enable image voting for this gallery</label>';
    echo '<label>Description<textarea name="description"></textarea></label>';
    echo '<label>Images<input name="images[]" type="file" accept="' . e($acceptValue) . '" multiple required></label>';
    echo '<label><input type="checkbox" name="create_thumbnails" value="1" checked> Create optimized thumbnails after upload</label>';
    echo '<button type="submit">Create gallery and upload</button></form></section>';
    render_footer();
}

/**
 * Handles admin wants json logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function admin_wants_json(): bool
{
    return !empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

