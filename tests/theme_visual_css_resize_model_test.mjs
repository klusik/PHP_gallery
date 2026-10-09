/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_visual_css_resize_model_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Verify safe resize profile classification, bounded geometry, and grouped CSS declarations.
 * Responsibilities: Exercise deterministic resize decisions without a browser or filesystem fixture.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {
    classifyVisualCssResizeProfile,
    createVisualCssResizeTransaction,
    updateVisualCssResizeTransaction,
} from '../public/assets/gallery-modules/theme-visual-css-resize.js';
import {validateVisualCssDraftRule} from '../public/assets/gallery-modules/theme-visual-css-draft.js';

/**
 * Build a DOM-like element with deterministic owner-window style and geometry adapters.
 * @param {{tag?:string,selectorMatches?:Array<string>,rect:{left:number,top:number,width:number,height:number},style?:Record<string,string>,parentStyle?:Record<string,string>}} options Element name, matching selectors, CSS pixel rectangle, and computed style fixtures.
 * @returns {Element} Minimal element contract consumed by the profile classifier.
 */
function resizeElement(options) {
    const parent = {parentElement: null};
    const element = {
        nodeType: 1,
        localName: options.tag || 'div',
        parentElement: parent,
        style: {getPropertyPriority: () => ''},
        matches: selector => selector.split(',').some(candidate => options.selectorMatches.includes(candidate.trim())),
        getBoundingClientRect: () => ({...options.rect}),
    };
    const computed = node => node === parent
        ? {display: options.parentStyle?.display || 'block', flexDirection: options.parentStyle?.flexDirection || 'row',
            gridTemplateColumns: options.parentStyle?.gridTemplateColumns || 'none',
            gridTemplateRows: options.parentStyle?.gridTemplateRows || 'none'}
        : {display: options.style?.display || 'block', minWidth: options.style?.minWidth || '0px',
            maxWidth: options.style?.maxWidth || 'none', minHeight: options.style?.minHeight || '0px',
            maxHeight: options.style?.maxHeight || 'none'};
    const view = {innerWidth: 390, innerHeight: 844, getComputedStyle: computed};
    const document = {defaultView: view};
    parent.ownerDocument = document;
    element.ownerDocument = document;
    return element;
}

const hero = resizeElement({selectorMatches: ['.hero'], rect: {left: 20, top: 100, width: 350, height: 240}});
const heroProfile = classifyVisualCssResizeProfile(hero);
assert.equal(heroProfile.profileId, 'hero');
assert.deepEqual(heroProfile.edges, ['top', 'bottom'], 'hero exposes vertical handles only');
assert.equal(heroProfile.horizontalProperty, null);
assert.equal(heroProfile.verticalProperty, 'min-height');

const heroTransaction = createVisualCssResizeTransaction(heroProfile,
    {left: 20, top: 100, width: 350, height: 240}, {x: 200, y: 340}, 'bottom', '.hero', 'responsive:tablet');
const heroResult = updateVisualCssResizeTransaction(heroTransaction, {x: 200, y: 700});
assert.equal(heroResult.changes.length, 1, 'one hero drag emits one model declaration');
assert.deepEqual(heroResult.changes[0], {
    scope: 'responsive:tablet', selector: '.hero', property: 'min-height', value: '600px',
});
assert.equal(validateVisualCssDraftRule(heroResult.changes[0]).valid, true,
    'hero sizing output must pass the managed CSS model validator');
const heroBounded = updateVisualCssResizeTransaction(heroTransaction, {x: 200, y: 10000});
assert.equal(heroBounded.height, heroProfile.maxHeight, 'hero resize must stop at its measured viewport/style bound');

const header = resizeElement({selectorMatches: ['.site-header'], rect: {left: 0, top: 0, width: 390, height: 72}});
const headerProfile = classifyVisualCssResizeProfile(header);
assert.equal(headerProfile.profileId, 'layout');
assert.deepEqual(headerProfile.edges, ['top', 'bottom'], 'site shell regions do not offer misleading horizontal resize');

const inline = resizeElement({selectorMatches: ['span'], rect: {left: 4, top: 4, width: 20, height: 12}, style: {display: 'inline'}});
assert.equal(classifyVisualCssResizeProfile(inline).eligible, false, 'inline text has no resize handles');

const flexCard = resizeElement({selectorMatches: ['.gallery-card'], rect: {left: 0, top: 0, width: 180, height: 220},
    parentStyle: {display: 'flex', flexDirection: 'row'}});
const flexCardProfile = classifyVisualCssResizeProfile(flexCard);
assert.equal(flexCardProfile.profileId, 'card');
assert.deepEqual(flexCardProfile.edges, ['left', 'right'], 'a row flex child is resizable only on its effective main axis');
assert.equal(flexCardProfile.horizontalProperty, 'width');
assert.equal(flexCardProfile.verticalProperty, null);
const unconstrainedCard = resizeElement({selectorMatches: ['.gallery-card'], rect: {left: 0, top: 0, width: 180, height: 220}});
assert.equal(classifyVisualCssResizeProfile(unconstrainedCard).eligible, false,
    'a grid/flex card with no measurable parent axis has no misleading handle');
const gridCard = resizeElement({selectorMatches: ['.gallery-card'], rect: {left: 0, top: 0, width: 180, height: 220},
    parentStyle: {display: 'grid', gridTemplateColumns: '180px 180px', gridTemplateRows: '110px 110px'}});
assert.deepEqual(classifyVisualCssResizeProfile(gridCard).edges,
    ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
    'a card in a measured two-axis grid can resize on its effective row and column tracks');

const media = resizeElement({tag: 'img', selectorMatches: ['img.safe-photo'], rect: {left: 10, top: 10, width: 200, height: 100},
    style: {maxHeight: '150px'}, parentStyle: {display: 'flex', flexDirection: 'row'}});
const mediaProfile = classifyVisualCssResizeProfile(media);
assert.equal(mediaProfile.profileId, 'media');
assert.deepEqual(mediaProfile.edges, ['left', 'right'], 'media in a row layout uses horizontal handles');
const mediaTransaction = createVisualCssResizeTransaction(mediaProfile,
    {left: 10, top: 10, width: 200, height: 100}, {x: 210, y: 60}, 'right', 'img.safe-photo', 'site');
const mediaResult = updateVisualCssResizeTransaction(mediaTransaction, {x: 1000, y: 60});
assert.equal(mediaResult.width, 300, 'aspect-preserving media size must clamp against the derived height bound');
assert.deepEqual(mediaResult.changes, [
    {scope: 'site', selector: 'img.safe-photo', property: 'width', value: '300px'},
    {scope: 'site', selector: 'img.safe-photo', property: 'height', value: 'auto'},
]);
assert.equal(Object.isFrozen(mediaResult.changes), true, 'resize callback changes are immutable model snapshots');
assert.equal(mediaResult.changes.every(change => validateVisualCssDraftRule(change).valid), true,
    'media resize output must remain inside the managed CSS value grammar');

const columnMedia = resizeElement({tag: 'img', selectorMatches: ['img.safe-photo'], rect: {left: 10, top: 10, width: 200, height: 100},
    parentStyle: {display: 'flex', flexDirection: 'column'}});
assert.deepEqual(classifyVisualCssResizeProfile(columnMedia).edges, ['top', 'bottom'],
    'media in a column layout uses vertical handles');

assert.equal(createVisualCssResizeTransaction(heroProfile, {left: 0, top: 0, width: 0, height: 40},
    {x: 0, y: 0}, 'bottom', '.hero', 'site'), null, 'zero-area targets cannot start a resize transaction');
assert.equal(updateVisualCssResizeTransaction(heroTransaction, {x: Number.NaN, y: 0}), null,
    'non-finite pointer coordinates cannot produce a CSS change');

console.log('Visual CSS resize model tests passed.');
