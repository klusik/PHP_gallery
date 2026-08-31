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
use function Gallery\Services\build_all_zip;
use function Gallery\Services\build_gallery_zip;
use function Gallery\Services\gallery_download_authorized_source;
use function Gallery\Services\gallery_download_legacy_manifest_is_safe;
use function Gallery\Services\gallery_download_manifest;
use function Gallery\Services\smart_gallery_download_authorized_source;
use function Gallery\Services\smart_gallery_download_manifest;
use Gallery\Services\GalleryDownloadManifestException;
use function Gallery\Services\build_smart_gallery_zip;
use function Gallery\Services\build_selected_images_zip;
use function Gallery\Services\send_download;
use function Gallery\Services\smart_gallery_effective_presentation;
use function Gallery\Services\smart_gallery_find_public_by_id;
use function Gallery\Services\smart_gallery_zip_failure_reason;

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

    try {
        // Direct/no-JavaScript requests retain a deliberately bounded legacy path.
        $manifest = gallery_download_manifest($gallery);
        if (!gallery_download_legacy_manifest_is_safe($manifest)) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.');
            return;
        }
        $zip = build_gallery_zip((int) $gallery['id'], true);
        send_download($zip, slugify((string) $gallery['title']) . '.zip');
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery.download_legacy_failed', 'Legacy gallery ZIP preparation failed.', [
            'gallery_id' => (int) $gallery['id'],
            'exception_class' => get_class($exception),
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('download.progress.legacy_failed', 'The server ZIP fallback could not be prepared. Open the gallery in a modern browser and use Download gallery there.');
    }
}

/**
 * Return browser-safe metadata for a progressive gallery download.
 */
function cms_download_gallery_manifest(): void
{
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    try {
        echo json_encode(gallery_download_manifest($gallery), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

/**
 * Stream one independently authorized original source file for browser ZIP assembly.
 */
function cms_download_gallery_file(): void
{
    $resolved = gallery_download_authorized_source(
        max(0, (int) ($_GET['gallery_id'] ?? 0)),
        max(0, (int) ($_GET['image_id'] ?? 0))
    );
    if ($resolved === null) {
        cms_not_found();
        return;
    }

    cms_stream_progressive_download_source($resolved);
}

/**
 * Stream one already-authorized original for a progressive browser ZIP.
 *
 * @param array{path:string,filename:string,size:int,version:string} $resolved Authorized source descriptor.
 */
function cms_stream_progressive_download_source(array $resolved): void
{
    $requestedVersion = trim((string) ($_GET['v'] ?? ''));
    if ($requestedVersion !== '' && !hash_equals((string) $resolved['version'], $requestedVersion)) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('download.progress.source_changed', 'A source file changed after the download was prepared. Retry the download.');
        return;
    }

    if (function_exists(__NAMESPACE__ . '\cms_release_public_media_session_lock')) {
        cms_release_public_media_session_lock();
    }
    $safeName = preg_replace('/[\x00-\x1F\x7F]/u', '_', (string) $resolved['filename']) ?? 'photo';
    $safeName = str_replace(['"', '\\'], '_', $safeName);
    if ($safeName === '') {
        $safeName = 'photo';
    }
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode((string) $resolved['filename']));
    header('Content-Length: ' . (int) $resolved['size']);
    readfile((string) $resolved['path']);
}


/**
 * Download the current visitor-authorized Smart Gallery result set as a ZIP.
 */
function cms_download_smart_gallery(): void
{
    $gallery = smart_gallery_find_public_by_id(max(0, (int) ($_GET['id'] ?? 0)));
    if (!$gallery || empty(smart_gallery_effective_presentation($gallery)['download_enabled'])) {
        cms_not_found();
        return;
    }
    try {
        // Direct/no-JavaScript requests retain only the same bounded legacy ZIP path
        // as physical galleries. Normal Smart Gallery clicks use the browser manifest.
        $manifest = smart_gallery_download_manifest($gallery);
        if (!gallery_download_legacy_manifest_is_safe($manifest)) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo t('download.progress.legacy_too_large', 'This gallery is too large for the legacy server ZIP. Open the gallery in a modern browser and use Download gallery there.');
            return;
        }
        $zip = build_smart_gallery_zip($gallery);
        send_download($zip, slugify((string) $gallery['title']) . '.zip');
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo $exception->getMessage();
    } catch (Throwable $exception) {
        admin_log_event('error', 'smart_gallery.download_failed', 'Smart Gallery ZIP preparation failed.', [
            'smart_gallery_id' => (int) $gallery['id'],
            'exception_class' => get_class($exception),
            'reason' => smart_gallery_zip_failure_reason($exception),
        ]);
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo t('smart_gallery.download_failed', 'Smart Gallery download could not be prepared.');
    }
}

/**
 * Return browser-safe metadata for a progressive Smart Gallery download.
 */
function cms_download_smart_gallery_manifest(): void
{
    $gallery = smart_gallery_find_public_by_id(max(0, (int) ($_GET['id'] ?? 0)));
    if (!$gallery || empty(smart_gallery_effective_presentation($gallery)['download_enabled'])) {
        cms_not_found();
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    try {
        echo json_encode(smart_gallery_download_manifest($gallery), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (GalleryDownloadManifestException $exception) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

/**
 * Stream one independently authorized Smart Gallery source for browser ZIP assembly.
 */
function cms_download_smart_gallery_file(): void
{
    $resolved = smart_gallery_download_authorized_source(
        max(0, (int) ($_GET['smart_gallery_id'] ?? 0)),
        max(0, (int) ($_GET['image_id'] ?? 0))
    );
    if ($resolved === null) {
        cms_not_found();
        return;
    }

    cms_stream_progressive_download_source($resolved);
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
