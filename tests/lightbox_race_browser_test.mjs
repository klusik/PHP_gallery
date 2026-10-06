/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_race_browser_test.mjs
 * Module Type: Browser Regression Test
 *
 * Purpose:
 *   Exercise real lightbox image races and same-task original promotion in Chromium.
 *
 * Responsibilities:
 *   - Gate actual local image responses and browser decode completion.
 *   - Deliver sparse metadata and decoded images out of order.
 *   - Verify close/reopen invalidation, resource cancellation, and zoom promotion.
 *
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    console.log('SKIP lightbox race browser test: supply the path to an installed Chrome/Edge executable.');
    process.exit(0);
}

const imageResponses = [];
const failedImagePaths = new Set();
const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="steelblue"/></svg>';

/** Send bounded JSON used by the fixture to inspect and release its own pending image requests.
 * @param {import('node:http').ServerResponse} response HTTP response owned by this loopback server.
 * @param {unknown} value JSON-compatible fixture response.
 * @return {void} Finishes the fixture response.
 */
function sendJson(response, value) {
    response.writeHead(200, {'Content-Type': 'application/json', 'Cache-Control': 'no-store'});
    response.end(JSON.stringify(value));
}

/** Handle an image request by holding its actual browser response until the fixture releases it.
 * @param {import('node:http').IncomingMessage} request Browser image request.
 * @param {import('node:http').ServerResponse} response Browser image response to hold.
 * @param {string} requestPath Path and query used to identify this request.
 * @return {void} Records and holds the response.
 */
function holdImageResponse(request, response, requestPath) {
    const previousFailure = failedImagePaths.has(requestPath);
    const previousValidResponse = !previousFailure && imageResponses.some(item => item.path === requestPath && item.valid);
    const completed = previousFailure || previousValidResponse;
    const record = {path: requestPath, response, released: completed, aborted: false, valid: previousValidResponse};
    imageResponses.push(record);
    request.on('aborted', () => { record.aborted = true; });
    response.on('close', () => {
        if (!record.released) record.aborted = true;
    });
    response.writeHead(200, {'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-store'});
    if (previousFailure) {
        response.end('invalid image response');
    } else if (previousValidResponse) {
        response.end(svg);
    }
}

/** Release the first matching live image response with a valid intrinsic-size SVG.
 * @param {import('node:http').ServerResponse} response Control request response.
 * @param {string} requestPath Path and query of the held media response.
 * @return {void} Completes the held image response or reports a missing request.
 */
function releaseImageResponse(response, requestPath) {
    const record = imageResponses.find(item => item.path === requestPath && !item.released && !item.aborted);
    if (!record) {
        sendJson(response, {ok: false, message: 'No pending image response for the requested source.'});
        return;
    }
    record.released = true;
    record.valid = true;
    record.response.end(svg);
    sendJson(response, {ok: true});
}

/** Fail held and subsequent responses for an exact image URL with invalid image bytes.
 * @param {import('node:http').ServerResponse} response Control request response.
 * @param {string} requestPath Path and query of the held media response.
 * @return {void} Completes all matching image responses with undecodable bytes.
 */
function failImageResponses(response, requestPath) {
    failedImagePaths.add(requestPath);
    const records = imageResponses.filter(item => item.path === requestPath && !item.released && !item.aborted);
    records.forEach(record => {
        record.released = true;
        record.valid = false;
        record.response.end('invalid image response');
    });
    sendJson(response, {ok: records.length > 0, count: records.length});
}

const server = createServer(async (request, response) => {
    const url = new URL(request.url || '/', 'http://127.0.0.1');
    if (url.pathname.startsWith('/media/')) {
        holdImageResponse(request, response, url.pathname + url.search);
        return;
    }
    if (url.pathname === '/__fixture/stats') {
        sendJson(response, {images: imageResponses.map(({path: requestPath, released, aborted, valid}) => ({path: requestPath, released, aborted, valid}))});
        return;
    }
    if (url.pathname === '/__fixture/release-image') {
        releaseImageResponse(response, url.searchParams.get('path') || '');
        return;
    }
    if (url.pathname === '/__fixture/fail-image') {
        failImageResponses(response, url.searchParams.get('path') || '');
        return;
    }

    const relative = url.pathname === '/' ? 'tests/fixtures/lightbox_race.html' : url.pathname.slice(1);
    const target = path.resolve(root, relative);
    if (
        !target.startsWith(root + path.sep)
        || (relative !== 'tests/fixtures/lightbox_race.html' && !relative.startsWith('public/assets/'))
    ) {
        response.writeHead(404).end();
        return;
    }
    try {
        const body = await readFile(target);
        const contentType = relative.endsWith('.html') ? 'text/html; charset=utf-8'
            : relative.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8';
        response.writeHead(200, {'Content-Type': contentType, 'Cache-Control': 'no-store'});
        response.end(body);
    } catch {
        response.writeHead(404).end();
    }
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const fixtureUrl = `http://127.0.0.1:${server.address().port}/`;
try {
    /** Run the isolated synthetic-media page in the confined Chromium profile. */
    const {result, exitCode} = await runHeadlessBrowserFixture(executable, fixtureUrl, 'lightbox-race-profile-', {timeoutMs: 45000});
    console.log(result || 'Browser left the lightbox race fixture without a result.');
    assert.equal(exitCode, 0, 'Browser process must finish successfully');
    assert.ok(result?.includes('BROWSER PASS'), 'Lightbox race fixture must complete all assertions');
} finally {
    server.close();
}
