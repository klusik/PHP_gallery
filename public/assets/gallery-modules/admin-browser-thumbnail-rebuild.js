/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-browser-thumbnail-rebuild.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Coordinates the browser-assisted thumbnail rebuild workflow.
 *
 * Responsibilities:
 *   - Download source image ZIP chunks prepared by PHP
 *   - Parse store-only source ZIP chunks in a worker
 *   - Generate thumbnail derivatives in a bounded worker pool
 *   - Package prepared thumbnails into store-only ZIP upload batches
 *   - Upload acknowledged batches sequentially with retry
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

import { i18n, updateThumbnailProgress } from './admin-core.js?v=20260512-modular-admin-v1';

const workerScriptUrl = new URL('./browser-image-worker.js?v=20260610-thumbnail-serial-v5', import.meta.url);

/**
 * Return whether the administrator requested browser-assisted thumbnail rebuild.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @return {boolean} True when the browser rebuild checkbox is checked.
 */
export function browserThumbnailRebuildRequested(form) {
    const toggle = form.querySelector('[data-browser-thumbnail-rebuild-toggle]');
    return toggle instanceof HTMLInputElement && toggle.checked && !toggle.disabled;
}

/**
 * Run the browser-assisted thumbnail rebuild workflow.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @param {HTMLElement} progress Progress root.
 * @param {object} options Optional behavior flags.
 * @return {Promise<Record<string, *>>} Aggregate result.
 */
export async function runBrowserThumbnailRebuild(form, progress, options = {}) {
    const config = browserThumbnailRebuildConfig(form);
    const capability = browserThumbnailRebuildCapability(config);
    if (!config.enabled || !capability.ok) {
        throw new Error(capability.reason || i18n('admin.browser_thumbnail_rebuild.unavailable', 'Browser thumbnail rebuild is not available in this browser.'));
    }

    const sessionId = browserThumbnailRebuildSessionId();
    const aggregate = emptyRebuildAggregate();
    const requestedScope = String(options.scope || 'all') === 'missing' ? 'missing' : 'all';
    let batchIndex = 0;
    let displayTotal = 0;
    const preparingLabel = requestedScope === 'missing'
        ? i18n('admin.browser_thumbnail_rebuild.preparing_missing', 'Preparing browser rebuild for missing thumbnails...')
        : i18n('admin.browser_thumbnail_rebuild.preparing', 'Preparing browser thumbnail rebuild...');
    updateRebuildProgress(progress, 0, 0, aggregate, preparingLabel);

    const initialPass = await runBrowserThumbnailRebuildScope(form, progress, config, sessionId, aggregate, requestedScope, batchIndex, 0);
    batchIndex = initialPass.batchIndex;
    displayTotal = Math.max(displayTotal, initialPass.total);

    // Run a few deterministic repair passes over the current missing-thumbnail
    // inventory. This catches any browser-side transient gap without making the
    // administrator start another rebuild manually. When the administrator asked
    // for missing thumbnails only, every pass stays inside the missing scope.
    for (let repairPass = 1; repairPass <= 3; repairPass += 1) {
        const createdBefore = Number(aggregate.created || 0);
        const repairResult = await runBrowserThumbnailRebuildScope(form, progress, config, sessionId, aggregate, 'missing', batchIndex, repairPass);
        batchIndex = repairResult.batchIndex;
        if (repairResult.total <= 0 || repairResult.sourceItemsProcessed <= 0 || Number(aggregate.created || 0) === createdBefore) {
            break;
        }
    }

    const completeLabel = requestedScope === 'missing'
        ? i18n('admin.browser_thumbnail_rebuild.complete_missing', 'Browser-assisted missing-thumbnail rebuild complete.')
        : i18n('admin.browser_thumbnail_rebuild.complete', 'Browser-assisted thumbnail rebuild complete.');
    updateRebuildProgress(progress, displayTotal, displayTotal, aggregate, completeLabel);
    return aggregate;
}

/**
 * Process one deterministic source scope for the browser rebuild workflow.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @param {HTMLElement} progress Progress root.
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} sessionId Rebuild session id.
 * @param {Record<string, *>} aggregate Aggregate counters.
 * @param {string} scope Source scope, either all or missing.
 * @param {number} startBatchIndex First global batch index.
 * @param {number} passIndex Repair pass number, zero for the full pass.
 * @return {Promise<{batchIndex: number, total: number, sourceItemsProcessed: number} >} Scope result.
 */
async function runBrowserThumbnailRebuildScope(form, progress, config, sessionId, aggregate, scope, startBatchIndex, passIndex) {
    let offset = 0;
    let total = 0;
    let chunkIndex = 0;
    let batchIndex = startBatchIndex;
    let sourceItemsProcessed = 0;
    const mutableScope = scope === 'missing';

    while (true) {
        const sourceZip = await downloadSourceChunk(form, config, sessionId, offset, scope);
        const parsed = await parseZipInWorker(sourceZip);
        const manifest = parsed.manifest || {};
        const items = Array.isArray(manifest.items) ? manifest.items : [];
        const entries = new Map((parsed.entries || []).map((entry) => [String(entry.path || ''), entry.blob]));
        total = Math.max(total, Number(manifest.total || 0));
        aggregate.sourceSkipped += Array.isArray(manifest.skipped) ? manifest.skipped.length : 0;
        aggregate.processed = Math.max(aggregate.processed, Number(manifest.processed || offset));

        updateRebuildProgress(progress, aggregate.processed, total, aggregate, i18n('admin.browser_thumbnail_rebuild.downloaded_chunk', 'Downloaded source chunk {current}.', {current: chunkIndex + 1}));

        if (items.length > 0) {
            const sourceItems = sourceItemsFromManifest(items, entries, config);
            sourceItemsProcessed += sourceItems.length;

            const batcher = createThumbnailRebuildBatcher(config, sessionId, chunkIndex + (passIndex * 10000), batchIndex);
            await processSourceItemsWithWorkerPool(sourceItems, config, async (item, completed, count) => {
                assertPreparedRebuildItemComplete(item, config);
                await batcher.addItem(item);
                updateRebuildProgress(
                    progress,
                    Math.max(aggregate.processed - items.length + completed, 0),
                    total,
                    aggregate,
                    i18n('admin.browser_thumbnail_rebuild.processing_chunk', 'Creating thumbnails in the browser for source chunk {current}: {done}/{total}.', {current: chunkIndex + 1, done: completed, total: count})
                );
            });

            const batches = await batcher.finish();
            for (const batch of batches) {
                updateRebuildProgress(
                    progress,
                    aggregate.processed,
                    total,
                    aggregate,
                    i18n('admin.browser_thumbnail_rebuild.uploading_batch', 'Uploading prepared thumbnail batch {current} of {total} for source chunk {chunk}.', {current: batch.localIndex + 1, total: batches.length, chunk: chunkIndex + 1})
                );
                const result = await uploadPreparedThumbnailBatchWithRetry(form, config, sessionId, batch, batch.batchIndex, batches.length);
                batchIndex = Math.max(batchIndex + 1, Number(batch.batchIndex || 0) + 1);
                mergeRebuildBatchResult(aggregate, result);
            }
        }

        aggregate.processed = Math.max(aggregate.processed, Number(manifest.processed || manifest.next_offset || total || 0));
        updateRebuildProgress(progress, aggregate.processed, total, aggregate, i18n('admin.browser_thumbnail_rebuild.chunk_complete', 'Browser thumbnail source chunk complete.'));
        offset = mutableScope ? 0 : Number(manifest.next_offset || offset || 0);
        chunkIndex += 1;
        if (Boolean(manifest.done) || (!mutableScope && offset >= total) || (mutableScope && items.length === 0)) {
            break;
        }
    }

    return {batchIndex, total, sourceItemsProcessed};
}

/**
 * Read thumbnail rebuild configuration emitted by PHP near the browser rebuild checkbox.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @return {Record<string, *>} Normalized configuration.
 */
function browserThumbnailRebuildConfig(form) {
    const toggle = form.querySelector('[data-browser-thumbnail-rebuild-toggle]');
    let parsed = {};
    if (toggle instanceof HTMLInputElement) {
        try {
            parsed = JSON.parse(toggle.dataset.browserThumbnailRebuildConfig || '{}');
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
    const sourceChunkBytes = clampInteger(parsed.source_chunk_bytes, 512 * 1024 * 1024, 16 * 1024 * 1024, 3 * 1024 * 1024 * 1024);
    const sourceChunkItemCap = clampInteger(parsed.source_chunk_item_cap, 96, 1, 512);
    const formats = Array.isArray(parsed.thumbnail_formats) ? parsed.thumbnail_formats.filter((format) => ['jpg', 'webp'].includes(String(format))) : ['webp'];
    const sizes = Array.isArray(parsed.thumbnail_sizes) ? parsed.thumbnail_sizes.map((size) => Number(size)).filter((size) => Number.isInteger(size) && size > 0) : [300, 600, 800, 960, 1280, 1600];
    return {
        enabled: Boolean(parsed.enabled),
        sourceEndpoint: String(parsed.source_endpoint || ''),
        uploadEndpoint: String(parsed.upload_endpoint || ''),
        workerCount,
        maxWorkers,
        hardCap,
        uploadLimitBytes,
        batchTargetBytes,
        maxItemsPerBatch,
        sourceChunkBytes,
        sourceChunkItemCap,
        thumbnailFormats: formats.length ? formats : ['webp'],
        thumbnailSizes: sizes.length ? sizes : [300, 600, 800, 960, 1280, 1600],
        jpegQuality: clampQuality(parsed.jpeg_quality, 82),
        webpQuality: clampQuality(parsed.webp_quality, 82),
    };
}

/**
 * Normalize one item-specific thumbnail format list from the source manifest.
 *
 * @param {*} formats Candidate format list.
 * @param {Array<string>} fallback Fallback formats from the page-level config.
 * @return {Array<string>} Safe format names for worker payloads.
 */
function normalizeThumbnailFormats(formats, fallback) {
    const source = Array.isArray(formats) ? formats : fallback;
    const normalized = [];
    source.forEach((format) => {
        const value = String(format || '').toLowerCase();
        if (!['jpg', 'webp'].includes(value) || normalized.includes(value)) {
            return;
        }
        normalized.push(value);
    });
    return normalized.length ? normalized : ['webp'];
}

/**
 * Return strict worker input items from a source chunk manifest.
 *
 * A previous version silently filtered manifest rows whose source ZIP entry was
 * not found. That made the rebuild appear successful while complete thumbnail
 * sets were missing for those images. The rebuild must now fail loudly before
 * any upload batch can be acknowledged for an incomplete source chunk.
 *
 * @param {Array<Record<string, *>>} items Manifest source items.
 * @param {Map<string, Blob>} entries Parsed source ZIP entries.
 * @param {Record<string, *>} config Browser configuration.
 * @return {Array<Record<string, *>>} Worker input items.
 */
function sourceItemsFromManifest(items, entries, config) {
    const sourceItems = [];
    const missing = [];
    items.forEach((item) => {
        const imageId = Number(item.image_id || 0);
        const entryPath = String(item.entry_path || '');
        const blob = entries.get(entryPath) || null;
        if (imageId <= 0 || entryPath === '' || !(blob instanceof Blob)) {
            missing.push(`${imageId || 'unknown'}:${entryPath || 'missing-entry-path'}`);
            return;
        }
        sourceItems.push({
            imageId,
            filename: String(item.filename || ''),
            entryPath,
            targetFormats: normalizeThumbnailFormats(item.target_formats, config.thumbnailFormats),
            expectedVariants: Math.max(1, Number(item.expected_variants || 0)),
            blob,
        });
    });
    if (missing.length > 0) {
        throw new Error(i18n('admin.browser_thumbnail_rebuild.source_entry_missing', 'The source ZIP chunk was incomplete. Missing source entries: {items}.', {items: missing.slice(0, 8).join(', ')}));
    }
    return sourceItems;
}

/**
 * Return how many thumbnail variants one source item must produce.
 *
 * @param {Record<string, *>} item Source or prepared item.
 * @param {Record<string, *>} config Browser configuration.
 * @return {number} Required variant count.
 */
function expectedVariantCountForSourceItem(item, config) {
    const formats = normalizeThumbnailFormats(item.targetFormats, config.thumbnailFormats);
    const sizes = Array.isArray(config.thumbnailSizes) ? config.thumbnailSizes : [];
    return Math.max(1, formats.length * sizes.length);
}

/**
 * Assert that a prepared worker result contains every requested variant.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @param {Record<string, *>} config Browser configuration.
 */
function assertPreparedRebuildItemComplete(item, config) {
    const variants = Array.isArray(item.variants) ? item.variants : [];
    const expected = Math.max(Number(item.expectedVariants || 0), expectedVariantCountForSourceItem(item, config));
    if (variants.length < expected) {
        throw new Error(i18n('admin.browser_thumbnail_rebuild.incomplete_worker_item', 'Browser worker returned an incomplete thumbnail set for image {image}.', {image: String(item.imageId || 'unknown')}));
    }
}

/**
 * Verify browser support for the browser rebuild path.
 *
 * @param {Record<string, *>} config Browser configuration.
 * @return {{ok: boolean, reason: string} } Capability result.
 */
function browserThumbnailRebuildCapability(config) {
    if (!config.sourceEndpoint || !config.uploadEndpoint) {
        return {ok: false, reason: 'missing_endpoint'};
    }
    if (!window.Worker || !window.Blob || !window.FormData || !window.TextEncoder || !window.crypto) {
        return {ok: false, reason: 'missing_core_browser_api'};
    }
    if (!('OffscreenCanvas' in window) || !('createImageBitmap' in window)) {
        return {ok: false, reason: 'missing_canvas_worker_api'};
    }
    return {ok: true, reason: 'ok'};
}

/**
 * Generate a per-run session id.
 *
 * @return {string} Session id.
 */
function browserThumbnailRebuildSessionId() {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Download one source ZIP chunk from the server.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} sessionId Rebuild session id.
 * @param {number} offset Source image offset.
 * @param {*} scope Scope value.
 * @return {Promise<Blob>} Source ZIP blob.
 */
async function downloadSourceChunk(form, config, sessionId, offset, scope = 'all') {
    const body = new FormData();
    body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
    body.set('ajax', '1');
    body.set('scope', scope === 'missing' ? 'missing' : 'all');
    body.set('upload_session_id', sessionId);
    body.set('offset', String(offset));
    body.set('source_chunk_bytes', String(config.sourceChunkBytes));
    body.set('source_chunk_item_cap', String(config.sourceChunkItemCap));
    const response = await fetch(String(config.sourceEndpoint), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/zip, application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    if (!response.ok || contentType.includes('application/json') || contentType.includes('text/html') || contentType.includes('text/plain')) {
        const result = await readJsonOrTextResponse(response, i18n('admin.browser_thumbnail_rebuild.source_failed', 'Source chunk download failed.'));
        throw new Error(result.error || i18n('admin.browser_thumbnail_rebuild.source_failed', 'Source chunk download failed.'));
    }
    return response.blob();
}

/**
 * Parse a source ZIP chunk in a worker.
 *
 * @param {Blob} zipBlob Source ZIP blob.
 * @return {Promise<Record<string, *>>} Parsed source archive.
 */
function parseZipInWorker(zipBlob) {
    return workerRoundTrip({action: 'parseZip', zipBlob}, i18n('admin.browser_thumbnail_rebuild.parse_failed', 'Source ZIP parsing failed.')).then((data) => data.parsed || {});
}

/**
 * Run source images through a bounded worker pool.
 *
 * @param {Array<Record<string, *>>} items Source items.
 * @param {Record<string, *>} config Browser configuration.
 * @param {(item: Record<string, *>, completed: number, total: number) => Promise<void>} onItem Prepared item callback.
 * @return {Promise<void>} Result value for the caller.
 */
function processSourceItemsWithWorkerPool(items, config, onItem) {
    const total = items.length;
    if (total === 0) {
        return Promise.resolve();
    }
    const workerCount = Math.max(1, Math.min(Number(config.workerCount || 1), total));
    const taskTimeoutMs = 120000;
    let nextIndex = 0;
    let completed = 0;
    let rejected = false;
    let callbackChain = Promise.resolve();
    const workers = [];
    const activeTimers = new Map();

    return new Promise((resolve, reject) => {
        /**
         * Clear worker timer.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {*} worker Worker value.
         */
        const clearWorkerTimer = (worker) => {
            const timer = activeTimers.get(worker);
            if (timer) {
                window.clearTimeout(timer);
                activeTimers.delete(worker);
            }
        };
        /**
         * Stop workers.
         *
         * Used by browser-side gallery behavior.
         */
        const stopWorkers = () => {
            workers.forEach((worker) => {
                clearWorkerTimer(worker);
                worker.terminate();
            });
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
            nextIndex += 1;
            const item = items[index];
            clearWorkerTimer(worker);
            activeTimers.set(worker, window.setTimeout(() => {
                rejectOnce(new Error(i18n('admin.browser_thumbnail_rebuild.worker_timeout', 'Browser thumbnail rebuild worker timed out while processing image {image}.', {image: String(item.imageId || 'unknown')})));
            }, taskTimeoutMs));
            worker.postMessage({
                action: 'prepareRebuildImage',
                id: index,
                imageId: item.imageId,
                filename: item.filename,
                entryPath: item.entryPath,
                blob: item.blob,
                sizes: config.thumbnailSizes,
                formats: normalizeThumbnailFormats(item.targetFormats, config.thumbnailFormats),
                expectedVariants: expectedVariantCountForSourceItem(item, config),
                jpegQuality: config.jpegQuality,
                webpQuality: config.webpQuality,
            });
        };

        for (let workerIndex = 0; workerIndex < workerCount; workerIndex += 1) {
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
                clearWorkerTimer(worker);
                if (!data.ok) {
                    rejectOnce(new Error(data.error || i18n('admin.browser_thumbnail_rebuild.worker_failed', 'Browser thumbnail rebuild worker failed.')));
                    return;
                }

                // Serialize all callback and batcher work. Worker results can arrive
                // concurrently, but the batcher mutates shared arrays and creates ZIPs.
                // Running these steps one-by-one prevents random complete-image gaps.
                callbackChain = callbackChain.then(async () => {
                    completed += 1;
                    await onItem(data.item, completed, total);
                });
                callbackChain.then(() => {
                    assign(worker);
                }).catch((error) => {
                    rejectOnce(error);
                });
            });
            worker.addEventListener('error', (event) => {
                clearWorkerTimer(worker);
                rejectOnce(new Error(event.message || i18n('admin.browser_thumbnail_rebuild.worker_failed', 'Browser thumbnail rebuild worker failed.')));
            });
            assign(worker);
        }
    });
}

/**
 * Create a memory-only batcher for prepared thumbnail upload archives.
 *
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} sessionId Rebuild session id.
 * @param {number} chunkIndex Source chunk index.
 * @param {number} startBatchIndex First global batch index.
 * @return {Record<string, Function>} Batcher object.
 */
function createThumbnailRebuildBatcher(config, sessionId, chunkIndex, startBatchIndex) {
    const targetBytes = Number(config.batchTargetBytes || 1);
    const maxItemsPerBatch = Math.max(1, Math.min(Number(config.maxItemsPerBatch || 8), 64));
    let currentItems = [];
    let currentEntries = [];
    let currentBytes = 0;
    let localIndex = 0;
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
        const batchIndex = startBatchIndex + batches.length;
        const manifest = {
            version: 1,
            kind: 'thumbnail_rebuild_batch',
            upload_session_id: sessionId,
            chunk_index: chunkIndex,
            batch_index: batchIndex,
            items: currentItems,
        };
        const zipBlob = await createStoreOnlyZipInWorker([
            {path: 'manifest.json', blob: new Blob([JSON.stringify(manifest)], {type: 'application/json'})},
            ...currentEntries,
        ]);
        if (zipBlob.size > targetBytes) {
            throw new Error(i18n('admin.browser_thumbnail_rebuild.batch_too_large', 'A prepared thumbnail ZIP batch is larger than the configured upload limit.'));
        }
        batches.push({blob: zipBlob, batchIndex, localIndex});
        localIndex += 1;
        currentItems = [];
        currentEntries = [];
        currentBytes = 0;
    };

    return {
        addItem: async (item) => {
            const itemEntries = entriesForPreparedRebuildItem(item);
            const itemBytes = itemEntries.reduce((sum, entry) => sum + Number(entry.blob.size || 0) + 256 + entry.path.length, 0) + 1024;
            if (itemBytes > targetBytes) {
                throw new Error(i18n('admin.browser_thumbnail_rebuild.item_too_large', 'One prepared thumbnail package is larger than the configured upload limit.'));
            }
            if (currentItems.length > 0 && (currentBytes + itemBytes > targetBytes || currentItems.length >= maxItemsPerBatch)) {
                await finalizeCurrent();
            }
            currentItems.push(manifestItemForPreparedRebuildItem(item));
            currentEntries.push(...itemEntries);
            currentBytes += itemBytes;
        },
        finish: async () => {
            await finalizeCurrent();
            return batches;
        },
    };
}

/**
 * Return ZIP entries for one prepared thumbnail rebuild item.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {Array<{path: string, blob: Blob} >} ZIP entries.
 */
function entriesForPreparedRebuildItem(item) {
    const entries = [];
    (item.variants || []).forEach((variant) => {
        entries.push({path: variant.path, blob: variant.blob});
    });
    return entries;
}

/**
 * Return the manifest-safe shape for one prepared thumbnail rebuild item.
 *
 * @param {Record<string, *>} item Prepared worker result.
 * @return {Record<string, *>} Manifest item.
 */
function manifestItemForPreparedRebuildItem(item) {
    return {
        image_id: Number(item.imageId || 0),
        filename: String(item.filename || ''),
        entry_path: String(item.entryPath || ''),
        variants: (item.variants || []).map((variant) => ({
            size: variant.size,
            format: variant.format,
            path: variant.path,
            width: variant.width,
            height: variant.height,
        })),
    };
}

/**
 * Upload a prepared thumbnail batch with bounded retry.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} sessionId Rebuild session id.
 * @param {Record<string, *>} batch Prepared batch.
 * @param {number} batchIndex Global batch index.
 * @param {number} totalBatches Total batches inside current source chunk.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function uploadPreparedThumbnailBatchWithRetry(form, config, sessionId, batch, batchIndex, totalBatches) {
    let lastError = null;
    for (let attempt = 1; attempt <= 3; attempt += 1) {
        try {
            return await uploadPreparedThumbnailBatch(form, config, sessionId, batch, batchIndex, totalBatches);
        } catch (error) {
            lastError = error;
            if (attempt < 3) {
                await delay(750 * attempt);
            }
        }
    }
    throw lastError || new Error(i18n('admin.browser_thumbnail_rebuild.batch_failed', 'Prepared thumbnail batch upload failed.'));
}

/**
 * Upload one prepared thumbnail batch.
 *
 * @param {HTMLFormElement} form Thumbnail maintenance form.
 * @param {Record<string, *>} config Browser configuration.
 * @param {string} sessionId Rebuild session id.
 * @param {Record<string, *>} batch Prepared batch.
 * @param {number} batchIndex Global batch index.
 * @param {number} totalBatches Total batches inside current source chunk.
 * @return {Promise<Record<string, *>>} Server response.
 */
async function uploadPreparedThumbnailBatch(form, config, sessionId, batch, batchIndex, totalBatches) {
    const body = new FormData();
    body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
    body.set('ajax', '1');
    body.set('upload_session_id', sessionId);
    body.set('batch_index', String(batchIndex));
    body.set('total_batches', String(totalBatches));
    body.append('zip_batch', batch.blob, `browser-thumbnail-rebuild-${sessionId}-${batchIndex}.zip`);
    const response = await fetch(String(config.uploadEndpoint), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    const result = await readJsonOrTextResponse(response, i18n('admin.browser_thumbnail_rebuild.batch_failed', 'Prepared thumbnail batch upload failed.'));
    if (!response.ok || result.ok === false) {
        throw new Error(result.error || i18n('admin.browser_thumbnail_rebuild.batch_failed', 'Prepared thumbnail batch upload failed.'));
    }
    return result;
}

/**
 * Package entries in a worker so ZIP creation does not run on the main thread.
 *
 * @param {*} entries Entries value.
 * @return {Promise<Blob>} ZIP blob.
 */
function createStoreOnlyZipInWorker(entries) {
    return workerRoundTrip({action: 'zip', entries}, i18n('admin.browser_thumbnail_rebuild.zip_failed', 'Prepared thumbnail ZIP packaging failed.')).then((data) => {
        if (!(data.zipBlob instanceof Blob)) {
            throw new Error(i18n('admin.browser_thumbnail_rebuild.zip_failed', 'Prepared thumbnail ZIP packaging failed.'));
        }
        return data.zipBlob;
    });
}

/**
 * Run one worker request and return its response payload.
 *
 * @param {Record<string, *>} payload Worker payload.
 * @param {string} fallbackMessage Fallback failure message.
 * @return {Promise<Record<string, *>>} Worker response.
 */
function workerRoundTrip(payload, fallbackMessage) {
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
            if (!data.ok) {
                reject(new Error(data.error || fallbackMessage));
                return;
            }
            resolve(data);
        });
        worker.addEventListener('error', (event) => {
            cleanup();
            reject(new Error(event.message || fallbackMessage));
        });
        worker.postMessage(payload);
    });
}

/**
 * Read a JSON response or turn a text/HTML response into a useful error object.
 *
 * @param {Response} response Fetch response.
 * @param {string} fallbackMessage Fallback error message.
 * @return {Promise<Record<string, *>>} Parsed response object.
 */
async function readJsonOrTextResponse(response, fallbackMessage) {
    const responseText = await response.text();
    try {
        return JSON.parse(responseText || '{}');
    } catch (error) {
        const snippet = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
        if (snippet.startsWith('<')) {
            const statusText = response.status ? `HTTP ${response.status}` : 'HTTP error';
            return {ok: false, error: `${fallbackMessage} ${i18n('admin.browser_thumbnail_rebuild.html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')} ${statusText}: ${snippet.slice(0, 120)}`};
        }
        return {ok: false, error: snippet || fallbackMessage};
    }
}

/**
 * Merge one server batch response into an aggregate rebuild result.
 *
 * @param {Record<string, *>} aggregate Aggregate counters.
 * @param {Record<string, *>} result Batch response.
 */
function mergeRebuildBatchResult(aggregate, result) {
    aggregate.created += Number(result.created || 0);
    aggregate.skipped += Number(result.skipped || 0);
    aggregate.failed += Number(result.failed || 0);
    aggregate.errors = Array.from(new Set([...(aggregate.errors || []), ...((result.errors || []).map(String))].filter(Boolean)));
}

/**
 * Create empty counters for one browser rebuild run.
 *
 * @return {Record<string, *>} Aggregate counters.
 */
function emptyRebuildAggregate() {
    return {
        processed: 0,
        created: 0,
        skipped: 0,
        failed: 0,
        sourceSkipped: 0,
        errors: [],
    };
}

/**
 * Update the shared thumbnail progress widget with browser rebuild counters.
 *
 * @param {HTMLElement} progress Progress root.
 * @param {number} processed Processed source images.
 * @param {number} total Total source images.
 * @param {Record<string, *>} aggregate Aggregate counters.
 * @param {string} label Status label.
 */
function updateRebuildProgress(progress, processed, total, aggregate, label) {
    const skipped = Number(aggregate.skipped || 0) + Number(aggregate.sourceSkipped || 0);
    updateThumbnailProgress(progress, processed, total, Number(aggregate.created || 0), skipped, label);
    if (Number(aggregate.failed || 0) > 0 || (aggregate.errors || []).length > 0) {
        const text = progress.querySelector('[data-thumbnail-progress-text]');
        if (text) {
            text.textContent += ` ${Number(aggregate.failed || 0)} failed.`;
        }
    }
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
 * Delay execution for retry backoff.
 *
 * @param {number} milliseconds Delay length.
 * @return {Promise<void>} Delay promise.
 */
function delay(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}
