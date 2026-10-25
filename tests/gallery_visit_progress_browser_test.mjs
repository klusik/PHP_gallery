/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_visit_progress_browser_test.mjs
 * Module Type: Browser Regression
 * Purpose: Verify real bell markup, inline statuses and responsive panel behavior.
 * Responsibilities: Serve confined first-party assets to disposable Chromium.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
assert.ok(executable, 'The required browser owner must supply Chromium.');
const markup = execFileSync(process.argv[3] || process.env.PHP_BINARY || 'php', [
    path.join(root, 'tests/support/gallery_visit_render_fixture.php'),
], {encoding: 'utf8', windowsHide: true});
const routes = new Map([
    ['/', 'tests/fixtures/gallery_visit_progress.html'],
    ['/visits.js', 'public/assets/gallery-modules/gallery-visit-progress.js'],
    ['/base.css', 'public/assets/styles/base.css'],
    ['/public.css', 'public/assets/styles/public.css'],
    ['/shared.css', 'public/assets/styles/public-shared.css'],
    ['/utilities.css', 'public/assets/styles/utilities.css'],
]);
const server = createServer(async (request, response) => {
    const relative = routes.get(new URL(request.url, 'http://localhost').pathname);
    if (!relative) { response.writeHead(404).end(); return; }
    try {
        let content = await readFile(path.join(root, relative), 'utf8');
        if (relative.endsWith('.html')) content = content.replace('<!-- VISIT_MARKUP -->', markup);
        response.setHeader('Content-Type', relative.endsWith('.html')
            ? 'text/html; charset=utf-8' : relative.endsWith('.css')
                ? 'text/css' : 'text/javascript; charset=utf-8');
        response.end(content);
    } catch { response.writeHead(500).end(); }
});
/**
 * Bound one responsive/browser-storage journey independently of startup.
 * Type: number. Units: milliseconds. Scope: one disposable browser fixture.
 * Consumers: runHeadlessBrowserFixture.
 * Rationale: 15000 allows actual Web Locks/storage events and layout assertions.
 */
const FIXTURE_DEADLINE = 15000;
/**
 * Exercise the actual public layout at representative desktop/mobile sizes.
 * Type: Array<{width:number,height:number}>. Units: CSS pixels.
 * Scope: this responsive regression. Consumers: the owned browser runner.
 * Rationale: desktop 1280x900, narrow 390x844 and short CSS viewport 640x450
 *   cover wrapped titles, 44px actions and dynamic height under constrained space.
 */
const VIEWPORTS = [
    {width: 1280, height: 900},
    {width: 390, height: 844},
    {width: 640, height: 450},
];
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const viewport of VIEWPORTS) {
        const result = await runHeadlessBrowserFixture(executable,
            `http://127.0.0.1:${server.address().port}/`, 'visit-browser-',
            {viewport, timeoutMs: FIXTURE_DEADLINE});
        assert.equal(result.exitCode, 0);
        assert.match(result.result, /BROWSER PASS/, result.result || result.timeoutDetail);
    }
} finally {
    server.close();
}
console.log('PASS visitor inline icons, anchored translucent panel, keyboard/reset and responsive geometry');
