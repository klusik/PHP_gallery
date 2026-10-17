<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_storage_statistics.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database reads used by Admin source/generated-media storage statistics.
 *
 * Responsibilities:
 *   - Return source/gallery fingerprint aggregates
 *   - Return image/gallery rows for storage scanning in bounded or full form
 *   - Aggregate durable thumbnail-variant counts and bytes
 *   - Page physical-gallery attachment owners independently of image membership
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
 *   - Filesystem size scanning and presentation grouping remain service responsibilities.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Count physical galleries independently of indexed photographs.
 * @return int Number of registered physical galleries, including empty ones.
 */
function admin_storage_statistics_model_gallery_count(): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM galleries');
    $statement->execute();
    return (int) $statement->fetchColumn();
}

/**
 * Read one keyset batch of physical attachment-storage owners.
 * @param int $lastGalleryId Exclusive cursor from the previous batch.
 * @param int $limit Positive service-owned gallery observation limit.
 * @return list<array{id:int|string,folder_path:string}> Ordered physical owners.
 */
function admin_storage_statistics_model_gallery_rows_after_id(
    int $lastGalleryId,
    int $limit
): array {
    $statement = db()->prepare(
        'SELECT id, folder_path FROM galleries'
        . ' WHERE id > ? ORDER BY id LIMIT ?'
    );
    $statement->bindValue(1, max(0, $lastGalleryId), \PDO::PARAM_INT);
    $statement->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

/** Return aggregate image/gallery values used for the storage cache fingerprint. */
function admin_storage_statistics_model_fingerprint_rows(): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(SUM(COALESCE(file_size, 0)), 0) AS original_bytes, COALESCE(MAX(id), 0) AS newest_image_id, COALESCE(MAX(updated_at), '') AS newest_image_update FROM images");
    $stmt->execute();
    $imageRow = $stmt->fetch() ?: [];
    $stmt = db()->prepare("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(updated_at), '') AS newest_gallery_update FROM galleries");
    $stmt->execute();
    $galleryRow = $stmt->fetch() ?: [];
    return [is_array($imageRow) ? $imageRow : [], is_array($galleryRow) ? $galleryRow : []];
}

/** Return image rows required by source/generated-media statistics. */
function admin_storage_statistics_model_image_rows(bool $hasDerivativeVersion): array
{
    $derivative = $hasDerivativeVersion ? 'COALESCE(i.thumbnail_derivative_version, 1)' : '1';
    $stmt = db()->prepare("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, $derivative AS thumbnail_derivative_version, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id ORDER BY g.folder_path, i.relative_path");
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Return one image-row batch after the supplied image id. */
function admin_storage_statistics_model_image_rows_after_id(int $lastImageId, int $limit, bool $hasDerivativeVersion): array
{
    $derivative = $hasDerivativeVersion ? 'COALESCE(i.thumbnail_derivative_version, 1)' : '1';
    $stmt = db()->prepare("SELECT i.id AS image_id, i.gallery_id AS image_gallery_id, i.relative_path, i.filename, i.mime_type, COALESCE(i.file_size, 0) AS file_size, i.width, i.height, $derivative AS thumbnail_derivative_version, g.id AS gallery_id, g.title AS gallery_title, g.folder_path AS gallery_folder_path FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id > ? ORDER BY i.id LIMIT ?");
    $stmt->execute([max(0, $lastImageId), max(1, $limit)]);
    return $stmt->fetchAll();
}

/** Return durable thumbnail-variant aggregate rows for configured sizes. */
function admin_storage_statistics_model_thumbnail_summary_rows(array $sizes, bool $matchDerivativeVersion): array
{
    $sizes = array_values(array_unique(array_filter(array_map('intval', $sizes), static fn (int $size): bool => $size > 0)));
    if ($sizes === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($sizes), '?'));
    $derivativePredicate = $matchDerivativeVersion ? ' AND v.derivative_version = GREATEST(1, i.thumbnail_derivative_version)' : '';
    $stmt = db()->prepare("SELECT v.format, COUNT(*) AS variant_count, COALESCE(SUM(COALESCE(v.file_size, 0)), 0) AS total_bytes FROM image_thumbnail_variants v INNER JOIN images i ON i.id = v.image_id WHERE v.status = 'valid' AND v.format IN ('jpg', 'webp') AND v.size_px IN ($placeholders)$derivativePredicate GROUP BY v.format ORDER BY v.format");
    $stmt->execute($sizes);
    return $stmt->fetchAll();
}
