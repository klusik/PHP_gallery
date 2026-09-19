<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_cache_eviction_liveness_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects decoded-image cache eviction and preload accounting from abandoning lightbox navigation.
 *
 * Responsibilities:
 *   - Require the existing source-index lookup during decoded-cache eviction
 *   - Keep optional cache telemetry isolated from viewer/cache control flow
 *   - Require balanced preload concurrency when a preload throws synchronously
 *   - Keep current-preview preload failures from abandoning foreground navigation
 *   - Require synchronous foreground setup failures to terminate through navigation ownership
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

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$preloadSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox-preload-lifecycle.js');

/**
 * Throw when a decoded-cache/navigation liveness contract fails.
 *
 * @param bool $condition Assertion result.
 * @param string $message Failure diagnostic.
 */
function lightbox_cache_eviction_liveness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Extract a JavaScript function up to the next named function.
 *
 * @param string $source JavaScript source.
 * @param string $name Function name to locate.
 * @param string $nextName Next function name used as the slice boundary.
 * @return string Function source or an empty string.
 */
function lightbox_cache_eviction_liveness_function(string $source, string $name, string $nextName): string
{
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $end = strpos($source, 'function ' . $nextName . '(', $start + 1);
    return $end === false ? '' : substr($source, $start, $end - $start);
}

lightbox_cache_eviction_liveness_assert(
    !str_contains($source, 'lightboxIndexForSource('),
    'Decoded-cache eviction must not call the nonexistent lightboxIndexForSource() helper.'
);

$evictionSource = lightbox_cache_eviction_liveness_function(
    $source,
    'evictSettledDecodedLightboxImage',
    'sweepDecodedLightboxImageCache'
);
lightbox_cache_eviction_liveness_assert($evictionSource !== '', 'Decoded-cache eviction helper is missing.');
lightbox_cache_eviction_liveness_assert(
    str_contains($evictionSource, 'decodedLightboxImages.delete(src);')
        && str_contains($evictionSource, 'const telemetryIndex = devFindSourceIndex(src);'),
    'Decoded-cache eviction must delete the settled entry and reuse the existing source-index lookup.'
);

$telemetrySource = lightbox_cache_eviction_liveness_function(
    $source,
    'telemetryLightboxCacheEvent',
    'telemetryVisibleImageDecoded'
);
lightbox_cache_eviction_liveness_assert($telemetrySource !== '', 'Decoded-cache telemetry helper is missing.');
lightbox_cache_eviction_liveness_assert(
    str_contains($telemetrySource, 'try {')
        && str_contains($telemetrySource, 'window.PHPGalleryTelemetryCacheEvent(')
        && str_contains($telemetrySource, '} catch (error) {'),
    'Optional decoded-cache telemetry must not be able to abort viewer/cache control flow.'
);

$drainSource = lightbox_cache_eviction_liveness_function(
    $preloadSource,
    'drainLightboxPreloadQueue',
    'reportError'
);
lightbox_cache_eviction_liveness_assert($drainSource !== '', 'Queued preload drain helper is missing.');
$incrementPosition = strpos($drainSource, 'activeLightboxPreloads += 1;');
$tryPosition = strpos($drainSource, 'try {', $incrementPosition === false ? 0 : $incrementPosition);
$preloadPosition = strpos($drainSource, 'preloadPromise = preload(', $tryPosition === false ? 0 : $tryPosition);
$catchPosition = strpos($drainSource, '} catch (error) {', $preloadPosition === false ? 0 : $preloadPosition);
$syncReleasePosition = strpos($drainSource, 'activeLightboxPreloads = Math.max(0, activeLightboxPreloads - 1);', $catchPosition === false ? 0 : $catchPosition);
$settlementPosition = strpos($drainSource, 'Promise.resolve(preloadPromise).then(releaseSlot, releaseSlot);');
lightbox_cache_eviction_liveness_assert(
    $incrementPosition !== false
        && $tryPosition !== false
        && $preloadPosition !== false
        && $catchPosition !== false
        && $syncReleasePosition !== false
        && $incrementPosition < $tryPosition
        && $tryPosition < $preloadPosition
        && $preloadPosition < $catchPosition
        && $catchPosition < $syncReleasePosition
        && $settlementPosition !== false
        && str_contains($drainSource, 'function releaseSlot()'),
    'Every queued preload slot must be released for both synchronous throws and asynchronous settlement.'
);

$openSource = lightbox_cache_eviction_liveness_function($source, 'openAt', 'step');
lightbox_cache_eviction_liveness_assert($openSource !== '', 'openAt() source is missing.');
$currentPreloadPosition = strpos($openSource, "preloadCardLightboxImages(card, false, {reason: 'current-preview'});");
$currentPreloadTryPosition = strrpos(substr($openSource, 0, $currentPreloadPosition === false ? 0 : $currentPreloadPosition), 'try {');
$currentPreloadCatchPosition = strpos($openSource, '} catch (error) {', $currentPreloadPosition === false ? 0 : $currentPreloadPosition);
$previewHelperPosition = strpos($openSource, 'const showPreviewFirst = () =>');
lightbox_cache_eviction_liveness_assert(
    $currentPreloadPosition !== false
        && $currentPreloadTryPosition !== false
        && $currentPreloadCatchPosition !== false
        && $previewHelperPosition !== false
        && $currentPreloadTryPosition < $currentPreloadPosition
        && $currentPreloadPosition < $currentPreloadCatchPosition
        && $currentPreloadCatchPosition < $previewHelperPosition,
    'Current-preview preload must be an optional guarded optimization and must not prevent foreground source setup.'
);

$initialPromisePosition = strpos($openSource, 'let initialMainPromise = null;');
$sourceSetupTryPosition = strpos($openSource, 'try {', $initialPromisePosition === false ? 0 : $initialPromisePosition);
$showPreviewPosition = strpos($openSource, '? showPreviewFirst().then(', $sourceSetupTryPosition === false ? 0 : $sourceSetupTryPosition);
$sourceSetupCatchPosition = strpos($openSource, "presentationFailureReason = lightboxNavigationExceptionReason('source-setup', error);", $showPreviewPosition === false ? 0 : $showPreviewPosition);
$finalizePosition = strpos($openSource, 'Promise.resolve(initialMainPromise)');
lightbox_cache_eviction_liveness_assert(
    $initialPromisePosition !== false
        && $sourceSetupTryPosition !== false
        && $showPreviewPosition !== false
        && $sourceSetupCatchPosition !== false
        && $finalizePosition !== false
        && $initialPromisePosition < $sourceSetupTryPosition
        && $sourceSetupTryPosition < $showPreviewPosition
        && $showPreviewPosition < $sourceSetupCatchPosition
        && $sourceSetupCatchPosition < $finalizePosition,
    'Synchronous foreground source setup failures must be converted into the owned navigation finalization path.'
);

$reasonSource = lightbox_cache_eviction_liveness_function(
    $source,
    'lightboxNavigationExceptionReason',
    'beginLightboxNavigationTransaction'
);
lightbox_cache_eviction_liveness_assert(
    $reasonSource !== '' && str_contains($reasonSource, ".slice(0, 64)"),
    'Navigation setup/rejection diagnostics must remain bounded for the DEV overlay.'
);

fwrite(STDOUT, "Lightbox decoded-cache eviction liveness checks passed.\n");
