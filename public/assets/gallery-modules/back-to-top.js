/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/back-to-top.js
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
 * Back to top control
 *
 * Shows the floating return button only while the public gallery listing is actually being browsed.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

const backToTopState = {
    controller: null,
    frameId: 0,
    ticking: false,
};

/**
 * Finds the currently active back-to-top elements.
 *
 * DOM nodes are looked up on demand so this module never stores old gallery
 * fragments after a server-rendered refresh replaces the public listing.
 *
 * @return {{scope: Element|null, listing: Element|null, button: HTMLButtonElement|null} }.
 */
function findBackToTopElements() {
    return {
        scope: document.querySelector('[data-back-to-top-scope]'),
        listing: document.querySelector('[data-back-to-top-list]') || document.querySelector('[data-gallery-image-list]'),
        button: document.querySelector('[data-back-to-top-button]'),
    };
}

/**
 * Returns whether the supplied back-to-top elements are usable.
 *
 * @param {HTMLElement} elements Elements value.
 * @return {boolean} True when the condition matches.
 */
function hasConnectedBackToTopElements(elements) {
    return Boolean(
        elements.scope
        && elements.listing
        && elements.button
        && elements.scope.isConnected
        && elements.listing.isConnected
        && elements.button.isConnected
    );
}

/**
 * Determines whether the back-to-top button should be visible now.
 *
 * @param {HTMLElement} elements Elements value.
 * @return {boolean} True when the condition matches.
 */
function shouldShowBackToTopButton(elements) {
    if (!hasConnectedBackToTopElements(elements)) {
        return false;
    }
    if (document.body.classList.contains('has-lightbox') || document.body.classList.contains('has-mobile-lightbox') || document.fullscreenElement) {
        return false;
    }

    const scopeRect = elements.scope.getBoundingClientRect();
    const listingRect = elements.listing.getBoundingClientRect();
    const enteredListing = listingRect.top < window.innerHeight * 0.72;
    const stillInsideListing = scopeRect.bottom > window.innerHeight * 0.24;
    return enteredListing && stillInsideListing && window.scrollY > 180;
}

/**
 * Applies the current back-to-top visibility state.
 */
function updateBackToTopVisibility() {
    backToTopState.frameId = 0;
    backToTopState.ticking = false;

    const elements = findBackToTopElements();
    if (!hasConnectedBackToTopElements(elements)) {
        return;
    }

    const visible = shouldShowBackToTopButton(elements);
    elements.button.hidden = !visible;
    elements.button.classList.toggle('is-visible', visible);
}

/**
 * Schedules a back-to-top visibility update for the next animation frame.
 */
function requestBackToTopVisibilityUpdate() {
    if (!backToTopState.controller || backToTopState.controller.signal.aborted || backToTopState.ticking) {
        return;
    }
    backToTopState.ticking = true;
    backToTopState.frameId = window.requestAnimationFrame(updateBackToTopVisibility);
}

/**
 * Scrolls the public page back to the top when the delegated button is clicked.
 *
 * @param {MouseEvent} event Click event from the document-level listener.
 */
function handleBackToTopClick(event) {
    const target = event.target instanceof Element ? event.target.closest('[data-back-to-top-button]') : null;
    if (!(target instanceof HTMLElement) || !target.isConnected) {
        return;
    }
    event.preventDefault();
    window.scrollTo({top: 0, behavior: 'smooth'});
}

/**
 * Releases the active back-to-top binding before gallery content is refreshed.
 */
export function teardownBackToTopButton() {
    if (backToTopState.frameId) {
        window.cancelAnimationFrame(backToTopState.frameId);
        backToTopState.frameId = 0;
    }
    if (backToTopState.controller) {
        backToTopState.controller.abort();
        backToTopState.controller = null;
    }
    backToTopState.ticking = false;
}

/**
 * Handle setup back to top button.
 *
 * Used by browser-side gallery behavior.
 */
export function setupBackToTopButton() {
    teardownBackToTopButton();

    const elements = findBackToTopElements();
    if (!hasConnectedBackToTopElements(elements)) {
        return;
    }

    const controller = new AbortController();
    backToTopState.controller = controller;
    backToTopState.ticking = false;

    document.addEventListener('click', handleBackToTopClick, {signal: controller.signal});
    window.addEventListener('scroll', requestBackToTopVisibilityUpdate, {passive: true, signal: controller.signal});
    window.addEventListener('resize', requestBackToTopVisibilityUpdate, {signal: controller.signal});
    document.addEventListener('fullscreenchange', requestBackToTopVisibilityUpdate, {signal: controller.signal});
    updateBackToTopVisibility();
}
