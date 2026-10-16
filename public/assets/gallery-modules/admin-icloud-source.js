/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-icloud-source.js
 * Module Type: Browser Source Adapter
 * Purpose: Import public iCloud exports directly through the existing Admin File-stream uploader.
 * Responsibilities:
 *   - Validate distinct public sources and acquire compatible exports only on explicit submit.
 *   - Keep bearer URLs document-local and preserve the existing uploader.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {stageIcloudZip} from './admin-icloud-download.js?v=20261013-icloud-auto-v2';
import {createBrowserArchiveStream} from './browser-upload-archive-stream.js?v=20261013-icloud-auto-v2';
import {uploadBrowserFileStream, browserArchiveUploadConfig, formatFileSize} from './admin-browser-upload.js?v=20261016-three-state-covers-v1';
import {adminOperationOwnsInput} from './admin-operation-keys.js?v=20261012-pdf-files-v1';
import {i18n, i18nForElement, updateBasicProgress} from './admin-core.js?v=20261003-scoped-i18n-v1';

/** @type {WeakMap<HTMLFormElement, {source:IcloudSource|null,abort:AbortController|null,manual:boolean,filesRequired:boolean,footerText:string,archive:import('./admin-icloud-download.js').IcloudArchive|null,stream:import('./browser-upload-archive-stream.js').BrowserArchiveStream|null}>} Mounted forms own bearer URLs, native picker requirements, ordinary footer labels, immutable disk sources and exact retries. */
const sources = new WeakMap();

/**
 * Bound a pasted share URL before parsing or retaining its bearer token.
 * Type: number.
 * Units: UTF-16 code units. Scope: one public sharing URL.
 * Consumers: parseIcloudSource and the corresponding form input maxlength.
 * Rationale: supported opaque sharing paths need no unbounded text or query payload.
 */
const maximumIcloudUrlLength = 2048;

/** Translate a source message in the original form's injected Admin scope, including on a public page.
 * @param {HTMLFormElement} form Original upload form that owns the administrator language.
 * @param {string} key Existing source or upload message key.
 * @param {string} fallback Safe English fallback when a catalog cannot be loaded.
 * @param {Record<string,string|number>} parameters Named display substitutions without bearer data.
 * @returns {string} Scoped translated text, with the existing global fallback for direct-page forms.
 */
function icloudMessage(form, key, fallback = '', parameters = {}) {
    return i18nForElement(form, key, i18n(key, fallback), parameters);
}

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

/** Synchronize native file admission and the existing footer label with the explicit source choice.
 * @param {HTMLFormElement} form Original mounted upload form.
 * @returns {void} Keeps local-upload validation/labels intact outside automatic mode.
 */
function refreshIcloudSourceControls(form) {
    const state = sources.get(form);
    const root = form.querySelector('[data-icloud-source]');
    if (!state || !root) return;
    const input = root.querySelector('[data-icloud-url]');
    const automatic = !state.manual && Boolean(state.source || parseIcloudSource(input.value));
    const files = form.querySelector('input[name="images[]"]');
    if (files) files.required = automatic ? false : state.filesRequired;
    const footer = form.querySelector('[data-gallery-upload-submit]');
    if (footer) {
        const label = automatic ? root.dataset.start : state.footerText;
        footer.textContent = label;
        footer.dataset.originalText = label;
    }
    root.querySelector('[data-icloud-open]').hidden = !state.source && !parseIcloudSource(input.value);
}

/** Recognize the pasted or retained source immediately before an explicit import/manual action.
 * @param {HTMLFormElement} form Original upload form, including unnamed source controls.
 * @returns {boolean} True for a retained or newly validated source; false leaves inline invalid-link guidance.
 */
function selectIcloudSource(form) {
    const state = sources.get(form);
    const root = form.querySelector('[data-icloud-source]');
    const input = root.querySelector('[data-icloud-url]');
    if (input.value.trim()) state.source = parseIcloudSource(input.value);
    state.manual = false;
    if (state.source) input.value = '';
    const guidance = root.querySelector('[data-icloud-guidance]');
    guidance.hidden = !state.source;
    root.querySelector('[data-icloud-status]').textContent = state.source
        ? root.dataset[state.source.type === 'shared-album' ? 'album' : 'link'] + ' ' + root.dataset.assisted
        : root.dataset.invalid;
    for (const item of guidance.querySelectorAll('[data-icloud-product]')) item.hidden = item.dataset.icloudProduct !== state.source?.type;
    refreshIcloudSourceControls(form);
    return Boolean(state.source);
}

/**
 * Bind source guidance to each current form, including newly injected panel forms.
 * @returns {void} Adds form-local handlers once; acquisition starts only on explicit form submission.
 */
export function setupIcloudSources() {
    document.querySelectorAll('[data-gallery-upload-form]').forEach(form => {
        const root = form.querySelector('[data-icloud-source]');
        if (!root || sources.has(form)) return;
        const filesInput = form.querySelector('input[name="images[]"]');
        const state = {source: null, abort: null, manual: false, filesRequired: Boolean(filesInput?.required), footerText: form.querySelector('[data-gallery-upload-submit]')?.textContent || '', archive: null, stream: null};
        sources.set(form, state);
        const input = root.querySelector('[data-icloud-url]');
        const status = root.querySelector('[data-icloud-status]');
        const guidance = root.querySelector('[data-icloud-guidance]');
        const open = root.querySelector('[data-icloud-open]');
        input.addEventListener('input', () => {
            if (adminOperationOwnsInput(form)) return;
            state.source = null;
            state.manual = false;
            guidance.hidden = true;
            open.hidden = true;
            status.textContent = '';
            refreshIcloudSourceControls(form);
        });
        root.querySelector('[data-icloud-validate]').addEventListener('click', () => {
            if (adminOperationOwnsInput(form)) return;
            if (selectIcloudSource(form)) form.requestSubmit();
        });
        open.addEventListener('click', () => {
            if (!adminOperationOwnsInput(form) && selectIcloudSource(form)) {
                state.manual = true;
                refreshIcloudSourceControls(form);
                status.textContent = icloudMessage(form, 'admin.icloud.manual_selected', 'Manual export selected. Download on Apple, then choose the ZIP here.');
                window.open(state.source.url, '_blank', 'noopener,noreferrer');
            }
        });
        root.querySelector('[data-icloud-clear]').addEventListener('click', () => {
            if (adminOperationOwnsInput(form)) return;
            state.source = null;
            state.manual = false;
            if (filesInput) filesInput.required = state.filesRequired;
            input.value = '';
            guidance.hidden = true;
            open.hidden = true;
            status.textContent = '';
            refreshIcloudSourceControls(form);
        });
        root.querySelector('[data-icloud-cancel]').addEventListener('click', () => {
            state.abort?.abort();
        });
        refreshIcloudSourceControls(form);
    });
}

/**
 * Validate and freeze an explicit source submission while exposing cancellation to the existing uploader.
 * @param {HTMLFormElement} form Original explicit upload form.
 * @returns {AbortSignal|null} Active source cancellation signal, or null for ordinary uploads.
 */
export function beginIcloudUpload(form) {
    const state = sources.get(form);
    const root = form.querySelector('[data-icloud-source]');
    if (root?.querySelector('[data-icloud-url]')?.value.trim() && !selectIcloudSource(form)) throw new Error(root.dataset.invalid);
    if (!state?.source) return null;
    const files = Array.from(form.querySelector('input[name="images[]"]')?.files || []);
    if (state.manual && (!files.length || files.some(file => !/\.(?:zip|jpe?g|png|webp|gif)$/i.test(file.name)))) throw new Error(root.dataset.filesRequired);
    const documents = Array.from(form.querySelector('input[name="pdf_files[]"]')?.files || []);
    if (!state.manual && (files.length || documents.length)) throw new Error(icloudMessage(form, 'admin.icloud.source_conflict', 'Choose either the iCloud source or local files for this import.'));
    const toggle = form.querySelector('[data-browser-upload-toggle]');
    if (!toggle?.checked || toggle.disabled) throw new Error(root.dataset.browserRequired);
    if (form.querySelector('[name="upload_mode"]')?.value !== 'new' && !Number(form.querySelector('[name="gallery_id"]')?.value || 0)) throw new Error(icloudMessage(form, 'admin.icloud.destination_required', 'Select a destination gallery before importing.'));
    if (form.querySelector('[name="upload_mode"]')?.value === 'new'
        && !['private', 'unpublished', 'draft'].includes(form.querySelector('[name="visibility"]')?.value)) {
        throw new Error(root.dataset.privateRequired);
    }
    state.abort = new AbortController();
    root.querySelector('[data-icloud-url]').disabled = true;
    root.querySelector('[data-icloud-retry]').hidden = true;
    root.querySelector('[data-icloud-cancel]').hidden = false;
    return state.abort.signal;
}

/** Report explicit automatic source ownership before the ordinary file path is selected.
 * @param {HTMLFormElement} form Original mounted upload form.
 * @returns {boolean} True for a recognized source whose manual alternative was not selected.
 */
export function icloudUploadRequested(form) {
    const state = sources.get(form);
    return Boolean(state?.source && !state.manual);
}

/** Acquire and import an immutable source through the existing prepared-batch replay pipeline.
 * @param {HTMLFormElement} form Original protected create/upload form.
 * @param {HTMLElement} progress Existing panel-local progress surface.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Existing intent/session owner.
 * @param {AbortSignal} signal Current explicit import cancellation.
 * @returns {Promise<Record<string,unknown>>} Canonical existing upload aggregate; failures retain disk data only when server work or a prepared stream must be reconciled.
 */
export async function runIcloudGalleryUpload(form, progress, operation, signal) {
    const state = sources.get(form);
    if (!state?.source || state.manual) throw new Error(icloudMessage(form, 'admin.icloud.invalid'));
    try {
        if (!state.archive) state.archive = await stageIcloudZip(state.source, signal, event => {
            const message = event.phase === 'checking' ? icloudMessage(form, 'admin.icloud.checking') : event.phase === 'exporting' ? icloudMessage(form, 'admin.icloud.exporting')
                : icloudMessage(form, event.total === null ? 'admin.icloud.downloading_unknown' : 'admin.icloud.downloading', '', {bytes: formatFileSize(event.loaded), total: formatFileSize(event.total || 0)});
            updateBasicProgress(progress, event.phase === 'downloading' && event.total ? Math.round(event.loaded / event.total * 100) : 0, message);
            if (event.total === null) progress.querySelector('[data-thumbnail-progress-fill]')?.removeAttribute('value');
        });
        if (!state.stream) {
            updateBasicProgress(progress, 0, icloudMessage(form, 'admin.browser_upload.log_extracting_zip', '', {name: 'iCloud', bytes: formatFileSize(state.archive.file.size)}));
            state.stream = await createBrowserArchiveStream(state.archive.file, browserArchiveUploadConfig(form), progress, signal);
        }
        state.stream.signal = signal;
        state.stream.startIndex = Number(operation.prepared?.nextSourceIndex || 0);
        const result = await uploadBrowserFileStream(form, progress, operation, state.stream, {signal, sourceKind: 'images', startPage: state.stream.startIndex + 1, onProgress: event => {
            if (event.phase === 'uploading') updateBasicProgress(progress, event.bytes ? event.uploadedBytes / event.bytes * 100 : 0, icloudMessage(form, 'admin.icloud.uploading_batch', '', {batch: event.batch}));
        }});
        await state.archive.cleanup();
        state.archive = null;
        state.stream = null;
        return result;
    } catch (error) {
        if (!operation.prepared && !operation.unresolved && state.archive) {
            await state.stream?.dispose();
            await state.archive.cleanup();
            state.archive = null;
            state.stream = null;
        }
        if (typeof error.icloudKey === 'string' && /^admin\.icloud\.[a-z_]+$/.test(error.icloudKey)) {
            throw new Error(icloudMessage(form, error.icloudKey), {cause: error});
        }
        throw error;
    }
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
    if (completed && state) {
        state.source = null;
        state.manual = false;
        const picker = form.querySelector('input[name="images[]"]');
        if (picker) picker.required = state.filesRequired;
        const guidance = form.querySelector('[data-icloud-guidance]');
        const open = form.querySelector('[data-icloud-open]');
        const status = form.querySelector('[data-icloud-status]');
        if (guidance) guidance.hidden = true;
        if (open) open.hidden = true;
        if (status) status.textContent = '';
    }
    const button = form.querySelector('[data-icloud-cancel]');
    if (button) button.hidden = true;
    const retry = form.querySelector('[data-icloud-retry]');
    if (retry) retry.hidden = completed || !state?.source;
    const locked = adminOperationOwnsInput(form);
    const input = form.querySelector('[data-icloud-url]');
    if (input) input.disabled = locked;
    for (const control of form.querySelectorAll('[data-icloud-validate], [data-icloud-clear], [data-icloud-open]')) control.disabled = locked;
    refreshIcloudSourceControls(form);
}
