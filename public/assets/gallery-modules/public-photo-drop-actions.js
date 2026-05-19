/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/public-photo-drop-actions.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides shared public photo drop target resolution and move dispatching.
 *
 * Responsibilities:
 *   - Locate visible subgallery cards under pointer coordinates
 *   - Apply consistent drag-ready and drag-hover classes to subgallery targets
 *   - Normalize dragged image IDs from DOM items or native drag data
 *   - Dispatch one canonical event for moving photos into another gallery
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

export const PUBLIC_PHOTO_MOVE_EVENT = 'php-gallery:picture-manager-drop-move';

const PUBLIC_SUBGALLERY_TARGET_SELECTOR = '[data-public-subgallery-section] [data-gallery-id]';
const PHOTO_IDS_TRANSFER_TYPE = 'application/x-php-gallery-image-ids';

/**
 * Returns a stable gallery ID from a public subgallery target.
 *
 * @param {Element|null} target Candidate subgallery card or nested child.
 * @returns {string} Gallery ID, or an empty string when unavailable.
 */
export function publicPhotoDropTargetGalleryId(target) {
    if (!(target instanceof Element)) {
        return '';
    }
    const card = target.closest(PUBLIC_SUBGALLERY_TARGET_SELECTOR);
    return card instanceof HTMLElement ? (card.dataset.galleryId || '') : '';
}

/**
 * Returns visible subgallery cards that can accept moved photos.
 *
 * @param {string} sourceGalleryId Gallery ID of the currently opened gallery.
 * @param {ParentNode} root DOM root used for querying targets.
 * @returns {HTMLElement[]} Drop targets excluding the current source gallery.
 */
export function publicPhotoDropTargets(sourceGalleryId, root = document) {
    return Array.from(root.querySelectorAll(PUBLIC_SUBGALLERY_TARGET_SELECTOR))
        .filter((target) => target instanceof HTMLElement && publicPhotoDropTargetGalleryId(target) !== '' && publicPhotoDropTargetGalleryId(target) !== sourceGalleryId);
}

/**
 * Resolves the public subgallery target under pointer coordinates.
 *
 * Pointer and native drag events often report nested images, anchors, or drag
 * ghosts as their direct target. Walking the visual stack gives both public
 * reorder dragging and native Picture manager dragging the same hit testing.
 *
 * @param {number} clientX Pointer X coordinate.
 * @param {number} clientY Pointer Y coordinate.
 * @param {{sourceGalleryId?: string, ignoreWithinSelector?: string}} options Hit-test options.
 * @returns {HTMLElement|null} Destination subgallery card, or null.
 */
export function publicPhotoDropTargetAtPoint(clientX, clientY, options = {}) {
    const sourceGalleryId = String(options.sourceGalleryId || '');
    const ignoreWithinSelector = typeof options.ignoreWithinSelector === 'string' ? options.ignoreWithinSelector : '';
    const elements = typeof document.elementsFromPoint === 'function'
        ? document.elementsFromPoint(clientX, clientY)
        : [document.elementFromPoint(clientX, clientY)].filter(Boolean);

    for (const element of elements) {
        if (!(element instanceof Element)) {
            continue;
        }
        if (ignoreWithinSelector !== '' && element.closest(ignoreWithinSelector)) {
            continue;
        }
        const target = element.closest(PUBLIC_SUBGALLERY_TARGET_SELECTOR);
        if (!(target instanceof HTMLElement)) {
            continue;
        }
        const destinationGalleryId = publicPhotoDropTargetGalleryId(target);
        if (destinationGalleryId === '' || destinationGalleryId === sourceGalleryId) {
            continue;
        }
        return target;
    }
    return null;
}

/**
 * Applies the shared active affordance to all visible public subgallery targets.
 *
 * @param {string} sourceGalleryId Current source gallery ID.
 * @param {boolean} isActive Whether drop targets should look available.
 * @returns {void}
 */
export function setPublicPhotoDropTargetsActive(sourceGalleryId, isActive) {
    publicPhotoDropTargets(sourceGalleryId).forEach((target) => {
        target.classList.toggle('is-picture-manager-drop-ready', isActive);
        if (!isActive) {
            target.classList.remove('is-picture-manager-drop-hover');
        }
    });
}

/**
 * Highlights one public subgallery target and clears the rest.
 *
 * @param {HTMLElement|null} activeTarget Target currently under the pointer.
 * @param {string} sourceGalleryId Current source gallery ID.
 * @returns {void}
 */
export function highlightPublicPhotoDropTarget(activeTarget, sourceGalleryId) {
    publicPhotoDropTargets(sourceGalleryId).forEach((target) => {
        target.classList.toggle('is-picture-manager-drop-hover', target === activeTarget);
    });
}

/**
 * Collects image IDs from dragged public photo items in visual order.
 *
 * @param {HTMLElement[]} items Dragged public photo cards.
 * @returns {string[]} Image IDs suitable for Picture manager move requests.
 */
export function publicPhotoImageIdsFromItems(items) {
    return items
        .filter((item) => item instanceof HTMLElement)
        .map((item) => item.dataset.publicOrderId || item.dataset.pictureManagerImageId || '')
        .map((imageId) => String(imageId).trim())
        .filter((imageId) => imageId !== '');
}

/**
 * Reads dragged image IDs from a native drag data transfer.
 *
 * @param {DataTransfer|null} dataTransfer Browser native drag data.
 * @returns {string[]} Image IDs carried by the drag session.
 */
export function publicPhotoImageIdsFromDataTransfer(dataTransfer) {
    if (!dataTransfer) {
        return [];
    }
    const transferredIds = dataTransfer.getData(PHOTO_IDS_TRANSFER_TYPE) || dataTransfer.getData('text/plain') || '';
    return transferredIds
        .split(',')
        .map((imageId) => imageId.trim())
        .filter((imageId) => imageId !== '');
}

/**
 * Writes dragged image IDs into a native drag data transfer.
 *
 * @param {DataTransfer|null} dataTransfer Browser native drag data.
 * @param {string[]} imageIds Image IDs selected for movement.
 * @returns {void}
 */
export function writePublicPhotoImageIdsToDataTransfer(dataTransfer, imageIds) {
    if (!dataTransfer) {
        return;
    }
    const serializedIds = imageIds.map((imageId) => String(imageId).trim()).filter((imageId) => imageId !== '').join(',');
    dataTransfer.effectAllowed = 'move';
    dataTransfer.setData(PHOTO_IDS_TRANSFER_TYPE, serializedIds);
    dataTransfer.setData('text/plain', serializedIds);
}

/**
 * Dispatches the canonical public photo move request event.
 *
 * The Picture manager remains the owner of what a move means. Other interaction
 * modules only resolve a destination and emit this event with normalized IDs.
 *
 * @param {string} destinationGalleryId Destination gallery ID.
 * @param {string[]} imageIds Image IDs to move.
 * @returns {void}
 */
export function dispatchPublicPhotoMove(destinationGalleryId, imageIds) {
    document.dispatchEvent(new CustomEvent(PUBLIC_PHOTO_MOVE_EVENT, {
        detail: {
            destinationGalleryId: String(destinationGalleryId || ''),
            imageIds: Array.isArray(imageIds) ? imageIds.map((imageId) => String(imageId)).filter((imageId) => imageId !== '') : [],
        },
    }));
}
