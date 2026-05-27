/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/lightbox.js
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
 * Public gallery tag suggestions, lightbox viewer, map overlays, and viewer dev diagnostics.
 *
 * This is intentionally the largest module because the lightbox, fullscreen
 * handling, adjacent image preloading, GPS split-map handling, and dev overlay
 * share one coherent viewer state. Splitting these internals further would require
 * a dedicated state object and event bus. Keeping them together makes the current
 * behavior easier to audit while still separating them from admin forms and upload
 * workflows.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupTagSuggestions, setupGalleryLightbox } from './gallery-modules/lightbox.js';
 * setupTagSuggestions();
 * setupGalleryLightbox();
 */


/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @returns {string} Browser-facing translated text.
 */
function i18n(key, fallback, parameters = {}) {
    const root = window.PHP_GALLERY_I18N && typeof window.PHP_GALLERY_I18N === 'object' ? window.PHP_GALLERY_I18N : {};
    const strings = root.strings && typeof root.strings === 'object' ? root.strings : {};
    let text = typeof strings[key] === 'string' ? strings[key] : fallback;
    Object.entries(parameters).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}
export { setupTagSuggestions } from './tag-suggestions.js?v=20260512-modular-lightbox-v1';
import { currentLightboxVoteForm, syncLightboxVote, updateLightboxVoteButtons } from './lightbox-votes.js?v=20260512-modular-lightbox-v1';

const galleryLightboxState = {
    controller: null,
    cleanup: null,
};

/**
 * Releases lightbox listeners and viewer-held DOM references before public gallery content is replaced.
 *
 * @returns {void}
 */
export function teardownGalleryLightbox() {
    if (typeof galleryLightboxState.cleanup === 'function') {
        galleryLightboxState.cleanup();
        galleryLightboxState.cleanup = null;
    }
    if (galleryLightboxState.controller) {
        galleryLightboxState.controller.abort();
        galleryLightboxState.controller = null;
    }
}

export function setupGalleryLightbox() {
    teardownGalleryLightbox();

    const controller = new AbortController();
    galleryLightboxState.controller = controller;

    // cards stores the authoritative image order for the viewer.
    let cards = [];
    // Variable `overlay` stores this steps working value.
    const overlay = document.querySelector('[data-lightbox]');
    // lightboxConfig stores the server-rendered async metadata settings.
    const lightboxConfig = document.querySelector('[data-lightbox-config]');
    // lightboxEndpoint stores the JSON endpoint used when a requested image is not in the DOM yet.
    const lightboxEndpoint = lightboxConfig instanceof HTMLElement ? lightboxConfig.dataset.lightboxEndpoint || '' : '';
    // lightboxTotal stores the visitor-visible number of images in the full lightbox order.
    const lightboxTotal = Math.max(0, Number.parseInt(lightboxConfig?.dataset.lightboxTotal || '0', 10) || 0);
    // lightboxWindowSize stores how many metadata records are fetched per async request.
    const lightboxWindowSize = Math.max(12, Math.min(80, Number.parseInt(lightboxConfig?.dataset.lightboxWindowSize || '60', 10) || 60));
    // lightboxMapsEnabled stores whether this gallery branch may expose EXIF GPS maps.
    const lightboxMapsEnabled = (
        lightboxConfig instanceof HTMLElement && lightboxConfig.dataset.lightboxMapsEnabled === '1'
    ) || (
        overlay instanceof HTMLElement && overlay.dataset.lightboxMapsEnabled === '1'
    );
    // lightboxGalleryMapUrl stores the lazy gallery-level map payload endpoint.
    const lightboxGalleryMapUrl = (
        lightboxConfig instanceof HTMLElement ? lightboxConfig.dataset.lightboxGalleryMapUrl || '' : ''
    ) || (
        overlay instanceof HTMLElement ? overlay.dataset.lightboxGalleryMapUrl || '' : ''
    );
    // lightboxGalleryMapTitle stores the map title used for gallery route payloads.
    const lightboxGalleryMapTitle = (
        lightboxConfig instanceof HTMLElement ? lightboxConfig.dataset.lightboxGalleryMapTitle || '' : ''
    ) || (
        overlay instanceof HTMLElement ? overlay.dataset.lightboxGalleryMapTitle || '' : ''
    );
    // lightboxGalleryMapPayloadPromises stores lazy gallery map fetches keyed by endpoint URL.
    const lightboxGalleryMapPayloadPromises = new Map();
    // lightboxPendingWindows stores in-flight async metadata requests keyed by endpoint range.
    const lightboxPendingWindows = new Map();

    /**
     * Refreshes the lightbox order after an admin reorders visible photo cards or replaces public gallery content.
     *
     * Navigation must read the current DOM order so Next and Previous match the
     * saved gallery order without requiring a full page reload. Paginated pages
     * use a sparse client-side cache, so non-visible images are fetched only when
     * the user approaches them in the viewer.
     *
     * @returns {void}
     */
    function refreshLightboxOrderFromDom() {
        const nextVisibleCards = Array.from(document.querySelectorAll('[data-lightbox-image]'));
        const nextSourceCards = Array.from(document.querySelectorAll('[data-lightbox-source]'));
        if (nextSourceCards.length > 0) {
            cards = nextSourceCards;
            return;
        }
        if (lightboxEndpoint && lightboxTotal > nextVisibleCards.length) {
            const sparseCards = Array.from({length: lightboxTotal}, () => null);
            nextVisibleCards.forEach((card, fallbackIndex) => {
                const index = lightboxIndexForCard(card, fallbackIndex);
                if (index >= 0 && index < sparseCards.length) {
                    sparseCards[index] = card;
                }
            });
            cards = sparseCards;
            return;
        }
        cards = nextVisibleCards;
    }

    /**
     * Return a card's zero-based lightbox index.
     *
     * @param {Element} card Server-rendered or async-created lightbox source element.
     * @param {number} fallbackIndex Position used by legacy markup without explicit indexes.
     * @returns {number} Zero-based index, or -1 when no usable index exists.
     */
    function lightboxIndexForCard(card, fallbackIndex = -1) {
        if (!(card instanceof HTMLElement)) {
            return -1;
        }
        const explicitIndex = Number.parseInt(card.dataset.lightboxIndex || '', 10);
        if (Number.isInteger(explicitIndex) && explicitIndex >= 0) {
            return explicitIndex;
        }
        return Number.isInteger(fallbackIndex) && fallbackIndex >= 0 ? fallbackIndex : -1;
    }

    /**
     * Build a detached source element from one lazy lightbox JSON item.
     *
     * @param {Object<string, *>} item JSON item returned by the lightbox endpoint.
     * @returns {HTMLElement|null} Detached source element, or null when the payload is invalid.
     */
    function createLightboxCardFromItem(item) {
        if (!item || typeof item !== 'object') {
            return null;
        }
        const index = Number.parseInt(String(item.index ?? '-1'), 10);
        const imageId = Number.parseInt(String(item.id ?? '0'), 10);
        if (!Number.isInteger(index) || index < 0 || !Number.isInteger(imageId) || imageId <= 0) {
            return null;
        }
        const card = document.createElement('div');
        card.setAttribute('data-lightbox-source', '');
        card.dataset.lightboxIndex = String(index);
        card.dataset.imageId = String(imageId);
        card.dataset.galleryId = String(item.gallery_id ?? '');
        card.dataset.fullSrc = String(item.full_src || '');
        card.dataset.previewSrc = String(item.preview_src || item.full_src || '');
        card.dataset.pageUrl = String(item.page_url || '');
        card.dataset.galleryUrl = String(item.gallery_url || '');
        card.dataset.title = String(item.title || '');
        card.dataset.description = String(item.description || '');
        card.dataset.score = String(item.score ?? '0');
        card.dataset.userVote = String(item.user_vote ?? '0');
        card.dataset.imageWidth = String(item.width ?? '0');
        card.dataset.imageHeight = String(item.height ?? '0');
        if (item.voting_allowed) {
            card.dataset.votingAllowed = '1';
        }
        if (typeof item.vote_form_html === 'string' && item.vote_form_html.trim() !== '') {
            card.dataset.voteFormHtml = item.vote_form_html;
        }
        if (item.map_point && typeof item.map_point === 'object') {
            card.dataset.mapPoint = JSON.stringify(item.map_point);
        }
        return card;
    }

    /**
     * Store async lightbox items in the sparse client cache.
     *
     * @param {Array<Object<string, *>>} items JSON items returned by the endpoint.
     * @returns {void}
     */
    function mergeLightboxItems(items) {
        if (!Array.isArray(items)) {
            return;
        }
        items.forEach((item) => {
            const card = createLightboxCardFromItem(item);
            if (!card) {
                return;
            }
            const index = lightboxIndexForCard(card);
            if (index >= 0 && index < cards.length && !cards[index]) {
                cards[index] = card;
            }
        });
    }

    /**
     * Return an async metadata window that contains the requested index.
     *
     * @param {number} index Zero-based lightbox index.
     * @returns {{offset:number, limit:number}} Endpoint range parameters.
     */
    function lightboxWindowForIndex(index) {
        if (cards.length <= 0) {
            return {offset: 0, limit: lightboxWindowSize};
        }
        const leadingItems = Math.min(12, Math.max(4, Math.floor(lightboxWindowSize / 4)));
        const maxOffset = Math.max(0, cards.length - lightboxWindowSize);
        const offset = Math.max(0, Math.min(maxOffset, index - leadingItems));
        const limit = Math.min(lightboxWindowSize, cards.length - offset);
        return {offset, limit: Math.max(1, limit)};
    }

    /**
     * Return true when the requested sparse cache range already has metadata.
     *
     * @param {number} offset Zero-based range offset.
     * @param {number} limit Maximum number of positions to inspect.
     * @returns {boolean} True when every position in the range has a card.
     */
    function lightboxRangeLoaded(offset, limit) {
        const end = Math.min(cards.length, offset + limit);
        for (let index = offset; index < end; index += 1) {
            if (!cards[index]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Fetch one async metadata range unless it is already loaded or in flight.
     *
     * @param {number} offset Zero-based range offset.
     * @param {number} limit Maximum items to request.
     * @returns {Promise<boolean>} True when the request completed successfully or was unnecessary.
     */
    function fetchLightboxRange(offset, limit) {
        if (!lightboxEndpoint || cards.length === 0 || lightboxRangeLoaded(offset, limit)) {
            return Promise.resolve(true);
        }
        const key = `${offset}:${limit}`;
        if (lightboxPendingWindows.has(key)) {
            return lightboxPendingWindows.get(key);
        }
        const url = new URL(lightboxEndpoint, window.location.href);
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('limit', String(limit));
        const promise = fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then((payload) => {
                mergeLightboxItems(payload?.items || []);
                return true;
            })
            .catch((error) => {
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('Lazy lightbox metadata request failed.', {
                        endpoint: url.toString(),
                        offset,
                        limit,
                        error,
                    });
                }
                return false;
            })
            .finally(() => {
                lightboxPendingWindows.delete(key);
            });
        lightboxPendingWindows.set(key, promise);
        return promise;
    }

    /**
     * Ensure that metadata around one index is available.
     *
     * @param {number} index Zero-based lightbox index.
     * @returns {Promise<boolean>} True when the surrounding metadata is available.
     */
    function fetchLightboxWindowAround(index) {
        const range = lightboxWindowForIndex(index);
        return fetchLightboxRange(range.offset, range.limit);
    }

    refreshLightboxOrderFromDom();

    // GPS map buttons can exist on public cards even if the lightbox markup is
    // absent, so this listener is registered before the lightbox early return.
    setupGpsMaps(controller.signal);

    galleryLightboxState.cleanup = () => {
        controller.abort();
        cards = [];
        if (overlay?.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        if (overlay?.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
        if (overlay?.galleryLeafletMap) {
            overlay.galleryLeafletMap.remove();
            overlay.galleryLeafletMap = null;
        }
    };

    if (!overlay || cards.length === 0) {
        return;
    }

    document.addEventListener('publicGalleryPhotoOrderChanged', refreshLightboxOrderFromDom, {signal: controller.signal});

    // Variable `image` stores this steps working value.
    const image = overlay.querySelector('[data-lightbox-img]');
    // stageLink stores state or configuration for the gallery front-end flow.
    const stageLink = image ? image.closest('.lightbox-stage-link') : null;
    // initialLoader stores the progress UI shown before the first lazy item is ready.
    const initialLoader = overlay.querySelector('[data-lightbox-initial-loader]');
    // initialLoaderFill stores the visual bar that receives estimated progress.
    const initialLoaderFill = overlay.querySelector('[data-lightbox-initial-loader-fill]');
    // initialLoaderCount stores the optional loaded-range estimate for large galleries.
    const initialLoaderCount = overlay.querySelector('[data-lightbox-initial-loader-count]');
    // lightboxMeta stores state or configuration for the gallery front-end flow.
    const lightboxMeta = overlay.querySelector('.lightbox-meta');
    // lightboxDefaultTransitionDuration stores the quick manual viewer blend duration.
    const lightboxDefaultTransitionDuration = 80;
    // lightboxSlideshowVisibleDuration stores how long one slideshow image remains stable before the next blend starts.
    const lightboxSlideshowVisibleDuration = readLightboxTimingSetting('lightboxSlideshowVisibleMs', 2000, 500, 600000);
    // lightboxSlideshowTransitionDuration stores the slideshow blend duration for automatic picture changes.
    const lightboxSlideshowTransitionDuration = readLightboxTimingSetting('lightboxSlideshowTransitionMs', 1000, 0, 30000);
    // lightboxPreviewPreloadRadius stores state or configuration for the gallery front-end flow.
    const lightboxPreviewPreloadRadius = 8;
    // lightboxFullPreloadRadius stores state or configuration for the gallery front-end flow.
    const lightboxFullPreloadRadius = 2;
    // lightboxFullSwapIdleDelay stores state or configuration for the gallery front-end flow.
    const lightboxFullSwapIdleDelay = 80;
    // lightboxDecodedImageCacheLimit stores state or configuration for the gallery front-end flow.
    const lightboxDecodedImageCacheLimit = 48;
    // transitionImage stores state or configuration for the gallery front-end flow.
    let transitionImage = null;
    // activeLightboxTransitionToken stores state or configuration for the gallery front-end flow.
    let activeLightboxTransitionToken = 0;
    // pendingFullImageSwapTimer stores state or configuration for the gallery front-end flow.
    let pendingFullImageSwapTimer = null;
    // Variable `title` stores this steps working value.
    const title = overlay.querySelector('[data-lightbox-title]');
    // Variable `description` stores this steps working value.
    const description = overlay.querySelector('[data-lightbox-description]');
    // counters stores desktop and mobile counter hosts that mirror the same position text.
    const counters = Array.from(overlay.querySelectorAll('[data-lightbox-counter]'));
    // Variable `lightboxVotePanel` stores the host for the shared gallery-card vote widget.
    const lightboxVotePanel = overlay.querySelector('[data-lightbox-vote-panel]');
    // Variable `lightboxMapButton` stores this steps working value.
    const lightboxMapButton = overlay.querySelector('[data-lightbox-map]');
    // lightboxMapButtons stores every toolbar and fullscreen map control that mirrors current map availability.
    const lightboxMapButtons = Array.from(overlay.querySelectorAll('[data-lightbox-map]'));
    // lightboxMapSplit stores state or configuration for the gallery front-end flow.
    const lightboxMapSplit = overlay.querySelector('[data-lightbox-map-split]');
    // lightboxMapSplitClose stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitClose = overlay.querySelector('[data-lightbox-map-split-close]');
    // lightboxMapSplitTitle stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitTitle = overlay.querySelector('[data-lightbox-map-split-title]');
    // lightboxMapSplitCanvas stores state or configuration for the gallery front-end flow.
    const lightboxMapSplitCanvas = overlay.querySelector('[data-lightbox-map-split-canvas]');
    // Variable `currentIndex` stores this steps working value.
    let currentIndex = 0;
    // lightboxReturnUrl stores state or configuration for the gallery front-end flow.
    let lightboxReturnUrl = window.location.href;
    // lightboxHistoryActive stores state or configuration for the gallery front-end flow.
    let lightboxHistoryActive = false;
    // initialLightboxLoadActive is true only before the first requested photo is displayed.
    let initialLightboxLoadActive = false;
    // Variable `preloadedSources` stores this steps working value.
    const preloadedSources = new Set();
    // decodedLightboxImages stores state or configuration for the gallery front-end flow.
    const decodedLightboxImages = new Map();
    // fullscreenHideTimer stores state or configuration for the gallery front-end flow.
    let fullscreenHideTimer = null;
    // lightboxSlideshowTimer stores the automatic advance timer while slideshow mode is active.
    let lightboxSlideshowTimer = null;
    // lightboxSlideshowActive stores whether slideshow mode owns automatic fullscreen advancing.
    let lightboxSlideshowActive = false;
    // touchGesture stores the active mobile stage swipe, when one is in progress.
    let touchGesture = null;
    // mobileSwipeVisualTimer clears temporary swipe animation classes after they settle.
    let mobileSwipeVisualTimer = 0;
    // suppressNextStageClick prevents a completed swipe from also toggling controls through a synthetic tap.
    let suppressNextStageClick = false;
    // isMobileTouchDevice stores state or configuration for the gallery front-end flow.
    const isMobileTouchDevice = detectMobileTouchDevice();
    // galleryDevModeEnabled stores state or configuration for the gallery front-end flow.
    const galleryDevModeEnabled = Boolean(document.body?.dataset.devMode === '1' || window.PHPGalleryDevMode?.enabled);
    // galleryDevModeState stores state or configuration for the gallery front-end flow.
    const galleryDevModeState = {
        overlay: null,
        text: null,
        canvas: null,
        canvasContext: null,
        startedAt: performance.now(),
        lastRenderAt: 0,
        currentIndex: -1,
        currentSource: '',
        currentSourceKind: '',
        sourceStats: new Map(),
        eventLog: [],
        samples: [],
        preloadStarted: 0,
        loadStarted: 0,
        cacheHits: 0,
        cacheMisses: 0,
        decodeErrors: 0,
        evictions: 0,
        frameMs: 0,
        lastFrameAt: 0,
        frameId: 0,
        intervalId: 0,
    };
    setupGalleryDevModeOverlay();
    // supportsPointerGestures stores state or configuration for the gallery front-end flow.
    const supportsPointerGestures = Boolean(window.PointerEvent);
    // Touch events are the most reliable mobile signal for swipes in iOS Safari and Chrome mobile.
    const supportsTouchGestures = Boolean('ontouchstart' in window || navigator.maxTouchPoints > 0);
    // isLightboxDebugEnabled stores state or configuration for the gallery front-end flow.
    const isLightboxDebugEnabled = detectLightboxDebugFlag();
    overlay.classList.toggle('is-mobile-device', isMobileTouchDevice);
    prepareMobileLightboxOverlay();
    updateMobileLightboxViewport();
    window.__LIGHTBOX_DEBUG__ = isLightboxDebugEnabled;

    galleryLightboxState.cleanup = () => {
        controller.abort();
        cards = [];
        clearPendingFullImageSwap();
        clearLightboxHudTimer();
        resetMobileSwipeVisuals(false);
        stopLightboxSlideshow(false);
        removeTransitionImage();
        preloadedSources.clear();
        lightboxPendingWindows.clear();
        lightboxGalleryMapPayloadPromises.clear();
        decodedLightboxImages.clear();
        if (galleryDevModeState.frameId) {
            window.cancelAnimationFrame(galleryDevModeState.frameId);
            galleryDevModeState.frameId = 0;
        }
        if (galleryDevModeState.intervalId) {
            window.clearInterval(galleryDevModeState.intervalId);
            galleryDevModeState.intervalId = 0;
        }
        if (galleryDevModeState.overlay) {
            galleryDevModeState.overlay.remove();
            galleryDevModeState.overlay = null;
            galleryDevModeState.text = null;
            galleryDevModeState.canvas = null;
            galleryDevModeState.canvasContext = null;
        }
        if (overlay?.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        if (overlay?.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
        if (overlay?.galleryLeafletMap) {
            overlay.galleryLeafletMap.remove();
            overlay.galleryLeafletMap = null;
        }
        const mapOverlay = document.querySelector('[data-map-overlay]');
        if (mapOverlay?.galleryMapOverlayCloseController) {
            mapOverlay.galleryMapOverlayCloseController.abort();
            mapOverlay.galleryMapOverlayCloseController = null;
        }
        if (mapOverlay?.galleryLeafletMap) {
            mapOverlay.galleryLeafletMap.remove();
            mapOverlay.galleryLeafletMap = null;
        }
        if (mapOverlay instanceof HTMLElement) {
            mapOverlay.hidden = true;
        }
        if (document.fullscreenElement === overlay && document.exitFullscreen) {
            document.exitFullscreen().catch(() => undefined);
        }
        overlay.hidden = true;
        overlay.classList.remove('is-fullscreen', 'is-mobile-fullscreen', 'is-ui-visible', 'is-map-split', 'is-map-split-disabled', 'is-slideshow');
        overlay.removeAttribute('data-current-image-id');
        overlay.removeAttribute('data-current-title');
        if (image) {
            image.removeAttribute('src');
        }
        document.documentElement.classList.remove('has-lightbox', 'has-mobile-lightbox');
        document.body.classList.remove('has-lightbox', 'has-mobile-lightbox', 'has-map-overlay');
    };

    /**
     * Move the mobile overlay to the body root so fixed positioning is not affected by page layout wrappers.
     *
     * @returns {void}
     */
    function prepareMobileLightboxOverlay() {
        if (!isMobileTouchDevice || overlay.parentElement === document.body) {
            return;
        }
        document.body.append(overlay);
    }

    /**
     * Keep the CSS fullscreen shell aligned with the currently visible mobile viewport.
     *
     * @returns {void}
     */
    function updateMobileLightboxViewport() {
        if (!isMobileTouchDevice) {
            return;
        }
        const viewport = window.visualViewport;
        const viewportTop = Math.max(0, Math.round(viewport?.offsetTop || 0));
        const viewportWidth = Math.max(1, Math.round(viewport?.width || window.innerWidth || document.documentElement.clientWidth || 1));
        const viewportHeight = Math.max(1, Math.round(viewport?.height || window.innerHeight || document.documentElement.clientHeight || 1));
        overlay.style.setProperty('--lightbox-mobile-viewport-top', `${viewportTop}px`);
        overlay.style.setProperty('--lightbox-mobile-viewport-width', `${viewportWidth}px`);
        overlay.style.setProperty('--lightbox-mobile-viewport-height', `${viewportHeight}px`);
    }

    /**
     * Handles clear lightbox stage focus behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearLightboxStageFocus() {
        if (stageLink && document.activeElement === stageLink) {
            stageLink.blur();
        }
    }

    // activeLightboxImageToken stores state or configuration for the gallery front-end flow.
    let activeLightboxImageToken = 0;

    /**
     * Handles clear pending full image swap behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearPendingFullImageSwap() {
        if (pendingFullImageSwapTimer) {
            window.clearTimeout(pendingFullImageSwapTimer);
            pendingFullImageSwapTimer = null;
        }
    }

    /**
     * Read one numeric lightbox timing setting from the server-rendered overlay.
     *
     * The current markup only exposes defaults. Keeping the values in data
     * attributes makes future admin settings possible without changing the
     * slideshow scheduler again.
     *
     * @param {string} datasetKey Overlay dataset key to read.
     * @param {number} fallbackMs Fallback duration in milliseconds.
     * @param {number} minimumMs Lowest accepted duration in milliseconds.
     * @param {number} maximumMs Highest accepted duration in milliseconds.
     * @returns {number} Safe duration in milliseconds.
     */
    function readLightboxTimingSetting(datasetKey, fallbackMs, minimumMs, maximumMs) {
        const rawValue = Number.parseInt(overlay.dataset[datasetKey] || '', 10);
        if (!Number.isFinite(rawValue)) {
            return fallbackMs;
        }
        return Math.max(minimumMs, Math.min(maximumMs, rawValue));
    }

    /**
     * Return the transition duration for the next image blend.
     *
     * Manual navigation keeps the existing snappy transition. Slideshow mode
     * uses the slower configurable blend requested for automatic playback.
     *
     * @returns {number} Transition duration in milliseconds.
     */
    function currentLightboxTransitionDuration() {
        return lightboxSlideshowActive ? lightboxSlideshowTransitionDuration : lightboxDefaultTransitionDuration;
    }

    /**
     * Handles decode loaded image behavior for the gallery UI.
     * @param {*} loadedImage Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function decodeLoadedImage(loadedImage) {
        if (typeof loadedImage.decode !== 'function') {
            return Promise.resolve();
        }
        return loadedImage.decode().catch(() => undefined);
    }

    /**
     * Handles setup gallery dev mode overlay behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function setupGalleryDevModeOverlay() {
        if (!galleryDevModeEnabled) {
            return;
        }
        // shell stores state or configuration for the gallery front-end flow.
        const shell = document.createElement('section');
        shell.className = 'gallery-dev-overlay';
        shell.setAttribute('aria-label', 'Gallery dev mode diagnostics');
        shell.innerHTML = '<header><strong>DEV</strong><span data-dev-title>viewer diagnostics</span></header><pre data-dev-text></pre><canvas width="340" height="72" data-dev-canvas></canvas><footer><span>Drag disabled</span><span>admin only</span></footer>';
        galleryDevModeState.overlay = shell;
        galleryDevModeState.text = shell.querySelector('[data-dev-text]');
        galleryDevModeState.canvas = shell.querySelector('[data-dev-canvas]');
        galleryDevModeState.canvasContext = galleryDevModeState.canvas ? galleryDevModeState.canvas.getContext('2d') : null;
        overlay.append(shell);
        cards.forEach((card, index) => {
            if (!card) {
                return;
            }
            devRegisterSource(card.dataset.previewSrc || card.dataset.fullSrc || '', 'preview', index, 'idle');
            devRegisterSource(card.dataset.fullSrc || card.dataset.previewSrc || '', 'full', index, 'idle');
        });
        galleryDevModeState.frameId = requestAnimationFrame(devFrameTick);
        galleryDevModeState.intervalId = window.setInterval(renderGalleryDevModeOverlay, 350);
        renderGalleryDevModeOverlay();
    }

    /**
     * Handles dev frame tick behavior for the gallery UI.
     * @param {*} timestamp Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFrameTick(timestamp) {
        if (!galleryDevModeEnabled || controller.signal.aborted) {
            return;
        }
        if (galleryDevModeState.lastFrameAt > 0) {
            galleryDevModeState.frameMs = timestamp - galleryDevModeState.lastFrameAt;
        }
        galleryDevModeState.lastFrameAt = timestamp;
        galleryDevModeState.frameId = requestAnimationFrame(devFrameTick);
    }

    /**
     * Handles dev register source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} kind Value supplied by the caller or event context.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devRegisterSource(src, kind, index, status) {
        if (!galleryDevModeEnabled || !src) {
            return null;
        }
        // existing stores state or configuration for the gallery front-end flow.
        const existing = galleryDevModeState.sourceStats.get(src) || {};
        // card stores state or configuration for the gallery front-end flow.
        const card = cards[index] || null;
        // width stores state or configuration for the gallery front-end flow.
        const width = Number.parseInt(card?.dataset.imageWidth || '0', 10) || existing.width || 0;
        // height stores state or configuration for the gallery front-end flow.
        const height = Number.parseInt(card?.dataset.imageHeight || '0', 10) || existing.height || 0;
        // stat stores state or configuration for the gallery front-end flow.
        const stat = {
            src,
            kind: existing.kind || kind,
            index: Number.isInteger(existing.index) ? existing.index : index,
            status: status || existing.status || 'idle',
            width,
            height,
            naturalWidth: existing.naturalWidth || 0,
            naturalHeight: existing.naturalHeight || 0,
            startedAt: existing.startedAt || 0,
            finishedAt: existing.finishedAt || 0,
            lastUsedAt: performance.now(),
            lastReason: existing.lastReason || '',
        };
        if (existing.kind && existing.kind !== kind) {
            stat.kind = 'shared';
        }
        galleryDevModeState.sourceStats.set(src, stat);
        return stat;
    }

    /**
     * Handles dev find source kind behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFindSourceKind(src) {
        if (!src) {
            return '';
        }
        for (const card of cards) {
            if (!card) {
                continue;
            }
            if (card.dataset.previewSrc === src && card.dataset.fullSrc === src) {
                return 'preview+full';
            }
            if (card.dataset.previewSrc === src) {
                return 'preview';
            }
            if (card.dataset.fullSrc === src) {
                return 'full';
            }
        }
        return 'unknown';
    }

    /**
     * Handles dev find source index behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFindSourceIndex(src) {
        if (!src) {
            return -1;
        }
        return cards.findIndex((card) => card && (card.dataset.previewSrc === src || card.dataset.fullSrc === src));
    }

    /**
     * Handles dev mark source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @param {*} reason Value supplied by the caller or event context.
     * @param {*} imageNode Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devMarkSource(src, status, reason, imageNode = null) {
        if (!galleryDevModeEnabled || !src) {
            return;
        }
        // index stores state or configuration for the gallery front-end flow.
        const index = devFindSourceIndex(src);
        // kind stores state or configuration for the gallery front-end flow.
        const kind = devFindSourceKind(src);
        // stat stores state or configuration for the gallery front-end flow.
        const stat = devRegisterSource(src, kind, index, status);
        if (!stat) {
            return;
        }
        stat.status = status;
        stat.lastReason = reason || '';
        stat.lastUsedAt = performance.now();
        if (status === 'loading' || status === 'preloading') {
            stat.startedAt = stat.startedAt || performance.now();
        }
        if (status === 'ready' || status === 'error') {
            stat.finishedAt = performance.now();
        }
        if (imageNode) {
            stat.naturalWidth = imageNode.naturalWidth || stat.naturalWidth || 0;
            stat.naturalHeight = imageNode.naturalHeight || stat.naturalHeight || 0;
        }
        galleryDevModeState.sourceStats.set(src, stat);
        devLog(`${kind}:${status}:${reason || 'state'}`);
    }

    /**
     * Handles dev log behavior for the gallery UI.
     * @param {*} message Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devLog(message) {
        if (!galleryDevModeEnabled) {
            return;
        }
        galleryDevModeState.eventLog.unshift(`${formatDevTime(performance.now() - galleryDevModeState.startedAt)} ${message}`);
        galleryDevModeState.eventLog = galleryDevModeState.eventLog.slice(0, 8);
    }

    /**
     * Handles dev decoded memory bytes behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devDecodedMemoryBytes() {
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        galleryDevModeState.sourceStats.forEach((stat, src) => {
            if (!decodedLightboxImages.has(src) || stat.status !== 'ready') {
                return;
            }
            // width stores state or configuration for the gallery front-end flow.
            const width = stat.naturalWidth || stat.width || 0;
            // height stores state or configuration for the gallery front-end flow.
            const height = stat.naturalHeight || stat.height || 0;
            if (width > 0 && height > 0) {
                total += width * height * 4;
            }
        });
        return total;
    }

    /**
     * Handles dev status counts behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devStatusCounts() {
        // counts stores state or configuration for the gallery front-end flow.
        const counts = {idle: 0, preloading: 0, loading: 0, ready: 0, error: 0};
        galleryDevModeState.sourceStats.forEach((stat) => {
            counts[stat.status] = (counts[stat.status] || 0) + 1;
        });
        return counts;
    }

    /**
     * Handles dev current window summary behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devCurrentWindowSummary() {
        if (galleryDevModeState.currentIndex < 0) {
            return 'not open';
        }
        // rows stores state or configuration for the gallery front-end flow.
        const rows = [];
        // current stores state or configuration for the gallery front-end flow.
        const current = galleryDevModeState.currentIndex;
        for (let offset = -3; offset <= 3; offset += 1) {
            // index stores state or configuration for the gallery front-end flow.
            const index = (current + offset + cards.length) % cards.length;
            // card stores state or configuration for the gallery front-end flow.
            const card = cards[index];
            // preview stores state or configuration for the gallery front-end flow.
            const preview = galleryDevModeState.sourceStats.get(card?.dataset.previewSrc || '');
            // full stores state or configuration for the gallery front-end flow.
            const full = galleryDevModeState.sourceStats.get(card?.dataset.fullSrc || '');
            // mark stores state or configuration for the gallery front-end flow.
            const mark = offset === 0 ? '*' : (offset > 0 ? '+' : '');
            rows.push(`${mark}${offset}:P${devShortStatus(preview)} F${devShortStatus(full)}`);
        }
        return rows.join(' ');
    }

    /**
     * Handles dev short status behavior for the gallery UI.
     * @param {*} stat Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devShortStatus(stat) {
        if (!stat) {
            return '?';
        }
        return {idle: 'i', preloading: 'p', loading: 'l', ready: 'r', error: 'e'}[stat.status] || '?';
    }

    /**
     * Handles dev browser memory line behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devBrowserMemoryLine() {
        // memory stores state or configuration for the gallery front-end flow.
        const memory = performance.memory;
        if (memory && typeof memory.usedJSHeapSize === 'number') {
            return `heap ${formatBytes(memory.usedJSHeapSize)} / ${formatBytes(memory.jsHeapSizeLimit)}`;
        }
        if (navigator.deviceMemory) {
            return `deviceMemory ${navigator.deviceMemory} GB, heap unavailable`;
        }
        return 'heap unavailable';
    }

    /**
     * Handles dev connection line behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devConnectionLine() {
        // connection stores state or configuration for the gallery front-end flow.
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return 'network hints unavailable';
        }
        // parts stores state or configuration for the gallery front-end flow.
        const parts = [];
        if (connection.effectiveType) {
            parts.push(connection.effectiveType);
        }
        if (typeof connection.downlink === 'number') {
            parts.push(`${connection.downlink} Mbps`);
        }
        if (connection.saveData) {
            parts.push('save-data');
        }
        return parts.length ? parts.join(', ') : 'network hints unavailable';
    }

    /**
     * Handles render gallery dev mode overlay behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function renderGalleryDevModeOverlay() {
        if (!galleryDevModeEnabled || !galleryDevModeState.overlay || !galleryDevModeState.text) {
            return;
        }
        // now stores state or configuration for the gallery front-end flow.
        const now = performance.now();
        // counts stores state or configuration for the gallery front-end flow.
        const counts = devStatusCounts();
        // decodedBytes stores state or configuration for the gallery front-end flow.
        const decodedBytes = devDecodedMemoryBytes();
        // cacheLimit stores state or configuration for the gallery front-end flow.
        const cacheLimit = lightboxDecodedImageCacheLimit;
        // browserMode stores state or configuration for the gallery front-end flow.
        const browserMode = isLightboxFullscreen() ? 'fullscreen' : (overlay.hidden ? 'closed' : 'normal');
        // currentCard stores state or configuration for the gallery front-end flow.
        const currentCard = cards[galleryDevModeState.currentIndex] || null;
        // currentSize stores state or configuration for the gallery front-end flow.
        const currentSize = currentCard ? `${currentCard.dataset.imageWidth || '?'}x${currentCard.dataset.imageHeight || '?'}` : 'n/a';
        // historySample stores state or configuration for the gallery front-end flow.
        const historySample = {
            ready: counts.ready,
            cached: decodedLightboxImages.size,
            memory: decodedBytes,
            frame: galleryDevModeState.frameMs,
            time: now,
        };
        galleryDevModeState.samples.push(historySample);
        galleryDevModeState.samples = galleryDevModeState.samples.slice(-90);
        // lines stores state or configuration for the gallery front-end flow.
        const lines = [
            `mode ${browserMode} | image ${galleryDevModeState.currentIndex + 1 || 0}/${cards.length} | ${currentSize} | src ${galleryDevModeState.currentSourceKind || 'none'}`,
            `preload radius P${lightboxPreviewPreloadRadius}/F${lightboxFullPreloadRadius} | cache ${decodedLightboxImages.size}/${cacheLimit} | known ${galleryDevModeState.sourceStats.size}`,
            `state idle ${counts.idle} | pre ${counts.preloading} | load ${counts.loading} | ready ${counts.ready} | err ${counts.error}`,
            `events preload ${galleryDevModeState.preloadStarted} | load ${galleryDevModeState.loadStarted} | hit ${galleryDevModeState.cacheHits} | miss ${galleryDevModeState.cacheMisses} | evict ${galleryDevModeState.evictions}`,
            `decoded estimate ${formatBytes(decodedBytes)} | ${devBrowserMemoryLine()} | frame ${galleryDevModeState.frameMs.toFixed(1)} ms`,
            `network ${devConnectionLine()} | active ${shortenDevUrl(galleryDevModeState.currentSource)}`,
            `window ${devCurrentWindowSummary()}`,
            `recent ${galleryDevModeState.eventLog.slice(0, 3).join(' | ') || 'none'}`,
        ];
        galleryDevModeState.text.textContent = lines.join('\n');
        drawGalleryDevModeGraph();
    }

    /**
     * Handles draw gallery dev mode graph behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function drawGalleryDevModeGraph() {
        // canvas stores state or configuration for the gallery front-end flow.
        const canvas = galleryDevModeState.canvas;
        // context stores state or configuration for the gallery front-end flow.
        const context = galleryDevModeState.canvasContext;
        if (!canvas || !context) {
            return;
        }
        // width stores state or configuration for the gallery front-end flow.
        const width = canvas.width;
        // height stores state or configuration for the gallery front-end flow.
        const height = canvas.height;
        context.clearRect(0, 0, width, height);
        context.globalAlpha = 1;
        context.fillStyle = 'rgba(0,0,0,0.42)';
        context.fillRect(0, 0, width, height);
        // samples stores state or configuration for the gallery front-end flow.
        const samples = galleryDevModeState.samples;
        if (samples.length < 2) {
            return;
        }
        // maxMemory stores state or configuration for the gallery front-end flow.
        const maxMemory = Math.max(1, ...samples.map((sample) => sample.memory));
        // maxReady stores state or configuration for the gallery front-end flow.
        const maxReady = Math.max(1, ...samples.map((sample) => sample.ready));
        // maxFrame stores state or configuration for the gallery front-end flow.
        const maxFrame = Math.max(16, ...samples.map((sample) => sample.frame));
        drawDevLine(samples, (sample) => sample.memory / maxMemory, height, width);
        drawDevLine(samples, (sample) => sample.ready / maxReady, height, width, 0.66);
        drawDevLine(samples, (sample) => Math.min(1, sample.frame / maxFrame), height, width, 0.36);
        context.globalAlpha = 0.8;
        context.fillStyle = 'rgba(255,255,255,0.85)';
        context.font = '10px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
        context.fillText('memory / ready / frame', 8, 14);
    }

    /**
     * Handles draw dev line behavior for the gallery UI.
     * @param {*} samples Value supplied by the caller or event context.
     * @param {*} selector Value supplied by the caller or event context.
     * @param {*} height Value supplied by the caller or event context.
     * @param {*} width Value supplied by the caller or event context.
     * @param {*} alpha Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function drawDevLine(samples, selector, height, width, alpha = 1) {
        // context stores state or configuration for the gallery front-end flow.
        const context = galleryDevModeState.canvasContext;
        if (!context) {
            return;
        }
        context.beginPath();
        samples.forEach((sample, index) => {
            // x stores state or configuration for the gallery front-end flow.
            const x = (index / Math.max(1, samples.length - 1)) * width;
            // y stores state or configuration for the gallery front-end flow.
            const y = height - (selector(sample) * (height - 18)) - 4;
            if (index === 0) {
                context.moveTo(x, y);
            } else {
                context.lineTo(x, y);
            }
        });
        context.globalAlpha = alpha;
        context.strokeStyle = 'rgba(255,255,255,0.92)';
        context.lineWidth = 1.5;
        context.stroke();
        context.globalAlpha = 1;
    }

    /**
     * Handles format bytes behavior for the gallery UI.
     * @param {*} bytes Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 B';
        }
        // units stores state or configuration for the gallery front-end flow.
        const units = ['B', 'KB', 'MB', 'GB'];
        // value stores state or configuration for the gallery front-end flow.
        let value = bytes;
        // unitIndex stores state or configuration for the gallery front-end flow.
        let unitIndex = 0;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }
        return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }

    /**
     * Handles format dev time behavior for the gallery UI.
     * @param {*} ms Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function formatDevTime(ms) {
        return `${(ms / 1000).toFixed(1)}s`;
    }

    /**
     * Handles shorten dev url behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function shortenDevUrl(src) {
        if (!src) {
            return 'none';
        }
        try {
            // url stores state or configuration for the gallery front-end flow.
            const url = new URL(src, window.location.href);
            // last stores state or configuration for the gallery front-end flow.
            const last = url.pathname.split('/').filter(Boolean).pop() || url.pathname;
            return decodeURIComponent(last).slice(0, 46);
        } catch {
            return src.slice(0, 46);
        }
    }

    /**
     * Handles load fresh decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function loadFreshDecodedLightboxImage(src) {
        return new Promise((resolve, reject) => {
            if (!src) {
                reject(new Error('Missing lightbox image source.'));
                return;
            }
            galleryDevModeState.loadStarted += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'loading', 'fresh');
            // loadedImage stores state or configuration for the gallery front-end flow.
            const loadedImage = new Image();
            loadedImage.decoding = 'async';
            loadedImage.loading = 'eager';
            loadedImage.onload = () => {
                decodeLoadedImage(loadedImage).then(() => {
                    devMarkSource(src, 'ready', 'decoded', loadedImage);
                    resolve(loadedImage);
                });
            };
            loadedImage.onerror = () => {
                galleryDevModeState.decodeErrors += galleryDevModeEnabled ? 1 : 0;
                devMarkSource(src, 'error', 'load');
                reject(new Error('Lightbox image load failed.'));
            };
            loadedImage.src = src;
        });
    }

    /**
     * Handles remember decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} preloadPromise Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function rememberDecodedLightboxImage(src, preloadPromise) {
        if (decodedLightboxImages.has(src)) {
            decodedLightboxImages.delete(src);
        }
        decodedLightboxImages.set(src, preloadPromise);
        trimDecodedLightboxImageCache();
        return preloadPromise;
    }

    /**
     * Handles trim decoded lightbox image cache behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function trimDecodedLightboxImageCache() {
        while (decodedLightboxImages.size > lightboxDecodedImageCacheLimit) {
            // oldestKey stores state or configuration for the gallery front-end flow.
            const oldestKey = decodedLightboxImages.keys().next().value;
            if (!oldestKey) {
                return;
            }
            decodedLightboxImages.delete(oldestKey);
            if (galleryDevModeEnabled) {
                galleryDevModeState.evictions += 1;
                devLog(`evict:${shortenDevUrl(oldestKey)}`);
            }
        }
    }

    /**
     * Handles preload decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadDecodedLightboxImage(src) {
        if (!src) {
            return Promise.resolve(null);
        }
        if (decodedLightboxImages.has(src)) {
            galleryDevModeState.cacheHits += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'preloading', 'preload-hit');
            // cachedPromise stores state or configuration for the gallery front-end flow.
            const cachedPromise = decodedLightboxImages.get(src);
            decodedLightboxImages.delete(src);
            decodedLightboxImages.set(src, cachedPromise);
            return cachedPromise;
        }
        galleryDevModeState.cacheMisses += galleryDevModeEnabled ? 1 : 0;
        galleryDevModeState.preloadStarted += galleryDevModeEnabled ? 1 : 0;
        devMarkSource(src, 'preloading', 'preload-miss');
        // preloadPromise stores state or configuration for the gallery front-end flow.
        const preloadPromise = loadFreshDecodedLightboxImage(src).catch(() => null);
        return rememberDecodedLightboxImage(src, preloadPromise);
    }

    /**
     * Handles load decoded lightbox image behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function loadDecodedLightboxImage(src) {
        if (!src) {
            return Promise.reject(new Error('Missing lightbox image source.'));
        }
        if (decodedLightboxImages.has(src)) {
            galleryDevModeState.cacheHits += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'loading', 'load-hit');
            // cachedPromise stores state or configuration for the gallery front-end flow.
            const cachedPromise = decodedLightboxImages.get(src);
            decodedLightboxImages.delete(src);
            decodedLightboxImages.set(src, cachedPromise);
            return cachedPromise.then((preloadedImage) => {
                if (preloadedImage) {
                    return preloadedImage;
                }
                // freshPromise stores state or configuration for the gallery front-end flow.
                const freshPromise = loadFreshDecodedLightboxImage(src);
                rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
                return freshPromise;
            });
        }
        galleryDevModeState.cacheMisses += galleryDevModeEnabled ? 1 : 0;
        // freshPromise stores state or configuration for the gallery front-end flow.
        const freshPromise = loadFreshDecodedLightboxImage(src);
        rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
        return freshPromise;
    }

    /**
     * Handles remove transition image behavior for the gallery UI.
     * @param {*} node Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function removeTransitionImage(node) {
        // imageToRemove stores state or configuration for the gallery front-end flow.
        const imageToRemove = node || transitionImage;
        if (!imageToRemove) {
            return;
        }
        imageToRemove.remove();
        if (!node || transitionImage === node) {
            transitionImage = null;
        }
    }

    /**
     * Handles update normal lightbox stage size behavior for the gallery UI.
     * @param {*} card Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function updateNormalLightboxStageSize(card) {
        if (!stageLink || !card || overlay.classList.contains('is-fullscreen') || overlay.classList.contains('is-mobile-fullscreen')) {
            return;
        }
        // naturalWidth stores state or configuration for the gallery front-end flow.
        const naturalWidth = Number.parseInt(card.dataset.imageWidth || '0', 10);
        // naturalHeight stores state or configuration for the gallery front-end flow.
        const naturalHeight = Number.parseInt(card.dataset.imageHeight || '0', 10);
        if (!naturalWidth || !naturalHeight) {
            stageLink.style.removeProperty('--lightbox-stage-width');
            stageLink.style.removeProperty('--lightbox-stage-height');
            return;
        }
        // rootFontSize stores state or configuration for the gallery front-end flow.
        const rootFontSize = Number.parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
        // availableWidth stores state or configuration for the gallery front-end flow.
        const availableWidth = Math.max(240, window.innerWidth - (12 * rootFontSize));
        // measuredMetaHeight stores state or configuration for the gallery front-end flow.
        const measuredMetaHeight = lightboxMeta && !overlay.hidden ? lightboxMeta.getBoundingClientRect().height : 0;
        // verticalReserve stores state or configuration for the gallery front-end flow.
        const verticalReserve = Math.max(5 * rootFontSize, measuredMetaHeight + (3 * rootFontSize));
        // availableHeight stores state or configuration for the gallery front-end flow.
        const availableHeight = Math.max(180, Math.min(window.innerHeight * 0.70, window.innerHeight - verticalReserve));
        // imageRatio stores state or configuration for the gallery front-end flow.
        const imageRatio = naturalWidth / naturalHeight;
        // stageWidth stores state or configuration for the gallery front-end flow.
        let stageWidth = availableWidth;
        // stageHeight stores state or configuration for the gallery front-end flow.
        let stageHeight = stageWidth / imageRatio;
        if (stageHeight > availableHeight) {
            stageHeight = availableHeight;
            stageWidth = stageHeight * imageRatio;
        }
        stageLink.style.setProperty('--lightbox-stage-width', `${Math.round(stageWidth)}px`);
        stageLink.style.setProperty('--lightbox-stage-height', `${Math.round(stageHeight)}px`);
    }

    /**
     * Handles apply lightbox image source behavior for the gallery UI.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function applyLightboxImageSource(src, altText) {
        if (!src) {
            image.alt = altText;
            return;
        }
        galleryDevModeState.currentSource = src;
        galleryDevModeState.currentSourceKind = devFindSourceKind(src);
        devMarkSource(src, 'ready', 'display');
        if (image.getAttribute('src') === src) {
            image.alt = altText;
            return;
        }
        image.src = src;
        image.alt = altText;
    }

    /**
     * Handles show lightbox image source behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} token Value supplied by the caller or event context.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @param {*} immediate Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxImageSource(index, token, src, altText, immediate) {
        if (!src) {
            return Promise.resolve(false);
        }
        if (immediate || !stageLink || !image.getAttribute('src')) {
            activeLightboxTransitionToken += 1;
            removeTransitionImage();
            applyLightboxImageSource(src, altText);
            return Promise.resolve(true);
        }
        return loadDecodedLightboxImage(src).then((loadedImage) => new Promise((resolve) => {
            if (currentIndex !== index || activeLightboxImageToken !== token) {
                resolve(false);
                return;
            }
            if (image.getAttribute('src') === src) {
                image.alt = altText;
                resolve(true);
                return;
            }
            activeLightboxTransitionToken += 1;
            // transitionToken stores state or configuration for the gallery front-end flow.
            const transitionToken = activeLightboxTransitionToken;
            removeTransitionImage();
            // transitionNode stores state or configuration for the gallery front-end flow.
            const transitionNode = loadedImage.cloneNode(false);
            transitionNode.alt = '';
            transitionNode.setAttribute('aria-hidden', 'true');
            transitionNode.className = 'lightbox-transition-image';
            // transitionDuration stores the exact duration used for this single blend.
            // Capture it before scheduling frames so stopping slideshow mode mid-transition
            // cannot shorten the active automatic picture blend.
            const transitionDuration = currentLightboxTransitionDuration();
            transitionNode.style.setProperty('--lightbox-transition-duration', `${transitionDuration}ms`);
            transitionImage = transitionNode;
            stageLink.append(transitionNode);
            // Force the browser to commit the initial opacity before enabling the
            // visible state. This makes slideshow blends reliable in fullscreen,
            // especially when the decoded image is already hot in browser cache.
            transitionNode.getBoundingClientRect();
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    if (
                        currentIndex !== index ||
                        activeLightboxImageToken !== token ||
                        activeLightboxTransitionToken !== transitionToken ||
                        transitionImage !== transitionNode
                    ) {
                        removeTransitionImage(transitionNode);
                        resolve(false);
                        return;
                    }
                    transitionNode.classList.add('is-visible');
                    window.setTimeout(() => {
                        if (
                            currentIndex !== index ||
                            activeLightboxImageToken !== token ||
                            activeLightboxTransitionToken !== transitionToken ||
                            transitionImage !== transitionNode
                        ) {
                            removeTransitionImage(transitionNode);
                            resolve(false);
                            return;
                        }
                        applyLightboxImageSource(src, altText);
                        requestAnimationFrame(() => {
                            removeTransitionImage(transitionNode);
                            resolve(true);
                        });
                    }, transitionDuration);
                });
            });
        })).catch(() => false);
    }

    /**
     * Handles swap lightbox image after decode behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} token Value supplied by the caller or event context.
     * @param {*} previewSrc Value supplied by the caller or event context.
     * @param {*} fullSrc Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function swapLightboxImageAfterDecode(index, token, previewSrc, fullSrc, altText) {
        if (!fullSrc || !previewSrc || fullSrc === previewSrc) {
            return Promise.resolve(false);
        }
        clearPendingFullImageSwap();
        return new Promise((resolve) => {
            pendingFullImageSwapTimer = window.setTimeout(() => {
                pendingFullImageSwapTimer = null;
                loadDecodedLightboxImage(fullSrc).then(() => {
                    if (currentIndex !== index || activeLightboxImageToken !== token) {
                        resolve(false);
                        return;
                    }
                    applyLightboxImageSource(fullSrc, altText);
                    resolve(true);
                }).catch(() => resolve(false));
            }, lightboxFullSwapIdleDelay);
        });
    }

    /**
     * Notify the optional anonymous telemetry module about a lightbox photo view.
     * @param {*} card Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function telemetryPhotoOpened(card) {
        if (!window.PHPGalleryTelemetryPhotoOpened || !card) {
            return;
        }
        const telemetryConfig = window.PHPGalleryTelemetry || {};
        window.PHPGalleryTelemetryPhotoOpened(
            Number(card.dataset.imageId || 0),
            Number(card.dataset.galleryId || telemetryConfig.galleryId || 0),
            document.fullscreenElement ? 'fullscreen' : 'normal'
        );
    }

    /**
     * Notify the optional anonymous telemetry module that the active photo view ended.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function telemetryPhotoClosed() {
        if (window.PHPGalleryTelemetryPhotoClosed) {
            window.PHPGalleryTelemetryPhotoClosed();
        }
    }

    /**
     * Compare two browser-visible URLs without being sensitive to relative input.
     *
     * The lightbox opens direct photo URLs by replacing the current history entry.
     * When the current page is already that photo URL, close should fall back to
     * the gallery URL rendered by PHP. When the current page is a paginated
     * gallery URL, close must restore that exact URL instead.
     *
     * @param {string} firstUrl First URL candidate.
     * @param {string} secondUrl Second URL candidate.
     * @returns {boolean} True when both URLs resolve to the same browser URL.
     */
    function urlsMatch(firstUrl, secondUrl) {
        if (!firstUrl || !secondUrl) {
            return false;
        }
        try {
            return new URL(firstUrl, window.location.href).href === new URL(secondUrl, window.location.href).href;
        } catch (error) {
            return firstUrl === secondUrl;
        }
    }

    /**
     * Show or update the initial lazy-loading progress indicator.
     *
     * The gallery can know the total image count and the requested index, but it
     * cannot know server-side metadata generation progress precisely. This uses
     * a bounded estimate based on the requested metadata window so the visitor
     * sees movement without pretending to report exact work completed.
     *
     * @param {number} index Zero-based requested lightbox index.
     * @param {number} progressPercent Estimated progress between 1 and 100.
     * @returns {void}
     */
    function showInitialLightboxLoader(index, progressPercent = 12) {
        if (!(initialLoader instanceof HTMLElement)) {
            return;
        }
        const safeProgress = Math.max(1, Math.min(100, Math.round(progressPercent)));
        initialLightboxLoadActive = true;
        initialLoader.hidden = false;
        initialLoader.setAttribute('aria-busy', 'true');
        overlay.classList.add('is-initial-loading');
        if (initialLoaderFill instanceof HTMLElement) {
            initialLoaderFill.style.setProperty('--lightbox-initial-loader-progress', `${safeProgress}%`);
        }
        if (initialLoaderCount instanceof HTMLElement) {
            const safeTotal = Math.max(cards.length, lightboxTotal, 0);
            initialLoaderCount.textContent = safeTotal > 0
                ? i18n('lightbox.initial_loader_count', 'Preparing photo {current} of {total}', {current: index + 1, total: safeTotal})
                : '';
        }
        updateLightboxCounters(cards.length > 0 ? `${index + 1} / ${cards.length}` : '');
        overlay.hidden = false;
        document.documentElement.classList.add('has-lightbox');
        document.body.classList.add('has-lightbox');
        if (isMobileTouchDevice && !isLightboxFullscreen()) {
            enterMobileLightboxFullscreen();
        }
        updateLightboxViewportMode();
    }

    /**
     * Hide the initial lazy-loading progress indicator.
     *
     * @returns {void}
     */
    function hideInitialLightboxLoader() {
        initialLightboxLoadActive = false;
        overlay.classList.remove('is-initial-loading');
        if (initialLoader instanceof HTMLElement) {
            initialLoader.hidden = true;
            initialLoader.removeAttribute('aria-busy');
        }
    }

    /**
     * Estimate first-open progress from the metadata window requested for one index.
     *
     * @param {number} index Zero-based requested lightbox index.
     * @returns {number} Estimated progress percentage.
     */
    function estimateInitialLightboxProgress(index) {
        if (cards.length <= 0) {
            return 12;
        }
        const range = lightboxWindowForIndex(index);
        const loadedBefore = cards.reduce((count, candidate) => count + (candidate ? 1 : 0), 0);
        const expectedAfter = Math.min(cards.length, loadedBefore + range.limit);
        return Math.max(12, Math.min(90, (expectedAfter / cards.length) * 100));
    }

    /**
     * Open the lightbox at a specific index.
     *
     * @param {number} index Zero-based image index.
     * @param {{revealHud?: boolean}} options Optional display behavior for automatic slideshow changes.
     * @returns {void}
     */
    function openAt(index, options = {}) {
        if (cards.length === 0) {
            return;
        }
        const revealHud = options.revealHud !== false;
        clearLightboxSlideshowTimer();
        const normalizedIndex = ((index % cards.length) + cards.length) % cards.length;
        // Variable `card` stores this steps working value.
        const card = cards[normalizedIndex];
        const isInitialPhotoOpen = overlay.hidden || !image.getAttribute('src') || overlay.classList.contains('is-initial-loading');
        if (isInitialPhotoOpen) {
            showInitialLightboxLoader(normalizedIndex, estimateInitialLightboxProgress(normalizedIndex));
        }
        if (!card) {
            currentIndex = normalizedIndex;
            galleryDevModeState.currentIndex = normalizedIndex;
            fetchLightboxWindowAround(normalizedIndex).then((loaded) => {
                if (!loaded || controller.signal.aborted || currentIndex !== normalizedIndex) {
                    return;
                }
                openAt(normalizedIndex);
            });
            return;
        }
        if (!isInitialPhotoOpen) {
            hideInitialLightboxLoader();
        }
        currentIndex = normalizedIndex;
        galleryDevModeState.currentIndex = normalizedIndex;
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearPendingFullImageSwap();
        // imageToken stores state or configuration for the gallery front-end flow.
        const imageToken = activeLightboxImageToken;
        // pageUrl stores state or configuration for the gallery front-end flow.
        const pageUrl = card.dataset.pageUrl || '';
        // galleryUrl stores the page that should be restored when the lightbox closes.
        // Prefer the current browser URL when the visitor opens a photo from a
        // paginated gallery page, because the server-rendered fallback points to
        // the base gallery URL and intentionally has no active pagination state.
        const galleryUrl = window.location.href;
        const fallbackGalleryUrl = card.dataset.galleryUrl || galleryUrl;
        if (!lightboxHistoryActive) {
            lightboxReturnUrl = pageUrl && urlsMatch(galleryUrl, pageUrl) ? fallbackGalleryUrl : galleryUrl;
            lightboxHistoryActive = true;
        }
        if (pageUrl && window.history && window.history.replaceState) {
            window.history.replaceState({lightbox: true}, '', pageUrl);
        }
        // previewSrc stores state or configuration for the gallery front-end flow.
        const previewSrc = card.dataset.previewSrc || card.dataset.fullSrc || '';
        // fullSrc stores state or configuration for the gallery front-end flow.
        const fullSrc = card.dataset.fullSrc || previewSrc;
        // altText stores state or configuration for the gallery front-end flow.
        const altText = card.dataset.title || '';
        const titleText = (card.dataset.title || '').trim();
        title.textContent = titleText;
        title.hidden = titleText === '';
        const descriptionText = (card.dataset.description || '').trim();
        description.textContent = descriptionText;
        description.hidden = descriptionText === '';
        updateLightboxCounters(`${normalizedIndex + 1} / ${cards.length}`);
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        overlay.dataset.currentTitle = card.dataset.title || '';
        syncLightboxVote(card, lightboxVotePanel);
        const mapPoint = lightboxMapPointForCard(card);
        syncLightboxMapControls(mapPoint);
        updateNormalLightboxStageSize(card);
        // shouldShowImmediately stores state or configuration for the gallery front-end flow.
        const shouldShowImmediately = overlay.hidden || !image.getAttribute('src');
        preloadCardLightboxImages(card, true);
        const showInitialPreview = () => showLightboxImageSource(normalizedIndex, imageToken, previewSrc, altText, shouldShowImmediately);
        const initialPreviewPromise = isInitialPhotoOpen && previewSrc
            ? loadDecodedLightboxImage(previewSrc).then(showInitialPreview).catch(showInitialPreview)
            : showInitialPreview();
        initialPreviewPromise.then((wasDisplayed) => {
            if (!wasDisplayed || currentIndex !== normalizedIndex || activeLightboxImageToken !== imageToken) {
                return;
            }
            hideInitialLightboxLoader();
            swapLightboxImageAfterDecode(normalizedIndex, imageToken, previewSrc, fullSrc, altText);
            scheduleLightboxSlideshowNext();
        });
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen()) {
                closeLightboxMapSplit();
            } else if (mapPoint) {
                openLightboxPhotoMapSplit(mapPoint, card.dataset.title || title.textContent || 'Map');
            } else if (hasLightboxGalleryMapPayload()) {
                openLightboxGalleryMapSplit(card.dataset.title || title.textContent || currentLightboxGalleryMapTitle('Map'));
            } else {
                openLightboxMapUnavailable(card.dataset.title || title.textContent || 'Map');
            }
        }
        preloadAdjacentImages(normalizedIndex);
        overlay.hidden = false;
        document.documentElement.classList.add('has-lightbox');
        document.body.classList.add('has-lightbox');
        if (isMobileTouchDevice && !isLightboxFullscreen()) {
            enterMobileLightboxFullscreen();
        }
        updateLightboxViewportMode();
        if (revealHud) {
            showLightboxHud();
        }
        telemetryPhotoOpened(card);
    }

    /**
     * Handles step behavior for the gallery UI.
     * @param {number} offset Relative image offset.
     * @param {{revealHud?: boolean}} options Optional display behavior for automatic slideshow changes.
     * @returns {void}
     */
    function step(offset, options = {}) {
        if (cards.length === 0) {
            return;
        }
        // nextIndex stores state or configuration for the gallery front-end flow.
        const nextIndex = (currentIndex + offset + cards.length) % cards.length;
        openAt(nextIndex, options);
    }

    /**
     * Synchronize toolbar and fullscreen map controls with the current item.
     *
     * @param {string} mapPoint Serialized EXIF marker payload for the active photo.
     * @returns {void}
     */
    function syncLightboxMapControls(mapPoint) {
        const hasMapPoint = String(mapPoint || '').trim() !== '';
        const hasMapFallback = hasLightboxGalleryMapPayload();
        lightboxMapButtons.forEach((button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }
            button.hidden = !(hasMapPoint || hasMapFallback);
            button.dataset.mapPoint = hasMapPoint ? mapPoint : '';
        });
    }

    /**
     * Clears any pending automatic slideshow advance.
     *
     * @returns {void}
     */
    function clearLightboxSlideshowTimer() {
        if (lightboxSlideshowTimer) {
            window.clearTimeout(lightboxSlideshowTimer);
            lightboxSlideshowTimer = null;
        }
    }

    /**
     * Synchronize visible slideshow controls and overlay state.
     *
     * @returns {void}
     */
    function syncLightboxSlideshowControls() {
        overlay.classList.toggle('is-slideshow', lightboxSlideshowActive);
        overlay.querySelectorAll('[data-lightbox-action="slideshow"]').forEach((button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }
            button.classList.toggle('is-active', lightboxSlideshowActive);
            button.setAttribute('aria-pressed', lightboxSlideshowActive ? 'true' : 'false');
        });
    }

    /**
     * Schedule the next automatic slideshow step after the stable image time.
     *
     * @returns {void}
     */
    function scheduleLightboxSlideshowNext() {
        clearLightboxSlideshowTimer();
        if (!lightboxSlideshowActive || overlay.hidden || cards.length <= 1) {
            return;
        }
        lightboxSlideshowTimer = window.setTimeout(() => {
            lightboxSlideshowTimer = null;
            if (!lightboxSlideshowActive || overlay.hidden) {
                return;
            }
            hideLightboxHud();
            step(1, {revealHud: false});
        }, lightboxSlideshowVisibleDuration);
    }

    /**
     * Start fullscreen slideshow mode.
     *
     * @returns {Promise<void>} Resolves after fullscreen entry has been requested.
     */
    async function startLightboxSlideshow() {
        if (lightboxSlideshowActive) {
            scheduleLightboxSlideshowNext();
            return;
        }
        lightboxSlideshowActive = true;
        syncLightboxSlideshowControls();
        if (!isLightboxFullscreen()) {
            await enterLightboxFullscreen();
        }
        showLightboxHud();
        scheduleLightboxSlideshowNext();
    }

    /**
     * Stop slideshow mode while optionally keeping fullscreen active.
     *
     * @param {boolean} syncControls Whether button state should be refreshed immediately.
     * @returns {void}
     */
    function stopLightboxSlideshow(syncControls = true) {
        clearLightboxSlideshowTimer();
        if (!lightboxSlideshowActive) {
            return;
        }
        lightboxSlideshowActive = false;
        if (syncControls) {
            syncLightboxSlideshowControls();
        } else {
            overlay.classList.remove('is-slideshow');
        }
    }

    /**
     * Toggle slideshow mode from the toolbar, HUD, or S keyboard shortcut.
     *
     * @returns {Promise<void>} Resolves after any fullscreen state change request.
     */
    async function toggleLightboxSlideshow() {
        if (lightboxSlideshowActive) {
            stopLightboxSlideshow();
            showLightboxHud();
            return;
        }
        await startLightboxSlideshow();
    }

    // Function `close` executes this focused behavior.
    function close() {
        telemetryPhotoClosed();
        stopLightboxSlideshow();
        exitLightboxFullscreen();
        clearLightboxHudTimer();
        resetMobileSwipeVisuals(false);
        overlay.classList.remove('is-ui-visible');
        clearTouchGesture();
        updateLightboxViewportMode();
        hideInitialLightboxLoader();
        overlay.hidden = true;
        clearPendingFullImageSwap();
        removeTransitionImage();
        image.removeAttribute('src');
        galleryDevModeState.currentSource = '';
        galleryDevModeState.currentSourceKind = '';
        galleryDevModeState.currentIndex = -1;
        document.documentElement.classList.remove('has-lightbox', 'has-mobile-lightbox');
        document.body.classList.remove('has-lightbox');
        if (lightboxHistoryActive && lightboxReturnUrl && window.history && window.history.replaceState) {
            window.history.replaceState({}, '', lightboxReturnUrl);
        }
        lightboxHistoryActive = false;
    }


    /**
     * Handles preload card lightbox images behavior for the gallery UI.
     * @param {*} card Value supplied by the caller or event context.
     * @param {*} includeFullImage Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadCardLightboxImages(card, includeFullImage) {
        if (!card) {
            return;
        }
        // previewSrc stores the lightweight preview source when one was rendered.
        // Hidden pagination source nodes intentionally omit previews so normal
        // page render does not resolve a thumbnail for every image. Do not treat
        // the full-size source as a preview during adjacent preview preloading.
        const previewSrc = card.dataset.previewSrc || '';
        // fullSrc stores state or configuration for the gallery front-end flow.
        const fullSrc = card.dataset.fullSrc || previewSrc;
        [previewSrc, includeFullImage ? fullSrc : ''].forEach((src) => {
            if (!src) {
                return;
            }
            preloadedSources.add(src);
            devMarkSource(src, 'preloading', includeFullImage && src === fullSrc ? 'adjacent-full' : 'adjacent-preview');
            preloadDecodedLightboxImage(src);
        });
    }

    /**
     * Handles preload adjacent images behavior for the gallery UI.
     * @param {*} index Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function preloadAdjacentImages(index) {
        if (cards.length === 0) {
            return;
        }
        const edgeDistance = Math.max(lightboxPreviewPreloadRadius + 4, 12);
        const nearStart = index <= edgeDistance;
        const nearEnd = index >= cards.length - edgeDistance - 1;
        if (!cards[index] || nearStart || nearEnd) {
            fetchLightboxWindowAround(index);
        }
        if (shouldLimitLightboxPreloading()) {
            return;
        }
        // previewOffsets stores state or configuration for the gallery front-end flow.
        const previewOffsets = [];
        for (let distance = 1; distance <= lightboxPreviewPreloadRadius; distance += 1) {
            previewOffsets.push(distance, -distance);
        }
        previewOffsets.forEach((offset) => {
            // normalizedIndex stores state or configuration for the gallery front-end flow.
            const normalizedIndex = (index + offset + cards.length) % cards.length;
            // card stores state or configuration for the gallery front-end flow.
            const card = cards[normalizedIndex];
            if (!card) {
                fetchLightboxWindowAround(normalizedIndex);
                return;
            }
            preloadCardLightboxImages(card, Math.abs(offset) <= lightboxFullPreloadRadius);
        });
    }

    /**
     * Handles should limit lightbox preloading behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function shouldLimitLightboxPreloading() {
        // connection stores state or configuration for the gallery front-end flow.
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return false;
        }
        if (connection.saveData) {
            return true;
        }
        return ['slow-2g', '2g'].includes(connection.effectiveType);
    }

    document.addEventListener('click', (event) => {
        if (controller.signal.aborted || !(event.target instanceof Element)) {
            return;
        }
        const card = event.target.closest('[data-lightbox-image], [data-lightbox-source]');
        if (!(card instanceof HTMLElement)) {
            return;
        }
        if (event.target.closest('form, [data-admin-inline-editor], [data-public-admin-card-action], [data-gallery-side-panel-link], [data-photo-map], [data-gallery-map-url]')) {
            return;
        }
        refreshLightboxOrderFromDom();
        // index stores the card position in the complete viewer order.
        let index = lightboxIndexForCard(card);
        if (index < 0 || index >= cards.length || cards[index]?.dataset.imageId !== card.dataset.imageId) {
            index = cards.findIndex((candidate) => candidate && candidate.dataset.imageId === card.dataset.imageId);
        }
        if (index < 0) {
            return;
        }
        event.preventDefault();
        openAt(index);
    }, {signal: controller.signal});

    overlay.addEventListener('click', (event) => {
        // target stores state or configuration for the gallery front-end flow.
        const target = event.target instanceof Element ? event.target : null;
        // actionTarget stores state or configuration for the gallery front-end flow.
        const actionTarget = target?.closest('[data-lightbox-action]');
        // Variable `action` stores this steps working value.
        const action = actionTarget?.dataset.lightboxAction;
        if (action === 'close' || event.target === overlay) {
            event.preventDefault();
            close();
            return;
        }
        if (target?.closest('[data-lightbox-stage]')) {
            event.preventDefault();
            if (suppressNextStageClick) {
                suppressNextStageClick = false;
                return;
            }
            if (initialLightboxLoadActive) {
                return;
            }
            clearLightboxStageFocus();
            if (isMobileTouchDevice) {
                if (overlay.classList.contains('is-mobile-fullscreen')) {
                    toggleLightboxHud();
                } else {
                    toggleLightboxFullscreen().finally(clearLightboxStageFocus);
                }
                clearLightboxStageFocus();
                return;
            }
            toggleLightboxFullscreen().finally(clearLightboxStageFocus);
            return;
        }
        if (action === 'previous') {
            step(-1);
            return;
        }
        if (action === 'next') {
            step(1);
            return;
        }
        if (action === 'fullscreen') {
            event.preventDefault();
            toggleLightboxFullscreen();
            return;
        }
        if (action === 'slideshow') {
            event.preventDefault();
            toggleLightboxSlideshow();
            return;
        }
        // mapButton stores state or configuration for the gallery front-end flow.
        const mapButton = target?.closest('[data-lightbox-map]');
        if (mapButton) {
            event.preventDefault();
            toggleCurrentLightboxMap(mapButton.dataset.mapPoint || '');
        }
    }, {signal: controller.signal});

    if (lightboxMapSplitClose) {
        lightboxMapSplitClose.addEventListener('click', closeLightboxMapSplit, {signal: controller.signal});
    }

    if (stageLink) {
        stageLink.addEventListener('mousedown', (event) => {
            if (event.button === 0) {
                event.preventDefault();
            }
        }, {signal: controller.signal});
    }

    overlay.addEventListener('mousemove', showLightboxHud, {signal: controller.signal});
    overlay.addEventListener('pointermove', showLightboxHudFromPointerMove, {signal: controller.signal});
    overlay.addEventListener('mouseleave', scheduleHideLightboxHud, {signal: controller.signal});
    if (stageLink && isMobileTouchDevice && supportsTouchGestures) {
        stageLink.addEventListener('touchstart', startTouchGesture, {passive: false, capture: true, signal: controller.signal});
        stageLink.addEventListener('touchmove', trackTouchGesture, {passive: false, capture: true, signal: controller.signal});
        stageLink.addEventListener('touchend', finishTouchGesture, {passive: false, capture: true, signal: controller.signal});
        stageLink.addEventListener('touchcancel', clearTouchGesture, {capture: true, signal: controller.signal});
        window.addEventListener('touchend', finishTouchGesture, {passive: false, capture: true, signal: controller.signal});
        window.addEventListener('touchcancel', clearTouchGesture, {capture: true, signal: controller.signal});
    } else if (stageLink && supportsPointerGestures) {
        stageLink.addEventListener('pointerdown', startTouchGesture, {capture: true, signal: controller.signal});
        overlay.addEventListener('pointermove', trackTouchGesture, {capture: true, signal: controller.signal});
        overlay.addEventListener('pointerup', finishTouchGesture, {capture: true, signal: controller.signal});
        overlay.addEventListener('pointercancel', clearTouchGesture, {capture: true, signal: controller.signal});
        window.addEventListener('pointerup', finishTouchGesture, {capture: true, signal: controller.signal});
        window.addEventListener('pointercancel', clearTouchGesture, {capture: true, signal: controller.signal});
    }
    document.addEventListener('touchmove', preventMobileLightboxPageGesture, {passive: false, capture: true, signal: controller.signal});
    overlay.addEventListener('fullscreenchange', syncLightboxFullscreenState, {signal: controller.signal});
    document.addEventListener('fullscreenchange', syncLightboxFullscreenState, {signal: controller.signal});
    window.addEventListener('resize', () => {
        if (controller.signal.aborted || overlay.hidden || currentIndex < 0) {
            return;
        }
        updateMobileLightboxViewport();
        updateNormalLightboxStageSize(cards[currentIndex]);
        updateFullscreenMapImageFit(cards[currentIndex]);
    }, {signal: controller.signal});
    window.visualViewport?.addEventListener('resize', updateMobileLightboxViewport, {signal: controller.signal});
    window.visualViewport?.addEventListener('scroll', updateMobileLightboxViewport, {signal: controller.signal});

    document.addEventListener('keydown', (event) => {
        if (overlay.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            if (isLightboxFullscreen()) {
                event.preventDefault();
                exitLightboxFullscreen();
                return;
            }
            close();
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            step(-1);
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            step(1);
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            submitLightboxVote(1);
        }
        if (!event.altKey && !event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'm') {
            event.preventDefault();
            toggleCurrentLightboxMap();
        }
        if (!event.altKey && !event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 's') {
            event.preventDefault();
            toggleLightboxSlideshow();
        }
        if (event.key === 'f' || (event.key === 'F' && event.shiftKey === false) || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f')) {
            event.preventDefault();
            toggleLightboxFullscreen();
        }
    }, {signal: controller.signal});

    // Function `submitLightboxVote` executes this focused behavior.
    function submitLightboxVote(value) {
        const form = currentLightboxVoteForm(lightboxVotePanel);
        if (!(form instanceof HTMLFormElement) || form.closest('[hidden]')) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = form.querySelector(`button[name="vote"][value="${value}"]`);
        if (button) {
            form.dataset.pendingVote = String(value);
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(button);
            } else {
                button.click();
            }
        }
    }

    /**
     * Update every rendered counter copy used by desktop and mobile layouts.
     *
     * @param {string} text Position text to render.
     * @returns {void}
     */
    function updateLightboxCounters(text) {
        counters.forEach((counterElement) => {
            if (counterElement instanceof HTMLElement) {
                counterElement.textContent = text;
            }
        });
    }

    /**
     * Handles is lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxFullscreen() {
        return overlay.classList.contains('is-fullscreen') || overlay.classList.contains('is-mobile-fullscreen');
    }

    /**
     * Handles toggle lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function toggleLightboxFullscreen() {
        debugLightbox('toggle:before', {
            mobile: isMobileTouchDevice,
            fullscreen: isLightboxFullscreen(),
            browserFullscreen: Boolean(document.fullscreenElement),
        });
        if (isLightboxFullscreen()) {
            stopLightboxSlideshow();
            await exitLightboxFullscreen();
            debugLightbox('toggle:exit');
            return;
        }
        await enterLightboxFullscreen();
        debugLightbox('toggle:enter');
    }

    /**
     * Enter the CSS-only mobile fullscreen viewer without requesting browser fullscreen.
     * @returns {void}
     */
    function enterMobileLightboxFullscreen() {
        prepareMobileLightboxOverlay();
        updateMobileLightboxViewport();
        overlay.classList.add('is-mobile-fullscreen');
        overlay.classList.remove('is-fullscreen');
        document.documentElement.classList.add('has-mobile-lightbox');
        document.body.classList.add('has-mobile-lightbox');
        debugLightbox('enter:mobile-auto');
    }

    /**
     * Handles enter lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function enterLightboxFullscreen() {
        overlay.classList.add('is-fullscreen');
        overlay.classList.remove('is-ui-visible');
        if (isMobileTouchDevice) {
            enterMobileLightboxFullscreen();
            showLightboxHud();
            return;
        }
        try {
            if (overlay.requestFullscreen) {
                await overlay.requestFullscreen();
                debugLightbox('enter:native');
                return;
            }
        } catch {
            // Browser fullscreen can fail; the CSS fullscreen fallback still applies.
        }
        enterMobileLightboxFullscreen();
        debugLightbox('enter:fallback-css');
        showLightboxHud();
    }

    /**
     * Handles exit lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function exitLightboxFullscreen() {
        stopLightboxSlideshow();
        overlay.classList.remove('is-fullscreen');
        overlay.classList.remove('is-mobile-fullscreen');
        closeLightboxMapSplit();
        document.documentElement.classList.remove('has-mobile-lightbox');
        document.body.classList.remove('has-mobile-lightbox');
        if (!isMobileTouchDevice && document.fullscreenElement) {
            try {
                await document.exitFullscreen();
            } catch {
                // Ignore fullscreen exit failures.
            }
        }
        clearLightboxStageFocus();
        debugLightbox('exit');
    }

    /**
     * Handles sync lightbox fullscreen state behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function syncLightboxFullscreenState() {
        if (isMobileTouchDevice) {
            return;
        }
        if (!document.fullscreenElement && overlay.classList.contains('is-fullscreen')) {
            overlay.classList.remove('is-fullscreen');
            overlay.classList.remove('is-mobile-fullscreen');
            overlay.classList.remove('is-ui-visible');
            document.documentElement.classList.remove('has-mobile-lightbox');
            document.body.classList.remove('has-mobile-lightbox');
            stopLightboxSlideshow();
            clearLightboxStageFocus();
            debugLightbox('sync:browser-exit');
            return;
        }
        if (document.fullscreenElement === overlay) {
            overlay.classList.add('is-fullscreen');
            overlay.classList.remove('is-mobile-fullscreen');
            document.documentElement.classList.remove('has-mobile-lightbox');
            document.body.classList.remove('has-mobile-lightbox');
            overlay.classList.remove('is-ui-visible');
            debugLightbox('sync:browser-enter');
        }
    }

    /**
     * Handles clear lightbox hud timer behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearLightboxHudTimer() {
        if (fullscreenHideTimer) {
            clearTimeout(fullscreenHideTimer);
            fullscreenHideTimer = null;
        }
    }


    /**
     * Hide the fullscreen controls immediately without stopping slideshow playback.
     *
     * @returns {void}
     */
    function hideLightboxHud() {
        clearLightboxHudTimer();
        overlay.classList.remove('is-ui-visible');
    }

    /**
     * Handles show lightbox hud behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxHud() {
        clearLightboxHudTimer();
        overlay.classList.add('is-ui-visible');
        if (isLightboxFullscreen()) {
            fullscreenHideTimer = window.setTimeout(() => {
                overlay.classList.remove('is-ui-visible');
            }, isMobileTouchDevice ? 2400 : 1800);
        } else {
            overlay.classList.remove('is-ui-visible');
        }
    }

    /**
     * Keeps desktop pointer movement behavior without reopening the mobile HUD during a swipe.
     *
     * @param {PointerEvent|MouseEvent} event Browser pointer movement event.
     * @returns {void}
     */
    function showLightboxHudFromPointerMove(event) {
        if (isActiveMobileLightbox() && isMobileLightboxStageEvent(event)) {
            return;
        }
        showLightboxHud();
    }

    /**
     * Toggle mobile fullscreen controls from a deliberate stage tap.
     * @returns {void}
     */
    function toggleLightboxHud() {
        clearLightboxHudTimer();
        if (overlay.classList.contains('is-ui-visible')) {
            overlay.classList.remove('is-ui-visible');
            return;
        }
        showLightboxHud();
    }

    /**
     * Handles schedule hide lightbox hud behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function scheduleHideLightboxHud() {
        if (!isLightboxFullscreen()) {
            return;
        }
        clearLightboxHudTimer();
        fullscreenHideTimer = window.setTimeout(() => {
            if (isLightboxFullscreen()) {
                overlay.classList.remove('is-ui-visible');
            }
        }, 1200);
    }

    /**
     * Handles update lightbox viewport mode behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function updateLightboxViewportMode() {
        const mobileLightboxActive = overlay.classList.contains('is-mobile-fullscreen');
        document.documentElement.classList.toggle('has-mobile-lightbox', mobileLightboxActive);
        document.body.classList.toggle('has-mobile-lightbox', mobileLightboxActive);
        if (mobileLightboxActive) {
            updateMobileLightboxViewport();
        }
    }

    /**
     * Reports whether the CSS-only mobile viewer is currently active.
     *
     * @returns {boolean} True when mobile Chrome style gestures should be trapped by the lightbox.
     */
    function isActiveMobileLightbox() {
        return isMobileTouchDevice && !overlay.hidden;
    }

    /**
     * Reports whether an event belongs to the photo stage instead of the surrounding document.
     *
     * @param {Event} event Browser event to inspect.
     * @returns {boolean} True when the event started from the image swipe surface.
     */
    function isMobileLightboxStageEvent(event) {
        const target = event.target instanceof Element ? event.target : null;
        return Boolean(target?.closest('[data-lightbox-stage]'));
    }

    /**
     * Prevents mobile Chrome from scrolling or swiping the page behind the lightbox.
     *
     * @param {TouchEvent} event Browser touch movement event.
     * @returns {void}
     */
    function preventMobileLightboxPageGesture(event) {
        if (!isActiveMobileLightbox()) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('.lightbox-meta, .lightbox-map-split, .lightbox-hud')) {
            return;
        }
        event.preventDefault();
    }

    /**
     * Handles clear touch gesture behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearTouchGesture() {
        if (touchGesture && touchGesture.pointerId !== null && touchGesture.captureElement) {
            try {
                touchGesture.captureElement.releasePointerCapture?.(touchGesture.pointerId);
            } catch {
                // Ignore pointer capture release failures from older mobile engines.
            }
        }
        touchGesture = null;
    }

    /**
     * Reset the CSS variables/classes used to render mobile swipe drag feedback.
     *
     * @param {boolean} animate Whether the stage should glide back to center.
     * @returns {void}
     */
    function resetMobileSwipeVisuals(animate = true) {
        if (mobileSwipeVisualTimer) {
            window.clearTimeout(mobileSwipeVisualTimer);
            mobileSwipeVisualTimer = 0;
        }
        overlay.classList.remove('is-swipe-dragging', 'is-swipe-committing');
        overlay.style.setProperty('--lightbox-swipe-x', '0px');
        overlay.style.setProperty('--lightbox-swipe-progress', '0');
        if (!animate) {
            overlay.classList.remove('is-swipe-settling');
            return;
        }
        overlay.classList.add('is-swipe-settling');
        mobileSwipeVisualTimer = window.setTimeout(() => {
            mobileSwipeVisualTimer = 0;
            overlay.classList.remove('is-swipe-settling');
        }, 190);
    }

    /**
     * Move the active mobile image under the user's finger without letting it leave the stage too far.
     *
     * @param {number} offsetX Horizontal drag distance in pixels.
     * @param {boolean} clamp Whether to cap the visual drag distance.
     * @returns {void}
     */
    function setMobileSwipeOffset(offsetX, clamp = true) {
        const stageWidth = Math.max(1, stageLink?.clientWidth || overlay.clientWidth || window.innerWidth || 1);
        const maxOffset = Math.max(72, Math.min(180, stageWidth * 0.36));
        const visualOffset = clamp ? Math.max(-maxOffset, Math.min(maxOffset, offsetX)) : offsetX;
        const progress = Math.min(1, Math.abs(visualOffset) / maxOffset);
        overlay.style.setProperty('--lightbox-swipe-x', `${Math.round(visualOffset)}px`);
        overlay.style.setProperty('--lightbox-swipe-progress', progress.toFixed(3));
    }

    /**
     * Animate the current mobile image offscreen, then navigate to the adjacent photo.
     *
     * @param {number} dx Final horizontal movement in pixels.
     * @returns {void}
     */
    function commitMobileSwipe(dx) {
        const direction = dx < 0 ? 1 : -1;
        const stageWidth = Math.max(320, stageLink?.clientWidth || overlay.clientWidth || window.innerWidth || 320);
        overlay.classList.remove('is-swipe-dragging', 'is-swipe-settling');
        overlay.classList.add('is-swipe-committing');
        setMobileSwipeOffset(direction > 0 ? -stageWidth : stageWidth, false);
        mobileSwipeVisualTimer = window.setTimeout(() => {
            mobileSwipeVisualTimer = 0;
            if (!controller.signal.aborted && !overlay.hidden) {
                step(direction, {revealHud: false});
            }
            resetMobileSwipeVisuals(false);
        }, 150);
    }

    /**
     * Handles start touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function startTouchGesture(event) {
        if (!isActiveMobileLightbox() || initialLightboxLoadActive || cards.length <= 1) {
            return;
        }
        if (event.touches && event.touches.length > 1) {
            return;
        }
        if (event.type === 'pointerdown' && (event.pointerType === 'mouse' || event.button !== 0 || event.isPrimary === false)) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('[data-lightbox-stage]') || isLightboxControlTarget(target)) {
            return;
        }
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        event.preventDefault();
        resetMobileSwipeVisuals(false);
        const captureElement = event.currentTarget instanceof Element ? event.currentTarget : stageLink;
        touchGesture = {
            pointerId: event.type === 'pointerdown' ? event.pointerId : null,
            captureElement,
            startX: point.clientX,
            startY: point.clientY,
            lastX: point.clientX,
            lastY: point.clientY,
            startedAt: Date.now(),
            active: true,
            horizontalIntent: false,
            verticalIntent: false,
            moved: false,
        };
        if (touchGesture.pointerId !== null && captureElement) {
            try {
                captureElement.setPointerCapture?.(touchGesture.pointerId);
            } catch {
                // Pointer capture is best-effort on mobile browsers.
            }
        }
    }

    /**
     * Handles track touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function trackTouchGesture(event) {
        if (!touchGesture || !touchGesture.active) {
            return;
        }
        if (event.touches && event.touches.length > 1) {
            resetMobileSwipeVisuals();
            clearTouchGesture();
            return;
        }
        if (touchGesture.pointerId !== null && event.pointerId !== touchGesture.pointerId) {
            return;
        }
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        touchGesture.lastX = point.clientX;
        touchGesture.lastY = point.clientY;
        const dx = touchGesture.lastX - touchGesture.startX;
        const dy = touchGesture.lastY - touchGesture.startY;
        const absDx = Math.abs(dx);
        const absDy = Math.abs(dy);
        event.preventDefault();
        if (absDx > 6 || absDy > 6) {
            touchGesture.moved = true;
        }
        if (!touchGesture.horizontalIntent && !touchGesture.verticalIntent) {
            if (absDx > 8 && absDx > absDy * 0.8) {
                touchGesture.horizontalIntent = true;
                overlay.classList.add('is-swipe-dragging');
                hideLightboxHud();
            } else if (absDy > 24 && absDy > absDx * 1.6) {
                touchGesture.verticalIntent = true;
            }
        }
        if (touchGesture.horizontalIntent) {
            setMobileSwipeOffset(dx);
        }
    }

    /**
     * Handles finish touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function finishTouchGesture(event) {
        if (!touchGesture || !touchGesture.active) {
            return;
        }
        if (touchGesture.pointerId !== null && event.pointerId !== touchGesture.pointerId) {
            return;
        }
        const point = lightboxGesturePoint(event) || {clientX: touchGesture.lastX, clientY: touchGesture.lastY};
        const dx = point.clientX - touchGesture.startX;
        const dy = point.clientY - touchGesture.startY;
        const absDx = Math.abs(dx);
        const absDy = Math.abs(dy);
        const elapsed = Math.max(1, Date.now() - touchGesture.startedAt);
        const velocityX = absDx / elapsed;
        const stageWidth = Math.max(1, stageLink?.clientWidth || overlay.clientWidth || window.innerWidth || 1);
        const distanceThreshold = Math.max(30, Math.min(72, stageWidth * 0.12));
        const hadHorizontalIntent = touchGesture.horizontalIntent;
        const hadMovement = touchGesture.moved;
        clearTouchGesture();
        const isSwipe = (hadHorizontalIntent || absDx > distanceThreshold)
            && absDx >= distanceThreshold
            && absDx > absDy * 0.62
            && (absDx > distanceThreshold * 1.35 || velocityX >= 0.22);
        if (!isSwipe) {
            resetMobileSwipeVisuals(Boolean(hadMovement));
            if (hadMovement) {
                suppressNextStageClick = true;
            } else if (isActiveMobileLightbox()) {
                event.preventDefault();
                event.stopPropagation();
                suppressNextStageClick = true;
                toggleLightboxHud();
            }
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        suppressNextStageClick = true;
        hideLightboxHud();
        commitMobileSwipe(dx);
    }

    /**
     * Handles lightbox gesture point behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function lightboxGesturePoint(event) {
        if (event.changedTouches && event.changedTouches.length > 0) {
            return event.changedTouches[0];
        }
        if (event.touches && event.touches.length > 0) {
            return event.touches[0];
        }
        if (typeof event.clientX === 'number' && typeof event.clientY === 'number') {
            return event;
        }
        return null;
    }

    /**
     * Handles is lightbox control target behavior for the gallery UI.
     * @param {*} target Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxControlTarget(target) {
        if (!target) {
            return false;
        }
        if (target.closest('.lightbox-hud')) {
            return true;
        }
        if (target.closest('.lightbox-meta')) {
            return Boolean(target.closest('button, a, input, textarea, select, form'));
        }
        return Boolean(target.closest('button, input, textarea, select, form'));
    }

    /**
     * Handles detect mobile touch device behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function detectMobileTouchDevice() {
        // hasTouch stores state or configuration for the gallery front-end flow.
        const hasTouch = navigator.maxTouchPoints > 0 || window.matchMedia?.('(pointer: coarse)').matches;
        if (!hasTouch) {
            return false;
        }
        // userAgent stores state or configuration for the gallery front-end flow.
        const userAgent = navigator.userAgent || '';
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(userAgent)
            || (/Macintosh/i.test(userAgent) && navigator.maxTouchPoints > 1);
    }

    /**
     * Handles debug lightbox behavior for the gallery UI.
     * @param {*} message Value supplied by the caller or event context.
     * @param {*} details Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function debugLightbox(message, details = {}) {
        if (!isLightboxDebugEnabled) {
            return;
        }
        console.debug('[lightbox]', message, details);
    }

    /**
     * Handles detect lightbox debug flag behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function detectLightboxDebugFlag() {
        if (new URLSearchParams(window.location.search).has('lightbox_debug')) {
            return true;
        }
        try {
            return window.localStorage.getItem('lightbox_debug') === '1';
        } catch {
            return false;
        }
    }


    // Function `setupGpsMaps` executes this focused behavior.
    function setupGpsMaps(signal) {
        document.addEventListener('click', async (event) => {
            if (signal.aborted) {
                return;
            }
            if (!(event.target instanceof Element)) {
                return;
            }
            // Variable `photoButton` stores this steps working value.
            const photoButton = event.target.closest('[data-photo-map]');
            if (photoButton) {
                // The photo pin is rendered inside the clickable image card.
                // Stop the card click handler as early as possible so the pin
                // opens the map directly instead of opening the photo lightbox.
                event.preventDefault();
                event.stopPropagation();
                // Variable `card` stores this steps working value.
                const card = photoButton.closest('[data-lightbox-image]');
                await openPhotoMapFromJson(photoButton.dataset.mapPoint || card?.dataset.mapPoint || '');
                return;
            }
            // Variable `galleryButton` stores this steps working value.
            const galleryButton = event.target.closest('[data-gallery-map-url]');
            if (galleryButton) {
                event.preventDefault();
                event.stopPropagation();
                await openGalleryMap(galleryButton.dataset.galleryMapUrl || '', galleryButton.dataset.galleryMapTitle || 'Gallery map');
            }
        }, {capture: true, signal});
    }

    // Function `openPhotoMapFromJson` executes this focused behavior.
    async function openPhotoMapFromJson(json) {
        if (!json) {
            return;
        }
        try {
            // payload stores the marker or map payload read from a data attribute.
            const payload = JSON.parse(json);
            const mapPayload = await photoMapPayloadWithGalleryRoute(normalizeMapPayload(payload));
            openMapOverlay(mapPayload.title || 'Photo location', mapPayload.points, mapPayload);
        } catch {
            // Invalid rendered JSON should not break the gallery UI.
        }
    }

    /**
     * Return the gallery-level map endpoint from the freshest rendered markup.
     *
     * Public gallery content can be replaced by admin-side AJAX tools. Reading the
     * current DOM avoids stale setup-time values when a gallery route was added or
     * changed without a full browser restart.
     *
     * @returns {string} Gallery map JSON endpoint, or an empty string.
     */
    function currentLightboxGalleryMapUrl() {
        const configUrl = String(document.querySelector('[data-lightbox-config]')?.dataset.lightboxGalleryMapUrl || '').trim();
        if (configUrl !== '') {
            return configUrl;
        }
        const overlayUrl = String(overlay?.dataset.lightboxGalleryMapUrl || '').trim();
        if (overlayUrl !== '') {
            return overlayUrl;
        }
        return String(document.querySelector('[data-gallery-map-url]')?.dataset.galleryMapUrl || lightboxGalleryMapUrl || '').trim();
    }

    /**
     * Return the gallery-level map title from the freshest rendered markup.
     *
     * @param {string} fallback Title used when no rendered title exists.
     * @returns {string} Human-readable map title.
     */
    function currentLightboxGalleryMapTitle(fallback = 'Gallery map') {
        const configTitle = String(document.querySelector('[data-lightbox-config]')?.dataset.lightboxGalleryMapTitle || '').trim();
        if (configTitle !== '') {
            return configTitle;
        }
        const overlayTitle = String(overlay?.dataset.lightboxGalleryMapTitle || '').trim();
        if (overlayTitle !== '') {
            return overlayTitle;
        }
        return String(document.querySelector('[data-gallery-map-url]')?.dataset.galleryMapTitle || lightboxGalleryMapTitle || fallback).trim();
    }

    /**
     * Return whether a gallery-level route or marker map can be opened.
     *
     * @returns {boolean} True when a gallery map endpoint is present.
     */
    function hasLightboxGalleryMapPayload() {
        return currentLightboxGalleryMapUrl() !== '';
    }

    /**
     * Return whether the shared map UI can be opened from the current lightbox.
     *
     * @returns {boolean} True when EXIF maps are enabled or gallery route data exist.
     */
    function sharedLightboxMapUiAvailable() {
        return lightboxMapsEnabled || hasLightboxGalleryMapPayload();
    }

    // Function `fetchGalleryMapPayload` executes this focused behavior.
    async function fetchGalleryMapPayload(url = '', title = '') {
        const endpointUrl = String(url || currentLightboxGalleryMapUrl()).trim();
        if (!endpointUrl) {
            return null;
        }
        if (lightboxGalleryMapPayloadPromises.has(endpointUrl)) {
            return lightboxGalleryMapPayloadPromises.get(endpointUrl);
        }
        const fetchPromise = fetch(endpointUrl, {headers: {'Accept': 'application/json'}}).then(async (response) => {
            if (!response.ok) {
                return null;
            }
            const payload = await response.json();
            const mapPayload = normalizeMapPayload(payload);
            if (!mapPayload.title) {
                mapPayload.title = title || currentLightboxGalleryMapTitle('Gallery map');
            }
            return mapPayload;
        }).catch(() => null);
        lightboxGalleryMapPayloadPromises.set(endpointUrl, fetchPromise);
        return fetchPromise;
    }

    // Function `openGalleryMap` executes this focused behavior.
    async function openGalleryMap(url = '', title = '') {
        const payload = await fetchGalleryMapPayload(url, title || currentLightboxGalleryMapTitle('Gallery map'));
        if (!payload || !payload.points.length) {
            return;
        }
        openMapOverlay(payload.title || title || currentLightboxGalleryMapTitle('Gallery map'), payload.points, payload);
    }

    // Function `ensureLeaflet` executes this focused behavior.
    function ensureLeaflet() {
        if (window.L && document.querySelector('link[data-gallery-leaflet-css]')) {
            configureLeafletMarkerIcon();
            return Promise.resolve();
        }
        if (window.galleryLeafletLoading) {
            return window.galleryLeafletLoading;
        }

        // Leaflet depends on its stylesheet for tile-pane positioning and image
        // state during zoom/fullscreen transitions. The app keeps a local CSS
        // fallback below, but loading the official stylesheet first prevents
        // Chromium fullscreen from showing stale tile panes as a visible grid.
        window.galleryLeafletLoading = Promise.all([
            ensureLeafletStylesheet(),
            ensureLeafletScript(),
        ]).then(() => {
            configureLeafletMarkerIcon();
        });

        return window.galleryLeafletLoading;
    }

    // Function `ensureLeafletStylesheet` executes this focused behavior.
    function ensureLeafletStylesheet() {
        // existingStylesheet stores state or configuration for the gallery front-end flow.
        const existingStylesheet = document.querySelector('link[data-gallery-leaflet-css]');
        if (existingStylesheet) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            // Variable `stylesheet` stores this steps working value.
            const stylesheet = document.createElement('link');
            stylesheet.rel = 'stylesheet';
            stylesheet.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            stylesheet.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
            stylesheet.crossOrigin = '';
            stylesheet.dataset.galleryLeafletCss = 'true';
            stylesheet.onload = () => resolve();
            stylesheet.onerror = () => resolve();
            document.head.append(stylesheet);
        });
    }

    // Function `configureLeafletMarkerIcon` executes this focused behavior.
    function configureLeafletMarkerIcon() {
        if (!window.L || !L.Icon || !L.Icon.Default) {
            return;
        }

        // Leaflet normally detects marker image URLs from leaflet.css. The app
        // loads Leaflet dynamically and can run inside fullscreen/modal scopes,
        // where custom gallery image CSS may make that detection unreliable.
        // Use explicit upstream image URLs so normal maps and fullscreen split
        // maps both keep the blue GPS marker.
        L.Icon.Default.mergeOptions({
            iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
            iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
            shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        });
    }

    // Function `getGalleryMapMarkerIcon` executes this focused behavior.
    function getGalleryMapMarkerIcon(point = {}) {
        if (!window.L || !L.divIcon) {
            return undefined;
        }

        const markerRole = mapPointMarkerRole(point);
        window.galleryMapMarkerIcons = window.galleryMapMarkerIcons || {};
        if (!window.galleryMapMarkerIcons[markerRole]) {
            const isActivePhoto = markerRole === 'active-photo';
            window.galleryMapMarkerIcons[markerRole] = L.divIcon({
                className: `gallery-leaflet-marker gallery-leaflet-marker--${markerRole}`,
                html: '<span class="gallery-leaflet-marker-shadow" aria-hidden="true"></span><span class="gallery-leaflet-marker-pin" aria-hidden="true"></span>',
                iconAnchor: isActivePhoto ? [16, 46] : [13, 40],
                iconSize: isActivePhoto ? [32, 46] : [26, 40],
                popupAnchor: [0, isActivePhoto ? -42 : -36],
            });
        }

        return window.galleryMapMarkerIcons[markerRole];
    }

    // Function `ensureLeafletScript` executes this focused behavior.
    function ensureLeafletScript() {
        if (window.L) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            // Variable `existingScript` stores this steps working value.
            const existingScript = document.querySelector('script[data-gallery-leaflet-js]');
            if (existingScript) {
                existingScript.addEventListener('load', () => resolve(), {once: true});
                existingScript.addEventListener('error', () => reject(new Error('Leaflet failed to load.')), {once: true});
                return;
            }

            // Variable `script` stores this steps working value.
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
            script.crossOrigin = '';
            script.dataset.galleryLeafletJs = 'true';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Leaflet failed to load.'));
            document.head.append(script);
        });
    }

    // Function `afterNextPaint` executes this focused behavior.
    function afterNextPaint() {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(resolve);
            });
        });
    }

    // Function `openMapOverlay` executes this focused behavior.
    async function openMapOverlay(title, points, mapPayload = {}) {
        if (!Array.isArray(points) || points.length === 0) {
            return;
        }
        await ensureLeaflet();
        // Variable `overlay` stores this steps working value.
        let overlay = document.querySelector('[data-map-overlay]');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'map-overlay';
            overlay.dataset.mapOverlay = 'true';
            overlay.innerHTML = '<div class="map-dialog"><button type="button" class="map-close" data-map-close>Close</button><h2 data-map-title></h2><div class="map-canvas" data-map-canvas></div><p class="muted map-attribution-note">Map tiles by OpenStreetMap contributors. Heavy production traffic should use a dedicated tile provider.</p></div>';
            document.body.append(overlay);
        }
        bindMapOverlayClose(overlay);
        overlay.hidden = false;
        document.body.classList.add('has-map-overlay');
        overlay.querySelector('[data-map-title]').textContent = title;

        // Wait until the overlay is painted. Leaflet reads the canvas size at
        // startup, so initializing it in the same task that unhides the modal
        // can produce partially offset tiles in Chromium-based browsers.
        await afterNextPaint();

        // Variable `canvas` stores this steps working value.
        const canvas = overlay.querySelector('[data-map-canvas]');
        await waitForElementSize(canvas);
        if (overlay.galleryLeafletMap) {
            overlay.galleryLeafletMap.remove();
            overlay.galleryLeafletMap = null;
        }
        canvas.innerHTML = '';

        // Variable `map` stores this steps working value.
        const map = L.map(canvas, {
            fadeAnimation: false,
            markerZoomAnimation: false,
            zoomAnimation: false,
        });
        overlay.galleryLeafletMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        // Variable `bounds` stores this steps working value.
        const bounds = renderLeafletMapPayload(map, points, mapPayload);

        setInitialMapViewport(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
        stabilizeMapAfterLayout(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
    }

    /**
     * Normalize marker arrays and route payloads into one renderer contract.
     *
     * @param {*} payload Raw JSON payload from PHP or a data attribute.
     * @returns {{title: string, points: Array, geometry: *, sourceType: string}}
     */
    function normalizeMapPayload(payload) {
        if (Array.isArray(payload)) {
            return {title: '', points: normalizeMapPoints(payload), geometry: null, sourceType: 'exif_point'};
        }
        if (!payload || typeof payload !== 'object') {
            return {title: '', points: [], geometry: null, sourceType: ''};
        }
        if ((payload.lat ?? payload.latitude) !== undefined && (payload.lng ?? payload.longitude) !== undefined) {
            return {
                title: String(payload.title || payload.name || ''),
                points: normalizeMapPoints([payload]),
                geometry: null,
                sourceType: String(payload.source_type || payload.map_source_type || 'exif_point'),
            };
        }
        const points = normalizeMapPoints(payload.points || payload.geometry?.points || []);
        const sourceType = String(payload.source_type || payload.map_source_type || payload.sourceType || '');
        const renderPath = payload.render_path === true || payload.renderPath === true || payload.is_path === true || payload.isPath === true;
        const geometry = payload.geometry && typeof payload.geometry === 'object' ? {
            ...payload.geometry,
            points: normalizeMapPoints(payload.geometry.points || points),
        } : null;
        return {
            title: String(payload.title || ''),
            points,
            geometry,
            sourceType,
            renderPath,
        };
    }

    /**
     * Normalize point coordinates before passing them to Leaflet.
     *
     * @param {*} points Candidate point list.
     * @returns {Array} Point list with numeric lat/lng values.
     */
    function normalizeMapPoints(points) {
        if (!Array.isArray(points)) {
            return [];
        }
        return points.map((point) => {
            if (!point || typeof point !== 'object') {
                return null;
            }
            const lat = Number(point.lat ?? point.latitude);
            const lng = Number(point.lng ?? point.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return null;
            }
            return {...point, lat, lng};
        }).filter(Boolean);
    }

    /**
     * Render markers and optional flight route geometry into an existing map.
     *
     * @param {*} map Leaflet map instance.
     * @param {Array} points Already normalized marker points.
     * @param {*} mapPayload Normalized map metadata.
     * @returns {Array} Leaflet bounds input collected from valid route points.
     */
    function renderLeafletMapPayload(map, points, mapPayload = {}) {
        const normalizedPoints = normalizeMapPoints(points);
        const bounds = [];
        const linePoints = normalizeMapPoints(mapPayload.geometry?.type === 'polyline' ? mapPayload.geometry.points : []);
        const routePoints = linePoints.length > 1
            ? linePoints
            : (shouldRenderPathForPayload(mapPayload, normalizedPoints) ? normalizedPoints : []);
        if (routePoints.length > 1) {
            L.polyline(routePoints.map((point) => [point.lat, point.lng]), {
                className: 'gallery-leaflet-route-line',
                color: '#2563eb',
                opacity: 0.92,
                smoothFactor: 1,
                weight: 4,
            }).addTo(map);
        }
        normalizedPoints.forEach((point) => {
            const markerRole = mapPointMarkerRole(point);
            const marker = L.marker([point.lat, point.lng], {
                icon: getGalleryMapMarkerIcon(point),
                zIndexOffset: markerRole === 'active-photo' ? 1200 : (markerRole === 'photo' ? 500 : 0),
            }).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });
        if (bounds.length === 0 && routePoints.length > 0) {
            routePoints.forEach((point) => bounds.push([point.lat, point.lng]));
        }
        return bounds;
    }

    /**
     * Return true when a normalized payload should be rendered as a connected path.
     *
     * @param {*} mapPayload Normalized map metadata.
     * @param {Array} normalizedPoints Already normalized marker points.
     * @returns {boolean} True when the point list represents route geometry.
     */
    function shouldRenderPathForPayload(mapPayload, normalizedPoints) {
        if (!Array.isArray(normalizedPoints) || normalizedPoints.length <= 1) {
            return false;
        }

        const sourceType = String(mapPayload?.sourceType || mapPayload?.source_type || mapPayload?.map_source_type || '');
        return sourceType === 'flight_path' || mapPayload?.renderPath === true || mapPayload?.geometry?.type === 'polyline';
    }

    /**
     * Return the visual marker role for one map point.
     *
     * @param {*} point Normalized marker payload.
     * @returns {string} Marker role used by CSS and icon caching.
     */
    function mapPointMarkerRole(point) {
        const pointType = String(point?.point_type || point?.type || '').trim();
        const sourceType = String(point?.source_type || point?.map_source_type || '').trim();
        if (point?.active_photo === true || pointType === 'active_photo_point') {
            return 'active-photo';
        }
        if (pointType === 'route_point' || sourceType === 'flight_path') {
            return 'route';
        }
        return 'photo';
    }

    /**
     * Return whether two marker payloads represent the same photo.
     *
     * @param {*} point Candidate gallery marker.
     * @param {*} activePoint Active lightbox photo marker.
     * @returns {boolean} True when the marker IDs match.
     */
    function mapPointsReferToSamePhoto(point, activePoint) {
        const pointId = String(point?.id ?? '').trim();
        const activeId = String(activePoint?.id ?? '').trim();
        return pointId !== '' && activeId !== '' && pointId === activeId;
    }

    /**
     * Layer the active photo marker onto a gallery route payload.
     *
     * @param {*} galleryPayload Normalized gallery route payload.
     * @param {*} photoPayload Normalized active photo payload.
     * @returns {*} Combined payload used by the Leaflet renderer.
     */
    function mergeActivePhotoIntoGalleryRoute(galleryPayload, photoPayload) {
        if (!galleryPayload || !photoPayload?.points?.length || !shouldRenderPathForPayload(galleryPayload, galleryPayload.points || [])) {
            return photoPayload;
        }
        const activePoint = {
            ...photoPayload.points[0],
            active_photo: true,
            type: 'active_photo_point',
            point_type: 'active_photo_point',
        };
        let activePointMerged = false;
        const points = normalizeMapPoints(galleryPayload.points || []).map((point) => {
            if (!mapPointsReferToSamePhoto(point, activePoint)) {
                return point;
            }
            activePointMerged = true;
            return {
                ...point,
                ...activePoint,
                thumb: activePoint.thumb || point.thumb,
                image: activePoint.image || point.image,
                gallery: activePoint.gallery || point.gallery,
            };
        });
        if (!activePointMerged) {
            points.push(activePoint);
        }
        return {
            ...galleryPayload,
            title: photoPayload.title || galleryPayload.title,
            points,
            activePoint,
        };
    }

    /**
     * Combine the active photo GPS marker with a gallery route when one exists.
     *
     * @param {*} photoPayload Normalized active photo payload.
     * @returns {Promise<*>} Photo-only payload or combined route/photo payload.
     */
    async function photoMapPayloadWithGalleryRoute(photoPayload) {
        if (!photoPayload?.points?.length || !hasLightboxGalleryMapPayload()) {
            return photoPayload;
        }
        const galleryPayload = await fetchGalleryMapPayload(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle('Gallery map'));
        if (!galleryPayload || !shouldRenderPathForPayload(galleryPayload, galleryPayload.points || [])) {
            return photoPayload;
        }
        return mergeActivePhotoIntoGalleryRoute(galleryPayload, photoPayload);
    }

    /**
     * Closes the persistent map overlay without changing the current photo viewer.
     *
     * @returns {void}
     */
    function closeMapOverlay() {
        // mapOverlay stores state or configuration for the gallery front-end flow.
        const mapOverlay = document.querySelector('[data-map-overlay]');
        if (mapOverlay instanceof HTMLElement) {
            mapOverlay.hidden = true;
        }
        document.body.classList.remove('has-map-overlay');
    }

    /**
     * Ensures the persistent map overlay has exactly one close listener for the active viewer lifecycle.
     *
     * @param {HTMLElement} mapOverlay Persistent map overlay element.
     * @returns {void}
     */
    function bindMapOverlayClose(mapOverlay) {
        if (mapOverlay.galleryMapOverlayCloseController) {
            mapOverlay.galleryMapOverlayCloseController.abort();
        }
        const closeController = new AbortController();
        mapOverlay.galleryMapOverlayCloseController = closeController;
        controller.signal.addEventListener('abort', () => closeController.abort(), {once: true});
        mapOverlay.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) {
                return;
            }
            if (event.target === mapOverlay || event.target.closest('[data-map-close]')) {
                closeMapOverlay();
            }
        }, {signal: closeController.signal});
    }

    /**
     * Return the current photo map payload only when the gallery allows GPS maps.
     *
     * @param {HTMLElement|null} card Active lightbox source element.
     * @returns {string} JSON marker payload, or an empty string when unavailable.
     */
    function lightboxMapPointForCard(card) {
        if (!lightboxMapsEnabled || !(card instanceof HTMLElement)) {
            return '';
        }
        return (card.dataset.mapPoint || '').trim();
    }

    /**
     * Remove any live Leaflet instance and leave the split-map panel ready for new content.
     *
     * @returns {void}
     */
    function clearLightboxSplitMapRuntime() {
        if (overlay.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        if (overlay.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
    }

    /**
     * Center and scale the fullscreen split image inside its current pane.
     *
     * Browser object-fit should handle this alone, but setting exact fit values
     * prevents wide photos from using stale intrinsic dimensions while the map
     * panel changes the available width.
     *
     * @param {HTMLElement|null} card Active lightbox source element.
     * @returns {void}
     */
    function updateFullscreenMapImageFit(card) {
        if (!stageLink || !(card instanceof HTMLElement) || !isLightboxFullscreen() || overlay.classList.contains('is-mobile-fullscreen') || !lightboxMapSplit || lightboxMapSplit.hidden) {
            clearFullscreenMapImageFit();
            return;
        }
        // naturalWidth stores the media width recorded during image indexing.
        const naturalWidth = Number.parseInt(card.dataset.imageWidth || '0', 10);
        // naturalHeight stores the media height recorded during image indexing.
        const naturalHeight = Number.parseInt(card.dataset.imageHeight || '0', 10);
        if (!naturalWidth || !naturalHeight) {
            clearFullscreenMapImageFit();
            return;
        }
        const rect = stageLink.getBoundingClientRect();
        const availableWidth = Math.max(1, rect.width);
        const availableHeight = Math.max(1, rect.height);
        const imageRatio = naturalWidth / naturalHeight;
        let fitWidth = availableWidth;
        let fitHeight = fitWidth / imageRatio;
        if (fitHeight > availableHeight) {
            fitHeight = availableHeight;
            fitWidth = fitHeight * imageRatio;
        }
        stageLink.style.setProperty('--lightbox-map-fit-width', `${Math.round(fitWidth)}px`);
        stageLink.style.setProperty('--lightbox-map-fit-height', `${Math.round(fitHeight)}px`);
    }

    /**
     * Clear split-map image fit values when the viewer leaves split-map layout.
     *
     * @returns {void}
     */
    function clearFullscreenMapImageFit() {
        if (!stageLink) {
            return;
        }
        stageLink.style.removeProperty('--lightbox-map-fit-width');
        stageLink.style.removeProperty('--lightbox-map-fit-height');
    }

    /**
     * Show the fullscreen map pane as unavailable for a photo without GPS EXIF.
     *
     * @param {string} title Current photo title.
     * @returns {void}
     */
    function openLightboxMapUnavailable(title) {
        if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen() || !lightboxMapSplit || !lightboxMapSplitCanvas) {
            return;
        }
        clearLightboxSplitMapRuntime();
        lightboxMapSplit.hidden = false;
        lightboxMapSplit.classList.add('is-map-unavailable');
        lightboxMapSplit.setAttribute('aria-disabled', 'true');
        overlay.classList.add('is-map-split', 'is-map-split-disabled');
        if (lightboxMapSplitTitle) {
            lightboxMapSplitTitle.textContent = title || 'Map';
        }
        lightboxMapSplitCanvas.innerHTML = `<div class="lightbox-map-unavailable" role="status"><strong>${escapeHtml(i18n('lightbox.no_gps_title', 'No GPS EXIF data'))}</strong><span>${escapeHtml(i18n('lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'))}</span></div>`;
        requestAnimationFrame(() => updateFullscreenMapImageFit(cards[currentIndex] || null));
    }

    /**
     * Handles toggle current lightbox map behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function toggleCurrentLightboxMap(json = '') {
        if (!sharedLightboxMapUiAvailable()) {
            closeLightboxMapSplit();
            closeMapOverlay();
            return;
        }
        // card stores state or configuration for the gallery front-end flow.
        const card = cards[currentIndex] || null;
        // mapPoint stores the active photo marker payload when one is available.
        const mapPoint = (json || lightboxMapPointForCard(card) || lightboxMapButton?.dataset.mapPoint || '').trim();
        if (isLightboxFullscreen()) {
            if (mapPoint) {
                await toggleLightboxPhotoMapSplit(mapPoint, card?.dataset.title || overlay.dataset.currentTitle || 'Map');
            } else if (hasLightboxGalleryMapPayload()) {
                await toggleLightboxGalleryMapSplit(card?.dataset.title || overlay.dataset.currentTitle || currentLightboxGalleryMapTitle('Map'));
            } else {
                toggleLightboxMapSplit('', card?.dataset.title || overlay.dataset.currentTitle || 'Map');
            }
            showLightboxHud();
            return;
        }
        // mapOverlay stores state or configuration for the gallery front-end flow.
        const mapOverlay = document.querySelector('[data-map-overlay]');
        if (mapOverlay instanceof HTMLElement && !mapOverlay.hidden) {
            closeMapOverlay();
            return;
        }
        if (mapPoint) {
            await openPhotoMapFromJson(mapPoint);
            return;
        }
        if (hasLightboxGalleryMapPayload()) {
            await openGalleryMap(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle('Gallery map'));
        }
    }

    /**
     * Handles toggle lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function toggleLightboxMapSplit(json, title) {
        if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen()) {
            return;
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            closeLightboxMapSplit();
            return;
        }
        if (json && json.trim()) {
            openLightboxMapSplit(json, title);
            return;
        }
        openLightboxMapUnavailable(title);
    }

    /**
     * Toggle the active photo map, merged with the gallery route when available.
     *
     * @param {string} json Serialized active photo map point.
     * @param {string} title Current photo title.
     * @returns {Promise<void>}
     */
    async function toggleLightboxPhotoMapSplit(json, title) {
        if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen()) {
            return;
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            closeLightboxMapSplit();
            return;
        }
        await openLightboxPhotoMapSplit(json, title);
    }

    /**
     * Open the active photo map, merged with the gallery route when available.
     *
     * @param {string} json Serialized active photo map point.
     * @param {string} title Current photo title.
     * @returns {Promise<void>}
     */
    async function openLightboxPhotoMapSplit(json, title) {
        if (!json || !json.trim()) {
            openLightboxMapUnavailable(title);
            return;
        }
        const mapPayload = await photoMapPayloadWithGalleryRoute(parseMapPayload(json));
        await openLightboxMapSplit(JSON.stringify(mapPayload), title);
    }

    /**
     * Toggle the gallery-level route or map payload inside fullscreen split mode.
     *
     * @param {string} title Current viewer title fallback.
     * @returns {Promise<void>}
     */
    async function toggleLightboxGalleryMapSplit(title) {
        if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen() || !hasLightboxGalleryMapPayload()) {
            return;
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            closeLightboxMapSplit();
            return;
        }
        await openLightboxGalleryMapSplit(title);
    }

    /**
     * Open the gallery-level route or map payload inside fullscreen split mode.
     *
     * @param {string} title Current viewer title fallback.
     * @returns {Promise<void>}
     */
    async function openLightboxGalleryMapSplit(title) {
        const payload = await fetchGalleryMapPayload(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle(title || 'Gallery map'));
        if (!payload || !payload.points.length) {
            openLightboxMapUnavailable(title || 'Map');
            return;
        }
        await openLightboxMapSplit(JSON.stringify(payload), payload.title || title || currentLightboxGalleryMapTitle('Gallery map'));
    }

    /**
     * Handles open lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function openLightboxMapSplit(json, title) {
        if (!sharedLightboxMapUiAvailable()) {
            return;
        }
        // mapPayload stores the normalized point or route data for the split pane.
        const mapPayload = parseMapPayload(json);
        const points = mapPayload.points;
        if (!points.length || !lightboxMapSplit || !lightboxMapSplitCanvas) {
            return;
        }
        await ensureLeaflet();
        clearLightboxSplitMapRuntime();
        lightboxMapSplit.hidden = false;
        lightboxMapSplit.classList.remove('is-map-unavailable');
        lightboxMapSplit.removeAttribute('aria-disabled');
        lightboxMapSplitTitle.textContent = title || 'Map';
        overlay.classList.add('is-map-split');
        overlay.classList.remove('is-map-split-disabled');
        requestAnimationFrame(() => updateFullscreenMapImageFit(cards[currentIndex] || null));
        await waitForElementSize(lightboxMapSplitCanvas);
        lightboxMapSplitCanvas.innerHTML = '';
        // map stores state or configuration for the gallery front-end flow.
        const map = L.map(lightboxMapSplitCanvas, {
            fadeAnimation: false,
            markerZoomAnimation: false,
            zoomAnimation: false,
        });
        overlay.galleryLeafletSplitMap = map;
        if (overlay.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);
        // bounds stores state or configuration for the gallery front-end flow.
        const bounds = renderLeafletMapPayload(map, points, mapPayload);
        setInitialMapViewport(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map);
        stabilizeMapAfterLayout(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map);
        overlay.galleryLeafletSplitResizeObserver = new ResizeObserver(() => {
            if (isUsableLeafletMap(overlay.galleryLeafletSplitMap)) {
                overlay.galleryLeafletSplitMap.invalidateSize(false);
            }
        });
        overlay.galleryLeafletSplitResizeObserver.observe(lightboxMapSplitCanvas);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                updateFullscreenMapImageFit(cards[currentIndex] || null);
                if (isUsableLeafletMap(overlay.galleryLeafletSplitMap)) {
                    overlay.galleryLeafletSplitMap.invalidateSize(false);
                }
            });
        });
    }

    /**
     * Handles is usable leaflet map behavior for the gallery UI.
     * @param {*} map Value supplied by the caller or event context.
     * @param {*} isCurrent Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isUsableLeafletMap(map, isCurrent = () => true) {
        return Boolean(
            map &&
            isCurrent() &&
            map._container &&
            map._container.isConnected &&
            map._mapPane
        );
    }

    // Function `setInitialMapViewport` executes this focused behavior.
    function setInitialMapViewport(map, bounds, options, isCurrent = () => true) {
        requestAnimationFrame(() => {
            if (!isUsableLeafletMap(map, isCurrent) || bounds.length === 0) {
                return;
            }
            try {
                map.invalidateSize(false);
                if (bounds.length === 1) {
                    map.setView(bounds[0], 15, {animate: false});
                } else if (bounds.length > 1) {
                    map.fitBounds(bounds, {...options, animate: false});
                }
            } catch {
                // Leaflet can briefly expose a stale map pane while overlays are
                // being recreated. Later stabilization passes will retry.
            }
        });
    }

    // Function `stabilizeMapAfterLayout` executes this focused behavior.
    function stabilizeMapAfterLayout(map, bounds, options, isCurrent = () => true) {
        // refreshDelays stores state or configuration for the gallery front-end flow.
        const refreshDelays = [0, 60, 150, 350];
        refreshDelays.forEach((delay) => {
            window.setTimeout(() => {
                if (!isUsableLeafletMap(map, isCurrent)) {
                    return;
                }
                setInitialMapViewport(map, bounds, options, isCurrent);
            }, delay);
        });
    }

    /**
     * Handles wait for element size behavior for the gallery UI.
     * @param {*} element Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function waitForElementSize(element) {
        for (let attempt = 0; attempt < 12; attempt += 1) {
            // rect stores state or configuration for the gallery front-end flow.
            const rect = element.getBoundingClientRect();
            // computed stores state or configuration for the gallery front-end flow.
            const computed = window.getComputedStyle(element);
            if (rect.width > 0 && rect.height > 0 && computed.display !== 'none' && computed.visibility !== 'hidden') {
                return;
            }
            await afterNextPaint();
        }
    }

    /**
     * Handles close lightbox map split behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function closeLightboxMapSplit() {
        clearLightboxSplitMapRuntime();
        clearFullscreenMapImageFit();
        if (lightboxMapSplit) {
            lightboxMapSplit.hidden = true;
            lightboxMapSplit.classList.remove('is-map-unavailable');
            lightboxMapSplit.removeAttribute('aria-disabled');
        }
        overlay.classList.remove('is-map-split', 'is-map-split-disabled');
        if (lightboxMapSplitCanvas) {
            lightboxMapSplitCanvas.innerHTML = '';
        }
    }

    /**
     * Parse a serialized map marker or route payload.
     *
     * @param {*} json Value supplied by the caller or event context.
     * @returns {{title: string, points: Array, geometry: *, sourceType: string}}
     */
    function parseMapPayload(json) {
        try {
            // parsed stores state or configuration for the gallery front-end flow.
            const parsed = JSON.parse(json);
            return normalizeMapPayload(parsed);
        } catch {
            return normalizeMapPayload(null);
        }
    }

    // Function `mapPopupHtml` executes this focused behavior.
    function mapPopupHtml(point) {
        // Variable `title` stores this steps working value.
        const title = escapeHtml(point.title || point.name || 'Map point');
        // Variable `description` stores this steps working value.
        const description = point.description ? `<p>${escapeHtml(point.description)}</p>` : '';
        // Variable `thumb` stores this steps working value.
        const thumb = point.thumb ? `<img decoding="async" loading="lazy" src="${escapeAttribute(point.thumb)}" alt="">` : '';
        // Variable `image` stores this steps working value.
        const image = point.image ? `<p><a href="${escapeAttribute(point.image)}">Open photo</a></p>` : '';
        return `<div class="map-popup">${thumb}<h3>${title}</h3>${description}${image}</div>`;
    }

    // Function `escapeHtml` executes this focused behavior.
    function escapeHtml(value) {
        return String(value).replace(/[&<>"]/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[character]));
    }

    // Function `escapeAttribute` executes this focused behavior.
    function escapeAttribute(value) {
        return escapeHtml(value).replace(/'/g, '&#039;');
    }

    // The vote module owns the fetch call. The lightbox only updates viewer-specific state.
    document.addEventListener('php-gallery:vote-updated', (event) => {
        if (controller.signal.aborted) {
            return;
        }
        const result = event.detail || {};
        if (overlay && overlay.dataset.currentImageId === String(result.image_id)) {
            if (lightboxVotePanel) {
                lightboxVotePanel.querySelectorAll('[data-score-for]').forEach((node) => {
                    node.textContent = String(result.score);
                });
            }
            updateLightboxVoteButtons(lightboxVotePanel, String(result.vote));
        }
    }, {signal: controller.signal});
}
