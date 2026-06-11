/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-experimental-upload.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Coordinates the opt-in experimental browser-side upload preparation path.
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
 *   2026-06-10
 */

import { i18n, updateBasicProgress } from './admin-core.js?v=20260512-modular-admin-v1';

const workerScriptUrl = new URL('./experimental-upload-worker.js?v=20260610-client-upload-v2', import.meta.url);
const tempDatabaseName = 'php_gallery_experimental_uploads';
const tempStoreName = 'prepared_batches';

/**
 * Return whether the admin explicitly selected the experimental path.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {boolean} True when the opt-in checkbox is checked.
 */
export function experimentalUploadRequested(form) {
    const toggle = form.querySelector('[data-experimental-upload-toggle]');
    return toggle instanceof HTMLInputElement && toggle.checked && !toggle.disabled;
}

/**
 * Run the experimental upload path or ask the caller to use the default path.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {HTMLElement} progress Progress container.
 * @return {Promise<Record<string, *> | {fallback: true, reason: string} >} Upload result or fallback request.
 */
export async function runExperimentalGalleryUpload(form, progress) {
    const config = experimentalUploadConfig(form);
    const files = selectedExperimentalUploadFiles(form);
    const allowEmptyPanelGallery = form.dataset.galleryPanelCloseOnSuccess === '1' && String(form.querySelector('input[name="upload_mode"]')?.value || '') === 'new';
    if (!config.enabled || files.length === 0 || allowEmptyPanelGallery && files.length === 0) {
        return {fallback: true, reason: 'not_applicable'};
    }
    const capability = experimentalUploadCapability(config, files);
    if (!capability.ok) {
        return {fallback: true, reason: capability.reason};
    }

    updateBasicProgress(progress, 1, i18n('admin.experimental_upload.preparing', 'Preparing images in the browser...'));
    const uploadSessionId = experimentalUploadSessionId();
    let batcher = null;
    let serverSideStarted = false;

    try {
        batcher = await createExperimentalBatcher(config, uploadSessionId);
        await processFilesWithWorkerPool(files, config, async (item, completed, total) => {
            await batcher.addItem(item);
            updateBasicProgress(progress, Math.max(2, Math.round((completed / total) * 45)), i18n('admin.experimental_upload.prepared_count', 'Prepared {count} of {total} image(s) in the browser.', {count: completed, total}));
        });
        const batches = await batcher.finish();
        if (batches.length === 0) {
            return {fallback: true, reason: 'empty_batches'};
        }

        let gallerySeed = null;
        let galleryId = selectedExperimentalGalleryId(form);
        if (!galleryId) {
            updateBasicProgress(progress, 48, i18n('admin.experimental_upload.creating_gallery', 'Creating gallery before uploading prepared batches...'));
            serverSideStarted = true;
            gallerySeed = await createGalleryForExperimentalUpload(form);
            galleryId = Number(gallerySeed.gallery_id || 0);
            if (!galleryId) {
                throw new Error(i18n('admin.experimental_upload.gallery_create_failed', 'The gallery was created, but no gallery id was returned.'));
            }
        }

        const aggregate = emptyExperimentalAggregate(gallerySeed, galleryId, batches.length);
        for (let index = 0; index < batches.length; index++) {
            const batch = batches[index];
            updateBasicProgress(progress, 50 + Math.round((index / batches.length) * 45), i18n('admin.experimental_upload.uploading_batch', 'Uploading prepared ZIP batch {current} of {total}...', {current: index + 1, total: batches.length}));
            serverSideStarted = true;
            const result = await uploadPreparedBatchWithRetry(form, config, galleryId, uploadSessionId, batch, index, batches.length);
            mergeExperimentalResult(aggregate, result);
            await batch.cleanup();
        }
        updateBasicProgress(progress, 98, i18n('admin.experimental_upload.finishing', 'Finishing browser-prepared upload...'));
        aggregate.total_files = files.length;
        aggregate.redirect_url = appendUploadResultParams(aggregate.redirect_url || window.location.href, aggregate.uploaded, aggregate.scanned, aggregate.thumbnails, aggregate.thumbnail_failed);
        return aggregate;
    } catch (error) {
        if (batcher) {
            await batcher.abort();
        }
        if (!serverSideStarted) {
            return {fallback: true, reason: error instanceof Error ? error.message : 'preparation_failed'};
        }
        throw error;
    }
}

/**
 * Read JSON configuration emitted by PHP near the opt-in checkbox.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {Record<string, *>} Normalized browser configuration.
 */
function experimentalUploadConfig(form) {
    const toggle = form.querySelector('[data-experimental-upload-toggle]');
    let parsed = {};
    if (toggle instanceof HTMLInputElement) {
        try {
            parsed = JSON.parse(toggle.dataset.experimentalUploadConfig || '{}');
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
 * Return selected files from the upload form.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {File[]} Selected files.
 */
function selectedExperimentalUploadFiles(form) {
    const input = form.querySelector('input[type="file"][name="images[]"]');
    if (!(input instanceof HTMLInputElement) || !input.files) {
        return [];
    }
    return Array.from(input.files).filter((file) => file instanceof File);
}

/**
 * Verify whether the browser and selected files can use the experimental path.
 *
 * @param {Record<string, *>} config Browser configuration.
 * @param {File[]} files Selected files.
 * @return {{ok: boolean, reason: string} } Capability result.
 */
function experimentalUploadCapability(config, files) {
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
function experimentalUploadSessionId() {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Run files through a bounded worker pool.
 *
 * @param {File[]} files Selected files.
 * @param {Record<string, *>} config Browser configuration.
 * @param {(item: Record<string, *>, completed: number, total: number) => Promise<void>} onItem Prepared item callback.
 * @return {Promise<void>} Result value for the caller.
 */
function processFilesWithWorkerPool(files, config, onItem) {
    const total = files.length;
    const workerCount = Math.max(1, Math.min(Number(config.workerCount || 1), total));
    let nextIndex = 0;
    let completed = 0;
    let rejected = false;
    const workers = [];

    return new Promise((resolve, reject) => {
        /**
         * Stop workers.
         *
         * Used by browser-side gallery behavior.
         */
        const stopWorkers = () => {
            workers.forEach((worker) => worker.terminate());
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
            reject(error instanceof Error ? error : new Error(String(error)));
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
                if (completed >= total) {
                    stopWorkers();
                    resolve();
                }
                return;
            }
            const index = nextIndex;
            nextIndex++;
            worker.postMessage({
                id: index,
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
            worker.addEventListener('message', async (event) => {
                const data = event.data || {};
                if (!data.ok) {
                    rejectOnce(new Error(data.error || i18n('admin.experimental_upload.worker_failed', 'Browser image preparation worker failed.')));
                    return;
                }
                try {
                    completed++;
                    await onItem(data.item, completed, total);
                    assign(worker);
                } catch (error) {
                    rejectOnce(error);
                }
            });
            worker.addEventListener('error', (event) => {
                rejectOnce(new Error(event.message || i18n('admin.experimental_upload.worker_failed', 'Browser image preparation worker failed.')));
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
 * @return {Promise<Record<string, Function>>} Batcher object.
 */
async function createExperimentalBatcher(config, uploadSessionId) {
    const tempStore = await openPreparedBatchStore();
    const targetBytes = Number(config.batchTargetBytes || 1);
    const maxBytes = targetBytes;
    const maxItemsPerBatch = Math.max(1, Math.min(Number(config.maxItemsPerBatch || 8), 64));
    let currentItems = [];
    let currentEntries = [];
    let currentBytes = 0;
    let nextBatchIndex = 0;
    const batches = [];

    /**
     * Handle finalize current.
     *
     * Used by browser-side gallery behavior.
     */
    const finalizeCurrent = async () => {
        if (!currentItems.length) {
            return;
        }
        const manifest = {
            version: 1,
            upload_session_id: uploadSessionId,
            batch_index: nextBatchIndex,
            items: currentItems,
        };
        const zipBlob = await createStoreOnlyZipInWorker([
            {path: 'manifest.json', blob: new Blob([JSON.stringify(manifest)], {type: 'application/json'})},
            ...currentEntries,
        ]);
        if (zipBlob.size > maxBytes) {
            throw new Error(i18n('admin.experimental_upload.batch_too_large', 'A prepared ZIP batch is larger than the server upload limit. Use the default upload path for these files.'));
        }
        const tempId = `${uploadSessionId}-${nextBatchIndex}`;
        await tempStore.put(tempId, zipBlob);
        batches.push({
            id: tempId,
            index: nextBatchIndex,
            blob: zipBlob,
            cleanup: () => tempStore.delete(tempId),
        });
        nextBatchIndex++;
        currentItems = [];
        currentEntries = [];
        currentBytes = 0;
    };

    return {
        addItem: async (item) => {
            const itemEntries = entriesForPreparedItem(item);
            const itemBytes = itemEntries.reduce((sum, entry) => sum + Number(entry.blob.size || 0) + 256 + entry.path.length, 0) + 1024;
            if (itemBytes > maxBytes) {
                throw new Error(i18n('admin.experimental_upload.item_too_large', 'One prepared image package is larger than the server upload limit. Use the default upload path for that image.'));
            }
            if (currentItems.length > 0 && (currentBytes + itemBytes > targetBytes || currentItems.length >= maxItemsPerBatch)) {
                await finalizeCurrent();
            }
            currentItems.push(manifestItemForPreparedItem(item));
            currentEntries.push(...itemEntries);
            currentBytes += itemBytes;
        },
        finish: async () => {
            await finalizeCurrent();
            return batches;
        },
        abort: async () => {
            await Promise.allSettled(batches.map((batch) => batch.cleanup()));
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
 * Return the manifest-safe shape for one prepared item.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {Record<string, *>} Manifest item.
 */
function manifestItemForPreparedItem(item) {
    return {
        original_name: item.originalName,
        prepared_name: item.preparedName,
        original_path: item.originalPath,
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
 * Open the IndexedDB store used as the temporary upload working area.
 *
 * @return {Promise<{put: Function, delete: Function} >} Store wrapper.
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
                put: (id, blob) => indexedDbRequest(database, 'readwrite', (store) => store.put(blob, id)),
                delete: (id) => indexedDbRequest(database, 'readwrite', (store) => store.delete(id)),
            });
        });
    });
}

/**
 * Run one IndexedDB object store request.
 *
 * @param {IDBDatabase} database Database connection.
 * @param {IDBTransactionMode} mode Transaction mode.
 * @param {(store: IDBObjectStore) => IDBRequest} operation Store operation.
 * @return {Promise<*>} Request result.
 */
function indexedDbRequest(database, mode, operation) {
    return new Promise((resolve, reject) => {
        const transaction = database.transaction(tempStoreName, mode);
        const store = transaction.objectStore(tempStoreName);
        const request = operation(store);
        request.addEventListener('success', () => resolve(request.result));
        request.addEventListener('error', () => reject(request.error || new Error('IndexedDB request failed.')));
    });
}

/**
 * Send the create-gallery form without files before experimental batch upload.
 *
 * @param {HTMLFormElement} form Upload form.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function createGalleryForExperimentalUpload(form) {
    const body = new FormData(form);
    body.delete('images[]');
    body.set('ajax', '1');
    const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    const result = await readJsonResponseSafely(response, i18n('admin.experimental_upload.gallery_create_failed', 'Gallery creation failed.'));
    if (!response.ok || result.ok === false) {
        throw new Error(result.error || i18n('admin.experimental_upload.gallery_create_failed', 'Gallery creation failed.'));
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
 * @param {Record<string, *>} batch Prepared batch.
 * @param {number} batchIndex Batch index.
 * @param {number} totalBatches Total batches.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function uploadPreparedBatchWithRetry(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches) {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            return await uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches);
        } catch (error) {
            lastError = error;
            if (attempt < 3) {
                await delay(750 * attempt);
            }
        }
    }
    throw lastError || new Error(i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.'));
}

/**
 * Upload one prepared batch once.
 *
 * @param {HTMLFormElement} form Upload form.
 * @param {Record<string, *>} config Browser configuration.
 * @param {number} galleryId Target gallery id.
 * @param {string} uploadSessionId Upload session id.
 * @param {Record<string, *>} batch Prepared batch.
 * @param {number} batchIndex Batch index.
 * @param {number} totalBatches Total batches.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches) {
    const body = new FormData();
    body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
    body.set('ajax', '1');
    body.set('gallery_id', String(galleryId));
    body.set('upload_session_id', uploadSessionId);
    body.set('batch_index', String(batchIndex));
    body.set('total_batches', String(totalBatches));
    const sourceUrl = form.querySelector('input[name="source_url"]')?.value || '';
    if (sourceUrl) {
        body.set('source_url', sourceUrl);
    }
    body.append('zip_batch', batch.blob, `experimental-upload-${uploadSessionId}-${batch.index}.zip`);
    const response = await fetch(String(config.endpoint), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    const result = await readJsonResponseSafely(response, i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.'));
    if (!response.ok || result.ok === false) {
        throw new Error(result.error || i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.'));
    }
    return result;
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
            throw new Error(i18n('admin.experimental_upload.php_upload_limit', 'The server refused the prepared ZIP because a PHP upload limit was reached.'));
        }
        if (snippet.startsWith('<')) {
            const statusText = response.status ? `HTTP ${response.status}` : 'HTTP error';
            throw new Error(`${fallbackMessage} ${i18n('admin.experimental_upload.html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')} ${statusText}: ${snippet.slice(0, 120)}`);
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
function selectedExperimentalGalleryId(form) {
    const select = form.querySelector('select[name="gallery_id"]');
    if (select instanceof HTMLSelectElement) {
        return Number(select.value || 0);
    }
    const hidden = form.querySelector('input[name="gallery_id"]');
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
function emptyExperimentalAggregate(seed, galleryId, totalBatches) {
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
function mergeExperimentalResult(aggregate, result) {
    aggregate.gallery_id = Number(result.gallery_id || aggregate.gallery_id || 0);
    aggregate.gallery_ids = Array.from(new Set([...(aggregate.gallery_ids || []), ...((result.gallery_ids || []).map(Number))].filter(Boolean)));
    aggregate.gallery_title = aggregate.gallery_title || String(result.gallery_title || '');
    aggregate.gallery_url = aggregate.gallery_url || String(result.gallery_url || '');
    aggregate.edit_url = String(result.edit_url || aggregate.edit_url || '');
    aggregate.parent_gallery_id = Number(result.parent_gallery_id || aggregate.parent_gallery_id || 0);
    aggregate.parent_gallery_url = aggregate.parent_gallery_url || String(result.parent_gallery_url || '');
    aggregate.refresh_gallery_id = Number(result.refresh_gallery_id || aggregate.refresh_gallery_id || 0);
    aggregate.refresh_url = String(result.refresh_url || aggregate.refresh_url || '');
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
 * @return {Promise<void>} Delay promise.
 */
function delay(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
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
                reject(new Error(data.error || i18n('admin.experimental_upload.zip_failed', 'Prepared ZIP packaging failed.')));
                return;
            }
            resolve(data.zipBlob);
        });
        worker.addEventListener('error', (event) => {
            cleanup();
            reject(new Error(event.message || i18n('admin.experimental_upload.zip_failed', 'Prepared ZIP packaging failed.')));
        });
        worker.postMessage({action: 'zip', entries});
    });
}
