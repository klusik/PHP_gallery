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
 *   - Compare rendered colors before and after cropped/original slideshow handoffs.
 *
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
// Purpose: Bound each fixture synchronization step while real viewer transitions settle.
// Type: integer.
// Units: milliseconds.
// Scope: one expected quick-tag keyboard phase in this browser fixture.
// Consumers: interact() phase wait deadline.
// Rationale: the loopback fixture is deterministic, but fullscreenchange and image decode are asynchronous.
const interactionDeadlineMs = 12000;
// Purpose: Pace synchronization with the fixture without generating excessive DevTools traffic.
// Type: integer.
// Units: milliseconds.
// Scope: quick-tag phase polling in the owned browser fixture.
// Consumers: interact() phase observation cadence.
// Rationale: a short interval keeps input close to each fixture checkpoint while remaining bounded.
const interactionPollMs = 20;
// Purpose: Average a flat screenshot patch without sampling JPEG edges or text.
// Type: integer. Units: raster pixels. Scope: slideshow handoff screenshots.
// Consumers: screenshotPatches. Rationale: radius 2 gives a stable 5x5 patch.
const screenshotPatchRadius = 2;
// Purpose: Reject a visible brightness step when the decoded cover is removed.
// Type: number. Units: 8-bit sRGB channel levels. Scope: slideshow handoffs.
// Consumers: interactPixels. Rationale: 1 admits raster rounding, but rejects
// the observed 5-level background addition in the previous compositor path.
const handoffChannelTolerance = 1;
// Purpose: Match the opaque #050505 fullscreen shell in actual screenshots.
// Type: integer. Units: 8-bit sRGB channel levels. Scope: backdrop probes.
// Consumers: interactPixels. Rationale: 5 distinguishes the dark shell from
// the conspicuous magenta/green gallery painted underneath the fixture.
const fullscreenBackingChannel = 5;
if (!executable || executable.startsWith('--')) {
    console.log('SKIP lightbox race browser test: supply the path to an installed Chrome/Edge executable.');
    process.exit(0);
}

/**
 * JSON-compatible value returned by one evaluated fixture expression.
 * @typedef {null|boolean|number|string|Array<LightboxRaceJsonValue>|Record<string,LightboxRaceJsonValue>} LightboxRaceJsonValue
 */

/**
 * Browser protocol operations consumed by the trusted-key fixture driver.
 * @typedef {object} LightboxRaceBrowserControls
 * @property {function(string):Promise<LightboxRaceJsonValue|undefined>} evaluate Evaluates one synchronous page expression and returns its JSON value.
 * @property {function({fullPage?:boolean}=):Promise<string>} captureScreenshot Captures the owned document or visible viewport as a base64 PNG.
 * @property {function('keyDown'|'keyUp',string):Promise<void>} key Sends one trusted browser key transition.
 */

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
        || (relative !== 'tests/fixtures/lightbox_race.html'
            && relative !== 'tests/fixtures/lightbox_content_fit.html'
            && !relative.startsWith('public/assets/'))
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

/**
 * Read flat photograph patches from an actual browser-composited PNG.
 * @param {LightboxRaceBrowserControls} controls Owned fixture protocol operations.
 * @param {string} png Base64 screenshot bytes captured at the current checkpoint.
 * @returns {Promise<number[][]>} Ordered patches, each with mean red/green/blue levels.
 */
async function screenshotPatches(controls, png) {
    const patches = await controls.evaluate(`(async () => {
        const raster = new Image();
        raster.src = ${JSON.stringify(`data:image/png;base64,${png}`)};
        await raster.decode();
        const canvas = document.createElement('canvas');
        canvas.width = raster.naturalWidth;
        canvas.height = raster.naturalHeight;
        const context = canvas.getContext('2d');
        context.drawImage(raster, 0, 0);
        const ratio = raster.naturalWidth / innerWidth;
        if (ratio !== raster.naturalHeight / innerHeight) {
            throw new Error('Viewport screenshot must retain equal CSS-to-raster scale: '
                + JSON.stringify({raster: [raster.naturalWidth, raster.naturalHeight],
                    viewport: [innerWidth, innerHeight]}));
        }
        const radius = ${screenshotPatchRadius};
        const side = radius * 2 + 1;
        const points = JSON.parse(document.querySelector('[data-pixel-advance]')
            .dataset.pixelSamples);
        return points.map(point => {
            const x = Math.round(point.x * ratio) - radius;
            const y = Math.round(point.y * ratio) - radius;
            if (x < 0 || y < 0 || x + side > canvas.width
                || y + side > canvas.height) throw new Error('Patch outside screenshot');
            const pixels = context.getImageData(x, y, side, side).data;
            const channels = [0, 0, 0];
            for (let offset = 0; offset < pixels.length; offset += 4) {
                channels.forEach((value, channel) => {
                    channels[channel] = value + pixels[offset + channel];
                });
            }
            return channels.map(value => value / (side * side));
        });
    })()`);
    assert.ok(Array.isArray(patches) && patches.length > 0,
        'Screenshot must yield real photograph color patches');
    return patches;
}

/**
 * Drive native S entry and compare backing/colors across automatic crop handoffs.
 * @param {LightboxRaceBrowserControls} controls Owned screenshot and fixture controls.
 * @returns {Promise<void>} Resolves after both directions retain their rendered colors.
 */
async function interactPixels(controls) {
    const captures = new Map();
    const deadline = Date.now() + interactionDeadlineMs;
    while (Date.now() < deadline) {
        const phase = await controls.evaluate(
            `document.querySelector('[data-pixel-advance]')?.dataset.pixelPhase || ''`);
        if (typeof phase === 'string' && phase && !captures.has(phase)) {
            if (phase === 'manual-s') {
                captures.set(phase, []);
                await controls.key('keyDown', 's');
                await controls.key('keyUp', 's');
                await controls.evaluate(
                    `document.querySelector('[data-pixel-advance]').click()`);
                continue;
            }
            const png = await controls.captureScreenshot({fullPage: false});
            const patches = await screenshotPatches(controls, png);
            captures.set(phase, patches);
            // The final patch is outside both landscape image fits and the HUD.
            // Its exact 5/5/5 backing must survive mid-fade as well as handoff.
            const backing = patches[patches.length - 1];
            if (backing.some(channel => Math.abs(channel - fullscreenBackingChannel)
                > handoffChannelTolerance)) {
                const diagnostics = await controls.evaluate(`({
                    viewport: [innerWidth, innerHeight],
                    fullscreen: document.fullscreenElement?.className || '',
                    shell: document.querySelector('[data-lightbox]').getBoundingClientRect().toJSON(),
                    outgoing: document.querySelector('[data-lightbox-zoom-surface]').getBoundingClientRect().toJSON(),
                    incoming: document.querySelector('.lightbox-transition-image')?.getBoundingClientRect().toJSON() || null,
                    clip: document.querySelector('.lightbox-transition-image')?.style.clipPath || '',
                    samples: document.querySelector('[data-pixel-advance]').dataset.pixelSamples,
                    capture: 'viewport'
                })`);
                const evidenceRoot = path.join(root, 'cache', 'test-audit');
                await mkdir(evidenceRoot, {recursive: true});
                const evidencePath = path.join(evidenceRoot,
                    `lightbox-backing-${process.pid}.png`);
                await writeFile(evidencePath, Buffer.from(png, 'base64'));
                assert.fail(`${phase}: gallery must never show through fullscreen backing; `
                    + `RGB=${JSON.stringify(backing)}; ${JSON.stringify(diagnostics)}; `
                    + `screenshot=${evidencePath}`);
            }
            if (phase.startsWith('stable-')) {
                const covered = captures.get(phase.replace('stable-', 'covered-'));
                assert.ok(covered, 'The opaque cover must be captured before its live image');
                assert.equal(patches.length, covered.length,
                    'Both screenshots must sample the same photograph patches');
                for (const [patch, channels] of patches.entries()) {
                    for (const [channel, value] of channels.entries()) {
                        assert.ok(Math.abs(value - covered[patch][channel])
                            <= handoffChannelTolerance,
                        `${phase} patch ${patch} channel ${channel}: cover `
                            + `${covered[patch][channel]}, live ${value}`);
                    }
                }
                console.log(`Slideshow pixel evidence ${phase}: ${JSON.stringify(patches)}`);
            }
            await controls.evaluate(
                `document.querySelector('[data-pixel-advance]').click()`);
            if (phase === 'stable-1') return;
        }
        await new Promise(resolve => setTimeout(resolve, interactionPollMs));
    }
    const result = await controls.evaluate(
        `document.querySelector('#results')?.textContent || ''`);
    throw new Error(`Slideshow pixel checkpoints incomplete: ${result}`);
}

try {
    let fullscreenScreenshotPath = '';
    let fullscreenEvidence = null;
    /** Send trusted keyboard input when the production fixture reaches each active-viewer checkpoint.
     * @param {LightboxRaceBrowserControls} controls Protocol utilities used to inspect the fixture and dispatch trusted keys.
     * @returns {Promise<void>} Resolves after the fixture reaches its terminal success marker.
     */
    async function interact(controls) {
        const {evaluate, captureScreenshot, key} = controls;
        const actions = new Map([
            ['normal-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['normal-enter', async () => { await key('keyDown', 'Enter'); await key('keyUp', 'Enter'); }],
            ['normal-reopen-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['normal-escape', async () => { await key('keyDown', 'Escape'); await key('keyUp', 'Escape'); }],
            ['normal-next', async () => evaluate('document.querySelector(\'[data-lightbox-action="next"]\').click()')],
            ['normal-next-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['normal-next-escape', async () => { await key('keyDown', 'Escape'); await key('keyUp', 'Escape'); }],
            ['fullscreen-f', async () => { await key('keyDown', 'f'); await key('keyUp', 'f'); }],
            ['fullscreen-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-enter', async () => { await key('keyDown', 'Enter'); await key('keyUp', 'Enter'); }],
            ['fullscreen-reopen-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-stale-reopen-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-reject-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-stale-reject-reopen-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-escape', async () => { await key('keyDown', 'Escape'); await key('keyUp', 'Escape'); }],
            ['fullscreen-outside-t', async () => { await key('keyDown', 'Shift'); await key('keyDown', 't'); await key('keyUp', 't'); await key('keyUp', 'Shift'); }],
            ['fullscreen-exit', async () => { await key('keyDown', 'Escape'); await key('keyUp', 'Escape'); }],
            ['viewer-close', async () => { await key('keyDown', 'Escape'); await key('keyUp', 'Escape'); }],
        ]);
        const completed = new Set();
        const deadline = Date.now() + interactionDeadlineMs;
        while (Date.now() < deadline) {
            const phase = await evaluate('window.quickTagInputPhase || ""');
            if (phase === 'quick-tag-done') return;
            const action = actions.get(phase);
            if (action && !completed.has(phase)) {
                completed.add(phase);
                await action();
                if (phase === 'fullscreen-t') {
                    fullscreenEvidence = await evaluate(`({
                        mode: document.fullscreenElement === document.querySelector('[data-lightbox]') ? 'native' : 'application',
                        fullscreenElement: document.fullscreenElement?.className || '',
                        viewerClasses: document.querySelector('[data-lightbox]')?.className || '',
                        popupParent: document.querySelector('[data-quick-photo-tag-popover]')?.parentElement?.className || '',
                        popup: (() => { const rect = document.querySelector('[data-quick-photo-tag-popover]').getBoundingClientRect(); return {left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom}; })(),
                        viewport: {width: innerWidth, height: innerHeight},
                    })`);
                    const screenshot = await captureScreenshot();
                    fullscreenScreenshotPath = path.join(os.tmpdir(), 'php-gallery-lightbox-quick-tag-fullscreen.png');
                    await writeFile(fullscreenScreenshotPath, Buffer.from(screenshot, 'base64'));
                }
            }
            await new Promise(resolve => setTimeout(resolve, interactionPollMs));
        }
        const diagnostics = await evaluate(`({phase: window.quickTagInputPhase, readyState: document.readyState,
            result: document.querySelector('#results')?.textContent || '',
            errors: window.quickTagFixtureErrors || [],
            viewerHidden: document.querySelector('[data-lightbox]')?.hidden ?? null,
            activeTitle: document.querySelector('[data-lightbox-title]')?.textContent || ''})`);
        throw new Error(`Fixture did not complete trusted keyboard phases: ${[...completed].join(', ')}; diagnostics=${JSON.stringify(diagnostics)}`);
    }

    /** Run the isolated synthetic-media page in the owned Chromium profile. */
    const {result, exitCode} = await runHeadlessBrowserFixture(executable, fixtureUrl, 'lightbox-race-profile-', {
        timeoutMs: 45000,
        viewport: {width: 1280, height: 900},
        interact,
    });
    console.log(result || 'Browser left the lightbox race fixture without a result.');
    console.log(`Fullscreen evidence: ${JSON.stringify(fullscreenEvidence)}; screenshot: ${fullscreenScreenshotPath || 'not captured'}`);
    assert.equal(exitCode, 0, 'Browser process must finish successfully');
    assert.ok(result?.includes('BROWSER PASS'), 'Lightbox race fixture must complete all assertions');
    // Cover the production cascade with widescreen and narrow portrait stages.
    // Type: Array<{width:number,height:number}>. Units: CSS pixels.
    // Scope: content-fit fixture only. Consumers: runHeadlessBrowserFixture.
    // Rationale: 1440x900 and 390x844 straddle the public mobile layout boundary.
    const contentFitViewports = [
        {width: 1440, height: 900},
        {width: 390, height: 844},
    ];
    for (const viewport of contentFitViewports) {
        const contentFit = await runHeadlessBrowserFixture(executable,
            `${fixtureUrl}tests/fixtures/lightbox_content_fit.html`,
            'lightbox-content-fit-profile-', {viewport});
        console.log(contentFit.result);
        assert.equal(contentFit.exitCode, 0, 'Content-fit browser must exit successfully');
        assert.ok(contentFit.result?.includes('BROWSER PASS'),
            'Content-fit fixture must pass at each supported layout');
        const pixels = await runHeadlessBrowserFixture(executable,
            `${fixtureUrl}tests/fixtures/lightbox_content_fit.html?pixels`,
            'lightbox-handoff-pixels-profile-', {viewport, interact: interactPixels});
        console.log(pixels.result);
        assert.equal(pixels.exitCode, 0, 'Pixel-comparison browser must exit successfully');
        assert.ok(pixels.result?.includes('BROWSER PASS'),
            'Cropped/original handoffs must retain their actual rendered colors');
    }
    // Both physical and Smart metadata must retain the native/touch journeys.
    for (const smart of ['', '&smart']) {
        const nativePixels = await runHeadlessBrowserFixture(executable,
            `${fixtureUrl}tests/fixtures/lightbox_content_fit.html?pixels&native${smart}`,
            'lightbox-native-background-profile-', {
                viewport: contentFitViewports[0], interact: interactPixels,
            });
        console.log(nativePixels.result);
        assert.equal(nativePixels.exitCode, 0, 'Native slideshow browser must exit successfully');
        assert.ok(nativePixels.result?.includes('BROWSER PASS'),
            'Plain photo opening followed by trusted S must retain native fullscreen backing');
        const touchPixels = await runHeadlessBrowserFixture(executable,
            `${fixtureUrl}tests/fixtures/lightbox_content_fit.html?pixels&touch${smart}`,
            'lightbox-touch-background-profile-', {
                viewport: contentFitViewports[1], touchEmulation: true,
                interact: interactPixels,
            });
        console.log(touchPixels.result);
        assert.equal(touchPixels.exitCode, 0, 'Touch slideshow browser must exit successfully');
        assert.ok(touchPixels.result?.includes('BROWSER PASS'),
            'Touch swipe styling must preserve both slideshow opacity transitions');
    }

} finally {
    server.close();
}
