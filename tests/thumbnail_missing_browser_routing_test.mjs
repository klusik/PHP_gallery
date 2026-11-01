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
console.log('Targeted thumbnails respect browser/server choice: PASS');
