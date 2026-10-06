/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-resource-lifecycle.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Own decoded lightbox image reuse and detached media resource lifetimes.
 *
 * Responsibilities:
 *   - Bound decoded URL cache retention and idle cleanup
 *   - Cancel detached image and tracked Fetch work with listener cleanup
 *   - Expose copied resource snapshots without exposing mutable collections
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Last Updated:
 *   2026-10-06
 */

/**
 * Bound the reusable desktop working set to limit decoded pixel memory.
 * Type: number. Units: reusable image entries.
 * Scope: one lightbox resource owner on desktop.
 * Consumers: limit().
 * Rationale: decoded originals can retain substantial pixel memory, so cache growth stays bounded.
 */
const desktopCacheLimit = 12;
/**
 * Reduce retained decoded images for touch devices with tighter memory budgets.
 * Type: number. Units: reusable image entries.
 * Scope: one lightbox resource owner on mobile/touch devices.
 * Consumers: limit().
 * Rationale: touch devices commonly have less available memory for decoded pixels.
 */
const mobileCacheLimit = 6;
/**
 * Release settled reusable images after one minute without a cache hit.
 * Type: number. Units: milliseconds.
 * Scope: idle decoded-image entries and sustained hidden-page cleanup.
 * Consumers: sweep() and setHidden().
 * Rationale: recent navigation can reuse decoded pixels while stale pages release them promptly.
 */
const defaultIdleMs = 60000;

/**
 * Clock and timers used by this resource owner.
 * @typedef {Object} LightboxResourceScheduler
 * @property {function():number} [now] Return deterministic monotonic time in milliseconds.
 * @property {Performance} [performance] Browser monotonic clock provider.
 * @property {function(function():void,number):number} setTimeout Schedule one one-shot release timer.
 * @property {function(number):void} clearTimeout Cancel one owner timer.
 */

/**
 * One local cache or detached-load diagnostic observation.
 * @typedef {Object} LightboxResourceDiagnosticEvent
 * @property {string} name Bounded lifecycle event name.
 * @property {string} [src] Exact source URL supplied by the coordinator.
 * @property {string} [reason] Bounded policy reason for a cache event.
 * @property {string} [result] Cache state associated with a foreground lookup.
 * @property {string} [priority] Request priority hint, when supplied.
 * @property {number} [width] Decoded image width for a completed load.
 * @property {number} [height] Decoded image height for a completed load.
 */

/**
 * Configuration and injectable browser dependencies for one resource owner.
 * @typedef {Object} LightboxResourceOptions
 * @property {AbortSignal|null} [signal] Terminal setup signal; abort disposes this owner.
 * @property {LightboxResourceScheduler} [scheduler] Timer and monotonic clock provider.
 * @property {boolean} [mobile] Apply the smaller touch-device cache bound.
 * @property {number} [idleMs] Settled-entry idle lifetime in milliseconds.
 * @property {function():HTMLImageElement} [createImage] Create one detached image node.
 * @property {function(HTMLImageElement):Promise<void>} [decodeImage] Decode one loaded image node.
 * @property {function(string,RequestInit):Promise<Response>} [fetchImpl] Fetch implementation used for tracked loads.
 * @property {function(LightboxResourceDiagnosticEvent):void} [onCacheEvent] Observe bounded cache lifecycle events.
 * @property {function(LightboxResourceDiagnosticEvent):void} [onLoadEvent] Observe detached-load lifecycle events.
 */

/**
 * Priority, cancellation, and diagnostics controls for reusable image lookup.
 * @typedef {Object} LightboxDecodedLoadOptions
 * @property {string} [priority] Browser fetch priority for a cache miss.
 * @property {function(string,string):void} [onCacheResult] Observe a foreground hit or miss.
 * @property {string} [cacheResult] Attach the bounded result label to the decoded node.
 */

/**
 * Cancellation and priority controls for one detached image request.
 * @typedef {Object} LightboxFreshLoadOptions
 * @property {string} [priority] Browser fetch priority hint.
 * @property {AbortSignal|null} [signal] Cancellation signal owned by this request.
 * @property {string} [cacheResult] Attach the bounded cache result to the decoded node.
 */

/**
 * Controls for one tracked byte-transfer and exact-URL decode.
 * @typedef {Object} LightboxTrackedLoadOptions
 * @property {string} [priority] Fetch and image priority hint.
 * @property {AbortSignal|null} [signal] Active quality request cancellation signal.
 * @property {function(number,number):void} [onProgress] Observe loaded and total response bytes.
 * @property {string} [cacheResult] Attach the bounded cache result to the decoded node.
 */

/**
 * Controls for warming one supplied URL through the nearby preload queue.
 * @typedef {Object} LightboxPreloadOptions
 * @property {string} [priority] Browser fetch priority hint.
 * @property {AbortSignal|null} [signal] Nearby preload queue cancellation signal.
 * @property {string} [reason] Bounded insertion reason for resource diagnostics.
 */

/**
 * Controls for the bounded cache sweep.
 * @typedef {Object} LightboxSweepOptions
 * @property {boolean} [releaseAllSettled] Release every settled cache entry.
 */

/**
 * One shared reusable decoded-image cache entry.
 * @typedef {Object} LightboxResourceCacheEntry
 * @property {Promise<HTMLImageElement|null>} promise Shared decoded image or null failure sentinel.
 * @property {number} lastUsedAt Monotonic timestamp for idle and LRU policy.
 * @property {boolean} settled Whether loading and decoding has completed.
 * @property {number} epoch Owner epoch captured when this load began.
 */

/**
 * One cancellable Fetch or detached-image operation owned by this module.
 * @typedef {Object} LightboxResourceOperation
 * @property {AbortController} controller Signal passed to browser work.
 * @property {function():void} cancel Abort this operation's browser work.
 * @property {function():void} cleanup Remove caller listeners and active ownership.
 * @property {'detached-image'|'tracked-request'} kind Read-only operation category.
 * @property {boolean} fetching Whether a tracked response body is still being consumed.
 */

/**
 * Read-only counts and cache policy published to diagnostic consumers.
 * @typedef {Object} LightboxResourceSnapshot
 * @property {number} entries Total reusable URL entries.
 * @property {number} pending Unsettled reusable URL entries.
 * @property {number} settled Settled reusable URL entries.
 * @property {number} limit Device-specific reusable cache bound.
 * @property {number} idleMs Settled-entry idle lifetime in milliseconds.
 * @property {number} activeOperations Total active browser resource operations.
 * @property {number} activeDetachedImageLoads Active detached Image nodes.
 * @property {number} activeTrackedLoads Active quality Fetch/decode operations.
 * @property {number} activeFetchTransfers Active response-body transfers.
 * @property {boolean} hiddenCleanupScheduled Whether the single hidden release timer exists.
 * @property {number} epoch Cache ownership generation.
 * @property {boolean} disposed Whether terminal disposal has occurred.
 */

/**
 * Public setup-scoped resource lifecycle API.
 * @typedef {Object} LightboxResourceOwner
 * @property {function(string,LightboxPreloadOptions=):Promise<HTMLImageElement|null>} preload Warm a reusable source and resolve failures to null.
 * @property {function(string,LightboxDecodedLoadOptions=):Promise<HTMLImageElement>} loadDecoded Load through the shared cache and retry null entries.
 * @property {function(string,LightboxFreshLoadOptions=):Promise<HTMLImageElement>} loadFresh Load one detached image without cache reuse.
 * @property {function(string,LightboxTrackedLoadOptions=):Promise<HTMLImageElement>} loadTracked Track Fetch byte progress and decode the exact source URL.
 * @property {function(LightboxSweepOptions=):void} sweep Expire idle settled entries and enforce the active cache bound.
 * @property {function(boolean,boolean):void} setHidden Apply hidden-page cache release policy.
 * @property {function(string):('cached'|'pending'|null)} cacheStatus Read one URL's cache state.
 * @property {function(string):boolean} hasCached Check whether one URL has a reusable entry.
 * @property {function(HTMLImageElement):string} cacheResultFor Read an image's bounded cache-result label.
 * @property {function():LightboxResourceSnapshot} snapshot Copy resource counts and policy state.
 * @property {function():void} clear Clear and cancel while leaving this setup reusable.
 * @property {function():void} dispose Permanently retire this owner and its listeners.
 */

/**
 * Create one setup-scoped owner for decoded-image cache and detached resource lifetimes.
 *
 * Sources are accepted exactly as supplied by the lightbox coordinator. This owner
 * does not discover neighboring media, create URLs, or select quality candidates.
 *
 * @param {LightboxResourceOptions} options Resource dependencies and policy for one lightbox setup.
 * @return {LightboxResourceOwner} Frozen resource owner API with load, cache, visibility, and teardown methods.
 */
export function createLightboxResourceLifecycle(options = {}) {
    const {
        signal = null,
        scheduler = globalThis,
        mobile = false,
        idleMs = defaultIdleMs,
        createImage = () => new Image(),
        decodeImage = (image) => typeof image.decode === 'function' ? image.decode() : Promise.resolve(),
        fetchImpl = (...args) => globalThis.fetch(...args),
        onCacheEvent = () => {},
        onLoadEvent = () => {},
    } = options;
    const cache = new Map();
    const cacheResults = new WeakMap();
    const activeOperations = new Set();
    let epoch = 0;
    let disposed = false;
    let hiddenCleanupTimer = null;
    let documentHidden = false;
    let viewerOpen = false;
    const effectiveIdleMs = Number.isFinite(idleMs) && idleMs >= 0 ? idleMs : defaultIdleMs;

    /**
     * Return the scheduler's monotonic time when available.
     * @return {number} Current resource-lifecycle time in milliseconds.
     */
    function now() {
        return typeof scheduler.now === 'function'
            ? scheduler.now()
            : (typeof scheduler.performance?.now === 'function' ? scheduler.performance.now() : Date.now());
    }

    /**
     * Invoke an optional diagnostic observer without allowing it to interrupt cleanup.
     * @param {Function} observer Optional callback supplied by the coordinator.
     * @param {...unknown} args Values passed to the optional callback.
     * @return {void} Isolates callback failures from resource ownership.
     */
    function report(observer, ...args) {
        try {
            observer(...args);
        } catch (error) {
            // Resource ownership must remain independent from optional diagnostics.
        }
    }

    /**
     * Create a consistent cancellation error for detached browser work.
     * @return {Error} Error named AbortError.
     */
    function abortError() {
        const error = new Error('Lightbox resource load cancelled.');
        error.name = 'AbortError';
        return error;
    }

    /**
     * Return the cache bound selected for this setup.
     * @return {number} Maximum reusable entries for this device class.
     */
    function limit() {
        return mobile ? mobileCacheLimit : desktopCacheLimit;
    }

    /**
     * Delete one settled cache entry and publish its bounded lifecycle event.
     * @param {string} src Exact caller-supplied URL key.
     * @param {string} reason Bounded eviction reason.
     * @return {boolean} True when a settled entry was removed.
     */
    function evict(src, reason) {
        const entry = cache.get(src);
        if (!entry?.settled) {
            return false;
        }
        cache.delete(src);
        report(onCacheEvent, {name: 'evicted', src, reason});
        return true;
    }

    /**
     * Remove expired settled entries and then enforce the active LRU count bound.
     * @param {LightboxSweepOptions} options Optional idle-sweep controls.
     * @param {boolean} options.releaseAllSettled Whether to release every settled entry.
     * @return {void} Updates only reusable cache ownership.
     */
    function sweep(options = {}) {
        const releaseAllSettled = options.releaseAllSettled === true;
        const timestamp = now();
        for (const [src, entry] of cache) {
            if (entry.settled && (releaseAllSettled || timestamp - entry.lastUsedAt >= effectiveIdleMs)) {
                evict(src, releaseAllSettled ? 'hidden' : 'idle');
            }
        }
        trim();
    }

    /**
     * Trim the oldest settled entries while leaving every in-flight promise reusable.
     * @return {void} Enforces the device-specific settled-entry bound.
     */
    function trim() {
        while (cache.size > limit()) {
            let oldestSettled = null;
            for (const [src, entry] of cache) {
                if (entry.settled) {
                    oldestSettled = src;
                    break;
                }
            }
            if (oldestSettled === null) {
                return;
            }
            evict(oldestSettled, 'limit');
        }
    }

    /**
     * Touch one cache entry and move it to the most-recently-used position.
     * @param {string} src Exact caller-supplied URL key.
     * @return {object|null} Reusable entry or null when the URL is not cached.
     */
    function useEntry(src) {
        sweep();
        const entry = cache.get(src);
        if (!entry) {
            return null;
        }
        entry.lastUsedAt = now();
        cache.delete(src);
        cache.set(src, entry);
        return entry;
    }

    /**
     * Create an operation signal linked to its caller and registered for owner cleanup.
     * @param {AbortSignal|null} externalSignal Optional caller cancellation signal.
     * @param {'detached-image'|'tracked-request'} kind Resource operation kind for read-only counts.
     * @return {LightboxResourceOperation} Operation controller, cancellation, and cleanup ownership.
     */
    function createOperation(externalSignal, kind) {
        const controller = new AbortController();
        const operation = {controller, cancel: () => controller.abort(), kind, fetching: kind === 'tracked-request'};
        const abortFromCaller = () => operation.cancel();
        if (externalSignal && typeof externalSignal.addEventListener === 'function') {
            if (externalSignal.aborted) {
                operation.cancel();
            } else {
                externalSignal.addEventListener('abort', abortFromCaller, {once: true});
            }
        }
        operation.cleanup = () => {
            externalSignal?.removeEventListener?.('abort', abortFromCaller);
            activeOperations.delete(operation);
        };
        activeOperations.add(operation);
        return operation;
    }

    /**
     * Store one reusable promise and mark settlement only while its epoch still owns the key.
     * @param {string} src Exact caller-supplied URL key.
     * @param {Promise<HTMLImageElement|null>} promise Promise resolving to an image or null sentinel.
     * @param {number} insertionEpoch Owner epoch captured when work began.
     * @param {string} reason Bounded insertion reason for diagnostics.
     * @return {LightboxResourceCacheEntry} The cache entry inserted for this promise.
     */
    function remember(src, promise, insertionEpoch, reason) {
        const entry = {promise, lastUsedAt: now(), settled: false, epoch: insertionEpoch};
        // Synchronous diagnostics may dispose or clear the owner while a load starts.
        if (disposed || insertionEpoch !== epoch) {
            return entry;
        }
        cache.set(src, entry);
        report(onCacheEvent, {name: 'inserted', src, reason});
        promise.then(
            () => {
                if (cache.get(src) !== entry || epoch !== insertionEpoch) {
                    return;
                }
                entry.lastUsedAt = now();
                entry.settled = true;
                report(onCacheEvent, {name: 'settled', src, reason});
                sweep();
            },
            () => {
                if (cache.get(src) !== entry || epoch !== insertionEpoch) {
                    return;
                }
                entry.lastUsedAt = now();
                entry.settled = true;
                report(onCacheEvent, {name: 'settled', src, reason});
                sweep();
            }
        );
        trim();
        return entry;
    }

    /**
     * Load and decode one detached image while owning all its listeners and cancellation.
     * @param {string} src Exact authorized URL supplied by the coordinator.
     * @param {LightboxFreshLoadOptions} options Decode priority, optional signal, and cache result label.
     * @return {Promise<HTMLImageElement>} Decoded detached image node.
     */
    function loadFresh(src, options = {}) {
        if (disposed) {
            return Promise.reject(abortError());
        }
        if (!src) {
            return Promise.reject(new Error('Missing lightbox image source.'));
        }
        const operation = createOperation(options.signal, 'detached-image');
        return new Promise((resolve, reject) => {
            let image;
            let settled = false;
            const cleanup = () => {
                if (image) {
                    image.onload = null;
                    image.onerror = null;
                }
                operation.controller.signal.removeEventListener('abort', cancel);
                operation.cleanup();
            };
            const finishError = (error) => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanup();
                report(onLoadEvent, {name: error?.name === 'AbortError' ? 'aborted' : 'failed', src, priority: options.priority || ''});
                reject(error);
            };
            const cancel = () => {
                if (settled) {
                    return;
                }
                settled = true;
                image?.removeAttribute?.('src');
                cleanup();
                report(onLoadEvent, {name: 'aborted', src, priority: options.priority || ''});
                reject(abortError());
            };
            if (operation.controller.signal.aborted) {
                cancel();
                return;
            }
            operation.controller.signal.addEventListener('abort', cancel, {once: true});
            try {
                image = createImage();
                image.decoding = 'async';
                image.loading = 'eager';
                if ('fetchPriority' in image && options.priority) {
                    image.fetchPriority = options.priority;
                }
                image.onload = () => {
                    Promise.resolve().then(() => decodeImage(image)).catch(() => undefined).then(() => {
                        if (settled) {
                            return;
                        }
                        if (operation.controller.signal.aborted) {
                            cancel();
                            return;
                        }
                        settled = true;
                        cleanup();
                        if (options.cacheResult) {
                            cacheResults.set(image, options.cacheResult);
                        }
                        report(onLoadEvent, {name: 'completed', src, priority: options.priority || '', width: image.naturalWidth || 0, height: image.naturalHeight || 0});
                        resolve(image);
                    });
                };
                image.onerror = () => finishError(new Error('Lightbox image load failed.'));
                image.src = src;
                report(onLoadEvent, {name: 'started', src, priority: options.priority || ''});
            } catch (error) {
                finishError(error);
            }
        });
    }

    /**
     * Load one caller-supplied URL with Fetch byte progress, then decode that exact URL.
     * @param {string} src Exact authorized URL supplied by the quality coordinator.
     * @param {LightboxTrackedLoadOptions} options Transfer priority, cancellation, progress, and result label.
     * @return {Promise<HTMLImageElement>} Decoded image after the response body is consumed.
     */
    function loadTracked(src, options = {}) {
        if (disposed) {
            return Promise.reject(abortError());
        }
        if (!src) {
            return Promise.reject(new Error('Missing lightbox image source.'));
        }
        const operation = createOperation(options.signal, 'tracked-request');
        let reader = null;
        const cancel = () => {
            operation.controller.abort();
            if (reader) {
                try {
                    Promise.resolve(reader.cancel()).catch(() => undefined);
                } catch (error) {
                    // A broken stream cancel hook must not prevent operation cleanup.
                }
            }
        };
        operation.cancel = cancel;
        const reportProgress = (loaded, total) => report(options.onProgress || (() => {}), loaded, total);
        const transfer = async () => {
            if (operation.controller.signal.aborted) {
                throw abortError();
            }
            const response = await fetchImpl(src, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'default',
                signal: operation.controller.signal,
                ...(options.priority ? {priority: options.priority} : {}),
            });
            if (!response.ok) {
                throw new Error('Lightbox image load failed.');
            }
            const declaredTotal = Math.max(0, Number.parseInt(response.headers?.get?.('Content-Length') || '0', 10) || 0);
            let loaded = 0;
            reportProgress(0, declaredTotal);
            if (!response.body || typeof response.body.getReader !== 'function') {
                const body = await response.blob();
                loaded = Math.max(0, Number(body?.size) || 0);
                reportProgress(loaded, declaredTotal || loaded);
            } else {
                reader = response.body.getReader();
                try {
                    while (true) {
                        if (operation.controller.signal.aborted) {
                            throw abortError();
                        }
                        const result = await reader.read();
                        if (result.done) {
                            break;
                        }
                        loaded += result.value?.byteLength || 0;
                        reportProgress(loaded, declaredTotal);
                    }
                } finally {
                    try {
                        reader.releaseLock();
                    } catch (error) {
                        // A cancelled stream may already have released its reader.
                    }
                    reader = null;
                }
                reportProgress(loaded, declaredTotal || loaded);
            }
            if (operation.controller.signal.aborted) {
                throw abortError();
            }
            operation.fetching = false;
            return loadFresh(src, {priority: options.priority || 'high', signal: operation.controller.signal, cacheResult: options.cacheResult || 'miss'});
        };
        return transfer().then((image) => image).finally(operation.cleanup);
    }

    /**
     * Remove one hidden-page cache cleanup timer when visibility or lifecycle changes.
     * @return {void} Clears the active one-shot timer if present.
     */
    function clearHiddenTimer() {
        if (hiddenCleanupTimer === null) {
            return;
        }
        scheduler.clearTimeout(hiddenCleanupTimer);
        hiddenCleanupTimer = null;
    }

    /**
     * Load a reusable cache miss and retain only a null-settling copy for later retry.
     * @param {string} src Exact caller-supplied URL key.
     * @param {LightboxDecodedLoadOptions} options Foreground request options.
     * @param {string} reason Bounded insertion reason.
     * @return {Promise<HTMLImageElement>} Foreground image request promise.
     */
    function startDecodedMiss(src, options, reason) {
        const insertionEpoch = epoch;
        const foreground = loadFresh(src, {
            priority: options.priority || 'high',
            cacheResult: options.cacheResult || 'miss',
        });
        const reusable = foreground.catch(() => null);
        remember(src, reusable, insertionEpoch, reason);
        return foreground;
    }

    /**
     * Decode one exact source URL through the reusable cache, retrying null entries for foreground use.
     * @param {string} src Exact authorized URL supplied by the coordinator.
     * @param {LightboxDecodedLoadOptions} options Decode priority and cache-result observer. Shared foreground cache work is not navigation-cancelled.
     * @return {Promise<HTMLImageElement>} Decoded image or a rejected load.
     */
    function loadDecoded(src, options = {}) {
        if (!src) {
            return Promise.reject(new Error('Missing lightbox image source.'));
        }
        if (disposed) {
            return Promise.reject(abortError());
        }
        const entry = useEntry(src);
        if (!entry) {
            report(options.onCacheResult, 'miss', src);
            report(onCacheEvent, {name: 'miss', src});
            return startDecodedMiss(src, options, 'load-fresh');
        }
        report(onCacheEvent, {name: 'lookup', src, result: entry.settled ? 'ready' : 'pending'});
        return entry.promise.then((image) => {
            if (image) {
                cacheResults.set(image, 'hit');
                report(options.onCacheResult, 'hit', src);
                return image;
            }
            if (epoch !== entry.epoch || disposed) {
                throw abortError();
            }
            const replacement = cache.get(src);
            if (replacement && replacement !== entry) {
                return replacement.promise;
            }
            report(options.onCacheResult, 'miss', src);
            report(onCacheEvent, {name: 'miss', src});
            return startDecodedMiss(src, options, 'load-cache-retry');
        });
    }

    /**
     * Warm one supplied source through the reusable cache, converting load failures to null.
     * @param {string} src Exact authorized URL supplied by the preload coordinator.
     * @param {LightboxPreloadOptions} options Priority, queue signal, and bounded diagnostic reason.
     * @return {Promise<HTMLImageElement|null>} Shared decoded promise or a null failure sentinel.
     */
    function preload(src, options = {}) {
        if (!src || disposed) {
            return Promise.resolve(null);
        }
        const entry = useEntry(src);
        if (entry) {
            return entry.promise;
        }
        const insertionEpoch = epoch;
        const reusable = loadFresh(src, {priority: options.priority || 'low', signal: options.signal || null})
            .catch(() => null);
        remember(src, reusable, insertionEpoch, options.reason || 'preload');
        if (options.signal) {
            reusable.then(() => {
                if (options.signal.aborted && cache.get(src)?.promise === reusable) {
                    cache.delete(src);
                }
            });
        }
        return reusable;
    }

    /**
     * Update hidden-page state and schedule one bounded release of settled entries.
     * @param {boolean} hidden Whether the page is hidden.
     * @param {boolean} isViewerOpen Whether the lightbox is currently open.
     * @return {void} Cancels or creates the single idle-age release timer.
     */
    function setHidden(hidden, isViewerOpen) {
        documentHidden = Boolean(hidden);
        viewerOpen = Boolean(isViewerOpen);
        clearHiddenTimer();
        if (!documentHidden) {
            sweep();
            return;
        }
        if (!viewerOpen || disposed) {
            return;
        }
        const scheduledEpoch = epoch;
        const timer = scheduler.setTimeout(() => {
            // A canceled callback cannot release a reopened cache or retire its replacement timer.
            if (hiddenCleanupTimer !== timer || epoch !== scheduledEpoch) {
                return;
            }
            hiddenCleanupTimer = null;
            if (documentHidden && viewerOpen && !disposed) {
                sweep({releaseAllSettled: true});
            }
        }, effectiveIdleMs);
        hiddenCleanupTimer = timer;
    }

    /**
     * Return a non-mutating cache state for one exact URL key.
     * @param {string} src Exact URL to inspect.
     * @return {'cached'|'pending'|null} Current reusable cache state.
     */
    function cacheStatus(src) {
        const entry = cache.get(src);
        return entry ? (entry.settled ? 'cached' : 'pending') : null;
    }

    /**
     * Return whether one exact URL currently has a reusable cache entry.
     * @param {string} src Exact URL to inspect.
     * @return {boolean} Whether a cache entry currently owns the URL.
     */
    function hasCached(src) {
        return cache.has(src);
    }

    /**
     * Return the bounded cache result attached to one decoded image node.
     * @param {HTMLImageElement} image Decoded image node returned by this owner.
     * @return {string} Cache result label or unknown when none is attached.
     */
    function cacheResultFor(image) {
        return cacheResults.get(image) || 'unknown';
    }

    /**
     * Return a copied snapshot of cache and active-operation counts.
     * @return {LightboxResourceSnapshot} Frozen read-only resource counts and policy values.
     */
    function snapshot() {
        let pending = 0;
        let settled = 0;
        for (const entry of cache.values()) {
            if (entry.settled) {
                settled += 1;
            } else {
                pending += 1;
            }
        }
        let activeDetachedImageLoads = 0;
        let activeTrackedLoads = 0;
        let activeFetchTransfers = 0;
        for (const operation of activeOperations) {
            if (operation.kind === 'detached-image') {
                activeDetachedImageLoads += 1;
            } else if (operation.kind === 'tracked-request') {
                activeTrackedLoads += 1;
                activeFetchTransfers += operation.fetching ? 1 : 0;
            }
        }
        return Object.freeze({
            entries: cache.size,
            pending,
            settled,
            limit: limit(),
            idleMs: effectiveIdleMs,
            activeOperations: activeOperations.size,
            activeDetachedImageLoads,
            activeTrackedLoads,
            activeFetchTransfers,
            hiddenCleanupScheduled: hiddenCleanupTimer !== null,
            epoch,
            disposed,
        });
    }

    /**
     * Clear reusable entries and cancel current detached work while leaving setup reusable.
     * @return {void} Advances the epoch so late work cannot regain cache ownership.
     */
    function clear() {
        if (disposed) {
            return;
        }
        epoch += 1;
        cache.clear();
        clearHiddenTimer();
        for (const operation of Array.from(activeOperations)) {
            try {
                operation.cancel();
            } catch (error) {
                // Teardown continues even if a browser cancellation hook throws.
            }
        }
    }

    /**
     * Permanently dispose this owner and release its component-signal listener.
     * @return {void} Performs terminal idempotent cancellation and cleanup.
     */
    function dispose() {
        if (disposed) {
            return;
        }
        disposed = true;
        epoch += 1;
        cache.clear();
        clearHiddenTimer();
        for (const operation of Array.from(activeOperations)) {
            try {
                operation.cancel();
            } catch (error) {
                // Terminal cleanup must remain idempotent.
            }
        }
        signal?.removeEventListener?.('abort', dispose);
    }

    signal?.addEventListener?.('abort', dispose, {once: true});
    if (signal?.aborted) {
        dispose();
    }

    return Object.freeze({
        preload,
        loadDecoded,
        loadFresh,
        loadTracked,
        sweep,
        setHidden,
        cacheStatus,
        hasCached,
        cacheResultFor,
        snapshot,
        clear,
        dispose,
    });
}
