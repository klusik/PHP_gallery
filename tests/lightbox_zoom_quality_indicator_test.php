<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_quality_indicator_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects visible and accessible feedback during full-quality image decoding.
 *
 * Responsibilities:
 *   - Show feedback only after a larger source is selected
 *   - Clear feedback on success, failure, navigation, close, and teardown
 *   - Keep the indicator pointer-transparent and reduced-motion safe
 *   - Announce the translated loading state without adding another live region
 *   - Keep the fullscreen/mobile-fullscreen byte-progress bar decorative and scoped
 *     away from the normal-mode pill/ring indicator
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$lightboxStyles = (string) file_get_contents($root . '/public/assets/styles/lightbox.css');

/**
 * Throw when a quality-loading indicator expectation fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_quality_indicator_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$loadingStart = strpos($lightboxSource, 'setLightboxQualityLoading(true);');
$decodeStart = strpos($lightboxSource, 'loadTrackedDecodedLightboxImage(desired.src, {');
lightbox_zoom_quality_indicator_assert(
    $loadingStart !== false && $decodeStart !== false && $loadingStart < $decodeStart,
    'The quality indicator must become active immediately before the tracked background download starts.'
);
lightbox_zoom_quality_indicator_assert(
    substr_count($lightboxSource, 'setLightboxQualityLoading(false);') >= 3,
    'Success, failure, and cancellation paths must clear the quality indicator.'
);
lightbox_zoom_quality_indicator_assert(
    str_contains($lightboxSource, "stageLink.setAttribute('aria-busy', 'true')")
        && str_contains($lightboxSource, "stageLink.removeAttribute('aria-busy')")
        && str_contains($lightboxSource, 'stageLink.dataset.qualityLoadingLabel = label')
        && str_contains($lightboxSource, 'zoomAnnouncement.textContent = label'),
    'The stage and existing polite status must expose translated loading state to assistive technology.'
);
lightbox_zoom_quality_indicator_assert(
    str_contains($lightboxStyles, '.lightbox.is-quality-loading:not(.is-initial-loading):not(.is-navigation-loading):not(.is-fullscreen):not(.is-mobile-fullscreen) .lightbox-stage-link::before')
        && str_contains($lightboxStyles, 'animation: lightbox-quality-spinner')
        && str_contains($lightboxStyles, 'content: attr(data-quality-loading-label)')
        && str_contains($lightboxStyles, 'pointer-events: none;'),
    'The visible pill/ring indicator must include a pointer-transparent translated label, a circular spinner, and stay excluded from fullscreen/mobile-fullscreen, which use the byte-progress bar instead.'
);
lightbox_zoom_quality_indicator_assert(
    str_contains($lightboxStyles, '@media (prefers-reduced-motion: reduce)')
        && str_contains($lightboxStyles, '.lightbox.is-quality-loading:not(.is-initial-loading):not(.is-navigation-loading):not(.is-fullscreen):not(.is-mobile-fullscreen) .lightbox-stage-link::before')
        && str_contains($lightboxStyles, 'animation: none;'),
    'Reduced-motion users must receive a static activity ring and label.'
);
lightbox_zoom_quality_indicator_assert(
    str_contains($lightboxSource, "qualityProgress.className = 'lightbox-quality-progress';")
        && str_contains($lightboxSource, 'qualityProgress.hidden = true;')
        && str_contains($lightboxSource, "qualityProgress.setAttribute('aria-hidden', 'true');")
        && str_contains($lightboxSource, 'qualityProgress.hidden = !isLoading;'),
    'Fullscreen and mobile fullscreen must expose a dedicated, decorative byte-progress element that toggles with the loading state.'
);
lightbox_zoom_quality_indicator_assert(
    str_contains($lightboxStyles, '.lightbox.is-quality-loading.is-fullscreen:not(.is-initial-loading):not(.is-navigation-loading) .lightbox-quality-progress')
        && str_contains($lightboxStyles, '.lightbox.is-quality-loading.is-mobile-fullscreen:not(.is-initial-loading):not(.is-navigation-loading) .lightbox-quality-progress')
        && str_contains($lightboxStyles, '.lightbox-quality-progress[hidden]'),
    'The byte-progress bar must be scoped to fullscreen/mobile-fullscreen and stay hidden through the [hidden] attribute otherwise.'
);

echo "Lightbox zoom quality indicator checks passed.\n";
