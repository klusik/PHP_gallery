/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_origin_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Observe the network headers sent by the production WinApp linking views.
 * Responsibilities: Exercise claim, consent, the loopback return and device revocation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

/**
 * Bound an isolated renderer or browser journey.
 * Type: number. Units: milliseconds. Scope: one render or browser result.
 * Consumers: execFileSync, runHeadlessBrowserFixture and runFirefox.
 * Rationale: 15000 covers a fresh profile and the short loopback request chain.
 */
const DEADLINE_MS = 15000;
/**
 * Check the approval page at its desktop and touch-friendly widths.
 * Type: Array<{width:number,height:number}>. Units: CSS pixels.
 * Scope: Chromium viewport. Consumers: runHeadlessBrowserFixture.
 * Rationale: 1280x900 and 390x844 exercise the full page and narrow layout.
 */
const VIEWPORTS = [{width: 1280, height: 900}, {width: 390, height: 844}];

const chromium = process.argv[2];
assert.ok(chromium, 'The browser-map owner must supply Chromium.');
const fixture = fileURLToPath(new URL(
    './support/app_linking_render_fixture.php', import.meta.url,
));
const php = process.argv[3] || process.env.PHP_BINARY || 'php';
const start = '/Galerie/index.php?page=app_linking_start';
const consent = '/Galerie/index.php?page=app_linking_consent';
const devices = '/Galerie/index.php?page=app_linking_devices';
const callback = '/oauth2redirect/php-gallery';
const legacy = '/Galerie/before-fix';
const resultUrl = '/Galerie/fixture-result';
const csrf = {csrf_token: 'fixture-csrf'};
const posts = new Map([
    [start, {...csrf, request_handle: 'fixture-handle'}],
    [consent, {...csrf, attempt: 'fixture-attempt', decision: 'approve'}],
    [devices, {...csrf, grant_id: '1'}],
]);
/** @type {Array<{browser:string,before_fix_origin:string,origin:string,referer:string,loopback_referer:string}>} */
const evidence = [];

/**
 * Render production markup and append a nonce-bound fixture interaction.
 * @param {'start'|'devices'} view Actual PHP view selected by the render fixture.
 * @return {string} Production HTML plus a driver that observes the real UI.
 */
function render(view) {
    const html = execFileSync(php, [fixture, view], {
        encoding: 'utf8', timeout: DEADLINE_MS, windowsHide: true,
    });
    return html.replace('</body>', `<script nonce="linking-fixture">(() => {
        const approval = document.querySelector('#approve');
        const revoke = document.querySelector('[data-grant-id]');
        const status = document.querySelector('#status, #device-status');
        const finish = result => {
            observer.disconnect();
            void fetch(${JSON.stringify(resultUrl)}, {method: 'POST', body: result});
        };
        const observe = () => {
            if (status.textContent.trim()) {
                finish('BROWSER FAIL the linking view reported an error');
            } else if (document.documentElement.scrollWidth > window.innerWidth) {
                finish('BROWSER FAIL the linking view overflows its viewport');
            } else if (approval && !document.querySelector('#consent').hidden) {
                observer.disconnect();
                approval.click();
            } else if (revoke && !revoke.isConnected) {
                finish('BROWSER PASS approval returned and device revocation completed');
            }
        };
        const observer = new MutationObserver(observe);
        observer.observe(document.querySelector('main'), {
            subtree: true, attributes: true, childList: true,
        });
        observe();
        if (revoke && revoke.isConnected) revoke.click();
    })();</script></body>`);
}

const views = new Map([[start, render('start')], [devices, render('devices')]]);
// Exercise the 1.5.3 RequestInit with the same document policy as the real views.
// Log the observed baseline without requiring future browser versions to keep the bug.
const beforeFix = `<!doctype html><script nonce="linking-fixture">
    fetch(location.href, {
        method: 'POST', mode: 'same-origin', credentials: 'same-origin',
        redirect: 'error', cache: 'no-store', referrer: '', referrerPolicy: 'same-origin',
        headers: {'Accept': 'application/json'}, body: new URLSearchParams({probe: 'before-fix'})
    }).then(() => location.replace(${JSON.stringify(start)}));
</script>`;
/** @type {{steps:string[],baseline:string,returned:boolean,result:string,finish:(value:string)=>void}} */
let active;
const server = createServer(async (request, response) => {
    const route = request.url;
    response.setHeader('Referrer-Policy', 'no-referrer');
    response.setHeader('Cache-Control', 'no-store');
    response.setHeader('Content-Security-Policy', [
        "default-src 'none'", "script-src 'nonce-linking-fixture'",
        "style-src 'nonce-linking-fixture'", "connect-src 'self'",
        "base-uri 'none'", "frame-ancestors 'none'",
    ].join('; '));
    try {
        if (request.method === 'GET') {
            if (route === callback) {
                assert.equal(request.headers.referer, undefined, 'Callback disclosed a referrer');
                active.returned = true;
                response.writeHead(303, {Location: devices}).end();
            } else if (route === legacy || views.has(route)) {
                response.writeHead(200, {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Set-Cookie': 'test_linking=admin; Path=/Galerie/; HttpOnly; SameSite=Strict',
                }).end(route === legacy ? beforeFix : views.get(route));
            } else {
                response.writeHead(404).end();
            }
            return;
        }
        if (request.method !== 'POST') { response.writeHead(405).end(); return; }
        let body = '';
        for await (const chunk of request) body += chunk;
        if (route === legacy) {
            active.baseline = request.headers.origin === origin ? 'same-origin'
                : request.headers.origin === 'null' ? 'null' : 'absent or different';
            response.writeHead(204).end();
            return;
        }
        if (route === resultUrl) {
            if (!active.result) {
                active.result = body;
                active.finish(body);
            }
            response.writeHead(204).end();
            return;
        }
        assert.ok(posts.has(route), 'Unexpected POST route');
        assert.equal(request.headers.origin, origin, 'Browser POST Origin mismatch');
        assert.equal(request.headers.referer, origin + '/', 'Only the site root may be sent');
        assert.equal(request.headers['sec-fetch-site'], 'same-origin');
        assert.match(request.headers.cookie || '', /(?:^|; )test_linking=admin(?:$|;)/);
        assert.deepEqual([...new URLSearchParams(body)], Object.entries(posts.get(route)));
        active.steps.push(route);
        const json = route === start ? {ok: true, attempt: 'fixture-attempt', scope: 'link:identity'}
            : route === consent ? {ok: true, callback: origin + callback} : {ok: true};
        response.writeHead(200, {'Content-Type': 'application/json'}).end(JSON.stringify(json));
    } catch (error) {
        active.result = 'BROWSER FAIL ' + error.message;
        active.finish(active.result);
        response.writeHead(403).end();
    }
});
server.listen(0, '127.0.0.1');
await once(server, 'listening');
const origin = 'http://127.0.0.1:' + server.address().port;

/**
 * Run the same interactive pages in the hosted runner's Firefox.
 * @param {Promise<string>} result Terminal result emitted by the fixture.
 * @return {Promise<string>} Browser evidence, or an error if the browser exits or stalls.
 */
async function runFirefox(result) {
    const profile = await mkdtemp(path.join(tmpdir(), 'linking-firefox-'));
    const browser = spawn('firefox', [
        '--headless', '--no-remote', '--profile', profile, origin + legacy,
    ], {stdio: 'ignore'});
    const closed = once(browser, 'close');
    try {
        return await Promise.race([
            result,
            delay(DEADLINE_MS, 'BROWSER FAIL Firefox result deadline expired', {ref: false}),
            closed.then(([code]) => 'BROWSER FAIL Firefox exited with code ' + code),
        ]);
    } finally {
        browser.kill();
        await closed.catch(() => {});
        await rm(profile, {recursive: true, force: true});
    }
}

/**
 * Confirm the complete network sequence and report the observed legacy header.
 * @param {string} browser Browser name and viewport description.
 * @param {string} result Terminal fixture outcome.
 * @return {void} Throws on a failed, missing or reordered approval step.
 */
function verifyResult(browser, result) {
    console.log(browser + ' before-fix Origin: ' + (active.baseline || 'probe not observed'));
    assert.match(result, /^BROWSER PASS/, result || 'No browser result arrived');
    assert.ok(active.baseline, 'Original RequestInit probe was not observed.');
    assert.deepEqual(active.steps, [start, consent, devices]);
    assert.equal(active.returned, true);
    evidence.push({
        browser,
        before_fix_origin: active.baseline,
        origin: 'exact same-origin',
        referer: 'site root only',
        loopback_referer: 'absent',
    });
}

try {
    for (const viewport of VIEWPORTS) {
        active = {steps: [], baseline: '', returned: false, result: '', finish: () => {}};
        const run = await runHeadlessBrowserFixture(
            chromium, origin + legacy, 'linking-origin-',
            {viewport, timeoutMs: DEADLINE_MS, readResult: async () => active.result},
        );
        verifyResult('Chromium at ' + viewport.width + 'px', run.result);
        assert.equal(run.exitCode, 0);
    }
    if (process.platform === 'linux' && process.env.GECKOWEBDRIVER) {
        const version = execFileSync('firefox', ['--version'], {
            encoding: 'utf8', timeout: DEADLINE_MS,
        }).trim();
        const {promise, resolve} = Promise.withResolvers();
        active = {steps: [], baseline: '', returned: false, result: '', finish: resolve};
        verifyResult(version, await runFirefox(promise));
    } else {
        console.log('Firefox not exercised in this environment.');
    }
} finally {
    server.closeAllConnections();
    server.close();
}
const reportDir = fileURLToPath(new URL('../cache/test-audit/', import.meta.url));
await mkdir(reportDir, {recursive: true});
await writeFile(path.join(reportDir, 'app-linking-browser-' + process.pid + '.json'),
    JSON.stringify(evidence, null, 2) + '\n');
console.log('PASS WinApp linking browser transport');
