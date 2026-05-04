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

export function setupBackToTopButton() {
    // scope stores state or configuration for the gallery front-end flow.
    const scope = document.querySelector('[data-back-to-top-scope]');
    // listing stores state or configuration for the gallery front-end flow.
    const listing = document.querySelector('[data-back-to-top-list]') || document.querySelector('[data-gallery-image-list]');
    // button stores state or configuration for the gallery front-end flow.
    const button = document.querySelector('[data-back-to-top-button]');
    if (!scope || !listing || !button) {
        return;
    }

    // ticking stores state or configuration for the gallery front-end flow.
    let ticking = false;

    /**
     * Handles should show button behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function shouldShowButton() {
        if (document.body.classList.contains('has-lightbox') || document.body.classList.contains('has-mobile-lightbox') || document.fullscreenElement) {
            return false;
        }
        // scopeRect stores state or configuration for the gallery front-end flow.
        const scopeRect = scope.getBoundingClientRect();
        // listingRect stores state or configuration for the gallery front-end flow.
        const listingRect = listing.getBoundingClientRect();
        // enteredListing stores state or configuration for the gallery front-end flow.
        const enteredListing = listingRect.top < window.innerHeight * 0.72;
        // stillInsideListing stores state or configuration for the gallery front-end flow.
        const stillInsideListing = scopeRect.bottom > window.innerHeight * 0.24;
        return enteredListing && stillInsideListing && window.scrollY > 180;
    }

    /**
     * Handles update visibility behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function updateVisibility() {
        ticking = false;
        // visible stores state or configuration for the gallery front-end flow.
        const visible = shouldShowButton();
        button.hidden = !visible;
        button.classList.toggle('is-visible', visible);
    }

    /**
     * Handles request visibility update behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function requestVisibilityUpdate() {
        if (ticking) {
            return;
        }
        ticking = true;
        window.requestAnimationFrame(updateVisibility);
    }

    button.addEventListener('click', () => {
        window.scrollTo({top: 0, behavior: 'smooth'});
    });
    window.addEventListener('scroll', requestVisibilityUpdate, {passive: true});
    window.addEventListener('resize', requestVisibilityUpdate);
    document.addEventListener('fullscreenchange', requestVisibilityUpdate);
    updateVisibility();
}
