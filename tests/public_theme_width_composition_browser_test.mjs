/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_theme_width_composition_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Check production Home and Gallery layout across Theme width modes and viewport sizes.
 * Responsibilities:
 *   - Render actual public view and shared-shell output from deterministic PHP view models.
 *   - Serve only the owned fixture and repository public assets to a disposable Chromium page.
 *   - Preserve public widget order around Home/Gallery content and the production footer.
 *   - Exercise production Home search phases and floating-widget clearance against the search control.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixturePath = path.join(root, 'tests', 'fixtures', 'public_theme_width_composition.html');
const phpFixturePath = path.join(root, 'tests', 'support', 'public_theme_width_composition_fixture.php');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP public Theme-width composition browser: no Chromium executable supplied.');
    process.exit(0);
}

const phpExecutable = process.argv[3] || process.env.PHP_BINARY || 'php';
const fixtureDocument = await readFile(fixturePath);
const productionPages = new Map();
for (const page of ['home', 'home-page-3', 'gallery']) {
    productionPages.set(page, execFileSync(phpExecutable, [phpFixturePath, page], {
        encoding: 'utf8',
        windowsHide: true,
        maxBuffer: 2 * 1024 * 1024,
    }));
}
const productionPageRoutes = new Map([
    ['/home', 'home'],
    ['/gallery', 'gallery'],
    ['/fixture/home/page/3', 'home-page-3'],
]);
const searchRequests = [];

const assetRoot = path.resolve(root, 'public', 'assets');
const assetMimeTypes = new Map([
    ['.css', 'text/css; charset=utf-8'],
    ['.js', 'text/javascript; charset=utf-8'],
    ['.svg', 'image/svg+xml'],
    ['.png', 'image/png'],
    ['.jpg', 'image/jpeg'],
    ['.jpeg', 'image/jpeg'],
    ['.webp', 'image/webp'],
    ['.ico', 'image/x-icon'],
    ['.woff', 'font/woff'],
    ['.woff2', 'font/woff2'],
]);

// Purpose: Bound production layout assertions and their own route waits.
// Type: integer.
// Units: milliseconds.
// Scope: Result polling after the outer fixture document becomes interactive.
// Consumers: runHeadlessBrowserFixture() completion window.
// Rationale: Both initial iframe routes load concurrently with 15-second per-route limits, the Home page-three navigation has its own 15-second limit, and layout settling is capped at 250 milliseconds per snapshot; 60 seconds leaves margin for the complete route and responsive-layout journey.
const fixtureResultTimeoutMs = 60000;

// Purpose: Let the disposable browser reuse exact repository asset bytes across its fixture documents.
// Type: integer.
// Units: seconds.
// Scope: Browser cache lifetime for first-party assets served by this test's loopback fixture.
// Consumers: Chromium's private HTTP cache for this one fixture run.
// Rationale: Repository assets cannot change during the run, and the owned browser profile is removed afterward.
const fixtureAssetCacheMaxAgeSeconds = 3600;

// Keep one in-memory copy of each requested, allowlisted repository asset for this Node fixture process.
const fixtureAssetContent = new Map();

/** Serve rendered production page routes and first-party assets from a loopback-only allowlist.
 * @param {import('node:http').IncomingMessage} request Owned browser request.
 * @param {import('node:http').ServerResponse} response Response for the loopback fixture.
 * @return {Promise<void>} Writes one bounded fixture, page or repository-asset response.
 */
const serveFixtureRequest = async (request, response) => {
    const requestUrl = new URL(request.url || '/', 'http://127.0.0.1');
    if (request.method !== 'GET' && request.method !== 'HEAD') {
        response.writeHead(405, {'Content-Type': 'text/plain; charset=utf-8'}).end('Method not allowed');
        return;
    }
    if (requestUrl.pathname === '/') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(request.method === 'HEAD' ? undefined : fixtureDocument);
        return;
    }
    const productionPage = productionPageRoutes.get(requestUrl.pathname);
    if (productionPage) {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(request.method === 'HEAD' ? undefined : productionPages.get(productionPage));
        return;
    }
    if (requestUrl.pathname === '/fixture-search') {
        const query = requestUrl.searchParams.get('q') || '';
        const phase = requestUrl.searchParams.get('phase') || '';
        searchRequests.push({query, phase});
        response.writeHead(200, {'Content-Type': 'application/json; charset=utf-8'});
        response.end(request.method === 'HEAD' ? undefined : JSON.stringify({
            results: [{
                key: 'fixture-search-result',
                type: 'gallery',
                url: '/fixture/gallery/search-result',
                label: 'Gallery',
                title: 'Fixture match for ' + query,
                subtitle: 'Fixture public result',
                rank: 100,
            }],
        }));
        return;
    }
    if (!requestUrl.pathname.startsWith('/public/assets/')) {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Fixture route not found');
        return;
    }

    let relativeAssetPath;
    try {
        relativeAssetPath = decodeURIComponent(requestUrl.pathname.slice('/public/'.length));
    } catch {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Invalid asset path');
        return;
    }
    if (!relativeAssetPath.startsWith('assets/')) {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Fixture asset not found');
        return;
    }
    const assetPath = path.resolve(root, 'public', relativeAssetPath);
    const assetRelativePath = path.relative(assetRoot, assetPath);
    if (assetRelativePath === '' || assetRelativePath.startsWith('..') || path.isAbsolute(assetRelativePath)) {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Fixture asset not found');
        return;
    }
    const contentType = assetMimeTypes.get(path.extname(assetPath).toLowerCase());
    if (!contentType) {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Unsupported fixture asset');
        return;
    }
    try {
        let contentPromise = fixtureAssetContent.get(assetPath);
        if (!contentPromise) {
            contentPromise = readFile(assetPath);
            fixtureAssetContent.set(assetPath, contentPromise);
        }
        const content = await contentPromise;
        // Purpose: Keep exact allowlisted repository assets reusable only in this disposable browser's private cache.
        // Type: integer.
        // Units: seconds.
        // Scope: This successful loopback asset response; the owned profile is discarded after the fixture.
        // Consumers: Chromium requests for the same versioned asset URL across Home, Gallery and page-three documents.
        // Rationale: These repository bytes stay fixed for one run, so re-fetching them adds work without improving fixture isolation.
        response.writeHead(200, {
            'Content-Type': contentType,
            'Cache-Control': 'private, max-age=' + fixtureAssetCacheMaxAgeSeconds,
        });
        response.end(request.method === 'HEAD' ? undefined : content);
    } catch {
        response.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'}).end('Fixture asset not found');
    }
};

const server = createServer((request, response) => {
    void serveFixtureRequest(request, response).catch(() => {
        if (!response.headersSent) response.writeHead(500, {'Content-Type': 'text/plain; charset=utf-8'});
        response.end('Fixture server failed');
    });
});

try {
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    const address = server.address();
    assert(address && typeof address === 'object', 'Owned fixture server must bind a loopback socket.');
    const {result, exitCode} = await runHeadlessBrowserFixture(
        executable,
        `http://127.0.0.1:${address.port}/`,
        'public-theme-width-',
        {viewport: {width: 1480, height: 1200}, timeoutMs: fixtureResultTimeoutMs}
    );
    assert.equal(exitCode, 0, 'Owned Chromium must close successfully after the public layout check.');
    assert.match(result, /^BROWSER PASS [0-9]+ production Home\/Gallery Theme-width layout assertions$/,
        'Actual production Home and Gallery documents must pass their responsive composition checks.');
    assert(searchRequests.length >= 4, 'The real Home search must issue every staged request to its loopback endpoint.');
    assert.deepEqual(
        [...new Set(searchRequests.map(({phase}) => phase))].sort(),
        ['deep', 'descriptive', 'media', 'primary'],
        'The actual public Home search must request all four progressive phases.'
    );
    assert(searchRequests.every(({query}) => query === 'public widget search'),
        'Every staged Home search request must preserve the exact typed query.');
    console.log(result);
} finally {
    await new Promise(resolve => server.close(() => resolve()));
}
