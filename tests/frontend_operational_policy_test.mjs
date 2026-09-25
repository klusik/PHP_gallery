/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/frontend_operational_policy_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify the bounded frontend policy migration without application bootstrap.
 * Responsibilities:
 *   - Exercise current title, Settings and navigation consumers with disposable DOM/timer seams.
 *   - Lock reviewed values, policy documentation and cache-import ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import * as policy from '../public/assets/gallery-modules/admin-interaction-policy.js';
import * as pickerPolicy from '../public/assets/gallery-modules/gallery-picker-policy.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const directory = path.join(root, 'public/assets/gallery-modules');
const policySource = await readFile(path.join(directory, 'admin-interaction-policy.js'), 'utf8');
/**
 * Pre-migration public values, independent from the production policy imports.
 * @type {Record<string,number|ReadonlyArray<number>>} Exact symbol-to-value fixture; values use each production definition's documented units.
 */
const expected = {
    GALLERY_TITLE_COMPLETION_MIN_CHARACTERS: 2,
    GALLERY_TITLE_COMPLETION_MAX_CHARACTERS: 255,
    GALLERY_TITLE_COMPLETION_RESULT_LIMIT: 8,
    GALLERY_TITLE_COMPLETION_DEBOUNCE_MS: 180,
    GALLERY_TITLE_COMPLETION_REQUEST_TIMEOUT_MS: 5000,
    ADMIN_SETTINGS_SEARCH_RESULT_LIMIT: 12,
    ADMIN_SETTINGS_SEARCH_PREFIX_SCORE: 100,
    ADMIN_SETTINGS_SEARCH_WORD_SCORE: 70,
    ADMIN_SETTINGS_SEARCH_SUBSTRING_SCORE: 50,
    ADMIN_SETTINGS_SEARCH_KEYWORD_SCORE: 10,
    ADMIN_SETTINGS_SEARCH_HIGHLIGHT_MS: 1800,
    ADMIN_NAVDATA_COPY_FEEDBACK_MS: 1600,
    ADMIN_NAVDATA_LOOKUP_MIN_CHARACTERS: 2,
    ADMIN_NAVDATA_SUBMIT_FEEDBACK_MS: 250,
    ADMIN_GALLERY_REPORT_DEFAULT_TELEMETRY_DAYS: 30,
    ADMIN_GALLERY_REPORT_MIN_TELEMETRY_DAYS: 1,
    ADMIN_GALLERY_REPORT_MAX_TELEMETRY_DAYS: 3650,
    ADMIN_GALLERY_REPORT_REQUEST_ATTEMPT_LIMIT: 4,
    ADMIN_GALLERY_REPORT_RETRY_BASE_DELAY_MS: 750,
    ADMIN_GALLERY_REPORT_RETRY_BACKOFF_FACTOR: 2,
    ADMIN_GALLERY_REPORT_RETRYABLE_HTTP_STATUSES: [408, 429, 500, 502, 503, 504],
};
assert.deepEqual({...policy}, expected, 'Only the reviewed public values belong in this owner');
for (const name of Object.keys(expected)) {
    const definition = policySource.match(new RegExp('/\\*\\*([^]*?)\\*/\\s*export const ' + name + ' ='));
    assert.ok(definition, name + ' has attached documentation');
    const comment = definition[1].split('/**').at(-1);
    const typeTag = Array.isArray(expected[name]) ? '@var {ReadonlyArray<number>}' : '@var {number}';
    for (const field of [typeTag, 'Units:', 'Scope:', 'Consumers:', 'Rationale:', 'Range:']) {
        assert.ok(comment.includes(field), name + ' documents ' + field);
    }
}
assert.doesNotMatch(policySource.replace(/\/\*[^]*?\*\//g, ''), /\bimport\s|\bfetch\s*\(|\bdocument\./,
    'Policy has no runtime/configuration dependency');
assert.equal(pickerPolicy.GALLERY_PICKER_SEARCH_DEBOUNCE_MS, 200);
assert.equal(pickerPolicy.GALLERY_PICKER_DISPLAY_DEPTH_LIMIT, 8);
assert.ok(pickerPolicy.GALLERY_PICKER_POSITIVE_ID_PATTERN.test('9007199254740993'));
assert.ok(!pickerPolicy.GALLERY_PICKER_POSITIVE_ID_PATTERN.test('1e3'));

/**
 * Minimal disposable DOM node; production modules own behavior, this seam only records effects.
 * query/queries map selector strings to child fixtures; handlers stores one callback per event.
 * dataset/attributes mirror scalar DOM state; classes records CSS class membership.
 * @property {Record<string,string>} dataset Synthetic production data attributes.
 * @property {Map<string,FixtureElement>} query Exact single-child selector mapping.
 * @property {Map<string,FixtureElement[]>} queries Exact child-list selector mapping.
 * @property {Map<string,Function>} handlers Captured listeners invoked only by this fixture.
 * @property {Map<string,string>} attributes Observable accessible/HTML attributes.
 * @property {Set<string>} classes Observable class membership.
 * @property {boolean} hidden Current fixture visibility.
 * @property {string} value Synthetic input value.
 * @property {string} textContent Synthetic visible text.
 */
class FixtureElement {
    /**
     * Create an empty element with no application data or browser resources.
     * @return {void} Initializes isolated lookup maps and observable element state.
     */
    constructor() {
        this.dataset = {};
        this.query = new Map();
        this.queries = new Map();
        this.handlers = new Map();
        this.attributes = new Map();
        this.classes = new Set();
        this.classList = {
            /** Record requested class additions. @param {...string} names Added class tokens. @return {void} Records classes. */
            add: (...names) => { for (const name of names) this.classes.add(name); },
            /** Record requested class removals. @param {...string} names Removed class tokens. @return {void} Removes classes. */
            remove: (...names) => { for (const name of names) this.classes.delete(name); },
            /** Apply the desired class membership. @param {string} name Class token. @param {boolean} active Desired membership. @return {void} Records membership. */
            toggle: (name, active) => { if (active) this.classes.add(name); else this.classes.delete(name); },
        };
        this.hidden = false;
        this.value = '';
        this.textContent = '';
        this.style = {};
    }
    /** Resolve one explicitly registered child. @param {string} selector Exact fixture selector. @return {FixtureElement|null} Registered child. */
    querySelector(selector) { return this.query.get(selector) || null; }
    /** Resolve the prepared list of children. @param {string} selector Exact fixture selector. @return {FixtureElement[]} Registered children. */
    querySelectorAll(selector) { return this.queries.get(selector) || []; }
    /** Capture a production listener for explicit invocation. @param {string} name Event type. @param {Function} callback Production listener. @return {void} Registers listener. */
    addEventListener(name, callback) { this.handlers.set(name, callback); }
    /** Record a production DOM attribute assignment. @param {string} name Attribute name. @param {string} value Attribute value. @return {void} Records attribute. */
    setAttribute(name, value) { this.attributes.set(name, value); }
    /** Remove an attribute from isolated element state. @param {string} name Attribute name. @return {void} Removes attribute. */
    removeAttribute(name) { this.attributes.delete(name); }
    /** Observe scrolling without browser layout. @return {void} Records that the production code requested scrolling. */
    scrollIntoView() { this.scrolled = true; }
    /** Observe the requested focus destination. @return {void} Records focus without creating a real browser. */
    focus() { this.focused = true; }
    /** Observe activation of a local Settings tab. @return {void} Records tab activation. */
    click() { this.clicked = true; }
    /** Keep pointer containment local in this fixture. @return {boolean} Treats fixture event targets as local. */
    contains() { return true; }
    /** Capture submission without issuing HTTP. @return {void} Records ordinary navigation submission without performing it. */
    submit() { this.submitted = true; }
}

/**
 * Load one current consumer into an isolated timer/DOM seam with its real policy source.
 * @param {string} filename Owned production module name.
 * @param {string[]} names Functions exposed only to this test, without changing production exports.
 * @return {Promise<{api:Record<string,Function>,document:FixtureElement,timers:Map<number,{callback:Function,delay:number}>,frames:Function[],window:object,context:object}>} Disposable environment, replaceable stub context and recorded work.
 */
async function consumer(filename, names) {
    const source = await readFile(path.join(directory, filename), 'utf8');
    assert.match(source, /from '\.\/admin-interaction-policy\.js\?v=20260920-admin-interaction-policy-v1'/);
    const document = new FixtureElement();
    document.documentElement = new FixtureElement();
    document.body = new FixtureElement();
    document.activeElement = null;
    document.getElementById =
    /** Expose no destination before fixture registration. @return {null} Missing target. */
    () => null;
    const timers = new Map();
    const frames = [];
    let serial = 0;
    const window = {
        location: new URL('http://fixture.invalid/admin'),
        /** Queue production work for explicit timer advancement. @param {Function} callback Deferred work. @param {number} delay Milliseconds. @return {number} Fixture handle. */
        setTimeout(callback, delay) { timers.set(++serial, {callback, delay}); return serial; },
        /** Capture a production paint-frame callback. @param {Function} callback Paint work. @return {number} Fixture frame handle. */
        requestAnimationFrame(callback) { frames.push(callback); return frames.length; },
        /** Accept the existing confirmation in the synthetic form. @return {boolean} Allows existing fixture-only confirmation. */
        confirm() { return true; },
    };
    const context = {
        window, document, URL, AbortController, console, Intl, Event,
        HTMLInputElement: FixtureElement, HTMLElement: FixtureElement, HTMLSelectElement: FixtureElement,
        HTMLButtonElement: FixtureElement, HTMLAnchorElement: FixtureElement, HTMLFormElement: FixtureElement,
        HTMLTextAreaElement: FixtureElement, Element: FixtureElement,
        CSS: {
            /** Preserve the safe fixture token. @param {string} value Selector token. @return {string} Same token. */
            escape: value => value,
        },
        requestAnimationFrame: window.requestAnimationFrame,
        /** Remove the cancelled timer from the fixture queue. @param {number} handle Pending fixture timer. @return {void} Cancels recorded work. */
        clearTimeout(handle) { timers.delete(handle); },
        /** Return the public fallback without loading language files. @param {string} key Translation key. @param {string} fallback Public fallback. @return {string} Fallback text. */
        i18n(key, fallback) { return fallback; },
        /** Supply an empty response without network access. @return {Promise<object>} Empty synthetic lookup response. */
        fetch: async () => ({
            ok: true,
            /** Decode an empty synthetic response. @return {Promise<{ok:boolean,candidates:object[]}>} Empty results. */
            json: async () => ({ok: true, candidates: []}),
        }),
    };
    const body = source.replace(/^import\s+[^]*?\sfrom\s+['"][^'"]+['"];\s*/gm, '').replace(/^export /gm, '');
    const api = vm.runInNewContext(policySource.replace(/^export /gm, '') + '\n' + body + '\n({' + names.join(',') + '})', context);
    return {api, document, timers, frames, window, context};
}

// Title admission, debounce, abort deadline and result cap execute the actual request owner.
const title = await consumer('admin-gallery-title-completion.js', ['requestGalleryTitleCompletion', 'findGalleryTitleCompletion']);
const control = new FixtureElement();
control.dataset.galleryTitleCompletionUrl = '/complete';
const input = new FixtureElement();
input.isConnected = true;
input.closest =
/** Resolve the input's synthetic ancestors. @param {string} selector Ancestor selector. @return {FixtureElement} Ancestor. */
selector => selector === 'form' ? new FixtureElement() : control;
title.document.activeElement = input;
title.context.fetch =
/** Return oversized candidates to exercise the client guard. @return {Promise<{ok:boolean,json:Function}>} Stub response. */
async () => ({
    ok: true,
    /** Decode the synthetic excess results. @return {Promise<{ok:boolean,candidates:{id:number,title:string}[]}>} Candidate rows. */
    json: async () => ({ok: true, candidates: Array.from({length: 20},
        /** Build one disposable candidate. @param {undefined} unused Empty array slot. @param {number} id Fixture index. @return {{id:number,title:string}} Candidate. */
        (unused, id) => ({id, title: 'Flight ' + id}))}),
});
/**
 * Set an entered title with a collapsed end caret and invoke production scheduling.
 * @param {string} value Synthetic entered title.
 * @return {void} Replaces the current request intent.
 */
function requestTitle(value) {
    input.value = value;
    input.selectionStart = input.selectionEnd = value.length;
    title.api.requestGalleryTitleCompletion(input);
}
requestTitle('F');
assert.equal(title.timers.size, 0, 'One code point does not issue a request');
requestTitle('x'.repeat(256));
assert.equal(title.timers.size, 0, 'Overlength prefix does not issue a request');
requestTitle('😀');
assert.equal(title.timers.size, 0, 'Admission counts code points, not UTF-16 units');
requestTitle('Fl');
const scheduledTitle = [...title.timers.values()][0];
assert.equal(scheduledTitle.delay, 180);
title.timers.clear();
const pendingTitle = scheduledTitle.callback();
assert.equal([...title.timers.values()][0].delay, 5000, 'Abort deadline uses the same units/value');
await pendingTitle;
assert.equal(control.__galleryTitleCompletionCandidates.length, 8);
assert.equal(title.timers.size, 0, 'Successful response clears its abort timer');
assert.equal(title.api.findGalleryTitleCompletion([{title: 'Flight'}], 'F'), '');
assert.equal(title.api.findGalleryTitleCompletion([{title: 'Flight'}], 'Fl'), 'Flight');

// Settings runs its actual closed-over scoring and bounded result replacement.
const settings = await consumer('admin-settings-search.js', ['setupAdminSettingsSearch']);
const search = new FixtureElement();
const searchInput = new FixtureElement();
const results = new FixtureElement();
const status = new FixtureElement();
const clear = new FixtureElement();
search.query.set('[data-admin-settings-search-input]', searchInput);
search.query.set('[data-admin-settings-search-results]', results);
search.query.set('[data-admin-settings-search-status]', status);
search.query.set('[data-admin-settings-search-clear]', clear);
const labels = ['ZZZ', 'xneedle', 'x needle', 'needle'];
const items = labels.concat(Array.from({length: 15},
    /** Create a description-only match label. @param {undefined} unused Empty slot. @param {number} index Fixture index. @return {string} Test label. */
    (unused, index) => 'z' + index)).map(
    /**
     * Prepare one Settings result using production data attributes.
     * @param {string} label Synthetic relevance class.
     * @param {number} index Unique result index.
     * @return {FixtureElement} Result node with no external destination.
     */
    (label, index) => {
    const item = new FixtureElement();
    item.id = 'setting-' + index;
    item.dataset = {searchLabel: label, searchText: label + ' needle', searchTarget: 'target'};
    return item;
});
search.queries.set('[data-admin-settings-search-result]', items);
settings.document.queries.set('[data-admin-settings-search]', [search]);
const target = new FixtureElement();
settings.document.getElementById =
/** Resolve the sole local Settings destination. @return {FixtureElement} Highlight target. */
() => target;
settings.api.setupAdminSettingsSearch(settings.document);
searchInput.value = 'needle';
searchInput.handlers.get('input')();
assert.equal(items.filter(
    /** Count the visible result set. @param {FixtureElement} item Result node. @return {boolean} Whether visible. */
    item => !item.hidden).length, 12);
assert.equal(searchInput.attributes.get('aria-activedescendant'), 'setting-3', 'Prefix outranks word/interior/description');
/**
 * Prepare a disposable event for a production listener.
 * @param {string} key Keyboard action under test.
 * @return {{key:string,preventDefault:Function}} Event without a browser default action.
 */
const keyEvent = key => ({
    key,
    /** Suppress the absent native default action. @return {void} No navigation occurs. */
    preventDefault() {},
});
searchInput.handlers.get('keydown')(keyEvent('ArrowDown'));
assert.equal(searchInput.attributes.get('aria-activedescendant'), 'setting-2');
searchInput.handlers.get('keydown')(keyEvent('ArrowDown'));
assert.equal(searchInput.attributes.get('aria-activedescendant'), 'setting-1');
searchInput.handlers.get('keydown')(keyEvent('Enter'));
settings.frames.shift()();
assert.ok(target.focused && target.scrolled && target.classes.has('is-search-highlighted'));
const highlight = [...settings.timers.values()][0];
assert.equal(highlight.delay, 1800);
highlight.callback();
assert.ok(!target.classes.has('is-search-highlighted'));

// Navigation diagnostics preserve their clipboard feedback and short-input refusal.
const navigation = await consumer('admin-navdata-panel.js', ['showNavigationDataButtonFeedback', 'runNavigationDataLookup']);
const copy = new FixtureElement();
copy.textContent = 'Copy';
navigation.api.showNavigationDataButtonFeedback(copy, true);
assert.equal(copy.textContent, 'Copied');
const feedback = [...navigation.timers.values()][0];
assert.equal(feedback.delay, 1600);
feedback.callback();
assert.equal(copy.textContent, 'Copy');
const lookup = new FixtureElement();
const lookupInput = new FixtureElement();
const lookupResult = new FixtureElement();
lookupInput.value = 'A';
lookup.query.set('input[name="ident"]', lookupInput);
lookup.query.set('[data-admin-navdata-lookup-result]', lookupResult);
await navigation.api.runNavigationDataLookup(lookup);
assert.equal(lookupResult.textContent, 'Enter at least two characters.');

// The import form keeps exactly two paint frames, then its existing short delay.
const update = await consumer('admin-navdata-update.js', ['setupAdminNavdataUpdateFeedback']);
const form = new FixtureElement();
update.document.queries.set('[data-navdata-update-form]', [form]);
update.api.setupAdminNavdataUpdateFeedback();
form.handlers.get('submit')(keyEvent(''));
assert.equal(update.frames.length, 1);
assert.equal(update.timers.size, 0);
update.frames.shift()();
assert.equal(update.frames.length, 1);
assert.equal(update.timers.size, 0);
update.frames.shift()();
const submit = [...update.timers.values()][0];
assert.equal(submit.delay, 250);
assert.ok(!form.submitted);
submit.callback();
assert.ok(form.submitted);

const entry = await readFile(path.join(root, 'public/assets/gallery.js'), 'utf8');
for (const name of ['admin-settings-search', 'admin-navdata-panel']) {
    assert.ok(entry.includes(name + '.js?v=20260920-admin-interaction-policy-v1'));
}
assert.ok(entry.includes('admin-gallery-title-completion.js?v=20260920-gallery-title-completion-policy-v4'));
assert.ok(entry.includes('admin-operations.js?v=20260925-editor-tabs-v2'));
const operations = await readFile(path.join(directory, 'admin-operations.js'), 'utf8');
assert.ok(operations.includes('admin-navdata-update.js?v=20260920-admin-interaction-policy-v1'));
console.log('frontend_operational_policy_test: PASS (21 unchanged policy values, title/Settings/navigation consumer seams; report runtime has a separate fixture; not browser acceptance)');
