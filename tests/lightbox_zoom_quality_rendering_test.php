<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_quality_rendering_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the server-rendered and lazy progressive zoom source contract.
 *
 * Responsibilities:
 *   - Require visible physical and Smart Gallery cards to pass their thumbnail bundle
 *   - Require server markup to emit JSON quality candidates
 *   - Require lazy pagination payloads to expose the same candidate model
 *   - Preserve the legacy preview/full attributes during progressive enhancement
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$rendererSource = (string) file_get_contents($root . '/app/controllers/public_gallery_lightbox.php');
$galleryPageSource = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$smartGallerySource = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$lazyEndpointSource = (string) file_get_contents($root . '/app/controllers/gallery_lightbox.php');
$lightboxStyleSource = (string) file_get_contents($root . '/public/assets/styles/lightbox.css');
$mobileGalleryStyleSource = (string) file_get_contents($root . '/public/assets/styles/mobile-gallery.css');

/**
 * Throw when a rendering integration expectation fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_quality_rendering_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

lightbox_zoom_quality_rendering_assert(
    str_contains($rendererSource, 'data-lightbox-quality-sources=')
        && str_contains($rendererSource, 'lightbox_zoom_quality_candidates($image, $previewUrl, $mediaUrl, $thumbnailBundle)'),
    'Shared lightbox markup must emit service-owned quality candidates.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($rendererSource, 'data-full-src=') && str_contains($rendererSource, 'data-preview-src='),
    'Progressive quality markup must preserve legacy preview/full attributes.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($rendererSource, 'class="lightbox-zoom-surface" data-lightbox-zoom-surface')
        && str_contains($rendererSource, 'data-lightbox-img'),
    'The active image must render inside the stable zoom surface used for quality swaps.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($lightboxStyleSource, '.lightbox-zoom-surface {')
        && !str_contains($lightboxStyleSource, '.lightbox-zoom-surface {\n    grid-area: 1 / 1;\n    display: grid;\n    place-items: center;\n    width: 100%;\n    height: 100%;\n    min-width: 0;\n    min-height: 0;\n    transform-origin: center center;\n    will-change: transform;')
        && str_contains($lightboxStyleSource, '.lightbox.is-zoom-animating .lightbox-zoom-surface')
        && str_contains($lightboxStyleSource, 'transition: width 120ms ease-out, height 120ms ease-out, transform 120ms ease-out;'),
    'Desktop zoom must animate the real image dimensions instead of permanently scaling a preview compositor layer.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($mobileGalleryStyleSource, '.lightbox.is-mobile-device .lightbox-zoom-surface > img')
        && !str_contains($mobileGalleryStyleSource, '.lightbox.is-mobile-device .lightbox-stage-link > img'),
    'Mobile swipe styling must keep targeting the image child inside the stable zoom surface.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($galleryPageSource, '$lightboxIndex >= 0 ? $lightboxIndex : null, $thumbnailBundle)'),
    'Physical gallery cards must pass the already-resolved thumbnail bundle.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($smartGallerySource, "'data-lightbox-image', \$voting, \$index, \$bundle"),
    'Smart Gallery cards must use the same bundle-backed source contract.'
);
lightbox_zoom_quality_rendering_assert(
    str_contains($lazyEndpointSource, "'quality_sources' => lightbox_zoom_quality_candidates(\$image, \$previewUrl, \$mediaUrl, \$thumbnailBundle)"),
    'Lazy lightbox pagination must return the same service-owned candidates.'
);

$galleryAccessPosition = strpos($lazyEndpointSource, 'visitor_can_access_gallery($gallery)');
$candidatePosition = strpos($lazyEndpointSource, '$items[] = gallery_lightbox_json_item(');
lightbox_zoom_quality_rendering_assert(
    $galleryAccessPosition !== false && $candidatePosition !== false && $galleryAccessPosition < $candidatePosition,
    'Lazy candidate URLs must be produced only after gallery access is accepted.'
);

echo "Lightbox zoom quality rendering checks passed.\n";
