/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_cron_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise production Cron Overview markup, CSS and asynchronous behavior.
 * Responsibilities: Verify density, sorting, passive single-flight reads and stale suppression.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP Cron Overview browser: no Chromium executable supplied.');
    process.exit(0);
}
/** Bound isolated PHP rendering. Type: number. Units: milliseconds. Scope: one fixture renderer. Consumers: execFileSync. Rationale: 10000 ms detects a hanging renderer well before the browser suite deadline. */
const RENDER_TIMEOUT_MS = 10000;
/**
 * Exercise both sides of the production mobile breakpoint.
 * Type: Array<{width:number,height:number}>. Units: CSS pixels.
 * Scope: two isolated browser fixture documents. Consumers: viewport emulation.
 * Rationale: 1200x800 verifies desktop density; 390x844 catches narrow-grid
 * overflow and the required mobile touch targets in a typical phone viewport.
 */
const VIEWPORTS = [{width: 1200, height: 800}, {width: 390, height: 844}];
const markup = execFileSync(process.env.PHP_BINARY || 'php',
    [path.join(root, 'tests/fixtures/admin_cron_view.php')],
    {encoding: 'utf8', windowsHide: true, timeout: RENDER_TIMEOUT_MS});
const html = String.raw`<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/public/assets/styles/base.css">
<link rel="stylesheet" href="/public/assets/styles/admin.css">
<link rel="stylesheet" href="/public/assets/styles/admin-cron.css">
<link rel="stylesheet" href="/public/assets/styles/admin-cinematic.css">
</head><body class="admin-page"><pre id="results"></pre>
<div class="admin-shell"><aside class="admin-sidebar">Admin</aside>
<main class="site-main admin-content">${markup}</main></div>
<script type="module">
import {setupAdminCron} from '/public/assets/gallery-modules/admin-cron.js';
/** Bound awaited browser transitions. Type: number. Units: milliseconds. Scope: one fixture assertion. Consumers: until. Rationale: 3000 ms permits a renderer turn without hiding a hanging request. */
const ASSERT_TIMEOUT_MS = 3000;
/** Yield between predicate observations. Type: number. Units: milliseconds. Scope: fixture transitions. Consumers: until. Rationale: 10 ms observes actual async completion rather than guessing microtask counts. */
const ASSERT_POLL_MS = 10;
/** Production observation interval under explicit fake-clock control. Type: number. Units: milliseconds. Scope: fixture clock. Consumers: firePoll. Rationale: assert the required 1000 ms observer cadence independently of wall-clock timing. */
const EXPECTED_POLL_MS = 1000;
/** Expected first outage delay. Type: number. Units: milliseconds. Scope: failed read regression. Consumers: backoff assertion. Rationale: the first exponential retry is twice the required 1000 ms preview cadence. */
const FIRST_BACKOFF_MS = 2000;
/** Maximum compact desktop row height. Type: number. Units: CSS pixels. Scope: production table density. Consumers: row geometry assertion. Rationale: the issue requires ordinary desktop rows within 30 through 36 pixels. */
const ROW_MAX_PX = 36;
/** Minimum mobile control target. Type: number. Units: CSS pixels. Scope: narrow fixture. Consumers: Play geometry assertion. Rationale: 44 px preserves comfortable touch access at the production mobile breakpoint. */
const MOBILE_TARGET_PX = 44;
const nativeTimeout = window.setTimeout.bind(window);
const nativeClear = window.clearTimeout.bind(window);
const nativeFetch = window.fetch.bind(window);
const timers = new Map();
let timerId = 0;
let hidden = false;
Object.defineProperty(document, 'hidden', {configurable: true, get: () => hidden});
window.setTimeout = (callback, delay) => {
    const id = ++timerId;
    timers.set(id, {callback, delay});
    return id;
};
window.clearTimeout = id => { timers.delete(id); };
const pending = [];
const requests = [];
window.fetch = (url, init = {}) => {
    if (String(url).startsWith('/__')) return nativeFetch(url, init);
    requests.push({url: String(url), init});
    // Ignore abort deliberately: a body already received by the browser can
    // finish after the view's generation changes. Production must reject it.
    return new Promise(resolve => pending.push({url: String(url), init, resolve}));
};
/** Assert one browser invariant. @param {boolean} condition Expected invariant. @param {string} message Failure context. @returns {void} Throws on failure. */
function check(condition, message) { if (!condition) throw new Error(message); }
/** Wait for an actual async state. @param {function():boolean} predicate Completion observation. @param {string} message Failure context. @returns {Promise<void>} Resolves only when the predicate holds. */
async function until(predicate, message) {
    const end = Date.now() + ASSERT_TIMEOUT_MS;
    while (!predicate()) {
        if (Date.now() > end) throw new Error(message);
        await new Promise(resolve => nativeTimeout(resolve, ASSERT_POLL_MS));
    }
}
/** Run a selected scheduled poll without advancing unrelated request deadlines. @param {number} delay Expected interval. @returns {Promise<void>} Resolves after the actual poll callback completes. */
async function firePoll(delay = EXPECTED_POLL_MS) {
    const entry = [...timers.entries()].find(([, value]) => value.delay === delay);
    check(Boolean(entry), 'Missing sequential poll timer ' + delay);
    timers.delete(entry[0]);
    await entry[1].callback();
}
/** Fulfil one pending mock request. @param {string} url Owned endpoint. @param {{ok: boolean, rows?: Array<{id: string, next_at: number|null, state_label: string, next_label: string}>, message?: string, outcome?: string}} payload Safe fixture response; rows carry additional display fields from the production fixture. @param {boolean} ok HTTP success. @returns {void} Releases the request with its independent JSON response. */
function respond(url, payload, ok = true) {
    const index = pending.findIndex(item => item.url === url);
    check(index >= 0, 'Missing pending request ' + url);
    pending.splice(index, 1)[0].resolve({ok, json: async () => payload});
}
try {
    const view = document.querySelector('[data-cron-overview]');
    const initial = JSON.parse(view.querySelector('[data-cron-initial]').textContent);
    const rows = initial.map(row => ({...row}));
    const originalNodes = [...view.querySelectorAll('tbody tr')];
    const originalUrl = location.href;
    setupAdminCron();
    const ids = () => [...view.querySelectorAll('tbody tr')].map(row => row.dataset.cronTask).join(',');
    check(ids() === 'cache,logs,updates', 'Default must sort numeric due time and null last');
    const help = view.querySelector('.cron-help');
    help.querySelector('summary').click();
    check(help.open, 'Native keyboard-accessible help failed to open');
    document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape'}));
    check(!help.open, 'Escape did not dismiss contextual help');
    help.querySelector('summary').click();
    document.body.click();
    check(!help.open, 'Outside click did not dismiss contextual help');

    if (new URLSearchParams(location.search).has('mobile')) {
        for (const play of view.querySelectorAll('.cron-play')) {
            const bounds = play.getBoundingClientRect();
            check(bounds.width >= MOBILE_TARGET_PX && bounds.height >= MOBILE_TARGET_PX,
                'Mobile Play is smaller than its touch target');
        }
        check(document.documentElement.scrollWidth <= innerWidth,
            'The table forced horizontal overflow onto the whole Admin page');
        const scroll = view.querySelector('.cron-table-scroll');
        check(scroll.scrollWidth > scroll.clientWidth,
            'Mobile table must retain its own horizontal scroll surface');
    } else {
        for (const row of view.querySelectorAll('tbody tr')) {
            check(row.getBoundingClientRect().height <= ROW_MAX_PX, 'Desktop row is not compact');
        }
    }
    check(getComputedStyle(view.querySelector('[data-cron-sort="name"]')).backgroundColor
        === 'rgba(0, 0, 0, 0)', 'Late Admin theme must retain transparent sorting');
    const firstRow = view.querySelector('tbody tr');
    check(firstRow.cells.length === 5 && firstRow.cells[0].querySelector('.cron-play'),
        'Play must precede the task name without a separate action column');
    check(firstRow.querySelector('a[data-cell="name"]').getAttribute('href')
        === '/settings/' + firstRow.dataset.cronTask,
        'Task name lost its prepared existing-settings link');
    check(getComputedStyle(firstRow.querySelector('.cron-play')).borderRadius === '6px'
        && firstRow.querySelector('.cron-play svg').getBoundingClientRect().width === 16,
        'Play lost the shared compact rounded SVG control');
    const name = view.querySelector('[data-cron-sort="name"]');
    name.click();
    check(ids() === 'logs,cache,updates', 'Name ascending failed');
    name.click();
    check(ids() === 'updates,cache,logs' && name.closest('th').getAttribute('aria-sort') === 'descending', 'Name descending/aria-sort failed');
    view.querySelector('[data-cron-sort="next_at"]').click();
    const focus = view.querySelector('[data-cron-task="cache"] .cron-play');
    focus.focus();
    const read = firePoll();
    await until(() => pending.some(item => item.url === '/status'), 'First poll missing');
    const readCount = requests.length;
    check(requests[0].init.credentials === 'same-origin'
        && requests[0].init.cache === 'no-store'
        && !requests[0].init.method && !requests[0].init.body, 'Status is not passive authenticated GET');
    check(![...timers.values()].some(value => value.delay === EXPECTED_POLL_MS), 'Overlapping poll interval exists');
    rows.find(row => row.id === 'logs').next_at = 50;
    rows.find(row => row.id === 'logs').state_label = 'Running';
    respond('/status', {ok: true, rows});
    await read;
    check(ids() === 'logs,cache,updates', 'Changed numeric due key not reordered');
    check(document.activeElement === focus && originalNodes.every(row => row.isConnected), 'Poll replaced nodes or lost focus');
    check(view.querySelector('[data-cron-task="cache"] a[data-cell="name"]').getAttribute('href') === '/settings/cache',
        'Passive refresh replaced or broke the settings link');
    check(requests.length === readCount, 'A poll executed extra work');

    // Freeze an older body while the operator pauses this view. Neither a
    // delayed body nor a visibility round trip may silently resume observation.
    const pausing = firePoll();
    await until(() => pending.some(item => item.url === '/status'), 'Pause poll missing');
    const pausedRequest = pending.splice(pending.findIndex(item => item.url === '/status'), 1)[0];
    let releasePausedBody;
    pausedRequest.resolve({ok: true, json: () => new Promise(resolve => { releasePausedBody = resolve; })});
    await until(() => Boolean(releasePausedBody), 'Pause body not pending');
    const preview = view.querySelector('[data-cron-preview-toggle]');
    const beforePause = view.querySelector('[data-cron-task="cache"] [data-cell="next_label"]').textContent;
    preview.click();
    const pausedCount = requests.length;
    hidden = true;
    document.dispatchEvent(new Event('visibilitychange'));
    hidden = false;
    document.dispatchEvent(new Event('visibilitychange'));
    check(preview.getAttribute('aria-pressed') === 'true'
        && preview.getAttribute('aria-label') === 'Resume live preview'
        && pausedRequest.init.signal.aborted && timers.size === 0,
        'Pause did not cancel reads or expose a resumable pressed state');
    releasePausedBody({ok: true, rows: rows.map(row => ({...row, next_label: 'STALE PAUSED BODY'}))});
    await pausing;
    check(requests.length === pausedCount && timers.size === 0
        && view.querySelector('[data-cron-task="cache"] [data-cell="next_label"]').textContent === beforePause,
        'Paused preview applied an old body or resumed after visibility');
    preview.click();
    await until(() => pending.some(item => item.url === '/status'), 'Resume did not read immediately');
    respond('/status', {ok: true, rows});
    await until(() => [...timers.values()].some(value => value.delay === EXPECTED_POLL_MS), 'Resume did not restore sequential observation');
    check(preview.getAttribute('aria-pressed') === 'false', 'Resume retained pressed state');

    // Hold an older JSON body across a protected manual run.
    const older = firePoll();
    await until(() => pending.some(item => item.url === '/status'), 'Older poll missing');
    let releaseBody;
    const oldRequest = pending.splice(pending.findIndex(item => item.url === '/status'), 1)[0];
    oldRequest.resolve({ok: true, json: () => new Promise(resolve => { releaseBody = resolve; })});
    await until(() => Boolean(releaseBody), 'Older body not pending');
    focus.click();
    await until(() => pending.some(item => item.url === '/run'), 'Manual run not submitted');
    const post = pending.find(item => item.url === '/run');
    check(post.init.method === 'POST' && post.init.body.get('csrf_token') === 'fixture-csrf'
        && post.init.body.get('task') === 'cache', 'Manual run lost task/CSRF');
    check(focus.disabled && focus.closest('form').getAttribute('aria-busy') === 'true', 'Manual action lacks busy state');
    const changed = {...rows.find(row => row.id === 'cache'), next_at: 900,
        state_label: 'Running', next_label: 'NEW RESULT'};
    respond('/run', {ok: true, outcome: 'started', message: 'Accepted', rows: [changed]});
    await until(() => view.querySelector('[data-cron-task="cache"] [data-cell="next_label"]').textContent === 'NEW RESULT', 'Affected row not refreshed immediately');
    releaseBody({ok: true, rows: initial});
    await older;
    await until(() => pending.some(item => item.url === '/status'), 'Manual reconciliation was not immediate');
    check(![...timers.values()].some(value => value.delay === EXPECTED_POLL_MS),
        'Manual reconciliation retained an older sequential poll timer');
    check(view.querySelector('[data-cron-task="cache"] [data-cell="next_label"]').textContent === 'NEW RESULT', 'Stale body overwrote manual result');
    rows[rows.findIndex(row => row.id === 'cache')] = changed;
    respond('/status', {ok: true, rows});
    await until(() => [...timers.values()].some(value => value.delay === EXPECTED_POLL_MS), 'Read did not settle');
    check(location.href === originalUrl, 'Manual action navigated');
    const outage = firePoll();
    await until(() => pending.some(item => item.url === '/status'), 'Outage poll missing');
    check(![...timers.values()].some(value => value.delay === EXPECTED_POLL_MS),
        'Manual completion scheduled another timer during the next active read');
    respond('/status', {ok: false}, false);
    await outage;
    check([...timers.values()].some(value => value.delay === FIRST_BACKOFF_MS), 'Failed read did not back off');
    check(view.querySelector('[data-cron-connection]').textContent !== '', 'Outage hidden from operator');
    check(view.querySelector('[data-cron-task="cache"] [data-cell="next_label"]').textContent === 'NEW RESULT', 'Failure hid last reliable evidence');
    hidden = true;
    document.dispatchEvent(new Event('visibilitychange'));
    check(timers.size === 0, 'Hidden page retained polling timers');
    hidden = false;
    document.dispatchEvent(new Event('visibilitychange'));
    await until(() => pending.some(item => item.url === '/status'), 'Visibility did not resume immediately');
    const visibleRequest = pending.find(item => item.url === '/status');
    view.remove();
    await until(() => visibleRequest.init.signal.aborted, 'Detached view retained active request');
    respond('/status', {ok: true, rows});
    await new Promise(resolve => nativeTimeout(resolve, ASSERT_POLL_MS));
    check(timers.size === 0, 'Unmounted view continued observing');
    check(requests.filter(request => request.init.method === 'POST').length === 1,
        'Polling triggered extra mutations');
    document.querySelector('#results').textContent = 'BROWSER PASS: compact inline Play, normalized sorting, passive single-flight polls, operator pause/resume, node/focus preservation, stale/manual reconciliation, backoff and hidden/unmounted pause';
} catch (error) {
    document.querySelector('#results').textContent = 'BROWSER FAIL: ' + error.message;
} finally {
    window.setTimeout = nativeTimeout;
    window.clearTimeout = nativeClear;
}
</script></body></html>`;
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(html);
        return;
    }
    const files = new Map([
        ['/public/assets/styles/base.css', 'public/assets/styles/base.css'],
        ['/public/assets/styles/admin.css', 'public/assets/styles/admin.css'],
        ['/public/assets/styles/admin-cron.css', 'public/assets/styles/admin-cron.css'],
        ['/public/assets/styles/admin-cinematic.css', 'public/assets/styles/admin-cinematic.css'],
        ['/public/assets/gallery-modules/admin-cron.js', 'public/assets/gallery-modules/admin-cron.js'],
    ]);
    const file = files.get(pathname);
    if (!file) { response.writeHead(404).end(); return; }
    response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
    response.end(await readFile(path.join(root, file)));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const [index, viewport] of VIEWPORTS.entries()) {
        const url = 'http://127.0.0.1:' + server.address().port + '/'
            + (index === 0 ? '' : '?mobile=1');
        const {result, exitCode} = await runHeadlessBrowserFixture(executable,
            url, 'cron-overview-', {viewport});
        console.log(viewport.width + 'px: '
            + (result || 'Cron Overview did not produce a result marker.'));
        assert.equal(exitCode, 0);
        assert.ok(result?.includes('BROWSER PASS'), 'Cron Overview fixture must complete');
    }
} finally {
    server.close();
}
