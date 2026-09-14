<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/image_order.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for direct image sort order within a gallery.
 *
 * Responsibilities:
 *   - Persist one complete image order atomically
 *   - Keep transaction handling and image sort-order SQL inside the model layer
 *   - Avoid HTTP, presentation, logging, and feature-policy concerns
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
 *   - Ordered image identifiers must already be validated by the caller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Persist a complete image order for one gallery in one transaction.
 *
 * @param int $galleryId Gallery whose direct image order is being saved.
 * @param array<int> $orderedIds Complete ordered image ids for this gallery.
 * @param string $now Shared SQL timestamp for every touched row.
 */
function image_order_model_save(int $galleryId, array $orderedIds, string $now): void
{
    // Variable $pdo stores the active database connection used for the atomic sort_order update.
    $pdo = db();
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
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
