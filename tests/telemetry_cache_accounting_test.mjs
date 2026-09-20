/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_cache_accounting_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises telemetry repairs using isolated deterministic fixtures.
 *
 * Responsibilities:
 *   - Execute production behavior with isolated inputs
 *   - Fail on privacy, recovery or reporting regressions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'public/assets/gallery-modules/lightbox.js'), 'utf8').replaceAll('\r\n', '\n');
const start = source.indexOf('    function loadDecodedLightboxImage(');
assert.ok(start > 0);
const end = source.indexOf('\n    }', start) + '\n    }'.length;
const body = source.slice(start, end);
/**
 * Execute the production cache decision function with isolated decode and navigation state.
 * @param {Promise<Object<string, unknown>|null>|null} entry Optional cached promise.
 * @return {Object<string, unknown>} Loaded function, captured events and mutable ownership.
 */
function fixture(entry) {
    const state = {current: true, fresh: 0, saved: 0};
    const events = [], freshImage = {source: 'fresh'}, counters = {cacheHits: 0, cacheMisses: 0};
    const context = {
        Promise, Number, Error, WeakMap, galleryDevModeEnabled: true, galleryDevModeState: counters,
        lightboxTelemetryCacheResults: new WeakMap(),
        /** Resolve one cached entry. @return {Object<string, unknown>|null} Prepared entry or cache miss. */
        useDecodedLightboxImageCacheEntry() { return entry === null ? null : {promise: entry}; },
        /** Resolve safe fallback text. @param {string} key Translation identifier. @param {string} fallback Safe default. @return {string} Message. */
        i18n(key, fallback) { assert.equal(key, 'lightbox.missing_image_source'); return fallback; },
        /** Ignore visual diagnostics in this no-DOM fixture. @return {void} No browser state is touched. */
        devMarkSource() {},
        /** Check live ownership, not ownership captured before an asynchronous decode. @return {boolean} Whether this navigation is current. */
        isCurrentLightboxImageRequest() { return state.current; },
        /** Capture a bounded cache observation. @param {string} name Event name. @param {number} index Active index. @param {string} src Source only retained inside this local fixture. @return {void} Appends one observed event. */
        telemetryLightboxCacheEvent(name, index, src) { events.push({name, index, src}); },
        /** Simulate fresh decoding. @return {Promise<Object<string, string>>} A distinct fresh image. */
        loadFreshDecodedLightboxImage() { state.fresh++; return Promise.resolve(freshImage); },
        /** Count recovery cache writes. @return {void} Records a cache insertion without network I/O. */
        rememberDecodedLightboxImage() { state.saved++; },
    };
    vm.createContext(context);
    vm.runInContext(body + '\nthis.load = loadDecodedLightboxImage;', context, {filename: 'lightbox-cache-fixture.js'});
    return {state, events, freshImage, counters, load: context.load};
}
const owned = {telemetryIndex: 4, telemetryToken: 22};
const image = {source: 'cached'};
let resolveCache;
const pending = new Promise(
    /** Capture deferred decode completion. @param {Function} resolve Promise resolver. @return {void} Stores the resolver for explicit completion. */
    function (resolve) { resolveCache = resolve; }
);
const hit = fixture(pending);
const hitLoad = hit.load('/authorized/image', owned);
assert.equal(hit.events.length, 0, 'A pending preload is not yet a cache hit.');
resolveCache(image);
assert.equal(await hitLoad, image);
assert.equal(hit.events.length, 1);
assert.equal(hit.events[0].name, 'cache.lightbox.hit');
assert.equal(hit.counters.cacheHits, 1);
assert.equal(hit.state.fresh, 0);
const failed = fixture(Promise.resolve(null));
assert.equal(await failed.load('/authorized/image', owned), failed.freshImage);
assert.equal(failed.events.length, 1);
assert.equal(failed.events[0].name, 'cache.lightbox.miss');
assert.equal(failed.counters.cacheHits, 0);
assert.equal(failed.counters.cacheMisses, 1);
assert.equal(failed.state.saved, 1);
const absent = fixture(null);
await absent.load('/authorized/image', owned);
assert.equal(absent.events.length, 1);
assert.equal(absent.events[0].name, 'cache.lightbox.miss');
const stale = fixture(Promise.resolve(image));
const staleLoad = stale.load('/authorized/image', owned);
stale.state.current = false;
assert.equal(await staleLoad, image);
assert.equal(stale.events.length, 0, 'Late decode must not attribute a hit to a new navigation.');
const preload = fixture(Promise.resolve(image));
await preload.load('/authorized/image');
assert.equal(preload.events.length, 0, 'Unowned background preloads must not inflate visible lookup ratios.');
await assert.rejects(preload.load(''), /Missing lightbox image source/);
console.log('Cache decision and asynchronous ownership fixtures passed.');
