/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/theme-form.js
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
 * Theme and pagination form helpers
 *
 * Keeps admin theme controls interactive without requiring a page reload before submit.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

export function setupThemeOverrideForm() {
    // form stores state or configuration for the gallery front-end flow.
    const form = document.querySelector('[data-theme-form]');
    if (!form) {
        return;
    }
    // changed stores state or configuration for the gallery front-end flow.
    const changed = form.querySelector('[data-theme-controls-changed]');
    if (!changed) {
        return;
    }
    form.querySelectorAll('[data-theme-override-control]').forEach((control) => {
        control.addEventListener('input', () => {
            changed.value = '1';
        });
        control.addEventListener('change', () => {
            changed.value = '1';
        });
    });
    // opacityControl stores state or configuration for the gallery front-end flow.
    const opacityControl = form.querySelector('[data-theme-background-opacity]');
    // opacityDisplay stores state or configuration for the gallery front-end flow.
    const opacityDisplay = form.querySelector('[data-theme-background-opacity-display]');
    if (opacityControl && opacityDisplay) {
        /**
         * Handles sync opacity behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        const syncOpacity = () => {
            opacityDisplay.textContent = `${opacityControl.value}%`;
        };
        opacityControl.addEventListener('input', syncOpacity);
        opacityControl.addEventListener('change', syncOpacity);
        syncOpacity();
    }
    // columnsControl stores state or configuration for the gallery front-end flow.
    const columnsControl = form.querySelector('[data-pagination-columns]');
    // rowsControl stores state or configuration for the gallery front-end flow.
    const rowsControl = form.querySelector('[data-pagination-rows]');
    // columnsDisplay stores state or configuration for the gallery front-end flow.
    const columnsDisplay = form.querySelector('[data-pagination-columns-display]');
    // rowsDisplay stores state or configuration for the gallery front-end flow.
    const rowsDisplay = form.querySelector('[data-pagination-rows-display]');
    // itemsPreview stores state or configuration for the gallery front-end flow.
    const itemsPreview = form.querySelector('[data-pagination-items-preview]');
    if (columnsControl && rowsControl && columnsDisplay && rowsDisplay && itemsPreview) {
        /**
         * Handles sync pagination preview behavior for the gallery UI.
         * @returns {*} Result of the UI operation, when a value is produced.
         */
        const syncPaginationPreview = () => {
            // columns stores state or configuration for the gallery front-end flow.
            const columns = Math.max(1, parseInt(columnsControl.value, 10) || 1);
            // rows stores state or configuration for the gallery front-end flow.
            const rows = Math.max(1, parseInt(rowsControl.value, 10) || 1);
            columnsDisplay.textContent = String(columns);
            rowsDisplay.textContent = String(rows);
            itemsPreview.textContent = String(columns * rows);
        };
        columnsControl.addEventListener('input', syncPaginationPreview);
        columnsControl.addEventListener('change', syncPaginationPreview);
        rowsControl.addEventListener('input', syncPaginationPreview);
        rowsControl.addEventListener('change', syncPaginationPreview);
        syncPaginationPreview();
    }
}
