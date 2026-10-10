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

const geometrySource = readFileSync(new URL('../public/assets/gallery-modules/public-content-widgets.js', import.meta.url), 'utf8');
const source = readFileSync(new URL('../public/assets/gallery-modules/admin-public-widgets.js', import.meta.url), 'utf8');
const adminSource = source.replace(/^import \{[\s\S]*?\} from '\.\/public-content-widgets\.js\?[^']+';\r?\n/m, '');
assert.notEqual(adminSource, source, 'Admin preview imports the shared public geometry implementation');
const sharedModuleSource = geometrySource.replace(/^export /gm, '') + '\n' + adminSource;
const module = await import('data:text/javascript;base64,' + Buffer.from(sharedModuleSource).toString('base64'));
const {publicWidgetPointerPosition, publicWidgetPlacementWarnings, publicWidgetProtectedHomeUrl, publicWidgetProtectedPageUrl} = module;
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
const draft = {mode: 'floating', anchor: 'bottom-right', x: 900, y: 900, device: 'desktop', page: 'home', widgetId: 'selected'};
const other = {id: 'another', scope: 'home', anchor: 'bottom-right', x: 900, y: 900, width: 320};
assert.deepEqual(publicWidgetPlacementWarnings(draft, [other]), ['collision'], 'Same-page competing anchor warns');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, page: 'gallery'}, [other]), [], 'Other gallery scopes cannot trigger collisions');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, widgetId: 'another'}, [other]), [], 'Own saved record is not a competitor');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, mode: 'flow'}, [other]), [], 'In-flow placement does not warn about floating');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, device: 'tablet'}, [other]), ['fallback'], 'Tablet uses existing below-960px fallback');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, device: 'mobile'}, [other]), ['fallback'], 'Mobile retains the initial in-flow article');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'top-left'}, []), ['header'], 'Top preset warns about protected header');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'custom', x: 10, y: 10}, []), ['header', 'edge'], 'Custom edge coordinates warn');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'custom', x: NaN}, []), ['invalid'], 'Malformed unsaved numeric field warns');
assert.deepEqual(publicWidgetPlacementWarnings(draft, [other, {...other, id: 'second', scope: 'all', anchor: 'top-left'}]),
    ['limit', 'collision'], 'Overlay limit and likely collision are separate risks');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'custom', x: 850, y: 850}, [other]),
    ['collision'], 'Nearby custom coordinates warn');
const base = 'https://gallery.example.test/galerie/index.php?page=admin_theme';
assert.equal(publicWidgetProtectedHomeUrl('/galerie/index.php?page=home&preview=visual&view_as=anonymous', base)?.pathname,
    '/galerie/index.php', 'Mounted query-routing preview is accepted');
assert.equal(publicWidgetProtectedHomeUrl('/galerie/?preview=visual&view_as=anonymous', base)?.pathname,
    '/galerie/', 'Clean routed homepage preview is accepted');
assert.equal(publicWidgetProtectedPageUrl('/galerie/index.php?page=gallery&public_path=preview-gallery&preview=visual&view_as=anonymous', base, 'gallery')?.pathname,
    '/galerie/index.php', 'Mounted query-routing Gallery preview is accepted');
assert.equal(publicWidgetProtectedPageUrl('/galerie/gallery/preview-gallery/?preview=visual&view_as=anonymous', base, 'gallery')?.pathname,
    '/galerie/gallery/preview-gallery/', 'Mounted clean Gallery preview is accepted');
for (const candidate of [
    '/galerie/index.php?page=home',
    '/galerie/?preview=visual',
    '/galerie/index.php?page=gallery&preview=visual',
    '/galerie/index.php?page=home&preview=visual&preview=visual',
    '/galerie/index.php?page=home&preview=visual&widget_action=delete',
    '/galerie/index.php?page=home&preview=visual&view_as=admin',
    'https://external.example.test/galerie/index.php?page=home&preview=visual',
    '/galerie/index.php?page=home&preview=visual#fragment',
    '/galerie/api/widgets?preview=visual',
]) {
    assert.equal(publicWidgetProtectedHomeUrl(candidate, base), null, 'Unsafe preview URL refused: ' + candidate);
}
for (const candidate of [
    '/galerie/index.php?page=home&preview=visual&view_as=anonymous',
    '/galerie/index.php?page=gallery&preview=visual&view_as=anonymous',
    '/galerie/index.php?page=gallery&public_path=&preview=visual&view_as=anonymous',
    '/galerie/index.php?page=gallery&public_path=../admin&preview=visual&view_as=anonymous',
    'https://external.example.test/galerie/gallery/example/?preview=visual&view_as=anonymous',
]) {
    assert.equal(publicWidgetProtectedPageUrl(candidate, base, 'gallery'), null,
        'Unsafe Gallery preview URL refused: ' + candidate);
}
assert.ok(source.includes("frame.setAttribute('sandbox', 'allow-same-origin')"), 'Iframe denies public scripts and form submission');
assert.ok(source.includes("doc.addEventListener('click', event => {") && source.includes("event.preventDefault();"),
    'Parent intercepts navigation and dismissals in the sandboxed frame');
console.log('public_content_widget_admin_position_test: PASS');
