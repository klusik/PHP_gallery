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
 *   2026-05-04
 */

/**
 * Responsive thumbnail size hints
 *
 * Measures rendered gallery cells and updates image sizes attributes so dense grids can request smaller thumbnails.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

export function setupResponsiveThumbnailSizes() {
    // thumbnails stores visible gallery images that can benefit from measured sizes.
    const thumbnails = Array.from(document.querySelectorAll('img[data-responsive-thumbnail]'));
    if (!thumbnails.length) {
        return;
    }

    /**
     * Applies one measured CSS pixel width to an image and its source nodes.
     * @param {HTMLImageElement} image Image element inside a public photo card.
     * @returns {void}
     */
    function updateImageSizes(image) {
        // card stores the nearest card, because it best represents the real grid cell width.
        const card = image.closest('.image-card') || image.parentElement;
        if (!card) {
            return;
        }
        // measuredWidth stores the actual rendered width in CSS pixels.
        const measuredWidth = Math.ceil(card.getBoundingClientRect().width || image.getBoundingClientRect().width || 0);
        if (measuredWidth <= 0) {
            return;
        }
        // sizesValue stores a concrete sizes hint understood by the browser srcset selector.
        const sizesValue = `${measuredWidth}px`;
        if (image.getAttribute('sizes') !== sizesValue) {
            image.setAttribute('sizes', sizesValue);
        }
        // picture stores the optional wrapper that may contain WebP and JPEG sources.
        const picture = image.closest('picture');
        if (picture) {
            picture.querySelectorAll('source[sizes]').forEach((source) => {
                source.setAttribute('sizes', sizesValue);
            });
        }
    }

    /**
     * Updates all public thumbnail sizes after layout changes.
     * @returns {void}
     */
    function updateAllImageSizes() {
        thumbnails.forEach(updateImageSizes);
    }

    updateAllImageSizes();

    if ('ResizeObserver' in window) {
        // observer stores resize notifications for gallery cards and the surrounding image grid.
        const observer = new ResizeObserver(updateAllImageSizes);
        // observedElements stores unique layout elements that can alter thumbnail widths.
        const observedElements = new Set();
        thumbnails.forEach((image) => {
            const card = image.closest('.image-card');
            if (card) {
                observedElements.add(card);
            }
            const grid = image.closest('[data-gallery-image-list]');
            if (grid) {
                observedElements.add(grid);
            }
        });
        observedElements.forEach((element) => observer.observe(element));
    } else {
        window.addEventListener('resize', updateAllImageSizes);
    }
}
