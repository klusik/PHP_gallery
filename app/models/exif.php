<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/exif.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence and read queries for EXIF/GPS gallery map features.
 *
 * Responsibilities:
 *   - Read and reset explicit gallery GPS-map overrides
 *   - Build allowlisted gallery-map predicates from semantic scope arguments
 *   - Return map cache fingerprints, availability, and marker source rows
 *   - Keep SQL composition and PDO calls out of the EXIF service layer
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
 *   - No caller-provided SQL fragments are accepted by this model.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Return the number of galleries with an explicit GPS-map override. */
function exif_model_gallery_gps_override_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM galleries WHERE gps_map_enabled IS NOT NULL')->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function exif_model_gallery_gps_override_rows(): array
{
    return db()->query('SELECT * FROM galleries WHERE gps_map_enabled IS NOT NULL ORDER BY folder_path')->fetchAll() ?: [];
}

/**
 * Reset every explicit gallery GPS-map override and advance each changed row's revision.
 *
 * @param string $now Shared SQL timestamp assigned to each changed gallery.
 * @return int Number of gallery rows changed by the reset.
 */
function exif_model_reset_gallery_gps_overrides(string $now): int
{
    $stmt = db()->prepare('UPDATE galleries SET gps_map_enabled = NULL, updated_at = ?, edit_revision = edit_revision + 1 WHERE gps_map_enabled IS NOT NULL');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Build one fixed map-query WHERE clause from semantic scope values.
 *
 * @return array{sql:string,params:array<int,mixed>}
 */
function exif_model_map_scope(
    int $galleryId,
    string $folderPath,
    bool $publicOnly,
    bool $recursive,
    bool $requireListedAccess
): array {
    $conditions = ['i.gps_lat IS NOT NULL', 'i.gps_lng IS NOT NULL'];
    $params = [];
    if ($recursive) {
        $conditions[] = '(g.folder_path = ? OR g.folder_path LIKE ?)';
        $params[] = $folderPath;
        $params[] = $folderPath . '/%';
    } else {
        $conditions[] = 'g.id = ?';
        $params[] = $galleryId;
    }
    if ($publicOnly) {
        $conditions[] = "g.visibility = 'public'";
        if ($requireListedAccess) {
            $conditions[] = "g.access_listing = 'listed'";
        }
        $conditions[] = "i.visibility = 'public'";
    }
    return ['sql' => implode(' AND ', $conditions), 'params' => $params];
}

/**
 * Return cheap aggregate metadata used for one map cache fingerprint.
 *
 * @return array<string,mixed>
 */
function exif_model_map_fingerprint_row(
    int $galleryId,
    string $folderPath,
    bool $publicOnly,
    bool $recursive,
    bool $requireListedAccess
): array {
    $scope = exif_model_map_scope($galleryId, $folderPath, $publicOnly, $recursive, $requireListedAccess);
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS point_count, MAX(i.updated_at) AS image_updated_at, MAX(i.gps_extracted_at) AS gps_extracted_at, '
        . 'MAX(g.updated_at) AS gallery_updated_at FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . $scope['sql']
    );
    $stmt->execute($scope['params']);
    return $stmt->fetch() ?: [];
}

/** Return whether one semantic gallery-map scope has at least one GPS point. */
function exif_model_has_map_points(
    int $galleryId,
    string $folderPath,
    bool $publicOnly,
    bool $recursive,
    bool $requireListedAccess
): bool {
    $scope = exif_model_map_scope($galleryId, $folderPath, $publicOnly, $recursive, $requireListedAccess);
    $stmt = db()->prepare(
        'SELECT 1 FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute($scope['params']);
    return (bool) $stmt->fetchColumn();
}

/** @return array<int,array<string,mixed>> */
function exif_model_map_point_rows(
    int $galleryId,
    string $folderPath,
    bool $publicOnly,
    bool $recursive,
    bool $requireListedAccess
): array {
    $scope = exif_model_map_scope($galleryId, $folderPath, $publicOnly, $recursive, $requireListedAccess);
    $stmt = db()->prepare(
        'SELECT i.*, g.title AS gallery_title, g.id AS gallery_id, g.folder_path AS gallery_folder_path '
        . 'FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE ' . $scope['sql']
        . ' ORDER BY g.folder_path, i.sort_order, i.filename'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll() ?: [];
}
