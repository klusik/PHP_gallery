/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery.js
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
 *   2026-05-19
 */

/**
 * PHP Gallery browser entrypoint.
 *
 * This file deliberately contains orchestration only. Feature code lives in
 * focused ES modules under `public/assets/gallery-modules/`. The split keeps the
 * historic single-file behavior intact while making each subsystem easier to
 * read, test in the browser console, and modify without scrolling through the
 * full viewer implementation.
 *
 * Loading model:
 * 1. `render_gallery_assets()` emits this file as `<script type="module">`.
 * 2. Browser module scripts are deferred by default, so the DOM is parsed before
 *    this entrypoint runs in normal page loads.
 * 3. Features that are sensitive to late DOM availability still use
 *    `runWhenDomReady()` as a defensive guard.
 *
 * Example console usage during development:
 *
 * import('/assets/gallery-modules/responsive-thumbnails.js')
 *   .then((module) => module.setupResponsiveThumbnailSizes());
 */

import { setupThemeOverrideForm } from './gallery-modules/theme-form.js?v=20260511-i18n-js-v1';
import { setupResponsiveThumbnailSizes } from './gallery-modules/responsive-thumbnails.js?v=20260510-lazy-map-v1';
import { setupFaviconCropper } from './gallery-modules/favicon-cropper.js';
import { setupBackToTopButton } from './gallery-modules/back-to-top.js?v=20260510-lifecycle-v3';
import { setupGallerySearchPickers } from './gallery-modules/searchable-gallery-picker.js?v=20260519-gallery-picker-v1';
import { setupPictureManager } from './gallery-modules/picture-manager.js?v=20260519-picture-manager-v5';
import { setupAdminDatePickers } from './gallery-modules/admin-date-picker.js?v=20260512-admin-date-picker-v1';
import { setupSimbriefDescriptionGenerator } from './gallery-modules/admin-simbrief-description.js?v=20260527-simbrief-ofp-route-v1';
import { setupAdminNavigationDataPanel } from './gallery-modules/admin-navdata-panel.js?v=20260527-navdata-panel-v1';
import { setupOpenAITextAssist } from './gallery-modules/admin-openai-text-assist.js?v=20260529-openai-text-assist-v2';
import { setupPublicHomeSearch } from './gallery-modules/public-home-search.js?v=20260528-public-search-context-v1';
import { setupAdminGalleryMigration } from './gallery-modules/admin-gallery-migration.js?v=20260527-gallery-migration-reconnect-v1';
import { setupVoteForms } from './gallery-modules/votes.js?v=20260512-lightbox-vote-clone-widget-v6';
import { setupAdminBulkSelection, setupGalleryBulkDeleteConfirmation, setupImageBulkDeleteConfirmation, setupImageBulkMoveFields, setupThumbnailCacheDeleteConfirmation } from './gallery-modules/admin-bulk-actions.js?v=20260519-gallery-picker-v1';
import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox-deferred.js?v=20260601-3d-carousel-v2';
import {
    setupAdminGalleryFilters,
    setupAdminGalleryTree,
    setupAdminTabs,
    setupAdminNestedTabs,
    setupAdminGalleryReordering,
    setupAdminImageReordering,
    setupPublicGalleryPageReordering,
    setupAdminLogStatusForms,
    setupAdminLogLiveFilters,
    setupGalleryRefreshProgress,
    setupGalleryUploadProgress,
    setupAdminGallerySidePanel,
    setupPictureGame,
    setupThumbnailProgress,
    setupAdminNavdataUpdateFeedback,
    setupAdminMediaRenamer,
} from './gallery-modules/admin-operations.js?v=20260604-admin-subtabs-v1';

/**
 * Runs a setup callback after the DOM is ready.
 *
 * Most module scripts run after parsing, but this helper documents the expected
 * timing and protects the few setup routines that measure DOM layout or attach
 * pointer handlers to late-rendered admin tables.
 *
 * @param {() => void} callback Feature setup function that expects parsed DOM nodes.
 * @returns {void}
 */
function runWhenDomReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, {once: true});
        return;
    }
    callback();
}

/**
 * Boots all gallery browser features.
 *
 * Each setup function is null-safe. Pages that do not contain the corresponding
 * controls simply return from that module, so the same entrypoint can be loaded
 * on public gallery pages, admin pages, setup pages, and utility screens.
 *
 * @returns {void}
 */
function bootGalleryBrowserFeatures() {
    setupAdminBulkSelection();
    setupGalleryBulkDeleteConfirmation();
    setupImageBulkDeleteConfirmation();
    setupGallerySearchPickers();
    setupImageBulkMoveFields();
    setupThumbnailCacheDeleteConfirmation();
    setupAdminTabs();
    setupAdminNestedTabs();
    setupAdminDatePickers();
    setupSimbriefDescriptionGenerator();
    setupOpenAITextAssist();
    setupAdminGalleryMigration();
    setupAdminMediaRenamer();
    setupAdminGalleryFilters();
    setupAdminGalleryTree();
    setupAdminGalleryReordering();
    setupThumbnailProgress();
    setupGalleryRefreshProgress();
    setupGalleryUploadProgress();
    setupAdminGallerySidePanel();
    setupPictureGame();
    setupAdminLogStatusForms();
    setupAdminLogLiveFilters();
    setupVoteForms();
    setupBackToTopButton();
    setupThemeOverrideForm();
    setupFaviconCropper();
    setupTagSuggestions();
    setupGalleryLightbox();
    setupPictureManager();
    setupPublicHomeSearch();

    runWhenDomReady(setupAdminNavdataUpdateFeedback);
    runWhenDomReady(setupAdminNavigationDataPanel);
    runWhenDomReady(setupResponsiveThumbnailSizes);
    runWhenDomReady(setupAdminImageReordering);
    runWhenDomReady(setupPublicGalleryPageReordering);
}

bootGalleryBrowserFeatures();
