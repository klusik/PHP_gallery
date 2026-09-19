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

const COMPLETION_SELECTOR = '[data-gallery-title-completion]';
const INPUT_SELECTOR = '[data-gallery-title-completion-input]';
const OVERLAY_SELECTOR = '[data-gallery-title-completion-overlay]';
const PREFIX_SELECTOR = '[data-gallery-title-completion-prefix]';
const TAIL_SELECTOR = '[data-gallery-title-completion-tail]';
const MIN_COMPLETION_CHARACTERS = 2;

/**
 * Normalize a gallery title fragment for case-insensitive prefix matching.
 *
 * @param {unknown} value Value to normalize.
 * @returns {string} Normalized search text.
 */
export function normalizeGalleryTitleCompletionText(value) {
    return String(value ?? '').normalize('NFKC').toLocaleLowerCase();
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
    if (typedValue.length < MIN_COMPLETION_CHARACTERS || typedValue.trim() === '') {
        return '';
    }

    const normalizedTypedValue = normalizeGalleryTitleCompletionText(typedValue);
    const matches = (Array.isArray(candidates) ? candidates : []).filter((candidate) => {
        const title = String(candidate?.title || '');
        if (title.length <= typedValue.length) {
            return false;
        }
        return normalizeGalleryTitleCompletionText(title).startsWith(normalizedTypedValue);
    });
    if (matches.length === 0) {
        return '';
    }

    matches.sort((left, right) => compareGalleryTitleCompletionCandidates(left, right, Number(parentId || 0)));
    return String(matches[0]?.title || '');
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
    const parent = form?.querySelector('select[name="parent_id"]');
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

    const typedLength = input.value.length;
    prefix.textContent = input.value;
    tail.textContent = suggestion.slice(typedLength);
    control.dataset.galleryTitleCompletionValue = suggestion;
    syncCompletionOverlayMetrics(input, overlay);
    overlay.hidden = false;
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
    if (suggestion === '') {
        return false;
    }

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
 */
export function setupAdminGalleryTitleCompletion() {
    if (document.documentElement.dataset.galleryTitleCompletionReady === '1') {
        return;
    }
    document.documentElement.dataset.galleryTitleCompletionReady = '1';

    document.addEventListener('input', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (input) {
            updateGalleryTitleCompletion(input);
        }
    });

    document.addEventListener('focusin', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (input) {
            updateGalleryTitleCompletion(input);
        }
    });

    document.addEventListener('focusout', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches(INPUT_SELECTOR) ? event.target : null;
        if (!input) {
            return;
        }
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

    document.addEventListener('change', (event) => {
        const parent = event.target instanceof HTMLSelectElement && event.target.matches('select[name="parent_id"]') ? event.target : null;
        if (!parent) {
            return;
        }
        const input = parent.closest('form')?.querySelector(INPUT_SELECTOR);
        if (input instanceof HTMLInputElement) {
            updateGalleryTitleCompletion(input);
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

        if (event.key === 'Escape') {
            const control = input.closest(COMPLETION_SELECTOR);
            if (control instanceof HTMLElement) {
                hideGalleryTitleCompletion(control);
            }
            return;
        }

        const acceptsWithTab = event.key === 'Tab' && !event.shiftKey;
        const acceptsWithArrow = event.key === 'ArrowRight';
        if (!(acceptsWithTab || acceptsWithArrow) || !caretAllowsGalleryTitleCompletion(input)) {
            return;
        }
        if (acceptGalleryTitleCompletion(input)) {
            event.preventDefault();
        }
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
