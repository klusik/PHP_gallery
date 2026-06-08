/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-operations.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Re-exports focused admin browser modules while preserving the legacy import contract.
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
 *   2026-05-28
 */

export { setupAdminTabs } from './admin-tabs.js?v=20260514-admin-sidebar-hash-v2';
export { setupAdminNestedTabs } from './admin-nested-tabs.js?v=20260604-admin-subtabs-v1';
export { setupGalleryRefreshProgress } from './admin-refresh-progress.js?v=20260512-modular-admin-v1';
export { setupGalleryUploadProgress, setupAdminGallerySidePanel } from './admin-side-panel.js?v=20260528-image-tag-pagination-v1';
export { setupThumbnailProgress } from './admin-thumbnail-progress.js?v=20260608-thumbnail-compatibility-v1';
export { setupAdminNavdataUpdateFeedback } from './admin-navdata-update.js?v=20260521-navdata-feedback-v2';
export { setupPictureGame } from './admin-picture-game.js?v=20260512-modular-admin-v1';
export { setupAdminLogStatusForms, setupAdminLogLiveFilters } from './admin-logs.js?v=20260512-modular-admin-v1';
export { setupAdminGalleryFilters, setupAdminGalleryTree, setupAdminGalleryReordering, setupPublicGalleryPageReordering } from './admin-gallery-list.js?v=20260519-public-drop-refactor-v1';
export { setupAdminImageReordering } from './admin-image-reordering.js?v=20260519-drag-ghost-v1';
export { setupAdminMediaRenamer } from './admin-media-renamer.js?v=20260603-media-renamer-apply-batches-v1';
