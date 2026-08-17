<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_lifecycle_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects reset and recalculation boundaries for public lightbox zoom state.
 *
 * Responsibilities:
 *   - Require canonical reset before every openAt image transition
 *   - Require close and slideshow startup to clear presentation state
 *   - Require decoded-image replacement to remeasure the active viewport
 *   - Preserve the shared navigation path for arrows, strip, swipe, and slideshow
 *
 * Last Updated:
 *   2026-08-16
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');

/**
 * Throw when a lightbox zoom lifecycle contract is absent.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$openStart = strpos($lightboxSource, 'function openAt(index, options = {})');
$openEnd = strpos($lightboxSource, 'function step(offset, options = {})', $openStart === false ? 0 : $openStart);
lightbox_zoom_lifecycle_assert($openStart !== false && $openEnd !== false, 'Canonical openAt/step navigation functions are missing.');
$openSource = substr($lightboxSource, (int) $openStart, (int) $openEnd - (int) $openStart);
$openResetPosition = strpos($openSource, 'resetLightboxZoom(false);');
$openCardPosition = strpos($openSource, 'const card = cards[normalizedIndex]');
lightbox_zoom_lifecycle_assert(
    $openResetPosition !== false && $openCardPosition !== false && $openResetPosition < $openCardPosition,
    'Zoom state must reset before the next card or lazy metadata is displayed.'
);

$closeStart = strpos($lightboxSource, 'function close()');
$closeEnd = strpos($lightboxSource, "document.addEventListener('click'", $closeStart === false ? 0 : $closeStart);
lightbox_zoom_lifecycle_assert($closeStart !== false && $closeEnd !== false, 'Canonical lightbox close boundary is missing.');
$closeSource = substr($lightboxSource, (int) $closeStart, (int) $closeEnd - (int) $closeStart);
lightbox_zoom_lifecycle_assert(str_contains($closeSource, 'resetLightboxZoom(false);'), 'Closing the lightbox must clear zoom state.');

$slideshowStart = strpos($lightboxSource, 'async function startLightboxSlideshow()');
$slideshowEnd = strpos($lightboxSource, 'function stopLightboxSlideshow', $slideshowStart === false ? 0 : $slideshowStart);
lightbox_zoom_lifecycle_assert($slideshowStart !== false && $slideshowEnd !== false, 'Slideshow lifecycle functions are missing.');
$slideshowSource = substr($lightboxSource, (int) $slideshowStart, (int) $slideshowEnd - (int) $slideshowStart);
lightbox_zoom_lifecycle_assert(str_contains($slideshowSource, 'resetLightboxZoom(false);'), 'Starting slideshow must clear manual zoom state.');

lightbox_zoom_lifecycle_assert(
    str_contains($lightboxSource, 'updateNormalLightboxStageSizeFromLoadedImage(loadedImage)')
        && str_contains($lightboxSource, 'scheduleLightboxZoomReclamp();'),
    'Decoded preview/full-image replacement must schedule a bounded zoom recalculation.'
);
lightbox_zoom_lifecycle_assert(str_contains($lightboxSource, 'openAt(nextIndex, options);'), 'Previous/next navigation must keep using openAt.');
lightbox_zoom_lifecycle_assert(str_contains($lightboxSource, 'openAt(stripIndex);'), 'Picture-strip navigation must keep using openAt.');
lightbox_zoom_lifecycle_assert(str_contains($lightboxSource, "zoomSurface.style.removeProperty('transform');"), 'Canonical reset must remove the zoom-surface transform.');

echo "Lightbox zoom lifecycle checks passed.\n";
