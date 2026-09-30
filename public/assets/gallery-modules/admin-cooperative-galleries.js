/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-cooperative-galleries.js
 * Module Type: Browser Module
 * Purpose: Keep friendship and album proposal forms and state refresh inside their current page or drawer.
 * Responsibilities: Delegate dynamic forms and preserve canonical mutation completion and panel ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20260902-create-delete-hotfix1';
import {captureAdminPanelOwner} from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

/** Capture ownership of this exact fragment and optional drawer intent.
 * @param {HTMLElement} root Submitted component.
 * @return {function(): boolean} Predicate checked before visible effects.
 */
function cooperativeOwner(root) {
    const panel = root.closest('[data-admin-side-panel]');
    const owner = panel ? captureAdminPanelOwner(panel) : null;
    return /** Check both DOM lifetime and the shared drawer generation. @return {boolean} True for the originating live component. */ () =>
        root.isConnected && (!owner || owner.isCurrent());
}

/** Replace only the owned server-rendered component.
 * @param {HTMLElement} root Existing component.
 * @param {string} html Server-rendered fragment.
 * @param {boolean} preserveDrafts Whether a read-only refresh retains unresolved user input.
 * @param {string} clearDraft Successfully completed composition form to reset.
 * @return {HTMLElement|false} Installed component, or false when the fragment is invalid.
 */
function replaceCooperativePanel(root, html, preserveDrafts = false, clearDraft = '') {
    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-cooperative-panel]');
    if (!fresh) return false;
    if (preserveDrafts) {
        for (const name of ['base_url', 'request_id', 'invitation_code']) {
            const previous = root.querySelector('[name="' + name + '"]');
            const next = fresh.querySelector('[name="' + name + '"]');
            if (previous && next) next.value = previous.value;
        }
    }
    for (const previousForm of root.querySelectorAll('[data-cooperative-draft]')) {
        const draft = previousForm.dataset.cooperativeDraft;
        if (draft === clearDraft) continue;
        const nextForm = Array.from(fresh.querySelectorAll('[data-cooperative-draft]')).find(
            /** Match a server-defined draft slot, never a user-supplied selector.
             * @param {HTMLFormElement} item Candidate form.
             * @return {boolean} Whether this form owns the same draft.
             */ item => item.dataset.cooperativeDraft === draft);
        if (!nextForm) continue;
        for (const previous of previousForm.querySelectorAll('[data-cooperative-retain]')) {
            const next = nextForm.elements.namedItem(previous.name);
            if (!next || !('value' in next)) continue;
            if (previous instanceof HTMLSelectElement && next instanceof HTMLSelectElement && previous.value
                && !Array.from(next.options).some(/** Check for an option on the new source page. @param {HTMLOptionElement} option Source choice. @return {boolean} Whether its identity matches. */ option => option.value === previous.value)) {
                const selected = previous.selectedOptions[0];
                if (selected) next.add(selected.cloneNode(true));
            }
            next.value = previous.value;
            if (previous instanceof HTMLInputElement && previous.type === 'checkbox') next.checked = previous.checked;
        }
    }
    const previousCode = root.querySelector('[name="reference_code"]');
    const nextCode = fresh.querySelector('[name="reference_code"]');
    if (previousCode && nextCode && !nextCode.value) nextCode.value = previousCode.value;
    root.replaceWith(fresh);
    fresh.querySelector('[data-cooperative-heading]')?.focus();
    return fresh;
}

/** Execute a form or read-only refresh without navigating or cancelling a submitted write.
 * @param {HTMLElement} root Owning component.
 * @param {HTMLFormElement|HTMLAnchorElement} control Submitted form or read-only link.
 * @param {number} remaining Maximum remaining automatic operations for this explicit click.
 * @return {Promise<void>} Settles after in-place completion or a retained-input error.
 */
async function runCooperativeRequest(root, control, remaining = 65) {
    if (!root.isConnected) return;
    if (root.dataset.cooperativePending === '1') return;
    const current = cooperativeOwner(root);
    const form = control instanceof HTMLFormElement;
    const body = form ? new FormData(control) : null;
    if (body) body.set('ajax', '1');
    const url = new URL(form ? control.action : control.href, window.location.href);
    if (!form) url.searchParams.set('panel', '1');
    const controls = Array.from(root.querySelectorAll('input, textarea, button, select'));
    const disabled = controls.map(/** Capture native disabled state before the pending lock. @param {HTMLInputElement|HTMLTextAreaElement|HTMLButtonElement|HTMLSelectElement} item Owned control. @return {boolean} Original disabled value. */ item => item.disabled);
    root.dataset.cooperativePending = '1';
    root.setAttribute('aria-busy', 'true');
    controls.forEach(/** Lock only the submitted component. @param {HTMLInputElement|HTMLTextAreaElement|HTMLButtonElement|HTMLSelectElement} item Owned control. @return {void} Disables editing during the request. */ item => { item.disabled = true; });
    const status = root.querySelector('[data-cooperative-status]');
    const error = root.querySelector('[data-cooperative-error-message]');
    status.textContent = root.dataset.cooperativeBusy;
    error.textContent = '';
    try {
        const response = await fetch(url.href, {
            method: form ? 'POST' : 'GET', body, credentials: 'same-origin',
            headers: {'Accept': form ? 'application/json' : 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
        });
        if (!form) {
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (current() && !replaceCooperativePanel(root, html, true)) throw new Error();
            return;
        }
        const payload = await response.json();
        if (!response.ok || payload.ok !== true) {
            if (current()) error.textContent = typeof payload.message === 'string' ? payload.message : root.dataset.cooperativeError;
            return;
        }
        // The unchanged server envelope goes through the shared coordinator.
        // Only rendering our own fragment belongs to this workflow.
        let replacement = null;
        const completion = await completeAdminMutation(payload, {
            /** Install a fragment only while both ownership guards remain current.
             * @param {Object<string, unknown>} panel Canonical panel metadata.
             * @param {Object<string, unknown>} envelope Normalized mutation metadata.
             * @param {{isCurrent: function(): boolean}} guard Shared completion guard.
             * @return {boolean} Whether synchronization succeeded or became irrelevant.
             */
            refreshPanel: (panel, envelope, guard) => {
                if (!current() || !guard.isCurrent()) return true;
                const clear = payload.mutation?.action === 'compose' ? 'proposal'
                    : payload.mutation?.action === 'expand' ? 'expand-' + body.get('group_id') : '';
                replacement = typeof payload.panel_html === 'string' && replaceCooperativePanel(root, payload.panel_html, false, clear);
                return Boolean(replacement);
            },
        });
        if (replacement && remaining > 0 && ['synchronize', 'decline'].includes(body.get('action')) && replacement.isConnected) {
            const next = Array.from(replacement.querySelectorAll('[data-cooperative-action="synchronize"]')).find(
                /** Continue only the group chosen by the original click.
                 * @param {HTMLFormElement} candidate Current server-rendered action.
                 * @return {boolean} Whether it belongs to the same collaboration.
                 */ candidate => candidate.dataset.cooperativeGroup === body.get('group_id'));
            if (next) await runCooperativeRequest(replacement, next, remaining - 1);
        }
        if (current() && completion.panel?.synchronized === false) {
            error.textContent = root.dataset.cooperativeError;
        }
    } catch {
        if (current()) error.textContent = root.dataset.cooperativeError;
    } finally {
        if (current()) {
            status.textContent = '';
            root.removeAttribute('aria-busy');
            delete root.dataset.cooperativePending;
            controls.forEach(/** Restore original native state without enabling a blocked retry. @param {HTMLInputElement|HTMLTextAreaElement|HTMLButtonElement|HTMLSelectElement} item Original control. @param {number} index Original control index. @return {void} Restores the captured value. */ (item, index) => { item.disabled = disabled[index]; });
        }
    }
}

/** Bind capture-phase delegation once; newly rendered forms need no rebinding.
 * @return {void} Installs handlers without initiating network traffic.
 */
export function setupAdminCooperativeGalleries() {
    if (document.body.dataset.cooperativeBound === '1') return;
    document.body.dataset.cooperativeBound = '1';
    document.addEventListener('submit', /** Claim only cooperative forms before generic form handlers. @param {SubmitEvent} event Delegated submit. @return {void} Starts one owned request. */ event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-cooperative-form]')) return;
        const root = form.closest('[data-cooperative-panel]');
        if (!root) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void runCooperativeRequest(root, form);
    }, true);
    document.addEventListener('click', /** Keep read-only navigation in the same component. @param {MouseEvent} event Delegated click. @return {void} Starts a bounded owned GET. */ event => {
        const link = event.target instanceof Element ? event.target.closest('[data-cooperative-refresh]') : null;
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        const root = link.closest('[data-cooperative-panel]');
        if (!root) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void runCooperativeRequest(root, link);
    }, true);
}
