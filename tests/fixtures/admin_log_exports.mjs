/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_log_exports.mjs
 * Module Type: Browser Contract Fixture
 * Purpose: Exercise the production log export handlers at their browser boundaries.
 * Responsibilities:
 *   - Preserve native clipboard transfer, permission fallback and truthful failure feedback.
 *   - Keep delegated controls active after live row replacement without duplicate listeners.
 *   - Allow native HTTP downloads instead of cancelling them for unsupported Blob URLs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const labels = {selection_count: '{count} selected', select_first: 'Select a log.', copying: 'Copying', copied: 'Copied',
    preparing: 'Download requested', export_failed: 'Export failed', manual_copy: 'Copy manually', copy_logs: 'Copy logs', close: 'Close'};
const makeNode = (selectors = []) => {
    const listeners = new Map();
    const attributes = new Map();
    const node = {dataset: {}, disabled: false, hidden: false, checked: false, value: '', textContent: '', children: [], listeners,
        matches: selector => selector.split(',').some(part => selectors.includes(part.trim())),
        closest: selector => node.matches(selector) ? node : null,
        getAttribute: name => attributes.get(name) ?? null,
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: name => attributes.delete(name),
        querySelector: () => null, querySelectorAll: () => [],
        addEventListener: (name, listener) => listeners.set(name, [...(listeners.get(name) || []), listener]),
        append: (...children) => node.children.push(...children), remove: () => {},
        focus: () => {document.activeElement = node;}, select: () => {selectedText = node.value;},
        showModal: () => {openedDialog = node;}, close: () => {}};
    return node;
};
let selectedText = '';
let copiedText = '';
let openedDialog = null;
let allowNative = true;
let allowAsync = false;
let fetchOk = true;
let asyncCalls = 0;
let currentChoices = [makeNode(['[data-admin-log-export-choice]'])];
currentChoices[0].checked = true;
currentChoices[0].value = '10';
currentChoices[0].dataset.exportToken = '10';
const scope = makeNode(['[data-admin-log-export-scope]']);
scope.value = 'selected';
const state = makeNode();
const count = makeNode();
const pageSelector = makeNode(['[data-admin-log-select-page]']);
const copy = makeNode(['[data-admin-log-copy-selection]']);
const save = makeNode(['[data-admin-log-save-selection]']);
const toolbar = makeNode();
toolbar.dataset = {labels: JSON.stringify(labels), exportUrl: '/?page=admin_log_export'};
toolbar.querySelector = selector => selector.includes('scope') ? scope : state;
toolbar.querySelectorAll = () => [copy, save];
const form = makeNode();
form.querySelector = () => form.children.find(child => child.name === 'export_tokens_json') || null;
const results = makeNode();
results.querySelector = selector => selector.includes('select-page') ? pageSelector : selector.includes('selection-count') ? count : currentChoices.find(choice => choice.checked) || null;
results.querySelectorAll = selector => selector.includes('copy-url') ? [copy] : selector.includes('export-choice') ? currentChoices : [];
const document = {activeElement: null, body: makeNode(),
    querySelector: selector => selector.includes('export-toolbar') ? toolbar : selector.includes('log-results') ? results : null,
    getElementById: () => form,
    createElement: () => makeNode(),
    execCommand: command => {
        assert.equal(command, 'copy');
        if (allowNative) copiedText = selectedText;
        return allowNative;
    }};
const requests = [];
const context = vm.createContext({document, console, HTMLElement: Object, WeakMap, JSON, Array, Object,
    FormData: function () { return []; },
    fetch: async (url, options) => {
        requests.push({url, options});
        return {ok: fetchOk, headers: {get: () => 'text/plain; charset=utf-8'}, text: async () => 'Complete Unicode context: Příliš žluťoučký'};
    },
    navigator: {clipboard: {writeText: async text => {
        asyncCalls++;
        if (!allowAsync) throw new Error('Clipboard permission denied');
        copiedText = text;
    }}}});
const source = readFileSync('public/assets/gallery-modules/admin-logs.js', 'utf8').replace(/^import .*;$/m, '').replaceAll('export function ', 'function ');
vm.runInContext(source, context);
vm.runInContext('setupAdminLogExports()', context);
const click = async target => {
    let prevented = false;
    for (const listener of results.listeners.get('click')) listener({target, preventDefault: () => {prevented = true;}});
    await new Promise(setImmediate);
    return prevented;
};
assert.equal(await click(copy), true);
assert.equal(copiedText, 'Complete Unicode context: Příliš žluťoučký');
assert.equal(asyncCalls, 0, 'Native clipboard transfer must not silently rely on a browser-session-only Clipboard API.');
assert.equal(state.textContent, labels.copied);
assert.equal(requests[0].options.method, 'POST');

allowNative = false;
allowAsync = true;
await click(copy);
assert.equal(asyncCalls, 1, 'The standard Clipboard API remains available when native copying is unavailable.');
allowAsync = false;
await click(copy);
assert.ok(openedDialog, 'Denied clipboard access must expose complete manually selectable text.');
assert.equal(state.textContent, labels.manual_copy);
assert.ok(openedDialog.children.some(child => child.value === 'Complete Unicode context: Příliš žluťoučký'));
fetchOk = false;
await click(copy);
assert.equal(state.textContent, labels.export_failed, 'An HTTP failure must never be reported as a successful copy.');
fetchOk = true;
allowNative = true;

const replacement = makeNode(['[data-admin-log-copy-url]']);
replacement.dataset.adminLogCopyUrl = '/?page=admin_log_export&id=11';
vm.runInContext('setupAdminLogExports()', context);
assert.equal(results.listeners.get('click').length, 1, 'Reinitializing live rows must not duplicate export requests.');
await click(replacement);
assert.equal(requests.at(-1).url, replacement.dataset.adminLogCopyUrl, 'Newly rendered row controls must use delegated handling.');
const download = makeNode(['[data-admin-log-save-url]']);
assert.equal(await click(download), false, 'The browser must handle native file downloads without a cancelled default action.');
let preventedSave = false;
for (const listener of form.listeners.get('submit')) listener({submitter: save, preventDefault: () => {preventedSave = true;}});
assert.equal(preventedSave, false, 'Scoped downloads must retain their native POST response.');
console.log('Admin log export browser contracts: PASS');
