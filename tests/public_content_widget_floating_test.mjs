/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_floating_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Prove shared public viewport policy, safe anchors and bounded collision fallback.
 * Responsibilities:
 *   - Exercise the real public and Admin-shared geometry model without a browser.
 *   - Refuse malformed and physically unreachable floating placements.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../public/assets/gallery-modules/public-content-widgets.js', import.meta.url), 'utf8');
const browserModule = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const {FLOAT_LIMIT, publicWidgetBoundedNumber, readPublicWidgetFloatingGeometry, resolvePublicWidgetFloatingRect} = browserModule;

const header = {
    hidden: false,
    closest: () => null,
    getBoundingClientRect: () => ({left: 0, top: 0, right: 1280, bottom: 52, width: 1280, height: 52}),
};
const publicDocument = {
    documentElement: {clientWidth: 1280},
    querySelector: selector => selector === '.site-header' ? header : null,
    querySelectorAll: () => [header],
};
const desktopGeometry = readPublicWidgetFloatingGeometry(publicDocument, {innerHeight: 800, visualViewport: null});
assert.equal(desktopGeometry.supported, true, 'Desktop Theme geometry supports a floating preview');
assert.equal(desktopGeometry.topInset, 64, 'The real header bottom protects the floating panel');
assert.deepEqual(desktopGeometry.exclusions, [{left: 0, top: 0, width: 1280, height: 52}]);
assert.equal(readPublicWidgetFloatingGeometry({
    ...publicDocument, documentElement: {clientWidth: 390},
}, {innerHeight: 844, visualViewport: null}).supported, false, 'Mobile retains its in-flow fallback');
assert.equal(readPublicWidgetFloatingGeometry(publicDocument, {
    innerHeight: 800, visualViewport: {height: 800, scale: 2},
}).supported, false, 'Zoomed viewports retain their in-flow fallback');
assert.equal(publicWidgetBoundedNumber('320', 300, 180, 480), 320);
assert.equal(publicWidgetBoundedNumber('481', 300, 180, 480), 300, 'Malformed placement data uses the safe default');
assert.equal(FLOAT_LIMIT, 2, 'Admin preview and public runtime share the same floating panel cap');

const defaults = {
    anchor: 'bottom-right', x: 900, y: 900,
    width: 300, height: 160, viewportWidth: 1280,
    viewportHeight: 800, topInset: 130, exclusions: [],
};
const right = resolvePublicWidgetFloatingRect(defaults);
assert.deepEqual(right, {left: 964, top: 624, width: 300, height: 160});

const left = resolvePublicWidgetFloatingRect({...defaults, anchor: 'top-left'});
assert.deepEqual(left, {left: 16, top: 130, width: 300, height: 160});
const custom = resolvePublicWidgetFloatingRect({...defaults, anchor: 'custom', x: 500, y: 500});
assert.deepEqual(custom, {left: 490, top: 377, width: 300, height: 160});

const collision = resolvePublicWidgetFloatingRect({...defaults, exclusions: [right]});
assert.ok(collision && collision.top < right.top, 'Identical anchors stack upward without overlapping');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, exclusions: [
    {left: 0, top: 0, width: 1280, height: 800},
]}), null, 'Occupied viewport must retain in-flow fallback');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, anchor: 'middle-center'}), null, 'Unsupported blocking anchor');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, x: -1, anchor: 'custom'}), null, 'Invalid saved coordinates');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, y: 1001, anchor: 'custom'}), null, 'Out-of-range coordinates');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, width: 640}), null, 'Panel cannot consume the viewport');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, height: 380}), null, 'Oversized panels fall back to flow');
assert.equal(resolvePublicWidgetFloatingRect({...defaults, topInset: 700}), null, 'Header cannot be covered');
console.log('public_content_widget_floating_test: PASS');
