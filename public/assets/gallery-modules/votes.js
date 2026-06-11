/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/votes.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides client-side behavior for the PHP Gallery user interface.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */


/**
 * Finds a vote button from any event target inside it.
 *
 * Browser click targets are usually the button itself, but pseudo elements, icon
 * wrappers, and some fullscreen overlay paths can expose a nested target. Keeping
 * the lookup local to button[name="vote"] avoids depending on a fragile
 * ancestor selector.
 *
 * @param {EventTarget|null} target Raw event target from click or submit flow.
 * @return {HTMLButtonElement|null} Vote button inside a managed vote form.
 */
function voteButtonFromEventTarget(target) {
    // Variable `element` stores this steps working value.
    const element = target instanceof Element ? target : target?.parentElement || null;
    if (!element) {
        return null;
    }

    // Variable `button` stores this steps working value.
    const button = element.closest('button[name="vote"]');
    if (!(button instanceof HTMLButtonElement)) {
        return null;
    }

    return button.closest('[data-vote-form]') ? button : null;
}

/**
 * Vote form AJAX helper.
 *
 * Public photo cards and the lightbox both render small voting forms. This module
 * owns the network request and the generic DOM updates. Viewer-specific updates
 * are published through the `php-gallery:vote-updated` CustomEvent.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupVoteForms } from './gallery-modules/votes.js';
 * setupVoteForms();
 */

/**
 * Returns the current active vote value for one form.
 *
 * Card forms can read their state from the surrounding image node. The lightbox
 * form has no image card parent, so it must also be able to read the active
 * button state directly from the form itself.
 *
 * @param {HTMLFormElement} form Vote form currently being submitted.
 * @return {string} Active vote value as posted to the server.
 */
function currentVoteForForm(form) {
    // Variable `cardVote` stores this steps working value.
    const cardVote = form.closest('[data-image-id]')?.dataset.userVote;
    if (cardVote === '1') {
        return '1';
    }

    // Variable `activeButton` stores this steps working value.
    const activeButton = form.querySelector('button[name="vote"].is-active');
    return activeButton ? activeButton.value : '0';
}

/**
 * Updates all visible and hidden vote forms for a single image.
 *
 * The gallery card, image-detail markup, and lightbox form may all exist on the
 * same page. Updating only the submitted form leaves another entry point stale,
 * which makes the next click look like a new upvote instead of a revoke.
 *
 * @param {number|string} imageId Image ID returned by the server.
 * @param {number|string} vote Current viewer vote returned by the server.
 */
function syncVoteFormsForImage(imageId, vote) {
    document.querySelectorAll('[data-vote-form]').forEach((form) => {
        // Variable `imageInput` stores this steps working value.
        const imageInput = form.querySelector('input[name="image_id"]');
        if (!imageInput || imageInput.value !== String(imageId)) {
            return;
        }

        form.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === String(vote);
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    });
}

/**
 * Submits one vote form through AJAX and synchronizes every visible entry point.
 *
 * @param {HTMLFormElement} form Vote form currently being submitted.
 * @param {HTMLButtonElement|null} submitter Button that initiated the vote, when available.
 */
async function submitVoteForm(form, submitter = null) {
    if (form.dataset.voteSubmitting === '1') {
        return;
    }

    // Allow the already-liked state to toggle back to no vote when the user
    // clicks the like button again. This must work from the card, normal
    // picture view, lightbox form, and lightbox keyboard shortcut.
    const activeVote = currentVoteForForm(form);
    const body = new FormData(form);
    const submittedVote = submitter?.value || form.dataset.pendingVote || '';
    if (submitter?.name || submittedVote !== '') {
        const submittedName = submitter?.name || 'vote';
        body.set(submittedName, activeVote === '1' && submittedVote === '1' ? '0' : submittedVote);
    }
    delete form.dataset.pendingVote;

    form.dataset.voteSubmitting = '1';
    try {
        // Variable `response` stores this steps working value.
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            return;
        }
        // Variable `result` stores this steps working value.
        const result = await response.json();
        document.querySelectorAll(`[data-score-for="${result.image_id}"]`).forEach((node) => {
            node.textContent = result.score;
        });
        document.querySelectorAll(`[data-image-id="${result.image_id}"]`).forEach((node) => {
            node.dataset.score = String(result.score);
            node.dataset.userVote = String(result.vote);
        });
        syncVoteFormsForImage(result.image_id, result.vote);
        // Variable `lightbox` stores this steps working value.
        const lightbox = document.querySelector('[data-lightbox]');
        // Variable `lightboxScore` stores this steps working value.
        const lightboxScore = document.querySelector('[data-lightbox-score]');
        if (lightbox && lightboxScore && lightbox.dataset.currentImageId === String(result.image_id)) {
            lightboxScore.textContent = String(result.score);
        }

        // Modules that own richer viewer state, especially the lightbox, listen
        // for this event. This avoids a hard dependency from the small vote form
        // helper back into the large viewer module.
        document.dispatchEvent(new CustomEvent('php-gallery:vote-updated', {detail: result}));
    } finally {
        delete form.dataset.voteSubmitting;
    }
}

/**
 * Attaches AJAX submit handling to every gallery vote form.
 *
 * The server returns the authoritative score and vote state. This helper writes
 * those values back to every matching public card first, then emits a CustomEvent
 * so stateful modules such as the lightbox can update their own controls without
 * this small module needing to import the full viewer implementation.
 */
export function setupVoteForms() {
    if (document.documentElement.dataset.voteFormsBound === '1') {
        return;
    }
    document.documentElement.dataset.voteFormsBound = '1';

    // Submit votes through fetch so the selected state and score update without
    // leaving the lightbox/gallery page. This remains as a keyboard and no-JS
    // behavior fallback for normal form submission.
    document.addEventListener('submit', async (event) => {
        // Variable `target` stores this steps working value.
        const target = event.target instanceof Element ? event.target : null;
        // Variable `form` stores this steps working value.
        const form = target?.closest('[data-vote-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();
        await submitVoteForm(form, event.submitter instanceof HTMLButtonElement ? event.submitter : null);
    });

    // Some viewer states place the vote form inside overlay/fullscreen UI. Handle
    // the actual vote button in capture phase, before the lightbox overlay can
    // consume the click for fullscreen, navigation, or map HUD behavior.
    document.addEventListener('click', async (event) => {
        // Variable `button` stores this steps working value.
        const button = voteButtonFromEventTarget(event.target);
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        // Variable `form` stores this steps working value.
        const form = button.closest('[data-vote-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        form.dataset.pendingVote = button.value;
        await submitVoteForm(form, button);
    }, true);
}
