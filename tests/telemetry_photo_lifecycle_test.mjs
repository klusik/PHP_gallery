/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_photo_lifecycle_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protect the anonymous photo telemetry activation state machine.
 *
 * Responsibilities:
 *   - Verify duplicate observations of the active image are semantic no-ops
 *   - Verify A -> B closes A exactly once before opening B
 *   - Verify a genuine close permits the same image to be counted again later
 *   - Verify visible-time flushes are emitted once per activation
 *   - Verify only bounded activation-origin buckets enter telemetry context
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
let nowMs = 0;
const payloads = [];
const listeners = new Map();
const storage = new Map();

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
    sendBeacon(_endpoint, blob) {
        // Blob.text() is asynchronous. Returning false keeps the test on the
        // synchronous fetch path where payload inspection is deterministic.
        void blob;
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
        performanceSampleRate: 0,
        errorSampleRate: 1,
    },
    PHPGalleryTelemetryLightboxOwner: 'native',
    crypto: {
        randomUUID() {
            return 'telemetry-photo-lifecycle-session';
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

assert.equal(typeof window.PHPGalleryTelemetryPhotoOpened, 'function', 'Photo-open telemetry hook must be installed.');
assert.equal(typeof window.PHPGalleryTelemetryPhotoClosed, 'function', 'Photo-close telemetry hook must be installed.');

nowMs = 100;
assert.equal(window.PHPGalleryTelemetryPhotoOpened(101, 77, 'normal', 'click'), true, 'Initial A activation must emit.');

// Prove deduplication is semantic rather than a short timeout heuristic.
nowMs = 2100;
assert.equal(window.PHPGalleryTelemetryPhotoOpened(101, 77, 'fullscreen', 'keyboard'), false, 'Duplicate A activation must remain a no-op even after 500 ms.');

nowMs = 2600;
assert.equal(window.PHPGalleryTelemetryPhotoOpened(202, 77, 'normal', 'keyboard'), true, 'A -> B must emit B after closing A.');

nowMs = 4100;
assert.equal(window.PHPGalleryTelemetryPhotoClosed(), true, 'Explicit close must close B once.');
assert.equal(window.PHPGalleryTelemetryPhotoClosed(), false, 'Repeated close must be idempotent.');

nowMs = 5200;
assert.equal(window.PHPGalleryTelemetryPhotoOpened(101, 77, 'normal', 'not-allowed'), true, 'A may be counted again after a genuine close.');
nowMs = 6100;
assert.equal(window.PHPGalleryTelemetryPhotoClosed(), true, 'Reopened A must close normally.');

const photoEvents = payloads
    .flatMap((payload) => Array.isArray(payload.events) ? payload.events : [])
    .filter((event) => event.event_name === 'public.photo.opened' || event.event_name === 'public.photo.visible_time');

assert.deepEqual(
    photoEvents.map((event) => [event.event_name, event.image_id]),
    [
        ['public.photo.opened', 101],
        ['public.photo.visible_time', 101],
        ['public.photo.opened', 202],
        ['public.photo.visible_time', 202],
        ['public.photo.opened', 101],
        ['public.photo.visible_time', 101],
    ],
    'Each activation must produce one open and one close/time flush in semantic order.'
);

const opened = photoEvents.filter((event) => event.event_name === 'public.photo.opened');
assert.deepEqual(
    opened.map((event) => event.context?.trigger),
    ['click', 'keyboard', 'unknown'],
    'Activation origin must remain inside the bounded privacy-safe trigger enum.'
);
assert.deepEqual(
    photoEvents.filter((event) => event.event_name === 'public.photo.visible_time').map((event) => event.duration_ms),
    [2500, 1500, 900],
    'Visible-time flushes must measure exactly one interval per activation.'
);

assert.equal(window.PHPGalleryTelemetryPhotoOpened(0, 77, 'normal', 'click'), false, 'Invalid image ids must never create semantic activations.');

console.log('telemetry_photo_lifecycle_test: ok');
