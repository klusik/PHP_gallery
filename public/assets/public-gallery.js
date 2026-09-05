/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/public-gallery.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides the anonymous public-page browser entrypoint.
 *
 * Responsibilities:
 *   - Load only visitor-facing public gallery behavior
 *   - Keep admin-only modules out of the anonymous public critical path
 *   - Preserve the existing server-rendered public lightbox, votes, search, and thumbnail lifecycle
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
 *   2026-09-05
 */

import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox-deferred.js?v=20260905-map-popup-viewer-navigation-v1';

const optionalPublicModules = {
    votes: './gallery-modules/votes.js?v=20260512-lightbox-vote-clone-widget-v6',
    backToTop: './gallery-modules/back-to-top.js?v=20260510-lifecycle-v3',
    publicHomeSearch: './gallery-modules/public-home-search.js?v=20260528-public-search-context-v1',
    responsiveThumbnails: './gallery-modules/responsive-thumbnails.js?v=20260510-lazy-map-v1',
    progressiveThumbnailRenderer: './gallery-modules/progressive-thumbnail-renderer.js?v=20260824-aspect-aware-thumbnail-selection',
    thumbnailRenderDiagnostics: './gallery-modules/public-thumbnail-render-diagnostics.js?v=20260809-thumbnail-render-diagnostics-v1',
    thumbnailWarmup: './gallery-modules/thumbnail-warmup.js?v=20260608-thumbnail-warmup-v1',
    heroTags: './gallery-modules/hero-tags.js?v=20260811-hero-tags-v1',
    viewerFavourites: './gallery-modules/viewer-favourites.js?v=20260818-viewer-favourites-v1',
    galleryDownload: './gallery-modules/gallery-download.js?v=20260903-download-capability-stage4-v1',
};

/**
 * Runs a setup callback after the DOM is ready.
 *
 * Module scripts are deferred by default, but this helper keeps the public
 * entrypoint aligned with the legacy gallery.js lifecycle for code that
 * measures DOM nodes or upgrades server-rendered media.
 *
 * @param {() => void} callback Feature setup function that expects parsed DOM nodes.
 */
function runWhenDomReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, {once: true});
        return;
    }
    callback();
}


/**
 * Returns whether the current document contains a selector needed by an optional public feature.
 *
 * @param {string} selector CSS selector tested against the parsed public markup.
 * @return {boolean} True when the feature has matching markup on this page.
 */
function publicFeatureMarkupExists(selector) {
    return document.querySelector(selector) !== null;
}

/**
 * Loads one optional public feature only after matching markup exists.
 *
 * @param {string} moduleUrl Browser module URL relative to this entrypoint.
 * @param {string} exportName Named setup function exported by the target module.
 * @param {string} selector CSS selector that proves the feature is present.
 */
function setupOptionalPublicFeature(moduleUrl, exportName, selector) {
    if (!publicFeatureMarkupExists(selector)) {
        return;
    }
    import(moduleUrl)
        .then((module) => {
            if (typeof module[exportName] === 'function') {
                module[exportName]();
            }
        })
        .catch(() => {});
}

/**
 * Boots anonymous public gallery browser features.
 *
 * These setup calls intentionally match the public subset from gallery.js. Admin
 * tools, upload workflows, side panels, reorder controls, and editors remain in
 * the legacy admin entrypoint so anonymous visitors do not fetch that module graph.
 */
function bootPublicGalleryBrowserFeatures() {
    setupGalleryLightbox();

    if (publicFeatureMarkupExists('[data-tag-input]')) {
        setupTagSuggestions();
    }

    runWhenDomReady(() => {
        setupOptionalPublicFeature(optionalPublicModules.votes, 'setupVoteForms', '[data-vote-form]');
        setupOptionalPublicFeature(optionalPublicModules.backToTop, 'setupBackToTopButton', '[data-back-to-top-button]');
        setupOptionalPublicFeature(optionalPublicModules.publicHomeSearch, 'setupPublicHomeSearch', '[data-public-home-search]');
        setupOptionalPublicFeature(optionalPublicModules.responsiveThumbnails, 'setupResponsiveThumbnailSizes', 'img[data-responsive-thumbnail]');
        setupOptionalPublicFeature(optionalPublicModules.progressiveThumbnailRenderer, 'setupProgressiveThumbnailRenderer', 'img[data-progressive-thumbnail]');
        setupOptionalPublicFeature(optionalPublicModules.thumbnailRenderDiagnostics, 'setupPublicThumbnailRenderDiagnostics', '[data-public-thumbnail-diagnostics]');
        setupOptionalPublicFeature(optionalPublicModules.thumbnailWarmup, 'setupThumbnailWarmup', 'img[data-thumbnail-warmup-id][data-thumbnail-warmup-token][data-thumbnail-warmup-endpoint]');
        setupOptionalPublicFeature(optionalPublicModules.heroTags, 'setupHeroTagDisclosure', '[data-hero-tags]');
        setupOptionalPublicFeature(optionalPublicModules.viewerFavourites, 'setupViewerFavourites', '[data-viewer-favourite-form]');
        setupOptionalPublicFeature(optionalPublicModules.galleryDownload, 'setupGalleryDownload', '[data-gallery-download]');
    });
}

bootPublicGalleryBrowserFeatures();
