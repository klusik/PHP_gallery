<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_quality_candidates_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the server-owned progressive lightbox quality candidate model.
 *
 * Responsibilities:
 *   - Preserve real aspect-ratio dimensions for generated previews
 *   - Keep the protected full source as the largest candidate
 *   - Collapse identical preview/full URLs
 *   - Reject missing geometry without emitting unsafe source metadata
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/thumbnail_generation.php';
require_once dirname(__DIR__) . '/app/services/thumbnail_metadata.php';
require_once dirname(__DIR__) . '/app/services/thumbnail_bundles.php';

use function Gallery\Services\lightbox_zoom_quality_candidates;

/**
 * Throw when a server candidate expectation fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_quality_candidates_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$image = ['id' => 42, 'width' => 8000, 'height' => 6000];
$bundle = [
    'variants' => [
        'jpg' => [1600 => '/thumb-1600.jpg'],
        'webp' => [],
    ],
];
$candidates = lightbox_zoom_quality_candidates($image, '/thumb-1600.jpg', '/media-original.jpg', $bundle);
lightbox_zoom_quality_candidates_assert(count($candidates) === 2, 'Preview and full media should produce two candidates.');
lightbox_zoom_quality_candidates_assert($candidates[0] === [
    'src' => '/thumb-1600.jpg',
    'width' => 1600,
    'height' => 1200,
    'kind' => 'preview',
], 'Preview dimensions must preserve the source aspect ratio.');
lightbox_zoom_quality_candidates_assert($candidates[1]['width'] === 8000 && $candidates[1]['kind'] === 'full', 'Full media must retain the known display dimensions.');

$portrait = lightbox_zoom_quality_candidates(['display_width' => 3000, 'display_height' => 4000], '/portrait.webp', '/portrait-full.webp');
lightbox_zoom_quality_candidates_assert($portrait[0]['width'] === 1200 && $portrait[0]['height'] === 1600, 'Portrait previews must use their real pixel width, not the maximum side.');

$sameSource = lightbox_zoom_quality_candidates($image, '/same.jpg', '/same.jpg', $bundle);
lightbox_zoom_quality_candidates_assert(count($sameSource) === 1 && $sameSource[0]['kind'] === 'full', 'Identical URLs must collapse to the full declared geometry.');
lightbox_zoom_quality_candidates_assert(lightbox_zoom_quality_candidates(['width' => 0, 'height' => 0], '/preview.jpg', '/full.jpg') === [], 'Unknown geometry must fail safely.');

echo "Lightbox zoom quality candidate checks passed.\n";
