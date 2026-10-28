/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-features.js
 * Module Type: Browser Module
 * Purpose: Autosave scoped global capability changes and restore factory defaults.
 * Responsibilities:
 *   - Preserve unrelated settings and reconcile server-rendered group health
 *   - Expose saving, saved and uncertain-write states without navigation
 *   - Position native light-dismiss help beside its trigger
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Bound one authenticated save or state read before reporting uncertainty.
 * Type: number. Units: milliseconds. Scope: Admin Features requests.
 * Consumers: requestFeatureState(). Value: 15000.
 * Rationale: Ordinary settings writes should finish promptly; timeout never
 * retries a possibly completed mutation and requires an authoritative read.
 */
const FEATURE_REQUEST_TIMEOUT_MS = 15000;

/**
 * Keep contextual help inside the viewport beside its question-mark trigger.
 * Type: number. Units: CSS pixels. Scope: Admin Features help positioning.
 * Consumers: positionFeatureHelp(). Value: 8.
 * Rationale: Match the shared compact panel edge gap on narrow screens.
 */
const FEATURE_HELP_GAP_PX = 8;

/**
 * Position an open native popover using its rendered size and trigger geometry.
 * @param {HTMLElement} panel Open contextual help panel.
 * @param {HTMLButtonElement} trigger Question-mark control owning the panel.
 * @return {void} Updates only the panel's viewport position.
 */
function positionFeatureHelp(panel, trigger) {
    const rect = trigger.getBoundingClientRect();
    const gap = FEATURE_HELP_GAP_PX;
    panel.style.left = `${Math.max(gap, Math.min(
        rect.left, window.innerWidth - panel.offsetWidth - gap,
    ))}px`;
    panel.style.top = `${Math.max(gap, Math.min(
        rect.bottom + gap, window.innerHeight - panel.offsetHeight - gap,
    ))}px`;
}

/**
 * Send one scoped write or passive read without repeating a mutation on failure.
 * @param {string} endpoint Controller-prepared same-origin feature route.
 * @param {FormData|null} body Explicit changes and CSRF, or null for a passive GET.
 * @return {Promise<{ok:boolean,message:string,registry_revision:string,groups_html:string,summary:string}>} Authoritative response, including health after a refused or partially completed write.
 */
async function requestFeatureState(endpoint, body) {
    const response = await fetch(endpoint, {
        method: body ? 'POST' : 'GET',
        body,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json'},
        signal: AbortSignal.timeout(FEATURE_REQUEST_TIMEOUT_MS),
    });
    const result = await response.json();
    if (typeof result.ok !== 'boolean'
        || typeof result.groups_html !== 'string'
        || typeof result.summary !== 'string'
        || typeof result.registry_revision !== 'string'
        || typeof result.message !== 'string') {
        throw new Error('Incomplete feature state');
    }
    if (!response.ok && result.ok) throw new Error('Unexpected feature response');
    return result;
}

/**
 * Enhance the global feature form with one serialized, delegated save pipeline.
 * @return {void} Installs autosave, group/reset actions and accessible context help once.
 */
export function setupAdminFeatures() {
    const root = document.querySelector('[data-admin-features]');
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';
    const form = root.querySelector('form');
    const groups = root.querySelector('[data-feature-groups]');
    const feedback = root.querySelector('[data-feature-feedback]');
    const reload = root.querySelector('[data-feature-reload]');
    const reset = root.querySelector('[data-feature-reset]');
    const endpoint = form.getAttribute('action');
    let busy = false;
    let uncertain = false;

    /**
     * Initialize mixed group semantics and remember server-owned read-only state.
     * @return {void} Prepares only the currently rendered checkboxes.
     */
    function prepareControls() {
        for (const control of groups.querySelectorAll('input[type="checkbox"]')) {
            control.dataset.readOnly = control.disabled ? '1' : '0';
            if (control.hasAttribute('data-feature-group')) {
                control.indeterminate = control.dataset.mixed === '1';
            }
        }
    }

    /**
     * Serialize operations and expose progress without claiming an unknown write failed.
     * @param {boolean} pending Whether a request is currently in flight.
     * @return {void} Updates interaction locks and accessible busy state.
     */
    function lockControls(pending) {
        busy = pending;
        form.setAttribute('aria-busy', String(pending));
        for (const control of groups.querySelectorAll('input[type="checkbox"]')) {
            control.disabled = pending || uncertain || control.dataset.readOnly === '1';
        }
        reset.disabled = pending || uncertain;
        reload.disabled = pending;
    }

    /**
     * Reconcile all dependency health from the response before enabling another write.
     * @param {FormData|null} body Explicit mutation fields, or null to recover by reading.
     * @param {string} focusId Stable activated control identifier restored after rendering.
     * @return {Promise<void>} Renders authoritative state or locks uncertain state for a passive reload.
     */
    async function synchronize(body, focusId) {
        if (busy) return;
        lockControls(true);
        feedback.textContent = root.dataset.saving;
        root.dataset.saveState = 'saving';
        try {
            const result = await requestFeatureState(endpoint, body);
            const revision = form.elements.namedItem('feature_registry_revision');
            const sameRegistry = result.registry_revision === revision.value;
            if (!sameRegistry && body) {
                throw new Error('Feature registry changed');
            }
            const parsed = new DOMParser().parseFromString(result.groups_html, 'text/html');
            const rows = parsed.querySelectorAll('[data-feature-row]');
            const currentKeys = new Set(Array.from(
                groups.querySelectorAll('[data-feature-row]'),
                row => row.dataset.featureRow,
            ));
            if (!rows.length
                || new Set(Array.from(rows, row => row.dataset.featureRow)).size !== rows.length
                || (sameRegistry && (rows.length !== currentKeys.size
                    || Array.from(rows).some(row => !currentKeys.delete(row.dataset.featureRow))))) {
                throw new Error('Incomplete feature groups');
            }
            groups.replaceChildren(...parsed.body.children);
            revision.value = result.registry_revision;
            root.querySelector('[data-feature-summary]').textContent = result.summary;
            uncertain = false;
            reload.hidden = true;
            prepareControls();
            feedback.textContent = result.ok ? root.dataset.saved : result.message;
            root.dataset.saveState = result.ok ? 'saved' : 'error';
        } catch {
            // A lost response can follow a successful write. Never replay it or
            // roll the switch back as though server persistence were known.
            uncertain = true;
            reload.hidden = false;
            feedback.textContent = root.dataset.failed;
            root.dataset.saveState = 'error';
        } finally {
            lockControls(false);
            const focused = focusId ? document.getElementById(focusId) : null;
            (focused && !focused.disabled ? focused : reload.hidden ? reset : reload)
                .focus({preventScroll: true});
        }
    }

    form.addEventListener('change', event => {
        const control = event.target;
        if (!(control instanceof HTMLInputElement) || busy || uncertain) return;
        const body = new FormData();
        body.set('csrf_token', form.elements.namedItem('csrf_token').value);
        body.set('feature_registry_revision', form.elements.namedItem('feature_registry_revision').value);
        if (control.hasAttribute('data-feature-group')) {
            for (const feature of control.closest('.admin-feature-group')
                .querySelectorAll('input[name="enabled_features[]"]:not(:disabled)')) {
                feature.checked = control.checked;
                body.set(`feature_changes[${feature.value}]`, feature.checked ? '1' : '0');
            }
        } else if (control.name === 'enabled_features[]') {
            body.set(`feature_changes[${control.value}]`, control.checked ? '1' : '0');
        } else return;
        void synchronize(body, control.id);
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (busy || uncertain || event.submitter !== reset) return;
        const body = new FormData();
        body.set('csrf_token', form.elements.namedItem('csrf_token').value);
        body.set('feature_registry_revision', form.elements.namedItem('feature_registry_revision').value);
        body.set('feature_action', 'factory_defaults');
        void synchronize(body, reset.id);
    });
    reload.addEventListener('click', () => void synchronize(null, reload.id));
    root.addEventListener('toggle', event => {
        const panel = event.target;
        if (!(panel instanceof HTMLElement)
            || !panel.matches('.admin-feature-help-panel:popover-open')) return;
        const trigger = root.querySelector(`[popovertarget="${CSS.escape(panel.id)}"]`);
        if (trigger) positionFeatureHelp(panel, trigger);
    }, true);
    prepareControls();
}
