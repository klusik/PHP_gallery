<?php

declare(strict_types=1);

/**
 * Regression contract for bounded lightbox decoded-image resources.
 *
 * Closing the viewer must discard decoded caches and cancel unfinished detached
 * image work. Nearby preview preloads must also be cancellable when navigation
 * invalidates the current preload generation.
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

lightbox_resource_assert(
    str_contains($source, 'const lightboxDecodedImageCacheLimit = 12;'),
    'Decoded lightbox cache must stay bounded to the current preview neighborhood.'
);
lightbox_resource_assert(
    str_contains($source, 'const activeDetachedLightboxImageLoads = new Set();'),
    'Detached lightbox image requests must be tracked for teardown cancellation.'
);

$resetStart = strpos($source, 'function resetLightboxPreloadQueue()');
$resetEnd = strpos($source, 'function queueDecodedLightboxPreload(', $resetStart === false ? 0 : $resetStart);
lightbox_resource_assert($resetStart !== false && $resetEnd !== false, 'Nearby preload reset helper is missing.');
$resetSource = substr($source, (int) $resetStart, (int) $resetEnd - (int) $resetStart);
lightbox_resource_assert(
    str_contains($resetSource, 'lightboxPreloadAbortController.abort();'),
    'Resetting the nearby preload queue must abort already-started detached preloads.'
);
lightbox_resource_assert(
    str_contains($resetSource, 'lightboxPreloadAbortController = new AbortController();'),
    'Resetting nearby preloads must create a fresh controller for subsequent navigation.'
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
    'preloadedSources.clear();',
    'resetLightboxPreloadQueue();',
    'cancelActiveDetachedLightboxImageLoads();',
    'decodedLightboxImages.clear();',
    'lightboxPendingWindows.clear();',
    'lightboxGalleryMapPayloadPromises.clear();',
    'failedLightboxQualitySources.clear();',
] as $requiredCleanup) {
    lightbox_resource_assert(
        str_contains($closeSource, $requiredCleanup),
        'Lightbox close is missing resource cleanup: ' . $requiredCleanup
    );
}

fwrite(STDOUT, "lightbox resource lifecycle contract: OK\n");
