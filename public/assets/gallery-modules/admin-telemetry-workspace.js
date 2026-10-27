/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-telemetry-workspace.js
 * Module Type: Browser Module
 * Purpose: Keep telemetry navigation, contextual help and scoped saves in place.
 * Responsibilities: Preserve native links/forms and require verified completions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/**
 * Bound one telemetry preference request before requiring a passive reload.
 * Type: number. Units: milliseconds. Scope: one scoped preference write.
 * Consumers: saveTelemetryPreference(). Value: 15000.
 * Rationale: match the existing Admin uncertain-write contract without retries.
 */
const TELEMETRY_SAVE_TIMEOUT_MS = 15000;

/**
 * Keep help clear of its trigger and the viewport boundary.
 * Type: number. Units: CSS pixels. Scope: telemetry contextual help geometry.
 * Consumers: positionTelemetryHelp(). Value: 8.
 * Rationale: match the Features and Maintenance help panel edge spacing.
 */
const TELEMETRY_HELP_GAP_PX = 8;

/**
 * Position one open help panel beside its trigger inside the visible viewport.
 * @param {HTMLElement} panel Open native popover.
 * @param {HTMLButtonElement} trigger Associated question-mark button.
 * @return {void} Set bounded fixed coordinates without moving page content.
 */
function positionTelemetryHelp(panel, trigger) {
    const gap = TELEMETRY_HELP_GAP_PX;
    const rect = trigger.getBoundingClientRect();
    const below = rect.bottom + gap;
    panel.style.left = `${Math.max(gap, Math.min(rect.left,
        window.innerWidth - panel.offsetWidth - gap))}px`;
    panel.style.top = `${Math.max(gap, below + panel.offsetHeight <= window.innerHeight - gap
        ? below : rect.top - panel.offsetHeight - gap)}px`;
}

/**
 * Show the current form's persistence state without replacing its native input.
 * @param {HTMLFormElement} form Owning scoped preference form.
 * @param {'saving'|'saved'|'error'|'unknown'} state Verified or uncertain outcome.
 * @param {string} message Localized safe feedback.
 * @return {void} Update the live region and offer passive reload for unknown writes.
 */
function telemetryPreferenceFeedback(form, state, message) {
    const root = form.closest('[data-telemetry-workspace]');
    const feedback = form.querySelector('[data-telemetry-feedback]');
    form.dataset.saveState = state;
    feedback.textContent = message;
    if (state === 'unknown') {
        const reload = document.createElement('button');
        reload.type = 'button';
        reload.textContent = root.dataset.reloadLabel;
        reload.addEventListener('click', () => window.location.reload());
        feedback.append(' ', reload);
    }
}

/**
 * Persist exactly one preference and apply only a verified canonical scalar.
 * @param {HTMLFormElement} form Form with one allowlisted native preference.
 * @param {function({public_enabled:boolean|null,admin_excluded:boolean|null}):void} updateStatus Apply current
 *     public-collection/admin-exclusion states when this is the latest request.
 * @return {Promise<void>} Resolve after confirmation or an explicitly uncertain write.
 */
async function saveTelemetryPreference(form, updateStatus) {
    if (form.dataset.saveState === 'saving' || form.dataset.saveState === 'unknown') return;
    if (!form.reportValidity()) return;
    const root = form.closest('[data-telemetry-workspace]');
    const input = form.querySelector('[data-telemetry-input]');
    const body = new FormData(form);
    body.set(input.name, input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
    body.set('ajax', '1');
    const controls = Array.from(form.elements).filter(control => !control.disabled);
    controls.forEach(control => { control.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    telemetryPreferenceFeedback(form, 'saving', root.dataset.savingLabel);
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), TELEMETRY_SAVE_TIMEOUT_MS);
    let verified = false;
    try {
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', credentials: 'same-origin', body,
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: controller.signal,
        });
        const result = await response.json();
        const value = result.setting_values?.[input.name];
        const scalarValid = input.type === 'checkbox'
            ? value === '0' || value === '1'
            : typeof value === 'string' && /^\d+$/.test(value)
                && Number(value) >= Number(input.min) && Number(value) <= Number(input.max);
        if (result.confirmed !== true || !scalarValid
            || typeof result.ok !== 'boolean' || typeof result.message !== 'string'
            || response.ok !== result.ok) throw new Error('Unverified telemetry completion.');
        if (input.type === 'checkbox') input.checked = value === '1';
        else input.value = value;
        if (result.ok) await completeAdminMutation(result);
        verified = true;
        updateStatus({public_enabled: result.public_enabled, admin_excluded: result.admin_excluded});
        telemetryPreferenceFeedback(form, result.ok ? 'saved' : 'error', result.message);
    } catch {
        updateStatus({public_enabled: null, admin_excluded: null});
        telemetryPreferenceFeedback(form, 'unknown', root.dataset.failedLabel);
    } finally {
        clearTimeout(timeout);
        form.removeAttribute('aria-busy');
        if (verified) controls.forEach(control => { control.disabled = false; });
    }
}

/**
 * Enhance telemetry navigation, help and preference forms on their owning page.
 * @return {void} Install one workspace while native forms/links remain fallbacks.
 */
export function setupAdminTelemetryWorkspace() {
    const root = document.querySelector('[data-telemetry-workspace]');
    if (!root || root.dataset.telemetryBound === '1') return;
    root.dataset.telemetryBound = '1';
    const tabs = Array.from(root.querySelectorAll('[data-telemetry-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-telemetry-panel]'));
    if (tabs.length) {
        root.querySelector('.telemetry-tabs').setAttribute('role', 'tablist');
        const activate = (name, push = false) => {
            const selected = tabs.find(tab => tab.dataset.telemetryTab === name) || tabs[0];
            tabs.forEach(tab => {
                const active = tab === selected;
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-selected', String(active));
                tab.setAttribute('aria-controls', `telemetry-panel-${tab.dataset.telemetryTab}`);
                tab.tabIndex = active ? 0 : -1;
                if (active) tab.setAttribute('aria-current', 'page');
                else tab.removeAttribute('aria-current');
            });
            panels.forEach(panel => {
                panel.hidden = panel.dataset.telemetryPanel !== selected.dataset.telemetryTab;
                panel.setAttribute('role', 'tabpanel');
            });
            if (push) history.pushState(null, '', selected.href);
        };
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', event => {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
                event.preventDefault();
                activate(tab.dataset.telemetryTab, true);
            });
            tab.addEventListener('keydown', event => {
                const next = event.key === 'Home' ? tabs[0]
                    : event.key === 'End' ? tabs[tabs.length - 1]
                        : event.key === 'ArrowRight' ? tabs[(index + 1) % tabs.length]
                            : event.key === 'ArrowLeft' ? tabs[(index + tabs.length - 1) % tabs.length] : null;
                if (!next) return;
                event.preventDefault();
                activate(next.dataset.telemetryTab, true);
                next.focus();
            });
        });
        const restoreTab = () => activate(new URL(window.location.href).searchParams.get('telemetry_tab'));
        window.addEventListener('popstate', restoreTab);
        restoreTab();
    }
    if (typeof fetch === 'function' && typeof AbortController === 'function') {
        // Sequence is a request identity, not a time/count policy. Older saves
        // cannot replace the header state returned by a newer user intention.
        let statusGeneration = 0;
        for (const form of root.querySelectorAll('[data-telemetry-preference]')) {
            const input = form.querySelector('[data-telemetry-input]');
            if (!input || input.disabled) continue;
            const save = () => {
                if (form.dataset.saveState === 'saving' || form.dataset.saveState === 'unknown') return;
                if (!form.reportValidity()) return;
                const generation = ++statusGeneration;
                saveTelemetryPreference(form, result => {
                    if (generation !== statusGeneration) return;
                    root.querySelector('[data-telemetry-public-status]').textContent =
                        result.public_enabled === true ? root.dataset.enabledLabel
                            : result.public_enabled === false ? root.dataset.disabledLabel : '—';
                    if (typeof result.admin_excluded === 'boolean') {
                        root.querySelector('[data-telemetry-admin-status]').hidden = !result.admin_excluded;
                    }
                });
            };
            input.addEventListener('change', save);
            form.addEventListener('submit', event => { event.preventDefault(); save(); });
            form.querySelector('[data-telemetry-save]').hidden = true;
        }
    }
    if (!('showPopover' in HTMLElement.prototype)) return;
    for (const details of root.querySelectorAll('[data-dashboard-help]')) {
        const summary = details.querySelector('summary');
        const panel = details.querySelector('.admin-dashboard-help-panel');
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'telemetry-help-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
        trigger.setAttribute('popovertarget', panel.id);
        trigger.setAttribute('aria-controls', panel.id);
        panel.setAttribute('popover', 'auto');
        details.replaceWith(trigger, panel);
    }
    const positionOpenHelp = () => {
        root.querySelectorAll('.admin-dashboard-help-panel:popover-open').forEach(panel => {
            const trigger = root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger) positionTelemetryHelp(panel, trigger);
        });
    };
    root.addEventListener('toggle', positionOpenHelp, true);
    window.addEventListener('resize', positionOpenHelp);
    window.addEventListener('scroll', positionOpenHelp, true);
}
