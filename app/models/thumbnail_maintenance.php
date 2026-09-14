<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/thumbnail_maintenance.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable thumbnail-maintenance inventory queries and metadata cleanup.
 *
 * Responsibilities:
 *   - Select direct image inventory rows for bounded maintenance scans
 *   - Return scoped inventory counts and fingerprints
 *   - Remove generated thumbnail-variant metadata before filesystem cleanup
 *   - Return gallery folder paths required by filesystem maintenance
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
 *   - Filesystem inspection and thumbnail generation remain service responsibilities.
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/**
 * Build the internal direct-image scope for thumbnail maintenance.
 *
 * @param ?array $galleryIds Optional normalized gallery identifiers.
 * @return array{sql:string,params:array<int,int>} SQL fragment and bound identifiers owned by the model.
 */
function thumbnail_maintenance_model_scope(?array $galleryIds): array
{
    $where = "i.relative_path NOT LIKE '%/%'";
    $params = [];
    if ($galleryIds !== null) {
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if ($galleryIds === []) {
            return ['sql' => '1 = 0', 'params' => []];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }
    return ['sql' => $where, 'params' => $params];
}

/**
 * Return direct image rows for one optional gallery scope.
 *
 * @param ?array $galleryIds Optional gallery identifiers.
 * @param ?int $limit Optional positive row limit.
 * @param bool $includeTitle Whether gallery title is required by the caller.
 * @return array<int,array<string,mixed>> Matching image rows.
 */
function thumbnail_maintenance_model_rows(?array $galleryIds, ?int $limit = null, bool $includeTitle = false): array
{
    $scope = thumbnail_maintenance_model_scope($galleryIds);
    $galleryColumns = $includeTitle ? 'g.title AS gallery_title, ' : '';
    $sql = 'SELECT i.*, ' . $galleryColumns . 'g.folder_path AS gallery_folder_path '
        . 'FROM images i JOIN galleries g ON g.id = i.gallery_id '
        . 'WHERE ' . $scope['sql'] . ' ORDER BY g.folder_path, i.sort_order, i.filename';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($scope['params']);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return selected direct image rows with gallery presentation metadata.
 *
 * @param array $imageIds Image identifiers.
 * @return array<int,array<string,mixed>> Matching direct image rows.
 */
function thumbnail_maintenance_model_rows_by_ids(array $imageIds): array
{
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($imageIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path
        FROM images i
        JOIN galleries g ON g.id = i.gallery_id
        WHERE i.id IN ($placeholders) AND i.relative_path NOT LIKE '%/%'
        ORDER BY g.folder_path, i.sort_order, i.filename");
    $stmt->execute($imageIds);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return total direct-image count for one optional gallery scope.
 *
 * @param ?array $galleryIds Optional gallery identifiers.
 * @return int Matching image count.
 */
function thumbnail_maintenance_model_count(?array $galleryIds): int
{
    $scope = thumbnail_maintenance_model_scope($galleryIds);
    $stmt = db()->prepare('SELECT COUNT(*) FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . $scope['sql']);
    $stmt->execute($scope['params']);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Return one bounded direct-image maintenance batch.
 *
 * @param ?array $galleryIds Optional gallery identifiers.
 * @param int $offset Zero-based offset.
 * @param int $batchSize Positive batch size.
 * @return array<int,array<string,mixed>> Matching image rows.
 */
function thumbnail_maintenance_model_batch(?array $galleryIds, int $offset, int $batchSize): array
{
    $scope = thumbnail_maintenance_model_scope($galleryIds);
    $offset = max(0, $offset);
    $batchSize = max(1, $batchSize);
    $sql = 'SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path '
        . 'FROM images i JOIN galleries g ON g.id = i.gallery_id '
        . 'WHERE ' . $scope['sql'] . ' ORDER BY g.folder_path, i.sort_order, i.filename '
        . 'LIMIT ' . $batchSize . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($scope['params']);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return aggregate direct-image inventory state used by warning dismissal.
 *
 * @param ?array $galleryIds Optional gallery identifiers.
 * @return array{image_count:int,newest_id:int,newest_created_at:string} Aggregate inventory state.
 */
function thumbnail_maintenance_model_inventory_state(?array $galleryIds): array
{
    $scope = thumbnail_maintenance_model_scope($galleryIds);
    $where = str_replace('i.', '', $scope['sql']);
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(created_at), '') AS newest_created_at FROM images WHERE $where");
    $stmt->execute($scope['params']);
    $row = $stmt->fetch() ?: [];
    return [
        'image_count' => max(0, (int) ($row['image_count'] ?? 0)),
        'newest_id' => max(0, (int) ($row['newest_id'] ?? 0)),
        'newest_created_at' => (string) ($row['newest_created_at'] ?? ''),
    ];
}

/**
 * Delete all durable generated thumbnail-variant metadata rows.
 */
function thumbnail_maintenance_model_clear_variant_metadata(): void
{
    db()->exec('DELETE FROM image_thumbnail_variants');
}

/**
 * Return every gallery folder path in deterministic order.
 *
 * @return array<int,string> Gallery folder paths.
 */
function thumbnail_maintenance_model_gallery_folder_paths(): array
{
    $stmt = db()->query('SELECT folder_path FROM galleries ORDER BY folder_path');
    return $stmt ? array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])) : [];
}
