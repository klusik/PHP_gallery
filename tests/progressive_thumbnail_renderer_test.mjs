/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/progressive_thumbnail_renderer_test.mjs
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies browser-independent progressive thumbnail candidate and scheduler logic under Node.js.
 *
 * Responsibilities:
 *   - Cover candidate parsing and smallest-adequate selection
 *   - Cover rendered-width and capped device-pixel-ratio calculations
 *   - Cover queue deduplication, visible-priority promotion, and concurrency bounds
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
 *   2026-08-09
 */

import {
    parseProgressiveThumbnailCandidates,
    progressiveThumbnailRequiredWidth,
    selectProgressiveThumbnailCandidate,
} from '../public/assets/gallery-modules/progressive-thumbnail-upgrade.js';
import {
    createProgressiveThumbnailUpgradeQueue,
    PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY,
} from '../public/assets/gallery-modules/progressive-thumbnail-renderer.js';
import {
    formatPublicThumbnailDiagnosticBytes,
    parsePublicThumbnailDiagnosticSrcset,
} from '../public/assets/gallery-modules/public-thumbnail-render-diagnostics.js';

/** Throw when a JavaScript renderer expectation fails. */
function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

/** Wait for queued promise continuations and short test workers to settle. */
function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

const parsed = parseProgressiveThumbnailCandidates('/t-800.webp 800w, /t-300.webp 300w, /t-600.webp 600w');
assert(parsed.length === 3, 'candidate parser should keep three valid width candidates');
assert(parsed[0].width === 300 && parsed[1].width === 600 && parsed[2].width === 800, 'candidate parser should sort widths ascending');
assert(parseProgressiveThumbnailCandidates('/invalid.webp').length === 0, 'candidate parser should ignore entries without width descriptors');

const diagnosticParsed = parsePublicThumbnailDiagnosticSrcset('/t-300.jpg 300w, /t-800.jpg 800w');
assert(diagnosticParsed.length === 2 && diagnosticParsed[1].width === 800, 'diagnostic srcset parser should preserve width descriptors for Resource Timing matching');
assert(formatPublicThumbnailDiagnosticBytes(1536) === '1.5 KiB', 'diagnostic byte formatter should produce copy-friendly binary units');

assert(progressiveThumbnailRequiredWidth(320, 1) === 320, 'required width should match CSS width at DPR 1');
assert(progressiveThumbnailRequiredWidth(320, 1.5) === 480, 'required width should account for fractional DPR');
assert(progressiveThumbnailRequiredWidth(320, 3) === 640, 'required width should cap DPR at the documented safe limit');
assert(progressiveThumbnailRequiredWidth(0, 2) === 0, 'invalid rendered width should not request an upgrade');

let selected = selectProgressiveThumbnailCandidate('/t-600.webp 600w, /t-800.webp 800w, /t-960.webp 960w', 700, 300);
assert(selected?.width === 800, 'selection should choose the smallest adequate candidate');
selected = selectProgressiveThumbnailCandidate('/t-600.webp 600w, /t-800.webp 800w', 1200, 300);
assert(selected?.width === 800, 'selection should use the largest available candidate when none reaches the requirement');
selected = selectProgressiveThumbnailCandidate('/t-600.webp 600w, /t-800.webp 800w', 600, 800);
assert(selected === null, 'selection should never downgrade or repeat an already adequate active candidate');

// Keep one worker active so pending visible work can overtake pending near-visible work.
const executionOrder = [];
let releaseBlocker;
const blockerPromise = new Promise((resolve) => { releaseBlocker = resolve; });
const priorityQueue = createProgressiveThumbnailUpgradeQueue(async (item) => {
    executionOrder.push(item);
    if (item === 'blocker') {
        await blockerPromise;
    }
}, 1);
priorityQueue.enqueue('blocker', 'near');
priorityQueue.enqueue('near-a', 'near');
priorityQueue.enqueue('near-a', 'near');
priorityQueue.enqueue('visible-b', 'visible');
assert(priorityQueue.pendingCount() === 2, 'duplicate queued work should not create a second entry');
releaseBlocker();
await delay(20);
assert(executionOrder.join(',') === 'blocker,visible-b,near-a', 'visible queued work should run before merely near-visible work');
priorityQueue.close();

let activeWorkers = 0;
let maximumActiveWorkers = 0;
let completedWorkers = 0;
const concurrencyQueue = createProgressiveThumbnailUpgradeQueue(async () => {
    activeWorkers += 1;
    maximumActiveWorkers = Math.max(maximumActiveWorkers, activeWorkers);
    await delay(12);
    activeWorkers -= 1;
    completedWorkers += 1;
}, PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY);
for (let index = 0; index < 7; index += 1) {
    concurrencyQueue.enqueue(`item-${index}`, index < 2 ? 'visible' : 'near');
}
await delay(80);
assert(completedWorkers === 7, 'bounded queue should eventually process every retained work item');
assert(maximumActiveWorkers <= PROGRESSIVE_THUMBNAIL_UPGRADE_CONCURRENCY, 'bounded queue must never exceed its exported concurrency constant');
assert(maximumActiveWorkers === 2, 'default progressive queue should use both documented worker slots');
concurrencyQueue.close();

console.log('Progressive thumbnail renderer JavaScript tests passed.');
