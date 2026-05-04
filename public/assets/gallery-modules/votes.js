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
        event.preventDefault();
        // Variable `body` stores this steps working value.
        const body = new FormData(form);
        if (event.submitter && event.submitter.name) {
            body.set(event.submitter.name, event.submitter.value);
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
        form.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === String(result.vote);
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
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
