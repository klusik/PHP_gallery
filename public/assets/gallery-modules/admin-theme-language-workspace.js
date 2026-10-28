/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-theme-language-workspace.js
 * Module Type: Browser Module
 * Purpose: Save scoped Language choices and anchor contextual help.
 * Responsibilities: Preserve other Theme drafts, canonical values and native fallback.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/**
 * Bound one write before requiring passive reload instead of retrying it.
 * Type: number. Units: milliseconds. Scope: one Theme Language preference write.
 * Consumers: saveLanguagePreference(). Value: 15000.
 * Rationale: match the existing Features/Maintenance uncertain-write contract.
 */
const LANGUAGE_SAVE_TIMEOUT_MS = 15000;

/**
 * Keep contextual help clear of its invoker and viewport edges.
 * Type: number. Units: CSS pixels. Scope: Theme Language help geometry.
 * Consumers: positionLanguageHelp(). Value: 8.
 * Rationale: reuse the compact Features/Maintenance panel spacing.
 */
const LANGUAGE_HELP_GAP_PX = 8;
const languageFlagSelects = new WeakMap();

/**
 * Refresh a flag select's visible value and disabled state from its native owner.
 * @param {HTMLSelectElement} select Original successful form control.
 * @return {void} Synchronize an enhanced control when one is registered.
 */
function refreshLanguageFlagSelect(select) {
    languageFlagSelects.get(select)?.refresh();
}

/**
 * Enhance one native language select with bundled SVG flags and a keyboard list.
 * @param {HTMLSelectElement} select Prepared language choices with flag URLs.
 * @return {void} Retain the named select and its existing change/form behavior;
 *     opening or navigating the popup never commits a language selection.
 */
function enhanceLanguageFlagSelect(select) {
    const options = Array.from(select.options);
    if (!options.length || !options.some(option => option.dataset.flagUrl)) return;
    const label = select.labels?.[0];
    const labelText = label ? Array.from(label.childNodes)
        .filter(node => node !== select).map(node => node.textContent).join('').trim() : select.name;
    const shell = document.createElement('span');
    shell.className = 'theme-language-flag-select';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'theme-language-flag-button';
    button.setAttribute('role', 'combobox');
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('div');
    panel.id = `${select.id}-flag-options`;
    panel.className = 'theme-language-flag-options';
    panel.setAttribute('popover', 'auto');
    panel.setAttribute('role', 'listbox');
    panel.setAttribute('aria-label', labelText);
    button.setAttribute('popovertarget', panel.id);
    button.setAttribute('aria-controls', panel.id);
    let active = select.selectedIndex;
    const items = options.map((option, index) => {
        const item = document.createElement('div');
        item.id = `${panel.id}-${index}`;
        item.className = 'theme-language-flag-option';
        item.setAttribute('role', 'option');
        if (option.dataset.flagUrl) {
            const flag = document.createElement('img');
            flag.src = option.dataset.flagUrl;
            flag.alt = '';
            flag.width = 16;
            flag.height = 12;
            item.append(flag);
        }
        item.append(document.createTextNode(option.textContent));
        item.addEventListener('click', event => {
            event.preventDefault();
            if (!option.disabled) choose(index);
        });
        panel.append(item);
        return item;
    });
    const markActive = () => {
        items.forEach((item, index) => {
            item.classList.toggle('is-active', index === active);
            item.setAttribute('aria-selected', String(index === select.selectedIndex));
        });
        button.setAttribute('aria-activedescendant', items[active].id);
    };
    const refresh = () => {
        const selected = options[select.selectedIndex];
        button.replaceChildren(...Array.from(items[select.selectedIndex].childNodes)
            .map(node => node.cloneNode(true)));
        button.setAttribute('aria-label', `${labelText}: ${selected.textContent}`);
        button.disabled = select.disabled;
        if (panel.matches(':popover-open')) markActive();
    };
    const choose = index => {
        const changed = select.selectedIndex !== index;
        select.value = options[index].value;
        panel.hidePopover();
        refresh();
        button.focus();
        if (changed) select.dispatchEvent(new Event('change', {bubbles: true}));
    };
    button.addEventListener('keydown', event => {
        if (event.key === 'Tab') {
            if (panel.matches(':popover-open')) panel.hidePopover();
            return;
        }
        const open = panel.matches(':popover-open');
        if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            if ((event.key === 'Enter' || event.key === ' ') && open) {
                choose(active);
                return;
            }
            if (!open) panel.showPopover();
            if (event.key === 'Home') active = 0;
            else if (event.key === 'End') active = options.length - 1;
            else if (event.key === 'ArrowDown') active = (active + 1) % options.length;
            else if (event.key === 'ArrowUp') active = (active + options.length - 1) % options.length;
            markActive();
        } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
            const index = options.findIndex(option => option.textContent.trim()
                .toLocaleLowerCase().startsWith(event.key.toLocaleLowerCase()));
            if (index >= 0) {
                event.preventDefault();
                if (!open) panel.showPopover();
                active = index;
                markActive();
            }
        }
    });
    panel.addEventListener('beforetoggle', event => {
        if (event.newState === 'open') active = select.selectedIndex;
    });
    panel.addEventListener('toggle', () => {
        const open = panel.matches(':popover-open');
        button.setAttribute('aria-expanded', String(open));
        if (open) {
            markActive();
            panel.style.width = `${button.getBoundingClientRect().width}px`;
            positionLanguageHelp(panel, button);
        } else {
            button.removeAttribute('aria-activedescendant');
        }
    });
    shell.append(button, panel);
    select.after(shell);
    select.classList.add('theme-language-flag-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.addEventListener('change', refresh);
    languageFlagSelects.set(select, {refresh});
    refresh();
}

/**
 * Resolve only this group's existing language preference controls.
 * @param {HTMLElement} group One admin, public-default or viewer-choice owner.
 * @return {Array<HTMLInputElement|HTMLSelectElement>} Native preference fields.
 */
function languagePreferenceControls(group) {
    return Array.from(group.querySelectorAll('select, input[type="checkbox"]'));
}

/**
 * Show a bounded persistence state and an explicit passive reload when needed.
 * @param {HTMLElement} group Owning preference group; never the shared form.
 * @param {'saving'|'saved'|'error'|'unknown'} state Observable completion state.
 * @param {string} message Localized safe feedback.
 * @return {void} Update the live status without replacing fields or other drafts.
 */
function languagePreferenceStatus(group, state, message) {
    const root = group.closest('.theme-language-workspace');
    let feedback = group.querySelector('[data-language-save-status]');
    if (!feedback) {
        feedback = document.createElement('span');
        feedback.dataset.languageSaveStatus = '';
        feedback.setAttribute('role', 'status');
        feedback.setAttribute('aria-live', 'polite');
        feedback.tabIndex = -1;
        group.append(feedback);
    }
    group.dataset.saveState = state;
    feedback.textContent = message;
    if (state === 'error' || state === 'unknown'
        || (state === 'saved' && group.dataset.themeLanguagePreferenceGroup === 'admin')) {
        const reload = document.createElement('button');
        reload.type = 'button';
        reload.textContent = state === 'saved'
            ? root.dataset.interfaceReloadLabel : root.dataset.reloadLabel;
        reload.addEventListener('click', () => window.location.reload());
        feedback.append(' ', reload);
    }
}

/**
 * Validate authoritative values for every submitted field before applying any.
 * @param {Array<HTMLInputElement|HTMLSelectElement>} controls Scoped fields.
 * @param {Object<string, boolean|string|Array<string>>} values Persisted values.
 * @return {boolean} Whether every scalar/list matches the owning control shape.
 */
function validLanguagePreferenceValues(controls, values) {
    if (!values || typeof values !== 'object') return false;
    return controls.every(control => {
        const key = control.name.replace(/\[\]$/, '');
        const value = values[key];
        if (control.name.endsWith('[]')) {
            return Array.isArray(value) && value.length > 0
                && value.every(code => typeof code === 'string'
                    && controls.some(peer => peer.name === control.name && peer.value === code));
        }
        if (control.type === 'checkbox') return typeof value === 'boolean';
        return typeof value === 'string'
            && Array.from(control.options).some(option => option.value === value);
    });
}

/**
 * Save one Language scope through its owning authenticated Theme POST route.
 * @param {HTMLElement} group Admin, public-default or coupled viewer choices.
 * @return {Promise<void>} Reconcile canonical values or lock until passive reload;
 *     never navigate, retry, submit files/design, or replace unrelated drafts.
 */
async function saveLanguagePreference(group) {
    if (['saving', 'error', 'unknown'].includes(group.dataset.saveState)) return;
    const root = group.closest('.theme-language-workspace');
    const form = group.closest('form');
    const controls = languagePreferenceControls(group);
    const body = new FormData();
    body.set('csrf_token', form.querySelector('[name="csrf_token"]').value);
    body.set('theme_language_preference_scope', group.dataset.themeLanguagePreferenceGroup);
    for (const control of controls) {
        if (control.type !== 'checkbox' || control.checked) {
            body.append(control.name, control.value);
        }
    }
    controls.forEach(control => {
        control.disabled = true;
        if (control instanceof HTMLSelectElement) refreshLanguageFlagSelect(control);
    });
    group.setAttribute('aria-busy', 'true');
    languagePreferenceStatus(group, 'saving', root.dataset.savingLabel);
    try {
        const response = await fetch(root.dataset.languagePreferenceUrl, {
            method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
            redirect: 'error', headers: {Accept: 'application/json'},
            signal: AbortSignal.timeout(LANGUAGE_SAVE_TIMEOUT_MS),
        });
        const result = await response.json();
        if (typeof result.ok !== 'boolean' || typeof result.message !== 'string'
            || (!response.ok && result.ok)
            || !validLanguagePreferenceValues(controls, result.setting_values)) {
            throw new Error('Unverified Language preference response');
        }
        if (result.ok) await completeAdminMutation(result);
        for (const control of controls) {
            const value = result.setting_values[control.name.replace(/\[\]$/, '')];
            if (control.name.endsWith('[]')) control.checked = value.includes(control.value);
            else if (control.type === 'checkbox') control.checked = value;
            else control.value = value;
            if (control instanceof HTMLSelectElement) refreshLanguageFlagSelect(control);
        }
        if (!result.ok) {
            languagePreferenceStatus(group, 'error', result.message);
            return;
        }
        languagePreferenceStatus(group, 'saved', root.dataset.savedLabel);
        controls.forEach(control => {
            control.disabled = false;
            if (control instanceof HTMLSelectElement) refreshLanguageFlagSelect(control);
        });
    } catch {
        // A response may be lost after persistence. Keep intent and block both
        // this group and the legacy full-form replay until a passive reload.
        languagePreferenceStatus(group, 'unknown', root.dataset.failedLabel);
    } finally {
        group.removeAttribute('aria-busy');
    }
}

/**
 * Fit an open help panel next to its native invoker in the visible client box.
 * @param {HTMLElement} panel Escaped contextual help promoted to a popover.
 * @param {HTMLButtonElement} trigger Owning plain question-mark control.
 * @return {void} Position below or above without moving the surrounding fields.
 */
function positionLanguageHelp(panel, trigger) {
    const gap = LANGUAGE_HELP_GAP_PX;
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
 * Enhance only Theme Language groups while preserving native shared-form saves.
 * @return {void} Bind autosave, replay exclusion and light-dismiss help once;
 *     designer drafts and explicit translation actions retain their owners.
 */
export function setupThemeLanguageWorkspace() {
    const root = document.querySelector('#admin-theme-tab-language .theme-language-workspace');
    const form = root?.closest('form');
    if (!root || root.dataset.languageAutosaveReady
        || !(form instanceof HTMLFormElement) || !form.querySelector('[name="csrf_token"]')) return;
    root.dataset.languageAutosaveReady = '1';
    root.addEventListener('change', event => {
        const control = event.target;
        if (!(control instanceof Element)) return;
        const group = control.closest('[data-theme-language-preference-group]');
        if (group && control.matches('select, input[type="checkbox"]')) saveLanguagePreference(group);
    });
    form.addEventListener('submit', event => {
        const locked = root.querySelector('[data-save-state="saving"], [data-save-state="error"], [data-save-state="unknown"]');
        if (!locked) return;
        event.preventDefault();
        document.querySelector('[data-admin-tab-target="admin-theme-tab-language"]')?.click();
        root.querySelector('[data-admin-subtab-target="admin-theme-language-subtab-settings"]')?.click();
        locked.querySelector('[data-language-save-status]')?.focus();
    }, true);
    for (const input of root.querySelectorAll('[data-theme-language-preference-group] input[title]')) {
        input.closest('label').title = input.title;
        input.setAttribute('aria-description', input.title);
    }
    if (!('showPopover' in HTMLElement.prototype)) return;
    root.querySelectorAll('select[data-language-flag-select]').forEach(enhanceLanguageFlagSelect);
    for (const details of root.querySelectorAll('[data-theme-language-help]')) {
        const summary = details.querySelector('summary');
        const panel = details.querySelector('.admin-language-help-panel');
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'admin-language-help-trigger';
        trigger.textContent = '?';
        trigger.setAttribute('aria-label', summary.getAttribute('aria-label'));
        trigger.setAttribute('popovertarget', panel.id);
        trigger.setAttribute('aria-controls', panel.id);
        panel.setAttribute('popover', 'auto');
        details.replaceWith(trigger, panel);
    }
    const positionOpenHelp = () => {
        for (const panel of root.querySelectorAll('.admin-language-help-panel:popover-open')) {
            const trigger = root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger instanceof HTMLButtonElement) positionLanguageHelp(panel, trigger);
        }
        for (const panel of root.querySelectorAll('.theme-language-flag-options:popover-open')) {
            const trigger = root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
            if (trigger instanceof HTMLButtonElement) positionLanguageHelp(panel, trigger);
        }
    };
    root.addEventListener('toggle', event => {
        if (event.target instanceof HTMLElement
            && event.target.matches('.admin-language-help-panel:popover-open')) positionOpenHelp();
    }, true);
    window.addEventListener('resize', positionOpenHelp);
    window.addEventListener('scroll', positionOpenHelp, true);
}
