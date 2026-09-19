<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_image_observability_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects browser image-performance and decoded-lightbox cache instrumentation.
 *
 * Responsibilities:
 *   - Verify decode telemetry measures only active visible-image decode work
 *   - Verify background preload paths do not receive visible telemetry ownership
 *   - Verify display telemetry begins after decode and ends at the presentation boundary
 *   - Verify decoded-lightbox cache telemetry is explicitly application-cache telemetry
 *   - Verify media variants remain normalized instead of exposing source URLs
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

/** Throw when one image observability source contract fails. */
function telemetry_image_observability_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$usageSource = (string) file_get_contents($root . '/public/assets/usage.js');
$compatibilitySource = (string) file_get_contents($root . '/public/assets/telemetry.js');
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$privacySource = (string) file_get_contents($root . '/app/services/telemetry_privacy.php');

telemetry_image_observability_contract_assert(
    hash('sha256', $usageSource) === hash('sha256', $compatibilitySource),
    'Canonical usage.js and compatibility telemetry.js must remain byte-identical.'
);
telemetry_image_observability_contract_assert(
    str_contains($usageSource, 'window.PHPGalleryTelemetryImageDecoded = function')
        && str_contains($usageSource, "baseEvent('client.performance.image_decode')")
        && str_contains($usageSource, 'window.PHPGalleryTelemetryImageDisplayed = function')
        && str_contains($usageSource, "baseEvent('client.performance.image_display')"),
    'Browser telemetry must expose documented image decode and display producers.'
);
telemetry_image_observability_contract_assert(
    str_contains($usageSource, 'display_width_bucket: visibleWidthBucket(displayWidth)')
        && str_contains($usageSource, 'natural_width_bucket: visibleWidthBucket(naturalWidth)'),
    'Image-performance geometry must be reduced to bounded width buckets before transport.'
);
telemetry_image_observability_contract_assert(
    str_contains($usageSource, 'function performanceTelemetrySampled()')
        && substr_count($usageSource, 'event.sampled_rate = performanceSamplingRate();') >= 3,
    'Page-load, image-decode, and image-display metrics must share the bounded performance sampling contract.'
);
telemetry_image_observability_contract_assert(
    str_contains($privacySource, "'client.performance.image_decode' => ['display_width_bucket', 'natural_width_bucket']")
        && str_contains($privacySource, "'client.performance.image_display' => ['display_width_bucket', 'natural_width_bucket']"),
    'Server privacy normalization must allow only the bounded image-performance context fields.'
);

$loadStart = strpos($lightboxSource, 'function loadFreshDecodedLightboxImage');
$loadEnd = $loadStart === false ? false : strpos($lightboxSource, 'function cancelActiveDetachedLightboxImageLoads', $loadStart);
$loadSource = ($loadStart !== false && $loadEnd !== false)
    ? substr($lightboxSource, $loadStart, $loadEnd - $loadStart)
    : '';
telemetry_image_observability_contract_assert(
    $loadSource !== ''
        && str_contains($loadSource, 'loadedImage.onload = () => {')
        && str_contains($loadSource, 'const decodeStartedAt = performance.now();')
        && str_contains($loadSource, 'decodeLoadedImage(loadedImage).then(() => {')
        && str_contains($loadSource, 'Number.isInteger(options.telemetryIndex)')
        && str_contains($loadSource, 'Number.isInteger(options.telemetryToken)')
        && str_contains($loadSource, "'miss'"),
    'Visible image-decode timing must begin after load completion and require active lightbox telemetry ownership.'
);

$preloadStart = strpos($lightboxSource, 'function preloadDecodedLightboxImage');
$preloadEnd = $preloadStart === false ? false : strpos($lightboxSource, 'function lightboxPreloadConcurrency', $preloadStart);
$preloadSource = ($preloadStart !== false && $preloadEnd !== false)
    ? substr($lightboxSource, $preloadStart, $preloadEnd - $preloadStart)
    : '';
telemetry_image_observability_contract_assert(
    $preloadSource !== ''
        && !str_contains($preloadSource, 'telemetryIndex')
        && !str_contains($preloadSource, 'telemetryToken')
        && !str_contains($preloadSource, 'telemetryVisibleImageDecoded'),
    'Adjacent background preloads must not emit the visible image-decode metric.'
);

$showStart = strpos($lightboxSource, 'function showLightboxImageSource');
$showEnd = $showStart === false ? false : strpos($lightboxSource, 'function lightboxQualityCandidatesForCard', $showStart);
$showSource = ($showStart !== false && $showEnd !== false)
    ? substr($lightboxSource, $showStart, $showEnd - $showStart)
    : '';
telemetry_image_observability_contract_assert(
    $showSource !== ''
        && str_contains($showSource, "loadDecodedLightboxImage(src, {priority: 'high', telemetryIndex: index, telemetryToken: token})")
        && str_contains($showSource, 'const displayStartedAt = performance.now();')
        && str_contains($showSource, 'telemetryVisibleImageDisplayed(index, token, src, decodedImage, performance.now() - displayStartedAt);'),
    'Visible presentation timing must start only after decode and finish at a successful display boundary.'
);
telemetry_image_observability_contract_assert(
    str_contains($lightboxSource, "telemetryLightboxCacheEvent('cache.lightbox.hit'")
        && str_contains($lightboxSource, "telemetryLightboxCacheEvent('cache.lightbox.miss'")
        && str_contains($lightboxSource, "telemetryLightboxCacheEvent('cache.lightbox.evicted'")
        && str_contains($lightboxSource, "'decoded_lightbox'")
        && str_contains($lightboxSource, 'This is not HTTP/browser cache telemetry.'),
    'Cache telemetry must describe only the decoded-lightbox application cache and must expose its scope explicitly.'
);
telemetry_image_observability_contract_assert(
    str_contains($lightboxSource, "return 'original';")
        && str_contains($lightboxSource, 'return `thumb_${width}`;')
        && str_contains($lightboxSource, "return 'unknown';"),
    'Lightbox media variants must be normalized to bounded variant labels instead of source URLs.'
);

echo "telemetry_image_observability_contract_test: ok\n";
