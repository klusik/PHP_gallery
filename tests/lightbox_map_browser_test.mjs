/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Exercise the real lightbox map in installed headless Chromium.
 * Responsibilities:
 *   - Use local synthetic media to verify browser-only map and viewer interactions.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/lightbox_map_browser_test.mjs
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/** Run the real lightbox module in an installed headless Chromium browser, with local synthetic media only. */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    console.log('SKIP browser map test: supply the path to an installed Chrome/Edge executable (see TESTING.md).');
    process.exit(0);
}
const baseline = process.argv.includes('--baseline');
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        if (/^\/photo\/\d+$/.test(url.pathname)) {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<pre id="results">BROWSER FAIL: unexpected page fallback to ${url.pathname}</pre>`);
            return;
        }
        const relative = url.pathname === '/'
            ? 'tests/fixtures/lightbox_map_navigation.html' : url.pathname.slice(1);
        const target = path.resolve(root, relative);
        if (!target.startsWith(root + path.sep) || (!relative.startsWith('public/assets/') && relative !== 'tests/fixtures/lightbox_map_navigation.html')) {
            response.writeHead(404).end(); return;
        }
        const body = baseline && relative === 'public/assets/gallery-modules/lightbox.js'
            ? execFileSync('git', ['show', 'HEAD:public/assets/gallery-modules/lightbox.js'], {cwd: root})
            : await readFile(target);
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html' : relative.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(body);
    } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}/`;
try {
    /** Run only this synthetic loopback page inside the runner's private Edge profile. */
    const {result, exitCode} = await runHeadlessBrowserFixture(executable, url, 'map-browser-profile-');
    console.log(result || 'Browser left the fixture without a result.');
    assert.equal(exitCode, 0, 'Browser process must finish successfully');
    assert.ok(result?.includes('BROWSER PASS'), 'Browser fixture must complete all assertions');
} finally { server.close(); }
