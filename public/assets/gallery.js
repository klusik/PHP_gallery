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

import { setupThemeOverrideForm } from './gallery-modules/theme-form.js';
import { setupResponsiveThumbnailSizes } from './gallery-modules/responsive-thumbnails.js';
import { setupFaviconCropper } from './gallery-modules/favicon-cropper.js';
import { setupBackToTopButton } from './gallery-modules/back-to-top.js';
import { setupVoteForms } from './gallery-modules/votes.js';
import { setupAdminBulkSelection, setupGalleryBulkDeleteConfirmation } from './gallery-modules/admin-bulk-actions.js';
import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox.js';
import {
    setupAdminGalleryFilters,
    setupAdminGalleryTree,
    setupAdminImageReordering,
    setupAdminLogStatusForms,
    setupGalleryRefreshProgress,
    setupGalleryUploadProgress,
    setupPictureGame,
    setupThumbnailProgress,
} from './gallery-modules/admin-operations.js';

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
    setupAdminGalleryFilters();
    setupAdminGalleryTree();
    setupThumbnailProgress();
    setupGalleryRefreshProgress();
    setupGalleryUploadProgress();
    setupPictureGame();
    setupAdminLogStatusForms();
    setupVoteForms();
    setupBackToTopButton();
    setupThemeOverrideForm();
    setupFaviconCropper();
    setupTagSuggestions();
    setupGalleryLightbox();

    runWhenDomReady(setupResponsiveThumbnailSizes);
    runWhenDomReady(setupAdminImageReordering);
}

bootGalleryBrowserFeatures();
