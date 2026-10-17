/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-media-panels.js
 * Module Type: Browser Module
 * Purpose: Reveal bounded PDF and playlist applets above gallery layout.
 * Responsibilities: Own disclosure motion, dismissal, focus and fragment reuse.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {
    scanPdfPageCountCells,
} from './gallery-pdf-metadata.js?v=20261017-media-summary-v1';

/**
 * Keep attachment disclosure motion brief without delaying media controls.
 * Type: number. Units: milliseconds. Scope: one panel transition.
 * Consumers: animatePanel.
 * Rationale: 160 ms makes opening/closing visible while keeping actions prompt.
 */
const MEDIA_PANEL_MOTION_MS = 160;
/**
 * Keep floating applets away from viewport edges, including narrow screens.
 * Type: number. Units: CSS pixels. Scope: the current visual viewport.
 * Consumers: positionPanel.
 * Rationale: Eight pixels preserve an accessible boundary around the applet.
 */
const MEDIA_PANEL_MARGIN_PX = 8;
/**
 * Separate the floating panel from its always-visible launcher/miniplayer.
 * Type: number. Units: CSS pixels. Scope: one open attachment widget.
 * Consumers: positionPanel.
 * Rationale: Six pixels distinguish the applet without a large empty gap.
 */
const MEDIA_PANEL_GAP_PX = 6;

/**
 * Retain the live native disclosure and its cancellable motion.
 * @typedef {Object} MediaPanelState
 * @property {HTMLElement} panel Original popup, never moved out of its owner.
 * @property {HTMLElement} summary Native keyboard-accessible launcher.
 * @property {Animation|null} animation Current optional opening/closing motion.
 * @property {boolean} closing Whether dismissal is awaiting its closing motion.
 * @property {number} scrollTop Last connected list scroll, in CSS pixels.
 */
/** @type {Map<HTMLDetailsElement,MediaPanelState>} Open owned applets only. */
const openPanels = new Map();
let bound = false;

/**
 * Fit a top-layer applet beside its complete launcher, including the miniplayer.
 * @param {HTMLDetailsElement} widget Native disclosure retaining its original DOM.
 * @param {MediaPanelState} state Open popup and launcher references.
 * @returns {void} Clamp the popup to the visible viewport without moving forms.
 */
function positionPanel(widget, state) {
    if (!state.panel.hasAttribute('popover')) return;
    const viewport = window.visualViewport;
    const margin = MEDIA_PANEL_MARGIN_PX;
    const leftEdge = (viewport?.offsetLeft || 0) + margin;
    const topEdge = (viewport?.offsetTop || 0) + margin;
    const rightEdge = leftEdge + (viewport?.width
        || document.documentElement.clientWidth) - margin * 2;
    const bottomEdge = topEdge + (viewport?.height
        || document.documentElement.clientHeight) - margin * 2;
    const anchor = widget.closest(
        '[data-gallery-music], [data-gallery-pdf-documents]'
    ) || widget;
    state.panel.style.maxWidth = `${Math.max(0, rightEdge - leftEdge)}px`;
    state.panel.style.maxHeight = `${Math.max(0, bottomEdge - topEdge)}px`;
    const target = anchor.getBoundingClientRect();
    const bounds = state.panel.getBoundingClientRect();
    const left = Math.max(leftEdge,
        Math.min(target.right - bounds.width, rightEdge - bounds.width));
    const below = target.bottom + MEDIA_PANEL_GAP_PX;
    const top = below + bounds.height <= bottomEdge ? below
        : Math.max(topEdge, target.top - bounds.height - MEDIA_PANEL_GAP_PX);
    state.panel.style.left = `${left}px`;
    state.panel.style.top = `${top}px`;
}

/**
 * Animate a disclosure only when the browser and motion preference permit it.
 * @param {HTMLElement} panel Popup whose layout is already established.
 * @param {boolean} closing Whether the applet is sliding back into its launcher.
 * @returns {Animation|null} Cancellable motion, or immediate supported fallback.
 */
function animatePanel(panel, closing) {
    if (typeof panel.animate !== 'function'
        || matchMedia('(prefers-reduced-motion: reduce)').matches) return null;
    const frames = [
        {opacity: 0, transform: 'translateY(-0.5rem)'},
        {opacity: 1, transform: 'translateY(0)'},
    ];
    return panel.animate(closing ? frames.reverse() : frames, {
        duration: MEDIA_PANEL_MOTION_MS, easing: 'ease-out',
    });
}

/**
 * Finish dismissal without stopping music or discarding an administrator draft.
 * @param {HTMLDetailsElement} widget Original disclosure, possibly detached.
 * @param {MediaPanelState} state State still owning this closing operation.
 * @param {boolean} restoreFocus Whether keyboard focus should return to summary.
 * @returns {void} Release top-layer presentation and close the native disclosure.
 */
function finishClose(widget, state, restoreFocus) {
    if (openPanels.get(widget) !== state) return;
    openPanels.delete(widget);
    state.animation?.cancel();
    if (state.panel.hasAttribute('popover')) {
        if (state.panel.matches(':popover-open')) state.panel.hidePopover();
        state.panel.removeAttribute('popover');
    }
    widget.open = false;
    state.summary.setAttribute('aria-expanded', 'false');
    if (restoreFocus && state.summary.isConnected) {
        state.summary.focus({preventScroll: true});
    }
}

/**
 * Dismiss an owned applet with cancellable motion, leaving its media owner alive.
 * @param {HTMLDetailsElement} widget Open native disclosure.
 * @param {boolean} restoreFocus Whether Escape/close should restore launcher focus.
 * @returns {void} Close immediately or after the current bounded animation.
 */
function closePanel(widget, restoreFocus = false) {
    const state = openPanels.get(widget);
    if (!state || state.closing) return;
    state.animation?.cancel();
    state.closing = true;
    const animation = animatePanel(state.panel, true);
    state.animation = animation;
    if (!animation) finishClose(widget, state, restoreFocus);
    else void animation.finished.then(() => {
        if (state.closing) finishClose(widget, state, restoreFocus);
    }, () => {});
}

/**
 * Open a single media applet while retaining all original form/playlist ancestors.
 * @param {HTMLDetailsElement} widget Existing native disclosure.
 * @returns {void} Promote the panel when supported, or retain bounded inline fallback.
 */
function openPanel(widget) {
    const panel = widget.querySelector('[data-gallery-media-panel]');
    const summary = widget.querySelector('summary');
    if (!(panel instanceof HTMLElement) || !(summary instanceof HTMLElement)) {
        return;
    }
    for (const [other, state] of openPanels) {
        if (other !== widget) finishClose(other, state, false);
    }
    const old = openPanels.get(widget);
    old?.animation?.cancel();
    const state = {
        panel, summary, animation: null, closing: false,
        scrollTop: panel.querySelector('.gallery-media-body')?.scrollTop || 0,
    };
    openPanels.set(widget, state);
    widget.open = true;
    summary.setAttribute('aria-expanded', 'true');
    // Permanent capability fallback: native details remains usable inline when
    // top-layer popovers are absent. This module owns dismissal in both modes.
    if (typeof panel.showPopover === 'function') {
        panel.setAttribute('popover', 'manual');
        if (!panel.matches(':popover-open')) panel.showPopover();
    }
    positionPanel(widget, state);
    state.animation = animatePanel(panel, false);
    if (state.animation) void state.animation.finished.catch(() => {});
    widget.dispatchEvent(new CustomEvent('php-gallery:media-panel-open', {
        bubbles: true,
    }));
}

/**
 * Preserve a visible applet through canonical card replacement, and reflow it.
 * @returns {void} Retire detached owners or reopen their same-identity replacement.
 */
function refreshPanels() {
    for (const [widget, state] of openPanels) {
        if (widget.isConnected) {
            state.scrollTop = state.panel.querySelector(
                '.gallery-media-body'
            )?.scrollTop || 0;
            positionPanel(widget, state);
            continue;
        }
        const replacement = document.getElementById(state.panel.id)?.closest(
            '[data-gallery-media-widget]'
        );
        // Detached top-layer contents lose their layout and report zero scroll.
        // Keep the last connected observation owned by this disclosure instead.
        const scrollTop = state.scrollTop;
        finishClose(widget, state, false);
        if (replacement instanceof HTMLDetailsElement) {
            openPanel(replacement);
            const body = replacement.querySelector('.gallery-media-body');
            if (body) body.scrollTop = scrollTop;
        }
    }
}

/**
 * Install one delegated disclosure lifecycle for public and injected Admin cards.
 * @returns {void} Bind keyboard/outside dismissal, viewport fitting and replacement.
 */
export function setupGalleryMediaPanels() {
    scanPdfPageCountCells(document);
    if (bound) return;
    bound = true;
    document.body.dataset.mediaPanelsBound = '1';
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        const share = target?.closest('[data-gallery-presentation-share]');
        if (share instanceof HTMLAnchorElement) {
            event.preventDefault();
            void copyPresentationLink(share);
            return;
        }
        const widget = target?.closest('[data-gallery-media-widget]');
        const summary = target?.closest('summary');
        if (widget instanceof HTMLDetailsElement
            && summary?.parentElement === widget) {
            event.preventDefault();
            if (widget.open && !openPanels.get(widget)?.closing) {
                closePanel(widget, false);
            } else openPanel(widget);
            return;
        }
        if (widget instanceof HTMLDetailsElement
            && target.closest('[data-media-close]')) closePanel(widget, true);
        for (const [open, state] of openPanels) {
            if (!open.contains(target) && !state.closing) closePanel(open);
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || event.defaultPrevented
            || document.querySelector('dialog[open]')) return;
        if (openPanels.size === 0) return;
        event.preventDefault();
        for (const widget of openPanels.keys()) closePanel(widget, true);
    });
    document.addEventListener('toggle', event => {
        const widget = event.target;
        if (!(widget instanceof HTMLDetailsElement)
            || !widget.matches('[data-gallery-media-widget]')) return;
        const state = openPanels.get(widget);
        if (widget.open && !state) openPanel(widget);
        else if (!widget.open && state) finishClose(widget, state, false);
    }, true);
    window.addEventListener('resize', refreshPanels);
    window.addEventListener('scroll', refreshPanels, true);
    window.visualViewport?.addEventListener('resize', refreshPanels);
    window.visualViewport?.addEventListener('scroll', refreshPanels);
    new MutationObserver(changes => {
        refreshPanels();
        for (const change of changes) for (const node of change.addedNodes) {
            if (node instanceof Element || node instanceof DocumentFragment) {
                scanPdfPageCountCells(node);
            }
        }
    }).observe(document.body, {
        childList: true, subtree: true,
    });
    window.addEventListener('pagehide', () => {
        for (const [widget, state] of openPanels) {
            finishClose(widget, state, false);
        }
    });
}

/**
 * Copy only the prepared canonical launch URL and expose a selectable fallback.
 * @param {HTMLAnchorElement} link Explicit share action beside Presentation.
 * @returns {Promise<void>} Report copy success or offer the URL for manual copy.
 */
async function copyPresentationLink(link) {
    const panel = link.closest('[data-gallery-presentation]');
    const status = panel.querySelector('[data-presentation-status]');
    const input = panel.querySelector('[data-presentation-copy]');
    try {
        await navigator.clipboard.writeText(link.href);
        status.textContent = status.dataset.copied;
        input.hidden = true;
    } catch {
        // Permanent browser capability fallback owned by this copy action:
        // denied clipboard access leaves the same URL selectable by keyboard.
        status.textContent = status.dataset.copyFailed;
        input.value = link.href;
        input.hidden = false;
        input.focus();
        input.select();
    }
}
