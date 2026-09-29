/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_setup_wizard_browser_test.mjs
 * Module Type: Node regression test
 * Purpose: Exercise Setup Wizard progressive enhancement with a deterministic DOM fake.
 * Responsibilities: Verify inclusion state, language controls, preview inputs, and navigation cleanup.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

/** Minimal browser Event substitute used by the deterministic DOM fixture. */
class FakeEvent {
    /** Construct one synthetic event.
     * @param {string} type Event name.
     * @param {{bubbles?: boolean}} options Event options.
     * @returns {void}
     */
    constructor(type, options = {}) { this.type = type; this.bubbles = !!options.bubbles; this.defaultPrevented = false; }
    /** Mark this synthetic event as cancelled. @returns {void} */
    preventDefault() { this.defaultPrevented = true; }
}
/** Minimal DOM element substitute covering the wizard's required surface. */
/** CSS class list substitute for the fixture element. */
class FakeClassList {
    /** Construct a class list bound to one fixture element.
     * @param {FakeElement} owner Owning element.
     * @returns {void}
     */
    constructor(owner) { this.owner = owner; }
    /** Set one class state on the owning element.
     * @param {string} name Class name.
     * @param {boolean} value Desired state.
     * @returns {void}
     */
    toggle(name, value) { this.owner.attrs[name] = value; }
}
/** Minimal DOM element substitute covering the wizard's required surface. */
class FakeElement {
    /** Construct one synthetic DOM element.
     * @param {Record<string, any>} attrs Fixture attributes.
     * @returns {void}
     */
    constructor(attrs = {}) { this.dataset = {...attrs}; this.attrs = attrs; this.children = []; this.listeners = {}; this.classList = new FakeClassList(this); this.checked = !!attrs.checked; this.disabled = false; this.value = attrs.value || ''; this.type = attrs.type || 'text'; this.name = attrs.name || ''; this.noValidate = false; }
    /** Append children to this fixture node.
     * @param {...FakeElement} items Children to append.
     * @returns {void}
     */
    append(...items) { this.children.push(...items); }
    /** Mark this fixture node as removed. @returns {void} */
    remove() { this.removed = true; }
    /** Register one event listener.
     * @param {string} type Event name.
     * @param {Function} callback Listener callback.
     * @returns {void}
     */
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    /** Dispatch one event to registered listeners.
     * @param {FakeEvent} event Event to dispatch.
     * @returns {boolean} Whether dispatch completed.
     */
    dispatchEvent(event) { for (const callback of this.listeners[event.type] || []) callback(event); return true; }
    /** Match one supported selector.
     * @param {string} selector Selector supported by this fixture.
     * @returns {boolean} Whether this node matches.
     */
    matches(selector) { return selector.includes('[data-admin-setup-wizard]') ? this.attrs.adminSetupWizard : selector.includes('[data-wizard-include]') ? this.attrs.include : selector.includes('[data-wizard-value]') ? this.attrs.valueControl : selector.includes('[data-wizard-include-target]') ? this.attrs.includeTarget : selector.includes('[data-wizard-progress-target]') ? this.attrs.progress : selector.includes('[data-wizard-form]') ? this.attrs.form : selector.includes('settings[public_language_selector_enabled]') ? this.name === 'settings[public_language_selector_enabled]' : false; }
    /** Find the matching fixture parent.
     * @param {string} selector Ancestor selector.
     * @returns {FakeElement|null} Matching parent.
     */
    closest(selector) { return selector === '[data-wizard-field]' ? this.parent : null; }
    /** Find the first matching child.
     * @param {string} selector Child selector.
     * @returns {FakeElement|null} First matching child.
     */
    querySelector(selector) {
        for (const child of this.children) {
            if (child.matches(selector)) return child;
        }
        return null;
    }
    /** Find all matching children.
     * @param {string} selector Child selector.
     * @returns {FakeElement[]} Matching children.
     */
    querySelectorAll(selector) {
        const matches = [];
        for (const child of this.children) if (child.matches(selector)) matches.push(child);
        return matches;
    }
    /** Submit this fixture form and capture its controls.
     * @returns {void}
     */
    requestSubmit() {
        this.submitted = true;
        this.submissionSnapshot = [];
        for (const child of this.children) {
            if (!child.removed) this.submissionSnapshot.push([child.name, child.value]);
        }
    }
}
/** Root fixture with document-style first-match lookup. */
class FakeRoot extends FakeElement {
    /** Find the first matching root child.
     * @param {string} selector Selector to match.
     * @returns {FakeElement|undefined} Matching child.
     */
    querySelector(selector) {
        for (const child of this.children) {
            if (child.matches(selector)) return child;
        }
        return undefined;
    }
}

/** Create one fixture element.
 * @returns {FakeElement} New fixture element.
 */
function fixtureCreateElement() { return new FakeElement(); }
/** Search the global fixture document.
 * @param {string} selector Selector to match.
 * @returns {null} No global match.
 */
function fixtureQuerySelector(selector) { return null; }
globalThis.Event = FakeEvent;
globalThis.document = { createElement: fixtureCreateElement, querySelector: fixtureQuerySelector };
const { setupAdminSetupWizard } = await import('../public/assets/gallery-modules/admin-setup-wizard.js?v=20260929-setup-wizard-v2');

const root = new FakeRoot();
const wizard = new FakeElement({adminSetupWizard: true}); wizard.dataset.adminSetupWizard = '1';
const form = new FakeElement({form: true});
const include = new FakeElement({include: true, checked: true});
const value = new FakeElement({valueControl: true, type: 'color', value: '#112233'}); value.dataset.currentValue = '#000000'; value.parent = wizard;
const toggle = new FakeElement({valueControl: true, type: 'checkbox', checked: true}); toggle.dataset.currentValue = '0'; toggle.parent = wizard;
const field = new FakeElement(); field.dataset.wizardField = '1'; field.children = [include, value]; include.parent = field;
const language = new FakeElement({include: true, includeTarget: true}); language.dataset.wizardIncludeTarget = 'public_language_selector_enabled'; const languageControl = new FakeElement({name: 'settings[public_language_selector_enabled]'});
const progress = new FakeElement({progress: true}); progress.dataset.wizardProgressTarget = 'appearance';
field.children.push(toggle); wizard.children = [form, field, include, language, languageControl, progress]; root.children = [wizard];
setupAdminSetupWizard(root);
if (wizard.dataset.wizardReady !== '1') throw new Error('wizard was not initialized');
include.checked = false; include.dispatchEvent(new FakeEvent('change')); if (!value.disabled || value.value !== '#000000') throw new Error('excluded value was not reset');
include.checked = true; include.dispatchEvent(new FakeEvent('change')); if (value.disabled || value.value !== '#112233' || !toggle.checked) throw new Error('staged values were not restored');
language.checked = false; language.dispatchEvent(new FakeEvent('change')); if (!languageControl.disabled) throw new Error('language selector was not disabled');
progress.dispatchEvent(new FakeEvent('click')); await Promise.resolve();
for (const child of form.children) if (!child.removed) throw new Error('goto controls were not cleaned up');
let hasAction = false; let hasTarget = false;
for (const [name, value] of form.submissionSnapshot) { hasAction ||= name === 'wizard_action' && value === 'goto'; hasTarget ||= name === 'target_step' && value === 'appearance'; }
if (!hasAction || !hasTarget) throw new Error('goto payload was incomplete');
setupAdminSetupWizard(root); if (wizard.dataset.wizardReady !== '1') throw new Error('setup was not idempotent');
console.log('admin_setup_wizard_browser_test: PASS');
