/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_floating_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Prove viewport clamping, safe anchors and bounded collision fallback.
 * Responsibilities:
 *   - Exercise the real public browser positioning model without a browser.
 *   - Refuse malformed and physically unreachable floating placements.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../public/assets/gallery-modules/public-content-widgets.js', import.meta.url), 'utf8');
const browserModule = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const {resolvePublicWidgetFloatingRect} = browserModule;

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
