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


/**
 * Keeps one range slider display span synchronized with its current value.
 *
 * This helper is intentionally independent from the Theme form because gallery
 * edit pages also expose display-grid sliders while using a different form.
 *
 * @param {string} controlSelector CSS selector for the range input.
 * @param {string} displaySelector CSS selector for the text value.
 * @returns {void}
 */
function syncGridRangeDisplay(controlSelector, displaySelector) {
    // controls stores every slider matching the requested control selector.
    const controls = Array.from(document.querySelectorAll(controlSelector));
    // displays stores every numeric readout matching the requested display selector.
    const displays = Array.from(document.querySelectorAll(displaySelector));
    if (controls.length === 0 || displays.length === 0) {
        return;
    }

    controls.forEach((control, index) => {
        // display stores the paired value readout. When only one display exists,
        // it is reused for the single slider on the current admin page.
        const display = displays[index] || displays[0];
        if (!display) {
            return;
        }

        /**
         * Copies the sanitized slider value to the visible display element.
         *
         * @returns {void}
         */
        const syncValue = () => {
            display.textContent = String(Math.max(1, parseInt(control.value, 10) || 1));
        };

        control.addEventListener('input', syncValue);
        control.addEventListener('change', syncValue);
        syncValue();
    });
}


/**
 * Automatically enables a per-gallery grid override when the admin edits its sliders.
 *
 * This keeps the UI forgiving: an admin can move Columns or Rows directly and the
 * form will persist those numbers as a custom gallery grid, instead of silently
 * treating the gallery as inherited because the override checkbox was forgotten.
 *
 * @returns {void}
 */
function setupGalleryGridOverrideAutoEnable() {
    // overrideControl stores the checkbox deciding whether this gallery owns a grid.
    const overrideControl = document.querySelector('[data-gallery-grid-override-enabled]');
    if (!overrideControl) {
        return;
    }

    // gridControls stores the per-gallery sliders that imply an explicit override.
    const gridControls = document.querySelectorAll('[data-gallery-grid-columns], [data-gallery-grid-rows]');
    gridControls.forEach((control) => {
        control.addEventListener('input', () => {
            overrideControl.checked = true;
        });
        control.addEventListener('change', () => {
            overrideControl.checked = true;
        });
    });
}

export function setupThemeOverrideForm() {
    syncGridRangeDisplay('[data-home-grid-columns]', '[data-home-grid-columns-display]');
    syncGridRangeDisplay('[data-home-grid-rows]', '[data-home-grid-rows-display]');
    syncGridRangeDisplay('[data-gallery-grid-columns]', '[data-gallery-grid-columns-display]');
    syncGridRangeDisplay('[data-gallery-grid-rows]', '[data-gallery-grid-rows-display]');
    setupGalleryGridOverrideAutoEnable();

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
