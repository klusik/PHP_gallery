<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/image_order.php
 * Module Type: Service
 *
 * Purpose:
 *   Orchestrates validated image-order persistence and admin audit logging.
 *
 * Responsibilities:
 *   - Convert an already-validated reorder command into model persistence
 *   - Record success and failure events without exposing SQL to controllers
 *   - Preserve the existing admin reorder logging contract
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
 *   - Request parsing and JSON/redirect responses remain controller concerns.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Models\image_order_model_save;

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
    // Variable $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        image_order_model_save($galleryId, $orderedIds, $now);
        admin_log_event('info', $eventKey, $eventMessage, array_merge([
            'gallery_id' => $galleryId,
            'images' => count($orderedIds),
        ], $context));
    } catch (Throwable $exception) {
        admin_log_event('error', 'image.reorder_failed', 'Admin image reorder failed.', [
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
            'event_key' => $eventKey,
        ]);
        throw $exception;
    }
}
