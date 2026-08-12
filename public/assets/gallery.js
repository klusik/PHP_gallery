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
 *   2026-08-11
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

import { setupThemeOverrideForm } from './gallery-modules/theme-form.js?v=20260811-hero-tag-theme-v1';
import { setupHeroTagDisclosure } from './gallery-modules/hero-tags.js?v=20260811-hero-tags-v1';
import { setupResponsiveThumbnailSizes } from './gallery-modules/responsive-thumbnails.js?v=20260510-lazy-map-v1';
import { setupThumbnailWarmup } from './gallery-modules/thumbnail-warmup.js?v=20260608-thumbnail-warmup-v1';
import { setupFaviconCropper } from './gallery-modules/favicon-cropper.js';
import { setupBackToTopButton } from './gallery-modules/back-to-top.js?v=20260510-lifecycle-v3';
import { setupGallerySearchPickers } from './gallery-modules/searchable-gallery-picker.js?v=20260519-gallery-picker-v1';
import { setupPictureManager } from './gallery-modules/picture-manager.js?v=20260519-picture-manager-v5';
import { setupAdminDatePickers } from './gallery-modules/admin-date-picker.js?v=20260512-admin-date-picker-v1';
import { setupSimbriefDescriptionGenerator } from './gallery-modules/admin-simbrief-description.js?v=20260527-simbrief-ofp-route-v1';
import { setupAdminNavigationDataPanel } from './gallery-modules/admin-navdata-panel.js?v=20260527-navdata-panel-v1';
import { setupOpenAITextAssist } from './gallery-modules/admin-openai-text-assist.js?v=20260529-openai-text-assist-v2';
import { setupPublicHomeSearch } from './gallery-modules/public-home-search.js?v=20260528-public-search-context-v1';
import { setupAdminSettingsSearch } from './gallery-modules/admin-settings-search.js?v=20260812-settings-spotlight-v1';
import { setupAdminGalleryMigration } from './gallery-modules/admin-gallery-migration.js?v=20260527-gallery-migration-reconnect-v1';
import { setupAdminGalleryDateSuggestions } from './gallery-modules/admin-gallery-date-suggestion.js?v=20260607-date-suggestion-endpoint-v2';
import { setupAdminDuplicatePhotoDetector } from './gallery-modules/admin-duplicate-photo-detector.js?v=20260808-duplicate-photo-detector-ledger-v4';
import { setupAdminStorageStatistics } from './gallery-modules/admin-storage-statistics.js?v=20260608-storage-statistics-v1';
import { setupAdminGalleryReport } from './gallery-modules/admin-gallery-report.js?v=20260615-admin-gallery-report-v1';
import { setupAdminGalleryBenchmark } from './gallery-modules/admin-gallery-benchmark.js?v=20260618-gallery-benchmark-v1';
import { setupPublicThumbnailRenderDiagnostics } from './gallery-modules/public-thumbnail-render-diagnostics.js?v=20260809-thumbnail-render-diagnostics-v1';
import { setupVoteForms } from './gallery-modules/votes.js?v=20260512-lightbox-vote-clone-widget-v6';
import { setupAdminBulkSelection, setupGalleryBulkDeleteConfirmation, setupImageBulkDeleteConfirmation, setupImageBulkMoveFields, setupThumbnailCacheDeleteConfirmation } from './gallery-modules/admin-bulk-actions.js?v=20260519-gallery-picker-v1';
import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox-deferred.js?v=20260811-leaflet-safari-marker-v1';
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
    setupAdminMetadataOrganizer,
} from './gallery-modules/admin-operations.js?v=20260812-deferred-maintenance-v2';

/**
 * Runs a setup callback after the DOM is ready.
 *
 * Most module scripts run after parsing, but this helper documents the expected
 * timing and protects the few setup routines that measure DOM layout or attach
 * pointer handlers to late-rendered admin tables.
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
 * Load progressive public thumbnail behavior only when the server selected that renderer for this page.
 *
 * Logged-in visitors share gallery.js with Admin pages, so a conditional dynamic import keeps the progressive
 * implementation out of Admin-only module work while preserving the same public renderer lifecycle as anonymous pages.
 */
function setupProgressiveThumbnailRendererWhenPresent() {
    if (!document.querySelector('img[data-progressive-thumbnail]')) {
        return;
    }
    import('./gallery-modules/progressive-thumbnail-renderer.js?v=20260809-progressive-thumbnail-renderer')
        .then((module) => module.setupProgressiveThumbnailRenderer?.())
        .catch(() => {});
}

/**
 * Boots all gallery browser features.
 *
 * Each setup function is null-safe. Pages that do not contain the corresponding
 * controls simply return from that module, so the same entrypoint can be loaded
 * on public gallery pages, admin pages, setup pages, and utility screens.
 */
function bootGalleryBrowserFeatures() {
    setupAdminBulkSelection();
    setupAdminGalleryDateSuggestions();
    setupAdminDuplicatePhotoDetector();
    setupAdminStorageStatistics();
    setupAdminGalleryReport();
    setupAdminGalleryBenchmark();
    setupPublicThumbnailRenderDiagnostics();
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
    setupAdminMetadataOrganizer();
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
    setupHeroTagDisclosure();
    setupFaviconCropper();
    setupTagSuggestions();
    setupGalleryLightbox();
    setupPictureManager();
    setupPublicHomeSearch();
    setupAdminSettingsSearch();

    runWhenDomReady(setupAdminNavdataUpdateFeedback);
    runWhenDomReady(setupAdminNavigationDataPanel);
    runWhenDomReady(setupResponsiveThumbnailSizes);
    runWhenDomReady(setupProgressiveThumbnailRendererWhenPresent);
    runWhenDomReady(setupThumbnailWarmup);
    runWhenDomReady(setupAdminImageReordering);
    runWhenDomReady(setupPublicGalleryPageReordering);
}

bootGalleryBrowserFeatures();
