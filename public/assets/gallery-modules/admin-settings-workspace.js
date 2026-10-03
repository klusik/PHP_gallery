/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-settings-workspace.js
 * Module Type: Browser Module
 * Purpose: Own Settings category navigation, drafts and scoped in-place saves.
 * Responsibilities: Preserve unrelated forms, accessible history navigation and canonical completions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {completeAdminMutation} from './admin-mutation-completion.js?v=20260920-admin-interaction-policy-v1';

/**
 * Capture submitted values by canonical setting identifier, excluding CSRF and routing fields.
 * @param {HTMLFormElement} form Settings form whose controls are currently enabled.
 * @return {Map<string, string>} Comparable values, including unchecked setting groups.
 */
export function captureAdminSettingsValues(form) {
    const values = new Map();
    for (const control of form.elements) {
        const match = String(control.name || '').match(/^settings\[([^\]]+)\]/);
        if (match) values.set(match[1], []);
    }
    for (const [name, value] of new FormData(form)) {
        const match = name.match(/^settings\[([^\]]+)\]/);
        if (match) values.get(match[1])?.push([name, String(value)]);
    }
    return new Map(Array.from(values, /** Serialize one composite setting. @param {[string, Array<[string,string]>]} pair Identifier and submitted entries. @return {[string,string]} Comparable setting value. */ ([name, entries]) => [name, JSON.stringify(entries)]));
}

/**
 * Count changed settings rather than individual controls in composite language preferences.
 * @param {Map<string, string>} baseline Last server-rendered values.
 * @param {Map<string, string>} current Current form values.
 * @return {number} Number of changed canonical settings.
 */
export function countAdminSettingsChanges(baseline, current) {
    return Array.from(new Set([...baseline.keys(), ...current.keys()]))
        .filter(/** Compare a canonical setting. @param {string} name Identifier. @return {boolean} Whether it changed. */ (name) => baseline.get(name) !== current.get(name)).length;
}

/**
 * Attach a single Settings workspace; no other Admin navigation or form is changed.
 * @param {HTMLElement} root Server-rendered Settings page.
 * @return {void} Installs category, keyboard, history and form listeners once.
 */
function setupAdminSettingsWorkspaceRoot(root) {
    if (root.dataset.adminSettingsWorkspaceBound === '1') return;
    const tabs = Array.from(root.querySelectorAll('[data-admin-settings-navigation] [data-admin-tab-target]'));
    const panels = Array.from(root.querySelectorAll('.admin-settings-section[data-admin-tab-panel]'));
    if (!tabs.length || panels.length !== tabs.length) return;
    root.dataset.adminSettingsWorkspaceBound = '1';
    root.classList.add('is-enhanced');
    const selector = root.querySelector('[data-admin-settings-category]');
    const formStates = new WeakMap();
    const mobilePending = new WeakSet();

    /**
     * Replace only the mobile connection fragment, leaving Settings drafts untouched.
     * @param {HTMLElement} owner Current mobile fragment.
     * @param {string} html Controller-rendered replacement markup.
     * @return {void} Installs the new fragment after checking its ownership marker.
     */
    const replaceMobile = (owner, html) => {
        const fragment = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-admin-settings-mobile]');
        if (!fragment) throw new Error('Incomplete mobile connection response');
        if (owner.isConnected) owner.replaceWith(fragment);
    };

    /**
     * Show a safe operation message within the mobile connection manager.
     * @param {HTMLElement} owner Mobile fragment owning the operation.
     * @param {string} message Localized feedback text.
     * @return {void} Displays escaped feedback without changing another form.
     */
    const mobileFeedback = (owner, message) => {
        let feedback = owner.querySelector('[data-admin-settings-mobile-feedback]');
        if (!feedback) {
            feedback = document.createElement('p');
            feedback.dataset.adminSettingsMobileFeedback = '';
            feedback.setAttribute('role', 'status');
            owner.append(feedback);
        }
        feedback.textContent = message;
        feedback.hidden = false;
    };

    /**
     * Load mobile connections only when the Uploads category becomes visible.
     * @return {Promise<void>} Settles one deduplicated read of the owned fragment.
     */
    const loadMobile = async () => {
        const owner = root.querySelector('[data-admin-settings-mobile][data-mobile-load-url]');
        if (!owner || mobilePending.has(owner)) return;
        const settingsForm = owner.closest('.admin-settings-section')?.querySelector('[data-admin-settings-form]');
        mobilePending.add(owner);
        owner.setAttribute('aria-busy', 'true');
        if (settingsForm) updateDraft(settingsForm);
        try {
            const response = await fetch(owner.dataset.mobileLoadUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'text/html'},
            });
            if (!response.ok) throw new Error('Mobile connections unavailable');
            replaceMobile(owner, await response.text());
        } catch (error) {
            mobileFeedback(owner, owner.dataset.failedLabel || root.querySelector('[data-admin-settings-form]')?.dataset.failedLabel || 'Could not load mobile connections.');
        } finally {
            mobilePending.delete(owner);
            owner.removeAttribute('aria-busy');
            if (settingsForm?.isConnected) updateDraft(settingsForm);
        }
    };

    /** Select a category from URL history, accepting the historical Website address deep link. @return {string} Canonical visible category. */
    const sectionFromUrl = () => {
        const url = new URL(window.location.href);
        const hashSection = url.hash.startsWith('#settings-') ? url.hash.slice(10) : '';
        const section = hashSection || url.searchParams.get('section') || 'general';
        return section === 'site' ? 'general' : section;
    };

    /** Switch visible controls without replacing any form or its unsaved values. @param {string} section Category identifier. @param {{history?:boolean,focus?:boolean,scroll?:boolean}} options Navigation intent. @return {void} Preserves all category drafts. */
    const activate = (section, options = {}) => {
        const target = panels.find(/** Locate a category panel. @param {HTMLElement} panel Candidate. @return {boolean} Whether its ID matches. */ (panel) => panel.id === `settings-${section}`) || panels[0];
        const selected = tabs.find(/** Locate the panel's tab. @param {HTMLAnchorElement} tab Candidate. @return {boolean} Whether it owns the target. */ (tab) => tab.dataset.adminTabTarget === target.id);
        tabs.forEach(/** Synchronize accessible tab state. @param {HTMLAnchorElement} tab Category tab. @return {void} Updates selection and keyboard entry. */ (tab) => {
            const active = tab === selected;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(/** Show only the selected category. @param {HTMLElement} panel Category panel. @return {void} Preserves its form DOM. */ (panel) => {
            panel.hidden = panel !== target;
            panel.classList.toggle('is-active', panel === target);
        });
        if (selector instanceof HTMLSelectElement) selector.value = target.id.slice(9);
        if (options.history && selected instanceof HTMLAnchorElement) {
            const next = new URL(selected.href, window.location.href);
            if (next.href !== window.location.href) window.history.pushState(null, '', next.href);
        }
        if (options.focus) selected?.focus();
        if (options.scroll) target.scrollIntoView({block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
        if (target.id === 'settings-uploads') void loadMobile();
    };

    /** Update the active form's savebar and category badge without changing other drafts. @param {HTMLFormElement} form Category form. @return {void} Updates changed-setting counts. */
    const updateDraft = (form) => {
        const state = formStates.get(form);
        if (!state || state.saving) return;
        const count = countAdminSettingsChanges(state.baseline, captureAdminSettingsValues(form));
        const hasErrors = Boolean(form.querySelector('[aria-invalid="true"]'));
        const languageStatus = form.querySelector('[data-settings-language-status]');
        if (languageStatus) {
            languageStatus.textContent = form.elements.namedItem('settings[public_language_selector_enabled]')?.checked
                ? languageStatus.dataset.enabledLabel : languageStatus.dataset.disabledLabel;
        }
        form.querySelector('[data-admin-settings-changes]').textContent = count
            ? String(form.dataset.changesLabel).replace('{count}', String(count)) : form.dataset.cleanLabel;
        form.querySelector('[data-admin-settings-savebar]').classList.toggle('is-dirty', count > 0);
        form.querySelector('[data-admin-settings-save]').disabled = (count === 0 && !hasErrors)
            || Boolean(form.closest('.admin-settings-section')?.querySelector('[data-admin-settings-mobile][aria-busy="true"]'));
        form.querySelector('[data-admin-settings-reset]').hidden = count === 0;
        const badge = root.querySelector(`[data-admin-settings-draft-badge="${CSS.escape(form.dataset.adminSettingsForm)}"]`);
        if (badge) {
            badge.hidden = count === 0;
            badge.textContent = count ? String(count) : '';
            if (count) badge.setAttribute('aria-label', String(form.dataset.changesLabel).replace('{count}', String(count)));
            else badge.removeAttribute('aria-label');
        }
    };

    /** Remove previous validation messages before an edit, reset or save attempt. @param {HTMLFormElement} form Category form. @return {void} Removes stale validation state. */
    const clearErrors = (form) => {
        form.querySelectorAll('[aria-invalid="true"]').forEach(/** Clear field validity. @param {HTMLElement} control Invalid field. @return {void} Removes invalid status. */ (control) => control.removeAttribute('aria-invalid'));
        form.querySelectorAll('[data-admin-settings-field-error]').forEach(/** Clear one inline message. @param {HTMLElement} message Error output. @return {void} Hides stale text. */ (message) => {
            message.textContent = '';
            message.hidden = true;
        });
        form.querySelectorAll('.has-error').forEach(/** Clear error styling. @param {HTMLElement} row Setting row. @return {void} Removes stale styling. */ (row) => row.classList.remove('has-error'));
        form.querySelector('[data-admin-settings-errors]').hidden = true;
    };

    /** Show escaped validation text in the current form. @param {HTMLFormElement} form Category form. @param {Record<string,string|Array<string>>} errors Field errors. @param {string} message Summary text. @return {void} Opens invalid disclosures and marks fields for focus after unlocking. */
    const showErrors = (form, errors, message) => {
        const summary = form.querySelector('[data-admin-settings-errors]');
        summary.replaceChildren(document.createTextNode(message));
        const list = document.createElement('ul');
        let firstControl = null;
        for (const [id, error] of Object.entries(errors || {})) {
            for (const text of Array.isArray(error) ? error : [error]) {
                const item = document.createElement('li');
                item.textContent = String(text);
                list.append(item);
            }
            const field = form.querySelector(`#${CSS.escape(`admin-setting-result-${id}`)}`);
            if (field) {
                field.classList.add('has-error');
                const control = field.querySelector('input:not([type="hidden"]), select');
                if (control) {
                    control.setAttribute('aria-invalid', 'true');
                    firstControl ||= control;
                }
                const inline = field.querySelector('[data-admin-settings-field-error]');
                if (inline) {
                    inline.textContent = String(error);
                    inline.hidden = false;
                    const describedBy = new Set((control?.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
                    describedBy.add(inline.id);
                    control?.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
                }
                let parent = field;
                while (parent && parent !== form) {
                    if (parent instanceof HTMLDetailsElement) parent.open = true;
                    parent = parent.parentElement;
                }
            }
        }
        if (list.childElementCount) summary.append(list);
        summary.hidden = false;
        if (firstControl) firstControl.dataset.settingsErrorFocus = '1';
    };

    /** Save one form once, keeping pending requests and drafts in other categories independent. @param {HTMLFormElement} form Category form. @return {Promise<void>} Settles after restoring controls or installing the saved fragment. */
    const save = async (form) => {
        const state = formStates.get(form);
        if (!state || state.saving || !form.reportValidity()) return;
        const panel = form.closest('.admin-settings-section');
        if (panel.querySelector('[data-admin-settings-mobile][aria-busy="true"]')) return;
        const feedback = panel.querySelector('[data-admin-settings-feedback]');
        const body = new FormData(form);
        clearErrors(form);
        feedback.hidden = true;
        state.saving = true;
        form.setAttribute('aria-busy', 'true');
        const controls = Array.from(form.elements).map(/** Remember each control's disabled state. @param {HTMLInputElement} control Form control. @return {[HTMLInputElement,boolean]} State to restore. */ (control) => [control, control.disabled]);
        for (const [control] of controls) control.disabled = true;
        form.querySelector('[data-admin-settings-changes]').textContent = form.dataset.savingLabel;
        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST', body, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const result = await response.json();
            if (result.section !== form.dataset.adminSettingsForm) throw new Error('Unexpected Settings response');
            if (!result.ok) {
                showErrors(form, result.errors, result.message || form.dataset.failedLabel);
                return;
            }
            if (!response.ok || typeof result.html !== 'string') throw new Error('Incomplete Settings response');
            // The shared coordinator owns mutation completion; Settings only replaces its own form.
            await completeAdminMutation(result);
            if (result.legacy_nav_changed && typeof result.sidebar_html === 'string') {
                const sidebar = new DOMParser().parseFromString(result.sidebar_html, 'text/html').querySelector('aside.admin-sidebar');
                if (sidebar) document.querySelector('aside.admin-sidebar')?.replaceWith(sidebar);
            }
            const disclosureStates = Array.from(form.querySelectorAll('details[id]')).map(/** Preserve disclosure visibility across saved markup. @param {HTMLDetailsElement} details Disclosure. @return {[string,boolean]} Stable ID and open state. */ (details) => [details.id, details.open]);
            panel.querySelector('[data-admin-settings-content]').innerHTML = result.html;
            disclosureStates.forEach(/** Restore a returned disclosure. @param {[string,boolean]} pair ID and previous open state. @return {void} Restores matching elements. */ ([id, open]) => {
                const details = panel.querySelector(`#${CSS.escape(id)}`);
                if (details instanceof HTMLDetailsElement) details.open = open;
            });
            bindForms();
            feedback.replaceChildren(document.createTextNode(result.message));
            if (result.address_url) {
                const link = document.createElement('a');
                link.href = result.address_url;
                link.textContent = form.dataset.addressLabel;
                feedback.append(link);
            }
            if (result.language_url && !result.address_url) {
                const link = document.createElement('a');
                link.href = result.language_url;
                link.textContent = form.dataset.languageLabel;
                feedback.append(link);
            }
            feedback.classList.remove('is-error');
            feedback.hidden = false;
        } catch (error) {
            feedback.textContent = form.dataset.failedLabel;
            feedback.classList.add('is-error');
            feedback.hidden = false;
        } finally {
            controls.forEach(/** Unlock each original control. @param {[HTMLInputElement,boolean]} pair Control and prior state. @return {void} Restores prior availability. */ ([control, disabled]) => { control.disabled = disabled; });
            state.saving = false;
            form.removeAttribute('aria-busy');
            if (form.isConnected) {
                updateDraft(form);
                const invalid = form.querySelector('[data-settings-error-focus]');
                invalid?.focus();
                invalid?.removeAttribute('data-settings-error-focus');
            }
        }
    };

    /**
     * Complete mobile connection mutations in place through the shared coordinator.
     * @param {HTMLFormElement} form Dynamically rendered create or delete form.
     * @return {Promise<void>} Restores controls or replaces only the mobile manager.
     */
    const saveMobile = async (form) => {
        const owner = form.closest('[data-admin-settings-mobile]');
        const settingsForm = form.closest('.admin-settings-section')?.querySelector('[data-admin-settings-form]');
        if (!owner || mobilePending.has(owner) || formStates.get(settingsForm)?.saving || !form.reportValidity()) return;
        const body = new FormData(form);
        body.set('ajax', '1');
        const controls = Array.from(owner.querySelectorAll('input, select, button')).map(
            /** Remember the manager's available controls. @param {HTMLInputElement} control Form control. @return {[HTMLInputElement,boolean]} Prior disabled state. */
            (control) => [control, control.disabled],
        );
        mobilePending.add(owner);
        owner.setAttribute('aria-busy', 'true');
        controls.forEach(/** Lock the manager while one mutation is pending. @param {[HTMLInputElement,boolean]} pair Control and prior state. @return {void} Prevents duplicate submissions. */ (pair) => { pair[0].disabled = true; });
        if (settingsForm) updateDraft(settingsForm);
        const feedback = owner.querySelector('[data-admin-settings-mobile-feedback]');
        if (feedback) feedback.hidden = true;
        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST', body, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const result = await response.json();
            if (!result.ok) {
                mobileFeedback(owner, result.message || owner.dataset.failedLabel || form.dataset.failedLabel);
                return;
            }
            if (!response.ok || typeof result.html !== 'string') throw new Error('Incomplete mobile connection response');
            await completeAdminMutation(result);
            replaceMobile(owner, result.html);
        } catch (error) {
            mobileFeedback(owner, owner.dataset.failedLabel || form.dataset.failedLabel || settingsForm?.dataset.failedLabel || 'Could not save mobile connections.');
        } finally {
            mobilePending.delete(owner);
            owner.removeAttribute('aria-busy');
            controls.forEach(/** Restore each original control. @param {[HTMLInputElement,boolean]} pair Control and prior state. @return {void} Unlocks after errors. */ (pair) => { pair[0].disabled = pair[1]; });
            if (settingsForm?.isConnected) updateDraft(settingsForm);
        }
    };

    root.addEventListener('submit', /**
     * Intercept current and dynamically returned mobile forms before generic handlers.
     * @param {SubmitEvent} event Submission from the Settings workspace.
     * @return {void} Keeps connection actions in the current category.
     */ (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-settings-mobile-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void saveMobile(form);
    }, true);

    /** Bind only newly returned forms; existing category drafts keep their original baselines. @return {void} Adds listeners once per form instance. */
    const bindForms = () => {
        root.querySelectorAll('[data-admin-settings-form]').forEach(/** Attach the category lifecycle to a new form. @param {HTMLFormElement} form Candidate form. @return {void} Preserves existing baselines. */ (form) => {
            if (!(form instanceof HTMLFormElement) || formStates.has(form)) return;
            formStates.set(form, {baseline: captureAdminSettingsValues(form), saving: false});
            /** Refresh draft state after editing. @return {void} Clears stale errors and recomputes changes. */
            const edited = () => { clearErrors(form); updateDraft(form); };
            form.addEventListener('input', edited);
            form.addEventListener('change', edited);
            form.addEventListener('submit', /** Own scoped saves before generic form handlers. @param {SubmitEvent} event Form submission. @return {void} Starts the asynchronous save. */ (event) => { event.preventDefault(); event.stopPropagation(); void save(form); });
            form.addEventListener('click', /** Observe delegated language design resets after the shared editor handles them. @param {MouseEvent} event Editor click. @return {void} Schedules draft recomputation after event dispatch. */ (event) => {
                if (event.target.closest('[data-language-design-reset-field], [data-language-design-reset-all], [data-language-design-reset-preset]')) queueMicrotask(edited);
            });
            form.querySelector('[data-admin-settings-reset]').addEventListener('click', /** Restore this category's saved values. @return {void} Leaves other category drafts intact. */ () => {
                form.reset();
                clearErrors(form);
                form.querySelectorAll('[data-language-design-field]').forEach(/** Refresh the shared language preview after reverting. @param {HTMLElement} control Editor field. @return {boolean} Event dispatch status. */ (control) => control.dispatchEvent(new Event('change', {bubbles: true})));
                updateDraft(form);
            });
            updateDraft(form);
        });
    };

    tabs.forEach(/** Attach accessible category navigation. @param {HTMLAnchorElement} tab Category tab. @param {number} index Position in tab order. @return {void} Installs activation and keyboard listeners. */ (tab, index) => {
        tab.addEventListener('click', /** Navigate while preserving native modified link clicks. @param {MouseEvent} event Tab click. @return {void} Activates in place for ordinary clicks. */ (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            activate(tab.dataset.adminTabTarget.slice(9), {history: true, scroll: true});
        });
        tab.addEventListener('keydown', /** Apply standard tab keyboard navigation. @param {KeyboardEvent} event Keyboard action. @return {void} Moves selection and focus. */ (event) => {
            let next = index;
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else if (event.key === ' ') { event.preventDefault(); tab.click(); return; }
            else return;
            event.preventDefault();
            activate(tabs[next].dataset.adminTabTarget.slice(9), {history: true, focus: true});
        });
    });
    selector?.addEventListener('change', /** Navigate from the mobile selector. @return {void} Preserves desktop tab state. */ () => activate(selector.value, {history: true}));
    window.addEventListener('popstate', /** Restore the category selected in browser history. @return {void} Preserves form drafts. */ () => activate(sectionFromUrl()));
    window.addEventListener('hashchange', /** Honor Settings fragment deep links. @return {void} Restores category visibility. */ () => activate(sectionFromUrl()));
    activate(sectionFromUrl());
    bindForms();
}

/** Initialize Settings workspaces in a document without attaching to unrelated Admin pages. @param {Document|HTMLElement} root Scope containing Settings. @return {void} Initializes each workspace once. */
export function setupAdminSettingsWorkspace(root = document) {
    root.querySelectorAll('[data-admin-settings-workspace]').forEach(setupAdminSettingsWorkspaceRoot);
}
