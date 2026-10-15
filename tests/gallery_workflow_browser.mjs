/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_browser.mjs
 * Module Type: Regression Test
 * Purpose: Provide a standalone Chromium workflow fixture mechanism.
 * Responsibilities:
 *   - Launch isolated browser journeys without adding an interactive runtime dependency.
 * Author: Rudolf Klusal
 * Standalone Chromium fixture mechanism; no interactive browser runtime or dependency framework.
 */
import {readFile, realpath} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const [directory, token, executable] = process.argv.slice(2);
try {
    if (!/^[a-f0-9]{24}$/.test(token || '')) throw new Error('Invalid owned fixture identity');
    const actual = await realpath(directory);
    const temporary = await realpath(os.tmpdir());
    if (path.dirname(actual).toLowerCase() !== temporary.toLowerCase() || path.basename(actual) !== `gallery-workflow-${token}`
        || await readFile(path.join(actual, '.workflow-owner'), 'utf8') !== token) throw new Error();
    const {url} = JSON.parse(await readFile(path.join(actual, 'endpoint.json'), 'utf8'));
    if (!/^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(url)) throw new Error();
    let result;
    // Purpose: Bound result polling for the authenticated live Gallery journey below its PHP audit child limit.
    // Type: integer.
    // Units: milliseconds.
    // Scope: Result-polling deadline for this full-stack Chromium regression.
    // Consumers: runHeadlessBrowserFixture() result poller.
    // Rationale: Run 38110623103 reached the 260-second result deadline and used about 7 seconds for outer browser/test cleanup; a 280-second window adds time while retaining 20 seconds within the registered 300-second PHP child budget.
    const workflowBrowserTimeoutMs = 280000;
    // Chromium launchers may exit before their actual browser process on Windows.
    // The owned DevTools session closes the real browser and its cache-holding children.
    const {result: marker, timeoutDetail} = await runHeadlessBrowserFixture(executable, `${url}/__workflow_${token}`,
        'workflow-browser-profile-', {timeoutMs: workflowBrowserTimeoutMs,
            timeoutExpression: 'JSON.stringify({stage:window.__galleryWorkflowStage||"missing",readyState:document.readyState})',
            readResult: async () => {
            try { result = JSON.parse(await readFile(path.join(actual, 'browser-result.json'), 'utf8')); }
            catch { return ''; }
            if (!/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) return 'BROWSER FAIL invalid workflow result';
            return `BROWSER ${result.ok ? 'PASS' : 'FAIL'} ${result.stage}`;
        }});
    if (!marker.startsWith('BROWSER ') || !result) {
        const safeTimeoutDetail = typeof timeoutDetail === 'string'
            ? timeoutDetail.replace(/[^a-zA-Z0-9 ._-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 80) : '';
        throw new Error('Browser result timeout' + (safeTimeoutDetail ? ' ' + safeTimeoutDetail : ''));
    }
    if (!result || !/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) throw new Error('Invalid workflow result identity');
    console.log(`${result.ok ? 'PASS' : 'FAIL'} gallery workflow ${result.stage}`);
    if (!result.ok) {
        // Only the fixture's bounded allowlisted record is printed. Its HTTP
        // endpoint and private directory identify ownership, never diagnostics.
        const safeLine = value => String(value || '').replace(/[^a-zA-Z0-9 .:_()-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400);
        const failure = result.failure;
        if (failure && ['assertion', 'state-timeout', 'http-timeout', 'json', 'application'].includes(failure.category)) {
            console.log('FAIL gallery workflow ' + failure.category + ' ' + safeLine(failure.reason));
            const observed = failure.observed || {};
            console.log('FAIL gallery workflow observed assertions ' + Number(observed.assertions)
                + ' elapsedMs ' + Number(observed.elapsedMs) + ' HTTP ' + Number(observed.lastHttpStatus)
                + ' panelOpen ' + Boolean(observed.panelOpen) + ' galleryCards ' + Number(observed.galleryCards));
            for (const frame of String(failure.stack || '').split('\n').slice(0, 8)) {
                if (frame) console.log('FAIL gallery workflow stack ' + safeLine(frame));
            }
        }
        process.exitCode = 1;
    }
} catch (error) {
    const reason = error instanceof Error ? error.message : 'Unknown browser fixture error';
    const safeReason = reason.replace(/https?:\/\/[^\s)]+/g, 'owned-fixture')
        .replace(/[^a-zA-Z0-9 .:_()-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400);
    console.log('FAIL gallery workflow browser ' + (safeReason || 'launch or result timeout'));
    // Keep original stack frame identities and locations, removing all URL paths,
    // query parameters and the test's private workflow directory/token.
    for (const frame of String(error?.stack || '').split('\n').slice(1, 9)) {
        const safeFrame = frame.replace(/(?:file:\/\/\/|https?:\/\/)[^\s)]+/g, value => {
            const location = /:(\d+):(\d+)$/.exec(value);
            return 'owned-fixture' + (location ? ':' + location[1] + ':' + location[2] : '');
        }).replace(/[^a-zA-Z0-9 .:_()-]/g, ' ').trim().slice(0, 180);
        if (safeFrame) console.log('FAIL gallery workflow stack ' + safeFrame);
    }
    process.exitCode = 1;
}
