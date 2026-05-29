/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox-deferred.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Defers the heavier public lightbox viewer until the page is visible and idle,
 *   or until the visitor explicitly opens a photo or map.
 *
 * Responsibilities:
 *   - Keep the first public-gallery render responsive
 *   - Preserve the existing lightbox implementation and server-rendered markup
 *   - Provide the same setup and teardown API used by the refresh lifecycle
 *   - Avoid eager querySelector and listener setup over large photo grids
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
 *   2026-05-10
 */

const lightboxModuleUrl = './lightbox.js?v=20260528-tag-pills-v1';

const deferredLightboxState = {
    controller: null,
    idleHandle: 0,
    idleTimer: 0,
    setupToken: 0,
    modulePromise: null,
    module: null,
    active: false,
};

/**
 * Loads the full lightbox module once.
 *
 * @returns {Promise<object>} Loaded lightbox module namespace.
 */
function loadLightboxModule() {
    if (!deferredLightboxState.modulePromise) {
        deferredLightboxState.modulePromise = import(lightboxModuleUrl).then((module) => {
            deferredLightboxState.module = module;
            return module;
        });
    }
    return deferredLightboxState.modulePromise;
}

/**
 * Cancels only the lightweight deferred activation listeners and timers.
 *
 * @returns {void}
 */
function cancelDeferredActivation() {
    if (deferredLightboxState.controller) {
        deferredLightboxState.controller.abort();
        deferredLightboxState.controller = null;
    }
    if (deferredLightboxState.idleHandle) {
        if ('cancelIdleCallback' in window) {
            window.cancelIdleCallback(deferredLightboxState.idleHandle);
        }
        deferredLightboxState.idleHandle = 0;
    }
    if (deferredLightboxState.idleTimer) {
        window.clearTimeout(deferredLightboxState.idleTimer);
        deferredLightboxState.idleTimer = 0;
    }
}


/**
 * Return the total number of photos declared by the current gallery markup.
 *
 * @returns {number} Visitor-visible lightbox image count, or 0 when unavailable.
 */
function deferredLightboxTotal() {
    const config = document.querySelector('[data-lightbox-config]');
    return Math.max(0, Number.parseInt(config?.dataset.lightboxTotal || '0', 10) || 0);
}

/**
 * Return the zero-based lightbox index declared on a clicked photo card.
 *
 * @param {Element|null} target Clicked activation target.
 * @returns {number} Zero-based index, or 0 when the markup does not expose one.
 */
function deferredLightboxIndex(target) {
    if (!(target instanceof HTMLElement)) {
        return 0;
    }
    const index = Number.parseInt(target.dataset.lightboxIndex || '0', 10);
    return Number.isInteger(index) && index >= 0 ? index : 0;
}

/**
 * Show a small initial progress indicator while the real lightbox module loads.
 *
 * @param {Element|null} target Clicked activation target.
 * @returns {void}
 */
function showDeferredLightboxLoader(target) {
    if (!(target instanceof HTMLElement) || !target.matches('[data-lightbox-image], [data-lightbox-source]')) {
        return;
    }
    const overlay = document.querySelector('[data-lightbox]');
    if (!(overlay instanceof HTMLElement) || !overlay.hidden) {
        return;
    }
    const loader = overlay.querySelector('[data-lightbox-initial-loader]');
    const loaderFill = overlay.querySelector('[data-lightbox-initial-loader-fill]');
    const loaderCount = overlay.querySelector('[data-lightbox-initial-loader-count]');
    const counter = overlay.querySelector('[data-lightbox-counter]');
    if (!(loader instanceof HTMLElement)) {
        return;
    }
    const total = deferredLightboxTotal();
    const index = deferredLightboxIndex(target);
    loader.hidden = false;
    loader.setAttribute('aria-busy', 'true');
    overlay.classList.add('is-initial-loading');
    overlay.hidden = false;
    document.body.classList.add('has-lightbox');
    if (loaderFill instanceof HTMLElement) {
        const progress = total > 0 ? Math.max(8, Math.min(35, ((index + 1) / total) * 100)) : 12;
        loaderFill.style.setProperty('--lightbox-initial-loader-progress', `${Math.round(progress)}%`);
    }
    if (loaderCount instanceof HTMLElement) {
        const template = loader.dataset.lightboxLoadingCountTemplate || 'Preparing photo {current} of {total}';
        loaderCount.textContent = total > 0
            ? template.split('{current}').join(String(index + 1)).split('{total}').join(String(total))
            : '';
    }
    if (counter instanceof HTMLElement && total > 0) {
        counter.textContent = `${index + 1} / ${total}`;
    }
}


/**
 * Hide the bootstrap progress indicator if full viewer activation fails.
 *
 * @returns {void}
 */
function hideDeferredLightboxLoader() {
    const overlay = document.querySelector('[data-lightbox]');
    if (!(overlay instanceof HTMLElement) || !overlay.classList.contains('is-initial-loading')) {
        return;
    }
    const loader = overlay.querySelector('[data-lightbox-initial-loader]');
    if (loader instanceof HTMLElement) {
        loader.hidden = true;
        loader.removeAttribute('aria-busy');
    }
    overlay.classList.remove('is-initial-loading');
    overlay.hidden = true;
    document.body.classList.remove('has-lightbox');
}

/**
 * Replays the visitor interaction that caused the full lightbox to load.
 *
 * @param {Element|null} target Element that should receive the second click.
 * @returns {void}
 */
function replayDeferredClick(target) {
    if (!(target instanceof HTMLElement) || !target.isConnected) {
        return;
    }
    window.requestAnimationFrame(() => {
        if (target.isConnected) {
            target.click();
        }
    });
}

/**
 * Starts the full lightbox implementation when it is still relevant.
 *
 * @param {number} setupToken Token for the current server-rendered page state.
 * @param {Element|null} replayTarget Optional element to click again after setup.
 * @returns {Promise<void>}
 */
async function activateFullLightbox(setupToken, replayTarget = null) {
    const module = await loadLightboxModule();
    if (setupToken !== deferredLightboxState.setupToken) {
        return;
    }
    cancelDeferredActivation();
    module.setupGalleryLightbox();
    deferredLightboxState.active = true;
    replayDeferredClick(replayTarget);
}

/**
 * Returns the closest element that should bootstrap the real lightbox or map code.
 *
 * @param {EventTarget|null} target Original event target.
 * @returns {Element|null} Matching card or button.
 */
function deferredActivationTarget(target) {
    if (!(target instanceof Element)) {
        return null;
    }
    if (target.closest('form, [data-admin-inline-editor], [data-public-admin-card-action], [data-gallery-side-panel-link]')) {
        return null;
    }
    return target.closest('[data-photo-map], [data-gallery-map-url], [data-lightbox-image], [data-lightbox-source]');
}

/**
 * Schedules full viewer setup after the page load and an idle period.
 *
 * @param {number} setupToken Token for the current server-rendered page state.
 * @returns {void}
 */
function scheduleIdleLightboxActivation(setupToken) {
    const scheduleIdle = () => {
        if (setupToken !== deferredLightboxState.setupToken) {
            return;
        }
        const run = () => activateFullLightbox(setupToken).catch(() => {});
        if ('requestIdleCallback' in window) {
            deferredLightboxState.idleHandle = window.requestIdleCallback(run, {timeout: 4500});
            return;
        }
        deferredLightboxState.idleTimer = window.setTimeout(run, 1600);
    };

    const scheduleAfterLoad = () => {
        deferredLightboxState.idleTimer = window.setTimeout(scheduleIdle, 700);
    };

    if (document.readyState === 'complete') {
        scheduleAfterLoad();
        return;
    }
    window.addEventListener('load', scheduleAfterLoad, {once: true, signal: deferredLightboxState.controller.signal});
}

/**
 * Sets up public lightbox behavior without immediately loading the full viewer code.
 *
 * The full viewer still starts automatically after the page is loaded and idle,
 * which keeps keyboard navigation and deep-link behavior available after the
 * initial render settles. A direct photo or map click starts it immediately.
 *
 * @returns {void}
 */
export function setupGalleryLightbox() {
    teardownGalleryLightbox();

    if (!document.querySelector('[data-lightbox-image], [data-lightbox-source], [data-photo-map], [data-gallery-map-url]')) {
        return;
    }

    const setupToken = deferredLightboxState.setupToken + 1;
    deferredLightboxState.setupToken = setupToken;
    deferredLightboxState.controller = new AbortController();

    document.addEventListener('click', (event) => {
        const target = deferredActivationTarget(event.target);
        if (!target) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        showDeferredLightboxLoader(target);
        activateFullLightbox(setupToken, target).catch(() => {
            hideDeferredLightboxLoader();
        });
    }, {capture: true, signal: deferredLightboxState.controller.signal});

    document.addEventListener('publicGalleryPhotoOrderChanged', () => {
        activateFullLightbox(setupToken).catch(() => {});
    }, {signal: deferredLightboxState.controller.signal});

    scheduleIdleLightboxActivation(setupToken);
}

/**
 * Releases deferred and full lightbox lifecycle state before public content changes.
 *
 * @returns {void}
 */
export function teardownGalleryLightbox() {
    deferredLightboxState.setupToken += 1;
    cancelDeferredActivation();
    deferredLightboxState.active = false;
    if (deferredLightboxState.module && typeof deferredLightboxState.module.teardownGalleryLightbox === 'function') {
        deferredLightboxState.module.teardownGalleryLightbox();
    }
}

/**
 * Loads tag suggestions only on admin forms that actually render tag inputs.
 *
 * @returns {void}
 */
export function setupTagSuggestions(root = document) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    if (!scope.querySelector('[data-tag-input]')) {
        return;
    }
    loadLightboxModule()
        .then((module) => module.setupTagSuggestions(scope))
        .catch(() => {});
}
