<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/download_signatures.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns metadata reads used to build deterministic download cache signatures.
 *
 * Responsibilities:
 *   - Read gallery/image metadata for one already-authorized gallery ID set\n *   - Apply public-image visibility filtering inside the data-access layer\n *   - Keep signature SQL out of download service orchestration\n *
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

/** @param array<int,int> $galleryIds @return array<int,array<string,mixed>> */
function download_signatures_model_rows(array $galleryIds, bool $publicOnly): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $imageVisibilitySql = $publicOnly ? " AND i.visibility = 'public'" : '';
    $stmt = db()->prepare("SELECT g.id AS gallery_id, g.parent_id, g.folder_path, g.visibility AS gallery_visibility,
            g.access_mode, g.updated_at AS gallery_updated_at, i.id AS image_id, i.relative_path, i.relative_path_hash,
            i.file_size, i.modified_at, i.checksum_sha256, i.visibility, i.updated_at AS image_updated_at
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id" . $imageVisibilitySql . "
        WHERE g.id IN ($placeholders)
        ORDER BY g.folder_path, i.relative_path");
    $stmt->execute($ids);
    return $stmt->fetchAll() ?: [];
}
