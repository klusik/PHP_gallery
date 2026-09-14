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
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Models\download_signatures_model_rows;

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

    $rows = download_signatures_model_rows($galleryIds, $publicOnly);

    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
}
