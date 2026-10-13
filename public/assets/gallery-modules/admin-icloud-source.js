/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-icloud-source.js
 * Module Type: Browser Source Adapter
 * Purpose: Guide explicit iCloud exports into the existing Admin upload form.
 * Responsibilities:
 *   - Validate distinct public sharing URL shapes without contacting Apple.
 *   - Keep bearer URLs document-local and preserve the existing uploader.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/** @type {WeakMap<HTMLFormElement, {source: IcloudSource|null, abort: AbortController|null}>} Mounted forms own private URLs and active cancellation. */
const sources = new WeakMap();

/**
 * Bound a pasted share URL before parsing or retaining its bearer token.
 * Type: number.
 * Units: UTF-16 code units. Scope: one public sharing URL.
 * Consumers: parseIcloudSource and the corresponding form input maxlength.
 * Rationale: supported opaque sharing paths need no unbounded text or query payload.
 */
const maximumIcloudUrlLength = 2048;

/**
 * Describe a recognized URL, never evidence of live availability or export format.
 * @typedef {Object} IcloudSource
 * @property {'photos-link'|'shared-album'} type Independent Apple sharing product.
 * @property {string} url Validated HTTPS bearer URL; never sent to Gallery or logged.
 */

/**
 * Recognize public Apple photo share URLs without network or credential access.
 * @param {string} value User-pasted URL.
 * @returns {IcloudSource|null} Recognized source, or null for unsupported/ambiguous input.
 */
export function parseIcloudSource(value) {
    const text = String(value || '').trim();
    if (text.length > maximumIcloudUrlLength) return null;
    // Accept only explicit HTTPS spellings. Reject normalization of backslashes,
    // encoded delimiters, credentials, ports, queries and unrelated library routes.
    const link = /^https:\/\/share\.icloud\.com\/photos\/[A-Za-z0-9_-]+\/?$/i;
    const album = /^https:\/\/photos\.icloud\.com\/shared\/album\/[A-Za-z0-9_-]+\/?$/i;
    const fragmentLink = /^https:\/\/www\.icloud\.com\/photos\/#(?:[A-Za-z0-9_-]+)\/?$/i;
    const fragmentAlbum = /^https:\/\/www\.icloud\.com\/photos\/#\/sc,[A-Za-z0-9_-]+\/?$/i;
    if (![link, album, fragmentLink, fragmentAlbum].some(pattern => pattern.test(text))) return null;
    const url = new URL(text);
    return {type: album.test(text) || fragmentAlbum.test(text) ? 'shared-album' : 'photos-link', url: url.href};
}

/**
 * Bind source guidance to each current form, including newly injected panel forms.
 * @returns {void} Adds form-local handlers once; does not submit or download anything.
 */
export function setupIcloudSources() {
    document.querySelectorAll('[data-gallery-upload-form]').forEach(form => {
        const root = form.querySelector('[data-icloud-source]');
        if (!root || sources.has(form)) return;
        const state = {source: null, abort: null};
        sources.set(form, state);
        const input = root.querySelector('[data-icloud-url]');
        const status = root.querySelector('[data-icloud-status]');
        const guidance = root.querySelector('[data-icloud-guidance]');
        const open = root.querySelector('[data-icloud-open]');
        input.addEventListener('input', () => {
            state.source = null;
            guidance.hidden = true;
            open.hidden = true;
            status.textContent = '';
        });
        root.querySelector('[data-icloud-validate]').addEventListener('click', () => {
            state.source = parseIcloudSource(input.value);
            input.value = '';
            guidance.hidden = !state.source;
            open.hidden = !state.source;
            status.textContent = state.source
                ? root.dataset[state.source.type === 'shared-album' ? 'album' : 'link'] + ' ' + root.dataset.assisted
                : root.dataset.invalid;
            for (const item of guidance.querySelectorAll('[data-icloud-product]')) {
                item.hidden = item.dataset.icloudProduct !== state.source?.type;
            }
        });
        open.addEventListener('click', () => {
            if (state.source) window.open(state.source.url, '_blank', 'noopener,noreferrer');
        });
        root.querySelector('[data-icloud-clear]').addEventListener('click', () => {
            state.source = null;
            input.value = '';
            guidance.hidden = true;
            open.hidden = true;
            status.textContent = '';
        });
        root.querySelector('[data-icloud-cancel]').addEventListener('click', () => {
            state.abort?.abort();
        });
    });
}

/**
 * Validate an assisted submission and expose cancellation to the existing uploader.
 * @param {HTMLFormElement} form Original explicit upload form.
 * @returns {AbortSignal|null} Active assisted cancellation signal, or null for ordinary uploads.
 */
export function beginIcloudUpload(form) {
    const state = sources.get(form);
    const root = form.querySelector('[data-icloud-source]');
    if (root?.querySelector('[data-icloud-url]')?.value.trim()) throw new Error(root.dataset.invalid);
    if (!state?.source) return null;
    const files = Array.from(form.querySelector('input[name="images[]"]')?.files || []);
    if (!files.length || files.some(file => !/\.(?:zip|jpe?g|png|webp|gif)$/i.test(file.name))) throw new Error(root.dataset.filesRequired);
    if (!form.querySelector('[data-browser-upload-toggle]')?.checked) throw new Error(root.dataset.browserRequired);
    if (form.querySelector('[name="upload_mode"]')?.value === 'new'
        && !['private', 'unpublished', 'draft'].includes(form.querySelector('[name="visibility"]')?.value)) {
        throw new Error(root.dataset.privateRequired);
    }
    state.abort = new AbortController();
    root.querySelector('[data-icloud-retry]').hidden = true;
    root.querySelector('[data-icloud-cancel]').hidden = false;
    return state.abort.signal;
}

/**
 * Retire the attempt's cancel control without discarding uploader retry identities.
 * @param {HTMLFormElement} form Original assisted upload form.
 * @param {boolean} completed Whether the canonical upload completion was acknowledged.
 * @returns {void} Releases the controller; the existing operation owner retains uncertain work.
 */
export function endIcloudUpload(form, completed = false) {
    const state = sources.get(form);
    if (state) state.abort = null;
    const button = form.querySelector('[data-icloud-cancel]');
    if (button) button.hidden = true;
    const retry = form.querySelector('[data-icloud-retry]');
    if (retry) retry.hidden = completed || !state?.source;
}
