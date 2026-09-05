/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/gallery-download.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Runs progressive gallery downloads and assembles the final ZIP in the browser.
 *
 * Responsibilities:
 *   - Fetch and validate the authorized gallery download manifest
 *   - Stream source files sequentially into a standards-compliant ZIP writer
 *   - Prefer direct-to-disk File System Access output and bound Blob fallback memory
 *   - Expose measured progress, cancellation, retry, and safe error handling
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

import {ZipStreamWriter} from './zip-stream-writer.js?v=20260831-client-zip-v1';

const DEFAULT_WARNING_BYTES = 256 * 1024 * 1024;
const DEFAULT_MAX_MEMORY_BYTES = 512 * 1024 * 1024;
const DOWNLOAD_CAPABILITY_HEADER = 'X-PHP-Gallery-Download-Capability';
const MAX_DOWNLOAD_CAPABILITY_LENGTH = 4096;
const CAPABILITY_RENEWAL_SAFETY_SECONDS = 30;

/** Stable error used when a progressive bearer capability must be renewed. */
class DownloadCapabilityRejectedError extends Error {
    /** Create the stable client-side signal for an expired or rejected capability. */
    constructor() {
        super(tr('download.progress.authorization_expired', 'Download authorization expired. Retry to restart the download safely.'));
        this.name = 'DownloadCapabilityRejectedError';
    }
}

/** strings supports the browser ZIP download workflow. */
function strings() {
    return globalThis.window?.PHP_GALLERY_I18N?.strings || {};
}

/** tr supports the browser ZIP download workflow. */
function tr(key, fallback, replacements = {}) {
    let text = String(strings()[key] ?? fallback);
    Object.entries(replacements).forEach(([name, value]) => {
        text = text.replaceAll(`{${name}}`, String(value));
    });
    return text;
}

/** formatBytes supports the browser ZIP download workflow. */
function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    if (value < 1024) return `${value} B`;
    const units = ['KiB', 'MiB', 'GiB', 'TiB'];
    let scaled = value;
    let index = -1;
    do {
        scaled /= 1024;
        index += 1;
    } while (scaled >= 1024 && index < units.length - 1);
    return `${scaled >= 10 ? scaled.toFixed(1) : scaled.toFixed(2)} ${units[index]}`;
}

/** validGalleryDownloadManifest supports the browser ZIP download workflow. */
export function validGalleryDownloadManifest(payload) {
    if (!payload || payload.ok !== true || !Array.isArray(payload.files)) return false;
    if (!Number.isSafeInteger(payload.total_files) || payload.total_files < 0) return false;
    if (!Number.isSafeInteger(payload.total_bytes) || payload.total_bytes < 0) return false;
    if (payload.total_files !== payload.files.length) return false;
    const usedNames = new Set();
    let sum = 0;
    for (const entry of payload.files) {
        if (!entry || typeof entry.name !== 'string' || entry.name.length === 0 || typeof entry.url !== 'string') return false;
        if (!Number.isSafeInteger(entry.size) || entry.size < 0) return false;
        const parts = entry.name.split('/');
        if (entry.name.startsWith('/')
            || entry.name.includes('\\')
            || entry.name.includes(':')
            || /[\u0000-\u001f\u007f]/u.test(entry.name)
            || parts.some((part) => part === '' || part === '.' || part === '..')) return false;
        const nameKey = entry.name.toLocaleLowerCase('en-US');
        if (usedNames.has(nameKey)) return false;
        usedNames.add(nameKey);
        sum += entry.size;
        if (!Number.isSafeInteger(sum)) return false;
    }
    return sum === payload.total_bytes;
}

/** Validate the cheap Stage 3 download-start response before using bearer metadata. */
export function validGalleryDownloadStart(payload) {
    if (!payload || payload.ok !== true) return false;
    if (typeof payload.capability !== 'string' || payload.capability.length < 32 || payload.capability.length > MAX_DOWNLOAD_CAPABILITY_LENGTH) return false;
    if (!Number.isSafeInteger(payload.issued_at) || !Number.isSafeInteger(payload.expires_at) || payload.expires_at <= payload.issued_at) return false;
    if (payload.expires_at <= Math.floor(Date.now() / 1000)) return false;
    if (payload.capability_transport?.type !== 'header' || payload.capability_transport?.header !== DOWNLOAD_CAPABILITY_HEADER) return false;
    if (typeof payload.manifest_url !== 'string' || payload.manifest_url.length === 0) return false;

    try {
        const baseUrl = globalThis.location?.href || 'https://gallery.invalid/';
        const manifestUrl = new URL(payload.manifest_url, baseUrl);
        const baseOrigin = new URL(baseUrl).origin;
        return ['http:', 'https:'].includes(manifestUrl.protocol) && manifestUrl.origin === baseOrigin;
    } catch (_) {
        return false;
    }
}


/** galleryDownloadMemoryPolicy supports the browser ZIP download workflow. */
export function galleryDownloadMemoryPolicy(payload, directStreaming = supportsDirectFileStreaming()) {
    const warningLimit = Number(payload?.memory_fallback_warning_bytes) || DEFAULT_WARNING_BYTES;
    const hardLimit = Number(payload?.memory_fallback_max_bytes) || DEFAULT_MAX_MEMORY_BYTES;
    const sourceBytes = Number(payload?.total_bytes) || 0;
    const encoder = new TextEncoder();
    let zipOverheadBytes = 1024;
    for (const entry of payload?.files || []) {
        const nameBytes = encoder.encode(String(entry?.name || '')).byteLength;
        zipOverheadBytes += Math.max(512, 160 + (2 * nameBytes));
    }
    const estimated = sourceBytes + zipOverheadBytes;
    const estimatedArchiveBytes = Number.isSafeInteger(estimated) ? estimated : Number.POSITIVE_INFINITY;
    return {
        directStreaming,
        estimatedArchiveBytes,
        warning: !directStreaming && estimatedArchiveBytes > warningLimit,
        allowed: directStreaming || estimatedArchiveBytes <= hardLimit,
    };
}

/** fetchGalleryDownloadEntry supports the browser ZIP download workflow. */
export async function fetchGalleryDownloadEntry(entry, signal, fetchImpl = globalThis.fetch, capability = '') {
    const baseUrl = globalThis.location?.href || 'https://gallery.invalid/';
    const targetUrl = new URL(entry.url, baseUrl);
    const baseOrigin = new URL(baseUrl).origin;
    if (!['http:', 'https:'].includes(targetUrl.protocol) || targetUrl.origin !== baseOrigin) {
        throw new Error(tr('download.progress.file_failed', 'Could not download {name}.', {name: entry.name}));
    }
    if (capability !== '' && (typeof capability !== 'string' || capability.length > MAX_DOWNLOAD_CAPABILITY_LENGTH)) {
        throw new DownloadCapabilityRejectedError();
    }

    const request = {
        credentials: 'same-origin',
        referrerPolicy: 'no-referrer',
        signal,
    };
    if (capability !== '') {
        request.headers = {[DOWNLOAD_CAPABILITY_HEADER]: capability};
    }
    const response = await fetchImpl(entry.url, request);
    if (response.status === 403 && capability !== '') {
        throw new DownloadCapabilityRejectedError();
    }
    if (!response.ok || !response.body) {
        throw new Error(tr('download.progress.file_failed', 'Could not download {name}.', {name: entry.name}));
    }
    const declaredLength = response.headers.get('Content-Length');
    if (declaredLength !== null && Number(declaredLength) !== entry.size) {
        throw new Error(tr('download.progress.file_failed', 'Could not download {name}.', {name: entry.name}));
    }
    return response;
}

class MemorySink {
/** constructor supports the browser ZIP download workflow. */
    constructor() {
        this.chunks = [];
        this.size = 0;
    }

/** write supports the browser ZIP download workflow. */
    async write(bytes) {
        const copy = bytes.slice();
        this.chunks.push(copy);
        this.size += copy.byteLength;
    }

/** toBlob supports the browser ZIP download workflow. */
    toBlob() {
        return new Blob(this.chunks, {type: 'application/zip'});
    }

/** discard supports the browser ZIP download workflow. */
    discard() {
        this.chunks.length = 0;
        this.size = 0;
    }
}

/** supportsDirectFileStreaming supports the browser ZIP download workflow. */
function supportsDirectFileStreaming() {
    return typeof window.showSaveFilePicker === 'function' && typeof window.WritableStream === 'function';
}

/** createPanel supports the browser ZIP download workflow. */
function createPanel() {
    const dialog = document.createElement('dialog');
    dialog.className = 'gallery-download-dialog';
    dialog.hidden = true;
    dialog.setAttribute('aria-labelledby', 'gallery-download-title');
    dialog.setAttribute('aria-describedby', 'gallery-download-status');
    dialog.innerHTML = `
        <section class="gallery-download-panel">
            <header class="gallery-download-header">
                <span class="gallery-download-icon" aria-hidden="true">&#8595;</span>
                <div class="gallery-download-heading-copy">
                    <h2 id="gallery-download-title"></h2>
                    <p class="gallery-download-summary" data-download-summary></p>
                </div>
            </header>
            <p class="gallery-download-warning" data-download-warning hidden></p>
            <div class="gallery-download-progress-wrap" data-download-progress-wrap hidden>
                <progress data-download-progress max="1" value="0"></progress>
                <div class="gallery-download-progress-text" data-download-progress-text></div>
                <div class="gallery-download-current" data-download-current></div>
            </div>
            <p class="gallery-download-status" id="gallery-download-status" data-download-status aria-live="polite"></p>
            <div class="gallery-download-actions">
                <button type="button" class="button" data-download-start></button>
                <button type="button" class="button secondary" data-download-retry hidden></button>
                <button type="button" class="button secondary" data-download-cancel></button>
                <button type="button" class="button secondary" data-download-close hidden></button>
            </div>
        </section>`;
    document.body.appendChild(dialog);
    return dialog;
}

class GalleryDownloadController {
/** constructor supports the browser ZIP download workflow. */
    constructor(panel) {
        this.panel = panel;
        this.title = panel.querySelector('#gallery-download-title');
        this.summary = panel.querySelector('[data-download-summary]');
        this.warning = panel.querySelector('[data-download-warning]');
        this.progressWrap = panel.querySelector('[data-download-progress-wrap]');
        this.progress = panel.querySelector('[data-download-progress]');
        this.progressText = panel.querySelector('[data-download-progress-text]');
        this.current = panel.querySelector('[data-download-current]');
        this.status = panel.querySelector('[data-download-status]');
        this.startButton = panel.querySelector('[data-download-start]');
        this.retryButton = panel.querySelector('[data-download-retry]');
        this.cancelButton = panel.querySelector('[data-download-cancel]');
        this.closeButton = panel.querySelector('[data-download-close]');
        this.manifest = null;
        this.startUrl = '';
        this.capability = '';
        this.capabilityExpiresAt = 0;
        this.abortController = null;
        this.activeSink = null;
        this.activeWritable = null;
        this.running = false;
        this.returnFocus = null;

        this.startButton.addEventListener('click', () => this.start().catch((error) => this.fail(error)));
        this.retryButton.addEventListener('click', () => {
            if (this.startUrl === '') return;
            this.prepare(this.startUrl, false).catch((error) => this.fail(error));
        });
        this.cancelButton.addEventListener('click', () => this.cancel());
        this.closeButton.addEventListener('click', () => this.close());
        panel.addEventListener('cancel', (event) => {
            event.preventDefault();
            if (this.running) this.cancel(); else this.close();
        });
        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && typeof panel.showModal !== 'function') {
                event.preventDefault();
                if (this.running) this.cancel(); else this.close();
            }
        });
        panel.addEventListener('click', (event) => {
            if (event.target !== panel || this.running) return;
            const bounds = panel.getBoundingClientRect();
            const insidePanel = event.clientX >= bounds.left
                && event.clientX <= bounds.right
                && event.clientY >= bounds.top
                && event.clientY <= bounds.bottom;
            if (!insidePanel) this.close();
        });
    }

/** show supports the browser ZIP download workflow. */
    show() {
        this.panel.hidden = false;
        document.body.classList.add('gallery-download-modal-open');
        if (typeof this.panel.showModal === 'function') {
            if (!this.panel.open) this.panel.showModal();
        } else {
            this.panel.setAttribute('open', '');
        }
        this.cancelButton.focus();
    }

/** close supports the browser ZIP download workflow. */
    close() {
        if (this.running) return;
        if (typeof this.panel.close === 'function' && this.panel.open) {
            this.panel.close();
        } else {
            this.panel.removeAttribute('open');
        }
        this.panel.hidden = true;
        document.body.classList.remove('gallery-download-modal-open');
        this.manifest = null;
        this.startUrl = '';
        this.capability = '';
        this.capabilityExpiresAt = 0;
        const returnFocus = this.returnFocus;
        this.returnFocus = null;
        if (returnFocus && returnFocus.isConnected && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

/** resetButtons supports the browser ZIP download workflow. */
    resetButtons() {
        this.startButton.hidden = true;
        this.retryButton.hidden = true;
        this.cancelButton.hidden = false;
        this.cancelButton.textContent = tr('download.progress.cancel', 'Cancel');
        this.closeButton.hidden = true;
    }

/** prepare supports the browser ZIP download workflow. */
    async prepare(startUrl, rememberFocus = true) {
        if (rememberFocus) {
            this.returnFocus = document.activeElement;
        }
        this.abortController?.abort();
        const prepareController = new AbortController();
        this.abortController = prepareController;
        this.startUrl = startUrl;
        this.manifest = null;
        this.capability = '';
        this.capabilityExpiresAt = 0;
        this.running = true;
        this.resetButtons();
        this.title.textContent = tr('gallery.download', 'Download gallery');
        this.summary.textContent = '';
        this.warning.hidden = true;
        this.progressWrap.hidden = true;
        this.status.textContent = tr('download.progress.preparing', 'Preparing download...');
        this.show();

        try {
            const baseUrl = globalThis.location?.href || 'https://gallery.invalid/';
            const resolvedStartUrl = new URL(startUrl, baseUrl);
            if (!['http:', 'https:'].includes(resolvedStartUrl.protocol) || resolvedStartUrl.origin !== new URL(baseUrl).origin) {
                throw new Error(tr('download.progress.failed', 'Download failed'));
            }

            const startResponse = await fetch(resolvedStartUrl.toString(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                referrerPolicy: 'no-referrer',
                signal: prepareController.signal,
            });
            let startPayload = null;
            try {
                startPayload = await startResponse.json();
            } catch (_) {
                throw new Error(tr('download.progress.failed', 'Download failed'));
            }
            if (!startResponse.ok || startPayload?.ok !== true) {
                throw new Error(String(startPayload?.error || tr('download.progress.failed', 'Download failed')));
            }
            if (!validGalleryDownloadStart(startPayload)) {
                throw new Error(tr('download.progress.failed', 'Download failed'));
            }

            this.capability = startPayload.capability;
            this.capabilityExpiresAt = startPayload.expires_at;
            const response = await fetch(startPayload.manifest_url, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    [DOWNLOAD_CAPABILITY_HEADER]: this.capability,
                },
                referrerPolicy: 'no-referrer',
                signal: prepareController.signal,
            });
            let payload = null;
            try {
                payload = await response.json();
            } catch (_) {
                throw new Error(tr('download.progress.invalid_manifest', 'The server returned an invalid download manifest.'));
            }
            if (response.status === 403) {
                throw new DownloadCapabilityRejectedError();
            }
            if (!response.ok || payload?.ok !== true) {
                throw new Error(String(payload?.error || tr('download.progress.failed', 'Download failed')));
            }
            if (!validGalleryDownloadManifest(payload)) {
                throw new Error(tr('download.progress.invalid_manifest', 'The server returned an invalid download manifest.'));
            }
            this.manifest = payload;
            this.summary.textContent = `${tr('download.progress.files', '{count} files', {count: payload.total_files})} \u00B7 ${formatBytes(payload.total_bytes)}`;
            if (payload.total_files === 0) {
                this.status.textContent = tr('download.progress.empty', 'This gallery has no downloadable files.');
                this.cancelButton.hidden = true;
                this.closeButton.hidden = false;
                this.closeButton.textContent = tr('download.progress.close', 'Close');
                this.closeButton.focus();
                return;
            }

            const memoryPolicy = galleryDownloadMemoryPolicy(payload);
            if (!memoryPolicy.allowed) {
                this.warning.hidden = false;
                this.warning.textContent = tr('download.progress.memory_too_large', 'Your browser cannot efficiently create an archive this large. Use a Chromium-based browser with direct file saving support.');
                this.status.textContent = this.warning.textContent;
                this.cancelButton.hidden = true;
                this.closeButton.hidden = false;
                this.closeButton.textContent = tr('download.progress.close', 'Close');
                this.closeButton.focus();
                return;
            }
            if (memoryPolicy.warning) {
                this.warning.hidden = false;
                this.warning.textContent = tr('download.progress.memory_warning', 'This browser cannot stream the ZIP directly to disk, so it must temporarily keep the archive in memory.');
            }
            this.status.textContent = '';
            this.startButton.hidden = false;
            this.startButton.textContent = tr('download.progress.start', 'Save ZIP and start');
            this.startButton.focus();
        } catch (error) {
            if (error?.name === 'AbortError') {
                this.showCancelled();
                return;
            }
            throw error;
        } finally {
            if (this.abortController === prepareController) {
                this.abortController = null;
                this.running = false;
            }
        }
    }

/** createOutput supports the browser ZIP download workflow. */
    async createOutput() {
        if (supportsDirectFileStreaming()) {
            const handle = await window.showSaveFilePicker({
                suggestedName: this.manifest.filename || 'gallery.zip',
                types: [{description: 'ZIP archive', accept: {'application/zip': ['.zip']}}],
            });
            const writable = await handle.createWritable({keepExistingData: false});
            this.activeWritable = writable;
            return {
                sink: {write: (bytes) => writable.write(bytes)},
                complete: () => writable.close(),
                abort: async () => {
                    if (typeof writable.abort === 'function') await writable.abort();
                },
                memory: false,
            };
        }

        const sink = new MemorySink();
        this.activeSink = sink;
        return {
            sink,
            complete: async () => {
                const blob = sink.toBlob();
                const url = URL.createObjectURL(blob);
                try {
                    const anchor = document.createElement('a');
                    anchor.href = url;
                    anchor.download = this.manifest.filename || 'gallery.zip';
                    anchor.hidden = true;
                    document.body.appendChild(anchor);
                    anchor.click();
                    anchor.remove();
                } finally {
                    setTimeout(() => URL.revokeObjectURL(url), 60000);
                }
            },
            abort: async () => sink.discard(),
            memory: true,
        };
    }

/** start supports the browser ZIP download workflow. */
    async start() {
        if (this.running || !this.manifest) return;
        const nowSeconds = Math.floor(Date.now() / 1000);
        if (this.capability === '' || this.capabilityExpiresAt <= nowSeconds + CAPABILITY_RENEWAL_SAFETY_SECONDS) {
            await this.prepare(this.startUrl, false);
            return;
        }
        this.running = true;
        this.resetButtons();
        this.startButton.hidden = true;
        this.retryButton.hidden = true;
        this.progressWrap.hidden = false;
        this.progress.max = Math.max(1, this.manifest.total_bytes);
        this.progress.value = 0;
        this.progressText.textContent = `0 / ${formatBytes(this.manifest.total_bytes)}`;
        this.current.textContent = '';
        this.status.textContent = tr('download.progress.downloading', 'Downloading files');
        this.abortController = new AbortController();

        let output = null;
        let downloaded = 0;
        let completedFiles = 0;
        try {
            output = await this.createOutput();
            const writer = new ZipStreamWriter(output.sink);
            for (const entry of this.manifest.files) {
                if (this.abortController.signal.aborted) throw new DOMException('Aborted', 'AbortError');
                this.current.textContent = tr('download.progress.current', 'Current: {name}', {name: entry.name});
                const response = await fetchGalleryDownloadEntry(entry, this.abortController.signal, globalThis.fetch, this.capability);
                await writer.addReadable(entry.name, entry.size, response.body, (chunkBytes) => {
                    downloaded += chunkBytes;
                    this.progress.value = Math.min(downloaded, this.manifest.total_bytes);
                    this.progressText.textContent = `${formatBytes(Math.min(downloaded, this.manifest.total_bytes))} / ${formatBytes(this.manifest.total_bytes)} \u00B7 ${tr('download.progress.file_count', '{done} / {total} files', {done: completedFiles, total: this.manifest.total_files})}`;
                });
                completedFiles += 1;
                this.progressText.textContent = `${formatBytes(downloaded)} / ${formatBytes(this.manifest.total_bytes)} \u00B7 ${tr('download.progress.file_count', '{done} / {total} files', {done: completedFiles, total: this.manifest.total_files})}`;
            }
            this.status.textContent = tr('download.progress.finalizing', 'Finalizing archive');
            await writer.finalize();
            this.status.textContent = tr('download.progress.saving', 'Saving');
            await output.complete();
            this.status.textContent = tr('download.progress.complete', 'Download complete');
            this.current.textContent = '';
            this.progress.value = this.manifest.total_bytes;
            this.cancelButton.hidden = true;
            this.closeButton.hidden = false;
            this.closeButton.textContent = tr('download.progress.close', 'Close');
            this.closeButton.focus();
        } catch (error) {
            if (output) {
                try { await output.abort(); } catch (_) {}
            }
            if (error?.name === 'AbortError') {
                this.showCancelled();
            } else {
                throw error;
            }
        } finally {
            this.running = false;
            this.abortController = null;
            this.activeSink = null;
            this.activeWritable = null;
        }
    }

/** showCancelled supports the browser ZIP download workflow. */
    showCancelled() {
        this.status.textContent = tr('download.progress.cancelled', 'Download cancelled.');
        this.current.textContent = '';
        this.cancelButton.hidden = true;
        this.retryButton.hidden = false;
        this.retryButton.textContent = tr('download.progress.retry', 'Retry');
        this.closeButton.hidden = false;
        this.closeButton.textContent = tr('download.progress.close', 'Close');
        this.retryButton.focus();
    }

/** cancel supports the browser ZIP download workflow. */
    cancel() {
        if (!this.running) {
            this.close();
            return;
        }
        this.abortController?.abort();
    }

/** fail supports the browser ZIP download workflow. */
    fail(error) {
        this.running = false;
        this.abortController?.abort();
        this.status.textContent = `${tr('download.progress.failed', 'Download failed')}: ${String(error?.message || error)}`;
        this.current.textContent = '';
        this.startButton.hidden = true;
        this.cancelButton.hidden = true;
        if (error?.name === 'DownloadCapabilityRejectedError') {
            this.manifest = null;
            this.capability = '';
            this.capabilityExpiresAt = 0;
        }
        this.retryButton.hidden = this.startUrl === '';
        this.retryButton.textContent = tr('download.progress.retry', 'Retry');
        this.closeButton.hidden = false;
        this.closeButton.textContent = tr('download.progress.close', 'Close');
        (this.retryButton.hidden ? this.closeButton : this.retryButton).focus();
    }
}

let controller = null;

/**
 * Progressively enhance Stage 4 POST fallback controls with the Stage 3 browser ZIP flow.
 *
 * The server-rendered form remains authoritative when JavaScript is unavailable.
 * Once this module is active, every activation of the download submit control is
 * intercepted so modifier keys cannot accidentally enter the expensive legacy path.
 */
export function setupGalleryDownload() {
    const controls = Array.from(document.querySelectorAll('[data-gallery-download][data-gallery-download-start-url]'));
    if (controls.length === 0) return;
    if (!controller) controller = new GalleryDownloadController(createPanel());
    controls.forEach((control) => {
        if (control.dataset.galleryDownloadReady === '1') return;
        control.dataset.galleryDownloadReady = '1';
        /** Intercept one progressive download activation and keep legacy POST as the no-JavaScript fallback. */
        const activate = (event) => {
            event.preventDefault();
            controller.prepare(control.dataset.galleryDownloadStartUrl).catch((error) => controller.fail(error));
        };
        if (control.form) {
            control.form.addEventListener('submit', activate);
        } else {
            control.addEventListener('click', activate);
        }
    });
}
