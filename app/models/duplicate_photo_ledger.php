<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/duplicate_photo_ledger.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for administrator duplicate-photo suppression rules.
 *
 * Responsibilities:
 *   - Load canonical ignored image-pair rows
 *   - Load exact ignored gallery identifiers
 *   - Persist pair and gallery suppression rules
 *   - Clear one administrator's ledger atomically
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
 *   - Schema availability and mutation policy remain service responsibilities.
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Return canonical ignored image pairs for one administrator.
 *
 * @param int $adminUserId Administrator user identifier.
 * @return array<int,array<string,mixed>> Ledger pair rows.
 */
function duplicate_photo_ledger_model_pairs(int $adminUserId): array
{
    $stmt = db()->prepare(
        'SELECT image_id_low, image_id_high
         FROM duplicate_photo_ledger_pairs
         WHERE user_id = ?
         ORDER BY image_id_low, image_id_high'
    );
    $stmt->execute([$adminUserId]);
    return $stmt->fetchAll();
}

/**
 * Return exact ignored gallery identifiers for one administrator.
 *
 * @param int $adminUserId Administrator user identifier.
 * @return array<int,int> Ignored gallery identifiers.
 */
function duplicate_photo_ledger_model_gallery_ids(int $adminUserId): array
{
    $stmt = db()->prepare(
        'SELECT gallery_id
         FROM duplicate_photo_ledger_galleries
         WHERE user_id = ?
         ORDER BY gallery_id'
    );
    $stmt->execute([$adminUserId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * Persist one canonical ignored image pair for an administrator.
 *
 * @param int $adminUserId Administrator user identifier.
 * @param int $lowImageId Lower canonical image identifier.
 * @param int $highImageId Higher canonical image identifier.
 */
function duplicate_photo_ledger_model_add_pair(int $adminUserId, int $lowImageId, int $highImageId): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO duplicate_photo_ledger_pairs (user_id, image_id_low, image_id_high, created_at)
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$adminUserId, $lowImageId, $highImageId]);
}

/**
 * Persist one exact ignored gallery identifier for an administrator.
 *
 * @param int $adminUserId Administrator user identifier.
 * @param int $galleryId Exact gallery identifier.
 */
function duplicate_photo_ledger_model_add_gallery(int $adminUserId, int $galleryId): void
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO duplicate_photo_ledger_galleries (user_id, gallery_id, created_at)
         VALUES (?, ?, NOW())'
    );
    $stmt->execute([$adminUserId, $galleryId]);
}

/**
 * Atomically clear every duplicate-ledger rule owned by one administrator.
 *
 * @param int $adminUserId Administrator user identifier.
 * @return array{pairs:int,galleries:int} Deleted row counts.
 */
function duplicate_photo_ledger_model_clear(int $adminUserId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pairStmt = $pdo->prepare('DELETE FROM duplicate_photo_ledger_pairs WHERE user_id = ?');
        $pairStmt->execute([$adminUserId]);
        $galleryStmt = $pdo->prepare('DELETE FROM duplicate_photo_ledger_galleries WHERE user_id = ?');
        $galleryStmt->execute([$adminUserId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'pairs' => $pairStmt->rowCount(),
        'galleries' => $galleryStmt->rowCount(),
    ];
}
