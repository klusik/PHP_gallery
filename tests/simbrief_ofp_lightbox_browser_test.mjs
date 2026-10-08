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
<main class="public-page"><div class="gallery-description-rich">
<div class="simbrief-ofp-actions">
<div class="simbrief-ofp-primary-actions">
<a class="button simbrief-ofp-open" data-simbrief-ofp-open href="/index.php?page=gallery_ofp_pdf&amp;id=42">View flight plan</a>
<a class="button secondary" download="simbrief-ofp.pdf" href="/index.php?page=gallery_ofp_pdf&amp;id=42&amp;download=1">Download PDF</a>
</div>
<div class="simbrief-ofp-secondary-actions">
<form class="simbrief-ofp-convert-form" data-ofp-convert-form>
<button type="submit" class="button secondary">Create private OFP subgallery</button>
<span class="simbrief-ofp-info"><button type="button" class="simbrief-ofp-info-toggle"
 data-ofp-info-toggle aria-expanded="false" aria-controls="fixture-ofp-help" aria-label="About OFP conversion">?</button>
<span class="simbrief-ofp-info-text" id="fixture-ofp-help" role="tooltip">Convert PDF pages into a private subgallery.</span></span>
</form></div></div></div></main>
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
          if (new URL(options.url, location.href).searchParams.get('page') !== 'gallery_ofp_pdf'
               || new URL(options.url, location.href).searchParams.get('id') !== '42'
               || options.isEvalSupported !== false) {
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
  const press = (name, target = stage(), options = {}) => {
      const event = new KeyboardEvent('keydown', {key: name, bubbles: true, cancelable: true, ...options});
      target.dispatchEvent(event);
      return event;
  };
  const wheel = (x, y, dy, options = {}) => {
      const event = new WheelEvent('wheel', {clientX: x, clientY: y, deltaY: dy,
          bubbles: true, cancelable: true, ...options});
      return {event, unhandled: stage().dispatchEvent(event)};
  };
  const point = (x, y) => {
      const rect = canvas().getBoundingClientRect();
      return [(x - rect.left) / rect.width, (y - rect.top) / rect.height];
  };
  const anchored = (before, x, y) => check(before.every((v, i) => Math.abs(v - point(x, y)[i]) < 0.03),
      'Mouse-wheel zoom did not retain the PDF point under the cursor');
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
      const toolbar = document.querySelector('.simbrief-ofp-toolbar').getBoundingClientRect();
      if (document.querySelector('dialog').classList.contains('is-ofp-fullscreen')) {
          const header = document.querySelector('.simbrief-ofp-header').getBoundingClientRect();
          check(page.top >= header.bottom - 2 && page.bottom <= toolbar.top + 2,
              reason + ': fullscreen HUD obscures the page');
      } else {
          check(viewport.bottom <= toolbar.top + 1, reason + ': toolbar overlaps PDF stage');
      }
  };

  try {
      setupSimbriefOfpViewer();
      setupSimbriefOfpViewer();
      const attachmentGroup = document.querySelector('.simbrief-ofp-actions');
      const firstRow = document.querySelector('.simbrief-ofp-primary-actions');
      const secondRow = document.querySelector('.simbrief-ofp-secondary-actions');
      const ofpActions = [
          document.querySelector('[data-simbrief-ofp-open]'),
          attachmentGroup.querySelector('a[download]'),
          attachmentGroup.querySelector('button[type="submit"]'),
          attachmentGroup.querySelector('[data-ofp-info-toggle]'),
      ];
      for (const width of [320, 375, 768]) {
          attachmentGroup.style.width = width + 'px';
          const group = attachmentGroup.getBoundingClientRect();
          for (const control of ofpActions) {
              const bounds = control.getBoundingClientRect();
              check(bounds.left >= group.left - 2 && bounds.right <= group.right + 2,
                  'OFP attachment action overflows ' + width + 'px group: ' + control.textContent);
          }
          check(secondRow.getBoundingClientRect().top >= firstRow.getBoundingClientRect().bottom - 2,
              'Private subgallery action was not separated from public attachments at ' + width + 'px');
      }
      attachmentGroup.style.removeProperty('width');
      const help = attachmentGroup.querySelector('[data-ofp-info-toggle]');
      const helpText = document.getElementById('fixture-ofp-help');
      check(help.getAttribute('aria-label')?.length > 0
          && getComputedStyle(helpText).display === 'none', 'OFP help is not initially accessible and collapsed');
      help.click();
      check(help.getAttribute('aria-expanded') === 'true' && getComputedStyle(helpText).display !== 'none',
          'Touch/primary-click OFP help did not expand');
      help.click();
      check(help.getAttribute('aria-expanded') === 'false' && getComputedStyle(helpText).display === 'none',
          'Second tap did not close the OFP tooltip');
      help.click();
      document.getElementById('photo-sequence').click();
      check(help.getAttribute('aria-expanded') === 'false', 'Outside click failed to dismiss OFP help');
      help.focus();
      check(getComputedStyle(helpText).display !== 'none', 'Keyboard focus failed to reveal OFP help');
      help.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
      check(help.getAttribute('aria-expanded') === 'false' && getComputedStyle(helpText).display === 'none',
          'Escape failed to dismiss focused OFP help');
      document.querySelector('[data-simbrief-ofp-open]').click();
      await waitUntil(() => counter() === 'Page 1 of 3' && document.querySelector('dialog')?.open, 'first PDF page');
      check(documentRequests === 1, 'Click handler was registered twice');
      // A deliberately hostile gallery theme cannot contaminate the PDF HUD.
      document.documentElement.style.setProperty('--accent', '#030712');
      document.documentElement.style.setProperty('--accent-dark', '#030712');
      const toRgb = (value) => (value.match(/\d+(?:\.\d+)?/g) || []).slice(0, 3).map(Number);
      const luminance = (value) => {
          const components = toRgb(value).map((channel) => {
              const v = channel / 255;
              return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * components[0] + 0.7152 * components[1] + 0.0722 * components[2];
      };
      const contrast = (foreground, background) => {
          const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
          return (values[0] + 0.05) / (values[1] + 0.05);
      };
      for (const name of ['close', 'fullscreen', 'previous', 'next', 'fit-width', 'fit-page', 'actual-size', 'zoom-in']) {
          const control = getComputedStyle(button(name));
          check(contrast(control.color, control.backgroundColor) >= 4.5,
              'OFP toolbar contrast below 4.5:1: ' + name + ' (' + control.color
              + ' on ' + control.backgroundColor + ')');
      }
      const level = getComputedStyle(document.querySelector('[data-ofp-zoom]'));
      check(contrast(level.color, 'rgb(33, 44, 61)') >= 4.5,
          'Zoom percentage inherited unreadable theme color');
      const selected = getComputedStyle(button('fit-page'));
      check(selected.backgroundColor === 'rgb(29, 78, 216)' && selected.color === 'rgb(255, 255, 255)',
          'Selected PDF mode must use a viewer-owned blue-and-white palette');
      check(button('previous').disabled, 'First page previous button must be disabled');
      check(new URL(document.querySelector('[data-ofp-download]').href).searchParams.get('download') === '1',
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

      // Synthetic TouchEvents cover deterministic mobile pan -> pinch -> pan
      // handoff; physical iOS/Android testing remains a separate requirement.
      check(getComputedStyle(stage()).touchAction === 'none', 'PDF stage touch actions leaked to browser');
      const touch = (id, x, y) => new Touch({
          identifier: id, target: stage(), clientX: x, clientY: y,
          pageX: x, pageY: y, radiusX: 1, radiusY: 1, force: 1,
      });
      const touchEvent = (type, current, changed = current) => {
          const event = new TouchEvent(type, {
              bubbles: true, cancelable: true, touches: current,
              targetTouches: current, changedTouches: changed,
          });
          stage().dispatchEvent(event);
          return event;
      };
      const touchRect = stage().getBoundingClientRect();
      const cx = touchRect.left + stage().clientWidth / 2;
      const cy = touchRect.top + stage().clientHeight / 2;
      stage().scrollTop = 0;
      touchEvent('touchstart', [touch(11, cx, cy + 20)]);
      const scrollTouch = touchEvent('touchmove', [touch(11, cx, cy - 70)]);
      check(scrollTouch.defaultPrevented && stage().scrollTop > 0,
          'One-finger drag failed to scroll the fit-width PDF');
      touchEvent('touchend', [], [touch(11, cx, cy - 70)]);
      const beforePinch = canvas().getBoundingClientRect().width;
      const fingerA = touch(21, cx - 28, cy), fingerB = touch(22, cx + 28, cy);
      touchEvent('touchstart', [fingerA, fingerB]);
      const fingerMovedA = touch(21, cx - 72, cy);
      const fingerMovedB = touch(22, cx + 72, cy);
      const pinchMove = touchEvent('touchmove', [fingerMovedA, fingerMovedB]);
      check(pinchMove.defaultPrevented && canvas().getBoundingClientRect().width > beforePinch * 1.2,
          'Two-finger pinch did not zoom the PDF content');
      touchEvent('touchend', [fingerMovedA], [fingerMovedB]);
      stage().scrollTop = 0;
      const handoff = touchEvent('touchmove', [touch(21, cx - 72, cy - 45)]);
      check(handoff.defaultPrevented && stage().scrollTop > 0,
          'Remaining pinch finger did not take over vertical PDF scrolling');
      previousRender = rendered;
      touchEvent('touchend', [], [touch(21, cx - 72, cy - 45)]);
      await afterRender(previousRender, 'mobile pinch-to-pan rasterization');
      previousRender = rendered;
      button('fit-width').click();
      await afterRender(previousRender, 'restore fit-width after mobile pinch');
      check(active('fit-width'), 'Mobile pinch reset lost requested fit width');

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

      // Native fullscreen is forbidden on <dialog> by the Fullscreen API.
      // Verify that the same PDF + controls have a fullscreenable inner target.
      const fullRoot = dialog.querySelector('[data-ofp-root]');
      check(fullRoot instanceof HTMLElement && fullRoot.tagName !== 'DIALOG',
          'Native fullscreen must target the document-and-HUD inner root');
      // Emulate native Fullscreen API state (synthetic clicks lack user activation).
      let fullscreenOwner = null;
      const oldExit = document.exitFullscreen;
      Object.defineProperty(document, 'fullscreenElement', {configurable: true,
          get: () => fullscreenOwner});
      fullRoot.requestFullscreen = async () => {
          fullscreenOwner = fullRoot;
          document.dispatchEvent(new Event('fullscreenchange'));
      };
      document.exitFullscreen = async () => {
          fullscreenOwner = null;
          document.dispatchEvent(new Event('fullscreenchange'));
      };
      const input = document.createElement('input');
      dialog.querySelector('.simbrief-ofp-header').append(input);
      check(!press('f', input).defaultPrevented && !fullscreenOwner,
          'Editable input F toggled fullscreen');
      input.remove();
      for (const modifier of [{ctrlKey: true}, {metaKey: true}, {altKey: true}, {repeat: true}]) {
          press('f', stage(), modifier);
          check(!fullscreenOwner, 'Modified/repeated F toggled fullscreen');
      }
      previousRender = rendered;
      check(press('F', stage(), {shiftKey: true}).defaultPrevented, 'F was not handled');
      await waitUntil(() => fullscreenOwner === fullRoot && dialog.classList.contains('is-ofp-fullscreen'),
          'F entered fullscreen');
      await afterRender(previousRender, 'native fullscreen fit');
      wholePage('native fullscreen whole page', 600, 1100);
      check(button('fullscreen').getAttribute('aria-keyshortcuts') === 'F'
          && button('fullscreen').getAttribute('aria-pressed') === 'true',
          'Fullscreen button shortcut/state is not synchronized');

      const pageBox = canvas().getBoundingClientRect();
      const px = pageBox.left + pageBox.width * 0.70;
      const py = pageBox.top + pageBox.height * 0.36;
      const anchor = point(px, py);
      const chromeFont = getComputedStyle(button('close')).fontSize;
      const chromeWidth = document.querySelector('.simbrief-ofp-toolbar').getBoundingClientRect().width;
      previousRender = rendered;
      const zoomEvent = wheel(px, py, -110, {ctrlKey: true});
      check(!zoomEvent.unhandled && zoomEvent.event.defaultPrevented, 'Ctrl+wheel did not zoom PDF');
      await afterRender(previousRender, 'fullscreen content-only Ctrl+wheel zoom');
      check(canvas().getBoundingClientRect().width > pageBox.width, 'PDF did not grow on Ctrl+wheel');
      anchored(anchor, px, py);
      check(getComputedStyle(button('close')).fontSize === chromeFont
          && Math.abs(document.querySelector('.simbrief-ofp-toolbar').getBoundingClientRect().width
              - chromeWidth) < 2, 'Mouse wheel scaled fullscreen controls');
      stage().dispatchEvent(new PointerEvent('pointermove', {bubbles: true}));
      const hudRenders = rendered, hudWidth = canvas().getBoundingClientRect().width;
      const stageHeight = stage().clientHeight;
      await pause(2950);
      check(dialog.classList.contains('is-ofp-hud-hidden'), 'Fullscreen HUD failed to hide');
      check(rendered === hudRenders && canvas().getBoundingClientRect().width === hudWidth
          && stage().clientHeight === stageHeight, 'HUD changed PDF viewport or rerendered it');
      stage().dispatchEvent(new PointerEvent('pointermove', {bubbles: true}));
      check(!dialog.classList.contains('is-ofp-hud-hidden'), 'Mouse did not reveal HUD');
      const closeBox = button('close').getBoundingClientRect();
      const closeHit = document.elementFromPoint(
          closeBox.left + closeBox.width / 2, closeBox.top + closeBox.height / 2
      );
      check(closeHit === button('close') || button('close').contains(closeHit),
          'Fullscreen PDF intercepts close button: ' + JSON.stringify({
              hit: closeHit?.outerHTML?.slice(0, 180) ?? null,
              button: button('close').outerHTML.slice(0, 180),
              rect: closeBox.toJSON(),
              header: document.querySelector('.simbrief-ofp-header').getBoundingClientRect().toJSON(),
              stage: stage().getBoundingClientRect().toJSON(),
              visibility: getComputedStyle(button('close')).visibility,
              pointerEvents: getComputedStyle(button('close')).pointerEvents,
              hudHidden: dialog.classList.contains('is-ofp-hud-hidden'),
          }));
      press('Escape');
      await waitUntil(() => !fullscreenOwner && !dialog.classList.contains('is-ofp-fullscreen'),
          'Escape exits native fullscreen');
      check(dialog.open, 'Escape closed OFP while fullscreen');
      press('f');
      await waitUntil(() => fullscreenOwner === fullRoot, 'F reenters native fullscreen');
      press('f');
      await waitUntil(() => !fullscreenOwner, 'F exits native fullscreen');
      fullRoot.requestFullscreen = async () => { throw new Error('Denied'); };
      press('f');
      await waitUntil(() => dialog.classList.contains('is-ofp-fullscreen'),
          'native refusal uses CSS fallback');
      check(!fullscreenOwner && button('fullscreen').getAttribute('aria-pressed') === 'true',
          'CSS fullscreen fallback has stale state');
      press('Escape');
      await waitUntil(() => !dialog.classList.contains('is-ofp-fullscreen'),
          'Escape exits CSS fullscreen');
      check(dialog.open, 'CSS fullscreen Escape closed document');
      fullRoot.requestFullscreen = async () => {
          fullscreenOwner = fullRoot;
          document.dispatchEvent(new Event('fullscreenchange'));
      };
      press('f');
      await waitUntil(() => fullscreenOwner === fullRoot, 'native fullscreen before browser exit');
      fullscreenOwner = null;
      document.dispatchEvent(new Event('fullscreenchange'));
      check(!dialog.classList.contains('is-ofp-fullscreen') && dialog.open,
          'Browser fullscreen exit did not restore document');
      delete document.fullscreenElement;
      document.exitFullscreen = oldExit;

      // Native scrolling is never reinterpreted as mouse-wheel zoom.
      previousRender = rendered;
      button('fit-page').click();
      await afterRender(previousRender, 'fit after fullscreen');
      const page = canvas().getBoundingClientRect();
      const wx = page.left + page.width * 0.68, wy = page.top + page.height * 0.33;
      const originalPoint = point(wx, wy);
      previousRender = rendered;
      const plain = wheel(wx, wy, -115);
      check(plain.unhandled && !plain.event.defaultPrevented,
          'Unmodified mouse wheel must remain native stage scroll');
      const horizontal = wheel(wx, wy, 20, {deltaX: 65, deltaMode: 0});
      check(horizontal.unhandled && !horizontal.event.defaultPrevented,
          'Horizontal trackpad scroll must remain native');
      await pause(170);
      check(rendered === previousRender && active('fit-page') && zoom() === 'Zoom 100%',
          'Unmodified wheel unexpectedly changed the PDF fit/scale');
      previousRender = rendered;
      const controlZoom = wheel(wx, wy, -115, {ctrlKey: true});
      check(!controlZoom.unhandled && controlZoom.event.defaultPrevented, 'Ctrl+wheel was not captured');
      await afterRender(previousRender, 'normal Ctrl+wheel PDF zoom');
      anchored(originalPoint, wx, wy);
      check(!active('fit-page'), 'Ctrl+wheel did not select manual zoom');
      await pause(210);
      previousRender = rendered;
      wheel(wx, wy, -90, {ctrlKey: true});
      await afterRender(previousRender, 'repeated cursor Ctrl+wheel zoom');
      anchored(originalPoint, wx, wy);
      await pause(210);
      const trackpadZoom = zoom(), trackpadRenders = rendered;
      const fine = wheel(wx, wy, 12, {deltaMode: 0});
      check(fine.unhandled && !fine.event.defaultPrevented, 'Trackpad scroll trapped');
      await pause(165);
      check(zoom() === trackpadZoom && rendered === trackpadRenders,
          'Fine trackpad movement changed zoom');
      previousRender = rendered;
      const ctrl = wheel(wx, wy, -35, {ctrlKey: true});
      check(!ctrl.unhandled && ctrl.event.defaultPrevented, 'Ctrl+wheel did not zoom PDF');
      await afterRender(previousRender, 'modifier wheel');
      const command = wheel(wx, wy, -30, {metaKey: true});
      check(command.unhandled && !command.event.defaultPrevented,
          'Command+wheel must remain a browser shortcut');
      const next = button('next').getBoundingClientRect();
      check(document.elementFromPoint(next.left + next.width / 2,
          next.top + next.height / 2) === button('next'),
          'PDF intercepts normal toolbar');
      previousRender = rendered;
      button('fit-page').click();
      await afterRender(previousRender, 'return to whole page');
      wholePage('after wheel to fit', 600, 1100);

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
