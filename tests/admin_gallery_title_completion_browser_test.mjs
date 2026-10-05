/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Regression Test
 * Purpose: Exercise production title-completion events in disposable Chromium.
 * Responsibilities:
 *   - Check input behavior and suggestion lifecycle against a synthetic document.
 * File: tests/admin_gallery_title_completion_browser_test.mjs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Run production title-completion events in a disposable Chromium document.
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP title-completion browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/admin_gallery_title_completion.html'],
    ['/completion.js', 'public/assets/gallery-modules/admin-gallery-title-completion.js'],
    ['/admin-interaction-policy.js', 'public/assets/gallery-modules/admin-interaction-policy.js'],
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
try {
    /** Keep browser storage in a unique profile that the shared runner removes after Edge closes. */
    const {result, exitCode} = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/', 'title-browser-');
    console.log(result || 'Title fixture did not produce a result marker.');
    assert.equal(exitCode, 0);
    assert.ok(result?.includes('BROWSER PASS'), 'Title event fixture must complete');
} finally {
    server.close();
}
