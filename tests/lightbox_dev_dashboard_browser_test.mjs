/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/lightbox_dev_dashboard_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify optional diagnostics against the real lightbox in Chromium.
 * Responsibilities: Serve isolated synthetic media and run the registered browser fixture.
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
    console.log('SKIP browser diagnostics test: supply an installed Chromium executable.');
    process.exit(0);
}
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/public/assets/gallery-modules/lightbox-dev-dashboard.js' && url.searchParams.get('v') === '20261005-dev-dashboard-v1') {
            response.setHeader('Content-Type', 'text/javascript');
            response.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
            // Keep the obsolete URL genuinely immutable to reproduce a warm module cache.
            response.end('export const obsoleteDashboardAsset = true; export const createLightboxDevDashboard = () => { throw new Error("Obsolete dashboard must not be reused"); };');
            return;
        }
        if (url.pathname.startsWith('/media/')) {
            if (url.pathname.includes('broken')) { response.writeHead(404).end(); return; }
            const full = url.pathname.includes('full');
            const width = full ? 2700 : 900;
            const height = full ? 1800 : 600;
            const id = Number(url.pathname.match(/\d+/)?.[0]) || 1;
            const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><defs><linearGradient id="sky" x2="0" y2="1"><stop stop-color="#102e52"/><stop offset="1" stop-color="#6597b1"/></linearGradient></defs><rect width="100%" height="100%" fill="url(#sky)"/><path d="M0 ${height} L${width / 3} ${height / 3} L${width / 2} ${height * 0.7} L${width * 0.8} ${height / 2} L${width} ${height}Z" fill="#203d46"/><text x="8%" y="20%" font-family="sans-serif" font-size="${width / 25}" fill="white">Diagnostic photo ${id}</text></svg>`;
            response.setHeader('Content-Type', 'image/svg+xml');
            response.setHeader('Cache-Control', 'public, max-age=3600');
            response.setHeader('Content-Length', Buffer.byteLength(svg));
            // A controlled cold-load interval makes pending versus displayed state observable.
            setTimeout(() => response.end(svg), full ? 180 : 90);
            return;
        }
        const relative = url.pathname === '/' ? 'tests/fixtures/lightbox_dev_dashboard.html' : url.pathname.slice(1);
        const target = path.resolve(root, relative);
        if (!target.startsWith(root + path.sep) || (!relative.startsWith('public/assets/') && relative !== 'tests/fixtures/lightbox_dev_dashboard.html' && relative !== 'app/lang/cs.json')) {
            response.writeHead(404).end(); return;
        }
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html' : relative.endsWith('.css') ? 'text/css' : relative.endsWith('.json') ? 'application/json' : 'text/javascript');
        if (relative.endsWith('.js')) response.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        response.end(await readFile(target));
    } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}/`;
if (preview) {
    console.log(`${url}?preview=1`);
} else {
    try {
        const {result, exitCode} = await runHeadlessBrowserFixture(executable, url, 'dev-dashboard-profile-');
        console.log(result || 'Browser left the fixture without a result.');
        assert.equal(exitCode, 0, 'Browser process must finish successfully');
        assert.ok(result?.includes('BROWSER PASS'), 'Diagnostics browser assertions must complete');
    } finally { server.close(); }
}
