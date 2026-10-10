/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise real widget Admin positioning and protected public previews in Chromium.
 * Responsibilities:
 *   - Verify pointer drag, keyboard positioning, reset and draft-safe controls.
 *   - Verify Home/Gallery insertion, peer/action geometry, responsive fit, and mobile fallback.
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
    ['/preview', 'tests/fixtures/public_content_widget_admin_preview.html'],
    ['/widget-admin.js', 'public/assets/gallery-modules/admin-public-widgets.js'],
    ['/public-content-widgets.js', 'public/assets/gallery-modules/public-content-widgets.js'],
    ['/widget-admin.css', 'public/assets/styles/admin-public-widgets.css'],
    ['/widget-public.css', 'public/assets/styles/public-content-widgets.css'],
    ['/widget-shared.css', 'public/assets/styles/public-shared.css'],
    ['/widget-shell.css', 'public/assets/styles/public.css'],
]);
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const page = url.searchParams.get('page');
    const previewKeys = [...url.searchParams.keys()];
    const protectedPreview = url.pathname === '/index.php'
        && url.searchParams.getAll('page').length === 1
        && url.searchParams.getAll('preview').length === 1
        && url.searchParams.get('preview') === 'visual'
        && url.searchParams.getAll('view_as').length === 1
        && url.searchParams.get('view_as') === 'anonymous'
        && (page === 'home' && previewKeys.length === 3
            || page === 'gallery' && previewKeys.length === 4
                && url.searchParams.getAll('public_path').length === 1
                && url.searchParams.get('public_path') === 'preview-gallery');
    const legacyHomePreview = url.pathname === '/index.php'
        && page === 'home' && url.searchParams.getAll('page').length === 1
        && url.searchParams.getAll('preview').length === 1
        && url.searchParams.get('preview') === 'visual'
        && previewKeys.length === 2;
    const relative = protectedPreview || legacyHomePreview
        ? page === 'gallery' ? 'tests/fixtures/public_content_widget_theme_gallery.html'
            : 'tests/fixtures/public_content_widget_theme_home.html'
        : url.pathname === '/index.php' ? null : routes.get(url.pathname);
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

    const preview = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/preview', 'widget-admin-theme-preview-', {
            viewport: {width: 1280, height: 900},
            interact: async ({evaluate}) => {
                const fitted = await evaluate(`new Promise(resolve => {
                    const deadline = Date.now() + 5000;
                    const check = () => {
                        const result = document.getElementById('results')?.textContent || '';
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        if (!result.startsWith('BROWSER PASS') || !frame?.contentDocument?.body) {
                            if (Date.now() >= deadline) resolve(null);
                            else setTimeout(check, 25);
                            return;
                        }
                        const host = document.querySelector('[data-widget-theme-preview]');
                        const shell = host?.querySelector('[data-widget-theme-frame-wrap]');
                        if (!host || !shell) return resolve(null);
                        host.style.width = '420px';
                        host.style.maxWidth = 'none';
                        const measure = () => {
                            const hostBounds = host.getBoundingClientRect();
                            const frameBounds = frame.getBoundingClientRect();
                            const availableWidth = Math.max(1, hostBounds.width - 20);
                            const expectedScale = Math.min(1, availableWidth / 1280);
                            const scale = Number(frame.style.transform.match(/scale\\(([^)]+)\\)/)?.[1] || 0);
                            const ready = Math.abs(scale - expectedScale) < 0.002
                                && Math.abs(Number.parseFloat(shell.style.width) - 1280 * expectedScale) < 1
                                && frameBounds.right <= hostBounds.right + 1
                                && frame.contentDocument.documentElement.clientWidth === 1280
                                && frame.contentDocument.body.dataset.previewFixturePage === 'gallery';
                            if (ready) resolve({scale, expectedScale, frameWidth: frameBounds.width,
                                hostWidth: hostBounds.width, viewportWidth: frame.contentDocument.documentElement.clientWidth});
                            else if (Date.now() >= deadline) resolve(null);
                            else setTimeout(measure, 25);
                        };
                        requestAnimationFrame(measure);
                    };
                    check();
                })`);
                assert.ok(fitted,
                    'Gallery detail preview must refit after the Admin workspace narrows while retaining the selected desktop viewport.');
                assert.ok(fitted.frameWidth <= fitted.hostWidth + 1,
                    'The resized Gallery preview remains inside its Admin host.');
                assert.equal(fitted.viewportWidth, 1280,
                    'Resizing the Admin host changes only the preview scale, not the selected public device viewport.');
            },
        });
    console.log(preview.result || 'Protected widget preview fixture produced no status marker.');
    assert.equal(preview.exitCode, 0, 'Protected widget preview browser process must exit cleanly.');
    assert.ok(preview.result?.includes('BROWSER PASS: widget protected Home/Gallery floating geometry, flow insertion and mobile flow fallback'),
        'Protected public preview must exercise both pages, flow insertion, peer-aware desktop geometry, mobile fallback and sandbox behavior.');
} finally {
    server.close();
}
