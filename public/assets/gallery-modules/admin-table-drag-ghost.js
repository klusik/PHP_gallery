/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-table-drag-ghost.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides shared table drag visuals for Admin reordering modules.
 *
 * Responsibilities:
 *   - Clone table rows into fixed-position drag ghosts
 *   - Preserve column widths while the clone is outside the source table
 *   - Build height-matched placeholder rows for table insertion previews
 *   - Keep form fields inside visual clones from being submitted accidentally
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

/**
 * Returns table rows as a safe array.
 *
 * @param {HTMLTableRowElement|HTMLTableRowElement[]} rows One row or a list of rows.
 * @returns {HTMLTableRowElement[]} Normalized row list.
 */
function normalizeRows(rows) {
    if (Array.isArray(rows)) {
        return rows.filter((row) => row instanceof HTMLTableRowElement);
    }
    return rows instanceof HTMLTableRowElement ? [rows] : [];
}

/**
 * Copies current column widths from a real row into a cloned row.
 *
 * Table cells otherwise shrink to their content when cloned into a fixed table
 * outside the original layout. Explicit widths keep drag visuals aligned with
 * the source table while the pointer moves.
 *
 * @param {HTMLTableRowElement} sourceRow Real row being cloned.
 * @param {HTMLTableRowElement} cloneRow Cloned row shown inside the drag ghost.
 * @returns {void}
 */
export function copyTableCellWidths(sourceRow, cloneRow) {
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
 * Removes submit-sensitive attributes from a cloned row.
 *
 * Drag ghosts are purely visual. Removing `name` attributes prevents cloned
 * inputs from becoming accidental form participants if browser extensions or
 * custom scripts inspect the document during a drag session.
 *
 * @param {HTMLElement} clone Cloned row or clone container.
 * @param {string[]} removeAttributes Additional data attributes to remove from the cloned row.
 * @returns {void}
 */
function sanitizeGhostClone(clone, removeAttributes) {
    removeAttributes.forEach((attributeName) => {
        if (attributeName !== '') {
            clone.removeAttribute(attributeName);
        }
    });
    clone.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));
}

/**
 * Creates a fixed-position table ghost from the first supplied source row.
 *
 * Multi-row gallery moves still use the first row as their visible drag proxy;
 * the caller can represent the total moved height through the placeholder.
 *
 * @param {HTMLTableRowElement|HTMLTableRowElement[]} rows One row or a list of moved rows.
 * @param {{className?: string, removeAttributes?: string[]}} options Visual and cleanup options.
 * @returns {HTMLTableElement|null} Ghost table appended to the document body, or null when no row is available.
 */
export function createTableDragGhost(rows, options = {}) {
    const sourceRows = normalizeRows(rows);
    const sourceRow = sourceRows[0] || null;
    if (!sourceRow) {
        return null;
    }

    const rowBox = sourceRow.getBoundingClientRect();
    const ghost = document.createElement('table');
    const ghostBody = document.createElement('tbody');
    const clonedRow = sourceRow.cloneNode(true);
    const className = typeof options.className === 'string' ? options.className.trim() : '';
    const removeAttributes = Array.isArray(options.removeAttributes) ? options.removeAttributes : [];

    copyTableCellWidths(sourceRow, clonedRow);
    clonedRow.classList.add('is-ghost-row');
    sanitizeGhostClone(clonedRow, removeAttributes);

    ghost.className = className !== '' ? className : 'admin-table-drag-ghost';
    ghost.style.width = `${rowBox.width}px`;
    ghost.style.left = `${rowBox.left}px`;
    ghost.appendChild(ghostBody);
    ghostBody.appendChild(clonedRow);
    document.body.appendChild(ghost);
    return ghost;
}

/**
 * Creates a height-matched table placeholder for the supplied row list.
 *
 * @param {HTMLTableRowElement|HTMLTableRowElement[]} rows One row or the moved row list.
 * @param {{className?: string, minHeight?: number}} options Visual options for the placeholder.
 * @returns {HTMLTableRowElement|null} Placeholder row ready to insert, or null when no source row is available.
 */
export function createTableDragPlaceholder(rows, options = {}) {
    const sourceRows = normalizeRows(rows);
    const sourceRow = sourceRows[0] || null;
    if (!sourceRow) {
        return null;
    }

    const placeholder = document.createElement('tr');
    const cell = document.createElement('td');
    const className = typeof options.className === 'string' ? options.className.trim() : '';
    const minHeight = Number.isFinite(Number(options.minHeight)) ? Number(options.minHeight) : 32;
    const totalHeight = sourceRows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);

    placeholder.className = className;
    placeholder.setAttribute('aria-hidden', 'true');
    cell.colSpan = Math.max(1, sourceRow.children.length);
    cell.style.height = `${Math.max(minHeight, totalHeight)}px`;
    placeholder.appendChild(cell);
    return placeholder;
}

/**
 * Moves a fixed table ghost vertically using the pointer offset captured at drag start.
 *
 * @param {HTMLTableElement|null} ghost Ghost table returned by createTableDragGhost().
 * @param {number} clientY Current pointer Y coordinate.
 * @param {number} pointerOffsetY Pointer distance from the source row top.
 * @returns {void}
 */
export function moveTableDragGhostY(ghost, clientY, pointerOffsetY) {
    if (!ghost) {
        return;
    }
    ghost.style.top = `${clientY - pointerOffsetY}px`;
}
