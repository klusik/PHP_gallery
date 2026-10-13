/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-browser-upload.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Coordinates the browser-side upload preparation path.
 *
 * Responsibilities:
 *   - Detect browser support before changing upload behavior
 *   - Run thumbnail preparation in a bounded worker pool
 *   - Package prepared originals and thumbnails into store-only ZIP batches
 *   - Upload batches with retry and acknowledgement cleanup
 *   - Return control to the normal upload path when no side effect happened
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
 *   2026-09-02
 */

import { appendUploadProgressLog, i18n, updateBasicProgress, updateUploadProgressMetrics } from './admin-core.js?v=20260614-upload-original-diagnostics-v1';
import {adminOperationBody, assertAdminOperationCurrent} from './admin-operation-keys.js?v=20261012-pdf-files-v1';

/**
 * Prepared uploader's existing session retained only for the current document's explicit retries.
 * @typedef {Object} PreparedUploadAttempt
 * @property {File[]} files Expanded immutable originals in upload order; no duplicate file-byte copy.
 * @property {ReturnType<typeof browserUploadConfig>} processingConfig Frozen-by-ownership preparation settings from the original attempt.
 * @property {ReturnType<typeof createBrowserProgressState>} progressState Pre-upload progress snapshot; byte counters use bytes.
 * @property {string} uploadSessionId Existing prepared-batch replay identity, not a classic operation key or credential.
 * @property {Awaited<ReturnType<typeof createBrowserBatcher>>} batcher Original package owner and acknowledged-cleanup callbacks.
 * @property {Array<{index: number, blob: Blob, itemCount: number, cleanup: function(): Promise<void>}>} batches Original indexed ZIP blobs and their existing cleanup callbacks.
 * @property {boolean} serverSideStarted Whether server work may have started and automatic package abandonment is unsafe.
 * @property {Record<string, unknown>} [acknowledgedPhotoResult] Canonical photo aggregate retained after package cleanup while subsequent PDFs are unresolved.
 */

const workerScriptUrl = new URL('./browser-image-worker.js?v=20261013-icloud-v1', import.meta.url);
const tempDatabaseName = 'php_gallery_browser_uploads';
const tempStoreName = 'prepared_batches';

/**
 * Bound temporary buffers used to verify that image-worker output retains a page PNG unchanged.
 * Type: number.
 * Units: bytes per comparison slice.
 * Scope: one input/output PNG pair in the browser uploader.
 * Consumers: filesHaveSameBytes.
 * Rationale: compare every source byte without duplicating a large page into full-size buffers.
 */
const maximumPngCompareChunkBytes = 1024 * 1024;

/**
 * Bound originals retained by a selection containing archives across worker requests.
 * Type: number.
 * Units: bytes. Scope: one upload selection containing at least one ZIP.
 * Consumers: expandBrowserUploadArchives and extractBrowserUploadZip.
 * Rationale: carry the worker's 64 MiB retention ceiling across multiple selected
 * archives instead of allowing each worker to consume a separate full budget.
 */
const maximumArchiveSelectionBytes = 64 * 1024 * 1024;

/**
 * Describe a pull-driven source of generated PDF page PNG files.
 * @typedef {Object} PdfUploadFileStream
 * @property {number|undefined} pageCount Total source PDF page count when known.
 * @property {number|undefined} dpi Output resolution in dots per inch when known.
 * @property {string|undefined} sourceId Caller-owned source document identity.
 * @property {() => AsyncIterator<File>} [Symbol.asyncIterator] Create the ordered page iterator.
 * @property {(() => Promise<void>)|undefined} cancel Cancel current PDF work when supported.
 * @property {(() => Promise<void>)|undefined} dispose Release renderer resources when supported.
 */

/**
 * Describe incremental stream upload settings and progress callbacks.
 * @typedef {Object} PdfUploadStreamOptions
 * @property {AbortSignal|null} [signal] Cancellation signal for rendering and requests.
 * @property {string} [endpoint] Protected same-origin prepared-upload endpoint.
 * @property {string} [uploadSessionId] Server replay identity for prepared ZIP batches.
 * @property {number} [galleryId] Existing destination gallery identifier.
 * @property {string} [conversionToken] Opaque server-issued PDF conversion session token.
 * @property {number} [sourceGalleryId] Gallery that owns the source PDF document.
 * @property {number} [startPage] One-based first source page to upload.
 * @property {number} [startBatchIndex] First batch index for resumed upload.
 * @property {PdfUploadProgressHandler} [onProgress] Observe render, packaging and upload progress.
 * @property {PdfUploadAcknowledgmentHandler} [onBatchAcknowledged] Observe durable batch acknowledgment.
 */

/**
 * Describe progress emitted while pages are rendered or uploaded.
 * @typedef {Object} PdfUploadProgress
 * @property {string} phase Current lifecycle phase such as prepared, packaging, uploading or acknowledged.
 * @property {number} page One-based source page associated with the update.
 * @property {number} pages Total source PDF page count.
 * @property {number} dpi Output resolution in dots per inch.
 * @property {number|undefined} [batch] One-based ZIP batch number.
 * @property {number|undefined} [bytes] Current batch size in bytes.
 * @property {number|undefined} [uploadedBytes] Bytes acknowledged in the current batch.
 */

/** @typedef {(progress: PdfUploadProgress) => void} PdfUploadProgressHandler */

/**
 * Describe a durable server acknowledgment for one prepared page batch.
 * @typedef {Object} PdfUploadBatchAcknowledgment
 * @property {number} batchIndex Zero-based batch index.
 * @property {number} itemCount Number of page PNG files in the batch.
 * @property {Record<string, unknown>} result Server acknowledgment fields.
 */

/** @typedef {(acknowledgment: PdfUploadBatchAcknowledgment) => Promise<void>|void} PdfUploadAcknowledgmentHandler */

/**
 * Describe a prepared ZIP batch owned by the existing browser upload batcher.
 * @typedef {Object} PdfUploadBatch
 * @property {string} id Stable batch storage identifier.
 * @property {number} index Zero-based batch index.
 * @property {Blob} blob Store-only ZIP bytes retained until acknowledgment.
 * @property {number} itemCount Number of PNG pages in the batch.
 * @property {number|undefined} [firstSourceIndex] Zero-based first source page index.
 * @property {number|undefined} [lastSourceIndex] Zero-based final source page index.
 * @property {() => Promise<void>} cleanup Remove the batch's temporary store data.
 */

/**
 * Describe one XMLHttpRequest byte progress event.
 * @typedef {Object} PdfUploadXhrProgress
 * @property {boolean} lengthComputable Whether the browser reports a complete request length.
 * @property {number} total Total request bytes when computable.
 * @property {number} loaded Request bytes sent so far.
 */

/** @typedef {(event: PdfUploadXhrProgress) => void} PdfUploadXhrProgressHandler */

/**
 * Return whether the browser-side upload path is enabled for this form submission.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {boolean} True when the browser-side preparation checkbox is checked or a PDF needs browser conversion.
 */
export function browserUploadRequested(form) {
    if (selectedBrowserUploadPdfFiles(form).length > 0) return true;
    const toggle = form.querySelector('[data-browser-upload-toggle]');
    return toggle instanceof HTMLInputElement && toggle.checked && !toggle.disabled;
}

/**
 * Package and upload an ordered File stream through the existing prepared-image path.
 *
 * Files are consumed serially, stored by the existing IndexedDB batcher, and packaged
 * with its store-only ZIP worker. The stream is never expanded into a document-wide
 * File array. An uncertain upload retains the original ZIP batches on the operation
 * for an exact retry, while each acknowledged batch is reported before local cleanup.
 *
 * @param {HTMLFormElement} form Existing Admin upload or create-gallery form.
 * @param {HTMLElement} progress Existing upload progress container.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Active form intent that owns retry identity.
 * @param {PdfUploadFileStream} fileStream Ordered File stream, including optional PDF metadata and cleanup methods.
 * @param {PdfUploadStreamOptions} options Stream transport and acknowledgment options.
 * @returns {Promise<Record<string, unknown>>} Canonical merged server acknowledgment fields and accumulated upload counters.
 */
export async function uploadBrowserFileStream(form, progress, operation, fileStream, options = {}) {
    if (!fileStream || typeof fileStream[Symbol.asyncIterator] !== 'function') {
        throw new Error(i18n('pdf.upload.stream_unavailable', 'The browser upload stream is unavailable.'));
    }
    const config = browserUploadConfig(form);
    const endpoint = String(options.endpoint || config.endpoint || '');
    const endpointUrl = new URL(endpoint, window.location.href);
    if (!config.enabled || !endpoint || endpointUrl.origin !== window.location.origin) {
        throw new Error(i18n('pdf.upload.endpoint_unavailable', 'The protected browser upload endpoint is unavailable. No pages were uploaded.'));
    }
    const signal = options.signal || null;
    const startPage = Math.max(1, Math.floor(Number(options.startPage || 1)));
    const pageCount = Math.max(0, Math.floor(Number(fileStream.pageCount || 0)));
    const totalFiles = pageCount > 0 ? Math.max(0, pageCount - startPage + 1) : 0;
    let prepared = operation.prepared;
    const isResume = Boolean(prepared);
    let batcher = null;
    let serverSideStarted = Boolean(prepared?.serverSideStarted);
    let processingConfig;
    let uploadSessionId;
    let progressState;
    const prepareThumbnails = Boolean(form.querySelector('input[name="create_thumbnails"]')?.checked);

    if (prepared) {
        if (prepared.kind !== 'pdf-file-stream') {
            throw new Error(i18n('pdf.upload.retry_pending', 'Another prepared upload is awaiting an exact retry. Keep the original upload form open.'));
        }
        if (startPage !== Number(prepared.nextSourceIndex || 0) + 1) {
            throw new Error(i18n('pdf.upload.resume_page', 'Resume this PDF conversion at page {page}.', {page: Number(prepared.nextSourceIndex || 0) + 1}));
        }
        ({processingConfig, uploadSessionId, progressState, batcher} = prepared);
    } else {
        processingConfig = {
            ...config,
            thumbnailSizes: prepareThumbnails ? config.thumbnailSizes : [],
            thumbnailFormats: prepareThumbnails ? config.thumbnailFormats : [],
            workerCount: 1,
        };
        uploadSessionId = String(options.uploadSessionId || browserUploadSessionId());
        progressState = {
            totalFiles: pageCount,
            totalOriginalBytes: 0,
            preparedFiles: startPage - 1,
            preparedOriginalBytes: 0,
            preparedPackageBytes: 0,
            exifFiles: 0,
            gpsFiles: 0,
            totalBatches: 0,
            totalZipBytes: 0,
            uploadedBatches: 0,
            uploadedFiles: startPage - 1,
            uploadedZipBytes: 0,
            currentBatchIndex: 0,
            currentBatchBytes: 0,
            currentBatchUploadedBytes: 0,
        };
    }

    try {
        if (!prepared) {
            batcher = await createBrowserBatcher({...processingConfig, expectedItemCount: pageCount, startBatchIndex: options.startBatchIndex}, uploadSessionId, {
                onBatchPackaging: (batchIndex, itemCount, byteCount) => {
                    appendUploadProgressLog(progress, i18n('pdf.upload.packaging', 'Packaging PDF pages ZIP {batch}: {count} page(s), estimated {bytes}.', {batch: batchIndex + 1, count: itemCount, bytes: formatFileSize(byteCount)}));
                    if (typeof options.onProgress === 'function') options.onProgress({phase: 'packaging', page: Number(prepared?.nextSourceIndex || 0), pages: pageCount, dpi: Number(fileStream.dpi || 150), batch: batchIndex + 1, bytes: byteCount, uploadedBytes: 0});
                },
                onBatchReady: async (batch) => {
                    appendUploadProgressLog(progress, i18n('pdf.upload.batch_ready', 'PDF pages ZIP {batch} ready: {count} page(s), {bytes}.', {batch: batch.index + 1, count: batch.itemCount, bytes: formatFileSize(batch.blob.size)}));
                    await acknowledgePdfUploadBatch(form, progress, operation, batch, prepared.transportOptions);
                },
            });
            updateBasicProgress(progress, 1, i18n('pdf.upload.preparing', 'Preparing {count} PDF page image(s) in the browser...', {count: totalFiles || 'PDF'}));
            let sourceIndex = startPage - 1;
            const existingGalleryId = Number(options.galleryId || selectedBrowserGalleryId(form) || 0);
            if (!existingGalleryId) throw new Error(i18n('pdf.upload.destination_required', 'Select the destination gallery before starting PDF page upload.'));
            prepared = {
                kind: 'pdf-file-stream',
                processingConfig,
                uploadSessionId,
                progressState,
                batcher,
                batches: batcher.batches,
                acknowledgedBatches: new Set(),
                serverSideStarted: false,
                gallerySeed: null,
                galleryId: existingGalleryId,
                endpoint: endpointUrl.href,
                conversionToken: String(options.conversionToken || ''),
                sourceGalleryId: Number(options.sourceGalleryId || 0),
                startPage,
                nextSourceIndex: sourceIndex,
                aggregate: emptyBrowserAggregate(null, existingGalleryId, 0),
                transportOptions: null,
            };
            operation.prepared = prepared;
        }
        progressState = prepared.progressState;
        prepared.transportOptions = {
            endpoint: prepared.endpoint || endpointUrl.href,
            uploadSessionId: prepared.uploadSessionId || uploadSessionId,
            galleryId: Number(prepared.galleryId || options.galleryId || selectedBrowserGalleryId(form) || 0),
            conversionToken: prepared.conversionToken || String(options.conversionToken || ''),
            sourceGalleryId: Number(prepared.sourceGalleryId || options.sourceGalleryId || 0),
            preparedThumbnailsRequired: prepareThumbnails,
            signal,
            onBatchAcknowledged: options.onBatchAcknowledged,
            onProgress: options.onProgress,
            processingConfig: prepared.processingConfig,
            progressState,
            pageCount,
            dpi: Number(fileStream.dpi || 150),
        };
        updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
        if (isResume) {
            for (const batch of prepared.batches) {
                if (batch.blob && !prepared.acknowledgedBatches.has(batch.index)) {
                    await acknowledgePdfUploadBatch(form, progress, operation, batch, prepared.transportOptions);
                }
            }
        }
        await consumePdfPageStream(fileStream, startPage, pageCount, progress, batcher, prepared, prepared.transportOptions, options.onProgress);
        if (prepared.nextSourceIndex < pageCount) {
            throw new Error(i18n('pdf.upload.stream_ended_early', 'PDF conversion ended before page {page}.', {page: prepared.nextSourceIndex + 1}));
        }
        await batcher.finish();
        if (!prepared.batches.length) throw new Error(i18n('pdf.upload.no_batches', 'The PDF conversion produced no upload batches.'));
        for (const batch of prepared.batches) {
            if (batch.blob && !prepared.acknowledgedBatches.has(batch.index)) {
                await acknowledgePdfUploadBatch(form, progress, operation, batch, {
                    ...prepared.transportOptions,
                });
            }
        }
        await batcher.cleanupPrepared();
        updateBasicProgress(progress, 98, i18n('pdf.upload.finishing', 'Finishing PDF page upload...'));
        const aggregate = prepared.aggregate;
        aggregate.total_files = progressState.totalFiles;
        aggregate.redirect_url = appendUploadResultParams(aggregate.redirect_url || window.location.href, aggregate.uploaded, aggregate.scanned, aggregate.thumbnails, aggregate.thumbnail_failed);
        aggregate.fallback = {...(aggregate.fallback || {}), redirect_url: aggregate.redirect_url};
        if (aggregate.created_gallery && aggregate.gallery_id > 0) {
            aggregate.mutation = {type: 'gallery.create_with_upload', entity: 'gallery', action: 'create', entity_ids: [aggregate.gallery_id]};
        } else if (aggregate.mutation && typeof aggregate.mutation === 'object') {
            aggregate.mutation = {...aggregate.mutation, entity_ids: aggregate.image_ids.slice()};
        }
        return aggregate;
    } catch (error) {
        if (fileStream && typeof fileStream.cancel === 'function' && signal?.aborted) await fileStream.cancel();
        if (batcher && !serverSideStarted && !prepared?.serverSideStarted) {
            await batcher.abort();
            operation.prepared = null;
        }
        throw error;
    } finally {
        if (fileStream && typeof fileStream.dispose === 'function') await fileStream.dispose();
    }
}

/**
 * Consume generated page Files one at a time and upload each bounded batch before pulling again.
 * @param {PdfUploadFileStream} fileStream Pull-driven PDF page stream.
 * @param {number} startPage One-based first source page in this stream.
 * @param {number} pageCount Total source PDF pages.
 * @param {HTMLElement} progress Existing upload progress surface.
 * @param {Awaited<ReturnType<typeof createBrowserBatcher>>} batcher Existing ZIP batcher.
 * @param {{processingConfig: Record<string, unknown>, progressState: Record<string, number>, nextSourceIndex: number}} prepared Retained stream preparation state.
 * @param {PdfUploadStreamOptions & {endpoint: string, uploadSessionId: string, galleryId: number, conversionToken: string, sourceGalleryId: number, preparedThumbnailsRequired: boolean, dpi: number, pageCount: number, processingConfig: Record<string, unknown>, progressState: Record<string, number>}} transportOptions Current batch transport, progress counters and callbacks.
 * @param {PdfUploadProgressHandler|undefined} onProgress Optional conversion and upload progress callback.
 * @returns {Promise<void>} Resolves after each page has been staged and eligible batches acknowledged.
 */
async function consumePdfPageStream(fileStream, startPage, pageCount, progress, batcher, prepared, transportOptions, onProgress) {
    let sourceIndex = startPage - 1;
    const totalPagesRemaining = Math.max(0, pageCount - startPage + 1);
    const iterator = fileStream[Symbol.asyncIterator]();
    let exhausted = false;
    try {
        while (true) {
            throwIfUploadStreamAborted(transportOptions.signal);
            const next = await iterator.next();
            if (next.done) {
                exhausted = true;
                break;
            }
            const file = next.value;
            throwIfUploadStreamAborted(transportOptions.signal);
            if (!(file instanceof File) || file.type !== 'image/png' || !/\.png$/i.test(file.name)) {
                throw new Error(i18n('pdf.upload.invalid_page_file', 'The PDF converter must provide genuine PNG Files in page order.'));
            }
            const item = await prepareStreamPngFile(file, prepared.processingConfig, sourceIndex);
            throwIfUploadStreamAborted(transportOptions.signal);
            if (batcher.shouldFlushBefore(item)) await batcher.flush();
            const added = await batcher.addItem(item);
            prepared.progressState.preparedFiles++;
            prepared.progressState.totalOriginalBytes += file.size;
            prepared.progressState.preparedOriginalBytes += file.size;
            prepared.progressState.preparedPackageBytes += added.itemBytes;
            sourceIndex++;
            prepared.nextSourceIndex = sourceIndex;
            updateBasicProgress(progress, Math.max(2, totalPagesRemaining ? Math.round(prepared.progressState.preparedFiles / prepared.progressState.totalFiles * 45) : 2), i18n('pdf.upload.prepared_page', 'Prepared PDF page {page} in the browser.', {page: sourceIndex}));
            updateUploadProgressMetrics(progress, browserProgressMetrics(prepared.progressState));
            if (typeof onProgress === 'function') {
                onProgress({phase: 'prepared', page: sourceIndex, pages: pageCount, dpi: Number(fileStream.dpi || 150)});
            }
            if (added.flushRequired) await batcher.flush();
        }
    } finally {
        if (!exhausted && typeof iterator.return === 'function') await iterator.return();
    }
}

/**
 * Upload one newly packaged PDF page batch and release its bytes only after acknowledgment.
 * @param {HTMLFormElement} form Original upload form with current CSRF fields.
 * @param {HTMLElement} progress Upload progress surface.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Active upload intent.
 * @param {PdfUploadBatch} batch One prepared page ZIP batch.
 * @param {PdfUploadStreamOptions & {endpoint: string, uploadSessionId: string, galleryId: number, conversionToken: string, sourceGalleryId: number, preparedThumbnailsRequired: boolean, processingConfig: Record<string, unknown>, progressState: Record<string, number>, pageCount: number, dpi?: number}} options Batch transport and progress state.
 * @returns {Promise<void>} Resolves after durable acknowledgment and local batch cleanup.
 */
async function acknowledgePdfUploadBatch(form, progress, operation, batch, options) {
    throwIfUploadStreamAborted(options.signal);
    assertAdminOperationCurrent(operation);
    operation.unresolved = true;
    operation.prepared.serverSideStarted = true;
    const progressState = options.progressState;
    const uploadedBeforeBatch = progressState.uploadedZipBytes;
    progressState.currentBatchIndex = batch.index + 1;
    progressState.currentBatchBytes = Number(batch.blob.size || 0);
    progressState.currentBatchUploadedBytes = 0;
    const batchPage = Math.max(0, Number(batch.lastSourceIndex ?? operation.prepared.nextSourceIndex - 1) + 1);
    if (typeof options.onProgress === 'function') options.onProgress({phase: 'uploading', page: batchPage, pages: options.pageCount, dpi: Number(options.dpi || 150), batch: batch.index + 1, bytes: progressState.currentBatchBytes, uploadedBytes: 0});
    const transport = {endpoint: options.endpoint, conversionToken: options.conversionToken, sourceGalleryId: options.sourceGalleryId, signal: options.signal};
    const result = await uploadPreparedBatchWithRetry(form, options.processingConfig, options.galleryId, options.uploadSessionId, batch, batch.index, 0, options.preparedThumbnailsRequired, (event) => {
        if (event.lengthComputable && event.total > 0) {
            progressState.currentBatchUploadedBytes = Math.round(Number(batch.blob.size || 0) * Math.max(0, Math.min(1, event.loaded / event.total)));
        }
        progressState.uploadedZipBytes = uploadedBeforeBatch + progressState.currentBatchUploadedBytes;
        updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
        if (typeof options.onProgress === 'function') options.onProgress({phase: 'uploading', page: batchPage, pages: options.pageCount, dpi: Number(options.dpi || 150), batch: batch.index + 1, bytes: progressState.currentBatchBytes, uploadedBytes: progressState.currentBatchUploadedBytes});
    }, transport);
    progressState.uploadedBatches++;
    progressState.uploadedFiles += Number(batch.itemCount || 0);
    progressState.uploadedZipBytes = uploadedBeforeBatch + Number(batch.blob.size || 0);
    progressState.currentBatchUploadedBytes = Number(batch.blob.size || 0);
    updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
    if (typeof options.onProgress === 'function') options.onProgress({phase: 'acknowledged', page: batchPage, pages: options.pageCount, dpi: Number(options.dpi || 150), batch: batch.index + 1, bytes: progressState.currentBatchBytes, uploadedBytes: progressState.currentBatchBytes});
    appendServerUploadEvents(progress, result.upload_events || []);
    mergeBrowserResult(operation.prepared.aggregate, result);
    if (typeof options.onBatchAcknowledged === 'function') {
        await options.onBatchAcknowledged({batchIndex: batch.index, itemCount: Number(batch.itemCount || 0), result});
    }
    operation.prepared.acknowledgedBatches.add(batch.index);
    await batch.cleanup();
    batch.blob = null;
}

/**
 * Throw when an upload stream has been cancelled by its owning form workflow.
 * @param {AbortSignal|null} signal Optional PDF or assisted archive cancellation signal.
 * @returns {void} No value when the operation remains active.
 */
function throwIfUploadStreamAborted(signal) {
    if (signal && signal.aborted) throw new Error(i18n('admin.icloud.cancelled', 'Import cancelled. Keep this form open and retry to reconcile any batch already sent.'));
}

/**
 * Prepare one rendered PNG with the existing image worker while preserving its original bytes.
 * @param {File} file Rendered PNG page from the PDF converter.
 * @param {Record<string, unknown>} config Existing thumbnail preparation settings.
 * @param {number} sourceIndex Stable zero-based page order for ZIP manifests.
 * @returns {Promise<{originalFile: File, originalDetectedFormat: string, originalPath: string, originalName: string, originalSize: number, variants: Array<{path: string, blob: Blob}>, sourceIndex: number}>} Prepared image metadata with the exact input PNG as originalFile.
 */
async function prepareStreamPngFile(file, config, sourceIndex) {
    let prepared = null;
    await processFilesWithWorkerPool([file], {...config, workerCount: 1, workerItemIdOffset: sourceIndex}, async (item) => {
        prepared = {...item, sourceIndex};
    });
    if (!prepared || prepared.originalDetectedFormat !== 'png' || !(await filesHaveSameBytes(prepared.originalFile, file))) {
        throw new Error(i18n('pdf.upload.page_prepare_failed', 'Rendered PDF page {page} failed PNG preparation.', {page: sourceIndex + 1}));
    }
    return prepared;
}

/**
 * Compare a worker-returned original with its input without retaining whole-document data.
 * @param {File} preparedFile Original File returned by the image worker.
 * @param {File} inputFile Exact rendered PNG supplied to the worker.
 * @returns {Promise<boolean>} True only when MIME, size and every byte match.
 */
async function filesHaveSameBytes(preparedFile, inputFile) {
    if (!(preparedFile instanceof File) || preparedFile.type !== inputFile.type || preparedFile.size !== inputFile.size) return false;
    for (let offset = 0; offset < inputFile.size; offset += maximumPngCompareChunkBytes) {
        const end = Math.min(inputFile.size, offset + maximumPngCompareChunkBytes);
        const [left, right] = await Promise.all([
            preparedFile.slice(offset, end).arrayBuffer(),
            inputFile.slice(offset, end).arrayBuffer(),
        ]);
        const first = new Uint8Array(left);
        const second = new Uint8Array(right);
        for (let index = 0; index < first.length; index++) {
            if (first[index] !== second[index]) return false;
        }
    }
    return true;
}

/**
 * Run the browser upload path or ask the caller to use the default path.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {HTMLElement} progress Progress container.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Original form intent; retains this uploader's own session/batches after uncertainty.
 * @param {AbortSignal|null} signal Optional assisted-import cancellation; retains uncertain prepared batches for exact replay.
 * @return {Promise<import('./admin-side-panel.js').AdminPanelResponse|{fallback:true,reason:string}>} Canonical upload response or no-files fallback request.
 */
export async function runBrowserGalleryUpload(form, progress, operation, signal = null) {
    throwIfUploadStreamAborted(signal);
    const config = browserUploadConfig(form);
    const selectedFiles = selectedBrowserUploadFiles(form);
    const pdfFiles = selectedBrowserUploadPdfFiles(form);
    const archiveSelected = selectedFiles.some(isBrowserUploadZipFile);
    const prepareThumbnails = Boolean(form.querySelector('input[name="create_thumbnails"]')?.checked);
    if (pdfFiles.length > 0) {
        if (!Object.prototype.hasOwnProperty.call(form.dataset, 'pdfOriginalCloseOnSuccess')) {
            form.dataset.pdfOriginalCloseOnSuccess = form.hasAttribute('data-gallery-panel-close-on-success')
                ? form.getAttribute('data-gallery-panel-close-on-success') || ''
                : '__absent__';
        }
        form.setAttribute('data-gallery-panel-close-on-success', '0');
    } else if (Object.prototype.hasOwnProperty.call(form.dataset, 'pdfOriginalCloseOnSuccess')) {
        const originalValue = form.dataset.pdfOriginalCloseOnSuccess;
        if (originalValue === '__absent__') form.removeAttribute('data-gallery-panel-close-on-success');
        else form.setAttribute('data-gallery-panel-close-on-success', originalValue);
        delete form.dataset.pdfOriginalCloseOnSuccess;
    }
    if (selectedFiles.length === 0 && pdfFiles.length === 0) {
        return {fallback: true, reason: 'no_files'};
    }
    if (!config.enabled) {
        throw new Error(i18n('admin.browser_upload.strict_unavailable', 'Browser-side processing is enabled for this upload, but it is unavailable. No files were uploaded. Uncheck browser-side processing to use the server path instead.'));
    }
    const capability = selectedFiles.length ? browserUploadCapability(config, selectedFiles) : {ok: true};
    if (!capability.ok) {
        if (archiveSelected) throw new Error(i18n('admin.browser_upload.zip_browser_required', 'ZIP import requires the browser-assisted upload path and a compatible browser.'));
        throw new Error(browserUploadStrictCapabilityMessage(capability.reason));
    }
    if (pdfFiles.length > 0 && operation.prepared?.acknowledgedPhotoResult) {
        assertAdminOperationCurrent(operation);
        return uploadSelectedGalleryPdfFiles(form, progress, pdfFiles, operation, operation.prepared.acknowledgedPhotoResult);
    }

    const processingConfig = operation.prepared?.processingConfig || {
        ...config,
        thumbnailSizes: prepareThumbnails ? config.thumbnailSizes : [],
        thumbnailFormats: prepareThumbnails ? config.thumbnailFormats : [],
    };

    if (selectedFiles.length === 0) {
        return uploadSelectedGalleryPdfFiles(form, progress, pdfFiles, operation, null);
    }

    updateBasicProgress(progress, 1, archiveSelected ? i18n('admin.browser_upload.extracting_archives', 'Inspecting selected ZIP archives in the browser...') : i18n('admin.browser_upload.preparing', 'Preparing images in the browser...'));
    const expanded = operation.prepared || await expandBrowserUploadArchives(selectedFiles, {...processingConfig, signal}, progress, Boolean(form.querySelector('[data-gallery-upload-preview]')));
    const files = expanded.files;
    if (files.length === 0 && pdfFiles.length > 0) {
        return uploadSelectedGalleryPdfFiles(form, progress, pdfFiles, operation, null);
    }
    if (files.length === 0) throw new Error(i18n('admin.browser_upload.zip_no_supported_images', 'No supported images were found in the selected ZIP archives.'));
    const progressState = operation.prepared ? {...operation.prepared.progressState} : createBrowserProgressState(files);
    updateBasicProgress(progress, 1, i18n('admin.browser_upload.preparing', 'Preparing images in the browser...'));
    updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
    appendUploadProgressLog(progress, i18n('admin.browser_upload.log_selected', 'Selected {count} image(s), {bytes} original data.', {count: files.length, bytes: formatFileSize(progressState.totalOriginalBytes)}));
    const uploadSessionId = operation.prepared?.uploadSessionId || browserUploadSessionId();
    let batcher = operation.prepared?.batcher || null;
    let serverSideStarted = operation.prepared?.serverSideStarted || false;

    try {
        if (!operation.prepared) {
            batcher = await createBrowserBatcher(processingConfig, uploadSessionId, {
                onBatchPackaging: (batchIndex, itemCount, byteCount) => {
                    appendUploadProgressLog(progress, i18n('admin.browser_upload.log_packaging_batch', 'Packaging ZIP {index}: {count} image(s), estimated {bytes}.', {index: batchIndex + 1, count: itemCount, bytes: formatFileSize(byteCount)}));
                },
                onBatchReady: (batch) => {
                    appendUploadProgressLog(progress, i18n('admin.browser_upload.log_packaged_batch', 'ZIP {index} ready: {count} image(s), {bytes}.', {index: batch.index + 1, count: batch.itemCount || 0, bytes: formatFileSize(batch.blob?.size || 0)}));
                },
            });
            await processFilesWithWorkerPool(files, {...processingConfig, signal}, async (item, completed, total) => {
                const prepared = await batcher.addItem(item);
                progressState.preparedFiles = completed;
                progressState.preparedOriginalBytes += Number(item.originalSize || 0);
                progressState.preparedPackageBytes += Number(prepared.itemBytes || 0);
                if (item.clientExif && typeof item.clientExif === 'object') {
                    progressState.exifFiles++;
                    if (clientExifHasGps(item.clientExif)) {
                        progressState.gpsFiles++;
                    }
                }
                updateBasicProgress(progress, Math.max(2, Math.round((completed / total) * 45)), i18n('admin.browser_upload.prepared_count', 'Prepared {count} of {total} image(s) in the browser.', {count: completed, total}));
                updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
                appendUploadProgressLog(progress, i18n('admin.browser_upload.log_prepared_image', 'Prepared {current}/{total}: {name}, source {source}, package {packageSize}.', {current: completed, total, name: String(item.originalName || ''), source: formatFileSize(item.originalSize || 0), packageSize: formatFileSize(prepared.itemBytes || 0)}));
            });
            updateBasicProgress(progress, 46, i18n('admin.browser_upload.packaging', 'Packaging prepared images into upload ZIP batches...'));
            appendUploadProgressLog(progress, i18n('admin.browser_upload.log_packaging_started', 'All images are prepared. Building upload ZIP files now.'));
            const batches = await batcher.finish();
            throwIfUploadStreamAborted(signal);
            if (batches.length === 0) {
                throw new Error(i18n('admin.browser_upload.strict_empty_batches', 'Browser-side processing produced no upload batches. No files were uploaded; the server-side fallback was not started.'));
            }
            // Keep the existing prepared session and exact ZIP blobs for an explicit
            // retry. Never generate a second session after an uncertain batch response.
            operation.prepared = {files, processingConfig, progressState: {...progressState}, uploadSessionId, batcher, batches, serverSideStarted: false};
        }
        const {batches} = operation.prepared;
        progressState.totalBatches = batches.length;
        progressState.totalZipBytes = batches.reduce((sum, batch) => sum + Number(batch.blob?.size || 0), 0);
        updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
        appendUploadProgressLog(progress, i18n('admin.browser_upload.log_packaging_finished', 'Created {count} ZIP upload file(s), total {bytes}.', {count: batches.length, bytes: formatFileSize(progressState.totalZipBytes)}));

        let gallerySeed = null;
        let galleryId = selectedBrowserGalleryId(form);
        throwIfUploadStreamAborted(signal);
        if (!galleryId) {
            updateBasicProgress(progress, 48, i18n('admin.browser_upload.creating_gallery', 'Creating gallery before uploading prepared batches...'));
            appendUploadProgressLog(progress, i18n('admin.browser_upload.log_creating_gallery', 'Creating target gallery before uploading ZIP files.'));
            assertAdminOperationCurrent(operation);
            serverSideStarted = true;
            operation.prepared.serverSideStarted = true;
            gallerySeed = await createGalleryForBrowserUpload(form, operation);
            galleryId = Number(gallerySeed.gallery_id || 0);
            appendServerUploadEvents(progress, gallerySeed.upload_events || []);
            if (!galleryId) {
                throw new Error(i18n('admin.browser_upload.gallery_create_failed', 'The gallery was created, but no gallery id was returned.'));
            }
        }

        const aggregate = emptyBrowserAggregate(gallerySeed, galleryId, batches.length);
        for (let index = 0; index < batches.length; index++) {
            throwIfUploadStreamAborted(signal);
            const batch = batches[index];
            const uploadedBeforeBatch = progressState.uploadedZipBytes;
            progressState.currentBatchIndex = index + 1;
            progressState.currentBatchBytes = Number(batch.blob?.size || 0);
            progressState.currentBatchUploadedBytes = 0;
            updateBasicProgress(progress, 50 + Math.round((index / batches.length) * 45), i18n('admin.browser_upload.uploading_batch', 'Uploading prepared ZIP batch {current} of {total}...', {current: index + 1, total: batches.length}));
            updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
            appendUploadProgressLog(progress, i18n('admin.browser_upload.log_uploading_batch', 'Uploading ZIP {current}/{total}: {count} image(s), {bytes}.', {current: index + 1, total: batches.length, count: batch.itemCount || 0, bytes: formatFileSize(batch.blob?.size || 0)}));
            assertAdminOperationCurrent(operation);
            serverSideStarted = true;
            operation.prepared.serverSideStarted = true;
            operation.unresolved = true;
            const result = await uploadPreparedBatchWithRetry(form, processingConfig, galleryId, uploadSessionId, batch, index, batches.length, prepareThumbnails, (event) => {
                if (event.lengthComputable && event.total > 0) {
                    const ratio = Math.max(0, Math.min(1, event.loaded / event.total));
                    progressState.currentBatchUploadedBytes = Math.round(Number(batch.blob?.size || 0) * ratio);
                }
                progressState.uploadedZipBytes = uploadedBeforeBatch + progressState.currentBatchUploadedBytes;
                updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
            }, {signal});
            progressState.uploadedBatches = index + 1;
            progressState.uploadedFiles += Number(batch.itemCount || 0);
            progressState.uploadedZipBytes = uploadedBeforeBatch + Number(batch.blob?.size || 0);
            progressState.currentBatchUploadedBytes = Number(batch.blob?.size || 0);
            updateUploadProgressMetrics(progress, browserProgressMetrics(progressState));
            appendServerUploadEvents(progress, result.upload_events || []);
            appendUploadProgressLog(progress, i18n('admin.browser_upload.log_batch_finished', 'Finished ZIP {current}/{total}: {uploaded} image(s), {thumbs} prepared thumbnail file(s).', {current: index + 1, total: batches.length, uploaded: Number(result.uploaded || 0), thumbs: Number(result.thumbnails || 0)}));
            mergeBrowserResult(aggregate, result);
            await batch.cleanup();
        }
        if (typeof batcher.cleanupPrepared === 'function') {
            await batcher.cleanupPrepared();
        }
        updateBasicProgress(progress, 98, i18n('admin.browser_upload.finishing', 'Finishing browser-prepared upload...'));
        aggregate.total_files = files.length;
        aggregate.redirect_url = appendUploadResultParams(aggregate.redirect_url || window.location.href, aggregate.uploaded, aggregate.scanned, aggregate.thumbnails, aggregate.thumbnail_failed);
        aggregate.fallback = {...(aggregate.fallback || {}), redirect_url: aggregate.redirect_url};
        if (aggregate.created_gallery && aggregate.gallery_id > 0) {
            aggregate.mutation = {
                type: 'gallery.create_with_upload',
                entity: 'gallery',
                action: 'create',
                entity_ids: [aggregate.gallery_id],
            };
        } else if (aggregate.mutation && typeof aggregate.mutation === 'object') {
            aggregate.mutation = {
                ...aggregate.mutation,
                entity_ids: aggregate.image_ids.slice(),
            };
        }
        if (pdfFiles.length > 0) {
            // Photo batches have already been acknowledged and their temporary
            // packages cleaned. A PDF retry must reuse that response, not replay
            // the retired photo packages or create another photo upload.
            operation.prepared.acknowledgedPhotoResult = aggregate;
            return await uploadSelectedGalleryPdfFiles(form, progress, pdfFiles, operation, aggregate);
        }
        return aggregate;
    } catch (error) {
        if (batcher && !serverSideStarted) {
            await batcher.abort();
            operation.prepared = null;
        }
        if (!serverSideStarted && ['QuotaExceededError', 'InvalidStateError', 'SecurityError', 'UnknownError'].includes(error?.name)) {
            throw new Error(i18n('admin.icloud.storage_failed'));
        }
        if (!serverSideStarted && !archiveSelected) {
            const reason = error instanceof Error ? error.message : String(error || 'preparation_failed');
            throw new Error(i18n('admin.browser_upload.strict_preparation_failed', 'Browser-side preparation failed before anything was written to the server. No server-side fallback was started. Details: {error}', {error: reason}));
        }
        throw error;
    }
}

/**
 * Return a readable strict-mode failure for a missing browser capability.
 *
 * Browser-side processing is an explicit checkbox choice. When selected files
 * cannot use that path, fail before persistence instead of silently changing the
 * thumbnail-processing location to PHP.
 *
 * @param {string} reason Capability reason identifier.
 * @return {string} Browser-visible failure message.
 */
function browserUploadStrictCapabilityMessage(reason) {
    const detail = {
        missing_core_browser_api: 'Required browser APIs are unavailable.',
        missing_indexeddb: 'IndexedDB is unavailable.',
        missing_canvas_worker_api: 'OffscreenCanvas or createImageBitmap is unavailable.',
        unsupported_file_type: 'At least one selected file type cannot be prepared in the browser.',
        single_file_larger_than_upload_limit: 'At least one selected file is larger than the server can accept in one prepared batch.',
    }[String(reason || '')] || 'Browser capability validation failed.';
    return i18n('admin.browser_upload.strict_capability_failed', 'Browser-side processing cannot be used for this upload. No files were uploaded and the server-side fallback was not started. {reason} Uncheck browser-side processing to use the server path instead.', {reason: detail});
}

/**
 * Read JSON configuration emitted by PHP near the browser-side preparation checkbox.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {Record<string, *>} Normalized browser configuration.
 */
function browserUploadConfig(form) {
    const toggle = form.querySelector('[data-browser-upload-toggle]');
    let parsed = {};
    if (toggle instanceof HTMLInputElement) {
        try {
            parsed = JSON.parse(toggle.dataset.browserUploadConfig || '{}');
        } catch (error) {
            parsed = {};
        }
    }
    const hardCap = clampInteger(parsed.hard_worker_cap, 32, 1, 32);
    const maxWorkers = clampInteger(parsed.max_worker_count, hardCap, 1, hardCap);
    const workerCount = clampInteger(parsed.worker_count, 8, 1, maxWorkers);
    const uploadLimitBytes = clampInteger(parsed.upload_limit_bytes, 8 * 1024 * 1024, 1, Number.MAX_SAFE_INTEGER);
    const batchTargetBytes = clampInteger(parsed.batch_target_bytes, Math.floor(uploadLimitBytes * 0.8), 1, uploadLimitBytes);
    const maxItemsPerBatch = clampInteger(parsed.max_items_per_batch, 8, 1, 64);
    const formats = Array.isArray(parsed.thumbnail_formats) ? parsed.thumbnail_formats.filter((format) => ['jpg', 'webp'].includes(String(format))) : ['webp'];
    const sizes = Array.isArray(parsed.thumbnail_sizes) ? parsed.thumbnail_sizes.map((size) => Number(size)).filter((size) => Number.isInteger(size) && size > 0) : [300, 600, 800, 960, 1280, 1600];
    return {
        enabled: Boolean(parsed.enabled),
        endpoint: String(parsed.endpoint || ''),
        workerCount,
        maxWorkers,
        hardCap,
        uploadLimitBytes,
        batchTargetBytes,
        maxItemsPerBatch,
        thumbnailFormats: formats.length ? formats : ['webp'],
        thumbnailSizes: sizes.length ? sizes : [300, 600, 800, 960, 1280, 1600],
        jpegQuality: clampQuality(parsed.jpeg_quality, 82),
        webpQuality: clampQuality(parsed.webp_quality, 82),
        supportedMimeTypes: Array.isArray(parsed.supported_mime_types) ? parsed.supported_mime_types.map(String) : ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    };
}

/**
 * Clamp an integer value.
 *
 * @param {*} value Input value.
 * @param {number} fallback Fallback value.
 * @param {number} minimum Minimum value.
 * @param {number} maximum Maximum value.
 * @return {number} Clamped integer.
 */
function clampInteger(value, fallback, minimum, maximum) {
    const parsed = Number.parseInt(String(value), 10);
    const number = Number.isFinite(parsed) ? parsed : fallback;
    return Math.max(minimum, Math.min(maximum, number));
}

/**
 * Normalize image export quality from a 0 to 100 PHP setting.
 *
 * @param {*} value Input value.
 * @param {number} fallback Fallback percentage.
 * @return {number} Canvas quality from 0.1 to 0.95.
 */
function clampQuality(value, fallback) {
    const parsed = Number.parseFloat(String(value));
    const percent = Number.isFinite(parsed) ? parsed : fallback;
    return Math.max(0.1, Math.min(0.95, percent / 100));
}


/**
 * Return a readable byte count for upload progress text.
 *
 * @param {*} value Byte count value.
 * @return {string} Human-readable byte count.
 */
function formatFileSize(value) {
    const bytes = Math.max(0, Number(value || 0));
    if (bytes < 1024) {
        return `${Math.round(bytes)} B`;
    }
    const units = ['KB', 'MB', 'GB', 'TB'];
    let number = bytes / 1024;
    let unitIndex = 0;
    while (number >= 1024 && unitIndex < units.length - 1) {
        number /= 1024;
        unitIndex++;
    }
    const digits = number >= 100 || unitIndex === 0 ? 0 : 1;
    return `${number.toFixed(digits)} ${units[unitIndex]}`;
}

/**
 * Build the mutable progress state used by the browser upload UI.
 *
 * @param {File[]} files Selected source files.
 * @return {Record<string, number>} Progress state.
 */
function createBrowserProgressState(files) {
    return {
        totalFiles: files.length,
        totalOriginalBytes: files.reduce((sum, file) => sum + Number(file.size || 0), 0),
        preparedFiles: 0,
        preparedOriginalBytes: 0,
        preparedPackageBytes: 0,
        exifFiles: 0,
        gpsFiles: 0,
        totalBatches: 0,
        totalZipBytes: 0,
        uploadedBatches: 0,
        uploadedFiles: 0,
        uploadedZipBytes: 0,
        currentBatchIndex: 0,
        currentBatchBytes: 0,
        currentBatchUploadedBytes: 0,
    };
}

/**
 * Return a compact progress metrics string for browser-prepared uploads.
 *
 * @param {Record<string, number>} state Progress state.
 * @return {string} Metrics label.
 */
function browserProgressMetrics(state) {
    const parts = [
        `Pictures prepared ${state.preparedFiles}/${state.totalFiles}`,
        `uploaded ${state.uploadedFiles}/${state.totalFiles}`,
        `originals prepared ${formatFileSize(state.preparedOriginalBytes)} / ${formatFileSize(state.totalOriginalBytes)}`,
        `prepared package ${formatFileSize(state.preparedPackageBytes)}`,
        `EXIF ${state.exifFiles}/${state.totalFiles}`,
        `GPS ${state.gpsFiles}/${state.totalFiles}`,
    ];
    if (state.totalBatches > 0) {
        parts.push(`ZIPs ${state.uploadedBatches}/${state.totalBatches}`);
        parts.push(`ZIP data ${formatFileSize(state.uploadedZipBytes)} / ${formatFileSize(state.totalZipBytes)}`);
    }
    if (state.currentBatchBytes > 0) {
        parts.push(`current ZIP ${state.currentBatchIndex}: ${formatFileSize(state.currentBatchUploadedBytes)} / ${formatFileSize(state.currentBatchBytes)}`);
    }
    return parts.join(' | ');
}

/**
 * Append server-reported upload events to the rolling progress log.
 *
 * @param {HTMLElement} progress Progress container.
 * @param {Array<Record<string, *>>} events Server events.
 */
function appendServerUploadEvents(progress, events) {
    if (!Array.isArray(events)) {
        return;
    }
    events.forEach((event) => {
        const message = String(event.message || '').trim();
        if (message === '') {
            return;
        }
        const elapsed = Number(event.elapsed_ms || 0);
        appendUploadProgressLog(progress, elapsed > 0 ? `Server: ${message} (${elapsed} ms)` : `Server: ${message}`);
    });
}

/**
 * Return selected non-PDF photo files from the shared upload picker.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {File[]} Selected photo sources in upload order.
 */
function selectedBrowserUploadFiles(form) {
    const input = form.querySelector('input[type="file"][name="images[]"]');
    if (!(input instanceof HTMLInputElement) || !input.files) {
        return [];
    }
    const files = Array.from(input.files).filter((file) => file instanceof File && !isBrowserUploadPdfFile(file));
    // Only the #118 drawer exposes explicit move controls. Honour their native
    // FileList order without changing the historical folder-order upload pages.
    return form.querySelector('[data-gallery-upload-preview]')
        ? files : files.sort(compareUploadFilesByDefaultFolderOrder);
}

/**
 * Return PDF documents from the shared chooser and dedicated existing-gallery picker.
 * @param {HTMLFormElement} form Existing-gallery upload form.
 * @returns {File[]} PDF originals in shared-queue order followed by dedicated-picker order.
 */
function selectedBrowserUploadPdfFiles(form) {
    const imageInput = form.querySelector('input[type="file"][name="images[]"]');
    const pdfInput = form.querySelector('input[type="file"][name="pdf_files[]"][data-gallery-upload-pdf-input]');
    const fromImageQueue = imageInput instanceof HTMLInputElement && imageInput.files
        ? Array.from(imageInput.files).filter((file) => file instanceof File && isBrowserUploadPdfFile(file))
        : [];
    const fromDedicatedPicker = pdfInput instanceof HTMLInputElement && pdfInput.files
        ? Array.from(pdfInput.files).filter((file) => file instanceof File && isBrowserUploadPdfFile(file))
        : [];
    return [...fromImageQueue, ...fromDedicatedPicker];
}

/**
 * Recognize PDF originals without treating them as image-worker inputs.
 * @param {File} file Original selected source.
 * @returns {boolean} True for a PDF MIME or filename.
 */
function isBrowserUploadPdfFile(file) {
    return String(file?.type || '').toLowerCase() === 'application/pdf' || /\.pdf$/i.test(String(file?.name || ''));
}

/**
 * Attach selected PDFs and pass only an explicitly checked conversion choice to the shared workflow.
 * The ordinary image queue has already completed when a base result is supplied,
 * so append mode places all converted pages after the newly uploaded photos.
 * @param {HTMLFormElement} form Existing-gallery upload form and CSRF source.
 * @param {HTMLElement} progress Existing gallery upload progress surface.
 * @param {File[]} files Selected PDF originals in picker order.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Existing upload operation owning retries.
 * @param {Record<string, *> | null} baseResult Acknowledged photo aggregate or null for PDF-only submissions.
 * @returns {Promise<Record<string, *>>} Canonical aggregate after the shared workflow acknowledges every PDF; clears its separate picker only after success.
 */
async function uploadSelectedGalleryPdfFiles(form, progress, files, operation, baseResult) {
    const helper = await import('./admin-pdf-documents.js?v=20261013-pdf-upload-optin-v1');
    if (typeof helper.uploadGalleryPdfFiles !== 'function') {
        throw new Error(i18n('pdf.upload.helper_unavailable', 'The PDF upload workflow is unavailable. No PDF was attached.'));
    }
    const target = form.querySelector('[name="pdf_target_mode"]');
    const dpi = form.querySelector('[name="pdf_dpi"]');
    const convertPages = form.querySelector('[name="pdf_convert_pages"][data-gallery-upload-pdf-convert]');
    const galleryId = selectedBrowserGalleryId(form);
    const result = await helper.uploadGalleryPdfFiles(form, progress, files, {
        galleryId,
        targetMode: target instanceof HTMLSelectElement ? target.value : 'child',
        dpi: dpi instanceof HTMLSelectElement ? Number(dpi.value || 150) : 150,
        convertPages: convertPages instanceof HTMLInputElement && convertPages.checked === true,
        operation,
        baseResult,
        onProgress: (state) => {
            const pages = Math.max(0, Number(state.pages || 0));
            const page = Math.max(0, Number(state.page || 0));
            const percent = pages ? Math.min(97, Math.max(1, Math.round(page / pages * 95))) : 1;
            updateBasicProgress(progress, percent, i18n('pdf.upload.progress', 'PDF conversion: {phase}, page {page} of {pages}.', {phase: String(state.phase || 'working'), page, pages}));
        },
    });
    const pdfInput = form.querySelector('input[type="file"][name="pdf_files[]"][data-gallery-upload-pdf-input]');
    if (Number(result.pdf_documents_uploaded || 0) > 0 && pdfInput instanceof HTMLInputElement) pdfInput.value = '';
    return result;
}

/** Return whether the current selection contains a user ZIP archive. */
export function browserUploadZipSelected(form) {
    return selectedBrowserUploadFiles(form).some(isBrowserUploadZipFile);
}

/** Return whether one selected file is a ZIP archive intended for browser import. */
function isBrowserUploadZipFile(file) {
    const type = String(file?.type || '').toLowerCase();
    return file instanceof File && (/\.zip$/i.test(file.name) || ['application/zip', 'application/x-zip-compressed'].includes(type));
}

/**
 * Replace selected ZIPs with their supported image entries before preparation.
 *
 * @param {File[]} selectedFiles Original browser selection.
 * @param {{uploadLimitBytes:number,signal?:AbortSignal|null}} config Effective per-file limit and optional cancellation.
 * @param {HTMLElement} progress Progress container.
 * @param {boolean} preserveOrder Honour explicit drawer order, including ZIP positions.
 * @return {Promise<{files:File[],skipped:number}>} Ordered bounded originals; oversized selections fail before server mutation.
 */
async function expandBrowserUploadArchives(selectedFiles, config, progress, preserveOrder = false) {
    const files = [];
    let skipped = 0;
    let retainedBytes = selectedFiles.filter(file => !isBrowserUploadZipFile(file)).reduce((sum, file) => sum + file.size, 0);
    if (selectedFiles.some(isBrowserUploadZipFile) && retainedBytes > maximumArchiveSelectionBytes) {
        throw new Error(i18n('admin.icloud.zip_retention'));
    }
    for (const selectedFile of selectedFiles) {
        throwIfUploadStreamAborted(config.signal || null);
        if (!isBrowserUploadZipFile(selectedFile)) {
            files.push(selectedFile);
            continue;
        }
        appendUploadProgressLog(progress, i18n('admin.browser_upload.log_extracting_zip', 'Inspecting ZIP: {name} ({bytes}).', {name: selectedFile.name, bytes: formatFileSize(selectedFile.size)}));
        const result = await extractBrowserUploadZip(selectedFile, {...config, remainingArchiveBytes: maximumArchiveSelectionBytes - retainedBytes});
        skipped += Number(result.skipped || 0);
        const archiveFiles = (result.entries || []).map((entry) => new File([entry.blob], String(entry.name || 'archive-image'), {
            type: browserUploadMimeFromFilename(String(entry.name || '')),
            lastModified: selectedFile.lastModified,
        }));
        files.push(...archiveFiles);
        retainedBytes += archiveFiles.reduce((sum, file) => sum + file.size, 0);
        appendUploadProgressLog(progress, i18n('admin.browser_upload.log_extracted_zip', 'ZIP {name}: added {added} supported image(s), skipped {skipped} other entry or entries.', {name: selectedFile.name, added: archiveFiles.length, skipped: Number(result.skipped || 0)}));
        for (const [reason, count] of Object.entries(result.reasons || {})) {
            appendUploadProgressLog(progress, i18n('admin.icloud.skipped', 'Skipped {count}: {reason}.', {count, reason: i18n('admin.icloud.skip_' + reason)}));
        }
    }
    return {files: preserveOrder ? files : files.sort(compareUploadFilesByDefaultFolderOrder), skipped};
}

/**
 * Extract one selected ZIP in a cancellable worker with a shared retention budget.
 * @param {File} zipFile Disk-backed user selection, never a full archive buffer.
 * @param {{uploadLimitBytes:number,remainingArchiveBytes:number,signal?:AbortSignal|null}} config Effective per-file/selection limits and cancellation.
 * @returns {Promise<{entries:Array<{name:string,path:string,blob:Blob}>,skipped:number,totalEntries:number,reasons:Record<string,number>}>} Bounded checked images and categorized skipped entries.
 */
function extractBrowserUploadZip(zipFile, config) {
    return new Promise((resolve, reject) => {
        const worker = new Worker(workerScriptUrl);
        /** Release the archive worker after its single extraction request. */
        const finish = () => {
            worker.terminate();
            config.signal?.removeEventListener('abort', abort);
        };
        const abort = () => {
            finish();
            reject(new Error(i18n('admin.icloud.cancelled')));
        };
        if (config.signal?.aborted) { abort(); return; }
        config.signal?.addEventListener('abort', abort, {once: true});
        worker.addEventListener('message', (event) => {
            const data = event.data || {};
            finish();
            if (!data.ok) {
                const code = ['zip64', 'entries', 'directory', 'size', 'retention', 'deflate'].includes(data.code) ? data.code : 'invalid';
                reject(new Error(i18n('admin.icloud.zip_' + code)));
                return;
            }
            resolve(data.result || {entries: [], skipped: 0, totalEntries: 0});
        }, {once: true});
        worker.addEventListener('error', (event) => {
            finish();
            reject(new Error(event.message || i18n('admin.browser_upload.zip_extract_failed', 'The selected ZIP archive could not be read.')));
        }, {once: true});
        worker.postMessage({
            action: 'extractUploadZip',
            id: 1,
            zipBlob: zipFile,
            limits: {
                maximumEntries: 10000,
                maximumEntryBytes: Number(config.uploadLimitBytes || 8 * 1024 * 1024),
                maximumRetainedBytes: config.remainingArchiveBytes,
                maximumTotalBytes: Math.min(2 * 1024 * 1024 * 1024, Math.max(Number(config.uploadLimitBytes || 1), Number(config.uploadLimitBytes || 1) * 1000)),
            },
        });
    });
}

/**
 * Return the existing MIME hint for an extracted supported image name.
 * @param {string} filename Checked image filename.
 * @returns {string} JPEG/PNG/WebP/GIF MIME, or the binary fallback.
 */
function browserUploadMimeFromFilename(filename) {
    const extension = String(filename || '').split('.').pop()?.toLowerCase() || '';
    return {jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif'}[extension] || 'application/octet-stream';
}

/**
 * Compare selected files by the default folder order used by the upload pipeline.
 *
 * Browser FileList ordering can vary by browser, operating system, and selection
 * method. The browser upload path runs files through workers, so the source
 * order must be assigned before threaded preparation starts. Folder-relative
 * paths are preferred when a browser provides them; otherwise filenames are
 * compared with natural numeric ordering.
 *
 * @param {File} left Left selected file.
 * @param {File} right Right selected file.
 * @return {number} Sort comparison result.
 */
function compareUploadFilesByDefaultFolderOrder(left, right) {
    const leftKey = uploadFileOrderKey(left);
    const rightKey = uploadFileOrderKey(right);
    const comparison = uploadFileNameCollator().compare(leftKey, rightKey);
    if (comparison !== 0) {
        return comparison;
    }
    return uploadFileNameCollator().compare(String(left.name || ''), String(right.name || ''));
}

/**
 * Return the stable path/name key used for default upload ordering.
 *
 * @param {File} file Selected browser file.
 * @return {string} Folder-relative path when available, otherwise filename.
 */
function uploadFileOrderKey(file) {
    const relativePath = typeof file.webkitRelativePath === 'string' ? file.webkitRelativePath : '';
    const key = relativePath.trim() !== '' ? relativePath : String(file.name || '');
    return key.replace(/\\/g, '/');
}

/**
 * Return a cached natural filename collator.
 *
 * @return {Intl.Collator} Collator used for source file ordering.
 */
function uploadFileNameCollator() {
    if (!uploadFileNameCollator.instance) {
        uploadFileNameCollator.instance = new Intl.Collator(undefined, {
            numeric: true,
            sensitivity: 'base',
        });
    }
    return uploadFileNameCollator.instance;
}

/**
 * Verify whether the browser and selected files can use the browser path.
 *
 * @param {Record<string, unknown>} config Browser capability settings, including endpoint, supported MIME types and upload byte limit.
 * @param {File[]} files Selected files.
 * @return {{ok: boolean, reason: string} } Capability result.
 */
function browserUploadCapability(config, files) {
    if (!config.endpoint || !window.Worker || !window.Blob || !window.File || !window.FormData || !window.TextEncoder || !window.crypto) {
        return {ok: false, reason: 'missing_core_browser_api'};
    }
    if (!window.indexedDB) {
        return {ok: false, reason: 'missing_indexeddb'};
    }
    if (!('OffscreenCanvas' in window) || !('createImageBitmap' in window)) {
        return {ok: false, reason: 'missing_canvas_worker_api'};
    }
    const supportedTypes = new Set(config.supportedMimeTypes || []);
    for (const file of files) {
        if (isBrowserUploadZipFile(file)) continue;
        const type = String(file.type || '').toLowerCase();
        const extension = file.name.split('.').pop()?.toLowerCase() || '';
        const extensionAllowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(extension);
        if (!supportedTypes.has(type) && !extensionAllowed) {
            return {ok: false, reason: 'unsupported_file_type'};
        }
        if (file.size > Number(config.uploadLimitBytes || 0)) {
            return {ok: false, reason: 'single_file_larger_than_upload_limit'};
        }
    }
    return {ok: true, reason: 'ok'};
}

/**
 * Generate a per-run upload session id for server idempotency.
 *
 * @return {string} Session id.
 */
function browserUploadSessionId() {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Describe the original and derivative files returned by the image worker.
 * @typedef {Object} PreparedWorkerImage
 * @property {number} sourceIndex Zero-based worker item identity.
 * @property {string} originalName Selected original filename.
 * @property {string} preparedName Safe output filename.
 * @property {string} originalPath Unique ZIP path of the original.
 * @property {number} originalWidth Decoded width in pixels.
 * @property {number} originalHeight Decoded height in pixels.
 * @property {number} originalDisplayWidth Display width in pixels after decoding orientation.
 * @property {number} originalDisplayHeight Display height in pixels after decoding orientation.
 * @property {number} originalExifOrientation Original EXIF orientation, defaulting to one.
 * @property {string} originalMime Selected MIME type or filename-derived fallback.
 * @property {string} originalDetectedFormat Byte-detected original format.
 * @property {number} originalSize Original byte length.
 * @property {File} originalFile Unmodified selected file reference.
 * @property {{exif_taken_at:string|null,exif_camera_make:string|null,exif_camera_model:string|null,exif_lens_model:string|null,exif_focal_length:string|null,exif_aperture:string|null,exif_exposure_time:string|null,exif_iso:number|null,gps_lat:number|null,gps_lng:number|null,gps_altitude:number|null,exif_orientation:number}|null} clientExif Compact JPEG metadata, or null for unsupported or unreadable metadata.
 * @property {Array<{size:number,format:string,width:number,height:number,path:string,blob:Blob}>} variants Encoded thumbnail variants with pixel dimensions and unique ZIP paths.
 */

/**
 * Persist a prepared worker result before resolving the pool.
 * @callback PreparedWorkerImageCallback
 * @param {PreparedWorkerImage} item Original file, decoded metadata and thumbnail variants.
 * @param {number} completed Number of source files decoded so far.
 * @param {number} total Number of selected source files.
 * @returns {Promise<void>} Resolve after the caller has stored the result.
 */

/**
 * Run files through a bounded worker pool.
 *
 * @param {File[]} files Selected files.
 * @param {{workerCount?:number,workerItemIdOffset?:number,thumbnailSizes:number[],thumbnailFormats:string[],jpegQuality:number,webpQuality:number,signal?:AbortSignal|null}} config Decoder settings and cancellation; the item offset keeps streamed PDF thumbnail paths unique.
 * @param {PreparedWorkerImageCallback} onItem Persist each original and derivative result with decoded-file counts.
 * @return {Promise<void>} Result value for the caller.
 */
function processFilesWithWorkerPool(files, config, onItem) {
    const total = files.length;
    const workerCount = Math.max(1, Math.min(Number(config.workerCount || 1), total));
    let nextIndex = 0;
    let completed = 0;
    let rejected = false;
    const workers = [];
    const pendingItemWrites = new Set();

    return new Promise((resolve, reject) => {
        /**
         * Stop workers.
         *
         * Used by browser-side gallery behavior.
         */
        const stopWorkers = () => {
            workers.forEach((worker) => worker.terminate());
            config.signal?.removeEventListener('abort', abort);
        };
        /**
         * Handle reject once.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {*} error Error value.
         */
        const rejectOnce = (error) => {
            if (rejected) {
                return;
            }
            rejected = true;
            stopWorkers();
            // Let admitted IndexedDB writes settle before abort cleanup deletes them.
            Promise.allSettled([...pendingItemWrites]).then(() => reject(error instanceof Error ? error : new Error(String(error))));
        };
        const abort = () => rejectOnce(new Error(i18n('admin.icloud.cancelled')));
        if (config.signal?.aborted) { abort(); return; }
        config.signal?.addEventListener('abort', abort, {once: true});
        /**
         * Resolve after every worker result has also reached temporary storage.
         *
         * Used by browser-side gallery behavior.
         */
        const resolveWhenFinished = () => {
            if (!rejected && completed >= total && pendingItemWrites.size === 0) {
                stopWorkers();
                resolve();
            }
        };
        /**
         * Persist one worker result without keeping the worker idle.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {Record<string, *>} item Prepared worker item.
         * @param {number} completedCount Number of images decoded by workers.
         */
        const persistPreparedItem = (item, completedCount) => {
            const writePromise = Promise.resolve()
                .then(() => onItem(item, completedCount, total))
                .catch((error) => {
                    rejectOnce(error);
                })
                .finally(() => {
                    pendingItemWrites.delete(writePromise);
                    resolveWhenFinished();
                });
            pendingItemWrites.add(writePromise);
        };
        /**
         * Handle assign.
         *
         * @param {*} worker Worker value.
         */
        const assign = (worker) => {
            if (rejected) {
                return;
            }
            if (nextIndex >= total) {
                resolveWhenFinished();
                return;
            }
            const index = nextIndex;
            nextIndex++;
            worker.postMessage({
                id: Math.max(0, Number(config.workerItemIdOffset || 0)) + index,
                file: files[index],
                sizes: config.thumbnailSizes,
                formats: config.thumbnailFormats,
                jpegQuality: config.jpegQuality,
                webpQuality: config.webpQuality,
            });
        };

        for (let workerIndex = 0; workerIndex < workerCount; workerIndex++) {
            let worker;
            try {
                worker = new Worker(workerScriptUrl);
            } catch (error) {
                rejectOnce(error);
                return;
            }
            workers.push(worker);
            worker.addEventListener('message', (event) => {
                if (rejected) return;
                const data = event.data || {};
                if (!data.ok) {
                    rejectOnce(new Error(data.error || i18n('admin.browser_upload.worker_failed', 'Browser image preparation worker failed.')));
                    return;
                }
                completed++;
                persistPreparedItem(data.item, completed);
                assign(worker);
            });
            worker.addEventListener('error', (event) => {
                rejectOnce(new Error(event.message || i18n('admin.browser_upload.worker_failed', 'Browser image preparation worker failed.')));
            });
            assign(worker);
        }
    });
}

/**
 * Create an object that packages prepared items into ZIP batches.
 *
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} uploadSessionId Upload session id.
 * @param {Record<string, Function>} hooks Optional progress hooks.
 * @return {Promise<Record<string, Function>>} Batcher object.
 */
async function createBrowserBatcher(config, uploadSessionId, hooks = {}) {
    const tempStore = await openPreparedBatchStore();
    const targetBytes = Number(config.batchTargetBytes || 1);
    // The configured batch size is a packing target, not an absolute rejection limit.
    // A single source image plus its prepared thumbnails cannot be split safely across
    // requests, so allow that one-image batch to grow up to the detected PHP upload
    // ceiling. Multi-image batches still split at targetBytes below.
    const maxBytes = Math.max(targetBytes, Number(config.uploadLimitBytes || targetBytes));
    const maxItemsPerBatch = Math.max(1, Math.min(Number(config.maxItemsPerBatch || 8), 64));
    let nextBatchIndex = Math.max(0, Math.floor(Number(config.startBatchIndex || 0)));
    const pendingItems = [];
    const batches = [];
    let preparedItemCount = 0;
    let pendingBytes = 0;

    /**
     * Return the IndexedDB key for one prepared image package.
     *
     * @param {number} sourceIndex Source file index.
     * @return {string} Temporary store key.
     */
    const preparedItemId = (sourceIndex) => `${uploadSessionId}-prepared-${String(sourceIndex).padStart(6, '0')}`;

    /**
     * Delete every temporary item and batch owned by this upload session.
     *
     * @param {boolean} includeBatches Whether ZIP batches should also be removed.
     * @return {Promise<void>} Cleanup promise.
     */
    const cleanupTemporaryFiles = async (includeBatches) => {
        const keys = pendingItems.map((item) => item.id);
        if (includeBatches) {
            keys.push(...batches.map((batch) => batch.id));
        }
        await Promise.allSettled(keys.map((key) => tempStore.delete(key)));
    };

    /**
     * Create and persist one ZIP batch from prepared item references.
     *
     * @param {Array<Record<string, *>>} current Prepared item references.
     */
    const finalizeBatch = async (current) => {
        if (!current.length) {
            return;
        }
        let batchPersisted = false;
        const currentItems = [];
        const currentEntries = [];
        const currentBytes = current.reduce((sum, reference) => sum + Number(reference.itemBytes || 0), 0);
        try {
            if (typeof hooks.onBatchPackaging === 'function') {
                hooks.onBatchPackaging(nextBatchIndex, current.length, currentBytes);
            }
            for (const reference of current) {
                const item = await tempStore.get(reference.id);
                if (!item || typeof item !== 'object') {
                    throw new Error(i18n('admin.browser_upload.prepared_item_missing', 'A prepared image package is missing from browser temporary storage.'));
                }
                currentItems.push(manifestItemForPreparedItem(item));
                currentEntries.push(...entriesForPreparedItem(item));
            }
            const manifest = {
                version: 2,
                upload_session_id: uploadSessionId,
                batch_index: nextBatchIndex,
                total_prepared_items: Math.max(preparedItemCount, Number(config.expectedItemCount || 0)),
                items: currentItems,
            };
            const zipBlob = await createStoreOnlyZipInWorker([
                {path: 'manifest.json', blob: new Blob([JSON.stringify(manifest)], {type: 'application/json'})},
                ...currentEntries,
            ]);
            if (zipBlob.size > maxBytes) {
                throw new Error(i18n('admin.browser_upload.batch_too_large', 'A prepared ZIP batch is larger than the server upload limit. Use the standard server-side fallback for these files.'));
            }
            const tempId = `${uploadSessionId}-batch-${nextBatchIndex}`;
            await tempStore.put(tempId, zipBlob);
            const batch = {
                id: tempId,
                index: nextBatchIndex,
                blob: zipBlob,
                itemCount: current.length,
                firstSourceIndex: Math.min(...current.map((reference) => Number(reference.sourceIndex))),
                lastSourceIndex: Math.max(...current.map((reference) => Number(reference.sourceIndex))),
                originalBytes: current.reduce((sum, reference) => sum + Number(reference.originalBytes || 0), 0),
                preparedBytes: currentBytes,
                cleanup: () => tempStore.delete(tempId),
            };
            batches.push(batch);
            batchPersisted = true;
            await Promise.allSettled(current.map((reference) => tempStore.delete(reference.id)));
            nextBatchIndex++;
            if (typeof hooks.onBatchReady === 'function') {
                await hooks.onBatchReady(batch);
            }
        } catch (error) {
            // A persisted ZIP owns the items after an upload callback fails; restore
            // references only when packaging failed before a durable batch existed.
            if (!batchPersisted) {
                pendingItems.unshift(...current);
                pendingBytes += currentBytes;
            }
            throw error;
        }
    };

    return {
        batches,
        addItem: async (item) => {
            const sourceIndex = Number.isInteger(Number(item.sourceIndex)) ? Number(item.sourceIndex) : preparedItemCount;
            const itemBytes = preparedItemByteSize(item);
            if (itemBytes > maxBytes) {
                throw new Error(i18n('admin.browser_upload.item_too_large', 'One prepared image package is larger than the server upload limit. Use the standard server-side fallback for that image.'));
            }
            const id = preparedItemId(sourceIndex);
            await tempStore.put(id, {...item, sourceIndex, itemBytes});
            const originalBytes = Number(item.originalSize || 0);
            const reference = {id, sourceIndex, itemBytes, originalBytes};
            preparedItemCount++;
            pendingItems.push(reference);
            pendingBytes += itemBytes;
            return {
                id,
                sourceIndex,
                itemBytes,
                originalBytes,
                flushRequired: pendingBytes >= targetBytes || pendingItems.length >= maxItemsPerBatch,
            };
        },
        shouldFlushBefore: (item) => {
            const itemBytes = preparedItemByteSize(item);
            return pendingItems.length > 0 && (pendingBytes + itemBytes > targetBytes || pendingItems.length >= maxItemsPerBatch);
        },
        flush: async () => {
            if (!pendingItems.length) return;
            const current = pendingItems.splice(0, pendingItems.length);
            pendingBytes = 0;
            await finalizeBatch(current);
        },
        finish: async () => {
            pendingItems.sort((left, right) => Number(left.sourceIndex || 0) - Number(right.sourceIndex || 0));
            const remainingItems = pendingItems.splice(0, pendingItems.length);
            pendingBytes = 0;
            let current = [];
            let currentBytes = 0;
            let nextReferenceIndex = 0;
            try {
                for (; nextReferenceIndex < remainingItems.length; nextReferenceIndex++) {
                    const reference = remainingItems[nextReferenceIndex];
                    const itemBytes = Number(reference.itemBytes || 0);
                    // Keep ordinary batches near the configured target. If one prepared
                    // image is itself larger than that target, finalize the previous batch
                    // first and let the oversized image stand alone. finalizeBatch() still
                    // enforces the real PHP upload ceiling through maxBytes.
                    if (current.length > 0 && (currentBytes + itemBytes > targetBytes || current.length >= maxItemsPerBatch)) {
                        await finalizeBatch(current);
                        current = [];
                        currentBytes = 0;
                    }
                    current.push(reference);
                    currentBytes += itemBytes;
                }
                await finalizeBatch(current);
            } catch (error) {
                pendingItems.push(...remainingItems.slice(nextReferenceIndex));
                pendingBytes += remainingItems.slice(nextReferenceIndex).reduce((sum, reference) => sum + Number(reference.itemBytes || 0), 0);
                throw error;
            }
            return batches;
        },
        cleanupPrepared: async () => {
            await cleanupTemporaryFiles(false);
        },
        abort: async () => {
            await cleanupTemporaryFiles(true);
        },
    };
}

/**
 * Return ZIP entries for one prepared item.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {Array<{path: string, blob: Blob} >} ZIP entries.
 */
function entriesForPreparedItem(item) {
    const entries = [{path: item.originalPath, blob: item.originalFile}];
    item.variants.forEach((variant) => {
        entries.push({path: variant.path, blob: variant.blob});
    });
    return entries;
}

/**
 * Estimate the real ZIP contribution of one prepared image package.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {number} Estimated store-only ZIP bytes.
 */
function preparedItemByteSize(item) {
    return entriesForPreparedItem(item).reduce((sum, entry) => sum + Number(entry.blob.size || 0) + 256 + entry.path.length, 0) + 1024;
}

/**
 * Return the manifest-safe shape for one prepared item.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {Record<string, *>} Manifest item.
 */
function manifestItemForPreparedItem(item) {
    return {
        source_index: Number(item.sourceIndex || 0),
        original_name: item.originalName,
        prepared_name: item.preparedName,
        original_path: item.originalPath,
        original_width: Number(item.originalWidth || 0),
        original_height: Number(item.originalHeight || 0),
        original_display_width: Number(item.originalDisplayWidth || item.originalWidth || 0),
        original_display_height: Number(item.originalDisplayHeight || item.originalHeight || 0),
        original_exif_orientation: Number(item.originalExifOrientation || item.clientExif?.exif_orientation || 1),
        original_mime: String(item.originalMime || ''),
        original_detected_format: String(item.originalDetectedFormat || ''),
        original_size: Number(item.originalSize || 0),
        client_exif: item.clientExif && typeof item.clientExif === 'object' ? item.clientExif : null,
        variants: item.variants.map((variant) => ({
            size: variant.size,
            format: variant.format,
            path: variant.path,
            width: variant.width,
            height: variant.height,
        })),
    };
}

/**
 * Return whether client-side EXIF metadata contains GPS coordinates.
 *
 * @param {Record<string, *>} metadata Client EXIF metadata.
 * @return {boolean} True when latitude and longitude are present.
 */
function clientExifHasGps(metadata) {
    return Number.isFinite(Number(metadata?.gps_lat)) && Number.isFinite(Number(metadata?.gps_lng));
}

/**
 * Open the IndexedDB store used as the temporary upload working area.
 *
 * @return {Promise<{put: Function, get: Function, delete: Function} >} Store wrapper.
 */
function openPreparedBatchStore() {
    return new Promise((resolve, reject) => {
        const request = window.indexedDB.open(tempDatabaseName, 1);
        request.addEventListener('upgradeneeded', () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(tempStoreName)) {
                database.createObjectStore(tempStoreName);
            }
        });
        request.addEventListener('error', () => reject(request.error || new Error('IndexedDB open failed.')));
        request.addEventListener('success', () => {
            const database = request.result;
            resolve({
                put: (id, value) => indexedDbRequest(database, 'readwrite', (store) => store.put(value, id)),
                get: (id) => indexedDbRequest(database, 'readonly', (store) => store.get(id)),
                delete: (id) => indexedDbRequest(database, 'readwrite', (store) => store.delete(id)),
            });
        });
    });
}

/**
 * Describe an IndexedDB operation on one prepared-image/batch store.
 * @typedef {(store: IDBObjectStore) => IDBRequest<Blob|PreparedWorkerImage|IDBValidKey|undefined>} IndexedDbOperation
 */
/**
 * Run one IndexedDB object store request and wait for its transaction to commit.
 *
 * @param {IDBDatabase} database Database connection.
 * @param {IDBTransactionMode} mode Transaction mode.
 * @param {IndexedDbOperation} operation Prepared-image read/write/delete request factory.
 * @return {Promise<Blob|PreparedWorkerImage|IDBValidKey|undefined>} Stored image/batch, write key or absent/delete result, only after transaction commit.
 */
function indexedDbRequest(database, mode, operation) {
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(tempStoreName, mode);
        const store = transaction.objectStore(tempStoreName);
        const request = operation(store);
        transaction.addEventListener('complete', () => resolve(request.result));
        transaction.addEventListener('abort', () => reject(transaction.error || new Error(i18n('admin.icloud.storage_failed'))));
        transaction.addEventListener('error', () => reject(transaction.error || new Error(i18n('admin.icloud.storage_failed'))));
        request.addEventListener('error', () => reject(request.error || new Error('IndexedDB request failed.')));
    });
}

/**
 * Send the create-gallery form without files before browser batch upload.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Original create intent; zero is its stable bootstrap request key.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function createGalleryForBrowserUpload(form, operation) {
    const body = new FormData(form);
    body.delete('images[]');
    body.set('ajax', '1');
    adminOperationBody(operation, 0, body);
    const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    const result = await readJsonResponseSafely(response, i18n('admin.browser_upload.gallery_create_failed', 'Gallery creation failed.'));
    if (!response.ok || result.ok !== true || !result.mutation || !Array.isArray(result.contexts)) {
        throw new Error(result.error || i18n('admin.browser_upload.gallery_create_failed', 'Gallery creation failed.'));
    }
    return result;
}

/**
 * Upload one prepared batch with bounded retries.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {Record<string, *>} config Browser configuration.
 * @param {number} galleryId Target gallery id.
 * @param {string} uploadSessionId Upload session id.
 * @param {{id: string, index: number, blob: Blob, itemCount: number}} batch Prepared ZIP batch.
 * @param {number} batchIndex Batch index.
 * @param {number} totalBatches Total batches.
 * @param {boolean} preparedThumbnailsRequired Whether every configured thumbnail must be browser-prepared.
 * @param {PdfUploadXhrProgressHandler} [progressHandler=()=>{}] Browser upload progress handler.
 * @param {{endpoint?: string, conversionToken?: string, sourceGalleryId?: number, signal?: AbortSignal|null}} [options={}] Optional conversion endpoint, token, source gallery and cancellation metadata.
 * @returns {Promise<Record<string, unknown>>} Server JSON acknowledgment.
 */
async function uploadPreparedBatchWithRetry(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, preparedThumbnailsRequired, progressHandler = () => {}, options = {}) {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt++) {
        throwIfUploadStreamAborted(options.signal || null);
        try {
            return await uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, preparedThumbnailsRequired, progressHandler, options);
        } catch (error) {
            lastError = error;
            if (options.signal?.aborted) throw error;
            if (!isRetryablePreparedUploadError(error)) {
                throw error;
            }
            if (attempt < 3) {
                await delay(750 * attempt, options.signal || null);
            }
        }
    }
    throw lastError || new Error(i18n('admin.browser_upload.batch_failed', 'Prepared batch upload failed.'));
}

/**
 * Upload one prepared batch once.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {Record<string, unknown>} config Browser configuration.
 * @param {number} galleryId Target gallery id.
 * @param {string} uploadSessionId Upload session id.
 * @param {{id: string, index: number, blob: Blob, itemCount: number}} batch Prepared ZIP batch.
 * @param {number} batchIndex Batch index.
 * @param {number} totalBatches Total batches.
 * @param {boolean} preparedThumbnailsRequired Whether every configured thumbnail must be browser-prepared.
 * @param {PdfUploadXhrProgressHandler} [progressHandler=()=>{}] Browser upload progress handler.
 * @param {{endpoint?: string, conversionToken?: string, sourceGalleryId?: number, signal?: AbortSignal|null}} [options={}] Optional conversion endpoint, token, source gallery and cancellation metadata.
 * @returns {Promise<Record<string, unknown>>} Server JSON acknowledgment.
 */
function uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, preparedThumbnailsRequired, progressHandler = () => {}, options = {}) {
    const body = new FormData();
    body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
    body.set('ajax', '1');
    body.set('gallery_id', String(galleryId));
    body.set('upload_session_id', uploadSessionId);
    body.set('batch_index', String(batchIndex));
    body.set('total_batches', String(totalBatches));
    body.set('prepared_thumbnails_required', preparedThumbnailsRequired ? '1' : '0');
    if (options.conversionToken) body.set('pdf_conversion_token', String(options.conversionToken));
    if (Number(options.sourceGalleryId || 0) > 0) body.set('pdf_source_gallery_id', String(Number(options.sourceGalleryId)));
    const sourceUrl = form.querySelector('input[name="source_url"]')?.value || '';
    if (sourceUrl) {
        body.set('source_url', sourceUrl);
    }
    body.append('zip_batch', batch.blob, `browser-upload-${uploadSessionId}-${batch.index}.zip`);
    return new Promise(/** Send one prepared batch and preserve its existing retry classification. @param {function(Record<string, unknown>): void} resolve Accept the canonical server acknowledgment. @param {function(Error): void} reject Report transport, parser or server refusal. @return {void} Installs request-local handlers and starts this XHR only. */ (resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', String(options.endpoint || config.endpoint));
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', progressHandler);
        const abortRequest = () => xhr.abort();
        if (options.signal) {
            if (options.signal.aborted) {
                reject(new Error(i18n('admin.icloud.cancelled')));
                return;
            }
            options.signal.addEventListener('abort', abortRequest, {once: true});
            xhr.addEventListener('loadend', () => options.signal.removeEventListener('abort', abortRequest), {once: true});
        }
        xhr.addEventListener('load', /** Validate one prepared acknowledgment before allowing cleanup or key rotation. @return {Promise<void>} Resolves the request promise or rejects with the existing retry classification. */ async () => {
            try {
                const response = new Response(xhr.responseText || '', {
                    status: xhr.status,
                    headers: {'Content-Type': xhr.getResponseHeader('Content-Type') || ''},
                });
                const result = await readJsonResponseSafely(response, i18n('admin.browser_upload.batch_failed', 'Prepared batch upload failed.'));
                if (xhr.status < 200 || xhr.status >= 300 || result.ok !== true || !result.mutation || typeof result.mutation !== 'object' || !Array.isArray(result.contexts)) {
                    const message = browserUploadServerErrorMessage(result, i18n('admin.browser_upload.batch_failed', 'Prepared batch upload failed.'));
                    const failure = new Error(message);
                    failure.httpStatus = xhr.status;
                    failure.retryable = result.retryable !== false && browserUploadStatusIsRetryable(xhr.status);
                    failure.serverContext = result.error_context || null;
                    throw failure;
                }
                resolve(result);
            } catch (error) {
                reject(error);
            }
        });
        xhr.addEventListener('error', () => {
            const failure = new Error(i18n('admin.browser_upload.batch_failed', 'Prepared batch upload failed.'));
            failure.retryable = true;
            reject(failure);
        });
        xhr.addEventListener('abort', () => {
            const failure = new Error(i18n('admin.icloud.cancelled'));
            failure.retryable = false;
            reject(failure);
        }, {once: true});
        xhr.send(body);
    });
}

/**
 * Return whether a prepared upload failure should be retried.
 *
 * @param {*} error Error value.
 * @return {boolean} True when retry is useful.
 */
function isRetryablePreparedUploadError(error) {
    if (error && typeof error === 'object' && error.retryable === false) {
        return false;
    }
    const status = Number(error?.httpStatus || 0);
    if (status > 0) {
        return browserUploadStatusIsRetryable(status);
    }
    return true;
}

/**
 * Return whether an HTTP status usually represents a transient upload failure.
 *
 * @param {number} status HTTP status.
 * @return {boolean} True when retry is useful.
 */
function browserUploadStatusIsRetryable(status) {
    return status === 0 || status === 408 || status === 429 || status >= 500;
}

/**
 * Build a readable server error message with compact validation context.
 *
 * @param {Record<string, *>} result Server JSON response.
 * @param {string} fallbackMessage Fallback message.
 * @return {string} Error message.
 */
function browserUploadServerErrorMessage(result, fallbackMessage) {
    const base = String(result?.error || fallbackMessage);
    const details = browserUploadContextSummary(result?.error_context || null);
    return details ? `${base} Details: ${details}` : base;
}

/**
 * Return a compact validation context summary for browser-visible failures.
 *
 * @param {*} context Validation context.
 * @return {string} Summary text.
 */
function browserUploadContextSummary(context) {
    if (!context || typeof context !== 'object') {
        return '';
    }
    const keys = [
        'validation_stage',
        'original_name',
        'prepared_name',
        'original_path',
        'extension',
        'detected_format',
        'payload_bytes',
        'signature_hex',
    ];
    return keys
        .filter((key) => context[key] !== undefined && context[key] !== null && String(context[key]) !== '')
        .map((key) => `${key}=${String(context[key])}`)
        .join(', ');
}

/**
 * Read a JSON response while reporting common HTML or PHP warning failures.
 *
 * @param {Response} response Fetch response.
 * @param {string} fallbackMessage Fallback error message.
 * @return {Promise<Record<string, *>>} Parsed JSON.
 */
async function readJsonResponseSafely(response, fallbackMessage) {
    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    const responseText = await response.text();
    try {
        return JSON.parse(responseText || '{}');
    } catch (error) {
        const snippet = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
        if (!contentType.includes('application/json') && snippet.includes('Maximum number of allowable file uploads exceeded')) {
            throw new Error(i18n('admin.browser_upload.php_upload_limit', 'The server refused the prepared ZIP because a PHP upload limit was reached.'));
        }
        if (snippet.startsWith('<')) {
            const statusText = response.status ? `HTTP ${response.status}` : 'HTTP error';
            throw new Error(`${fallbackMessage} ${i18n('admin.browser_upload.html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')} ${statusText}: ${snippet.slice(0, 120)}`);
        }
        throw new Error(snippet || fallbackMessage);
    }
}

/**
 * Return selected gallery id from select or hidden field.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {number} Gallery id or zero.
 */
function selectedBrowserGalleryId(form) {
    const select = form.querySelector('select[name="gallery_id"]:enabled');
    if (select instanceof HTMLSelectElement) {
        return Number(select.value || 0);
    }
    const hidden = form.querySelector('input[type="hidden"][name="gallery_id"]:enabled');
    if (hidden instanceof HTMLInputElement) {
        return Number(hidden.value || 0);
    }
    return 0;
}

/**
 * Create an empty aggregate response.
 *
 * @param {Record<string, *> | null} seed Initial create-gallery response.
 * @param {number} galleryId Gallery id.
 * @param {number} totalBatches Total batches.
 * @return {Record<string, *>} Aggregate response.
 */
function emptyBrowserAggregate(seed, galleryId, totalBatches) {
    return {
        ok: true,
        gallery_id: galleryId,
        gallery_ids: galleryId ? [galleryId] : [],
        gallery_title: String(seed?.gallery_title || ''),
        gallery_url: String(seed?.gallery_url || ''),
        edit_url: String(seed?.edit_url || ''),
        parent_gallery_id: Number(seed?.parent_gallery_id || 0),
        parent_gallery_url: String(seed?.parent_gallery_url || ''),
        refresh_gallery_id: Number(seed?.refresh_gallery_id || galleryId || 0),
        refresh_url: String(seed?.refresh_url || ''),
        created_gallery: Boolean(seed?.created_gallery),
        mutation: seed?.mutation && typeof seed.mutation === 'object' ? {...seed.mutation} : null,
        panel: seed?.panel && typeof seed.panel === 'object' ? {...seed.panel} : null,
        contexts: Array.isArray(seed?.contexts) ? seed.contexts.map((context) => ({...context})) : [],
        fallback: seed?.fallback && typeof seed.fallback === 'object' ? {...seed.fallback} : {},
        image_ids: Array.isArray(seed?.image_ids) ? seed.image_ids.map(Number).filter(Boolean) : [],
        upload_events: Array.isArray(seed?.upload_events) ? seed.upload_events.slice() : [],
        uploaded: 0,
        scanned: 0,
        thumbnails: 0,
        thumbnail_skipped: 0,
        thumbnail_failed: 0,
        thumbnail_errors: [],
        scan_failed: 0,
        scan_failed_filenames: [],
        renamed: 0,
        rename_warnings: [],
        rename_failures: [],
        total_batches: totalBatches,
        total_files: 0,
        redirect_url: String(seed?.redirect_url || ''),
    };
}

/**
 * Merge one batch response into the aggregate response.
 *
 * @param {Record<string, *>} aggregate Aggregate response.
 * @param {Record<string, *>} result Batch response.
 */
function mergeBrowserResult(aggregate, result) {
    aggregate.gallery_id = Number(result.gallery_id || aggregate.gallery_id || 0);
    aggregate.gallery_ids = Array.from(new Set([...(aggregate.gallery_ids || []), ...((result.gallery_ids || []).map(Number))].filter(Boolean)));
    aggregate.gallery_title = aggregate.gallery_title || String(result.gallery_title || '');
    aggregate.gallery_url = aggregate.gallery_url || String(result.gallery_url || '');
    aggregate.edit_url = String(result.edit_url || aggregate.edit_url || '');
    aggregate.parent_gallery_id = Number(result.parent_gallery_id || aggregate.parent_gallery_id || 0);
    aggregate.parent_gallery_url = aggregate.parent_gallery_url || String(result.parent_gallery_url || '');
    aggregate.refresh_gallery_id = Number(result.refresh_gallery_id || aggregate.refresh_gallery_id || 0);
    aggregate.refresh_url = String(result.refresh_url || aggregate.refresh_url || '');
    aggregate.created_gallery = Boolean(result.created_gallery) || Boolean(aggregate.created_gallery);
    aggregate.image_ids = Array.from(new Set([...(aggregate.image_ids || []), ...((result.image_ids || []).map(Number))].filter(Boolean)));
    aggregate.upload_events = [...(aggregate.upload_events || []), ...((result.upload_events || []).slice())];
    if (!aggregate.created_gallery) {
        aggregate.mutation = result.mutation && typeof result.mutation === 'object' ? {...result.mutation} : aggregate.mutation;
        aggregate.panel = result.panel && typeof result.panel === 'object' ? {...result.panel} : aggregate.panel;
        aggregate.contexts = Array.isArray(result.contexts) ? result.contexts.map((context) => ({...context})) : aggregate.contexts;
        aggregate.fallback = result.fallback && typeof result.fallback === 'object' ? {...result.fallback} : aggregate.fallback;
    }
    aggregate.uploaded += Number(result.uploaded || 0);
    aggregate.scanned += Number(result.scanned || 0);
    aggregate.thumbnails += Number(result.thumbnails || 0);
    aggregate.thumbnail_skipped += Number(result.thumbnail_skipped || 0);
    aggregate.thumbnail_failed += Number(result.thumbnail_failed || 0);
    aggregate.scan_failed += Number(result.scan_failed || 0);
    aggregate.renamed += Number(result.renamed || 0);
    aggregate.thumbnail_errors = Array.from(new Set([...(aggregate.thumbnail_errors || []), ...((result.thumbnail_errors || []).map(String))].filter(Boolean)));
    aggregate.scan_failed_filenames = Array.from(new Set([...(aggregate.scan_failed_filenames || []), ...((result.scan_failed_filenames || []).map(String))].filter(Boolean)));
    aggregate.rename_warnings = Array.from(new Set([...(aggregate.rename_warnings || []), ...((result.rename_warnings || []).map(String))].filter(Boolean)));
    aggregate.rename_failures = Array.from(new Set([...(aggregate.rename_failures || []), ...((result.rename_failures || []).map(String))].filter(Boolean)));
    aggregate.redirect_url = String(result.redirect_url || aggregate.redirect_url || '');
}

/**
 * Append upload result counters to a URL.
 *
 * @param {string} urlValue Base URL.
 * @param {number} uploaded Uploaded count.
 * @param {number} scanned Scanned count.
 * @param {number} thumbnails Thumbnail count.
 * @param {number} thumbnailFailed Failed thumbnail count.
 * @return {string} URL with counters.
 */
function appendUploadResultParams(urlValue, uploaded, scanned, thumbnails, thumbnailFailed = 0) {
    const url = new URL(urlValue || window.location.href, window.location.href);
    url.searchParams.set('uploaded', String(uploaded));
    url.searchParams.set('scanned', String(scanned));
    url.searchParams.set('thumbnails', String(thumbnails));
    if (thumbnailFailed > 0) {
        url.searchParams.set('thumbnail_failed', String(thumbnailFailed));
    }
    return url.toString();
}

/**
 * Delay execution for retry backoff.
 *
 * @param {number} milliseconds Delay length.
 * @param {AbortSignal|null} signal Optional cancellation signal that interrupts the backoff.
 * @return {Promise<void>} Delay promise.
 */
function delay(milliseconds, signal = null) {
    return new Promise((resolve, reject) => {
        let timer = null;
        const finish = () => {
            if (signal) signal.removeEventListener('abort', abort);
            resolve();
        };
        const abort = () => {
            if (timer !== null) window.clearTimeout(timer);
            if (signal) signal.removeEventListener('abort', abort);
            reject(new Error(i18n('admin.icloud.cancelled')));
        };
        if (signal?.aborted) {
            abort();
            return;
        }
        if (signal) signal.addEventListener('abort', abort, {once: true});
        timer = window.setTimeout(finish, milliseconds);
    });
}

/**
 * Package entries in a worker so ZIP creation does not run on the main thread.
 *
 * @param {*} entries Entries value.
 * @return {Promise<Blob>} ZIP blob.
 */
function createStoreOnlyZipInWorker(entries) {
    return new Promise((resolve, reject) => {
        let worker;
        try {
            worker = new Worker(workerScriptUrl);
        } catch (error) {
            reject(error);
            return;
        }
        /**
         * Handle cleanup.
         *
         * @return {*} Result value for the caller.
         */
        const cleanup = () => worker.terminate();
        worker.addEventListener('message', (event) => {
            const data = event.data || {};
            cleanup();
            if (!data.ok || !(data.zipBlob instanceof Blob)) {
                reject(new Error(data.error || i18n('admin.browser_upload.zip_failed', 'Prepared ZIP packaging failed.')));
                return;
            }
            resolve(data.zipBlob);
        });
        worker.addEventListener('error', (event) => {
            cleanup();
            reject(new Error(event.message || i18n('admin.browser_upload.zip_failed', 'Prepared ZIP packaging failed.')));
        });
        worker.postMessage({action: 'zip', entries});
    });
}
