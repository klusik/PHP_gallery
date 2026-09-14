<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/duplicate_photo_detector.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns read-only database access used by duplicate-photo detection.
 *
 * Responsibilities:
 *   - Resolve gallery branch identifiers from canonical folder paths
 *   - Snapshot bounded duplicate-detector image scopes
 *   - Fetch metadata batches by immutable scope and cursor
 *   - Fetch detailed image/gallery rows for bounded result identifiers
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
 *   - Callers normalize identifiers and enforce public/admin policy before invoking the model.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/** @return array<int,int> */
function duplicate_photo_model_gallery_branch_ids(int $galleryId): array
{
    $stmt = db()->prepare(
        "SELECT child.id
         FROM galleries root
         INNER JOIN galleries child
             ON child.folder_path = root.folder_path
             OR child.folder_path LIKE CONCAT(root.folder_path, '/%')
         WHERE root.id = ?
         ORDER BY CHAR_LENGTH(child.folder_path), child.folder_path, child.id"
    );
    $stmt->execute([$galleryId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/**
 * Return total images and the immutable maximum image id for one detector scope.
 *
 * @param array<int,int> $galleryIds Empty for global scope.
 * @return array{total:int,max_image_id:int}
 */
function duplicate_photo_model_scope_snapshot(array $galleryIds): array
{
    if ($galleryIds === []) {
        $stmt = db()->query('SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_image_id FROM images');
    } else {
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_image_id FROM images WHERE gallery_id IN (' . $placeholders . ')'
        );
        $stmt->execute($galleryIds);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => max(0, (int) ($row['total'] ?? 0)),
        'max_image_id' => max(0, (int) ($row['max_image_id'] ?? 0)),
    ];
}

/**
 * Fetch one bounded metadata batch for duplicate detection.
 *
 * @param array<int,int> $galleryIds Empty for global scope.
 * @return array<int,array<string,mixed>>
 */
function duplicate_photo_model_fetch_batch(array $galleryIds, int $cursor, int $maxImageId, int $batchSize): array
{
    $columns = 'id, gallery_id, checksum_sha256, file_size, width, height, mime_type, exif_taken_at, exif_camera_make, exif_camera_model, exif_lens_model, exif_focal_length, exif_aperture, exif_exposure_time, exif_iso, gps_lat, gps_lng';
    if ($galleryIds === []) {
        $stmt = db()->prepare('SELECT ' . $columns . ' FROM images WHERE id > ? AND id <= ? ORDER BY id ASC LIMIT ?');
        $stmt->bindValue(1, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(2, $maxImageId, PDO::PARAM_INT);
        $stmt->bindValue(3, $batchSize, PDO::PARAM_INT);
    } else {
        $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
        $stmt = db()->prepare(
            'SELECT ' . $columns . ' FROM images WHERE gallery_id IN (' . $placeholders . ') AND id > ? AND id <= ? ORDER BY id ASC LIMIT ?'
        );
        $parameter = 1;
        foreach ($galleryIds as $galleryId) {
            $stmt->bindValue($parameter++, $galleryId, PDO::PARAM_INT);
        }
        $stmt->bindValue($parameter++, $cursor, PDO::PARAM_INT);
        $stmt->bindValue($parameter++, $maxImageId, PDO::PARAM_INT);
        $stmt->bindValue($parameter, $batchSize, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch detailed image/gallery rows for bounded result ids.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @return array<int,array<string,mixed>> Rows keyed by image id.
 */
function duplicate_photo_model_images_by_ids(array $imageIds): array
{
    if ($imageIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $stmt = db()->prepare(
        'SELECT i.id, i.gallery_id, i.relative_path, i.filename, i.url_slug, i.width, i.height, i.mime_type, i.file_size, '
        . 'i.checksum_sha256, i.exif_taken_at, i.exif_camera_make, i.exif_camera_model, i.exif_lens_model, '
        . 'i.exif_focal_length, i.exif_aperture, i.exif_exposure_time, i.exif_iso, i.gps_lat, i.gps_lng, i.visibility, '
        . 'g.title AS gallery_title, g.folder_path AS gallery_folder_path, g.slug AS gallery_slug, g.url_path AS gallery_url_path '
        . 'FROM images i INNER JOIN galleries g ON g.id = i.gallery_id WHERE i.id IN (' . $placeholders . ')'
    );
    foreach ($imageIds as $index => $imageId) {
        $stmt->bindValue($index + 1, $imageId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['id'] ?? 0)] = $row;
    }
    return $rows;
}
