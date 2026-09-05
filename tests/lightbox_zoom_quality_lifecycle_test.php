<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_zoom_quality_lifecycle_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects immediate original-quality promotion after an explicit public-lightbox zoom.
 *
 * Responsibilities:
 *   - Prevent eager full-source swaps from returning
 *   - Require a fresh original-node promotion on the first explicit zoom for each displayed photo
 *   - Reject stale decodes after navigation or close
 *   - Preserve transform state and preview fallback when promotion fails
 *   - Install the decoded node so transformed previews repaint immediately
 *   - Keep lazy metadata hydration on the same quality-source contract
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$publicEntrypointSource = (string) file_get_contents($root . '/public/assets/public-gallery.js');
$authenticatedEntrypointSource = (string) file_get_contents($root . '/public/assets/gallery.js');

/**
 * Throw when a quality-promotion lifecycle expectation fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_zoom_quality_lifecycle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

lightbox_zoom_quality_lifecycle_assert(
    !str_contains($lightboxSource, 'swapLightboxImageAfterDecode')
        && !str_contains($lightboxSource, 'lightboxFullSwapIdleDelay')
        && !str_contains($lightboxSource, 'scheduleFullMediaSwap'),
    'Opening a photo must not unconditionally schedule the full source.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, 'lightboxZoomRequiredSourceWidth(')
        && str_contains($lightboxSource, "candidate.kind === 'full'")
        && str_contains($lightboxSource, 'lightboxZoomState.scale > LIGHTBOX_ZOOM_MIN_SCALE')
        && str_contains($lightboxSource, 'const lightboxQualityUpgradeDelay = 140;'),
    'At 100% quality may remain demand-driven, but any zoom above 100% must select the full/original candidate.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, 'card.dataset.lightboxQualitySources = JSON.stringify(item.quality_sources)')
        && str_contains($lightboxSource, "JSON.parse(card.dataset.lightboxQualitySources || '[]')"),
    'Lazy and server-rendered cards must share the quality candidate parser.'
);
$explicitUpgradeStart = strpos($lightboxSource, 'function requestLightboxQualityUpgradeNow()');
$explicitUpgradeEnd = $explicitUpgradeStart === false
    ? false
    : strpos($lightboxSource, 'function pictureStripRadius()', $explicitUpgradeStart);
$explicitUpgradeSource = ($explicitUpgradeStart !== false && $explicitUpgradeEnd !== false)
    ? substr($lightboxSource, $explicitUpgradeStart, $explicitUpgradeEnd - $explicitUpgradeStart)
    : '';
$liveSourceAssignmentPosition = strpos($explicitUpgradeSource, 'targetImage.src = fullSrc;');
$asyncDecodePosition = strpos($explicitUpgradeSource, 'loadFreshDecodedLightboxImage(');
$setZoomStart = strpos($lightboxSource, 'function setLightboxZoomScale(');
$setZoomEnd = $setZoomStart === false ? false : strpos($lightboxSource, 'function resetLightboxZoom(', $setZoomStart);
$setZoomSource = ($setZoomStart !== false && $setZoomEnd !== false)
    ? substr($lightboxSource, $setZoomStart, $setZoomEnd - $setZoomStart)
    : '';
$setZoomRequestPosition = strpos($setZoomSource, 'requestLightboxQualityUpgradeNow();');
$setZoomRenderPosition = strpos($setZoomSource, 'applyLightboxZoomState(announce, metrics);');

lightbox_zoom_quality_lifecycle_assert(
    $setZoomRequestPosition !== false
        && $setZoomRenderPosition !== false
        && $setZoomRequestPosition < $setZoomRenderPosition,
    'Discrete zoom must assign the original source before enlarging the preview layout, while retaining the pre-source-change geometry.'
);

lightbox_zoom_quality_lifecycle_assert(
    substr_count($lightboxSource, 'requestLightboxQualityUpgradeNow();') >= 4
        && str_contains($explicitUpgradeSource, "const fullSrc = String(card?.dataset.fullSrc || '').trim();")
        && str_contains($explicitUpgradeSource, 'activeLightboxTransitionToken += 1;')
        && str_contains($explicitUpgradeSource, 'removeTransitionImage();')
        && str_contains($lightboxSource, 'delete image.dataset.lightboxExplicitZoomQuality;')
        && str_contains($explicitUpgradeSource, 'targetImage.dataset.lightboxExplicitZoomQuality = imageId;')
        && str_contains($explicitUpgradeSource, "targetImage.fetchPriority = 'high';")
        && str_contains($explicitUpgradeSource, "devMarkSource(fullSrc, 'loading', 'zoom-live-original');")
        && str_contains($explicitUpgradeSource, "devMarkSource(fullSrc, 'ready', 'zoom-live-original', targetImage);")
        && $liveSourceAssignmentPosition !== false
        && ($asyncDecodePosition === false || $liveSourceAssignmentPosition < $asyncDecodePosition)
        && !str_contains($explicitUpgradeSource, 'installDecodedLightboxQualityImage(')
        && !str_contains($explicitUpgradeSource, 'promoteLightboxQualityIfNeeded(')
        && substr_count($lightboxSource, 'scheduleLightboxQualityUpgrade(') >= 4,
    'Button/keyboard, wheel, and pinch zoom must synchronously assign the original URL to the live image without waiting for a detached decode or mode transition.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, 'loadFreshDecodedLightboxImage(desired.src')
        && str_contains($lightboxSource, 'qualityToken !== activeLightboxQualityRequestToken')
        && str_contains($lightboxSource, '!isCurrentLightboxImageRequest(index, imageToken)'),
    'Passive high-resolution decode must stay transient and reject stale image generations.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, 'failedLightboxQualitySources.add(failureKey)')
        && str_contains($lightboxSource, 'updateNormalLightboxStageSizeFromLoadedImage(loadedImage);')
        && str_contains($lightboxSource, 'applyLightboxZoomState(false);'),
    'Promotion must retain the preview after a bounded failure and reapply the live zoom state after installation.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, "const zoomSurface = overlay.querySelector('[data-lightbox-zoom-surface]');")
        && str_contains($lightboxSource, 'const frameWidth = Math.max(1, metrics.imageWidth * lightboxZoomState.scale);')
        && str_contains($lightboxSource, 'const frameHeight = Math.max(1, metrics.imageHeight * lightboxZoomState.scale);')
        && str_contains($lightboxSource, 'zoomSurface.style.width = `${frameWidth}px`;')
        && str_contains($lightboxSource, 'zoomSurface.style.height = `${frameHeight}px`;')
        && str_contains($lightboxSource, 'zoomSurface.style.transform = `translate3d(calc(-50% + ${lightboxZoomState.translateX}px), calc(-50% + ${lightboxZoomState.translateY}px), 0)`;')
        && str_contains($lightboxSource, "image.style.width = '100%';")
        && str_contains($lightboxSource, "image.style.height = '100%';")
        && str_contains($lightboxSource, 'function applyLightboxZoomState(announce = false, measuredMetrics = null)')
        && str_contains($lightboxSource, 'const metrics = measuredMetrics || measureLightboxZoomMetrics();')
        && !str_contains($lightboxSource, 'scale(${lightboxZoomState.scale})')
        && !str_contains($lightboxSource, "zoomSurface.style.overflow = 'hidden'")
        && !str_contains($lightboxSource, 'qualityRepaintImage')
        && !str_contains($lightboxSource, 'qualityRepaintTransformReady'),
    'Zoom must enlarge a stage-centered fitted photograph frame, keep the image filling that frame, and preserve center-relative panning.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, "let image = overlay.querySelector('[data-lightbox-img]');")
        && str_contains($lightboxSource, 'loadedImage.onload = null;')
        && str_contains($lightboxSource, 'loadedImage.onerror = null;')
        && str_contains($lightboxSource, "loadedImage.dataset.lightboxImageId = imageId;")
        && str_contains($lightboxSource, 'loadedImage.style.cssText = previousImage.style.cssText;')
        && str_contains($lightboxSource, "loadedImage.style.removeProperty('transform');")
        && str_contains($lightboxSource, 'previousImage.replaceWith(loadedImage);')
        && str_contains($lightboxSource, 'image = loadedImage;')
        && str_contains($lightboxSource, 'loadedImage.isConnected')
        && str_contains($lightboxSource, 'installDecodedLightboxQualityImage(loadedImage, desired.src'),
    'Passive promotion may replace the untransformed image child with an already-decoded larger source.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($lightboxSource, 'image.dataset.lightboxImageId = imageId;')
        && substr_count($lightboxSource, 'String(cards[index]?.dataset.imageId || index)') >= 3
        && str_contains($lightboxSource, 'image.dataset.lightboxImageId !== imageId')
        && str_contains($lightboxSource, 'qualityToken !== activeLightboxQualityRequestToken'),
    'Preview and full-quality phases must retain per-photo ownership across repeated navigation.'
);
$qualityInstallPosition = strpos($lightboxSource, 'return installDecodedLightboxQualityImage(loadedImage, desired.src');
$qualityReadyPosition = $qualityInstallPosition === false
    ? false
    : strpos($lightboxSource, 'setLightboxQualityLoading(false);', $qualityInstallPosition + 1);
lightbox_zoom_quality_lifecycle_assert(
    $qualityInstallPosition !== false
        && $qualityReadyPosition !== false
        && $qualityInstallPosition < $qualityReadyPosition,
    'Quality loading feedback must remain active until the decoded node is installed.'
);
lightbox_zoom_quality_lifecycle_assert(
    substr_count($lightboxSource, 'clearPendingLightboxQualityUpgrade();') >= 3
        && str_contains($lightboxSource, "image.removeAttribute('src');"),
    'Teardown, navigation, and close must invalidate pending quality work.'
);
lightbox_zoom_quality_lifecycle_assert(
    str_contains($publicEntrypointSource, 'map-popup-viewer-navigation-v1')
        && str_contains($authenticatedEntrypointSource, 'map-popup-viewer-navigation-v1')
        && str_contains($lightboxSource, 'lightbox-zoom-model.js?v=20260817-lightbox-zoom-centered-frame-v5'),
    'All public lightbox module paths must invalidate stale browser caches together.'
);

echo "Lightbox zoom quality lifecycle checks passed.\n";
