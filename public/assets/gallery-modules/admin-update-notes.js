/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-update-notes.js
 * Module Type: Browser Module
 * Purpose: Keep dynamically refreshed release-note pickers usable in Updates and drawers.
 * Responsibilities: Delegate selection, reject stale responses and replace installed notes in place.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Purpose: Own the latest selection request for each viewer.
 * Units: one abort controller per viewer.
 * Scope: browser document, weakly held DOM fragments.
 * Consumers: loadVersion(), refreshUpdateNotes().
 * Rationale: an older selection must never overwrite newly installed release notes.
 * @type {WeakMap<HTMLElement, AbortController>}
 */
const NOTE_REQUESTS = new WeakMap();

/** Set the picker state. @param {HTMLElement} viewer Owned notes viewer. @param {boolean} open Expanded state. @return {void} Updates visible and accessible state. */
function setPickerOpen(viewer, open) {
    viewer.querySelector('[data-patch-notes-picker]')?.classList.toggle('is-open', open);
    viewer.querySelector('[data-patch-notes-picker-button]')?.setAttribute('aria-expanded', String(open));
}

/** Synchronize every selected-version control. @param {HTMLElement} viewer Owned viewer. @param {string} version Selected release. @return {void} Updates labels and form values. */
function syncSelection(viewer, version) {
    for (const input of viewer.querySelectorAll('[data-patch-notes-input], [data-patch-notes-select]')) input.value = version;
    for (const option of viewer.querySelectorAll('.patch-notes-version-option')) {
        const selected = option.dataset.patchVersion === version;
        option.classList.toggle('is-selected', selected);
        option.setAttribute('aria-selected', String(selected));
        if (selected) {
            const label = viewer.querySelector('[data-patch-notes-picker-text]');
            if (label) label.textContent = option.dataset.patchLabel || version;
        }
    }
}

/** Read a selected release without navigation. @param {HTMLElement} viewer Owned viewer. @param {string} version Requested release. @return {Promise<void>} Settles selection or inline error. */
async function loadVersion(viewer, version) {
    const target = viewer.querySelector('[data-patch-notes-fragment]');
    if (!viewer.dataset.fragmentUrl || !target || !version) return;
    NOTE_REQUESTS.get(viewer)?.abort();
    const controller = new AbortController();
    NOTE_REQUESTS.set(viewer, controller);
    viewer.open = true;
    setPickerOpen(viewer, false);
    target.setAttribute('aria-busy', 'true');
    const url = new URL(viewer.dataset.fragmentUrl, location.href);
    url.searchParams.set('patch_version', version);
    try {
        const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
        const payload = await response.json();
        if (!response.ok || !payload?.ok || typeof payload.html !== 'string') throw new Error('Could not load release notes.');
        if (!viewer.isConnected || NOTE_REQUESTS.get(viewer) !== controller) return;
        target.innerHTML = payload.html;
        syncSelection(viewer, String(payload.version || version));
    } catch (error) {
        if (error.name !== 'AbortError' && viewer.isConnected && NOTE_REQUESTS.get(viewer) === controller) {
            const notice = document.createElement('p');
            notice.className = 'notice';
            notice.textContent = viewer.dataset.errorText || 'Could not load release notes. Please try again.';
            target.replaceChildren(notice);
        }
    } finally {
        if (NOTE_REQUESTS.get(viewer) === controller) {
            NOTE_REQUESTS.delete(viewer);
            target.removeAttribute('aria-busy');
        }
    }
}

/** Replace picker, badges and notes after activation. @param {HTMLElement} page Updates workspace. @param {{notes_html?:string,notes_count?:number}} payload Passive presentation response. @return {void} Cancels old selections and installs server-rendered notes. */
export function refreshUpdateNotes(page, payload) {
    const container = page?.querySelector('[data-update-patch-notes]');
    if (!container || typeof payload.notes_html !== 'string') return;
    const oldViewer = container.querySelector('[data-patch-notes-viewer]');
    if (oldViewer) { NOTE_REQUESTS.get(oldViewer)?.abort(); NOTE_REQUESTS.delete(oldViewer); }
    container.innerHTML = payload.notes_html;
    const diagnostics = page.querySelector('[data-update-api-status]');
    if (diagnostics && typeof payload.api_html === 'string') {
        const open = diagnostics.querySelector('details')?.open;
        diagnostics.innerHTML = payload.api_html;
        const details = diagnostics.querySelector('details');
        if (details) details.open = Boolean(open);
    }
    const badge = page.querySelector('[aria-controls="admin-update-tab-notes"] .admin-tab-badge');
    if (badge && Number.isInteger(payload.notes_count)) badge.textContent = String(payload.notes_count);
}

/** Install delegated handlers once, including dynamically replaced pickers. @return {void} Preserves drawer and browser URL. */
export function setupAdminUpdateNotes() {
    if (document.documentElement.dataset.adminUpdateNotesReady === '1') return;
    document.documentElement.dataset.adminUpdateNotesReady = '1';
    document.addEventListener('click', /** Handle picker controls. @param {MouseEvent} event User interaction. @return {void} Opens a picker or reads a version. */ event => {
        const control = event.target instanceof Element ? event.target.closest('[data-patch-version], [data-patch-notes-picker-button]') : null;
        const viewer = control?.closest('[data-patch-notes-viewer]');
        if (viewer) {
            event.preventDefault();
            if (control.matches('[data-patch-notes-picker-button]')) setPickerOpen(viewer, control.getAttribute('aria-expanded') !== 'true');
            else loadVersion(viewer, control.dataset.patchVersion || '');
        }
        for (const current of document.querySelectorAll('[data-patch-notes-viewer]')) {
            if (!current.querySelector('[data-patch-notes-picker]')?.contains(event.target)) setPickerOpen(current, false);
        }
    });
    document.addEventListener('submit', /** Handle the no-JavaScript compatible notes form. @param {SubmitEvent} event Form intent. @return {void} Reads the chosen section in place. */ event => {
        if (!event.target.matches?.('[data-patch-notes-form]')) return;
        event.preventDefault();
        const viewer = event.target.closest('[data-patch-notes-viewer]');
        loadVersion(viewer, viewer.querySelector('[data-patch-notes-input]')?.value || '');
    });
    document.addEventListener('change', /** Handle the native accessible selector. @param {Event} event Selection change. @return {void} Reads a release. */ event => {
        if (event.target.matches?.('[data-patch-notes-select]')) loadVersion(event.target.closest('[data-patch-notes-viewer]'), event.target.value);
    });
    document.addEventListener('keydown', /** Close open pickers with Escape. @param {KeyboardEvent} event Keyboard input. @return {void} Collapses selectors. */ event => {
        if (event.key === 'Escape') for (const viewer of document.querySelectorAll('[data-patch-notes-viewer]')) setPickerOpen(viewer, false);
    });
}
