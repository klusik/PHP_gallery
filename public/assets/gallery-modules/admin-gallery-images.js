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
 * Canonical cover selection projection consumed by editor presentation helpers.
 * @typedef {Object} CoverSelectionResponse
 * @property {'legacy'|'single'|'automatic'|'multi'} cover_selection_mode Persisted selector mode.
 * @property {Array<number|string>} [cover_selection_ids] Persisted explicit selected image IDs.
 * @property {Array<number|string>} [cover_image_ids] Compatibility projection of persisted explicit IDs.
 * @property {number|string} [cover_image_id] Primary persisted image ID for legacy/single state.
 * @property {string} [cover_selection_revision] Opaque persisted cover-selection revision.
 * @property {string} [edit_revision] Current gallery settings revision after the mutation.
 * @property {string} [previous_edit_revision] Exact settings revision superseded by this acknowledged write; absent on passive reconciliation.
 */

/**
 * Choose the semantic cover action before normal form submission.
 *
 * @param {string} mode Current selection mode (multi or single/automatic).
 * @param {string} state State of the clicked star (off, single, multi).
 * @returns {string} Controller row action without the clicked image ID.
 */
export function coverStarActionForState(mode, state) {
    return mode === 'multi'
        ? (state === 'multi' ? 'cover_reset' : 'cover_add')
        : (state === 'single' ? 'cover_arm' : 'cover');
}

/**
 * Return the presentation state for server-confirmed title-picture selection.
 *
 * @param {CoverSelectionResponse|null} result Mutation or authoritative state response.
 * @return {{mode:string,ids:number[],revision:string}|null} Normalized state, or null when incomplete.
 */
export function coverSelectionPresentation(result) {
    if (!result || typeof result !== 'object') return null;
    const mode = String(result.cover_selection_mode || '');
    const rawIds = result.cover_selection_ids ?? result.cover_image_ids;
    if (!['legacy', 'single', 'automatic', 'multi'].includes(mode) || !Array.isArray(rawIds)) return null;
    const ids = [...new Set(rawIds.map(Number).filter(id => Number.isInteger(id) && id > 0))];
    if (ids.length > 4) return null;
    const revision = String(result.cover_selection_revision || '');
    if (revision === '') return null;
    return {mode, ids, revision};
}

/**
 * Apply a server-confirmed title-picture state to the mounted editor controls.
 *
 * Only cover controls and the cover selector change; editor markup and drafts stay mounted.
 *
 * @param {HTMLFormElement} form Mounted image bulk form.
 * @param {CoverSelectionResponse|null} result Canonical server response containing cover selection state.
 * @return {boolean} Whether a complete authoritative state was applied.
 */
export function applyCoverSelectionPresentation(form, result) {
    const selection = coverSelectionPresentation(result);
    if (!selection || !(form instanceof HTMLFormElement)) return false;
    const {mode, ids, revision} = selection;
    form.dataset.coverSelectionMode = mode;
    if (revision !== '') form.dataset.coverSelectionRevision = revision;
    const revisionInput = form.querySelector('input[name="cover_selection_revision"]');
    if (revisionInput instanceof HTMLInputElement && revision !== '') revisionInput.value = revision;
    const editRevision = String(result.edit_revision || '');
    const editorRoot = form.closest('[data-admin-side-panel]') || form.ownerDocument;
    const settingsForm = editorRoot.querySelector('.admin-edit-gallery-form');
    const settingsRevision = settingsForm?.querySelector('input[name="edit_revision"]');
    if (settingsRevision instanceof HTMLInputElement && editRevision !== ''
        && String(result.previous_edit_revision || '') === settingsRevision.value) {
        settingsRevision.value = editRevision;
    }
    const settingsCoverRevision = settingsForm?.querySelector('input[name="cover_selection_revision"]');
    if (settingsCoverRevision instanceof HTMLInputElement && revision !== '') settingsCoverRevision.value = revision;
    const selectedIds = new Set(ids.map(String));
    form.querySelectorAll('[data-admin-image-cover-star]').forEach(button => {
        if (!(button instanceof HTMLButtonElement)) return;
        const selected = selectedIds.has(String(button.dataset.imageId || ''));
        const state = selected ? (mode === 'multi' ? 'multi' : 'single') : 'off';
        button.dataset.coverState = state;
        button.classList.toggle('is-cover', selected);
        button.classList.toggle('is-cover-multi', state === 'multi');
        button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        const label = String((state === 'multi' ? button.dataset.coverLabelReset
            : state === 'single' ? button.dataset.coverLabelArm
            : mode === 'multi' ? button.dataset.coverLabelAdd : button.dataset.coverLabelSingle) || '');
        const name = String(button.closest('[data-admin-image-order-row]')?.dataset.imageName || '');
        button.title = label;
        button.setAttribute('aria-label', (label + ' ' + name).trim());
        const star = button.querySelector('[aria-hidden="true"]');
        if (star) star.textContent = selected ? '★' : '☆';
    });
    const coverSelect = settingsForm?.elements.namedItem('cover_image_id');
    if (coverSelect instanceof HTMLSelectElement) {
        const selectedValue = mode === 'automatic' ? 'automatic'
            : mode === 'multi' ? 'multi'
                : mode === 'single' ? String(ids[0] || Number(result.cover_image_id || 0))
                    : '0';
        if (selectedValue === 'multi' && !Array.from(coverSelect.options).some(option => option.value === 'multi')) {
            const multiOption = form.ownerDocument.createElement('option');
            multiOption.value = 'multi';
            multiOption.textContent = String(form.dataset.coverMultiLabel || 'Selected photos').replace('{count}', String(ids.length));
            coverSelect.add(multiOption);
        }
        if (selectedValue === 'multi') {
            const multiOption = Array.from(coverSelect.options).find(option => option.value === 'multi');
            if (multiOption) multiOption.textContent = String(form.dataset.coverMultiLabel || 'Selected photos').replace('{count}', String(ids.length));
        }
        if (Array.from(coverSelect.options).some(option => option.value === selectedValue)) {
            coverSelect.value = selectedValue;
        }
    }
    return true;
}

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
     * Resolve a dynamically rendered star's action synchronously before SubmitEvent.
     * Existing form handlers retain ownership of CSRF, saving, canonical completion,
     * failure handling and full-page fallback.
     *
     * @param {MouseEvent} event Click on a gallery title-picture star.
     * @return {void} Assign the clicked submitter's intended row action.
     */
    function handleCoverStarClick(event) {
        const button = event.target instanceof Element ? event.target.closest('[data-admin-image-cover-star]') : null;
        if (!(button instanceof HTMLButtonElement) || button.disabled) return;
        const form = button.closest('[data-admin-image-bulk-form]');
        if (!(form instanceof HTMLFormElement)) return;
        const mode = String(form.dataset.coverSelectionMode || 'legacy');
        if (mode === 'multi' && String(button.dataset.coverState || 'off') === 'off'
            && form.querySelectorAll('[data-admin-image-cover-star][data-cover-state="multi"]').length >= 4) {
            event.preventDefault();
            const status = form.querySelector('[data-admin-image-cover-status]');
            if (status) status.textContent = status.dataset.coverLimitMessage || 'A title picture can contain at most four photos.';
            return;
        }
        const action = form.dataset.coverMultiSupported === '1'
            ? coverStarActionForState(mode, String(button.dataset.coverState || 'off'))
            : 'cover';
        button.value = action + ':' + String(button.dataset.imageId || '0');
        form.__adminCoverSubmitter = button;
    }

    /**
     * Reflect server-confirmed blue/gold star state without replacing the editor.
     *
     * @param {CustomEvent} event Existing side-panel successful bulk mutation.
     * @return {void} Update selected rows, tooltips and keyboard pressed states.
     */
    function handleCoverSaved(event) {
        if (event.detail?.source !== 'gallery-image-bulk') return;
        const result = event.detail?.result;
        if (!result?.ok || !['cover', 'cover_arm', 'cover_add', 'cover_reset'].includes(String(result.bulk_action || ''))) return;
        const form = event.target instanceof Element ? event.target.closest('[data-admin-image-bulk-form]') : null;
        if (!(form instanceof HTMLFormElement)) return;
        applyCoverSelectionPresentation(form, result);
    }

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

    doc.addEventListener('click', handleCoverStarClick, true);
    doc.addEventListener('php-gallery:side-panel-success', handleCoverSaved);
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
