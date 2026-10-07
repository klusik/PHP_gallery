/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/simbrief_ofp_lightbox_browser_test.mjs
 * Module Type: Browser Regression Test
 *
 * Purpose:
 *   Exercise OFP page isolation, zoom, keyboard, download and cleanup in Chromium.
 *
 * Responsibilities:
 *   - Serve the actual PDF document-viewer module from a loopback-only fixture
 *   - Mock PDF decoding without contacting SimBrief or uploading real PDFs
 *   - Ensure OFP navigation cannot enter the parent gallery photo sequence
 *
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

if (!executable || executable.startsWith('--')) {
    console.log('SKIP SimBrief OFP lightbox browser test: Chromium executable not supplied.');
    process.exit(0);
}

const html = String.raw`<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>SimBrief document fixture</title>
<link rel="stylesheet" href="/public/assets/styles/lightbox.css"></head>
<body>
<div id="results"></div>
<div id="photo-sequence" data-selected-image="7" data-count="8">Existing photo #7</div>
<div class="simbrief-ofp-actions">
<a data-simbrief-ofp-open href="/fixture/ofp.pdf">View flight plan</a>
<a download="simbrief-ofp.pdf" href="/fixture/ofp.pdf?download=1">Download PDF</a>
</div>
<script type="module">
  import {setupSimbriefOfpViewer} from '/public/assets/gallery-modules/simbrief-ofp-viewer.js';
  const results = document.getElementById('results');
  const photo = document.getElementById('photo-sequence');
  let photoShortcutEvents = 0;
  let documentRequests = 0;
  let rendered = 0;
  let destroys = 0;
  document.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowRight') photoShortcutEvents += 1;
  });
  window.pdfjsLib = {
      GlobalWorkerOptions: {workerSrc: ''},
      getDocument(options) {
          documentRequests += 1;
          if (!options.url.endsWith('/fixture/ofp.pdf') || options.isEvalSupported !== false) {
              throw new Error('Incorrect PDF source or missing eval protection');
          }
          return {
              promise: Promise.resolve({
                  numPages: 3,
                  async getPage(index) {
                      if (index < 1 || index > 3) throw new Error('Out of range PDF page');
                      return {
                          getViewport({scale}) { return {width: 600 * scale, height: 800 * scale}; },
                          render({canvasContext}) {
                              rendered += 1;
                              canvasContext.fillRect(0, 0, 10, 10);
                              return {promise: Promise.resolve(), cancel() {}};
                          },
                          cleanup() {},
                      };
                  },
                  async destroy() { destroys += 1; },
              }),
              async destroy() { destroys += 1; },
          };
      },
  };
  const waitUntil = async (condition, message) => {
      for (let retry = 0; retry < 80; retry += 1) {
          if (condition()) return;
          await new Promise(resolve => setTimeout(resolve, 40));
      }
      throw new Error('Timeout: ' + message);
  };
  const check = (value, message) => {
      if (!value) throw new Error(message);
  };
  const button = (name) => document.querySelector('[data-ofp-action="' + name + '"]');
  const counter = () => document.querySelector('[data-ofp-counter]')?.textContent || '';
  const zoom = () => document.querySelector('[data-ofp-zoom]')?.textContent || '';
  const unchangedPhoto = () =>
      check(photo.dataset.selectedImage === '7' && photo.dataset.count === '8', 'Photo sequence changed');

  try {
      setupSimbriefOfpViewer();
      setupSimbriefOfpViewer();
      document.querySelector('[data-simbrief-ofp-open]').click();
      await waitUntil(() => counter() === 'Page 1 of 3' && document.querySelector('dialog')?.open, 'first PDF page');
      check(documentRequests === 1, 'Click handler was registered twice');
      check(button('previous').disabled, 'First page previous button must be disabled');
      check(document.querySelector('[data-ofp-download]').href.endsWith('/fixture/ofp.pdf?download=1'),
          'Download must preserve original link');
      unchangedPhoto();

      button('next').click();
      await waitUntil(() => counter() === 'Page 2 of 3', 'next PDF page');
      button('next').click();
      await waitUntil(() => counter() === 'Page 3 of 3', 'last PDF page');
      check(button('next').disabled, 'Last PDF page should have no next image');
      button('next').click();
      await new Promise(resolve => setTimeout(resolve, 80));
      check(counter() === 'Page 3 of 3', 'PDF navigation wrapped into photo sequence');
      unchangedPhoto();

      button('zoom-in').click();
      await waitUntil(() => zoom() === 'Zoom 125%', 'zoom-in control');
      button('fit-page').click();
      await waitUntil(() => zoom() === 'Zoom 100%', 'fit-page control');
      document.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true}));
      await new Promise(resolve => setTimeout(resolve, 80));
      check(counter() === 'Page 3 of 3' && photoShortcutEvents === 0, 'Document shortcut leaked to photo handler');

      button('previous').click();
      await waitUntil(() => counter() === 'Page 2 of 3', 'previous page');
      document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
      await waitUntil(() => !document.querySelector('dialog'), 'document close');
      check(destroys >= 1 && rendered >= 4, 'PDF resources were not rendered or destroyed');
      unchangedPhoto();

      document.querySelector('[data-simbrief-ofp-open]').click();
      await waitUntil(() => counter() === 'Page 1 of 3', 'reopen document');
      button('close').click();
      await waitUntil(() => !document.querySelector('dialog'), 'close control');
      check(documentRequests === 2, 'Document reopening did not release previous instance');
      results.textContent = 'BROWSER PASS SimBrief OFP page navigation/zoom/isolation and cleanup';
  } catch (error) {
      results.textContent = 'BROWSER FAIL ' + (error?.stack || String(error));
  }
</script>
</body></html>`;

const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url || '/', 'http://127.0.0.1');
        if (url.pathname === '/') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            response.end(html);
            return;
        }
        if (!url.pathname.startsWith('/public/assets/')) {
            response.writeHead(404).end();
            return;
        }
        const relative = url.pathname.slice(1);
        const file = path.resolve(root, relative);
        if (!file.startsWith(root + path.sep)) {
            response.writeHead(404).end();
            return;
        }
        const body = await readFile(file);
        response.writeHead(200, {
            'Content-Type': relative.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8',
            'Cache-Control': 'no-store',
        });
        response.end(body);
    } catch {
        response.writeHead(404).end();
    }
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    const {result, exitCode} = await runHeadlessBrowserFixture(executable, url, 'simbrief-ofp-browser-', {timeoutMs: 40000});
    console.log(result || 'Browser exited without a document fixture result');
    assert.equal(exitCode, 0, 'Chromium fixture process failed');
    assert.ok(result?.startsWith('BROWSER PASS'), 'SimBrief OFP lightbox test must complete');
} finally {
    server.close();
}
