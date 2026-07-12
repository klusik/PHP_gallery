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
 *   2026-06-09
 */

import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox-deferred.js?v=20260620-lightbox-phaseb-help-map-nav-v1';

const optionalPublicModules = {
    votes: './gallery-modules/votes.js?v=20260512-lightbox-vote-clone-widget-v6',
    backToTop: './gallery-modules/back-to-top.js?v=20260510-lifecycle-v3',
    publicHomeSearch: './gallery-modules/public-home-search.js?v=20260528-public-search-context-v1',
    responsiveThumbnails: './gallery-modules/responsive-thumbnails.js?v=20260510-lazy-map-v1',
    thumbnailWarmup: './gallery-modules/thumbnail-warmup.js?v=20260608-thumbnail-warmup-v1',
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
        setupOptionalPublicFeature(optionalPublicModules.thumbnailWarmup, 'setupThumbnailWarmup', 'img[data-thumbnail-warmup-id][data-thumbnail-warmup-token][data-thumbnail-warmup-endpoint]');
    });
}

bootPublicGalleryBrowserFeatures();
