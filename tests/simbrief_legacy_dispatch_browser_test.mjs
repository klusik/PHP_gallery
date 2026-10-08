/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/simbrief_legacy_dispatch_browser_test.mjs
 * Module Type: Standalone Browser-Model Regression Test
 *
 * Purpose:
 *   Verify legacy SimBrief redirect encoding and negative cases without
 *   a real SimBrief account, browser, database or network.
 *
 * Responsibilities:
 *   - Preserve reviewed inputs exactly across official query serialization
 *   - Reject invalid airport, UTC time, route and historical-date combinations
 *   - Keep unsupported options and unchecked defaults out of the redirect
 *
 * Author:
 *   Rudolf Klusal
 *
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

/**
 * Import pure model functions from the production browser module.
 *
 * The admin-core translation import depends on a browser DOM. Replace only
 * that top-level import with a no-op translation fallback for this model test.
 *
 * @returns {Promise<object>} Two real production URL/date helper functions.
 */
async function loadDispatchModel() {
    const path = fileURLToPath(new URL('../public/assets/gallery-modules/admin-simbrief-description.js', import.meta.url));
    const raw = readFileSync(path, 'utf8');
    assert.match(raw, /export function buildSimbriefDispatchRedirectUrl\(/);
    assert.match(raw, /export function simbriefDispatchEncodeDate\(/);
    const source = raw.replace(
        /^import \{ i18nForElement \} from .*;\s*$/m,
        'const i18nForElement = (_element, _key, fallback) => fallback;',
    );
    assert.notEqual(raw, source, 'Browser translation import must be replaced in model fixture.');
    const url = 'data:text/javascript;base64,' + Buffer.from(source).toString('base64');
    return import(url);
}

/**
 * A tiny fake input lets the real production serializer keep its same type
 * check while avoiding a DOM dependency in the Node regression runner.
 *
 * @param {string} value Field value.
 */
class MockInput {
    /**
     * Construct a test-only input with a concrete string value.
     * @param {string} value Text entered in the dispatch form.
     * @returns {void} Initialize a single fake input instance.
     */
    constructor(value) {
        this.value = value;
    }
}
globalThis.HTMLInputElement = MockInput;

/**
 * Build the minimal native-HTMLFormElement-like interface used by the pure
 * redirect model, without simulating any browser form submission.
 *
 * @param {Record<string,string>} fields Explicit admin-edited fields.
 * @returns {{elements:{namedItem:Function}}} Form exposing one named input accessor.
 */
function makeForm(fields) {
    return {
        elements: {
            /**
             * Return the typed fake input for one supported form control.
             * @param {string} key Input name.
             * @returns {MockInput|null} Field value or null when absent.
             */
            namedItem(key) {
                return Object.hasOwn(fields, key) ? new MockInput(fields[key]) : null;
            },
        },
    };
}

/**
 * Assert a malformed reviewed value fails before any external navigation.
 *
 * @param {Function} build Production URL function.
 * @param {Record<string,string>} fields Explicit entered values.
 * @param {RegExp} message Required validation error family.
 * @returns {void} Assert the production function rejects an invalid form.
 */
function rejects(build, fields, message) {
    assert.throws(() => build(makeForm(fields)), message);
}

const {buildSimbriefDispatchRedirectUrl: build, simbriefDispatchEncodeDate: encodeDate} = await loadDispatchModel();

assert.equal(encodeDate('2026-06-03'), '03JUN26');
assert.equal(encodeDate('2024-02-29'), '29FEB24');
assert.equal(encodeDate(''), '');
assert.throws(() => encodeDate('2026-02-30'), /date/i);
assert.throws(() => encodeDate('1999-12-31'), /date/i);
assert.throws(() => encodeDate('2026-13-01'), /date/i);

const complete = new URL(build(makeForm({
    orig: 'lkpr', dest: 'essa', date: '2026-06-03',
    deph: '7', depm: '20', type: 'a320', airline: 'csa', fltnum: '437',
    callsign: 'csa437', reg: 'ok-hea', route: 'dct okL+ dct',
    fl: '350', altn: 'eskn', pax: '162', unsupported: 'dangerous',
})));
assert.equal(complete.origin, 'https://dispatch.simbrief.com');
assert.equal(complete.pathname, '/options/custom');
assert.equal(complete.searchParams.get('orig'), 'LKPR');
assert.equal(complete.searchParams.get('dest'), 'ESSA');
assert.equal(complete.searchParams.get('date'), '03JUN26');
assert.equal(complete.searchParams.get('deph'), '7');
assert.equal(complete.searchParams.get('depm'), '20');
assert.equal(complete.searchParams.get('route'), 'DCT OKL+ DCT');
assert.equal(complete.searchParams.get('type'), 'A320');
assert.equal(complete.searchParams.get('fl'), '350');
assert.equal(complete.searchParams.get('reg'), 'OK-HEA');
assert.equal(complete.searchParams.get('unsupported'), null);
assert.match(complete.href, /route=DCT\+OKL%2B\+DCT/);

const minimum = new URL(build(makeForm({orig: 'LKPR', dest: 'ESSA'})));
assert.deepEqual([...minimum.searchParams.keys()], ['orig', 'dest']);
rejects(build, {orig: 'LKPR', dest: 'ESS'}, /ICAO/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', deph: '10'}, /hour and minute/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', deph: '25', depm: '00'}, /hour/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', deph: '10', depm: '60'}, /minute/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', fl: '650'}, /FL600/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', date: '2026-02-30'}, /date/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', route: 'DCT&dest=EVIL'}, /route/i);
rejects(build, {orig: 'LKPR', dest: 'ESSA', route: '<script>alert(1)</script>'}, /route/i);

const browserSource = readFileSync(fileURLToPath(
    new URL('../public/assets/gallery-modules/admin-simbrief-description.js', import.meta.url),
), 'utf8');
assert.match(browserSource, /dialog\.showModal\(\)/);
assert.match(browserSource, /event\.preventDefault\(\)/);
assert.match(browserSource, /window\.open\(url, '_blank', 'noopener,noreferrer'\)/);
assert.match(browserSource, /if \(form\.dataset\.pending === '1'\) return;/);

console.log('Legacy SimBrief reviewed dispatch URL and date contracts: PASS');
