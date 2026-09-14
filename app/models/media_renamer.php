<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/media_renamer.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database reads and atomic row updates for the media renamer.
 *
 * Responsibilities:
 *   - Read galleries and indexed image ownership/path facts
 *   - Apply two-phase image path updates atomically
 *   - Read and delete ZIP archive metadata invalidated by renames
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
 *   - Filesystem moves, collision policy, naming policy, and rollback orchestration remain services.
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Throwable;
use function Gallery\Core\db;

/**
 * Return gallery rows with direct-image counts.
 *
 * @param bool $hideEmptyGalleries Whether galleries with no direct images are excluded.
 * @return array<int,array<string,mixed>> Gallery rows.
 */
function media_renamer_model_gallery_rows(bool $hideEmptyGalleries): array
{
    $having = $hideEmptyGalleries ? ' HAVING direct_image_count > 0' : '';
    $stmt = db()->query("SELECT g.*, COUNT(i.id) AS direct_image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%'
        GROUP BY g.id" . $having . "
        ORDER BY CHAR_LENGTH(g.folder_path), g.folder_path, g.title, g.id");
    return $stmt ? ($stmt->fetchAll() ?: []) : [];
}

/**
 * Return every gallery identifier in stable filesystem order.
 *
 * @param bool $hideEmptyGalleries Whether galleries with no direct images are excluded.
 * @return array<int,int> Gallery identifiers.
 */
function media_renamer_model_all_gallery_ids(bool $hideEmptyGalleries): array
{
    if (!$hideEmptyGalleries) {
        $stmt = db()->query('SELECT id FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    } else {
        $stmt = db()->query("SELECT g.id
            FROM galleries g
            INNER JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%'
            GROUP BY g.id
            ORDER BY CHAR_LENGTH(g.folder_path), g.folder_path, g.id");
    }
    return $stmt ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
}

/**
 * Return indexed image paths for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<int,array{id:int,relative_path:string}> Indexed rows.
 */
function media_renamer_model_indexed_paths(int $galleryId): array
{
    $stmt = db()->prepare('SELECT id, relative_path FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return requested gallery identifiers that still exist.
 *
 * @param array $galleryIds Requested gallery identifiers.
 * @return array<int,int> Existing gallery identifiers.
 */
function media_renamer_model_existing_gallery_ids(array $galleryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id FROM galleries WHERE id IN (' . $placeholders . ') ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Return image ownership rows for a selected image batch.
 *
 * @param array $imageIds Image identifiers.
 * @return array<int,array{id:int,gallery_id:int}> Ownership rows.
 */
function media_renamer_model_image_gallery_rows(array $imageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id, gallery_id FROM images WHERE id IN (' . $placeholders . ') ORDER BY gallery_id, sort_order, id');
    $stmt->execute($ids);
    return $stmt->fetchAll() ?: [];
}

/**
 * Apply prepared two-phase image rename rows in one transaction.
 *
 * @param array<int,array{id:int,temp_relative_path:string,relative_path:string,filename:string,title:?string}> $updates Prepared row updates.
 * @param string $now Current SQL timestamp.
 */
function media_renamer_model_apply_updates(array $updates, string $now): void
{
    if ($updates === []) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tempStmt = $pdo->prepare('UPDATE images SET relative_path = ?, relative_path_hash = ?, filename = ?, updated_at = ? WHERE id = ?');
        foreach ($updates as $update) {
            $tempPath = (string) $update['temp_relative_path'];
            $tempStmt->execute([$tempPath, hash('sha256', $tempPath), $tempPath, $now, (int) $update['id']]);
        }
        $finalStmt = $pdo->prepare('UPDATE images SET relative_path = ?, relative_path_hash = ?, filename = ?, title = ?, updated_at = ? WHERE id = ?');
        foreach ($updates as $update) {
            $relativePath = (string) $update['relative_path'];
            $finalStmt->execute([
                $relativePath,
                hash('sha256', $relativePath),
                (string) $update['filename'],
                $update['title'],
                $now,
                (int) $update['id'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return ZIP archive rows invalidated by gallery renames.
 *
 * @param array $galleryIds Gallery identifiers.
 * @return array<int,array{id:int,file_path:string}> Archive rows.
 */
function media_renamer_model_download_archives(array $galleryIds): array
{
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    $params = [];
    $where = "scope = 'all'";
    if ($galleryIds !== []) {
        $where .= ' OR gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }
    $stmt = db()->prepare('SELECT id, file_path FROM zip_archives WHERE ' . $where);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * Delete selected ZIP archive metadata rows.
 *
 * @param array $archiveIds ZIP archive row identifiers.
 * @return int Number of deleted rows.
 */
function media_renamer_model_delete_download_archives(array $archiveIds): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $archiveIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    $stmt = db()->prepare('DELETE FROM zip_archives WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    return (int) $stmt->rowCount();
}
