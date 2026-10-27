/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-theme-appearance-workspace.js
 * Module Type: Browser Module
 * Purpose: Enhance Appearance switches and anchored contextual explanations.
 * Responsibilities: Preserve other drafts, canonical values and native fallback.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/**
 * Bound a preference write before requiring passive state recovery.
 * Type: number. Units: milliseconds. Scope: one Appearance switch request.
 * Consumers: saveAppearanceSwitch(). Value: 15000.
 * Rationale: match Features and Language without retrying uncertain writes.
 */
const APPEARANCE_SAVE_TIMEOUT_MS = 15000;

/**
 * Keep contextual help clear of its invoker and viewport edges.
 * Type: number. Units: CSS pixels. Scope: Appearance help geometry.
 * Consumers: positionAppearanceHelp(). Value: 8.
 * Rationale: match the approved Features/visitor panel spacing.
 */
const APPEARANCE_HELP_GAP_PX = 8;

/**
 * Display one scoped completion state without replacing fields or preview nodes.
 * @param {HTMLElement} row One switch and its local status owner.
 * @param {'saving'|'saved'|'error'|'unknown'} state Current completion state.
 * @param {string} message Safe localized explanation.
 * @return {void} Updates live feedback and offers passive reload after failure.
 */
function appearanceSwitchStatus(row, state, message) {
    row.dataset.saveState = state;
    let feedback = row.querySelector('[data-appearance-save-status]');
    if (!feedback) {
        feedback = document.createElement('span');
        feedback.dataset.appearanceSaveStatus = '';
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');
        feedback.tabIndex = -1;
        row.append(feedback);
    }
    feedback.textContent = message;
    if (state === 'error' || state === 'unknown') {
        const reload = document.createElement('button');
        reload.type = 'button';
        reload.textContent = row.closest('.theme-appearance-workspace').dataset.reloadLabel;
        reload.addEventListener('click', () => window.location.reload());
        feedback.append(' ', reload);
    }
}

/**
 * Persist one binary preference without sending neighboring Theme/JSON drafts.
 * @param {HTMLInputElement} input Existing named checkbox with a scoped owner.
 * @return {Promise<void>} Applies a verified canonical value or locks until
 *     passive reload; uncertain writes retain intent and are never retried.
 */
async function saveAppearanceSwitch(input) {
    const row = input.closest('.theme-appearance-switch-row');
    if (['saving', 'error', 'unknown'].includes(row.dataset.saveState)) return;
    const root = row.closest('.theme-appearance-workspace');
    const form = input.form;
    const body = new FormData();
    body.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
    body.set('theme_appearance_switch', input.name);
    body.set('enabled', input.checked ? '1' : '0');
    const reset = row.querySelector('[data-theme-advanced-reset]');
    const focused = document.activeElement;
    input.disabled = true;
    if (reset) reset.disabled = true;
    row.setAttribute('aria-busy', 'true');
    appearanceSwitchStatus(row, 'saving', root.dataset.savingLabel);
    try {
        const response = await fetch(root.dataset.appearancePreferenceUrl, {
            method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
            redirect: 'error', headers: {Accept: 'application/json'},
            signal: AbortSignal.timeout(APPEARANCE_SAVE_TIMEOUT_MS),
        });
        const result = await response.json();
        if (typeof result.ok !== 'boolean' || typeof result.message !== 'string'
            || (!response.ok && result.ok)
            || typeof result.setting_values?.[input.name] !== 'boolean') {
            throw new Error('Unverified Appearance preference response');
        }
        if (result.ok) await completeAdminMutation(result);
        input.checked = result.setting_values[input.name];
        // Input refreshes the existing preview/dependencies without another save.
        input.dispatchEvent(new Event('input', {bubbles: true}));
        appearanceSwitchStatus(row, result.ok ? 'saved' : 'error',
            result.ok ? root.dataset.savedLabel : result.message);
        if (result.ok) {
            input.disabled = false;
            if (reset) reset.disabled = false;
            if (focused instanceof HTMLElement && document.activeElement === document.body) {
                focused.focus({preventScroll: true});
            }
        }
    } catch {
        // Persistence may have completed before the response was lost. Keep the
        // displayed intent and exclude legacy shared-form replay until reload.
        appearanceSwitchStatus(row, 'unknown', root.dataset.failedLabel);
    } finally {
        row.removeAttribute('aria-busy');
    }
}

/**
 * Fit one contextual explanation below or above its own question-mark trigger.
 * @param {HTMLElement} panel Open help promoted to the native top layer.
 * @param {HTMLButtonElement} trigger Owning question-mark action.
 * @return {void} Bounds the panel in the client viewport without moving rows.
 */
function positionAppearanceHelp(panel, trigger) {
    const gap = APPEARANCE_HELP_GAP_PX;
    const width = document.documentElement.clientWidth;
    const height = document.documentElement.clientHeight;
    const rect = trigger.getBoundingClientRect();
    panel.style.maxWidth = `${width - gap - gap}px`;
    const below = rect.bottom + gap;
    const top = below + panel.offsetHeight <= height - gap
        ? below : rect.top - panel.offsetHeight - gap;
    panel.style.left = `${Math.max(gap, Math.min(rect.left, width - panel.offsetWidth - gap))}px`;
    panel.style.top = `${Math.max(gap, Math.min(top, height - panel.offsetHeight - gap))}px`;
}

/**
 * Enhance all Appearance subsections while retaining shared-form draft authority.
 * @return {void} Binds isolated binary saves and native light-dismiss help once;
 *     colors, ranges, selects and text retain their existing explicit Save path.
 */
export function setupThemeAppearanceWorkspace() {
    const root = document.querySelector('#admin-theme-tab-appearance .theme-appearance-workspace');
    const form = root?.closest('form');
    if (!root || root.dataset.appearanceWorkspaceReady
        || !(form instanceof HTMLFormElement)) return;
    root.dataset.appearanceWorkspaceReady = '1';
    if (root.dataset.appearancePreferenceUrl && form.querySelector('[name="csrf_token"]')) {
        const hint = root.querySelector('.theme-appearance-save-hint');
        if (hint) hint.hidden = false;
        for (const input of root.querySelectorAll('input[data-theme-appearance-switch]')) {
            const label = input.closest('label') || root.querySelector(`label[for="${CSS.escape(input.id)}"]`);
            let row = input.closest('[data-theme-advanced-control]');
            if (!row) {
                row = document.createElement('div');
                label.before(row);
                row.append(label);
            }
            row.classList.add('theme-appearance-switch-row');
            if (label && input.title) label.title = input.title;
            input.addEventListener('change', () => saveAppearanceSwitch(input));
        }
        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            const locked = root.querySelector('[data-save-state="saving"], [data-save-state="error"], [data-save-state="unknown"]');
            if (!locked) return;
            event.preventDefault();
            document.querySelector('[data-admin-tab-target="admin-theme-tab-appearance"]')?.click();
            const panel = locked.closest('[data-admin-subtab-panel]');
            root.querySelector(`[data-admin-subtab-target="${CSS.escape(panel.id)}"]`)?.click();
            locked.querySelector('[data-appearance-save-status]')?.focus();
        }, true);
    }
    if (!('showPopover' in HTMLElement.prototype)) return;
    for (const details of root.querySelectorAll('[data-theme-appearance-help]')) {
        const summary = details.querySelector('summary');
        const panel = details.querySelector('.theme-appearance-help-panel');
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'theme-appearance-help-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
        trigger.setAttribute('popovertarget', panel.id);
        trigger.setAttribute('aria-controls', panel.id);
        panel.setAttribute('popover', 'auto');
        details.replaceWith(trigger, panel);
    }
    const positionOpenHelp = () => {
        for (const panel of root.querySelectorAll('.theme-appearance-help-panel:popover-open')) {
            const trigger = root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger instanceof HTMLButtonElement) positionAppearanceHelp(panel, trigger);
        }
    };
    root.addEventListener('toggle', event => {
        if (event.target instanceof HTMLElement
            && event.target.matches('.theme-appearance-help-panel:popover-open')) positionOpenHelp();
    }, true);
    window.addEventListener('resize', positionOpenHelp);
    window.addEventListener('scroll', positionOpenHelp, true);
}
