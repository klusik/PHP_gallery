<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_descriptions.php
 * Module Type: Controller
 *
 * Purpose:
 *   Prepares external-link presentation metadata for public gallery descriptions.
 *
 * Responsibilities:
 *   - Discover supported link targets from the gallery-description Markdown subset
 *   - Resolve safe normalized URLs and optional cached favicons through Services
 *   - Keep public description Views independent from Service-layer favicon lookups
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
 *   - This controller prepares data only and must not render description HTML.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Services\link_favicon_cached_public_url;
use function Gallery\Services\link_favicon_known_icon_id;
use function Gallery\Services\link_favicon_normalize_url;

/**
 * Prepare normalized link presentation metadata for one description.
 *
 * @param string $markdown Gallery description using the supported Markdown/BBCode subset.
 * @return array<string, array{icon_id:?string,cached_url:?string}>
 */
function public_gallery_description_link_models(string $markdown): array
{
    if (trim($markdown) === '') {
        return [];
    }

    $candidates = [];
    if (preg_match_all('/\[(?:link|url)=([^\]\n]{1,2048})\]/iu', $markdown, $matches)) {
        foreach ((array) ($matches[1] ?? []) as $value) {
            $candidates[] = (string) $value;
        }
    }
    if (preg_match_all('/\[(?:link|url)\]([^\[\]\n]{1,2048})\[\/(?:link|url)\]/iu', $markdown, $matches)) {
        foreach ((array) ($matches[1] ?? []) as $value) {
            $candidates[] = trim((string) $value);
        }
    }
    if (preg_match_all('/\[[^\]\n]{1,160}\]\((https?:\/\/[^\s<>")]+)\)/iu', $markdown, $matches)) {
        foreach ((array) ($matches[1] ?? []) as $value) {
            $candidates[] = (string) $value;
        }
    }

    $models = [];
    foreach (array_unique($candidates) as $candidate) {
        $normalized = link_favicon_normalize_url($candidate);
        if ($normalized === null || isset($models[$normalized])) {
            continue;
        }
        $iconId = link_favicon_known_icon_id($normalized);
        $models[$normalized] = [
            'icon_id' => $iconId,
            'cached_url' => $iconId === null ? link_favicon_cached_public_url($normalized) : null,
        ];
    }
    return $models;
}
