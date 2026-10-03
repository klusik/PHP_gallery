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
 *   2026-08-11
 */

import { setupThemeAppearanceResize } from './theme-appearance-resizer.js?v=20261002-theme-appearance-v5';
import { setupAdminGalleryGridControls } from './admin-gallery-grid-controls.js?v=20261003-grid-default-v1';

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
 * Synchronize compact HEX editors with the existing authoritative named color inputs.
 * @param {HTMLFormElement} form Theme form containing optional compact color rows.
 * @return {void} Binds complete-value synchronization and blocks submission of invalid drafts.
 */
function setupThemeColorHexControls(form) {
    form.querySelectorAll('[data-theme-color-row]').forEach(/**
     * Bind one native swatch and its unnamed text editor without changing persistence names.
     * @param {Element} row Compact color control wrapper.
     * @return {void} Installs idempotent synchronization when both controls exist.
     */ (row) => {
        const color = row.querySelector('input[type="color"][data-theme-preview-color]');
        const hex = row.querySelector('[data-theme-color-hex]');
        if (!(color instanceof HTMLInputElement) || !(hex instanceof HTMLInputElement) || row.dataset.themeColorReady === '1') {
            return;
        }
        row.dataset.themeColorReady = '1';
        hex.required = true;
        hex.pattern = '#?[0-9A-Fa-f]{6}';
        let dispatchingHex = false;
        let hexEdited = false;

        /**
         * Mirror a native picker change and clear any obsolete invalid text draft.
         * @return {void} Updates text only when the event did not originate from this HEX editor.
         */
        const syncNativeColor = () => {
            if (dispatchingHex) {
                return;
            }
            hex.value = color.value.toUpperCase();
            hexEdited = false;
            hex.setCustomValidity('');
            hex.removeAttribute('aria-invalid');
        };

        /**
         * Commit a complete six-digit value while retaining incomplete drafts for correction.
         * @param {boolean} normalizeText Whether blur or submission should add the leading hash.
         * @param {'input'|'change'|null} eventType Canonical notification, or null for final validation.
         * @return {boolean} Whether the text is a complete supported HEX value.
         */
        const syncHexColor = (normalizeText, eventType) => {
            const raw = hex.value.trim();
            if (!/^#?[0-9a-f]{6}$/i.test(raw)) {
                hex.setCustomValidity(hex.dataset.themeColorInvalidMessage || 'Use #RRGGBB.');
                hex.setAttribute('aria-invalid', 'true');
                return false;
            }
            const value = '#' + raw.replace(/^#/, '').toLowerCase();
            const changed = color.value.toLowerCase() !== value;
            hex.setCustomValidity('');
            hex.removeAttribute('aria-invalid');
            if (normalizeText) {
                hex.value = value.toUpperCase();
            }
            color.value = value;
            if (changed || eventType) {
                dispatchingHex = true;
                color.dispatchEvent(new Event(eventType || 'change', {bubbles: true}));
                dispatchingHex = false;
            }
            return true;
        };

        color.addEventListener('input', syncNativeColor);
        color.addEventListener('change', syncNativeColor);
        hex.addEventListener('input', /**
         * Preview only complete text values; incomplete typing never changes the swatch.
         * @return {void} Validates the draft and emits the canonical input event when complete.
        */ () => {
            hexEdited = true;
            syncHexColor(false, 'input');
        });
        hex.addEventListener('blur', /**
         * Normalize a completed HEX edit when the user leaves its text field.
         * @return {void} Emits the canonical change event or retains an invalid draft.
        */ () => {
            if (syncHexColor(true, hexEdited ? 'change' : null)) {
                hexEdited = false;
            }
        });
        hex.addEventListener('invalid', /**
         * Reveal an invalid HEX draft through the existing Theme tab controls before focus.
         * @param {Event} event Native constraint-validation failure for this text editor.
         * @return {void} Defers hidden-field focus through the existing tab transition; visible validation stays native.
         */ (event) => {
            if (!hex.closest('[hidden]') && hex.getClientRects().length > 0) {
                return;
            }
            event.preventDefault();
            const scope = form.closest('[data-admin-side-panel-body]') || form.closest('main') || document;
            scope.querySelector('[role="tab"][data-admin-tab-target="admin-theme-tab-appearance"]')?.click();
            form.querySelector('[role="tab"][data-admin-subtab-target="admin-theme-appearance-subtab-colors"]')?.click();
            requestAnimationFrame(/**
             * Focus and report the draft after the shared tab handlers finish revealing its panel.
             * @return {void} Reports once for a connected visible field without scheduling another invalid loop.
             */ () => {
                if (hex.isConnected && !hex.closest('[hidden]') && hex.getClientRects().length > 0) {
                    hex.focus();
                    hex.reportValidity();
                }
            });
        });
        form.addEventListener('submit', /**
         * Refuse invalid ordinary saves while preserving intentional validation-bypassing actions.
         * @param {SubmitEvent} event Submission of the owning Theme form.
         * @return {void} Prevents invalid drafts from silently submitting the previous swatch value.
         */ (event) => {
            if (form.noValidate || event.submitter?.formNoValidate) {
                return;
            }
            if (!syncHexColor(true, null)) {
                event.preventDefault();
                hex.reportValidity();
            }
        });
        syncNativeColor();
        hex.hidden = false;
    });
}

/**
 * Keep public tag-page range readouts and translated capacity synchronized.
 * @param {HTMLFormElement} form Theme form containing optional tag-page grid controls.
 * @return {void} Binds the existing named ranges without introducing additional saved fields.
 */
function setupThemeTagGridControls(form) {
    const columns = form.querySelector('[name="tag_page_gallery_grid_columns"]');
    const rows = form.querySelector('[name="tag_page_gallery_grid_rows"]');
    if (!(columns instanceof HTMLInputElement) || !(rows instanceof HTMLInputElement) || columns.dataset.themeTagGridReady === '1') {
        return;
    }
    columns.dataset.themeTagGridReady = '1';
    const columnsDisplay = form.querySelector('[data-theme-tag-grid-columns-display]');
    const rowsDisplay = form.querySelector('[data-theme-tag-grid-rows-display]');
    const capacity = form.querySelector('[data-theme-tag-grid-capacity]');
    /**
     * Copy range values into their paired readouts and capacity template.
     * @return {void} Reflects current bounded native range values without changing them.
     */
    const sync = () => {
        const columnCount = Math.max(1, Number.parseInt(columns.value, 10) || 1);
        const rowCount = Math.max(1, Number.parseInt(rows.value, 10) || 1);
        if (columnsDisplay) columnsDisplay.textContent = String(columnCount);
        if (rowsDisplay) rowsDisplay.textContent = String(rowCount);
        if (capacity) {
            const template = capacity.getAttribute('data-theme-tag-grid-capacity-template') || '{count}';
            capacity.textContent = template.split('{count}').join(String(columnCount * rowCount));
        }
    };
    columns.addEventListener('input', sync);
    columns.addEventListener('change', sync);
    rows.addEventListener('input', sync);
    rows.addEventListener('change', sync);
    sync();
}


/**
 * Keeps the Theme optimized-background size readout synchronized while dragging.
 *
 * The server-rendered text includes translated wording around the pixel value, so
 * this helper uses the same template from a data attribute instead of hardcoding
 * English in JavaScript.
 *
 * @param {HTMLFormElement} form Theme form containing the background controls.
 */
function setupThemeBackgroundOptimizedSizeDisplay(form) {
    // control stores the slider deciding the longest side of the generated WebP copy.
    const control = form.querySelector('[data-theme-background-optimized-size]');
    // display stores the visible text next to the slider.
    const display = form.querySelector('[data-theme-background-optimized-size-display]');
    if (!control || !display) {
        return;
    }

        /**
     * Copies the current slider value into the localized readout template.
     */
    const syncValue = () => {
        // size stores the clamped pixel value accepted by the PHP controller.
        const size = Math.max(1024, Math.min(3840, parseInt(control.value, 10) || 1920));
        // template stores translated text such as "{size}px longest side".
        const template = display.getAttribute('data-theme-background-optimized-size-template') || '{size}px longest side';
        display.textContent = template.split('{size}').join(String(size));
    };

    control.addEventListener('input', syncValue);
    control.addEventListener('change', syncValue);
    syncValue();
}


/**
 * Keeps the visual gallery-description layout picker synchronized with the saved select field.
 *
 * @param {HTMLFormElement} form Theme form containing the layout controls.
 */
function setupThemeDescriptionLayoutPicker(form) {
    // select stores the real persisted field submitted to the PHP controller.
    const select = form.querySelector('[data-theme-description-layout-select]');
    // options stores the visual cards that make the layout choice easier to understand.
    const options = Array.from(form.querySelectorAll('[data-theme-description-layout-option]'));
    if (!select || options.length === 0) {
        return;
    }

        /**
     * Updates pressed state for every visual layout card.
     */
    const syncPressedState = () => {
        options.forEach((option) => {
            option.setAttribute('aria-pressed', option.getAttribute('data-theme-description-layout-option') === select.value ? 'true' : 'false');
        });
    };

    options.forEach((option) => {
        option.addEventListener('click', () => {
            const nextValue = option.getAttribute('data-theme-description-layout-option') || 'vertical';
            if (select.value !== nextValue) {
                select.value = nextValue;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncPressedState();
        });
    });

    select.addEventListener('change', syncPressedState);
    syncPressedState();
}


/**
 * Keeps the dual thumbnail-bound sliders ordered and copies their selected sizes to hidden inputs.
 *
 * @return {void} Result value for the caller.
 */
function setupThumbnailBoundControls() {
    document.querySelectorAll('[data-thumbnail-bound-control]').forEach((root) => {
        // values stores the supported thumbnail sizes. Zero is the Auto sentinel.
        const values = String(root.getAttribute('data-thumbnail-bound-values') || '0')
            .split(',')
            .map((value) => parseInt(value, 10))
            .filter((value) => Number.isFinite(value));
        const minIndexControl = root.querySelector('[data-thumbnail-bound-min-index]');
        const maxIndexControl = root.querySelector('[data-thumbnail-bound-max-index]');
        const minValueControl = root.querySelector('[data-thumbnail-bound-min-value]');
        const maxValueControl = root.querySelector('[data-thumbnail-bound-max-value]');
        const summary = root.querySelector('[data-thumbnail-bound-summary]');
        const minDisplay = root.querySelector('[data-thumbnail-bound-min-display]');
        const maxDisplay = root.querySelector('[data-thumbnail-bound-max-display]');
        if (values.length < 2 || !minIndexControl || !maxIndexControl || !minValueControl || !maxValueControl || !summary) {
            return;
        }

                /**
         * Formats one selected size for the visible summary.
         *
         * @param {number} value Selected thumbnail size, or zero for Auto.
         * @param {'min'|'max'} side Which edge is being displayed.
         * @return {string} Human-readable size label.
         */
        const formatSize = (value, side) => {
            if (value === 0) {
                return side === 'min' ? i18n('thumbnail_bounds.auto_min', 'Auto min') : i18n('thumbnail_bounds.auto_max', 'Auto max');
            }
            return `${value}px`;
        };

                /**
         * Synchronizes slider order, hidden form values, and the text summary.
         *
         * @param {HTMLInputElement|null} changedControl Control that initiated the update, if any.
         */
        const sync = (changedControl = null) => {
            let minIndex = parseInt(minIndexControl.value, 10) || 0;
            let maxIndex = parseInt(maxIndexControl.value, 10) || 0;
            const highestIndex = values.length - 1;
            minIndex = Math.max(0, Math.min(highestIndex, minIndex));
            maxIndex = Math.max(0, Math.min(highestIndex, maxIndex));
            if (minIndex > maxIndex) {
                if (changedControl === minIndexControl) {
                    maxIndex = minIndex;
                } else {
                    minIndex = maxIndex;
                }
            }
            minIndexControl.value = String(minIndex);
            maxIndexControl.value = String(maxIndex);
            const minValue = values[minIndex] || 0;
            const maxValue = values[maxIndex] || 0;
            minValueControl.value = String(minValue);
            maxValueControl.value = String(maxValue);
            const minPercent = highestIndex > 0 ? (minIndex / highestIndex) * 100 : 0;
            const maxPercent = highestIndex > 0 ? (maxIndex / highestIndex) * 100 : 100;
            root.style.setProperty('--thumbnail-bound-min-index', String(minIndex));
            root.style.setProperty('--thumbnail-bound-max-index', String(maxIndex));
            root.style.setProperty('--thumbnail-bound-step-count', String(highestIndex + 1));
            root.style.setProperty('--thumbnail-bound-min-percent', `${minPercent}%`);
            root.style.setProperty('--thumbnail-bound-max-percent', `${maxPercent}%`);
            root.style.setProperty('--thumbnail-bound-active-start', `${minPercent}%`);
            root.style.setProperty('--thumbnail-bound-active-end', `${maxPercent}%`);
            root.style.setProperty('--thumbnail-bound-active-start-number', String(minPercent));
            root.style.setProperty('--thumbnail-bound-active-end-number', String(maxPercent));
            const minLabel = formatSize(minValue, 'min');
            const maxLabel = formatSize(maxValue, 'max');
            if (minDisplay) {
                minDisplay.textContent = minLabel;
            }
            if (maxDisplay) {
                maxDisplay.textContent = maxLabel;
            }
            summary.textContent = `${minLabel} to ${maxLabel}`;
        };

        minIndexControl.addEventListener('input', () => sync(minIndexControl));
        minIndexControl.addEventListener('change', () => sync(minIndexControl));
        maxIndexControl.addEventListener('input', () => sync(maxIndexControl));
        maxIndexControl.addEventListener('change', () => sync(maxIndexControl));
        sync();
    });
}

/**
 * Reads the first matching form control value from the Theme form.
 *
 * @param {HTMLFormElement} form Theme form containing the appearance controls.
 * @param {string} selector CSS selector for the desired control.
 * @param {string} fallback Value used when the control is missing.
 * @return {string} Current control value or fallback.
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
 * Reads one checkbox or hidden boolean preview control without assuming one DOM input type.
 *
 * Wizard fallback values are hidden inputs while the canonical Theme editor uses checkboxes.
 * This helper lets both surfaces drive exactly the same preview code.
 *
 * @param {HTMLFormElement} form Theme or Setup Wizard form.
 * @param {string} selector CSS selector for the boolean control.
 * @param {boolean} fallback Value used when the control is missing.
 * @return {boolean} Normalized boolean preview state.
 */
function themeBooleanControlValue(form, selector, fallback) {
    const control = form.querySelector(selector);
    if (!control) {
        return fallback;
    }
    if (control.type === 'checkbox' && typeof control.checked === 'boolean') {
        return control.checked;
    }
    return typeof control.value === 'string' ? control.value === '1' : fallback;
}


/**
 * Returns the visible label for one select-backed preview value when possible.
 *
 * @param {HTMLFormElement} form Theme or Setup Wizard form.
 * @param {string} selector CSS selector for the source control.
 * @param {string} fallback Raw fallback label.
 * @return {string} Human-readable selected label.
 */
function themePreviewControlLabel(form, selector, fallback) {
    const control = form.querySelector(selector);
    if (control && control.tagName === 'SELECT' && control.options) {
        return control.options[control.selectedIndex]?.text || fallback;
    }
    const value = control && typeof control.value === 'string' ? control.value : fallback;
    return String(value || fallback).replaceAll('_', ' ');
}


/**
 * Converts the two stored font modes into the real preview CSS font stack.
 *
 * @param {string} fontMode Theme font mode from the Admin select control.
 * @return {string} CSS font-family value for the live preview.
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
 * @return {number} Safe pixel width between 1024 and 2048.
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
 * @return {void} Result value for the caller.
 */
export function setupThemeLivePreview(form) {
    // previewRoot stores the split Appearance editor that owns all preview state.
    const previewRoot = form.querySelector('[data-theme-preview-root]');
    // previewPage stores the miniature public page shown on the right side.
    const previewPage = form.querySelector('[data-theme-preview-page]');
    if (!previewRoot || !previewPage) {
        return;
    }
    if (previewRoot.dataset.themePreviewReady === '1') {
        return;
    }
    previewRoot.dataset.themePreviewReady = '1';

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
    const gpsPinSamples = Array.from(form.querySelectorAll('[data-theme-gps-pin-sample]'));
    const gpsPinSizeDisplay = form.querySelector('[data-theme-gps-pin-size-display]');
    const gpsPinBackgroundSizeDisplay = form.querySelector('[data-theme-gps-pin-background-size-display]');
    const descriptionCards = Array.from(form.querySelectorAll('[data-theme-preview-description-card]'));
    const countBadgeSamples = Array.from(form.querySelectorAll('[data-theme-preview-count-badge-sample]'));
    const previewGrid = form.querySelector('[data-theme-preview-grid]');
    const paginationPreview = form.querySelector('[data-theme-preview-pagination]');
    const homeGridState = form.querySelector('[data-theme-preview-home-grid-state]');
    const tagGridState = form.querySelector('[data-theme-preview-tag-grid-state]');
    const lightboxState = form.querySelector('[data-theme-preview-lightbox-state]');
    const thumbnailState = form.querySelector('[data-theme-preview-thumbnail-state]');
    const wizardStep = form.querySelector('[name="wizard_step"]')?.value || '';
    const globalLayoutSelector = '[data-theme-preview-description-layout], [name="theme_gallery_description_layout"]';
    const tagLayoutSelector = '[data-theme-preview-tag-description-layout], [name="tag_page_gallery_description_layout"]';
    const previewContextLabel = form.querySelector('[data-theme-preview-context-label]');
    const previewContextSelect = form.querySelector('[data-theme-preview-context-select]');
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
     * @return {number} Safe custom width in pixels.
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
     * @param {Event|null} event Optional source control, used only for Appearance's local preview context.
     * @return {void} Reflects the active Appearance or wizard context using existing form values.
     */
    const syncPreview = (event = null) => {
        if (!wizardStep && previewRoot.classList.contains('theme-appearance-workspace')) {
            const control = event?.target instanceof Element ? event.target : null;
            if (control?.matches(globalLayoutSelector)) {
                previewRoot.dataset.themePreviewContext = 'home';
            } else if (control?.matches(tagLayoutSelector + ', [data-theme-preview-tag-grid-columns], [data-theme-preview-tag-grid-rows]')) {
                previewRoot.dataset.themePreviewContext = 'tag';
            } else if (previewContextSelect && control === previewContextSelect) {
                previewRoot.dataset.themePreviewContext = previewContextSelect.value === 'tag' ? 'tag' : 'home';
            }
            if (previewContextSelect) {
                previewContextSelect.value = previewRoot.dataset.themePreviewContext === 'tag' ? 'tag' : 'home';
            }
        }
        const tagContext = wizardStep === 'content' || (!wizardStep && previewRoot.classList.contains('theme-appearance-workspace') && previewRoot.dataset.themePreviewContext === 'tag');
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

        const gpsEnabled = themeBooleanControlValue(form, '[data-theme-gps-pin-enabled]', true);
        const gpsBackgroundEnabled = themeBooleanControlValue(form, '[data-theme-gps-pin-background-enabled]', true);
        const pinSize = Math.max(14, Math.min(48, parseInt(themeControlValue(form, '[data-theme-gps-pin-size]', '26'), 10) || 26));
        const backgroundSize = Math.max(0, Math.min(48, parseInt(themeControlValue(form, '[data-theme-gps-pin-background-size]', '22'), 10) || 22));
        gpsPinSamples.forEach((gpsPinSample) => {
            gpsPinSample.style.display = gpsEnabled ? 'inline-flex' : 'none';
            gpsPinSample.style.setProperty('--gps-pin-size', String(pinSize));
            gpsPinSample.style.setProperty('--gps-pin-background-size', String(backgroundSize));
            gpsPinSample.style.background = gpsBackgroundEnabled ? 'rgba(15, 23, 42, 0.55)' : 'transparent';
            gpsPinSample.style.borderColor = gpsBackgroundEnabled ? 'rgba(255, 255, 255, 0.25)' : 'transparent';
            gpsPinSample.style.boxShadow = gpsBackgroundEnabled ? '0 1px 3px rgba(0, 0, 0, 0.16)' : 'none';
            gpsPinSample.style.backdropFilter = gpsBackgroundEnabled ? 'blur(4px)' : 'none';
            gpsPinSample.style.webkitBackdropFilter = gpsBackgroundEnabled ? 'blur(4px)' : 'none';
        });
        if (gpsPinSizeDisplay) {
            gpsPinSizeDisplay.textContent = `${pinSize}px`;
        }
        if (gpsPinBackgroundSizeDisplay) {
            gpsPinBackgroundSizeDisplay.textContent = `${backgroundSize}px`;
        }

        const baseDescriptionLayout = themeControlValue(form, globalLayoutSelector, 'vertical');
        const tagDescriptionLayout = themeControlValue(form, tagLayoutSelector, baseDescriptionLayout);
        const activeDescriptionLayout = tagContext ? tagDescriptionLayout : baseDescriptionLayout;
        descriptionCards.forEach((card) => card.setAttribute('data-description-layout', activeDescriptionLayout === 'horizontal' ? 'horizontal' : 'vertical'));
        if (previewContextLabel) {
            const contextLabel = tagContext
                ? (previewContextLabel.dataset.themePreviewContextTagLabel || 'Tag pages')
                : (previewContextLabel.dataset.themePreviewContextHomeLabel || 'Gallery cards');
            const layoutLabel = themePreviewControlLabel(form, tagContext ? tagLayoutSelector : globalLayoutSelector, activeDescriptionLayout);
            previewContextLabel.textContent = `${contextLabel} · ${layoutLabel}`;
        }

        const countBadgeEnabled = themeBooleanControlValue(form, '[data-theme-preview-count-badge]', true);
        countBadgeSamples.forEach((badge) => { badge.hidden = !countBadgeEnabled; });

        const paginationEnabled = themeBooleanControlValue(form, '[data-theme-preview-pagination-enabled]', false);
        if (paginationPreview) {
            paginationPreview.hidden = !paginationEnabled;
        }

        const globalColumns = Math.max(1, Math.min(12, parseInt(themeControlValue(form, '[data-theme-preview-grid-columns]', '3'), 10) || 3));
        const globalRows = Math.max(1, Math.min(50, parseInt(themeControlValue(form, '[data-theme-preview-grid-rows]', '3'), 10) || 3));
        const homeColumns = Math.max(1, Math.min(12, parseInt(themeControlValue(form, '[data-theme-preview-home-grid-columns]', String(globalColumns)), 10) || globalColumns));
        const homeRows = Math.max(1, Math.min(50, parseInt(themeControlValue(form, '[data-theme-preview-home-grid-rows]', String(globalRows)), 10) || globalRows));
        const tagColumns = Math.max(1, Math.min(12, parseInt(themeControlValue(form, '[data-theme-preview-tag-grid-columns]', String(globalColumns)), 10) || globalColumns));
        const tagRows = Math.max(1, Math.min(50, parseInt(themeControlValue(form, '[data-theme-preview-tag-grid-rows]', String(globalRows)), 10) || globalRows));
        const visibleColumns = tagContext ? tagColumns : homeColumns;
        if (previewGrid) {
            previewGrid.style.setProperty('--preview-grid-columns', String(Math.min(previewRoot.classList.contains('theme-appearance-workspace') ? 2 : 4, visibleColumns)));
        }
        if (homeGridState) {
            homeGridState.textContent = `${homeColumns} × ${homeRows}`;
        }
        if (tagGridState) {
            tagGridState.textContent = `${tagColumns} × ${tagRows}`;
        }
        if (lightboxState) {
            lightboxState.textContent = themePreviewControlLabel(form, '[data-theme-preview-lightbox-mode]', 'single');
        }
        if (thumbnailState) {
            thumbnailState.textContent = themePreviewControlLabel(form, '[data-theme-preview-thumbnail-mode]', 'progressive');
        }
    };

    form.querySelectorAll('[data-theme-preview-color], [data-theme-preview-radius], [data-theme-preview-font], [data-theme-preview-width], [data-theme-background-opacity], [data-theme-preview-site-name], [data-theme-gps-pin-enabled], [data-theme-gps-pin-background-enabled], [data-theme-gps-pin-size], [data-theme-gps-pin-background-size], [data-theme-preview-description-layout], [name="theme_gallery_description_layout"], [data-theme-preview-count-badge], [data-theme-preview-pagination-enabled], [data-theme-preview-grid-columns], [data-theme-preview-grid-rows], [data-theme-preview-home-grid-columns], [data-theme-preview-home-grid-rows], [data-theme-preview-tag-grid-columns], [data-theme-preview-tag-grid-rows], [data-theme-preview-tag-description-layout], [name="tag_page_gallery_description_layout"], [data-theme-preview-context-select], [data-theme-preview-lightbox-mode], [data-theme-preview-thumbnail-mode]').forEach(/**
     * Bind each canonical or compatibility-hook control to the shared unsaved preview.
     * @param {Element} control Theme or wizard form control that affects preview presentation.
     * @return {void} Registers the existing input/change preview pipeline once per matching element.
     */ (control) => {
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
    form.querySelectorAll('[data-theme-preview-grid-columns], [data-theme-preview-grid-rows], [data-theme-preview-home-grid-columns], [data-theme-preview-home-grid-rows], [data-theme-preview-tag-grid-columns], [data-theme-preview-tag-grid-rows]').forEach((control) => {
        const output = control.parentElement?.querySelector('output');
        if (!output) {
            return;
        }
        /** Keep the range readout synchronized with its source control. @returns {void} */
        const syncOutput = () => { output.textContent = control.value; };
        control.addEventListener('input', syncOutput);
        control.addEventListener('change', syncOutput);
        syncOutput();
    });
    if (previewRoot.classList.contains('theme-appearance-workspace') && !wizardStep) {
        // Respect the prepared deep-link context once; subsequent tab navigation never changes preview scope.
        if (!['home', 'tag'].includes(previewRoot.dataset.themePreviewContext)) {
            const selected = previewRoot.querySelector('[data-admin-subtabs] [data-admin-subtab-target][aria-selected="true"]');
            previewRoot.dataset.themePreviewContext = selected?.dataset.adminSubtabTarget === 'admin-theme-appearance-subtab-gallery-tags' ? 'tag' : 'home';
        }
    }
    syncPreview();
}


/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @return {string} Browser-facing translated text.
 */
function i18n(key, fallback, parameters = {}) {
    const root = window.PHP_GALLERY_I18N && typeof window.PHP_GALLERY_I18N === 'object' ? window.PHP_GALLERY_I18N : {};
    const strings = root.strings && typeof root.strings === 'object' ? root.strings : {};
    let text = typeof strings[key] === 'string' ? strings[key] : fallback;
    Object.entries(parameters).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}

/**
 * Keep a range slider and direct number field synchronized within validated bounds.
 *
 * @param {HTMLFormElement} form Theme form containing the controls.
 * @param {string} sliderSelector Selector for the range input.
 * @param {string} numberSelector Selector for the numeric input.
 * @param {string} displaySelector Selector for the optional visible value display.
 * @param {number} minimum Lowest accepted integer.
 * @param {number} maximum Highest accepted integer.
 * @param {number} fallback Value used when direct input is empty or invalid.
 */
function setupThemeNumberSliderPair(form, sliderSelector, numberSelector, displaySelector, minimum, maximum, fallback) {
    const slider = form.querySelector(sliderSelector);
    const number = form.querySelector(numberSelector);
    const display = form.querySelector(displaySelector);
    if (!(slider instanceof HTMLInputElement) || !(number instanceof HTMLInputElement)) {
        return;
    }

    /**
     * Clamp one input and mirror the resolved integer to both controls.
     *
     * @param {HTMLInputElement} source Input that triggered synchronization.
     */
    const sync = (source) => {
        const parsed = Number.parseInt(source.value, 10);
        const value = Math.max(minimum, Math.min(maximum, Number.isFinite(parsed) ? parsed : fallback));
        slider.value = String(value);
        number.value = String(value);
        if (display instanceof HTMLElement) {
            display.textContent = String(value);
        }
    };

    [slider, number].forEach((control) => {
        control.addEventListener('input', () => {
            // Let the direct number field be temporarily empty while a user replaces its value.
            if (control === number && number.value.trim() === '') {
                if (display instanceof HTMLElement) {
                    display.textContent = '';
                }
                return;
            }
            sync(control);
        });
        control.addEventListener('change', () => sync(control));
    });
    sync(number);
}

/**
 * Initialize Theme controls specific to the public gallery hero tag collection.
 *
 * The dependency sections are presentation-only. The server still validates
 * every submitted value so direct POST requests cannot escape supported bounds.
 *
 * @param {HTMLFormElement} form Theme form containing hero-tag settings.
 */
function setupThemeHeroTagControls(form) {
    setupThemeNumberSliderPair(
        form,
        '[data-theme-hero-tag-limit-slider]',
        '[data-theme-hero-tag-limit-number]',
        '[data-theme-hero-tag-limit-display]',
        1,
        200,
        20,
    );
    setupThemeNumberSliderPair(
        form,
        '[data-theme-hero-tag-scrollbar-rows-slider]',
        '[data-theme-hero-tag-scrollbar-rows-number]',
        '[data-theme-hero-tag-scrollbar-rows-display]',
        1,
        12,
        5,
    );

    const displayAll = form.querySelector('[data-theme-hero-tag-display-all]');
    const limitControls = form.querySelector('[data-theme-hero-tag-limit-controls]');
    const scrollbarEnabled = form.querySelector('[data-theme-hero-tag-scrollbar-enabled]');
    const scrollbarControls = form.querySelector('[data-theme-hero-tag-scrollbar-controls]');

    /**
     * Hide controls that have no effect under the currently selected mode.
     */
    const syncDependencies = () => {
        if (displayAll instanceof HTMLInputElement && limitControls instanceof HTMLElement) {
            limitControls.hidden = displayAll.checked;
        }
        if (scrollbarEnabled instanceof HTMLInputElement && scrollbarControls instanceof HTMLElement) {
            scrollbarControls.hidden = !scrollbarEnabled.checked;
        }
    };

    [displayAll, scrollbarEnabled].forEach((control) => {
        if (!(control instanceof HTMLInputElement)) {
            return;
        }
        control.addEventListener('input', syncDependencies);
        control.addEventListener('change', syncDependencies);
    });
    syncDependencies();
}

/**
 * Show each shortcut's gallery picker only for its Gallery target.
 *
 * Keep every canonical ID field enabled and in place so parallel shortcut arrays
 * retain their slot order. Without JavaScript the original visible picker remains.
 *
 * @param {HTMLFormElement} form Existing shared Theme form.
 * @return {void} Binds presentation-only target visibility without changing stored selections.
 */
function setupThemeFavoriteGalleryTargets(form) {
    form.querySelectorAll('.admin-theme-favorite-gallery-slot').forEach(/**
     * Bind one shortcut without taking ownership of the searchable picker.
     * @param {Element} slot Rendered shortcut row containing one target and one ID field.
     * @return {void} Preserves all field values and installs one visibility listener.
     */ (slot) => {
        const target = slot.querySelector('select[name="theme_favorite_gallery_types[]"]');
        const picker = slot.querySelector('[data-gallery-search-picker]')
            || slot.querySelector('select[name="theme_favorite_gallery_ids[]"]');
        if (!(target instanceof HTMLSelectElement) || !(picker instanceof HTMLElement)
            || target.dataset.themeFavoriteTargetBound === '1') {
            return;
        }
        target.dataset.themeFavoriteTargetBound = '1';
        const pickerShell = picker.closest('.theme-layout-picker') || picker;
        const hint = slot.querySelector(':scope > small');
        /**
         * Update only visibility; ID fields must still submit their original slot.
         * @return {void} Reveals the picker for Gallery and preserves inactive selections.
         */
        const syncTarget = () => {
            pickerShell.hidden = target.value !== 'gallery';
            if (hint instanceof HTMLElement) {
                hint.hidden = pickerShell.hidden;
            }
        };
        target.addEventListener('change', syncTarget);
        syncTarget();
    });
}

/**
 * Keep the main-page capacity readout aligned with its existing grid sliders.
 * @param {HTMLFormElement} form Existing shared Theme form.
 * @return {void} Displays the current column/row product without changing grid values.
 */
function setupThemeHomeGridCapacity(form) {
    const columns = form.querySelector('[data-home-grid-columns]');
    const rows = form.querySelector('[data-home-grid-rows]');
    const display = form.querySelector('[data-home-grid-items-preview]');
    if (!(columns instanceof HTMLInputElement) || !(rows instanceof HTMLInputElement)
        || !(display instanceof HTMLElement) || display.dataset.themeCapacityBound === '1') {
        return;
    }
    display.dataset.themeCapacityBound = '1';
    /**
     * Present the current capacity from the native range values.
     * @return {void} Updates only the derived readout.
     */
    const syncCapacity = () => {
        display.textContent = String(Math.max(1, Number(columns.value)) * Math.max(1, Number(rows.value)));
    };
    columns.addEventListener('input', syncCapacity);
    columns.addEventListener('change', syncCapacity);
    rows.addEventListener('input', syncCapacity);
    rows.addEventListener('change', syncCapacity);
    syncCapacity();
}

/**
 * Handle setup theme override form.
 *
 * Used by browser-side gallery behavior.
 * @return {void} Binds existing Theme controls and optional compact color/tag-grid editors.
 */
export function setupThemeOverrideForm() {
    syncGridRangeDisplay('[data-home-grid-columns]', '[data-home-grid-columns-display]');
    syncGridRangeDisplay('[data-home-grid-rows]', '[data-home-grid-rows-display]');
    syncGridRangeDisplay('[data-gallery-grid-columns]', '[data-gallery-grid-columns-display]');
    syncGridRangeDisplay('[data-gallery-grid-rows]', '[data-gallery-grid-rows-display]');
    setupAdminGalleryGridControls(document);
    setupThumbnailBoundControls();

    // form stores state or configuration for the gallery front-end flow.
    const form = document.querySelector('[data-theme-form]');
    if (!form) {
        return;
    }
    setupThemeFavoriteGalleryTargets(form);
    setupThemeHomeGridCapacity(form);
    setupThemeLivePreview(form);
    setupThemeAppearanceResize(form);
    setupThemeColorHexControls(form);
    setupThemeTagGridControls(form);
    setupThemeBackgroundOptimizedSizeDisplay(form);
    setupThemeDescriptionLayoutPicker(form);
    setupThemeHeroTagControls(form);
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
