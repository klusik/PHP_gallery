<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/site_maintenance.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns bounded persistence operations used by scheduled site maintenance.
 *
 * Responsibilities:
 *   - Count original source image rows
 *   - Fetch bounded source-image maintenance batches
 *   - Remove orphaned thumbnail metadata rows
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
 *   - Filesystem and maintenance orchestration stay in the service layer.
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/** Return the number of original image rows in the gallery library. */
function site_maintenance_model_total_source_image_count(): int
{
    $stmt = db()->query("SELECT COUNT(*) FROM images WHERE relative_path NOT LIKE '%/%'");
    return max(0, (int) $stmt->fetchColumn());
}

/** Return one bounded original-image maintenance batch after the supplied cursor. */
function site_maintenance_model_source_images_after_id(int $cursorImageId, int $limit): array
{
    $stmt = db()->prepare("SELECT i.* FROM images i WHERE i.relative_path NOT LIKE '%/%' AND i.id > ? ORDER BY i.id LIMIT ?");
    $stmt->bindValue(1, max(0, $cursorImageId), PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Delete thumbnail metadata rows whose source image no longer exists. */
function site_maintenance_model_delete_thumbnail_variants_missing_images(?int $limit = null): int
{
    if ($limit === null) {
        $stmt = db()->prepare('DELETE v FROM image_thumbnail_variants v LEFT JOIN images i ON i.id = v.image_id WHERE i.id IS NULL');
        $stmt->execute();
        return $stmt->rowCount();
    }

    $stmt = db()->prepare(
        'DELETE FROM image_thumbnail_variants
         WHERE id IN (
             SELECT id FROM (
                 SELECT v.id
                 FROM image_thumbnail_variants v
                 LEFT JOIN images i ON i.id = v.image_id
                 WHERE i.id IS NULL
                 ORDER BY v.id
                 LIMIT ?
             ) orphan_ids
         )'
    );
    $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

/** Delete thumbnail metadata rows whose gallery no longer exists. */
function site_maintenance_model_delete_thumbnail_variants_missing_galleries(?int $limit = null): int
{
    if ($limit === null) {
        $stmt = db()->prepare('DELETE v FROM image_thumbnail_variants v LEFT JOIN galleries g ON g.id = v.gallery_id WHERE g.id IS NULL');
        $stmt->execute();
        return $stmt->rowCount();
    }

    $stmt = db()->prepare(
        'DELETE FROM image_thumbnail_variants
         WHERE id IN (
             SELECT id FROM (
                 SELECT v.id
                 FROM image_thumbnail_variants v
                 LEFT JOIN galleries g ON g.id = v.gallery_id
                 WHERE g.id IS NULL
                 ORDER BY v.id
                 LIMIT ?
             ) orphan_ids
         )'
    );
    $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}


/** Return thumbnail metadata rows whose source image no longer exists. */
function site_maintenance_model_count_thumbnail_variants_missing_images(): int
{
    $stmt = db()->query('SELECT COUNT(*) FROM image_thumbnail_variants v LEFT JOIN images i ON i.id = v.image_id WHERE i.id IS NULL');
    return max(0, (int) $stmt->fetchColumn());
}

/** Return thumbnail metadata rows whose gallery no longer exists. */
function site_maintenance_model_count_thumbnail_variants_missing_galleries(): int
{
    $stmt = db()->query('SELECT COUNT(*) FROM image_thumbnail_variants v LEFT JOIN galleries g ON g.id = v.gallery_id WHERE g.id IS NULL');
    return max(0, (int) $stmt->fetchColumn());
}
