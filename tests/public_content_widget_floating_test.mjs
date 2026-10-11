/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_floating_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Prove shared public viewport policy, safe-area bounds and bounded collision fallback.
 * Responsibilities:
 *   - Exercise the real public and Admin-shared geometry model without a browser.
 *   - Refuse malformed and physically unreachable floating placements.
 *   - Verify per-document CSS safe-area probes are cached while computed insets stay fresh.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../public/assets/gallery-modules/public-content-widgets.js', import.meta.url), 'utf8');
const browserModule = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const {
    FLOAT_LIMIT, FLOAT_MIN_PANEL_WIDTH, publicWidgetBoundedNumber,
    readPublicWidgetFloatingGeometry, resolvePublicWidgetFloatingRect,
} = browserModule;

const header = {
    hidden: false,
    closest: () => null,
    getBoundingClientRect: () => ({left: 0, top: 0, right: 1280, bottom: 52, width: 1280, height: 52}),
};
const galleryActions = {
    hidden: false,
    closest: () => null,
    getBoundingClientRect: () => ({left: 870, top: 90, right: 1260, bottom: 134, width: 390, height: 44}),
};
const publicDocument = {
    documentElement: {clientWidth: 1280},
    querySelector: selector => selector === '.site-header' ? header : null,
    querySelectorAll: () => [header, galleryActions],
};
const desktopGeometry = readPublicWidgetFloatingGeometry(publicDocument, {innerHeight: 800, visualViewport: null});
assert.equal(desktopGeometry.supported, true, 'Desktop Theme geometry supports a floating preview');
assert.equal(desktopGeometry.topInset, 64, 'The real header bottom protects the floating panel');
assert.deepEqual(desktopGeometry.safeAreaInsets, {top: 0, right: 0, bottom: 0, left: 0},
    'Documents without safe-area measurement support default every inset to zero');
assert.equal(desktopGeometry.availableWidth, 1248, 'The usable width retains the 16px margins on both sides');
assert.deepEqual(desktopGeometry.exclusions, [
    {left: 0, top: 0, width: 1280, height: 52},
    {left: 870, top: 90, width: 390, height: 44},
]);

let safeAreaValues = {top: 100, right: 30, bottom: 40, left: 20};
const safeAreaProbeNodes = [];
const safeAreaDocument = {
    documentElement: {
        clientWidth: 1280,
        appendChild: probe => safeAreaProbeNodes.push(probe),
    },
    createElement: tag => {
        assert.equal(tag, 'div', 'Safe-area measurement uses one hidden element probe');
        const attributes = {};
        return {
            style: {},
            setAttribute: (name, value) => { attributes[name] = value; },
            attributes,
        };
    },
    querySelector: selector => selector === '.site-header' ? header : null,
    querySelectorAll: () => [],
};
const safeAreaWindow = {
    innerHeight: 800,
    visualViewport: null,
    getComputedStyle: probe => {
        assert.equal(probe, safeAreaProbeNodes[0], 'Computed inset values come from the cached probe');
        return {
            paddingTop: safeAreaValues.top + 'px',
            paddingRight: safeAreaValues.right + 'px',
            paddingBottom: safeAreaValues.bottom + 'px',
            paddingLeft: safeAreaValues.left + 'px',
        };
    },
};
const insetGeometry = readPublicWidgetFloatingGeometry(safeAreaDocument, safeAreaWindow);
assert.deepEqual(insetGeometry.safeAreaInsets, safeAreaValues,
    'The measured safe-area inset values are exposed to both geometry consumers');
assert.equal(insetGeometry.topInset, 116, 'The top safe-area inset is added to the 16px margin');
assert.equal(insetGeometry.availableWidth, 1198,
    'The usable width subtracts both horizontal insets and both 16px margins');
assert.equal(safeAreaProbeNodes.length, 1, 'One safe-area probe is cached for the document');
assert.match(safeAreaProbeNodes[0].style.cssText, /position:fixed/,
    'The cached probe is fixed and does not take part in document flow');
assert.match(safeAreaProbeNodes[0].style.cssText, /env\(safe-area-inset-left, 0px\)/,
    'The probe reads the CSS environment value with a zero fallback');
safeAreaValues = {top: 8, right: 12, bottom: 6, left: 4};
const refreshedInsetGeometry = readPublicWidgetFloatingGeometry(safeAreaDocument, safeAreaWindow);
assert.deepEqual(refreshedInsetGeometry.safeAreaInsets, safeAreaValues,
    'Computed safe-area values are re-read on every geometry pass');
assert.equal(safeAreaProbeNodes.length, 1, 'Fresh values reuse the cached document probe');
safeAreaValues = {top: 8, right: 600, bottom: 6, left: 600};
const insetConstrainedGeometry = readPublicWidgetFloatingGeometry(safeAreaDocument, safeAreaWindow);
assert.equal(insetConstrainedGeometry.availableWidth, 48);
assert.equal(insetConstrainedGeometry.supported, false,
    'A safe-area-constrained width below the established panel minimum stays in normal flow');
assert.equal(safeAreaProbeNodes.length, 1, 'Unsupported safe-area geometry still reuses one probe');
assert.equal(readPublicWidgetFloatingGeometry({
    ...publicDocument, documentElement: {clientWidth: 390},
}, {innerHeight: 844, visualViewport: null}).supported, false, 'Mobile retains its in-flow fallback');
assert.equal(readPublicWidgetFloatingGeometry(publicDocument, {
    innerHeight: 800, visualViewport: {height: 800, scale: 2},
}).supported, false, 'Zoomed viewports retain their in-flow fallback');
assert.equal(publicWidgetBoundedNumber('320', 300, 180, 480), 320);
assert.equal(publicWidgetBoundedNumber('481', 300, 180, 480), 300, 'Malformed placement data uses the safe default');
assert.equal(FLOAT_LIMIT, 2, 'Admin preview and public runtime share the same floating panel cap');
assert.equal(FLOAT_MIN_PANEL_WIDTH, 180, 'Public and Admin previews share the persisted minimum panel width');

const defaults = {
    anchor: 'bottom-right', x: 900, y: 900,
    width: 300, height: 160, viewportWidth: 1280,
    viewportHeight: 800, topInset: 130, exclusions: [],
};
const galleryActionBox = desktopGeometry.exclusions[1];
const galleryTopRightInput = {
    ...defaults, anchor: 'top-right', width: 300, height: 100,
    topInset: desktopGeometry.topInset, exclusions: [desktopGeometry.exclusions[0]],
};
const galleryTopRightWithoutAction = resolvePublicWidgetFloatingRect(galleryTopRightInput);
assert.ok(galleryTopRightWithoutAction
    && galleryTopRightWithoutAction.left < galleryActionBox.left + galleryActionBox.width
    && galleryTopRightWithoutAction.left + galleryTopRightWithoutAction.width > galleryActionBox.left
    && galleryTopRightWithoutAction.top < galleryActionBox.top + galleryActionBox.height
    && galleryTopRightWithoutAction.top + galleryTopRightWithoutAction.height > galleryActionBox.top,
'A top-right Gallery panel would cover the real hero action cluster without its protected rectangle');
const galleryTopRightWithAction = resolvePublicWidgetFloatingRect({
    ...galleryTopRightInput, exclusions: desktopGeometry.exclusions,
});
assert.ok(galleryTopRightWithAction
    && (galleryTopRightWithAction.left + galleryTopRightWithAction.width <= galleryActionBox.left
        || galleryTopRightWithAction.left >= galleryActionBox.left + galleryActionBox.width
        || galleryTopRightWithAction.top + galleryTopRightWithAction.height <= galleryActionBox.top
        || galleryTopRightWithAction.top >= galleryActionBox.top + galleryActionBox.height),
'Shared Gallery geometry moves a top-right panel clear of the hero action cluster');
const right = resolvePublicWidgetFloatingRect(defaults);
assert.deepEqual(right, {left: 964, top: 624, width: 300, height: 160});

const left = resolvePublicWidgetFloatingRect({...defaults, anchor: 'top-left'});
assert.deepEqual(left, {left: 16, top: 130, width: 300, height: 160});
const insetTopLeft = resolvePublicWidgetFloatingRect({
    ...defaults, anchor: 'top-left', topInset: insetGeometry.topInset,
    safeAreaInsets: insetGeometry.safeAreaInsets, exclusions: [],
});
assert.deepEqual(insetTopLeft, {left: 36, top: 116, width: 300, height: 160},
    'The left and top edges retain the safe-area inset plus the existing 16px margin');
const insetBottomRight = resolvePublicWidgetFloatingRect({
    ...defaults, topInset: insetGeometry.topInset,
    safeAreaInsets: insetGeometry.safeAreaInsets, exclusions: [],
});
assert.deepEqual(insetBottomRight, {left: 934, top: 584, width: 300, height: 160},
    'The right and bottom edges retain the safe-area inset plus the existing 16px margin');
const subpixelInsetTopLeft = resolvePublicWidgetFloatingRect({
    ...defaults, anchor: 'top-left', topInset: 16.25,
    safeAreaInsets: {top: 0.25, right: 0.25, bottom: 0.25, left: 0.25}, exclusions: [],
});
assert.deepEqual(subpixelInsetTopLeft, {left: 16.25, top: 16.25, width: 300, height: 160},
    'Coordinate rounding cannot move a panel inside a fractional safe-area margin');
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
