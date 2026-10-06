/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_resource_lifecycle_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercise decoded-cache and detached-resource ownership deterministically.
 *
 * Responsibilities:
 *   - Protect cache reuse, bounds, idle release, and retry behavior
 *   - Protect detached Image and tracked Fetch cancellation cleanup
 *   - Verify optional diagnostics cannot interrupt resource settlement
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

import assert from 'node:assert/strict';
import {test} from 'node:test';
import {createLightboxResourceLifecycle} from '../public/assets/gallery-modules/lightbox-resource-lifecycle.js';

/**
 * One manually scheduled callback and its fake-clock deadline.
 * @typedef {Object} FakeTimer
 * @property {function():void} callback Timer callback to dispatch.
 * @property {number} delay Requested delay in milliseconds.
 * @property {number} due Fake-clock deadline in milliseconds.
 */

/**
 * Deterministic timer and monotonic-time interface for resource tests.
 * @typedef {Object} FakeScheduler
 * @property {Map<number,FakeTimer>} timers Pending one-shot timers.
 * @property {function():number} now Current fake monotonic time.
 * @property {function(function():void,number):number} setTimeout Schedule one timer.
 * @property {function(number):void} clearTimeout Cancel one timer.
 * @property {function(number):void} advance Advance time and dispatch due timers.
 */

/**
 * Controllable detached image used by the resource owner fixture.
 * @typedef {Object} FakeImage
 * @property {function():void|null} onload Load-completion callback owned by production code.
 * @property {function():void|null} onerror Load-failure callback owned by production code.
 * @property {number} naturalWidth Decoded fixture width.
 * @property {number} naturalHeight Decoded fixture height.
 * @property {boolean} removed Whether cancellation detached its source.
 * @property {string} source Exact assigned source URL.
 * @property {string} src Readable assigned source URL.
 * @property {function(string):void} removeAttribute Record removal of the source attribute.
 * @property {string} src The exact URL currently assigned to the image.
 * @property {function():void} finish Dispatch image load completion.
 * @property {function():void} fail Dispatch image load failure.
 */

/**
 * Fake image collection and production factory callback.
 * @typedef {Object} FakeImages
 * @property {Array<FakeImage>} images Created controllable image nodes.
 * @property {Array<string>} urls Exact source URLs assigned by production code.
 * @property {function():FakeImage} createImage Create one fake detached image.
 */

/**
 * Mutable stream cleanup observations used by one fake response.
 * @typedef {Object} ReaderState
 * @property {boolean} [cancelled] Whether cancellation reached the response reader.
 * @property {boolean} [released] Whether the reader lock was released.
 */

/**
 * One diagnostic event recorded by a test resource owner.
 * @typedef {Object} ResourceDiagnosticEvent
 * @property {string} [type] Fixture event category.
 * @property {string} [name] Resource lifecycle event name.
 * @property {string} [src] Exact test source URL.
 * @property {string} [reason] Cache lifecycle reason.
 */

/**
 * Controllable response body reader for tracked-fetch tests.
 * @typedef {Object} FakeReader
 * @property {function():Promise<FakeReadResult>} read Return the next byte chunk.
 * @property {function():Promise<void>} cancel Cancel a pending read.
 * @property {function():void} releaseLock Release the stream reader lock.
 */

/**
 * One response-body read result.
 * @typedef {Object} FakeReadResult
 * @property {boolean} done Whether the stream has completed.
 * @property {Uint8Array} [value] Bytes returned for one stream chunk.
 */

/**
 * Header lookup surface consumed by the resource owner.
 * @typedef {Object} FakeHeaders
 * @property {function(string):string|null} get Read one response header.
 */

/**
 * Reader-backed body surface consumed by the resource owner.
 * @typedef {Object} FakeResponseBody
 * @property {function():FakeReader} getReader Create the response body reader.
 */

/**
 * Minimal Fetch response surface consumed by the resource owner.
 * @typedef {Object} FakeResponse
 * @property {boolean} ok Whether the response succeeded.
 * @property {FakeHeaders} headers Case-insensitive response headers.
 * @property {FakeResponseBody} body Reader-backed response stream.
 * @property {FakeReader} reader Direct test access to the controllable reader.
 */

/**
 * Optional resource dependencies supplied to the fixture builder.
 * @typedef {Object} ResourceFixtureOptions
 * @property {boolean} [mobile] Whether to use the mobile cache bound.
 * @property {number} [idleMs] Cache idle delay used by the fake clock.
 * @property {function(FakeImage):Promise<void>} [decodeImage] Optional controlled decode operation.
 * @property {function(string,RequestInit):Promise<FakeResponse>} [fetchImpl] Controlled tracked-fetch response provider.
 * @property {function(ResourceDiagnosticEvent):void} [onCacheEvent] Optional cache callback override.
 * @property {function(ResourceDiagnosticEvent):void} [onLoadEvent] Optional image callback override.
 * @property {AbortController} [ownerController] Optional setup-signal controller.
 * @property {AbortSignal} [ownerSignal] Optional setup signal compatible with AbortSignal.
 */

/**
 * Isolated owner and deterministic browser dependencies returned by fixture().
 * @typedef {Object} ResourceFixture
 * @property {ReturnType<typeof createLightboxResourceLifecycle>} owner Production resource owner under test.
 * @property {AbortController} ownerController Terminal owner-signal controller.
 * @property {FakeScheduler} scheduler Fake monotonic clock and timers.
 * @property {FakeImages} images Created fake image nodes and URLs.
 * @property {Array<ResourceDiagnosticEvent>} events Captured fetch and diagnostic events.
 */

/**
 * Create deterministic timeout and monotonic-time controls.
 * @return {FakeScheduler} Fake clock and controllable one-shot timeout API.
 */
function createScheduler() {
    let clock = 0;
    let nextTimer = 0;
    const timers = new Map();
    return {
        timers,
        now: () => clock,
        /**
         * Schedule a one-shot callback against the fake monotonic clock.
         * @param {function():void} callback Timer callback to retain.
         * @param {number} delay Delay in milliseconds.
         * @return {number} Fake timeout identifier.
         */
        setTimeout(callback, delay) {
            const id = nextTimer++;
            timers.set(id, {callback, delay, due: clock + delay});
            return id;
        },
        /**
         * Cancel a pending fake timer.
         * @param {number} id Fake timeout identifier.
         * @return {void} Removes the timer from the pending map.
         */
        clearTimeout(id) { timers.delete(id); },
        /**
         * Advance fake time and synchronously dispatch all timers now due.
         * @param {number} ms Time increment in milliseconds.
         * @return {void} Updates the clock and dispatches due callbacks.
         */
        advance(ms) {
            clock += ms;
            for (const [id, timer] of [...timers]) {
                if (timer.due <= clock) {
                    timers.delete(id);
                    timer.callback();
                }
            }
        },
    };
}

/**
 * Make a controllable fake Image factory and record every supplied URL.
 * @return {FakeImages} Fake image nodes, exact source URLs, and factory callback.
 */
function createImages() {
    const images = [];
    const urls = [];
    return {
        images,
        urls,
        /**
         * Create one fake detached image for the production resource owner.
         * @return {FakeImage} New controllable image node.
         */
        createImage() {
            const image = {
                onload: null,
                onerror: null,
                naturalWidth: 120,
                naturalHeight: 80,
                removed: false,
                /**
                 * Record detached-source cleanup requested by the production owner.
                 * @param {string} name Attribute name requested for removal.
                 * @return {void} Records source detachment for the test.
                 */
                removeAttribute(name) {
                    if (name === 'src') this.removed = true;
                },
                /**
                 * Record the exact URL assigned to the fake image.
                 * @param {string} value Caller-supplied source URL.
                 * @return {void} Stores the URL and appends it to the fixture log.
                 */
                set src(value) {
                    this.source = value;
                    urls.push(value);
                },
                /**
                 * Read the exact URL currently assigned to the fake image.
                 * @return {string} Assigned URL or an empty string.
                 */
                get src() { return this.source || ''; },
                /**
                 * Dispatch the currently installed load-completion handler.
                 * @return {void} Completes the fake image request.
                 */
                finish() { this.onload?.(); },
                /**
                 * Dispatch the currently installed load-failure handler.
                 * @return {void} Fails the fake image request.
                 */
                fail() { this.onerror?.(); },
            };
            images.push(image);
            return image;
        },
    };
}

/**
 * Flush chained promise callbacks without using wall-clock time.
 * @return {Promise<void>} Resolves after queued microtasks have run.
 */
async function settlePromises() {
    for (let index = 0; index < 12; index += 1) await Promise.resolve();
}

/**
 * Track caller-visible abort listener registrations while preserving AbortSignal behavior.
 * @param {AbortController} controller Controller whose signal drives the tracked listener surface.
 * @return {{signal:AbortSignal,added:number,removed:number,active:number}} Signal wrapper and listener counts.
 */
function createTrackedSignal(controller = new AbortController()) {
    const listeners = new Map();
    let added = 0;
    let removed = 0;
    const signal = {
        /**
         * Observe cancellation on the real controller behind this listener spy.
         * @return {boolean} Whether the backing controller has been aborted.
         */
        get aborted() { return controller.signal.aborted; },
        /**
         * Register and count one abort listener on the backing signal.
         * @param {string} type Event type requested by the owner.
         * @param {EventListener} listener Listener registered for that event.
         * @param {AddEventListenerOptions|boolean} [options] Native listener options.
         * @return {void} Forwards a supported abort listener to the controller.
         */
        addEventListener(type, listener, options) {
            if (type !== 'abort' || typeof listener !== 'function' || listeners.has(listener)) return;
            added += 1;
            listeners.set(listener, type);
            controller.signal.addEventListener(type, listener, options);
        },
        /**
         * Remove and count one previously tracked abort listener.
         * @param {string} type Event type requested by the owner.
         * @param {EventListener} listener Listener previously registered for that event.
         * @return {void} Removes the listener from tracking and the backing signal.
         */
        removeEventListener(type, listener) {
            if (listeners.get(listener) !== type) return;
            listeners.delete(listener);
            removed += 1;
            controller.signal.removeEventListener(type, listener);
        },
    };
    return {
        signal,
        /** Count listener registrations observed by the spy.
         * @return {number} Number of listener registrations observed.
         */
        get added() { return added; },
        /** Count listener removals observed by the spy.
         * @return {number} Number of listener removals observed.
         */
        get removed() { return removed; },
        /** Count listeners still held by the backing signal.
         * @return {number} Number of listeners still registered.
         */
        get active() { return listeners.size; },
    };
}

/**
 * Build an isolated resource owner with fake images, timers, and fetch.
 * @param {ResourceFixtureOptions} options Optional device policy and injectable test callbacks.
 * @return {ResourceFixture} Owner plus deterministic dependencies and recorded events.
 */
function createFixture(options = {}) {
    const scheduler = createScheduler();
    const images = createImages();
    const events = [];
    const ownerController = options.ownerController || new AbortController();
    const owner = createLightboxResourceLifecycle({
        signal: options.ownerSignal || ownerController.signal,
        scheduler,
        mobile: options.mobile || false,
        idleMs: options.idleMs ?? 60000,
        createImage: images.createImage,
        decodeImage: options.decodeImage || (() => Promise.resolve()),
        fetchImpl: options.fetchImpl || (async (src, request) => {
            events.push({type: 'fetch', src, request});
            return responseFor([new Uint8Array([1, 2, 3])], 3);
        }),
        /**
         * Capture cache lifecycle events for assertions.
         * @param {Object<string,unknown>} event Bounded event emitted by the owner.
         * @return {void} Appends the event to fixture-local storage.
         */
        onCacheEvent: options.onCacheEvent || function (event) { events.push({type: 'cache', ...event}); },
        /**
         * Capture detached-image events for assertions.
         * @param {Object<string,unknown>} event Bounded event emitted by the owner.
         * @return {void} Appends the event to fixture-local storage.
         */
        onLoadEvent: options.onLoadEvent || function (event) { events.push({type: 'load', ...event}); },
    });
    return {owner, ownerController, scheduler, images, events};
}

/**
 * Create a small response stream with deterministic chunks and reader cleanup.
 * @param {Uint8Array[]} chunks Response byte chunks to return in order.
 * @param {number} total Declared response length.
 * @param {ReaderState} state Mutable reader observations written by the fake.
 * @return {FakeResponse} Fetch response with its controllable reader.
 */
function responseFor(chunks, total, state = {}) {
    let index = 0;
    const reader = {
        /**
         * Return one byte chunk or the stream completion marker.
         * @return {Promise<{done:boolean,value?:Uint8Array}>} Next controlled stream result.
         */
        async read() {
            if (index >= chunks.length) return {done: true};
            return {done: false, value: chunks[index++]};
        },
        /**
         * Record cancellation of the fake response reader.
         * @return {Promise<void>} Resolves after marking the reader cancelled.
         */
        async cancel() { state.cancelled = true; },
        /**
         * Record release of the fake response reader lock.
         * @return {void} Marks the lock as released.
         */
        releaseLock() { state.released = true; },
    };
    return {
        ok: true,
        headers: {get: (name) => name.toLowerCase() === 'content-length' ? String(total) : null},
        body: {getReader: () => reader},
        reader,
    };
}

/**
 * Capture one promise rejection name for concise cancellation assertions.
 * @param {Promise<unknown>} promise Promise expected to reject.
 * @return {Promise<string>} Rejection name or an empty string after fulfillment.
 */
async function rejectionName(promise) {
    try {
        await promise;
    } catch (error) {
        return error.name;
    }
    return '';
}

test('owner and caller abort listeners are balanced on setup abort, settlement, cancellation, and disposal', async () => {
    const alreadyAbortedController = new AbortController();
    alreadyAbortedController.abort();
    const alreadyAbortedSignal = createTrackedSignal(alreadyAbortedController);
    const stopped = createFixture({ownerController: alreadyAbortedController, ownerSignal: alreadyAbortedSignal.signal});
    assert.equal(stopped.owner.snapshot().disposed, true);
    assert.equal(stopped.images.images.length, 0);
    assert.deepEqual([alreadyAbortedSignal.added, alreadyAbortedSignal.removed, alreadyAbortedSignal.active], [1, 1, 0]);

    const ownerController = new AbortController();
    const ownerSignal = createTrackedSignal(ownerController);
    const f = createFixture({ownerController, ownerSignal: ownerSignal.signal});
    assert.deepEqual([ownerSignal.added, ownerSignal.removed, ownerSignal.active], [1, 0, 1]);

    const settledController = new AbortController();
    const settledSignal = createTrackedSignal(settledController);
    const settled = f.owner.preload('/approved/listener-settled.jpg', {signal: settledSignal.signal});
    f.images.images[0].finish();
    assert.equal((await settled).src, '/approved/listener-settled.jpg');
    assert.deepEqual([settledSignal.added, settledSignal.removed, settledSignal.active], [1, 1, 0]);

    const cancelledController = new AbortController();
    const cancelledSignal = createTrackedSignal(cancelledController);
    const cancelled = f.owner.preload('/approved/listener-cancelled.jpg', {signal: cancelledSignal.signal});
    cancelledController.abort();
    assert.equal(await cancelled, null);
    assert.deepEqual([cancelledSignal.added, cancelledSignal.removed, cancelledSignal.active], [1, 1, 0]);
    assert.equal(f.owner.snapshot().activeOperations, 0);

    f.owner.dispose();
    assert.deepEqual([ownerSignal.added, ownerSignal.removed, ownerSignal.active], [1, 1, 0]);
});

test('a cleared hidden timer callback cannot trim reopened cache or orphan the replacement timer', async () => {
    const f = createFixture();
    f.owner.setHidden(true, true);
    const staleTimer = [...f.scheduler.timers.values()][0];
    assert.equal(typeof staleTimer.callback, 'function');

    f.owner.clear();
    f.owner.setHidden(false, true);
    const reopened = f.owner.preload('/approved/reopened-cache.jpg');
    f.images.images[0].finish();
    assert.equal((await reopened).src, '/approved/reopened-cache.jpg');
    const pending = f.owner.preload('/approved/reopened-pending.jpg');

    f.owner.setHidden(true, true);
    assert.equal(f.scheduler.timers.size, 1);
    const currentTimerId = [...f.scheduler.timers.keys()][0];
    staleTimer.callback();

    assert.equal(f.owner.cacheStatus('/approved/reopened-cache.jpg'), 'cached', 'an obsolete timer cannot release reopened settled entries');
    assert.equal(f.owner.cacheStatus('/approved/reopened-pending.jpg'), 'pending', 'a stale timer cannot cancel newly owned work');
    assert.equal(f.owner.snapshot().hiddenCleanupScheduled, true, 'the replacement hidden timer remains owned');
    assert.equal(f.scheduler.timers.has(currentTimerId), true);
    f.owner.dispose();
    assert.equal(f.scheduler.timers.size, 0, 'terminal disposal still cancels the live replacement timer');
    assert.equal(await pending, null);
});

test('reentrant started observers cannot insert work after clear or terminal disposal', async () => {
    for (const action of ['clear', 'dispose']) {
        let owner;
        let lateOnload;
        const f = createFixture({
            /**
             * Retire the owner synchronously when detached work announces its start.
             * @param {ResourceDiagnosticEvent} event Started or settled load event.
             * @return {void} Clears or disposes the owning resource scope.
             */
            onLoadEvent(event) {
                if (event.name === 'started') {
                    lateOnload = f.images.images[0].onload;
                    owner[action]();
                }
            },
        });
        owner = f.owner;
        const preload = owner.preload(`/approved/reentrant-${action}.jpg`);
        assert.equal(await preload, null, `${action} cancels the preload which triggered the observer`);
        assert.equal(owner.cacheStatus(`/approved/reentrant-${action}.jpg`), null);
        assert.equal(owner.snapshot().activeOperations, 0);
        assert.deepEqual(f.events.filter((event) => event.name === 'inserted'), [], 'stale owner epochs do not publish cache insertion');
        lateOnload?.();
        await settlePromises();
        assert.equal(owner.cacheStatus(`/approved/reentrant-${action}.jpg`), null, 'late image completion cannot restore retired cache state');
    }
});

test('a foreground miss observer that disposes the owner prevents detached image creation', async () => {
    let owner;
    const f = createFixture();
    owner = f.owner;
    const load = owner.loadDecoded('/approved/reentrant-miss.jpg', {
        /**
         * Dispose the owner before the reported foreground miss starts work.
         * @param {string} result Bounded cache lookup result.
         * @param {string} src Exact source URL supplied to the lookup.
         * @return {void} Retires the owner on the cache-miss callback.
         */
        onCacheResult(result, src) {
            if (result === 'miss' && src === '/approved/reentrant-miss.jpg') owner.dispose();
        },
    });
    assert.equal(await rejectionName(load), 'AbortError');
    assert.equal(f.images.images.length, 0);
    assert.equal(owner.snapshot().disposed, true);
    assert.equal(owner.snapshot().activeOperations, 0);
    assert.equal(owner.cacheStatus('/approved/reentrant-miss.jpg'), null);
    assert.deepEqual(f.events.filter((event) => event.name === 'inserted'), [], 'disposing from miss observation prevents insertion diagnostics');
});

test('cache insertion diagnostics describe admitted entries and late completion cannot repopulate disposal', async () => {
    let owner;
    let lateOnload;
    const cacheEvents = [];
    const f = createFixture({
        /**
         * Retain the actual completion callback before insertion observers retire the owner.
         * @param {ResourceDiagnosticEvent} event Detached-load lifecycle event emitted by the owner.
         * @return {void} Saves the callback while the image operation is live.
         */
        onLoadEvent(event) {
            if (event.name === 'started') lateOnload = f.images.images[0].onload;
        },
        /**
         * Record an admitted entry and synchronously dispose its owner from the observer.
         * @param {ResourceDiagnosticEvent} event Cache lifecycle event emitted by the owner.
         * @return {void} Records the event and retires the owner after insertion.
         */
        onCacheEvent(event) {
            cacheEvents.push(event);
            if (event.name === 'inserted') owner.dispose();
        },
    });
    owner = f.owner;
    const preload = owner.preload('/approved/inserted-then-disposed.jpg');
    assert.equal(await preload, null);
    assert.deepEqual(cacheEvents.filter((event) => event.name === 'inserted'), [
        {name: 'inserted', src: '/approved/inserted-then-disposed.jpg', reason: 'preload'},
    ]);
    assert.equal(owner.snapshot().disposed, true);
    assert.equal(owner.snapshot().entries, 0);
    assert.equal(owner.snapshot().activeOperations, 0);
    lateOnload?.();
    await settlePromises();
    assert.equal(owner.cacheStatus('/approved/inserted-then-disposed.jpg'), null);
    assert.equal(cacheEvents.filter((event) => event.name === 'inserted').length, 1, 'retired work emits no second insertion event');
});

test('pending URL cache work reuses one promise and failed preview resolves null for foreground retry', async () => {
    const f = createFixture();
    const first = f.owner.preload('/authorized/preview-a.jpg');
    const reused = f.owner.preload('/authorized/preview-a.jpg');
    assert.equal(first, reused);
    assert.equal(f.images.images.length, 1);
    assert.equal(f.owner.cacheStatus('/authorized/preview-a.jpg'), 'pending');
    f.images.images[0].finish();
    const decoded = await first;
    assert.equal(f.owner.cacheStatus('/authorized/preview-a.jpg'), 'cached');
    assert.equal(f.owner.cacheResultFor(decoded), 'unknown');

    const failed = f.owner.preload('/authorized/broken-preview.jpg');
    f.images.images[1].fail();
    assert.equal(await failed, null);
    assert.equal(f.owner.cacheStatus('/authorized/broken-preview.jpg'), 'cached');
    const retry = f.owner.loadDecoded('/authorized/broken-preview.jpg');
    await settlePromises();
    assert.equal(f.images.images.length, 3, 'foreground use must retry the null cache sentinel');
    f.images.images[2].finish();
    const retried = await retry;
    assert.equal(f.owner.cacheResultFor(retried), 'miss');
    assert.deepEqual(f.images.urls, [
        '/authorized/preview-a.jpg',
        '/authorized/broken-preview.jpg',
        '/authorized/broken-preview.jpg',
    ]);
    f.owner.dispose();
});

test('desktop and mobile limits retain unresolved work and evict only settled least-recent entries', async () => {
    const desktop = createFixture();
    const desktopLoads = Array.from({length: 13}, (_, index) => desktop.owner.preload(`/approved/desktop-${index}.jpg`));
    assert.equal(desktop.owner.snapshot().entries, 13, 'in-flight entries may exceed the limit without being orphaned');
    assert.equal(desktop.owner.cacheStatus('/approved/desktop-0.jpg'), 'pending');
    desktop.images.images[0].finish();
    await desktopLoads[0];
    assert.equal(desktop.owner.snapshot().entries, 12);
    assert.equal(desktop.owner.cacheStatus('/approved/desktop-0.jpg'), null);
    assert.equal(desktop.owner.cacheStatus('/approved/desktop-1.jpg'), 'pending');
    desktop.owner.dispose();

    const lru = createFixture();
    const settledLoads = Array.from({length: 12}, (_, index) => lru.owner.preload(`/approved/lru-${index}.jpg`));
    for (let index = 0; index < settledLoads.length; index += 1) lru.images.images[index].finish();
    await Promise.all(settledLoads);
    await lru.owner.loadDecoded('/approved/lru-0.jpg');
    const thirteenth = lru.owner.preload('/approved/lru-12.jpg');
    lru.images.images[12].finish();
    await thirteenth;
    assert.equal(lru.owner.cacheStatus('/approved/lru-0.jpg'), 'cached', 'a cache touch makes that settled entry newest');
    assert.equal(lru.owner.cacheStatus('/approved/lru-1.jpg'), null, 'the oldest untouched settled entry is evicted');
    lru.owner.dispose();

    const mobile = createFixture({mobile: true});
    const mobileLoads = Array.from({length: 7}, (_, index) => mobile.owner.preload(`/approved/mobile-${index}.jpg`));
    mobile.images.images[0].finish();
    await mobileLoads[0];
    assert.equal(mobile.owner.snapshot().limit, 6);
    assert.equal(mobile.owner.snapshot().entries, 6);
    assert.equal(mobile.owner.cacheStatus('/approved/mobile-0.jpg'), null);
    mobile.owner.dispose();
});

test('LRU touch refreshes age and hidden cleanup is one bounded timer that preserves displayed image nodes', async () => {
    const f = createFixture();
    const first = f.owner.preload('/approved/first.jpg');
    const second = f.owner.preload('/approved/second.jpg');
    f.images.images[0].finish();
    f.images.images[1].finish();
    const displayed = await first;
    await second;
    f.scheduler.advance(30000);
    assert.equal(await f.owner.loadDecoded('/approved/first.jpg'), displayed);
    f.scheduler.advance(30000);
    f.owner.setHidden(false, true);
    assert.equal(f.owner.cacheStatus('/approved/first.jpg'), 'cached', 'a cache hit refreshes last-used time');
    f.scheduler.advance(29999);
    f.owner.setHidden(false, true);
    assert.equal(f.owner.cacheStatus('/approved/first.jpg'), 'cached');
    f.owner.setHidden(true, true);
    assert.equal(f.scheduler.timers.size, 1);
    assert.equal([...f.scheduler.timers.values()][0].delay, 60000);
    f.owner.setHidden(false, true);
    assert.equal(f.scheduler.timers.size, 0, 'visible transition cancels the pending hidden release');
    const pending = f.owner.preload('/approved/still-loading.jpg');
    f.owner.setHidden(true, true);
    f.scheduler.advance(60000);
    assert.equal(f.owner.cacheStatus('/approved/first.jpg'), null);
    assert.equal(f.owner.cacheStatus('/approved/second.jpg'), null);
    assert.equal(f.owner.cacheStatus('/approved/still-loading.jpg'), 'pending', 'idle release never evicts unresolved work');
    assert.equal(displayed.src, '/approved/first.jpg', 'eviction must not detach an image already handed to presentation');
    assert.equal(displayed.removed, false);
    f.owner.dispose();
    assert.equal(await pending, null);
});

test('a pending decode that outlives the idle window receives a full idle window after settlement', async () => {
    const f = createFixture();
    const pending = f.owner.preload('/approved/slow-to-decode.jpg');
    f.scheduler.advance(60001);
    assert.equal(f.owner.cacheStatus('/approved/slow-to-decode.jpg'), 'pending');
    f.images.images[0].finish();
    await pending;
    assert.equal(f.owner.cacheStatus('/approved/slow-to-decode.jpg'), 'cached');
    f.scheduler.advance(59999);
    f.owner.sweep();
    assert.equal(f.owner.cacheStatus('/approved/slow-to-decode.jpg'), 'cached');
    f.scheduler.advance(1);
    f.owner.sweep();
    assert.equal(f.owner.cacheStatus('/approved/slow-to-decode.jpg'), null);
    f.owner.dispose();
});

test('clear cancels pending detached images, fences late completion, and leaves the owner reusable', async () => {
    const f = createFixture();
    const caller = new AbortController();
    const pending = f.owner.loadFresh('/authorized/pending.jpg', {signal: caller.signal});
    const image = f.images.images[0];
    const lateOnload = image.onload;
    f.owner.clear();
    assert.equal(image.removed, true);
    assert.equal(image.onload, null);
    assert.equal(image.onerror, null);
    assert.equal(await rejectionName(pending), 'AbortError');
    lateOnload();
    await settlePromises();
    assert.equal(f.owner.snapshot().entries, 0, 'cleared work cannot reinsert a stale entry');

    const next = f.owner.preload('/authorized/reopened.jpg');
    f.images.images[1].finish();
    assert.equal((await next).src, '/authorized/reopened.jpg');
    assert.equal(f.owner.snapshot().disposed, false);
    f.owner.dispose();
});

test('shared foreground decode ignores navigation cancellation and old same-URL settlement cannot evict its replacement', async () => {
    const f = createFixture();
    const navigation = new AbortController();
    const foreground = f.owner.loadDecoded('/approved/shared.jpg', {signal: navigation.signal});
    navigation.abort();
    f.images.images[0].finish();
    assert.equal((await foreground).src, '/approved/shared.jpg', 'shared decoded work is not owned by a navigation signal');

    const preloadSignal = new AbortController();
    const oldPromise = f.owner.preload('/approved/reused-key.jpg', {signal: preloadSignal.signal});
    f.owner.clear();
    const replacement = f.owner.preload('/approved/reused-key.jpg');
    preloadSignal.abort();
    assert.equal(f.owner.cacheStatus('/approved/reused-key.jpg'), 'pending');
    assert.equal(await oldPromise, null);
    assert.equal(f.owner.cacheStatus('/approved/reused-key.jpg'), 'pending', 'late cancellation of an old entry cannot delete a newer same-key entry');
    f.images.images[2].finish();
    assert.equal((await replacement).src, '/approved/reused-key.jpg');
    f.owner.dispose();
});

test('tracked Fetch reports real byte progress, decodes the exact URL, and isolates throwing observers', async () => {
    const responseState = {};
    let fetched;
    const f = createFixture({
        fetchImpl: async (src, request) => {
            fetched = {src, request};
            return responseFor([new Uint8Array([1, 2]), new Uint8Array([3])], 3, responseState);
        },
        /**
         * Throw to prove optional cache diagnostics cannot interrupt a resource load.
         * @param {ResourceDiagnosticEvent} event Cache lifecycle event supplied by the owner.
         * @return {void} Raises the fixture-only observer error.
         */
        onCacheEvent(event) { throw new Error('optional cache diagnostics'); },
        /**
         * Throw to prove optional load diagnostics cannot interrupt a resource load.
         * @param {ResourceDiagnosticEvent} event Load lifecycle event supplied by the owner.
         * @return {void} Raises the fixture-only observer error.
         */
        onLoadEvent(event) { throw new Error('optional load diagnostics'); },
    });
    const progress = [];
    const load = f.owner.loadTracked('/approved/active-full.jpg', {
        priority: 'high',
        /**
         * Record byte progress and throw to verify observer isolation.
         * @param {number} loaded Received response bytes.
         * @param {number} total Declared response bytes when known.
         * @return {void} Records progress then raises a fixture-only observer error.
         */
        onProgress(loaded, total) {
            progress.push([loaded, total]);
            throw new Error('optional progress display');
        },
    });
    assert.equal(fetched.src, '/approved/active-full.jpg', 'quality Fetch starts in the initiating input task');
    assert.equal(fetched.src, '/approved/active-full.jpg');
    assert.equal(fetched.request.credentials, 'same-origin');
    assert.equal(fetched.request.cache, 'default');
    await settlePromises();
    assert.equal(f.owner.snapshot().activeTrackedLoads, 1);
    assert.equal(f.owner.snapshot().activeFetchTransfers, 0, 'the byte transfer has finished before detached decoding starts');
    assert.equal(f.owner.snapshot().activeDetachedImageLoads, 1, 'the Image node is counted separately from its tracked request');
    assert.equal(f.images.urls[0], '/approved/active-full.jpg');
    f.images.images[0].finish();
    const image = await load;
    assert.equal(image.src, '/approved/active-full.jpg');
    assert.deepEqual(progress, [[0, 3], [2, 3], [3, 3], [3, 3]]);
    assert.equal(responseState.released, true);
    assert.equal(f.owner.snapshot().activeTrackedLoads, 0);
    assert.equal(f.owner.snapshot().activeDetachedImageLoads, 0);
    assert.deepEqual(f.images.urls, ['/approved/active-full.jpg']);
    f.owner.dispose();
});

test('clear and terminal dispose cancel tracked Fetch readers before a detached Image can start', async () => {
    let readPending;
    let fetchSignal;
    const readerState = {};
    const f = createFixture({fetchImpl: async (src, request) => {
        fetchSignal = request.signal;
        const response = responseFor([], 5, readerState);
        response.reader.read = () => new Promise((resolve) => { readPending = resolve; });
        response.reader.cancel = async () => {
            readerState.cancelled = true;
            readPending?.({done: true});
        };
        return response;
    }});
    const load = f.owner.loadTracked('/approved/slow-full.jpg');
    await settlePromises();
    assert.equal(typeof readPending, 'function');
    f.owner.clear();
    assert.equal(fetchSignal.aborted, true);
    assert.equal(readerState.cancelled, true);
    readPending({done: true});
    assert.equal(await rejectionName(load), 'AbortError');
    assert.equal(f.images.images.length, 0, 'cancelled transfer cannot start late image decode');
    assert.equal(f.owner.snapshot().activeOperations, 0);

    const next = f.owner.loadTracked('/approved/terminal-full.jpg');
    await settlePromises();
    const nextSignal = fetchSignal;
    f.ownerController.abort();
    assert.equal(nextSignal.aborted, true);
    assert.equal(await rejectionName(next), 'AbortError');
    assert.equal(f.owner.snapshot().disposed, true);
    assert.equal(f.owner.snapshot().activeOperations, 0);
    assert.equal(f.images.images.length, 0);
    f.owner.dispose();
});

test('snapshot is copied and frozen, while clear remains distinct from terminal dispose', async () => {
    const f = createFixture();
    const load = f.owner.preload('/approved/snapshot.jpg');
    const snapshot = f.owner.snapshot();
    assert.equal(Object.isFrozen(snapshot), true);
    assert.equal(snapshot.pending, 1);
    assert.equal('cache' in snapshot, false);
    assert.equal('activeOperationsSet' in snapshot, false);
    f.owner.clear();
    assert.equal(await load, null, 'a cancelled preload settles with the established null sentinel');
    assert.equal(f.owner.snapshot().disposed, false);
    assert.equal(f.owner.hasCached('/approved/snapshot.jpg'), false);
    f.owner.dispose();
    f.owner.dispose();
    assert.equal(f.owner.snapshot().disposed, true);
    assert.equal(f.owner.snapshot().activeOperations, 0);
});
