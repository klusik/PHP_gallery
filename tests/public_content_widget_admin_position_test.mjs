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
const sharedModuleSource = geometrySource.replace(/^export /gm, '') + '\n' + adminSource
    + '\nexport {publicWidgetRectConflicts};';
const module = await import('data:text/javascript;base64,' + Buffer.from(sharedModuleSource).toString('base64'));
const {publicWidgetPointerPosition, publicWidgetRectConflicts, publicWidgetPlacementWarnings, publicWidgetProtectedHomeUrl, publicWidgetProtectedPageUrl} = module;
const stage = {left: 20, top: 30, width: 640, height: 450};
const panel = {width: 320, height: 120};
const geometry = {
    supported: true, viewportWidth: 1280, viewportHeight: 900, topInset: 100, availableWidth: 1188,
    safeAreaInsets: {top: 20, right: 20, bottom: 30, left: 40}, exclusions: [],
};
assert.deepEqual(publicWidgetPointerPosition(265, 238.5, stage, geometry, panel), {x: 500, y: 500},
    'Pointer inversion uses the same inset-adjusted available rect as public placement.');
assert.deepEqual(publicWidgetPointerPosition(48, 80, stage, geometry, panel), {x: 0, y: 0},
    'The displayed safe top-left position maps back to solver origin.');
assert.deepEqual(publicWidgetPointerPosition(482, 397, stage, geometry, panel), {x: 1000, y: 1000},
    'The displayed safe bottom-right position maps back to solver maximum.');
assert.deepEqual(publicWidgetPointerPosition(-250, -250, stage, geometry, panel), {x: 0, y: 0});
assert.deepEqual(publicWidgetPointerPosition(10000, 10000, stage, geometry, panel), {x: 1000, y: 1000});
assert.equal(publicWidgetPointerPosition(285, 238.5, {...stage, width: 0}, geometry, panel), null);
assert.equal(publicWidgetPointerPosition(Infinity, 80, stage, geometry, panel), null);
assert.equal(publicWidgetPointerPosition(285, 238.5, stage, {...geometry, supported: false}, panel), null,
    'An unsupported public viewport does not accept misleading map coordinates.');
assert.equal(publicWidgetRectConflicts({left: 0, top: 0, width: 100, height: 100},
    {left: 111, top: 0, width: 100, height: 100}), true, 'The shared solver rule rejects gaps below 12px.');
assert.equal(publicWidgetRectConflicts({left: 0, top: 0, width: 100, height: 100},
    {left: 112, top: 0, width: 100, height: 100}), false, 'The shared solver rule accepts a 12px gap.');

for (const eventName of ['pointerdown', 'pointermove', 'pointerup', 'pointercancel', 'keydown']) {
    assert.ok(source.includes("stage.addEventListener('" + eventName + "'"), 'Accessible pointer stage event: ' + eventName);
}
assert.ok(source.includes("stage.setPointerCapture?.(event.pointerId)"), 'Pointer capture keeps drag inside the editor');
assert.ok(source.includes("event.shiftKey ? 50 : 10"), 'Keyboard fine/coarse precision');
assert.ok(source.includes("reset.type = 'button'"), 'Reset is nonpublishing');
assert.ok(source.includes("anchor.value = 'bottom-right'"), 'Reset restores a safe supported anchor');
assert.ok(source.includes("x.value = '900'") && source.includes("y.value = '900'"), 'Reset bounds saved coordinates');
const draft = {mode: 'floating', anchor: 'bottom-right', x: 900, y: 900, device: 'desktop', page: 'home'};
const desiredRect = {left: 800, top: 700, width: 320, height: 120};
const finalDraftRect = {left: 800, top: 700, width: 320, height: 120};
const otherRect = {left: 800, top: 700, width: 280, height: 120};
const layout = {
    page: 'home', device: 'desktop', geometry,
    headerRect: {left: 0, top: 0, width: 1280, height: 80},
    draftRequest: {width: 320, height: 120, desiredRect},
    placements: [
        {id: 'selected', draft: true, rect: finalDraftRect},
        {id: 'another', draft: false, rect: otherRect},
    ],
    draftPositioned: true,
};
assert.deepEqual(publicWidgetPlacementWarnings(draft, layout), ['collision'], 'Measured peer rectangles trigger overlap warnings.');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, page: 'gallery'}, {...layout, page: 'home'}),
    ['fallback'], 'A stale page layout cannot drive warnings on another page.');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, mode: 'flow'}, layout), [], 'In-flow placement does not warn about floating.');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, device: 'tablet'}, {...layout, device: 'tablet'}),
    ['fallback'], 'Tablet keeps the existing in-page fallback.');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, device: 'mobile'}, {...layout, device: 'mobile'}),
    ['fallback'], 'Mobile retains the in-page fallback.');
const headerLayout = {...layout, draftRequest: {width: 320, height: 120, desiredRect: {...desiredRect, top: 40}},
    placements: [{id: 'selected', draft: true, rect: finalDraftRect}]};
assert.deepEqual(publicWidgetPlacementWarnings(draft, headerLayout), ['header'],
    'The requested rectangle warns when it overlaps the measured public header.');
const nearPeerLayout = {...layout, draftRequest: {width: 320, height: 120,
    desiredRect: {left: 500, top: 700, width: 320, height: 120}},
    placements: [
        {id: 'selected', draft: true, rect: {left: 500, top: 500, width: 320, height: 120}},
        {id: 'another', draft: false, rect: {left: 828, top: 700, width: 280, height: 120}},
    ]};
assert.deepEqual(publicWidgetPlacementWarnings(draft, nearPeerLayout), ['collision'],
    'A peer inside the production 12px clearance triggers a collision warning.');
const nearControlLayout = {...layout, geometry: {...geometry, exclusions: [
    {left: 1128, top: 700, width: 32, height: 40},
]}, placements: [{id: 'selected', draft: true, rect: finalDraftRect}], draftRequest: {width: 320, height: 120,
    desiredRect: {left: 800, top: 700, width: 320, height: 120}}};
assert.deepEqual(publicWidgetPlacementWarnings(draft, nearControlLayout), ['controls'],
    'A measured non-header public control inside the solver gap triggers its own warning.');
const edgeLayout = {...layout, draftRequest: {width: 320, height: 120, desiredRect: {left: 56, top: 100, ...panel}},
    placements: [{id: 'selected', draft: true, rect: finalDraftRect}]};
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'custom'}, edgeLayout), ['edge'],
    'A custom rectangle at the measured inset boundary warns.');
assert.deepEqual(publicWidgetPlacementWarnings({...draft, anchor: 'custom', x: NaN}, layout),
    ['invalid', 'collision'], 'Malformed input remains visible alongside measured rectangle warnings.');
const limitLayout = {...layout, placements: [
    {id: 'first', draft: false, rect: {left: 100, top: 300, width: 280, height: 120}},
    {id: 'second', draft: false, rect: {left: 900, top: 300, width: 280, height: 120}},
], draftPositioned: false};
assert.deepEqual(publicWidgetPlacementWarnings(draft, limitLayout), ['limit', 'fallback'],
    'Two already-positioned peer rectangles expose the production panel cap and flow fallback.');
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
