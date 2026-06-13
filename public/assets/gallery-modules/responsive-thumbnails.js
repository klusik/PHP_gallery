/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/responsive-thumbnails.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides client-side behavior for the PHP Gallery user interface.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-10
 */

/**
 * Responsive thumbnail size hints
 *
 * Measures rendered gallery cells only when they are near the viewport. Public
 * gallery thumbnails start with a cheap 300px candidate, then this module
 * upgrades visible large cells to their full srcset after the first image has
 * had a chance to paint.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

const responsiveThumbnailState = {
    observer: null,
    intersectionObserver: null,
    controller: null,
    frameId: 0,
    idleTimer: 0,
    idleHandle: 0,
    upgradeTimers: new Set(),
    visibleImages: new Set(),
    observedElements: new Set(),
};

/**
 * Releases responsive thumbnail listeners and observers before public gallery markup is replaced.
 */
export function teardownResponsiveThumbnailSizes() {
    if (responsiveThumbnailState.frameId) {
        window.cancelAnimationFrame(responsiveThumbnailState.frameId);
        responsiveThumbnailState.frameId = 0;
    }
    if (responsiveThumbnailState.idleHandle && 'cancelIdleCallback' in window) {
        window.cancelIdleCallback(responsiveThumbnailState.idleHandle);
        responsiveThumbnailState.idleHandle = 0;
    }
    if (responsiveThumbnailState.idleTimer) {
        window.clearTimeout(responsiveThumbnailState.idleTimer);
        responsiveThumbnailState.idleTimer = 0;
    }
    responsiveThumbnailState.upgradeTimers.forEach((timerId) => window.clearTimeout(timerId));
    responsiveThumbnailState.upgradeTimers.clear();
    if (responsiveThumbnailState.observer) {
        responsiveThumbnailState.observer.disconnect();
        responsiveThumbnailState.observer = null;
    }
    if (responsiveThumbnailState.intersectionObserver) {
        responsiveThumbnailState.intersectionObserver.disconnect();
        responsiveThumbnailState.intersectionObserver = null;
    }
    if (responsiveThumbnailState.controller) {
        responsiveThumbnailState.controller.abort();
        responsiveThumbnailState.controller = null;
    }
    responsiveThumbnailState.visibleImages.clear();
    responsiveThumbnailState.observedElements.clear();
}

/**
 * Schedules low-priority work without delaying the first gallery paint.
 *
 * @param {() => void} callback Work to run when the browser is idle enough.
 */
function scheduleIdleThumbnailWork(callback) {
    const delayMs = Number.parseInt(String(callback.progressiveDelayMs || '0'), 10);
    if (Number.isFinite(delayMs) && delayMs > 0) {
        const timerId = window.setTimeout(() => {
            responsiveThumbnailState.upgradeTimers.delete(timerId);
            scheduleIdleThumbnailWork(Object.assign(callback, {progressiveDelayMs: 0}));
        }, Math.min(Math.max(delayMs, 0), 500));
        responsiveThumbnailState.upgradeTimers.add(timerId);
        return;
    }
    if ('requestIdleCallback' in window) {
        responsiveThumbnailState.idleHandle = window.requestIdleCallback(() => {
            responsiveThumbnailState.idleHandle = 0;
            callback();
        }, {timeout: 1200});
        return;
    }
    responsiveThumbnailState.idleTimer = window.setTimeout(() => {
        responsiveThumbnailState.idleTimer = 0;
        callback();
    }, 180);
}


/**
 * Parses a srcset attribute into ordered width candidates.
 *
 * @param {string} srcset Source-set string rendered by PHP.
 * @return {{url: string, width: number} []} Parsed candidates with numeric widths.
 */
function parseThumbnailSrcsetCandidates(srcset) {
    return String(srcset || '')
        .split(',')
        .map((candidate) => candidate.trim())
        .map((candidate) => {
            const parts = candidate.split(/\s+/).filter(Boolean);
            const descriptor = parts[1] || '';
            const width = descriptor.endsWith('w') ? Number.parseInt(descriptor.slice(0, -1), 10) : 0;
            return {
                url: parts[0] || '',
                width: Number.isFinite(width) ? width : 0,
            };
        })
        .filter((candidate) => candidate.url !== '')
        .sort((a, b) => a.width - b.width);
}

/**
 * Selects the smallest candidate that satisfies the current rendered thumbnail need.
 *
 * @param {string} srcset Source-set string rendered by PHP.
 * @param {number} requiredWidth Device-pixel width needed for a sharp thumbnail.
 * @return {string} URL that should be decoded before the visible srcset changes.
 */
function selectThumbnailPreloadCandidate(srcset, requiredWidth) {
    const candidates = parseThumbnailSrcsetCandidates(srcset);
    if (!candidates.length) {
        return '';
    }
    const selected = candidates.find((candidate) => candidate.width >= requiredWidth);
    return (selected || candidates[candidates.length - 1]).url;
}

/**
 * Decodes the likely replacement thumbnail before the visible image receives a larger srcset.
 *
 * @param {string} src URL selected from the larger progressive srcset.
 * @return {Promise<boolean>} True when the browser has loaded or decoded the replacement.
 */
function preloadProgressiveThumbnailCandidate(src) {
    if (!src) {
        return Promise.resolve(false);
    }
    return new Promise((resolve) => {
        const preloader = new Image();
        preloader.decoding = 'async';
        preloader.onload = () => {
            if (typeof preloader.decode === 'function') {
                preloader.decode().then(() => resolve(true)).catch(() => resolve(true));
                return;
            }
            resolve(true);
        };
        preloader.onerror = () => resolve(false);
        preloader.src = src;
    });
}

/**
 * Copies progressive srcset data into real image attributes.
 *
 * @param {HTMLImageElement} image Thumbnail image to upgrade.
 * @param {string} sizesValue Current measured sizes hint.
 */
function upgradeProgressiveThumbnail(image, sizesValue) {
    if (image.dataset.progressiveUpgraded === '1' || image.dataset.progressiveUpgradePending === '1') {
        return;
    }
    /**
     * Run upgrade.
     *
     * Used by browser-side gallery behavior.
     */
    const runUpgrade = () => {
        if (!image.isConnected || image.dataset.progressiveUpgraded === '1') {
            return;
        }
        image.dataset.progressiveUpgradePending = '1';
        const finalSizes = image.dataset.progressiveSizes || sizesValue;
        const effectiveNeed = Math.ceil((Number.parseInt(sizesValue, 10) || image.getBoundingClientRect().width || 300) * Math.min(window.devicePixelRatio || 1, 2));
        const picture = image.closest('picture');
        const preferredSource = picture?.querySelector('source[data-progressive-srcset]');
        const preferredSrcset = preferredSource?.getAttribute('data-progressive-srcset') || image.getAttribute('data-progressive-srcset') || '';
        const preloadSrc = selectThumbnailPreloadCandidate(preferredSrcset, effectiveNeed);

        preloadProgressiveThumbnailCandidate(preloadSrc).then(() => {
            if (!image.isConnected || image.dataset.progressiveUpgraded === '1') {
                return;
            }
            if (picture) {
                picture.querySelectorAll('source[data-progressive-srcset]').forEach((source) => {
                    const nextSrcset = source.getAttribute('data-progressive-srcset') || '';
                    if (nextSrcset !== '') {
                        source.setAttribute('sizes', source.getAttribute('data-progressive-sizes') || finalSizes);
                        source.setAttribute('srcset', nextSrcset);
                    }
                });
            }
            const imageSrcset = image.getAttribute('data-progressive-srcset') || '';
            if (imageSrcset !== '') {
                image.setAttribute('sizes', finalSizes);
                image.setAttribute('srcset', imageSrcset);
            }
            image.dataset.progressiveUpgraded = '1';
        }).finally(() => {
            if (image.isConnected) {
                delete image.dataset.progressiveUpgradePending;
            }
        });
    };

    const delayMs = Number.parseInt(image.dataset.progressiveDelayMs || '0', 10);
    const scheduledUpgrade = Object.assign(runUpgrade, {
        progressiveDelayMs: Number.isFinite(delayMs) ? delayMs : 0,
    });

    if (image.complete && image.naturalWidth > 0) {
        scheduleIdleThumbnailWork(scheduledUpgrade);
        return;
    }
    image.addEventListener('load', () => scheduleIdleThumbnailWork(scheduledUpgrade), {
        once: true,
        signal: responsiveThumbnailState.controller?.signal,
    });
}

/**
 * Applies one measured CSS pixel width to an image and its source nodes.
 *
 * @param {HTMLImageElement} image Image element inside a public card.
 */
function updateImageSizes(image) {
    if (!responsiveThumbnailState.controller || responsiveThumbnailState.controller.signal.aborted || !image.isConnected) {
        return;
    }
    const card = image.closest('.image-card, .gallery-card') || image.parentElement;
    if (!card || !card.isConnected) {
        return;
    }
    const measuredWidth = Math.ceil(card.getBoundingClientRect().width || image.getBoundingClientRect().width || 0);
    if (measuredWidth <= 0) {
        return;
    }
    const sizesValue = `${measuredWidth}px`;
    if (image.getAttribute('sizes') !== sizesValue) {
        image.setAttribute('sizes', sizesValue);
    }
    const picture = image.closest('picture');
    if (picture) {
        picture.querySelectorAll('source[sizes]').forEach((source) => {
            source.setAttribute('sizes', sizesValue);
        });
    }
    const effectiveNeed = measuredWidth * Math.min(window.devicePixelRatio || 1, 2);
    if (image.hasAttribute('data-progressive-thumbnail') && effectiveNeed > 340) {
        upgradeProgressiveThumbnail(image, sizesValue);
    }
}

/**
 * Observes layout changes only for cards that have reached the viewport margin.
 *
 * @param {HTMLImageElement} image Thumbnail image that is now relevant.
 */
function activateResponsiveThumbnail(image) {
    if (!image.isConnected || responsiveThumbnailState.visibleImages.has(image)) {
        return;
    }
    responsiveThumbnailState.visibleImages.add(image);
    updateImageSizes(image);

    if (!responsiveThumbnailState.observer) {
        return;
    }
    const card = image.closest('.image-card, .gallery-card');
    const grid = image.closest('[data-gallery-image-list], [data-public-subgallery-grid]');
    [card, grid].forEach((element) => {
        if (element instanceof Element && !responsiveThumbnailState.observedElements.has(element)) {
            responsiveThumbnailState.observedElements.add(element);
            responsiveThumbnailState.observer.observe(element);
        }
    });
}

/**
 * Updates all activated thumbnails after layout changes.
 */
function updateVisibleImageSizes() {
    if (!responsiveThumbnailState.controller || responsiveThumbnailState.controller.signal.aborted) {
        return;
    }
    responsiveThumbnailState.visibleImages.forEach((image) => {
        if (image.isConnected) {
            updateImageSizes(image);
        } else {
            responsiveThumbnailState.visibleImages.delete(image);
        }
    });
}

/**
 * Schedules one thumbnail measurement pass after layout has settled.
 */
function scheduleUpdateVisibleImageSizes() {
    if (!responsiveThumbnailState.controller || responsiveThumbnailState.controller.signal.aborted || responsiveThumbnailState.frameId) {
        return;
    }
    responsiveThumbnailState.frameId = window.requestAnimationFrame(() => {
        responsiveThumbnailState.frameId = 0;
        updateVisibleImageSizes();
    });
}

/**
 * Initializes progressive and responsive thumbnail sizing.
 */
export function setupResponsiveThumbnailSizes() {
    teardownResponsiveThumbnailSizes();

    const thumbnails = Array.from(document.querySelectorAll('img[data-responsive-thumbnail]'));
    if (!thumbnails.length) {
        return;
    }

    const controller = new AbortController();
    responsiveThumbnailState.controller = controller;

    if ('ResizeObserver' in window) {
        responsiveThumbnailState.observer = new ResizeObserver(scheduleUpdateVisibleImageSizes);
    } else {
        window.addEventListener('resize', scheduleUpdateVisibleImageSizes, {signal: controller.signal});
    }

    if ('IntersectionObserver' in window) {
        responsiveThumbnailState.intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const image = entry.target;
                    if (image instanceof HTMLImageElement) {
                        activateResponsiveThumbnail(image);
                        responsiveThumbnailState.intersectionObserver?.unobserve(image);
                    }
                }
            });
        }, {rootMargin: '900px 0px', threshold: 0.01});

        thumbnails.forEach((image) => responsiveThumbnailState.intersectionObserver.observe(image));
        return;
    }

    const pending = thumbnails.slice();
    /**
     * Process batch.
     *
     * Used by browser-side gallery behavior.
     */
    const processBatch = () => {
        if (controller.signal.aborted) {
            return;
        }
        pending.splice(0, 10).forEach(activateResponsiveThumbnail);
        if (pending.length > 0) {
            scheduleIdleThumbnailWork(processBatch);
        }
    };
    scheduleIdleThumbnailWork(processBatch);
}
