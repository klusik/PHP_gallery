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


/**
 * Reads the first matching form control value from the Theme form.
 *
 * @param {HTMLFormElement} form Theme form containing the appearance controls.
 * @param {string} selector CSS selector for the desired control.
 * @param {string} fallback Value used when the control is missing.
 * @returns {string} Current control value or fallback.
 */
function themeControlValue(form, selector, fallback) {
    // control stores the matching input, select, or range element used by the preview.
    const control = form.querySelector(selector);
    if (!control || typeof control.value !== 'string') {
        return fallback;
    }
    return control.value || fallback;
}


/**
 * Converts the two stored font modes into the real preview CSS font stack.
 *
 * @param {string} fontMode Theme font mode from the Admin select control.
 * @returns {string} CSS font-family value for the live preview.
 */
function themePreviewFontFamily(fontMode) {
    if (fontMode === 'sans') {
        return 'Arial, Helvetica, sans-serif';
    }
    return 'Georgia, Times New Roman, serif';
}


/**
 * Clamps the custom page-width value shared by the slider, number input, preview, and PHP validator.
 *
 * @param {string|number} value Raw value coming from either width control.
 * @returns {number} Safe pixel width between 1024 and 2048.
 */
function customPageWidthValue(value) {
    // width stores the parsed pixel width before clamping to the supported public layout range.
    const width = parseInt(String(value), 10);
    if (!Number.isFinite(width)) {
        return 1440;
    }
    return Math.max(1024, Math.min(2048, width));
}



/**
 * Keeps the compact Appearance preview synchronized with unsaved form values.
 *
 * The preview uses local CSS variables so it can mirror public styling without
 * changing the real Admin page while the user is still editing.
 *
 * @param {HTMLFormElement} form Theme form containing the appearance controls.
 * @returns {void}
 */
function setupThemeLivePreview(form) {
    // previewRoot stores the split Appearance editor that owns all preview state.
    const previewRoot = form.querySelector('[data-theme-preview-root]');
    // previewPage stores the miniature public page shown on the right side.
    const previewPage = form.querySelector('[data-theme-preview-page]');
    if (!previewRoot || !previewPage) {
        return;
    }

    // brandText stores the visible site title inside the preview header.
    const brandText = form.querySelector('[data-theme-preview-brand]');
    // siteNameControl stores the real site-name field, which is not a color override but should still update visually.
    const siteNameControl = form.querySelector('[data-theme-preview-site-name]');
    // radiusDisplay stores the small px readout beside the Rounded corners slider.
    const radiusDisplay = form.querySelector('[data-theme-radius-display]');
    // backgroundImage stores the inner preview element that simulates the public background image layer.
    const backgroundImage = form.querySelector('[data-theme-preview-background-image]');
    // backgroundUrl stores the already-saved theme background URL supplied by the PHP controller.
    const backgroundUrl = previewRoot.getAttribute('data-theme-preview-background-url') || '';
    const gpsPinSample = form.querySelector('[data-theme-gps-pin-sample]');
    const gpsPinEnabled = form.querySelector('[data-theme-gps-pin-enabled]');
    const gpsPinBackgroundEnabled = form.querySelector('[data-theme-gps-pin-background-enabled]');
    const gpsPinSize = form.querySelector('[data-theme-gps-pin-size]');
    const gpsPinBackgroundSize = form.querySelector('[data-theme-gps-pin-background-size]');
    const gpsPinSizeDisplay = form.querySelector('[data-theme-gps-pin-size-display]');
    const gpsPinBackgroundSizeDisplay = form.querySelector('[data-theme-gps-pin-background-size-display]');
    // pageWidthSelect stores the preset selector that decides whether the custom-width controls are visible.
    const pageWidthSelect = form.querySelector('[data-theme-page-width-select]');
    // customWidthShell stores the conditional slider/number UI for the Custom page-width preset.
    const customWidthShell = form.querySelector('[data-theme-custom-width-control]');
    // customWidthSlider stores the range control used for quick custom-width tuning.
    const customWidthSlider = form.querySelector('[data-theme-custom-width-slider]');
    // customWidthNumber stores the direct pixel input saved by the PHP controller.
    const customWidthNumber = form.querySelector('[data-theme-custom-width-number]');
    // customWidthDisplay stores the visible px readout beside the slider.
    const customWidthDisplay = form.querySelector('[data-theme-custom-width-display]');

    /**
     * Synchronizes the custom-width slider, number input, readout, and preview scale.
     *
     * @param {HTMLInputElement|null} sourceControl Control that initiated the update, if any.
     * @returns {number} Safe custom width in pixels.
     */
    const syncCustomWidthControls = (sourceControl = null) => {
        // sourceValue stores the value from the changed control, preferring the number input when called during initial setup.
        const sourceValue = sourceControl ? sourceControl.value : (customWidthNumber ? customWidthNumber.value : '1440');
        // customWidth stores the clamped pixel value shared by all custom-width UI elements.
        const customWidth = customPageWidthValue(sourceValue);
        if (customWidthSlider && sourceControl !== customWidthSlider) {
            customWidthSlider.value = String(customWidth);
        }
        if (customWidthNumber && sourceControl !== customWidthNumber) {
            customWidthNumber.value = String(customWidth);
        }
        if (customWidthDisplay) {
            customWidthDisplay.textContent = `${customWidth}px`;
        }
        previewPage.style.setProperty('--preview-custom-width-scale', String((customWidth - 1024) / 1024));
        return customWidth;
    };

    /**
     * Copies all unsaved visual settings into the preview CSS variables.
     *
     * @returns {void}
     */
    const syncPreview = () => {
        // colorMap stores form field names and their corresponding preview CSS variables.
        const colorMap = {
            accent: '--preview-accent',
            accent_dark: '--preview-accent-dark',
            paper: '--preview-paper',
            panel: '--preview-panel',
            gallery_panel: '--preview-gallery-panel',
            header_text: '--preview-header-text',
            hero_text: '--preview-hero-text',
        };

        Object.entries(colorMap).forEach(([colorName, cssVariable]) => {
            // control stores the color picker bound to the current visual property.
            const control = form.querySelector(`[data-theme-preview-color="${colorName}"]`);
            if (control && typeof control.value === 'string') {
                previewPage.style.setProperty(cssVariable, control.value);
            }
        });

        // radiusValue stores the sanitized border-radius value used by preview cards and controls.
        const radiusValue = Math.max(0, Math.min(32, parseInt(themeControlValue(form, '[data-theme-preview-radius]', '16'), 10) || 0));
        previewPage.style.setProperty('--preview-radius', `${radiusValue}px`);
        if (radiusDisplay) {
            radiusDisplay.textContent = `${radiusValue}px`;
        }

        // fontMode stores the selected serif/sans display mode.
        const fontMode = themeControlValue(form, '[data-theme-preview-font]', 'serif');
        previewPage.style.setProperty('--preview-font-family', themePreviewFontFamily(fontMode));

        // pageWidthMode stores the public container preset chosen in the Appearance form.
        // The compact preview cannot use real viewport pixels, so it represents the
        // choice by changing how much of the preview column the simulated page occupies.
        const pageWidthMode = themeControlValue(form, '[data-theme-preview-width]', 'default');
        const normalizedPageWidthMode = ['default', 'wide', 'custom', 'full'].includes(pageWidthMode) ? pageWidthMode : 'default';
        previewPage.setAttribute('data-preview-width', normalizedPageWidthMode);
        if (customWidthShell) {
            customWidthShell.hidden = normalizedPageWidthMode !== 'custom';
        }
        syncCustomWidthControls();

        // backgroundOpacity stores the same 0-100 percentage used by the public theme background layer.
        const backgroundOpacity = Math.max(0, Math.min(100, parseInt(themeControlValue(form, '[data-theme-background-opacity]', '65'), 10) || 0));
        previewPage.style.setProperty('--preview-background-opacity', String(backgroundOpacity / 100));

        if (backgroundImage) {
            backgroundImage.style.backgroundImage = backgroundUrl !== '' ? `url("${backgroundUrl}")` : 'none';
        }

        if (siteNameControl && brandText) {
            brandText.textContent = siteNameControl.value.trim() || 'Gallery CMS';
        }

        if (gpsPinSample) {
            const enabled = !gpsPinEnabled || gpsPinEnabled.checked;
            const backgroundEnabled = !gpsPinBackgroundEnabled || gpsPinBackgroundEnabled.checked;
            const pinSize = Math.max(14, Math.min(48, parseInt(gpsPinSize?.value || '26', 10) || 26));
            const backgroundSize = Math.max(0, Math.min(48, parseInt(gpsPinBackgroundSize?.value || '22', 10) || 22));
            gpsPinSample.style.display = enabled ? 'inline-flex' : 'none';
            gpsPinSample.style.setProperty('--gps-pin-size', String(pinSize));
            gpsPinSample.style.setProperty('--gps-pin-background-size', String(backgroundSize));
            gpsPinSample.style.background = backgroundEnabled ? 'rgba(15, 23, 42, 0.55)' : 'transparent';
            gpsPinSample.style.borderColor = backgroundEnabled ? 'rgba(255, 255, 255, 0.25)' : 'transparent';
            gpsPinSample.style.boxShadow = backgroundEnabled ? '0 1px 3px rgba(0, 0, 0, 0.16)' : 'none';
            gpsPinSample.style.backdropFilter = backgroundEnabled ? 'blur(4px)' : 'none';
            gpsPinSample.style.webkitBackdropFilter = backgroundEnabled ? 'blur(4px)' : 'none';
            if (gpsPinSizeDisplay) {
                gpsPinSizeDisplay.textContent = `${pinSize}px`;
            }
            if (gpsPinBackgroundSizeDisplay) {
                gpsPinBackgroundSizeDisplay.textContent = `${backgroundSize}px`;
            }
        }
    };

    form.querySelectorAll('[data-theme-preview-color], [data-theme-preview-radius], [data-theme-preview-font], [data-theme-preview-width], [data-theme-background-opacity], [data-theme-preview-site-name], [data-theme-gps-pin-enabled], [data-theme-gps-pin-background-enabled], [data-theme-gps-pin-size], [data-theme-gps-pin-background-size]').forEach((control) => {
        control.addEventListener('input', syncPreview);
        control.addEventListener('change', syncPreview);
    });
    [customWidthSlider, customWidthNumber].forEach((control) => {
        if (!control) {
            return;
        }
        control.addEventListener('input', () => {
            syncCustomWidthControls(control);
            syncPreview();
        });
        control.addEventListener('change', () => {
            syncCustomWidthControls(control);
            syncPreview();
        });
    });
    syncPreview();
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
    setupThemeLivePreview(form);
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
