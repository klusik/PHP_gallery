/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise the real widget Admin positioning module in disposable Chromium.
 * Responsibilities:
 *   - Verify pointer drag, keyboard positioning, reset and draft-safe controls.
 *   - Keep fixtures confined to an isolated, loopback-only browser profile.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
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
    console.log('SKIP widget Admin browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/public_content_widget_admin_position.html'],
    ['/widget-admin.js', 'public/assets/gallery-modules/admin-public-widgets.js'],
    ['/widget-admin.css', 'public/assets/styles/admin-public-widgets.css'],
]);
const server = createServer(async (request, response) => {
    const relative = routes.get(new URL(request.url, 'http://localhost').pathname);
    if (!relative) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8'
            : relative.endsWith('.css') ? 'text/css' : 'text/javascript; charset=utf-8');
        response.end(await readFile(path.join(root, relative)));
    } catch {
        response.writeHead(500).end();
    }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
try {
    const {result, exitCode} = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/', 'widget-admin-browser-');
    console.log(result || 'Widget Admin fixture produced no status marker.');
    assert.equal(exitCode, 0);
    assert.ok(result?.includes('BROWSER PASS'), 'Widget pointer and keyboard fixture must complete.');
} finally {
    server.close();
}
