/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_ux_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise compact public overlays and mobile gestures in Chromium.
 * Responsibilities: Serve isolated production assets and responsive fixtures.
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
const preview = process.argv.includes('--preview');
if (!executable && !preview) {
    console.log('SKIP public UX browser test: supply an installed Chromium.');
    process.exit(0);
}
const fixture = 'tests/fixtures/public_ux.html';
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        const relative = url.pathname === '/' ? fixture : url.pathname.slice(1);
        const target = path.resolve(root, relative);
        if (!target.startsWith(root + path.sep)
            || (!relative.startsWith('public/assets/') && relative !== fixture)) {
            response.writeHead(404).end();
            return;
        }
        const type = relative.endsWith('.html') ? 'text/html'
            : (relative.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.writeHead(200, {'Content-Type': type, 'Cache-Control': 'no-store'});
        response.end(await readFile(target));
    } catch {
        response.writeHead(404).end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}/`;
if (preview) {
    console.log(url);
} else {
    try {
        const {result, exitCode} = await runHeadlessBrowserFixture(
            executable, url, 'public-ux-browser-');
        console.log(result);
        assert.equal(exitCode, 0, 'Browser must close successfully.');
        assert.ok(result?.includes('BROWSER PASS'), 'Public UX scenarios must pass.');
    } finally {
        server.close();
    }
}
