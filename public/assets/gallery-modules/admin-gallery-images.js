/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-images.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Adds lightweight preview, selection, and filename controls to the gallery Images editor.
 *
 * Responsibilities:
 *   - Remember filename visibility per editor and provide a browser default
 *   - Extend checkbox selection across visible image rows with Shift-click
 *   - Show bounded previews from the authorized thumbnail URL supplied by the view
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

const filenamePreferenceKey = 'php-gallery.admin-image-names.default.v1';
const sessionFilenameStates = new Map();
const boundDocuments = new WeakSet();
const selectionRangeAnchors = new WeakMap();

/**
 * Initializes image editor interactions in a document or newly injected panel fragment.
 * Document-level event delegation keeps controls in dynamically replaced panel content active.
 *
 * @param {Document|HTMLElement} root Fragment to initialize, or the document.
 * @return {void} Result value for the caller.
 */
export function setupAdminGalleryImages(root = document) {
    initializeImageEditorFragments(root);
    const doc = root instanceof Document ? root : root.ownerDocument;
    if (!doc || boundDocuments.has(doc)) return;
    boundDocuments.add(doc);

    const overlay = createPreviewOverlay(doc);
    let pinnedPreview = false;
    let activePreviewButton = null;
    let previewAnchorX = 0;
    let previewAnchorY = 0;
    let suppressPreviewFocusFor = null;
    const previewImage = overlay.querySelector('img');

    /**
     * Repositions the preview after its thumbnail image finishes loading.
     *
     * @return {void} Result value for the caller.
     */
    function handlePreviewImageLoad() {
        if (!overlay.hidden) positionPreview(previewAnchorX, previewAnchorY);
    }

    /**
     * Positions the preview next to the pointer while keeping it inside the viewport.
     *
     * @param {number} clientX Pointer horizontal coordinate.
     * @param {number} clientY Pointer vertical coordinate.
     * @return {void} Result value for the caller.
     */
    function positionPreview(clientX, clientY) {
        previewAnchorX = clientX;
        previewAnchorY = clientY;
        const margin = 12;
        const bounds = overlay.getBoundingClientRect();
        let left = clientX + 18;
        let top = clientY + 18;
        if (left + bounds.width > doc.defaultView.innerWidth - margin) left = clientX - bounds.width - 18;
        if (top + bounds.height > doc.defaultView.innerHeight - margin) top = doc.defaultView.innerHeight - bounds.height - margin;
        overlay.style.left = `${Math.max(margin, left)}px`;
        overlay.style.top = `${Math.max(margin, top)}px`;
    }

    /**
     * Shows the authorized thumbnail preview for a row control.
     *
     * @param {HTMLElement} button Preview control containing the thumbnail URL and accessible name.
     * @param {number} clientX Pointer horizontal coordinate.
     * @param {number} clientY Pointer vertical coordinate.
     * @param {boolean} pin Whether dismissal requires an explicit close or outside action.
     * @return {void} Result value for the caller.
     */
    function showPreview(button, clientX, clientY, pin) {
        const src = button.dataset.previewSrc || '';
        if (!src) return;
        const panel = button.closest('[data-admin-side-panel]');
        const modalRoot = panel?.querySelector('.admin-side-panel-dialog') || doc.body;
        if (overlay.parentElement !== modalRoot) modalRoot.append(overlay);
        const image = overlay.querySelector('img');
        if (!(image instanceof HTMLImageElement)) return;
        image.src = src;
        image.alt = button.dataset.previewName || '';
        activePreviewButton = button;
        pinnedPreview = pin;
        overlay.hidden = false;
        overlay.setAttribute('aria-label', button.getAttribute('aria-label') || image.alt || 'Image preview');
        overlay.classList.toggle('is-pinned', pin);
        const close = overlay.querySelector('[data-admin-image-preview-close]');
        if (close instanceof HTMLButtonElement) {
            close.setAttribute('aria-label', button.dataset.previewCloseLabel || 'Close image preview');
        }
        positionPreview(clientX, clientY);
    }

    /**
     * Hides the preview and clears its active source.
     *
     * @return {void} Result value for the caller.
     */
    function hidePreview() {
        const returnFocusTarget = activePreviewButton;
        const focusIsInPreview = overlay.contains(doc.activeElement);
        overlay.hidden = true;
        overlay.classList.remove('is-pinned');
        const image = overlay.querySelector('img');
        if (image instanceof HTMLImageElement) image.removeAttribute('src');
        activePreviewButton = null;
        pinnedPreview = false;
        if (focusIsInPreview && returnFocusTarget?.isConnected) {
            suppressPreviewFocusFor = returnFocusTarget;
            returnFocusTarget.focus({preventScroll: true});
        }
    }

    /**
     * Delegates thumbnail preview hover and pointer movement from the document.
     *
     * @param {PointerEvent} event Pointer event emitted by a preview control.
     * @return {void} Result value for the caller.
     */
    function handlePreviewPointer(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-admin-image-preview]') : null;
        if (!(button instanceof HTMLElement)) return;
        if (event.type === 'pointerover') {
            if (!pinnedPreview) showPreview(button, event.clientX, event.clientY, false);
        } else if (event.type === 'pointermove' && activePreviewButton === button && !pinnedPreview) {
            positionPreview(event.clientX, event.clientY);
        } else if (event.type === 'pointerout' && !pinnedPreview && !button.contains(event.relatedTarget)) {
            hidePreview();
        }
    }

    /**
     * Shows a non-pinned preview when its thumbnail control receives keyboard focus.
     *
     * @param {FocusEvent} event Focus event emitted by a preview control.
     * @return {void} Result value for the caller.
     */
    function handlePreviewFocus(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-admin-image-preview]') : null;
        if (!(button instanceof HTMLElement) || pinnedPreview) return;
        if (suppressPreviewFocusFor === button) {
            suppressPreviewFocusFor = null;
            return;
        }
        const bounds = button.getBoundingClientRect();
        showPreview(button, bounds.left + bounds.width / 2, bounds.top + bounds.height / 2, false);
    }

    /**
     * Hides an unpinned preview when focus leaves its thumbnail control.
     *
     * @param {FocusEvent} event Focus event emitted by a preview control.
     * @return {void} Result value for the caller.
     */
    function handlePreviewBlur(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-admin-image-preview]') : null;
        if (button && !pinnedPreview) hidePreview();
    }

    /**
     * Pins a preview on click, while the overlay close button remains available.
     *
     * @param {MouseEvent} event Click event emitted by a preview control or overlay close control.
     * @return {void} Result value for the caller.
     */
    function handlePreviewClick(event) {
        if (event.target instanceof Element && event.target.closest('[data-admin-image-preview-close]')) {
            hidePreview();
            return;
        }
        const button = event.target instanceof Element ? event.target.closest('[data-admin-image-preview]') : null;
        if (button instanceof HTMLElement) {
            event.preventDefault();
            if (activePreviewButton === button && pinnedPreview) hidePreview();
            else {
                const bounds = button.getBoundingClientRect();
                const clientX = event.clientX || bounds.left + bounds.width / 2;
                const clientY = event.clientY || bounds.top + bounds.height / 2;
                showPreview(button, clientX, clientY, true);
                if (event.detail === 0) overlay.querySelector('[data-admin-image-preview-close]')?.focus({preventScroll: true});
            }
            return;
        }
        if (pinnedPreview && !overlay.contains(event.target)) hidePreview();
    }

    /**
     * Dismisses previews on Escape, scrolling, or when the active row is replaced.
     *
     * @param {KeyboardEvent} event Keyboard event emitted by the document.
     * @return {void} Result value for the caller.
     */
    function handlePreviewKeydown(event) {
        if (event.key === 'Escape' && !overlay.hidden) {
            event.preventDefault();
            event.stopPropagation();
            hidePreview();
        }
    }

    /**
     * Updates filename visibility and remembers the current editor state for its gallery.
     *
     * @param {HTMLInputElement} toggle Filename visibility checkbox.
     * @return {void} Result value for the caller.
     */
    function applyFilenameVisibility(toggle) {
        const form = toggle.closest('[data-admin-image-bulk-form]');
        const table = form?.querySelector('[data-admin-image-order-table]');
        if (!form || !table) return;
        const galleryId = form.querySelector('input[name="gallery_id"]')?.value || 'unknown';
        table.classList.toggle('is-filenames-hidden', !toggle.checked);
        sessionFilenameStates.set(galleryId, toggle.checked);
    }

    /**
     * Applies filename visibility changes and saves the default when requested.
     *
     * @param {Event} event Change or click event emitted by a filename control.
     * @return {void} Result value for the caller.
     */
    function handleFilenameControl(event) {
        const target = event.target;
        if (target instanceof HTMLInputElement && target.matches('[data-admin-image-names-toggle]')) {
            applyFilenameVisibility(target);
            return;
        }
        if (!(target instanceof Element) || !target.closest('[data-admin-image-names-default]')) return;
        const button = target.closest('[data-admin-image-names-default]');
        const toggle = button?.closest('[data-admin-image-bulk-form]')?.querySelector('[data-admin-image-names-toggle]');
        const status = button?.closest('[data-admin-image-bulk-form]')?.querySelector('[data-admin-image-names-status]');
        if (!(toggle instanceof HTMLInputElement)) return;
        try {
            doc.defaultView.localStorage.setItem(filenamePreferenceKey, toggle.checked ? 'shown' : 'hidden');
            if (status) status.textContent = button.dataset.savedMessage || 'Default saved.';
        } catch {
            if (status) status.textContent = button.dataset.unavailableMessage || 'Browser storage is unavailable; this choice applies only to this editor session.';
        }
    }

    /**
     * Mirrors selected image checkboxes onto row and label presentation state.
     *
     * @param {HTMLInputElement} checkbox Image selection checkbox.
     * @return {void} Result value for the caller.
     */
    function updateSelection(checkbox) {
        const row = checkbox.closest('[data-admin-image-order-row]');
        if (!row) return;
        row.classList.toggle('is-picture-manager-selected', checkbox.checked);
        row.setAttribute('aria-selected', checkbox.checked ? 'true' : 'false');
        const label = row.querySelector('label.picture-manager-select-button');
        label?.setAttribute('aria-pressed', checkbox.checked ? 'true' : 'false');
    }

    /**
     * Handles dynamic selection checkbox updates.
     *
     * @param {Event} event Change event emitted by an image selection checkbox.
     * @return {void} Result value for the caller.
     */
    function handleSelectionChange(event) {
        const checkbox = event.target;
        if (checkbox instanceof HTMLInputElement && checkbox.name === 'image_ids[]') {
            updateSelection(checkbox);
            return;
        }
        const form = checkbox instanceof Element ? checkbox.closest('[data-admin-image-bulk-form]') : null;
        if (!form) return;
        // Existing select-all code updates row checkboxes programmatically without dispatching change.
        /**
         * Refreshes row selection visuals after a select-all action updates inputs.
         *
         * @return {void} Result value for the caller.
         */
        function refreshProgrammaticSelection() {
            /**
             * Refreshes one image checkbox after a select-all update.
             *
             * @param {Element} input Candidate image selection checkbox.
             * @return {void} Result value for the caller.
             */
            function refreshInputSelection(input) {
                if (input instanceof HTMLInputElement) updateSelection(input);
            }
            form.querySelectorAll('input[name="image_ids[]"]').forEach(refreshInputSelection);
        }
        queueMicrotask(refreshProgrammaticSelection);
    }

    /**
     * Extends a shifted image-checkbox click across the current visual row range.
     *
     * @param {MouseEvent} event Delegated click event from an image selection checkbox.
     * @return {void} Result value for the caller.
     */
    function handleSelectionRangeClick(event) {
        const checkbox = event.target instanceof Element ? event.target.closest('input[type="checkbox"][name="image_ids[]"]') : null;
        if (!(checkbox instanceof HTMLInputElement) || !event.shiftKey) {
            if (checkbox instanceof HTMLInputElement) {
                const form = checkbox.closest('[data-admin-image-bulk-form]');
                if (form) selectionRangeAnchors.set(form, checkbox);
            }
            return;
        }

        const form = checkbox.closest('[data-admin-image-bulk-form]');
        const table = checkbox.closest('[data-admin-image-order-table]');
        const anchor = form ? selectionRangeAnchors.get(form) : null;
        if (!form || !table || !anchor?.isConnected || anchor === checkbox || !table.contains(anchor)) {
            if (form) selectionRangeAnchors.set(form, checkbox);
            return;
        }
        const inputs = Array.from(table.querySelectorAll('input[type="checkbox"][name="image_ids[]"]'));
        const anchorIndex = inputs.indexOf(anchor);
        const targetIndex = inputs.indexOf(checkbox);
        if (anchorIndex < 0 || targetIndex < 0) {
            selectionRangeAnchors.set(form, checkbox);
            return;
        }

        const start = Math.min(anchorIndex, targetIndex);
        const end = Math.max(anchorIndex, targetIndex);
        const selected = checkbox.checked;
        /**
         * Applies the target selection state to one row in the inclusive range.
         *
         * @param {HTMLInputElement} input Checkbox belonging to an image row in the range.
         * @return {void} Result value for the caller.
         */
        function applyRangeCheckboxState(input) {
            if (input === checkbox || input.checked === selected) return;
            input.checked = selected;
            input.dispatchEvent(new doc.defaultView.Event('change', {bubbles: true}));
        }
        inputs.slice(start, end + 1).forEach(applyRangeCheckboxState);
    }

    doc.addEventListener('pointerover', handlePreviewPointer, true);
    doc.addEventListener('pointermove', handlePreviewPointer, true);
    doc.addEventListener('pointerout', handlePreviewPointer, true);
    doc.addEventListener('focusin', handlePreviewFocus, true);
    doc.addEventListener('focusout', handlePreviewBlur, true);
    doc.addEventListener('click', handlePreviewClick, true);
    doc.addEventListener('keydown', handlePreviewKeydown, true);
    doc.addEventListener('scroll', hidePreview, true);
    doc.addEventListener('change', handleFilenameControl, true);
    doc.addEventListener('click', handleFilenameControl, true);
    doc.addEventListener('change', handleSelectionChange, true);
    doc.addEventListener('click', handleSelectionRangeClick);

    /**
     * Dismisses a preview when its row or containing side panel is removed or closed.
     *
     * @return {void} Result value for the caller.
     */
    function handleObservedEditorChanges() {
        if (!activePreviewButton) return;
        const panel = activePreviewButton.closest('[data-admin-side-panel]');
        if (!activePreviewButton.isConnected || (panel && !panel.classList.contains('is-open'))) hidePreview();
    }
    const observer = new MutationObserver(handleObservedEditorChanges);
    observer.observe(doc.body || doc.documentElement, {childList: true, subtree: true});
    const panel = doc.querySelector('[data-admin-side-panel]');
    if (panel) observer.observe(panel, {attributes: true, attributeFilter: ['class', 'hidden', 'aria-hidden']});
    previewImage?.addEventListener('load', handlePreviewImageLoad);
}

/**
 * Creates the single reusable, initially hidden preview overlay.
 *
 * @param {Document} doc Owner document for the current editor.
 * @return {HTMLDivElement} Detached overlay mounted into the active editor when shown.
 */
function createPreviewOverlay(doc) {
    const overlay = doc.createElement('div');
    overlay.className = 'admin-image-preview-overlay';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'false');
    const image = doc.createElement('img');
    image.alt = '';
    image.decoding = 'async';
    image.loading = 'eager';
    const close = doc.createElement('button');
    close.type = 'button';
    close.dataset.adminImagePreviewClose = '';
    close.setAttribute('aria-label', 'Close image preview');
    close.textContent = '×';
    overlay.append(image, close);
    return overlay;
}

/**
 * Applies remembered or default filename visibility and synchronizes initial checkbox state.
 *
 * @param {Document|HTMLElement} root Fragment to initialize.
 * @return {void} Result value for the caller.
 */
function initializeImageEditorFragments(root) {
    const doc = root instanceof Document ? root : root.ownerDocument;
    const tables = [];
    if (root instanceof Element && root.matches('[data-admin-image-order-table]')) tables.push(root);
    tables.push(...root.querySelectorAll('[data-admin-image-order-table]'));
    let defaultState = true;
    try {
        defaultState = doc?.defaultView?.localStorage.getItem(filenamePreferenceKey) !== 'hidden';
    } catch {
        defaultState = true;
    }
    /**
     * Applies the stored filename and selection state to one editor table.
     *
     * @param {Element} table Image-order table in the fragment.
     * @return {void} Result value for the caller.
     */
    function initializeTable(table) {
        const form = table.closest('[data-admin-image-bulk-form]');
        const toggle = form?.querySelector('[data-admin-image-names-toggle]');
        if (!(toggle instanceof HTMLInputElement)) return;
        const galleryId = form.querySelector('input[name="gallery_id"]')?.value || 'unknown';
        toggle.checked = sessionFilenameStates.has(galleryId) ? sessionFilenameStates.get(galleryId) : defaultState;
        table.classList.toggle('is-filenames-hidden', !toggle.checked);
        /**
         * Restores presentation state for one selected image checkbox.
         *
         * @param {Element} checkbox Selection checkbox in an image row.
         * @return {void} Result value for the caller.
         */
        function initializeCheckbox(checkbox) {
            if (checkbox instanceof HTMLInputElement) {
                const row = checkbox.closest('[data-admin-image-order-row]');
                if (row) {
                    row.setAttribute('aria-selected', checkbox.checked ? 'true' : 'false');
                    row.querySelector('label.picture-manager-select-button')?.setAttribute('aria-pressed', checkbox.checked ? 'true' : 'false');
                }
                row?.classList.toggle('is-picture-manager-selected', checkbox.checked);
            }
        }
        table.querySelectorAll('input[name="image_ids[]"]').forEach(initializeCheckbox);
    }
    tables.forEach(initializeTable);
}
