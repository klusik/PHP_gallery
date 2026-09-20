/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_report_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify the bounded report policy migration with disposable request and timer seams.
 * Responsibilities:
 *   - Exercise real report window normalization, retry order and response handling without network access.
 *   - Prove both report actions defer batch sizing to the server and preserve existing transport fields.
 *   - Lock documented public policy values and the refreshed report import edges.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import * as policy from '../public/assets/gallery-modules/admin-interaction-policy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const directory = path.join(root, 'public/assets/gallery-modules');
const source = await readFile(path.join(directory, 'admin-gallery-report.js'), 'utf8');
const policySource = await readFile(path.join(directory, 'admin-interaction-policy.js'), 'utf8');
/** Pre-migration report values; fixture literals deliberately do not derive expected behavior from production constants. @type {Record<string,number|ReadonlyArray<number>>} */
const expected = {
    ADMIN_GALLERY_REPORT_DEFAULT_TELEMETRY_DAYS: 30,
    ADMIN_GALLERY_REPORT_MIN_TELEMETRY_DAYS: 1,
    ADMIN_GALLERY_REPORT_MAX_TELEMETRY_DAYS: 3650,
    ADMIN_GALLERY_REPORT_REQUEST_ATTEMPT_LIMIT: 4,
    ADMIN_GALLERY_REPORT_RETRY_BASE_DELAY_MS: 750,
    ADMIN_GALLERY_REPORT_RETRY_BACKOFF_FACTOR: 2,
    ADMIN_GALLERY_REPORT_RETRYABLE_HTTP_STATUSES: [408, 429, 500, 502, 503, 504],
};
for (const [name, value] of Object.entries(expected)) {
    assert.deepEqual(policy[name], value, name + ' preserves its reviewed value');
    const definition = policySource.match(new RegExp('/\\*\\*([^]*?)\\*/\\s*export const ' + name + ' ='));
    assert.ok(definition, name + ' has attached documentation');
    const comment = definition[1].split('/**').at(-1);
    const typeTag = Array.isArray(value) ? '@var {ReadonlyArray<number>}' : '@var {number}';
    for (const field of [typeTag, 'Units:', 'Scope:', 'Consumers:', 'Rationale:', 'Range:']) {
        assert.ok(comment.includes(field), name + ' documents ' + field);
    }
}
assert.ok(Object.isFrozen(policy.ADMIN_GALLERY_REPORT_RETRYABLE_HTTP_STATUSES));
assert.doesNotMatch(policySource.replace(/\/\*[^]*?\*\//g, ''), /\bimport\s|\bfetch\s*\(|\bdocument\./);
assert.match(source, /from '\.\/admin-interaction-policy\.js\?v=20260920-admin-interaction-policy-report-v2'/);
assert.doesNotMatch(source, /body\.set\(['"]batch_size['"]/);
const entry = await readFile(path.join(root, 'public/assets/gallery.js'), 'utf8');
assert.ok(entry.includes('admin-gallery-report.js?v=20260920-admin-gallery-report-policy-v3'));

/**
 * Synthetic select marker used only by the report window reader's instanceof check.
 * @property {string} value Untrusted window input assigned by each test case.
 */
class ReportFixtureSelect {}

/**
 * Actual report request fields observed without serializing credentials or issuing HTTP.
 * @typedef {Object} ReportFixtureRequest
 * @property {string} method Production HTTP method.
 * @property {FormData} body In-memory action/window fields; token is an inert fixture marker.
 * @property {Record<string,string>} headers Production Accept header.
 */

/**
 * Construct an isolated report consumer with a finite response queue and immediate recorded timers.
 * @param {Array<Response|Error>} replies Synthetic responses or transport errors, consumed in request order.
 * @return {{context:vm.Context,calls:Array<{endpoint:string,options:ReportFixtureRequest}>,events:string[]}} VM API and observable request/wait sequence; no timer or network survives this fixture.
 */
function reportSeam(replies = []) {
    const queue = [...replies];
    const calls = [];
    const events = [];
    const context = vm.createContext({
        ...policy, Error, FormData, HTMLSelectElement: ReportFixtureSelect,
        window: {
            /**
             * Record backoff and settle its Promise immediately without wall-clock waiting.
             * @param {function():void} callback Production retry-loop resolver.
             * @param {number} delay Requested delay in milliseconds.
             * @return {number} Synthetic timer handle; this fixture leaves no scheduled work.
             */
            setTimeout(callback, delay) {
                events.push('wait:' + delay);
                callback();
                return events.length;
            },
        },
        /**
         * Consume one explicit reply while rejecting any accidental extra request.
         * @param {string} endpoint Inert fixture endpoint, never fetched.
         * @param {ReportFixtureRequest} options Actual production request envelope.
         * @return {Promise<Response>} Queued response; a queued Error rejects with the same identity.
         */
        async fetch(endpoint, options) {
            calls.push({endpoint, options});
            events.push('fetch');
            assert.ok(queue.length > 0, 'No unplanned request beyond the fixture queue');
            const next = queue.shift();
            if (next instanceof Error) {
                throw next;
            }
            return next;
        },
        /**
         * Keep status/error wording local rather than importing application translation state.
         * @param {string} key Public translation identifier, unused by the fixture.
         * @param {string} fallback Existing public fallback text.
         * @return {string} Fallback text for deterministic assertions.
         */
        i18n(key, fallback) { return fallback; },
    });
    const executable = source.replace(/^import\s+[^]*?\sfrom\s+['"][^'"]+['"];\s*/gm, '').replace(/^export /gm, '');
    vm.runInContext(executable, context);
    return {context, calls, events};
}

const selected = new ReportFixtureSelect();
const panel = {
    /**
     * Return only the report's synthetic window select.
     * @param {string} selector Production control selector.
     * @return {ReportFixtureSelect} Isolated input; no real DOM is accessed.
     */
    querySelector(selector) {
        assert.equal(selector, '[data-admin-gallery-report-telemetry-days]');
        return selected;
    },
};
const normalizer = reportSeam();
for (const [input, days] of [
    ['', 30], ['invalid', 30], ['Infinity', 30], ['-Infinity', 30],
    ['0', 1], ['-6', 1], ['1', 1], ['30', 30], ['73', 73],
    ['3650', 3650], ['99999', 3650], ['1.75', 1.75], ['  ', 1],
]) {
    selected.value = input;
    assert.equal(normalizer.context.readTelemetryDays(panel), days, input + ' retains Number conversion and bounds');
}
assert.equal(normalizer.context.readTelemetryDays({
    /** Omit the optional selector entirely. @return {null} Absent control. */
    querySelector() { return null; },
}), 30);
assert.equal(normalizer.context.readTelemetryDays({
    /** Supply a non-select node with a misleading value. @return {{value:string}} Not an HTMLSelectElement fixture. */
    querySelector() { return {value: '77'}; },
}), 30);
assert.equal(normalizer.calls.length, 0, 'Window normalization issues no requests');

const body = new FormData();
const success = new Response('{}', {status: 200});
const immediate = reportSeam([success]);
assert.equal(await immediate.context.fetchGalleryReportWithRetry('/fixture-report', body), success);
assert.deepEqual(immediate.events, ['fetch'], 'There is no initial delay');
for (const status of [408, 429, 500, 502, 503, 504]) {
    const final = new Response('{}', {status});
    const retry = reportSeam([
        new Response('{}', {status}), new Response('{}', {status}),
        new Response('{}', {status}), final,
    ]);
    assert.equal(await retry.context.fetchGalleryReportWithRetry('/fixture-report', body), final);
    assert.deepEqual(retry.events, ['fetch', 'wait:750', 'fetch', 'wait:1500', 'fetch', 'wait:3000', 'fetch']);
    assert.equal(retry.calls.length, 4, status + ' exhausts exactly four total attempts');
    for (const call of retry.calls) {
        assert.equal(call.endpoint, '/fixture-report');
        assert.equal(call.options.method, 'POST');
        assert.equal(call.options.body, body, 'Retry reuses the same action body');
        assert.equal(call.options.headers.Accept, 'application/json');
        assert.equal(call.options.signal, undefined, 'This refactor adds no request deadline');
    }
}
for (const status of [400, 401, 403, 404, 409, 422, 501]) {
    const final = new Response('{}', {status});
    const refusal = reportSeam([final]);
    assert.equal(await refusal.context.fetchGalleryReportWithRetry('/fixture-report', body), final);
    assert.deepEqual(refusal.events, ['fetch'], status + ' must not retry');
}
const mixedSuccess = new Response('{}');
const mixed = reportSeam([new Error('fixture disconnect'), new Response('{}', {status: 503}), mixedSuccess]);
assert.equal(await mixed.context.fetchGalleryReportWithRetry('/fixture-report', body), mixedSuccess);
assert.deepEqual(mixed.events, ['fetch', 'wait:750', 'fetch', 'wait:1500', 'fetch']);
const lastError = new Error('fixture final disconnect');
const transport = reportSeam([new Error('one'), new Error('two'), new Error('three'), lastError]);
let caught = null;
try {
    await transport.context.fetchGalleryReportWithRetry('/fixture-report', body);
} catch (error) {
    caught = error;
}
assert.equal(caught, lastError, 'Final transport error identity remains unchanged');
assert.deepEqual(transport.events, ['fetch', 'wait:750', 'fetch', 'wait:1500', 'fetch', 'wait:3000', 'fetch']);

for (const action of ['start', 'step']) {
    const payload = {ok: true, status: 'running', processed: 7, total: 91, percent: 7.7};
    const request = reportSeam([new Response(JSON.stringify(payload))]);
    assert.deepEqual(await request.context.postGalleryReportAction('/fixture-report', 'fixture-token', action, 73), payload);
    const fields = request.calls[0].options.body;
    assert.equal(fields.get('action'), action);
    assert.equal(fields.get('csrf_token'), 'fixture-token');
    assert.equal(fields.get('telemetry_days'), '73');
    assert.equal(fields.has('batch_size'), false, action + ' defers work sizing to server Core policy');
    assert.deepEqual([...fields.keys()], ['csrf_token', 'action', 'telemetry_days']);
}
const completed = reportSeam([new Response('<html>fixture report</html>', {headers: {
    'X-Gallery-Report-Complete': '1',
    'X-Gallery-Report-Filename': 'fixture%20report.html',
    'X-Gallery-Report-Bytes': '27',
}})]);
const download = await completed.context.postGalleryReportAction('/fixture-report', 'fixture-token', 'step', 30);
assert.equal(download.ok, true);
assert.equal(download.status, 'complete');
assert.equal(download.report_html, '<html>fixture report</html>');
assert.equal(download.filename, 'fixture report.html');
assert.equal(download.report_bytes, 27);
const forbidden = reportSeam([new Response('{}', {status: 403})]);
await assert.rejects(forbidden.context.postGalleryReportAction('/fixture-report', 'fixture-token', 'start', 30),
    /Gallery report request failed/);
assert.deepEqual(forbidden.events, ['fetch']);
const invalidJson = reportSeam([new Response('not JSON')]);
await assert.rejects(invalidJson.context.postGalleryReportAction('/fixture-report', 'fixture-token', 'step', 30),
    /Gallery report response was not valid JSON/);
assert.deepEqual(invalidJson.events, ['fetch'], 'JSON decode failure is not a transport retry');
console.log('gallery_report_policy_test: PASS (7 report policies, window bounds, request attempts/backoff, no batch override, HTML/JSON response seams; not browser acceptance)');

