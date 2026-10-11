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
    ['/nojs', 'tests/fixtures/public_content_widget_public_placement.html'],
    ['/public-widgets.js', 'public/assets/gallery-modules/public-content-widgets.js'],
    ['/public-widgets.css', 'public/assets/styles/public-content-widgets.css'],
    ['/lightbox.css', 'public/assets/styles/lightbox.css'],
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
        const source = await readFile(path.join(root, relative), 'utf8');
        response.end(new URL(request.url, 'http://localhost').pathname === '/nojs'
            ? source.replace(/<script type="module">[\s\S]*?<\/script>/, '') : source);
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
    // Remove the browser enhancer entirely and verify the actual initial HTML/CSS fallback.
    // DevTools evaluates assertions only; no public widget script is included in /nojs.
    const noScript = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/nojs', 'widget-public-nojs-',
        {
            viewport: {width: 1280, height: 900},
            interact: async ({evaluate}) => {
                const result = await evaluate(`(async () => {
                    for (let i = 0; i < 80 && document.readyState !== 'complete'; i++) {
                        await new Promise(resolve => setTimeout(resolve, 30));
                    }
                    const widgets = Array.from(document.querySelectorAll('[data-public-widget-id]'));
                    const unique = new Set(widgets.map(node => node.dataset.publicWidgetId));
                    const pass = widgets.length === 3 && unique.size === 3
                        && widgets.every(node => !node.hidden && getComputedStyle(node).position !== 'fixed')
                        && widgets.every(node => node.querySelector('a, p'))
                        && document.querySelector('.public-widget-dismiss') === null
                        && !document.querySelector('script[type="module"]')
                        && document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1;
                    const marker = pass ? 'BROWSER PASS: public widgets genuine no-script SSR fallback'
                        : 'BROWSER FAIL: no-script SSR content or document flow missing';
                    document.getElementById('results').textContent = marker;
                    return marker;
                })()`);
                assert.ok(String(result).startsWith('BROWSER PASS'), 'No-script SSR must remain usable');
            },
        });
    console.log(noScript.result || 'No-script fixture produced no status marker.');
    assert.equal(noScript.exitCode, 0, 'No-script browser process must exit cleanly.');
    assert.ok(noScript.result.includes('genuine no-script SSR fallback'), 'No-script fixture must pass');
} finally {
    server.close();
}
