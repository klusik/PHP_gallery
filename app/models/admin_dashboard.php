<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_dashboard.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns bounded database reads used to assemble the Admin dashboard read model.
 *
 * Responsibilities:
 *   - Aggregate indexed source-file storage bytes
 *   - Fetch dashboard gallery rows with migration-aware optional columns
 *   - Return gallery hierarchy fingerprint inputs
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
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/** Return indexed source-file bytes from image metadata. */
function admin_dashboard_model_original_storage_bytes(): int
{
    try {
        $stmt = db()->prepare('SELECT COALESCE(SUM(file_size), 0) AS original_bytes FROM images');
        $stmt->execute();
        $row = $stmt->fetch();
        return max(0, (int) ($row['original_bytes'] ?? 0));
    } catch (Throwable) {
        return 0;
    }
}

/** Return dashboard gallery rows with only schema-ready optional columns. */
function admin_dashboard_model_gallery_rows(array $capabilities): array
{
    $selects = [
        'g.id', 'g.parent_id', 'g.folder_path', 'g.slug', 'g.title', 'g.sort_order', 'g.visibility',
        'parent.title AS parent_title', 'COALESCE(image_counts.image_count, 0) AS image_count',
    ];
    $selects[] = !empty($capabilities['public_path']) ? 'g.url_path' : "'' AS url_path";
    $selects[] = !empty($capabilities['access']) ? 'g.access_mode' : "'normal' AS access_mode";
    $selects[] = !empty($capabilities['access']) ? 'g.access_listing' : "'listed' AS access_listing";
    $selects[] = !empty($capabilities['gps_map']) ? 'g.gps_map_enabled' : '0 AS gps_map_enabled';
    $selects[] = !empty($capabilities['background_source']) ? 'g.background_source' : 'NULL AS background_source';
    $selects[] = !empty($capabilities['filename_display']) ? 'g.show_filenames' : '0 AS show_filenames';
    $selects[] = !empty($capabilities['voting']) ? 'g.voting_enabled' : '0 AS voting_enabled';
    $selects[] = !empty($capabilities['picture_game']) ? 'g.picture_game_enabled' : '0 AS picture_game_enabled';
    $selects[] = !empty($capabilities['cover_asset']) ? 'g.cover_image_path' : 'NULL AS cover_image_path';

    $sql = 'SELECT ' . implode(', ', $selects) . "
        FROM galleries g
        LEFT JOIN galleries parent ON parent.id = g.parent_id
        LEFT JOIN (
            SELECT gallery_id, COUNT(id) AS image_count
            FROM images
            WHERE relative_path NOT LIKE '%/%'
            GROUP BY gallery_id
        ) image_counts ON image_counts.gallery_id = g.id
        ORDER BY COALESCE(g.parent_id, 0), g.sort_order, g.title";
    return db()->query($sql)->fetchAll();
}

/** Return aggregate gallery hierarchy values used for the parent-sync fingerprint. */
function admin_dashboard_model_parent_sync_fingerprint_row(): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(updated_at), '') AS newest_updated_at, COALESCE(SUM(CHAR_LENGTH(folder_path)), 0) AS path_length_sum FROM galleries");
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}
