<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_integration_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects event, map, voting, and media-access boundaries around public lightbox zoom.
 *
 * Responsibilities:
 *   - Keep wheel and pointer interception scoped to the existing image stage
 *   - Preserve browser zoom modifiers and control-target exclusions
 *   - Recalculate zoom when fullscreen map split changes the image viewport
 *   - Prevent zoom state from adding persistence or media-fetch behavior
 *   - Preserve gallery and NSFW access checks in the existing lightbox endpoint
 *
 * Last Updated:
 *   2026-08-16
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$lightboxStyleSource = (string) file_get_contents($root . '/public/assets/styles/lightbox.css');
$zoomModelSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox-zoom-model.js');
$lightboxEndpointSource = (string) file_get_contents($root . '/app/controllers/gallery_lightbox.php');
$publicEntrypointSource = (string) file_get_contents($root . '/public/assets/public-gallery.js');
$authenticatedEntrypointSource = (string) file_get_contents($root . '/public/assets/gallery.js');
$assetRendererSource = (string) file_get_contents($root . '/app/helpers_page_rendering.php');
$layoutSource = (string) file_get_contents($root . '/app/views/layout.php');
$runtimeHelpersSource = (string) file_get_contents($root . '/app/helpers_runtime.php');

/**
 * Throw when a lightbox integration boundary is absent.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

lightbox_zoom_integration_assert(
    str_contains($lightboxSource, "stageLink.addEventListener('wheel', handleLightboxZoomWheel, {passive: false"),
    'Wheel zoom must remain a non-passive listener scoped to the image stage.'
);
lightbox_zoom_integration_assert(
    !str_contains($lightboxSource, "document.addEventListener('wheel', handleLightboxZoomWheel")
        && !str_contains($lightboxSource, "overlay.addEventListener('wheel', handleLightboxZoomWheel"),
    'Wheel zoom must not intercept the document, map sibling, or complete overlay.'
);
lightbox_zoom_integration_assert(
    str_contains($lightboxSource, 'event.ctrlKey || event.metaKey || event.altKey')
        && str_contains($lightboxSource, 'isLightboxZoomControlTarget(target)')
        && str_contains($lightboxSource, 'interactiveTarget !== stageLink'),
    'Zoom events must preserve browser modifiers and exclude toolbar, map, voting, and form controls.'
);
lightbox_zoom_integration_assert(
    str_contains($lightboxSource, "target?.closest('[data-lightbox-stage]') || isLightboxZoomControlTarget(target)"),
    'The 100% mobile swipe path must treat the stage button as the gesture surface, not a nested control.'
);
lightbox_zoom_integration_assert(
    str_contains($lightboxSource, "stageLink.addEventListener('pointermove', rememberLightboxZoomPointerPosition")
        && str_contains($lightboxSource, 'const pointerAnchor = currentLightboxZoomPointerAnchor();')
        && str_contains($lightboxSource, 'lightboxZoomStateForAnchor(')
        && str_contains($zoomModelSource, 'zoomLightboxStateAtPhotoAnchor')
        && str_contains($lightboxSource, 'currentLightboxRenderedImageRect()')
        && substr_count($lightboxSource, 'currentLightboxZoomPointerAnchor()') >= 5,
    'Wheel and discrete zoom must preserve the photo point under the latest meaningful cursor position using canonical fitted-photo geometry.'
);
lightbox_zoom_integration_assert(
    str_contains($lightboxStyleSource, '.lightbox.is-fullscreen .lightbox-close')
        && str_contains($lightboxStyleSource, 'z-index: 14;'),
    'Fullscreen close control must remain above the zoomed image compositor layer for hit-testing.'
);

lightbox_zoom_integration_assert(
    str_contains($lightboxSource, 'panViewportWidth: fullscreen ? imageWidth : viewportWidth')
        && str_contains($lightboxSource, 'panViewportHeight: fullscreen ? imageHeight : viewportHeight')
        && str_contains($lightboxSource, 'metrics.imageWidth * lightboxZoomState.scale')
        && str_contains($lightboxSource, 'zoomSurface.style.transform = `translate3d(calc(-50% + ${lightboxZoomState.translateX}px), calc(-50% + ${lightboxZoomState.translateY}px), 0)`')
        && str_contains($lightboxStyleSource, 'position: absolute;')
        && str_contains($lightboxStyleSource, 'left: 50%;')
        && str_contains($lightboxStyleSource, 'top: 50%;')
        && !str_contains($lightboxSource, "zoomSurface.style.overflow = 'hidden'")
        && str_contains($zoomModelSource, 'metrics?.panViewportHeight'),
    'Fullscreen zoom must retain two-axis pan bounds while the fitted photograph frame grows symmetrically from the stage center.'
);
lightbox_zoom_integration_assert(
    substr_count($lightboxSource, 'scheduleLightboxZoomReclamp();') >= 8
        && str_contains($lightboxSource, "overlay.classList.add('is-map-split')")
        && str_contains($lightboxSource, "overlay.classList.remove('is-map-split', 'is-map-split-disabled')"),
    'Opening and closing fullscreen map split must reclamp the shared image viewport.'
);

foreach (['fetch(', 'localStorage', 'sessionStorage', 'document.cookie', 'XMLHttpRequest'] as $forbiddenZoomSideEffect) {
    lightbox_zoom_integration_assert(
        !str_contains($zoomModelSource, $forbiddenZoomSideEffect),
        'Zoom model must not fetch or persist viewer state: ' . $forbiddenZoomSideEffect
    );
}

lightbox_zoom_integration_assert(
    str_contains($publicEntrypointSource, 'lightbox-deferred.js?v=20260905-map-popup-viewer-navigation-v1')
        && str_contains($authenticatedEntrypointSource, 'lightbox-deferred.js?v=20260905-map-popup-viewer-navigation-v1'),
    'Anonymous and authenticated browser entrypoints must invalidate the deferred lightbox cache together.'
);
lightbox_zoom_integration_assert(
    substr_count($assetRendererSource, "gallery-modules/lightbox-zoom-model.js'") === 2,
    'Both public asset revision models must include the zoom-model dependency.'
);
lightbox_zoom_integration_assert(
    str_contains($runtimeHelpersSource, 'function asset_dependency_revision(array $paths): string')
        && str_contains($runtimeHelpersSource, "hash_init('sha256')")
        && str_contains($runtimeHelpersSource, '@file_get_contents($path)')
        && str_contains($assetRendererSource, 'asset_dependency_revision($scriptVersionPaths)')
        && str_contains($layoutSource, 'asset_dependency_revision($scriptVersionPaths)')
        && !str_contains($assetRendererSource, 'max($scriptVersion, filemtime($versionPath))')
        && !str_contains($layoutSource, 'max($scriptVersion, filemtime($versionPath))'),
    'Browser asset revisions must change with dependency content instead of depending on the single newest mtime.'
);

$galleryAccessPosition = strpos($lightboxEndpointSource, 'visitor_can_access_gallery($gallery)');
$imageQueryPosition = strpos($lightboxEndpointSource, 'gallery_lightbox_total_count(');
lightbox_zoom_integration_assert(
    $galleryAccessPosition !== false && $imageQueryPosition !== false && $galleryAccessPosition < $imageQueryPosition,
    'The lazy lightbox endpoint must enforce gallery access before loading image metadata.'
);
lightbox_zoom_integration_assert(
    str_contains($lightboxEndpointSource, 'image_nsfw_restricted($image, $gallery)')
        && str_contains($lightboxEndpointSource, 'visitor_can_access_nsfw_content()'),
    'The lazy lightbox endpoint must keep per-image NSFW filtering.'
);

echo "Lightbox zoom integration checks passed.\n";
