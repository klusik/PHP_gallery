/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_preload_lifecycle_test.mjs
 * Module Type: Test Script
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

/** Runtime contracts for the production nearby-preview lifecycle and its viewer seam. */
import assert from 'node:assert/strict';
import {getEventListeners} from 'node:events';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import vm from 'node:vm';
import {createLightboxPreloadLifecycle} from '../public/assets/gallery-modules/lightbox-preload-lifecycle.js';

/** Controlled idle/timeout scheduling, including callbacks dispatched before cancellation. */
function schedulerFixture(idle = true) {
    let sequence = 0;
    const pending = new Map();
    const cancelled = [];
    const schedule = (callback, delay) => {
        const handle = sequence++;
        pending.set(handle, {callback, delay});
        return handle;
    };
    const cancel = (kind, handle) => {
        cancelled.push(kind);
        pending.delete(handle);
    };
    return {
        pending, cancelled,
        ...(idle ? {
            requestIdleCallback: schedule,
            cancelIdleCallback: handle => cancel('idle', handle),
        } : {}),
        setTimeout: schedule,
        clearTimeout: handle => cancel('timeout', handle),
        run() {
            const [handle, item] = pending.entries().next().value;
            pending.delete(handle);
            item.callback();
        },
        nextCallback() { return pending.values().next().value.callback; },
    };
}

async function settle() {
    for (let index = 0; index < 8; index += 1) await Promise.resolve();
}

function fixture({idle = true, limit = 2, settleOnAbort = true, preload, onError} = {}) {
    const owner = new AbortController();
    const scheduler = schedulerFixture(idle);
    const calls = [];
    const policy = {limit};
    const queue = createLightboxPreloadLifecycle({
        signal: owner.signal, scheduler, concurrency: () => policy.limit, onError,
        preload: preload || ((src, {signal}, reason) => new Promise((resolve, reject) => {
            const call = {src, signal, reason, resolve: finish(resolve), reject: finish(reject)};
            function finish(callback) {
                return value => {
                    signal.removeEventListener('abort', onAbort);
                    callback(value);
                };
            }
            function onAbort() { call.resolve(null); }
            if (settleOnAbort) signal.addEventListener('abort', onAbort, {once: true});
            calls.push(call);
        })),
    });
    return {owner, scheduler, calls, policy, queue};
}

for (const idle of [true, false]) {
    test(`FIFO, deduplication, bounded concurrency, and ${idle ? 'idle' : 'timeout'} scheduling`, async () => {
        const f = fixture({idle});
        assert.equal(f.scheduler.pending.size, 0, 'initialization must not schedule work');
        assert.equal(getEventListeners(f.owner.signal, 'abort').length, 1);
        for (const src of ['a', 'b', 'a', 'c', 'd']) f.queue.enqueue(src, 'adjacent-preview');
        assert.equal(f.queue.snapshot().queued, 4);
        assert.equal(f.queue.snapshot().queuedSources, 4);
        assert.equal(f.scheduler.pending.size, 1);
        assert.deepEqual(f.scheduler.pending.values().next().value.delay, idle ? {timeout: 350} : 80);
        f.scheduler.run();
        assert.deepEqual(f.calls.map(call => call.src), ['a', 'b']);
        assert.equal(f.queue.snapshot().active, 2);
        f.calls[0].resolve({});
        f.calls[1].resolve({});
        await settle();
        assert.equal(f.scheduler.pending.size, 1, 'settlements must coalesce scheduling');
        f.scheduler.run();
        assert.deepEqual(f.calls.map(call => call.src), ['a', 'b', 'c', 'd']);
        assert.ok(f.calls.every(call => call.reason === 'adjacent-preview'));
        f.queue.reset();
        await settle();
        assert.equal(f.queue.snapshot().active, 0);
        assert.equal(f.scheduler.pending.size, 0);
        f.queue.enqueue('e');
        f.queue.reset();
        assert.equal(f.scheduler.cancelled.at(-1), idle ? 'idle' : 'timeout');
        f.queue.dispose();
        assert.equal(getEventListeners(f.owner.signal, 'abort').length, 0);
    });
}

test('soft reset preserves running previews, drops queued neighbors, and uses one generation authority', async () => {
    const f = fixture({limit: 1});
    f.queue.enqueue('running');
    f.queue.enqueue('old-neighbor');
    f.scheduler.run();
    const signal = f.queue.signal;
    const oldGeneration = f.queue.generation;
    f.queue.reset({abortActive: false});
    assert.equal(f.queue.signal, signal);
    assert.equal(signal.aborted, false);
    assert.equal(f.queue.generation, oldGeneration + 1);
    f.queue.enqueue('new-neighbor', 'stale', oldGeneration);
    f.queue.enqueue('new-neighbor', 'fresh', f.queue.generation);
    f.scheduler.run();
    assert.equal(f.calls.length, 1, 'old running requests still consume a slot');
    f.calls[0].resolve({});
    await settle();
    f.scheduler.run();
    assert.deepEqual(f.calls.map(call => call.src), ['running', 'new-neighbor']);
    assert.equal(f.calls[1].reason, 'fresh');
    f.queue.dispose();
    await settle();
});

test('hard reset cancels active and immediate preview signals; late settlement cannot exceed the limit', async () => {
    const f = fixture({limit: 1, settleOnAbort: false});
    const immediatePreviewSignal = f.queue.signal;
    f.queue.enqueue('old');
    f.scheduler.run();
    f.queue.reset();
    assert.equal(immediatePreviewSignal.aborted, true);
    assert.notEqual(f.queue.signal, immediatePreviewSignal);
    assert.equal(f.queue.signal.aborted, false);
    f.queue.enqueue('reopened');
    f.scheduler.run();
    assert.equal(f.calls.length, 1);
    f.calls[0].resolve(null);
    await settle();
    f.scheduler.run();
    assert.equal(f.calls[1].src, 'reopened');
    assert.equal(f.queue.snapshot().active, 1);
    f.calls[1].resolve({});
    await settle();
    assert.equal(f.queue.snapshot().active, 0);
    f.queue.dispose();
});

test('cancelled callbacks cannot drain a new generation or clear its scheduled handle', async () => {
    const f = fixture();
    f.queue.enqueue('old');
    const lateDrain = f.scheduler.nextCallback();
    f.queue.reset();
    f.queue.enqueue('new');
    lateDrain();
    assert.equal(f.calls.length, 0);
    assert.equal(f.queue.snapshot().scheduled, true);
    assert.equal(f.scheduler.pending.size, 1);
    f.scheduler.run();
    assert.equal(f.calls[0].src, 'new');
    f.queue.dispose();
    await settle();
});

test('sync throws, throwing diagnostics, and rejected promises do not strand slots or neighbors', async () => {
    const started = [];
    const errors = [];
    const f = fixture({limit: 1,
        preload(src) {
            started.push(src);
            if (src === 'throw') throw new Error('decode setup');
            if (src === 'reject') return Promise.reject(new Error('decode failed'));
            return Promise.resolve(null);
        },
        onError(error, src) { errors.push(src); throw new Error('diagnostics failed'); },
    });
    for (const src of ['throw', 'reject', 'success']) f.queue.enqueue(src);
    f.scheduler.run();
    assert.deepEqual(started, ['throw', 'reject']);
    await settle();
    f.scheduler.run();
    await settle();
    assert.deepEqual(started, ['throw', 'reject', 'success']);
    assert.deepEqual(errors, ['throw']);
    assert.equal(f.queue.snapshot().active, 0);
    assert.equal(f.scheduler.pending.size, 0);
    f.queue.dispose();
});

test('connection concurrency is read at drain time', async () => {
    const f = fixture();
    for (const src of ['a', 'b', 'c']) f.queue.enqueue(src);
    f.policy.limit = 1;
    f.scheduler.run();
    assert.equal(f.calls.length, 1);
    f.policy.limit = 2;
    f.calls[0].resolve({});
    await settle();
    f.scheduler.run();
    assert.equal(f.calls.length, 3);
    f.queue.dispose();
    await settle();
});

test('owner abort and explicit disposal are terminal and idempotent', async () => {
    const f = fixture();
    f.queue.enqueue('active');
    f.scheduler.run();
    f.queue.enqueue('queued');
    const lateDrain = f.scheduler.nextCallback();
    f.owner.abort();
    const retiredGeneration = f.queue.generation;
    f.queue.dispose();
    f.queue.reset();
    f.queue.enqueue('must-not-start');
    lateDrain();
    await settle();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].signal.aborted, true);
    assert.equal(f.queue.generation, retiredGeneration);
    assert.deepEqual(f.queue.snapshot(), {queued: 0, queuedSources: 0, active: 0,
        generation: retiredGeneration, scheduled: false, disposed: true});
    assert.equal(getEventListeners(f.owner.signal, 'abort').length, 0);
});

test('already-aborted setup cannot register work or retain an owner listener', () => {
    const owner = new AbortController();
    owner.abort();
    const scheduler = schedulerFixture();
    const queue = createLightboxPreloadLifecycle({signal: owner.signal, scheduler,
        concurrency: () => 2, preload: () => assert.fail('retired setup started work')});
    queue.enqueue('a');
    assert.equal(queue.signal.aborted, true);
    assert.equal(queue.snapshot().disposed, true);
    assert.equal(scheduler.pending.size, 0);
    assert.equal(getEventListeners(owner.signal, 'abort').length, 0);
});

test('repeated close/reopen and fragment replacement leave no scheduler or owner listeners behind', async () => {
    for (let index = 0; index < 30; index += 1) {
        const old = fixture({settleOnAbort: false});
        old.queue.enqueue('before-close');
        old.scheduler.run();
        old.queue.reset();
        old.queue.enqueue('after-reopen');
        const lateDrain = old.scheduler.nextCallback();
        old.owner.abort();
        const replacement = fixture();
        replacement.queue.enqueue('replacement-fragment');
        old.calls[0].resolve(null);
        lateDrain();
        await settle();
        assert.equal(old.queue.snapshot().active, 0);
        assert.equal(old.scheduler.pending.size, 0);
        assert.equal(getEventListeners(old.owner.signal, 'abort').length, 0);
        assert.equal(old.calls.length, 1);
        assert.equal(replacement.queue.snapshot().queued, 1);
        replacement.scheduler.run();
        assert.equal(replacement.calls[0].src, 'replacement-fragment');
        replacement.queue.dispose();
        await settle();
        assert.equal(replacement.scheduler.pending.size, 0);
        assert.equal(getEventListeners(replacement.owner.signal, 'abort').length, 0);
    }
});

const viewerSource = readFileSync(new URL('../public/assets/gallery-modules/lightbox.js', import.meta.url), 'utf8');
function productionFunction(name) {
    const match = viewerSource.match(new RegExp(`^    function ${name}\\([^]*?^    }`, 'm'));
    assert.ok(match, `Missing production viewer seam: ${name}`);
    return match[0];
}

for (const renderer of ['progressive', 'responsive']) {
    test(`${renderer} card seam preserves cache hits, preview selection, cancellation, and mobile policy`, async () => {
        const owner = new AbortController();
        const scheduler = schedulerFixture();
        const cache = new Map();
        const calls = [];
        const card = {dataset: {previewSrc: '/authorized/preview', fullSrc: '/authorized/original'}};
        const context = vm.createContext({
            createLightboxPreloadLifecycle, controller: owner, window: scheduler,
            navigator: {connection: {effectiveType: '4g', saveData: false}}, isMobileTouchDevice: false,
            document: {body: {dataset: {publicThumbnailRenderingMode: renderer}}},
            decodedLightboxImages: cache, preloadedSources: new Set(), galleryDevModeEnabled: false,
            devMarkSource() {}, devLog() {}, shortenDevUrl: src => src,
            preloadDecodedLightboxImage(src, options = {}) {
                calls.push({src, signal: options.signal});
                if (!cache.has(src)) cache.set(src, Promise.resolve({}));
                return cache.get(src);
            },
            card,
        });
        const creation = viewerSource.match(/^    const lightboxPreloads = createLightboxPreloadLifecycle\(\{[^]*?^    \}\);/m);
        assert.ok(creation, 'viewer must create the production lifecycle owner');
        const functions = ['queueDecodedLightboxPreload', 'resetLightboxPreloadQueue', 'preloadCardLightboxImages',
            'lightboxPreloadConcurrency', 'currentLightboxConnection', 'shouldLimitLightboxPreloading'];
        vm.runInContext(functions.map(productionFunction).join('\n') + '\n' + creation[0]
            + '\nglobalThis.queue = lightboxPreloads;', context);
        vm.runInContext("preloadCardLightboxImages(card, false, {queued: true});", context);
        assert.equal(calls.length, 0);
        scheduler.run();
        await settle();
        assert.deepEqual(calls.map(call => call.src), ['/authorized/preview']);
        vm.runInContext("preloadCardLightboxImages(card, false, {queued: true});", context);
        assert.equal(calls.length, 2, 'cached preview keeps its immediate reuse path');
        assert.equal(scheduler.pending.size, 0);
        vm.runInContext("preloadCardLightboxImages({dataset: {fullSrc: '/authorized/other-original'}}, false, {queued: true});", context);
        assert.equal(calls.length, 2, 'hidden metadata cards must not fall back to neighboring originals');
        vm.runInContext('resetLightboxPreloadQueue({abortActive: false});', context);
        assert.equal(calls[0].signal.aborted, false);
        assert.equal(context.preloadedSources.size, 0);
        vm.runInContext('preloadCardLightboxImages(card, false);', context);
        assert.equal(calls[2].signal, context.queue.signal, 'immediate warming shares cancellation');
        vm.runInContext('resetLightboxPreloadQueue();', context);
        assert.equal(calls[2].signal.aborted, true);
        assert.equal(vm.runInContext('lightboxPreloadConcurrency()', context), 2);
        for (const settings of ["isMobileTouchDevice = true;", "isMobileTouchDevice = false; navigator.connection.effectiveType = '3g';",
            "navigator.connection.effectiveType = '4g'; navigator.connection.saveData = true;"]) {
            vm.runInContext(settings, context);
            assert.equal(vm.runInContext('lightboxPreloadConcurrency()', context), 1);
        }
        owner.abort();
        assert.equal(context.queue.snapshot().disposed, true);
        assert.equal(getEventListeners(owner.signal, 'abort').length, 0);
    });
}
