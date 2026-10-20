/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_gallery_cover_stars_browser.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify server-confirmed title-picture changes preserve the open editor.
 * Responsibilities:
 *   - Exercise the real delegated image-star and side-panel submission modules.
 *   - Assert the three-state sequence and editor identity, drafts, scroll, tab and URL.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './headless_browser_fixture.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP cover-star browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/admin_gallery_cover_stars.html'],
    ['/public-gallery', 'tests/fixtures/admin_gallery_cover_stars.html'],
    ['/fixture.js', 'tests/fixtures/admin_gallery_cover_stars.js'],
]);
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    const route = routes.get(pathname) || (pathname.startsWith('/assets/')
        ? 'public' + pathname
        : pathname === '/admin-gallery-images.js' ? 'public/assets/gallery-modules/admin-gallery-images.js'
            : pathname === '/admin-side-panel.js' ? 'public/assets/gallery-modules/admin-side-panel.js' : null);
    if (!route) { response.writeHead(404).end(); return; }
    try {
        const contentType = route.endsWith('.html')
            ? 'text/html; charset=utf-8'
            : route.endsWith('.css') ? 'text/css; charset=utf-8'
                : 'text/javascript; charset=utf-8';
        response.setHeader('Content-Type', contentType);
        response.end(await readFile(path.join(projectRoot, route)));
    } catch { response.writeHead(500).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const {result, exitCode} = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/public-gallery', 'cover-stars-browser-');
    console.log(result || 'Cover-star fixture did not produce a result marker.');
    assert.equal(exitCode, 0);
    assert.ok(result?.includes('BROWSER PASS'), 'Cover-star browser fixture must complete');
} finally {
    server.close();
}
