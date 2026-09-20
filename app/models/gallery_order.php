<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/gallery_order.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence used by gallery reorder workflows.
 *
 * Responsibilities:
 *   - Read the complete gallery tree state required for reorder validation
 *   - Persist complete tree sibling sort orders atomically
 *   - Persist direct-child gallery sort orders atomically without changing parents
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
 *   - Request validation, filesystem moves, logging, and responses remain outside the model layer.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Return complete persisted gallery tree state for reorder validation.
 *
 * @return array<int,array<string,mixed>> Gallery rows ordered by identifier.
 */
function gallery_order_model_tree_rows(): array
{
    return db()->query('SELECT id, parent_id, sort_order, title, folder_path FROM galleries ORDER BY id')->fetchAll();
}

/**
 * Persist sibling sort order for a submitted complete gallery tree.
 *
 * Parent relationships are not written here. Filesystem moves and the existing
 * parent-id synchronization workflow remain authoritative for hierarchy changes.
 *
 * @param array<int,array{id:int,parent_id:int}> $submittedEntries Submitted flattened tree order.
 * @param string $now Shared SQL timestamp for all touched rows.
 * @return array<int,int> Persisted sort order keyed by gallery identifier.
 */
function gallery_order_model_save_tree(array $submittedEntries, string $now): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
        $siblingPositionByParent = [];
        $nextSortOrderById = [];
        foreach ($submittedEntries as $entry) {
            $galleryId = (int) ($entry['id'] ?? 0);
            $parentId = (int) ($entry['parent_id'] ?? 0);
            $position = ($siblingPositionByParent[$parentId] ?? 0) + 1;
            $siblingPositionByParent[$parentId] = $position;
            $sortOrder = $position * 10;
            $nextSortOrderById[$galleryId] = $sortOrder;
            $stmt->execute([$sortOrder, $now, $galleryId]);
        }
        $pdo->commit();
        return $nextSortOrderById;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Persist the complete direct-child order for one parent gallery.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @param array<int,int> $orderedIds Complete direct-child gallery identifiers.
 * @param string $now Shared SQL timestamp for all touched rows.
 * @return void
 */
function gallery_order_model_save_children(int $parentGalleryId, array $orderedIds, string $now): void
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ? AND parent_id = ?');
        foreach ($orderedIds as $index => $galleryId) {
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $galleryId, $parentGalleryId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
