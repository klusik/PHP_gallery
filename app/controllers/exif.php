<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/exif.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

namespace Gallery\Controllers;

use function Gallery\Core\current_user;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_map_payload;
use function Gallery\Services\visitor_can_access_gallery;

/**
 * EXIF and GPS endpoint controller module.
 *
 * This controller streams the JSON payload used by gallery map views. It is
 * separated from the general controller file so HTML rendering and metadata
 * JSON endpoints can evolve independently without changing route names.
 */

/**
 * Return JSON map points for a gallery branch.
 *
 * The endpoint uses the same public/private access rules as the gallery page and
 * only returns points when GPS maps are enabled on this gallery or an ancestor.
 */
function cms_gallery_map_data(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || (!visitor_can_access_gallery($gallery) && !current_user())) {
        cms_not_found();
        return;
    }
    // Variable $publicOnly stores this steps working value.
    $publicOnly = !current_user();
    // Variable $payload stores this steps working value.
    $payload = gallery_map_payload($gallery, $publicOnly, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
