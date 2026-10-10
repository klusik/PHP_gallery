/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_position_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Verify bounded Admin-only pointer positioning and nonpublishing reset controls.
 * Responsibilities:
 *   - Test the pure production pointer mapping for mouse, pen and touch coordinates.
 *   - Preserve native, explicit form publication and keyboard positioning alternatives.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../public/assets/gallery-modules/admin-public-widgets.js', import.meta.url), 'utf8');
const module = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const {publicWidgetPointerPosition} = module;
const rect = {left: 20, top: 30, width: 200, height: 100};

assert.deepEqual(publicWidgetPointerPosition(120, 80, rect), {x: 500, y: 500});
assert.deepEqual(publicWidgetPointerPosition(20, 30, rect), {x: 0, y: 0});
assert.deepEqual(publicWidgetPointerPosition(220, 130, rect), {x: 1000, y: 1000});
assert.deepEqual(publicWidgetPointerPosition(-250, -250, rect), {x: 0, y: 0});
assert.deepEqual(publicWidgetPointerPosition(10000, 10000, rect), {x: 1000, y: 1000});
assert.equal(publicWidgetPointerPosition(120, 80, {...rect, width: 0}), null);
assert.equal(publicWidgetPointerPosition(Infinity, 80, rect), null);

for (const eventName of ['pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'keydown']) {
    assert.ok(source.includes("stage.addEventListener('" + eventName + "'"), 'Accessible pointer stage event: ' + eventName);
}
assert.ok(source.includes("stage.setPointerCapture?.(event.pointerId)"), 'Pointer capture keeps drag inside the editor');
assert.ok(source.includes("event.shiftKey ? 50 : 10"), 'Keyboard fine/coarse precision');
assert.ok(source.includes("reset.type = 'button'"), 'Reset is nonpublishing');
assert.ok(source.includes("anchor.value = 'bottom-right'"), 'Reset restores a safe supported anchor');
assert.ok(source.includes("x.value = '900'") && source.includes("y.value = '900'"), 'Reset bounds saved coordinates');
console.log('public_content_widget_admin_position_test: PASS');
