<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/duplicate_photo_ledger.php
 * Module Type: Service
 *
 * Purpose:
 *   Persists administrator decisions to suppress reviewed duplicate-photo pairs or galleries.
 *
 * Responsibilities:
 *   - Store canonical ignored image pairs per administrator
 *   - Store independently ignored exact gallery ids per administrator
 *   - Load compact ledger snapshots for duplicate-result filtering
 *   - Clear only the authenticated administrator's duplicate ledger
 *   - Keep gallery rules exact so parent and child galleries remain independent
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
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use PDO;
use function Gallery\Core\db;

const DUPLICATE_PHOTO_LEDGER_PAIR_TABLE = 'duplicate_photo_ledger_pairs';
const DUPLICATE_PHOTO_LEDGER_GALLERY_TABLE = 'duplicate_photo_ledger_galleries';

/**
 * Return whether both persistent duplicate-ledger tables are available.
 *
 * @return bool True when the ledger migration has been applied.
 */
function duplicate_photo_ledger_schema_ready(): bool
{
    return db_table_exists(DUPLICATE_PHOTO_LEDGER_PAIR_TABLE)
        && db_table_exists(DUPLICATE_PHOTO_LEDGER_GALLERY_TABLE);
}

/**
 * Normalize two distinct positive image ids into stable ascending order.
 *
 * @param int $firstImageId First image identifier.
 * @param int $secondImageId Second image identifier.
 * @return array{0:int,1:int} Canonical pair ids.
 */
function duplicate_photo_ledger_normalize_pair(int $firstImageId, int $secondImageId): array
{
    if ($firstImageId <= 0 || $secondImageId <= 0 || $firstImageId === $secondImageId) {
        throw new InvalidArgumentException('A duplicate ledger pair requires two distinct positive image ids.');
    }

    return $firstImageId < $secondImageId
        ? [$firstImageId, $secondImageId]
        : [$secondImageId, $firstImageId];
}

/**
 * Build the stable in-memory key used for one canonical ignored pair.
 *
 * @param int $firstImageId First image identifier.
 * @param int $secondImageId Second image identifier.
 * @return string Canonical pair key.
 */
function duplicate_photo_ledger_pair_key(int $firstImageId, int $secondImageId): string
{
    [$lowId, $highId] = duplicate_photo_ledger_normalize_pair($firstImageId, $secondImageId);
    return $lowId . ':' . $highId;
}

/**
 * Return an empty ledger snapshot, optionally marking schema availability.
 *
 * @param bool $ready Whether the persistent schema is available.
 * @return array{ready:bool,pairs:array<string,bool>,galleries:array<int,bool>,pair_count:int,gallery_count:int} Ledger snapshot.
 */
function duplicate_photo_ledger_empty_snapshot(bool $ready = true): array
{
    return [
        'ready' => $ready,
        'pairs' => [],
        'galleries' => [],
        'pair_count' => 0,
        'gallery_count' => 0,
    ];
}

/**
 * Load all duplicate-ledger rules for one authenticated administrator.
 *
 * Pair rows are kept as canonical string keys for constant-time result filtering.
 * Gallery rows contain exact gallery ids only; descendants are intentionally not
 * implied so a parent and one of its child galleries can be ledgered separately.
 *
 * @param int $adminUserId Authenticated administrator user id.
 * @return array{ready:bool,pairs:array<string,bool>,galleries:array<int,bool>,pair_count:int,gallery_count:int} Ledger snapshot.
 */
function duplicate_photo_ledger_snapshot(int $adminUserId): array
{
    if ($adminUserId <= 0 || !duplicate_photo_ledger_schema_ready()) {
        return duplicate_photo_ledger_empty_snapshot(false);
    }

    $snapshot = duplicate_photo_ledger_empty_snapshot(true);

    $pairStmt = db()->prepare(
        'SELECT image_id_low, image_id_high
         FROM duplicate_photo_ledger_pairs
         WHERE user_id = ?
         ORDER BY image_id_low, image_id_high'
    );
    $pairStmt->execute([$adminUserId]);
    foreach ($pairStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $lowId = (int) ($row['image_id_low'] ?? 0);
        $highId = (int) ($row['image_id_high'] ?? 0);
        if ($lowId <= 0 || $highId <= 0 || $lowId === $highId) {
            continue;
        }
        $snapshot['pairs'][$lowId . ':' . $highId] = true;
    }

    $galleryStmt = db()->prepare(
        'SELECT gallery_id
         FROM duplicate_photo_ledger_galleries
         WHERE user_id = ?
         ORDER BY gallery_id'
    );
    $galleryStmt->execute([$adminUserId]);
    foreach ($galleryStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $galleryId) {
        $galleryId = (int) $galleryId;
        if ($galleryId > 0) {
            $snapshot['galleries'][$galleryId] = true;
        }
    }

    $snapshot['pair_count'] = count($snapshot['pairs']);
    $snapshot['gallery_count'] = count($snapshot['galleries']);
    return $snapshot;
}

/**
 * Return whether one candidate pair is suppressed by the supplied ledger snapshot.
 *
 * A gallery rule matches only the exact gallery id stored in the ledger. It does
 * not match descendants, preserving independent parent/child gallery decisions.
 *
 * @param array<string,mixed> $ledger Ledger snapshot.
 * @param int $leftImageId Left image identifier.
 * @param int $rightImageId Right image identifier.
 * @param int $leftGalleryId Left image gallery identifier.
 * @param int $rightGalleryId Right image gallery identifier.
 * @return bool True when the pair must be omitted from detector results.
 */
function duplicate_photo_ledger_ignores_pair(array $ledger, int $leftImageId, int $rightImageId, int $leftGalleryId, int $rightGalleryId): bool
{
    if (empty($ledger['ready'])) {
        return false;
    }

    $galleries = is_array($ledger['galleries'] ?? null) ? $ledger['galleries'] : [];
    if (($leftGalleryId > 0 && !empty($galleries[$leftGalleryId])) || ($rightGalleryId > 0 && !empty($galleries[$rightGalleryId]))) {
        return true;
    }

    try {
        $pairKey = duplicate_photo_ledger_pair_key($leftImageId, $rightImageId);
    } catch (InvalidArgumentException) {
        return false;
    }
    $pairs = is_array($ledger['pairs'] ?? null) ? $ledger['pairs'] : [];
    return !empty($pairs[$pairKey]);
}

/**
 * Persist one canonical ignored image pair for an administrator.
 *
 * @param int $adminUserId Authenticated administrator user id.
 * @param int $firstImageId First image identifier.
 * @param int $secondImageId Second image identifier.
 * @return void
 */
function duplicate_photo_ledger_add_pair(int $adminUserId, int $firstImageId, int $secondImageId): void
{
    if ($adminUserId <= 0) {
        throw new InvalidArgumentException('A duplicate ledger pair requires an authenticated administrator.');
    }
    if (!duplicate_photo_ledger_schema_ready()) {
        throw new RuntimeException('Duplicate photo ledger migration is required.');
    }

    [$lowId, $highId] = duplicate_photo_ledger_normalize_pair($firstImageId, $secondImageId);
    $stmt = db()->prepare(
        'INSERT IGNORE INTO duplicate_photo_ledger_pairs (user_id, image_id_low, image_id_high, created_at)
         VALUES (?, ?, ?, NOW())'
    );
    $stmt->execute([$adminUserId, $lowId, $highId]);
}

/**
 * Persist one exact ignored gallery id for an administrator.
 *
 * @param int $adminUserId Authenticated administrator user id.
 * @param int $galleryId Exact gallery identifier to suppress.
 * @return void
 */
function duplicate_photo_ledger_add_gallery(int $adminUserId, int $galleryId): void
{
    if ($adminUserId <= 0 || $galleryId <= 0) {
        throw new InvalidArgumentException('A duplicate ledger gallery rule requires positive administrator and gallery ids.');
    }
    if (!duplicate_photo_ledger_schema_ready()) {
        throw new RuntimeException('Duplicate photo ledger migration is required.');
    }

    $stmt = db()->prepare(
        'INSERT IGNORE INTO duplicate_photo_ledger_galleries (user_id, gallery_id, created_at)
         VALUES (?, ?, NOW())'
    );
    $stmt->execute([$adminUserId, $galleryId]);
}

/**
 * Remove every persistent duplicate-ledger rule owned by one administrator.
 *
 * @param int $adminUserId Authenticated administrator user id.
 * @return array{pairs:int,galleries:int} Deleted rule counts.
 */
function duplicate_photo_ledger_clear(int $adminUserId): array
{
    if ($adminUserId <= 0) {
        throw new InvalidArgumentException('Clearing the duplicate ledger requires an authenticated administrator.');
    }
    if (!duplicate_photo_ledger_schema_ready()) {
        throw new RuntimeException('Duplicate photo ledger migration is required.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pairStmt = $pdo->prepare('DELETE FROM duplicate_photo_ledger_pairs WHERE user_id = ?');
        $pairStmt->execute([$adminUserId]);
        $galleryStmt = $pdo->prepare('DELETE FROM duplicate_photo_ledger_galleries WHERE user_id = ?');
        $galleryStmt->execute([$adminUserId]);
        $pdo->commit();
    } catch (\Throwable $exception) {
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
