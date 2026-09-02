<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_thumbnails.php
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
use const Gallery\Services\THUMBNAIL_COMPATIBILITY_MODERN;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\all_image_ids;
use function Gallery\Services\app_setting;
use function Gallery\Services\create_all_thumbnails;
use function Gallery\Services\create_gallery_thumbnails;
use function Gallery\Services\create_image_thumbnails;
use function Gallery\Services\create_image_thumbnails_result;
use function Gallery\Services\delete_all_thumbnail_files;
use function Gallery\Services\delete_app_settings;
use function Gallery\Services\delete_legacy_jpg_thumbnails_for_image_ids;
use function Gallery\Services\browser_thumbnail_rebuild_source_chunk_plan;
use function Gallery\Services\browser_thumbnail_rebuild_store_prepared_zip_batch;
use function Gallery\Services\browser_thumbnail_rebuild_stream_source_zip;
use function Gallery\Services\browser_upload_settings;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\image_ids_for_galleries;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\set_thumbnail_compatibility_mode;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_compatibility_format_bytes;
use function Gallery\Services\thumbnail_compatibility_mode_label;
use function Gallery\Services\thumbnail_compatibility_mode_normalize;
use function Gallery\Services\thumbnail_inventory_fingerprint;
use function Gallery\Services\thumbnail_maintenance_check_batch;
use function Gallery\Services\thumbnail_maintenance_check_report;
use function Gallery\Services\thumbnail_maintenance_check_report_for_image_ids;
use function Gallery\Services\thumbnail_maintenance_debug_image_statuses;
use function Gallery\Services\thumbnail_maintenance_empty_check_report;
use function Gallery\Services\thumbnail_maintenance_finalize_check_report;
use function Gallery\Services\thumbnail_maintenance_image_ids;
use function Gallery\Services\thumbnail_maintenance_last_check;
use function Gallery\Services\thumbnail_maintenance_last_check_image_ids;
use function Gallery\Services\thumbnail_maintenance_merge_check_reports;
use function Gallery\Services\thumbnail_maintenance_store_last_check;
use function Gallery\Services\thumbnail_maintenance_summary_cache_clear;
use function Gallery\Services\thumbnail_metadata_refresh_image;
use function Gallery\Views\view_render_admin_thumbnail_maintenance_notice;
use function Gallery\Services\admin_log_event;

/**
 * Admin thumbnail controller model.
 *
 * This module renders thumbnail maintenance notices and handles manual or batched thumbnail generation requests.
 *
 * @param array $summary Summary value.
 */
function render_admin_thumbnail_maintenance_notice(array $summary): void
{
    if (function_exists('Gallery\\Views\\view_render_admin_thumbnail_maintenance_notice')) {
        view_render_admin_thumbnail_maintenance_notice($summary);
        return;
    }

    if (($summary['images_with_missing'] ?? 0) <= 0) {
        return;
    }

    if (thumbnail_maintenance_notice_is_dismissed($summary)) {
        return;
    }

    echo '<div class="notice admin-thumbnail-maintenance-notice">';
    echo '<div class="admin-thumbnail-maintenance-copy">';
    if (($summary['images_with_missing'] ?? 0) > 0) {
        echo '<strong>' . e(t('admin.thumbnails.maintenance_required', 'Thumbnail maintenance required.')) . '</strong> ';
        echo e(t('admin.thumbnails.missing_images_value', '{count} image(s) are missing optimized thumbnails or have stale thumbnail files.', ['count' => (string) $summary['images_with_missing']])) . ' ';
        echo e(t('admin.thumbnails.missing_variants_value', '{count} thumbnail variant(s) need to be created.', ['count' => (string) $summary['missing_variants']])) . ' ';
        if (!empty($summary['limited'])) {
            echo e(t('admin.thumbnails.limited_scan_value', 'Only the first {count} image(s) were checked, so more may be pending.', ['count' => (string) $summary['images_scanned']])) . ' ';
        }
        echo t('admin.thumbnails.public_visitors_do_not_generate', 'Public visitors will not generate these thumbnails while browsing. Use <strong>Create all thumbnails</strong> in the admin toolbar.');
    }
    if (($summary['webp_skipped'] ?? 0) > 0) {
        echo (($summary['images_with_missing'] ?? 0) > 0 ? '<br>' : '');
        echo e(t('admin.thumbnails.webp_skipped_exif', 'Some WebP variants are intentionally skipped because the source images contain EXIF metadata and this server cannot preserve EXIF during WebP conversion.'));
    }
    echo '</div>';
    echo '<form method="post" action="' . e(url_for('admin_dismiss_thumbnail_notice')) . '" class="admin-thumbnail-maintenance-dismiss" data-thumbnail-maintenance-form>';
    echo csrf_field();
    echo '<input type="hidden" name="thumbnail_inventory_fingerprint" value="' . e((string) ($summary['inventory_fingerprint'] ?? '')) . '">';
    echo '<button type="submit" class="secondary" formaction="' . e(url_for('admin_create_thumbnails')) . '" name="scope" value="missing" data-create-missing-thumbnails>' . e(t('admin.thumbnails.create_missing', 'Create missing thumbnails')) . '</button>';
    echo '<button type="submit" class="secondary">' . e(t('admin.thumbnails.dismiss_7_days', 'Dismiss for 7 days')) . '</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Store a seven-day dismissal for the current thumbnail maintenance warning.
 *
 * The dismissal is intentionally bound to a lightweight image inventory
 * fingerprint. Adding or importing a new image changes that fingerprint, which
 * makes the old dismissal invalid before its seven-day expiry.
 */
function cms_admin_dismiss_thumbnail_notice(): void
{
    require_admin();
    verify_csrf();

    // $fingerprint stores the exact image inventory state seen by the admin.
    $fingerprint = trim((string) ($_POST['thumbnail_inventory_fingerprint'] ?? ''));
    // $currentFingerprint stores the server-side inventory state at submit time.
    $currentFingerprint = thumbnail_inventory_fingerprint();

    if ($fingerprint !== '' && hash_equals($currentFingerprint, $fingerprint)) {
        set_app_setting('thumbnail_notice_dismissed_until', gmdate('Y-m-d H:i:s', time() + 7 * 86400));
        set_app_setting('thumbnail_notice_dismissed_inventory', $currentFingerprint);
        flash_message('admin_notice', t('admin.thumbnails.dismissed_notice', 'Thumbnail maintenance warning dismissed for 7 days. It will appear again sooner if new images are added.'));
    } else {
        delete_app_settings(['thumbnail_notice_dismissed_until', 'thumbnail_notice_dismissed_inventory']);
        flash_message('admin_notice', t('admin.thumbnails.dismiss_changed_notice', 'Thumbnail maintenance warning was not dismissed because the image list changed.'));
    }

    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Run a dry thumbnail inventory check without generating thumbnails.
 */
function cms_admin_check_thumbnail_maintenance(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    if (($_POST['ajax'] ?? '') === '1') {
        cms_admin_check_thumbnail_maintenance_batch();
        return;
    }

    @set_time_limit(300);
    // $report stores a full dry-run inventory grouped by gallery.
    $report = thumbnail_maintenance_check_report(null, 0);
    thumbnail_maintenance_store_last_check($report);
    cms_admin_thumbnail_repair_queue_store($report);

    cms_admin_record_thumbnail_check_completion($report);

    flash_message('admin_notice', cms_admin_thumbnail_check_message($report));
    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Run one dry thumbnail inventory check batch and return JSON progress.
 */
function cms_admin_check_thumbnail_maintenance_batch(): void
{
    // $bufferLevel stores the output-buffer nesting level before JSON-safe processing starts.
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        @set_time_limit(120);

        // $jobToken scopes the server-side aggregate to one browser progress run.
        $jobToken = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['job_token'] ?? ''));
        if ($jobToken === '') {
            $jobToken = bin2hex(random_bytes(8));
        }
        // $offset stores the current batch offset requested by the browser.
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        // $batchSize stores the number of image rows inspected per request.
        $batchSize = max(25, min(500, (int) ($_POST['batch_size'] ?? 150)));

        if (!isset($_SESSION['thumbnail_maintenance_check_jobs']) || !is_array($_SESSION['thumbnail_maintenance_check_jobs'])) {
            $_SESSION['thumbnail_maintenance_check_jobs'] = [];
        }
        if ($offset === 0) {
            $_SESSION['thumbnail_maintenance_check_jobs'][$jobToken] = thumbnail_maintenance_empty_check_report(null);
        } elseif (!isset($_SESSION['thumbnail_maintenance_check_jobs'][$jobToken]) || !is_array($_SESSION['thumbnail_maintenance_check_jobs'][$jobToken])) {
            throw new RuntimeException(t('admin.thumbnails.check_session_expired', 'Thumbnail check progress expired. Start the check again.'));
        }

        // $batchReport stores one dry, non-mutating check slice.
        $batchReport = thumbnail_maintenance_check_batch(null, $offset, $batchSize);
        // $aggregate stores the session aggregate accumulated across prior batches.
        $aggregate = thumbnail_maintenance_merge_check_reports((array) $_SESSION['thumbnail_maintenance_check_jobs'][$jobToken], $batchReport);
        $_SESSION['thumbnail_maintenance_check_jobs'][$jobToken] = $aggregate;

        $done = !empty($batchReport['done']);
        // $repairToken stores the session key for the server-side targeted repair queue.
        $repairToken = '';
        if ($done) {
            $aggregate['checked_at'] = now_sql();
            $aggregate = thumbnail_maintenance_finalize_check_report($aggregate);
            thumbnail_maintenance_store_last_check($aggregate);
            $repairToken = cms_admin_thumbnail_repair_queue_store($aggregate);
            cms_admin_record_thumbnail_check_completion($aggregate);
            flash_message('admin_notice', cms_admin_thumbnail_check_message($aggregate));
            unset($_SESSION['thumbnail_maintenance_check_jobs'][$jobToken]);
        }

        $response = [
            'ok' => true,
            'job_token' => $jobToken,
            'total' => (int) ($batchReport['total'] ?? 0),
            'processed' => (int) ($batchReport['processed'] ?? 0),
            'next_offset' => (int) ($batchReport['next_offset'] ?? 0),
            'done' => $done,
            'images_with_missing' => (int) ($aggregate['images_with_missing'] ?? 0),
            'missing_variants' => (int) ($aggregate['missing_variants'] ?? 0),
            'affected_gallery_count' => (int) ($aggregate['affected_gallery_count'] ?? 0),
            'repair_token' => $repairToken,
            'redirect_url' => url_for('admin') . '#admin-tab-maintenance',
        ];

        $discardedOutput = (string) ob_get_clean();
        if (trim($discardedOutput) !== '') {
            admin_log_event('warning', 'thumbnail.check_response_output_discarded', 'Thumbnail dry check produced output before its JSON response.', [
                'discarded_output_preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500),
            ], ['category' => 'other', 'severity' => 'warning']);
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        return;
    } catch (Throwable $exception) {
        $discardedOutput = (string) ob_get_clean();
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'thumbnail.check_failed', 'Thumbnail dry check request failed before a JSON response could be completed.', [
            'error' => $exception->getMessage(),
            'discarded_output_preview' => $discardedOutput !== '' ? mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500) : null,
        ], ['category' => 'other', 'severity' => 'error']);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => t('admin.thumbnails.check_failed', 'Thumbnail check failed. Check the admin logs or PHP error log for details.'),
        ]);
        return;
    }
}

/**
 * Return the flash message for one completed dry thumbnail check.
 *
 * @param array $report Report value.
 * @return string Text result for the caller.
 */
function cms_admin_thumbnail_check_message(array $report): string
{
    $affectedImages = (int) ($report['images_with_missing'] ?? 0);
    if ($affectedImages > 0) {
        return t('admin.thumbnails.check_completed_with_missing', 'Thumbnail check complete. Checked {images} image(s); {affected_images} image(s) in {galleries} gallery/galleries need {variants} thumbnail variant(s).', [
            'images' => (string) (int) ($report['images_scanned'] ?? 0),
            'affected_images' => (string) $affectedImages,
            'galleries' => (string) (int) ($report['affected_gallery_count'] ?? 0),
            'variants' => (string) (int) ($report['missing_variants'] ?? 0),
        ]);
    }

    return t('admin.thumbnails.check_completed_clean', 'Thumbnail check complete. Checked {images} image(s); no missing or stale thumbnail variants were found.', [
        'images' => (string) (int) ($report['images_scanned'] ?? 0),
    ]);
}

/**
 * Write the admin log entry for one completed dry thumbnail check.
 *
 * @param array $report Report value.
 */
function cms_admin_record_thumbnail_check_completion(array $report): void
{
    admin_log_event('info', 'thumbnail.maintenance_checked', 'Admin ran a dry thumbnail maintenance check.', [
        'images_scanned' => (int) ($report['images_scanned'] ?? 0),
        'images_with_missing' => (int) ($report['images_with_missing'] ?? 0),
        'missing_variants' => (int) ($report['missing_variants'] ?? 0),
        'affected_gallery_count' => (int) ($report['affected_gallery_count'] ?? 0),
        'affected_galleries' => (array) ($report['affected_galleries'] ?? []),
        'affected_galleries_truncated' => !empty($report['affected_galleries_truncated']),
        'invalid_geometry_detected' => (int) ($report['invalid_geometry_detected'] ?? 0),
        'webp_skipped' => (int) ($report['webp_skipped'] ?? 0),
    ]);
}

/**
 * Remove expired server-side thumbnail repair queues from the session.
 *
 * @param int $ttlSeconds Queue lifetime in seconds.
 */
function cms_admin_thumbnail_repair_queue_prune(int $ttlSeconds = 3600): void
{
    if (!isset($_SESSION['thumbnail_maintenance_repair_jobs']) || !is_array($_SESSION['thumbnail_maintenance_repair_jobs'])) {
        $_SESSION['thumbnail_maintenance_repair_jobs'] = [];
        return;
    }

    $now = time();
    foreach ($_SESSION['thumbnail_maintenance_repair_jobs'] as $token => $job) {
        if (!is_array($job) || $now - (int) ($job['created_at'] ?? 0) > max(300, $ttlSeconds)) {
            unset($_SESSION['thumbnail_maintenance_repair_jobs'][$token]);
        }
    }
}

/**
 * Store a targeted server-side repair queue from a completed dry thumbnail check.
 *
 * The queue is stored in the admin session so the follow-up Create missing
 * thumbnails action can process the exact image IDs that the dry check found,
 * without rescanning the whole library before the first progress response.
 *
 * @param array $report Completed dry thumbnail check report.
 * @return string Session repair token, or an empty string when no exact queue exists.
 */
function cms_admin_thumbnail_repair_queue_store(array $report): string
{
    cms_admin_thumbnail_repair_queue_prune();
    if (!empty($report['affected_image_ids_truncated'])) {
        return '';
    }

    $imageIds = array_values(array_unique(array_filter(array_map('intval', (array) ($report['affected_image_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return '';
    }

    $token = bin2hex(random_bytes(12));
    $_SESSION['thumbnail_maintenance_repair_jobs'][$token] = [
        'created_at' => time(),
        'inventory_fingerprint' => (string) ($report['inventory_fingerprint'] ?? thumbnail_inventory_fingerprint(null)),
        'image_ids' => $imageIds,
    ];

    return $token;
}

/**
 * Return image IDs for a stored server-side thumbnail repair queue.
 *
 * @param string $token Repair queue token posted by the admin browser.
 * @return array<int int> Image IDs from the matching session queue.
 */
function cms_admin_thumbnail_repair_queue_read(string $token): array
{
    cms_admin_thumbnail_repair_queue_prune();
    $token = preg_replace('/[^A-Fa-f0-9]/', '', $token) ?: '';
    if ($token === '' || empty($_SESSION['thumbnail_maintenance_repair_jobs'][$token]) || !is_array($_SESSION['thumbnail_maintenance_repair_jobs'][$token])) {
        return [];
    }

    $job = $_SESSION['thumbnail_maintenance_repair_jobs'][$token];
    $fingerprint = (string) ($job['inventory_fingerprint'] ?? '');
    if ($fingerprint === '' || !hash_equals(thumbnail_inventory_fingerprint(null), $fingerprint)) {
        unset($_SESSION['thumbnail_maintenance_repair_jobs'][$token]);
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', (array) ($job['image_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
}

/**
 * Filter selected thumbnail repair IDs by an optional gallery scope.
 *
 * @param array $imageIds Candidate image IDs.
 * @param ?array $galleryIds Optional gallery IDs supplied by the request.
 * @return array<int int> Image IDs that still match the gallery scope.
 */
function cms_admin_thumbnail_repair_filter_image_ids(array $imageIds, ?array $galleryIds): array
{
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return [];
    }
    if ($galleryIds === null) {
        return $imageIds;
    }

    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$galleryIds) {
        return [];
    }

    $filteredIds = [];
    foreach ($imageIds as $imageId) {
        $image = find_image($imageId);
        if ($image && in_array((int) ($image['gallery_id'] ?? 0), $galleryIds, true)) {
            $filteredIds[] = $imageId;
        }
    }

    return $filteredIds;
}

/**
 * Return true when the current thumbnail maintenance warning is temporarily hidden.
 *
 * Both conditions must match: the dismissal timestamp must still be in the
 * future, and the image inventory fingerprint must match the current summary.
 * If either value is stale, the dismissal settings are removed so later checks
 * start from a clean state.
 *
 * @param array $summary Summary value.
 * @return bool True when the condition matches.
 */
function thumbnail_maintenance_notice_is_dismissed(array $summary): bool
{
    // $dismissedUntil stores the UTC SQL timestamp after which the warning must reappear.
    $dismissedUntil = trim((string) app_setting('thumbnail_notice_dismissed_until', ''));
    // $dismissedInventory stores the image inventory fingerprint captured when the admin dismissed the warning.
    $dismissedInventory = trim((string) app_setting('thumbnail_notice_dismissed_inventory', ''));
    // $currentInventory stores the current fingerprint supplied by thumbnail_maintenance_summary().
    $currentInventory = (string) ($summary['inventory_fingerprint'] ?? thumbnail_inventory_fingerprint());

    if ($dismissedUntil === '' || $dismissedInventory === '') {
        return false;
    }

    // $expiresAt stores the parsed Unix timestamp for the dismissal.
    $expiresAt = strtotime($dismissedUntil . ' UTC');
    if ($expiresAt === false || $expiresAt <= time() || !hash_equals($currentInventory, $dismissedInventory)) {
        delete_app_settings(['thumbnail_notice_dismissed_until', 'thumbnail_notice_dismissed_inventory']);
        return false;
    }

    return true;
}

/**
 * Handles cms admin create thumbnails logic for the gallery application.
 */
function cms_admin_create_thumbnails(): void
{
    // $isJsonRequest keeps the browser-driven batch contract JSON-only even when authentication or CSRF expires.
    $isJsonRequest = !empty($_POST['ajax']) || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if ($isJsonRequest) {
        // $user stores the authenticated administrator without triggering the normal HTML login redirect.
        $user = current_user();
        if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(admin_mutation_error_envelope(
                t('auth.admin_required', 'Admin access is required.'),
                'auth.admin_required',
                cms_admin_thumbnail_mutation_descriptor($_POST)
            ));
            return;
        }
        if (!cms_admin_thumbnail_csrf_valid()) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(admin_mutation_error_envelope(
                t('security.invalid_csrf', 'Invalid CSRF token.'),
                'security.invalid_csrf',
                cms_admin_thumbnail_mutation_descriptor($_POST)
            ));
            return;
        }
        cms_admin_create_thumbnails_batch();
        return;
    }

    require_admin();
    verify_csrf();
    // Variable $count stores this steps working value.
    $count = 0;
    if (($_POST['scope'] ?? '') === 'metadata') {
        // $imageIds stores every image that should have its physical thumbnails inventoried.
        $imageIds = all_image_ids();
        // $deleted stores invalid thumbnail files removed during the metadata scan.
        $deleted = 0;
        // $galleryCache stores galleries only once per refresh pass.
        $galleryCache = [];
        foreach ($imageIds as $imageId) {
            // $image stores the current source image row.
            $image = find_image((int) $imageId);
            if (!$image) {
                continue;
            }
            // $galleryId stores the current image gallery identifier.
            $galleryId = (int) $image['gallery_id'];
            if (!array_key_exists($galleryId, $galleryCache)) {
                $galleryCache[$galleryId] = find_gallery($galleryId);
            }
            if (!$galleryCache[$galleryId]) {
                continue;
            }
            // $result stores refreshed metadata counters for this image.
            $result = thumbnail_metadata_refresh_image($image, $galleryCache[$galleryId], null, true);
            $count += (int) ($result['valid'] ?? 0);
            $deleted += (int) ($result['invalid_deleted'] ?? 0);
        }
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.metadata_refreshed_count', 'Thumbnail database refreshed for {count} valid thumbnail file(s). Deleted {deleted} invalid thumbnail file(s).', ['count' => (string) $count, 'deleted' => (string) $deleted]));
        redirect_to(url_for('admin') . '#admin-tab-maintenance');
    }
    if (($_POST['scope'] ?? '') === 'all') {
        // Variable $count stores this steps working value.
        $count = create_all_thumbnails();
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.created_count', 'Created {count} thumbnail(s).', ['count' => (string) $count]));
        redirect_to(url_for('admin'));
    }
    if (($_POST['scope'] ?? '') === 'missing') {
        // Variable $imageIds stores the images that currently need thumbnail regeneration.
        $imageIds = thumbnail_maintenance_image_ids(null, 0);
        if ($imageIds) {
            // Variable $galleryCache stores galleries only once per batch so we do not refetch the same parent repeatedly.
            $galleryCache = [];
            foreach ($imageIds as $imageId) {
                // Variable $image stores this steps working value.
                $image = find_image((int) $imageId);
                if (!$image) {
                    continue;
                }
                // Variable $galleryId stores this steps working value.
                $galleryId = (int) $image['gallery_id'];
                if (!array_key_exists($galleryId, $galleryCache)) {
                    $galleryCache[$galleryId] = find_gallery($galleryId);
                }
                if (!$galleryCache[$galleryId]) {
                    continue;
                }
                $count += create_image_thumbnails_result($image, $galleryCache[$galleryId])['created'];
            }
        }
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.created_missing_count', 'Created {count} thumbnail(s) for images with missing or stale thumbnails.', ['count' => (string) $count]));
        redirect_to(url_for('admin'));
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['thumbnail_gallery_id'] ?? $_POST['gallery_id'] ?? 0);
    // Variable $gallery stores this steps working value.
    $gallery = $galleryId > 0 ? find_gallery($galleryId) : null;
    if ($gallery && empty($_POST['thumbnail_gallery_id']) && !empty($_POST['image_ids'])) {
        foreach (array_map('intval', $_POST['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image && (int) $image['gallery_id'] === $galleryId) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.created_count', 'Created {count} thumbnail(s).', ['count' => (string) $count]));
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if ($gallery) {
        // Variable $count stores this steps working value.
        $count = create_gallery_thumbnails($galleryId);
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.created_count', 'Created {count} thumbnail(s).', ['count' => (string) $count]));
        redirect_to(url_for('admin'));
    }
    redirect_to(url_for('admin'));
}

/**
 * Handles cms admin create thumbnails batch logic for the gallery application.
 */
function cms_admin_create_thumbnails_batch(): void
{
    // $bufferLevel stores the output-buffer nesting level before JSON-safe processing starts.
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        // $scope stores the requested batch type so targeted repair can be logged separately.
        $scope = (string) ($_POST['scope'] ?? '');
        // $imageIds stores the exact server-side image queue for this thumbnail job.
        $imageIds = thumbnail_request_image_ids($_POST);
        // $total stores the number of images selected before the current batch slice.
        $total = count($imageIds);
        // $offset stores the current browser-driven batch offset.
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        // $maintenanceBefore stores the saved dry check report without rescanning the library.
        $maintenanceBefore = null;
        if ($scope === 'missing' && $offset === 0) {
            $maintenanceBefore = function_exists('Gallery\\Services\\thumbnail_maintenance_last_check') ? thumbnail_maintenance_last_check() : null;
            admin_log_event('info', 'thumbnail.missing_repair_started', 'Targeted server-side thumbnail repair started.', [
                'scope' => $scope,
                'selected_image_count' => $total,
                'selected_image_ids' => array_slice($imageIds, 0, 50),
                'selected_image_ids_truncated' => count($imageIds) > 50,
                'selection_source' => (string) ($_POST['thumbnail_repair_token'] ?? '') !== '' ? 'session_repair_queue' : 'stored_dry_check_or_fallback',
                'maintenance_before' => $maintenanceBefore,
                'selected_image_debug' => thumbnail_maintenance_debug_image_statuses($imageIds),
            ]);
        }

        // $batchSize stores the maximum number of image rows processed by this request.
        $batchSize = max(1, min(12, (int) ($_POST['batch_size'] ?? 6)));
        // $batch stores this request's image ID slice.
        $batch = array_slice($imageIds, $offset, $batchSize);
        // $created stores newly written thumbnail files for this batch.
        $created = 0;
        // $skipped stores already current thumbnail files for this batch.
        $skipped = 0;
        // $webpSkipped stores intentionally skipped WebP variants for this batch.
        $webpSkipped = 0;
        // $failed stores required thumbnail or DNG display derivatives that could not be generated.
        $failed = 0;
        // $invalidGeometryDeleted stores wrong-ratio derivative files removed by metadata refresh.
        $invalidGeometryDeleted = 0;
        // $invalidGeometryFiles stores a small diagnostic sample of deleted invalid derivatives.
        $invalidGeometryFiles = [];
        // $errors stores concise thumbnail generation diagnostics for the JSON response.
        $errors = [];
        // $galleryCache stores parent galleries loaded once per batch.
        $galleryCache = [];

        foreach ($batch as $imageId) {
            // $image stores the current image row loaded from the database.
            $image = find_image((int) $imageId);
            if (!$image) {
                continue;
            }
            // $galleryId stores the parent gallery identifier used for path resolution.
            $galleryId = (int) $image['gallery_id'];
            if (!array_key_exists($galleryId, $galleryCache)) {
                $galleryCache[$galleryId] = find_gallery($galleryId);
            }
            if (!$galleryCache[$galleryId]) {
                continue;
            }
            if ($scope === 'metadata') {
                // $result stores refreshed thumbnail metadata counters for this image.
                $result = thumbnail_metadata_refresh_image($image, $galleryCache[$galleryId], null, true);
                $created += (int) ($result['valid'] ?? 0);
                $skipped += (int) ($result['missing'] ?? 0) + (int) ($result['invalid_deleted'] ?? 0);
                $invalidGeometryDeleted += (int) ($result['invalid_deleted'] ?? 0);
                foreach ((array) ($result['invalid_files'] ?? []) as $invalidFile) {
                    $invalidGeometryFiles[] = (string) $invalidFile;
                }
                continue;
            }

            // $result stores generation counters for the current source image.
            $result = create_image_thumbnails_result($image, $galleryCache[$galleryId]);
            $created += (int) $result['created'];
            $skipped += (int) $result['skipped'];
            $webpSkipped += (int) ($result['webp_skipped'] ?? 0);
            $failed += (int) ($result['failed'] ?? 0);
            foreach ((array) ($result['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
        }

        if ($created > 0 || $scope === 'missing' || $scope === 'metadata' || $invalidGeometryDeleted > 0) {
            thumbnail_maintenance_summary_cache_clear();
        }
        if ($failed > 0) {
            admin_log_event('warning', 'thumbnail.generation_failed', 'One or more thumbnail or DNG display derivatives could not be generated.', [
                'scope' => $scope,
                'selected_image_count' => $total,
                'selected_image_ids' => array_slice($imageIds, 0, 50),
                'selected_image_ids_truncated' => count($imageIds) > 50,
                'failed' => $failed,
                'created' => $created,
                'existing_skipped' => $skipped,
                'webp_skipped' => $webpSkipped,
                'errors' => array_values(array_unique(array_filter($errors))),
            ], ['category' => 'other', 'severity' => 'warning']);
        }

        // $processed stores the image count completed after this batch.
        $processed = min($total, $offset + count($batch));
        // $done stores whether this response finishes the requested thumbnail job.
        $done = $processed >= $total;
        // $maintenanceAfter stores a targeted post-check for selected images only.
        $maintenanceAfter = null;
        // $remainingImageIds stores selected images still considered affected after repair.
        $remainingImageIds = [];

        if ($scope === 'metadata' && $done) {
            admin_log_event('info', 'thumbnail.metadata_refreshed', 'Thumbnail database metadata refresh completed.', [
                'scope' => $scope,
                'selected_image_count' => $total,
                'selected_image_ids' => array_slice($imageIds, 0, 50),
                'selected_image_ids_truncated' => count($imageIds) > 50,
                'processed' => $processed,
                'valid_variants' => $created,
                'missing_or_invalid_variants' => $skipped,
                'invalid_geometry_deleted' => $invalidGeometryDeleted,
                'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter($invalidGeometryFiles))), 0, 50),
            ]);
        }
        if ($scope === 'missing' && $done) {
            $maintenanceAfter = function_exists('Gallery\\Services\\thumbnail_maintenance_check_report_for_image_ids')
                ? thumbnail_maintenance_check_report_for_image_ids($imageIds)
                : null;
            $remainingImageIds = is_array($maintenanceAfter)
                ? array_values(array_unique(array_filter(array_map('intval', (array) ($maintenanceAfter['affected_image_ids'] ?? [])), static fn (int $id): bool => $id > 0)))
                : [];
            admin_log_event($remainingImageIds ? 'warning' : 'info', 'thumbnail.missing_repair_completed', 'Targeted server-side thumbnail repair completed.', [
                'scope' => $scope,
                'selected_image_count' => $total,
                'selected_image_ids' => array_slice($imageIds, 0, 50),
                'selected_image_ids_truncated' => count($imageIds) > 50,
                'processed' => $processed,
                'created' => $created,
                'existing_skipped' => $skipped,
                'webp_skipped' => $webpSkipped,
                'failed' => $failed,
                'errors' => array_values(array_unique(array_filter($errors))),
                'maintenance_before' => $maintenanceBefore,
                'maintenance_after' => $maintenanceAfter,
                'remaining_image_count' => count($remainingImageIds),
                'remaining_image_ids' => array_slice($remainingImageIds, 0, 50),
                'remaining_image_ids_truncated' => count($remainingImageIds) > 50,
                'remaining_image_debug' => thumbnail_maintenance_debug_image_statuses($remainingImageIds),
            ]);
        }

        // $response stores the JSON batch result returned to the browser.
        $response = [
            'ok' => true,
            'total' => $total,
            'processed' => $processed,
            'next_offset' => $processed,
            'webp_skipped' => $webpSkipped,
            'failed' => $failed,
            'invalid_geometry_deleted' => $invalidGeometryDeleted,
            'invalid_geometry_files' => array_slice(array_values(array_unique(array_filter($invalidGeometryFiles))), 0, 50),
            'errors' => array_values(array_unique(array_filter($errors))),
            'created' => $created,
            'skipped' => $skipped,
            'done' => $done,
            'maintenance_after' => $maintenanceAfter,
            'remaining_image_count' => count($remainingImageIds),
        ];
        if ($done) {
            // Only explicit gallery-scoped jobs need public invalidation metadata. Global maintenance jobs keep their compact dashboard response.
            $galleryId = cms_admin_thumbnail_explicit_gallery_id($_POST);
            $gallery = $galleryId > 0 ? find_gallery($galleryId, true) : null;
            if (is_array($gallery)) {
                $message = $scope === 'metadata'
                    ? t('admin.thumbnails.metadata_refresh_complete', 'Thumbnail database refresh complete.')
                    : t('admin.thumbnails.job_complete', 'Thumbnail job complete.');
                $response = array_merge($response, admin_mutation_success_envelope(
                    $message,
                    cms_admin_thumbnail_mutation_descriptor($_POST),
                    null,
                    cms_admin_thumbnail_public_contexts($gallery),
                    []
                ));
            }
        }
        // $discardedOutput stores any incidental output such as PHP warnings that would otherwise corrupt JSON.
        $discardedOutput = (string) ob_get_clean();
        if (trim($discardedOutput) !== '') {
            admin_log_event('warning', 'thumbnail.batch_response_output_discarded', 'Thumbnail generation produced output before its JSON response.', [
                'scope' => $scope,
                'selected_image_count' => $total,
                'selected_image_ids' => array_slice($imageIds, 0, 50),
                'selected_image_ids_truncated' => count($imageIds) > 50,
                'discarded_output_preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500),
            ], ['category' => 'other', 'severity' => 'warning']);
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        return;
    } catch (Throwable $exception) {
        // $discardedOutput stores any incidental output that should not leak into the JSON response body.
        $discardedOutput = (string) ob_get_clean();
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'thumbnail.batch_failed', 'Thumbnail batch request failed before a JSON response could be completed.', [
            'error' => $exception->getMessage(),
            'discarded_output_preview' => $discardedOutput !== '' ? mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500) : null,
        ], ['category' => 'other', 'severity' => 'error']);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $message = t('admin.thumbnails.request_failed', 'Thumbnail request failed. Check the admin logs or PHP error log for details.');
        echo json_encode(admin_mutation_error_envelope(
            $message,
            'thumbnail.batch_failed',
            cms_admin_thumbnail_mutation_descriptor($_POST)
        ));
        return;
    }
}

/**
 * Return whether the submitted CSRF token matches the current administrator session.
 *
 * The browser-driven thumbnail endpoint must not call verify_csrf(), because that
 * helper exits with a plain-text body and would corrupt the JSON completion path.
 */
function cms_admin_thumbnail_csrf_valid(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

/**
 * Return the one explicit gallery id owned by a gallery-editor thumbnail job.
 *
 * Global dashboard maintenance may touch many galleries and intentionally returns
 * no public context list. Side-panel gallery jobs always carry one stable id.
 *
 * @param array<string, mixed> $post Submitted thumbnail request.
 */
function cms_admin_thumbnail_explicit_gallery_id(array $post): int
{
    $galleryId = (int) ($post['thumbnail_gallery_id'] ?? $post['gallery_id'] ?? 0);
    return max(0, $galleryId);
}

/**
 * Return the public gallery contexts whose rendered thumbnail URLs may need retrying.
 *
 * The edited gallery is required when the drawer is opened from inside that gallery.
 * Its owning parent or the root index is also required when the drawer was opened from
 * a gallery card whose cover thumbnail may have been repaired by this job.
 *
 * @param array<string, mixed> $gallery Explicit gallery row for the thumbnail job.
 * @return array<int, array<string, mixed>> Canonical public render contexts.
 */
function cms_admin_thumbnail_public_contexts(array $gallery): array
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return [];
    }

    // Thumbnail repair changes generated cache artifacts, not a stable database field rendered in markup, so this context explicitly has no immediate postcondition.
    $contexts = [admin_mutation_public_gallery_context($galleryId, gallery_public_url($gallery), null)];
    $parentId = (int) ($gallery['parent_id'] ?? 0);
    $parent = $parentId > 0 ? find_gallery($parentId, true) : null;
    $contexts[] = admin_mutation_public_gallery_context(
        $parentId,
        is_array($parent) ? gallery_public_url($parent) : url_for('home'),
        null
    );
    return $contexts;
}

/**
 * Build canonical mutation metadata for a browser-driven thumbnail job.
 *
 * @param array<string, mixed> $post Submitted thumbnail request.
 * @return array<string, mixed> Canonical mutation descriptor.
 */
function cms_admin_thumbnail_mutation_descriptor(array $post): array
{
    $scope = trim((string) ($post['scope'] ?? ''));
    $action = $scope === 'metadata' ? 'refresh_metadata' : 'rebuild';
    return admin_mutation_descriptor('thumbnail.' . $action, 'thumbnail', $action, []);
}


/**
 * Return a JSON response for browser thumbnail rebuild endpoints.
 *
 * @param array $payload Payload value.
 * @param int $statusCode Status code value.
 */
function cms_admin_thumbnail_browser_json_response(array $payload, int $statusCode = 200): void
{
    if (function_exists('Gallery\\Controllers\\admin_upload_browser_json_response')) {
        admin_upload_browser_json_response($payload, $statusCode);
        return;
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Verify CSRF for an browser thumbnail rebuild request without emitting HTML.
 */
function cms_admin_thumbnail_browser_verify_csrf(): void
{
    if (function_exists('Gallery\\Controllers\\admin_upload_browser_verify_csrf')) {
        admin_upload_browser_verify_csrf();
        return;
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        throw new RuntimeException(t('admin.upload.error_invalid_csrf', 'Invalid CSRF token. Reload the admin page and try again.'));
    }
}

/**
 * Stream one source ZIP chunk for the browser thumbnail rebuild path.
 */
function cms_admin_thumbnail_browser_source_chunk(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_admin_thumbnail_browser_json_response(['ok' => false, 'error' => t('admin.upload.error_method_not_allowed', 'This endpoint accepts POST requests only.')], 405);
        return;
    }

    try {
        cms_admin_thumbnail_browser_verify_csrf();
        $settings = function_exists('Gallery\\Services\\browser_upload_settings') ? browser_upload_settings() : ['enabled' => false];
        if (empty($settings['enabled'])) {
            throw new RuntimeException(t('browser_upload.error_disabled', 'Browser-side upload is disabled in Admin settings.'));
        }
        if (!function_exists('Gallery\\Services\\browser_thumbnail_rebuild_source_chunk_plan') || !function_exists('Gallery\\Services\\browser_thumbnail_rebuild_stream_source_zip')) {
            throw new RuntimeException(t('browser_thumbnail_rebuild.error_unavailable', 'Browser thumbnail rebuild support is not available.'));
        }

        $plan = browser_thumbnail_rebuild_source_chunk_plan($_POST);
        admin_log_event('info', 'thumbnail.browser_rebuild_source_chunk', 'Admin downloaded a browser thumbnail rebuild source chunk.', [
            'offset' => (int) ($plan['offset'] ?? 0),
            'next_offset' => (int) ($plan['next_offset'] ?? 0),
            'total' => (int) ($plan['total'] ?? 0),
            'items' => count((array) ($plan['items'] ?? [])),
            'skipped' => count((array) ($plan['skipped'] ?? [])),
            'source_payload_bytes' => (int) ($plan['source_payload_bytes'] ?? 0),
        ]);
        browser_thumbnail_rebuild_stream_source_zip($plan);
    } catch (Throwable $exception) {
        admin_log_event('error', 'thumbnail.browser_rebuild_source_failed', 'Browser thumbnail source chunk request failed.', [
            'error' => $exception->getMessage(),
            'offset' => (int) ($_POST['offset'] ?? 0),
        ], ['category' => 'other', 'severity' => 'error']);
        cms_admin_thumbnail_browser_json_response(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Accept one browser-prepared thumbnail ZIP batch for the browser rebuild path.
 */
function cms_admin_thumbnail_browser_upload_batch(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_admin_thumbnail_browser_json_response(['ok' => false, 'error' => t('admin.upload.error_method_not_allowed', 'This upload endpoint accepts POST requests only.')], 405);
        return;
    }
    if (function_exists('Gallery\\Controllers\\admin_upload_browser_reject_discarded_body') && admin_upload_browser_reject_discarded_body()) {
        return;
    }

    try {
        cms_admin_thumbnail_browser_verify_csrf();
        $settings = function_exists('Gallery\\Services\\browser_upload_settings') ? browser_upload_settings() : ['enabled' => false];
        if (empty($settings['enabled'])) {
            throw new RuntimeException(t('browser_upload.error_disabled', 'Browser-side upload is disabled in Admin settings.'));
        }
        if (!function_exists('Gallery\\Services\\browser_thumbnail_rebuild_store_prepared_zip_batch')) {
            throw new RuntimeException(t('browser_thumbnail_rebuild.error_unavailable', 'Browser thumbnail rebuild support is not available.'));
        }
        $sessionId = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_POST['upload_session_id'] ?? '')) ?: bin2hex(random_bytes(8));
        $batchIndex = max(0, (int) ($_POST['batch_index'] ?? 0));
        $response = browser_thumbnail_rebuild_store_prepared_zip_batch($_FILES['zip_batch'] ?? [], $sessionId, $batchIndex);
        cms_admin_thumbnail_browser_json_response($response);
    } catch (Throwable $exception) {
        admin_log_event('error', 'thumbnail.browser_rebuild_upload_failed', 'Browser-prepared thumbnail upload batch failed.', [
            'error' => $exception->getMessage(),
            'batch_index' => (int) ($_POST['batch_index'] ?? 0),
        ], ['category' => 'other', 'severity' => 'error']);
        cms_admin_thumbnail_browser_json_response(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}


/**
 * Handles cms admin delete thumbnails logic for the gallery application.
 *
 * The operation is intentionally separate from thumbnail generation so the
 * destructive path can have its own CSRF check, explicit confirmation token,
 * admin flash message, and operational log entry. The confirmation word is not
 * a security mechanism. It is a human safety rail against accidental clicks.
 */
function cms_admin_delete_thumbnails(): void
{
    require_admin();
    verify_csrf();

    // $expectedWord stores the randomly selected challenge word sent by the browser.
    $expectedWord = strtolower(trim((string) ($_POST['confirmation_expected'] ?? '')));
    // $typedWord stores the admin-entered response to the destructive action prompt.
    $typedWord = strtolower(trim((string) ($_POST['confirmation_typed'] ?? '')));
    // $allowedWords stores the intentionally small challenge vocabulary shared with the admin UI.
    $allowedWords = thumbnail_delete_confirmation_words();

    if ($expectedWord === '' || $typedWord === '' || $expectedWord !== $typedWord || !in_array($expectedWord, $allowedWords, true)) {
        flash_message('admin_notice', t('admin.thumbnails.delete_not_confirmed', 'Thumbnail deletion was not confirmed. No thumbnail files were deleted.'));
        redirect_to(url_for('admin') . '#admin-tab-maintenance');
    }

    try {
        // $result stores the count of deleted files and removed thumbnail directories.
        $result = delete_all_thumbnail_files();
        thumbnail_maintenance_summary_cache_clear();
        admin_log_event('warning', 'thumbnail.cache_deleted', 'Admin deleted all generated thumbnail cache files.', [
            'files_deleted' => (int) $result['files_deleted'],
            'directories_removed' => (int) $result['directories_removed'],
            'directories_scanned' => (int) $result['directories_scanned'],
        ]);
        flash_message('admin_notice', t('admin.thumbnails.deleted_count', 'Deleted {files} thumbnail file(s) and removed {directories} thumbnail folder(s).', ['files' => (string) (int) $result['files_deleted'], 'directories' => (string) (int) $result['directories_removed']]));
    } catch (Throwable $exception) {
        admin_log_event('error', 'thumbnail.cache_delete_failed', 'Admin thumbnail cache deletion failed.', [
            'error' => $exception->getMessage(),
        ]);
        flash_message('admin_notice', t('admin.thumbnails.delete_failed_value', 'Thumbnail deletion failed: {error}', ['error' => $exception->getMessage()]));
    }

    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Return the allowed confirmation words for deleting generated thumbnails.
 *
 * Keeping this vocabulary server-side prevents arbitrary submitted words from
 * confirming the destructive action. The JavaScript button uses the same words
 * from its data attribute and picks one randomly for the prompt.
 *
 * @return array<int string>.
 */
function thumbnail_delete_confirmation_words(): array
{
    return ['archive', 'remove', 'clean', 'thumbs', 'purge', 'reset', 'delete', 'cache', 'media', 'confirm'];
}

/**
 * Return image IDs for a targeted missing-thumbnail repair request.
 *
 * The dashboard warning is based on the dry maintenance check. This selector
 * first reuses the session repair queue or saved dry-check image list. AJAX
 * requests do not run a full live scan because that would stall progress at 0/0.
 *
 * @param array $post Post value.
 * @return array<int int>.
 */
function thumbnail_maintenance_request_image_ids(array $post): array
{
    // $galleryIds stores an optional scoped subset when a future maintenance UI supplies one.
    $galleryIds = null;
    if (!empty($post['gallery_ids']) && is_array($post['gallery_ids'])) {
        $galleryIds = $post['gallery_ids'];
    } elseif ((int) ($post['thumbnail_gallery_id'] ?? 0) > 0) {
        $galleryIds = [(int) $post['thumbnail_gallery_id']];
    } elseif ((int) ($post['gallery_id'] ?? 0) > 0) {
        $galleryIds = [(int) $post['gallery_id']];
    }

    // $repairToken stores the dry-check queue selected by the browser progress flow.
    $repairToken = preg_replace('/[^A-Fa-f0-9]/', '', (string) ($post['thumbnail_repair_token'] ?? '')) ?: '';
    if ($repairToken !== '') {
        // $queuedImageIds stores exact image IDs from the current admin session.
        $queuedImageIds = cms_admin_thumbnail_repair_queue_read($repairToken);
        if ($queuedImageIds !== []) {
            return cms_admin_thumbnail_repair_filter_image_ids($queuedImageIds, $galleryIds);
        }
    }

    // $lastCheckImageIds stores the exact dry-check findings when the saved report is still current.
    $lastCheckImageIds = function_exists('Gallery\\Services\\thumbnail_maintenance_last_check_image_ids') ? thumbnail_maintenance_last_check_image_ids($galleryIds) : [];
    if ($lastCheckImageIds !== []) {
        return $lastCheckImageIds;
    }

    if ((string) ($post['ajax'] ?? '') === '1') {
        return [];
    }

    return thumbnail_maintenance_image_ids($galleryIds, 0);
}

/**
 * Return image IDs selected by one thumbnail generation request.
 *
 * @param array $post Submitted thumbnail request data.
 * @return array<int int> Image IDs selected for generation.
 */
function thumbnail_request_image_ids(array $post): array
{
    // $scope stores the requested thumbnail job scope shared by normal forms and AJAX batch jobs.
    $scope = (string) ($post['scope'] ?? '');
    if ($scope === 'all' || $scope === 'metadata') {
        return all_image_ids();
    }
    if ($scope === 'missing') {
        return thumbnail_maintenance_request_image_ids($post);
    }
    if (!empty($post['gallery_ids']) && is_array($post['gallery_ids'])) {
        return image_ids_for_galleries($post['gallery_ids']);
    }
    // Variable $thumbnailGalleryId stores this steps working value.
    $thumbnailGalleryId = (int) ($post['thumbnail_gallery_id'] ?? 0);
    if ($thumbnailGalleryId > 0) {
        return image_ids_for_galleries([$thumbnailGalleryId]);
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($post['gallery_id'] ?? 0);
    if (!empty($post['image_ids']) && is_array($post['image_ids'])) {
        // Variable $ids stores this steps working value.
        $ids = [];
        foreach (array_map('intval', $post['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if (!$image) {
                continue;
            }
            if ($galleryId > 0 && (int) $image['gallery_id'] !== $galleryId) {
                continue;
            }
            $ids[] = $imageId;
        }
        return array_values(array_unique($ids));
    }
    if ($galleryId > 0) {
        return image_ids_for_galleries([$galleryId]);
    }
    return [];
}
/**
 * Persist the thumbnail compatibility mode.
 */
function cms_admin_thumbnail_compatibility_settings(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    $mode = thumbnail_compatibility_mode_normalize((string) ($_POST['thumbnail_compatibility_mode'] ?? THUMBNAIL_COMPATIBILITY_MODERN));
    set_thumbnail_compatibility_mode($mode);
    thumbnail_maintenance_summary_cache_clear();
    admin_log_event('info', 'thumbnail.compatibility_mode_updated', 'Admin updated thumbnail compatibility mode.', [
        'mode' => $mode,
    ]);
    flash_message('admin_notice', t('admin.thumbnails.compatibility_saved', 'Thumbnail compatibility mode saved. Future thumbnail generation will use {mode}.', ['mode' => thumbnail_compatibility_mode_label($mode)]));
    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Remove generated legacy JPEG thumbnails while preserving originals and WebP derivatives.
 */
function cms_admin_delete_legacy_jpg_thumbnails(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        cms_admin_delete_legacy_jpg_thumbnails_batch();
        return;
    }

    $imageIds = all_image_ids();
    $result = delete_legacy_jpg_thumbnails_for_image_ids($imageIds);
    thumbnail_maintenance_summary_cache_clear();
    admin_log_event('warning', 'thumbnail.legacy_jpg_deleted', 'Admin deleted generated legacy JPEG thumbnails.', [
        'scope' => 'all',
        'image_count' => count($imageIds),
        'files_deleted' => (int) $result['files_deleted'],
        'bytes_deleted' => (int) $result['bytes_deleted'],
    ]);
    flash_message('admin_notice', t('admin.thumbnails.legacy_jpg_deleted_notice', 'Deleted {files} legacy JPG thumbnail file(s), freeing {size}. Originals and WebP files were kept.', [
        'files' => (string) (int) $result['files_deleted'],
        'size' => thumbnail_compatibility_format_bytes((int) $result['bytes_deleted']),
    ]));
    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Process one Ajax batch of legacy JPEG thumbnail cleanup.
 */
function cms_admin_delete_legacy_jpg_thumbnails_batch(): void
{
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        $imageIds = all_image_ids();
        $total = count($imageIds);
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $batchSize = max(1, min(80, (int) ($_POST['batch_size'] ?? 24)));
        $batch = array_slice($imageIds, $offset, $batchSize);
        $result = delete_legacy_jpg_thumbnails_for_image_ids($batch);
        $processed = min($total, $offset + count($batch));
        $done = $processed >= $total;

        if ((int) $result['files_deleted'] > 0 || $done) {
            thumbnail_maintenance_summary_cache_clear();
        }
        if ($done) {
            admin_log_event('warning', 'thumbnail.legacy_jpg_deleted', 'Admin deleted generated legacy JPEG thumbnails.', [
                'scope' => 'all_ajax',
                'image_count' => $total,
                'processed' => $processed,
                'last_batch_files_deleted' => (int) $result['files_deleted'],
                'last_batch_bytes_deleted' => (int) $result['bytes_deleted'],
            ]);
        }

        $discardedOutput = (string) ob_get_clean();
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        if (trim($discardedOutput) !== '') {
            admin_log_event('warning', 'thumbnail.legacy_cleanup_output_discarded', 'Legacy JPEG cleanup produced output before its JSON response.', [
                'discarded_output_preview' => mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500),
            ], ['category' => 'other', 'severity' => 'warning']);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'total' => $total,
            'processed' => $processed,
            'next_offset' => $processed,
            'files_deleted' => (int) $result['files_deleted'],
            'bytes_deleted' => (int) $result['bytes_deleted'],
            'done' => $done,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        $discardedOutput = (string) ob_get_clean();
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'thumbnail.legacy_jpg_delete_failed', 'Legacy JPEG thumbnail cleanup failed.', [
            'error' => $exception->getMessage(),
            'discarded_output_preview' => $discardedOutput !== '' ? mb_substr(trim(preg_replace('/\s+/', ' ', $discardedOutput)), 0, 500) : null,
        ], ['category' => 'other', 'severity' => 'error']);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => t('admin.thumbnails.legacy_jpg_delete_failed', 'Legacy JPG thumbnail cleanup failed. Check the admin logs or PHP error log for details.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

