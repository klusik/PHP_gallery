/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-images-preload.js
 * Module Type: Browser Module
 * Purpose: Prepare the drawer Images table without blocking unrelated settings.
 * Responsibilities: Own bounded batches, thumbnail settlement, progress and cancellation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {captureAdminPanelOwner} from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

// Bound background resource pressure while leaving room for interactive requests.
// Type: number; Units: simultaneous thumbnails; Scope: one editor;
// Consumers: preloadEditorThumbnails; Rationale: six workers within the reviewed 4–8 range.
const thumbnailWorkers = 6;
// Stop failed resources from holding the tab unavailable indefinitely.
// Type: number; Units: milliseconds; Scope: one thumbnail or metadata request;
// Consumers: preloadEditorThumbnails and fetchEditorBatch; Rationale: finite recovery on stalled hosting.
const requestTimeoutMs = 20000;
// Yield after small DOM batches so row construction does not monopolize input handling.
// Type: number; Units: rows; Scope: one insertion task;
// Consumers: startGalleryImagesPreload; Rationale: insert no more than twenty rows per task.
const insertionRows = 20;

/**
 * Settle actual table thumbnails through a bounded pool without duplicate bitmap copies.
 * @param {HTMLImageElement[]} images One bounded batch of actual table thumbnail elements.
 * @param {AbortSignal} signal Current editor cancellation signal.
 * @param {function(boolean):void} settled Receives success or explicit failure once per resource.
 * @param {number} workers Maximum simultaneous thumbnail requests.
 * @param {number} timeoutMs Deadline for each thumbnail load and decode.
 * @returns {Promise<void>} Settles all resources or rejects on cancellation.
 */
export async function preloadEditorThumbnails(images, signal, settled, workers = thumbnailWorkers, timeoutMs = requestTimeoutMs) {
    if (!Number.isInteger(workers) || workers < 1 || workers > 8 || !Number.isFinite(timeoutMs) || timeoutMs <= 0) throw new RangeError('Invalid preload bounds.');
    let next = 0;
    await Promise.all(Array.from({length: Math.min(workers, images.length)}, async () => {
        while (next < images.length) {
            signal.throwIfAborted();
            const image = images[next++];
            const loaded = await new Promise((resolve, reject) => {
                let finished = false;
                const finish = success => {
                    if (finished) return;
                    finished = true;
                    clearTimeout(timer);
                    image.removeEventListener('load', loaded);
                    image.removeEventListener('error', failed);
                    signal.removeEventListener('abort', cancelled);
                    resolve(success);
                };
                const loaded = () => {
                    if (typeof image.decode === 'function') image.decode().then(() => finish(true), () => finish(false));
                    else finish(true);
                };
                const failed = () => finish(false);
                const cancelled = () => {
                    image.removeAttribute('src');
                    finish(false);
                    reject(signal.reason || new DOMException('Cancelled', 'AbortError'));
                };
                const timer = setTimeout(() => finish(false), timeoutMs);
                signal.addEventListener('abort', cancelled, {once: true});
                image.addEventListener('load', loaded);
                image.addEventListener('error', failed);
                image.loading = 'eager';
                image.fetchPriority = 'low';
                image.src = image.dataset.adminPreloadSrc;
                if (image.complete) queueMicrotask(() => image.naturalWidth > 0 ? loaded() : failed());
            });
            signal.throwIfAborted();
            if (!loaded) {
                image.removeAttribute('src');
                image.dataset.adminThumbnailFailed = '1';
            }
            settled(loaded);
        }
    }));
}

/**
 * Fetch one bounded same-origin batch with a finite transport deadline.
 * @param {URL} url Authorized editor endpoint with its current offset/revision.
 * @param {AbortSignal} signal Current editor cancellation signal.
 * @returns {Promise<{ok:boolean,gallery_id:number,offset:number,next_offset:number,total:number,done:boolean,revision:string,rows_html:string,shell_html:string}>} Parsed response; caller validates the complete contract.
 */
async function fetchEditorBatch(url, signal) {
    const controller = new AbortController();
    const cancel = () => controller.abort(signal.reason);
    signal.addEventListener('abort', cancel, {once: true});
    const timer = setTimeout(() => controller.abort(new DOMException('Timed out', 'TimeoutError')), requestTimeoutMs);
    try {
        const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return await response.json();
    } finally {
        clearTimeout(timer);
        signal.removeEventListener('abort', cancel);
    }
}

/**
 * Start one fragment-owned background preparation after its settings are interactive.
 * Retry replaces only Images content; it never reads or rewrites the settings form.
 * @param {HTMLElement} widget Current editor's top progress widget and transport metadata.
 * @param {HTMLElement} imagesPanel Existing unselected Images tab panel.
 * @param {function():void} ready Initialize existing image controls and unlock the tab.
 * @returns {void} Bind explicit retry and launch a handled, cancellable background task.
 */
export function startGalleryImagesPreload(widget, imagesPanel, ready) {
    if (widget.dataset.preloadBound === '1') return;
    widget.dataset.preloadBound = '1';
    const panel = widget.closest('[data-admin-side-panel]');
    const owner = captureAdminPanelOwner(panel);
    const controller = new AbortController();
    const current = () => !controller.signal.aborted && widget.isConnected && imagesPanel.isConnected && (!panel || owner.isCurrent());
    const cancel = () => controller.abort();
    owner.signal?.addEventListener('abort', cancel, {once: true});
    const observer = new MutationObserver(() => { if (!current()) cancel(); });
    observer.observe(widget.parentElement, {childList: true, subtree: true});
    const status = widget.querySelector('[data-images-preload-status]');
    const announcement = widget.querySelector('[data-images-preload-announcement]');
    const bar = widget.querySelector('[role="progressbar"]');
    const fill = widget.querySelector('[data-images-preload-fill]');
    const retry = widget.querySelector('[data-images-preload-retry]');
    const tab = widget.parentElement.querySelector('[data-admin-tab-target="admin-edit-images"]');
    const initialEmpty = Number(widget.dataset.total) === 0 && Boolean(imagesPanel.querySelector('[data-admin-image-order-table]')) && !widget.dataset.preloadState;
    let firstRun = true;
    let running = false;
    let settledCount = 0;
    let failures = 0;
    let total = Number(widget.dataset.total);
    const progress = () => {
        if (!current()) return;
        // Never report complete until end-of-list validation and resource settlement agree.
        const percent = total > 0 ? Math.min(99, Math.floor(settledCount * 100 / total)) : 0;
        bar.setAttribute('aria-valuenow', String(percent));
        fill.style.width = percent + '%';
        status.textContent = widget.dataset.labelProgress.replace('{current}', String(settledCount)).replace('{total}', String(total));
        bar.setAttribute('aria-valuetext', status.textContent);
    };
    const run = async () => {
        if (running || !current() || controller.signal.aborted) return;
        running = true;
        const skipBatch = firstRun && initialEmpty;
        firstRun = false;
        settledCount = 0;
        failures = 0;
        retry.hidden = true;
        widget.hidden = false;
        widget.dataset.preloadState = 'loading';
        imagesPanel.inert = true;
        tab?.setAttribute('aria-disabled', 'true');
        tab?.setAttribute('tabindex', '-1');
        widget.dispatchEvent(new CustomEvent('php-gallery:images-loading', {bubbles: true}));
        bar.removeAttribute('aria-valuenow');
        bar.removeAttribute('aria-valuetext');
        fill.style.width = '';
        status.textContent = widget.dataset.labelPreparing;
        announcement.textContent = '';
        let offset = 0;
        let revision = '';
        const seen = new Set();
        try {
            while (!skipBatch) {
                controller.signal.throwIfAborted();
                const url = new URL(widget.dataset.endpoint, window.location.href);
                if (url.origin !== window.location.origin) throw new Error('Invalid image endpoint.');
                url.searchParams.set('offset', String(offset));
                if (revision) url.searchParams.set('revision', revision);
                const payload = await fetchEditorBatch(url, controller.signal);
                if (!current()) return;
                if (!payload || payload.ok !== true || payload.gallery_id !== Number(widget.dataset.galleryId)
                    || payload.offset !== offset || !Number.isSafeInteger(payload.total) || payload.total < 0
                    || !Number.isSafeInteger(payload.next_offset) || payload.next_offset < offset || payload.next_offset > payload.total
                    || typeof payload.done !== 'boolean' || payload.done !== (payload.next_offset === payload.total)
                    || typeof payload.revision !== 'string' || !/^[a-f0-9]{64}$/.test(payload.revision)
                    || (revision && (revision !== payload.revision || total !== payload.total))
                    || typeof payload.rows_html !== 'string' || typeof payload.shell_html !== 'string') throw new Error('Invalid image batch.');
                total = payload.total;
                revision = payload.revision;
                if (offset === 0) {
                    const shell = document.createElement('template');
                    shell.innerHTML = payload.shell_html;
                    const content = shell.content.querySelector('[data-admin-tab-panel]');
                    if (!content || content.id !== 'admin-edit-images') throw new Error('Invalid image shell.');
                    imagesPanel.replaceChildren(...content.childNodes);
                }
                const body = imagesPanel.querySelector('[data-admin-image-order-table] tbody');
                if (!body) throw new Error('Missing image table.');
                const batch = document.createElement('template');
                // Parse rows in a table context, with sources withheld until workers admit them.
                batch.innerHTML = '<table><tbody>' + payload.rows_html + '</tbody></table>';
                const rows = Array.from(batch.content.querySelector('tbody').children);
                if (rows.length > 100 || rows.length !== payload.next_offset - offset || (!payload.done && rows.length === 0)) throw new Error('Incomplete image batch.');
                for (const row of rows) {
                    const id = row.dataset.imageId;
                    if (row.tagName !== 'TR' || !/^[1-9][0-9]*$/.test(id || '') || seen.has(id)) throw new Error('Invalid image identity.');
                    seen.add(id);
                    const thumbnail = row.querySelector('img[data-admin-preload-src]');
                    if (!thumbnail || row.querySelectorAll('img').length !== 1 || thumbnail.hasAttribute('src')) throw new Error('Invalid thumbnail batch.');
                    const source = new URL(thumbnail.dataset.adminPreloadSrc, window.location.href);
                    if (source.origin !== window.location.origin) throw new Error('Invalid thumbnail origin.');
                }
                for (let index = 0; index < rows.length; index += insertionRows) {
                    await new Promise(resolve => setTimeout(resolve, 0));
                    controller.signal.throwIfAborted();
                    if (!current()) return;
                    body.append(...rows.slice(index, index + insertionRows));
                }
                progress();
                await preloadEditorThumbnails(rows.map(row => row.querySelector('img')), controller.signal, loaded => {
                    settledCount += 1;
                    if (!loaded) failures += 1;
                    progress();
                });
                if (!current()) return;
                offset = payload.next_offset;
                if (payload.done) break;
            }
            if (seen.size !== total || settledCount !== total) throw new Error('Incomplete image inventory.');
            if (!current()) return;
            imagesPanel.inert = false;
            tab?.removeAttribute('aria-disabled');
            tab?.setAttribute('href', '#admin-edit-images');
            widget.dataset.preloadState = failures ? 'ready-with-errors' : 'ready';
            bar.setAttribute('aria-valuenow', '100');
            fill.style.width = '100%';
            status.textContent = failures ? widget.dataset.labelFailed.replace('{count}', String(failures)) : widget.dataset.labelReady;
            announcement.textContent = status.textContent;
            bar.setAttribute('aria-valuetext', status.textContent);
            imagesPanel.querySelectorAll('[data-admin-thumbnail-failed]').forEach(image => { image.alt = widget.dataset.labelFailed.replace('{count}', '1'); });
            retry.hidden = failures === 0;
            ready();
            if (!failures) {
                bar.hidden = true;
                status.hidden = true;
            }
        } catch (error) {
            if (!current() || controller.signal.aborted) return;
            widget.dataset.preloadState = 'error';
            status.textContent = widget.dataset.labelError + (error instanceof Error && /^HTTP [0-9]{3}$/.test(error.message) ? ' (' + error.message + ')' : '');
            announcement.textContent = status.textContent;
            retry.hidden = false;
            bar.removeAttribute('aria-valuenow');
            bar.removeAttribute('aria-valuetext');
        } finally {
            running = false;
            if (!current() || controller.signal.aborted) {
                observer.disconnect();
                owner.signal?.removeEventListener('abort', cancel);
            }
        }
    };
    const onRetry = () => {
        bar.hidden = false;
        status.hidden = false;
        void run();
    };
    const saved = event => {
        if (!current() || event.detail?.source !== 'gallery-edit' || event.detail?.result?.ok !== true) return;
        // The existing save acknowledgment may preserve later typing instead of
        // replacing this editor. Restart photo metadata without touching that draft.
        cancel();
        observer.disconnect();
        owner.signal?.removeEventListener('abort', cancel);
        retry.removeEventListener('click', onRetry);
        widget.parentElement.removeEventListener('php-gallery:side-panel-success', saved);
        widget.dataset.preloadBound = '0';
        bar.hidden = false;
        status.hidden = false;
        startGalleryImagesPreload(widget, imagesPanel, ready);
    };
    retry.addEventListener('click', onRetry);
    widget.parentElement.addEventListener('php-gallery:side-panel-success', saved);
    controller.signal.addEventListener('abort', () => {
        observer.disconnect();
        owner.signal?.removeEventListener('abort', cancel);
        retry.removeEventListener('click', onRetry);
        widget.parentElement?.removeEventListener('php-gallery:side-panel-success', saved);
    }, {once: true});
    // One task boundary lets the mounted editor paint before admitting photo work.
    setTimeout(() => { void run(); }, 0);
}
