/**
 * Project: PHP Gallery
 * File: tests/admin_gallery_title_completion_browser_test.mjs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Run production title-completion events in a disposable Chromium document.
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile, mkdtemp, rm} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP title-completion browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/admin_gallery_title_completion.html'],
    ['/completion.js', 'public/assets/gallery-modules/admin-gallery-title-completion.js'],
    ['/completion.css', 'public/assets/styles/admin-gallery-title-completion.css'],
]);
const server = createServer(async (request, response) => {
    const relative = routes.get(new URL(request.url, 'http://localhost').pathname);
    if (!relative) { response.writeHead(404).end(); return; }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8' : relative.endsWith('.css') ? 'text/css' : 'text/javascript; charset=utf-8');
        response.end(await readFile(path.join(root, relative)));
    } catch { response.writeHead(500).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const profile = await mkdtemp(path.join(root, 'cache', 'title-browser-'));
try {
    const browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-component-update',
        '--user-data-dir=' + profile, '--dump-dom', '--virtual-time-budget=15000',
        'http://127.0.0.1:' + server.address().port + '/'], {windowsHide: true});
    let output = ''; let errors = '';
    browser.stdout.on('data', data => { output += data; });
    browser.stderr.on('data', data => { errors += data; });
    const timer = setTimeout(() => browser.kill(), 45000);
    try {
        const code = await new Promise((resolve, reject) => { browser.on('error', reject); browser.on('close', resolve); });
        const result = output.match(/<pre id="results"[^>]*>([^]*?)<\/pre>/)?.[1];
        console.log(result || errors.slice(-1000));
        assert.equal(code, 0);
        assert.ok(result?.includes('BROWSER PASS'), 'Title event fixture must complete');
    } finally { clearTimeout(timer); }
} finally {
    server.close();
    // Only the exact unique profile created above is disposable.
    if (path.dirname(profile) === path.join(root, 'cache') && path.basename(profile).startsWith('title-browser-')) {
        await rm(profile, {recursive: true, force: true, maxRetries: 3}).catch(() => {});
    }
}
