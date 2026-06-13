<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_images_reorder.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles image order persistence and image reorder JSON responses.
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
use function Gallery\Core\db;
use function Gallery\Core\flash_message;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_images;

/**
 * Handles cms admin image reorder logic for the gallery application.
 *
 * The edit-gallery image table sends the complete ordered image-id list after a
 * drag-and-drop operation. This endpoint validates that every submitted image
 * belongs to the selected gallery before it touches sort_order values, so a
 * forged request cannot reorder images in another gallery.
 */
function cms_admin_reorder_images(): void
{
    require_admin();
    verify_csrf();
    // Variable $galleryId stores the gallery whose direct image order is being changed.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // Variable $gallery stores the database row for the submitted gallery id.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        cms_not_found();
        return;
    }

    // $submittedIds stores the ordered image ids exactly as submitted by the browser.
    $submittedIds = admin_decode_reorder_id_list((string) ($_POST['image_order'] ?? '[]'));
    if ($submittedIds === null) {
        admin_reorder_images_response(false, 'The submitted image order was not valid JSON or contained duplicate images.', $galleryId);
        return;
    }

    // $currentRows stores every direct image currently owned by this gallery.
    $currentRows = gallery_images($galleryId, false);
    // $currentOrderedIds stores the complete direct-image order before the requested change.
    $currentOrderedIds = array_map(static fn (array $image): int => (int) $image['id'], $currentRows);
    // $reorderScope stores whether this request is the full Admin table or the public visible-page path.
    $reorderScope = (string) ($_POST['reorder_scope'] ?? 'full');

    if ($reorderScope === 'visible_page') {
        // $visibleOffset stores the first image position rendered on the current pagination page.
        $visibleOffset = (int) ($_POST['visible_offset'] ?? -1);
        // $visibleCount stores the number of images rendered on the current pagination page.
        $visibleCount = (int) ($_POST['visible_count'] ?? 0);
        // $nextIds stores the full gallery image order with only the current visible page rearranged.
        $nextIds = admin_visible_page_reordered_ids($currentOrderedIds, $submittedIds, $visibleOffset, $visibleCount);
        if ($nextIds === null) {
            admin_reorder_images_response(false, 'The visible photo page changed while you were reordering. Reload the page and try again.', $galleryId);
            return;
        }
        try {
            admin_save_image_order($galleryId, $nextIds, 'image.public_page_reordered', 'Admin reordered visible public-page photos.', [
                'visible_offset' => $visibleOffset,
                'visible_count' => $visibleCount,
                'submitted_image_ids' => $submittedIds,
            ]);
            admin_reorder_images_response(true, 'Visible photo order saved.', $galleryId);
        } catch (Throwable $exception) {
            admin_reorder_images_response(false, 'Image order could not be saved: ' . $exception->getMessage(), $galleryId);
        }
        return;
    }

    // $currentIds stores the complete direct-image set currently visible in the edit-gallery table.
    $currentIds = $currentOrderedIds;
    sort($currentIds);
    // Variable $sortedSubmittedIds stores the submitted id set for exact set comparison with the database state.
    $sortedSubmittedIds = $submittedIds;
    sort($sortedSubmittedIds);
    if ($sortedSubmittedIds !== $currentIds) {
        admin_reorder_images_response(false, 'The image list changed while you were reordering. Reload the page and try again.', $galleryId);
        return;
    }

    try {
        admin_save_image_order($galleryId, $submittedIds, 'image.reordered', 'Admin reordered gallery images.', [
            'images' => count($submittedIds),
        ]);
        admin_reorder_images_response(true, 'Image order saved.', $galleryId);
    } catch (Throwable $exception) {
        admin_reorder_images_response(false, 'Image order could not be saved: ' . $exception->getMessage(), $galleryId);
    }
}

/**
 * Persists a complete image order for one gallery.
 *
 * @param int $galleryId Gallery whose direct image order is being saved.
 * @param array<int> $orderedIds Complete ordered image ids for this gallery.
 * @param string $eventKey Admin log event key.
 * @param string $eventMessage Admin log event message.
 * @param array<string,mixed> $context Additional event context.
 */
function admin_save_image_order(int $galleryId, array $orderedIds, string $eventKey, string $eventMessage, array $context = []): void
{
    // Variable $pdo stores the active database connection used for the atomic sort_order update.
    $pdo = db();
    // Variable $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // Variable $stmt stores the prepared update reused for each reordered image row.
        $stmt = $pdo->prepare('UPDATE images SET sort_order = ?, updated_at = ? WHERE id = ? AND gallery_id = ?');
        foreach ($orderedIds as $index => $imageId) {
            // Variable $sortOrder stores a spaced integer so future maintenance can insert between rows if needed.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $imageId, $galleryId]);
        }
        $pdo->commit();
        admin_log_event('info', $eventKey, $eventMessage, array_merge([
            'gallery_id' => $galleryId,
            'images' => count($orderedIds),
        ], $context));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'image.reorder_failed', 'Admin image reorder failed.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
            'event_key' => $eventKey,
        ]);
        throw $exception;
    }
}

/**
 * Returns the image-reorder result as JSON for drag-and-drop requests or as a
 * normal admin redirect for non-JavaScript fallback submissions.
 *
 * @param bool $ok Whether the reorder operation completed successfully.
 * @param string $message Human-readable status message for the admin UI.
 * @param int $galleryId Gallery id used to build the redirect fallback.
 */
function admin_reorder_images_response(bool $ok, string $message, int $galleryId): void
{
    // Variable $acceptHeader stores the browser Accept header used to detect the JavaScript request path.
    $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    // Variable $isJsonRequest stores whether the client explicitly expects a JSON response.
    $isJsonRequest = str_contains($acceptHeader, 'application/json') || (string) ($_POST['ajax'] ?? '') === '1';
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to(admin_edit_gallery_tab_url($galleryId, 'admin-edit-images'));
}
