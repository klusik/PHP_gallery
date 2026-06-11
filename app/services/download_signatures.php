<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/download_signatures.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Download cache signature service.
 *
 * The ZIP download subsystem uses this helper to decide whether an existing
 * cached archive still represents the current gallery subtree. The function is
 * intentionally kept separate from the streaming controller code because it is
 * pure metadata calculation over galleries and images.
 */

/**
 * Build a content signature for one gallery ZIP cache entry.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Public only value.
 * @return string Text result for the caller.
 */
function gallery_zip_signature(int $galleryId, bool $publicOnly): string
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return hash('sha256', 'missing-gallery-' . $galleryId);
    }

    // Variable $galleries stores this steps working value.
    $galleries = gallery_zip_gallery_rows($gallery, $publicOnly);
    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_zip_gallery_ids($galleries);
    if (!$galleryIds) {
        return hash('sha256', 'empty-visible-gallery-' . $galleryId . '-' . ($publicOnly ? 'public' : 'admin'));
    }

    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $imageVisibilitySql stores this steps working value.
    $imageVisibilitySql = $publicOnly ? " AND i.visibility = 'public'" : '';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT g.folder_path, g.updated_at AS gallery_updated_at, i.relative_path, i.file_size, i.modified_at, i.visibility
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id" . $imageVisibilitySql . "
        WHERE g.id IN ($placeholders)
        ORDER BY g.folder_path, i.relative_path");
    $stmt->execute($galleryIds);

    return hash('sha256', json_encode($stmt->fetchAll(), JSON_UNESCAPED_SLASHES));
}
