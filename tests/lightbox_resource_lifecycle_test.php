<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect lightbox resource lifetime and cleanup.
 * Responsibilities:
 *   - Detect detached media or stale work surviving viewer teardown.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_resource_lifecycle_test.php
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

/**
 * Regression contract for bounded lightbox decoded-image resources.
 *
 * The reusable decoded cache must keep desktop/mobile limits, refresh last-use
 * age on hits, age-evict only settled work, and shed low-priority resources when
 * a live viewer remains backgrounded. Closing or tearing down the viewer must
 * still cancel unfinished detached work and discard all application references.
 */

$sourcePath = dirname(__DIR__) . '/public/assets/gallery-modules/lightbox.js';
$source = file_get_contents($sourcePath);
$resourceSource = file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/lightbox-resource-lifecycle.js');
$preloadSource = (string) file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/lightbox-preload-lifecycle.js');
if (!is_string($source) || !is_string($resourceSource)) {
    fwrite(STDERR, "Unable to read lightbox.js\n");
    exit(1);
}

/**
 * Fail the regression script with one precise contract message.
 */
function lightbox_resource_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Return one JavaScript function body slice bounded by the next function declaration.
 */
function lightbox_resource_function_source(string $source, string $functionName, ?string $nextFunctionName): string
{
    $start = strpos($source, 'function ' . $functionName . '(');
    if ($start === false) {
        return '';
    }
    if ($nextFunctionName === null) {
        return substr($source, $start);
    }
    $end = strpos($source, 'function ' . $nextFunctionName . '(', $start + 1);
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

foreach ([
    'const desktopCacheLimit = 12;',
    'const mobileCacheLimit = 6;',
    'const defaultIdleMs = 60000;',
    'const cache = new Map();',
    'const cacheResults = new WeakMap();',
    'const activeOperations = new Set();',
    'function sweep(options = {})',
    'function setHidden(hidden, isViewerOpen)',
    "function loadDecoded(src, options = {})",
    'function loadFresh(src, options = {})',
    'function loadTracked(src, options = {})',
    'function clear()',
    'function dispose()',
] as $ownerContract) {
    lightbox_resource_assert(
        str_contains($resourceSource, $ownerContract),
        'Resource owner is missing its cache or detached-work contract: ' . $ownerContract
    );
}
lightbox_resource_assert(
    str_contains($resourceSource, 'if (!entry?.settled)')
        && str_contains($resourceSource, 'effectiveIdleMs')
        && str_contains($resourceSource, 'cache.delete(src)')
        && str_contains($resourceSource, 'cache.set(src, entry)'),
    'Resource owner must touch exact URL entries, idle-evict only settled work, and preserve LRU ordering.'
);
lightbox_resource_assert(
    str_contains($resourceSource, 'entry.lastUsedAt = now();')
        && str_contains($resourceSource, 'epoch !== insertionEpoch')
        && str_contains($resourceSource, 'cache.get(src) !== entry'),
    'Cache completion must refresh idle age and reject stale epoch or replaced-entry work.'
);
lightbox_resource_assert(
    str_contains($source, 'lightboxResources.loadDecoded(src, {')
        && !str_contains($source, 'lightboxResources.loadDecoded(src, {\n            signal:')
        && str_contains($source, 'lightboxResources.loadTracked(src, {')
        && str_contains($source, 'lightboxResources.preload(src, {'),
    'Coordinator must use the owner while keeping shared decode independent from navigation cancellation.'
);

$resetStart = strpos($source, 'function resetLightboxPreloadQueue(');
$resetEnd = strpos($source, 'function handleLightboxVisibilityChange(', $resetStart === false ? 0 : $resetStart);
lightbox_resource_assert($resetStart !== false && $resetEnd !== false, 'Nearby preload reset helper is missing.');
$resetSource = substr($source, (int) $resetStart, (int) $resetEnd - (int) $resetStart);
lightbox_resource_assert(
    str_contains($resetSource, 'preloadedSources.clear();') && str_contains($resetSource, 'lightboxPreloads.reset(options);'),
    'Viewer reset must clear diagnostic bookkeeping and delegate lifecycle options to the queue owner.'
);
$resetSource = lightbox_resource_function_source($preloadSource, 'resetLightboxPreloadQueue', 'queueDecodedLightboxPreload');
lightbox_resource_assert(
    str_contains($resetSource, 'const abortActive = options.abortActive !== false;')
        && str_contains($resetSource, 'if (abortActive) {'),
    'Nearby preload reset must support queue-only invalidation without weakening hard cancellation paths.'
);
foreach ([
    'lightboxPreloadQueue.length = 0;',
    'lightboxQueuedSources.clear();',
    'lightboxPreloadAbortController.abort();',
    'lightboxPreloadAbortController = new AbortController();',
] as $resetContract) {
    lightbox_resource_assert(
        str_contains($resetSource, $resetContract),
        'Resetting nearby preloads is missing lifecycle cleanup: ' . $resetContract
    );
}

$visibilitySource = lightbox_resource_function_source($source, 'handleLightboxVisibilityChange', 'queueDecodedLightboxPreload');
lightbox_resource_assert($visibilitySource !== '', 'Background visibility lifecycle helper is missing.');
lightbox_resource_assert(str_contains($visibilitySource, 'const hidden = document.hidden;'), 'Visibility lifecycle must distinguish hidden state.');
lightbox_resource_assert(str_contains($visibilitySource, '!overlay.hidden'), 'Hidden-page cleanup must not create work for an already closed viewer.');
lightbox_resource_assert(str_contains($visibilitySource, 'resetLightboxPreloadQueue();'), 'Backgrounding an open viewer must cancel/reset low-priority nearby preview work.');
lightbox_resource_assert(str_contains($visibilitySource, 'lightboxResources?.setHidden(hidden, !overlay.hidden);'), 'Visibility changes must delegate bounded cache cleanup to the resource owner.');
lightbox_resource_assert(
    str_contains($source, "document.addEventListener('visibilitychange', handleLightboxVisibilityChange, {signal: controller.signal});"),
    'Visibility listener must be owned by the component AbortSignal.'
);
lightbox_resource_assert(
    !str_contains($visibilitySource, 'setInterval('),
    'Background lifecycle cleanup must not use a permanent cleanup interval.'
);

$closeStart = strpos($source, 'function close()');
$closeEnd = strpos($source, 'function preloadCardLightboxImages(', $closeStart === false ? 0 : $closeStart);
lightbox_resource_assert($closeStart !== false && $closeEnd !== false, 'Lightbox close helper is missing.');
$closeSource = substr($source, (int) $closeStart, (int) $closeEnd - (int) $closeStart);
foreach ([
    "image.removeAttribute('src');",
    'preloadedSources.clear();',
    'resetLightboxPreloadQueue();',
    'lightboxResources.clear();',
    'cancelLightboxMetadataRequests();',
    'lightboxGalleryMapPayloadPromises.clear();',
    'failedLightboxQualitySources.clear();',
] as $requiredCleanup) {
    lightbox_resource_assert(
        str_contains($closeSource, $requiredCleanup),
        'Lightbox close is missing resource cleanup: ' . $requiredCleanup
    );
}
lightbox_resource_assert(
    str_contains($source, 'lightboxResources?.dispose();'),
    'Setup teardown must terminally dispose its resource owner.'
);

fwrite(STDOUT, "lightbox resource lifecycle contract: OK\n");
