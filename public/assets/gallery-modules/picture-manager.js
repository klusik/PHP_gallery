/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/picture-manager.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides public gallery picture selection and bulk actions for logged-in users.
 *
 * Responsibilities:
 *   - Attach a visible file-manager-style selection model to public gallery photos
 *   - Support Shift-click range selection and Ctrl/Cmd-click item toggling
 *   - Move selected photos through the existing server-side move endpoint
 *   - Copy selected photos through the shared server-side copy endpoint
 *   - Create a child gallery from selected photos through the copy endpoint
 *   - Keep desktop drag-and-drop progressive, with toolbar actions as the fallback
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
 *   2026-05-19
 */

import { PUBLIC_PHOTO_MOVE_EVENT, highlightPublicPhotoDropTarget, publicPhotoDropTargetAtPoint, publicPhotoDropTargetGalleryId, publicPhotoDropTargets, publicPhotoImageIdsFromDataTransfer, setPublicPhotoDropTargetsActive, writePublicPhotoImageIdsToDataTransfer } from './public-photo-drop-actions.js?v=20260519-public-photo-drop-v1';

// activePictureManager stores the currently bound toolbar instance so fragment
// refreshes can safely replace the toolbar and bind a fresh one.
let activePictureManager = null;

/**
 * Release the currently bound Picture manager instance, if any.
 *
 * @returns {void}
 */
export function teardownPictureManager() {
    if (!activePictureManager || typeof activePictureManager.teardown !== 'function') {
        activePictureManager = null;
        return;
    }
    activePictureManager.teardown();
    activePictureManager = null;
}

/**
 * Enables the public gallery Picture manager.
 *
 * The interaction model intentionally mirrors normal desktop file managers while
 * keeping every action discoverable through visible controls. Normal photo clicks
 * still open the lightbox. Modifier-clicks and the visible check buttons are the
 * only card-level selection gestures, so anonymous browsing behavior stays intact.
 *
 * @returns {void}
 */
export function setupPictureManager() {
    // toolbar stores the public-page manager controls rendered by PHP.
    const toolbar = document.querySelector('[data-picture-manager]');
    if (!(toolbar instanceof HTMLElement)) {
        teardownPictureManager();
        return;
    }
    if (activePictureManager && activePictureManager.toolbar === toolbar) {
        return;
    }
    teardownPictureManager();

    // cards stores the currently visible photo cards on this pagination page.
    const cards = Array.from(document.querySelectorAll('[data-picture-manager-image]'))
        .filter((card) => card instanceof HTMLElement);
    if (cards.length === 0) {
        activePictureManager = {
            toolbar,
            teardown() {
                if (toolbar.dataset.pictureManagerExpanded) {
                    delete toolbar.dataset.pictureManagerExpanded;
                }
            },
        };
        return;
    }
    const eventController = new AbortController();
    const signalOptions = {signal: eventController.signal};

    // selectedIds stores image IDs currently selected by the user.
    const selectedIds = new Set();
    // anchorCard stores the card where the latest contiguous range should begin.
    let anchorCard = null;
    // activeRequest stores whether a server mutation is currently in flight.
    let activeRequest = false;
    // dragSelection stores the IDs being dragged during a native drag session.
    let dragSelection = [];

    // sourceGalleryId stores the current gallery ID posted to all endpoints.
    const sourceGalleryId = toolbar.dataset.sourceGalleryId || '';
    // csrfToken stores the CSRF token rendered by PHP for manager endpoints.
    const csrfToken = toolbar.dataset.csrfToken || '';
    // moveUrl stores the JSON endpoint used for moving selected photos.
    const moveUrl = toolbar.dataset.moveUrl || '';
    // copyUrl stores the JSON endpoint used for copying selected photos to an existing gallery.
    const copyUrl = toolbar.dataset.copyUrl || '';
    // createUrl stores the JSON endpoint used for creating a child gallery copy.
    const createUrl = toolbar.dataset.createUrl || '';

    // toggleButton stores the visible collapse and expand control.
    const toggleButton = toolbar.querySelector('[data-picture-manager-toggle]');
    // panel stores the collapsible action area.
    const panel = toolbar.querySelector('[data-picture-manager-panel]');
    // countLabel stores the selected-photo counter shown in the toolbar.
    const countLabel = toolbar.querySelector('[data-picture-manager-count]');
    // statusLabel stores the live status and error text.
    const statusLabel = toolbar.querySelector('[data-picture-manager-status]');
    // selectAllButton stores the visible current-page selection action.
    const selectAllButton = toolbar.querySelector('[data-picture-manager-select-all]');
    // clearButton stores the visible selection reset action.
    const clearButton = toolbar.querySelector('[data-picture-manager-clear]');
    // destinationInput stores the shared searchable gallery picker submitted value.
    const destinationInput = toolbar.querySelector('[data-picture-manager-destination]');
    // moveButton stores the explicit move action.
    const moveButton = toolbar.querySelector('[data-picture-manager-move]');
    // copyButton stores the explicit copy action.
    const copyButton = toolbar.querySelector('[data-picture-manager-copy]');
    // newTitleInput stores the new child gallery title.
    const newTitleInput = toolbar.querySelector('[data-picture-manager-new-title]');
    // newFolderInput stores the optional child gallery folder name.
    const newFolderInput = toolbar.querySelector('[data-picture-manager-new-folder]');
    // createButton stores the explicit create-gallery-from-selection action.
    const createButton = toolbar.querySelector('[data-picture-manager-create]');

    /**
     * Expands or collapses the Picture manager action panel.
     *
     * @param {boolean} expanded Whether the detailed controls should be visible.
     * @returns {void}
     */
    function setPanelExpanded(expanded) {
        toolbar.classList.toggle('is-picture-manager-collapsed', !expanded);
        toolbar.classList.toggle('is-picture-manager-expanded', expanded);
        toolbar.dataset.pictureManagerExpanded = expanded ? '1' : '0';
        if (toggleButton instanceof HTMLButtonElement) {
            toggleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
        if (panel instanceof HTMLElement) {
            panel.hidden = !expanded;
        }
    }

    /**
     * Expands the manager when a selection makes bulk actions relevant.
     *
     * @returns {void}
     */
    function expandForSelection() {
        if (selectedIds.size > 0) {
            setPanelExpanded(true);
        }
    }

    /**
     * Returns a stable image ID from a visible card.
     *
     * @param {Element|null} card Card element rendered for one image.
     * @returns {string} Image ID or an empty string when unavailable.
     */
    function cardImageId(card) {
        if (!(card instanceof HTMLElement)) {
            return '';
        }
        return card.dataset.pictureManagerImageId || '';
    }

    /**
     * Returns the current card index inside the visible page.
     *
     * @param {Element|null} card Card element rendered for one image.
     * @returns {number} Zero-based visible index, or -1 when unavailable.
     */
    function cardPosition(card) {
        if (!(card instanceof HTMLElement)) {
            return -1;
        }
        return cards.indexOf(card);
    }

    /**
     * Writes a toolbar status message.
     *
     * @param {string} message Message visible to the user.
     * @param {'idle'|'ok'|'error'|'working'} state Visual state used by CSS.
     * @returns {void}
     */
    function setStatus(message, state = 'idle') {
        if (!(statusLabel instanceof HTMLElement)) {
            return;
        }
        statusLabel.textContent = message;
        statusLabel.dataset.state = state;
    }

    /**
     * Returns selected image IDs in the same order as visible cards.
     *
     * @returns {string[]} Selected image IDs in visual order.
     */
    function selectedIdsInPageOrder() {
        return cards
            .map((card) => cardImageId(card))
            .filter((imageId) => selectedIds.has(imageId));
    }

    /**
     * Applies visual selected state to one card and its check button.
     *
     * @param {HTMLElement} card Visible image card.
     * @returns {void}
     */
    function syncCardState(card) {
        const imageId = cardImageId(card);
        const isSelected = imageId !== '' && selectedIds.has(imageId);
        const button = card.querySelector('[data-picture-manager-select]');
        card.classList.toggle('is-picture-manager-selected', isSelected);
        card.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        // Every managed photo remains draggable so a single non-selected photo
        // can be dropped into a subgallery without requiring a separate select step.
        card.draggable = true;
        if (button instanceof HTMLButtonElement) {
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            button.title = isSelected ? 'Deselect photo' : 'Select photo';
        }
    }

    /**
     * Synchronizes toolbar controls and visible card state after any selection change.
     *
     * @returns {void}
     */
    function syncSelectionState() {
        cards.forEach(syncCardState);
        const count = selectedIds.size;
        if (countLabel instanceof HTMLElement) {
            countLabel.textContent = count === 0 ? 'No photos selected.' : `${count} photo${count === 1 ? '' : 's'} selected.`;
        }
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.disabled = count === 0;
        }
        updateActionButtons();
        expandForSelection();
    }

    /**
     * Enables or disables mutation buttons based on current form state.
     *
     * @returns {void}
     */
    function updateActionButtons() {
        const hasSelection = selectedIds.size > 0;
        const hasDestination = destinationInput instanceof HTMLInputElement && destinationInput.value !== '';
        const hasTitle = newTitleInput instanceof HTMLInputElement && newTitleInput.value.trim() !== '';
        if (moveButton instanceof HTMLButtonElement) {
            moveButton.disabled = activeRequest || !hasSelection || !hasDestination;
        }
        if (copyButton instanceof HTMLButtonElement) {
            copyButton.disabled = activeRequest || !hasSelection || !hasDestination;
        }
        if (createButton instanceof HTMLButtonElement) {
            createButton.disabled = activeRequest || !hasSelection || !hasTitle;
        }
        if (selectAllButton instanceof HTMLButtonElement) {
            selectAllButton.disabled = activeRequest;
        }
    }

    /**
     * Sets active state for all server-side mutation controls.
     *
     * @param {boolean} isActive Whether a request is running.
     * @returns {void}
     */
    function setRequestActive(isActive) {
        activeRequest = isActive;
        if (destinationInput instanceof HTMLInputElement) {
            destinationInput.disabled = isActive;
        }
        if (newTitleInput instanceof HTMLInputElement) {
            newTitleInput.disabled = isActive;
        }
        if (newFolderInput instanceof HTMLInputElement) {
            newFolderInput.disabled = isActive;
        }
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.disabled = isActive || selectedIds.size === 0;
        }
        updateActionButtons();
    }

    /**
     * Selects exactly the provided cards and clears all other visible selections.
     *
     * @param {HTMLElement[]} nextCards Cards that should be selected.
     * @param {HTMLElement|null} nextAnchor Card that becomes the new range anchor.
     * @returns {void}
     */
    function replaceSelection(nextCards, nextAnchor) {
        selectedIds.clear();
        nextCards.forEach((card) => {
            const imageId = cardImageId(card);
            if (imageId !== '') {
                selectedIds.add(imageId);
            }
        });
        anchorCard = nextAnchor;
        syncSelectionState();
    }

    /**
     * Selects all visible photos on the current page.
     *
     * @returns {void}
     */
    function selectAllVisible() {
        replaceSelection(cards, cards[0] || null);
        setStatus(`Selected all ${cards.length} visible photo${cards.length === 1 ? '' : 's'}.`, 'ok');
    }

    /**
     * Clears every visible selected photo.
     *
     * @returns {void}
     */
    function clearSelection() {
        selectedIds.clear();
        anchorCard = null;
        syncSelectionState();
        setPanelExpanded(false);
        setStatus('Selection cleared.', 'idle');
    }

    /**
     * Toggles one card in the selected set.
     *
     * @param {HTMLElement} card Card to toggle.
     * @returns {void}
     */
    function toggleCard(card) {
        const imageId = cardImageId(card);
        if (imageId === '') {
            return;
        }
        if (selectedIds.has(imageId)) {
            selectedIds.delete(imageId);
        } else {
            selectedIds.add(imageId);
        }
        anchorCard = card;
        syncSelectionState();
    }

    /**
     * Selects an inclusive range from the anchor card to the target card.
     *
     * @param {HTMLElement} targetCard Card at the end of the range.
     * @returns {void}
     */
    function selectRange(targetCard) {
        if (!anchorCard) {
            replaceSelection([targetCard], targetCard);
            return;
        }
        const anchorIndex = cardPosition(anchorCard);
        const targetIndex = cardPosition(targetCard);
        if (anchorIndex < 0 || targetIndex < 0) {
            replaceSelection([targetCard], targetCard);
            return;
        }
        const first = Math.min(anchorIndex, targetIndex);
        const last = Math.max(anchorIndex, targetIndex);
        cards.slice(first, last + 1).forEach((card) => {
            const imageId = cardImageId(card);
            if (imageId !== '') {
                selectedIds.add(imageId);
            }
        });
        syncSelectionState();
    }

    /**
     * Handles a click on a visible selection check button.
     *
     * @param {MouseEvent} event Click event from the check button.
     * @returns {void}
     */
    function handleSelectButtonClick(event) {
        const button = event.currentTarget;
        const card = button instanceof HTMLElement ? button.closest('[data-picture-manager-image]') : null;
        if (!(card instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (event.shiftKey) {
            selectRange(card);
            return;
        }
        toggleCard(card);
    }

    /**
     * Handles modifier-click selection on the card itself.
     *
     * @param {MouseEvent} event Click event from the card.
     * @returns {void}
     */
    function handleCardClick(event) {
        if (event.defaultPrevented || activeRequest) {
            return;
        }
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        if (target.closest('[data-picture-manager-select], .public-reorder-handle, .photo-map-pin, form, input, textarea, select, button')) {
            return;
        }
        if (!event.shiftKey && !event.ctrlKey && !event.metaKey) {
            return;
        }
        const card = event.currentTarget;
        if (!(card instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (event.shiftKey) {
            selectRange(card);
            return;
        }
        toggleCard(card);
    }

    /**
     * Collapses the HUD when the user clicks away from it without an active selection.
     *
     * @param {PointerEvent} event Pointer event from the document.
     * @returns {void}
     */
    function handleDocumentPointerDown(event) {
        if (selectedIds.size > 0 || toolbar.dataset.pictureManagerExpanded !== '1') {
            return;
        }
        if (!(event.target instanceof Node) || toolbar.contains(event.target)) {
            return;
        }
        setPanelExpanded(false);
        setStatus('Ready.', 'idle');
    }

    /**
     * Adds selected image IDs and shared request metadata to a FormData object.
     *
     * @param {FormData} formData Request body to populate.
     * @param {string[]} imageIds Selected image IDs.
     * @returns {void}
     */
    function appendBaseFormData(formData, imageIds) {
        formData.append('csrf_token', csrfToken);
        formData.append('source_gallery_id', sourceGalleryId);
        imageIds.forEach((imageId) => formData.append('image_ids[]', imageId));
    }

    /**
     * Sends a Picture manager POST request and parses its JSON response safely.
     *
     * @param {string} url Endpoint URL rendered by PHP.
     * @param {FormData} formData Request body.
     * @returns {Promise<object>} Parsed response payload.
     */
    async function postManagerAction(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new Error('The server returned HTML instead of JSON. Check the admin logs or PHP error log.');
        }
        const payload = await response.json();
        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || 'The action failed.');
        }
        return payload;
    }

    /**
     * Reloads the gallery page after a successful mutation.
     *
     * @param {object} payload JSON payload returned by the server.
     * @returns {void}
     */
    function reloadAfterSuccess(payload) {
        const refreshUrl = typeof payload.refresh_url === 'string' && payload.refresh_url !== '' ? payload.refresh_url : window.location.href;
        window.location.href = refreshUrl;
    }

    /**
     * Moves the current selection into a destination gallery.
     *
     * @param {string} destinationGalleryId Destination gallery ID.
     * @param {string[]|null} explicitImageIds Optional explicit dragged image IDs.
     * @returns {Promise<void>}
     */
    async function moveSelectedToGallery(destinationGalleryId, explicitImageIds = null) {
        const imageIds = Array.isArray(explicitImageIds) && explicitImageIds.length > 0
            ? explicitImageIds.map((imageId) => String(imageId)).filter((imageId) => imageId !== '')
            : selectedIdsInPageOrder();
        if (activeRequest) {
            return;
        }
        if (imageIds.length === 0) {
            setStatus('Select at least one photo first.', 'error');
            return;
        }
        if (!destinationGalleryId) {
            setStatus('Choose a destination gallery first.', 'error');
            return;
        }
        if (!moveUrl) {
            setStatus('Move endpoint is not configured.', 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds);
        formData.append('destination_gallery_id', destinationGalleryId);

        setRequestActive(true);
        setStatus(`Moving ${imageIds.length} selected photo${imageIds.length === 1 ? '' : 's'}...`, 'working');
        try {
            const payload = await postManagerAction(moveUrl, formData);
            setStatus(payload.message || 'Selected photos were moved.', 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Image move failed.', 'error');
            setRequestActive(false);
        }
    }

    /**
     * Copies the current selection into an existing destination gallery.
     *
     * @param {string} destinationGalleryId Destination gallery ID.
     * @returns {Promise<void>}
     */
    async function copySelectedToGallery(destinationGalleryId) {
        const imageIds = selectedIdsInPageOrder();
        if (activeRequest) {
            return;
        }
        if (imageIds.length === 0) {
            setStatus('Select at least one photo first.', 'error');
            return;
        }
        if (!destinationGalleryId) {
            setStatus('Choose a destination gallery first.', 'error');
            return;
        }
        if (!copyUrl) {
            setStatus('Copy endpoint is not configured.', 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds);
        formData.append('destination_gallery_id', destinationGalleryId);

        setRequestActive(true);
        setStatus(`Copying ${imageIds.length} selected photo${imageIds.length === 1 ? '' : 's'}...`, 'working');
        try {
            const payload = await postManagerAction(copyUrl, formData);
            setStatus(payload.message || 'Selected photos were copied.', 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Image copy failed.', 'error');
            setRequestActive(false);
        }
    }

    /**
     * Creates a child gallery by copying the current selection.
     *
     * @returns {Promise<void>}
     */
    async function createGalleryFromSelection() {
        const imageIds = selectedIdsInPageOrder();
        const title = newTitleInput instanceof HTMLInputElement ? newTitleInput.value.trim() : '';
        const folderName = newFolderInput instanceof HTMLInputElement ? newFolderInput.value.trim() : '';
        if (activeRequest) {
            return;
        }
        if (imageIds.length === 0) {
            setStatus('Select at least one photo first.', 'error');
            return;
        }
        if (title === '') {
            setStatus('Enter a title for the new gallery first.', 'error');
            return;
        }
        if (!createUrl) {
            setStatus('Create-gallery endpoint is not configured.', 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds);
        formData.append('new_gallery_title', title);
        formData.append('new_gallery_folder_name', folderName);

        setRequestActive(true);
        setStatus(`Copying ${imageIds.length} selected photo${imageIds.length === 1 ? '' : 's'} into the new gallery...`, 'working');
        try {
            const payload = await postManagerAction(createUrl, formData);
            setStatus(payload.message || 'Gallery created from selected photos.', 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Create gallery failed.', 'error');
            setRequestActive(false);
        }
    }

    /**
     * Returns visible subgallery cards that can accept selected photos.
     *
     * @returns {HTMLElement[]} Visible drop targets.
     */
    function dropTargets() {
        return publicPhotoDropTargets(sourceGalleryId);
    }


    /**
     * Returns the visible subgallery target under the current pointer position.
     *
     * Native HTML drag events often report a nested image, link, or badge as the
     * event target. A document-level hit test is more reliable because it walks
     * through the rendered stack and finds the owning subgallery card regardless
     * of which child element is under the pointer.
     *
     * @param {number} clientX Pointer X coordinate.
     * @param {number} clientY Pointer Y coordinate.
     * @returns {HTMLElement|null} Destination subgallery card, or null.
     */
    function dropTargetAt(clientX, clientY) {
        return publicPhotoDropTargetAtPoint(clientX, clientY, {sourceGalleryId});
    }

    /**
     * Updates the highlighted native drag destination.
     *
     * @param {HTMLElement|null} activeTarget Target that should be highlighted.
     * @returns {void}
     */
    function highlightDropTarget(activeTarget) {
        highlightPublicPhotoDropTarget(activeTarget, sourceGalleryId);
    }

    /**
     * Updates visible subgallery targets during drag sessions.
     *
     * @param {boolean} isActive Whether drag affordance should be shown.
     * @returns {void}
     */
    function setDropTargetsActive(isActive) {
        setPublicPhotoDropTargetsActive(sourceGalleryId, isActive);
    }

    /**
     * Starts native desktop dragging for selected picture cards.
     *
     * @param {DragEvent} event Drag event from an image card.
     * @returns {void}
     */
    function handleDragStart(event) {
        if (activeRequest || !(event.currentTarget instanceof HTMLElement)) {
            event.preventDefault();
            return;
        }
        const card = event.currentTarget;
        const imageId = cardImageId(card);
        if (imageId === '') {
            event.preventDefault();
            return;
        }
        if (!selectedIds.has(imageId)) {
            replaceSelection([card], card);
        }
        dragSelection = selectedIdsInPageOrder();
        if (dragSelection.length === 0 || !event.dataTransfer) {
            event.preventDefault();
            return;
        }
        writePublicPhotoImageIdsToDataTransfer(event.dataTransfer, dragSelection);
        document.body.classList.add('picture-manager-drag-active');
        setDropTargetsActive(true);
        setStatus(`Dragging ${dragSelection.length} selected photo${dragSelection.length === 1 ? '' : 's'}. Drop onto a subgallery.`, 'working');
    }

    /**
     * Ends a native drag session and removes transient visual state.
     *
     * @returns {void}
     */
    function handleDragEnd() {
        dragSelection = [];
        document.body.classList.remove('picture-manager-drag-active');
        setDropTargetsActive(false);
        if (!activeRequest) {
            setStatus(selectedIds.size > 0 ? 'Selection ready.' : 'Ready.', 'idle');
        }
    }

    /**
     * Handles dragover on visible subgallery cards.
     *
     * @param {DragEvent} event Drag event from a drop target.
     * @returns {void}
     */
    function handleTargetDragOver(event) {
        if (dragSelection.length === 0 || activeRequest) {
            return;
        }
        const target = dropTargetAt(event.clientX, event.clientY)
            || (event.currentTarget instanceof HTMLElement ? event.currentTarget : null);
        if (!(target instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
        highlightDropTarget(target);
    }

    /**
     * Handles dragleave on visible subgallery cards.
     *
     * @param {DragEvent} event Drag event from a drop target.
     * @returns {void}
     */
    function handleTargetDragLeave() {
        highlightDropTarget(null);
    }

    /**
     * Moves the dragged selection when dropped onto a valid subgallery target.
     *
     * @param {DragEvent} event Drop event from a subgallery card.
     * @returns {void}
     */
    function handleTargetDrop(event) {
        const target = dropTargetAt(event.clientX, event.clientY)
            || (event.currentTarget instanceof HTMLElement ? event.currentTarget : null);
        if (!(target instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        highlightDropTarget(null);
        const destinationGalleryId = publicPhotoDropTargetGalleryId(target);
        if (destinationGalleryId === '' || destinationGalleryId === sourceGalleryId) {
            setStatus('Selected photos cannot be moved to this target.', 'error');
            return;
        }
        const explicitImageIds = publicPhotoImageIdsFromDataTransfer(event.dataTransfer || null);
        moveSelectedToGallery(destinationGalleryId, explicitImageIds.length > 0 ? explicitImageIds : null);
    }

    /**
     * Moves the current selected photos to a subgallery chosen by another module.
     *
     * Public photo reordering uses pointer events instead of native HTML drag events.
     * This listener gives that drag path the same destination-gallery behavior as
     * the native Picture manager card drag path.
     *
     * @param {CustomEvent} event Drop request emitted by public photo reordering.
     * @returns {void}
     */
    function handleExternalDropMove(event) {
        const detail = event.detail || {};
        const destinationGalleryId = typeof detail.destinationGalleryId === 'string' ? detail.destinationGalleryId : '';
        const explicitImageIds = Array.isArray(detail.imageIds)
            ? detail.imageIds.map((imageId) => String(imageId)).filter((imageId) => imageId !== '')
            : null;
        if (destinationGalleryId === '') {
            setStatus('Selected photos cannot be moved to this target.', 'error');
            return;
        }
        moveSelectedToGallery(destinationGalleryId, explicitImageIds);
    }

    /**
     * Handles native drag movement at document level so nested anchors and images
     * inside subgallery cards cannot swallow the dragover event.
     *
     * @param {DragEvent} event Dragover event captured from the document.
     * @returns {void}
     */
    function handleDocumentDragOver(event) {
        if (dragSelection.length === 0 || activeRequest) {
            return;
        }
        const target = dropTargetAt(event.clientX, event.clientY);
        highlightDropTarget(target);
        if (!(target instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
    }

    /**
     * Handles native drops at document level. This is the safety net that makes
     * dropping over any visible part of a subgallery card behave consistently.
     *
     * @param {DragEvent} event Drop event captured from the document.
     * @returns {void}
     */
    function handleDocumentDrop(event) {
        if (dragSelection.length === 0 || activeRequest) {
            return;
        }
        const target = dropTargetAt(event.clientX, event.clientY);
        if (!(target instanceof HTMLElement)) {
            return;
        }
        handleTargetDrop(event);
    }

    /**
     * Enables Ctrl/Cmd+A selection when focus is not inside an editor field.
     *
     * @param {KeyboardEvent} event Keyboard event from the document.
     * @returns {void}
     */
    function handleDocumentKeyDown(event) {
        if (event.key === 'Escape' && selectedIds.size === 0 && toolbar.dataset.pictureManagerExpanded === '1') {
            setPanelExpanded(false);
            setStatus('Ready.', 'idle');
            return;
        }
        if ((!event.ctrlKey && !event.metaKey) || event.key.toLowerCase() !== 'a') {
            return;
        }
        const target = event.target;
        if (target instanceof Element && target.closest('input, textarea, select, [contenteditable="true"]')) {
            return;
        }
        event.preventDefault();
        selectAllVisible();
    }

    cards.forEach((card) => {
        const selectButton = card.querySelector('[data-picture-manager-select]');
        if (selectButton instanceof HTMLButtonElement) {
            selectButton.addEventListener('click', handleSelectButtonClick, signalOptions);
        }
        card.addEventListener('click', handleCardClick, signalOptions);
        card.addEventListener('dragstart', handleDragStart, signalOptions);
        card.addEventListener('dragend', handleDragEnd, signalOptions);
    });

    dropTargets().forEach((target) => {
        target.classList.add('picture-manager-drop-target');
        target.addEventListener('dragover', handleTargetDragOver, signalOptions);
        target.addEventListener('dragleave', handleTargetDragLeave, signalOptions);
        target.addEventListener('drop', handleTargetDrop, signalOptions);
    });

    if (toggleButton instanceof HTMLButtonElement) {
        toggleButton.addEventListener('click', () => {
            setPanelExpanded(toolbar.classList.contains('is-picture-manager-collapsed'));
        }, signalOptions);
    }
    if (selectAllButton instanceof HTMLButtonElement) {
        selectAllButton.addEventListener('click', selectAllVisible, signalOptions);
    }
    if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', clearSelection, signalOptions);
    }
    if (destinationInput instanceof HTMLInputElement) {
        destinationInput.addEventListener('change', updateActionButtons, signalOptions);
        destinationInput.addEventListener('input', updateActionButtons, signalOptions);
    }
    if (moveButton instanceof HTMLButtonElement) {
        moveButton.addEventListener('click', () => {
            const destinationGalleryId = destinationInput instanceof HTMLInputElement ? destinationInput.value : '';
            moveSelectedToGallery(destinationGalleryId);
        }, signalOptions);
    }
    if (copyButton instanceof HTMLButtonElement) {
        copyButton.addEventListener('click', () => {
            const destinationGalleryId = destinationInput instanceof HTMLInputElement ? destinationInput.value : '';
            copySelectedToGallery(destinationGalleryId);
        }, signalOptions);
    }
    if (newTitleInput instanceof HTMLInputElement) {
        newTitleInput.addEventListener('input', updateActionButtons, signalOptions);
    }
    if (createButton instanceof HTMLButtonElement) {
        createButton.addEventListener('click', createGalleryFromSelection, signalOptions);
    }
    document.addEventListener('keydown', handleDocumentKeyDown, signalOptions);
    document.addEventListener('pointerdown', handleDocumentPointerDown, signalOptions);
    document.addEventListener('dragover', handleDocumentDragOver, {capture: true, signal: eventController.signal});
    document.addEventListener('drop', handleDocumentDrop, {capture: true, signal: eventController.signal});
    document.addEventListener(PUBLIC_PHOTO_MOVE_EVENT, handleExternalDropMove, signalOptions);

    setPanelExpanded(false);
    syncSelectionState();

    activePictureManager = {
        toolbar,
        teardown() {
            eventController.abort();
            dragSelection = [];
            document.body.classList.remove('picture-manager-drag-active');
            setDropTargetsActive(false);
            highlightDropTarget(null);
            if (toolbar.dataset.pictureManagerExpanded) {
                delete toolbar.dataset.pictureManagerExpanded;
            }
        },
    };
}
