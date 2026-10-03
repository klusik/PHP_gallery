/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-grid-controls.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Keep inherited gallery-grid controls synchronized across full-page and panel editors.
 *
 * Responsibilities:
 *   - Mark slider edits as custom gallery-grid drafts.
 *   - Restore inherited dimensions without submitting the shared form.
 *   - Initialize native controls after side-panel fragments are rendered.
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

let delegatedHandlersReady = false;

/** Return the gallery editor mode widget containing a target. @param {EventTarget|null} target Event target. @returns {HTMLElement|null} Local grid mode widget. */
function modeForTarget(target) {
    if (!(target instanceof Element)) return null;
    const directMode = target.closest('[data-gallery-grid-mode]');
    if (directMode) return directMode;
    return target.closest('.admin-display-grid-primary')?.querySelector('[data-gallery-grid-mode]') || null;
}

/** Update both slider readouts and the visible persisted-mode status. @param {HTMLElement} mode Prepared mode widget. @returns {void} Synchronizes presentation and native form state. */
function syncMode(mode) {
    const card = mode.closest('.admin-display-grid-primary');
    if (!(card instanceof HTMLElement)) return;
    const columns = card.querySelector('[data-gallery-grid-columns]');
    const rows = card.querySelector('[data-gallery-grid-rows]');
    const columnsDisplay = card.querySelector('[data-gallery-grid-columns-display]');
    const rowsDisplay = card.querySelector('[data-gallery-grid-rows-display]');
    const override = mode.querySelector('[data-gallery-grid-override-enabled]');
    const status = mode.querySelector('[data-gallery-grid-status]');
    const reset = mode.querySelector('[data-gallery-grid-reset]');
    if (columns instanceof HTMLInputElement && columnsDisplay instanceof HTMLElement) columnsDisplay.textContent = columns.value;
    if (rows instanceof HTMLInputElement && rowsDisplay instanceof HTMLElement) rowsDisplay.textContent = rows.value;
    if (status instanceof HTMLElement && override instanceof HTMLInputElement) {
        status.textContent = override.checked ? String(mode.dataset.customLabel || '') : String(mode.dataset.defaultLabel || '');
    }
    if (reset instanceof HTMLButtonElement && override instanceof HTMLInputElement) reset.disabled = !override.checked;
}

/** Enable the enhanced status/reset controls in a rendered direct-page or panel fragment. @param {ParentNode} root Rendered page or newly injected fragment. @returns {void} Makes each matching gallery grid editor interactive once. */
export function setupAdminGalleryGridControls(root = document) {
    const modes = [];
    if (root instanceof Element && root.matches('[data-gallery-grid-mode]')) modes.push(root);
    root.querySelectorAll('[data-gallery-grid-mode]').forEach(/** Collect each matching mode widget under the requested root. @param {Element} mode Candidate mode widget. @returns {void} Adds the widget for local initialization. */ (mode) => modes.push(mode));
    modes.forEach(/** Initialize one locally scoped gallery grid editor. @param {Element} candidate Candidate mode widget. @returns {void} Sets up one card and its status UI. */ (candidate) => {
        if (!(candidate instanceof HTMLElement) || candidate.dataset.gridControlsReady === '1') return;
        candidate.dataset.gridControlsReady = '1';
        const card = candidate.closest('.admin-display-grid-primary');
        if (!(card instanceof HTMLElement)) return;
        card.classList.add('is-grid-enhanced');
        const status = candidate.querySelector('[data-gallery-grid-status]');
        const reset = candidate.querySelector('[data-gallery-grid-reset]');
        if (status instanceof HTMLElement) status.hidden = false;
        if (reset instanceof HTMLButtonElement) reset.hidden = false;
        syncMode(candidate);
    });

    if (delegatedHandlersReady) return;
    delegatedHandlersReady = true;
    document.addEventListener('input', updateGalleryGridFromSlider);
    document.addEventListener('change', updateGalleryGridFromSlider);
    document.addEventListener('click', resetGalleryGrid);
}

/** Mark one slider edit custom and synchronize its mode card. @param {Event} event Native range event. @returns {void} Updates the local override state and readouts. */
function updateGalleryGridFromSlider(event) {
    const target = event.target;
    if (!(target instanceof Element) || !target.matches('[data-gallery-grid-columns], [data-gallery-grid-rows]')) return;
    const mode = modeForTarget(target);
    if (!mode) return;
    const override = mode.querySelector('[data-gallery-grid-override-enabled]');
    if (override instanceof HTMLInputElement) override.checked = true;
    syncMode(mode);
}

/** Restore one card's inherited dimensions without dispatching customizing slider events. @param {Event} event Native click event. @returns {void} Clears the local draft override and updates its readouts. */
function resetGalleryGrid(event) {
    const target = event.target;
    const button = target instanceof Element ? target.closest('[data-gallery-grid-reset]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const mode = modeForTarget(button);
    if (!mode) return;
    const card = mode.closest('.admin-display-grid-primary');
    const columns = card?.querySelector('[data-gallery-grid-columns]');
    const rows = card?.querySelector('[data-gallery-grid-rows]');
    const override = mode.querySelector('[data-gallery-grid-override-enabled]');
    if (columns instanceof HTMLInputElement) columns.value = String(mode.dataset.defaultColumns || columns.value);
    if (rows instanceof HTMLInputElement) rows.value = String(mode.dataset.defaultRows || rows.value);
    if (override instanceof HTMLInputElement) override.checked = false;
    syncMode(mode);
}
