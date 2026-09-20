/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_navigation_timing_test.mjs
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
const source = fs.readFileSync(path.join(root, 'public/assets/usage.js'), 'utf8');
/**
 * Provide an element constructor for the unused delegated-click branch.
 * @return {void} No DOM is allocated by this fixture.
 */
function FixtureElement() {}
/**
 * Discard an unused browser hook while preserving the collector's API surface.
 * @return {void} The corresponding event is not driven by this timing fixture.
 */
function noop() {}
/**
 * Run the real collector with an event-loop fixture, not source-string assertions.
 * @param {string} readyState Initial document load state.
 * @param {Object<string, number>|null} navigation Mutable navigation timing entry.
 * @returns {Object<string, unknown>} Queues and hooks for deterministic post-load assertions.
 */
function fixture(readyState, navigation) {
    const payloads = [], timers = [], listeners = new Map(), storage = new Map();
    const window = {
        PHPGalleryTelemetry: {enabled: true, endpoint: '/usage_collect', routeName: 'gallery', pageKind: 'gallery',
            galleryId: 1, sampleRate: 1, performanceSampleRate: 1},
        PHPGalleryTelemetryLightboxOwner: 'native', innerWidth: 1200,
        crypto: {
            /** Generate one deterministic anonymous fixture ID. @return {string} Local session identity. */
            randomUUID() { return 'navigation-timing-fixture'; },
        },
        /**
         * Queue a task without wall-clock waiting.
         * @param {Function} callback Callback to execute while draining the fixture.
         * @param {number} delay Requested delay retained for assertions.
         * @return {number} Local timer identity.
         */
        setTimeout(callback, delay) { timers.push({callback, delay}); return timers.length; },
        clearTimeout: noop,
        /**
         * Capture a browser event hook for explicit fixture dispatch.
         * @param {string} name Event name.
         * @param {Function} fn Event callback.
         * @return {void} Saves the callback without dispatching it.
         */
        addEventListener(name, fn) { listeners.set(name, fn); },
    };
    const document = {readyState, visibilityState: 'visible', fullscreenElement: null, addEventListener: noop,
        /** Report no fallback lightbox DOM. @return {null} No matching node exists. */
        querySelector() { return null; },
    };
    const context = vm.createContext({Blob, Date, Math, Number, String, console, Element: FixtureElement, window, document,
        navigator: {doNotTrack: '0', userAgent: 'Chrome/120', platform: 'Linux', language: 'en',
            /** Exercise the fetch transport instead of beacon. @return {boolean} Beacon is unavailable. */
            sendBeacon() { return false; },
        },
        performance: {
            /** Return a stable fixture clock. @return {number} Monotonic milliseconds. */
            now() { return 1000; },
            /** Return the mutable navigation entry. @return {Array<Object<string, number>>} Zero or one navigation record. */
            getEntriesByType() { return navigation ? [navigation] : []; },
        },
        sessionStorage: {
            /** Read isolated session state. @param {string} key Storage key. @return {string|null} Persisted fixture value. */
            getItem(key) { return storage.get(key) ?? null; },
            /** Write isolated session state. @param {string} key Storage key. @param {string} value Stored value. @return {void} Updates the local map. */
            setItem(key, value) { storage.set(key, String(value)); },
        },
        /**
         * Capture the real collector payload without making network requests.
         * @param {string} url Endpoint, checked against the isolated fixture configuration.
         * @param {Object<string, unknown>} options Fetch options including the serialized batch.
         * @return {Promise<Object<string, boolean>>} Successful local transport response.
         */
        fetch(url, options) { assert.equal(url, '/usage_collect'); payloads.push(JSON.parse(options.body)); return Promise.resolve({ok: true}); },
    });
    vm.runInContext(source, context, {filename: 'usage.js'});
    return {timers, listeners,
        /** Read captured events without losing their original order. @return {Array<Object<string, unknown>>} All transmitted events. */
        events() {
            const events = [];
            for (const payload of payloads) { events.push(...(payload.events ?? [])); }
            return events;
        },
        /** Drain a bounded queue of initial tasks. @return {void} Executes queued callbacks in order. */
        drain() { let n = 0; while (timers.length) { assert.ok(n++ < 20, 'Unbounded timer loop'); timers.shift().callback(); } },
    };
}
/**
 * Select page-load samples from the captured telemetry batch.
 * @param {Array<Object<string, unknown>>} events Transmitted fixture events.
 * @return {Array<Object<string, unknown>>} Only navigation-timing samples.
 */
function pageLoads(events) {
    const result = [];
    for (const event of events) { if (event.event_name === 'client.performance.page_load') { result.push(event); } }
    return result;
}
const timing = {startTime: 0, loadEventEnd: 0, duration: 0};
const duringLoad = fixture('loading', timing);
assert.ok(duringLoad.listeners.has('load'));
duringLoad.listeners.get('load')();
assert.equal(pageLoads(duringLoad.events()).length, 0, 'No measurement inside load handler.');
timing.loadEventEnd = 1234.5;
duringLoad.drain();
const loads = pageLoads(duringLoad.events());
assert.equal(loads.length, 1);
assert.equal(loads[0].value_ms, 1235);
const alreadyLoaded = fixture('complete', {startTime: 20, loadEventEnd: 1020});
alreadyLoaded.drain();
assert.equal(pageLoads(alreadyLoaded.events())[0].value_ms, 1000, 'Late-loaded collector must still measure navigation.');
for (const entry of [null, {startTime: 0, loadEventEnd: 0}, {startTime: 0, loadEventEnd: NaN}]) {
    const invalid = fixture('complete', entry); invalid.drain();
    assert.equal(pageLoads(invalid.events()).length, 0, 'Unknown or zero timing is not a zero-ms sample.');
}
assert.equal(source, fs.readFileSync(path.join(root, 'public/assets/telemetry.js'), 'utf8'));
console.log('Navigation timing event-loop fixtures passed.');
