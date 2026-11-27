/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Regression Test
 * Purpose: Launch PHP-rendered parent-picker fixtures in private Chromium.
 * Responsibilities:
 *   - Serve isolated fixture HTML and production assets, then verify observable picker behavior.
 * File: tests/gallery_picker_parent_integration_browser_test.mjs
 * Author: Rudolf Klusal
 * Launch current PHP-rendered parent fixtures in a private Chromium document.
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
const php = process.argv[3] || process.env.PHP_GALLERY_PHP || 'php';
if (!executable) {
    console.log('SKIP: picker parent browser fixture requires a Chromium executable.');
    process.exit(0);
}
let html;
try {
    html = execFileSync(php, [path.join(root, 'tests/gallery_picker_search_test.php'), '--render-browser-fixture'],
        {encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, windowsHide: true, timeout: 15000});
} catch (error) {
    if (error.code === 'ENOENT') {
        console.log('SKIP: picker browser fixture requires PHP CLI (third argument or PHP_GALLERY_PHP).');
        process.exit(0);
    }
    throw error;
}
if (html.startsWith('SKIP:')) {
    console.log(html.trim());
    process.exit(0);
}
assert.ok(html.startsWith('<!doctype html>'), 'Render the current production controls from the isolated PHP fixture');
const routes = new Map([
    ['/fixture.js', 'tests/fixtures/gallery_picker_parent_integration.js'],
    ['/picker.js', 'public/assets/gallery-modules/searchable-gallery-picker.js'],
    ['/gallery-picker-policy.js', 'public/assets/gallery-modules/gallery-picker-policy.js'],
    ['/title.js', 'public/assets/gallery-modules/admin-gallery-title-completion.js'],
    ['/admin-interaction-policy.js', 'public/assets/gallery-modules/admin-interaction-policy.js'],
    ['/picture-manager.js', 'public/assets/gallery-modules/picture-manager.js'],
    ['/public-photo-drop-actions.js', 'public/assets/gallery-modules/public-photo-drop-actions.js'],
    ['/admin-core.js', 'public/assets/gallery-modules/admin-core.js'],
    ['/admin-mutation-completion.js', 'public/assets/gallery-modules/admin-mutation-completion.js'],
    ['/base.css', 'public/assets/styles/base.css'],
    ['/public.css', 'public/assets/styles/public.css'],
]);

/**
 * Wait for the real selector to open before taking hosted evidence.
 * Type: number. Units: ms. Scope: isolated manager fixture interaction.
 * Consumers: capturePictureManager. Value: 10000.
 * Rationale: covers preceding catalog checks under the existing child deadline.
 */
const MANAGER_CAPTURE_TIMEOUT_MS = 10000;

/**
 * Observe readiness without busy polling the Chromium process.
 * Type: number. Units: ms. Scope: isolated manager fixture interaction.
 * Consumers: capturePictureManager. Value: 40.
 * Rationale: lets one committed DOM change settle between bounded queries.
 */
const MANAGER_CAPTURE_POLL_MS = 40;

/**
 * Cover ordinary desktop and narrow mobile layouts.
 * Type: {width:number,height:number,label:string}[]. Units: CSS pixels.
 * Scope: Picture manager fixture. Consumers: capturePictureManager.
 * Rationale: 1280×800 and 390×650 exercise the desktop and mobile layout rules.
 */
const MANAGER_VIEWPORTS = [
    {width: 1280, height: 800, label: 'desktop'},
    {width: 390, height: 650, label: 'narrow'},
];

/**
 * Inspect actual panel and option geometry, then retain both screenshots.
 * @param {{evaluate:function(string):Promise<unknown>,captureScreenshot:function({fullPage:boolean}):Promise<string>,setViewport:function({width:number,height:number}):Promise<void>}} tools Owned DevTools controls.
 * @return {Promise<void>} Validates clickability and releases the pending browser action.
 */
async function capturePictureManager(tools) {
    const {evaluate, captureScreenshot, setViewport} = tools;
    const ready = 'document.querySelector("[data-picture-manager]").dataset.captureReady';
    const deadline = Date.now() + MANAGER_CAPTURE_TIMEOUT_MS;
    while (await evaluate(ready) !== '1') {
        const result = await evaluate('document.querySelector("#results").textContent');
        assert.ok(typeof result !== 'string' || !result.startsWith('BROWSER FAIL'), String(result));
        assert.ok(Date.now() < deadline, 'Picture manager did not open its destination results');
        await new Promise(
            /** Yield to the browser fixture. @param {function():void} resolve End this wait. @return {void} Schedules the next observation. */
            (resolve) => { setTimeout(resolve, MANAGER_CAPTURE_POLL_MS); });
    }
    const evidence = path.join(root, 'cache', 'test-audit', 'picture-manager-move');
    await mkdir(evidence, {recursive: true});
    for (const {width, height, label} of MANAGER_VIEWPORTS) {
        await setViewport({width, height});
        const visible = await evaluate(`(() => {
            const panel = document.querySelector('[data-picture-manager-panel]');
            const option = panel.querySelector('[data-gallery-id="777"]');
            const box = panel.getBoundingClientRect();
            const row = option.getBoundingClientRect();
            const atOption = document.elementFromPoint((row.left + row.right) / 2, (row.top + row.bottom) / 2);
            return box.left >= 0 && box.right <= innerWidth && box.bottom <= innerHeight
                && row.bottom <= box.bottom && option.contains(atOption)
                && getComputedStyle(panel).backdropFilter.startsWith('blur(')
                && getComputedStyle(panel.parentElement).backdropFilter === 'none';
        })()`);
        assert.equal(visible, true, `Destination remains clickable and readable at ${label} width`);
        await writeFile(path.join(evidence, `${label}.png`),
            Buffer.from(await captureScreenshot({fullPage: false}), 'base64'));
    }
    await evaluate('document.querySelector("[data-picture-manager]").dataset.captureReady = "done"');
}
const server = createServer(
    /**
     * Serve only the explicit disposable fixture and current module sources.
     * @param {import('node:http').IncomingMessage} request Fixture request.
     * @param {import('node:http').ServerResponse} response Fixture response.
     * @return {Promise<void>} Completes one allowlisted read.
     */
    async (request, response) => {
        const pathname = new URL(request.url, 'http://localhost').pathname;
        if (pathname === '/') {
            response.setHeader('Content-Type', 'text/html; charset=utf-8');
            response.end(html);
            return;
        }
        const relative = routes.get(pathname);
        if (!relative) { response.writeHead(404).end(); return; }
        try {
            response.setHeader('Content-Type', pathname.endsWith('.css')
                ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8');
            response.end(await readFile(path.join(root, relative)));
        } catch { response.writeHead(500).end(); }
    }
);
await new Promise(
    /** Wait for the isolated loopback listener before launching Chromium. @param {()=>void} resolve Listener-ready callback. @return {import('node:http').Server} The fixture server. */
    (resolve) => server.listen(0, '127.0.0.1', resolve));
try {
    /** Read the result marker from the exact browser profile owned by this fixture. */
    const {result, exitCode} = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/', 'picker-parent-browser-',
        {interact: capturePictureManager});
    console.log(result || 'Browser fixture produced no result marker.');
    assert.equal(exitCode, 0);
    assert.ok(result?.startsWith('BROWSER PASS:'), 'Current parent integration must pass in Chromium');
} finally {
    server.close();
}
