<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/downloads.php
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

use Throwable;
use function Gallery\Controllers\cms_not_found;
use function Gallery\Controllers\picture_manager_image_ids_from_post;
use function Gallery\Controllers\picture_manager_require_logged_in_user;
use function Gallery\Controllers\picture_manager_source_gallery_from_post;
use function Gallery\Core\require_admin;
use function Gallery\Core\slugify;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\t;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\admin_log_event;

/**
 * Public download controller model.
 *
 * This module contains the routes that produce gallery, selected-photo, and site-wide ZIP
 * downloads. It depends on the download service functions and keeps all
 * request handling for archive downloads away from public gallery rendering.
 *
 * Route names, permissions, HTTP responses, and redirect behaviour are kept
 * identical to the previous app/controllers.php implementation.
 */

/**
 * Download a public ZIP for one gallery.
 */
function cms_download_gallery(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    // Variable $zip stores this steps working value.
    $zip = build_gallery_zip((int) $gallery['id'], true);
    send_download($zip, slugify((string) $gallery['title']) . '.zip');
}


/**
 * Download a ZIP containing only Picture manager selected photos.
 */
function cms_picture_manager_download_selection(): void
{
    picture_manager_require_logged_in_user();
    verify_csrf();

    try {
        // $sourceGallery stores the gallery currently shown in the public manager.
        $sourceGallery = picture_manager_source_gallery_from_post();
        // $imageIds stores selected photo IDs from the public grid.
        $imageIds = picture_manager_image_ids_from_post();
        // $zip stores the generated transient archive path.
        $zip = build_selected_images_zip((int) $sourceGallery['id'], $imageIds);
        admin_log_event('info', 'picture_manager.selection_zip_downloaded', 'Picture manager prepared a selected-photo share fallback ZIP.', [
            'source_gallery_id' => (int) $sourceGallery['id'],
            'selected_count' => count($imageIds),
        ], ['category' => 'other', 'severity' => 'info']);
        send_download($zip, slugify((string) $sourceGallery['title']) . '-selected-photos.zip');
    } catch (Throwable $exception) {
        admin_log_event('error', 'picture_manager.selection_zip_failed', 'Picture manager selected-photo ZIP failed.', [
            'source_gallery_id' => (int) ($_POST['source_gallery_id'] ?? 0),
            'error' => $exception->getMessage(),
        ], ['category' => 'other', 'severity' => 'error']);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo t('download.selected_failed', 'Selected-photo download failed: {error}', ['error' => $exception->getMessage()]);
    }
}

/**
 * Download an admin ZIP containing all imported galleries.
 */
function cms_download_all(): void
{
    require_admin();
    // Variable $zip stores this steps working value.
    $zip = build_all_zip();
    send_download($zip, 'all-galleries.zip');
}
