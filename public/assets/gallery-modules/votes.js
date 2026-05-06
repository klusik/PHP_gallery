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
 * @returns {string} Active vote value as posted to the server.
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
 * @returns {void}
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
 * Attaches AJAX submit handling to every gallery vote form.
 *
 * The server returns the authoritative score and vote state. This helper writes
 * those values back to every matching public card first, then emits a CustomEvent
 * so stateful modules such as the lightbox can update their own controls without
 * this small module needing to import the full viewer implementation.
 *
 * @returns {void}
 */
export function setupVoteForms() {
    // Submit votes through fetch so the selected state and score update without
    // leaving the lightbox/gallery page.
    document.addEventListener('submit', async (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target.closest('[data-vote-form]');
        if (!form) {
            return;
        }
        // Allow the already-liked state to toggle back to no vote when the user
        // clicks the like button again. This must work from the card, normal
        // picture view, lightbox form, and lightbox keyboard shortcut.
        const activeVote = currentVoteForForm(form);
        event.preventDefault();
        // Variable `body` stores this steps working value.
        const body = new FormData(form);
        if (event.submitter && event.submitter.name) {
            const submittedValue = event.submitter.value;
            body.set(event.submitter.name, activeVote === '1' && submittedValue === '1' ? '0' : submittedValue);
        }
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
    });
}
