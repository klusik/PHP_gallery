/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-list.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides gallery filtering, tree collapsing, and gallery reordering behaviors.
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
 *   2026-05-12
 */

import { setGalleryRowHiddenReason } from './admin-core.js?v=20260512-modular-admin-v1';

// Function `setupAdminGalleryFilters` executes this focused behavior.
export function setupAdminGalleryFilters() {
    // Variable `filter` stores this steps working value.
    const filter = document.querySelector('[data-gallery-visibility-filter]');
    if (!(filter instanceof HTMLSelectElement)) {
        return;
    }
    // Variable `form` stores this steps working value.
    const form = filter.closest('form');
    // Variable `rows` stores this steps working value.
    const rows = Array.from(document.querySelectorAll('[data-gallery-row]'));
    // Variable `summary` stores this steps working value.
    const summary = document.querySelector('[data-gallery-filter-summary]');
    // Variable `selectAll` stores this steps working value.
    const selectAll = form ? form.querySelector('[data-select-all="gallery_ids[]"]') : null;

    // Function `updateSummary` executes this focused behavior.
    function updateSummary() {
        // displayed stores state or configuration for the gallery front-end flow.
        let displayed = 0;
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        // selectedVisibility stores state or configuration for the gallery front-end flow.
        const selectedVisibility = filter.value || 'all';
        rows.forEach((row) => {
            // matchesFilter stores state or configuration for the gallery front-end flow.
            const matchesFilter = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
            if (matchesFilter && row.dataset.hiddenByTree !== '1') {
                total++;
            }
            if (!row.hidden) {
                displayed++;
            }
        });
        if (summary) {
            summary.textContent = `${displayed} / ${total} galleries displayed`;
        }
    }

    // Function `applyFilter` executes this focused behavior.
    function applyFilter() {
        // selectedVisibility stores state or configuration for the gallery front-end flow.
        const selectedVisibility = filter.value || 'all';
        rows.forEach((row) => {
            // A filtered-out row is also unchecked. This prevents a hidden
            // stale selection from being included in the next bulk action.
            const matches = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
            setGalleryRowHiddenReason(row, 'filter', !matches);
            if (!matches) {
                row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                    checkbox.checked = false;
                });
            }
        });
        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = false;
        }
        updateSummary();
    }

    document.addEventListener('galleryRowsChanged', updateSummary);
    filter.addEventListener('change', applyFilter);
    applyFilter();
}

// Function `setupAdminGalleryTree` executes this focused behavior.
export function setupAdminGalleryTree() {
    // Variable `table` stores this steps working value.
    const table = document.querySelector('[data-admin-gallery-order-table]');
    if (!table) {
        return;
    }
    // Variable `csrf` stores this steps working value.
    const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
    // Variable `saveUrl` stores this steps working value.
    const saveUrl = new URL(window.location.href);
    saveUrl.search = '?page=admin_save_gallery_collapse';

    // Function `currentRows` executes this focused behavior.
    function currentRows() {
        return Array.from(table.querySelectorAll('[data-gallery-row]'));
    }

    // Function `rowById` executes this focused behavior.
    function rowById(galleryId) {
        return currentRows().find((candidate) => candidate.dataset.galleryId === String(galleryId)) || null;
    }

    // Function `collapsedIds` executes this focused behavior.
    function collapsedIds() {
        return currentRows().filter((row) => row.classList.contains('is-collapsed')).map((row) => row.dataset.galleryId);
    }

    // Function `save` executes this focused behavior.
    function save() {
        // Variable `body` stores this steps working value.
        const body = new FormData();
        body.set('csrf_token', csrf);
        body.set('collapsed_ids', JSON.stringify(collapsedIds()));
        fetch(saveUrl.toString(), {method: 'POST', body, headers: {'Accept': 'application/json'}});
    }

    /**
     * Ensures the row has the correct expand/collapse control for its current children.
     *
     * Reordering can turn a leaf gallery into a parent or remove the last child
     * from a previous parent without a page reload. The visible control must be
     * rebuilt from current parent_id values before tree visibility is recalculated.
     *
     * @param {HTMLTableRowElement} row Gallery row to refresh.
     * @param {boolean} hasChildren Whether this row currently owns child rows.
     * @returns {void}
     */
    function syncRowToggle(row, hasChildren) {
        const title = row.querySelector('.tree-title');
        if (!title) {
            return;
        }
        const galleryId = row.dataset.galleryId || '';
        const existingToggle = title.querySelector('[data-gallery-toggle]');
        const existingSpacer = title.querySelector('.tree-spacer');
        if (hasChildren) {
            if (existingToggle) {
                existingToggle.textContent = row.classList.contains('is-collapsed') ? '+' : '-';
                existingToggle.setAttribute('aria-expanded', row.classList.contains('is-collapsed') ? 'false' : 'true');
                return;
            }
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'tree-toggle';
            toggle.dataset.galleryToggle = galleryId;
            toggle.textContent = row.classList.contains('is-collapsed') ? '+' : '-';
            toggle.setAttribute('aria-expanded', row.classList.contains('is-collapsed') ? 'false' : 'true');
            existingSpacer?.remove();
            title.insertBefore(toggle, title.firstChild);
            return;
        }
        row.classList.remove('is-collapsed');
        existingToggle?.remove();
        if (!existingSpacer) {
            const spacer = document.createElement('span');
            spacer.className = 'tree-spacer';
            spacer.setAttribute('aria-hidden', 'true');
            title.insertBefore(spacer, title.firstChild);
        }
    }

    /**
     * Rebuilds child-aware toggle controls from current parent_id metadata.
     *
     * @returns {void}
     */
    function syncTreeControls() {
        const childCounts = new Map();
        currentRows().forEach((row) => {
            const parentId = row.dataset.parentId || '0';
            if (parentId === '0') {
                return;
            }
            childCounts.set(parentId, (childCounts.get(parentId) || 0) + 1);
        });
        currentRows().forEach((row) => {
            syncRowToggle(row, (childCounts.get(row.dataset.galleryId || '') || 0) > 0);
        });
    }

    // Function `refreshVisibility` executes this focused behavior.
    function refreshVisibility() {
        syncTreeControls();
        // Variable `rows` stores this steps working value.
        const rows = currentRows();
        // Variable `collapsed` stores this steps working value.
        const collapsed = new Set(collapsedIds().map(String));
        rows.forEach((row) => {
            // Variable `parentId` stores this steps working value.
            let parentId = row.dataset.parentId || '0';
            // Variable `hidden` stores this steps working value.
            let hidden = false;
            while (parentId !== '0') {
                if (collapsed.has(parentId)) {
                    hidden = true;
                    break;
                }
                // Variable `parent` stores this steps working value.
                const parent = rowById(parentId);
                parentId = parent ? (parent.dataset.parentId || '0') : '0';
            }
            setGalleryRowHiddenReason(row, 'tree', hidden);
            if (hidden) {
                row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                    checkbox.checked = false;
                });
            }
        });
        // The master checkbox is a one-shot command for the current view,
        // so any tree visibility change clears it rather than leaving a
        // stale checked state after hidden rows have been unchecked.
        const selectAll = document.querySelector('[data-gallery-bulk-form] [data-select-all="gallery_ids[]"]');
        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = false;
        }
        document.dispatchEvent(new Event('galleryRowsChanged'));
    }

    table.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-gallery-toggle]') : null;
        if (!button) {
            return;
        }
        // Variable `row` stores this steps working value.
        const row = button.closest('[data-gallery-row]');
        if (!row) {
            return;
        }
        // Variable `collapsed` stores this steps working value.
        const collapsed = !row.classList.contains('is-collapsed');
        row.classList.toggle('is-collapsed', collapsed);
        button.textContent = collapsed ? '+' : '-';
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        refreshVisibility();
        save();
    });

    document.querySelectorAll('[data-gallery-tree-action]').forEach((button) => {
        button.addEventListener('click', () => {
            // Variable `collapse` stores this steps working value.
            const collapse = button.dataset.galleryTreeAction === 'collapse-all';
            syncTreeControls();
            currentRows().forEach((row) => {
                // Variable `toggle` stores this steps working value.
                const toggle = row.querySelector('[data-gallery-toggle]');
                if (!toggle) {
                    return;
                }
                row.classList.toggle('is-collapsed', collapse);
                toggle.textContent = collapse ? '+' : '-';
                toggle.setAttribute('aria-expanded', collapse ? 'false' : 'true');
            });
            refreshVisibility();
            save();
        });
    });

    document.addEventListener('adminGalleryTreeMutated', refreshVisibility);
    refreshVisibility();
}

/**
 * Enables pointer-based nested ordering for the Admin gallery table.
 *
 * The gallery table is a flattened tree. During a drag, this controller moves
 * the selected gallery together with all of its descendants, then uses pointer X
 * movement to calculate the new depth. The server receives the full flattened
 * order and derives each parent_id from that order before updating sort_order
 * and moving folders on disk when the parent changes.
 *
 * @returns {void}
 */
export function setupAdminGalleryReordering() {
    // table stores the reorder-enabled gallery table on the Admin dashboard.
    const table = document.querySelector('[data-admin-gallery-order-table]');
    // toolbar stores endpoint metadata and status UI for the gallery reorder feature.
    const toolbar = document.querySelector('[data-admin-gallery-order-toolbar]');
    // form stores the existing gallery bulk form, reused for CSRF and row scope.
    const form = document.querySelector('[data-admin-gallery-order-form]');
    if (!table || !toolbar || !form) {
        return;
    }

    // body stores the table body containing the flattened gallery tree rows.
    const body = table.querySelector('tbody');
    // status stores the live textual state displayed above the gallery table.
    const status = toolbar.querySelector('[data-admin-gallery-order-status]');
    // reorderUrl stores the server endpoint that persists order and nesting.
    const reorderUrl = toolbar.dataset.reorderUrl || '';
    // csrfInput stores the CSRF token generated by the PHP form helper.
    const csrfInput = form.querySelector('input[name="csrf_token"]');
    if (!body || !reorderUrl || !csrfInput) {
        return;
    }

    // indentWidth stores the horizontal distance that represents one tree level.
    const indentWidth = 28;
    // draggedRows stores the moved root row and all descendant rows.
    let draggedRows = [];
    // draggedHandle stores the gallery-column area that started the drag, so its visual state can be restored.
    let draggedHandle = null;
    // placeholderRow stores the temporary row marking the insertion point.
    let placeholderRow = null;
    // ghostTable stores the fixed-position visual copy that follows the pointer.
    let ghostTable = null;
    // originalSignature stores order and parent values before dragging begins.
    let originalSignature = '';
    // originalDepth stores the depth of the dragged root row before dragging begins.
    let originalDepth = 0;
    // pointerOffsetY stores the pointer distance from the top of the row at drag start.
    let pointerOffsetY = 0;
    // startClientX stores the horizontal pointer coordinate at drag start.
    let startClientX = 0;
    // proposedDepth stores the candidate depth shown by the placeholder.
    let proposedDepth = 0;
    // activePointerId stores the pointer that owns the current drag session.
    let activePointerId = null;
    // activeMouseFallback stores whether classic mouse events are currently driving movement.
    let activeMouseFallback = false;
    // pendingDrag stores a possible drag that has not crossed the movement threshold yet.
    let pendingDrag = null;
    // suppressClickUntil stores a short timestamp window used to stop link clicks after dragging a title area.
    let suppressClickUntil = 0;
    // saveController stores the in-flight request controller so a newer drop can supersede an older save.
    let saveController = null;

    /**
     * Updates the small status label above the gallery order table.
     *
     * @param {string} message Human-readable state shown to the admin.
     * @param {string} state Visual state name used by CSS.
     * @returns {void}
     */
    function setStatus(message, state) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.dataset.state = state;
    }

    /**
     * Converts accidental HTML output from a JSON endpoint into readable admin text.
     *
     * Shared hosting can print PHP warnings as HTML before JSON when display_errors
     * is enabled. The server now buffers that output, but this fallback keeps older
     * cached PHP files from showing raw parser errors to the admin.
     *
     * @param {string} responseText Raw response returned by the reorder endpoint.
     * @returns {string} Friendly status message for the toolbar.
     */
    function cleanAdminJsonParseMessage(responseText) {
        const plainText = String(responseText || '')
            .replace(/<br\s*\/?>(\s*)/gi, '\n')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (plainText) {
            return `Gallery order was saved, but the server returned a diagnostic message instead of clean JSON: ${plainText.slice(0, 240)}`;
        }
        return 'Gallery order was saved, but the server returned an empty response. Refresh the page to verify the current order.';
    }

    /**
     * Returns all real gallery rows in current DOM order.
     *
     * @returns {HTMLTableRowElement[]} Gallery rows in flattened tree order.
     */
    function galleryRows() {
        return Array.from(body.querySelectorAll('[data-gallery-row]'));
    }

    /**
     * Reads the integer depth value from one gallery row.
     *
     * @param {Element|null} row Gallery row to inspect.
     * @returns {number} Non-negative row depth.
     */
    function rowDepth(row) {
        return Math.max(0, Number(row?.dataset.depth || 0));
    }

    /**
     * Returns a compact signature used to detect whether anything changed.
     *
     * @returns {string} Ordered id and parent-id signature.
     */
    function currentGallerySignature() {
        return galleryRows().map((row) => `${row.dataset.galleryId || ''}:${row.dataset.parentId || '0'}`).join('|');
    }

    /**
     * Collects the dragged root row and every following descendant row.
     *
     * @param {HTMLTableRowElement} rootRow Gallery row whose subtree should move.
     * @returns {HTMLTableRowElement[]} Root row followed by its descendants.
     */
    function collectMovedRows(rootRow) {
        const rows = galleryRows();
        const startIndex = rows.indexOf(rootRow);
        const rootDepth = rowDepth(rootRow);
        const moved = [];
        if (startIndex < 0) {
            return moved;
        }
        for (let index = startIndex; index < rows.length; index++) {
            const row = rows[index];
            if (index !== startIndex && rowDepth(row) <= rootDepth) {
                break;
            }
            moved.push(row);
        }
        return moved;
    }

    /**
     * Copies current column widths from a real row into a cloned row.
     *
     * @param {HTMLTableRowElement} sourceRow Real row being cloned.
     * @param {HTMLTableRowElement} cloneRow Cloned row shown inside the ghost table.
     * @returns {void}
     */
    function copyCellWidths(sourceRow, cloneRow) {
        const sourceCells = Array.from(sourceRow.children);
        const cloneCells = Array.from(cloneRow.children);
        sourceCells.forEach((cell, index) => {
            const cloneCell = cloneCells[index];
            if (!cloneCell) {
                return;
            }
            cloneCell.style.width = `${cell.getBoundingClientRect().width}px`;
        });
    }

    /**
     * Creates the fixed visual copy used while a gallery subtree is moving.
     *
     * @param {HTMLTableRowElement[]} rows Real rows being moved.
     * @returns {HTMLTableElement} Ghost table appended to the document body.
     */
    function createGalleryGhost(rows) {
        const firstBox = rows[0].getBoundingClientRect();
        const ghost = document.createElement('table');
        const ghostBody = document.createElement('tbody');
        ghost.className = 'admin-image-order-ghost admin-gallery-order-ghost';
        ghost.style.width = `${firstBox.width}px`;
        ghost.style.left = `${firstBox.left}px`;
        const clonedRow = rows[0].cloneNode(true);
        copyCellWidths(rows[0], clonedRow);
        clonedRow.classList.add('is-ghost-row');
        clonedRow.removeAttribute('data-gallery-row');
        clonedRow.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));
        ghostBody.appendChild(clonedRow);
        ghost.appendChild(ghostBody);
        document.body.appendChild(ghost);
        return ghost;
    }

    /**
     * Creates a placeholder row matching the moved subtree height.
     *
     * @param {HTMLTableRowElement[]} rows Rows being moved.
     * @returns {HTMLTableRowElement} Placeholder inserted into the table body.
     */
    function createGalleryPlaceholder(rows) {
        const placeholder = document.createElement('tr');
        const cell = document.createElement('td');
        const totalHeight = rows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);
        placeholder.className = 'admin-image-order-placeholder admin-gallery-order-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.dataset.depth = String(originalDepth);
        cell.colSpan = Math.max(1, rows[0].children.length);
        cell.style.height = `${Math.max(32, totalHeight)}px`;
        placeholder.appendChild(cell);
        return placeholder;
    }

    /**
     * Moves the fixed ghost table to follow the current pointer position.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function moveGhost(clientY) {
        if (!ghostTable) {
            return;
        }
        ghostTable.style.top = `${clientY - pointerOffsetY}px`;
    }

    /**
     * Returns rows available as insertion targets while a subtree is moving.
     *
     * @returns {HTMLTableRowElement[]} Rows not currently hidden as part of the moved subtree.
     */
    function availableRows() {
        return galleryRows().filter((row) => !row.classList.contains('is-reorder-hidden'));
    }

    /**
     * Finds the row before which the placeholder should be inserted.
     *
     * @param {number} pointerY Current pointer Y coordinate.
     * @returns {HTMLTableRowElement|null} Row before the placeholder, or null to append.
     */
    function rowBeforePointer(pointerY) {
        return availableRows().reduce((closest, row) => {
            const box = row.getBoundingClientRect();
            const offset = pointerY - box.top - (box.height / 2);
            if (offset < 0 && offset > closest.offset) {
                return {offset, row};
            }
            return closest;
        }, {offset: Number.NEGATIVE_INFINITY, row: null}).row;
    }

    /**
     * Returns the row that would visually precede the placeholder.
     *
     * @returns {HTMLTableRowElement|null} Previous real gallery row, or null at table start.
     */
    function rowBeforePlaceholder() {
        let previous = placeholderRow?.previousElementSibling || null;
        while (previous && !previous.matches('[data-gallery-row]:not(.is-reorder-hidden)')) {
            previous = previous.previousElementSibling;
        }
        return previous;
    }

    /**
     * Calculates a legal tree depth from pointer X and the surrounding rows.
     *
     * @param {number} clientX Current pointer X coordinate.
     * @returns {number} Candidate depth for the moved root gallery.
     */
    function depthFromPointer(clientX) {
        const previousRow = rowBeforePlaceholder();
        const maxDepth = previousRow ? rowDepth(previousRow) + 1 : 0;
        const rawDepth = originalDepth + Math.round((clientX - startClientX) / indentWidth);
        return Math.max(0, Math.min(maxDepth, rawDepth));
    }

    /**
     * Updates placeholder indentation and status text for the current target depth.
     *
     * @param {number} depth Candidate depth for the moved gallery.
     * @returns {void}
     */
    function applyPlaceholderDepth(depth) {
        proposedDepth = depth;
        const direction = depth > originalDepth ? 'right' : (depth < originalDepth ? 'left' : 'level');
        const levelDelta = Math.abs(depth - originalDepth);
        const levelText = levelDelta === 1 ? '1 level' : `${levelDelta} levels`;
        const message = direction === 'right'
            ? `→ Nest deeper (${levelText}).`
            : (direction === 'left' ? `← Move out (${levelText}).` : '↓ Same level.');
        if (placeholderRow) {
            const placeholderCell = placeholderRow.firstElementChild;
            placeholderRow.dataset.depth = String(depth);
            placeholderRow.dataset.dragDirection = direction;
            if (placeholderCell) {
                placeholderCell.dataset.dragHint = message;
            }
            placeholderRow.style.setProperty('--gallery-drag-depth', String(depth));
        }
        if (ghostTable) {
            ghostTable.dataset.dragDirection = direction;
        }
        table.dataset.galleryDragDirection = direction;
        document.body.dataset.galleryDragDirection = direction;
        setStatus(message, 'dragging');
    }

    /**
     * Moves the placeholder to the insertion point under the pointer.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @param {number} clientX Current viewport X coordinate.
     * @returns {void}
     */
    function movePlaceholder(clientY, clientX) {
        if (!placeholderRow) {
            return;
        }
        const beforeRow = rowBeforePointer(clientY);
        if (beforeRow === null) {
            body.appendChild(placeholderRow);
        } else if (beforeRow !== placeholderRow.nextElementSibling) {
            body.insertBefore(placeholderRow, beforeRow);
        }
        applyPlaceholderDepth(depthFromPointer(clientX));
    }

    /**
     * Applies a new depth to the moved rows while preserving descendant offsets.
     *
     * @param {number} newRootDepth New depth for the dragged root gallery.
     * @returns {void}
     */
    function applyMovedDepths(newRootDepth) {
        const shift = newRootDepth - originalDepth;
        draggedRows.forEach((row) => {
            const nextDepth = Math.max(0, rowDepth(row) + shift);
            setGalleryRowDepth(row, nextDepth);
        });
    }

    /**
     * Updates depth-related row metadata and title indentation classes.
     *
     * @param {HTMLTableRowElement} row Row to update.
     * @param {number} depth New visible tree depth.
     * @returns {void}
     */
    function setGalleryRowDepth(row, depth) {
        const title = row.querySelector('.tree-title');
        row.dataset.depth = String(depth);
        row.style.setProperty('--gallery-depth', String(Math.min(depth, 8)));
        row.classList.toggle('is-subgallery', depth > 0);
        if (!title) {
            return;
        }
        Array.from(title.classList).forEach((className) => {
            if (className.startsWith('tree-depth-')) {
                title.classList.remove(className);
            }
        });
        title.classList.add(`tree-depth-${Math.min(depth, 8)}`);
        title.querySelector('.tree-branch')?.remove();
        if (depth > 0 && !title.querySelector('.tree-branch')) {
            const branch = document.createElement('span');
            branch.className = 'tree-branch';
            branch.setAttribute('aria-hidden', 'true');
            const link = title.querySelector('a');
            title.insertBefore(branch, link || null);
        }
    }

    /**
     * Derives parent ids for every visible row from the flattened depth values.
     *
     * @returns {Array<{id: string, parent_id: string}>} Ordered rows with parent ids.
     */
    function serializeGalleryTree() {
        const stack = [];
        return galleryRows().map((row) => {
            const depth = rowDepth(row);
            stack.length = depth;
            const parent = depth > 0 ? stack[depth - 1] : '0';
            const id = row.dataset.galleryId || '';
            row.dataset.parentId = parent || '0';
            stack[depth] = id;
            return {id, parent_id: parent || '0'};
        }).filter((entry) => entry.id !== '');
    }

    /**
     * Returns the stable folder name segment for a gallery row.
     *
     * The Admin table can update visible paths immediately after a tree move
     * without waiting for the next page load. The folder segment is captured
     * once from the current path, then reused even after the displayed path is
     * recalculated under another parent.
     *
     * @param {HTMLTableRowElement} row Gallery row whose folder name is needed.
     * @returns {string} Last folder path segment for this gallery.
     */
    function galleryFolderName(row) {
        if (row.dataset.galleryFolderName) {
            return row.dataset.galleryFolderName;
        }
        const pathText = row.querySelector('.admin-gallery-path')?.textContent?.trim() || '';
        const parts = pathText.split('/').filter((part) => part !== '');
        const folderName = parts.length > 0 ? parts[parts.length - 1] : (row.dataset.galleryTitle || row.dataset.galleryId || 'gallery');
        row.dataset.galleryFolderName = folderName;
        return folderName;
    }

    /**
     * Returns the base public gallery URL prefix from the current link.
     *
     * @param {HTMLTableRowElement} row Gallery row whose public link should be refreshed.
     * @returns {string} Public URL prefix ending at `/gallery/`, or the current href prefix.
     */
    function galleryUrlPrefix(row) {
        if (row.dataset.galleryUrlPrefix) {
            return row.dataset.galleryUrlPrefix;
        }
        const link = row.querySelector('.admin-gallery-title-link');
        const href = link?.getAttribute('href') || '';
        const marker = '/gallery/';
        const markerIndex = href.indexOf(marker);
        const prefix = markerIndex >= 0 ? href.slice(0, markerIndex + marker.length) : '';
        row.dataset.galleryUrlPrefix = prefix;
        return prefix;
    }

    /**
     * Returns the gallery's canonical public URL segment from the current link.
     *
     * The admin tree must preserve the existing slug segment for the gallery
     * itself and only recompute the parent path when nesting changes.
     *
     * @param {HTMLTableRowElement} row Gallery row whose public segment is needed.
     * @returns {string} Decoded canonical public URL segment.
     */
    function galleryUrlSegment(row) {
        if (row.dataset.galleryUrlSegment) {
            return row.dataset.galleryUrlSegment;
        }
        const link = row.querySelector('.admin-gallery-title-link');
        const href = link?.getAttribute('href') || '';
        const marker = '/gallery/';
        const markerIndex = href.indexOf(marker);
        if (markerIndex < 0) {
            row.dataset.galleryUrlSegment = galleryFolderName(row);
            return row.dataset.galleryUrlSegment;
        }
        const path = href.slice(markerIndex + marker.length).replace(/\/+$/, '');
        const parts = path.split('/').filter((part) => part !== '');
        const segment = parts.length > 0 ? decodeURIComponent(parts[parts.length - 1]) : galleryFolderName(row);
        row.dataset.galleryUrlSegment = segment;
        return segment;
    }

    /**
     * Rebuilds the gallery link from the current tree path.
     *
     * The admin table already knows the live nesting order, so this keeps the
     * public link aligned with the just-saved move without a full refresh.
     *
     * @param {HTMLTableRowElement} row Gallery row whose link should be refreshed.
     * @param {string} nextPath Newly computed gallery path.
     * @returns {void}
     */
    function refreshGalleryLink(row, nextPath) {
        const link = row.querySelector('.admin-gallery-title-link');
        if (!link) {
            return;
        }
        const prefix = galleryUrlPrefix(row);
        if (!prefix) {
            return;
        }
        const path = nextPath.split('/').map((segment) => encodeURIComponent(segment)).join('/');
        const nextUrl = `${prefix}${path}/`;
        link.href = nextUrl;
        row.dataset.galleryUrl = nextUrl;
    }

    /**
     * Updates visible parent labels and folder paths after a client-side tree move.
     *
     * @returns {void}
     */
    function refreshVisibleGalleryTreeMetadata() {
        const titlesById = new Map();
        const pathsById = new Map();
        const urlPathsById = new Map();
        galleryRows().forEach((row) => {
            titlesById.set(row.dataset.galleryId || '', row.dataset.galleryTitle || row.querySelector('.admin-gallery-title-link')?.textContent?.trim() || 'Gallery');
        });
        galleryRows().forEach((row) => {
            const id = row.dataset.galleryId || '';
            const parentId = row.dataset.parentId || '0';
            const folderName = galleryFolderName(row);
            const parentPath = parentId !== '0' ? (pathsById.get(parentId) || '') : '';
            const nextPath = parentPath !== '' ? `${parentPath}/${folderName}` : folderName;
            const segment = galleryUrlSegment(row);
            const parentUrlPath = parentId !== '0' ? (urlPathsById.get(parentId) || '') : '';
            const nextUrlPath = parentUrlPath !== '' ? `${parentUrlPath}/${segment}` : segment;
            const pathLabel = row.querySelector('.admin-gallery-path');
            let parentLabel = row.querySelector('.admin-gallery-parent');
            if (!parentLabel) {
                parentLabel = document.createElement('span');
                parentLabel.className = 'admin-gallery-parent';
                row.querySelector('.admin-gallery-summary-text')?.appendChild(parentLabel);
            }
            pathsById.set(id, nextPath);
            urlPathsById.set(id, nextUrlPath);
            if (pathLabel) {
                pathLabel.textContent = nextPath;
            }
            refreshGalleryLink(row, nextUrlPath);
            if (parentLabel) {
                if (parentId !== '0') {
                    parentLabel.textContent = `Parent: ${titlesById.get(parentId) || 'Gallery'}`;
                    parentLabel.hidden = false;
                } else {
                    parentLabel.textContent = '';
                    parentLabel.hidden = true;
                }
            }
        });
    }

    /**
     * Sends the complete gallery order to PHP for validation and persistence.
     *
     * @returns {Promise<void>} Promise resolved after the save attempt finishes.
     */
    async function saveGalleryTree() {
        if (saveController) {
            saveController.abort();
        }
        const bodyData = new FormData();
        bodyData.set('csrf_token', csrfInput.value);
        bodyData.set('gallery_tree', JSON.stringify(serializeGalleryTree()));
        bodyData.set('ajax', '1');

        const controller = new AbortController();
        saveController = controller;
        setStatus('Saving gallery order and nesting...', 'saving');
        try {
            const response = await fetch(reorderUrl, {
                method: 'POST',
                body: bodyData,
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            });
            const responseText = await response.text();
            let result = null;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(cleanAdminJsonParseMessage(responseText));
            }
            if (!response.ok) {
                throw new Error(result.message || 'The server rejected the gallery reorder request.');
            }
            if (!result.ok) {
                throw new Error(result.message || 'Gallery order could not be saved.');
            }
            setStatus(result.message || 'Gallery order saved.', 'saved');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            setStatus(error.message || 'Gallery order could not be saved.', 'error');
        } finally {
            if (saveController === controller) {
                saveController = null;
            }
        }
    }

    /**
     * Reads the filename value used by automatic Name-column sorting.
     *
     * PHP stores the canonical relative path in data-image-name so sorting does
     * not depend on presentation markup. The visible cell is still used as a
     * fallback for older cached markup during rolling updates.
     *
     * @param {HTMLTableRowElement} row Image row from the edit-gallery table.
     * @returns {string} Trimmed name used for locale-aware comparison.
     */
    function sortableImageName(row) {
        const fallbackCell = row.querySelector('[data-admin-image-name-cell]');
        return (row.dataset.imageName || fallbackCell?.textContent || '').trim();
    }

    /**
     * Synchronizes visual and accessibility state of the Name sorting header.
     *
     * @param {HTMLButtonElement} button Header button used to sort names.
     * @param {'asc'|'desc'} nextDirection Direction to apply on the next click.
     * @param {'asc'|'desc'} activeDirection Direction now represented by the table.
     * @returns {void}
     */
    function updateNameSortHeader(button, nextDirection, activeDirection) {
        const sortHeader = button.closest('th');
        const arrow = button.querySelector('[aria-hidden="true"]');
        button.dataset.sortDirection = nextDirection;
        button.setAttribute('aria-label', nextDirection === 'asc' ? 'Sort photos by name from A to Z' : 'Sort photos by name from Z to A');
        sortHeader?.setAttribute('aria-sort', activeDirection === 'asc' ? 'ascending' : 'descending');
        if (arrow) {
            arrow.textContent = activeDirection === 'asc' ? '↑' : '↓';
        }
    }

    /**
     * Sorts rows by filename and persists the generated order immediately.
     *
     * Automatic name sorting intentionally reuses the same save endpoint as
     * manual dragging. Server-side validation, CSRF checks, exact image-list
     * comparison, transactional sort_order updates, and admin logging therefore
     * stay identical for both ordering methods.
     *
     * @param {MouseEvent} event Click event from the Name header button.
     * @returns {void}
     */
    function handleNameSortClick(event) {
        if (draggedRow) {
            return;
        }
        const button = event.currentTarget;
        const direction = button.dataset.sortDirection === 'desc' ? 'desc' : 'asc';
        const multiplier = direction === 'asc' ? 1 : -1;
        const rows = Array.from(body.querySelectorAll('[data-admin-image-order-row]'));
        if (rows.length < 2) {
            setStatus('There is only one image, so sorting is not needed.', 'idle');
            return;
        }

        const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
        rows.map((row, index) => ({row, index, name: sortableImageName(row)}))
            .sort((left, right) => {
                const compared = collator.compare(left.name, right.name);
                if (compared !== 0) {
                    return compared * multiplier;
                }
                return left.index - right.index;
            })
            .forEach((entry) => body.appendChild(entry.row));

        updateNameSortHeader(button, direction === 'asc' ? 'desc' : 'asc', direction);
        saveOrder();
    }

    /**
     * Reads the filename value used by automatic Name-column sorting.
     *
     * @param {HTMLTableRowElement} row Image row from the edit-gallery table.
     * @returns {string} Trimmed name used for locale-aware comparison.
     */
    function sortableImageName(row) {
        const fallbackCell = row.querySelector('[data-admin-image-name-cell]');
        return (row.dataset.imageName || fallbackCell?.textContent || '').trim();
    }

    /**
     * Synchronizes visual and accessibility state of the Name sorting header.
     *
     * @param {HTMLButtonElement} button Header button used to sort names.
     * @param {'asc'|'desc'} nextDirection Direction to apply on the next click.
     * @param {'asc'|'desc'} activeDirection Direction now represented by the table.
     * @returns {void}
     */
    function updateNameSortHeader(button, nextDirection, activeDirection) {
        const sortHeader = button.closest('th');
        const arrow = button.querySelector('[aria-hidden="true"]');
        button.dataset.sortDirection = nextDirection;
        button.setAttribute('aria-label', nextDirection === 'asc' ? 'Sort photos by name from A to Z' : 'Sort photos by name from Z to A');
        sortHeader?.setAttribute('aria-sort', activeDirection === 'asc' ? 'ascending' : 'descending');
        if (arrow) {
            arrow.textContent = activeDirection === 'asc' ? '↑' : '↓';
        }
    }

    /**
     * Sorts rows by filename and persists the generated order immediately.
     *
     * @param {MouseEvent} event Click event from the Name header button.
     * @returns {void}
     */
    function handleNameSortClick(event) {
        if (draggedRow) {
            return;
        }
        const button = event.currentTarget;
        const direction = button.dataset.sortDirection === 'desc' ? 'desc' : 'asc';
        const multiplier = direction === 'asc' ? 1 : -1;
        const rows = Array.from(body.querySelectorAll('[data-admin-image-order-row]'));
        if (rows.length < 2) {
            setStatus('There is only one image, so sorting is not needed.', 'idle');
            return;
        }

        const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
        rows.map((row, index) => ({row, index, name: sortableImageName(row)}))
            .sort((left, right) => {
                const compared = collator.compare(left.name, right.name);
                if (compared !== 0) {
                    return compared * multiplier;
                }
                return left.index - right.index;
            })
            .forEach((entry) => body.appendChild(entry.row));

        updateNameSortHeader(button, direction === 'asc' ? 'desc' : 'asc', direction);
        saveOrder();
    }

    /**
     * Removes document-level movement listeners for any active input path.
     *
     * @returns {void}
     */
    function removeDocumentListeners() {
        document.removeEventListener('pointermove', handleDocumentPointerMove, true);
        document.removeEventListener('pointerup', handleDocumentPointerEnd, true);
        document.removeEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.removeEventListener('mousemove', handleDocumentMouseMove, true);
        document.removeEventListener('mouseup', handleDocumentMouseEnd, true);
        document.removeEventListener('keydown', handleDocumentKeydown, true);
    }

    /**
     * Cleans temporary drag elements and optionally inserts the moved rows at the placeholder.
     *
     * @param {boolean} commit Whether the moved rows should move to the placeholder position.
     * @returns {boolean} Whether cleanup found an active drag session.
     */
    function cleanupVisuals(commit) {
        if (draggedRows.length === 0) {
            return false;
        }
        if (commit && placeholderRow?.parentNode === body) {
            applyMovedDepths(proposedDepth);
            draggedRows.forEach((row) => {
                body.insertBefore(row, placeholderRow);
            });
        }
        draggedRows.forEach((row) => row.classList.remove('is-dragging', 'is-reorder-hidden'));
        draggedHandle?.classList.remove('is-dragging');
        ghostTable?.remove();
        placeholderRow?.remove();
        document.body.classList.remove('admin-gallery-order-active');
        delete document.body.dataset.galleryDragDirection;
        delete table.dataset.galleryDragDirection;
        removeDocumentListeners();
        draggedRows = [];
        draggedHandle = null;
        placeholderRow = null;
        ghostTable = null;
        activePointerId = null;
        activeMouseFallback = false;
        return true;
    }

    /**
     * Cancels the active gallery reorder operation.
     *
     * @returns {void}
     */
    function cancelReorder() {
        if (!cleanupVisuals(false)) {
            return;
        }
        setStatus('Gallery order unchanged.', 'idle');
    }

    /**
     * Ends the current reorder operation and persists the new tree when it changed.
     *
     * @returns {void}
     */
    function finishReorder() {
        if (draggedRows.length === 0) {
            return;
        }
        cleanupVisuals(true);
        serializeGalleryTree();
        refreshVisibleGalleryTreeMetadata();
        document.dispatchEvent(new Event('adminGalleryTreeMutated'));
        document.dispatchEvent(new Event('galleryRowsChanged'));
        if (currentGallerySignature() !== originalSignature) {
            saveGalleryTree();
            return;
        }
        setStatus('Gallery order unchanged.', 'idle');
    }

    /**
     * Handles pointer movement for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerMove(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY, event.clientX);
    }

    /**
     * Handles pointer release or cancellation for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerEnd(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Handles mouse movement for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseMove(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY, event.clientX);
    }

    /**
     * Handles mouse release for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseEnd(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Lets the admin cancel an active gallery reorder operation with Escape.
     *
     * @param {KeyboardEvent} event Key event emitted during dragging.
     * @returns {void}
     */
    function handleDocumentKeydown(event) {
        if (event.key !== 'Escape') {
            return;
        }
        event.preventDefault();
        cancelReorder();
    }

    /**
     * Returns whether a pointer target should keep its native control behavior instead of starting gallery movement.
     *
     * @param {EventTarget|null} target Original pointer or mouse target.
     * @returns {boolean} Whether the target should be ignored by the row drag controller.
     */
    function isNativeGalleryControl(target) {
        if (!(target instanceof Element)) {
            return true;
        }
        return Boolean(target.closest('a[href], input, select, textarea, button, label, [contenteditable], [data-gallery-toggle], .gallery-row-action, .admin-gallery-row-action'));
    }

    /**
     * Removes listeners for a drag candidate that never crossed the movement threshold.
     *
     * @returns {void}
     */
    function removePendingDragListeners() {
        document.removeEventListener('pointermove', handlePendingPointerMove, true);
        document.removeEventListener('pointerup', handlePendingPointerEnd, true);
        document.removeEventListener('pointercancel', handlePendingPointerEnd, true);
        document.removeEventListener('mousemove', handlePendingMouseMove, true);
        document.removeEventListener('mouseup', handlePendingMouseEnd, true);
    }

    /**
     * Clears a not-yet-started drag candidate and restores document listeners.
     *
     * @returns {void}
     */
    function clearPendingDrag() {
        removePendingDragListeners();
        pendingDrag = null;
    }

    /**
     * Starts row movement only after the pointer clearly becomes a drag gesture.
     *
     * @param {number} clientX Current viewport X coordinate.
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function maybeStartPendingDrag(clientX, clientY) {
        if (!pendingDrag || draggedRows.length > 0) {
            return;
        }
        const deltaX = clientX - pendingDrag.startX;
        const deltaY = clientY - pendingDrag.startY;
        if (Math.hypot(deltaX, deltaY) < 12) {
            return;
        }
        const candidate = pendingDrag;
        clearPendingDrag();
        suppressClickUntil = Date.now() + 450;
        startReorder(candidate.zone, candidate.startX, candidate.startY, candidate.pointerId, candidate.mouseFallback);
        moveGhost(clientY);
        movePlaceholder(clientY, clientX);
    }

    /**
     * Watches pointer movement for the gallery-column drag threshold.
     *
     * @param {PointerEvent} event Pointer movement emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingPointerMove(event) {
        if (!pendingDrag || pendingDrag.pointerId !== event.pointerId) {
            return;
        }
        maybeStartPendingDrag(event.clientX, event.clientY);
        if (draggedRows.length > 0) {
            event.preventDefault();
        }
    }

    /**
     * Clears a pointer candidate when the admin clicked without dragging.
     *
     * @param {PointerEvent} event Pointer end event emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingPointerEnd(event) {
        if (!pendingDrag || pendingDrag.pointerId !== event.pointerId) {
            return;
        }
        clearPendingDrag();
    }

    /**
     * Watches classic mouse movement for browsers that do not use Pointer Events for this input.
     *
     * @param {MouseEvent} event Mouse movement emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingMouseMove(event) {
        if (!pendingDrag || !pendingDrag.mouseFallback) {
            return;
        }
        maybeStartPendingDrag(event.clientX, event.clientY);
        if (draggedRows.length > 0) {
            event.preventDefault();
        }
    }

    /**
     * Clears a mouse candidate when the admin clicked without dragging.
     *
     * @returns {void}
     */
    function handlePendingMouseEnd() {
        if (!pendingDrag || !pendingDrag.mouseFallback) {
            return;
        }
        clearPendingDrag();
    }

    /**
     * Arms a gallery-column area so normal clicks still work and only movement starts reordering.
     *
     * @param {HTMLElement} zone Gallery-column area that can initiate row movement.
     * @param {number} clientX Starting viewport X coordinate.
     * @param {number} clientY Starting viewport Y coordinate.
     * @param {number|null} pointerId Pointer id for Pointer Events, or null for mouse fallback.
     * @param {boolean} mouseFallback Whether mouse events should be accepted for this session.
     * @returns {void}
     */
    function armGalleryDragZone(zone, clientX, clientY, pointerId, mouseFallback) {
        if (draggedRows.length > 0) {
            return;
        }
        clearPendingDrag();
        pendingDrag = {zone, startX: clientX, startY: clientY, pointerId, mouseFallback};
        if (mouseFallback) {
            document.addEventListener('mousemove', handlePendingMouseMove, true);
            document.addEventListener('mouseup', handlePendingMouseEnd, true);
            return;
        }
        document.addEventListener('pointermove', handlePendingPointerMove, true);
        document.addEventListener('pointerup', handlePendingPointerEnd, true);
        document.addEventListener('pointercancel', handlePendingPointerEnd, true);
    }

    /**
     * Starts moving the gallery subtree controlled by a gallery-column drag zone.
     *
     * @param {HTMLElement} handle Gallery-column area dragged by the admin.
     * @param {number} clientX Starting viewport X coordinate.
     * @param {number} clientY Starting viewport Y coordinate.
     * @param {number|null} pointerId Pointer id for Pointer Events, or null for mouse fallback.
     * @param {boolean} mouseFallback Whether mouse events should be accepted for this session.
     * @returns {void}
     */
    function startReorder(handle, clientX, clientY, pointerId, mouseFallback) {
        const row = handle.closest('[data-gallery-row]');
        if (!row || draggedRows.length > 0) {
            return;
        }
        draggedRows = collectMovedRows(row);
        if (draggedRows.length === 0) {
            return;
        }

        const rowBox = row.getBoundingClientRect();
        draggedHandle = handle;
        originalSignature = currentGallerySignature();
        originalDepth = rowDepth(row);
        proposedDepth = originalDepth;
        pointerOffsetY = clientY - rowBox.top;
        startClientX = clientX;
        activePointerId = pointerId;
        activeMouseFallback = mouseFallback;
        placeholderRow = createGalleryPlaceholder(draggedRows);
        ghostTable = createGalleryGhost(draggedRows);

        body.insertBefore(placeholderRow, draggedRows[draggedRows.length - 1].nextSibling);
        draggedRows.forEach((movedRow) => movedRow.classList.add('is-dragging', 'is-reorder-hidden'));
        handle.classList.add('is-dragging');
        document.body.classList.add('admin-gallery-order-active');
        moveGhost(clientY);
        applyPlaceholderDepth(originalDepth);

        document.addEventListener('pointermove', handleDocumentPointerMove, true);
        document.addEventListener('pointerup', handleDocumentPointerEnd, true);
        document.addEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.addEventListener('mousemove', handleDocumentMouseMove, true);
        document.addEventListener('mouseup', handleDocumentMouseEnd, true);
        document.addEventListener('keydown', handleDocumentKeydown, true);
    }

    body.querySelectorAll('[data-gallery-row]').forEach((row) => {
        // The native draggable attribute is disabled because custom pointer movement handles nested ordering.
        row.setAttribute('draggable', 'false');
    });

    body.querySelectorAll('[data-admin-gallery-drag-zone]').forEach((zone) => {
        // Prevents browser-provided drag images while keeping ordinary title and preview clicks usable.
        zone.setAttribute('draggable', 'false');
        zone.addEventListener('dragstart', (event) => {
            if (!isNativeGalleryControl(event.target)) {
                event.preventDefault();
            }
        });

        zone.addEventListener('click', (event) => {
            if (Date.now() <= suppressClickUntil) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        zone.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.isPrimary === false || isNativeGalleryControl(event.target)) {
                return;
            }
            armGalleryDragZone(zone, event.clientX, event.clientY, event.pointerId, false);
        });

        zone.addEventListener('mousedown', (event) => {
            if (window.PointerEvent || event.button !== 0 || draggedRows.length > 0 || isNativeGalleryControl(event.target)) {
                return;
            }
            armGalleryDragZone(zone, event.clientX, event.clientY, null, true);
        });
    });

    setStatus('Gallery ordering ready.', 'idle');
}

/**
 * Enables public gallery page card reordering for logged-in admins.
 *
 * The controller is scoped by toolbar. Subgallery cards and photo cards are
 * handled as separate lists, so the page keeps its existing galleries-first and
 * photos-underneath structure. The server receives only the visible page ids
 * plus the pagination offset/count rendered by PHP, then validates that exact
 * slice before saving.
 *
 * @returns {void}
 */
export function setupPublicGalleryPageReordering() {
    document.querySelectorAll('[data-public-reorder-toolbar]').forEach((toolbar) => {
        if (!(toolbar instanceof HTMLElement) || toolbar.dataset.publicReorderBound === '1') {
            return;
        }
        toolbar.dataset.publicReorderBound = '1';

        const kind = toolbar.dataset.reorderKind || '';
        const listSelector = `[data-public-reorder-list="${kind}"]`;
        const itemSelector = kind === 'gallery' ? '[data-public-gallery-order-item]' : '[data-public-photo-order-item]';
        const scope = toolbar.parentElement || document;
        const list = scope.querySelector(listSelector) || document.querySelector(listSelector);
        const status = toolbar.querySelector('[data-public-reorder-status]');
        const reorderUrl = toolbar.dataset.reorderUrl || '';
        const galleryId = toolbar.dataset.galleryId || '';
        const csrfToken = toolbar.dataset.csrfToken || '';
        const visibleOffset = toolbar.dataset.visibleOffset || '0';
        const visibleCount = toolbar.dataset.visibleCount || '0';

        if (!(list instanceof HTMLElement) || !reorderUrl || !galleryId || !csrfToken) {
            return;
        }

        let draggedItem = null;
        let draggedHandle = null;
        let placeholderItem = null;
        let ghostItem = null;
        let pointerOffsetX = 0;
        let pointerOffsetY = 0;
        let originalSignature = '';
        let originalItems = [];
        let activePointerId = null;
        let activeMouseFallback = false;

        /**
         * Updates the compact save status for one public reorder list.
         *
         * @param {string} message Text shown to the admin.
         * @param {string} state Visual state token used by CSS.
         * @returns {void}
         */
        function setStatus(message, state) {
            if (!status) {
                return;
            }
            status.textContent = message;
            status.dataset.state = state;
        }

        /**
         * Returns direct sortable items for the current list.
         *
         * @returns {HTMLElement[]} Sortable cards in current DOM order.
         */
        function sortableItems() {
            return Array.from(list.querySelectorAll(itemSelector))
                .filter((item) => item instanceof HTMLElement && item.parentElement === list);
        }

        /**
         * Returns direct sortable items that are still visible during dragging.
         *
         * @returns {HTMLElement[]} Cards available as insertion targets.
         */
        function availableItems() {
            return sortableItems().filter((item) => !item.classList.contains('is-public-reorder-hidden'));
        }

        /**
         * Returns the current visible id order as strings.
         *
         * @returns {string[]} Ordered ids from the current DOM.
         */
        function currentOrder() {
            return sortableItems().map((item) => item.dataset.publicOrderId || '').filter((id) => id !== '');
        }

        /**
         * Returns a compact id signature for change detection.
         *
         * @returns {string} Ordered id signature.
         */
        function currentSignature() {
            return currentOrder().join('|');
        }

        /**
         * Builds the fixed drag preview from the original card.
         *
         * @param {HTMLElement} sourceItem Card being moved.
         * @returns {HTMLElement} Fixed-position clone appended to the body.
         */
        function buildGhost(sourceItem) {
            const box = sourceItem.getBoundingClientRect();
            const ghost = sourceItem.cloneNode(true);
            ghost.classList.add('public-reorder-ghost');
            ghost.classList.remove('is-public-reorder-hidden');
            ghost.removeAttribute('data-public-gallery-order-item');
            ghost.removeAttribute('data-public-photo-order-item');
            ghost.removeAttribute('data-lightbox-image');
            ghost.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));
            ghost.style.left = `${box.left}px`;
            ghost.style.top = `${box.top}px`;
            ghost.style.width = `${box.width}px`;
            ghost.style.height = `${box.height}px`;
            document.body.appendChild(ghost);
            return ghost;
        }

        /**
         * Builds the card-shaped placeholder used as the drop marker.
         *
         * @param {HTMLElement} sourceItem Card being moved.
         * @returns {HTMLElement} Placeholder inserted into the list.
         */
        function buildPlaceholder(sourceItem) {
            const box = sourceItem.getBoundingClientRect();
            const placeholder = document.createElement(sourceItem.tagName.toLowerCase());
            placeholder.className = `public-reorder-placeholder ${kind === 'gallery' ? 'gallery-card' : 'image-card'}`;
            placeholder.setAttribute('aria-hidden', 'true');
            placeholder.style.minHeight = `${Math.max(96, box.height)}px`;
            placeholder.innerHTML = `<span>${kind === 'gallery' ? 'Drop gallery here' : 'Drop photo here'}</span>`;
            return placeholder;
        }

        /**
         * Returns the next real item after a target, skipping temporary nodes.
         *
         * @param {HTMLElement} target Current target card.
         * @returns {HTMLElement|null} Next insertion reference, or null to append.
         */
        function nextRealItem(target) {
            let next = target.nextElementSibling;
            while (next) {
                if (next instanceof HTMLElement && next.matches(itemSelector) && !next.classList.contains('is-public-reorder-hidden')) {
                    return next;
                }
                next = next.nextElementSibling;
            }
            return null;
        }

        /**
         * Returns the card closest to the pointer when the pointer is over a gap.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {HTMLElement|null} Nearest sortable card.
         */
        function nearestItem(clientX, clientY) {
            let closestItem = null;
            let closestDistance = Number.POSITIVE_INFINITY;
            availableItems().forEach((item) => {
                const box = item.getBoundingClientRect();
                const centerX = box.left + (box.width / 2);
                const centerY = box.top + (box.height / 2);
                const distance = Math.hypot(clientX - centerX, clientY - centerY);
                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestItem = item;
                }
            });
            return closestItem;
        }

        /**
         * Returns the best insertion target for the current pointer position.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {{target: HTMLElement|null, after: boolean}} Target card and side.
         */
        function insertionTarget(clientX, clientY) {
            const directTarget = document.elementFromPoint(clientX, clientY)?.closest(itemSelector);
            const target = directTarget instanceof HTMLElement && directTarget.parentElement === list && !directTarget.classList.contains('is-public-reorder-hidden')
                ? directTarget
                : nearestItem(clientX, clientY);
            if (!target) {
                return {target: null, after: false};
            }
            const box = target.getBoundingClientRect();
            const pointerWithinRow = clientY >= box.top && clientY <= box.bottom;
            const after = pointerWithinRow ? clientX > box.left + (box.width / 2) : clientY > box.top + (box.height / 2);
            return {target, after};
        }

        /**
         * Moves the placeholder to the candidate drop position.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {void}
         */
        function movePlaceholder(clientX, clientY) {
            if (!placeholderItem) {
                return;
            }
            const insertion = insertionTarget(clientX, clientY);
            if (!insertion.target) {
                list.appendChild(placeholderItem);
                return;
            }
            const reference = insertion.after ? nextRealItem(insertion.target) : insertion.target;
            list.insertBefore(placeholderItem, reference);
        }

        /**
         * Moves the fixed ghost to follow the pointer.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {void}
         */
        function moveGhost(clientX, clientY) {
            if (!ghostItem) {
                return;
            }
            ghostItem.style.left = `${clientX - pointerOffsetX}px`;
            ghostItem.style.top = `${clientY - pointerOffsetY}px`;
        }

        /**
         * Restores DOM order when the server rejects a save.
         *
         * @returns {void}
         */
        function restoreOriginalOrder() {
            originalItems.forEach((item) => {
                list.appendChild(item);
            });
        }

        /**
         * Keeps hidden lightbox source metadata aligned with a visible photo reorder.
         *
         * @param {string[]} orderedIds Visible photo ids after the drop.
         * @returns {void}
         */
        function syncLightboxSourceOrder(orderedIds) {
            if (kind !== 'photo') {
                return;
            }
            const sourceList = document.querySelector('.lightbox-source-list');
            if (!(sourceList instanceof HTMLElement)) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const sourceNodes = Array.from(sourceList.querySelectorAll('[data-lightbox-source]'));
            const sourceById = new Map(sourceNodes.map((node) => [node.dataset.imageId || '', node]));
            const indexes = orderedIds.map((id) => sourceNodes.findIndex((node) => (node.dataset.imageId || '') === id));
            if (indexes.some((index) => index < 0)) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const sortedIndexes = indexes.slice().sort((left, right) => left - right);
            const startIndex = sortedIndexes[0];
            const isContiguous = sortedIndexes.every((index, offset) => index === startIndex + offset);
            if (!isContiguous) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const nextNodes = sourceNodes.slice();
            orderedIds.forEach((id, offset) => {
                const node = sourceById.get(id);
                if (node) {
                    nextNodes[startIndex + offset] = node;
                }
            });
            nextNodes.forEach((node) => sourceList.appendChild(node));
            document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
        }

        /**
         * Persists the current visible order to the matching PHP endpoint.
         *
         * @param {string[]} orderedIds Current visible ids after the drop.
         * @returns {Promise<void>} Resolves after save handling completes.
         */
        async function saveOrder(orderedIds) {
            const body = new FormData();
            body.set('csrf_token', csrfToken);
            body.set('gallery_id', galleryId);
            body.set('visible_offset', visibleOffset);
            body.set('visible_count', visibleCount);
            body.set('ajax', '1');
            if (kind === 'gallery') {
                body.set('gallery_order', JSON.stringify(orderedIds));
            } else {
                body.set('image_order', JSON.stringify(orderedIds));
                body.set('reorder_scope', 'visible_page');
            }

            setStatus('Saving visible page order...', 'saving');
            try {
                const response = await fetch(reorderUrl, {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'},
                });
                const text = await response.text();
                let result = null;
                try {
                    result = JSON.parse(text);
                } catch (parseError) {
                    throw new Error('The server returned HTML or text instead of JSON. Check the admin logs or PHP error log.');
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'Visible page order could not be saved.');
                }
                setStatus(result.message || 'Visible page order saved.', 'saved');
                syncLightboxSourceOrder(orderedIds);
                document.dispatchEvent(new CustomEvent('php-gallery:public-visible-order-saved', {
                    detail: {
                        kind,
                        galleryId,
                        orderedIds,
                        result,
                    },
                }));
            } catch (error) {
                restoreOriginalOrder();
                setStatus(error.message || 'Visible page order could not be saved.', 'error');
            }
        }

        /**
         * Removes temporary drag state.
         *
         * @param {boolean} commit Whether to insert the moved item at the placeholder.
         * @returns {void}
         */
        function cleanupDrag(commit) {
            document.removeEventListener('pointermove', handlePointerMove, true);
            document.removeEventListener('pointerup', handlePointerEnd, true);
            document.removeEventListener('pointercancel', handlePointerCancel, true);
            document.removeEventListener('mousemove', handleMouseMove, true);
            document.removeEventListener('mouseup', handleMouseEnd, true);
            document.removeEventListener('keydown', handleKeydown, true);

            if (commit && draggedItem && placeholderItem?.parentElement === list) {
                list.insertBefore(draggedItem, placeholderItem);
            }
            draggedItem?.classList.remove('is-public-reorder-hidden');
            draggedHandle?.classList.remove('is-dragging');
            placeholderItem?.remove();
            ghostItem?.remove();
            document.body.classList.remove('public-reorder-active');
            draggedItem = null;
            draggedHandle = null;
            placeholderItem = null;
            ghostItem = null;
            activePointerId = null;
            activeMouseFallback = false;
        }

        /**
         * Handles pointer or mouse movement during an active drag.
         *
         * @param {MouseEvent|PointerEvent} event Movement event.
         * @returns {void}
         */
        function handleMove(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            moveGhost(event.clientX, event.clientY);
            movePlaceholder(event.clientX, event.clientY);
        }

        /**
         * Handles the end of a pointer or mouse drag.
         *
         * @param {MouseEvent|PointerEvent} event Release event.
         * @returns {void}
         */
        function finishDrag(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            cleanupDrag(true);
            const nextSignature = currentSignature();
            if (nextSignature === originalSignature) {
                setStatus('Order unchanged.', 'idle');
                return;
            }
            saveOrder(currentOrder());
        }

        /**
         * Cancels the active drag and leaves the DOM unchanged.
         *
         * @param {Event} event Cancellation event.
         * @returns {void}
         */
        function cancelDrag(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            cleanupDrag(false);
            setStatus('Order unchanged.', 'idle');
        }

        /**
         * Handles pointer movement for the active drag.
         *
         * @param {PointerEvent} event Pointer movement event.
         * @returns {void}
         */
        function handlePointerMove(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            handleMove(event);
        }

        /**
         * Handles pointer release for the active drag.
         *
         * @param {PointerEvent} event Pointer release event.
         * @returns {void}
         */
        function handlePointerEnd(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            finishDrag(event);
        }

        /**
         * Handles pointer cancellation for the active drag.
         *
         * @param {PointerEvent} event Pointer cancellation event.
         * @returns {void}
         */
        function handlePointerCancel(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            cancelDrag(event);
        }

        /**
         * Handles mouse movement for browsers without PointerEvent support.
         *
         * @param {MouseEvent} event Mouse movement event.
         * @returns {void}
         */
        function handleMouseMove(event) {
            if (!activeMouseFallback) {
                return;
            }
            handleMove(event);
        }

        /**
         * Handles mouse release for browsers without PointerEvent support.
         *
         * @param {MouseEvent} event Mouse release event.
         * @returns {void}
         */
        function handleMouseEnd(event) {
            if (!activeMouseFallback) {
                return;
            }
            finishDrag(event);
        }

        /**
         * Lets the admin cancel a drag with Escape.
         *
         * @param {KeyboardEvent} event Keyboard event.
         * @returns {void}
         */
        function handleKeydown(event) {
            if (event.key === 'Escape') {
                cancelDrag(event);
            }
        }

        /**
         * Starts card movement from a dedicated handle.
         *
         * @param {MouseEvent|PointerEvent} event Initial press event.
         * @param {boolean} mouseFallback Whether classic mouse events own this drag.
         * @returns {void}
         */
        function startDrag(event, mouseFallback) {
            const handle = event.target instanceof Element ? event.target.closest('[data-public-reorder-handle]') : null;
            const item = handle instanceof HTMLElement ? handle.closest(itemSelector) : null;
            if (!(handle instanceof HTMLElement) || !(item instanceof HTMLElement) || item.parentElement !== list || event.button !== 0 || draggedItem) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            const itemBox = item.getBoundingClientRect();
            draggedItem = item;
            draggedHandle = handle;
            originalSignature = currentSignature();
            originalItems = sortableItems();
            pointerOffsetX = event.clientX - itemBox.left;
            pointerOffsetY = event.clientY - itemBox.top;
            activePointerId = mouseFallback ? null : event.pointerId;
            activeMouseFallback = mouseFallback;
            placeholderItem = buildPlaceholder(item);
            ghostItem = buildGhost(item);

            list.insertBefore(placeholderItem, item.nextElementSibling);
            item.classList.add('is-public-reorder-hidden');
            handle.classList.add('is-dragging');
            document.body.classList.add('public-reorder-active');
            setStatus(`Dragging visible ${kind === 'gallery' ? 'gallery' : 'photo'}...`, 'dragging');
            moveGhost(event.clientX, event.clientY);
            movePlaceholder(event.clientX, event.clientY);

            if (mouseFallback) {
                document.addEventListener('mousemove', handleMouseMove, true);
                document.addEventListener('mouseup', handleMouseEnd, true);
            } else {
                document.addEventListener('pointermove', handlePointerMove, true);
                document.addEventListener('pointerup', handlePointerEnd, true);
                document.addEventListener('pointercancel', handlePointerCancel, true);
            }
            document.addEventListener('keydown', handleKeydown, true);
        }

        list.querySelectorAll('[data-public-reorder-handle]').forEach((handle) => {
            handle.setAttribute('draggable', 'false');
            handle.addEventListener('dragstart', (event) => event.preventDefault());
            handle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });
            handle.addEventListener('pointerdown', (event) => {
                if (event.isPrimary === false) {
                    return;
                }
                startDrag(event, false);
            });
            handle.addEventListener('mousedown', (event) => {
                if (window.PointerEvent) {
                    return;
                }
                startDrag(event, true);
            });
        });

        setStatus('Drag handles ready.', 'idle');
    });
}
