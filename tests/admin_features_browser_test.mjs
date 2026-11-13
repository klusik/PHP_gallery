/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_features_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify compact feature autosave against isolated real policy/render owners.
 * Responsibilities: Cover group/reset actions, dependency health and lost-write reconciliation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP Admin Features browser: no Chromium executable supplied.');
    process.exit(0);
}
/**
 * Bound isolated PHP snapshot generation.
 * Type: number. Units: milliseconds. Scope: one fixture child.
 * Consumers: renderState() and screenshot readiness. Value: 10000.
 * Rationale: Detect snapshot/readiness hangs before the browser owner deadline.
 */
const RENDER_TIMEOUT_MS = 10000;
/**
 * Observe the existing fixture's screenshot boundary without a fixed long wait.
 * Type: number. Units: milliseconds. Scope: screenshot readiness polling.
 * Consumers: the browser interaction callback. Value: 20.
 * Rationale: Let actual requests settle while bounding readiness by RENDER_TIMEOUT_MS.
 */
const SCREENSHOT_POLL_MS = 20;
/**
 * Exercise one, two and three columns plus the wide-screen column cap.
 * Type: Array<{width:number,height:number,columns:number}>.
 * Units: CSS pixels for dimensions; count for expected occupied columns.
 * Scope: four isolated documents. Consumers: viewport emulation.
 * Rationale: 390 checks touch/overflow, 1200 checks two columns, 1700 checks
 * three and 2560 confirms the cap. Each also checks a constrained container.
 */
const VIEWPORTS = [
    {width: 1200, height: 800, columns: 2},
    {width: 1700, height: 1000, columns: 3},
    {width: 2560, height: 1080, columns: 3},
    {width: 390, height: 844, columns: 1},
];

/**
 * Run the real policy and controller/view against one isolated settings snapshot.
 * @param {Record<string,string>} settings Persisted app-setting text keyed by setting name.
 * @param {{feature_registry_revision?:string,feature_changes?:Record<string,string>,feature_action?:string}|null} post Scoped request or null for a passive read.
 * @return {{ok:boolean,message:string,registry_revision:string,summary:string,groups_html:string,html:string,stylesheet_files:string[],account_html:string,notice_html:string,account_state:{ok:boolean,message:string,enabled:boolean,mutation:{type:string,entity:string,action:string,entity_ids:number[]},panel:null,contexts:[],fallback:{}},settings:Record<string,string>}} Updated settings and authoritative presentation.
 */
function renderState(settings, post = null) {
    return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
        path.join(root, 'tests/fixtures/admin_features_view.php'),
        JSON.stringify({settings, ...(post ? {post} : {})}),
    ], {encoding: 'utf8', windowsHide: true, timeout: RENDER_TIMEOUT_MS}));
}

let settings = {};
const initial = renderState(settings);
const html = String.raw`<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
${initial.stylesheet_files.map(file => '<link rel="stylesheet" href="/public/' + file + '">').join('\n')}
</head><body class="admin-page"><pre id="results"></pre>
<div class="site-main">${initial.notice_html}
<article class="account-settings-card account-profile-settings">${initial.account_html}</article></div>
<main>${initial.html}</main>
<script type="module">
import {setupAdminFeatures} from '/public/assets/gallery-modules/admin-features.js';
import {setupGalleryMediaPanels} from '/public/assets/gallery-modules/gallery-media-panels.js';
/** Bound one real async transition. Type: number. Units: milliseconds. Scope: fixture assertions. Consumers: until(). Value: 10000. Rationale: Includes one isolated PHP render while detecting a stuck operation. */
const ASSERT_TIMEOUT_MS = 10000;
/** Yield for DOM and request completion. Type: number. Units: milliseconds. Scope: fixture observation. Consumers: until(). Value: 10. Rationale: Observe actual completion rather than guessing microtask counts. */
const POLL_MS = 10;
/** Compact desktop row bound. Type: number. Units: CSS pixels. Scope: density assertion. Consumers: geometry check. Value: 36. Rationale: Keep the requested table compact with production CSS loaded. */
const ROW_MAX_PX = 36;
/** Comfortable touch target. Type: number. Units: CSS pixels. Scope: narrow controls. Consumers: geometry checks. Value: 44. Rationale: Match the mandatory design contract. */
const TAP_MIN_PX = 44;
/**
 * Bound subpixel rounding in column geometry.
 * Type: number. Units: CSS pixels. Scope: layout assertions.
 * Consumers: checkLayout(). Value: 2.
 * Rationale: Permit fractional widths without accepting row-sized gaps.
 */
const LAYOUT_TOLERANCE_PX = 2;
const nativeFetch = window.fetch.bind(window);
const requests = [];
let hold = false;
let release;
let drop = false;
let dropAccount = false;
let refuseAccount = false;
const accountRequests = [];
window.fetch = async (url, init = {}) => {
    if (String(url) === '/account') {
        const fields = [...init.body];
        accountRequests.push(fields);
        check(init.method === 'POST' && init.credentials === 'same-origin'
            && init.headers.Accept === 'application/json', 'Account lost authenticated JSON contract');
        check(init.body.get('csrf_token') === 'fixture-csrf', 'Account lost CSRF');
        const revision = document.querySelector('[name="feature_registry_revision"]').value;
        const response = await nativeFetch('/fixture-state', {
            method: 'POST', body: JSON.stringify([
                ['feature_registry_revision', revision],
                ['feature_changes[release_email_notifications]',
                    refuseAccount ? '1' : init.body.has('release_email_enabled') ? '1' : '0'],
            ]),
        });
        const result = await response.json();
        if (dropAccount) { dropAccount = false; throw new Error('Lost account response'); }
        const payload = result.account_state;
        if (refuseAccount) { payload.ok = false; payload.message = 'Preference refused'; }
        return new Response(JSON.stringify(payload), {
            status: payload.ok ? 200 : 422, headers: {'Content-Type': 'application/json'},
        });
    }
    if (String(url) !== '/features') return nativeFetch(url, init);
    requests.push({method: init.method, fields: init.body ? [...init.body] : []});
    check(init.headers.Accept === 'application/json' && init.credentials === 'same-origin', 'Lost authenticated JSON contract');
    if (init.body) check(init.body.get('csrf_token') === 'fixture-csrf', 'Lost CSRF');
    if (hold) await new Promise(resolve => { release = resolve; });
    const response = await nativeFetch('/fixture-state', {
        method: 'POST', body: JSON.stringify(init.body ? [...init.body] : null),
    });
    if (drop) { drop = false; throw new Error('Lost response after persistence'); }
    return response;
};
/** Assert a browser invariant. @param {boolean} condition Expected invariant. @param {string} message Failure context. @return {void} Throws on mismatch. */
function check(condition, message) { if (!condition) throw new Error(message); }
/** Wait for authoritative completion. @param {function():boolean} predicate Actual completion observation. @param {string} message Failure context. @return {Promise<void>} Resolves when observed or throws at the deadline. */
async function until(predicate, message) {
    const deadline = Date.now() + ASSERT_TIMEOUT_MS;
    while (!predicate()) {
        if (Date.now() > deadline) throw new Error(message);
        await new Promise(resolve => setTimeout(resolve, POLL_MS));
    }
}
/** Resolve the current server-rendered feature control after replacement. @param {string} key Canonical feature identity. @return {HTMLInputElement} Current control. */
function feature(key) { return document.getElementById('admin-feature-' + key); }
/** Wait for a serialized operation. @return {Promise<void>} Resolves after controls are unlocked. */
async function settled() { await until(() => form.getAttribute('aria-busy') === 'false', 'Request did not settle'); }
/**
 * Verify intact groups pack vertically without shared row heights.
 * @param {number} expectedColumns Required occupied columns for this container.
 * @return {void} Throws on fragmentation, overflow, overlap or extra gaps.
 */
function checkLayout(expectedColumns) {
    const groups = [...document.querySelectorAll('.admin-feature-group')];
    const container = document.querySelector('[data-feature-groups]');
    const bounds = container.getBoundingClientRect();
    const gap = parseFloat(getComputedStyle(container).columnGap);
    const columns = new Map();
    for (const group of groups) {
        check(group.getClientRects().length === 1, 'Feature group split across columns');
        const rect = group.getBoundingClientRect();
        check(rect.left >= bounds.left - LAYOUT_TOLERANCE_PX
            && rect.right <= bounds.right + LAYOUT_TOLERANCE_PX,
            'Feature group overflows its container');
        const left = Math.round(rect.left);
        const previous = columns.get(left);
        if (previous) {
            check(Math.abs(rect.top - previous.bottom - gap) <= LAYOUT_TOLERANCE_PX,
                'Unequal group heights introduced a shared row gap or overlap');
        }
        columns.set(left, rect);
    }
    check(columns.size === expectedColumns, 'Wrong number of occupied feature columns');
}
const form = document.querySelector('.admin-feature-form');
const view = document.querySelector('[data-admin-features]');
const originalUrl = location.href;
const expectedColumns = Number(new URL(location.href).searchParams.get('columns'));
try {
    setupAdminFeatures();
    setupGalleryMediaPanels();
    const accountForm = document.querySelector('[data-account-release-email]');
    const accountInput = accountForm.elements.namedItem('release_email_enabled');
    const accountStatus = accountForm.querySelector('[role="status"]');
    check(accountInput.checked && accountInput.classList.contains('ui-switch'),
        'Existing email accounts must have the canonical enabled default');
    accountInput.focus(); accountInput.click();
    await until(() => accountStatus.textContent === 'Saved', 'Account preference did not save');
    check(!accountInput.checked && document.activeElement === accountInput,
        'Account did not apply canonical state or restore focus');
    check(accountRequests[0].every(([key]) => ['csrf_token', 'account_action',
        'release_email_enabled'].includes(key)), 'Account sent unrelated profile/password drafts');
    check(!accountForm.querySelector('[data-release-email-save]').getBoundingClientRect().height,
        'Enhanced preference still requires a second Save');
    accountInput.click();
    await until(() => accountInput.checked && !accountInput.disabled, 'Account ON did not save');
    const accountHelp = document.querySelector('.account-release-help > summary');
    check(getComputedStyle(accountHelp).backgroundColor === 'rgba(0, 0, 0, 0)',
        'Account help inherited a filled control');
    if (innerWidth <= 640) {
        check(accountInput.closest('label').getBoundingClientRect().height >= TAP_MIN_PX
            && accountHelp.getBoundingClientRect().height >= TAP_MIN_PX,
            'Account switch/help lost touch targets');
    }
    checkLayout(expectedColumns);
    // A narrow panel on a wide viewport must use its own available width.
    view.style.maxWidth = '450px';
    checkLayout(1);
    view.style.removeProperty('max-width');
    checkLayout(expectedColumns);
    check(document.querySelector('table thead tr').children.length === 2, 'State became a separate column');
    const name = feature('downloads').closest('.admin-feature-name');
    check(name.firstElementChild.contains(feature('downloads')), 'Switch must precede the feature name');
    check(feature('downloads').classList.contains('ui-switch')
        && getComputedStyle(feature('downloads')).width === '26px'
        && getComputedStyle(feature('downloads')).height === '14px',
    'Features must use the shared compact switch.');
    const help = document.querySelector('.admin-feature-help');
    check(getComputedStyle(help).backgroundColor === 'rgba(0, 0, 0, 0)', 'Legacy filled help button won the cascade');
    check(document.documentElement.scrollWidth <= innerWidth, 'Feature table overflows viewport');
    if (innerWidth <= 640) {
        check(help.getBoundingClientRect().height >= TAP_MIN_PX, 'Help touch target shrank');
        check(feature('downloads').closest('label').getBoundingClientRect().width >= TAP_MIN_PX, 'Switch touch target shrank');
    } else check(name.closest('tr').getBoundingClientRect().height <= ROW_MAX_PX, 'Desktop row lost density');

    hold = true;
    feature('downloads').click();
    await until(() => Boolean(release), 'Save did not start');
    check(form.getAttribute('aria-busy') === 'true' && feature('image_voting').disabled, 'Concurrent writes remain enabled');
    check(!requests[0].fields.some(([key]) => key === 'enabled_features[]'), 'Autosave sent a complete stale form');
    check(requests[0].fields.some(([key, value]) => key === 'feature_changes[downloads]' && value === '0'), 'Scoped OFF missing');
    hold = false; release();
    await settled();
    check(!feature('downloads').checked && view.dataset.saveState === 'saved', 'Scoped save was not rendered');
    checkLayout(expectedColumns);
    check(document.activeElement === feature('downloads'), 'Save lost keyboard focus');

    feature('image_voting').click(); await settled();
    check(feature('picture_game').checked && feature('picture_game').closest('tr').querySelector('.admin-feature-blocked'), 'Dependency changed configured preference or hid blocker');
    const mixed = document.getElementById('admin-feature-group-public_display-switch');
    check(mixed.classList.contains('ui-switch') && mixed.indeterminate
        && getComputedStyle(mixed, '::before').left === '8px',
    'A partially enabled group must expose the shared mixed-state thumb.');
    const groupId = 'admin-feature-group-maps_flightsim-switch';
    document.getElementById(groupId).click(); await settled();
    check(!feature('gallery_maps').checked && !feature('navigation_data').checked && !feature('downloads').checked, 'Group save touched wrong scope');
    document.getElementById(groupId).click(); await settled();
    check(feature('flight_maps').checked && !feature('downloads').checked, 'Rerendered group control lost delegation');

    document.querySelector('[data-feature-reset]').click(); await settled();
    check(document.querySelectorAll('.admin-feature-comparison.is-changed').length === 0, 'Factory reset did not restore all declared defaults');
    check(feature('image_voting').checked && !feature('thumbnail_warmup').checked, 'Factory reset ignored fresh-install defaults');
    checkLayout(expectedColumns);

    drop = true;
    feature('downloads').click(); await settled();
    check(feature('downloads').disabled && !document.querySelector('[data-feature-reload]').hidden, 'Lost response was reported as known state');
    const beforeRead = requests.length;
    document.querySelector('[data-feature-reload]').click(); await settled();
    check(requests.length === beforeRead + 1 && requests.at(-1).method === 'GET', 'Recovery replayed a mutation');
    check(!feature('downloads').checked && !feature('downloads').disabled, 'Recovery did not read the committed write');
    check(location.href === originalUrl, 'Feature action navigated');
    const finalHelp = document.querySelector('.admin-feature-help');
    finalHelp.click();
    await until(() => Boolean(document.querySelector('.admin-feature-help-panel:popover-open')), 'Help did not open');
    document.querySelector('.admin-feature-help-panel:popover-open').hidePopover();
    // The existing owned browser session retains desktop/narrow visual evidence.
    window.__releaseEmailPreviewReady = true;
    await new Promise(resolve => { window.__releaseEmailPreviewContinue = resolve; });
    refuseAccount = true;
    accountInput.click();
    await until(() => accountStatus.textContent === 'Preference refused', 'Account refusal not shown');
    check(accountInput.checked && accountInput.disabled,
        'Authoritative refusal did not apply persisted state and lock its form');
    // Restore only fixture controls for the independent lost-response case.
    accountInput.disabled = false; refuseAccount = false; dropAccount = true;
    const accountWriteCount = accountRequests.length;
    accountInput.click();
    await until(() => accountStatus.textContent === accountForm.dataset.failed,
        'Account lost response offered no passive reload');
    check(accountInput.disabled && !accountInput.checked
        && accountRequests.length === accountWriteCount + 1,
        'Account uncertain write retried or invented rollback');
    check(location.href === originalUrl, 'Account preference navigated');
    document.getElementById('results').textContent = 'BROWSER PASS compact features and account release email, scoped autosave, groups, factory reset, dependencies and lost-response recovery';
} catch (error) { document.getElementById('results').textContent = 'BROWSER FAIL ' + error.stack; }
</script></body></html>`;
const files = new Map([
    ['/public/assets/styles.css', 'public/assets/styles.css'],
    ['/public/assets/styles/base.css', 'public/assets/styles/base.css'],
    ['/public/assets/styles/admin.css', 'public/assets/styles/admin.css'],
    ['/public/assets/styles/admin-cinematic.css', 'public/assets/styles/admin-cinematic.css'],
    ['/public/assets/styles/ui-button.css', 'public/assets/styles/ui-button.css'],
    ['/public/assets/styles/ui-switch.css', 'public/assets/styles/ui-switch.css'],
    ['/public/assets/gallery-modules/admin-features.js', 'public/assets/gallery-modules/admin-features.js'],
]);
const server = createServer(async (request, response) => {
    try {
        const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
        if (pathname === '/') { response.setHeader('Content-Type', 'text/html'); response.end(html); return; }
        if (pathname === '/fixture-state' && request.method === 'POST') {
            let raw = '';
            for await (const chunk of request) raw += chunk;
            const fields = JSON.parse(raw);
            const post = fields ? {} : null;
            for (const [key, value] of fields || []) {
                const match = key.match(/^feature_changes\[([a-z0-9_]+)\]$/);
                if (match) (post.feature_changes ||= {})[match[1]] = value;
                else if (key !== 'csrf_token') post[key] = value;
            }
            const result = renderState(settings, post);
            settings = result.settings;
            response.writeHead(result.ok ? 200 : 409, {'Content-Type': 'application/json'});
            response.end(JSON.stringify(result)); return;
        }
        const file = files.get(pathname)
            || (/^\/public\/assets\/(styles|gallery-modules)\/[a-z0-9-]+\.(css|js)$/.test(pathname)
                ? pathname.slice(1) : null);
        if (!file) { response.writeHead(404).end(); return; }
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(await readFile(path.join(root, file)));
    } catch (error) { response.writeHead(500).end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const viewport of VIEWPORTS) {
        settings = {};
        const {result, exitCode} = await runHeadlessBrowserFixture(executable,
            'http://127.0.0.1:' + server.address().port + '/?columns=' + viewport.columns,
            'admin-features-', {viewport, interact: async ({evaluate, captureScreenshot}) => {
                const deadline = Date.now() + RENDER_TIMEOUT_MS;
                while (!await evaluate('window.__releaseEmailPreviewReady === true')) {
                    const failure = await evaluate('document.getElementById("results").textContent');
                    assert.ok(!String(failure).startsWith('BROWSER FAIL'), String(failure));
                    assert.ok(Date.now() < deadline, 'Release email preview did not become ready');
                    await new Promise(resolve => setTimeout(resolve, SCREENSHOT_POLL_MS));
                }
                if (viewport.width === 1200 || viewport.width === 390) {
                    await evaluate('window.scrollTo(0, 0); document.querySelector(".account-release-help > summary").click(); true');
                    assert.equal(await evaluate(`(() => {
                        const panel = document.querySelector('.account-release-help .gallery-media-panel');
                        const rect = panel.getBoundingClientRect();
                        return panel.matches(':popover-open') && rect.left >= 0
                            && rect.right <= innerWidth && rect.top >= 0
                            && rect.bottom <= innerHeight;
                    })()`), true, 'Account help must stay anchored inside the viewport');
                    await evaluate(`Promise.all(document.querySelector('.account-release-help .gallery-media-panel')
                        .getAnimations().map(animation => animation.finished)).then(() => true)`);
                    const evidence = path.join(root, 'cache/test-audit/admin-release-email');
                    await mkdir(evidence, {recursive: true});
                    await writeFile(path.join(evidence, viewport.width + '.png'),
                        Buffer.from(await captureScreenshot({fullPage: false}), 'base64'));
                    await evaluate('document.querySelector(".account-release-help > summary").click(); true');
                }
                await evaluate('window.__releaseEmailPreviewContinue(); true');
            }});
        console.log(viewport.width + 'px: ' + result);
        assert.equal(exitCode, 0);
        assert.ok(result?.includes('BROWSER PASS'), result || 'Features fixture did not complete');
    }
} finally { server.close(); }
