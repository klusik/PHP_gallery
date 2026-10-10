/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_public_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Qualify the production public widget CSS and JS against real Chromium geometry.
 * Responsibilities:
 *   - Verify server-rendered articles are in the DOM before enhancement.
 *   - Exercise desktop fixed anchoring, collision avoidance, scrolling and dismissal.
 *   - Prove narrow/mobile fallback retains a single accessible in-flow instance.
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
    console.log('SKIP public widget browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/public_content_widget_public_placement.html'],
    ['/public-widgets.js', 'public/assets/gallery-modules/public-content-widgets.js'],
    ['/public-widgets.css', 'public/assets/styles/public-content-widgets.css'],
]);
const server = createServer(async (request, response) => {
    const relative = routes.get(new URL(request.url, 'http://localhost').pathname);
    if (!relative) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8'
            : relative.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8');
        response.end(await readFile(path.join(root, relative)));
    } catch {
        response.writeHead(500).end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const [label, viewport, expected] of [
        ['desktop', {width: 1280, height: 900}, 'desktop fixed placement'],
        ['mobile', {width: 390, height: 740}, 'mobile no-overlay'],
    ]) {
        const {result, exitCode} = await runHeadlessBrowserFixture(executable,
            'http://127.0.0.1:' + server.address().port + '/', 'widget-public-' + label + '-',
            {viewport});
        console.log(result || 'Public widget ' + label + ' fixture produced no status marker.');
        assert.equal(exitCode, 0, 'Browser process must exit cleanly: ' + label);
        assert.ok(result?.startsWith('BROWSER PASS') && result.includes(expected),
            'Public widget ' + label + ' geometry and fallback must pass');
    }
} finally {
    server.close();
}
