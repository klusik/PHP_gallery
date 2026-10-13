/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_upload_zip_worker/icloud_source.mjs
 * Module Type: Regression Test
 * Purpose: Exercise source recognition, form lifecycle and localization without live Apple access.
 * Responsibilities:
 *   - Refuse unsafe URLs and preserve mounted/re-rendered upload ownership.
 *   - Verify four catalogs and language-specific manual source coverage.
 *   - Propagate every failure to the registered browser_upload_zip_worker_test.mjs owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/**
 * Describe a control stub used only by this deterministic DOM fixture.
 * @typedef {Object} FixtureControl
 * @property {Record<string,string>} dataset Prepared labels and product metadata.
 * @property {string} value Input value.
 * @property {boolean} hidden Visibility state.
 * @property {string} textContent Localized status text.
 * @property {File[]} files Immutable selected references.
 * @property {boolean} checked Browser preparation switch.
 * @property {Map<string,Array<function():void>>} listeners Installed handlers by event type.
 * @property {Map<string,FixtureControl>} children Exact selector-to-control map.
 * @property {FixtureControl[]} products Product-specific instructions.
 * @property {function(string,function():void):void} addEventListener Register a handler.
 * @property {function(string):FixtureControl|null} querySelector Resolve an exact descendant.
 * @property {function(string):FixtureControl[]} querySelectorAll Resolve product instructions.
 */
/**
 * Construct one form/control stub supporting the source adapter's DOM contract.
 * @param {Record<string,string>} dataset Prepared presentation labels.
 * @returns {FixtureControl} Control with observable handlers and descendants.
 */
function control(dataset = {}) {
    const item = {dataset, value: '', hidden: false, textContent: '', files: [], checked: true, listeners: new Map(), children: new Map(), products: []};
    item.addEventListener = (type, callback) => item.listeners.set(type, [...(item.listeners.get(type) || []), callback]);
    item.querySelector = selector => item.children.get(selector) || null;
    item.querySelectorAll = selector => selector === '[data-icloud-product]' ? item.products : [];
    return item;
}

/**
 * Mount an independent upload form without granting any Apple/network access.
 * @returns {FixtureControl} Form containing the complete source adapter controls.
 */
function uploadForm() {
    const form = control();
    const root = control({album: 'Public Shared Album.', link: 'Photos Link.', assisted: 'Assisted; availability unchecked.', invalid: 'Invalid link.', filesRequired: 'Choose supported files.', browserRequired: 'Browser required.', privateRequired: 'Private required.'});
    form.children.set('[data-icloud-source]', root);
    for (const name of ['url', 'status', 'guidance', 'open', 'validate', 'clear', 'cancel', 'retry']) {
        const child = control();
        root.children.set(`[data-icloud-${name}]`, child);
        form.children.set(`[data-icloud-${name}]`, child);
    }
    root.children.get('[data-icloud-guidance]').products = ['photos-link', 'shared-album'].map(icloudProduct => control({icloudProduct}));
    for (const selector of ['input[name="images[]"]', '[data-browser-upload-toggle]', '[name="upload_mode"]', '[name="visibility"]']) form.children.set(selector, control());
    form.children.get('[name="upload_mode"]').value = 'existing';
    return form;
}

/**
 * Dispatch a fixture event to all installed callbacks.
 * @param {FixtureControl} target Original control.
 * @param {string} type Event type to dispatch.
 * @returns {void} Executes observable handlers synchronously.
 */
function fire(target, type) {
    for (const listener of target.listeners.get(type) || []) listener();
}

const source = await readFile(new URL('../../public/assets/gallery-modules/admin-icloud-source.js', import.meta.url), 'utf8');
let forms = [uploadForm()];
const openings = [];
const context = vm.createContext({URL, AbortController, document: {querySelectorAll: () => forms}, window: {open: (...args) => openings.push(args)}, fetch: () => {throw new Error('Apple/network access forbidden in synthetic tests.');}});
vm.runInContext(source.replace(/\bexport /g, '') + '\nthis.api = {parseIcloudSource, setupIcloudSources, beginIcloudUpload, endIcloudUpload};', context);
const {parseIcloudSource, setupIcloudSources, beginIcloudUpload, endIcloudUpload} = context.api;
const token = 'SyntheticToken_0123456789';
for (const [url, type] of [
    [`https://share.icloud.com/photos/${token}`, 'photos-link'],
    [`https://www.icloud.com/photos/#${token}`, 'photos-link'],
    [`https://photos.icloud.com/shared/album/${token}/`, 'shared-album'],
    [`https://www.icloud.com/photos/#/sc,${token}/`, 'shared-album'],
]) assert.equal(parseIcloudSource(url)?.type, type);
for (const url of ['', 'https://www.icloud.com/photos/', `http://share.icloud.com/photos/${token}`,
    `https://share.icloud.com.evil.test/photos/${token}`, `https://share.icloud.com@evil.test/photos/${token}`,
    `https://user@share.icloud.com/photos/${token}`, `https://share.icloud.com:443/photos/${token}`,
    `https://share.icloud.com/photos/${token}?secret=x`, `https://share.icloud.com/photos/${token}#other`,
    'https://www.icloud.com/iclouddrive/example', 'https://127.0.0.1/photos/example',
    'https://share.icloud.com/photos/../library', 'https://share.icloud.com/photos/%2fexample',
    'https://share.icloud.com\\photos\\example', 'https://share.icloud.com/photos/' + 'x'.repeat(2049)]) {
    assert.equal(parseIcloudSource(url), null, 'Unsafe/unsupported source must be refused');
}

setupIcloudSources(); setupIcloudSources();
let form = forms[0];
let root = form.children.get('[data-icloud-source]');
const input = root.children.get('[data-icloud-url]');
assert.equal(root.children.get('[data-icloud-validate]').listeners.get('click').length, 1);
input.value = `https://photos.icloud.com/shared/album/${token}/`;
fire(root.children.get('[data-icloud-validate]'), 'click');
assert.equal(input.value, '', 'Bearer input is cleared after recognition');
assert.match(root.children.get('[data-icloud-status]').textContent, /Shared Album.*unchecked/);
assert.equal(root.children.get('[data-icloud-guidance]').products[0].hidden, true);
assert.equal(root.children.get('[data-icloud-guidance]').products[1].hidden, false);
fire(root.children.get('[data-icloud-open]'), 'click');
assert.equal(openings.length, 1);
assert.equal(openings[0][1], '_blank');
assert.equal(openings[0][2], 'noopener,noreferrer');
assert.throws(() => beginIcloudUpload(form), /Choose supported files/);
form.children.get('input[name="images[]"]').files = [new File(['fixture'], 'download.zip')];
form.children.get('[name="upload_mode"]').value = 'new';
form.children.get('[name="visibility"]').value = 'public';
assert.throws(() => beginIcloudUpload(form), /Private required/);
form.children.get('[name="visibility"]').value = 'unpublished';
form.children.get('[data-browser-upload-toggle]').checked = false;
assert.throws(() => beginIcloudUpload(form), /Browser required/);
form.children.get('[data-browser-upload-toggle]').checked = true;
const signal = beginIcloudUpload(form);
fire(root.children.get('[data-icloud-cancel]'), 'click');
assert.equal(signal.aborted, true);
endIcloudUpload(form);
assert.equal(root.children.get('[data-icloud-retry]').hidden, false);
assert.equal(beginIcloudUpload(form).aborted, false, 'Explicit retry gets a new cancellation signal, not a new upload identity');
endIcloudUpload(form, true);
assert.equal(root.children.get('[data-icloud-retry]').hidden, true);
form.children.get('input[name="images[]"]').files = [new File(['fixture'], 'unsupported.heic')];
assert.throws(() => beginIcloudUpload(form), /Choose supported files/);
fire(root.children.get('[data-icloud-clear]'), 'click');
assert.equal(beginIcloudUpload(form), null, 'Ordinary uploads remain independent');
input.value = 'invalid';
assert.throws(() => beginIcloudUpload(form), /Invalid link/);

forms = [uploadForm()];
setupIcloudSources();
form = forms[0]; root = form.children.get('[data-icloud-source]');
assert.equal(root.children.get('[data-icloud-validate]').listeners.get('click').length, 1, 'A re-rendered panel has its own bound controls');
root.children.get('[data-icloud-url]').value = `https://share.icloud.com/photos/${token}`;
fire(root.children.get('[data-icloud-validate]'), 'click');
assert.match(root.children.get('[data-icloud-status]').textContent, /Photos Link/);
fire(root.children.get('[data-icloud-open]'), 'click');
assert.equal(openings.length, 2);

const catalogs = await Promise.all(['en', 'cs', 'de', 'sv'].map(async language => JSON.parse(await readFile(new URL(`../../app/lang/${language}.json`, import.meta.url), 'utf8'))));
const keys = Object.keys(catalogs[0]).filter(key => key.startsWith('admin.icloud.')).sort();
assert.ok(keys.length >= 35);
for (const catalog of catalogs) {
    assert.deepEqual(Object.keys(catalog).filter(key => key.startsWith('admin.icloud.')).sort(), keys);
    for (const key of keys) {
        assert.ok(catalog[key].trim());
        assert.deepEqual((catalog[key].match(/\{[a-z]+\}/g) || []).sort(), (catalogs[0][key].match(/\{[a-z]+\}/g) || []).sort());
    }
}
for (const catalog of catalogs.slice(1)) for (const key of keys) assert.notEqual(catalog[key], catalogs[0][key], `Localized prose required: ${key}`);
for (const suffix of ['', '_CZ', '_DE', '_SV']) {
    const manual = await readFile(new URL(`../../docs/PHP_Gallery_Manual${suffix}.tex`, import.meta.url), 'utf8');
    const section = manual.split('% iCloud assisted import contract')[1]?.split('\\subsection{')[1];
    assert.ok(section, 'All four maintained manual sources document the feature');
    for (const required of ['64~MiB', '4~MiB', '2~GiB', 'ZIP64', 'IndexedDB', 'OPFS', 'HEIC/HEIF/DNG/RAW', 'MP4/MOV']) assert.ok(section.includes(required));
}
const view = await readFile(new URL('../../app/views/admin_uploads.php', import.meta.url), 'utf8');
const urlTag = view.match(/<input type="url"[^>]+data-icloud-url[^>]*>/)?.[0];
assert.ok(urlTag && !urlTag.includes('name='), 'Source URLs must not be successful form controls');
const panel = await readFile(new URL('../../public/assets/gallery-modules/admin-side-panel.js', import.meta.url), 'utf8');
assert.match(panel, /runBrowserGalleryUpload\(form, progress, operation, cloudSignal\)/);
assert.match(panel, /not\(\[data-icloud-cancel\]\)/, 'Cancellation stays enabled while submit controls are pending');
assert.match(panel, /requireCanonicalUploadMutationResult\(result\)/);
console.log('iCloud source recognition, assisted lifecycle, localization and manual contracts passed.');
