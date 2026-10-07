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
                          getViewport({scale}) {
                              const sizes = [[595, 842], [842, 595], [600, 1100]];
                              return {width: sizes[index - 1][0] * scale, height: sizes[index - 1][1] * scale};
                          },
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
  const stage = () => document.querySelector('[data-ofp-stage]');
  const canvas = () => stage().querySelector('canvas');
  const active = (name) => button(name).getAttribute('aria-pressed') === 'true';
  const settled = () => canvas() && document.querySelector('[data-ofp-status]').textContent === '';
  const pause = (ms) => new Promise(resolve => setTimeout(resolve, ms));
  const afterRender = (oldCount, reason) => waitUntil(() => rendered > oldCount && settled(), reason);
  const wholePage = (reason, width, height) => {
      const viewport = stage().getBoundingClientRect();
      const page = canvas().getBoundingClientRect();
      check(stage().clientWidth > 100 && stage().clientHeight > 100,
          reason + ': grid stage collapsed to ' + stage().clientWidth + ' × '
          + stage().clientHeight + ' CSS pixels');
      check(page.top >= viewport.top - 2 && page.bottom <= viewport.bottom + 2
          && page.left >= viewport.left - 2 && page.right <= viewport.right + 2,
          reason + ': PDF page clipped');
      check(stage().scrollHeight <= stage().clientHeight + 2
          && stage().scrollWidth <= stage().clientWidth + 2, reason + ': scrollbars in whole-page fit');
      check(Math.abs((page.top + page.bottom) / 2 - (viewport.top + stage().clientHeight / 2)) < 4
          && Math.abs((page.left + page.right) / 2 - (viewport.left + stage().clientWidth / 2)) < 4,
          reason + ': PDF page not centered');
      check(Math.abs(page.width / page.height - width / height) < 0.01,
          reason + ': PDF aspect ratio was distorted (' + page.width.toFixed(2)
          + ' × ' + page.height.toFixed(2) + ' displayed, expected '
          + width + ' × ' + height + ', stage '
          + stage().clientWidth + ' × ' + stage().clientHeight + ')');
      check(active('fit-page'), reason + ': expected whole-page view mode');
      check(viewport.bottom <= document.querySelector('.simbrief-ofp-toolbar').getBoundingClientRect().top + 1,
          reason + ': toolbar overlaps PDF stage');
  };

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
      check(stage().clientWidth > stage().clientHeight, 'Landscape fixture was not landscape');
      wholePage('first A4 portrait page', 595, 842);
      let previousRender = rendered;
      button('fit-width').click();
      await afterRender(previousRender, 'fit-width');
      check(active('fit-width') && !active('fit-page'), 'Fit-width mode not announced');
      check(stage().scrollHeight > stage().clientHeight + 10, 'Fit-width cannot scroll vertically');
      check(canvas().getBoundingClientRect().width <= stage().clientWidth - 10,
          'Fit width causes horizontal clipping');

      previousRender = rendered;
      button('next').click();
      await waitUntil(() => counter() === 'Page 2 of 3' && rendered > previousRender && settled(),
          'next PDF page');
      check(active('fit-width'), 'Fit-width mode was lost on page navigation');
      check(Math.abs(canvas().getBoundingClientRect().width /
          canvas().getBoundingClientRect().height - 842 / 595) < 0.01,
          'Landscape page dimensions were not recalculated');
      button('next').click();
      await waitUntil(() => counter() === 'Page 3 of 3', 'last PDF page');
      check(button('next').disabled, 'Last PDF page should have no next image');
      button('next').click();
      await new Promise(resolve => setTimeout(resolve, 80));
      check(counter() === 'Page 3 of 3', 'PDF navigation wrapped into photo sequence');
      unchangedPhoto();
      previousRender = rendered;
      button('fit-page').click();
      await afterRender(previousRender, 'whole page on tall portrait');
      wholePage('tall portrait page', 600, 1100);

      button('zoom-in').click();
      await waitUntil(() => zoom() === 'Zoom 125%' && settled(), 'zoom-in control');
      check(!active('fit-page') && !active('fit-width'), 'Manual zoom still indicates auto fit');
      const originalWidth = canvas().getBoundingClientRect().width;
      stage().scrollTo({top: 40, behavior: 'instant'});
      await pause(100);
      const originalPan = stage().scrollTop;
      check(originalPan > 0, 'Manual zoom does not allow expected vertical panning');
      previousRender = rendered;
      document.querySelector('dialog').style.height = '480px';
      await pause(160);
      check(rendered === previousRender, 'Manual zoom rerendered after ordinary resize');
      check(Math.abs(canvas().getBoundingClientRect().width - originalWidth) < 1,
          'Manual zoom scale changed after ordinary resize');
      check(stage().scrollTop >= Math.min(originalPan, Math.max(0, stage().scrollHeight - stage().clientHeight)) - 2,
          'Manual pan reset after resize: before=' + originalPan + ', after=' + stage().scrollTop
          + ', scrollHeight=' + stage().scrollHeight + ', clientHeight=' + stage().clientHeight
          + ', rendered=' + rendered);

      previousRender = rendered;
      button('reset').click();
      await afterRender(previousRender, 'reset manual zoom');
      check(zoom() === 'Zoom 100%', 'Reset did not restore last fit');
      wholePage('reset to whole page', 600, 1100);

      const dialog = document.querySelector('dialog');
      previousRender = rendered;
      dialog.style.width = '360px';
      dialog.style.height = '540px';
      await afterRender(previousRender, 'narrow portrait viewport');
      wholePage('narrow portrait viewport', 600, 1100);
      previousRender = rendered;
      dialog.style.width = '750px';
      dialog.style.height = '430px';
      await afterRender(previousRender, 'landscape rotation');
      wholePage('landscape rotation', 600, 1100);
      // Headless programmatic clicks lack user activation for real fullscreen.
      // Verify the document-specific fullscreen relayout event lifecycle directly.
      previousRender = rendered;
      document.dispatchEvent(new Event('fullscreenchange'));
      await afterRender(previousRender, 'fullscreen relayout');
      wholePage('fullscreen relayout', 600, 1100);

      previousRender = rendered;
      button('actual-size').click();
      await afterRender(previousRender, 'actual size');
      check(active('actual-size'), 'Actual size button is not marked active');
      check(Math.abs(canvas().getBoundingClientRect().width - 600 * 96 / 72) < 2,
          'Actual-size PDF scaling is incorrect');
      previousRender = rendered;
      button('fit-page').click();
      await afterRender(previousRender, 'return to whole-page fit');
      wholePage('return from actual size', 600, 1100);
      document.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true}));
      await new Promise(resolve => setTimeout(resolve, 80));
      check(counter() === 'Page 3 of 3' && photoShortcutEvents === 0, 'Document shortcut leaked to photo handler');

      button('previous').click();
      await waitUntil(() => counter() === 'Page 2 of 3' && settled(), 'previous page');
      wholePage('previous landscape page', 842, 595);
      document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
      await waitUntil(() => !document.querySelector('dialog'), 'document close');
      check(destroys >= 1 && rendered >= 4, 'PDF resources were not rendered or destroyed');
      unchangedPhoto();

      document.querySelector('[data-simbrief-ofp-open]').click();
      await waitUntil(() => counter() === 'Page 1 of 3', 'reopen document');
      button('close').click();
      await waitUntil(() => !document.querySelector('dialog'), 'close control');
      check(documentRequests === 2, 'Document reopening did not release previous instance');
      for (const [lang, label, actual] of [
          ['cs', 'Zobrazit celou stránku', 'Skutečná velikost (100 %)'],
          ['de', 'Ganze Seite anpassen', 'Originalgröße (100 %)'],
          ['sv', 'Anpassa hela sidan', 'Faktisk storlek (100 %)'],
      ]) {
          document.documentElement.lang = lang;
          document.querySelector('[data-simbrief-ofp-open]').click();
          await waitUntil(() => document.querySelector('dialog')?.open && settled()
              && active('fit-page'), 'localized dialog ' + lang);
          check(button('fit-page').textContent === label, 'Untranslated fit control: ' + lang);
          check(button('actual-size').textContent === actual, 'Untranslated actual size: ' + lang);
          button('close').click();
          await waitUntil(() => !document.querySelector('dialog'), 'localized close ' + lang);
          unchangedPhoto();
      }
      check(documentRequests === 5, 'Localized reopening created duplicate viewers');
      results.textContent = 'BROWSER PASS SimBrief OFP full-page geometry, modes, resize, fullscreen and isolation';
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
