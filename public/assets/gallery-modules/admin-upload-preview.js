/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-upload-preview.js
 * Module Type: Browser Module
 * Purpose: Display an ephemeral image selection inside the right Admin upload drawer.
 * Responsibilities:
 *   - Compose native picker, #99 clipboard and drop selections into the same FileList
 *   - Render removable/reorderable, locally generated miniature previews
 *   - Bound object URL lifetime and guard unsent files on drawer navigation
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {adminOperationOwnsInput, allowAdminOperationTransition} from './admin-operation-keys.js?v=20261008-issue118-v3';
import {createGalleryUploadQueue} from './admin-upload-queue.js?v=20261008-issue118-v1';
import {requestGalleryUploadThumbnail, galleryUploadPreviewPolicy} from './admin-upload-thumbnail.js?v=20261008-issue118-v1';

/**
 * Per-form local selection and image resources owned by the current DOM.
 * No state in this record is durable or visible to an anonymous viewer.
 * @typedef {Object} UploadPreviewState
 * @property {HTMLFormElement} form Exact drawer upload form.
 * @property {HTMLInputElement} input Authoritative native file input.
 * @property {HTMLElement} view Localized preview mount.
 * @property {import('./admin-upload-queue.js').GalleryUploadSelectionQueue} queue Ephemeral File references.
 * @property {Map<string, string>} urls Small PNG Blob URL per client ID, never an original URL.
 * @property {Map<string, AbortController>} previewJobs Admitted work scoped to one tile generation.
 * @property {Set<string>} failedPreviews Browser-only preview decode errors.
 * @property {Set<string>} visible Current intersection identities.
 * @property {IntersectionObserver|null} observer Visible placeholder admission observer.
 * @property {Map<string, HTMLImageElement>} elements Current image nodes.
 * @property {number} uploadLimitBytes Effective per-file server limit, or zero if unavailable.
 * @property {boolean} syncingFileList True only during this owner's native event dispatch.
 * @property {boolean} pendingSyntheticInput A change preceded by the #99 synthetic input event.
 * @property {number} generation Invalidates stale image/observer callbacks after a rerender.
 */
const uploadSelector = '[data-gallery-upload-form]';
const fileSelector = 'input[type="file"][name="images[]"]';
const previewSelector = '[data-gallery-upload-preview]';
const previewableTypes = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif']);
/**
 * Maximum simultaneous miniature URLs plus pending miniature requests.
 * Twelve 256-pixel RGBA miniature surfaces occupy at most 3 MiB of pixel data.
 * @var {number} Units: preview slots (URLs or pending requests). Scope: one active form.
 * Consumers: admin-upload-preview.js.
 * Rationale: bound decoded image load pressure while supporting ordinary preview grids.
 */
const MAX_ACTIVE_PREVIEWS = 12;
/**
 * Largest original source admitted to preview decoding; larger images still upload.
 * @var {number} Units: source bytes. Scope: one preview image candidate.
 * Consumers: admin-upload-preview.js.
 * Rationale: avoid decoding unusually large originals only to display a thumbnail.
 */
const MAX_PREVIEW_SOURCE_BYTES = galleryUploadPreviewPolicy.maxSourceBytes;
/** @type {WeakMap<HTMLFormElement, UploadPreviewState>} Never persists File objects beyond the document. */
const queues = new WeakMap();
/** @type {Set<HTMLFormElement>} Only active forms, for teardown of detached fragments. */
const activeForms = new Set();
let delegateBound = false;
let detachedObserver = null;

/**
 * Resolve only enhanced forms with server-rendered preview metadata.
 * @param {Element|null} element Candidate element.
 * @return {HTMLFormElement|null} Enhanced drawer form only.
 */
function uploadForm(element) {
    const form = element instanceof Element ? element.closest(uploadSelector) : null;
    return form instanceof HTMLFormElement && form.querySelector(previewSelector) ? form : null;
}

/**
 * Create/find a per-form queue. Full-page upload forms do not have preview markup
 * and continue using their original selection behavior unchanged.
 * @param {HTMLFormElement} form Current drawer form.
 * @return {UploadPreviewState|null} DOM/URL owner for this form.
 */
function queueFor(form) {
    let state = queues.get(form);
    if (state) return state;
    const input = form.querySelector(fileSelector);
    const view = form.querySelector(previewSelector);
    if (!(input instanceof HTMLInputElement) || !(view instanceof HTMLElement)) return null;
    state = {
        form, input, view, queue: createGalleryUploadQueue(Array.from(input.files || [])),
        urls: new Map(), previewJobs: new Map(), failedPreviews: new Set(), visible: new Set(),
        observer: null, elements: new Map(), uploadLimitBytes: 0, syncingFileList: false, pendingSyntheticInput: false, generation: 0,
    };
    const rawConfig = form.querySelector('[data-browser-upload-config]')?.dataset.browserUploadConfig;
    if (rawConfig) {
        try {
            const bytes = Number(JSON.parse(rawConfig).upload_limit_bytes);
            if (Number.isFinite(bytes) && bytes > 0) state.uploadLimitBytes = bytes;
        } catch { /* Malformed optional hint does not change upload authorization. */ }
    }
    queues.set(form, state);
    renderQueue(state);
    return state;
}

/**
 * Return byte count without rounding away nonzero files.
 * @param {number} bytes Source bytes.
 * @return {string} Compact human-readable size.
 */
function fileSize(bytes) {
    if (bytes < 1024) return String(bytes) + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KiB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MiB';
}

/**
 * Format one local label supplied by the PHP view. No HTML interpolation.
 * @param {HTMLElement} view Per-form metadata.
 * @param {string} key data-* property name.
 * @param {Object<string, string|number>} values Placeholder values.
 * @return {string} Localized text.
 */
function label(view, key, values = {}) {
    let message = String(view.dataset[key] || '');
    for (const [name, value] of Object.entries(values)) {
        message = message.replaceAll('{' + name + '}', String(value));
    }
    return message;
}

/**
 * Restrict local preview decoding to known image MIME values.
 * @param {string} type Low-trust MIME.
 * @return {boolean} Whether to allow inline decoding.
 */
function mayPreview(type) {
    return previewableTypes.has(String(type || '').toLowerCase());
}

/**
 * Explain provisional client-side problems without rejecting an otherwise valid
 * selection. Server MIME sniffing, PHP limits and the upload owner remain final.
 * @param {UploadPreviewState} state Original form and resolved upload hints.
 * @param {File} file Raw selected source, including user ZIP archives.
 * @param {boolean} repeatedName Whether another selected occurrence has the exact same filename.
 * @return {string[]} Localized warning messages, or an empty list if no warning is known.
 */
function selectionWarnings(state, file, repeatedName) {
    const warnings = [];
    const ext = '.' + String(file.name.split('.').pop() || '').toLowerCase();
    const accepted = new Set(state.input.accept.toLowerCase().split(',').map(token => token.trim()));
    const isZip = ext === '.zip';
    const preparing = Boolean(state.form.querySelector('[data-browser-upload-toggle]')?.checked);
    if (file.size === 0) warnings.push(label(state.view, 'emptyFile'));
    if (repeatedName) warnings.push(label(state.view, 'repeatedName'));
    if (state.input.accept && !accepted.has(ext) && !accepted.has(String(file.type || '').toLowerCase())) {
        // image/* is a picker convenience, not a claim that arbitrary codecs are supported.
        warnings.push(label(state.view, 'unsupportedFormat'));
    }
    if (isZip && !preparing) warnings.push(label(state.view, 'zipRequiresBrowser'));
    if (preparing && !isZip && !['.jpg', '.jpeg', '.png', '.gif', '.webp'].includes(ext)) {
        warnings.push(label(state.view, 'browserFormat'));
    }
    if (state.uploadLimitBytes && file.size > state.uploadLimitBytes) {
        warnings.push(label(state.view, 'largeUpload', {size: fileSize(state.uploadLimitBytes)}));
    }
    return warnings;
}

/**
 * Revoke a single local Blob URL and release presentation resources.
 * @param {UploadPreviewState} state Form queue.
 * @param {string} id Local item ID.
 * @return {void} Revoke one Blob URL.
 */
function releasePreview(state, id) {
    const job = state.previewJobs.get(id);
    state.previewJobs.delete(id);
    job?.abort();
    const url = state.urls.get(id);
    if (url) URL.revokeObjectURL(url);
    state.urls.delete(id);
    state.visible.delete(id);
    const image = state.elements.get(id);
    if (image instanceof HTMLImageElement) {
        image.removeAttribute('src');
        image.hidden = true;
        const fallback = image.parentElement?.querySelector('.gallery-upload-queue-fallback');
        if (fallback instanceof HTMLElement) fallback.hidden = false;
    }
}

/**
 * Explain a local preview failure without changing the selected upload source.
 * @param {UploadPreviewState} state Form queue and localized messages.
 * @param {string} id Failed local item identity.
 * @return {void} Keep the filename/icon visible and expose advisory feedback.
 */
function previewUnavailable(state, id) {
    state.failedPreviews.add(id);
    const image = state.elements.get(id);
    const note = image?.closest('[data-upload-queue-id]')?.querySelector('[data-upload-preview-note]');
    if (note instanceof HTMLElement) {
        note.textContent = label(state.view, 'previewUnavailable');
        note.hidden = false;
    }
    releasePreview(state, id);
}

/**
 * Schedule a near-viewport miniature within the shared URL/request budget.
 * Original decoding is serialized by the thumbnail owner; stale results never
 * allocate a Blob URL or reattach to a removed or replaced tile.
 * @param {UploadPreviewState} state Form queue.
 * @param {string} id Local item ID.
 * @return {void} Admit one cancellable thumbnail request or reuse its small PNG URL.
 */
function activatePreview(state, id) {
    const item = state.queue.items().find(entry => entry.id === id);
    const image = state.elements.get(id);
    if (!item || !(image instanceof HTMLImageElement) || state.failedPreviews.has(id)) return;
    if (item.file.size > MAX_PREVIEW_SOURCE_BYTES || !mayPreview(item.file.type)) return;
    const existing = state.urls.get(id);
    if (existing) { image.src = existing; return; }
    if (state.previewJobs.has(id) || state.urls.size + state.previewJobs.size >= MAX_ACTIVE_PREVIEWS) return;
    const controller = new AbortController();
    const generation = state.generation;
    state.previewJobs.set(id, controller);
    void requestGalleryUploadThumbnail(item.file, controller.signal).then(blob => {
        if (state.previewJobs.get(id) !== controller) return;
        state.previewJobs.delete(id);
        if (controller.signal.aborted || state.generation !== generation
            || !state.visible.has(id) || state.elements.get(id) !== image) return;
        if (!blob) {
            previewUnavailable(state, id);
        } else {
            try {
                const url = URL.createObjectURL(blob);
                state.urls.set(id, url);
                image.src = url;
            } catch { previewUnavailable(state, id); }
        }
        for (const candidate of state.visible) activatePreview(state, candidate);
    });
}

/**
 * Rebuild only the lightweight DOM, retaining URLs belonging to unchanged IDs.
 * IntersectionObserver materializes previews near the drawer viewport and revokes
 * them offscreen. Browsers without it get bounded first-item previews as fallback.
 * @param {UploadPreviewState} state Current queue owner.
 * @return {void} Synchronize visible selection, order and aggregate metrics.
 */
function renderQueue(state) {
    const {view, form} = state;
    const items = state.queue.items();
    const nameCounts = new Map();
    for (const item of items) nameCounts.set(item.file.name, (nameCounts.get(item.file.name) || 0) + 1);
    const generation = ++state.generation;
    const ids = new Set(items.map(item => item.id));
    for (const id of Array.from(state.previewJobs.keys())) releasePreview(state, id);
    state.visible.clear();
    for (const id of state.urls.keys()) if (!ids.has(id)) releasePreview(state, id);
    for (const id of state.failedPreviews) if (!ids.has(id)) state.failedPreviews.delete(id);
    state.observer?.disconnect();
    state.observer = null;
    state.elements.clear();
    const previousGridScroll = view.querySelector('.gallery-upload-queue-grid')?.scrollTop || 0;
    view.replaceChildren();
    view.hidden = items.length === 0;
    if (items.length === 0) {
        activeForms.delete(form);
        if (activeForms.size === 0) { detachedObserver?.disconnect(); detachedObserver = null; }
        return;
    }
    activeForms.add(form);
    observeDetachedForms();

    const header = document.createElement('div');
    header.className = 'gallery-upload-queue-header';
    const summary = document.createElement('strong');
    summary.textContent = label(view, 'summary', {
        count: items.length,
        size: fileSize(items.reduce((sum, item) => sum + item.file.size, 0)),
    });
    header.append(summary);
    const clear = actionButton(view, 'clear', label(view, 'clear'), false);
    header.append(clear);
    view.append(header);

    const grid = document.createElement('ol');
    grid.className = 'gallery-upload-queue-grid';
    for (let index = 0; index < items.length; index++) {
        const item = items[index];
        const tile = document.createElement('li');
        tile.className = 'gallery-upload-queue-item';
        tile.dataset.uploadQueueId = item.id;

        const thumb = document.createElement('div');
        thumb.className = 'gallery-upload-queue-thumb';
        const fallback = document.createElement('span');
        fallback.className = 'gallery-upload-queue-fallback';
        fallback.textContent = (item.file.name.split('.').pop() || '?').slice(0, 6).toUpperCase();
        thumb.append(fallback);
        if (mayPreview(item.file.type) && item.file.size <= MAX_PREVIEW_SOURCE_BYTES && item.file.size > 0) {
            const image = document.createElement('img');
            // Viewport admission belongs to the visible placeholder, not native lazy loading.
            // A hidden image has no intersection box and must still load its admitted PNG.
            image.hidden = true;
            image.decoding = 'async';
            image.alt = '';
            image.dataset.uploadQueueImage = item.id;
            image.addEventListener('load', () => {
                if (state.generation === generation && state.elements.get(item.id) === image
                    && image.hasAttribute('src')) { fallback.hidden = true; image.hidden = false; }
            });
            image.addEventListener('error', () => {
                if (state.generation !== generation || state.elements.get(item.id) !== image
                    || !image.hasAttribute('src')) return;
                previewUnavailable(state, item.id);
                fallback.hidden = false;
            });
            thumb.append(image);
            state.elements.set(item.id, image);
        }
        tile.append(thumb);
        const meta = document.createElement('div');
        meta.className = 'gallery-upload-queue-meta';
        const name = document.createElement('span');
        name.className = 'gallery-upload-queue-name';
        name.title = item.file.name;
        name.textContent = item.file.name || label(view, 'unnamed');
        meta.append(name);
        const details = document.createElement('small');
        details.textContent = String(index + 1) + ' · ' + fileSize(item.file.size);
        meta.append(details);
        for (const message of selectionWarnings(state, item.file, nameCounts.get(item.file.name) > 1)) {
            const warning = document.createElement('small');
            warning.className = 'gallery-upload-queue-warning';
            warning.textContent = message;
            meta.append(warning);
        }
        const note = document.createElement('small');
        note.className = 'gallery-upload-queue-note';
        note.dataset.uploadPreviewNote = '';
        note.hidden = true;
        if (item.file.size > MAX_PREVIEW_SOURCE_BYTES && mayPreview(item.file.type)) {
            note.textContent = label(view, 'largePreview');
            note.hidden = false;
        } else if (state.failedPreviews.has(item.id)) {
            note.textContent = label(view, 'previewUnavailable');
            note.hidden = false;
        }
        meta.append(note);
        tile.append(meta);
        const actions = document.createElement('div');
        actions.className = 'gallery-upload-queue-actions';
        const earlier = actionButton(view, 'earlier', '↑', index === 0);
        earlier.setAttribute('aria-label', label(view, 'earlier', {name: item.file.name}));
        const later = actionButton(view, 'later', '↓', index === items.length - 1);
        later.setAttribute('aria-label', label(view, 'later', {name: item.file.name}));
        const remove = actionButton(view, 'remove', '×', false);
        remove.setAttribute('aria-label', label(view, 'remove', {name: item.file.name}));
        actions.append(earlier, later, remove);
        tile.append(actions);
        grid.append(tile);
    }
    view.append(grid);
    grid.scrollTop = previousGridScroll;

    if (typeof IntersectionObserver === 'function') {
        state.observer = new IntersectionObserver(entries => {
            if (state.generation !== generation) return;
            for (const entry of entries) {
                const id = entry.target.dataset.uploadPreviewTarget;
                if (!id) continue;
                if (entry.isIntersecting) state.visible.add(id);
                else releasePreview(state, id);
            }
            for (const id of state.visible) activatePreview(state, id);
        }, {rootMargin: '80px'});
        for (const [id, image] of state.elements) {
            const placeholder = image.parentElement;
            if (!(placeholder instanceof HTMLElement)) continue;
            placeholder.dataset.uploadPreviewTarget = id;
            state.observer.observe(placeholder);
        }
    } else {
        for (const id of Array.from(state.elements.keys()).slice(0, MAX_ACTIVE_PREVIEWS)) {
            state.visible.add(id);
            activatePreview(state, id);
        }
    }
}

/**
 * Create a native button whose action is delegated to this module.
 * @param {HTMLElement} view Metadata owner.
 * @param {string} action Stable action name.
 * @param {string} caption Visible text.
 * @param {boolean} disabled Boundary control.
 * @return {HTMLButtonElement} Non-submitting button.
 */
function actionButton(view, action, caption, disabled) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'gallery-upload-queue-button';
    button.dataset.uploadQueueAction = action;
    button.textContent = caption;
    const form = uploadForm(view);
    button.disabled = disabled || Boolean(form && (form.classList.contains('is-uploading') || adminOperationOwnsInput(form)));
    return button;
}

/**
 * Reassign the canonical FileList and announce it to all existing upload owners.
 * @param {UploadPreviewState} state Current queue.
 * @return {boolean} Whether native assignment succeeded.
 */
function writeFileList(state) {
    state.syncingFileList = true;
    try {
        const transfer = new DataTransfer();
        for (const item of state.queue.items()) transfer.items.add(item.file);
        state.input.files = transfer.files;
        state.input.dispatchEvent(new Event('input', {bubbles: true}));
        state.input.dispatchEvent(new Event('change', {bubbles: true}));
        return true;
    } catch {
        state.queue.reconcile(Array.from(state.input.files || []));
        const status = state.form.querySelector('[data-gallery-upload-selection-status]');
        if (status instanceof HTMLElement) status.textContent = label(state.view, 'assignmentUnavailable');
        return false;
    } finally {
        state.syncingFileList = false;
    }
}

/**
 * Append one native event's files and reflect the authoritative FileList.
 * @param {UploadPreviewState} state Current queue.
 * @param {File[]} files Native source files.
 * @param {string} source Source event.
 * @return {void} Append through the enabled native input, or retain the selection when locked/disabled.
 */
function appendSelection(state, files, source) {
    if (files.length === 0 || state.input.matches(':disabled')
        || state.form.classList.contains('is-uploading') || adminOperationOwnsInput(state.form)) return;
    state.queue.append(files, source);
    writeFileList(state);
    renderQueue(state);
}

/**
 * Revoke preview URLs when a drawer fragment is detached externally.
 * @return {void} Release memory for detached forms.
 */
function cleanupDetachedForms() {
    for (const form of Array.from(activeForms)) if (!form.isConnected) teardownGalleryUploadQueue(form);
}

/**
 * Install lifecycle observation only while unsent Files are selected.
 * @return {void} Observe detached drawer fragments.
 */
function observeDetachedForms() {
    if (detachedObserver || typeof MutationObserver !== 'function') return;
    detachedObserver = new MutationObserver(cleanupDetachedForms);
    detachedObserver.observe(document.body, {childList: true, subtree: true});
}

/**
 * Bind one delegated handler for newly mounted drawer forms. The old clipboard
 * listener remains responsible for filtering editor paste and native FileList append.
 * @return {void} React to selection changes without touching full-page upload forms.
 */
export function setupGalleryUploadPreviews() {
    document.querySelectorAll(uploadSelector).forEach(form => {
        if (form instanceof HTMLFormElement && form.querySelector(previewSelector)) queueFor(form);
    });
    if (delegateBound) return;
    delegateBound = true;

    // The #99 clipboard owner dispatches input then change after assigning its
    // aggregate FileList. Native chooser changes append instead of replacing it.
    document.addEventListener('input', event => {
        if (!(event.target instanceof Element) || !event.target.matches(fileSelector)) return;
        const form = uploadForm(event.target);
        // A newly injected form initializes from its current FileList on change.
        // Do not seed it from native input and then append the same chooser event.
        const state = form ? queues.get(form) : null;
        if (state && !state.syncingFileList && !event.isTrusted) state.pendingSyntheticInput = true;
    });
    document.addEventListener('change', event => {
        const form = uploadForm(event.target);
        if (!form) return;
        const existingState = queues.get(form);
        const state = queueFor(form);
        if (!state) return;
        if (event.target.matches('[data-browser-upload-toggle]')) {
            if (!form.classList.contains('is-uploading')) renderQueue(state);
            return;
        }
        if (!event.target.matches(fileSelector) || state.syncingFileList) return;
        // A dynamically inserted form starts with its already-assigned native
        // FileList, so its very first event must not append the same files again.
        if (!existingState) return;
        const fromClipboardOrExternalInput = state.pendingSyntheticInput;
        state.pendingSyntheticInput = false;
        if (form.classList.contains('is-uploading') || adminOperationOwnsInput(form)) {
            // The upload already captured its source selection. Reject late native
            // chooser changes and preserve the original retry's FileList.
            writeFileList(state);
            const panel = form.closest('[data-admin-side-panel]');
            if (panel) allowAdminOperationTransition(panel);
            return;
        }
        const incoming = Array.from(state.input.files || []);
        if (event.isTrusted || !fromClipboardOrExternalInput) {
            // Native chooser change replaces the input: normalize it to append.
            // A change-only synthetic fixture follows the same user journey.
            appendSelection(state, incoming, 'picker');
            return;
        }
        const previous = state.queue.items();
        if (previous.length === incoming.length && previous.every((item, index) => item.file === incoming[index])) return;
        state.queue.reconcile(incoming);
        renderQueue(state);
    });

    document.addEventListener('dragover', event => {
        const zone = event.target instanceof Element ? event.target.closest('[data-gallery-upload-drop-zone]') : null;
        if (!uploadForm(zone) || !Array.from(event.dataTransfer?.types || []).includes('Files')) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    });
    document.addEventListener('drop', event => {
        const zone = event.target instanceof Element ? event.target.closest('[data-gallery-upload-drop-zone]') : null;
        const form = uploadForm(zone);
        if (!form || !event.dataTransfer?.files?.length) return;
        event.preventDefault();
        const state = queueFor(form);
        if (state) appendSelection(state, Array.from(event.dataTransfer.files), 'drop');
    });
    document.addEventListener('click', event => {
        const button = event.target instanceof Element ? event.target.closest('[data-upload-queue-action]') : null;
        const form = uploadForm(button);
        if (!button || !form || form.classList.contains('is-uploading') || adminOperationOwnsInput(form)) return;
        const state = queueFor(form);
        if (!state) return;
        const action = button.dataset.uploadQueueAction;
        const id = button.closest('[data-upload-queue-id]')?.dataset.uploadQueueId;
        const previousIndex = state.queue.items().findIndex(item => item.id === id);
        if (action === 'clear') state.queue.clear();
        else if (action === 'remove') state.queue.remove(id);
        else if (action === 'earlier') state.queue.move(id, -1);
        else if (action === 'later') state.queue.move(id, 1);
        else return;
        writeFileList(state);
        renderQueue(state);
        // Replacing the grid must not send keyboard focus to the page body.
        if (action === 'clear') {
            state.input.focus({preventScroll: true});
        } else {
            const remaining = state.queue.items();
            const focusId = action === 'remove'
                ? remaining[Math.min(Math.max(0, previousIndex), remaining.length - 1)]?.id
                : id;
            const tile = Array.from(state.view.querySelectorAll('[data-upload-queue-id]'))
                .find(candidate => candidate.dataset.uploadQueueId === focusId);
            const focusAction = action === 'remove' ? 'remove' : action;
            const focusButton = tile?.querySelector('[data-upload-queue-action="' + focusAction + '"]:enabled')
                || tile?.querySelector('[data-upload-queue-action="remove"]');
            focusButton?.focus({preventScroll: true});
            if (!tile) state.input.focus({preventScroll: true});
        }
    });
    document.addEventListener('reset', event => {
        const form = uploadForm(event.target);
        if (!form) return;
        if (form.classList.contains('is-uploading') || adminOperationOwnsInput(form)) {
            event.preventDefault();
            const panel = form.closest('[data-admin-side-panel]');
            if (panel) allowAdminOperationTransition(panel);
            return;
        }
        // The native reset runs after dispatch; respect another owner's veto.
        queueMicrotask(() => { if (!event.defaultPrevented) clearGalleryUploadQueue(form); });
    });
    window.addEventListener('pagehide', () => {
        for (const form of activeForms) {
            const state = queues.get(form);
            if (!state) continue;
            state.generation++;
            state.observer?.disconnect();
            for (const id of new Set([...state.urls.keys(), ...state.previewJobs.keys()])) releasePreview(state, id);
        }
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) for (const form of activeForms) {
            const state = queues.get(form);
            if (state && form.isConnected) renderQueue(state);
        }
    });
    window.addEventListener('beforeunload', event => {
        if (![...activeForms].some(form => form.isConnected)) return;
        event.preventDefault();
        event.returnValue = '';
    });
}

/**
 * Forget a submitted selection only after a verified canonical upload result.
 * The transport retains File objects on failed/uncertain requests for safe retry.
 * @param {HTMLFormElement} form Original upload form.
 * @return {void} Revoke previews and clear canonical input.
 */
export function clearGalleryUploadQueue(form) {
    const state = queues.get(form);
    if (!state) return;
    for (const id of new Set([...state.urls.keys(), ...state.previewJobs.keys()])) releasePreview(state, id);
    state.failedPreviews.clear();
    state.queue.clear();
    try { state.input.value = ''; } catch { /* Detached browser input. */ }
    renderQueue(state);
}

/**
 * Release every URL and File reference owned by a removed/replaced form.
 * @param {HTMLFormElement} form Original form.
 * @return {void} Stop observing the obsolete gallery context.
 */
export function teardownGalleryUploadQueue(form) {
    const state = queues.get(form);
    if (!state) return;
    state.generation++;
    state.observer?.disconnect();
    for (const id of new Set([...state.urls.keys(), ...state.previewJobs.keys()])) releasePreview(state, id);
    state.queue.clear();
    state.input.value = '';
    state.elements.clear();
    queues.delete(form);
    activeForms.delete(form);
    if (activeForms.size === 0) { detachedObserver?.disconnect(); detachedObserver = null; }
}

/**
 * Check whether a drawer holds local selection not yet acknowledged by upload.
 * @param {HTMLElement} panel Current drawer.
 * @return {boolean} Whether it holds unsent File objects.
 */
export function hasPendingGalleryUploadQueue(panel) {
    return Array.from(panel.querySelectorAll(uploadSelector)).some(form =>
        form instanceof HTMLFormElement && (queues.get(form)?.queue.items().length || 0) > 0);
}

/**
 * Protect a local-only queue before closing or switching the right drawer.
 * The warning is a choice, not browser storage or a fake server draft.
 * @param {HTMLElement} panel Current drawer.
 * @param {function(): void} proceed Explicit transition to resume after discard.
 * @return {boolean} True when caller may continue immediately.
 */
export function allowGalleryUploadQueueTransition(panel, proceed) {
    // An outstanding upload must be protected by the canonical operation owner.
    if (!allowAdminOperationTransition(panel)) return false;
    if (panel.classList.contains('is-uploading')) return true;
    if (!hasPendingGalleryUploadQueue(panel)) return true;
    if (panel.querySelector('[data-gallery-upload-queue-discard-prompt]')) return false;
    const form = Array.from(panel.querySelectorAll(uploadSelector)).find(candidate =>
        candidate instanceof HTMLFormElement && (queues.get(candidate)?.queue.items().length || 0) > 0);
    const view = form?.querySelector(previewSelector);
    if (!(view instanceof HTMLElement)) return true;
    const body = panel.querySelector('[data-admin-side-panel-body]');
    if (!(body instanceof HTMLElement)) return false;
    const previousFocus = document.activeElement;
    const prompt = document.createElement('div');
    prompt.className = 'notice gallery-upload-queue-discard-prompt';
    prompt.dataset.galleryUploadQueueDiscardPrompt = '';
    prompt.setAttribute('role', 'alertdialog');
    prompt.setAttribute('aria-label', label(view, 'unsentTitle'));
    const explanation = document.createElement('p');
    explanation.textContent = label(view, 'unsentWarning');
    const stay = document.createElement('button');
    stay.type = 'button';
    stay.textContent = label(view, 'keepEditing');
    stay.addEventListener('click', () => { prompt.remove(); stateFocus(); });
    const discard = document.createElement('button');
    discard.type = 'button';
    discard.textContent = label(view, 'discard');
    discard.addEventListener('click', () => {
        if (!allowAdminOperationTransition(panel)) return;
        prompt.remove();
        for (const current of Array.from(panel.querySelectorAll(uploadSelector))) clearGalleryUploadQueue(current);
        proceed();
    });
    prompt.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        prompt.remove();
        stateFocus();
    });
    prompt.append(explanation, stay, discard);
    body.prepend(prompt);
    /**
     * Return keyboard focus to the retained local selection after cancelling discard.
     * @return {void} Restore the original connected control or the retained queue's clear button.
     */
    function stateFocus() {
        const target = previousFocus instanceof HTMLElement && view.closest('form')?.contains(previousFocus)
            ? previousFocus : view.querySelector('[data-upload-queue-action="clear"]');
        target?.focus({preventScroll: true});
    }
    stay.focus({preventScroll: true});
    return false;
}
