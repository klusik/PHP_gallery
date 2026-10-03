/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-quick-access.js
 * Module Type: Browser Module
 * Purpose: Complete embedded gallery visibility and password actions in place.
 * Responsibilities:
 *   - Delegate embedded visibility and current-gallery password controls without nested forms.
 *   - Preserve canonical mutation completion and request authoritative Admin fragment refresh.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20260920-admin-interaction-policy-v1';
import {setGalleryRowHiddenReason} from './admin-core.js?v=20260512-modular-admin-v1';

const installedRoots = new WeakSet();
const pendingRows = new WeakSet();
const openPopups = new Map();

/**
 * Clamp a fixed popup beside its native trigger, including the last scrollable table row.
 *
 * @param {HTMLElement} popup Owned popup kept within its original form DOM.
 * @param {HTMLElement} trigger Summary or password toggle anchoring the popup.
 * @return {void}
 */
function positionPopup(popup, trigger) {
    const margin = 8;
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;
    popup.style.setProperty('position', 'fixed', 'important');
    popup.style.setProperty('inset', 'auto', 'important');
    popup.style.setProperty('margin', '0', 'important');
    popup.style.setProperty('max-width', `${Math.max(0, viewportWidth - margin * 2)}px`, 'important');
    popup.style.setProperty('max-height', `${Math.max(0, viewportHeight - margin * 2)}px`, 'important');
    popup.style.setProperty('min-width', `min(150px, ${Math.max(0, viewportWidth - margin * 2)}px)`, 'important');
    popup.style.setProperty('box-sizing', 'border-box', 'important');
    popup.style.setProperty('overflow', 'auto', 'important');
    popup.style.setProperty('z-index', '2147483000', 'important');
    const anchor = trigger.getBoundingClientRect();
    const bounds = popup.getBoundingClientRect();
    const left = Math.max(margin, Math.min(anchor.left, viewportWidth - bounds.width - margin));
    const below = anchor.bottom + 4;
    const top = below + bounds.height <= viewportHeight - margin ? below
        : Math.max(margin, Math.min(anchor.top - bounds.height - 4, viewportHeight - bounds.height - margin));
    popup.style.setProperty('left', `${left}px`, 'important');
    popup.style.setProperty('top', `${top}px`, 'important');
}

/**
 * Close an owned top-layer popup without moving its native controls or losing a draft.
 *
 * @param {HTMLElement} popup Owned embedded options or password editor.
 * @return {void}
 */
function closePopup(popup) {
    if (typeof popup.hidePopover === 'function' && popup.matches(':popover-open')) popup.hidePopover();
    popup.style.setProperty('display', 'none', 'important');
    if (popup.matches('[data-admin-gallery-password-editor]')) popup.hidden = true;
    openPopups.delete(popup);
}

/**
 * Open an embedded control in the browser top layer with a fixed-position fallback.
 *
 * @param {HTMLElement} popup Owned options or editor; remains under the bulk form.
 * @param {HTMLElement} trigger Native control retaining keyboard and details behavior.
 * @return {void}
 */
function openPopup(popup, trigger) {
    popup.hidden = false;
    popup.style.setProperty('display', 'flex', 'important');
    if (typeof popup.showPopover === 'function') {
        popup.setAttribute('popover', 'manual');
        if (!popup.matches(':popover-open')) popup.showPopover();
    }
    openPopups.set(popup, trigger);
    positionPopup(popup, trigger);
}

/**
 * Reposition active controls after viewport or ancestor scrolling, removing detached fragments.
 *
 * @return {void}
 */
function positionOpenPopups() {
    openPopups.forEach(
        /** Maintain owned popup positioning and lifetime. @param {HTMLElement} trigger Native anchor. @param {HTMLElement} popup Owned popup. @return {void} */
        (trigger, popup) => {
            if (!popup.isConnected || !trigger.isConnected) openPopups.delete(popup);
            else positionPopup(popup, trigger);
        }
    );
}

/**
 * Apply acknowledged credential-free state to the existing row's controls.
 *
 * @param {HTMLElement} row Existing gallery table row.
 * @param {Record<string, *>} state Server-owned safe gallery state.
 * @return {void}
 */
function applyState(row, state) {
    if (Number(state.id) !== Number(row.dataset.galleryId)) return;
    if (['public', 'unpublished', 'private'].includes(state.visibility)) {
        row.dataset.galleryVisibility = state.visibility;
        const pill = row.querySelector('.admin-gallery-status-pill');
        if (pill) {
            ['public', 'unpublished', 'private'].forEach(
                /** Match the visual marker to acknowledged visibility. @param {string} value Canonical visibility state. @return {void} */
                (value) => pill.classList.toggle(`is-${value}`, value === state.visibility)
            );
            if (typeof state.visibility_label === 'string') pill.textContent = state.visibility_label;
        }
        const filter = row.closest('form')?.querySelector('[data-gallery-visibility-filter]');
        const matches = !filter || (filter.value || 'all') === 'all' || filter.value === state.visibility;
        setGalleryRowHiddenReason(row, 'filter', !matches);
        if (!matches) {
            row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach(
                /** Remove only this mismatching row from bulk selection. @param {HTMLInputElement} checkbox This row's stale hidden selection. @return {void} */
                (checkbox) => { checkbox.checked = false; }
            );
        }
    }
    row.querySelectorAll('[data-admin-gallery-visibility], [data-admin-gallery-password]').forEach(
        /** Update the exact revision of both row controls. @param {HTMLElement} owner Owned quick control. @return {void} */
        (owner) => { owner.dataset.editRevision = String(state.edit_revision || ''); }
    );
    const password = row.querySelector('[data-admin-gallery-password]');
    if (password) {
        password.dataset.passwordEnabled = state.own_password_enabled ? '1' : '0';
        const toggle = password.querySelector('[data-admin-gallery-password-toggle]');
        if (toggle && typeof state.access_label === 'string') toggle.textContent = state.access_label;
    }
    const visibility = row.querySelector('[data-admin-gallery-visibility]');
    if (visibility) {
        visibility.querySelectorAll('[data-admin-gallery-visibility-choice]').forEach(
            /** Mark the acknowledged visibility choice as current. @param {HTMLButtonElement} choice Visibility choice. @return {void} */
            (choice) => {
                const current = choice.dataset.visibility === state.visibility;
                choice.classList.toggle('is-current', current);
                choice.setAttribute('aria-pressed', current ? 'true' : 'false');
            }
        );
        const icon = visibility.querySelector('summary .public-admin-visibility-icon');
        if (icon) {
            ['public', 'unpublished', 'private'].forEach(
                /** Match the visual marker to acknowledged visibility. @param {string} value Canonical visibility. @return {void} */
                (value) => icon.classList.toggle(`public-admin-visibility-icon-${value}`, value === state.visibility)
            );
        }
        visibility.open = false;
        const popup = visibility.querySelector('.public-admin-visibility-options');
        if (popup) closePopup(popup);
    }
    document.dispatchEvent(new Event('galleryRowsChanged'));
}

/**
 * Send a single owned action and preserve the canonical completion response unchanged.
 *
 * @param {HTMLElement} owner Prepared quick control with endpoint and revision.
 * @param {string} action Canonical visibility or password enable/disable action.
 * @param {string} password Plaintext held only for this request.
 * @return {Promise<void>} Resolves after the request, acknowledged state, and shared completion settle.
 */
async function mutate(owner, action, password = '') {
    const row = owner.closest('tr[data-gallery-id]');
    if (!row || pendingRows.has(row)) return;
    pendingRows.add(row);
    const status = row.querySelector('[data-admin-gallery-quick-status]');
    const buttons = [...row.querySelectorAll('[data-admin-gallery-visibility-choice], [data-admin-gallery-password-toggle], [data-admin-gallery-password-save]')];
    const originalDisabled = buttons.map(
        /** Preserve the initial disabled state for later restoration. @param {HTMLButtonElement} button Owned native button. @return {boolean} */
        (button) => button.disabled
    );
    buttons.forEach(/** Lock native row controls during the request. @param {HTMLButtonElement} button Owned native button. @return {void} */ (button) => { button.disabled = true; });
    owner.setAttribute('aria-busy', 'true');
    if (status) status.textContent = '';
    const data = new FormData();
    const csrf = owner.closest('form')?.querySelector('input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]');
    if (csrf) data.set('csrf_token', csrf.value);
    data.set('gallery_id', owner.dataset.galleryId || row.dataset.galleryId || '');
    data.set('edit_revision', owner.dataset.editRevision || '');
    data.set('action', action);
    if (action === 'enable') data.set('password', password);
    try {
        const response = await fetch(owner.dataset.actionUrl, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(String(result.message || owner.dataset.errorMessage || 'Save failed.'));
        if (result.gallery_state) applyState(row, result.gallery_state);
        const input = row.querySelector('[data-admin-gallery-password-input]');
        if (input) input.value = '';
        const editor = row.querySelector('[data-admin-gallery-password-editor]');
        if (editor) closePopup(editor);
        if (status) status.textContent = String(result.message || '');
        await completeAdminMutation(result);
        document.dispatchEvent(new CustomEvent('adminGalleryWorkspaceMutation', {detail: result}));
    } catch (error) {
        if (status) status.textContent = error instanceof Error ? error.message : String(owner.dataset.errorMessage || 'Save failed.');
    } finally {
        pendingRows.delete(row);
        owner.removeAttribute('aria-busy');
        buttons.forEach(
            /** Restore each control to its original availability. @param {HTMLButtonElement} button Owned button. @param {number} index Original disabled-state index. @return {void} */
            (button, index) => { button.disabled = originalDisabled[index]; }
        );
    }
}

/**
 * Install capture delegation once, covering dynamically refreshed gallery rows.
 *
 * @param {Document|HTMLElement} root Stable delegation root; defaults to the document.
 * @return {void}
 */
export function setupAdminGalleryQuickAccess(root = document) {
    if (installedRoots.has(root)) return;
    installedRoots.add(root);
    root.querySelectorAll('[data-admin-gallery-visibility] .public-admin-visibility-options').forEach(
        /** Hide only embedded options before their first open. @param {HTMLElement} popup Embedded options; public card menus are excluded. @return {void} */
        (popup) => closePopup(popup)
    );
    root.addEventListener('toggle',
        /** Synchronize the owned popup with native details state. @param {Event} event Captured native details state change. @return {void} */
        (event) => {
            const owner = event.target;
            if (!(owner instanceof HTMLDetailsElement) || !owner.matches('[data-admin-gallery-visibility]')) return;
            const popup = owner.querySelector('.public-admin-visibility-options');
            const trigger = owner.querySelector('summary');
            if (!popup || !trigger) return;
            if (owner.open) openPopup(popup, trigger);
            else closePopup(popup);
        }, true
    );
    root.addEventListener('scroll', positionOpenPopups, true);
    window.addEventListener('resize', positionOpenPopups);
    root.addEventListener('click',
        /** Intercept owned choices and dismiss controls outside the click. @param {MouseEvent} event Delegated native click. @return {void} */
        (event) => {
            openPopups.forEach(
                /** Maintain owned popup positioning and lifetime. @param {HTMLElement} trigger Native anchor. @param {HTMLElement} popup Owned popup. @return {void} */
                (trigger, popup) => {
                    const owner = trigger.closest('[data-admin-gallery-visibility], [data-admin-gallery-password]');
                    if (event.target instanceof Node && owner && !owner.contains(event.target)) {
                        closePopup(popup);
                        if (owner instanceof HTMLDetailsElement) owner.open = false;
                    }
                }
            );
            const button = event.target instanceof Element ? event.target.closest('[data-admin-gallery-visibility-choice], [data-admin-gallery-password-toggle], [data-admin-gallery-password-save]') : null;
            if (!button || !root.contains(button)) return;
            const owner = button.closest('[data-admin-gallery-visibility], [data-admin-gallery-password]');
            if (!owner) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (button.disabled || pendingRows.has(owner.closest('tr[data-gallery-id]'))) return;
            if (button.matches('[data-admin-gallery-visibility-choice]')) {
                void mutate(owner, button.dataset.visibility || '');
            } else if (button.matches('[data-admin-gallery-password-toggle]') && owner.dataset.passwordEnabled === '1') {
                void mutate(owner, 'disable');
            } else {
                const editor = owner.querySelector('[data-admin-gallery-password-editor]');
                const input = owner.querySelector('[data-admin-gallery-password-input]');
                if (button.matches('[data-admin-gallery-password-toggle]')) {
                    if (editor) {
                        if (editor.hidden) openPopup(editor, button);
                        else closePopup(editor);
                    }
                    if (editor && !editor.hidden) input?.focus();
                } else if (input) {
                    if (input.value.trim() === '') {
                        const status = owner.closest('tr[data-gallery-id]')?.querySelector('[data-admin-gallery-quick-status]');
                        if (status) status.textContent = String(owner.dataset.passwordRequired || 'Enter a gallery password.');
                        input.focus();
                    } else if (input.reportValidity()) {
                        void mutate(owner, 'enable', input.value);
                    }
                }
            }
        }, true
    );
    root.addEventListener('keydown',
        /** Preserve local keyboard save and cancellation without bulk submission. @param {KeyboardEvent} event Password editor keyboard action. @return {void} */
        (event) => {
            const visibilityOwner = event.target instanceof Element ? event.target.closest('[data-admin-gallery-visibility]') : null;
            if (event.key === 'Escape' && visibilityOwner) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const popup = visibilityOwner.querySelector('.public-admin-visibility-options');
                if (popup) closePopup(popup);
                visibilityOwner.open = false;
                visibilityOwner.querySelector('summary')?.focus();
                return;
            }
            const passwordOwner = event.target instanceof Element ? event.target.closest('[data-admin-gallery-password]') : null;
            if (event.key === 'Escape' && passwordOwner) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const editor = passwordOwner.querySelector('[data-admin-gallery-password-editor]');
                if (editor) closePopup(editor);
                passwordOwner.querySelector('[data-admin-gallery-password-toggle]')?.focus();
                return;
            }
            if (!(event.target instanceof Element) || !event.target.matches('[data-admin-gallery-password-input]')) return;
            const owner = event.target.closest('[data-admin-gallery-password]');
            if (!owner) return;
            if (event.key === 'Enter') {
                event.preventDefault();
                event.stopImmediatePropagation();
                owner.querySelector('[data-admin-gallery-password-save]')?.click();
            }
        }, true
    );
}
