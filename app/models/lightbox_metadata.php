<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/lightbox_metadata.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns gallery lightbox image metadata queries.
 *
 * Responsibilities:
 *   - Build visibility and NSFW predicates from semantic flags\n *   - Return aggregate state, ordered image windows, and stable image positions\n *   - Keep lightbox SQL and PDO operations out of service policy\n *
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
 *   - HTTP transport, authorization policy, and filesystem workflows remain outside this model.
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/** Build the internal image WHERE clause for one semantic lightbox scope. */
function lightbox_metadata_model_where(bool $publicOnly, bool $excludeRestrictedNsfw): string
{
    $where = "i.gallery_id = ? AND i.relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $where .= " AND i.visibility = 'public'";
    }
    if ($excludeRestrictedNsfw) {
        $where .= ' AND COALESCE(i.nsfw_enabled, 0) = 0';
    }
    return $where;
}

/** @return array{count:int,revision:string} */
function lightbox_metadata_model_state_summary(int $galleryId, bool $publicOnly, bool $excludeRestrictedNsfw): array
{
    $stmt = db()->prepare("SELECT COUNT(*) AS image_count, COALESCE(MAX(i.updated_at), '') AS image_revision FROM images i WHERE " . lightbox_metadata_model_where($publicOnly, $excludeRestrictedNsfw));
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch() ?: [];
    return ['count' => max(0, (int) ($row['image_count'] ?? 0)), 'revision' => trim((string) ($row['image_revision'] ?? ''))];
}

/**
 * Return total images in one lightbox scope.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Whether to require public images.
 * @param bool $excludeRestrictedNsfw Whether to exclude restricted NSFW rows.
 * @return int Number of matching images.
 */
function lightbox_metadata_model_total_count(int $galleryId, bool $publicOnly, bool $excludeRestrictedNsfw): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM images i WHERE ' . lightbox_metadata_model_where($publicOnly, $excludeRestrictedNsfw));
    $stmt->execute([$galleryId]);
    return max(0, (int) $stmt->fetchColumn());
}

/** @return array<int,array<string,mixed>> */
function lightbox_metadata_model_fetch_images(int $galleryId, bool $publicOnly, int $offset, ?int $limit, bool $excludeRestrictedNsfw): array
{
    $sql = "SELECT i.*, (
            SELECT COALESCE(SUM(v.vote), 0)
            FROM image_votes v
            WHERE v.image_id = i.id
        ) AS score
        FROM images i
        WHERE " . lightbox_metadata_model_where($publicOnly, $excludeRestrictedNsfw) . "
        ORDER BY i.sort_order, i.filename, i.id";
    if ($limit !== null) {
        $sql .= ' LIMIT ? OFFSET ?';
    }
    $stmt = db()->prepare($sql);
    $stmt->bindValue(1, $galleryId, \PDO::PARAM_INT);
    if ($limit !== null) {
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), \PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/**
 * Return zero-based position for one image inside one lightbox scope.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $image Image row used as the ordering anchor.
 * @param bool $publicOnly Whether to require public images.
 * @param bool $excludeRestrictedNsfw Whether to exclude restricted NSFW rows.
 * @return int Zero-based position.
 */
function lightbox_metadata_model_image_position(int $galleryId, array $image, bool $publicOnly, bool $excludeRestrictedNsfw): int
{
    $sql = 'SELECT COUNT(*) FROM images i WHERE ' . lightbox_metadata_model_where($publicOnly, $excludeRestrictedNsfw) . '
          AND (
              i.sort_order < ?
              OR (i.sort_order = ? AND i.filename < ?)
              OR (i.sort_order = ? AND i.filename = ? AND i.id < ?)
          )';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        $galleryId,
        (int) ($image['sort_order'] ?? 0),
        (int) ($image['sort_order'] ?? 0),
        (string) ($image['filename'] ?? ''),
        (int) ($image['sort_order'] ?? 0),
        (string) ($image['filename'] ?? ''),
        (int) ($image['id'] ?? 0),
    ]);
    return max(0, (int) $stmt->fetchColumn());
}
