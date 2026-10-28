/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-trash.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances Admin Maintenance trash mutations without turning bounded server
 *   cleanup batches into one long-running PHP request.
 *
 * Responsibilities:
 *   - Submit restore and permanent-delete forms through their JSON mutation path
 *   - Repeat bounded Empty Trash batches until the server reports none remaining
 *   - Refresh only the Trash panel after each completed mutation workflow
 *   - Serialize preference autosave and reconcile uncertain state by passive reads
 *   - Keep native help available and enhance it to bounded contextual popovers
 *   - Preserve ordinary form submission as the no-JavaScript fallback
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Event delegation is required because Maintenance content can be lazy-loaded.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-07
 */

import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/**
 * Bound a preference write before requiring a passive authoritative reload.
 * Type: number. Units: milliseconds. Scope: one preference write/passive reload.
 * Consumers: saveAdminTrashSettings(), refreshAdminTrashPanel() for preferences.
 * Value: 15000.
 * Rationale: match the compact settings contract without replaying uncertain writes.
 */
const ADMIN_TRASH_SETTINGS_TIMEOUT_MS = 15000;

/**
 * Keep contextual help clear of the visible viewport and its own trigger.
 * Type: number. Units: CSS pixels. Scope: Trash help placement.
 * Consumers: positionAdminTrashHelp(). Value: 8.
 * Rationale: match Features and the other Maintenance help surfaces.
 */
const ADMIN_TRASH_HELP_GAP_PX = 8;

let adminTrashActionsBound = false;

/**
 * Return the Trash panel that owns a submitted mutation form.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {HTMLElement|null} Owning Trash panel, when present.
 */
function adminTrashPanelForForm(form) {
    const panel = form.closest('[data-admin-trash-panel]');
    return panel instanceof HTMLElement ? panel : null;
}

/**
 * Toggle all Trash mutation controls while one enhanced request is active.
 *
 * @param {HTMLElement|null} panel Owning Trash panel.
 * @param {boolean} busy Whether controls should be disabled.
 * @return {void} Locks submission and preference inputs within the owned panel.
 */
function setAdminTrashPanelBusy(panel, busy) {
    if (!(panel instanceof HTMLElement)) {
        return;
    }
    // Hidden CSRF/trash identities stay successful form controls. Preference
    // inputs also lock so a manual task cannot race a retention-policy write.
    panel.querySelectorAll('button, [data-admin-trash-settings-form] input:not([type="hidden"])').forEach((control) => {
        control.disabled = busy;
    });
    panel.dataset.adminTrashBusy = busy ? '1' : '0';
}

/**
 * Submit one Trash mutation through the canonical JSON response path.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {Promise<Record<string, *>>} Parsed mutation response.
 */
async function submitAdminTrashForm(form) {
    const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const text = await response.text();
    let payload = null;
    try {
        payload = JSON.parse(text);
    } catch (error) {
        throw new Error('The trash action returned an invalid server response.');
    }
    if (!payload || typeof payload !== 'object') {
        throw new Error('The trash action returned an invalid server response.');
    }
    if (!response.ok || payload.ok !== true) {
        throw new Error(String(payload.error || payload.message || 'The trash action failed.'));
    }
    return payload;
}

/**
 * Replace the current Trash panel with a fresh server-rendered fragment.
 *
 * @param {HTMLElement} panel Existing Trash panel.
 * @param {string} message Result message shown after the refresh.
 * @param {boolean} isError Whether the message represents an error.
 * @param {string} focusName Preference field to refocus after a settings save;
 *     empty preserves the existing destructive-task refresh behavior.
 * @return {Promise<void>} Installs authoritative state and restores a settings field when requested.
 */
async function refreshAdminTrashPanel(panel, message, isError = false, focusName = '') {
    const endpoint = String(panel.dataset.adminTrashRefreshUrl || '').trim();
    if (!endpoint) {
        throw new Error('Trash panel refresh URL is missing.');
    }
    const response = await fetch(endpoint, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        signal: focusName ? AbortSignal.timeout(ADMIN_TRASH_SETTINGS_TIMEOUT_MS) : undefined,
    });
    if (!response.ok) {
        throw new Error(`Trash panel refresh failed with HTTP ${response.status}.`);
    }
    const html = await response.text();
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const replacement = template.content.querySelector('[data-admin-trash-panel]');
    if (!(replacement instanceof HTMLElement)) {
        throw new Error('Trash panel refresh returned unexpected markup.');
    }
    const status = replacement.querySelector('[data-admin-trash-status]');
    if (status instanceof HTMLElement && message) {
        status.hidden = false;
        status.textContent = message;
        status.classList.toggle('error', isError);
    }
    panel.replaceWith(replacement);
    setupAdminTrashHelp(replacement);
    if (focusName) {
        const form = replacement.querySelector('[data-admin-trash-settings-form]');
        const feedback = form?.querySelector('[data-admin-trash-settings-status]');
        if (feedback) feedback.textContent = message;
        if (form) form.dataset.saveState = message ? 'saved' : 'idle';
        form?.querySelector(`input[name="${CSS.escape(focusName)}"]`)?.focus();
        if (status) status.hidden = true;
    }
}

/**
 * Expose a safe refusal/uncertain result with an explicit read-only reload.
 * @param {HTMLFormElement} form Locked preference form containing live feedback.
 * @param {string} message Localized safe refusal or unverified-result explanation.
 * @param {'error'|'unknown'} state Whether refusal or transport uncertainty ended the write.
 * @param {string} focusName Existing preference name to restore after passive reload.
 * @return {void} Keeps writes locked and offers only a GET-based panel reconciliation.
 */
function showAdminTrashSettingsFailure(form, message, state, focusName) {
    form.dataset.saveState = state;
    const feedback = form.querySelector('[data-admin-trash-settings-status]');
    feedback.textContent = message;
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.textContent = form.dataset.reloadLabel;
    reload.addEventListener('click', async () => {
        reload.disabled = true;
        try {
            await refreshAdminTrashPanel(adminTrashPanelForForm(form), '', false, focusName);
        } catch {
            reload.disabled = false;
        }
    });
    feedback.append(' ', reload);
}

/**
 * Persist coupled Trash preferences once through their existing guarded route.
 * @param {HTMLFormElement} form Existing POST/CSRF settings form, never a purge form.
 * @param {string} focusName Changed preference field restored after the canonical panel read.
 * @return {Promise<void>} Refreshes confirmed state in place or locks uncertain/refused writes.
 */
async function saveAdminTrashSettings(form, focusName) {
    const panel = adminTrashPanelForForm(form);
    if (!panel || panel.dataset.adminTrashBusy === '1' || !form.reportValidity()) return;
    const body = new FormData(form);
    body.set('ajax', '1');
    const controls = Array.from(form.querySelectorAll('input:not([type="hidden"])'));
    setAdminTrashPanelBusy(panel, true);
    form.dataset.saveState = 'saving';
    const feedback = form.querySelector('[data-admin-trash-settings-status]');
    feedback.textContent = form.dataset.savingLabel;
    form.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', body, credentials: 'same-origin',
            cache: 'no-store', redirect: 'error',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: AbortSignal.timeout(ADMIN_TRASH_SETTINGS_TIMEOUT_MS),
        });
        const payload = await response.json();
        if (typeof payload.ok !== 'boolean' || typeof payload.message !== 'string'
            || !payload.setting_values
            || controls.some(control => typeof payload.setting_values[control.name]
                !== (control.type === 'checkbox' ? 'boolean' : 'string'))
            || (!response.ok && payload.ok)) throw new Error('Unverified Trash settings response');
        for (const control of controls) {
            if (control.type === 'checkbox') control.checked = payload.setting_values[control.name];
            else control.value = payload.setting_values[control.name];
        }
        if (!payload.ok) {
            showAdminTrashSettingsFailure(form, payload.message, 'error', focusName);
            return;
        }
        await completeAdminMutation(payload);
        await refreshAdminTrashPanel(panel, form.dataset.savedLabel, false, focusName);
    } catch {
        // The server may have committed the guarded transition before a lost
        // response. Preserve intent; never retry or claim an automatic rollback.
        showAdminTrashSettingsFailure(form, form.dataset.failedLabel, 'unknown', focusName);
    } finally {
        form.removeAttribute('aria-busy');
    }
}

/**
 * Position one open native help popover beside its independent trigger.
 * @param {HTMLElement} panel Open help surface containing escaped explanatory text.
 * @param {HTMLButtonElement} trigger Plain question-mark help control.
 * @return {void} Bounds the readable panel within the current viewport.
 */
function positionAdminTrashHelp(panel, trigger) {
    const gap = ADMIN_TRASH_HELP_GAP_PX;
    const width = document.documentElement.clientWidth;
    const height = document.documentElement.clientHeight;
    const rect = trigger.getBoundingClientRect();
    panel.style.maxWidth = `${width - gap - gap}px`;
    panel.style.left = `${Math.max(gap, Math.min(rect.left, width - panel.offsetWidth - gap))}px`;
    const below = rect.bottom + gap;
    const top = below + panel.offsetHeight <= height - gap ? below : rect.top - panel.offsetHeight - gap;
    panel.style.top = `${Math.max(gap, Math.min(top, height - panel.offsetHeight - gap))}px`;
}

/**
 * Enhance native Trash help after initial/deferred rendering or panel replacement.
 * @param {Document|HTMLElement} root Document or newly installed Trash fragment.
 * @return {void} Preserves native disclosures without Popover API support.
 */
function setupAdminTrashHelp(root) {
    // Permanent no-script/older-browser support belongs to the native disclosure.
    if (typeof HTMLElement.prototype.showPopover !== 'function') return;
    for (const disclosure of root.querySelectorAll('[data-admin-trash-help]')) {
        const panel = disclosure.querySelector('.admin-trash-help-panel');
        const summary = disclosure.querySelector('summary');
        if (!panel?.id || !summary) continue;
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'admin-trash-help-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
        trigger.setAttribute('aria-controls', panel.id);
        trigger.setAttribute('popovertarget', panel.id);
        panel.setAttribute('popover', 'auto');
        disclosure.replaceWith(trigger, panel);
    }
}

/**
 * Return the best redirect fallback carried by one canonical mutation response.
 *
 * @param {Record<string, *>|null} payload Mutation response, when available.
 * @return {string} Fallback URL or the current page URL.
 */
function adminTrashFallbackUrl(payload) {
    const fallback = payload && typeof payload.fallback === 'object' ? payload.fallback : null;
    const redirect = String(fallback?.redirect_url || '').trim();
    return redirect || window.location.href;
}

/**
 * Run one restore or per-entry purge and refresh the Trash fragment in place.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {Promise<void>} Resolves after completion handling.
 */
async function runAdminTrashSingleMutation(form) {
    const panel = adminTrashPanelForForm(form);
    if (!(panel instanceof HTMLElement) || panel.dataset.adminTrashBusy === '1') {
        return;
    }
    setAdminTrashPanelBusy(panel, true);
    let payload = null;
    try {
        payload = await submitAdminTrashForm(form);
        await refreshAdminTrashPanel(panel, String(payload.message || ''));
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        try {
            await refreshAdminTrashPanel(panel, message, true);
        } catch (refreshError) {
            window.location.assign(adminTrashFallbackUrl(payload));
        }
    } finally {
        setAdminTrashPanelBusy(document.querySelector('[data-admin-trash-panel]'), false);
    }
}

/**
 * Run Empty Trash as repeated bounded HTTP requests until no purgeable row remains.
 *
 * @param {HTMLFormElement} form Empty Trash form.
 * @return {Promise<void>} Resolves after the final batch and panel refresh.
 */
async function runAdminTrashEmpty(form) {
    const panel = adminTrashPanelForForm(form);
    if (!(panel instanceof HTMLElement) || panel.dataset.adminTrashBusy === '1') {
        return;
    }
    setAdminTrashPanelBusy(panel, true);

    let totalPurged = 0;
    let payload = null;
    let finalMessage = '';
    try {
        while (true) {
            payload = await submitAdminTrashForm(form);
            const batch = payload.trash_batch && typeof payload.trash_batch === 'object' ? payload.trash_batch : null;
            if (!batch) {
                throw new Error('Empty Trash response is missing bounded-batch metadata.');
            }
            totalPurged += Math.max(0, Number(batch.purged || 0));
            const failed = Math.max(0, Number(batch.failed || 0));
            const remaining = Math.max(0, Number(batch.remaining || 0));
            finalMessage = String(payload.message || '');
            if (failed > 0 || remaining === 0) {
                break;
            }
        }

        const successTemplate = String(form.dataset.adminTrashSuccessTemplate || '').trim();
        if (successTemplate && payload?.trash_batch && Number(payload.trash_batch.failed || 0) === 0) {
            finalMessage = successTemplate.replace('{count}', String(totalPurged));
        }
        await refreshAdminTrashPanel(panel, finalMessage);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        try {
            await refreshAdminTrashPanel(panel, message, true);
        } catch (refreshError) {
            window.location.assign(adminTrashFallbackUrl(payload));
        }
    } finally {
        setAdminTrashPanelBusy(document.querySelector('[data-admin-trash-panel]'), false);
    }
}

/**
 * Attach delegated enhanced behavior for Admin Trash mutation forms.
 *
 * @return {void} Binds settings autosave, help and confirmed destructive actions once.
 */
export function setupAdminTrashActions() {
    if (adminTrashActionsBound) {
        return;
    }
    adminTrashActionsBound = true;
    document.addEventListener('change', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;
        const form = input.closest('[data-admin-trash-settings-form]');
        if (!form) return;
        event.stopImmediatePropagation();
        void saveAdminTrashSettings(form, input.name);
    }, true);
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-trash-settings-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void saveAdminTrashSettings(form, form.querySelector('input[type="checkbox"]').name);
    }, true);
    document.documentElement.dataset.adminTrashPreferencesReady = '1';
    setupAdminTrashHelp(document);
    new MutationObserver(() => setupAdminTrashHelp(document)).observe(document.body, {childList:true,subtree:true});
    const positionHelp = () => {
        for (const panel of document.querySelectorAll('.admin-trash-help-panel[popover]')) {
            if (!panel.matches(':popover-open')) continue;
            const trigger = document.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger instanceof HTMLButtonElement) positionAdminTrashHelp(panel, trigger);
        }
    };
    document.addEventListener('toggle', event => {
        if (event.target instanceof HTMLElement && event.target.classList.contains('admin-trash-help-panel')) positionHelp();
    }, true);
    document.addEventListener('scroll', positionHelp, true);
    window.addEventListener('resize', positionHelp);
    document.addEventListener('submit', /**
     * Respect earlier confirmation cancellation before dispatching delegated Trash actions.
     * @param {SubmitEvent} event Submitted form event, including native cancellation state.
     * @return {void} Starts only an approved enhanced mutation workflow.
     */ (event) => {
        // Inline confirmation can cancel submission before this delegated listener runs.
        if (event.defaultPrevented) {
            return;
        }
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.matches('[data-admin-trash-empty-form]')) {
            event.preventDefault();
            void runAdminTrashEmpty(form);
            return;
        }
        if (form.matches('[data-admin-trash-restore-form], [data-admin-trash-purge-form]')) {
            event.preventDefault();
            void runAdminTrashSingleMutation(form);
        }
    });
}
