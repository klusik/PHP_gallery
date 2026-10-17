/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-pdf-metadata.js
 * Module Type: Browser Module
 * Purpose: Share authorized PDF page counts between public and editor surfaces.
 * Responsibilities: Serialize metadata reads and preserve explicit unknown totals.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {
    createPdfPngUploadStream,
} from './admin-pdf-conversion.js?v=20261012-pdf-dpi-warning-v3';

/** @type {WeakSet<HTMLElement>} Cells already admitted to the shared reader. */
const seen = new WeakSet();
/** @type {Map<string, Promise<number|null>>} Counts by protected URL/version. */
const counts = new Map();
/** @type {HTMLElement[]} Connected cells awaiting serialized metadata work. */
const queue = [];
let running = false;

/**
 * Scan protected PDF row metadata without rendering a page or opening its list.
 * @param {ParentNode} root Document or newly mounted attachment fragment.
 * @returns {void} Admit each cell once and share its exact-version count.
 */
export function scanPdfPageCountCells(root) {
    const cells = [...root.querySelectorAll('[data-pdf-page-count]')];
    if (root instanceof Element && root.matches('[data-pdf-page-count]')) {
        cells.unshift(root);
    }
    for (const cell of cells) {
        if (seen.has(cell) || !cell.dataset.pdfUrl) continue;
        seen.add(cell);
        cell.title = cell.dataset.pageCountTitle || '';
        queue.push(cell);
    }
    void drainPdfPageCounts();
}

/**
 * Aggregate every authorized row, retaining pending and unknown metadata states.
 * @param {Element} section Public PDF section containing its own total.
 * @returns {void} Show complete page count only after all rows are known.
 */
function updatePdfPageTotal(section) {
    const total = section.querySelector('[data-pdf-page-total]');
    if (!total) return;
    const cells = [...section.querySelectorAll('[data-pdf-page-count]')];
    const ready = cells.every(cell => cell.dataset.pdfPagesLoaded === '1');
    const known = cells.every(cell => cell.dataset.pdfPages !== undefined);
    total.textContent = !ready ? '…' : !known ? '—' : String(
        cells.reduce((sum, cell) => sum + Number(cell.dataset.pdfPages), 0)
    );
    if (ready && known) total.removeAttribute('title');
}

/**
 * Open metadata through the established protected PDF stream, then release it.
 * @param {string} url Same-origin authorized original URL from rendered markup.
 * @returns {Promise<number|null>} Positive page count, or explicit unknown.
 */
async function readPdfPageCount(url) {
    let stream = null;
    try {
        stream = await createPdfPngUploadStream(url, {});
        return stream.pageCount;
    } catch {
        return null;
    } finally {
        if (stream) await stream.dispose();
    }
}

/**
 * Drain the shared queue without overlapping PDF decoders or stale DOM updates.
 * @returns {Promise<void>} Update surviving row cells and their public totals.
 */
async function drainPdfPageCounts() {
    if (running) return;
    running = true;
    try {
        while (queue.length) {
            const cell = queue.shift();
            if (!cell.isConnected) continue;
            const key = JSON.stringify([
                cell.dataset.pdfUrl, cell.dataset.pdfVersion || '',
            ]);
            if (!counts.has(key)) {
                counts.set(key, readPdfPageCount(cell.dataset.pdfUrl));
            }
            const pages = await counts.get(key);
            if (!cell.isConnected) continue;
            cell.dataset.pdfPagesLoaded = '1';
            cell.textContent = pages === null ? '—' : String(pages);
            if (pages !== null) {
                cell.dataset.pdfPages = String(pages);
                cell.removeAttribute('title');
            }
            const section = cell.closest('[data-gallery-pdf-documents]');
            if (section) updatePdfPageTotal(section);
        }
    } finally {
        running = false;
    }
}
