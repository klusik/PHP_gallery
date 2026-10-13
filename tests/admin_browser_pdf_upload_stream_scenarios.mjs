/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_browser_pdf_upload_stream_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Verify PDF page backpressure, acknowledged-batch cleanup and cancellation.
 * Responsibilities: Exercise the reusable uploader with a controlled worker, batcher and ordered page source.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/**
 * Describe one prepared original record supplied to the ZIP batcher.
 * @typedef {Object} ProductionBatcherItem
 * @property {string} originalPath Relative package path for the original PNG.
 * @property {Blob} originalFile Original PNG bytes, retained unchanged.
 * @property {number} originalSize Original PNG byte count.
 * @property {Array<{path: string, blob: Blob}>} variants Prepared derivative files; empty in this retry fixture.
 */

/**
 * Describe one persisted ZIP batch returned by the production batcher.
 * @typedef {Object} ProductionBatcherBatch
 * @property {string} id IndexedDB key for the ZIP.
 * @property {number} index Zero-based batch index.
 * @property {Blob} blob Store-only ZIP bytes.
 * @property {number} itemCount Number of original image packages.
 */

/** @typedef {(batch: ProductionBatcherBatch) => Promise<void>} ProductionBatcherReadyHandler Upload callback invoked after the ZIP is durable. */

/**
 * Describe callbacks supplied to the production batcher.
 * @typedef {Object} ProductionBatcherHooks
 * @property {ProductionBatcherReadyHandler} [onBatchReady] Upload a retained batch; rejection simulates a retryable transport failure.
 */

/**
 * Describe the production batcher operations needed by the retry regression.
 * @typedef {Object} ProductionBatcher
 * @property {ProductionBatcherBatch[]} batches Persisted batches in creation order.
 * @property {(item: ProductionBatcherItem) => Promise<{id: string, sourceIndex: number, itemBytes: number, originalBytes: number, flushRequired: boolean}>} addItem Store one prepared package and queue its reference.
 * @property {() => Promise<ProductionBatcherBatch[]>} finish Package the trailing pending items and return persisted batches.
 */

/**
 * Describe dependencies and state for the production batcher fixture.
 * @typedef {Object} ProductionBatcherHarness
 * @property {(config: {batchTargetBytes: number, uploadLimitBytes: number}, sessionId: string, hooks?: ProductionBatcherHooks) => Promise<ProductionBatcher>} createBatcher Construct the production batcher.
 * @property {Map<string, unknown>} store In-memory substitute for the uploader IndexedDB store.
 */

/**
 * Describe the mock PDF stream consumed by the uploader test.
 * @typedef {Object} PdfUploadTestStream
 * @property {number} pageCount Total number of generated pages.
 * @property {number} dpi Fixed test output resolution.
 * @property {() => AsyncGenerator<File, void, void>} [Symbol.asyncIterator] Return the controlled page iterator.
 * @property {() => Promise<void>} cancel Release source work after cancellation.
 * @property {() => Promise<void>} dispose Record stream resource release.
 */

/**
 * Describe mutable observations shared by test adapters.
 * @typedef {Object} PdfUploadTestState
 * @property {number[]} generatedPages Source pages rendered by the fake converter.
 * @property {number[]} acknowledged Batch indexes acknowledged by the fake server.
 * @property {number[]} uploadedBatchIndexes Batch indexes sent by the uploader.
 * @property {number} disposed Number of stream disposal calls.
 */

/**
 * Describe the minimal browser uploader harness returned by the loader.
 * @typedef {Object} PdfUploadTestHarness
 * @property {(form: HTMLFormElement, progress: HTMLElement, operation: {prepared: object|null, unresolved: boolean}, fileStream: PdfUploadTestStream, options: Record<string, unknown>) => Promise<Record<string, unknown>>} upload Reusable upload entry point.
 * @property {PdfUploadTestState} state Observed stream, upload and acknowledgment order.
 */

/**
 * Describe the form and progress elements returned by the fixture factory.
 * @typedef {Object} PdfUploadTestNodes
 * @property {PdfUploadTestForm} form Minimal upload destination form.
 * @property {PdfUploadTestProgress} progress Minimal progress surface.
 */

/**
 * Describe the one form query used by the uploader fixture.
 * @typedef {Object} PdfUploadTestForm
 * @property {(selector: string) => ({checked: boolean}|null)} querySelector Return the thumbnail option or no matching control.
 */

/**
 * Describe the one progress query used by the uploader fixture.
 * @typedef {Object} PdfUploadTestProgress
 * @property {(selector: string) => null} querySelector Report that no optional progress element is present.
 */

/**
 * Load the reusable uploader with deterministic image-worker and ZIP-batcher adapters.
 * @returns {Promise<PdfUploadTestHarness>} VM upload entry point and observed stream order.
 */
async function loadUploaderHarness() {
    const source = await readFile(new URL('../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
    const state = {generatedPages: [], acknowledged: [], uploadedBatchIndexes: [], disposed: 0};
    const context = vm.createContext({
        AbortController,
        AbortSignal,
        Blob,
        File,
        FormData,
        HTMLInputElement: Object,
        URL,
        window: {location: {href: 'https://gallery.example/admin.php', origin: 'https://gallery.example'}},
        i18n: (_key, fallback, parameters = {}) => Object.entries(parameters).reduce((message, [name, value]) => message.replaceAll(`{${name}}`, String(value)), fallback),
        appendUploadProgressLog: () => {},
        updateBasicProgress: () => {},
        updateUploadProgressMetrics: () => {},
        adminOperationBody: () => new FormData(),
        assertAdminOperationCurrent: () => {},
        state,
        console,
    });
    const overrides = `
        function browserUploadConfig() {
            return {enabled: true, endpoint: '/upload', thumbnailSizes: [], thumbnailFormats: [], workerCount: 1,
                uploadLimitBytes: 1024 * 1024, batchTargetBytes: 1024 * 1024, maxItemsPerBatch: 2};
        }
        function browserUploadSessionId() { return 'pdf-session-test'; }
        function selectedBrowserGalleryId() { return 41; }
        function browserProgressMetrics() { return {}; }
        function formatFileSize(bytes) { return String(bytes); }
        async function processFilesWithWorkerPool(files, _config, callback) {
            for (const file of files) await callback({originalFile: file, originalDetectedFormat: 'png', originalPath: file.name,
                originalName: file.name, originalSize: file.size, variants: [], clientExif: null});
        }
        async function uploadPreparedBatchWithRetry(_form, _config, _galleryId, _session, batch) {
            state.uploadedBatchIndexes.push(batch.index);
            return {ok: true, gallery_id: 41, gallery_ids: [41], image_ids: [batch.index + 1], uploaded: batch.itemCount,
                scanned: 0, thumbnails: 0, upload_events: []};
        }
        async function createBrowserBatcher(config, _session, hooks = {}) {
            const pending = [];
            const batches = [];
            let nextIndex = Number(config.startBatchIndex || 0);
            const flush = async () => {
                if (!pending.length) return;
                const files = pending.splice(0, pending.length);
                const batch = {id: 'batch-' + nextIndex, index: nextIndex++, blob: new Blob(['zip']), itemCount: files.length,
                    cleanup: async () => {}};
                batches.push(batch);
                if (typeof hooks.onBatchReady === 'function') await hooks.onBatchReady(batch);
            };
            return {batches, addItem: async item => {
                    pending.push(item);
                    return {itemBytes: item.originalSize, flushRequired: pending.length >= 2};
                },
                shouldFlushBefore: () => pending.length >= 2,
                flush,
                finish: async () => { await flush(); return batches; },
                cleanupPrepared: async () => {},
                abort: async () => { pending.length = 0; },
            };
        }
        globalThis.__uploadBrowserFileStream = uploadBrowserFileStream;
    `;
    const transformed = source
        .replace(/^import .*;\s*$/gm, '')
        .replace(/^export (async function|function) /gm, '$1 ')
        .replaceAll('import.meta.url', "'https://gallery.example/assets/gallery-modules/admin-browser-upload.js'");
    vm.runInContext(`${transformed}\n${overrides}`, context);
    return {upload: context.__uploadBrowserFileStream, state};
}

/**
 * Load the production ZIP batcher with a small in-memory IndexedDB substitute.
 * @returns {Promise<ProductionBatcherHarness>} Batch factory and persisted temporary records.
 */
async function loadProductionBatcherHarness() {
    const source = await readFile(new URL('../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
    const declarationStart = source.indexOf('async function createBrowserBatcher(');
    const documentationStart = source.lastIndexOf('/**', declarationStart);
    const declarationEnd = source.indexOf('\n}\n\n/**\n * Return ZIP entries for one prepared item.', declarationStart) + 2;
    assert.ok(documentationStart >= 0 && declarationEnd > declarationStart, 'The production batcher declaration is available for the focused fixture.');
    const store = new Map();
    const context = vm.createContext({
        Blob,
        Map,
        i18n: (_key, fallback) => fallback,
        openPreparedBatchStore: async () => ({
            get: async key => store.get(key),
            put: async (key, value) => store.set(key, value),
            delete: async key => store.delete(key),
        }),
        preparedItemByteSize: item => item.originalFile.size + 1,
        manifestItemForPreparedItem: item => ({path: item.originalPath}),
        entriesForPreparedItem: item => [{path: item.originalPath, blob: item.originalFile}],
        createStoreOnlyZipInWorker: async () => new Blob(['stored-zip']),
    });
    const declaration = source.slice(documentationStart, declarationEnd);
    vm.runInContext(`${declaration}\nglobalThis.__createBrowserBatcher = createBrowserBatcher;`, context);
    return {createBatcher: context.__createBrowserBatcher, store};
}

/**
 * Build a generated PNG stream that proves an acknowledgment precedes the next page pull.
 * @param {number} pageCount Total pages to yield.
 * @param {AbortSignal} signal Signal checked by the source before rendering each page.
 * @param {PdfUploadTestState} state Shared observed upload state.
 * @returns {PdfUploadTestStream} Controlled ordered page source.
 */
function pageFileStream(pageCount, signal, state) {
    const iterator = async function* () {
            for (let page = 1; page <= pageCount; page++) {
                if (signal.aborted) throw new Error('The PDF page upload was cancelled.');
                if (page > 1 && (page - 1) % 2 === 0) {
                    assert.ok(state.acknowledged.length >= (page - 1) / 2, 'Uploader must acknowledge each full batch before pulling another page.');
                }
                state.generatedPages.push(page);
                yield new File([`png-${page}`], `page-${String(page).padStart(4, '0')}.png`, {type: 'image/png'});
            }
    };
    return {
        pageCount,
        dpi: 150,
        [Symbol.asyncIterator]: () => iterator(),
        cancel: async () => {},
        dispose: async () => { state.disposed++; },
    };
}

/**
 * Build the minimal form and progress nodes used by the stream entry point.
 * @returns {PdfUploadTestNodes} Deterministic upload UI substitutes.
 */
function uploadNodes() {
    const form = {
        querySelector: (selector) => {
            if (selector === 'input[name="create_thumbnails"]') return {checked: false};
            return null;
        },
    };
    return {form, progress: {querySelector: () => null}};
}

const normalRun = await loadUploaderHarness();
const normalController = new AbortController();
const normalSource = pageFileStream(5, normalController.signal, normalRun.state);
const normalOperation = {prepared: null, unresolved: false};
const {form: normalForm, progress: normalProgress} = uploadNodes();
const normalResult = await normalRun.upload(normalForm, normalProgress, normalOperation, normalSource, {
    signal: normalController.signal,
    galleryId: 41,
    uploadSessionId: 'pdf-session-test',
    conversionToken: 'opaque-token',
    sourceGalleryId: 17,
    startBatchIndex: 6,
    onBatchAcknowledged: ack => normalRun.state.acknowledged.push(ack.batchIndex),
});
assert.deepEqual(normalRun.state.generatedPages, [1, 2, 3, 4, 5]);
assert.deepEqual(normalRun.state.acknowledged, [6, 7, 8]);
assert.deepEqual(normalRun.state.uploadedBatchIndexes, [6, 7, 8]);
assert.equal(normalResult.uploaded, 5);
assert.equal(normalRun.state.disposed, 1);

const cancelRun = await loadUploaderHarness();
const cancelController = new AbortController();
const cancelSource = pageFileStream(5, cancelController.signal, cancelRun.state);
const cancelOperation = {prepared: null, unresolved: false};
const {form: cancelForm, progress: cancelProgress} = uploadNodes();
await assert.rejects(cancelRun.upload(cancelForm, cancelProgress, cancelOperation, cancelSource, {
    signal: cancelController.signal,
    galleryId: 41,
    uploadSessionId: 'pdf-session-test',
    conversionToken: 'opaque-token',
    sourceGalleryId: 17,
    startBatchIndex: 0,
    onBatchAcknowledged: () => {
        cancelRun.state.acknowledged.push(0);
        cancelController.abort();
    },
}), /cancelled/i);
assert.deepEqual(cancelRun.state.generatedPages, [1, 2], 'Cancellation prevents the next page render after the acknowledged batch.');
assert.deepEqual(cancelRun.state.acknowledged, [0], 'Cancellation retains the acknowledged batch result.');
assert.equal(cancelRun.state.disposed, 1, 'The source renderer is disposed after cancellation.');

const retryBatcherHarness = await loadProductionBatcherHarness();
let rejectFirstBatchUpload = true;
const retryBatcher = await retryBatcherHarness.createBatcher({batchTargetBytes: 4096, uploadLimitBytes: 8192}, 'retry-session', {
    onBatchReady: async () => {
        if (rejectFirstBatchUpload) {
            rejectFirstBatchUpload = false;
            throw new Error('temporary upload failure');
        }
    },
});
await retryBatcher.addItem({
    originalPath: 'pdf-source-page-0001.png',
    originalFile: new Blob(['page-png'], {type: 'image/png'}),
    originalSize: 8,
    variants: [],
});
await assert.rejects(retryBatcher.finish(), /temporary upload failure/);
assert.equal(retryBatcher.batches.length, 1, 'A failed upload retains its already packaged ZIP for exact retry.');
assert.ok(retryBatcherHarness.store.has(retryBatcher.batches[0].id), 'The batch ZIP remains durable after upload failure.');
assert.equal(retryBatcherHarness.store.has('retry-session-prepared-000000'), false, 'Acknowledged package ownership no longer depends on deleted prepared items.');
assert.equal((await retryBatcher.finish()).length, 1, 'Retry completion does not repackage the trailing item from a stale pending reference.');

const uploaderSource = await readFile(new URL('../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
assert.match(uploaderSource, /processFilesWithWorkerPool\(files, \{\.\.\.processingConfig, signal\}/, 'Classic FileList browser uploads retain the existing worker path with explicit optional cancellation.');
assert.match(uploaderSource, /const batches = await batcher\.finish\(\)/, 'Classic browser uploads still finish their existing ZIP batcher.');
assert.match(uploaderSource, /options\.signal\.addEventListener\('abort', abortRequest/, 'Conversion cancellation aborts an in-flight prepared-batch request.');

console.log('Browser PDF upload stream tests passed.');
