<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/favorite_galleries.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence reads for Theme-configured favorite gallery shortcuts.
 *
 * Responsibilities:
 *   - Load selected gallery rows in one bounded query
 *   - Keep optional url_path and gallery-access schema projections inside the data layer
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Callers pass only validated gallery identifiers and schema capability booleans.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Resolve gallery rows for selected IDs.
 *
 * @param array<int,int> $ids Positive gallery identifiers.
 * @param bool $urlPathReady Whether galleries.url_path exists.
 * @param bool $accessSchemaReady Whether the gallery-access columns exist.
 * @return array<int,array<string,mixed>> Rows keyed by gallery id.
 */
function favorite_gallery_model_rows_by_ids(array $ids, bool $urlPathReady, bool $accessSchemaReady): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) return [];

    $selects = ['id', 'parent_id', 'folder_path', 'slug', 'title', 'visibility'];
    $selects[] = $urlPathReady ? 'url_path' : "'' AS url_path";
    if ($accessSchemaReady) {
        $selects[] = 'access_mode';
        $selects[] = 'access_listing';
        $selects[] = 'access_password_hash';
        $selects[] = 'access_token_hash';
        $selects[] = 'access_token_expires_at';
    } else {
        $selects[] = "'normal' AS access_mode";
        $selects[] = "'listed' AS access_listing";
        $selects[] = 'NULL AS access_password_hash';
        $selects[] = 'NULL AS access_token_hash';
        $selects[] = 'NULL AS access_token_expires_at';
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT ' . implode(', ', $selects) . ' FROM galleries WHERE id IN (' . $placeholders . ')');
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $galleryId = (int) ($row['id'] ?? 0);
        if ($galleryId > 0) $rows[$galleryId] = $row;
    }
    return $rows;
}
