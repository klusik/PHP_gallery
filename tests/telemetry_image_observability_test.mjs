/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_image_observability_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises browser image-performance and cache telemetry payloads deterministically.
 *
 * Responsibilities:
 *   - Verify image decode/display events carry normalized variants and width buckets
 *   - Verify cache telemetry exposes only the bounded application-cache source kind
 *   - Verify exact geometry and source URLs are not placed in event context
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
const source = fs.readFileSync(path.join(root, 'public/assets/usage.js'), 'utf8');
const payloads = [];
const listeners = new Map();
const storage = new Map();
let nowMs = 1000;

const sessionStorage = {
    getItem(key) {
        return storage.has(key) ? storage.get(key) : null;
    },
    setItem(key, value) {
        storage.set(key, String(value));
    },
};

const document = {
    fullscreenElement: null,
    visibilityState: 'visible',
    addEventListener(name, handler) {
        listeners.set(`document:${name}`, handler);
    },
    querySelector() {
        return null;
    },
};

const navigator = {
    doNotTrack: '0',
    userAgent: 'Mozilla/5.0 Chrome/153.0.0.0',
    platform: 'Linux x86_64',
    language: 'en-US',
    sendBeacon() {
        return false;
    },
};

const window = {
    PHPGalleryTelemetry: {
        enabled: true,
        endpoint: '/telemetry',
        routeName: 'gallery',
        pageKind: 'gallery',
        galleryId: 77,
        maxPhotoViewSeconds: 900,
        sampleRate: 1,
        performanceSampleRate: 1,
        errorSampleRate: 1,
    },
    PHPGalleryTelemetryLightboxOwner: 'native',
    crypto: {
        randomUUID() {
            return 'telemetry-image-observability-session';
        },
    },
    innerWidth: 1920,
    setTimeout() {
        return 1;
    },
    clearTimeout() {},
    addEventListener(name, handler) {
        listeners.set(`window:${name}`, handler);
    },
};

const context = vm.createContext({
    Blob,
    Date,
    Element: class Element {},
    Math,
    Number,
    String,
    console,
    document,
    fetch(_endpoint, options) {
        payloads.push(JSON.parse(String(options?.body || '{}')));
        return Promise.resolve({ok: true});
    },
    navigator,
    performance: {
        now() {
            return nowMs;
        },
        getEntriesByType() {
            return [];
        },
    },
    sessionStorage,
    window,
});

vm.runInContext(source, context, {filename: 'usage.js'});

assert.equal(typeof window.PHPGalleryTelemetryImageDecoded, 'function', 'Image-decode telemetry hook must be installed.');
assert.equal(typeof window.PHPGalleryTelemetryImageDisplayed, 'function', 'Image-display telemetry hook must be installed.');
assert.equal(typeof window.PHPGalleryTelemetryCacheEvent, 'function', 'Cache telemetry hook must be installed.');

window.PHPGalleryTelemetryImageDecoded(101, 77, 12.6, 'thumb_1200', 'miss', 786, 4032);
window.PHPGalleryTelemetryImageDisplayed(101, 77, 7.4, 'thumb_1200', 'miss', 786, 4032);
window.PHPGalleryTelemetryCacheEvent('cache.lightbox.miss', 101, 77, 'decoded_lightbox');

// Photo-open flushes the queued observability events synchronously through fetch.
nowMs = 1100;
window.PHPGalleryTelemetryPhotoOpened(101, 77, 'normal', 'click');

const events = payloads.flatMap((payload) => Array.isArray(payload.events) ? payload.events : []);
const decoded = events.find((event) => event.event_name === 'client.performance.image_decode');
const displayed = events.find((event) => event.event_name === 'client.performance.image_display');
const cacheMiss = events.find((event) => event.event_name === 'cache.lightbox.miss');

assert.ok(decoded, 'Image-decode event must be transported.');
assert.ok(displayed, 'Image-display event must be transported.');
assert.ok(cacheMiss, 'Decoded-lightbox cache miss must be transported.');
assert.equal(decoded.value_ms, 13, 'Decode duration must be rounded to milliseconds.');
assert.equal(displayed.value_ms, 7, 'Display duration must be rounded to milliseconds.');
assert.equal(decoded.media_variant, 'thumb_1200', 'Decode event must retain a normalized media variant.');
assert.equal(decoded.cache_result, 'miss', 'Decode event must retain the bounded cache result.');
assert.deepEqual(
    decoded.context,
    {display_width_bucket: '601_800', natural_width_bucket: '1201_plus'},
    'Decode context must contain only bounded width classes.'
);
assert.deepEqual(
    displayed.context,
    {display_width_bucket: '601_800', natural_width_bucket: '1201_plus'},
    'Display context must contain only bounded width classes.'
);
assert.equal(cacheMiss.cache_result, 'miss', 'Cache miss event must carry the bounded miss result.');
assert.deepEqual(cacheMiss.context, {source_kind: 'decoded_lightbox', lookup_phase: 'unknown'}, 'Cache event must identify only the bounded application-cache layer.');
assert.ok(!JSON.stringify(events).includes('4032'), 'Exact natural width must not enter transported telemetry payloads.');
assert.ok(!JSON.stringify(events).includes('https://'), 'Image observability payloads must not contain source URLs.');

const beforeDisabledEvents = payloads.flatMap((payload) => Array.isArray(payload.events) ? payload.events : []).length;
window.PHPGalleryTelemetry.performanceEnabled = false;
window.PHPGalleryTelemetry.cacheEnabled = false;
assert.equal(
    window.PHPGalleryTelemetryImageDecoded(101, 77, 5, 'thumb_1200', 'hit', 786, 4032),
    false,
    'Disabled performance telemetry must avoid enqueueing decode observations.'
);
assert.equal(
    window.PHPGalleryTelemetryCacheEvent('cache.lightbox.hit', 101, 77, 'decoded_lightbox'),
    false,
    'Disabled cache telemetry must avoid enqueueing application-cache observations.'
);
window.PHPGalleryTelemetryPhotoOpened(102, 77, 'normal', 'click');
const afterDisabledEvents = payloads.flatMap((payload) => Array.isArray(payload.events) ? payload.events : []).length;
assert.equal(afterDisabledEvents - beforeDisabledEvents, 2, 'Only photo close/open events may flush after optional observability is disabled.');

console.log('telemetry_image_observability_test: ok');
