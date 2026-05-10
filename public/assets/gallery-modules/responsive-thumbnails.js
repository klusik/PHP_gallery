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
    visibleImages: new Set(),
    observedElements: new Set(),
};

/**
 * Releases responsive thumbnail listeners and observers before public gallery markup is replaced.
 *
 * @returns {void}
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
 * @returns {void}
 */
function scheduleIdleThumbnailWork(callback) {
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
 * Copies progressive srcset data into real image attributes.
 *
 * @param {HTMLImageElement} image Thumbnail image to upgrade.
 * @param {string} sizesValue Current measured sizes hint.
 * @returns {void}
 */
function upgradeProgressiveThumbnail(image, sizesValue) {
    if (image.dataset.progressiveUpgraded === '1') {
        return;
    }
    const runUpgrade = () => {
        if (!image.isConnected || image.dataset.progressiveUpgraded === '1') {
            return;
        }
        const finalSizes = image.dataset.progressiveSizes || sizesValue;
        const picture = image.closest('picture');
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
    };

    if (image.complete && image.naturalWidth > 0) {
        scheduleIdleThumbnailWork(runUpgrade);
        return;
    }
    image.addEventListener('load', () => scheduleIdleThumbnailWork(runUpgrade), {
        once: true,
        signal: responsiveThumbnailState.controller?.signal,
    });
}

/**
 * Applies one measured CSS pixel width to an image and its source nodes.
 *
 * @param {HTMLImageElement} image Image element inside a public card.
 * @returns {void}
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
 * @returns {void}
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
 *
 * @returns {void}
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
 *
 * @returns {void}
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
 *
 * @returns {void}
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
