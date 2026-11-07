/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/thumbnail_missing_browser_routing_test.mjs
 * Module Type: Regression Test
 * Purpose: Keep targeted thumbnail creation in the explicitly selected engine.
 * Responsibilities: Drive the production delegated click handler and verify
 *     browser/server choice, missing scope, errors and duplicate admission.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

const source = await readFile(new URL(
    '../public/assets/gallery-modules/admin-thumbnail-progress.js',
    import.meta.url,
), 'utf8');
const start = source.indexOf('export function setupThumbnailProgress()');
const end = source.indexOf('async function runThumbnailMaintenanceCheck', start);
assert.ok(start >= 0 && end > start, 'Production action owner must exist.');
const declarations = source.slice(start, end).replace('export function', 'function');

/** Model only the native type identities used by the delegated action. */
class FixtureElement {}
/** Model the form identity without loading unrelated thumbnail transports. */
class FixtureForm extends FixtureElement {}
/** Model the disabled state owned by the native action. */
class FixtureButton extends FixtureElement {}

for (const useBrowser of [true, false]) {
    for (const fail of [false, true]) {
        const calls = [];
        const handlers = new Map();
        const form = new FixtureForm();
        const button = new FixtureButton();
        const progress = {};
        const errors = [];
        button.disabled = false;
        button.closest = selector => selector === 'form' ? form : null;
        const target = new FixtureElement();
        target.closest = selector => selector === '[data-create-missing-thumbnails]'
            ? button : null;
        let release;
        const pending = new Promise(resolve => { release = resolve; });
        const context = vm.createContext({
            Element: FixtureElement,
            HTMLFormElement: FixtureForm,
            HTMLButtonElement: FixtureButton,
            Error,
            document: {addEventListener: (kind, handler) => {
                if (!handlers.has(kind)) handlers.set(kind, handler);
            }},
            browserThumbnailRebuildRequested: candidate => {
                assert.equal(candidate, form);
                return useBrowser;
            },
            ensureThumbnailProgress: candidate => {
                assert.equal(candidate, form);
                return progress;
            },
            runBrowserThumbnailRebuild: async (candidate, node, options) => {
                assert.equal(candidate, form);
                assert.equal(node, progress);
                assert.equal(options.scope, 'missing');
                calls.push('browser');
                await pending;
                if (fail) throw new Error('Browser decoder unavailable');
            },
            runThumbnailJob: async (candidate, submitter, options) => {
                assert.equal(candidate, form);
                assert.equal(submitter, null);
                assert.equal(options.scope, 'missing');
                calls.push('server');
                await pending;
                // The server owner deliberately keeps an exhausted queue disabled.
                button.disabled = true;
                if (fail) throw new Error('Server decoder unavailable');
            },
            updateThumbnailProgress: (node, ...fields) => {
                assert.equal(node, progress);
                errors.push(fields.at(-1));
            },
        });
        vm.runInContext(declarations + '\nsetupThumbnailProgress();', context);
        const click = handlers.get('click');
        let prevented = false;
        const event = {target, preventDefault: () => { prevented = true; }};
        const operation = click(event);
        assert.equal(prevented, true);
        assert.equal(button.disabled, true);
        await click(event);
        assert.deepEqual(calls, [useBrowser ? 'browser' : 'server']);
        release();
        await operation;
        assert.equal(button.disabled, !useBrowser);
        assert.equal(errors.length, fail ? 1 : 0);
        if (fail) assert.match(errors[0], /decoder unavailable/);
        assert.deepEqual(calls, [useBrowser ? 'browser' : 'server'],
            'A browser failure must never silently start server generation.');
    }
}

// Exercise the real import workflow with an isolated fetch boundary. The
// literal strings are synthetic responses, never HTML to be interpreted.
const importStart = source.indexOf('async function runImportWithThumbnailProgress(form)');
const importEnd = source.indexOf('function discoveryImportFinishedMessage', importStart);
assert.ok(importStart >= 0 && importEnd > importStart, 'Production import owner must exist.');
const importDeclaration = source.slice(importStart, importEnd);

/** Keep only the form fields needed by the isolated import workflow. */
class FixtureImportData {
    /** Accept synthetic form fields without publishing them.
     * @param {HTMLFormElement | undefined} form Optional fixture form.
     * @returns {void} Keeps only isolated fixture state.
     */
    constructor(form) { this.form = form; }
    /** Accept a synthetic replacement field.
     * @param {string} name Field name.
     * @param {string} value Field value.
     * @returns {void} Does not contact a server.
     */
    set(name, value) { void name; void value; }
    /** Accept a synthetic repeated field.
     * @param {string} name Field name.
     * @param {string} value Field value.
     * @returns {void} Does not contact a server.
     */
    append(name, value) { void name; void value; }
}

for (const scenario of ['refused', 'thumbnail_failure']) {
    const button = {disabled: false};
    const fill = {style: {display: ''}};
    const classes = new Set();
    const label = {
        textContent: '', role: '',
        classList: {
            add: name => classes.add(name),
            remove: name => classes.delete(name),
        },
        setAttribute: (name, value) => { if (name === 'role') label.role = value; },
    };
    const progress = {querySelector: selector =>
        selector === '[data-thumbnail-progress-fill]' ? fill : label};
    const form = {
        action: '/fixture-import',
        querySelectorAll: () => [button],
        querySelector: () => null,
    };
    const message = '<synthetic refusal> Keep the existing attempt.';
    const requests = [];
    const updates = [];
    const context = vm.createContext({
        Error, FormData: FixtureImportData,
        window: {location: {href: '/fixture'}},
        ensureThumbnailProgress: () => progress,
        i18n: (_key, fallback) => fallback,
        thumbnailEndpoint: () => '/fixture-thumbnails',
        discoveryThumbnailRunningMessage: () => 'Working',
        discoveryImportFinishedMessage: () => 'Finished',
        adminUrlWithParams: () => '/fixture-complete',
        updateThumbnailProgress: (...args) => updates.push(args),
        fetch: async (url, options) => {
            requests.push({url, method: options.method});
            if (requests.length === 1 && scenario === 'refused') {
                return {ok: true, json: async () => ({ok: false, error: message})};
            }
            if (requests.length === 1) {
                return {ok: true, json: async () => ({ok: true, gallery_ids: [1], scanned: 1})};
            }
            return {ok: true, json: async () => ({ok: false, error: message})};
        },
    });
    vm.runInContext(importDeclaration, context);
    const operation = context.runImportWithThumbnailProgress(form);
    assert.equal(fill.style.display, 'none', 'Pending import has no fabricated percentage.');
    assert.equal(button.disabled, true);
    await operation;
    assert.equal(fill.style.display, 'none', 'Refusal must not show a full progress bar.');
    assert.equal(label.textContent, message, 'Display the exact error as text.');
    assert.equal(label.role, 'alert');
    assert.equal(classes.has('notice'), true);
    assert.equal(classes.has('muted'), false, 'Refusal must remain readable.');
    assert.equal(button.disabled, false);
    assert.equal(requests.length, scenario === 'refused' ? 1 : 2);
    assert.equal(updates.length, 0, 'A refusal must not fabricate a 0/0 summary.');
    assert.equal(context.window.location.href, '/fixture');
}
console.log('Targeted thumbnails respect browser/server choice: PASS');
