/*
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-music-album-order.js
 * Module Type: Browser Module
 * Purpose: Add drag ordering to songs in the Music Manager album detail.
 * Responsibilities:
 *   - Mark same-album insertion points during pointer or native dragging.
 *   - Submit the existing canonical album reorder form after a valid drop.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Ignore small pointer movement so a click on the handle does not start a drag.
 * Type: number. Units: CSS pixels. Scope: one album song pointer gesture.
 * Consumers: pointermove drag activation in setupAdminMusicAlbumOrdering.
 * Rationale: six pixels distinguish a deliberate drag from click movement.
 * Value: 6 CSS pixels.
 */
const ADMIN_MUSIC_ALBUM_DRAG_THRESHOLD = 6;

/**
 * Bind delegated album-song drag ordering once for the document lifetime.
 * Reorder writes go through the existing form submit coordinator.
 * @returns {void} Installs the Music Manager drag handlers once.
 */
export function setupAdminMusicAlbumOrdering() {
    if (!(document.querySelector('[data-admin-music]') instanceof HTMLElement)
        || !document.body
        || document.body.dataset.adminMusicAlbumOrderBound === '1') {
        return;
    }
    document.body.dataset.adminMusicAlbumOrderBound = '1';

    /** @type {{root: HTMLElement, item: HTMLLIElement, handle: HTMLButtonElement, albumId: string, kind: 'pointer'|'native', pointerId: number|null, startX: number, startY: number, dragging: boolean, target: HTMLLIElement|null, position: 'before'|'after'|null}|null} */
    let activeDrag = null;

    /**
     * Clear the current drag marker and insertion indicators.
     * @param {HTMLElement} managerRoot Root captured when dragging started.
     * @returns {void} Removes temporary drag presentation state.
     */
    function clearDrag(managerRoot) {
        managerRoot.querySelectorAll('[data-admin-music-album-members] li')
            .forEach(/** Remove transient insertion state from one album member. @param {HTMLLIElement} item Album member row. @returns {void} Clears drag-only state. */ item => {
                item.classList.remove('is-dragging');
                delete item.dataset.dropPosition;
            });
        managerRoot.querySelectorAll('[data-admin-music-album-drag-handle]')
            .forEach(/** Restore one handle after drag completion. @param {HTMLButtonElement} handle Drag handle. @returns {void} Clears its dragging marker. */ handle => {
                handle.classList.remove('is-dragging');
                handle.setAttribute('aria-grabbed', 'false');
            });
    }

    /**
     * Resolve an album member row from a drag event target.
     * @param {EventTarget|null} target Event target from the current document.
     * @returns {HTMLLIElement|null} Album row, or null outside a member list.
     */
    function memberFromTarget(target) {
        if (!(target instanceof Element)) return null;
        const item = target.closest('li[data-song-id][data-position]');
        return item instanceof HTMLLIElement
            && item.closest('[data-admin-music-album-members]')
            ? item : null;
    }

    /**
     * Prepare a same-album insertion target from viewport coordinates.
     * @param {HTMLElement} managerRoot Current Music Manager root.
     * @param {HTMLLIElement} source Song row that started the drag.
     * @param {string} albumId Direct album identifier of the source row.
     * @param {number} clientX Horizontal viewport coordinate.
     * @param {number} clientY Vertical viewport coordinate.
     * @returns {{target: HTMLLIElement, position: 'before'|'after'}|null} Valid target or null outside the source album.
     */
    function insertionAt(managerRoot, source, albumId, clientX, clientY) {
        const hit = document.elementFromPoint(clientX, clientY);
        const target = memberFromTarget(hit);
        const list = target?.closest('[data-admin-music-album-members]');
        if (!target || !list || list.dataset.albumId !== albumId
            || !managerRoot.contains(target)) return null;
        if (target === source) return {target, position: 'before'};
        const bounds = target.getBoundingClientRect();
        return {
            target,
            position: clientY < bounds.top + bounds.height / 2 ? 'before' : 'after',
        };
    }

    /**
     * Update the visible insertion marker for the active drag.
     * @param {{root: HTMLElement, item: HTMLLIElement, handle: HTMLButtonElement, albumId: string, kind: 'pointer'|'native', pointerId: number|null, startX: number, startY: number, dragging: boolean, target: HTMLLIElement|null, position: 'before'|'after'|null}} drag Current pointer or native session.
     * @param {HTMLLIElement|null} target Candidate target row.
     * @param {'before'|'after'|null} position Insertion side of the candidate.
     * @returns {void} Shows the valid insertion side, if any.
     */
    function showInsertion(drag, target, position) {
        drag.root.querySelectorAll('[data-admin-music-album-members] li')
            .forEach(/** Clear older insertion markers before showing the current target. @param {HTMLLIElement} item Album member row. @returns {void} Removes its previous marker. */ item => { delete item.dataset.dropPosition; });
        drag.target = target;
        drag.position = position;
        if (!target || !position || target === drag.item) return;
        target.dataset.dropPosition = position;
    }

    /**
     * Persist a final row position through its existing canonical form.
     * @param {{root: HTMLElement, item: HTMLLIElement, handle: HTMLButtonElement, albumId: string, kind: 'pointer'|'native', pointerId: number|null, startX: number, startY: number, dragging: boolean, target: HTMLLIElement|null, position: 'before'|'after'|null}} drag Completed drag session.
     * @returns {void} Dispatches the existing form submit with a temporary position.
     */
    function submitPosition(drag) {
        const target = drag.target;
        if (!drag.dragging || !target || target === drag.item || !drag.position
            || document.querySelector('[data-admin-music]') !== drag.root
            || drag.root.dataset.busy === '1' || drag.root.dataset.stale === '1') return;
        const insertionPosition = Number(target.dataset.position)
            + (drag.position === 'after' ? 1 : 0);
        const sourcePosition = Number(drag.item.dataset.position);
        const finalPosition = insertionPosition
            - (sourcePosition < insertionPosition ? 1 : 0);
        const form = drag.item.querySelector(
            'form input[name="action"][value="album_reorder_song"]',
        )?.closest('form');
        const position = form?.querySelector('input[name="position"]');
        if (!(form instanceof HTMLFormElement)
            || !(position instanceof HTMLInputElement)
            || !Number.isInteger(finalPosition)
            || finalPosition < 0 || finalPosition === sourcePosition) return;
        const originalPosition = position.value;
        position.value = String(finalPosition);
        try {
            form.requestSubmit();
        } finally {
            position.value = originalPosition;
        }
    }

    /**
     * Release pointer capture and remove all temporary drag presentation.
     * @param {boolean} releaseCapture Whether to release the captured pointer.
     * @returns {void} Clears the one active pointer or native drag session.
     */
    function clearActiveDrag(releaseCapture) {
        if (!activeDrag) return;
        const drag = activeDrag;
        clearDrag(drag.root);
        activeDrag = null;
        if (releaseCapture && drag.pointerId !== null
            && drag.handle.hasPointerCapture(drag.pointerId)) {
            drag.handle.releasePointerCapture(drag.pointerId);
        }
    }

    /**
     * Start a pointer session from the dedicated handle and capture its pointer.
     * @param {PointerEvent} event Native pointer-down event.
     * @returns {void} Captures movement without triggering native HTML dragging.
     */
    document.addEventListener('pointerdown', /** Start an intentional handle pointer gesture. @param {PointerEvent} event Native pointer-down event. @returns {void} Captures the handle pointer. */ event => {
        if (!(event.target instanceof Element)) return;
        const handle = event.target.closest('[data-admin-music-album-drag-handle]');
        if (!(handle instanceof HTMLButtonElement) || !event.isPrimary
            || event.button !== 0) return;
        const root = handle.closest('[data-admin-music]');
        const item = handle.closest('li[data-song-id][data-position]');
        const list = item?.closest('[data-admin-music-album-members]');
        if (!(root instanceof HTMLElement)
            || document.querySelector('[data-admin-music]') !== root
            || !(item instanceof HTMLLIElement)
            || !(list instanceof HTMLUListElement)
            || root.dataset.busy === '1' || root.dataset.stale === '1') return;
        event.preventDefault();
        event.stopPropagation();
        clearActiveDrag(true);
        const drag = {
            root,
            item,
            handle,
            albumId: list.dataset.albumId || '',
            kind: 'pointer',
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            dragging: false,
            target: null,
            position: null,
        };
        activeDrag = drag;
        try {
            handle.setPointerCapture(event.pointerId);
        } catch {
            clearActiveDrag(false);
        }
    });

    /**
     * Activate and track pointer movement after crossing the movement threshold.
     * @param {PointerEvent} event Native pointer-move event.
     * @returns {void} Updates the target row without moving persistent DOM nodes.
     */
    document.addEventListener('pointermove', /** Track only the captured Music Manager pointer. @param {PointerEvent} event Native pointer-move event. @returns {void} Updates drag activation and target. */ event => {
        if (!activeDrag || activeDrag.kind !== 'pointer'
            || activeDrag.pointerId !== event.pointerId) return;
        const drag = activeDrag;
        const distance = Math.hypot(
            event.clientX - drag.startX,
            event.clientY - drag.startY,
        );
        if (!drag.dragging && distance < ADMIN_MUSIC_ALBUM_DRAG_THRESHOLD) return;
        event.preventDefault();
        event.stopPropagation();
        if (document.querySelector('[data-admin-music]') !== drag.root
            || drag.root.dataset.busy === '1' || drag.root.dataset.stale === '1') {
            clearActiveDrag(true);
            return;
        }
        if (!drag.dragging) {
            drag.dragging = true;
            drag.item.classList.add('is-dragging');
            drag.handle.classList.add('is-dragging');
            drag.handle.setAttribute('aria-grabbed', 'true');
        }
        const insertion = insertionAt(
            drag.root,
            drag.item,
            drag.albumId,
            event.clientX,
            event.clientY,
        );
        showInsertion(drag, insertion?.target || null, insertion?.position || null);
    });

    /**
     * Commit a moved pointer gesture on release; clicks remain write-free.
     * @param {PointerEvent} event Native pointer-up event.
     * @returns {void} Submits at most one canonical reorder request.
     */
    document.addEventListener('pointerup', /** Finish the matching pointer session. @param {PointerEvent} event Native pointer-up event. @returns {void} Commits or cancels the gesture. */ event => {
        if (!activeDrag || activeDrag.kind !== 'pointer'
            || activeDrag.pointerId !== event.pointerId) return;
        event.preventDefault();
        event.stopPropagation();
        const drag = activeDrag;
        if (drag.dragging) {
            const insertion = insertionAt(
                drag.root,
                drag.item,
                drag.albumId,
                event.clientX,
                event.clientY,
            );
            showInsertion(drag, insertion?.target || null, insertion?.position || null);
            submitPosition(drag);
        }
        clearActiveDrag(true);
    });

    /**
     * Cancel the pointer drag when the platform cancels the captured gesture.
     * @param {PointerEvent} event Native pointer-cancel or capture-lost event.
     * @returns {void} Clears matching transient drag state without writing.
     */
    function cancelPointer(event) {
        if (!activeDrag || activeDrag.kind !== 'pointer'
            || activeDrag.pointerId !== event.pointerId) return;
        clearActiveDrag(false);
    }

    document.addEventListener('pointercancel', /** Cancel a pointer session interrupted by the platform. @param {PointerEvent} event Native pointer-cancel event. @returns {void} Clears the active session. */ cancelPointer);
    document.addEventListener('lostpointercapture', /** Cancel when the handle loses pointer ownership unexpectedly. @param {PointerEvent} event Native capture-loss event. @returns {void} Clears the active session. */ cancelPointer);

    /**
     * Cancel a pending pointer gesture when the user presses Escape.
     * @param {KeyboardEvent} event Native keyboard event.
     * @returns {void} Clears active pointer state without submitting.
     */
    document.addEventListener('keydown', /** Cancel the active pointer gesture on Escape. @param {KeyboardEvent} event Native keyboard event. @returns {void} Releases drag state. */ event => {
        if (!activeDrag || activeDrag.kind !== 'pointer'
            || event.key !== 'Escape') return;
        event.preventDefault();
        clearActiveDrag(true);
    });

    /**
     * Keep native HTML drag as a fallback without allowing pointer double-writes.
     * @param {DragEvent} event Native drag-start event.
     * @returns {void} Starts only a handle-originated native fallback session.
     */
    document.addEventListener('dragstart', /** Capture a fallback source row from its handle. @param {DragEvent} event Native drag-start event. @returns {void} Establishes one native session. */ event => {
        if (!(event.target instanceof Element)) return;
        const handle = event.target.closest('[data-admin-music-album-drag-handle]');
        if (!(handle instanceof HTMLButtonElement)) return;
        if (activeDrag?.kind === 'pointer') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        const root = handle.closest('[data-admin-music]');
        const item = handle.closest('li[data-song-id][data-position]');
        const list = item?.closest('[data-admin-music-album-members]');
        if (!(root instanceof HTMLElement)
            || document.querySelector('[data-admin-music]') !== root
            || !(item instanceof HTMLLIElement)
            || !(list instanceof HTMLUListElement)
            || root.dataset.busy === '1' || root.dataset.stale === '1') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        activeDrag = {
            root, item, handle,
            albumId: list.dataset.albumId || '',
            kind: 'native', pointerId: null,
            startX: event.clientX, startY: event.clientY,
            dragging: true, target: null, position: null,
        };
        item.classList.add('is-dragging');
        handle.classList.add('is-dragging');
        handle.setAttribute('aria-grabbed', 'true');
        event.dataTransfer?.setData(
            'application/x-php-gallery-music-song',
            item.dataset.songId || '',
        );
        if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
        event.stopPropagation();
    });

    /**
     * Update a native fallback insertion marker for the active album.
     * @param {DragEvent} event Native drag-over event.
     * @returns {void} Marks a same-album target for native dropping.
     */
    document.addEventListener('dragover', /** Track the native fallback target. @param {DragEvent} event Native drag-over event. @returns {void} Updates insertion presentation. */ event => {
        if (!activeDrag || activeDrag.kind !== 'native') return;
        const drag = activeDrag;
        if (document.querySelector('[data-admin-music]') !== drag.root
            || drag.root.dataset.busy === '1' || drag.root.dataset.stale === '1') {
            clearActiveDrag(false);
            return;
        }
        const insertion = insertionAt(
            drag.root, drag.item, drag.albumId, event.clientX, event.clientY,
        );
        if (!insertion) return;
        event.preventDefault();
        event.stopPropagation();
        showInsertion(drag, insertion.target, insertion.position);
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    });

    /**
     * Commit a native fallback drop, or release its session outside the album.
     * @param {DragEvent} event Native drop event.
     * @returns {void} Submits at most one canonical reorder request.
     */
    document.addEventListener('drop', /** Finish only the active native fallback. @param {DragEvent} event Native drop event. @returns {void} Commits or cancels the session. */ event => {
        if (!activeDrag || activeDrag.kind !== 'native') return;
        const drag = activeDrag;
        const insertion = insertionAt(
            drag.root, drag.item, drag.albumId, event.clientX, event.clientY,
        );
        if (insertion) {
            event.preventDefault();
            event.stopPropagation();
            showInsertion(drag, insertion.target, insertion.position);
            submitPosition(drag);
        }
        clearActiveDrag(false);
    });

    /**
     * Release native fallback state when its handle drag ends.
     * @param {DragEvent} event Native drag-end event.
     * @returns {void} Clears the current native session.
     */
    document.addEventListener('dragend', /** Release state after the native handle drag ends. @param {DragEvent} event Native drag-end event. @returns {void} Clears the fallback session. */ event => {
        if (!activeDrag || activeDrag.kind !== 'native'
            || !(event.target instanceof Element)
            || !event.target.closest('[data-admin-music-album-drag-handle]')) return;
        clearActiveDrag(false);
    });
}
