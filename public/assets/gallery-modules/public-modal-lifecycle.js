/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/public-modal-lifecycle.js
 * Module Type: Browser Lifecycle
 * Purpose: Give public viewer overlays one focus and background owner.
 * Responsibilities:
 *   - Isolate covered content and restore its original inert state.
 *   - Keep keyboard focus in the topmost viewer or map layer.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/** @type {Array<{element:HTMLElement,opener:HTMLElement|null}>} */
const layers = [];
/** @type {Map<HTMLElement,boolean>} */
const covered = new Map();
const backgroundObserver = new MutationObserver(() => {
    const element = layers.at(-1)?.element;
    if (!element) return;
    isolateCurrentLayer();
    // A nested editor can move from an inert background branch into the viewer.
    // Restore its inherited state before retrying the focus it could not accept
    // synchronously during that move. Native top-layer dialogs keep ownership.
    if (!document.querySelector('dialog:modal')
        && !modalFocusScope(element).contains(document.activeElement)) {
        focusCurrentLayer();
    }
});

/**
 * Reconcile covered siblings without reactivating unchanged background branches.
 *
 * Photo navigation and transition layers mutate the viewer subtree frequently.
 * Retained siblings must stay inert throughout those updates: restoring and
 * reapplying their state needlessly invalidates the entire covered gallery.
 * Restore removed branches before capturing additions so a moved nested editor
 * keeps its original state and can regain focus inside the current viewer.
 *
 * @returns {void} Preserves original inert state across navigation and nested layouts.
 */
function isolateCurrentLayer() {
    const desired = new Set();
    let branch = layers.at(-1)?.element;
    while (branch && branch !== document.body) {
        const parent = branch.parentElement;
        if (!parent) break;
        [...parent.children].forEach((sibling) => {
            if (sibling !== branch && sibling instanceof HTMLElement) {
                desired.add(sibling);
            }
        });
        branch = parent;
    }
    covered.forEach((inert, element) => {
        if (desired.has(element)) return;
        if (element.inert !== inert) element.inert = inert;
        covered.delete(element);
    });
    desired.forEach((element) => {
        if (!covered.has(element)) covered.set(element, element.inert);
        if (!element.inert) element.inert = true;
    });
}

/**
 * Resolve an active nested dialog or presentation consent inside its viewer.
 * @param {HTMLElement} element Topmost modal surface.
 * @returns {HTMLElement} Current focus scope, including an open inline dialog.
 */
function modalFocusScope(element) {
    return element.querySelector(
        '[data-modal-focus-scope]:not([hidden]), [role="dialog"]:not([hidden])'
    ) || element;
}

/**
 * Return visible, enabled keyboard targets in an overlay.
 * @param {HTMLElement} element Active modal surface.
 * @returns {HTMLElement[]} Targets in DOM tab order, excluding covered controls.
 */
function modalTargets(element) {
    return [...modalFocusScope(element).querySelectorAll(
        'a[href], button, input, select, textarea, [tabindex]'
    )].filter((target) => target instanceof HTMLElement
        && target.tabIndex >= 0 && !target.matches(':disabled')
        && !target.closest('[hidden], [inert], [aria-hidden="true"]')
        && target.getClientRects().length > 0
        && getComputedStyle(target).visibility === 'visible');
}

/**
 * Place focus inside the current modal without scrolling the underlying album.
 * @returns {void} Focuses its close action or its first available control.
 */
function focusCurrentLayer() {
    const element = layers.at(-1)?.element;
    if (!element) return;
    const targets = modalTargets(element);
    const close = targets.find((target) => target.matches(
        '[data-map-close], [data-lightbox-action="close"]'
    ));
    (close || targets[0] || element).focus({preventScroll: true});
}

/**
 * Activate an existing viewer overlay, preserving the opener across navigation.
 * @param {HTMLElement} element Visible lightbox or map overlay.
 * @param {HTMLElement|null} source Explicit photo opener for direct/deferred loads.
 * @returns {void} Makes the active layer modal and focuses it on first entry.
 */
export function openPublicModal(element, source = null) {
    // Deferred work can finish after an owned frame/document was unloaded.
    // Such a detached surface must never acquire modal ownership.
    if (!element.isConnected || !(document.body instanceof HTMLElement)) return;
    const existing = layers.find((layer) => layer.element === element);
    if (existing) {
        if (source?.isConnected && existing.opener === document.body) {
            existing.opener = source;
        }
        isolateCurrentLayer();
        return;
    }
    const opener = source?.isConnected ? source
        : (document.activeElement instanceof HTMLElement
            ? document.activeElement : null);
    layers.push({element, opener});
    element.setAttribute('role', 'dialog');
    element.setAttribute('aria-modal', 'true');
    element.tabIndex = -1;
    isolateCurrentLayer();
    backgroundObserver.observe(document.body, {childList: true, subtree: true});
    focusCurrentLayer();
}

/**
 * Release a dismissed overlay and restore the previous layer or its opener.
 * @param {HTMLElement} element Dismissed or disposed modal surface.
 * @param {boolean} restoreFocus Whether teardown should return keyboard focus.
 * @returns {void} Restores exact background state when the final overlay closes.
 */
export function closePublicModal(element, restoreFocus = true) {
    const index = layers.findIndex((layer) => layer.element === element);
    if (index < 0) return;
    const wasTop = index === layers.length - 1;
    const [{opener}] = layers.splice(index, 1);
    element.removeAttribute('aria-modal');
    isolateCurrentLayer();
    if (!layers.length) backgroundObserver.disconnect();
    if (!restoreFocus || !wasTop) return;
    if (opener?.isConnected && !opener.closest('[hidden], [inert]')) {
        opener.focus({preventScroll: true});
    } else {
        focusCurrentLayer();
    }
}

document.addEventListener('focusin', (event) => {
    const element = layers.at(-1)?.element;
    // Native dialogs own their browser top layer and its focus lifecycle.
    if (!element || document.querySelector('dialog:modal')) return;
    if (!modalFocusScope(element).contains(event.target)) focusCurrentLayer();
});

document.addEventListener('keydown', (event) => {
    const element = layers.at(-1)?.element;
    if (!element || document.querySelector('dialog:modal')) return;
    if (event.key === 'Escape' && element.matches('[data-map-overlay]')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        element.querySelector('[data-map-close]')?.click();
    } else if (event.key === 'Tab') {
        event.preventDefault();
        event.stopImmediatePropagation();
        element.dispatchEvent(new Event('publicModalKeyboardNavigation'));
        const targets = modalTargets(element);
        const current = targets.indexOf(document.activeElement);
        const next = event.shiftKey
            ? (current <= 0 ? targets.length - 1 : current - 1)
            : (current + 1) % targets.length;
        (targets[next] || element).focus({preventScroll: true});
    }
}, {capture: true});
