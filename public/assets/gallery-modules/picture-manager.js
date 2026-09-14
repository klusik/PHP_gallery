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
 *   - Attach a visible file-manager-style selection model to public gallery photos and physical subgalleries
 *   - Support Shift-click range selection and Ctrl/Cmd-click item toggling
 *   - Move selected photos through the existing server-side move endpoint
 *   - Copy selected photos through the shared server-side copy endpoint
 *   - Delete mixed selections through the existing filesystem-backed mutation services
 *   - Create a physical gallery from selected photos and subgallery trees at a chosen parent
 *   - Keep desktop drag-and-drop progressive, with toolbar actions as the fallback
 *   - Share selected browser-displayable photos through the native share sheet when available
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
 *   2026-09-14
 */

import { PUBLIC_PHOTO_MOVE_EVENT, highlightPublicPhotoDropTarget, publicPhotoDropTargetAtPoint, publicPhotoDropTargetGalleryId, publicPhotoDropTargets, publicPhotoImageIdsFromDataTransfer, setPublicPhotoDropTargetsActive, writePublicPhotoImageIdsToDataTransfer } from './public-photo-drop-actions.js?v=20260519-public-photo-drop-v1';
import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

// activePictureManager stores the currently bound toolbar instance so fragment
// refreshes can safely replace the toolbar and bind a fresh one.
let activePictureManager = null;

/**
 * Release the currently bound Picture manager instance, if any.
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
 * @return {void} Result value for the caller.
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

    // cards stores selectable direct photos and physical subgallery cards in document order.
    const cards = Array.from(document.querySelectorAll('[data-picture-manager-image], [data-picture-manager-gallery]'))
        .filter((card) => card instanceof HTMLElement);
    // imageCards stores the photo subset used by move, copy, share, and desktop drag behavior.
    const imageCards = cards.filter((card) => card.hasAttribute('data-picture-manager-image'));
    // galleryCards stores selected physical direct subgalleries used by delete and create-from-selection.
    const galleryCards = cards.filter((card) => card.hasAttribute('data-picture-manager-gallery'));
    if (cards.length === 0) {
        activePictureManager = {
            toolbar,
            /** Clear the empty-state manager's expanded toolbar marker. */
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

    // selectedKeys stores typed item keys so image and gallery IDs cannot collide.
    const selectedKeys = new Set();
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
    // deleteUrl stores the JSON endpoint used for deleting selected physical items.
    const deleteUrl = toolbar.dataset.deleteUrl || '';
    // downloadUrl stores the fallback endpoint that returns a ZIP of selected share candidates.
    const downloadUrl = toolbar.dataset.downloadUrl || '';

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
    // shareButton stores the native device share-sheet action.
    const shareButton = toolbar.querySelector('[data-picture-manager-share]');
    // deleteButton stores the destructive mixed-selection action.
    const deleteButton = toolbar.querySelector('[data-picture-manager-delete]');
    // destinationInput stores the shared searchable gallery picker submitted value.
    const destinationInput = toolbar.querySelector('[data-picture-manager-destination]');
    // moveButton stores the explicit move action.
    const moveButton = toolbar.querySelector('[data-picture-manager-move]');
    // copyButton stores the explicit copy action.
    const copyButton = toolbar.querySelector('[data-picture-manager-copy]');
    // newParentInput stores the committed physical parent gallery for create-from-selection.
    const newParentInput = toolbar.querySelector('[data-picture-manager-new-parent]');
    // newTitleInput stores the new physical gallery title.
    const newTitleInput = toolbar.querySelector('[data-picture-manager-new-title]');
    // newFolderInput stores the optional child gallery folder name.
    const newFolderInput = toolbar.querySelector('[data-picture-manager-new-folder]');
    // createButton stores the explicit create-gallery-from-selection action.
    const createButton = toolbar.querySelector('[data-picture-manager-create]');

        /**
     * Expands or collapses the Picture manager action panel.
     *
     * @param {boolean} expanded Whether the detailed controls should be visible.
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
     */
    function expandForSelection() {
        if (selectedKeys.size > 0) {
            setPanelExpanded(true);
        }
    }

        /**
     * Returns a stable image ID from a visible card.
     *
     * @param {Element|null} card Card element rendered for one image.
     * @return {string} Image ID or an empty string when unavailable.
     */
    function cardImageId(card) {
        if (!(card instanceof HTMLElement)) {
            return '';
        }
        return card.dataset.pictureManagerImageId || '';
    }

        /**
     * Returns a stable physical gallery ID from a visible subgallery card.
     *
     * @param {Element|null} card Card element rendered for one physical gallery.
     * @return {string} Gallery ID or an empty string when unavailable.
     */
    function cardGalleryId(card) {
        if (!(card instanceof HTMLElement)) {
            return '';
        }
        return card.dataset.pictureManagerGalleryId || '';
    }

        /**
     * Returns the typed key used by the mixed selection set.
     *
     * @param {Element|null} card Selectable photo or physical gallery card.
     * @return {string} Typed selection key or an empty string.
     */
    function cardSelectionKey(card) {
        const imageId = cardImageId(card);
        if (imageId !== '') {
            return `image:${imageId}`;
        }
        const galleryId = cardGalleryId(card);
        return galleryId !== '' ? `gallery:${galleryId}` : '';
    }

        /**
     * Returns the current card index inside the visible page.
     *
     * @param {Element|null} card Card element rendered for one image.
     * @return {number} Zero-based visible index, or -1 when unavailable.
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
     * @return {string[]} Selected image IDs in visual order.
     */
    function selectedIdsInPageOrder() {
        return imageCards
            .map((card) => cardImageId(card))
            .filter((imageId) => imageId !== '' && selectedKeys.has(`image:${imageId}`));
    }

        /**
     * Returns selected physical gallery IDs in visible page order.
     *
     * @return {string[]} Selected gallery IDs in visual order.
     */
    function selectedGalleryIdsInPageOrder() {
        return galleryCards
            .map((card) => cardGalleryId(card))
            .filter((galleryId) => galleryId !== '' && selectedKeys.has(`gallery:${galleryId}`));
    }

        /**
     * Returns selected photo cards in the same order as the visible gallery page.
     *
     * @return {HTMLElement[]} Selected visible image cards.
     */
    function selectedCardsInPageOrder() {
        return imageCards.filter((card) => selectedKeys.has(cardSelectionKey(card)));
    }

        /**
     * Applies visual selected state to one card and its check button.
     *
     * @param {HTMLElement} card Visible image card.
     */
    function syncCardState(card) {
        const selectionKey = cardSelectionKey(card);
        const isSelected = selectionKey !== '' && selectedKeys.has(selectionKey);
        const isGallery = cardGalleryId(card) !== '';
        const button = card.querySelector('[data-picture-manager-select]');
        card.classList.toggle('is-picture-manager-selected', isSelected);
        card.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        // Only managed photos are native drag sources. Physical gallery cards remain drop targets.
        card.draggable = !isGallery;
        if (button instanceof HTMLButtonElement) {
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            button.title = isGallery
                ? (isSelected ? i18n('picture_manager.deselect_gallery', 'Deselect gallery') : i18n('picture_manager.select_gallery', 'Select gallery'))
                : (isSelected ? i18n('picture_manager.deselect_photo', 'Deselect photo') : i18n('picture_manager.select_photo', 'Select photo'));
        }
    }

        /**
     * Synchronizes toolbar controls and visible card state after any selection change.
     */
    function syncSelectionState() {
        cards.forEach(syncCardState);
        const count = selectedKeys.size;
        if (countLabel instanceof HTMLElement) {
            countLabel.textContent = count === 0 ? i18n('picture_manager.no_items_selected', 'No items selected.') : (count === 1 ? i18n('picture_manager.one_item_selected', '1 item selected.') : i18n('picture_manager.many_items_selected', '{count} items selected.', {count}));
        }
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.disabled = count === 0;
        }
        updateActionButtons();
        expandForSelection();
    }

        /**
     * Enables or disables mutation buttons based on current form state.
     */
    function updateActionButtons() {
        const hasSelection = selectedKeys.size > 0;
        const hasSelectedGalleries = selectedGalleryIdsInPageOrder().length > 0;
        const hasSelectedImages = selectedIdsInPageOrder().length > 0;
        const hasDestination = destinationInput instanceof HTMLInputElement && destinationInput.value !== '';
        const hasNewParent = newParentInput instanceof HTMLInputElement && newParentInput.value !== '';
        const hasTitle = newTitleInput instanceof HTMLInputElement && newTitleInput.value.trim() !== '';
        if (moveButton instanceof HTMLButtonElement) {
            moveButton.disabled = activeRequest || !hasSelectedImages || hasSelectedGalleries || !hasDestination;
        }
        if (copyButton instanceof HTMLButtonElement) {
            copyButton.disabled = activeRequest || !hasSelectedImages || hasSelectedGalleries || !hasDestination;
        }
        if (createButton instanceof HTMLButtonElement) {
            createButton.disabled = activeRequest || !hasSelection || !hasTitle || !hasNewParent;
        }
        if (shareButton instanceof HTMLButtonElement) {
            shareButton.disabled = activeRequest || !hasSelectedImages || hasSelectedGalleries;
        }
        if (deleteButton instanceof HTMLButtonElement) {
            deleteButton.disabled = activeRequest || !hasSelection;
        }
        if (selectAllButton instanceof HTMLButtonElement) {
            selectAllButton.disabled = activeRequest;
        }
    }

        /**
     * Sets active state for all server-side mutation controls.
     *
     * @param {boolean} isActive Whether a request is running.
     */
    function setRequestActive(isActive) {
        activeRequest = isActive;
        if (destinationInput instanceof HTMLInputElement) {
            destinationInput.disabled = isActive;
        }
        if (newParentInput instanceof HTMLInputElement) {
            newParentInput.disabled = isActive;
        }
        if (newTitleInput instanceof HTMLInputElement) {
            newTitleInput.disabled = isActive;
        }
        if (newFolderInput instanceof HTMLInputElement) {
            newFolderInput.disabled = isActive;
        }
        if (clearButton instanceof HTMLButtonElement) {
            clearButton.disabled = isActive || selectedKeys.size === 0;
        }
        updateActionButtons();
    }

        /**
     * Return a conservative file extension for a fetched share Blob MIME type.
     *
     * @param {string} mimeType Response Blob MIME type.
     * @return {string} Extension without dot.
     */
    function extensionForMimeType(mimeType) {
        const normalized = String(mimeType || '').toLowerCase();
        if (normalized.includes('jpeg') || normalized.includes('jpg')) {
            return 'jpg';
        }
        if (normalized.includes('png')) {
            return 'png';
        }
        if (normalized.includes('gif')) {
            return 'gif';
        }
        if (normalized.includes('webp')) {
            return 'webp';
        }
        return 'jpg';
    }

        /**
     * Return a filesystem-safe name suitable for a File object sent to mobile apps.
     *
     * @param {string} requestedName Name rendered by PHP for the selected card.
     * @param {string} mimeType Response Blob MIME type.
     * @param {number} index One-based fallback sequence number.
     * @return {string} Safe filename with extension.
     */
    function shareFilename(requestedName, mimeType, index) {
        const fallback = `photo-${index}`;
        const cleaned = String(requestedName || '')
            .trim()
            .replace(/[\\/:*?"<>|]+/g, '-')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^[-.]+|[-.]+$/g, '');
        const candidate = cleaned || fallback;
        if (/\.[a-z0-9]{2,5}$/i.test(candidate)) {
            return candidate;
        }
        return `${candidate}.${extensionForMimeType(mimeType)}`;
    }

        /**
     * Fetch one selected image as a File for the native Web Share API.
     *
     * @param {HTMLElement} card Selected image card.
     * @param {number} index One-based selected photo number.
     * @return {Promise<File>} Browser File object ready for navigator.share().
     */
    async function fetchShareFile(card, index) {
        const shareUrl = card.dataset.pictureManagerShareUrl || card.dataset.previewSrc || card.dataset.fullSrc || '';
        if (shareUrl === '') {
            throw new Error(i18n('picture_manager.share_url_missing', 'A selected photo does not have a shareable media URL.'));
        }
        const response = await fetch(shareUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            throw new Error(`Photo ${index} could not be loaded for sharing.`);
        }
        const blob = await response.blob();
        const mimeType = blob.type || response.headers.get('content-type') || 'image/jpeg';
        if (!String(mimeType).toLowerCase().startsWith('image/')) {
            throw new Error(`Photo ${index} is not a browser-shareable image.`);
        }
        return new File([blob], shareFilename(card.dataset.pictureManagerShareFilename || card.dataset.pictureManagerShareTitle || '', mimeType, index), {
            type: mimeType,
            lastModified: Date.now(),
        });
    }

        /**
     * Submit the selected photo IDs to the ZIP fallback endpoint without leaving the page.
     *
     * @param {string[]} imageIds Selected image IDs.
     */
    function downloadSelectionFallback(imageIds) {
        if (!downloadUrl) {
            setStatus(i18n('picture_manager.share_unavailable_no_zip', 'Native sharing is not available and the ZIP fallback is not configured.'), 'error');
            return;
        }
        if (imageIds.length === 0) {
            setStatus(i18n('picture_manager.select_photo_first', 'Select at least one photo first.'), 'error');
            return;
        }
        const frameName = 'picture-manager-download-frame';
        let frame = document.querySelector(`iframe[name="${frameName}"]`);
        if (!(frame instanceof HTMLIFrameElement)) {
            frame = document.createElement('iframe');
            frame.name = frameName;
            frame.hidden = true;
            frame.setAttribute('aria-hidden', 'true');
            document.body.appendChild(frame);
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = downloadUrl;
        form.target = frameName;
        form.hidden = true;

        /**
         * Handle add field.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {string} name Name value.
         * @param {*} value Value to process.
         */
        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(value);
            form.appendChild(input);
        };
        addField('csrf_token', csrfToken);
        addField('source_gallery_id', sourceGalleryId);
        imageIds.forEach((imageId) => addField('image_ids[]', imageId));
        document.body.appendChild(form);
        form.submit();
        form.remove();
        setStatus(i18n('picture_manager.share_zip_started', 'Native sharing is not available here. A ZIP download was started with the selected photos.'), 'ok');
    }

        /**
     * Shares selected photos through the native device share sheet when possible.
     *
     * Browser code cannot force Instagram Story or Reel as the target. The share
     * sheet decides which installed apps can receive multiple images.
     */
    async function shareSelectedPhotos() {
        const imageIds = selectedIdsInPageOrder();
        const selectedCards = selectedCardsInPageOrder();
        if (activeRequest) {
            return;
        }
        if (selectedCards.length === 0 || imageIds.length === 0) {
            setStatus(i18n('picture_manager.select_photo_first', 'Select at least one photo first.'), 'error');
            return;
        }
        if (typeof File !== 'function' || !navigator.share) {
            downloadSelectionFallback(imageIds);
            return;
        }

        setRequestActive(true);
        setStatus(i18n('picture_manager.share_preparing', 'Preparing {count} selected photo(s) for sharing...', {count: selectedCards.length}), 'working');
        try {
            const files = [];
            for (let index = 0; index < selectedCards.length; index += 1) {
                files.push(await fetchShareFile(selectedCards[index], index + 1));
            }
            const payload = {files};
            if (navigator.canShare && !navigator.canShare(payload)) {
                setRequestActive(false);
                downloadSelectionFallback(imageIds);
                return;
            }
            await navigator.share(payload);
            setStatus(i18n('picture_manager.share_opened', 'Share sheet opened. Finish the Instagram Story, Reel, or another share target in the receiving app.'), 'ok');
            setRequestActive(false);
        } catch (error) {
            setRequestActive(false);
            const name = typeof DOMException !== 'undefined' && error instanceof DOMException ? error.name : '';
            if (name === 'AbortError') {
                setStatus(i18n('picture_manager.share_cancelled', 'Sharing was cancelled.'), 'idle');
                return;
            }
            setStatus(error instanceof Error ? i18n('picture_manager.share_failed_with_error_zip', '{error} Downloading ZIP fallback.', {error: error.message}) : i18n('picture_manager.share_failed_zip', 'Sharing failed. Downloading ZIP fallback.'), 'error');
            downloadSelectionFallback(imageIds);
        }
    }

        /**
     * Selects exactly the provided cards and clears all other visible selections.
     *
     * @param {HTMLElement[]} nextCards Cards that should be selected.
     * @param {HTMLElement|null} nextAnchor Card that becomes the new range anchor.
     */
    function replaceSelection(nextCards, nextAnchor) {
        selectedKeys.clear();
        nextCards.forEach((card) => {
            const selectionKey = cardSelectionKey(card);
            if (selectionKey !== '') {
                selectedKeys.add(selectionKey);
            }
        });
        anchorCard = nextAnchor;
        syncSelectionState();
    }

        /**
     * Selects all visible photos on the current page.
     */
    function selectAllVisible() {
        replaceSelection(cards, cards[0] || null);
        setStatus(i18n('picture_manager.selected_all_visible', 'Selected all {count} visible item(s).', {count: cards.length}), 'ok');
    }

        /**
     * Clears every visible selected photo.
     */
    function clearSelection() {
        selectedKeys.clear();
        anchorCard = null;
        syncSelectionState();
        setPanelExpanded(false);
        setStatus(i18n('picture_manager.selection_cleared', 'Selection cleared.'), 'idle');
    }

        /**
     * Toggles one card in the selected set.
     *
     * @param {HTMLElement} card Card to toggle.
     */
    function toggleCard(card) {
        const selectionKey = cardSelectionKey(card);
        if (selectionKey === '') {
            return;
        }
        if (selectedKeys.has(selectionKey)) {
            selectedKeys.delete(selectionKey);
        } else {
            selectedKeys.add(selectionKey);
        }
        anchorCard = card;
        syncSelectionState();
    }

        /**
     * Selects an inclusive range from the anchor card to the target card.
     *
     * @param {HTMLElement} targetCard Card at the end of the range.
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
            const selectionKey = cardSelectionKey(card);
            if (selectionKey !== '') {
                selectedKeys.add(selectionKey);
            }
        });
        syncSelectionState();
    }

        /**
     * Handles a click on a visible selection check button.
     *
     * @param {MouseEvent} event Click event from the check button.
     */
    function handleSelectButtonClick(event) {
        const button = event.currentTarget;
        const card = button instanceof HTMLElement ? button.closest('[data-picture-manager-image], [data-picture-manager-gallery]') : null;
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
     */
    function handleDocumentPointerDown(event) {
        if (selectedKeys.size > 0 || toolbar.dataset.pictureManagerExpanded !== '1') {
            return;
        }
        if (!(event.target instanceof Node) || toolbar.contains(event.target)) {
            return;
        }
        setPanelExpanded(false);
        setStatus(i18n('picture_manager.ready', 'Ready.'), 'idle');
    }

        /**
     * Adds selected image IDs and shared request metadata to a FormData object.
     *
     * @param {FormData} formData Request body to populate.
     * @param {string[]} imageIds Selected image IDs.
     */
    function appendBaseFormData(formData, imageIds, galleryIds = []) {
        formData.append('csrf_token', csrfToken);
        formData.append('source_gallery_id', sourceGalleryId);
        imageIds.forEach((imageId) => formData.append('image_ids[]', imageId));
        galleryIds.forEach((galleryId) => formData.append('gallery_ids[]', galleryId));
    }

        /**
     * Sends a Picture manager POST request and parses its JSON response safely.
     *
     * @param {string} url Endpoint URL rendered by PHP.
     * @param {FormData} formData Request body.
     * @return {Promise<object>} Parsed response payload.
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
            throw new Error(i18n('picture_manager.html_instead_json', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.'));
        }
        const payload = await response.json();
        if (!response.ok || payload.ok === false) {
            throw new Error(payload.message || i18n('picture_manager.action_failed', 'The action failed.'));
        }
        return payload;
    }

        /**
     * Reloads the gallery page after a successful mutation.
     *
     * @param {object} payload JSON payload returned by the server.
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
     */
    async function moveSelectedToGallery(destinationGalleryId, explicitImageIds = null) {
        const imageIds = Array.isArray(explicitImageIds) && explicitImageIds.length > 0
            ? explicitImageIds.map((imageId) => String(imageId)).filter((imageId) => imageId !== '')
            : selectedIdsInPageOrder();
        if (activeRequest) {
            return;
        }
        if (imageIds.length === 0) {
            setStatus(i18n('picture_manager.select_photo_first', 'Select at least one photo first.'), 'error');
            return;
        }
        if (!Array.isArray(explicitImageIds) && selectedGalleryIdsInPageOrder().length > 0) {
            setStatus(i18n('picture_manager.move_copy_photos_only', 'Move and copy currently apply only to selected photos. Clear selected galleries first.'), 'error');
            return;
        }
        if (!destinationGalleryId) {
            setStatus(i18n('picture_manager.choose_destination_first', 'Choose a destination gallery first.'), 'error');
            return;
        }
        if (!moveUrl) {
            setStatus(i18n('picture_manager.move_endpoint_missing', 'Move endpoint is not configured.'), 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds);
        formData.append('destination_gallery_id', destinationGalleryId);

        setRequestActive(true);
        setStatus(i18n('picture_manager.moving_selected', 'Moving {count} selected photo(s)...', {count: imageIds.length}), 'working');
        try {
            const payload = await postManagerAction(moveUrl, formData);
            setStatus(payload.message || i18n('picture_manager.move_complete', 'Selected photos were moved.'), 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : i18n('picture_manager.move_failed', 'Image move failed.'), 'error');
            setRequestActive(false);
        }
    }

        /**
     * Copies the current selection into an existing destination gallery.
     *
     * @param {string} destinationGalleryId Destination gallery ID.
     */
    async function copySelectedToGallery(destinationGalleryId) {
        const imageIds = selectedIdsInPageOrder();
        if (activeRequest) {
            return;
        }
        if (imageIds.length === 0) {
            setStatus(i18n('picture_manager.select_photo_first', 'Select at least one photo first.'), 'error');
            return;
        }
        if (selectedGalleryIdsInPageOrder().length > 0) {
            setStatus(i18n('picture_manager.move_copy_photos_only', 'Move and copy currently apply only to selected photos. Clear selected galleries first.'), 'error');
            return;
        }
        if (!destinationGalleryId) {
            setStatus(i18n('picture_manager.choose_destination_first', 'Choose a destination gallery first.'), 'error');
            return;
        }
        if (!copyUrl) {
            setStatus(i18n('picture_manager.copy_endpoint_missing', 'Copy endpoint is not configured.'), 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds);
        formData.append('destination_gallery_id', destinationGalleryId);

        setRequestActive(true);
        setStatus(i18n('picture_manager.copying_selected', 'Copying {count} selected photo(s)...', {count: imageIds.length}), 'working');
        try {
            const payload = await postManagerAction(copyUrl, formData);
            setStatus(payload.message || i18n('picture_manager.copy_complete', 'Selected photos were copied.'), 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : i18n('picture_manager.copy_failed', 'Image copy failed.'), 'error');
            setRequestActive(false);
        }
    }

        /**
     * Creates a child gallery by copying the current selection.
     */
    async function createGalleryFromSelection() {
        const imageIds = selectedIdsInPageOrder();
        const galleryIds = selectedGalleryIdsInPageOrder();
        const parentGalleryId = newParentInput instanceof HTMLInputElement ? newParentInput.value : '';
        const title = newTitleInput instanceof HTMLInputElement ? newTitleInput.value.trim() : '';
        const folderName = newFolderInput instanceof HTMLInputElement ? newFolderInput.value.trim() : '';
        const selectedCount = imageIds.length + galleryIds.length;
        if (activeRequest) {
            return;
        }
        if (selectedCount === 0) {
            setStatus(i18n('picture_manager.select_item_first', 'Select at least one photo or physical gallery first.'), 'error');
            return;
        }
        if (!parentGalleryId) {
            setStatus(i18n('picture_manager.choose_parent_first', 'Choose the parent gallery for the new gallery first.'), 'error');
            return;
        }
        if (title === '') {
            setStatus(i18n('picture_manager.enter_new_gallery_title', 'Enter a title for the new gallery first.'), 'error');
            return;
        }
        if (!createUrl) {
            setStatus(i18n('picture_manager.create_endpoint_missing', 'Create-gallery endpoint is not configured.'), 'error');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds, galleryIds);
        formData.append('new_gallery_parent_id', parentGalleryId);
        formData.append('new_gallery_title', title);
        formData.append('new_gallery_folder_name', folderName);

        setRequestActive(true);
        setStatus(i18n('picture_manager.copying_items_into_new_gallery', 'Copying {count} selected item(s) into the new physical gallery...', {count: selectedCount}), 'working');
        try {
            const payload = await postManagerAction(createUrl, formData);
            setStatus(payload.message || i18n('picture_manager.create_complete', 'Physical gallery created from the selection.'), 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : i18n('picture_manager.create_failed', 'Create gallery failed.'), 'error');
            setRequestActive(false);
        }
    }

        /**
     * Deletes the current mixed selection after one explicit destructive confirmation.
     *
     * Physical galleries follow the configured server-side trash policy. The browser
     * intentionally does not try to predict whether trash or permanent deletion is active.
     */
    async function deleteSelectedItems() {
        const imageIds = selectedIdsInPageOrder();
        const galleryIds = selectedGalleryIdsInPageOrder();
        const selectedCount = imageIds.length + galleryIds.length;
        if (activeRequest) {
            return;
        }
        if (selectedCount === 0) {
            setStatus(i18n('picture_manager.select_item_first', 'Select at least one photo or physical gallery first.'), 'error');
            return;
        }
        if (!deleteUrl) {
            setStatus(i18n('picture_manager.delete_endpoint_missing', 'Delete endpoint is not configured.'), 'error');
            return;
        }
        const confirmation = i18n(
            'picture_manager.delete_confirm',
            'Delete {photos} selected photo(s) and {galleries} selected physical gallery tree(s)? Gallery trash policy will be applied when enabled.',
            {photos: imageIds.length, galleries: galleryIds.length},
        );
        if (!window.confirm(confirmation)) {
            setStatus(i18n('picture_manager.delete_cancelled', 'Delete cancelled.'), 'idle');
            return;
        }

        const formData = new FormData();
        appendBaseFormData(formData, imageIds, galleryIds);
        setRequestActive(true);
        setStatus(i18n('picture_manager.deleting_selected', 'Deleting {count} selected item(s)...', {count: selectedCount}), 'working');
        try {
            const payload = await postManagerAction(deleteUrl, formData);
            setStatus(payload.message || i18n('picture_manager.delete_complete', 'Selected items were deleted.'), 'ok');
            reloadAfterSuccess(payload);
        } catch (error) {
            setStatus(error instanceof Error ? error.message : i18n('picture_manager.delete_failed', 'Delete selection failed.'), 'error');
            setRequestActive(false);
        }
    }

        /**
     * Returns visible subgallery cards that can accept selected photos.
     *
     * @return {HTMLElement[]} Visible drop targets.
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
     * @return {HTMLElement|null} Destination subgallery card, or null.
     */
    function dropTargetAt(clientX, clientY) {
        return publicPhotoDropTargetAtPoint(clientX, clientY, {sourceGalleryId});
    }

        /**
     * Updates the highlighted native drag destination.
     *
     * @param {HTMLElement|null} activeTarget Target that should be highlighted.
     */
    function highlightDropTarget(activeTarget) {
        highlightPublicPhotoDropTarget(activeTarget, sourceGalleryId);
    }

        /**
     * Updates visible subgallery targets during drag sessions.
     *
     * @param {boolean} isActive Whether drag affordance should be shown.
     */
    function setDropTargetsActive(isActive) {
        setPublicPhotoDropTargetsActive(sourceGalleryId, isActive);
    }

        /**
     * Starts native desktop dragging for selected picture cards.
     *
     * @param {DragEvent} event Drag event from an image card.
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
        if (!selectedKeys.has(`image:${imageId}`)) {
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
        setStatus(i18n('picture_manager.dragging_selected', 'Dragging {count} selected photo(s). Drop onto a subgallery.', {count: dragSelection.length}), 'working');
    }

        /**
     * Ends a native drag session and removes transient visual state.
     */
    function handleDragEnd() {
        dragSelection = [];
        document.body.classList.remove('picture-manager-drag-active');
        setDropTargetsActive(false);
        if (!activeRequest) {
            setStatus(selectedKeys.size > 0 ? i18n('picture_manager.selection_ready', 'Selection ready.') : i18n('picture_manager.ready', 'Ready.'), 'idle');
        }
    }

        /**
     * Handles dragover on visible subgallery cards.
     *
     * @param {DragEvent} event Drag event from a drop target.
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
     */
    function handleTargetDragLeave() {
        highlightDropTarget(null);
    }

        /**
     * Moves the dragged selection when dropped onto a valid subgallery target.
     *
     * @param {DragEvent} event Drop event from a subgallery card.
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
            setStatus(i18n('picture_manager.invalid_move_target', 'Selected photos cannot be moved to this target.'), 'error');
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
     */
    function handleExternalDropMove(event) {
        const detail = event.detail || {};
        const destinationGalleryId = typeof detail.destinationGalleryId === 'string' ? detail.destinationGalleryId : '';
        const explicitImageIds = Array.isArray(detail.imageIds)
            ? detail.imageIds.map((imageId) => String(imageId)).filter((imageId) => imageId !== '')
            : null;
        if (destinationGalleryId === '') {
            setStatus(i18n('picture_manager.invalid_move_target', 'Selected photos cannot be moved to this target.'), 'error');
            return;
        }
        moveSelectedToGallery(destinationGalleryId, explicitImageIds);
    }

        /**
     * Handles native drag movement at document level so nested anchors and images
     * inside subgallery cards cannot swallow the dragover event.
     *
     * @param {DragEvent} event Dragover event captured from the document.
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
     */
    function handleDocumentKeyDown(event) {
        if (event.key === 'Escape' && selectedKeys.size === 0 && toolbar.dataset.pictureManagerExpanded === '1') {
            setPanelExpanded(false);
            setStatus(i18n('picture_manager.ready', 'Ready.'), 'idle');
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
    });
    imageCards.forEach((card) => {
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
    if (shareButton instanceof HTMLButtonElement) {
        shareButton.addEventListener('click', () => {
            shareSelectedPhotos();
        }, signalOptions);
    }
    if (deleteButton instanceof HTMLButtonElement) {
        deleteButton.addEventListener('click', () => {
            deleteSelectedItems();
        }, signalOptions);
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
    if (newParentInput instanceof HTMLInputElement) {
        newParentInput.addEventListener('change', updateActionButtons, signalOptions);
        newParentInput.addEventListener('input', updateActionButtons, signalOptions);
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
        /** Release event handlers, drag state, and toolbar state for this manager. */
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
