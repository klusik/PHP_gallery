/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_visual_css_draft_model_test.mjs
 * Module Type: Node Regression Test
 * Purpose: Verify exact preservation, safe edits, responsive scopes, and immutable history for visual CSS drafts.
 * Responsibilities: Exercise the managed CSS model without a browser or filesystem fixture.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {
    applyVisualCssDraftChanges,
    parseVisualCssDraft,
    redoVisualCssDraft,
    removeVisualCssDraftProperty,
    serializeVisualCssDraft,
    undoVisualCssDraft,
    updateVisualCssDraftProperty,
    validateVisualCssDraftRule,
    visualCssDraftPropertyValueIsValid,
    visualCssDraftSelectorIsValid,
} from '../public/assets/gallery-modules/theme-visual-css-draft.js';

const source = '/* hand-authored header */\r\nbody { color: rebeccapurple; }\r\n/* untouched trailing comment */';
const parsed = parseVisualCssDraft(source);
assert.equal(parsed.valid, true, 'ordinary existing CSS should parse as an editable draft');
assert.equal(serializeVisualCssDraft(parsed), source, 'a no-op session should preserve every original byte');

for (const lineEnding of ['\n', '\r\n']) {
    const authoredCss = [
        '/* hand-authored header */',
        'body { color: rebeccapurple; }',
        '/* section note */',
        'main { padding: 1rem; }',
        '/* untouched trailing comment */',
    ].join(lineEnding);
    const authoredDraft = parseVisualCssDraft(authoredCss);
    const editedAuthoredDraft = updateVisualCssDraftProperty(authoredDraft, {
        selector: 'body', scope: 'site', property: 'background-color', value: 'rgba(20, 30, 40, 0.5)',
    });
    assert.equal(editedAuthoredDraft.css.startsWith(authoredCss.slice(0, authoredCss.indexOf('body {'))), true,
        'earlier comments and authored rules must remain ahead of new managed declarations');
    assert.ok(editedAuthoredDraft.css.indexOf('main {') < editedAuthoredDraft.css.indexOf('PHP Gallery managed visual CSS BEGIN'),
        'a closed earlier comment must not absorb a later authored rule into the trailing suffix');
    assert.equal(editedAuthoredDraft.css.endsWith(`${lineEnding}/* untouched trailing comment */`), true,
        'only the final detached comment should remain after the managed block');
    assert.equal(serializeVisualCssDraft(parseVisualCssDraft(editedAuthoredDraft.css)), editedAuthoredDraft.css,
        'a managed stylesheet with earlier comments and real rules should reopen idempotently');
    assert.equal(removeVisualCssDraftProperty(editedAuthoredDraft, {
        selector: 'body', scope: 'site', property: 'background-color',
    }).css, authoredCss, 'reset should restore exact original bytes around multiple comments and CSS rules');
}

const first = updateVisualCssDraftProperty(parsed, {
    selector: 'body',
    scope: 'site',
    property: 'background-color',
    value: 'rgba(20, 30, 40, 0.5)',
});
assert.equal(first.valid, true, 'safe literal alpha colors should be accepted');
assert.equal(first.css.startsWith(source.slice(0, source.indexOf('body {'))), true, 'the original prefix should remain byte-identical');
assert.equal(first.css.endsWith('/* untouched trailing comment */'), true, 'the original suffix should remain byte-identical');
assert.match(first.css, /background-color: rgba\(20, 30, 40, 0\.5\);/);
assert.match(first.css, /line-ending=crlf/);

const second = updateVisualCssDraftProperty(first, {
    selector: 'body',
    scope: 'responsive:tablet',
    property: 'padding',
    value: '1rem 2rem',
});
assert.match(second.css, /@media \(max-width: 768px\)/, 'tablet rules should use the explicit tablet scope');
assert.doesNotMatch(second.css, /max-width: 480px/, 'tablet scope should not create mobile rules');
const reparsed = parseVisualCssDraft(second.css);
assert.equal(reparsed.valid, true, 'a saved managed block should be accepted in a later session');
assert.equal(reparsed.block?.suffix, '\r\n/* untouched trailing comment */',
    'a reopened CRLF block must leave its terminal marker carriage return in the exact preserved suffix');
assert.equal(serializeVisualCssDraft(reparsed), second.css, 'repeated parse and serialization should be idempotent');

const unchanged = updateVisualCssDraftProperty(second, {
    selector: 'body',
    scope: 'responsive:tablet',
    property: 'padding',
    value: '1rem 2rem',
});
assert.equal(unchanged, second, 'an identical property update should be a byte-preserving no-op');

const grouped = applyVisualCssDraftChanges(second, [
    {selector: '#safe-id', scope: 'site', property: 'color', value: '#1234'},
    {selector: '#safe-id', scope: 'responsive:mobile', property: 'object-fit', value: 'cover'},
]);
assert.equal(grouped.historyIndex, second.historyIndex + 1, 'multiple property edits should form one undo step');
assert.match(grouped.css, /@media \(max-width: 480px\)/);
assert.equal(undoVisualCssDraft(grouped).css, second.css, 'undo should restore the complete previous snapshot');
assert.equal(redoVisualCssDraft(undoVisualCssDraft(grouped)).css, grouped.css, 'redo should restore the edited snapshot');

const replaced = updateVisualCssDraftProperty(grouped, {
    selector: '#safe-id',
    scope: 'site',
    property: 'color',
    value: 'blue',
});
assert.equal(replaced.rules.filter(rule => rule.selector === '#safe-id' && rule.scope === 'site' && rule.property === 'color').length, 1,
    'updating a property should replace its unique selector/scope/property entry');
const removed = removeVisualCssDraftProperty(replaced, {selector: '#safe-id', scope: 'site', property: 'color'});
assert.equal(removed.rules.some(rule => rule.selector === '#safe-id' && rule.scope === 'site' && rule.property === 'color'), false,
    'removal should target exactly one property identity');

for (const property of [
    'color', 'background-color', 'font-size', 'font-weight', 'line-height', 'text-align', 'text-decoration',
    'backdrop-filter', '-webkit-backdrop-filter', 'border-radius',
    'border-width', 'border-style', 'border-color', 'box-shadow', 'gap', 'min-height', 'width', 'height',
    'object-fit', 'object-position', 'padding', 'margin',
]) {
    assert.equal(visualCssDraftPropertyValueIsValid(property, 'url(https://example.invalid/x)').valid, false,
        `${property} should reject resource-loading values`);
}
assert.equal(visualCssDraftPropertyValueIsValid('color', 'rgba(0, 0, 0, .4)').valid, true,
    'literal rgba colors should remain usable inside color properties');
assert.equal(visualCssDraftPropertyValueIsValid('box-shadow', '0 2px 4px rgba(0, 0, 0, .2)').valid, true,
    'box-shadow parsing should keep functional color commas within one shadow');
assert.equal(visualCssDraftPropertyValueIsValid('padding', '1rem 2rem 3rem 4rem').valid, true,
    'four-value spacing shorthand should be accepted');
assert.equal(visualCssDraftPropertyValueIsValid('padding', '1rem 2rem 3rem 4rem 5rem').valid, false,
    'overlong spacing shorthand should be rejected');
for (const value of ['none', 'underline', 'overline', 'line-through']) {
    assert.equal(visualCssDraftPropertyValueIsValid('text-decoration', value).valid, true,
        'text-decoration controls accept only the supported line values');
}
for (const value of ['underline dotted red', 'blink', 'url(image.svg)', 'var(--decoration)']) {
    assert.equal(visualCssDraftPropertyValueIsValid('text-decoration', value).valid, false,
        'text-decoration rejects shorthand details and unsupported or executable values');
}
for (const property of ['backdrop-filter', '-webkit-backdrop-filter']) {
    assert.equal(visualCssDraftPropertyValueIsValid(property, 'blur(12px) saturate(1.08)').valid, true,
        'the Theme backdrop filter grammar preserves its current bounded blur and saturation values');
    assert.equal(visualCssDraftPropertyValueIsValid(property, 'none').valid, true,
        'the Theme backdrop filter can be explicitly cleared');
    for (const value of ['blur(40px)', 'blur(1rem)', 'saturate(2.1)', 'grayscale(1)', 'blur(1px) blur(2px)', 'url(image.svg)']) {
        assert.equal(visualCssDraftPropertyValueIsValid(property, value).valid, false,
            'backdrop filters reject unsupported functions, duplicate functions, and values above their bounds');
    }
}
const textDecorationDraft = applyVisualCssDraftChanges(parsed, [{
    selector: 'body.public-page #heading', scope: 'site', property: 'text-decoration', value: 'underline',
}]);
assert.equal(textDecorationDraft.historyIndex, parsed.historyIndex + 1,
    'text decoration enters one ordinary managed history action');
assert.equal(undoVisualCssDraft(textDecorationDraft).css, parsed.css,
    'undo removes only the managed text-decoration declaration and restores exact source bytes');
assert.equal(redoVisualCssDraft(undoVisualCssDraft(textDecorationDraft)).css, textDecorationDraft.css,
    'redo restores the exact bounded text-decoration declaration');
const themeShells = ['body.public-page.public-page .site-header', 'body.public-page.public-page .site-main',
    'body.public-page.public-page .site-footer'];
const widthModes = [
    'min(1120px,calc(100% - 2rem))',
    'min(1440px,calc(100% - 2rem))',
    'min(1024px,calc(100% - 2rem))',
    'min(2048px,calc(100% - 2rem))',
    'calc(100% - clamp(1rem, 3vw, 3rem))',
];
for (const selector of themeShells) {
    for (const value of widthModes) {
        assert.equal(validateVisualCssDraftRule({selector, scope: 'site', property: 'width', value}).valid, true,
            'only the established Theme width templates should be allowed on each public shell');
    }
    assert.equal(validateVisualCssDraftRule({selector, scope: 'responsive:tablet', property: 'width',
        value: 'min(1440px,calc(100% - 2rem))'}).valid, false,
    'Theme page width must remain a site-wide choice rather than an implicit responsive rule');
    assert.equal(validateVisualCssDraftRule({selector, scope: 'site', property: 'width', value: '1120px'}).valid, false,
        'public shells must use an exact Theme width template');
}
for (const value of ['min(1023px,calc(100% - 2rem))', 'min(2049px,calc(100% - 2rem))',
    'min(900px,calc(100% - 2rem))', 'min(var(--page-width-wide),calc(100% - 2rem))',
    'calc(100% - clamp(1rem,3vw,3rem))']) {
    assert.equal(validateVisualCssDraftRule({selector: themeShells[1], scope: 'site', property: 'width', value}).valid, false,
        'page width values outside the exact supported Theme forms must be rejected');
}
assert.equal(validateVisualCssDraftRule({selector: 'body.public-page .site-main', scope: 'site', property: 'width',
    value: 'min(1440px,calc(100% - 2rem))'}).valid, false,
'a less-specific or unrelated selector must not inject a Theme page-width function');
assert.equal(visualCssDraftPropertyValueIsValid('width', '100%').valid, true,
    'ordinary safe media/card width values remain available');
assert.equal(validateVisualCssDraftRule({selector: '.gallery-card', scope: 'site', property: 'width', value: '100%'}).valid, true,
    'ordinary media/card width stays available under the existing literal size grammar');
const widthDraft = applyVisualCssDraftChanges(parsed, themeShells.map(selector => ({selector, scope: 'site',
    property: 'width', value: 'min(1440px,calc(100% - 2rem))'})));
assert.equal(widthDraft.historyIndex, parsed.historyIndex + 1, 'the three-shell width mode should form one undo step');
assert.equal(widthDraft.rules.filter(rule => rule.property === 'width').length, 3,
    'one page-width choice should apply to header, main, and footer together');
assert.equal(serializeVisualCssDraft(parseVisualCssDraft(widthDraft.css)), widthDraft.css,
    'the Theme width templates should survive persisted CSS parsing and serialization unchanged');
const widthReset = applyVisualCssDraftChanges(widthDraft, themeShells.map(selector => ({selector, scope: 'site',
    property: 'width', value: null})));
assert.equal(widthReset.css, parsed.css, 'width reset should remove only the managed shell declarations');
assert.equal(undoVisualCssDraft(widthReset).css, widthDraft.css, 'one undo should restore the grouped width mode');

assert.equal(visualCssDraftPropertyValueIsValid('opacity', '0.50').value, '0.5',
    'background opacity should normalize decimal values');
for (const value of ['-0.1', '1.01', '2', 'NaN', '50%', '0.1234']) {
    assert.equal(visualCssDraftPropertyValueIsValid('opacity', value).valid, false,
        'background opacity must remain in the normalized 0..1 range');
}
assert.equal(validateVisualCssDraftRule({selector: 'body.public-page .theme-background-image', scope: 'site',
    property: 'opacity', value: '0.65'}).valid, true, 'opacity may target only the separate Theme background image layer');
assert.equal(validateVisualCssDraftRule({selector: 'body.public-page .site-main', scope: 'site',
    property: 'opacity', value: '0.65'}).valid, false, 'opacity must never fade page content');
assert.equal(validateVisualCssDraftRule({selector: 'body.public-page .theme-background-image', scope: 'responsive:mobile',
    property: 'opacity', value: '0.65'}).valid, false, 'Theme background opacity is a site-level setting preview');
const backgroundOpacity = updateVisualCssDraftProperty(parsed, {selector: 'body.public-page .theme-background-image',
    scope: 'site', property: 'opacity', value: '0.65'});
assert.match(backgroundOpacity.css, /opacity: 0\.65;/,
    'a deliberate opacity preview writes only the background image layer');
const resetBackgroundOpacity = removeVisualCssDraftProperty(backgroundOpacity, {
    selector: 'body.public-page .theme-background-image', scope: 'site', property: 'opacity',
});
assert.equal(resetBackgroundOpacity.css, parsed.css,
    'resetting background opacity removes only its managed declaration and reveals the saved Theme baseline');
assert.equal(undoVisualCssDraft(resetBackgroundOpacity).css, backgroundOpacity.css,
    'background opacity reset remains one undoable model edit');
assert.equal(visualCssDraftPropertyValueIsValid('background-size', 'COVER').value, 'cover',
    'Theme background fit should normalize the two supported sizing keywords');
assert.equal(visualCssDraftPropertyValueIsValid('background-size', 'contain').valid, true,
    'contain should be a supported Theme background fit');
for (const value of ['auto', '100% 100%', 'cover, contain', 'url(/private/image.jpg)', 'var(--fit)']) {
    assert.equal(visualCssDraftPropertyValueIsValid('background-size', value).valid, false,
        'Theme background fit must reject values outside the closed cover/contain choices');
}
assert.equal(visualCssDraftPropertyValueIsValid('background-position', '025%   070%').value, '25% 70%',
    'Theme background position should normalize bounded integer percentage axes');
for (const value of ['101% 50%', '-1% 50%', '50% 101%', '50.5% 50%', 'center center', '20px 30px', 'url(image.jpg)']) {
    assert.equal(visualCssDraftPropertyValueIsValid('background-position', value).valid, false,
        'Theme background position must reject unsupported values and out-of-range axes');
}
const themeBackgroundSelector = 'body.public-page .theme-background-image';
for (const property of ['background-size', 'background-position']) {
    assert.equal(validateVisualCssDraftRule({selector: themeBackgroundSelector, scope: 'site', property,
        value: property === 'background-size' ? 'contain' : '25% 70%'}).valid, true,
    `${property} should target only the permanent global Theme image layer`);
    assert.equal(validateVisualCssDraftRule({selector: '.hero', scope: 'site', property,
        value: property === 'background-size' ? 'contain' : '25% 70%'}).valid, false,
    `${property} must not target gallery hero image backgrounds`);
    assert.equal(validateVisualCssDraftRule({selector: themeBackgroundSelector, scope: 'responsive:mobile', property,
        value: property === 'background-size' ? 'contain' : '25% 70%'}).valid, false,
    `${property} must remain a site-scope Theme layer control`);
}
const themeBackgroundFit = applyVisualCssDraftChanges(parsed, [
    {selector: themeBackgroundSelector, scope: 'site', property: 'background-size', value: 'contain'},
]);
const themeBackgroundPresentation = applyVisualCssDraftChanges(themeBackgroundFit, [
    {selector: themeBackgroundSelector, scope: 'site', property: 'background-position', value: '25% 70%'},
]);
assert.match(themeBackgroundPresentation.css, /background-size: contain;/,
    'Theme background fit should serialize to the existing global image layer');
assert.match(themeBackgroundPresentation.css, /background-position: 25% 70%;/,
    'Theme background position should serialize to the existing global image layer');
assert.equal(themeBackgroundPresentation.historyIndex, parsed.historyIndex + 2,
    'fit and position should be independently undoable managed changes');
const undoThemeBackgroundPosition = undoVisualCssDraft(themeBackgroundPresentation);
assert.equal(undoThemeBackgroundPosition.css, themeBackgroundFit.css,
    'undoing position should preserve the earlier independent fit change');
assert.equal(undoVisualCssDraft(undoThemeBackgroundPosition).css, parsed.css,
    'a second undo should restore the original Theme image-layer declarations');
assert.equal(serializeVisualCssDraft(parseVisualCssDraft(themeBackgroundPresentation.css)), themeBackgroundPresentation.css,
    'background fit and position should survive a persisted managed-block round trip');
const resetThemeBackgroundFit = removeVisualCssDraftProperty(themeBackgroundPresentation, {
    selector: themeBackgroundSelector, scope: 'site', property: 'background-size',
});
assert.equal(resetThemeBackgroundFit.rules.some(rule => rule.property === 'background-size'), false,
    'fit reset should remove only its managed declaration');
assert.equal(resetThemeBackgroundFit.rules.some(rule => rule.property === 'background-position'), true,
    'fit reset should preserve the independent managed position');
assert.equal(undoVisualCssDraft(resetThemeBackgroundFit).css, themeBackgroundPresentation.css,
    'fit reset should be undoable without changing the position rule');
const resetThemeBackgroundPosition = removeVisualCssDraftProperty(resetThemeBackgroundFit, {
    selector: themeBackgroundSelector, scope: 'site', property: 'background-position',
});
assert.equal(resetThemeBackgroundPosition.css, parsed.css,
    'position reset should remove its managed declaration and reveal the existing Theme center baseline');
const unchangedThemeBackground = applyVisualCssDraftChanges(themeBackgroundPresentation, [
    {selector: themeBackgroundSelector, scope: 'site', property: 'background-size', value: 'contain'},
]);
assert.equal(unchangedThemeBackground.css, themeBackgroundPresentation.css,
    'reselecting the active fit must preserve exact CSS bytes');
assert.equal(unchangedThemeBackground.historyIndex, themeBackgroundPresentation.historyIndex,
    'reselecting the active fit must not add an undo entry');
assert.equal(visualCssDraftSelectorIsValid('[data-testid="safe-value"]').valid, true,
    'the inspector attribute selectors should be admitted');
assert.equal(visualCssDraftSelectorIsValid('article.gallery-card > img:nth-of-type(1)').valid, true,
    'generated tag, class, and structural selector chains should be admitted');
assert.equal(visualCssDraftSelectorIsValid('.public-page .hero::before').valid, true,
    'a validated stable selector may target its terminal before pseudo-element');
assert.equal(visualCssDraftSelectorIsValid('.public-page .hero::after').valid, true,
    'a validated stable selector may target its terminal after pseudo-element');
assert.equal(visualCssDraftSelectorIsValid('.public-page .hero::marker').valid, false,
    'unsupported pseudo-elements must remain unavailable');
assert.equal(visualCssDraftSelectorIsValid('.hero::before .child').valid, false,
    'pseudo-elements must remain terminal and cannot select descendant structure');
assert.equal(visualCssDraftPropertyValueIsValid('background-image', 'none').valid, true,
    'only disabling a pseudo-element background image with none is permitted');
assert.equal(visualCssDraftPropertyValueIsValid('background-image', 'url(/private/image.jpg)').valid, false,
    'background-image must reject resource URLs');
assert.equal(visualCssDraftPropertyValueIsValid('background-image', 'linear-gradient(red, blue)').valid, false,
    'background-image must reject authored image functions');
assert.equal(visualCssDraftSelectorIsValid('article > > img').valid, false,
    'malformed repeated combinators should be rejected');
assert.equal(visualCssDraftSelectorIsValid('body, html').valid, false,
    'comma selector lists should be rejected as ambiguous editor targets');

const heroEdited = applyVisualCssDraftChanges(parsed, [
    {selector: '.public-page .hero', scope: 'site', property: 'background-color', value: 'rgba(20, 30, 40, 0.5)'},
    {selector: '.public-page .hero::before', scope: 'site', property: 'background-image', value: 'none',
        companionOf: {scope: 'site', selector: '.public-page .hero', property: 'background-color'}},
]);
assert.equal(heroEdited.historyIndex, parsed.historyIndex + 1,
    'hero color and pseudo-overlay suppression should form one undoable action');
assert.match(heroEdited.css, /\.public-page \.hero::before/);
assert.match(heroEdited.css, /background-image: none;/);
const reopenedHero = parseVisualCssDraft(heroEdited.css);
assert.equal(reopenedHero.valid, true, 'owned hero overlay provenance should survive a later edit session');
assert.deepEqual(reopenedHero.rules.find(rule => rule.property === 'background-image')?.companionOf,
    {scope: 'site', selector: '.public-page .hero', property: 'background-color'});
assert.equal(Object.isFrozen(reopenedHero.rules.find(rule => rule.property === 'background-image')?.companionOf), true,
    'nested companion ownership is immutable with its containing rule');
assert.equal(serializeVisualCssDraft(reopenedHero), heroEdited.css,
    'managed overlay ownership must remain byte-stable after reopening');
assert.equal(undoVisualCssDraft(heroEdited).css, parsed.css,
    'one undo should restore the authored hero cascade exactly');
const heroReset = applyVisualCssDraftChanges(reopenedHero, [
    {selector: '.public-page .hero', scope: 'site', property: 'background-color', value: null},
    {selector: '.public-page .hero::before', scope: 'site', property: 'background-image', value: null},
]);
assert.equal(heroReset.css, parsed.css,
    'grouped reset should remove both managed declarations and reveal the authored gradient');
assert.equal(undoVisualCssDraft(heroReset).css, reopenedHero.css,
    'undo after reset should restore both linked declarations in one history step');

const independentPseudo = applyVisualCssDraftChanges(parsed, [
    {selector: '.public-page .hero::before', scope: 'site', property: 'background-image', value: 'none'},
]);
const independentWithPrimary = applyVisualCssDraftChanges(independentPseudo, [
    {selector: '.public-page .hero', scope: 'site', property: 'background-color', value: 'rgba(20, 30, 40, 0.5)'},
]);
const independentReset = applyVisualCssDraftChanges(independentWithPrimary, [
    {selector: '.public-page .hero', scope: 'site', property: 'background-color', value: null},
]);
assert.equal(independentReset.rules.some(rule => rule.selector === '.public-page .hero::before'
    && rule.property === 'background-image' && rule.value === 'none' && !rule.companionOf), true,
    'reset must preserve a pre-existing independent pseudo-element override');
const orphanedCompanion = applyVisualCssDraftChanges(parsed, [
    {selector: '.public-page .hero::before', scope: 'site', property: 'background-image', value: 'none',
        companionOf: {scope: 'site', selector: '.public-page .hero', property: 'background-color'}},
]);
assert.equal(orphanedCompanion.css, parsed.css, 'an owned overlay without its primary declaration must fail closed');
assert.ok(orphanedCompanion.warning, 'a malformed ownership group should explain why existing CSS was kept');
const malformedOwner = applyVisualCssDraftChanges(parsed, [
    {selector: '.public-page .hero', scope: 'site', property: 'background-color', value: 'rgba(20, 30, 40, 0.5)'},
    {selector: '.public-page .hero::before', scope: 'site', property: 'background-image', value: 'none',
        companionOf: {scope: 'site', selector: '.public-page .hero', property: 'background-color', unexpected: true}},
]);
assert.equal(malformedOwner.css, parsed.css, 'unknown provenance fields must fail closed');
const corruptedOwner = heroEdited.css.replace(/\/\* PHP Gallery managed visual CSS DATA ([A-Za-z0-9+/=]+) \*\//, (_match, encoded) => {
    const rules = JSON.parse(Buffer.from(encoded, 'base64').toString('utf8'));
    rules.find(rule => rule.property === 'background-image').companionOf.selector = '.public-page .unrelated';
    return '/* PHP Gallery managed visual CSS DATA ' + Buffer.from(JSON.stringify(rules), 'utf8').toString('base64') + ' */';
});
assert.equal(parseVisualCssDraft(corruptedOwner).valid, false,
    'reopened managed CSS with a dangling ownership link must fail closed');

const malformed = parseVisualCssDraft(source + '\n/* PHP Gallery managed visual CSS BEGIN broken */');
assert.equal(malformed.valid, false, 'a malformed marker should fail closed');
assert.equal(malformed.css, source + '\n/* PHP Gallery managed visual CSS BEGIN broken */', 'failed parsing should retain the exact original CSS');
assert.ok(malformed.warning, 'failed parsing should expose a user-readable warning');

const duplicate = parseVisualCssDraft(second.css + '\n' + second.css.match(/\/\* PHP Gallery managed visual CSS BEGIN[^\n]*/)[0]);
assert.equal(duplicate.valid, false, 'duplicate marker prefixes should fail closed');
assert.equal(duplicate.css, second.css + '\n' + second.css.match(/\/\* PHP Gallery managed visual CSS BEGIN[^\n]*/)[0],
    'duplicate marker conflicts should retain every input byte');

const rejected = updateVisualCssDraftProperty(parsed, {
    selector: 'body', scope: 'site', property: 'background-color', value: 'url(/private/image.jpg)',
});
assert.equal(rejected.css, source, 'an invalid update should not change CSS bytes');
assert.ok(rejected.warning, 'an invalid update should explain why the draft was kept');

const duplicateProperty = parseVisualCssDraft(second.css.replace('padding: 1rem 2rem;', 'padding: 1rem 2rem;\r\n  padding: 3rem;'));
assert.equal(duplicateProperty.valid, false, 'editing the managed body outside the model should fail closed');
assert.equal(duplicateProperty.css, second.css.replace('padding: 1rem 2rem;', 'padding: 1rem 2rem;\r\n  padding: 3rem;'));

console.log('Visual CSS draft model tests passed.');
