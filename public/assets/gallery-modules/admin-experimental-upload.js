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

import { appendUploadProgressLog, i18n, updateBasicProgress, updateUploadProgressMetrics } from './admin-core.js?v=20260614-upload-order-v2';

const workerScriptUrl = new URL('./experimental-upload-worker.js?v=20260614-upload-order-v2', import.meta.url);
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

    const progressState = createExperimentalProgressState(files);
    updateBasicProgress(progress, 1, i18n('admin.experimental_upload.preparing', 'Preparing images in the browser...'));
    updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
    appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_selected', 'Selected {count} image(s), {bytes} original data.', {count: files.length, bytes: formatFileSize(progressState.totalOriginalBytes)}));
    const uploadSessionId = experimentalUploadSessionId();
    let batcher = null;
    let serverSideStarted = false;

    try {
        batcher = await createExperimentalBatcher(config, uploadSessionId, {
            onBatchPackaging: (batchIndex, itemCount, byteCount) => {
                appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_packaging_batch', 'Packaging ZIP {index}: {count} image(s), estimated {bytes}.', {index: batchIndex + 1, count: itemCount, bytes: formatFileSize(byteCount)}));
            },
            onBatchReady: (batch) => {
                appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_packaged_batch', 'ZIP {index} ready: {count} image(s), {bytes}.', {index: batch.index + 1, count: batch.itemCount || 0, bytes: formatFileSize(batch.blob?.size || 0)}));
            },
        });
        await processFilesWithWorkerPool(files, config, async (item, completed, total) => {
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
            updateBasicProgress(progress, Math.max(2, Math.round((completed / total) * 45)), i18n('admin.experimental_upload.prepared_count', 'Prepared {count} of {total} image(s) in the browser.', {count: completed, total}));
            updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
            appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_prepared_image', 'Prepared {current}/{total}: {name}, source {source}, package {packageSize}.', {current: completed, total, name: String(item.originalName || ''), source: formatFileSize(item.originalSize || 0), packageSize: formatFileSize(prepared.itemBytes || 0)}));
        });
        updateBasicProgress(progress, 46, i18n('admin.experimental_upload.packaging', 'Packaging prepared images into upload ZIP batches...'));
        appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_packaging_started', 'All images are prepared. Building upload ZIP files now.'));
        const batches = await batcher.finish();
        if (batches.length === 0) {
            return {fallback: true, reason: 'empty_batches'};
        }
        progressState.totalBatches = batches.length;
        progressState.totalZipBytes = batches.reduce((sum, batch) => sum + Number(batch.blob?.size || 0), 0);
        updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
        appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_packaging_finished', 'Created {count} ZIP upload file(s), total {bytes}.', {count: batches.length, bytes: formatFileSize(progressState.totalZipBytes)}));

        let gallerySeed = null;
        let galleryId = selectedExperimentalGalleryId(form);
        if (!galleryId) {
            updateBasicProgress(progress, 48, i18n('admin.experimental_upload.creating_gallery', 'Creating gallery before uploading prepared batches...'));
            appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_creating_gallery', 'Creating target gallery before uploading ZIP files.'));
            serverSideStarted = true;
            gallerySeed = await createGalleryForExperimentalUpload(form);
            galleryId = Number(gallerySeed.gallery_id || 0);
            appendServerUploadEvents(progress, gallerySeed.upload_events || []);
            if (!galleryId) {
                throw new Error(i18n('admin.experimental_upload.gallery_create_failed', 'The gallery was created, but no gallery id was returned.'));
            }
        }

        const aggregate = emptyExperimentalAggregate(gallerySeed, galleryId, batches.length);
        for (let index = 0; index < batches.length; index++) {
            const batch = batches[index];
            const uploadedBeforeBatch = progressState.uploadedZipBytes;
            progressState.currentBatchIndex = index + 1;
            progressState.currentBatchBytes = Number(batch.blob?.size || 0);
            progressState.currentBatchUploadedBytes = 0;
            updateBasicProgress(progress, 50 + Math.round((index / batches.length) * 45), i18n('admin.experimental_upload.uploading_batch', 'Uploading prepared ZIP batch {current} of {total}...', {current: index + 1, total: batches.length}));
            updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
            appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_uploading_batch', 'Uploading ZIP {current}/{total}: {count} image(s), {bytes}.', {current: index + 1, total: batches.length, count: batch.itemCount || 0, bytes: formatFileSize(batch.blob?.size || 0)}));
            serverSideStarted = true;
            const result = await uploadPreparedBatchWithRetry(form, config, galleryId, uploadSessionId, batch, index, batches.length, (event) => {
                if (event.lengthComputable && event.total > 0) {
                    const ratio = Math.max(0, Math.min(1, event.loaded / event.total));
                    progressState.currentBatchUploadedBytes = Math.round(Number(batch.blob?.size || 0) * ratio);
                }
                progressState.uploadedZipBytes = uploadedBeforeBatch + progressState.currentBatchUploadedBytes;
                updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
            });
            progressState.uploadedBatches = index + 1;
            progressState.uploadedFiles += Number(batch.itemCount || 0);
            progressState.uploadedZipBytes = uploadedBeforeBatch + Number(batch.blob?.size || 0);
            progressState.currentBatchUploadedBytes = Number(batch.blob?.size || 0);
            updateUploadProgressMetrics(progress, experimentalProgressMetrics(progressState));
            appendServerUploadEvents(progress, result.upload_events || []);
            appendUploadProgressLog(progress, i18n('admin.experimental_upload.log_batch_finished', 'Finished ZIP {current}/{total}: {uploaded} image(s), {thumbs} prepared thumbnail file(s).', {current: index + 1, total: batches.length, uploaded: Number(result.uploaded || 0), thumbs: Number(result.thumbnails || 0)}));
            mergeExperimentalResult(aggregate, result);
            await batch.cleanup();
        }
        if (typeof batcher.cleanupPrepared === 'function') {
            await batcher.cleanupPrepared();
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
 * Build the mutable progress state used by the experimental upload UI.
 *
 * @param {File[]} files Selected source files.
 * @return {Record<string, number>} Progress state.
 */
function createExperimentalProgressState(files) {
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
function experimentalProgressMetrics(state) {
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
    return Array.from(input.files)
        .filter((file) => file instanceof File)
        .sort(compareUploadFilesByDefaultFolderOrder);
}

/**
 * Compare selected files by the default folder order used by the upload pipeline.
 *
 * Browser FileList ordering can vary by browser, operating system, and selection
 * method. The experimental upload path runs files through workers, so the source
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
    const pendingItemWrites = new Set();

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
            worker.addEventListener('message', (event) => {
                const data = event.data || {};
                if (!data.ok) {
                    rejectOnce(new Error(data.error || i18n('admin.experimental_upload.worker_failed', 'Browser image preparation worker failed.')));
                    return;
                }
                completed++;
                persistPreparedItem(data.item, completed);
                assign(worker);
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
 * @param {Record<string, Function>} hooks Optional progress hooks.
 * @return {Promise<Record<string, Function>>} Batcher object.
 */
async function createExperimentalBatcher(config, uploadSessionId, hooks = {}) {
    const tempStore = await openPreparedBatchStore();
    const targetBytes = Number(config.batchTargetBytes || 1);
    const maxBytes = targetBytes;
    const maxItemsPerBatch = Math.max(1, Math.min(Number(config.maxItemsPerBatch || 8), 64));
    let nextBatchIndex = 0;
    const preparedItems = [];
    const batches = [];

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
        const keys = preparedItems.map((item) => item.id);
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
        const currentItems = [];
        const currentEntries = [];
        const currentBytes = current.reduce((sum, reference) => sum + Number(reference.itemBytes || 0), 0);
        if (typeof hooks.onBatchPackaging === 'function') {
            hooks.onBatchPackaging(nextBatchIndex, current.length, currentBytes);
        }
        for (const reference of current) {
            const item = await tempStore.get(reference.id);
            if (!item || typeof item !== 'object') {
                throw new Error(i18n('admin.experimental_upload.prepared_item_missing', 'A prepared image package is missing from browser temporary storage.'));
            }
            currentItems.push(manifestItemForPreparedItem(item));
            currentEntries.push(...entriesForPreparedItem(item));
        }
        const manifest = {
            version: 2,
            upload_session_id: uploadSessionId,
            batch_index: nextBatchIndex,
            total_prepared_items: preparedItems.length,
            items: currentItems,
        };
        const zipBlob = await createStoreOnlyZipInWorker([
            {path: 'manifest.json', blob: new Blob([JSON.stringify(manifest)], {type: 'application/json'})},
            ...currentEntries,
        ]);
        if (zipBlob.size > maxBytes) {
            throw new Error(i18n('admin.experimental_upload.batch_too_large', 'A prepared ZIP batch is larger than the server upload limit. Use the default upload path for these files.'));
        }
        const tempId = `${uploadSessionId}-batch-${nextBatchIndex}`;
        await tempStore.put(tempId, zipBlob);
        const batch = {
            id: tempId,
            index: nextBatchIndex,
            blob: zipBlob,
            itemCount: current.length,
            originalBytes: current.reduce((sum, reference) => sum + Number(reference.originalBytes || 0), 0),
            preparedBytes: currentBytes,
            cleanup: () => tempStore.delete(tempId),
        };
        batches.push(batch);
        if (typeof hooks.onBatchReady === 'function') {
            hooks.onBatchReady(batch);
        }
        nextBatchIndex++;
    };

    return {
        addItem: async (item) => {
            const sourceIndex = Number.isInteger(Number(item.sourceIndex)) ? Number(item.sourceIndex) : preparedItems.length;
            const itemBytes = preparedItemByteSize(item);
            if (itemBytes > maxBytes) {
                throw new Error(i18n('admin.experimental_upload.item_too_large', 'One prepared image package is larger than the server upload limit. Use the default upload path for that image.'));
            }
            const id = preparedItemId(sourceIndex);
            await tempStore.put(id, {...item, sourceIndex, itemBytes});
            const originalBytes = Number(item.originalSize || 0);
            preparedItems.push({id, sourceIndex, itemBytes, originalBytes});
            return {id, sourceIndex, itemBytes, originalBytes};
        },
        finish: async () => {
            preparedItems.sort((left, right) => Number(left.sourceIndex || 0) - Number(right.sourceIndex || 0));
            let current = [];
            let currentBytes = 0;
            for (const reference of preparedItems) {
                const itemBytes = Number(reference.itemBytes || 0);
                if (current.length > 0 && (currentBytes + itemBytes > targetBytes || current.length >= maxItemsPerBatch)) {
                    await finalizeBatch(current);
                    current = [];
                    currentBytes = 0;
                }
                current.push(reference);
                currentBytes += itemBytes;
            }
            await finalizeBatch(current);
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
 * @param {Function} progressHandler Browser upload progress handler.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function uploadPreparedBatchWithRetry(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, progressHandler = () => {}) {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            return await uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, progressHandler);
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
 * @param {Function} progressHandler Browser upload progress handler.
 * @return {Promise<Record<string, *>>} Server response.
 */
function uploadPreparedBatch(form, config, galleryId, uploadSessionId, batch, batchIndex, totalBatches, progressHandler = () => {}) {
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
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', String(config.endpoint));
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', progressHandler);
        xhr.addEventListener('load', async () => {
            try {
                const response = new Response(xhr.responseText || '', {
                    status: xhr.status,
                    headers: {'Content-Type': xhr.getResponseHeader('Content-Type') || ''},
                });
                const result = await readJsonResponseSafely(response, i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.'));
                if (xhr.status < 200 || xhr.status >= 300 || result.ok === false) {
                    throw new Error(result.error || i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.'));
                }
                resolve(result);
            } catch (error) {
                reject(error);
            }
        });
        xhr.addEventListener('error', () => {
            reject(new Error(i18n('admin.experimental_upload.batch_failed', 'Prepared batch upload failed.')));
        });
        xhr.send(body);
    });
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
