/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/breadcrumb_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Verify real breadcrumb markup across narrow, desktop, and theme layouts.
 * Responsibilities: Serve the PHP-rendered view and production stylesheet to isolated Chromium.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
const php = process.argv[3] || process.env.PHP_GALLERY_PHP || 'php';
if (!executable) {
    console.log('SKIP breadcrumb browser test: supply an installed Chromium executable.');
    process.exit(0);
}

const fixtureHtml = execFileSync(php, [path.join(root, 'tests/fixtures/breadcrumbs.php')], {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
});
assert.ok(fixtureHtml.includes('breadcrumbs__list'), 'Fixture must contain output from the PHP breadcrumb view.');

const stylesheetFiles = new Map([
    ['/public/assets/styles/base.css', 'public/assets/styles/base.css'],
    ['/public/assets/styles/public.css', 'public/assets/styles/public.css'],
    ['/public/assets/styles/lightbox.css', 'public/assets/styles/lightbox.css'],
    ['/public/assets/styles/public-shared.css', 'public/assets/styles/public-shared.css'],
    ['/public/assets/styles/utilities.css', 'public/assets/styles/utilities.css'],
    ['/public/assets/styles.css', 'public/assets/styles.css'],
    ['/public/assets/styles/breadcrumbs.css', 'public/assets/styles/breadcrumbs.css'],
]);
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    if (pathname === '/') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}).end(fixtureHtml);
        return;
    }
    if (stylesheetFiles.has(pathname)) {
        response.writeHead(200, {'Content-Type': 'text/css; charset=utf-8'});
        response.end(await readFile(path.join(root, stylesheetFiles.get(pathname))));
        return;
    }
    response.writeHead(404).end();
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = `http://127.0.0.1:${server.address().port}/`;
try {
    const {result, exitCode} = await runHeadlessBrowserFixture(executable, url, 'breadcrumb-browser-profile-', {timeoutMs: 60000});
    console.log(result || 'Browser left the breadcrumb fixture without a result.');
    assert.equal(exitCode, 0, 'Browser process must finish successfully.');
    assert.ok(result?.includes('BROWSER PASS'), 'Breadcrumb browser assertions must complete.');
} finally {
    server.close();
}
