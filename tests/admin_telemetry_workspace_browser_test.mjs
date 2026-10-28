/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_telemetry_workspace_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify real telemetry markup, scoped save ordering and responsive UI.
 * Responsibilities: Serve isolated production assets and require both journeys.
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
if (!executable) {
    console.log('SKIP telemetry workspace browser: no Chromium executable supplied.');
    process.exit(0);
}
/**
 * Bound the isolated PHP renderer before starting a browser.
 * Type: number. Units: milliseconds. Scope: one fixture render.
 * Consumers: execFileSync(). Value: 10000.
 * Rationale: production markup rendering needs no installation or external I/O.
 */
const RENDER_TIMEOUT_MS = 10000;
/**
 * Cover the three-column desktop and single-column narrow workspace.
 * Type: Array<{width:number,height:number}>. Units: CSS pixels.
 * Scope: two independent browser profiles. Consumers: browser viewport emulation.
 * Value: 1200x800 and 390x844.
 * Rationale: verify group flow, help bounds and mobile targets across breakpoints.
 */
const VIEWPORTS = [{width: 1200, height: 800}, {width: 390, height: 844}];
const markup = execFileSync(process.env.PHP_BINARY || 'php', [
    path.join(root, 'tests/admin_telemetry_workspace_test.php'), '--render',
], {encoding: 'utf8', windowsHide: true, timeout: RENDER_TIMEOUT_MS});
const html = (await readFile(path.join(root,
    'tests/fixtures/admin_telemetry_workspace.html'), 'utf8'))
    .replace('<!-- TELEMETRY_WORKSPACE -->', markup);
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname.endsWith('/thumb-600.webp')) {
        // This synthetic image tests the browser preview owner's loading and
        // lifecycle; derivative encoding/authorization have their own fixtures.
        response.setHeader('Content-Type', 'image/svg+xml');
        response.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="#456"/></svg>');
        return;
    }
    if (pathname === '/' || pathname === '/index.php') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(html);
        return;
    }
    const file = path.resolve(root, '.' + pathname);
    if (!pathname.startsWith('/public/assets/')
        || !file.startsWith(root + path.sep)
        || !['.js', '.css'].includes(path.extname(file))) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(await readFile(file));
    } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const viewport of VIEWPORTS) {
        const {result, exitCode} = await runHeadlessBrowserFixture(executable,
            'http://127.0.0.1:' + server.address().port + '/', 'telemetry-workspace-', {viewport});
        console.log(viewport.width + 'px: ' + (result || 'No telemetry fixture result.'));
        assert.equal(exitCode, 0);
        assert.ok(result?.includes('BROWSER PASS'), 'Telemetry workspace must complete all phases.');
    }
} finally { server.close(); }
