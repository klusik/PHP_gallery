/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-pdf-documents.js
 * Module Type: Browser Module
 * Purpose: Keep PDF attachment and page conversion actions in their originating panel.
 * Responsibilities: Own explicit actions, progress, cancellation and canonical completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {createPdfPngUploadStream} from './admin-pdf-conversion.js?v=20261012-pdf-dpi-warning-v3';
import {uploadBrowserFileStream} from './admin-browser-upload.js?v=20261014-pdf-core-cache-v1';
import {beginAdminOperation, finishAdminOperation, assertAdminOperationCurrent} from './admin-operation-keys.js?v=20261012-pdf-files-v1';
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261012-pdf-documents-v2';
import {i18nForElement, appendUploadProgressLog} from './admin-core.js?v=20261003-scoped-i18n-v1';

/** @type {WeakMap<HTMLFormElement, AbortController>} Active conversion cancellation owners. */
const conversions = new WeakMap();

/** @type {WeakSet<HTMLElement>} Page-count cells already queued or resolved. */
const pageCountCells = new WeakSet();
/** @type {Map<string, Promise<number|null>>} Sequential page-count requests cached by protected URL and exact source version. */
const pageCountsByUrl = new Map();
/** @type {HTMLElement[]} Visible table cells waiting for one-at-a-time PDF metadata reads. */
const pageCountQueue = [];
let pageCountQueueRunning = false;

/**
 * Retain each selected original and its acknowledged stages for an exact upload retry.
 * @typedef {Object} PdfUploadRecord
 * @property {File} file Original browser file, never a reconstructed copy.
 * @property {Record<string, unknown>|null} attachment Canonical attachment response after acknowledgment.
 * @property {HTMLFormElement|null} conversionForm Server-rendered conversion controls with fresh CSRF.
 * @property {Record<string, unknown>|null} result Canonical finalized conversion response.
 * @property {boolean} attachmentPending Whether an attachment response is still unknown.
 */

/**
 * Observe page preparation and acknowledgment without owning the transport.
 * @callback PdfUploadObserver
 * @param {import('./admin-pdf-conversion.js').PdfConversionProgress} state Current page phase and counters.
 * @returns {void} Update the originating upload progress surface.
 */

/**
 * Configure original attachment and page conversion from the ordinary upload form.
 * @typedef {Object} GalleryPdfUploadOptions
 * @property {number} galleryId Existing physical source gallery.
 * @property {'child'|'append'} targetMode PNG destination used only for an opted-in conversion.
 * @property {number} dpi Dots per inch used only for an opted-in conversion.
 * @property {boolean} [convertPages=false] Whether the administrator explicitly opted into PNG conversion.
 * @property {import('./admin-operation-keys.js').AdminOperationIntent} operation Parent intent owning the exact file selection.
 * @property {Record<string, unknown>|null} [baseResult] Already acknowledged photo envelope, preserved during aggregation.
 * @property {PdfUploadObserver} [onProgress] Optional caller progress observer.
 */

/** @type {WeakMap<object, {records: PdfUploadRecord[], container: HTMLElement|null, convertPages: boolean}>} Document-local acknowledged stages, optional page-progress surface and frozen conversion choice. */
const pdfUploadAttempts = new WeakMap();

/**
 * Attach selected PDFs and convert pages only when the administrator opted in.
 *
 * Child forms live beside the parent form so their transport fields cannot alter
 * the parent's frozen upload identity. Every server envelope remains available.
 *
 * @param {HTMLFormElement} form Original upload form and current CSRF source.
 * @param {HTMLElement} progress Parent upload progress surface.
 * @param {File[]} files Original PDFs in selection order.
 * @param {GalleryPdfUploadOptions} options Source, destination, explicit conversion choice and parent retry ownership.
 * @returns {Promise<Record<string, unknown>>} Canonical aggregate with all acknowledged envelopes, image IDs and affected contexts.
 */
export async function uploadGalleryPdfFiles(form, progress, files, options) {
    const endpointValue = String(form.dataset.pdfAttachmentUrl || '');
    if (endpointValue === '') throw new Error(i18nForElement(form, 'pdf.upload.helper_unavailable', 'The PDF upload workflow is unavailable. No PDF was attached.'));
    const endpoint = new URL(endpointValue, window.location.href);
    if (endpoint.origin !== window.location.origin || !Number.isSafeInteger(options.galleryId) || options.galleryId < 1) {
        throw new Error(i18nForElement(form, 'pdf.upload.destination_required', 'Select the destination gallery before starting PDF page upload.'));
    }
    endpoint.searchParams.set('id', String(options.galleryId));
    let attempt = pdfUploadAttempts.get(options.operation);
    if (!attempt) {
        let container = null;
        if (options.convertPages === true) {
            container = document.createElement('section');
            container.className = 'gallery-pdf-upload-workflow';
            container.dataset.pdfUploadWorkflow = 'true';
            form.insertAdjacentElement('afterend', container);
        }
        attempt = {container, records: files.map(file => ({file, attachment: null, conversionForm: null, result: null, attachmentPending: false})), convertPages: options.convertPages === true};
        pdfUploadAttempts.set(options.operation, attempt);
    }
    if (attempt.convertPages !== (options.convertPages === true)) {
        throw new Error(i18nForElement(form, 'pdf.upload.retry_choice', 'Keep the original PDF selection and conversion choice while retrying this upload.'));
    }
    if (attempt.records.length !== files.length || attempt.records.some((record, index) => record.file !== files[index])) {
        throw new Error(i18nForElement(form, 'pdf.unresolved', 'Keep the original PDF selection while retrying this upload.'));
    }
    const envelopes = options.baseResult ? [options.baseResult] : [];
    const parentFill = progress.querySelector('[data-thumbnail-progress-fill]');
    if (parentFill) parentFill.value = 1;
    const language = form.closest('[data-admin-i18n-language]')?.dataset.adminI18nLanguage || document.documentElement.lang;
    for (const [index, record] of attempt.records.entries()) {
        assertAdminOperationCurrent(options.operation);
        if (!record.attachment) {
            if (record.attachmentPending) {
                throw new Error(i18nForElement(form, 'pdf.upload.attachment_unknown', 'The original PDF attachment response is unknown. Review this gallery’s documents before starting another upload.'));
            }
            const requestForm = document.createElement('form');
            requestForm.action = endpoint.href;
            const body = new FormData();
            body.set('csrf_token', String(form.querySelector('[name="csrf_token"]')?.value || ''));
            body.set('id', String(options.galleryId));
            body.set('pdf_action', 'upload');
            body.set('pdf_file', record.file, record.file.name);
            body.set('pdf_title', record.file.name);
            body.set('pdf_ui_language', language);
            options.operation.unresolved = true;
            record.attachmentPending = true;
            try {
                record.attachment = await pdfRequest(requestForm, body);
                record.attachmentPending = false;
            } catch (error) {
                if (error?.responseReceived === true) record.attachmentPending = false;
                throw error;
            }
        }
        envelopes.push(record.attachment);
        if (attempt.convertPages && !record.result) {
            if (!record.conversionForm) {
                const template = document.createElement('template');
                template.innerHTML = String(record.attachment.panel_html || '');
                const conversionForm = Array.from(template.content.querySelectorAll('[data-pdf-convert-form]')).find(candidate => candidate.querySelector('[name="document_id"]')?.value === record.attachment.document_id);
                if (!conversionForm) throw new Error(i18nForElement(form, 'pdf.failed', 'PDF operation failed.'));
                conversionForm.querySelector('[name="target_mode"]').value = options.targetMode;
                conversionForm.querySelector('[name="dpi"]').value = String(options.dpi);
                conversionForm.querySelector('[name="pdf_ui_language"]').value = language;
                // The parent upload form already owns these choices. Keep only
                // page progress and Cancel visible in the child workflow.
                for (const control of conversionForm.querySelectorAll('select, [name="allow_new"], button[type="submit"], p')) {
                    (control.closest('label') || control).hidden = true;
                }
                const heading = document.createElement('h4');
                heading.textContent = record.file.name;
                attempt.container.append(heading, conversionForm);
                record.conversionForm = conversionForm;
            }
            record.conversionForm.dataset.pdfRunning = '1';
            const submitButtons = Array.from(record.conversionForm.querySelectorAll('button[type="submit"]'));
            submitButtons.forEach(button => { button.disabled = true; });
            try {
                record.result = await convertGalleryPdf(record.conversionForm, {completeMutation: false, onProgress: state => {
                    const fill = progress.querySelector('[data-thumbnail-progress-fill]');
                    if (fill) fill.value = Math.min(99, Math.floor((index + state.page / Math.max(1, state.pages)) / files.length * 100));
                    if (typeof options.onProgress === 'function') options.onProgress(state);
                }});
            } finally {
                record.conversionForm.dataset.pdfRunning = '0';
                submitButtons.forEach(button => { button.disabled = false; });
            }
        }
        if (record.result) envelopes.push(record.result);
    }
    const last = envelopes[envelopes.length - 1];
    const contexts = new Map();
    const imageIds = new Set();
    for (const envelope of envelopes) {
        for (const context of Array.isArray(envelope.contexts) ? envelope.contexts : []) {
            const key = JSON.stringify([context.type, context.gallery_id, context.postcondition?.type, context.postcondition?.gallery_id]);
            contexts.set(key, context);
        }
        for (const id of Array.isArray(envelope.image_ids) ? envelope.image_ids : []) imageIds.add(Number(id));
    }
    const result = {...last, gallery_id: options.galleryId, contexts: Array.from(contexts.values()),
        image_ids: Array.from(imageIds), mutation_envelopes: envelopes, pdf_documents_uploaded: files.length,
        pdf_document_ids: attempt.records.map(record => record.attachment.document_id),
        panel: {...last.panel, keep_open: true}};
    result.uploaded = imageIds.size;
    result.scanned = envelopes.reduce((count, envelope) => count + Number(envelope.scanned || 0), 0);
    result.thumbnails = envelopes.reduce((count, envelope) => count + Number(envelope.thumbnails || 0), 0);
    result.thumbnail_failed = envelopes.reduce((count, envelope) => count + Number(envelope.thumbnail_failed || 0), 0);
    if (parentFill) parentFill.value = 100;
    if (result.mutation?.entity === 'image') result.mutation = {...result.mutation, entity_ids: result.image_ids};
    return result;
}

/**
 * Bind dynamically injected document forms before generic panel submission handlers.
 * @returns {void} Install delegated document handlers, page-count scanning and PDF table controls once.
 */
export function setupGalleryPdfDocuments() {
    scanPdfPageCountCells(document);
    if (document.body.dataset.pdfDocumentsBound === '1') return;
    document.body.dataset.pdfDocumentsBound = '1';
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-pdf-attachment-form], [data-pdf-convert-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.pdfRunning === '1') return;
        if (form.matches('[data-pdf-attachment-form][data-pdf-delete-confirm]')
            && !window.confirm(form.dataset.pdfDeleteConfirm || 'Delete this PDF document?')) return;
        form.dataset.pdfRunning = '1';
        const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
        buttons.forEach(button => { button.disabled = true; });
        try {
            if (form.matches('[data-pdf-attachment-form]')) await submitPdfAttachment(form, event.submitter);
            else await convertGalleryPdf(form);
        } catch (error) {
            const status = form.querySelector('[data-pdf-status]');
            if (status) status.textContent = error instanceof Error ? error.message : String(error);
        } finally {
            form.dataset.pdfRunning = '0';
            buttons.forEach(button => { button.disabled = false; });
        }
    }, true);
    document.addEventListener('click', event => {
        const toggle = event.target instanceof Element ? event.target.closest('[data-pdf-toggle]') : null;
        if (toggle instanceof HTMLButtonElement) {
            const controlledId = toggle.getAttribute('aria-controls') || '';
            const row = controlledId ? document.getElementById(controlledId) : null;
            if (row instanceof HTMLTableRowElement) {
                event.preventDefault();
                const willOpen = row.hidden;
                row.hidden = !willOpen;
                toggle.setAttribute('aria-expanded', String(willOpen));
                if (willOpen) row.querySelector('select')?.focus();
            }
            return;
        }
        const button = event.target instanceof Element ? event.target.closest('[data-pdf-cancel]') : null;
        if (!button) return;
        event.preventDefault();
        const form = button.closest('form');
        if (form) conversions.get(form)?.abort();
    });
    // Panel refreshes insert new table cells after setup has already run.
    const observer = new MutationObserver(records => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node instanceof Element || node instanceof DocumentFragment) scanPdfPageCountCells(node);
            }
        }
    });
    observer.observe(document.body, {childList: true, subtree: true});
}

/**
 * Start metadata-only PDF page-count reads for unprocessed table cells.
 * @param {ParentNode} root Initial document or newly injected fragment to scan.
 * @returns {void} Queue page-count metadata reads without rendering PDF pages.
 */
function scanPdfPageCountCells(root) {
    const cells = [];
    if (root instanceof Element && root.matches('[data-pdf-page-count]')) cells.push(root);
    cells.push(...root.querySelectorAll('[data-pdf-page-count]'));
    for (const cell of cells) {
        if (pageCountCells.has(cell)) continue;
        pageCountCells.add(cell);
        const url = String(cell.dataset.pdfUrl || '');
        if (url === '') continue;
        const title = cell.dataset.pageCountTitle || cell.getAttribute('title') || 'Page count unavailable';
        cell.title = title;
        pageCountQueue.push(cell);
    }
    void drainPdfPageCountQueue();
}

/**
 * Read queued PDF metadata sequentially and reuse counts only for the same source version.
 * @returns {Promise<void>} Resolves when the current visible-cell queue is drained.
 */
async function drainPdfPageCountQueue() {
    if (pageCountQueueRunning) return;
    pageCountQueueRunning = true;
    try {
        while (pageCountQueue.length > 0) {
            const cell = pageCountQueue.shift();
            if (!cell?.isConnected) continue;
            const url = String(cell.dataset.pdfUrl || '');
            if (url === '') continue;
            const cacheKey = JSON.stringify([url, String(cell.dataset.pdfVersion || '')]);
            let countPromise = pageCountsByUrl.get(cacheKey);
            if (!countPromise) {
                countPromise = readPdfPageCountFromStream(url);
                pageCountsByUrl.set(cacheKey, countPromise);
            }
            const pageCount = await countPromise;
            if (cell.isConnected) cell.textContent = pageCount === null ? '—' : String(pageCount);
        }
    } finally {
        pageCountQueueRunning = false;
        if (pageCountQueue.length > 0) void drainPdfPageCountQueue();
    }
}

/**
 * Open a PDF through the existing protected-source loader and release it before rendering.
 * @param {string} url Protected same-origin PDF URL from a rendered table cell.
 * @returns {Promise<number|null>} Positive page count, or null when PDF metadata is unavailable.
 */
async function readPdfPageCountFromStream(url) {
    let stream = null;
    try {
        stream = await createPdfPngUploadStream(url, {});
        return stream.pageCount;
    } catch (error) {
        return null;
    } finally {
        if (stream) await stream.dispose();
    }
}

/**
 * Send one fresh authenticated form request without retaining transport secrets.
 * @param {HTMLFormElement} form Original server-rendered form.
 * @param {FormData} body Current request controls and operation identity.
 * @param {AbortSignal|null} signal Optional request cancellation.
 * @returns {Promise<Record<string, unknown>>} Canonical response or an actionable refusal.
 */
async function pdfRequest(form, body, signal = null) {
    body.set('ajax', '1');
    const response = await fetch(form.action, {method: 'POST', body, signal, credentials: 'same-origin',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
    let result;
    try { result = await response.json(); }
    catch {
        throw new Error(i18nForElement(form, 'pdf.session_error', 'The PDF request was not acknowledged. Check your session and PHP upload limits, then retry.'));
    }
    if (!response.ok || result.ok !== true) {
        const error = new Error(String(result.message || result.error || i18nForElement(form, 'pdf.failed', 'PDF operation failed.')));
        error.responseReceived = true;
        error.pdfRestartAvailable = result.pdf_restart_available === true;
        throw error;
    }
    return result;
}

/**
 * Persist an attachment, refresh its document surface and optionally open the relevant conversion controls.
 * @param {HTMLFormElement} form Attachment upload/replacement/deletion form.
 * @param {HTMLElement|null} submitter Explicit clicked mutation button.
 * @returns {Promise<void>} Resolve after canonical mutation completion and optional conversion.
 */
async function submitPdfAttachment(form, submitter) {
    const container = form.closest('[data-gallery-pdf-documents]');
    const body = new FormData(form);
    if (submitter?.getAttribute('name')) body.set(submitter.getAttribute('name'), submitter.getAttribute('value') || '');
    if (form.hasAttribute('data-pdf-delete-confirm')) body.set('confirmed', '1');
    const mode = String(body.get('target_mode') || 'none');
    const dpi = String(body.get('dpi') || '150');
    const result = await pdfRequest(form, body);
    // The shared coordinator owns public refresh and stale-read retries; this owner replaces only its document card.
    let replacement = null;
    await completeAdminMutation(result, {refreshPanel: async (_panel, _envelope, guard) => {
        if (!guard.isCurrent() || !container?.isConnected || typeof result.panel_html !== 'string') return false;
        const template = document.createElement('template');
        template.innerHTML = result.panel_html;
        replacement = template.content.querySelector('[data-gallery-pdf-documents]');
        if (replacement) {
            container.replaceWith(replacement);
        } else if (result.panel_html === '') {
            const layout = container.closest('.gallery-description-documents');
            container.remove();
            if (layout) layout.replaceWith(...layout.childNodes);
            return true;
        }
        return Boolean(replacement);
    }});
    if (replacement?.isConnected) {
        if (mode !== 'none' && typeof result.document_id === 'string') {
            const next = Array.from(replacement.querySelectorAll('[data-pdf-convert-form]')).find(candidate => candidate.querySelector('[name="document_id"]')?.value === result.document_id);
            if (next) {
                const controlsRow = next.closest('[data-pdf-controls]');
                if (controlsRow instanceof HTMLTableRowElement) {
                    controlsRow.hidden = false;
                    const toggle = replacement.querySelector(`[aria-controls="${CSS.escape(controlsRow.id)}"]`);
                    toggle?.setAttribute('aria-expanded', 'true');
                } else {
                    const details = next.closest('details');
                    if (details) details.open = true;
                }
                next.querySelector('[name="target_mode"]').value = mode;
                next.querySelector('[name="dpi"]').value = dpi;
                next.requestSubmit();
            }
        }
    }
}

/**
 * Render incrementally into the selected existing gallery or a private child.
 * @param {HTMLFormElement} form Original conversion form with fresh CSRF and protected PDF URL.
 * @param {{completeMutation?:boolean,onProgress?:PdfUploadObserver}} options Optional parent workflow completion and progress ownership.
 * @returns {Promise<Record<string, unknown>>} Canonical result after all pages and finalization are acknowledged.
 */
async function convertGalleryPdf(form, options = {}) {
    const abort = new AbortController();
    conversions.set(form, abort);
    const cancel = form.querySelector('[data-pdf-cancel]');
    const status = form.querySelector('[data-pdf-status]');
    const progress = form.querySelector('[data-upload-progress]');
    let resourceWarning = form.querySelector('[data-pdf-resource-warning]');
    if (!resourceWarning) {
        resourceWarning = document.createElement('p');
        resourceWarning.dataset.pdfResourceWarning = 'true';
        resourceWarning.className = 'gallery-pdf-resource-warning';
        resourceWarning.setAttribute('role', 'status');
        progress.insertAdjacentElement('beforebegin', resourceWarning);
    }
    resourceWarning.hidden = true;
    if (cancel) cancel.hidden = false;
    progress.hidden = false;
    if (!progress.querySelector('[data-thumbnail-progress-fill]')) {
        progress.innerHTML = '<progress max="100" value="0" data-thumbnail-progress-fill></progress><span data-thumbnail-progress-text></span><div data-upload-progress-metrics></div><div data-upload-progress-log></div>';
    }
    let stream = null;
    let operation = null;
    let lifecycle = null;
    let uploadResult = null;
    let completed = false;
    const showProgress = state => {
        if (typeof options.onProgress === 'function') options.onProgress(state);
        if (state.phase === 'warning') {
            const message = i18nForElement(form, 'pdf.conversion.large_page_warning', 'Page {page}: {width} × {height} pixels, about {memory} MiB for the canvas. Rendering may exhaust browser memory.',
                {page: state.page, width: state.width, height: state.height, memory: state.memoryMiB});
            resourceWarning.textContent = message;
            resourceWarning.hidden = false;
            status.textContent = message;
            appendUploadProgressLog(progress, message);
            return;
        }
        const phase = i18nForElement(form, `pdf.phase.${state.phase}`, state.phase);
        status.textContent = `${phase} · ${state.page} / ${state.pages}`;
        if (Number.isFinite(state.batch)) {
            status.textContent += ' · ' + i18nForElement(form, 'pdf.upload_status', 'Batch {batch} · {uploaded}/{bytes} bytes',
                {batch: state.batch, uploaded: state.uploadedBytes || 0, bytes: state.bytes || 0});
        }
    };
    try {
        operation = beginAdminOperation(form, 'prepared-pdf');
        const sourceIntent = {
            documentId: String(form.querySelector('[name="document_id"]').value),
            dpi: Number(form.querySelector('[name="dpi"]').value),
            targetMode: String(form.querySelector('[name="target_mode"]').value),
        };
        if (operation.pdfSource && (operation.pdfSource.documentId !== sourceIntent.documentId
            || operation.pdfSource.dpi !== sourceIntent.dpi || operation.pdfSource.targetMode !== sourceIntent.targetMode)) {
            throw new Error(i18nForElement(form, 'pdf.unresolved', 'An earlier PDF conversion is unresolved. Restore its original document, DPI and destination and retry before starting different work.'));
        }
        operation.pdfSource = Object.freeze(sourceIntent);
        status.textContent = i18nForElement(form, 'pdf.phase.loading', 'Loading PDF');
        stream = await createPdfPngUploadStream(form.dataset.pdfUrl, {dpi: Number(form.querySelector('[name="dpi"]').value), signal: abort.signal, onProgress: showProgress});
        const begin = new FormData(form);
        begin.set('document_id', sourceIntent.documentId);
        begin.set('dpi', String(sourceIntent.dpi));
        begin.set('target_mode', sourceIntent.targetMode);
        begin.set('pdf_action', 'begin');
        begin.set('page_count', String(stream.pageCount));
        operation.unresolved = true;
        try {
            lifecycle = await pdfRequest(form, begin, abort.signal);
        } catch (error) {
            if (error?.pdfRestartAvailable !== true || begin.get('allow_new') === '1'
                || !window.confirm(i18nForElement(form, 'pdf.restart_confirm', 'The previous destination is missing or changed. Create a new private result and preserve all previous files and galleries?'))) throw error;
            begin.set('allow_new', '1');
            const confirmation = form.querySelector('[name="allow_new"]');
            if (confirmation) confirmation.checked = true;
            lifecycle = await pdfRequest(form, begin, abort.signal);
        }
        if (lifecycle.created === true && operation.prepared && String(operation.prepared.uploadSessionId) !== String(lifecycle.session_id)) {
            if (Number(lifecycle.start_page) !== 1 || Number(lifecycle.next_batch_index) !== 0
                || (operation.prepared.acknowledgedBatches?.size > 0 && begin.get('allow_new') !== '1')) {
                throw new Error(i18nForElement(form, 'pdf.unresolved', 'An earlier PDF conversion is unresolved. Preserve its acknowledged pages and review the result.'));
            }
            await operation.prepared.batcher.abort();
            operation.prepared = null;
        }
        if (lifecycle.status !== 'complete') {
            const startPage = operation.prepared ? Number(operation.prepared.nextSourceIndex || 0) + 1 : Number(lifecycle.start_page || 1);
            stream.configure({baseName: String(lifecycle.prefix), startPage, pageDigits: Number(lifecycle.page_digits)});
            if (startPage <= stream.pageCount || operation.prepared) {
                uploadResult = await uploadBrowserFileStream(form, progress, operation, stream, {signal: abort.signal,
                    galleryId: Number(lifecycle.gallery_id), uploadSessionId: String(lifecycle.session_id),
                    conversionToken: String(lifecycle.session_token), sourceGalleryId: Number(form.querySelector('[name="id"]').value),
                    startPage, startBatchIndex: Number(lifecycle.next_batch_index || 0), onProgress: showProgress});
            }
        }
        status.textContent = i18nForElement(form, 'pdf.phase.finalizing', 'Finalizing / indexing');
        const finish = new FormData(form);
        finish.set('pdf_action', 'finalize');
        finish.set('pdf_conversion_token', String(lifecycle.session_token));
        finish.set('target_gallery_id', String(lifecycle.gallery_id));
        const result = await pdfRequest(form, finish, abort.signal);
        result.uploaded = Number(uploadResult?.uploaded || result.image_ids?.length || 0);
        result.scanned = Number(uploadResult?.scanned || result.image_ids?.length || 0);
        result.thumbnails = Number(uploadResult?.thumbnails || 0);
        result.thumbnail_failed = Number(uploadResult?.thumbnail_failed || 0);
        if (options.completeMutation !== false) await completeAdminMutation(result, {refreshPanel: async () => form.isConnected});
        completed = true;
        progress.querySelector('[data-thumbnail-progress-fill]').value = 100;
        status.textContent = i18nForElement(form, 'pdf.phase.completed', 'Completed');
        if (typeof result.target_url === 'string' && result.target_url) {
            const link = document.createElement('a');
            link.href = result.target_url;
            link.textContent = i18nForElement(form, 'pdf.open_result', 'Open gallery');
            status.append(' · ', link);
        }
        return result;
    } catch (error) {
        if (abort.signal.aborted && lifecycle) {
            const body = new FormData(form);
            body.set('pdf_action', 'cancel');
            body.set('pdf_conversion_token', String(lifecycle.session_token));
            body.set('target_gallery_id', String(lifecycle.gallery_id));
            await pdfRequest(form, body).catch(() => {});
        }
        throw new Error(abort.signal.aborted
            ? i18nForElement(form, 'pdf.cancelled', 'Cancelled. Acknowledged pages are preserved; retry to resume safely.')
            : (error instanceof Error ? error.message : String(error)));
    } finally {
        finishAdminOperation(operation, completed);
        if (stream) await stream.dispose();
        if (cancel) cancel.hidden = true;
        conversions.delete(form);
    }
}
