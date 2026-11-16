/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_media_renamer_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify production lazy renamer behavior and rendered responsive UI.
 * Responsibilities: Exercise bounded details, retries, cancellation and actual batch completion contracts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {execFileSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
/** One JSON value exposed by the owned browser evaluator.
 * @typedef {null|boolean|number|string|FixtureBrowserJsonValue[]|Record<string,FixtureBrowserJsonValue>} FixtureBrowserJsonValue
 */
const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    if (process.env.PHP_GALLERY_BROWSER_REQUIRED === '1') throw new Error('Required Chromium unavailable.');
    console.log('SKIP lazy renamer browser: Chromium unavailable.');
    process.exit(0);
}
const php = process.argv[3] || process.env.PHP_BINARY || 'php';
/** Bound deterministic PHP presentation rendering.
 * Type: number. Units: milliseconds. Scope: one fixture render.
 * Consumers: execFileSync. Rationale: 10000 covers isolated view loading only.
 */
const renderTimeoutMs = 10000;
/** Bound one browser interaction phase.
 * Type: number. Units: milliseconds. Scope: one lazy read/apply assertion.
 * Consumers: browser wait helper. Rationale: 5000 allows asynchronous local responses.
 */
const phaseTimeoutMs = 5000;
/** Pace owned browser readiness checks without starving native toggle events.
 * Type: number. Units: milliseconds. Scope: one condition poll.
 * Consumers: browser wait helper. Rationale: 10 admits rendering and fetch continuations.
 */
const pollMs = 10;
/** Capture approved desktop and narrow layouts at readable widths.
 * Type: number[]. Units: CSS pixels. Scope: review screenshots.
 * Consumers: owned DevTools viewport. Rationale: 1280 and 390 cover desktop/phone.
 */
const widths = [1280, 390];
/** Bound the review viewport height.
 * Type: number. Units: CSS pixels. Scope: screenshot viewport.
 * Consumers: DevTools. Rationale: 900 shows the compact overview and initial detail rows.
 */
const height = 900;
const fragments = {};
for (const mode of ['preview', 'report', 'first', 'tail', 'second', 'report-first']) {
    fragments[mode] = execFileSync(php, [path.join(root, 'tests/support/admin_media_renamer_render_fixture.php'), mode],
        {encoding: 'utf8', windowsHide: true, timeout: renderTimeoutMs});
}
assert.ok(!fragments.preview.includes('camera_') && !fragments.report.includes('camera_'),
    'Production overview render eagerly included filenames.');
const encodedFragments = JSON.stringify(fragments).replaceAll('<', '\\u003c');
assert.ok(!encodedFragments.includes('<') && JSON.parse(encodedFragments).preview === fragments.preview,
    'Production fragments must round-trip safely inside the fixture module.');
const css = ['base', 'public', 'admin', 'admin-layout', 'admin-gallery-media', 'utilities', 'ui-button', 'ui-switch'];
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
${css.map(name => `<link rel="stylesheet" href="/public/assets/styles/${name}.css">`).join('')}
<style>body{padding:16px}main{max-width:1050px;margin:auto;min-width:0}</style>
<title>Media renamer · WinApp 1.4.0 review</title></head><body class="admin-page"><main><h1>Media renamer</h1>
<p class="muted">Database overview · file details load when requested</p>${fragments.preview}</main>
<output id="results" hidden></output><script type="module">
import {setupAdminMediaRenamer} from '/public/assets/gallery-modules/admin-media-renamer.js';
const fragments = ${encodedFragments};
const requests = []; let failedOnce = false; let hold = false; let release = null;
let applied = []; let confirmed = null; let completion = null;
const token = 'a'.repeat(32);
const check = (ok, text) => { if (!ok) throw new Error(text); };
const pause = () => new Promise(resolve => setTimeout(resolve, ${pollMs}));
/** Wait for this phase's actual terminal state.
 * @param {function():boolean} ready Current-state predicate.
 * @returns {Promise<void>} Resolves after readiness or throws at the deadline.
 */
async function wait(ready) {
    const deadline = performance.now() + ${phaseTimeoutMs};
    while (!ready()) { if (performance.now() > deadline) throw new Error('Phase timed out'); await pause(); }
}
window.fetch = async (_url, options) => {
    const body = options.body; const action = body.get('renamer_action');
    const gallery = Number(body.get('detail_gallery_id'));
    const offset = Number(body.get('detail_offset') || 0);
    const ids = body.getAll('batch_image_ids[]').map(Number);
    requests.push({action, gallery, offset, ids});
    check(body.get('csrf_token') === 'fixture', 'Read/write dropped CSRF');
    let payload;
    if (action === 'preview_details' || action === 'report_details') {
        if (hold) { await new Promise(resolve => { release = resolve; }); }
        if (gallery === 8 && !failedOnce) {
            failedOnce = true;
            return new Response(JSON.stringify({ok:false,error:'Temporary detail failure'}),
                {status:503,headers:{'Content-Type':'application/json'}});
        }
        payload = {ok:true,detail_html:fragments[gallery === 8 ? 'second'
            : action === 'report_details' ? 'report-first' : offset ? 'tail' : 'first'],
            next_offset: gallery === 8 || offset ? null : 50};
    } else if (action === 'candidate_page') {
        check(!document.querySelector('[data-media-renamer-proposals]')?.textContent.trim(), 'Old proposals survived apply');
        payload = {ok:true,candidate_ids:gallery === 7 ? Array.from({length:61},(_,i)=>i+1) : [62,63],next_offset:null};
    } else if (action === 'apply_batch') {
        check(ids.length > 0 && ids.length <= 15, 'Physical batch limit changed');
        check(body.get('report_token') === (applied.length ? token : ''), 'Report continuation lost');
        applied.push(...ids);
        payload = {ok:true,processed:ids.length,result:{renamed:ids.length,details:[],warnings:[],failures:[],report_token:token},
            mutation:{source:'image.media_rename',entity:'image',operation:'rename',entity_ids:ids},
            contexts:[{kind:'gallery',id:7,image_revision:'server-'+applied.length}]};
        confirmed = payload;
    } else if (action === 'apply_refresh') {
        const result = JSON.parse(body.get('batch_result_payload'));
        check(result.details.length === 0 && result.renamed === 63 && result.report_token === token,
            'Browser aggregated eager detail lists or lost counters');
        payload = {ok:true,result,body_html:fragments.report};
    } else throw new Error('Unexpected action '+action);
    return new Response(JSON.stringify(payload),{status:200,headers:{'Content-Type':'application/json'}});
};
document.addEventListener('php-gallery:auxiliary-mutation-success', event => { completion = event.detail.result; });
setupAdminMediaRenamer();
/** Run one externally selected browser journey phase.
 * @param {string} phase Overview, details, cancellation, apply or report.
 * @returns {Promise<string>} Phase completion marker.
 */
window.renamePhase = async phase => {
    const workspace = () => document.querySelector('[data-admin-media-renamer-workspace]');
    const details = () => workspace().querySelectorAll('[data-media-renamer-detail]');
    if (phase === 'overview') {
        check(document.body.classList.contains('admin-page')
            && getComputedStyle(document.body).fontFamily.includes('Arial'), 'Missing production Admin style context');
        check(requests.length === 0 && details().length === 2, 'Overview performed eager reads');
        check(!workspace().textContent.includes('camera_'), 'Overview contained filenames');
        check(!workspace().querySelector('[name="rename_candidate_image_ids[]"]'), 'Overview contained hidden file IDs');
    } else if (phase === 'details') {
        const first = details()[0]; first.open = true;
        await wait(() => first.querySelectorAll('tbody tr').length === 50);
        check(requests.length === 1 && requests[0].gallery === 7, 'Expanded unrelated galleries');
        check(!first.querySelector('untrusted'), 'Filename note escaped presentation boundary');
        first.querySelector('[data-media-renamer-detail-more]').click();
        await wait(() => first.querySelectorAll('tbody tr').length === 61);
        check(first.querySelector('[data-media-renamer-detail-more]').hidden, 'Final page kept continuation');
        const second = details()[1]; second.open = true;
        await wait(() => second.querySelector('[data-media-renamer-detail-status]').textContent.includes('Temporary'));
        second.querySelector('[data-media-renamer-detail-more]').click();
        await wait(() => second.querySelectorAll('tbody tr').length === 2);
        check(requests.at(-1).offset === 0, 'Retry skipped failed page');
        second.open = false;
    } else if (phase === 'cancel') {
        workspace().outerHTML = fragments.preview;
        const first = details()[0]; hold = true; first.open = true;
        await wait(() => release !== null); first.open = false;
        await wait(() => !first.hasAttribute('aria-busy'));
        release(); hold = false; await pause(); await pause();
        check(first.querySelectorAll('tbody tr').length === 0, 'Closed disclosure accepted stale rows');
    } else if (phase === 'apply') {
        const form = workspace().querySelector('[data-admin-media-renamer-form]');
        const button = form.querySelector('[data-media-renamer-apply-button]');
        const before = requests.length;
        form.dispatchEvent(new SubmitEvent('submit',{bubbles:true,cancelable:true,submitter:button}));
        await pause(); check(requests.length === before, 'Unconfirmed rename wrote files');
        form.querySelector('[name="confirm_media_rename"]').checked = true;
        form.requestSubmit(button);
        await wait(() => Boolean(workspace().querySelector('[data-media-renamer-report]')));
        check(applied.length === 63 && new Set(applied).size === 63, 'ID-only apply lost/duplicated candidates');
        check(!workspace().querySelector('[data-media-renamer-proposals]'), 'Report retained stale proposals');
        check(!workspace().textContent.includes('camera_'), 'Report eagerly rendered filenames');
        check(JSON.stringify(completion.mutation) === JSON.stringify(confirmed.mutation)
            && JSON.stringify(completion.contexts) === JSON.stringify(confirmed.contexts), 'Completion invented public revisions');
    } else if (phase === 'report') {
        const first = details()[0]; first.open = true;
        await wait(() => first.querySelectorAll('tbody tr').length === 50);
        check(requests.at(-1).action === 'report_details', 'Report expansion did not read lazy outcomes');
    }
    return phase + ' passed';
};
document.getElementById('results').textContent = 'READY';
</script></body></html>`;
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/') { response.setHeader('Content-Type', 'text/html; charset=utf-8'); response.end(html); return; }
    if (!url.pathname.startsWith('/public/assets/') || url.pathname.includes('..')) { response.writeHead(404).end(); return; }
    try {
        response.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(await readFile(path.join(root, url.pathname)));
    } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const evidence = path.join(root, 'cache/test-audit/admin-media-renamer-browser-' + process.pid);
await mkdir(evidence, {recursive:true});
try {
    const run = await runHeadlessBrowserFixture(executable, 'http://127.0.0.1:' + server.address().port + '/',
        'media-renamer-browser-', {viewport:{width:widths[0],height},
            interact: /** Exercise production controls and save actual responsive rendering.
             * @param {{evaluate:function(string):Promise<FixtureBrowserJsonValue|undefined>,captureScreenshot:function():Promise<string>,setViewport:function({width:number,height:number}):Promise<void>}} controls Owned Chromium controls.
             * @returns {Promise<void>} Completes all behavior and screenshot phases.
             */ async ({evaluate,captureScreenshot,setViewport}) => {
                const deadline = Date.now() + phaseTimeoutMs;
                while (await evaluate('document.getElementById("results")?.textContent') !== 'READY') {
                    assert.ok(Date.now() < deadline, 'Renamer module did not initialize');
                    await new Promise(resolve => setTimeout(resolve, pollMs));
                }
                for (const phase of ['overview','details','cancel','apply','report']) {
                    assert.equal(await evaluate('window.renamePhase(' + JSON.stringify(phase) + ')'), phase + ' passed');
                    if (phase === 'cancel') continue;
                    for (const width of widths) {
                        await setViewport({width,height});
                        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true,
                            phase + ' introduced horizontal overflow at ' + width);
                        await writeFile(path.join(evidence, phase + '-' + width + '.png'),
                            Buffer.from(await captureScreenshot(), 'base64'));
                    }
                    await setViewport({width:widths[0],height});
                }
                await evaluate('document.getElementById("results").textContent="BROWSER PASS lazy media renamer"');
            }});
    assert.equal(run.exitCode, 0);
    assert.ok(run.result?.includes('BROWSER PASS'), run.result || 'No browser result');
    console.log(run.result);
} finally { server.close(); }
