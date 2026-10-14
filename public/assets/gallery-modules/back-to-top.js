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
    resizeObserver: null,
    bodyObserver: null,
};

/**
 * Finds the currently active back-to-top elements.
 *
 * DOM nodes are looked up on demand so this module never stores old gallery
 * fragments after a server-rendered refresh replaces the public listing.
 *
 * @return {{scope: Element|null, listing: Element|null, button: HTMLButtonElement|null, downButton: HTMLButtonElement|null}} Active controls.
 */
function findBackToTopElements() {
    return {
        scope: document.querySelector('[data-back-to-top-scope]'),
        listing: document.querySelector('[data-back-to-top-list]') || document.querySelector('[data-gallery-image-list]'),
        button: document.querySelector('[data-back-to-top-button]'),
        downButton: document.querySelector('[data-scroll-to-bottom-button]'),
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
 * Reports whether the visitor can scroll farther down the document.
 *
 * The small tolerance avoids a flickering control at fractional browser scroll
 * positions; it does not depend on whether the Top control is currently visible.
 *
 * @param {{scope: Element|null, listing: Element|null, button: HTMLButtonElement|null, downButton: HTMLButtonElement|null}} elements Active controls.
 * @return {boolean} True while there is meaningful remaining document scroll.
 */
function shouldShowScrollToBottomButton(elements) {
    if (!hasConnectedBackToTopElements(elements) || !elements.downButton || !elements.downButton.isConnected) {
        return false;
    }
    if (document.body.classList.contains('has-lightbox') || document.body.classList.contains('has-mobile-lightbox') || document.fullscreenElement) {
        return false;
    }

    const viewportHeight = window.innerHeight;
    const documentHeight = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
    return documentHeight > viewportHeight + 2
        && window.scrollY + viewportHeight < documentHeight - 2;
}

/**
 * Applies the current back-to-top visibility state.
 *
 * @return {void} Update both controls and transfer focus when possible.
 */
function updateBackToTopVisibility() {
    backToTopState.frameId = 0;
    backToTopState.ticking = false;

    const elements = findBackToTopElements();
    if (!hasConnectedBackToTopElements(elements)) {
        return;
    }

    const visible = shouldShowBackToTopButton(elements);
    const downVisible = shouldShowScrollToBottomButton(elements);
    const focusedTop = document.activeElement === elements.button;
    const focusedDown = document.activeElement === elements.downButton;

    elements.button.hidden = !visible;
    elements.button.classList.toggle('is-visible', visible);
    if (elements.downButton && elements.downButton.isConnected) {
        elements.downButton.hidden = !downVisible;
        elements.downButton.classList.toggle('is-visible', downVisible);
        if (focusedTop && !visible && downVisible) {
            elements.downButton.focus({preventScroll: true});
        } else if (focusedDown && !downVisible && visible) {
            elements.button.focus({preventScroll: true});
        }
    }
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
 * Scrolls to the requested document edge using the delegated navigation buttons.
 *
 * @param {MouseEvent} event Click event from the document-level listener.
 * @return {void} Performs one native scroll, or ignores unrelated/hidden elements.
 */
function handleBackToTopClick(event) {
    const target = event.target instanceof Element
        ? event.target.closest('[data-back-to-top-button], [data-scroll-to-bottom-button]')
        : null;
    if (!(target instanceof HTMLElement) || !target.isConnected || target.hidden) {
        return;
    }
    event.preventDefault();
    const reducedMotion = typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const toBottom = target.matches('[data-scroll-to-bottom-button]');
    window.scrollTo({
        top: toBottom ? Math.max(document.documentElement.scrollHeight, document.body.scrollHeight) : 0,
        behavior: reducedMotion ? 'auto' : 'smooth',
    });
}

/**
 * Releases the active back-to-top binding before gallery content is refreshed.
 *
 * @return {void} Disconnect listeners, observers and pending frames.
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
    if (backToTopState.resizeObserver) {
        backToTopState.resizeObserver.disconnect();
        backToTopState.resizeObserver = null;
    }
    if (backToTopState.bodyObserver) {
        backToTopState.bodyObserver.disconnect();
        backToTopState.bodyObserver = null;
    }
    backToTopState.ticking = false;
}

/**
 * Bind the public Top and Down buttons to document scroll and content lifecycle.
 *
 * Used by browser-side gallery behavior and Admin fragment replacement.
 *
 * @return {void} Install one abortable set of listeners and observers.
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
    window.addEventListener('load', requestBackToTopVisibilityUpdate, {once: true, signal: controller.signal});

    if (typeof ResizeObserver !== 'undefined') {
        backToTopState.resizeObserver = new ResizeObserver(requestBackToTopVisibilityUpdate);
        backToTopState.resizeObserver.observe(document.documentElement);
        backToTopState.resizeObserver.observe(document.body);
        backToTopState.resizeObserver.observe(elements.listing);
    }
    if (typeof MutationObserver !== 'undefined') {
        backToTopState.bodyObserver = new MutationObserver(requestBackToTopVisibilityUpdate);
        backToTopState.bodyObserver.observe(document.body, {
            attributes: true,
            attributeFilter: ['class'],
            childList: true,
        });
    }
    updateBackToTopVisibility();
}
