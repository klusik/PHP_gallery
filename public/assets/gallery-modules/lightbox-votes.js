/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-votes.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides lightbox vote form cloning and button state synchronization.
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
 *   2026-05-12
 */


// Function `currentLightboxVoteForm` returns the injected shared vote form.
/**
 * Handle current lightbox vote form.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLElement} lightboxVotePanel Lightbox vote panel value.
 * @return {*} Result value for the caller.
 */
export function currentLightboxVoteForm(lightboxVotePanel) {
    return lightboxVotePanel?.querySelector('[data-vote-form]') || null;
}

// Function `visibleVoteFormForImage` finds the already-rendered gallery-card vote form for an image.
/**
 * Handle visible vote form for image.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {number} imageId Image identifier.
 * @return {*} Result value for the caller.
 */
function visibleVoteFormForImage(imageId) {
    if (!imageId) {
        return null;
    }

    for (const candidate of document.querySelectorAll('[data-lightbox-image][data-image-id]')) {
        if (!(candidate instanceof HTMLElement) || candidate.dataset.imageId !== String(imageId)) {
            continue;
        }
        const form = candidate.querySelector('[data-vote-form]');
        if (form instanceof HTMLFormElement) {
            return form;
        }
    }
    return null;
}

// Function `templateVoteFormForCard` returns the inert vote form for hidden source-only images.
/**
 * Handle template vote form for card.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} card Card value.
 * @return {*} Result value for the caller.
 */
function templateVoteFormForCard(card) {
    const template = card.querySelector('[data-lightbox-vote-template]');
    if (template instanceof HTMLTemplateElement) {
        const form = template.content.querySelector('[data-vote-form]');
        if (form instanceof HTMLFormElement) {
            return form;
        }
    }

    // Older cached markup stored the shared form in a data attribute. Keeping
    // this fallback prevents a half-updated browser cache from losing voting.
    const voteFormHtml = card.dataset.voteFormHtml || '';
    if (voteFormHtml.trim() === '') {
        return null;
    }
    const scratch = document.createElement('template');
    scratch.innerHTML = voteFormHtml;
    const form = scratch.content.querySelector('[data-vote-form]');
    return form instanceof HTMLFormElement ? form : null;
}

// Function `clonedVoteFormForCard` clones the same server-rendered widget used by gallery cards.
/**
 * Handle cloned vote form for card.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} card Card value.
 * @return {*} Result value for the caller.
 */
function clonedVoteFormForCard(card) {
    const imageId = card.dataset.imageId || '';
    const form = card.querySelector('[data-vote-form]') || visibleVoteFormForImage(imageId) || templateVoteFormForCard(card);
    return form instanceof HTMLFormElement ? form.cloneNode(true) : null;
}

// Function `syncLightboxVote` injects the same server-rendered vote widget used by gallery cards.
/**
 * Synchronize lightbox vote.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} card Card value.
 * @param {HTMLElement} lightboxVotePanel Lightbox vote panel value.
 */
export function syncLightboxVote(card, lightboxVotePanel) {
    if (!lightboxVotePanel) {
        return;
    }

    lightboxVotePanel.replaceChildren();
    lightboxVotePanel.hidden = true;

    const form = clonedVoteFormForCard(card);
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    lightboxVotePanel.appendChild(form);
    lightboxVotePanel.hidden = false;
    form.dataset.lightboxVoteForm = '1';
    form.classList.add('lightbox-vote');
    const imageIdInput = form.querySelector('input[name="image_id"]');
    if (imageIdInput instanceof HTMLInputElement) {
        imageIdInput.value = card.dataset.imageId || '';
    }

    form.querySelectorAll('[data-score-for]').forEach((node) => {
        node.dataset.scoreFor = card.dataset.imageId || '';
        node.textContent = card.dataset.score || '0';
    });
    updateLightboxVoteButtons(lightboxVotePanel, card.dataset.userVote === '1' ? '1' : '0');
}

// Function `updateLightboxVoteButtons` executes this focused behavior.
/**
 * Update lightbox vote buttons.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLElement} lightboxVotePanel Lightbox vote panel value.
 * @param {*} vote Vote value.
 */
export function updateLightboxVoteButtons(lightboxVotePanel, vote) {
    const form = currentLightboxVoteForm(lightboxVotePanel);
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    form.querySelectorAll('button[name="vote"]').forEach((button) => {
        // Variable `active` stores this steps working value.
        const active = button.value === vote;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}
