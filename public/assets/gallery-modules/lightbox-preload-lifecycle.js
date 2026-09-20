/**
 * Project: PHP Gallery
 * Purpose: Own the nearby-preview queue for one lightbox setup instance.
 * Responsibilities:
 *   - Bound queue generations, concurrency, scheduling and cancellation.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-preload-lifecycle.js
 * Module Type: Browser Module
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Last Updated:
 *   2026-09-20
 */

/**
 * Own the nearby-preview queue for one lightbox setup instance.
 *
 * The viewer supplies authorized sources, decoding, and connection policy. This
 * module owns only queue generations, concurrency, scheduling, and cancellation;
 * it never selects media, changes the DOM, or owns foreground navigation tokens.
 */
export function createLightboxPreloadLifecycle({signal, preload, concurrency, onError = () => {}, scheduler = globalThis}) {
    // lightboxPreloadQueue holds nearby preview work so opening a photo does not start all downloads at once.
    const lightboxPreloadQueue = [];
    // lightboxQueuedSources prevents duplicate queued work while still allowing cached image reuse.
    const lightboxQueuedSources = new Set();
    // lightboxPreloadGeneration invalidates stale queued work after fast next/previous navigation.
    let lightboxPreloadGeneration = 0;
    // activeLightboxPreloads tracks how many background image decodes are currently active.
    let activeLightboxPreloads = 0;
    // lightboxPreloadAbortController owns active nearby preview preloads for the current navigation generation.
    let lightboxPreloadAbortController = new AbortController();
    // lightboxPreloadDrainHandle stores the pending queue drain callback handle.
    let lightboxPreloadDrainHandle = null;
    // lightboxPreloadDrainUsesIdleCallback tracks which browser timer API owns the drain handle.
    let lightboxPreloadDrainUsesIdleCallback = false;
    let disposed = false;

    /** Schedule a low-priority drain of the nearby-image preload queue. */
    function scheduleLightboxPreloadDrain() {
        if (lightboxPreloadDrainHandle !== null || disposed) {
            return;
        }
        const generation = lightboxPreloadGeneration;
        /** Drain the preload queue when the selected idle mechanism fires. */
        const drain = () => {
            // A callback already dispatched before cancellation must not drain a reopened queue.
            if (disposed || generation !== lightboxPreloadGeneration) {
                return;
            }
            lightboxPreloadDrainHandle = null;
            lightboxPreloadDrainUsesIdleCallback = false;
            drainLightboxPreloadQueue();
        };
        if (typeof scheduler.requestIdleCallback === 'function') {
            lightboxPreloadDrainUsesIdleCallback = true;
            lightboxPreloadDrainHandle = scheduler.requestIdleCallback(drain, {timeout: 350});
            return;
        }
        lightboxPreloadDrainUsesIdleCallback = false;
        lightboxPreloadDrainHandle = scheduler.setTimeout(drain, 80);
    }

    /** Cancel queued nearby-image preload work that has not started yet. */
    function resetLightboxPreloadQueue(options = {}) {
        if (disposed) {
            return;
        }
        const abortActive = options.abortActive !== false;
        lightboxPreloadGeneration += 1;
        lightboxPreloadQueue.length = 0;
        lightboxQueuedSources.clear();
        if (lightboxPreloadDrainHandle !== null) {
            if (lightboxPreloadDrainUsesIdleCallback && typeof scheduler.cancelIdleCallback === 'function') {
                scheduler.cancelIdleCallback(lightboxPreloadDrainHandle);
            } else {
                scheduler.clearTimeout(lightboxPreloadDrainHandle);
            }
            lightboxPreloadDrainHandle = null;
            lightboxPreloadDrainUsesIdleCallback = false;
        }
        if (abortActive) {
            lightboxPreloadAbortController.abort();
            lightboxPreloadAbortController = new AbortController();
        }
    }

    /** Add one low-priority nearby-image preload to the queue. */
    function queueDecodedLightboxPreload(src, reason, generation = lightboxPreloadGeneration) {
        if (!src || disposed || generation !== lightboxPreloadGeneration || lightboxQueuedSources.has(src)) {
            return;
        }
        lightboxQueuedSources.add(src);
        lightboxPreloadQueue.push({src, reason, generation});
        scheduleLightboxPreloadDrain();
    }

    /** Start queued nearby-image preloads within the current concurrency limit. */
    function drainLightboxPreloadQueue() {
        const limit = concurrency();
        while (!disposed && activeLightboxPreloads < limit && lightboxPreloadQueue.length > 0) {
            const item = lightboxPreloadQueue.shift();
            lightboxQueuedSources.delete(item.src);
            if (item.generation !== lightboxPreloadGeneration) {
                continue;
            }
            activeLightboxPreloads += 1;
            let preloadPromise = null;
            try {
                preloadPromise = preload(item.src, {signal: lightboxPreloadAbortController.signal}, item.reason || 'queued-preview');
            } catch (error) {
                activeLightboxPreloads = Math.max(0, activeLightboxPreloads - 1);
                reportError(error, item.src);
                continue;
            }
            // Both fulfillment and rejection release exactly one slot. Rejection
            // is consumed here because background warming has no foreground result.
            Promise.resolve(preloadPromise).then(releaseSlot, releaseSlot);
        }
    }

    /** Release one settled preload slot and schedule remaining work for a live owner. */
    function releaseSlot() {
        activeLightboxPreloads = Math.max(0, activeLightboxPreloads - 1);
        if (!disposed && lightboxPreloadQueue.length > 0) {
            scheduleLightboxPreloadDrain();
        }
    }

    /** Report a synchronous preload failure without letting diagnostics interrupt the queue. */
    function reportError(error, src) {
        try {
            onError(error, src);
        } catch (diagnosticError) {
            // Optional diagnostics must not strand the remaining queue.
        }
    }

    /** Permanently retire this setup instance; closing the viewer uses reset instead. */
    function dispose() {
        if (disposed) {
            return;
        }
        resetLightboxPreloadQueue({abortActive: false});
        disposed = true;
        signal.removeEventListener('abort', dispose);
        lightboxPreloadAbortController.abort();
    }

    signal.addEventListener('abort', dispose, {once: true});
    if (signal.aborted) {
        dispose();
    }

    return Object.freeze({
        enqueue: queueDecodedLightboxPreload,
        reset: resetLightboxPreloadQueue,
        dispose,
        get generation() { return lightboxPreloadGeneration; },
        // Immediate current-preview warming shares cancellation, but not queue slots.
        get signal() { return lightboxPreloadAbortController.signal; },
        snapshot: () => ({
            queued: lightboxPreloadQueue.length,
            queuedSources: lightboxQueuedSources.size,
            active: activeLightboxPreloads,
            generation: lightboxPreloadGeneration,
            scheduled: lightboxPreloadDrainHandle !== null,
            disposed,
        }),
    });
}
