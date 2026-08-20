<?php

declare(strict_types=1);

/**
 * Regression contract for slideshow-only full-image preloading.
 *
 * The automatic slideshow must begin loading the next protected full source while
 * the current photo is still visible, then advance only after both the display
 * interval and that detached full-size decode have completed. Manual lightbox and
 * fullscreen navigation must continue through the ordinary preview-first path.
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
function slideshow_preload_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$prepareStart = strpos($source, 'function prepareLightboxSlideshowImage(index, signal = null)');
$prepareEnd = strpos($source, 'function syncLightboxSlideshowControls()', $prepareStart === false ? 0 : $prepareStart);
slideshow_preload_assert($prepareStart !== false && $prepareEnd !== false, 'Slideshow full-image preparation helper is missing.');
$prepareSource = substr($source, (int) $prepareStart, (int) $prepareEnd - (int) $prepareStart);
slideshow_preload_assert(str_contains($prepareSource, "card.dataset.fullSrc || card.dataset.previewSrc"), 'Slideshow preparation must prefer the full source.');
slideshow_preload_assert(str_contains($prepareSource, "loadFreshDecodedLightboxImage(fullSrc, {priority: 'high', signal})"), 'Slideshow preparation must fully load and decode the next full source without adding it to the reusable decoded cache.');
slideshow_preload_assert(!str_contains($prepareSource, 'loadDecodedLightboxImage(fullSrc'), 'Slideshow full-source preparation must remain transient instead of accumulating originals in the reusable decoded cache.');
slideshow_preload_assert(str_contains($prepareSource, 'fetchLightboxWindowAround(normalizedIndex)'), 'Slideshow preparation must support sparse paginated gallery metadata.');

$scheduleStart = strpos($source, 'function scheduleLightboxSlideshowNext()');
$scheduleEnd = strpos($source, 'async function startLightboxSlideshow()', $scheduleStart === false ? 0 : $scheduleStart);
slideshow_preload_assert($scheduleStart !== false && $scheduleEnd !== false, 'Slideshow scheduler is missing.');
$scheduleSource = substr($source, (int) $scheduleStart, (int) $scheduleEnd - (int) $scheduleStart);
$preparePosition = strpos($scheduleSource, 'prepareLightboxSlideshowImage(nextIndex, preloadController.signal)');
$timerPosition = strpos($scheduleSource, 'window.setTimeout(() =>');
$readyGatePosition = strpos($scheduleSource, 'preparedImagePromise.then((prepared) =>');
$openPosition = strpos($scheduleSource, 'openAt(nextIndex, {');
slideshow_preload_assert(str_contains($scheduleSource, 'const preloadController = new AbortController();'), 'Each slideshow cycle must own a cancellable next-image preload.');
slideshow_preload_assert(str_contains($scheduleSource, 'lightboxSlideshowPreloadController = preloadController;'), 'The active slideshow preload controller must be retained for stop/close cancellation.');
slideshow_preload_assert($preparePosition !== false && $timerPosition !== false && $preparePosition < $timerPosition, 'Next full-image preload must start before the slideshow timer expires.');
slideshow_preload_assert($readyGatePosition !== false && $timerPosition < $readyGatePosition, 'Automatic advance must wait for the preload only after the display timer gate opens.');
slideshow_preload_assert($openPosition !== false && $readyGatePosition < $openPosition, 'Automatic slideshow navigation must occur only inside the decoded-image readiness gate.');
slideshow_preload_assert(str_contains($scheduleSource, 'slideshowPreparedImage: prepared.image'), 'Automatic slideshow navigation must pass the decoded full image into the display path.');
slideshow_preload_assert(str_contains($scheduleSource, 'slideshowPreparedSrc: prepared.src'), 'Automatic slideshow navigation must bind the prepared image to its full source.');

$openStart = strpos($source, 'function openAt(index, options = {})');
$openEnd = strpos($source, 'function step(offset, options = {})', $openStart === false ? 0 : $openStart);
slideshow_preload_assert($openStart !== false && $openEnd !== false, 'Lightbox navigation function is missing.');
$openSource = substr($source, (int) $openStart, (int) $openEnd - (int) $openStart);
slideshow_preload_assert(str_contains($openSource, 'options.slideshowPreparedImage instanceof HTMLImageElement'), 'Prepared full images must be explicitly slideshow-only.');
slideshow_preload_assert(str_contains($openSource, 'const initialMainPromise = slideshowPreparedImage'), 'Prepared slideshow images must bypass the preview-first branch.');
slideshow_preload_assert(str_contains($openSource, '? showMainImage(slideshowPreparedImage)'), 'Prepared slideshow images must enter the existing decoded-image transition path directly.');

$clearStart = strpos($source, 'function clearLightboxSlideshowTimer()');
$clearEnd = strpos($source, 'function prepareLightboxSlideshowImage(', $clearStart === false ? 0 : $clearStart);
slideshow_preload_assert($clearStart !== false && $clearEnd !== false, 'Slideshow timer cancellation helper is missing.');
$clearSource = substr($source, (int) $clearStart, (int) $clearEnd - (int) $clearStart);
slideshow_preload_assert(str_contains($clearSource, 'lightboxSlideshowPreloadController.abort();'), 'Stopping or replacing a slideshow cycle must abort its unfinished full-image preload.');

fwrite(STDOUT, "lightbox slideshow preload contract: OK\n");
