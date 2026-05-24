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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Admin thumbnail controller model.
 * 
 * This module renders thumbnail maintenance notices and handles manual or batched thumbnail generation requests.
 */

function render_admin_thumbnail_maintenance_notice(array $summary): void
{
    if (function_exists('view_render_admin_thumbnail_maintenance_notice')) {
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
 *
 * @return void
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
 * Return true when the current thumbnail maintenance warning is temporarily hidden.
 *
 * Both conditions must match: the dismissal timestamp must still be in the
 * future, and the image inventory fingerprint must match the current summary.
 * If either value is stale, the dismissal settings are removed so later checks
 * start from a clean state.
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
 * @return mixed Result produced by this operation.
 */
function cms_admin_create_thumbnails(): void
{
    require_admin();
    verify_csrf();
    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        cms_admin_create_thumbnails_batch();
        return;
    }
    // Variable $count stores this steps working value.
    $count = 0;
    if (($_POST['scope'] ?? '') === 'all') {
        // Variable $count stores this steps working value.
        $count = create_all_thumbnails();
        thumbnail_maintenance_summary_cache_clear();
        flash_message('admin_notice', t('admin.thumbnails.created_count', 'Created {count} thumbnail(s).', ['count' => (string) $count]));
        redirect_to(url_for('admin'));
    }
    if (($_POST['scope'] ?? '') === 'missing') {
        // Variable $imageIds stores the images that currently need thumbnail regeneration.
        $imageIds = thumbnail_maintenance_image_ids(null, 1000);
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
 * @return mixed Result produced by this operation.
 */
function cms_admin_create_thumbnails_batch(): void
{
    // $bufferLevel stores the output-buffer nesting level before JSON-safe processing starts.
    $bufferLevel = ob_get_level();
    ob_start();
    try {
        // $scope stores the requested batch type so targeted repair can be logged separately.
        $scope = (string) ($_POST['scope'] ?? '');
    // Variable $imageIds stores this steps working value.
    $imageIds = thumbnail_request_image_ids($_POST);
    // Variable $total stores this steps working value.
    $total = count($imageIds);
    // Variable $offset stores this steps working value.
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    // $maintenanceBefore stores the warning state before the first targeted repair batch mutates files.
    $maintenanceBefore = null;
    if ($scope === 'missing' && $offset === 0) {
        $maintenanceBefore = thumbnail_maintenance_summary(null, 1000);
        admin_log_event('info', 'thumbnail.missing_repair_started', 'Targeted thumbnail repair started.', [
            'scope' => $scope,
            'selected_image_count' => $total,
            'selected_image_ids' => array_slice($imageIds, 0, 50),
            'selected_image_ids_truncated' => count($imageIds) > 50,
            'maintenance_before' => $maintenanceBefore,
            'selected_image_debug' => thumbnail_maintenance_debug_image_statuses($imageIds),
        ]);
    }
    // Variable $batchSize stores this steps working value.
    $batchSize = max(1, min(12, (int) ($_POST['batch_size'] ?? 6)));
    // Variable $batch stores this steps working value.
    $batch = array_slice($imageIds, $offset, $batchSize);
    // Variable $created stores this steps working value.
    $created = 0;
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    // $failed stores required thumbnail or DNG display derivatives that could not be generated.
    $failed = 0;
    // $errors stores concise thumbnail generation diagnostics for the JSON response.
    $errors = [];
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    foreach ($batch as $imageId) {
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
        // Variable $result stores this steps working value.
        $result = create_image_thumbnails_result($image, $galleryCache[$galleryId]);
        $created += (int) $result['created'];
        $skipped += (int) $result['skipped'];
        $webpSkipped += (int) ($result['webp_skipped'] ?? 0);
        $failed += (int) ($result['failed'] ?? 0);
        foreach ((array) ($result['errors'] ?? []) as $error) {
            $errors[] = (string) $error;
        }
    }
    if ($created > 0 || $scope === 'missing') {
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
    // Variable $processed stores this steps working value.
    $processed = min($total, $offset + count($batch));
    // $done stores whether this response finishes the requested thumbnail job.
    $done = $processed >= $total;
    // $maintenanceAfter stores a fresh warning state after a targeted repair finishes.
    $maintenanceAfter = null;
    // $remainingImageIds stores any images still considered affected after a targeted repair finishes.
    $remainingImageIds = [];
    if ($scope === 'missing' && $done) {
        $maintenanceAfter = thumbnail_maintenance_summary(null, 1000);
        $remainingImageIds = thumbnail_maintenance_image_ids(null, 1000);
        admin_log_event($remainingImageIds ? 'warning' : 'info', 'thumbnail.missing_repair_completed', 'Targeted thumbnail repair completed.', [
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
            'errors' => array_values(array_unique(array_filter($errors))),
            'created' => $created,
            'skipped' => $skipped,
            'done' => $done,
            'maintenance_after' => $maintenanceAfter,
            'remaining_image_count' => count($remainingImageIds),
        ];
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
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => t('admin.thumbnails.request_failed', 'Thumbnail request failed. Check the admin logs or PHP error log for details.'),
        ]);
        return;
    }
}


/**
 * Handles cms admin delete thumbnails logic for the gallery application.
 *
 * The operation is intentionally separate from thumbnail generation so the
 * destructive path can have its own CSRF check, explicit confirmation token,
 * admin flash message, and operational log entry. The confirmation word is not
 * a security mechanism. It is a human safety rail against accidental clicks.
 *
 * @return void
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
 * @return array<int, string>
 */
function thumbnail_delete_confirmation_words(): array
{
    return ['archive', 'remove', 'clean', 'thumbs', 'purge', 'reset', 'delete', 'cache', 'media', 'confirm'];
}

/**
 * Return image IDs for a targeted missing-thumbnail repair request.
 *
 * The dashboard warning is based on thumbnail_maintenance_summary(), so this
 * selector deliberately uses thumbnail_maintenance_image_ids() instead of the
 * broader gallery/all-image selectors used by normal thumbnail jobs. This keeps
 * the AJAX batch path and the non-AJAX fallback on the same maintenance scope.
 *
 * @param array<string, mixed> $post Submitted thumbnail request fields.
 * @return array<int, int>
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

    return thumbnail_maintenance_image_ids($galleryIds, 1000);
}

/**
 * Handles thumbnail request image ids logic for the gallery application.
 * @param mixed $post Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_request_image_ids(array $post): array
{
    // $scope stores the requested thumbnail job scope shared by normal forms and AJAX batch jobs.
    $scope = (string) ($post['scope'] ?? '');
    if ($scope === 'all') {
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

