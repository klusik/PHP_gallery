<?php

declare(strict_types=1);

/**
 * Regression contract for lazy lightbox metadata concurrency and lifecycle.
 *
 * Sparse metadata windows must be stable instead of shifting by one image for
 * every adjacent preload. Closing the viewer must abort the real HTTP work,
 * and the PHP endpoint must release its session before thumbnail-bundle work so
 * pagination or reload requests from the same visitor are not serialized behind
 * a hidden lightbox request.
 */

$root = dirname(__DIR__);
$lightboxPath = $root . '/public/assets/gallery-modules/lightbox.js';
$galleryControllerPath = $root . '/app/controllers/gallery_lightbox.php';
$smartGalleryControllerPath = $root . '/app/controllers/smart_galleries.php';
$lightbox = file_get_contents($lightboxPath);
$galleryController = file_get_contents($galleryControllerPath);
$smartGalleryController = file_get_contents($smartGalleryControllerPath);

if (!is_string($lightbox) || !is_string($galleryController) || !is_string($smartGalleryController)) {
    fwrite(STDERR, "Unable to read lightbox metadata lifecycle sources.\n");
    exit(1);
}

/**
 * Fail the regression script with one precise contract message.
 */
function lightbox_metadata_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Return one source slice bounded by two function declarations.
 */
function lightbox_metadata_function_source(string $source, string $functionName, ?string $nextFunctionName): string
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

$windowSource = lightbox_metadata_function_source($lightbox, 'lightboxWindowForIndex', 'lightboxRangeLoaded');
lightbox_metadata_assert($windowSource !== '', 'Stable lazy metadata window helper is missing.');
lightbox_metadata_assert(
    str_contains($windowSource, 'Math.floor(normalizedIndex / lightboxWindowSize) * lightboxWindowSize'),
    'Lazy lightbox metadata windows must be aligned to stable non-overlapping blocks.'
);
lightbox_metadata_assert(
    !str_contains($windowSource, 'index - leadingItems'),
    'Lazy lightbox metadata windows must not shift by one item around every adjacent index.'
);

$cancelSource = lightbox_metadata_function_source($lightbox, 'cancelLightboxMetadataRequests', 'fetchLightboxRange');
lightbox_metadata_assert($cancelSource !== '', 'Lazy metadata cancellation helper is missing.');
lightbox_metadata_assert(str_contains($cancelSource, 'lightboxMetadataAbortController.abort();'), 'Closing the viewer must abort active metadata HTTP requests.');
lightbox_metadata_assert(str_contains($cancelSource, 'lightboxMetadataGeneration += 1;'), 'Metadata cancellation must invalidate stale response generations.');
lightbox_metadata_assert(str_contains($cancelSource, 'lightboxPendingWindows.clear();'), 'Metadata cancellation must also clear in-flight request bookkeeping.');

$fetchSource = lightbox_metadata_function_source($lightbox, 'fetchLightboxRange', 'fetchLightboxWindowAround');
lightbox_metadata_assert($fetchSource !== '', 'Lazy metadata fetch helper is missing.');
lightbox_metadata_assert(str_contains($fetchSource, 'signal: metadataSignal'), 'Lazy metadata fetches must use the per-viewer metadata AbortSignal.');
lightbox_metadata_assert(str_contains($fetchSource, "error?.name === 'AbortError'"), 'Expected metadata cancellation must not be reported as a request failure.');
lightbox_metadata_assert(str_contains($fetchSource, 'requestGeneration !== lightboxMetadataGeneration'), 'Late metadata responses must be rejected after viewer lifecycle invalidation.');
lightbox_metadata_assert(str_contains($fetchSource, 'lightboxPendingWindows.get(key) === promise'), 'An old request must not delete a newer same-range Promise after reopen.');

$closeSource = lightbox_metadata_function_source($lightbox, 'close', 'preloadCardLightboxImages');
lightbox_metadata_assert($closeSource !== '', 'Lightbox close helper is missing.');
lightbox_metadata_assert(str_contains($closeSource, 'cancelLightboxMetadataRequests();'), 'Normal lightbox close must abort lazy metadata requests.');
lightbox_metadata_assert(str_contains($closeSource, 'stopGalleryDevModeMonitoring();'), 'Normal lightbox close must suspend development diagnostics loops.');

$releaseSource = lightbox_metadata_function_source($galleryController, 'cms_release_gallery_lightbox_session_lock', 'gallery_lightbox_json_item');
lightbox_metadata_assert($releaseSource !== '', 'Gallery lightbox session-release helper is missing.');
lightbox_metadata_assert(str_contains($releaseSource, 'session_status() === PHP_SESSION_ACTIVE'), 'Gallery lightbox session release must only close an active PHP session.');
lightbox_metadata_assert(str_contains($releaseSource, 'session_write_close();'), 'Gallery lightbox session release must close the PHP session lock.');

$galleryEndpointSource = lightbox_metadata_function_source($galleryController, 'cms_gallery_lightbox_data', null);
$galleryCsrf = strpos($galleryEndpointSource, 'csrf_token();');
$galleryRelease = strpos($galleryEndpointSource, 'cms_release_gallery_lightbox_session_lock();');
$galleryPreload = strpos($galleryEndpointSource, 'thumbnail_bundles_preload(array_values($renderImages));');
$galleryLoop = strpos($galleryEndpointSource, 'foreach ($renderImages as $rowIndex => $image)');
lightbox_metadata_assert($galleryCsrf !== false && $galleryRelease !== false && $galleryPreload !== false && $galleryLoop !== false, 'Normal lazy lightbox endpoint lifecycle markers are incomplete.');
lightbox_metadata_assert($galleryCsrf < $galleryRelease, 'Vote CSRF state must be persisted before the normal lightbox endpoint releases the session.');
lightbox_metadata_assert($galleryRelease < $galleryPreload && $galleryPreload < $galleryLoop, 'Normal lightbox endpoint must release the session before batched thumbnail work and item rendering.');
lightbox_metadata_assert(str_contains($galleryEndpointSource, '$nsfwAllowed = visitor_can_access_nsfw_content();'), 'Normal lightbox endpoint must capture NSFW authorization before releasing the session lock.');
lightbox_metadata_assert(str_contains($galleryEndpointSource, '$renderImages[$rowIndex] = $image;'), 'Normal lightbox item filtering must reuse captured NSFW authorization before thumbnail preloading.');

$smartEndpointSource = lightbox_metadata_function_source($smartGalleryController, 'cms_smart_gallery_lightbox_data', null);
$smartCsrf = strpos($smartEndpointSource, 'csrf_token();');
$smartRelease = strpos($smartEndpointSource, 'cms_release_gallery_lightbox_session_lock();');
$smartPreload = strpos($smartEndpointSource, 'thumbnail_bundles_preload($images);');
$smartLoop = strpos($smartEndpointSource, 'foreach ($images as $rowIndex => $image)');
lightbox_metadata_assert($smartCsrf !== false && $smartRelease !== false && $smartPreload !== false && $smartLoop !== false, 'Smart Gallery lazy lightbox endpoint lifecycle markers are incomplete.');
lightbox_metadata_assert($smartCsrf < $smartRelease, 'Smart Gallery vote CSRF state must be persisted before releasing the session.');
lightbox_metadata_assert($smartRelease < $smartPreload && $smartPreload < $smartLoop, 'Smart Gallery lightbox endpoint must release the session before batched thumbnail work and item rendering.');

fwrite(STDOUT, "lightbox metadata lifecycle contract: OK\n");
