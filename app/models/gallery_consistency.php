<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_consistency.php
 * Module Type: Model
 * Purpose: Read catalog snapshots for explicit media consistency jobs.
 * Responsibilities: Page source rows and observe catalog changes without writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/**
 * Read physical gallery identities, including unpublished/private galleries.
 * @return list<array{id:int|string,title:string,folder_path:string,
 *     parent_id:int|string|null,updated_at:string,...}> Catalog rows.
 */
function gallery_consistency_model_galleries(): array
{
    return db()->query(
        'SELECT * FROM galleries ORDER BY folder_path, id',
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Read a bounded source batch, retaining rows with a missing gallery parent.
 * @param int $afterId Exclusive source cursor.
 * @param int $limit Maximum number of records requested by the job owner.
 * @return list<array{id:int|string,gallery_id:int|string,filename:string,
 *     relative_path:string,modified_at?:string,checksum_sha256?:string|null,
 *     file_size?:int|string,thumbnail_derivative_version?:int|string,...}>
 *     Persisted source identities in identifier order.
 */
function gallery_consistency_model_images(int $afterId, int $limit): array
{
    $query = db()->prepare(
        'SELECT * FROM images WHERE id > :after_id ORDER BY id LIMIT :lim',
    );
    $query->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    $query->bindValue(':lim', $limit, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Observe source membership and edits without decoding any media.
 * @param bool $includeImages Include source membership; false keeps a names-only
 *     lease independent of the photograph catalog and its size.
 * @return array{fingerprint:string,images:int} Catalog lease and source count.
 */
function gallery_consistency_model_snapshot(bool $includeImages = true): array
{
    $hash = hash_init('sha256');
    foreach (gallery_consistency_model_galleries() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
    }
    $count = 0;
    if (!$includeImages) {
        return ['fingerprint' => hash_final($hash), 'images' => 0];
    }
    // Stream source identities instead of retaining the complete catalog twice.
    $query = db()->query(
        'SELECT id, gallery_id, filename, relative_path, modified_at,'
            . ' file_size, checksum_sha256 FROM images ORDER BY id',
    );
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
        $count++;
    }
    return ['fingerprint' => hash_final($hash), 'images' => $count];
}

/**
 * Read every metadata variant attached to one source, including obsolete sizes.
 * @param int $imageId Persisted source identifier.
 * @return list<array{id:int|string,image_id:int|string,size_px:int|string,
 *     format:string,status:string,width:int|string|null,height:int|string|null,
 *     file_size:int|string,derivative_version?:int|string,...}> Variant rows.
 */
function gallery_consistency_model_variants(int $imageId): array
{
    $query = db()->prepare(
        'SELECT * FROM image_thumbnail_variants WHERE image_id = ?',
    );
    $query->execute([$imageId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Count derivative metadata whose original catalog row no longer exists.
 * @return int Number of orphan metadata rows; no files are changed.
 */
function gallery_consistency_model_orphan_variants(): int
{
    return (int) db()->query(
        'SELECT COUNT(*) FROM image_thumbnail_variants v'
            . ' LEFT JOIN images i ON i.id = v.image_id WHERE i.id IS NULL',
    )->fetchColumn();
}
