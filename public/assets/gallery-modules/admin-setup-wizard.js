/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-setup-wizard.js
 * Module Type: JavaScript module
 * Purpose: Add progressive enhancement to the staged Admin setup wizard.
 * Responsibilities:
 *   - Synchronize inclusion checkboxes with submitted controls.
 *   - Preserve server-owned navigation while wiring previews and accessibility.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT
 */
import { setupThemeLivePreview } from './theme-form.js?v=20260929-setup-wizard-preview-v2';

/** Enable or disable one field's submitted values without losing its staged browser value.
 * @param {HTMLInputElement} include Inclusion checkbox.
 * @returns {void}
 */
function syncWizardField(include) {
    const field = include.closest('[data-wizard-field]');
    if (!field) return;
    field.classList.toggle('is-excluded', !include.checked);
    field.querySelectorAll('[data-wizard-value], [data-wizard-value-fallback]').forEach(
        /**
         * Synchronize one submitted control with the inclusion state.
         * @param {HTMLInputElement} control Field value control.
         * @returns {void}
         */
        (control) => {
        if (control.matches('[data-wizard-value]')) {
            if (!include.checked) {
                if (control.type === 'checkbox') control.dataset.stagedChecked = control.checked ? '1' : '0';
                else control.dataset.stagedValue = control.value;
                if (control.type === 'checkbox') control.checked = control.dataset.currentValue === '1';
                else control.value = control.dataset.currentValue || '';
                control.dispatchEvent(new Event('input', { bubbles: true }));
            } else if (control.type === 'checkbox' && control.dataset.stagedChecked !== undefined) {
                control.checked = control.dataset.stagedChecked === '1';
                control.dispatchEvent(new Event('input', { bubbles: true }));
            } else if (control.dataset.stagedValue !== undefined) {
                control.value = control.dataset.stagedValue;
                control.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        control.disabled = !include.checked;
        },
    );
}

/** Enable only the canonical language-selector values selected for this draft.
 * @param {HTMLElement} wizard Wizard root element.
 * @returns {void}
 */
function syncLanguageSelector(wizard) {
    wizard.querySelectorAll('[data-wizard-include-target]').forEach(
        /**
         * Synchronize one language selector inclusion control.
         * @param {HTMLInputElement} include Language inclusion checkbox.
         * @returns {void}
         */
        (include) => {
        const target = include.dataset.wizardIncludeTarget || '';
        const selectors = {
            public_language_selector_enabled: '[name="settings[public_language_selector_enabled]"]',
            public_language_selector_languages: '[name="settings[public_language_selector_languages][]"]',
            public_language_selector_design: '[name^="settings[public_language_selector_design]"]',
        };
            wizard.querySelectorAll(selectors[target] || '[data-no-wizard-target]').forEach(
                /**
                 * Apply language inclusion state to one control.
                 * @param {HTMLInputElement} control Language selector control.
                 * @returns {void}
                 */
                (control) => { control.disabled = !include.checked; },
            );
        },
    );
}

/** Activate one in-step subsection without submitting or mutating staged controls.
 * @param {HTMLElement} wizard Wizard root element.
 * @param {string} target Stable subsection identifier.
 * @returns {void}
 */
function activateWizardSubsection(wizard, target) {
    wizard.querySelectorAll('[data-wizard-subsection-panel]').forEach(
        /** @param {HTMLElement} panel Subsection panel. @returns {void} */
        (panel) => {
            const active = panel.dataset.wizardSubsectionPanel === target;
            panel.classList.toggle('is-active', active);
            panel.setAttribute?.('aria-hidden', active ? 'false' : 'true');
        },
    );
    wizard.querySelectorAll('[data-wizard-subsection-target]').forEach(
        /** @param {HTMLButtonElement} button Subsection navigation button. @returns {void} */
        (button) => {
            const active = button.dataset.wizardSubsectionTarget === target;
            button.classList.toggle('is-active', active);
            button.setAttribute?.('aria-selected', active ? 'true' : 'false');
        },
    );
}

/** Synchronize the final review's global unchanged-settings control with native details elements.
 * @param {HTMLElement} wizard Wizard root element.
 * @returns {void}
 */
function setupWizardSummaryDisclosure(wizard) {
    const toggle = wizard.querySelector('[data-wizard-summary-show-all]');
    if (!toggle) return;
    const details = Array.from(wizard.querySelectorAll('[data-wizard-summary-unchanged]'));
    const unchangedOnlyGroups = Array.from(wizard.querySelectorAll('[data-wizard-summary-unchanged-group]'));
    const syncUnchangedOnlyGroups = () => {
        for (const group of unchangedOnlyGroups) group.hidden = !toggle.checked;
    };
    syncUnchangedOnlyGroups();
    toggle.addEventListener('change', () => {
        for (const detail of details) detail.open = toggle.checked;
        syncUnchangedOnlyGroups();
    });
    for (const detail of details) {
        detail.addEventListener('toggle', () => {
            toggle.checked = details.length > 0 && details.every((item) => item.open);
            syncUnchangedOnlyGroups();
        });
    }
}

/**
 * Enhance one server-rendered wizard without taking over its navigation.
 * @param {Document|HTMLElement} root Search root containing the wizard.
 * @returns {void}
 */
export function setupAdminSetupWizard(root = document) {
    const wizard = root.querySelector('[data-admin-setup-wizard]');
    if (!wizard || wizard.dataset.wizardReady === '1') return;
    wizard.dataset.wizardReady = '1';
    wizard.classList.toggle('is-enhanced', true);
    const themeForm = wizard.querySelector('[data-theme-form]');
    if (themeForm) setupThemeLivePreview(themeForm);
    const initialSubsection = wizard.querySelector('[data-wizard-subsection-panel]');
    if (initialSubsection) activateWizardSubsection(wizard, initialSubsection.dataset.wizardSubsectionPanel || '');
    wizard.querySelectorAll('[data-wizard-subsection-target]').forEach(
        /**
         * Switch one visual subsection while preserving the entire staged form.
         * @param {HTMLButtonElement} button Subsection navigation button.
         * @returns {void}
         */
        (button) => {
            button.addEventListener('click', () => activateWizardSubsection(wizard, button.dataset.wizardSubsectionTarget || ''));
        },
    );
    setupWizardSummaryDisclosure(wizard);
    wizard.querySelectorAll('[data-wizard-progress-target]').forEach(
        /**
         * Attach server-owned goto navigation to one progress link.
         * @param {HTMLAnchorElement} link Progress link.
         * @returns {void}
         */
        (link) => {
        link.addEventListener('click',
            /**
             * Submit a temporary goto action while preserving the current form.
             * @param {MouseEvent} event Link click event.
             * @returns {void}
             */
            (event) => {
            const form = wizard.querySelector('[data-wizard-form]');
            if (!form) return;
            event.preventDefault();
            const action = document.createElement('input');
            action.type = 'hidden'; action.name = 'wizard_action'; action.value = 'goto';
            const target = document.createElement('input');
            target.type = 'hidden'; target.name = 'target_step'; target.value = link.dataset.wizardProgressTarget || '';
            form.append(action, target);
            const previousNoValidate = form.noValidate;
            form.noValidate = true;
            form.requestSubmit();
            queueMicrotask(
                /**
                 * Remove temporary navigation controls after submission.
                 * @returns {void}
                 */
                () => { action.remove(); target.remove(); form.noValidate = previousNoValidate; },
            );
        });
        },
    );
    wizard.querySelectorAll('[data-wizard-include]').forEach(
        /**
         * Initialize one wizard inclusion checkbox.
         * @param {HTMLInputElement} checkbox Inclusion checkbox.
         * @returns {void}
         */
        (checkbox) => {
        if (!checkbox.matches('[data-wizard-include-target]')) syncWizardField(checkbox);
        checkbox.addEventListener('change',
            /**
             * Reconcile field and language controls after inclusion changes.
             * @returns {void}
             */
            () => {
            if (!checkbox.matches('[data-wizard-include-target]')) syncWizardField(checkbox);
            syncLanguageSelector(wizard);
            },
        );
        },
    );
    syncLanguageSelector(wizard);
    wizard.querySelector('[data-wizard-error-summary]')?.focus();
}
