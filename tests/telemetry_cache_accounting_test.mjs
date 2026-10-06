/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_cache_accounting_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises decoded-cache telemetry through the production resource owner.
 *
 * Responsibilities:
 *   - Execute production cache ownership with deterministic detached images
 *   - Protect hit/miss attribution and stale-navigation suppression
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
import {createLightboxResourceLifecycle} from '../public/assets/gallery-modules/lightbox-resource-lifecycle.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'public/assets/gallery-modules/lightbox.js'), 'utf8').replaceAll('\r\n', '\n');
const start = source.indexOf('    function loadDecodedLightboxImage(');
assert.ok(start > 0);
const end = source.indexOf('\n    }', start) + '\n    }'.length;
const body = source.slice(start, end);

/**
 * Create the production cache owner and coordinator adapter around controllable image nodes.
 * @param {'pending'|'failed'|'success'|null} [seed] Optional cache entry state to prepare.
 * @return {Object<string, unknown>} Owner, controlled images, telemetry observations, and adapter.
 */
function fixture(seed = null) {
    const state = {current: true, fresh: 0, cacheHits: 0, cacheMisses: 0};
    const events = [];
    const images = [];
    const context = {
        Promise,
        Number,
        Error,
        performance: {now: () => Date.now()},
        galleryDevModeEnabled: true,
        galleryDevModeState: state,
        lightboxResources: null,
        i18n: (key, fallback) => {
            assert.equal(key, 'lightbox.missing_image_source');
            return fallback;
        },
        devMarkSource: () => {},
        isCurrentLightboxImageRequest: () => state.current,
        telemetryLightboxCacheEvent: (name, index, src) => events.push({name, index, src}),
        telemetryVisibleImageDecoded: () => {},
    };
    const owner = createLightboxResourceLifecycle({
        createImage: () => {
            const image = {
                naturalWidth: 800,
                naturalHeight: 600,
                onload: null,
                onerror: null,
                source: '',
                removeAttribute: () => {},
                finish: () => image.onload?.(),
                fail: () => image.onerror?.(),
            };
            Object.defineProperty(image, 'src', {
                get: () => image.source,
                set: (value) => { image.source = value; },
            });
            images.push(image);
            state.fresh += 1;
            return image;
        },
        decodeImage: () => Promise.resolve(),
    });
    context.lightboxResources = owner;
    vm.createContext(context);
    vm.runInContext(body + '\nthis.load = loadDecodedLightboxImage;', context, {filename: 'lightbox-cache-fixture.js'});
    if (seed !== null) {
        owner.preload('/authorized/image', {reason: 'fixture-seed'});
        if (seed === 'failed') {
            images.at(-1).fail();
        } else if (seed === 'pending') {
            // The caller controls when the pending detached image is completed.
        } else {
            images.at(-1).finish();
        }
    }
    return {state, events, images, owner, load: context.load};
}

const owned = {telemetryIndex: 4, telemetryToken: 22};
const hit = fixture('pending');
const hitLoad = hit.load('/authorized/image', owned);
assert.equal(hit.events.length, 0, 'An unresolved preload is not yet a cache hit.');
hit.images[0].finish();
assert.equal(await hitLoad, hit.images[0]);
assert.equal(hit.events.length, 1);
assert.equal(hit.events[0].name, 'cache.lightbox.hit');
assert.equal(hit.state.cacheHits, 1);
assert.equal(hit.state.fresh, 1);

const failed = fixture('failed');
const failedLoad = failed.load('/authorized/image', owned);
await new Promise((resolve) => setImmediate(resolve));
assert.equal(failed.state.fresh, 2, 'Foreground lookup retries a cached null failure.');
failed.images[1].finish();
assert.equal(await failedLoad, failed.images[1]);
assert.equal(failed.events.at(-1).name, 'cache.lightbox.miss');
assert.equal(failed.state.cacheMisses, 1);

const absent = fixture();
const absentLoad = absent.load('/authorized/image', owned);
assert.equal(absent.events[0].name, 'cache.lightbox.miss');
absent.images[0].finish();
await absentLoad;

const stale = fixture('pending');
const staleLoad = stale.load('/authorized/image', owned);
stale.state.current = false;
stale.images[0].finish();
assert.equal(await staleLoad, stale.images[0]);
assert.equal(stale.events.length, 0, 'Late decode must not attribute a hit to a new navigation.');

const preload = fixture('pending');
const backgroundLoad = preload.load('/authorized/image');
preload.images[0].finish();
await backgroundLoad;
assert.equal(preload.events.length, 0, 'Background lookup without active navigation ownership emits no visible telemetry.');
await assert.rejects(preload.load(''), /Missing lightbox image source/);
console.log('Production resource owner cache accounting fixtures passed.');
