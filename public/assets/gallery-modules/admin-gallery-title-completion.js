/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-title-completion.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides inline ghost-text completion for new gallery titles.
 *
 * Responsibilities:
 *   - Prefer existing gallery titles from the selected parent gallery
 *   - Fall back to matching titles elsewhere in the gallery tree
 *   - Accept the active completion with Tab, ArrowRight, or pointer input
 *   - Support forms injected later through the shared Admin side panel
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - The submitted form contract remains a normal `title` input.
 *
 * Last Updated:
 *   2026-09-19
 */

import {
    GALLERY_TITLE_COMPLETION_MIN_CHARACTERS,
    GALLERY_TITLE_COMPLETION_MAX_CHARACTERS,
    GALLERY_TITLE_COMPLETION_RESULT_LIMIT,
    GALLERY_TITLE_COMPLETION_DEBOUNCE_MS,
    GALLERY_TITLE_COMPLETION_REQUEST_TIMEOUT_MS,
} from './admin-interaction-policy.js?v=20260920-admin-interaction-policy-v1';

/**
 * Locate the server-rendered title completion owner and its accessible children.
 * @var {string}
 * Units: CSS selector string. Scope: this module's title-control markup contract.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: keep private element roles beside their event/presentation owner, not in global operational policy.
 */
const COMPLETION_SELECTOR = '[data-gallery-title-completion]';
/**
 * Recognize delegated events from title inputs, including injected forms.
 * @var {string}
 * Units: CSS selector string. Scope: this module's title-control markup contract.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: keep private element roles beside their event/presentation owner, not in global operational policy.
 */
const INPUT_SELECTOR = '[data-gallery-title-completion-input]';
/**
 * Locate the presentation-only ghost overlay without changing the submitted field.
 * @var {string}
 * Units: CSS selector string. Scope: this module's title-control markup contract.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: keep private element roles beside their event/presentation owner, not in global operational policy.
 */
const OVERLAY_SELECTOR = '[data-gallery-title-completion-overlay]';
/**
 * Locate the already-entered prefix span used for ghost-text alignment.
 * @var {string}
 * Units: CSS selector string. Scope: this module's title-control markup contract.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: keep private element roles beside their event/presentation owner, not in global operational policy.
 */
const PREFIX_SELECTOR = '[data-gallery-title-completion-prefix]';
/**
 * Locate the actionable suggested suffix for pointer acceptance and presentation.
 * @var {string}
 * Units: CSS selector string. Scope: this module's title-control markup contract.
 * Consumers: admin-gallery-title-completion.js.
 * Rationale: keep private element roles beside their event/presentation owner, not in global operational policy.
 */
const TAIL_SELECTOR = '[data-gallery-title-completion-tail]';
const completionRequests = new WeakMap();
let completionControlId = 0;

/**
 * Normalize a gallery title fragment for case-insensitive prefix matching.
 *
 * @param {unknown} value Value to normalize.
 * @returns {string} Normalized search text.
 */
export function normalizeGalleryTitleCompletionText(value) {
    return String(value ?? '').normalize('NFKC').toLowerCase();
}

/**
 * Map a normalized prefix back to a whole grapheme boundary in the original title.
 * A partial ligature/combining sequence is not a safe inline completion.
 *
 * @param {string} title Candidate title.
 * @param {string} typed Entered prefix.
 * @returns {string|null} Visible suffix, or null when no safe completion exists.
 */
export function galleryTitleCompletionSuffix(title, typed) {
    const normalized = normalizeGalleryTitleCompletionText(typed);
    if (!normalized || !normalizeGalleryTitleCompletionText(title).startsWith(normalized)) {
        return null;
    }
    // Printable ASCII has identical raw/normalized boundaries; avoid segmenter
    // construction for this common case without weakening Unicode handling.
    if (/^[\x20-\x7e]*$/.test(title + typed)) {
        return title.length > typed.length ? title.slice(typed.length) : null;
    }
    if (typeof Intl.Segmenter !== 'function') {
        // Old browsers retain plain ASCII completion; decline unsafe Unicode geometry.
        return null;
    }
    for (const segment of new Intl.Segmenter('en', {granularity: 'grapheme'}).segment(title)) {
        const end = segment.index + segment.segment.length;
        if (normalizeGalleryTitleCompletionText(title.slice(0, end)) === normalized) {
            return end < title.length ? title.slice(end) : null;
        }
    }
    return null;
}

/**
 * Compare two candidate rows using the create-gallery completion priority.
 *
 * Direct siblings are preferred first. Within the same priority class, newer
 * rows are preferred so sequential names such as Leg 01, Leg 02, Leg 03 use the
 * most recently created matching title as the template.
 *
 * @param {Record<string, unknown>} left Left candidate.
 * @param {Record<string, unknown>} right Right candidate.
 * @param {number} parentId Currently selected parent gallery id.
 * @returns {number} Array sort comparator result.
 */
function compareGalleryTitleCompletionCandidates(left, right, parentId) {
    const leftSibling = Number(left.parent_id || 0) === parentId ? 1 : 0;
    const rightSibling = Number(right.parent_id || 0) === parentId ? 1 : 0;
    if (leftSibling !== rightSibling) {
        return rightSibling - leftSibling;
    }

    const leftCreatedAt = String(left.created_at || '');
    const rightCreatedAt = String(right.created_at || '');
    if (leftCreatedAt !== rightCreatedAt) {
        return rightCreatedAt.localeCompare(leftCreatedAt);
    }

    return Number(right.id || 0) - Number(left.id || 0);
}

/**
 * Return the best existing title that completes the current input value.
 *
 * @param {Array<Record<string, unknown>>} candidates Existing gallery titles.
 * @param {string} inputValue Current title input value.
 * @param {number} parentId Currently selected parent gallery id.
 * @returns {string} Full suggested title, or an empty string when no suggestion exists.
 */
export function findGalleryTitleCompletion(candidates, inputValue, parentId = 0) {
    const typedValue = String(inputValue ?? '');
    if ([...typedValue].length < GALLERY_TITLE_COMPLETION_MIN_CHARACTERS || typedValue.trim() === '') {
        return '';
    }

    const normalizedTypedValue = normalizeGalleryTitleCompletionText(typedValue);
    const matches = (Array.isArray(candidates) ? candidates : []).filter((candidate) => {
        const title = String(candidate?.title || '');
        return normalizeGalleryTitleCompletionText(title).startsWith(normalizedTypedValue)
            && galleryTitleCompletionSuffix(title, typedValue) !== null;
    });
    if (matches.length === 0) {
        return '';
    }

    const best = matches.reduce((winner, row) =>
        !winner || compareGalleryTitleCompletionCandidates(row, winner, Number(parentId || 0)) < 0 ? row : winner, null);
    return String(best?.title || '');
}

/** Return the single request owner for a title control, assigning accessible IDs on demand. */
function completionRequestState(input) {
    if (!completionRequests.has(input)) {
        completionRequests.set(input, {generation: 0, timer: 0, controller: null, composing: false, dismissed: false});
        const control = input.closest(COMPLETION_SELECTOR);
        const help = control?.querySelector('[data-gallery-title-completion-help]');
        if (help instanceof HTMLElement) {
            if (!help.id) help.id = 'gallery-title-help-client-' + (++completionControlId);
            input.setAttribute('aria-describedby', help.id);
        }
    }
    return completionRequests.get(input);
}

/** Cancel queued and active work before a newer intent, blur, or disposal takes ownership. */
function cancelCompletionRequest(input) {
    const state = completionRequestState(input);
    state.generation += 1;
    clearTimeout(state.timer);
    state.timer = 0;
    state.controller?.abort();
    state.controller = null;
}

/**
 * Fetch only bounded results after typing settles; stale and detached controls never render.
 * @param {HTMLInputElement} input Title control owning its pending request and debounce timer.
 * @return {void} Queues optional work without changing the submitted title.
 */
function requestGalleryTitleCompletion(input) {
    const control = input.closest(COMPLETION_SELECTOR);
    if (!(control instanceof HTMLElement)) return;
    cancelCompletionRequest(input);
    hideGalleryTitleCompletion(control);
    const state = completionRequestState(input);
    state.dismissed = false;
    if (state.composing || !caretAllowsGalleryTitleCompletion(input)
        || [...input.value].length < GALLERY_TITLE_COMPLETION_MIN_CHARACTERS
        || [...input.value].length > GALLERY_TITLE_COMPLETION_MAX_CHARACTERS) return;
    const endpoint = control.dataset.galleryTitleCompletionUrl;
    if (!endpoint) {
        updateGalleryTitleCompletion(input);
        return;
    }
    control.__galleryTitleCompletionCandidates = [];
    const generation = state.generation;
    const value = input.value;
    const parentId = selectedParentGalleryId(input);
    state.timer = window.setTimeout(
        /** Request one settled title intent; generation checks prevent stale rendering. @return {Promise<void>} Completes optional suggestions or silent failure. */
        async () => {
        state.timer = 0;
        const controller = new AbortController();
        state.controller = controller;
        const timeout = window.setTimeout(() => controller.abort(), GALLERY_TITLE_COMPLETION_REQUEST_TIMEOUT_MS);
        try {
            const url = new URL(endpoint, window.location.href);
            // Keep credentials on the active origin, including local host aliases.
            url.protocol = window.location.protocol;
            url.host = window.location.host;
            url.searchParams.set('q', value);
            url.searchParams.set('parent_id', String(parentId));
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {Accept: 'application/json'}});
            if (!response.ok) return;
            const result = await response.json();
            if (!result.ok || generation !== state.generation || !input.isConnected
                || document.activeElement !== input || input.value !== value || selectedParentGalleryId(input) !== parentId) return;
            control.__galleryTitleCompletionCandidates = Array.isArray(result.candidates)
                ? result.candidates.slice(0, GALLERY_TITLE_COMPLETION_RESULT_LIMIT) : [];
            updateGalleryTitleCompletion(input);
        } catch {
            // Completion is optional. The ordinary required title field remains available.
        } finally {
            clearTimeout(timeout);
            if (state.controller === controller) state.controller = null;
        }
    }, GALLERY_TITLE_COMPLETION_DEBOUNCE_MS);
}

/**
 * Parse completion rows stored on one server-rendered title control.
 *
 * @param {HTMLElement} control Completion control root.
 * @returns {Array<Record<string, unknown>>} Parsed completion rows.
 */
function completionCandidates(control) {
    if (Array.isArray(control.__galleryTitleCompletionCandidates)) {
        return control.__galleryTitleCompletionCandidates;
    }

    try {
        const parsed = JSON.parse(String(control.dataset.galleryTitleCompletionCandidates || '[]'));
        control.__galleryTitleCompletionCandidates = Array.isArray(parsed) ? parsed : [];
    } catch {
        control.__galleryTitleCompletionCandidates = [];
    }
    return control.__galleryTitleCompletionCandidates;
}

/**
 * Resolve the parent gallery selected by the form containing one title input.
 *
 * @param {HTMLInputElement} input Title input.
 * @returns {number} Selected parent gallery id.
 */
function selectedParentGalleryId(input) {
    const form = input.closest('form');
    const parent = form?.querySelector('input[type="hidden"][name="parent_id"]:enabled, select[name="parent_id"]:enabled');
    return Number(parent?.value || 0);
}

/**
 * Copy the computed input typography and box spacing onto the ghost overlay.
 *
 * @param {HTMLInputElement} input Title input.
 * @param {HTMLElement} overlay Ghost-text overlay.
 */
function syncCompletionOverlayMetrics(input, overlay) {
    const style = window.getComputedStyle(input);
    overlay.style.fontFamily = style.fontFamily;
    overlay.style.fontSize = style.fontSize;
    overlay.style.fontWeight = style.fontWeight;
    overlay.style.fontStyle = style.fontStyle;
    overlay.style.letterSpacing = style.letterSpacing;
    overlay.style.lineHeight = style.lineHeight;
    overlay.style.paddingTop = style.paddingTop;
    overlay.style.paddingRight = style.paddingRight;
    overlay.style.paddingBottom = style.paddingBottom;
    overlay.style.paddingLeft = style.paddingLeft;
    overlay.style.borderTopWidth = style.borderTopWidth;
    overlay.style.borderRightWidth = style.borderRightWidth;
    overlay.style.borderBottomWidth = style.borderBottomWidth;
    overlay.style.borderLeftWidth = style.borderLeftWidth;
    overlay.style.transform = `translateX(${-input.scrollLeft}px)`;
}

/**
 * Hide the active completion for one control.
 *
 * @param {HTMLElement} control Completion control root.
 */
function hideGalleryTitleCompletion(control) {
    const overlay = control.querySelector(OVERLAY_SELECTOR);
    const prefix = control.querySelector(PREFIX_SELECTOR);
    const tail = control.querySelector(TAIL_SELECTOR);
    if (overlay instanceof HTMLElement) {
        overlay.hidden = true;
    }
    if (prefix instanceof HTMLElement) {
        prefix.textContent = '';
    }
    if (tail instanceof HTMLElement) {
        tail.textContent = '';
    }
    control.dataset.galleryTitleCompletionValue = '';
    const status = control.querySelector('[data-gallery-title-completion-status]');
    if (status instanceof HTMLElement) status.textContent = '';
}

/**
 * Recalculate and render one title completion.
 *
 * @param {HTMLInputElement} input Title input.
 */
function updateGalleryTitleCompletion(input) {
    const control = input.closest(COMPLETION_SELECTOR);
    if (!(control instanceof HTMLElement)) {
        return;
    }
    if (completionRequestState(input).composing || completionRequestState(input).dismissed || !caretAllowsGalleryTitleCompletion(input)) {
        hideGalleryTitleCompletion(control);
        return;
    }

    const overlay = control.querySelector(OVERLAY_SELECTOR);
    const prefix = control.querySelector(PREFIX_SELECTOR);
    const tail = control.querySelector(TAIL_SELECTOR);
    if (!(overlay instanceof HTMLElement) || !(prefix instanceof HTMLElement) || !(tail instanceof HTMLElement)) {
        return;
    }

    const suggestion = findGalleryTitleCompletion(
        completionCandidates(control),
        input.value,
        selectedParentGalleryId(input)
    );
    if (suggestion === '') {
        hideGalleryTitleCompletion(control);
        return;
    }

    const suffix = galleryTitleCompletionSuffix(suggestion, input.value);
    if (suffix === null) {
        hideGalleryTitleCompletion(control);
        return;
    }
    prefix.textContent = input.value;
    tail.textContent = suffix;
    control.dataset.galleryTitleCompletionValue = suggestion;
    syncCompletionOverlayMetrics(input, overlay);
    overlay.hidden = false;
    const status = control.querySelector('[data-gallery-title-completion-status]');
    if (status instanceof HTMLElement) {
        const announcement = (control.dataset.galleryTitleCompletionAnnouncement || '{title}').replace('{title}', suggestion);
        if (status.textContent !== announcement) status.textContent = announcement;
    }
}

/**
 * Accept the currently visible completion for one input.
 *
 * @param {HTMLInputElement} input Title input.
 * @returns {boolean} True when a completion was accepted.
 */
function acceptGalleryTitleCompletion(input) {
    const control = input.closest(COMPLETION_SELECTOR);
    if (!(control instanceof HTMLElement)) {
        return false;
    }
    const suggestion = String(control.dataset.galleryTitleCompletionValue || '');
    if (suggestion === '' || completionRequestState(input).composing || !caretAllowsGalleryTitleCompletion(input)
        || galleryTitleCompletionSuffix(suggestion, input.value) === null) {
        return false;
    }

    cancelCompletionRequest(input);
    input.value = suggestion;
    input.setSelectionRange(suggestion.length, suggestion.length);
    hideGalleryTitleCompletion(control);
    input.dispatchEvent(new Event('input', {bubbles: true}));
    return true;
}

/**
 * Return whether keyboard completion should operate at the current caret position.
 *
 * @param {HTMLInputElement} input Title input.
 * @returns {boolean} True when the caret is collapsed at the end of the input.
 */
function caretAllowsGalleryTitleCompletion(input) {
    return input.selectionStart === input.selectionEnd && input.selectionEnd === input.value.length;
}

/**
 * Attach delegated inline title completion behavior.
 *
 * Delegation is required because the create-gallery form can be fetched and
 * injected into the side panel after the main browser bundle has already booted.
 * @return {void} Installs document-owned handlers once without changing submitted title values.
 */
export function setupAdminGalleryTitleCompletion() {
    if (document.documentElement.dataset.galleryTitleCompletionReady === '1') {
        return;
    }
    document.documentElement.dataset.galleryTitleCompletionReady = '1';

    document.addEventListener('input', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (input) {
            requestGalleryTitleCompletion(input);
        }
    });

    document.addEventListener('focusin', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (input) {
            requestGalleryTitleCompletion(input);
        }
    });

    document.addEventListener('focusout', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (!input) {
            return;
        }
        cancelCompletionRequest(input);
        // A next-task focus check, not a tunable waiting period: let focus settle first.
        window.setTimeout(() => {
            if (document.activeElement === input) {
                return;
            }
            const control = input.closest(COMPLETION_SELECTOR);
            if (control instanceof HTMLElement) {
                hideGalleryTitleCompletion(control);
            }
        }, 0);
    });

    document.addEventListener('change',
        /** Refresh suggestions only for an enabled parent control in the same form. @param {Event} event Delegated change. @return {void} Schedules optional title work. */
        (event) => {
        const parent = (event.target instanceof HTMLSelectElement || event.target instanceof HTMLInputElement)
            && event.target.matches('input[type="hidden"][name="parent_id"]:enabled, select[name="parent_id"]:enabled') ? event.target : null;
        if (!parent) {
            return;
        }
        const input = parent.closest('form')?.querySelector(INPUT_SELECTOR);
        if (input instanceof HTMLInputElement) {
            requestGalleryTitleCompletion(input);
        }
    });

    document.addEventListener('scroll', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (input) {
            updateGalleryTitleCompletion(input);
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (!input) {
            return;
        }
        if (event.isComposing || event.keyCode === 229 || completionRequestState(input).composing
            || event.ctrlKey || event.altKey || event.metaKey) return;

        if (event.key === 'Escape') {
            const control = input.closest(COMPLETION_SELECTOR);
            if (control instanceof HTMLElement) {
                const state = completionRequestState(input);
                const handled = Boolean(control.dataset.galleryTitleCompletionValue || state.timer || state.controller);
                state.dismissed = true;
                cancelCompletionRequest(input);
                hideGalleryTitleCompletion(control);
                if (handled) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            }
            return;
        }

        const acceptsWithTab = event.key === 'Tab' && !event.shiftKey;
        const acceptsWithArrow = event.key === 'ArrowRight' && !event.shiftKey;
        if (!(acceptsWithTab || acceptsWithArrow) || !caretAllowsGalleryTitleCompletion(input)) {
            return;
        }
        if (acceptGalleryTitleCompletion(input)) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('compositionstart', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches(INPUT_SELECTOR)) return;
        completionRequestState(input).composing = true;
        cancelCompletionRequest(input);
        const control = input.closest(COMPLETION_SELECTOR);
        if (control) hideGalleryTitleCompletion(control);
    });
    document.addEventListener('compositionend', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches(INPUT_SELECTOR)) return;
        completionRequestState(input).composing = false;
        requestGalleryTitleCompletion(input);
    });
    document.addEventListener('selectionchange', () => {
        const input = document.activeElement;
        if (input instanceof HTMLInputElement && input.matches(INPUT_SELECTOR)) updateGalleryTitleCompletion(input);
    });

    document.addEventListener('pointerdown', (event) => {
        const tail = event.target instanceof Element ? event.target.closest(TAIL_SELECTOR) : null;
        if (!(tail instanceof HTMLElement)) {
            return;
        }
        const control = tail.closest(COMPLETION_SELECTOR);
        const input = control?.querySelector(INPUT_SELECTOR);
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        event.preventDefault();
        acceptGalleryTitleCompletion(input);
        input.focus();
    });
}
