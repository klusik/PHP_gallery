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
import {readFile, mkdtemp, rm} from 'node:fs/promises';
import {execFileSync, spawn} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

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
]);
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
            response.setHeader('Content-Type', 'text/javascript; charset=utf-8');
            response.end(await readFile(path.join(root, relative)));
        } catch { response.writeHead(500).end(); }
    }
);
await new Promise(
    /** Wait for the isolated loopback listener before launching Chromium. @param {()=>void} resolve Listener-ready callback. @return {import('node:http').Server} The fixture server. */
    (resolve) => server.listen(0, '127.0.0.1', resolve));
const cacheRoot = path.resolve(root, 'cache');
const profile = await mkdtemp(path.join(cacheRoot, 'picker-parent-browser-'));
try {
    const browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-component-update',
        '--user-data-dir=' + profile, '--dump-dom', '--virtual-time-budget=15000',
        'http://127.0.0.1:' + server.address().port + '/'], {windowsHide: true});
    let output = '';
    browser.stdout.on('data',
        /** Collect Chromium's dumped fixture DOM for the result assertion. @param {Buffer} data Process output chunk. @return {void} Appends local output. */
        (data) => { output += data; });
    browser.stderr.on('data',
        /** Drain incidental Chromium diagnostics without treating them as fixture results. @return {void} Keeps stderr from blocking the child. */
        () => {});
    const timer = setTimeout(
        /** Stop an overlong private browser process. @return {boolean} Whether termination was requested. */
        () => browser.kill(), 45000);
    try {
        const code = await new Promise(
            /** Observe browser startup failure or its final process exit. @param {(code:number|null)=>void} resolve Exit receiver. @param {(error:Error)=>void} reject Spawn failure receiver. @return {void} Registers terminal process listeners. */
            (resolve, reject) => { browser.on('error', reject); browser.on('close', resolve); });
        const result = output.match(/<pre id="results"[^>]*>([^]*?)<\/pre>/)?.[1];
        console.log(result || 'Browser fixture produced no result marker.');
        assert.equal(code, 0);
        assert.ok(result?.startsWith('BROWSER PASS:'), 'Current parent integration must pass in Chromium');
    } finally { clearTimeout(timer); }
} finally {
    server.close();
    const resolvedProfile = path.resolve(profile);
    if (path.dirname(resolvedProfile) === cacheRoot && path.basename(resolvedProfile).startsWith('picker-parent-browser-')) {
        await rm(resolvedProfile, {recursive: true, force: true, maxRetries: 3}).catch(
            /** Leave a locked private profile recoverable without masking test results. @return {void} Ignores cleanup-only failure after the checked-path removal attempt. */
            () => {});
    }
}
