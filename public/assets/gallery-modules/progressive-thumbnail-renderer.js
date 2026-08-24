/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/progressive-thumbnail-renderer.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Coordinates near-viewport progressive thumbnail sharpening for server-rendered public photo cards.
 *
 * Responsibilities:
 *   - Activate only when permanent data-progressive-thumbnail markup exists
 *   - Observe visible and near-visible thumbnails without starting distant upgrades
 *   - Wait for the small thumbnail to load before scheduling non-critical sharpening work
 *   - Bound larger-image preload/decode work through a two-slot priority queue
 *   - Measure actual rendered card geometry and source aspect ratio while accounting for a capped device pixel ratio
 *   - Reconsider relevant cards after resize without duplicate queue entries or downgrade loops
 *   - Tear down observers, idle work, listeners, and queued jobs when public markup is reinitialized
 *
 * Integration points:
 *   - public-gallery.js imports this module conditionally for anonymous public markup
 *   - gallery.js imports this module conditionally for logged-in public markup
 *   - progressive-thumbnail-upgrade.js owns candidate choice and decode-before-swap mutation
 *   - PHP retains ownership of semantic picture/img markup, links, access checks, and initial loading attributes
 *
 * Lifecycle:
 *   setupProgressiveThumbnailRenderer() first tears down any previous lifecycle, registers current progressive images,
 *   and installs near/visible IntersectionObservers plus mutation tracking. A card becomes eligible only after its small
 *   image has loaded and it intersects the near viewport. Idle scheduling then enters a bounded priority queue. Visible
 *   cards are promoted ahead of near-visible cards. ResizeObserver requeues only currently relevant cards. Teardown
 *   aborts late decode mutations and releases all observers and scheduled callbacks.
 *
 * Invariants:
 *   - PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY is the only concurrency limit and remains bounded
 *   - Far-offscreen cards are not enqueued for larger transfers
 *   - One image cannot occupy duplicate queue entries; active resize requests collapse into one rerun
 *   - Disconnected images are removed from observers and pending queues
 *   - Native loading="lazy" remains untouched
 *   - Lightbox links, voting controls, map buttons, and normal navigation are never intercepted
 *   - The module never reloads or navigates the page
 *
 * Fallback behavior:
 *   IntersectionObserver absence keeps the progressive small thumbnails functional and deliberately skips sharpening
 *   rather than starting all large requests. requestIdleCallback absence uses a short setTimeout fallback. Failed or
 *   aborted upgrades leave the initial small thumbnail visible.
 *
 * Accessibility:
 *   This scheduler does not alter focus, alt text, pointer handling, ARIA, or link semantics. It introduces no required
 *   animation. The visual state classes therefore remain compatible with prefers-reduced-motion without special motion
 *   overrides, and the server-rendered image is usable throughout the lifecycle.
 *
 * No-JavaScript behavior:
 *   No browser setup is required for basic gallery use. PHP already rendered a real small thumbnail and its card link.
 *
 * Performance rationale:
 *   Larger transfers are constrained to visible/near-visible cards, delayed until the small paint is available, and
 *   limited to two concurrent upgrade jobs. requestIdleCallback is preferred so scrolling and initial interaction win
 *   main-thread time, with a bounded timeout preventing indefinitely postponed sharpening.
 *
 * Naming:
 *   Progressive is a permanent architecture term. The Admin interface may add Default/Legacy status wording as a
 *   maturity label; implementation symbols, filenames, data markers, queue state, and tests use permanent terminology.
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
 *   2026-08-24
 */

import {
    progressiveThumbnailRequiredWidth,
    upgradeProgressiveThumbnailImage,
} from './progressive-thumbnail-upgrade.js?v=20260824-aspect-aware-thumbnail-selection';

export const PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY = 2;
const PROGRESSIVE_THUMBNAIL_NEAR_ROOT_MARGIN = '720px 0px';
const PROGRESSIVE_THUMBNAIL_IDLE_TIMEOUT_MS = 1000;
const PROGRESSIVE_THUMBNAIL_IDLE_FALLBACK_MS = 160;

const progressiveThumbnailRendererState = {
    controller: null,
    nearObserver: null,
    visibleObserver: null,
    resizeObserver: null,
    mutationObserver: null,
    queue: null,
    registeredImages: new Set(),
    nearImages: new Set(),
    visibleImages: new Set(),
    waitingForSmallLoad: new Set(),
    idleWork: new Map(),
    diagnostics: {
        upgradeAttempts: 0,
        upgraded: 0,
        noUpgrade: 0,
        failed: 0,
        maxActiveUpgrades: 0,
    },
};

/**
 * Create a bounded, priority-aware deduplicating work queue.
 *
 * The queue intentionally keeps only job references, not an unbounded collection of worker promises. A duplicate job
 * already waiting is promoted when necessary. A duplicate job requested while active becomes one collapsed rerun after
 * the active pass finishes, which is enough to reconsider a resize without parallel downloads for the same image.
 *
 * @template T
 * @param {(item: T) => Promise<unknown>|unknown} worker Worker invoked for one item at a time.
 * @param {number} concurrency Maximum simultaneous worker calls.
 * @return {{enqueue: function(T, string=): boolean, remove: function(T): void, clear: function(): void, close: function(): void, activeCount: function(): number, pendingCount: function(): number}} Queue API.
 */
export function createProgressiveThumbnailUpgradeQueue(worker, concurrency = PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY) {
    const limit = Math.max(1, Math.floor(Number(concurrency) || 1));
    const queued = new Map();
    const active = new Set();
    const rerun = new Map();
    let closed = false;
    let sequence = 0;

    /** Convert semantic priority to a stable sortable rank. */
    const priorityRank = (priority) => priority === 'visible' ? 0 : 1;

    /** Return the next queued item, preferring visible work then insertion order. */
    const takeNext = () => {
        let selectedItem = null;
        let selectedJob = null;
        queued.forEach((job, item) => {
            if (!selectedJob || job.rank < selectedJob.rank || (job.rank === selectedJob.rank && job.sequence < selectedJob.sequence)) {
                selectedItem = item;
                selectedJob = job;
            }
        });
        if (selectedItem !== null) {
            queued.delete(selectedItem);
        }
        return selectedItem;
    };

    /** Fill free worker slots without ever exceeding the configured concurrency limit. */
    const drain = () => {
        if (closed) {
            return;
        }
        while (active.size < limit && queued.size > 0) {
            const item = takeNext();
            if (item === null) {
                return;
            }
            active.add(item);
            Promise.resolve()
                .then(() => worker(item))
                .catch(() => {})
                .finally(() => {
                    active.delete(item);
                    if (!closed && rerun.has(item)) {
                        const priority = rerun.get(item);
                        rerun.delete(item);
                        queued.set(item, {rank: priorityRank(priority), sequence: sequence++});
                    }
                    drain();
                });
        }
    };

    return {
        /**
         * Add or promote one job without creating a duplicate queue entry.
         *
         * @param {T} item Work item used as the deduplication key.
         * @param {string} priority "visible" or "near".
         * @return {boolean} True when queue state changed.
         */
        enqueue(item, priority = 'near') {
            if (closed || item === null || item === undefined) {
                return false;
            }
            const normalizedPriority = priority === 'visible' ? 'visible' : 'near';
            const rank = priorityRank(normalizedPriority);
            if (active.has(item)) {
                const previous = rerun.get(item);
                if (!previous || rank < priorityRank(previous)) {
                    rerun.set(item, normalizedPriority);
                    return true;
                }
                return false;
            }
            if (queued.has(item)) {
                const existing = queued.get(item);
                if (rank < existing.rank) {
                    queued.set(item, {...existing, rank});
                    return true;
                }
                return false;
            }
            queued.set(item, {rank, sequence: sequence++});
            drain();
            return true;
        },

        /** Remove future work for one item; an already active native image load is allowed to finish harmlessly. */
        remove(item) {
            queued.delete(item);
            rerun.delete(item);
        },

        /** Remove all waiting and collapsed-rerun work while leaving current worker slots to settle. */
        clear() {
            queued.clear();
            rerun.clear();
        },

        /** Permanently stop queue intake and discard all waiting work for the current renderer lifecycle. */
        close() {
            closed = true;
            queued.clear();
            rerun.clear();
        },

        /** @return {number} Number of active worker slots. */
        activeCount() {
            return active.size;
        },

        /** @return {number} Number of waiting jobs excluding active work. */
        pendingCount() {
            return queued.size;
        },
    };
}


/**
 * Return an admin-diagnostics-safe snapshot of the current progressive renderer lifecycle.
 *
 * The snapshot contains only aggregate scheduler state and never contains gallery URLs, image identifiers, or
 * access-sensitive metadata. Public pages without the Admin render profile may still call this function harmlessly.
 *
 * @return {{enabled: boolean, concurrencyLimit: number, registered: number, near: number, visible: number, waitingForSmallLoad: number, idleScheduled: number, queuePending: number, queueActive: number, upgradeAttempts: number, upgraded: number, noUpgrade: number, failed: number, maxActiveUpgrades: number}} Aggregate renderer state.
 */
export function progressiveThumbnailRendererDiagnostics() {
    const diagnostics = progressiveThumbnailRendererState.diagnostics;
    return {
        enabled: Boolean(progressiveThumbnailRendererState.controller && !progressiveThumbnailRendererState.controller.signal.aborted),
        concurrencyLimit: PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY,
        registered: progressiveThumbnailRendererState.registeredImages.size,
        near: progressiveThumbnailRendererState.nearImages.size,
        visible: progressiveThumbnailRendererState.visibleImages.size,
        waitingForSmallLoad: progressiveThumbnailRendererState.waitingForSmallLoad.size,
        idleScheduled: progressiveThumbnailRendererState.idleWork.size,
        queuePending: progressiveThumbnailRendererState.queue?.pendingCount() || 0,
        queueActive: progressiveThumbnailRendererState.queue?.activeCount() || 0,
        upgradeAttempts: diagnostics.upgradeAttempts,
        upgraded: diagnostics.upgraded,
        noUpgrade: diagnostics.noUpgrade,
        failed: diagnostics.failed,
        maxActiveUpgrades: diagnostics.maxActiveUpgrades,
    };
}

/** Reset aggregate counters when a new progressive renderer lifecycle starts. */
function resetProgressiveThumbnailRendererDiagnostics() {
    progressiveThumbnailRendererState.diagnostics.upgradeAttempts = 0;
    progressiveThumbnailRendererState.diagnostics.upgraded = 0;
    progressiveThumbnailRendererState.diagnostics.noUpgrade = 0;
    progressiveThumbnailRendererState.diagnostics.failed = 0;
    progressiveThumbnailRendererState.diagnostics.maxActiveUpgrades = 0;
}

/**
 * Cancel one not-yet-enqueued idle callback or timer for an image.
 *
 * @param {HTMLImageElement} image Progressive image whose pending idle activation should be cancelled.
 */
function cancelProgressiveThumbnailIdleWork(image) {
    const pending = progressiveThumbnailRendererState.idleWork.get(image);
    if (!pending) {
        return;
    }
    if (pending.kind === 'idle' && 'cancelIdleCallback' in window) {
        window.cancelIdleCallback(pending.handle);
    } else {
        window.clearTimeout(pending.handle);
    }
    progressiveThumbnailRendererState.idleWork.delete(image);
}

/**
 * Queue relevant sharpening work during browser idle time after the initial small thumbnail can paint.
 *
 * @param {HTMLImageElement} image Progressive image that is currently visible or near-visible.
 * @param {string} priority "visible" or "near" scheduling priority.
 */
function scheduleProgressiveThumbnailIdleWork(image, priority) {
    if (!image.isConnected || progressiveThumbnailRendererState.controller?.signal.aborted) {
        return;
    }

    const queue = progressiveThumbnailRendererState.queue;
    if (!queue) {
        return;
    }

    const existing = progressiveThumbnailRendererState.idleWork.get(image);
    if (existing) {
        if (priority === 'visible' && existing.priority !== 'visible') {
            cancelProgressiveThumbnailIdleWork(image);
        } else {
            return;
        }
    }

    /** Move one eligible image from idle scheduling into the bounded upgrade queue. */
    const enqueueWhenIdle = () => {
        progressiveThumbnailRendererState.idleWork.delete(image);
        if (!image.isConnected || progressiveThumbnailRendererState.controller?.signal.aborted) {
            return;
        }
        if (!progressiveThumbnailRendererState.nearImages.has(image) && !progressiveThumbnailRendererState.visibleImages.has(image)) {
            return;
        }
        queue.enqueue(image, progressiveThumbnailRendererState.visibleImages.has(image) ? 'visible' : priority);
    };

    if ('requestIdleCallback' in window) {
        const handle = window.requestIdleCallback(enqueueWhenIdle, {timeout: PROGRESSIVE_THUMBNAIL_IDLE_TIMEOUT_MS});
        progressiveThumbnailRendererState.idleWork.set(image, {kind: 'idle', handle, priority});
        return;
    }

    const handle = window.setTimeout(enqueueWhenIdle, PROGRESSIVE_THUMBNAIL_IDLE_FALLBACK_MS);
    progressiveThumbnailRendererState.idleWork.set(image, {kind: 'timer', handle, priority});
}

/**
 * Wait for the real small thumbnail to finish loading before any larger candidate is considered.
 *
 * @param {HTMLImageElement} image Progressive image eligible for near-viewport work.
 * @param {string} priority Current intersection priority.
 */
function scheduleProgressiveThumbnailAfterSmallLoad(image, priority) {
    const signal = progressiveThumbnailRendererState.controller?.signal;
    if (!signal || signal.aborted) {
        return;
    }

    if (image.complete && image.naturalWidth > 0) {
        scheduleProgressiveThumbnailIdleWork(image, priority);
        return;
    }

    if (progressiveThumbnailRendererState.waitingForSmallLoad.has(image)) {
        return;
    }

    progressiveThumbnailRendererState.waitingForSmallLoad.add(image);
    image.addEventListener('load', () => {
        progressiveThumbnailRendererState.waitingForSmallLoad.delete(image);
        if (image.isConnected) {
            scheduleProgressiveThumbnailIdleWork(
                image,
                progressiveThumbnailRendererState.visibleImages.has(image) ? 'visible' : priority
            );
        }
    }, {once: true, signal});
    image.addEventListener('error', () => {
        progressiveThumbnailRendererState.waitingForSmallLoad.delete(image);
    }, {once: true, signal});
}

/**
 * Measure and run one queued progressive upgrade while the image remains relevant to the viewport.
 *
 * @param {HTMLImageElement} image Image selected by the bounded queue.
 * @return {Promise<void>} Settles after the candidate upgrade attempt.
 */
async function runProgressiveThumbnailUpgrade(image) {
    const signal = progressiveThumbnailRendererState.controller?.signal;
    if (!signal || signal.aborted || !image.isConnected) {
        return;
    }
    if (!progressiveThumbnailRendererState.nearImages.has(image) && !progressiveThumbnailRendererState.visibleImages.has(image)) {
        return;
    }

    const card = image.closest('.image-stage') || image.closest('.image-card') || image;
    // $imageRect stores the actual CSS box used by object-fit: cover, including mobile square-card overrides.
    const imageRect = image.getBoundingClientRect();
    // $cardRect stores a safe geometry fallback when the image box is temporarily unavailable during layout.
    const cardRect = card.getBoundingClientRect();
    const renderedWidth = imageRect.width || cardRect.width;
    const renderedHeight = imageRect.height || cardRect.height;
    // $sourceWidth and $sourceHeight come from server-rendered orientation-aware intrinsic dimensions, not the small derivative.
    const sourceWidth = Number.parseInt(image.getAttribute('width') || '0', 10) || 0;
    const sourceHeight = Number.parseInt(image.getAttribute('height') || '0', 10) || 0;
    const requiredWidth = progressiveThumbnailRequiredWidth(
        renderedWidth,
        window.devicePixelRatio || 1,
        undefined,
        renderedHeight,
        sourceWidth,
        sourceHeight
    );
    if (requiredWidth <= 0) {
        return;
    }

    const diagnostics = progressiveThumbnailRendererState.diagnostics;
    diagnostics.upgradeAttempts++;
    diagnostics.maxActiveUpgrades = Math.max(
        diagnostics.maxActiveUpgrades,
        progressiveThumbnailRendererState.queue?.activeCount() || 0
    );

    const result = await upgradeProgressiveThumbnailImage(image, {
        requiredWidth,
        measuredSizes: `${Math.max(1, Math.ceil(renderedWidth))}px`,
        signal,
    });
    if (result.upgraded) {
        diagnostics.upgraded++;
    } else if (image.closest('picture')?.classList.contains('is-progressive-thumbnail-failed')) {
        diagnostics.failed++;
    } else {
        diagnostics.noUpgrade++;
    }
}

/**
 * Register one server-rendered progressive image with all relevant observers exactly once.
 *
 * @param {HTMLImageElement} image Progressive thumbnail image.
 */
function registerProgressiveThumbnail(image) {
    if (!image.matches('img[data-progressive-thumbnail]') || progressiveThumbnailRendererState.registeredImages.has(image)) {
        return;
    }
    progressiveThumbnailRendererState.registeredImages.add(image);
    progressiveThumbnailRendererState.nearObserver?.observe(image);
    progressiveThumbnailRendererState.visibleObserver?.observe(image);
}

/**
 * Remove a disconnected progressive image from future work and observer state.
 *
 * @param {HTMLImageElement} image Progressive thumbnail image being removed or replaced.
 */
function unregisterProgressiveThumbnail(image) {
    cancelProgressiveThumbnailIdleWork(image);
    progressiveThumbnailRendererState.queue?.remove(image);
    progressiveThumbnailRendererState.nearObserver?.unobserve(image);
    progressiveThumbnailRendererState.visibleObserver?.unobserve(image);
    progressiveThumbnailRendererState.resizeObserver?.unobserve(image);
    progressiveThumbnailRendererState.nearImages.delete(image);
    progressiveThumbnailRendererState.visibleImages.delete(image);
    progressiveThumbnailRendererState.waitingForSmallLoad.delete(image);
    progressiveThumbnailRendererState.registeredImages.delete(image);
}

/**
 * Release observers, scheduled work, and late decode mutations from the current progressive renderer lifecycle.
 */
export function teardownProgressiveThumbnailRenderer() {
    progressiveThumbnailRendererState.controller?.abort();
    progressiveThumbnailRendererState.controller = null;
    progressiveThumbnailRendererState.nearObserver?.disconnect();
    progressiveThumbnailRendererState.nearObserver = null;
    progressiveThumbnailRendererState.visibleObserver?.disconnect();
    progressiveThumbnailRendererState.visibleObserver = null;
    progressiveThumbnailRendererState.resizeObserver?.disconnect();
    progressiveThumbnailRendererState.resizeObserver = null;
    progressiveThumbnailRendererState.mutationObserver?.disconnect();
    progressiveThumbnailRendererState.mutationObserver = null;
    progressiveThumbnailRendererState.queue?.close();
    progressiveThumbnailRendererState.queue = null;
    Array.from(progressiveThumbnailRendererState.idleWork.keys()).forEach(cancelProgressiveThumbnailIdleWork);
    progressiveThumbnailRendererState.idleWork.clear();
    progressiveThumbnailRendererState.registeredImages.clear();
    progressiveThumbnailRendererState.nearImages.clear();
    progressiveThumbnailRendererState.visibleImages.clear();
    progressiveThumbnailRendererState.waitingForSmallLoad.clear();
}

/**
 * Initialize bounded progressive sharpening for all matching current and dynamically inserted public photo cards.
 *
 * IntersectionObserver is intentionally required for larger activation. If it is unavailable, returning leaves the
 * server-rendered small thumbnails fully usable and avoids the much worse fallback of upgrading every distant image.
 */
export function setupProgressiveThumbnailRenderer() {
    teardownProgressiveThumbnailRenderer();
    resetProgressiveThumbnailRendererDiagnostics();

    const initialImages = Array.from(document.querySelectorAll('img[data-progressive-thumbnail]'));
    if (!initialImages.length || !('IntersectionObserver' in window)) {
        return;
    }

    const controller = new AbortController();
    progressiveThumbnailRendererState.controller = controller;
    progressiveThumbnailRendererState.queue = createProgressiveThumbnailUpgradeQueue(
        runProgressiveThumbnailUpgrade,
        PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY
    );

    progressiveThumbnailRendererState.resizeObserver = 'ResizeObserver' in window
        ? new ResizeObserver((entries) => {
            entries.forEach((entry) => {
                const image = entry.target;
                if (!image.isConnected) {
                    unregisterProgressiveThumbnail(image);
                    return;
                }
                if (progressiveThumbnailRendererState.nearImages.has(image) || progressiveThumbnailRendererState.visibleImages.has(image)) {
                    scheduleProgressiveThumbnailAfterSmallLoad(
                        image,
                        progressiveThumbnailRendererState.visibleImages.has(image) ? 'visible' : 'near'
                    );
                }
            });
        })
        : null;

    progressiveThumbnailRendererState.nearObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            const image = entry.target;
            if (!image.isConnected) {
                unregisterProgressiveThumbnail(image);
                return;
            }
            if (entry.isIntersecting) {
                progressiveThumbnailRendererState.nearImages.add(image);
                progressiveThumbnailRendererState.resizeObserver?.observe(image);
                scheduleProgressiveThumbnailAfterSmallLoad(
                    image,
                    progressiveThumbnailRendererState.visibleImages.has(image) ? 'visible' : 'near'
                );
                return;
            }
            progressiveThumbnailRendererState.nearImages.delete(image);
            if (!progressiveThumbnailRendererState.visibleImages.has(image)) {
                cancelProgressiveThumbnailIdleWork(image);
                progressiveThumbnailRendererState.queue?.remove(image);
                progressiveThumbnailRendererState.resizeObserver?.unobserve(image);
            }
        });
    }, {rootMargin: PROGRESSIVE_THUMBNAIL_NEAR_ROOT_MARGIN, threshold: 0.01});

    progressiveThumbnailRendererState.visibleObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            const image = entry.target;
            if (!image.isConnected) {
                unregisterProgressiveThumbnail(image);
                return;
            }
            if (entry.isIntersecting) {
                progressiveThumbnailRendererState.visibleImages.add(image);
                scheduleProgressiveThumbnailAfterSmallLoad(image, 'visible');
                return;
            }
            progressiveThumbnailRendererState.visibleImages.delete(image);
        });
    }, {threshold: 0.01});

    initialImages.forEach(registerProgressiveThumbnail);

    progressiveThumbnailRendererState.mutationObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }
                if (node.matches('img[data-progressive-thumbnail]')) {
                    registerProgressiveThumbnail(node);
                }
                node.querySelectorAll?.('img[data-progressive-thumbnail]').forEach(registerProgressiveThumbnail);
            });
            mutation.removedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }
                if (node.matches('img[data-progressive-thumbnail]')) {
                    unregisterProgressiveThumbnail(node);
                }
                node.querySelectorAll?.('img[data-progressive-thumbnail]').forEach(unregisterProgressiveThumbnail);
            });
        });
    });
    progressiveThumbnailRendererState.mutationObserver.observe(document.body, {childList: true, subtree: true});
}
