/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Module
 * Purpose: Own initial drawer loads, keyboard focus and transient mutation context.
 * Responsibilities:
 *   - Coordinate panel request generations and focus while shared mutation completion owns public refresh.
 * File: public/assets/gallery-modules/admin-panel-lifecycle.js
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Own initial drawer loads, modal keyboard focus and transient mutation context.
 * Public mutation refresh/retry remains in admin-mutation-completion.js.
 */
import {ADMIN_PANEL_FOCUS_SELECTOR} from './admin-panel-policy.js?v=20260920-panel-lifecycle-v1';

/**
 * @typedef {Object} AdminPanelState
 * @property {number} generation Monotonic open/close intent sequence for this shell.
 * @property {boolean} active Whether the current intent owns an interactive drawer.
 * @property {AbortController|null} controller Cancels only the current initial GET.
 * @property {HTMLElement|null} opener Last external opener to restore on close.
 * @property {Map<HTMLElement, string|null>} inert Exact prior sibling inert attributes.
 * @property {MutationObserver|null} observer Tracks newly inserted background siblings.
 * @property {boolean} bound Whether this shell's modal listeners are already installed.
 */
/** @type {WeakMap<HTMLElement, AdminPanelState>} Per-shell generation, abort and focus state. */
const panels = new WeakMap();
/** @type {WeakMap<Object, Object>} Response identity to its originating drawer generation. */
const mutationOwners = new WeakMap();

/**
 * @typedef {Object} AdminPanelOwner
 * @property {function(): boolean} isCurrent Whether this generation still owns drawer effects.
 * @property {AbortSignal|undefined} signal Initial-GET or coordinator cancellation signal; never a submitted-write cancellation.
 */

/**
 * Read or initialize one drawer's document-local ownership state.
 * @param {HTMLElement} panel Reusable shell.
 * @return {AdminPanelState} Mutable lifecycle state; never contains form values.
 */
function stateFor(panel) {
    if (!panels.has(panel)) {
        panels.set(panel, {generation: 0, active: false, controller: null, opener: null, inert: new Map(), observer: null, bound: false});
    }
    return panels.get(panel);
}

/**
 * Snapshot the open intent without creating another refresh generation.
 * @param {HTMLElement|null} panel Originating drawer, if any.
 * @return {{isCurrent: function(): boolean, signal: AbortSignal|undefined}} Guard for panel effects only.
 */
export function captureAdminPanelOwner(panel) {
    const state = panel instanceof HTMLElement ? stateFor(panel) : null;
    const generation = state?.generation;
    return {
        /** Check this exact open intent at the point of a DOM effect. @return {boolean} Whether its shell remains active and connected. */
        isCurrent: () => Boolean(state?.active && state.generation === generation && panel.isConnected),
        signal: state?.controller?.signal,
    };
}

/**
 * Cancel the previous initial GET and establish the new opener's generation.
 * @param {HTMLElement} panel Reusable shell.
 * @param {HTMLElement} opener Control requesting this workflow.
 * @return {AdminPanelOwner} Current load guard with an AbortSignal.
 */
export function beginAdminPanelOpen(panel, opener) {
    const state = stateFor(panel);
    state.controller?.abort();
    state.generation += 1;
    state.active = true;
    state.controller = new AbortController();
    if (!panel.contains(opener)) state.opener = opener;
    return captureAdminPanelOwner(panel);
}

/**
 * Associate a completed response with the scope captured before its POST.
 * @param {{ok?: boolean}} result Opaque canonical response identity, preserved without adding or interpreting envelope fields.
 * @param {AdminPanelOwner} owner Originating panel guard.
 * @return {void}
 */
export function rememberAdminPanelMutation(result, owner) {
    if (result && typeof result === 'object') mutationOwners.set(result, owner);
}

/**
 * Resolve an explicit mutation origin or the current scope for external events.
 * @param {{ok?: boolean}} result Opaque canonical response identity; fields are not reconstructed here.
 * @param {HTMLElement|null} panel Current shell.
 * @return {AdminPanelOwner} Panel-effects guard; public synchronization is independent.
 */
export function adminPanelMutationOwner(result, panel) {
    return mutationOwners.get(result) || captureAdminPanelOwner(panel);
}

/**
 * Require both the open intent and the canonical completion generation.
 * @param {AdminPanelOwner} owner Open-intent guard.
 * @param {{isCurrent?: function(): boolean, signal?: AbortSignal}|null} completion Canonical refresh guard, or null when no refresh generation exists.
 * @return {AdminPanelOwner} Combined predicate preserving the coordinator's cancellation signal.
 */
export function combineAdminPanelGuards(owner, completion) {
    return {
        /** Require both independent ownership checks before a panel effect. @return {boolean} Whether neither open nor completion generation was superseded. */
        isCurrent: () => owner.isCurrent() && (!completion?.isCurrent || completion.isCurrent()),
        signal: completion?.signal || owner.signal,
    };
}

/**
 * Check actual rendered focus eligibility, including native disabled fieldsets.
 * @param {Element|null} element Candidate focus target.
 * @return {boolean} Whether the candidate can receive meaningful visible focus.
 */
function visibleFocusable(element) {
    return element instanceof HTMLElement && element.isConnected && !element.matches(':disabled')
        && !element.closest('[hidden], [inert], [aria-hidden="true"]') && element.getClientRects().length > 0
        && getComputedStyle(element).visibility !== 'hidden';
}

/**
 * Recompute keyboard order after any fragment or nested tab replacement.
 * @param {HTMLElement} dialog Modal content root.
 * @return {HTMLElement[]} Visible controls in native tabindex order.
 */
function focusTargets(dialog) {
    return Array.from(dialog.querySelectorAll(ADMIN_PANEL_FOCUS_SELECTOR))
        .filter(/** Keep visible native keyboard candidates only. @param {HTMLElement} element Candidate control. @return {boolean} Whether it participates in sequential focus. */ element => visibleFocusable(element) && element.tabIndex >= 0)
        .sort(/** Match native positive-tabindex precedence. @param {HTMLElement} left Earlier candidate. @param {HTMLElement} right Later candidate. @return {number} Relative keyboard ordering. */ (left, right) => (left.tabIndex || Infinity) - (right.tabIndex || Infinity));
}

/**
 * Focus the first eligible body control, or the persistent close button/dialog.
 * @param {HTMLElement} panel Active shell.
 * @return {void}
 */
export function focusAdminPanelContent(panel) {
    if (!captureAdminPanelOwner(panel).isCurrent()) return;
    const body = panel.querySelector('[data-admin-side-panel-body]');
    const dialog = panel.querySelector('[role="dialog"]');
    const target = (body && focusTargets(body)[0]) || (dialog && focusTargets(dialog)[0]) || dialog;
    if (target instanceof HTMLElement) target.focus({preventScroll: true});
}

/**
 * Capture a semantic focus target before replacing the owned body.
 * @param {HTMLElement} panel Active shell.
 * @return {function(): void} Restore an equivalent control if the intent is still current.
 */
export function preserveAdminPanelFocus(panel) {
    const owner = captureAdminPanelOwner(panel);
    const active = document.activeElement;
    const id = panel.contains(active) ? active.id : '';
    const name = panel.contains(active) ? active.getAttribute('name') : '';
    return /** Restore equivalent focus only while the captured open intent is current. @return {void} Keeps surviving focused controls or selects a current fallback. */ () => {
        if (!owner.isCurrent() || (visibleFocusable(active) && panel.contains(active))) return;
        const equivalent = id ? panel.querySelector(`#${CSS.escape(id)}`)
            : name ? panel.querySelector(`[name="${CSS.escape(name)}"]`) : null;
        if (visibleFocusable(equivalent)) equivalent.focus({preventScroll: true});
        else focusAdminPanelContent(panel);
    };
}

/**
 * Mark current and newly inserted background siblings inert, preserving prior state.
 * @param {HTMLElement} panel Active shell appended directly to document.body.
 * @return {void}
 */
function isolateBackground(panel) {
    const state = stateFor(panel);
    for (const sibling of document.body.children) {
        if (sibling === panel || !(sibling instanceof HTMLElement) || state.inert.has(sibling)) continue;
        state.inert.set(sibling, sibling.getAttribute('inert'));
        sibling.inert = true;
    }
}

/**
 * Bind keyboard containment after local widgets have had their intended keys.
 * @param {HTMLElement} panel Reusable shell.
 * @return {void}
 */
function bindModalKeys(panel) {
    const state = stateFor(panel);
    if (state.bound) return;
    state.bound = true;
    // The existing destination picker closes at target phase without cancelling
    // Escape. Capture its expanded state before it closes; do not consume its key.
    const widgetEscapes = new WeakSet();
    panel.addEventListener('keydown', /** Observe expanded picker Escape before its target handler closes it. @param {KeyboardEvent} event Original key event. @return {void} Records ownership without consuming the key. */ (event) => {
        if (event.key === 'Escape' && event.target instanceof Element
            && event.target.closest('[data-gallery-search-picker]')?.querySelector('[aria-expanded="true"]')) {
            widgetEscapes.add(event);
        }
    }, true);
    window.addEventListener('keydown', /** Contain focus and close only for keys unclaimed by nested widgets. @param {KeyboardEvent} event Bubbled keyboard event. @return {void} Preserves composing/modifier and widget-specific behavior. */ (event) => {
        if (!captureAdminPanelOwner(panel).isCurrent() || event.defaultPrevented || event.isComposing) return;
        if (event.key === 'Escape') {
            if (widgetEscapes.has(event) || event.ctrlKey || event.altKey || event.metaKey) return;
            event.preventDefault();
            panel.dispatchEvent(new CustomEvent('php-gallery:panel-request-close', {bubbles: true}));
        } else if (event.key === 'Tab') {
            const dialog = panel.querySelector('[role="dialog"]');
            const targets = focusTargets(dialog);
            const first = targets[0] || dialog;
            const last = targets[targets.length - 1] || dialog;
            if (!targets.length || !dialog.contains(document.activeElement)
                || (event.shiftKey ? document.activeElement === first : document.activeElement === last)) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus({preventScroll: true});
            }
        }
    });
    document.addEventListener('focusin', /** Return escaped focus to the active modal's persistent close control. @param {FocusEvent} event Newly focused target. @return {void} Does nothing for inactive generations or in-panel focus. */ (event) => {
        if (captureAdminPanelOwner(panel).isCurrent() && !panel.contains(event.target)) {
            panel.querySelector('[data-admin-side-panel-close]')?.focus({preventScroll: true});
        }
    });
}

/**
 * Activate modal semantics immediately, including the loading and error states.
 * @param {HTMLElement} panel Visible shell.
 * @return {void}
 */
export function activateAdminPanelModal(panel) {
    const state = stateFor(panel);
    panel.inert = false;
    const dialog = panel.querySelector('[role="dialog"]');
    dialog.setAttribute('tabindex', '-1');
    bindModalKeys(panel);
    isolateBackground(panel);
    if (!state.observer) {
        state.observer = new MutationObserver(/** Isolate newly inserted body siblings while this modal is active. @return {void} Preserves each sibling's original inert value. */ () => isolateBackground(panel));
        state.observer.observe(document.body, {childList: true});
    }
    panel.querySelector('[data-admin-side-panel-close]')?.focus({preventScroll: true});
}

/**
 * Invalidate outstanding loads, release background isolation and restore focus.
 * @param {HTMLElement} panel Closing shell.
 * @return {function(): boolean} Whether this exact close still owns the exit animation.
 */
export function deactivateAdminPanel(panel) {
    const state = stateFor(panel);
    state.active = false;
    state.generation += 1;
    const closingGeneration = state.generation;
    state.controller?.abort();
    state.controller = null;
    state.observer?.disconnect();
    state.observer = null;
    for (const [element, previous] of state.inert) {
        if (previous === null) element.removeAttribute('inert');
        else element.setAttribute('inert', previous);
    }
    state.inert.clear();
    panel.inert = true;
    const opener = visibleFocusable(state.opener) ? state.opener
        : Array.from(document.querySelectorAll('[data-gallery-side-panel-link], main, [role="main"]'))
            .find(/** Find a visible replacement opener or main landmark outside the drawer. @param {HTMLElement} element Candidate fallback. @return {boolean} Whether it can receive restored focus. */ element => !panel.contains(element) && visibleFocusable(element));
    if (opener instanceof HTMLElement) {
        const previous = opener.getAttribute('tabindex');
        if (opener.tabIndex < 0) opener.setAttribute('tabindex', '-1');
        opener.focus({preventScroll: true});
        if (previous === null && opener.hasAttribute('tabindex')) {
            // Removing temporary focusability synchronously can blur a main landmark.
            opener.addEventListener('blur', /** Remove temporary landmark focusability only after focus leaves. @return {void} Restores the original absence of tabindex. */ () => opener.removeAttribute('tabindex'), {once: true});
        }
    }
    return /** Protect delayed exit hiding against a later reopen. @return {boolean} Whether this exact close generation still owns the animation. */ () => !state.active && state.generation === closingGeneration;
}
