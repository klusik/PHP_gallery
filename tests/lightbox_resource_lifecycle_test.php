<?php

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
if (!is_string($source)) {
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

lightbox_resource_assert(
    str_contains($source, 'const lightboxDecodedImageCacheDesktopLimit = 12;'),
    'Desktop decoded lightbox cache must remain bounded to 12 reusable entries.'
);
lightbox_resource_assert(
    str_contains($source, 'const lightboxDecodedImageCacheMobileLimit = 6;'),
    'Mobile/touch decoded lightbox cache must remain bounded to 6 reusable entries.'
);
lightbox_resource_assert(
    str_contains($source, 'const lightboxDecodedImageCacheIdleMs = 60000;'),
    'Settled reusable decoded entries must use the approximately 60-second idle age.'
);
lightbox_resource_assert(
    str_contains($source, 'const activeDetachedLightboxImageLoads = new Set();'),
    'Detached lightbox image requests must be tracked for teardown cancellation.'
);

$limitSource = lightbox_resource_function_source($source, 'activeLightboxDecodedImageCacheLimit', 'evictSettledDecodedLightboxImage');
lightbox_resource_assert($limitSource !== '', 'Decoded cache device-limit helper is missing.');
lightbox_resource_assert(
    str_contains($limitSource, 'isMobileTouchDevice ? lightboxDecodedImageCacheMobileLimit : lightboxDecodedImageCacheDesktopLimit'),
    'Decoded cache limit must reuse the existing mobile/touch detection result.'
);

$sweepSource = lightbox_resource_function_source($source, 'sweepDecodedLightboxImageCache', 'useDecodedLightboxImageCacheEntry');
lightbox_resource_assert($sweepSource !== '', 'Decoded cache idle-age sweep helper is missing.');
lightbox_resource_assert(
    str_contains($sweepSource, 'if (!entry?.settled)'),
    'Idle-age cleanup must never silently evict unresolved decoded-image work.'
);
lightbox_resource_assert(
    str_contains($sweepSource, 'now - entry.lastUsedAt >= lightboxDecodedImageCacheIdleMs'),
    'Decoded cache age cleanup must be based on last use.'
);
lightbox_resource_assert(
    str_contains($sweepSource, 'releaseAllSettled ||'),
    'Sustained hidden-state cleanup must be able to release every settled reusable entry.'
);

$hitSource = lightbox_resource_function_source($source, 'useDecodedLightboxImageCacheEntry', 'rememberDecodedLightboxImage');
lightbox_resource_assert($hitSource !== '', 'Decoded cache hit helper is missing.');
lightbox_resource_assert(
    str_contains($hitSource, 'entry.lastUsedAt = performance.now();'),
    'A reusable decoded cache hit must refresh last-used age.'
);
lightbox_resource_assert(
    str_contains($hitSource, 'decodedLightboxImages.delete(src);') && str_contains($hitSource, 'decodedLightboxImages.set(src, entry);'),
    'A reusable decoded cache hit must preserve LRU newest-position behavior.'
);

$rememberSource = lightbox_resource_function_source($source, 'rememberDecodedLightboxImage', 'trimDecodedLightboxImageCache');
lightbox_resource_assert($rememberSource !== '', 'Decoded cache insertion helper is missing.');
foreach ([
    'promise: preloadPromise',
    'lastUsedAt: performance.now()',
    'settled: false',
    'entry.settled = true;',
] as $entryContract) {
    lightbox_resource_assert(
        str_contains($rememberSource, $entryContract),
        'Decoded cache entry lifecycle is missing: ' . $entryContract
    );
}

$trimSource = lightbox_resource_function_source($source, 'trimDecodedLightboxImageCache', 'preloadDecodedLightboxImage');
lightbox_resource_assert($trimSource !== '', 'Decoded cache LRU trim helper is missing.');
lightbox_resource_assert(
    str_contains($trimSource, 'const cacheLimit = activeLightboxDecodedImageCacheLimit();'),
    'LRU trimming must enforce the active desktop/mobile decoded cache limit.'
);
lightbox_resource_assert(
    str_contains($trimSource, ".find(([, entry]) => entry?.settled)?.[0]"),
    'LRU trimming must choose settled entries instead of orphaning active image work.'
);

$resetStart = strpos($source, 'function resetLightboxPreloadQueue()');
$resetEnd = strpos($source, 'function clearLightboxHiddenCleanupTimer(', $resetStart === false ? 0 : $resetStart);
lightbox_resource_assert($resetStart !== false && $resetEnd !== false, 'Nearby preload reset helper is missing.');
$resetSource = substr($source, (int) $resetStart, (int) $resetEnd - (int) $resetStart);
foreach ([
    'preloadedSources.clear();',
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
lightbox_resource_assert(str_contains($visibilitySource, 'if (document.hidden)'), 'Visibility lifecycle must distinguish hidden state.');
lightbox_resource_assert(str_contains($visibilitySource, 'if (overlay.hidden)'), 'Hidden-page cleanup must not create work for an already closed viewer.');
lightbox_resource_assert(str_contains($visibilitySource, 'resetLightboxPreloadQueue();'), 'Backgrounding an open viewer must cancel/reset low-priority nearby preview work.');
lightbox_resource_assert(str_contains($visibilitySource, 'window.setTimeout(() =>'), 'Sustained hidden cleanup must use a one-shot timeout.');
lightbox_resource_assert(str_contains($visibilitySource, 'lightboxDecodedImageCacheIdleMs'), 'Hidden decoded-cache cleanup must use the normal idle-age delay.');
lightbox_resource_assert(str_contains($visibilitySource, 'sweepDecodedLightboxImageCache({releaseAllSettled: true});'), 'Sustained hidden cleanup must release settled reusable decoded entries.');
lightbox_resource_assert(str_contains($visibilitySource, 'clearLightboxHiddenCleanupTimer();'), 'Returning visible before the delay must cancel hidden cleanup.');
lightbox_resource_assert(
    str_contains($source, "document.addEventListener('visibilitychange', handleLightboxVisibilityChange, {signal: controller.signal});"),
    'Visibility listener must be owned by the component AbortSignal.'
);
lightbox_resource_assert(
    str_contains($source, "controller.signal.addEventListener('abort', clearLightboxHiddenCleanupTimer, {once: true});"),
    'Component teardown must explicitly cancel the hidden-state timeout.'
);
lightbox_resource_assert(
    !str_contains($visibilitySource, 'setInterval('),
    'Background lifecycle cleanup must not use a permanent cleanup interval.'
);

$loaderStart = strpos($source, 'function loadFreshDecodedLightboxImage(src, options = {})');
$loaderEnd = strpos($source, 'function cancelActiveDetachedLightboxImageLoads()', $loaderStart === false ? 0 : $loaderStart);
lightbox_resource_assert($loaderStart !== false && $loaderEnd !== false, 'Detached image loader lifecycle is missing.');
$loaderSource = substr($source, (int) $loaderStart, (int) $loaderEnd - (int) $loaderStart);
lightbox_resource_assert(str_contains($loaderSource, "signal.addEventListener('abort', cancelLoad"), 'Detached image loader must react to AbortSignal cancellation.');
lightbox_resource_assert(str_contains($loaderSource, "loadedImage.removeAttribute('src');"), 'Cancelling a detached image load must detach its network source.');
lightbox_resource_assert(str_contains($loaderSource, 'activeDetachedLightboxImageLoads.delete(cancelLoad);'), 'Settled detached image loads must leave the active cancellation registry.');

$closeStart = strpos($source, 'function close()');
$closeEnd = strpos($source, 'function preloadCardLightboxImages(', $closeStart === false ? 0 : $closeStart);
lightbox_resource_assert($closeStart !== false && $closeEnd !== false, 'Lightbox close helper is missing.');
$closeSource = substr($source, (int) $closeStart, (int) $closeEnd - (int) $closeStart);
foreach ([
    "image.removeAttribute('src');",
    'clearLightboxHiddenCleanupTimer();',
    'preloadedSources.clear();',
    'resetLightboxPreloadQueue();',
    'cancelActiveDetachedLightboxImageLoads();',
    'decodedLightboxImages.clear();',
    'cancelLightboxMetadataRequests();',
    'lightboxGalleryMapPayloadPromises.clear();',
    'failedLightboxQualitySources.clear();',
] as $requiredCleanup) {
    lightbox_resource_assert(
        str_contains($closeSource, $requiredCleanup),
        'Lightbox close is missing resource cleanup: ' . $requiredCleanup
    );
}

fwrite(STDOUT, "lightbox resource lifecycle contract: OK\n");
