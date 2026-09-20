/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_migration_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify unchanged migration reconnect and retry policy using disposable request seams.
 * Responsibilities:
 *   - Exercise the current migration module's normalization, probe ordering and attempt limits.
 *   - Assert timeout cleanup and cancellation without network, configuration or persistent data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import * as policy from '../public/assets/gallery-modules/gallery-migration-policy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const directory = path.join(root, 'public/assets/gallery-modules');
const source = await readFile(path.join(directory, 'admin-gallery-migration.js'), 'utf8');
const policySource = await readFile(path.join(directory, 'gallery-migration-policy.js'), 'utf8');
/** Exact pre-migration public values with their documented units. @type {Record<string,number>} */
const expected = {
    GALLERY_MIGRATION_DEFAULT_RECONNECT_SECONDS: 30,
    GALLERY_MIGRATION_MIN_RECONNECT_SECONDS: 5,
    GALLERY_MIGRATION_MAX_RECONNECT_SECONDS: 300,
    GALLERY_MIGRATION_PACKAGE_ATTEMPT_LIMIT: 6,
    GALLERY_MIGRATION_STATUS_PROBE_LIMIT: 4,
    GALLERY_MIGRATION_STATUS_PROBE_DELAY_MS: 1500,
};
assert.deepEqual({...policy}, expected);
assert.match(source, /from '\.\/gallery-migration-policy\.js\?v=20260920-gallery-migration-policy-v1'/);
for (const name of Object.keys(expected)) {
    const match = policySource.match(new RegExp('/\\*\\*([^]*?)\\*/\\s*export const ' + name + ' ='));
    assert.ok(match, name + ' has an attached policy contract');
    const comment = match[1].split('/**').at(-1);
    for (const field of ['@var {number}', 'Units:', 'Scope:', 'Consumers:', 'Rationale:', 'Range:']) {
        assert.ok(comment.includes(field), name + ' documents ' + field);
    }
}
assert.doesNotMatch(policySource.replace(/\/\*[^]*?\*\//g, ''), /\bimport\s|\bfetch\s*\(|\bdocument\./);

/**
 * Disposable type marker for the form's input and owning panel.
 * @property {Record<string,string>} dataset Synthetic data attributes, assigned only by this test.
 * @property {string} value Synthetic reconnect value on the input instance.
 */
class FixtureElement {}

/**
 * Minimal form contract read by the production migration consumer in this fixture.
 * @typedef {Object} MigrationFixtureForm
 * @property {{galleryMigrationCancelled:string}} dataset Explicit cancellation flag.
 * @property {{namedItem:Function}} elements Resolves only the synthetic reconnect input.
 * @property {Function} closest Resolves the fixture panel carrying its inert endpoint.
 * @property {Function} querySelector Returns null because optional progress/log nodes are omitted.
 */

/** In-memory FormData replacement; never serializes or transmits a credential. */
class FixtureFormData extends Map {
    /**
     * Ignore browser form construction; tested explicit fields are recorded by inherited Map.set.
     * @param {MigrationFixtureForm} form Synthetic form; no real DOM or submitted secrets.
     * @return {void} Creates empty per-request fixture storage.
     */
    constructor(form) { super(); }
}

/** Recorded timer handles map to callbacks and millisecond delays; no real timer runs. @type {Map<number,{callback:Function,delay:number}>} */
const timers = new Map();
let timerSerial = 0;
/** Current module globals use only fixture-owned constructors and recorded scheduling. */
const context = vm.createContext({
    Error, DOMException, AbortController, console,
    HTMLElement: FixtureElement, HTMLInputElement: FixtureElement, FormData: FixtureFormData,
    window: {
        /** Queue a request deadline without waiting. @param {Function} callback Deadline work. @param {number} delay Milliseconds. @return {number} Fixture handle. */
        setTimeout(callback, delay) { timers.set(++timerSerial, {callback, delay}); return timerSerial; },
        /** Remove exactly one request deadline. @param {number} handle Fixture handle. @return {void} Cancels recorded work. */
        clearTimeout(handle) { timers.delete(handle); },
    },
    /** Keep translations fixture-local. @param {string} key Public key. @param {string} fallback Public fallback. @return {string} Fallback text. */
    i18n(key, fallback) { return fallback; },
});
const executable = source.replace(/^import\s+[^]*?\sfrom\s+['"][^'"]+['"];\s*/gm, '').replace(/^export /gm, '');
vm.runInContext(policySource.replace(/^export /gm, '') + '\n' + executable, context);
const originalConfirm = context.confirmPackageOnReconnect;
const originalPost = context.postMigrationStep;
const originalStatus = context.requestMigrationStatus;

/** Explicit in-memory request targets, not network addresses. */
const panel = new FixtureElement();
panel.dataset = {galleryMigrationEndpoint: '/fixture-migration'};
const control = new FixtureElement();
control.value = '37';
const form = {
    dataset: {galleryMigrationCancelled: '0'},
    elements: {
        /** Return only the synthetic reconnect input. @param {string} name Field name. @return {FixtureElement|null} Input or absent checkbox. */
        namedItem(name) { return name === 'reconnect_seconds' ? control : null; },
    },
    /** Resolve the owning fixture panel. @return {FixtureElement} Panel. */
    closest() { return panel; },
    /** Omit optional progress/log nodes. @return {null} No rendering target. */
    querySelector() { return null; },
};
for (const [input, result] of [[NaN, 30], [Infinity, 30], [-8, 5], [0, 5], [5, 5], [37.6, 38], [300, 300], [9999, 300]]) {
    assert.equal(context.clampReconnectSeconds(input), result);
}
assert.equal(context.getReconnectSeconds(form), 37, 'The administrator input is not replaced by the fallback');
control.value = '42.8';
assert.equal(context.getReconnectSeconds(form), 42, 'Existing parseInt form semantics remain unchanged');
control.value = 'invalid';
assert.equal(context.getReconnectSeconds(form), 30);
control.value = '37';

let capturedRequest;
context.fetch =
/**
 * Capture the actual request envelope and keep response data synthetic.
 * @param {string} endpoint Local fixture marker, never fetched.
 * @param {{method:string,body:FixtureFormData,headers:Record<string,string>,signal:AbortSignal}} options Production request envelope retained for assertions.
 * @return {Promise<{ok:boolean,json:Function}>} Successful stub response.
 */
async (endpoint, options) => {
    capturedRequest = {endpoint, options};
    return {
        ok: true,
        /** Decode a fixture-only reply. @return {Promise<{ok:boolean}>} Success record. */
        json: async () => ({ok: true}),
    };
};
const pendingPost = originalPost(form, 'push_status', {job_id: 'fixture-job'}, {timeoutSeconds: 9999});
assert.equal([...timers.values()][0].delay, 300000, 'Request timeout remains capped and converted from seconds to milliseconds');
await pendingPost;
assert.equal(timers.size, 0, 'Completed fetch clears its deadline');
assert.equal(capturedRequest.options.method, 'POST');
assert.equal(capturedRequest.options.body.get('action'), 'push_status');
assert.equal(capturedRequest.options.body.get('job_id'), 'fixture-job');
const zeroOption = originalPost(form, 'push_status', {}, {timeoutSeconds: 0});
assert.equal([...timers.values()][0].delay, 37000, 'Zero option retains the existing form-value fallback');
await zeroOption;

context.fetch =
/**
 * Reject only when the production deadline aborts this disposable request.
 * @param {string} endpoint Ignored fixture endpoint.
 * @param {{method:string,body:FixtureFormData,headers:Record<string,string>,signal:AbortSignal}} options Production request envelope; only its abort signal is observed here.
 * @return {Promise<never>} Synthetic cancellation, without an HTTP operation.
 */
(endpoint, options) => new Promise(
    /**
     * Observe the production abort signal.
     * @param {Function} resolve Unused response resolver.
     * @param {Function} reject Fixture cancellation receiver.
     * @return {void} Registers the abort listener.
     */
    (resolve, reject) => options.signal.addEventListener('abort',
        /** Reject the synthetic fetch at its deadline. @return {void} Emits a native-shaped abort. */
        () => reject(new DOMException('Fixture deadline', 'AbortError')), {once: true})
);
const timedPost = originalPost(form, 'push_package', {}, {timeoutSeconds: 5});
const rejection = assert.rejects(timedPost, {name: 'GalleryMigrationTimeoutError'});
assert.equal([...timers.values()][0].delay, 5000);
[...timers.values()][0].callback();
await rejection;
assert.equal(timers.size, 0, 'Aborted fetch also releases its deadline');

/** Probe-delay observations are milliseconds, with no elapsed-time assertions. @type {number[]} */
const waits = [];
context.sleep =
/** Record probe spacing without a real sleep. @param {number} delay Milliseconds. @return {Promise<void>} Immediate completion. */
async delay => { waits.push(delay); };
let probes = 0;
context.requestMigrationStatus =
/** Return an incomplete remote receipt. @return {Promise<{received_asset_keys:string[]}>} No received assets. */
async () => { probes++; return {received_asset_keys: []}; };
const descriptor = {package_id: 'fixture-package', asset_keys: ['fixture-asset'], assets: []};
await originalConfirm(form, 'source_push', descriptor, 'fixture-job', 30);
assert.equal(probes, 4);
assert.deepEqual(waits, [1500, 1500, 1500], 'No wait before the first probe');
waits.length = 0;
probes = 0;
context.requestMigrationStatus =
/** Confirm only on the third probe. @return {Promise<{received_asset_keys:string[]}>} Synthetic eventual receipt. */
async () => ({received_asset_keys: ++probes === 3 ? ['fixture-asset'] : []});
await originalConfirm(form, 'source_push', descriptor, 'fixture-job', 30);
assert.equal(probes, 3);
assert.deepEqual(waits, [1500, 1500]);
const statusFailure = new Error('Fixture status unavailable');
context.requestMigrationStatus =
/** Simulate unavailable remote status. @return {Promise<never>} Rejected fixture observation. */
async () => { throw statusFailure; };
const unavailableStatus = context.requestMigrationStatus;
await assert.rejects(originalConfirm(form, 'source_push', descriptor, 'fixture-job', 30), {message: statusFailure.message});

let attempts = 0;
let confirmations = 0;
const transferFailure = new Error('Fixture transfer interrupted');
context.postMigrationStep =
/** Interrupt every synthetic transfer. @return {Promise<never>} A recoverable transfer failure. */
async () => { attempts++; throw transferFailure; };
context.confirmPackageOnReconnect =
/** Keep receipt incomplete while counting every recovery decision. @return {Promise<{received_asset_keys:string[]}>} No receipt. */
async () => { confirmations++; return {received_asset_keys: []}; };
await assert.rejects(context.transferPackageWithReconnect(form, 'source_push', 'push_package', descriptor, 'fixture-job', 30),
    /** Preserve the actual final transfer error. @param {Error} error Rejection. @return {boolean} Whether unchanged. */
    error => error === transferFailure);
assert.equal(attempts, 6, 'Six total attempts, not six retries plus an initial request');
assert.equal(confirmations, 6, 'Receipt is checked after every failed attempt, including the final one');

attempts = 0;
confirmations = 0;
context.confirmPackageOnReconnect =
/** Report receipt after the first interrupted request. @return {Promise<{received_asset_keys:string[]}>} Complete receipt. */
async () => { confirmations++; return {received_asset_keys: ['fixture-asset']}; };
const recovered = await context.transferPackageWithReconnect(form, 'source_push', 'push_package', descriptor, 'fixture-job', 30);
assert.equal(attempts, 1);
assert.equal(confirmations, 1);
assert.deepEqual(Array.from(recovered.received_asset_keys), ['fixture-asset'], 'Never resend a confirmed received package');
form.dataset.galleryMigrationCancelled = '1';
attempts = 0;
await assert.rejects(context.transferPackageWithReconnect(form, 'source_push', 'push_package', descriptor, 'fixture-job', 30));
assert.equal(attempts, 0, 'Cancellation still prevents the next package attempt');
form.dataset.galleryMigrationCancelled = '0';

// Exercise a real four-probe refusal through the transfer owner: unknown receipt never permits a resend.
context.confirmPackageOnReconnect = originalConfirm;
context.requestMigrationStatus = unavailableStatus;
attempts = 0;
await assert.rejects(context.transferPackageWithReconnect(form, 'source_push', 'push_package', descriptor, 'fixture-job', 30));
assert.equal(attempts, 1, 'Unknown remote receipt refuses retries rather than risking duplicate transfer');
context.postMigrationStep = originalPost;
context.requestMigrationStatus = originalStatus;

const entry = await readFile(path.join(root, 'public/assets/gallery.js'), 'utf8');
assert.ok(entry.includes('admin-gallery-migration.js?v=20260920-gallery-migration-policy-v3'));
const titleBrowser = await readFile(path.join(root, 'tests/admin_gallery_title_completion_browser_test.mjs'), 'utf8');
assert.ok(titleBrowser.includes("['/admin-interaction-policy.js', 'public/assets/gallery-modules/admin-interaction-policy.js']"));
const benchmark = await readFile(path.join(root, 'scripts/benchmark_title_completion_browser.mjs'), 'utf8');
assert.ok(benchmark.includes("'/admin-interaction-policy.js'") && benchmark.includes('current_policy_sha256'));
console.log('gallery_migration_policy_test: PASS (six unchanged policies, attempt/probe ordering, deadline cleanup; no network or browser acceptance)');
