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
 *   2026-08-11
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
 * @return {string} Browser-facing translated text.
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
export { setupTagSuggestions } from './tag-suggestions.js?v=20260528-tag-pills-v1';
import { currentLightboxVoteForm, syncLightboxVote, updateLightboxVoteButtons } from './lightbox-votes.js?v=20260512-modular-lightbox-v1';
import {
    LIGHTBOX_ZOOM_MAX_SCALE,
    LIGHTBOX_ZOOM_MIN_SCALE,
    LIGHTBOX_ZOOM_STEP,
    createLightboxZoomState,
    lightboxZoomPercentage,
    lightboxZoomRequiredSourceWidth,
    normalizeLightboxZoomState,
    normalizeLightboxZoomQualityCandidates,
    panLightboxZoomState,
    selectLightboxZoomQualityCandidate,
    zoomLightboxStateAtAnchor,
    zoomLightboxStateAtPhotoAnchor,
    zoomLightboxStateAtRenderedAnchor,
} from './lightbox-zoom-model.js?v=20260817-lightbox-zoom-centered-frame-v5';

const galleryLightboxState = {
    controller: null,
    cleanup: null,
};

/**
 * Releases lightbox listeners and viewer-held DOM references before public gallery content is replaced.
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

/**
 * Handle setup gallery lightbox.
 *
 * Used by browser-side gallery behavior.
 *
 * @return {object} Object result for the caller.
 */
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
        /**
     * Normalize the browsing mode emitted by PHP before it drives DOM behavior.
     *
     * Older deployments used strip as the stored picture-strip value. The public
     * data attribute now uses picture_strip, but accepting the legacy value keeps
     * cached markup and not-yet-migrated rows from falling back unexpectedly.
     *
     * @param {string} value Raw server-rendered browsing mode value.
     * @return {'single'|'picture_strip'|'3d_carousel'} Supported browser mode.
     */
    function normalizeLightboxBrowsingMode(value) {
        const mode = String(value || '').trim().toLowerCase();
        if (mode === 'strip') {
            return 'picture_strip';
        }
        return ['single', 'picture_strip', '3d_carousel'].includes(mode) ? mode : 'single';
    }

    // lightboxBrowsingMode stores the effective Theme plus gallery override emitted by PHP.
    const lightboxBrowsingMode = normalizeLightboxBrowsingMode(
        (lightboxConfig instanceof HTMLElement ? lightboxConfig.dataset.lightboxBrowsingMode || '' : '') ||
        (overlay instanceof HTMLElement ? overlay.dataset.lightboxBrowsingMode || '' : '') ||
        'single'
    );
    // lightboxPendingWindows stores in-flight async metadata requests keyed by endpoint range.
    const lightboxPendingWindows = new Map();

        /**
     * Refreshes the lightbox order after an admin reorders visible photo cards or replaces public gallery content.
     *
     * Navigation must read the current DOM order so Next and Previous match the
     * saved gallery order without requiring a full page reload. Paginated pages
     * use a sparse client-side cache, so non-visible images are fetched only when
     * the user approaches them in the viewer.
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
     * @return {number} Zero-based index, or -1 when no usable index exists.
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
     * @return {HTMLElement|null} Detached source element, or null when the payload is invalid.
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
        if (Array.isArray(item.quality_sources)) {
            card.dataset.lightboxQualitySources = JSON.stringify(item.quality_sources);
        }
        card.dataset.pageUrl = String(item.page_url || '');
        card.dataset.galleryUrl = String(item.gallery_url || '');
        card.dataset.title = String(item.title || '');
        card.dataset.description = String(item.description || '');
        card.dataset.score = String(item.score ?? '0');
        card.dataset.userVote = String(item.user_vote ?? '0');
        if (typeof item.viewer_favourite === 'boolean') {
            card.dataset.viewerFavourite = item.viewer_favourite ? '1' : '0';
        }
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
     * @return {{offset:number, limit:number} } Endpoint range parameters.
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
     * @return {boolean} True when every position in the range has a card.
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
     * @return {Promise<boolean>} True when the request completed successfully or was unnecessary.
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
     * @return {Promise<boolean>} True when the surrounding metadata is available.
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
    let image = overlay.querySelector('[data-lightbox-img]');
    // zoomSurface is the real fitted photograph frame. It stays centered in the stage and grows symmetrically with zoom.
    const zoomSurface = overlay.querySelector('[data-lightbox-zoom-surface]');
    // stageLink stores state or configuration for the gallery front-end flow.
    const stageLink = image ? image.closest('.lightbox-stage-link') : null;
    // zoomStatuses mirror the current scale in the normal toolbar and fullscreen HUD.
    const zoomStatuses = Array.from(overlay.querySelectorAll('[data-lightbox-zoom-status]'));
    // zoomAnnouncement is the single polite screen-reader status for deliberate scale changes.
    const zoomAnnouncement = overlay.querySelector('[data-lightbox-zoom-announcement]');
    // previousButtons keeps every rendered previous-photo control synchronized.
    const previousButtons = Array.from(overlay.querySelectorAll('[data-lightbox-action="previous"]'));
    // nextButtons keeps every rendered next-photo control synchronized.
    const nextButtons = Array.from(overlay.querySelectorAll('[data-lightbox-action="next"]'));
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
    // lightboxRapidNavigationThreshold skips blend waits when the visitor outruns decoding.
    const lightboxRapidNavigationThreshold = 220;
    // lightboxSlideshowVisibleDuration stores how long one slideshow image remains stable before the next blend starts.
    const lightboxSlideshowVisibleDuration = readLightboxTimingSetting('lightboxSlideshowVisibleMs', 2000, 500, 600000);
    // lightboxSlideshowTransitionDuration stores the slideshow blend duration for automatic picture changes.
    const lightboxSlideshowTransitionDuration = readLightboxTimingSetting('lightboxSlideshowTransitionMs', 1000, 0, 30000);
    // lightboxSlideshowEnabled stores whether this rendered gallery permits slideshow activation.
    const lightboxSlideshowEnabled = !(overlay instanceof HTMLElement) || overlay.dataset.lightboxSlideshowEnabled !== '0';
    // lightboxPreviewPreloadRadius limits how many nearby preview images are warmed after a photo opens.
    const lightboxPreviewPreloadRadius = 4;
    // lightboxFullPreloadRadius stays zero so adjacent navigation warms previews without downloading full media early.
    const lightboxFullPreloadRadius = 0;
    // lightboxQualityUpgradeDelay coalesces passive viewport/layout quality checks; explicit zoom requests promote immediately.
    const lightboxQualityUpgradeDelay = 140;
    // lightboxDecodedImageCacheLimit keeps only the current preview neighborhood decoded.
    const lightboxDecodedImageCacheLimit = 12;
    // transitionImage stores state or configuration for the gallery front-end flow.
    let transitionImage = null;
    // activeLightboxTransitionToken stores state or configuration for the gallery front-end flow.
    let activeLightboxTransitionToken = 0;
    // pendingLightboxQualityTimer owns the current debounced source-density evaluation.
    let pendingLightboxQualityTimer = 0;
    // activeLightboxQualityRequestToken invalidates late high-resolution decodes after navigation or close.
    let activeLightboxQualityRequestToken = 0;
    // activeLightboxQualitySource records the current image source without relying on absolute URL normalization.
    let activeLightboxQualitySource = '';
    // pendingLightboxQualitySource prevents duplicate decodes while one larger source is already in flight.
    let pendingLightboxQualitySource = '';
    // failedLightboxQualitySources prevents a broken full source from retrying on every gesture.
    const failedLightboxQualitySources = new Set();
    // pendingLightboxNavigationTimer delays the busy indicator so hot cached images do not flash a spinner.
    let pendingLightboxNavigationTimer = 0;
    // pendingLightboxNavigationToken owns the delayed busy indicator for the latest requested image.
    let pendingLightboxNavigationToken = 0;
    // Variable `title` stores this steps working value.
    const title = overlay.querySelector('[data-lightbox-title]');
    // Variable `description` stores this steps working value.
    const description = overlay.querySelector('[data-lightbox-description]');
    // lightboxHelpPanel stores the optional keyboard shortcut help panel.
    const lightboxHelpPanel = overlay.querySelector('[data-lightbox-help-panel]');
    // lightboxHelpButtons stores every shortcut help toggle button.
    const lightboxHelpButtons = Array.from(overlay.querySelectorAll('[data-lightbox-action="help"]'));
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
    // pictureStrip stores the optional nearby-photo browser container rendered only for enhanced public lightbox modes.
    const pictureStrip = overlay.querySelector('[data-lightbox-strip]');
    // pictureStripTrack stores the animated row or 3D plane whose children are rebuilt when the active index changes.
    const pictureStripTrack = overlay.querySelector('[data-lightbox-strip-track]');
    // pictureStripEnabled stores whether the current gallery wants the flat picture-strip mode and the needed markup exists.
    const pictureStripEnabled = lightboxBrowsingMode === 'picture_strip' && pictureStrip instanceof HTMLElement && pictureStripTrack instanceof HTMLElement;
    // threeDCarouselEnabled stores whether the current gallery wants layered neighboring cards behind the main image.
    const threeDCarouselEnabled = lightboxBrowsingMode === '3d_carousel' && pictureStrip instanceof HTMLElement && pictureStripTrack instanceof HTMLElement;
    // lightboxNeighborBrowserEnabled means either enhanced nearby-photo mode owns the shared strip container.
    const lightboxNeighborBrowserEnabled = pictureStripEnabled || threeDCarouselEnabled;
    // pictureStripAnimationToken prevents late animation cleanup from a previous navigation from touching the current strip state.
    let pictureStripAnimationToken = 0;
    // pictureStripLastIndex stores the last rendered center index so the animation direction can be inferred.
    let pictureStripLastIndex = -1;
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
    // lightboxPreloadQueue holds nearby preview work so opening a photo does not start all downloads at once.
    const lightboxPreloadQueue = [];
    // lightboxQueuedSources prevents duplicate queued work while still allowing cached image reuse.
    const lightboxQueuedSources = new Set();
    // lightboxPreloadGeneration invalidates stale queued work after fast next/previous navigation.
    let lightboxPreloadGeneration = 0;
    // activeLightboxPreloads tracks how many background image decodes are currently active.
    let activeLightboxPreloads = 0;
    // lightboxPreloadAbortController owns active nearby preview preloads for the current navigation generation.
    let lightboxPreloadAbortController = new AbortController();
    // activeDetachedLightboxImageLoads contains cancellation callbacks for detached image requests that have not settled yet.
    const activeDetachedLightboxImageLoads = new Set();
    // lastLightboxNavigationRequestedAt stores the last manual navigation timestamp.
    let lastLightboxNavigationRequestedAt = 0;
    // lightboxPreloadDrainHandle stores the pending queue drain callback handle.
    let lightboxPreloadDrainHandle = 0;
    // lightboxPreloadDrainUsesIdleCallback tracks which browser timer API owns the drain handle.
    let lightboxPreloadDrainUsesIdleCallback = false;
    // fullscreenHideTimer stores state or configuration for the gallery front-end flow.
    let fullscreenHideTimer = null;
    // lightboxSlideshowTimer stores the automatic advance timer while slideshow mode is active.
    let lightboxSlideshowTimer = null;
    // lightboxSlideshowScheduleToken invalidates an automatic cycle when slideshow stops or navigation changes.
    let lightboxSlideshowScheduleToken = 0;
    // lightboxSlideshowPreloadController owns the single detached full-image request prepared for the next slide.
    let lightboxSlideshowPreloadController = null;
    // lightboxSlideshowActive stores whether slideshow mode owns automatic fullscreen advancing.
    let lightboxSlideshowActive = false;
    // touchGesture stores the active mobile stage swipe, when one is in progress.
    let touchGesture = null;
    // mobileSwipeVisualTimer clears temporary swipe animation classes after they settle.
    let mobileSwipeVisualTimer = 0;
    // suppressNextStageClick prevents a completed swipe from also toggling controls through a synthetic tap.
    let suppressNextStageClick = false;
    // lightboxZoomState is presentation-only state for the current decoded image.
    let lightboxZoomState = createLightboxZoomState();
    // lightboxZoomAnimationTimer removes the short transform transition after button or keyboard zooming.
    let lightboxZoomAnimationTimer = 0;
    // lightboxZoomPan owns one captured pointer while a zoomed image is dragged.
    let lightboxZoomPan = null;
    // lightboxZoomPointerPosition remembers the latest desktop pointer position over the photo stage.
    let lightboxZoomPointerPosition = null;
    // lightboxZoomPointers tracks only touch pointers that may form a two-pointer pinch.
    const lightboxZoomPointers = new Map();
    // lightboxZoomPinch stores the immutable start geometry for the active pinch.
    let lightboxZoomPinch = null;
    // lightboxZoomReclampFrame waits for fullscreen and responsive layout changes before measuring again.
    let lightboxZoomReclampFrame = 0;
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
    overlay.classList.toggle('is-picture-strip-mode', pictureStripEnabled);
    overlay.classList.toggle('is-3d-carousel-mode', threeDCarouselEnabled);
    if (pictureStrip instanceof HTMLElement) {
        pictureStrip.hidden = !lightboxNeighborBrowserEnabled;
        pictureStrip.setAttribute('aria-label', threeDCarouselEnabled ? i18n('lightbox.3d_carousel_label', '3D carousel nearby photos') : i18n('lightbox.picture_strip_label', 'Nearby photos'));
    }
    prepareMobileLightboxOverlay();
    updateMobileLightboxViewport();
    window.__LIGHTBOX_DEBUG__ = isLightboxDebugEnabled;

    galleryLightboxState.cleanup = () => {
        controller.abort();
        cards = [];
        clearPendingLightboxQualityUpgrade();
        clearLightboxNavigationPending();
        clearLightboxHudTimer();
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearLightboxNavigationPending();
        resetMobileSwipeVisuals(false);
        stopLightboxSlideshow(false);
        removeTransitionImage();
        preloadedSources.clear();
        resetLightboxPreloadQueue();
        cancelActiveDetachedLightboxImageLoads();
        lightboxPendingWindows.clear();
        lightboxGalleryMapPayloadPromises.clear();
        decodedLightboxImages.clear();
        failedLightboxQualitySources.clear();
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
        overlay.classList.remove('is-fullscreen', 'is-mobile-fullscreen', 'is-ui-visible', 'is-map-split', 'is-map-split-disabled', 'is-slideshow', 'is-picture-strip-animating', 'is-3d-carousel-mode');
        overlay.classList.toggle('is-picture-strip-mode', pictureStripEnabled);
        overlay.classList.toggle('is-3d-carousel-mode', threeDCarouselEnabled);
        if (pictureStripTrack instanceof HTMLElement) {
            pictureStripTrack.replaceChildren();
        }
        if (pictureStrip instanceof HTMLElement) {
            pictureStrip.hidden = !lightboxNeighborBrowserEnabled;
        }
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
     */
    function prepareMobileLightboxOverlay() {
        if (!isMobileTouchDevice || overlay.parentElement === document.body) {
            return;
        }
        document.body.append(overlay);
    }

        /**
     * Keep the CSS fullscreen shell aligned with the currently visible mobile viewport.
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
     */
    function clearLightboxStageFocus() {
        if (stageLink && document.activeElement === stageLink) {
            stageLink.blur();
        }
    }

    // activeLightboxImageToken stores state or configuration for the gallery front-end flow.
    let activeLightboxImageToken = 0;

    /**
     * Cancel pending quality evaluation and invalidate any background decode.
     */
    function clearPendingLightboxQualityUpgrade() {
        if (pendingLightboxQualityTimer) {
            window.clearTimeout(pendingLightboxQualityTimer);
            pendingLightboxQualityTimer = 0;
        }
        activeLightboxQualityRequestToken += 1;
        pendingLightboxQualitySource = '';
        setLightboxQualityLoading(false);
    }

    /**
     * Synchronize the visible spinner and accessible full-quality loading status.
     *
     * @param {boolean} loading Whether an active-photo quality decode is in progress.
     */
    function setLightboxQualityLoading(loading) {
        const isLoading = Boolean(loading && !overlay.hidden);
        overlay.classList.toggle('is-quality-loading', isLoading);
        if (stageLink instanceof HTMLElement) {
            if (isLoading) {
                const label = i18n('lightbox.quality_loading', 'Loading full-quality image...');
                stageLink.setAttribute('aria-busy', 'true');
                stageLink.dataset.qualityLoadingLabel = label;
                if (zoomAnnouncement instanceof HTMLElement) {
                    zoomAnnouncement.textContent = label;
                }
            } else {
                stageLink.removeAttribute('aria-busy');
                delete stageLink.dataset.qualityLoadingLabel;
                if (zoomAnnouncement instanceof HTMLElement) {
                    zoomAnnouncement.textContent = '';
                }
            }
        }
    }

        /**
     * Report whether an async lightbox image request still belongs to the active photo.
     *
     * @param {number} index Requested lightbox index.
     * @param {number} token Request token captured when the navigation started.
     * @return {boolean} True when the request may still update the visible image.
     */
    function isCurrentLightboxImageRequest(index, token) {
        return !controller.signal.aborted && currentIndex === index && activeLightboxImageToken === token;
    }

        /**
     * Clear the delayed busy state used while a fast navigation waits for decoding.
     *
     * @param {number|null} token Optional request token that must own the pending state.
     */
    function clearLightboxNavigationPending(token = null) {
        if (token !== null && pendingLightboxNavigationToken !== token) {
            return;
        }
        if (pendingLightboxNavigationTimer) {
            window.clearTimeout(pendingLightboxNavigationTimer);
            pendingLightboxNavigationTimer = 0;
        }
        pendingLightboxNavigationToken = 0;
        overlay.classList.remove('is-navigation-loading');
    }

        /**
     * Show a subtle busy state only when a requested image is not ready quickly.
     *
     * @param {number} index Requested lightbox index.
     * @param {number} token Request token captured when the navigation started.
     * @param {string} targetSrc Image URL expected to appear first.
     */
    function scheduleLightboxNavigationPending(index, token, targetSrc) {
        clearLightboxNavigationPending();
        pendingLightboxNavigationToken = token;
        if (!targetSrc || image.getAttribute('src') === targetSrc) {
            return;
        }
        pendingLightboxNavigationTimer = window.setTimeout(() => {
            pendingLightboxNavigationTimer = 0;
            if (isCurrentLightboxImageRequest(index, token) && image.getAttribute('src') !== targetSrc) {
                overlay.classList.add('is-navigation-loading');
            }
        }, 140);
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
     * @return {number} Safe duration in milliseconds.
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
     * @return {number} Transition duration in milliseconds.
     */
    function currentLightboxTransitionDuration() {
        return lightboxSlideshowActive ? lightboxSlideshowTransitionDuration : lightboxDefaultTransitionDuration;
    }

        /**
     * Handles decode loaded image behavior for the gallery UI.
     *
     * @param {*} loadedImage Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function decodeLoadedImage(loadedImage) {
        if (typeof loadedImage.decode !== 'function') {
            return Promise.resolve();
        }
        return loadedImage.decode().catch(() => undefined);
    }

        /**
     * Handles setup gallery dev mode overlay behavior for the gallery UI.
     */
    function setupGalleryDevModeOverlay() {
        if (!galleryDevModeEnabled) {
            return;
        }
        // shell stores state or configuration for the gallery front-end flow.
        const shell = document.createElement('section');
        shell.className = 'gallery-dev-overlay';
        shell.setAttribute('aria-label', i18n('lightbox.dev_diagnostics_aria', 'Gallery dev mode diagnostics'));
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
     *
     * @param {*} timestamp Value supplied by the caller or event context.
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
     *
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} kind Value supplied by the caller or event context.
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} src Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} src Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function devFindSourceIndex(src) {
        if (!src) {
            return -1;
        }
        return cards.findIndex((card) => card && (card.dataset.previewSrc === src || card.dataset.fullSrc === src));
    }

        /**
     * Handles dev mark source behavior for the gallery UI.
     *
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} status Value supplied by the caller or event context.
     * @param {*} reason Value supplied by the caller or event context.
     * @param {*} imageNode Value supplied by the caller or event context.
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
     *
     * @param {*} message Value supplied by the caller or event context.
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
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} stat Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function devShortStatus(stat) {
        if (!stat) {
            return '?';
        }
        return {idle: 'i', preloading: 'p', loading: 'l', ready: 'r', error: 'e'}[stat.status] || '?';
    }

        /**
     * Handles dev browser memory line behavior for the gallery UI.
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} samples Value supplied by the caller or event context.
     * @param {*} selector Value supplied by the caller or event context.
     * @param {*} height Value supplied by the caller or event context.
     * @param {*} width Value supplied by the caller or event context.
     * @param {*} alpha Value supplied by the caller or event context.
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
     *
     * @param {*} bytes Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} ms Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function formatDevTime(ms) {
        return `${(ms / 1000).toFixed(1)}s`;
    }

        /**
     * Handles shorten dev url behavior for the gallery UI.
     *
     * @param {*} src Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} src Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function loadFreshDecodedLightboxImage(src, options = {}) {
        return new Promise((resolve, reject) => {
            if (!src) {
                reject(new Error(i18n('lightbox.missing_image_source', 'Missing lightbox image source.')));
                return;
            }
            galleryDevModeState.loadStarted += galleryDevModeEnabled ? 1 : 0;
            devMarkSource(src, 'loading', 'fresh');
            // loadedImage stores state or configuration for the gallery front-end flow.
            const loadedImage = new Image();
            // signal optionally lets nearby-image or slideshow lifecycle code cancel this detached request.
            const signal = options.signal && typeof options.signal.addEventListener === 'function' ? options.signal : null;
            let settled = false;

            /** Remove request listeners and release this detached load from the active registry. */
            const cleanupLoad = () => {
                loadedImage.onload = null;
                loadedImage.onerror = null;
                if (signal) {
                    signal.removeEventListener('abort', cancelLoad);
                }
                activeDetachedLightboxImageLoads.delete(cancelLoad);
            };

            /** Cancel an unfinished detached image request and reject it as an abort. */
            const cancelLoad = () => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanupLoad();
                loadedImage.removeAttribute('src');
                const error = new Error('Lightbox image load cancelled.');
                error.name = 'AbortError';
                reject(error);
            };

            activeDetachedLightboxImageLoads.add(cancelLoad);
            if (signal) {
                if (signal.aborted) {
                    cancelLoad();
                    return;
                }
                signal.addEventListener('abort', cancelLoad, {once: true});
            }

            loadedImage.decoding = 'async';
            loadedImage.loading = 'eager';
            if ('fetchPriority' in loadedImage && options.priority) {
                loadedImage.fetchPriority = options.priority;
            }
            loadedImage.onload = () => {
                decodeLoadedImage(loadedImage).then(() => {
                    if (settled) {
                        return;
                    }
                    if (signal?.aborted) {
                        cancelLoad();
                        return;
                    }
                    settled = true;
                    cleanupLoad();
                    devMarkSource(src, 'ready', 'decoded', loadedImage);
                    resolve(loadedImage);
                });
            };
            loadedImage.onerror = () => {
                if (settled) {
                    return;
                }
                settled = true;
                cleanupLoad();
                galleryDevModeState.decodeErrors += galleryDevModeEnabled ? 1 : 0;
                devMarkSource(src, 'error', 'load');
                reject(new Error(i18n('lightbox.image_load_failed', 'Lightbox image load failed.')));
            };
            loadedImage.src = src;
        });
    }

    /**
     * Cancel every unfinished detached image request owned by the lightbox.
     *
     * This is used when the viewer closes or is torn down so decoded-image work
     * cannot continue consuming bandwidth and memory after the gallery is visible.
     */
    function cancelActiveDetachedLightboxImageLoads() {
        Array.from(activeDetachedLightboxImageLoads).forEach((cancelLoad) => {
            try {
                cancelLoad();
            } catch {
                // A late browser cancellation must never break viewer teardown.
            }
        });
        activeDetachedLightboxImageLoads.clear();
    }

        /**
     * Handles remember decoded lightbox image behavior for the gallery UI.
     *
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} preloadPromise Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} src Value supplied by the caller or event context.
     * @param {object} options Optional preload lifecycle controls.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function preloadDecodedLightboxImage(src, options = {}) {
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
        const preloadPromise = loadFreshDecodedLightboxImage(src, {priority: 'low', signal: options.signal}).catch(() => null);
        rememberDecodedLightboxImage(src, preloadPromise);
        preloadPromise.finally(() => {
            if (options.signal?.aborted && decodedLightboxImages.get(src) === preloadPromise) {
                decodedLightboxImages.delete(src);
            }
        });
        return preloadPromise;
    }

        /**
     * Return how many background lightbox preview preloads may run at once.
     *
     * @return {number} Safe concurrent preload count for the current browser context.
     */
    function lightboxPreloadConcurrency() {
        if (shouldLimitLightboxPreloading()) {
            return 1;
        }
        const connection = currentLightboxConnection();
        if (isMobileTouchDevice || connection?.effectiveType === '3g') {
            return 1;
        }
        return 2;
    }

        /**
     * Schedule a low-priority drain of the nearby-image preload queue.
     */
    function scheduleLightboxPreloadDrain() {
        if (lightboxPreloadDrainHandle || controller.signal.aborted) {
            return;
        }
        /** Drain the preload queue when the selected idle mechanism fires. */
        const drain = () => {
            lightboxPreloadDrainHandle = 0;
            lightboxPreloadDrainUsesIdleCallback = false;
            drainLightboxPreloadQueue();
        };
        if ('requestIdleCallback' in window) {
            lightboxPreloadDrainUsesIdleCallback = true;
            lightboxPreloadDrainHandle = window.requestIdleCallback(drain, {timeout: 350});
            return;
        }
        lightboxPreloadDrainUsesIdleCallback = false;
        lightboxPreloadDrainHandle = window.setTimeout(drain, 80);
    }

        /**
     * Cancel queued nearby-image preload work that has not started yet.
     */
    function resetLightboxPreloadQueue() {
        lightboxPreloadGeneration += 1;
        lightboxPreloadQueue.length = 0;
        lightboxQueuedSources.clear();
        lightboxPreloadAbortController.abort();
        lightboxPreloadAbortController = new AbortController();
        if (!lightboxPreloadDrainHandle) {
            return;
        }
        if (lightboxPreloadDrainUsesIdleCallback && 'cancelIdleCallback' in window) {
            window.cancelIdleCallback(lightboxPreloadDrainHandle);
        } else {
            window.clearTimeout(lightboxPreloadDrainHandle);
        }
        lightboxPreloadDrainHandle = 0;
        lightboxPreloadDrainUsesIdleCallback = false;
    }

        /**
     * Add one low-priority nearby-image preload to the queue.
     *
     * @param {string} src Image URL to warm.
     * @param {string} reason Diagnostic reason shown in dev mode.
     * @param {number} generation Queue generation that owns this work item.
     */
    function queueDecodedLightboxPreload(src, reason, generation) {
        if (!src || controller.signal.aborted) {
            return;
        }
        if (decodedLightboxImages.has(src)) {
            preloadDecodedLightboxImage(src);
            return;
        }
        if (lightboxQueuedSources.has(src)) {
            return;
        }
        lightboxQueuedSources.add(src);
        lightboxPreloadQueue.push({src, reason, generation});
        scheduleLightboxPreloadDrain();
    }

        /**
     * Start queued nearby-image preloads within the current concurrency limit.
     */
    function drainLightboxPreloadQueue() {
        if (controller.signal.aborted) {
            resetLightboxPreloadQueue();
            return;
        }
        const concurrency = lightboxPreloadConcurrency();
        while (activeLightboxPreloads < concurrency && lightboxPreloadQueue.length > 0) {
            const item = lightboxPreloadQueue.shift();
            if (!item || !item.src) {
                continue;
            }
            lightboxQueuedSources.delete(item.src);
            if (item.generation !== lightboxPreloadGeneration) {
                continue;
            }
            activeLightboxPreloads += 1;
            devMarkSource(item.src, 'preloading', item.reason || 'queued-preview');
            preloadDecodedLightboxImage(item.src, {signal: lightboxPreloadAbortController.signal}).finally(() => {
                activeLightboxPreloads = Math.max(0, activeLightboxPreloads - 1);
                if (lightboxPreloadQueue.length > 0) {
                    scheduleLightboxPreloadDrain();
                }
            });
        }
    }

        /**
     * Handles load decoded lightbox image behavior for the gallery UI.
     *
     * @param {*} src Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function loadDecodedLightboxImage(src, options = {}) {
        if (!src) {
            return Promise.reject(new Error(i18n('lightbox.missing_image_source', 'Missing lightbox image source.')));
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
                const freshPromise = loadFreshDecodedLightboxImage(src, options);
                rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
                return freshPromise;
            });
        }
        galleryDevModeState.cacheMisses += galleryDevModeEnabled ? 1 : 0;
        // freshPromise stores state or configuration for the gallery front-end flow.
        const freshPromise = loadFreshDecodedLightboxImage(src, options);
        rememberDecodedLightboxImage(src, freshPromise.catch(() => null));
        return freshPromise;
    }

        /**
     * Handles remove transition image behavior for the gallery UI.
     *
     * @param {*} node Value supplied by the caller or event context.
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
     *
     * @param {*} card Value supplied by the caller or event context.
     */
    function updateNormalLightboxStageSize(card) {
        if (!card) {
            return;
        }
        // naturalWidth stores state or configuration for the gallery front-end flow.
        const naturalWidth = Number.parseInt(card.dataset.imageWidth || '0', 10);
        // naturalHeight stores state or configuration for the gallery front-end flow.
        const naturalHeight = Number.parseInt(card.dataset.imageHeight || '0', 10);
        updateNormalLightboxStageSizeFromDimensions(naturalWidth, naturalHeight);
    }

        /**
     * Resize the normal lightbox stage from trusted intrinsic dimensions.
     *
     * Database dimensions can be wrong for EXIF-oriented JPEGs until the
     * browser decodes the actual display image. This shared helper lets the
     * normal card metadata path and the decoded-image correction path use the
     * same viewport fitting math.
     *
     * @param {number} naturalWidth Intrinsic browser display width.
     * @param {number} naturalHeight Intrinsic browser display height.
     */
    function updateNormalLightboxStageSizeFromDimensions(naturalWidth, naturalHeight) {
        if (!stageLink || overlay.classList.contains('is-fullscreen') || overlay.classList.contains('is-mobile-fullscreen')) {
            return;
        }
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
     * Correct the normal stage size after the browser has decoded an image.
     *
     * This prevents a temporary white letterbox caused by stale scan dimensions
     * or EXIF orientation differences between PHP metadata and browser display.
     *
     * @param {HTMLImageElement|null} loadedImage Decoded image used by the lightbox.
     */
    function updateNormalLightboxStageSizeFromLoadedImage(loadedImage, scheduleReclamp = true) {
        if (!(loadedImage instanceof HTMLImageElement)) {
            return;
        }
        updateNormalLightboxStageSizeFromDimensions(loadedImage.naturalWidth || 0, loadedImage.naturalHeight || 0);
        if (scheduleReclamp) {
            scheduleLightboxZoomReclamp();
        }
    }

    /**
     * Resolve the final 100% presentation geometry for an already-decoded target image.
     *
     * Normal lightbox mode first sizes the stage from the browser-decoded dimensions.
     * Fullscreen and mobile fullscreen keep their fixed viewport-sized stage. A forced
     * stage measurement then makes the returned fitted rectangle authoritative before
     * any pixels from the target image are allowed to become visible.
     *
     * @param {HTMLImageElement} loadedImage Decoded image that is about to be displayed.
     * @return {{viewportWidth:number,viewportHeight:number,imageWidth:number,imageHeight:number,panViewportWidth:number,panViewportHeight:number}|null} Final target geometry.
     */
    function prepareDecodedLightboxGeometry(loadedImage) {
        if (!(loadedImage instanceof HTMLImageElement) || !loadedImage.naturalWidth || !loadedImage.naturalHeight) {
            return null;
        }
        updateNormalLightboxStageSizeFromLoadedImage(loadedImage, false);
        stageLink?.getBoundingClientRect();
        return measureLightboxZoomMetricsForDimensions(loadedImage.naturalWidth, loadedImage.naturalHeight);
    }

        /**
     * Handles apply lightbox image source behavior for the gallery UI.
     *
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @param {string} imageId Stable active-photo identifier.
     */
    function applyLightboxImageSource(src, altText, imageId) {
        image.dataset.lightboxImageId = imageId;
        delete image.dataset.lightboxExplicitZoomQuality;
        if (!src) {
            image.alt = altText;
            return;
        }
        activeLightboxQualitySource = src;
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
     * Install an already-decoded quality image inside the stable zoom surface.
     *
     * Passive 100% quality promotion may still decode off-DOM before installation.
     * The replacement inherits the current image layout, then the shared zoom model
     * reapplies real enlarged CSS dimensions and translation-only pan when zoomed.
     * This avoids compositor scale magnification of a preview raster.
     *
     * Loader callbacks are cleared before installation so later navigation on the
     * same node cannot re-enter an earlier decode promise. Completion is reported
     * after the next animation frame confirms that the decoded child still owns
     * the active lightbox image.
     *
     * @param {HTMLImageElement} loadedImage Detached decoded full-quality image.
     * @param {string} src Authorized source URL represented by the decoded node.
     * @param {string} altText Accessible image alternative text.
     * @param {string} imageId Stable active-photo identifier.
     * @return {Promise<boolean>} True when the decoded child remains installed after the paint boundary.
     */
    function installDecodedLightboxQualityImage(loadedImage, src, altText, imageId) {
        if (
            !(loadedImage instanceof HTMLImageElement)
            || !(image instanceof HTMLImageElement)
            || !(zoomSurface instanceof HTMLElement)
            || !src
        ) {
            return Promise.resolve(false);
        }
        const targetMetrics = prepareDecodedLightboxGeometry(loadedImage);
        if (!targetMetrics) {
            return Promise.resolve(false);
        }
        const previousImage = image;
        loadedImage.onload = null;
        loadedImage.onerror = null;
        loadedImage.removeAttribute('loading');
        loadedImage.removeAttribute('fetchpriority');
        loadedImage.setAttribute('data-lightbox-img', '');
        loadedImage.dataset.lightboxImageId = imageId;
        loadedImage.className = previousImage.className;
        loadedImage.alt = altText;
        loadedImage.decoding = 'async';
        loadedImage.style.cssText = previousImage.style.cssText;

        // Discard any stale pan transform copied from the previous node. The shared
        // zoom renderer immediately reapplies enlarged dimensions and current pan.
        loadedImage.style.removeProperty('transform');
        loadedImage.style.removeProperty('will-change');
        previousImage.replaceWith(loadedImage);
        image = loadedImage;
        activeLightboxQualitySource = src;
        galleryDevModeState.currentSource = src;
        galleryDevModeState.currentSourceKind = devFindSourceKind(src);
        devMarkSource(src, 'ready', 'quality-display');
        applyLightboxZoomState(false, targetMetrics);
        loadedImage.getBoundingClientRect();

        return new Promise((resolve) => {
            window.requestAnimationFrame(() => {
                resolve(
                    image === loadedImage
                    && loadedImage.isConnected
                    && loadedImage.getAttribute('src') === src
                    && loadedImage.dataset.lightboxImageId === imageId
                );
            });
        });
    }

    /**
     * Create a live lightbox image node from an already-decoded cached image.
     *
     * The decoded cache keeps its own detached node. Display nodes are cloned from it
     * so later cache hits cannot move the currently visible image out of the viewer.
     * Geometry-related inline styles are cleared because the target image receives a
     * fresh, aspect-ratio-correct 100% frame before it becomes visible.
     *
     * @param {HTMLImageElement} loadedImage Decoded cached source.
     * @param {HTMLImageElement} previousImage Currently live image used for presentation classes.
     * @param {string} imageId Stable target image identifier.
     * @param {string} altText Accessible target image alternative text.
     * @return {HTMLImageElement} Detached target display node.
     */
    function createPreparedLightboxDisplayNode(loadedImage, previousImage, imageId, altText) {
        const displayNode = loadedImage.cloneNode(false);
        displayNode.onload = null;
        displayNode.onerror = null;
        displayNode.removeAttribute('loading');
        displayNode.removeAttribute('fetchpriority');
        displayNode.setAttribute('data-lightbox-img', '');
        displayNode.dataset.lightboxImageId = imageId;
        displayNode.alt = altText;
        displayNode.decoding = 'sync';
        displayNode.className = previousImage.className;
        displayNode.style.cssText = previousImage.style.cssText;
        displayNode.style.removeProperty('width');
        displayNode.style.removeProperty('height');
        displayNode.style.removeProperty('max-width');
        displayNode.style.removeProperty('max-height');
        displayNode.style.removeProperty('transform');
        displayNode.style.removeProperty('will-change');
        displayNode.style.removeProperty('opacity');
        return displayNode;
    }

    /**
     * Commit an already-decoded image using geometry calculated from that same image.
     *
     * The replacement and the 100% fitted zoom-surface dimensions are applied in one
     * browser task. Two paint boundaries are then allowed before completion is reported,
     * which prevents a caller from removing a covering transition while the browser is
     * still laying out the new aspect ratio.
     *
     * @param {number} index Zero-based image index.
     * @param {number} token Active lightbox navigation token.
     * @param {HTMLImageElement} loadedImage Decoded cached target image.
     * @param {string} src Authorized target source URL.
     * @param {string} altText Accessible image alternative text.
     * @param {{viewportWidth:number,viewportHeight:number,imageWidth:number,imageHeight:number,panViewportWidth:number,panViewportHeight:number}|null} measuredMetrics Precomputed target geometry.
     * @return {Promise<boolean>} True after the final geometry survives two paint boundaries.
     */
    function commitPreparedLightboxImage(index, token, loadedImage, src, altText, measuredMetrics = null) {
        if (
            !(loadedImage instanceof HTMLImageElement)
            || !(image instanceof HTMLImageElement)
            || !(zoomSurface instanceof HTMLElement)
            || !src
            || !isCurrentLightboxImageRequest(index, token)
        ) {
            return Promise.resolve(false);
        }
        const targetMetrics = measuredMetrics || prepareDecodedLightboxGeometry(loadedImage);
        if (!targetMetrics) {
            return Promise.resolve(false);
        }
        const previousImage = image;
        const imageId = String(cards[index]?.dataset.imageId || index);
        const displayNode = createPreparedLightboxDisplayNode(loadedImage, previousImage, imageId, altText);

        return decodeLoadedImage(displayNode).then(() => {
            if (
                !isCurrentLightboxImageRequest(index, token)
                || image !== previousImage
                || !displayNode.complete
                || displayNode.naturalWidth <= 0
                || displayNode.naturalHeight <= 0
            ) {
                return false;
            }
            previousImage.replaceWith(displayNode);
            image = displayNode;
            applyLightboxImageSource(src, altText, imageId);
            applyLightboxZoomState(false, targetMetrics);
            zoomSurface.getBoundingClientRect();
            displayNode.getBoundingClientRect();

            return new Promise((resolve) => {
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        const stillCurrent = isCurrentLightboxImageRequest(index, token)
                            && image === displayNode
                            && displayNode.isConnected
                            && displayNode.getAttribute('src') === src
                            && displayNode.dataset.lightboxImageId === imageId;
                        if (stillCurrent) {
                            clearLightboxNavigationPending(token);
                        }
                        resolve(stillCurrent);
                    });
                });
            });
        }).catch(() => false);
    }

    /**
     * Blend an already-decoded image in at its final centered stage position.
     *
     * The visible transition node is independent from the live zoom surface. This is
     * important when consecutive photographs have different aspect ratios: the old
     * image can keep its old zoom-surface rectangle while the new image fades in using
     * the final stage-centered `object-fit: contain` geometry. Once the transition is
     * fully opaque, the real zoom surface is switched underneath it using dimensions
     * calculated from the same decoded target. The transition is removed only after
     * that final live layout has survived two animation frames.
     *
     * @param {number} index Zero-based image index.
     * @param {number} token Active lightbox navigation token.
     * @param {HTMLImageElement} loadedImage Detached decoded image.
     * @param {string} src Authorized target source URL.
     * @param {string} altText Accessible image alternative text.
     * @return {Promise<boolean>} True when the target image is live at final geometry.
     */
    function showPreparedLightboxTransitionImage(index, token, loadedImage, src, altText) {
        if (
            !(loadedImage instanceof HTMLImageElement)
            || !(image instanceof HTMLImageElement)
            || !(stageLink instanceof HTMLElement)
            || !src
            || !isCurrentLightboxImageRequest(index, token)
        ) {
            return Promise.resolve(false);
        }

        const targetMetrics = prepareDecodedLightboxGeometry(loadedImage);
        if (!targetMetrics) {
            return Promise.resolve(false);
        }
        activeLightboxTransitionToken += 1;
        const transitionToken = activeLightboxTransitionToken;
        removeTransitionImage();
        const imageId = String(cards[index]?.dataset.imageId || index);
        const transitionNode = loadedImage.cloneNode(false);
        transitionNode.onload = null;
        transitionNode.onerror = null;
        transitionNode.removeAttribute('loading');
        transitionNode.removeAttribute('fetchpriority');
        transitionNode.removeAttribute('data-lightbox-img');
        transitionNode.setAttribute('aria-hidden', 'true');
        transitionNode.dataset.lightboxImageId = imageId;
        transitionNode.alt = '';
        transitionNode.decoding = 'sync';
        transitionNode.className = 'lightbox-transition-image';
        const transitionDuration = currentLightboxTransitionDuration();
        transitionNode.style.setProperty('--lightbox-transition-duration', `${transitionDuration}ms`);
        transitionImage = transitionNode;
        stageLink.append(transitionNode);

        return decodeLoadedImage(transitionNode).then(() => new Promise((resolve) => {
            if (
                !isCurrentLightboxImageRequest(index, token)
                || activeLightboxTransitionToken !== transitionToken
                || transitionImage !== transitionNode
                || !transitionNode.isConnected
                || !transitionNode.complete
                || transitionNode.naturalWidth <= 0
                || transitionNode.naturalHeight <= 0
            ) {
                removeTransitionImage(transitionNode);
                resolve(false);
                return;
            }

            // The target stage dimensions and its centered contain geometry are now final.
            // Give the browser two frames before revealing the transition pixels so cached
            // and freshly decoded resources follow the same paint sequence.
            transitionNode.getBoundingClientRect();
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => {
                    if (
                        !isCurrentLightboxImageRequest(index, token)
                        || activeLightboxTransitionToken !== transitionToken
                        || transitionImage !== transitionNode
                        || !transitionNode.isConnected
                    ) {
                        removeTransitionImage(transitionNode);
                        resolve(false);
                        return;
                    }

                    transitionNode.classList.add('is-visible');
                    window.setTimeout(() => {
                        if (
                            !isCurrentLightboxImageRequest(index, token)
                            || activeLightboxTransitionToken !== transitionToken
                            || transitionImage !== transitionNode
                            || !transitionNode.isConnected
                        ) {
                            removeTransitionImage(transitionNode);
                            resolve(false);
                            return;
                        }

                        // The transition is now fully opaque. Rebuild the real zoom surface
                        // underneath it, then keep the cover in place until the new live node
                        // has been painted at the exact same final geometry.
                        commitPreparedLightboxImage(index, token, loadedImage, src, altText, targetMetrics).then((committed) => {
                            if (
                                !committed
                                || !isCurrentLightboxImageRequest(index, token)
                                || activeLightboxTransitionToken !== transitionToken
                                || transitionImage !== transitionNode
                            ) {
                                removeTransitionImage(transitionNode);
                                resolve(false);
                                return;
                            }
                            removeTransitionImage(transitionNode);
                            resolve(true);
                        });
                    }, transitionDuration);
                });
            });
        })).catch(() => {
            if (transitionImage === transitionNode) {
                removeTransitionImage(transitionNode);
            }
            return false;
        });
    }

    /**
     * Present an automatic slideshow image from the exact DOM node that was prepared for display.
     *
     * The full source is already decoded before this function is called. The shared prepared
     * transition path renders it as a stage-centered cover, then rebuilds the real zoom surface
     * underneath that opaque cover from the same target dimensions. The cover remains until the
     * live image has survived its final paint boundaries, so a different aspect ratio cannot
     * expose a second geometry correction after the photograph becomes visible.
     *
     * @param {number} index Zero-based image index.
     * @param {number} token Active lightbox navigation token.
     * @param {HTMLImageElement} loadedImage Detached decoded full-quality image.
     * @param {string} src Authorized full-quality source URL.
     * @param {string} altText Accessible image alternative text.
     * @return {Promise<boolean>} True when the prepared node became the live image.
     */
    function showPreparedLightboxSlideshowImage(index, token, loadedImage, src, altText) {
        return showPreparedLightboxTransitionImage(index, token, loadedImage, src, altText);
    }

    /**
     * Handles show lightbox image source behavior for the gallery UI.
     *
     * @param {*} index Value supplied by the caller or event context.
     * @param {*} token Value supplied by the caller or event context.
     * @param {*} src Value supplied by the caller or event context.
     * @param {*} altText Value supplied by the caller or event context.
     * @param {*} immediate Value supplied by the caller or event context.
     * @param {*} decodedImage Decoded image value.
     * @param {boolean} preparedForSlideshow Whether the decoded image must use the stable automatic-slideshow handoff.
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxImageSource(index, token, src, altText, immediate, decodedImage = null, preparedForSlideshow = false) {
        if (!src) {
            return Promise.resolve(false);
        }
        if (!(decodedImage instanceof HTMLImageElement)) {
            return loadDecodedLightboxImage(src, {priority: 'high'})
                .then((loadedImage) => showLightboxImageSource(
                    index,
                    token,
                    src,
                    altText,
                    immediate,
                    loadedImage,
                    preparedForSlideshow,
                ))
                .catch(() => {
                    const stillCurrent = isCurrentLightboxImageRequest(index, token);
                    if (stillCurrent) {
                        clearLightboxNavigationPending(token);
                    }
                    return false;
                });
        }
        const targetMetrics = prepareDecodedLightboxGeometry(decodedImage);
        if (!targetMetrics || !isCurrentLightboxImageRequest(index, token)) {
            return Promise.resolve(false);
        }
        if (preparedForSlideshow && !immediate) {
            return showPreparedLightboxSlideshowImage(index, token, decodedImage, src, altText);
        }
        if (immediate || !stageLink || !image.getAttribute('src')) {
            activeLightboxTransitionToken += 1;
            removeTransitionImage();
            return commitPreparedLightboxImage(index, token, decodedImage, src, altText, targetMetrics);
        }
        if (image.getAttribute('src') === src) {
            image.alt = altText;
            image.dataset.lightboxImageId = String(cards[index]?.dataset.imageId || index);
            applyLightboxZoomState(false, targetMetrics);
            clearLightboxNavigationPending(token);
            return Promise.resolve(true);
        }
        return showPreparedLightboxTransitionImage(index, token, decodedImage, src, altText);
    }

    /**
     * Parse the server-authorized quality candidates attached to one lightbox card.
     *
     * Legacy cached markup receives a conservative preview/full fallback derived
     * only from its existing protected URLs and known display dimensions.
     *
     * @param {HTMLElement|null} card Active server-rendered or lazy lightbox card.
     * @return {Array<{src:string,width:number,height:number,kind:string}>} Normalized candidates.
     */
    function lightboxQualityCandidatesForCard(card) {
        if (!(card instanceof HTMLElement)) {
            return [];
        }
        let candidates = [];
        try {
            candidates = JSON.parse(card.dataset.lightboxQualitySources || '[]');
        } catch {
            candidates = [];
        }
        const normalized = normalizeLightboxZoomQualityCandidates(candidates);
        if (normalized.length > 0) {
            return normalized;
        }
        const sourceWidth = Math.max(0, Number.parseInt(card.dataset.imageWidth || '0', 10) || 0);
        const sourceHeight = Math.max(0, Number.parseInt(card.dataset.imageHeight || '0', 10) || 0);
        if (!sourceWidth || !sourceHeight) {
            return [];
        }
        const previewSrc = card.dataset.previewSrc || '';
        const fullSrc = card.dataset.fullSrc || previewSrc;
        const previewScale = Math.min(1, 1600 / Math.max(sourceWidth, sourceHeight));
        return normalizeLightboxZoomQualityCandidates([
            previewSrc ? {
                src: previewSrc,
                width: Math.max(1, Math.round(sourceWidth * previewScale)),
                height: Math.max(1, Math.round(sourceHeight * previewScale)),
                kind: 'preview',
            } : null,
            fullSrc ? {src: fullSrc, width: sourceWidth, height: sourceHeight, kind: 'full'} : null,
        ]);
    }

    /**
     * Return the bounded display density used for active-photo quality selection.
     *
     * Data-saving mode avoids multiplying the initial demand by high-DPI density,
     * while deeper zoom can still request the full source when it is necessary.
     *
     * @return {number} Browser density supplied to the pure quality model.
     */
    function lightboxQualityDevicePixelRatio() {
        if (window.navigator?.connection?.saveData) {
            return 1;
        }
        return Math.max(1, Number(window.devicePixelRatio) || 1);
    }

    /**
     * Decode and promote the active photograph when its current source is undersized.
     *
     * @param {number} index Active lightbox index.
     * @param {number} imageToken Navigation generation captured by openAt().
     * @return {Promise<boolean>} True only when a larger source became visible.
     */
    function promoteLightboxQualityIfNeeded(index, imageToken) {
        if (!isCurrentLightboxImageRequest(index, imageToken) || overlay.hidden) {
            return Promise.resolve(false);
        }
        const card = cards[index];
        const imageId = String(card?.dataset.imageId || index);
        if (!(card instanceof HTMLElement) || image.dataset.lightboxImageId !== imageId) {
            return Promise.resolve(false);
        }
        const metrics = measureLightboxZoomMetrics();
        const candidates = lightboxQualityCandidatesForCard(card);
        const requiredWidth = lightboxZoomRequiredSourceWidth(
            metrics.imageWidth,
            lightboxZoomState.scale,
            lightboxQualityDevicePixelRatio(),
        );
        const fullCandidate = lightboxZoomState.scale > LIGHTBOX_ZOOM_MIN_SCALE
            ? (candidates.find((candidate) => candidate.kind === 'full') || candidates[candidates.length - 1] || null)
            : null;
        const desired = fullCandidate || selectLightboxZoomQualityCandidate(candidates, requiredWidth, activeLightboxQualitySource);
        if (!desired || desired.src === activeLightboxQualitySource || desired.src === pendingLightboxQualitySource) {
            return Promise.resolve(false);
        }
        const current = candidates.find((candidate) => candidate.src === activeLightboxQualitySource) || null;
        if (current && desired.width <= current.width) {
            return Promise.resolve(false);
        }
        const failureKey = `${card?.dataset.imageId || index}:${desired.src}`;
        if (failedLightboxQualitySources.has(failureKey)) {
            return Promise.resolve(false);
        }

        activeLightboxQualityRequestToken += 1;
        const qualityToken = activeLightboxQualityRequestToken;
        pendingLightboxQualitySource = desired.src;
        setLightboxQualityLoading(true);
        return loadFreshDecodedLightboxImage(desired.src, {priority: 'high'}).then((loadedImage) => {
            if (
                !isCurrentLightboxImageRequest(index, imageToken)
                || qualityToken !== activeLightboxQualityRequestToken
                || pendingLightboxQualitySource !== desired.src
            ) {
                return false;
            }
            pendingLightboxQualitySource = '';
            return installDecodedLightboxQualityImage(loadedImage, desired.src, card.dataset.title || '', imageId).then((installed) => {
                if (
                    !installed
                    || !isCurrentLightboxImageRequest(index, imageToken)
                    || qualityToken !== activeLightboxQualityRequestToken
                    || image.dataset.lightboxImageId !== imageId
                ) {
                    if (qualityToken === activeLightboxQualityRequestToken) {
                        setLightboxQualityLoading(false);
                    }
                    return false;
                }
                updateNormalLightboxStageSizeFromLoadedImage(loadedImage);
                applyLightboxZoomState(false);
                setLightboxQualityLoading(false);
                return true;
            });
        }).catch(() => {
            if (qualityToken === activeLightboxQualityRequestToken) {
                pendingLightboxQualitySource = '';
                failedLightboxQualitySources.add(failureKey);
                setLightboxQualityLoading(false);
            }
            return false;
        });
    }

    /**
     * Debounce passive active-photo quality evaluation after open or viewport changes.
     *
     * @param {number} delay Delay in milliseconds; zero schedules after the current task.
     */
    function scheduleLightboxQualityUpgrade(delay = lightboxQualityUpgradeDelay) {
        if (pendingLightboxQualityTimer) {
            window.clearTimeout(pendingLightboxQualityTimer);
        }
        const index = currentIndex;
        const imageToken = activeLightboxImageToken;
        pendingLightboxQualityTimer = window.setTimeout(() => {
            pendingLightboxQualityTimer = 0;
            promoteLightboxQualityIfNeeded(index, imageToken);
        }, Math.max(0, delay));
    }

    /**
     * Switch the live lightbox image to the protected original on deliberate zoom.
     *
     * Explicit zoom must change the source on the image that is already visible.
     * The browser therefore starts the original request in the same input task as
     * the scale change instead of waiting for a detached decode, fullscreen change,
     * resize, or passive quality timer. The preview remains the browser's current
     * decoded pixels only until the live element receives the original response.
     *
     * A failed original request restores the authorized preview when one exists.
     * Navigation and close invalidate the request token so late load/error events
     * cannot alter the next photograph.
     *
     * @return {boolean} True when the live image was switched to the original.
     */
    function requestLightboxQualityUpgradeNow() {
        if (lightboxZoomState.scale <= LIGHTBOX_ZOOM_MIN_SCALE || overlay.hidden || currentIndex < 0) {
            return false;
        }
        const card = cards[currentIndex];
        const imageId = String(card?.dataset.imageId || currentIndex);
        const fullSrc = String(card?.dataset.fullSrc || '').trim();
        if (!(card instanceof HTMLElement) || !(image instanceof HTMLImageElement) || !fullSrc) {
            return false;
        }

        activeLightboxTransitionToken += 1;
        removeTransitionImage();
        image.dataset.lightboxImageId = imageId;

        if (pendingLightboxQualityTimer) {
            window.clearTimeout(pendingLightboxQualityTimer);
            pendingLightboxQualityTimer = 0;
        }

        if (
            image.dataset.lightboxExplicitZoomQuality === imageId
            && image.getAttribute('src') === fullSrc
        ) {
            return false;
        }

        activeLightboxQualityRequestToken += 1;
        const qualityToken = activeLightboxQualityRequestToken;
        const index = currentIndex;
        const imageToken = activeLightboxImageToken;
        const previewSrc = String(card.dataset.previewSrc || '').trim();
        const targetImage = image;

        pendingLightboxQualitySource = fullSrc;
        targetImage.dataset.lightboxExplicitZoomQuality = imageId;
        targetImage.loading = 'eager';
        targetImage.decoding = 'async';
        if ('fetchPriority' in targetImage) {
            targetImage.fetchPriority = 'high';
        }
        setLightboxQualityLoading(true);
        devMarkSource(fullSrc, 'loading', 'zoom-live-original');

        /**
         * Remove the paired live-original completion listeners from the active image.
         */
        const clearOriginalListeners = () => {
            targetImage.removeEventListener('load', finishOriginalLoad);
            targetImage.removeEventListener('error', handleOriginalError);
        };

        /**
         * Finalize a successful live original-source load for the active photograph.
         */
        const finishOriginalLoad = () => {
            clearOriginalListeners();
            if (
                image !== targetImage
                || !isCurrentLightboxImageRequest(index, imageToken)
                || qualityToken !== activeLightboxQualityRequestToken
                || targetImage.dataset.lightboxImageId !== imageId
                || targetImage.getAttribute('src') !== fullSrc
            ) {
                return;
            }
            pendingLightboxQualitySource = '';
            activeLightboxQualitySource = fullSrc;
            galleryDevModeState.currentSource = fullSrc;
            galleryDevModeState.currentSourceKind = devFindSourceKind(fullSrc);
            devMarkSource(fullSrc, 'ready', 'zoom-live-original', targetImage);
            updateNormalLightboxStageSizeFromLoadedImage(targetImage);
            applyLightboxZoomState(false);
            setLightboxQualityLoading(false);
        };

        /**
         * Restore the protected preview when the explicit original-source request fails.
         */
        const handleOriginalError = () => {
            clearOriginalListeners();
            if (
                image !== targetImage
                || !isCurrentLightboxImageRequest(index, imageToken)
                || qualityToken !== activeLightboxQualityRequestToken
                || targetImage.dataset.lightboxImageId !== imageId
            ) {
                return;
            }
            pendingLightboxQualitySource = '';
            failedLightboxQualitySources.add(`${imageId}:${fullSrc}`);
            delete targetImage.dataset.lightboxExplicitZoomQuality;
            setLightboxQualityLoading(false);
            if (previewSrc && targetImage.getAttribute('src') === fullSrc) {
                activeLightboxQualitySource = previewSrc;
                targetImage.src = previewSrc;
            }
        };

        targetImage.addEventListener('load', finishOriginalLoad);
        targetImage.addEventListener('error', handleOriginalError);

        // This assignment is deliberately synchronous with the user's zoom input.
        // No application-controlled decode or mode transition sits in front of it.
        activeLightboxQualitySource = fullSrc;
        galleryDevModeState.currentSource = fullSrc;
        galleryDevModeState.currentSourceKind = devFindSourceKind(fullSrc);
        targetImage.src = fullSrc;

        if (targetImage.complete && targetImage.naturalWidth > 0) {
            finishOriginalLoad();
        }
        return true;
    }

        /**
     * Return the nearby-photo radius that is usable for the current viewport and mode.
     *
     * Picture-strip mode shows the same nearby-photo radius in a flat rail.
     * The 3D carousel uses up to three neighbors per side on desktop, then
     * reduces the radius on cramped or touch-first screens so navigation remains
     * usable and the central image stays dominant.
     *
     * @return {number} Number of neighbors to attempt on each side.
     */
    function pictureStripRadius() {
        const viewportWidth = window.visualViewport?.width || window.innerWidth || document.documentElement.clientWidth || 0;
        if (isMobileTouchDevice || viewportWidth <= 520) {
            return 1;
        }
        if (threeDCarouselEnabled) {
            if (viewportWidth <= 900) {
                return 2;
            }
            return 3;
        }
        if (viewportWidth <= 820) {
            return 2;
        }
        return 3;
    }

        /**
     * Return a normalized neighbor index when the gallery can be navigated in a loop.
     *
     * @param {number} index Candidate gallery index.
     * @return {number} Index wrapped into the known card range.
     */
    function normalizeLightboxIndex(index) {
        if (cards.length <= 0) {
            return -1;
        }
        return ((index % cards.length) + cards.length) % cards.length;
    }

        /**
     * Return a small signed offset from the active image to a rendered neighbor.
     *
     * Wrapped galleries need this helper so the last image can appear as the
     * previous neighbor of the first image instead of looking many positions away.
     * CSS uses this value to place carousel cards to the left or right and to scale
     * deeper cards down predictably.
     *
     * @param {number} itemIndex Candidate gallery index.
     * @param {number} centerIndex Active lightbox index.
     * @return {number} Signed relative offset near the active image.
     */
    function lightboxRelativeOffset(itemIndex, centerIndex) {
        if (cards.length <= 0) {
            return itemIndex - centerIndex;
        }
        let offset = itemIndex - centerIndex;
        const half = cards.length / 2;
        if (offset > half) {
            offset -= cards.length;
        } else if (offset < -half) {
            offset += cards.length;
        }
        return offset;
    }


        /**
     * Return presentation variables for one 3D carousel neighbor.
     *
     * CSS receives explicit distance, scale, depth, and blur values instead of
     * deriving every layer from one linear offset. That keeps the inner cards
     * large and readable, while the outer cards stay smaller and farther back.
     * The center image is rendered by the real lightbox stage, so offset zero
     * remains intentionally neutral here.
     *
     * @param {number} relativeOffset Signed distance from the active image.
     * @return {{x: string, scale: string, hoverScale: string, rotate: string, opacity: string, blur: string, brightness: string, depth: string, hoverDepth: string, zIndex: number} }.
     */
    function threeDCarouselPresentation(relativeOffset) {
        const absoluteOffset = Math.min(3, Math.abs(relativeOffset));
        const direction = relativeOffset < 0 ? -1 : 1;
        const layers = {
            0: {x: '0px', scale: '1', hoverScale: '1', rotate: '0deg', opacity: '0', blur: '0px', brightness: '1', depth: '0rem', hoverDepth: '0rem', zIndex: 1},
            1: {x: 'clamp(21rem, 31vw, 36rem)', scale: '1.08', hoverScale: '1.12', rotate: '-7deg', opacity: '0.94', blur: '0.08px', brightness: '0.84', depth: '-2rem', hoverDepth: '-1.25rem', zIndex: 14},
            2: {x: 'clamp(31rem, 43vw, 50rem)', scale: '0.88', hoverScale: '0.925', rotate: '-12deg', opacity: '0.74', blur: '0.55px', brightness: '0.66', depth: '-6rem', hoverDepth: '-4.75rem', zIndex: 9},
            3: {x: 'clamp(40rem, 53vw, 62rem)', scale: '0.68', hoverScale: '0.725', rotate: '-16deg', opacity: '0.52', blur: '1.1px', brightness: '0.5', depth: '-11rem', hoverDepth: '-9rem', zIndex: 5},
        };
        const layer = layers[absoluteOffset] || layers[3];
        const signedX = direction < 0 ? `calc(0px - ${layer.x})` : layer.x;
        const signedRotation = direction < 0 ? layer.rotate.replace('-', '') : layer.rotate;

        return {
            x: signedX,
            scale: layer.scale,
            hoverScale: layer.hoverScale,
            rotate: signedRotation,
            opacity: layer.opacity,
            blur: layer.blur,
            brightness: layer.brightness,
            depth: layer.depth,
            hoverDepth: layer.hoverDepth,
            zIndex: layer.zIndex,
        };
    }

        /**
     * Ensure lazy metadata for the strip neighborhood is available.
     *
     * The strip is visual navigation, so it must tolerate paginated galleries and
     * sparse client caches. Missing neighbors trigger the same lazy JSON endpoint
     * as keyboard and next/previous navigation, then the strip is rebuilt when
     * those items arrive. The function never changes the authoritative order.
     *
     * @param {number} centerIndex Active lightbox index.
     */
    function fetchPictureStripNeighbors(centerIndex) {
        if (!lightboxNeighborBrowserEnabled || cards.length <= 0) {
            return;
        }
        const radius = pictureStripRadius();
        for (let offset = -radius; offset <= radius; offset += 1) {
            const neighborIndex = normalizeLightboxIndex(centerIndex + offset);
            if (neighborIndex >= 0 && !cards[neighborIndex]) {
                fetchLightboxWindowAround(neighborIndex).then((loaded) => {
                    if (loaded && !controller.signal.aborted && currentIndex === centerIndex) {
                        renderPictureStrip(centerIndex, false);
                    }
                });
            }
        }
    }

        /**
     * Preload strip thumbnails and close neighbors without promoting every item to full-size preloading.
     *
     * @param {number} centerIndex Active lightbox index.
     */
    function preloadPictureStripNeighbors(centerIndex) {
        if (!lightboxNeighborBrowserEnabled || shouldLimitLightboxPreloading()) {
            return;
        }
        const radius = pictureStripRadius();
        for (let offset = -radius; offset <= radius; offset += 1) {
            if (offset === 0) {
                continue;
            }
            const neighborIndex = normalizeLightboxIndex(centerIndex + offset);
            const neighborCard = neighborIndex >= 0 ? cards[neighborIndex] : null;
            if (neighborCard) {
                preloadCardLightboxImages(neighborCard, false, {queued: true, reason: 'strip-preview', generation: lightboxPreloadGeneration});
            }
        }
    }

        /**
     * Build one accessible strip thumbnail button for an already loaded card.
     *
     * @param {HTMLElement} card Lightbox metadata source for the thumbnail.
     * @param {number} itemIndex Zero-based lightbox index represented by the button.
     * @param {number} centerIndex Zero-based active lightbox index.
     * @return {HTMLButtonElement} Thumbnail button ready to append into the strip.
     */
    function createPictureStripButton(card, itemIndex, centerIndex) {
        const button = document.createElement('button');
        const relativeOffset = lightboxRelativeOffset(itemIndex, centerIndex);
        const absoluteOffset = Math.abs(relativeOffset);
        button.type = 'button';
        button.className = 'lightbox-strip-item';
        button.dataset.lightboxStripIndex = String(itemIndex);
        button.dataset.stripOffset = String(relativeOffset);
        button.style.setProperty('--lightbox-carousel-offset', String(relativeOffset));
        button.style.setProperty('--lightbox-carousel-abs', String(absoluteOffset));
        if (threeDCarouselEnabled) {
            const presentation = threeDCarouselPresentation(relativeOffset);
            button.style.setProperty('--lightbox-carousel-x', presentation.x);
            button.style.setProperty('--lightbox-carousel-scale', presentation.scale);
            button.style.setProperty('--lightbox-carousel-hover-scale', presentation.hoverScale);
            button.style.setProperty('--lightbox-carousel-rotate', presentation.rotate);
            button.style.setProperty('--lightbox-carousel-opacity', presentation.opacity);
            button.style.setProperty('--lightbox-carousel-blur', presentation.blur);
            button.style.setProperty('--lightbox-carousel-brightness', presentation.brightness);
            button.style.setProperty('--lightbox-carousel-depth', presentation.depth);
            button.style.setProperty('--lightbox-carousel-hover-depth', presentation.hoverDepth);
            button.style.zIndex = String(presentation.zIndex);
        } else {
            button.style.zIndex = String(Math.max(1, 8 - absoluteOffset));
        }
        button.setAttribute('aria-label', i18n(threeDCarouselEnabled ? 'lightbox.3d_carousel_open' : 'lightbox.picture_strip_open', 'Open photo {current} of {total}', {current: itemIndex + 1, total: cards.length}));
        if (itemIndex === centerIndex) {
            button.classList.add('is-active');
            button.setAttribute('aria-current', 'true');
        }
        const img = document.createElement('img');
        img.decoding = 'async';
        img.loading = 'eager';
        img.alt = '';
        img.src = card.dataset.previewSrc || card.dataset.fullSrc || '';
        button.append(img);
        return button;
    }

        /**
     * Render or rerender the picture strip centered on the active image.
     *
     * The strip intentionally rebuilds a small fixed window rather than moving
     * long DOM lists around. Animation state is kept by the last center index and
     * a short-lived class. This preserves keyboard and button navigation behavior
     * while giving visual continuity when the active image changes.
     *
     * @param {number} centerIndex Active zero-based lightbox index.
     * @param {boolean} animate Whether to run the short slide/fade animation.
     */
    function renderPictureStrip(centerIndex, animate = true) {
        if (!lightboxNeighborBrowserEnabled || cards.length <= 1) {
            if (pictureStrip instanceof HTMLElement) {
                pictureStrip.hidden = true;
            }
            return;
        }
        const radius = pictureStripRadius();
        const fragment = document.createDocumentFragment();
        const usedIndexes = new Set();
        for (let offset = -radius; offset <= radius; offset += 1) {
            if (threeDCarouselEnabled && offset === 0) {
                continue;
            }
            const itemIndex = normalizeLightboxIndex(centerIndex + offset);
            if (itemIndex < 0 || usedIndexes.has(itemIndex)) {
                continue;
            }
            usedIndexes.add(itemIndex);
            const card = cards[itemIndex];
            if (!card) {
                const placeholder = document.createElement('span');
                const relativeOffset = lightboxRelativeOffset(itemIndex, centerIndex);
                const absoluteOffset = Math.abs(relativeOffset);
                placeholder.className = 'lightbox-strip-item lightbox-strip-placeholder';
                placeholder.dataset.stripOffset = String(relativeOffset);
                placeholder.style.setProperty('--lightbox-carousel-offset', String(relativeOffset));
                placeholder.style.setProperty('--lightbox-carousel-abs', String(absoluteOffset));
                if (threeDCarouselEnabled) {
                    const presentation = threeDCarouselPresentation(relativeOffset);
                    placeholder.style.setProperty('--lightbox-carousel-x', presentation.x);
                    placeholder.style.setProperty('--lightbox-carousel-scale', presentation.scale);
                    placeholder.style.setProperty('--lightbox-carousel-hover-scale', presentation.hoverScale);
                    placeholder.style.setProperty('--lightbox-carousel-rotate', presentation.rotate);
                    placeholder.style.setProperty('--lightbox-carousel-opacity', presentation.opacity);
                    placeholder.style.setProperty('--lightbox-carousel-blur', presentation.blur);
                    placeholder.style.setProperty('--lightbox-carousel-brightness', presentation.brightness);
                    placeholder.style.setProperty('--lightbox-carousel-depth', presentation.depth);
                    placeholder.style.setProperty('--lightbox-carousel-hover-depth', presentation.hoverDepth);
                    placeholder.style.zIndex = String(presentation.zIndex);
                } else {
                    placeholder.style.zIndex = String(Math.max(1, 8 - absoluteOffset));
                }
                placeholder.setAttribute('aria-hidden', 'true');
                fragment.append(placeholder);
                continue;
            }
            fragment.append(createPictureStripButton(card, itemIndex, centerIndex));
        }

        pictureStrip.hidden = false;
        const direction = pictureStripLastIndex < 0 || centerIndex === pictureStripLastIndex
            ? 0
            : (centerIndex > pictureStripLastIndex ? 1 : -1);
        pictureStripLastIndex = centerIndex;
        pictureStripAnimationToken += 1;
        const animationToken = pictureStripAnimationToken;
        pictureStripTrack.style.setProperty('--lightbox-strip-shift', `${direction * -14}px`);
        overlay.style.setProperty('--lightbox-carousel-direction', String(direction));
        pictureStripTrack.replaceChildren(fragment);
        pictureStripTrack.scrollLeft = Math.max(0, Math.round((pictureStripTrack.scrollWidth - pictureStripTrack.clientWidth) / 2));
        if (!animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        overlay.classList.add('is-picture-strip-animating');
        window.setTimeout(() => {
            if (animationToken === pictureStripAnimationToken) {
                overlay.classList.remove('is-picture-strip-animating');
            }
        }, threeDCarouselEnabled ? 640 : 220);
    }

        /**
     * Synchronize the picture strip after the active lightbox image changes.
     *
     * @param {number} centerIndex Active zero-based lightbox index.
     */
    function syncPictureStrip(centerIndex) {
        if (!lightboxNeighborBrowserEnabled) {
            return;
        }
        renderPictureStrip(centerIndex, true);
        fetchPictureStripNeighbors(centerIndex);
        preloadPictureStripNeighbors(centerIndex);
    }

        /**
     * Notify the optional anonymous telemetry module about a lightbox photo view.
     *
     * @param {*} card Value supplied by the caller or event context.
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
     * @return {boolean} True when both URLs resolve to the same browser URL.
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
     * @return {number} Estimated progress percentage.
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
     * Measure the stage and contained image at its unscaled object-fit dimensions.
     *
     * @return {{viewportWidth: number, viewportHeight: number, imageWidth: number, imageHeight: number, panViewportWidth: number, panViewportHeight: number}} Current zoom metrics.
     */
    function measureLightboxZoomMetricsForDimensions(naturalWidth, naturalHeight) {
        const stageRect = stageLink?.getBoundingClientRect();
        const stagedWidth = Number.parseFloat(stageLink?.style.getPropertyValue('--lightbox-stage-width') || '0') || 0;
        const stagedHeight = Number.parseFloat(stageLink?.style.getPropertyValue('--lightbox-stage-height') || '0') || 0;
        const fullscreenViewportWidth = isLightboxFullscreen()
            ? (window.visualViewport?.width || window.innerWidth || document.documentElement.clientWidth || 0)
            : 0;
        const fullscreenViewportHeight = isLightboxFullscreen()
            ? (window.visualViewport?.height || window.innerHeight || document.documentElement.clientHeight || 0)
            : 0;
        const viewportWidth = Math.max(0, stageRect?.width || stageLink?.clientWidth || stagedWidth || fullscreenViewportWidth || 0);
        const viewportHeight = Math.max(0, stageRect?.height || stageLink?.clientHeight || stagedHeight || fullscreenViewportHeight || 0);
        const resolvedNaturalWidth = Math.max(0, Number(naturalWidth) || 0);
        const resolvedNaturalHeight = Math.max(0, Number(naturalHeight) || 0);
        if (!viewportWidth || !viewportHeight || !resolvedNaturalWidth || !resolvedNaturalHeight) {
            return {
                viewportWidth,
                viewportHeight,
                imageWidth: viewportWidth,
                imageHeight: viewportHeight,
                panViewportWidth: viewportWidth,
                panViewportHeight: viewportHeight,
            };
        }
        const containScale = Math.min(viewportWidth / resolvedNaturalWidth, viewportHeight / resolvedNaturalHeight);
        const imageWidth = resolvedNaturalWidth * containScale;
        const imageHeight = resolvedNaturalHeight * containScale;
        const fullscreen = isLightboxFullscreen();
        return {
            viewportWidth,
            viewportHeight,
            imageWidth,
            imageHeight,
            // Normal lightbox already sizes the stage to the fitted image. Fullscreen
            // uses the complete screen as its stage, including letterbox space. Pan
            // against the fitted 100% image rectangle instead so a wide/tall photo
            // can move on both axes immediately after zooming.
            panViewportWidth: fullscreen ? imageWidth : viewportWidth,
            panViewportHeight: fullscreen ? imageHeight : viewportHeight,
        };
    }

    /**
     * Measure the stage against the currently live image dimensions.
     *
     * @return {{viewportWidth: number, viewportHeight: number, imageWidth: number, imageHeight: number, panViewportWidth: number, panViewportHeight: number}} Current zoom metrics.
     */
    function measureLightboxZoomMetrics() {
        return measureLightboxZoomMetricsForDimensions(image?.naturalWidth || 0, image?.naturalHeight || 0);
    }

    /**
     * Cancel a pending post-layout zoom measurement.
     */
    function cancelLightboxZoomReclamp() {
        if (lightboxZoomReclampFrame) {
            window.cancelAnimationFrame(lightboxZoomReclampFrame);
            lightboxZoomReclampFrame = 0;
        }
    }

    /**
     * Recalculate stage size and clamp zoom after fullscreen or split-layout changes settle.
     */
    function scheduleLightboxZoomReclamp() {
        cancelLightboxZoomReclamp();
        lightboxZoomReclampFrame = window.requestAnimationFrame(() => {
            lightboxZoomReclampFrame = 0;
            if (controller.signal.aborted || overlay.hidden) {
                return;
            }
            updateNormalLightboxStageSize(cards[currentIndex]);
            applyLightboxZoomState(false);
            scheduleLightboxQualityUpgrade();
        });
    }

    /**
     * Synchronize visible scale values, button availability, and the polite announcement.
     *
     * @param {boolean} announce Whether to notify assistive technology about this deliberate change.
     */
    function syncLightboxZoomControls(announce = false) {
        const percentage = lightboxZoomPercentage(lightboxZoomState.scale);
        zoomStatuses.forEach((status) => {
            status.textContent = percentage;
        });
        overlay.querySelectorAll('[data-lightbox-action="zoom-out"]').forEach((button) => {
            button.disabled = lightboxZoomState.scale <= LIGHTBOX_ZOOM_MIN_SCALE;
        });
        overlay.querySelectorAll('[data-lightbox-action="zoom-reset"]').forEach((button) => {
            button.disabled = lightboxZoomState.scale <= LIGHTBOX_ZOOM_MIN_SCALE;
        });
        overlay.querySelectorAll('[data-lightbox-action="zoom-in"]').forEach((button) => {
            button.disabled = lightboxZoomState.scale >= LIGHTBOX_ZOOM_MAX_SCALE;
        });
        if (announce && zoomAnnouncement instanceof HTMLElement) {
            const template = overlay.dataset.lightboxZoomStatusTemplate || 'Zoom {percent}';
            zoomAnnouncement.textContent = template.replace('{percent}', percentage);
        }
    }

    /**
     * Render the current zoom state without changing media sources.
     *
     * The zoom surface is always the actual fitted photograph rectangle, including
     * at 100%. Its center is fixed to the stage center plus the model translation,
     * and width/height grow symmetrically around that center. Keeping this geometry
     * explicit avoids the fullscreen `object-fit` box discontinuity and prevents
     * repeated animated zoom steps from accumulating a top-left or bottom-right drift.
     *
     * A deliberate zoom may change `src` before the new resource has dimensions.
     * Callers can therefore pass geometry measured from the still-loaded preview so
     * the same input task keeps the correct aspect ratio while the original loads.
     *
     * @param {boolean} announce Whether to announce the resulting percentage.
     * @param {{viewportWidth:number,viewportHeight:number,imageWidth:number,imageHeight:number,panViewportWidth?:number,panViewportHeight?:number}|null} measuredMetrics Stable pre-source-change geometry when available.
     */
    function applyLightboxZoomState(announce = false, measuredMetrics = null) {
        const metrics = measuredMetrics || measureLightboxZoomMetrics();
        lightboxZoomState = normalizeLightboxZoomState(lightboxZoomState, metrics);
        const isZoomed = lightboxZoomState.scale > LIGHTBOX_ZOOM_MIN_SCALE;
        const frameWidth = Math.max(1, metrics.imageWidth * lightboxZoomState.scale);
        const frameHeight = Math.max(1, metrics.imageHeight * lightboxZoomState.scale);

        if (zoomSurface instanceof HTMLElement) {
            zoomSurface.style.width = `${frameWidth}px`;
            zoomSurface.style.height = `${frameHeight}px`;
            zoomSurface.style.transform = `translate3d(calc(-50% + ${lightboxZoomState.translateX}px), calc(-50% + ${lightboxZoomState.translateY}px), 0)`;
            if (isZoomed) {
                zoomSurface.style.willChange = 'width, height, transform';
            } else {
                zoomSurface.style.removeProperty('will-change');
            }
            zoomSurface.style.removeProperty('overflow');
        }
        if (image instanceof HTMLImageElement) {
            image.style.width = '100%';
            image.style.height = '100%';
            image.style.maxWidth = 'none';
            image.style.maxHeight = 'none';
            image.style.removeProperty('transform');
            image.style.removeProperty('will-change');
        }
        overlay.classList.toggle('is-zoomed', isZoomed);
        syncLightboxZoomControls(announce);
    }

    /**
     * Briefly animate a discrete button or keyboard scale change.
     */
    function animateLightboxZoomChange() {
        if (lightboxZoomAnimationTimer) {
            window.clearTimeout(lightboxZoomAnimationTimer);
        }
        overlay.classList.add('is-zoom-animating');
        lightboxZoomAnimationTimer = window.setTimeout(() => {
            lightboxZoomAnimationTimer = 0;
            overlay.classList.remove('is-zoom-animating');
        }, 140);
    }

    /**
     * Measure the current rendered image rectangle in stage-relative coordinates.
     *
     * The DOM rectangle is authoritative once the zoom frame has grown or moved.
     * Reusing only the original fitted dimensions would make successive cursor
     * zoom steps drift toward a corner because the anchor would be resolved in an
     * outdated coordinate system.
     *
     * @return {{left:number,top:number,width:number,height:number}|null} Rendered image rectangle relative to the stage.
     */
    function currentLightboxRenderedImageRect() {
        if (!(stageLink instanceof HTMLElement) || !(image instanceof HTMLImageElement)) {
            return null;
        }
        const stageRect = stageLink.getBoundingClientRect();
        const imageRect = image.getBoundingClientRect();
        if (!stageRect.width || !stageRect.height || !imageRect.width || !imageRect.height) {
            return null;
        }
        return {
            left: imageRect.left - stageRect.left,
            top: imageRect.top - stageRect.top,
            width: imageRect.width,
            height: imageRect.height,
        };
    }

    /**
     * Resolve a requested scale against the image rectangle that is visible now.
     *
     * @param {number} scale Requested scale.
     * @param {{x?: number, y?: number}|null} anchor Stage-relative cursor anchor.
     * @param {{viewportWidth:number,viewportHeight:number,imageWidth:number,imageHeight:number,panViewportWidth?:number,panViewportHeight?:number}} metrics Current 100% zoom metrics.
     * @return {{scale:number,translateX:number,translateY:number}} Anchored bounded state.
     */
    function lightboxZoomStateForRenderedAnchor(scale, anchor, metrics) {
        const resolvedAnchor = anchor || {
            x: metrics.viewportWidth / 2,
            y: metrics.viewportHeight / 2,
        };
        const renderedImageRect = currentLightboxRenderedImageRect();
        if (!renderedImageRect) {
            return zoomLightboxStateAtAnchor(lightboxZoomState, scale, resolvedAnchor, metrics);
        }
        return zoomLightboxStateAtRenderedAnchor(
            lightboxZoomState,
            scale,
            resolvedAnchor,
            metrics,
            renderedImageRect,
        );
    }

    /**
     * Resolve a requested scale around the current photograph point under an anchor.
     *
     * The calculation uses only canonical model state plus fitted image dimensions.
     * It deliberately does not read an in-flight animated DOM rectangle, because a
     * rapid sequence of zoom inputs can otherwise mix visual intermediate geometry
     * with already-committed model state and accumulate drift toward a corner.
     *
     * @param {number} scale Requested scale.
     * @param {{x?: number, y?: number}|null} anchor Stage-relative cursor anchor.
     * @param {{viewportWidth:number,viewportHeight:number,imageWidth:number,imageHeight:number,panViewportWidth?:number,panViewportHeight?:number}} metrics Current 100% zoom metrics.
     * @return {{scale:number,translateX:number,translateY:number}} Anchored bounded state.
     */
    function lightboxZoomStateForAnchor(scale, anchor, metrics) {
        const resolvedAnchor = anchor || {
            x: metrics.viewportWidth / 2,
            y: metrics.viewportHeight / 2,
        };
        return zoomLightboxStateAtPhotoAnchor(
            lightboxZoomState,
            scale,
            resolvedAnchor,
            metrics,
        );
    }

    /**
     * Set scale around a viewport-relative anchor and update all zoom controls.
     *
     * @param {number} scale Requested scale.
     * @param {{x?: number, y?: number}|null} anchor Optional viewport-relative anchor; center is the default.
     * @param {boolean} announce Whether to announce the resulting percentage.
     * @param {boolean} animate Whether to use the short discrete-control transition.
     */
    function setLightboxZoomScale(scale, anchor = null, announce = true, animate = true) {
        const metrics = measureLightboxZoomMetrics();
        lightboxZoomState = lightboxZoomStateForAnchor(scale, anchor, metrics);
        if (animate) {
            animateLightboxZoomChange();
        }
        requestLightboxQualityUpgradeNow();
        applyLightboxZoomState(announce, metrics);
    }

    /**
     * Restore the canonical centered 100% state for navigation and teardown.
     *
     * @param {boolean} announce Whether to announce the reset.
     */
    function resetLightboxZoom(announce = false) {
        cancelLightboxZoomReclamp();
        clearLightboxZoomPointers();
        clearLightboxZoomPan();
        lightboxZoomState = createLightboxZoomState();
        if (lightboxZoomAnimationTimer) {
            window.clearTimeout(lightboxZoomAnimationTimer);
            lightboxZoomAnimationTimer = 0;
        }
        overlay.classList.remove('is-zoom-animating', 'is-zoom-panning', 'is-zoomed');
        if (zoomSurface instanceof HTMLElement) {
            zoomSurface.style.removeProperty('transform');
            zoomSurface.style.removeProperty('will-change');
            zoomSurface.style.removeProperty('width');
            zoomSurface.style.removeProperty('height');
            zoomSurface.style.removeProperty('overflow');
        }
        if (image instanceof HTMLImageElement) {
            image.style.removeProperty('width');
            image.style.removeProperty('height');
            image.style.removeProperty('max-width');
            image.style.removeProperty('max-height');
            image.style.removeProperty('transform');
            image.style.removeProperty('will-change');
        }
        syncLightboxZoomControls(announce);
    }

    /**
     * Return whether a keyboard event originated in an editable control.
     *
     * @param {KeyboardEvent} event Keyboard event to inspect.
     * @return {boolean} True when gallery shortcuts must not replace text editing.
     */
    function isLightboxEditableKeyboardTarget(event) {
        const target = event.target instanceof Element ? event.target : null;
        return Boolean(target?.closest('input, textarea, select, [contenteditable="true"]'));
    }

    /**
     * Remember the latest pointer position while it is over the zoomable photo stage.
     *
     * Keeping client coordinates rather than stage-relative coordinates lets the
     * anchor survive fullscreen/layout changes and be resolved against the stage
     * rectangle that exists at the exact moment a later zoom action runs.
     *
     * @param {PointerEvent|MouseEvent|WheelEvent} event Pointer-bearing stage event.
     */
    function rememberLightboxZoomPointerPosition(event) {
        if (!(stageLink instanceof HTMLElement)) {
            lightboxZoomPointerPosition = null;
            return;
        }
        const clientX = Number(event?.clientX);
        const clientY = Number(event?.clientY);
        if (!Number.isFinite(clientX) || !Number.isFinite(clientY)) {
            return;
        }
        lightboxZoomPointerPosition = {clientX, clientY};
    }

    /**
     * Resolve the remembered pointer to the current stage coordinate system.
     *
     * @return {{x:number,y:number}|null} Current viewport-relative zoom anchor, or null when unavailable.
     */
    function currentLightboxZoomPointerAnchor() {
        if (!(stageLink instanceof HTMLElement) || !lightboxZoomPointerPosition) {
            return null;
        }
        const stageRect = stageLink.getBoundingClientRect();
        const {clientX, clientY} = lightboxZoomPointerPosition;
        return {
            x: clientX - stageRect.left,
            y: clientY - stageRect.top,
        };
    }

    /**
     * Return whether a stage event came from a nested interactive control rather than the stage button itself.
     *
     * @param {Element|null} target Event target inside the zoom viewport.
     * @return {boolean} True only for a nested control that zoom must not intercept.
     */
    function isLightboxZoomControlTarget(target) {
        if (!target) {
            return false;
        }
        const interactiveTarget = target.closest('button, a, input, textarea, select, form, [contenteditable="true"]');
        return Boolean(interactiveTarget && interactiveTarget !== stageLink);
    }

    /**
     * Zoom the active photograph around the wheel pointer without hijacking browser page zoom.
     *
     * @param {WheelEvent} event Stage wheel or trackpad event.
     */
    function handleLightboxZoomWheel(event) {
        if (overlay.hidden || initialLightboxLoadActive || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('[data-lightbox-zoom-viewport]') || isLightboxZoomControlTarget(target)) {
            return;
        }
        const metrics = measureLightboxZoomMetrics();
        if (!metrics.viewportWidth || !metrics.viewportHeight) {
            return;
        }
        rememberLightboxZoomPointerPosition(event);
        const pointerAnchor = currentLightboxZoomPointerAnchor();
        const deltaUnit = event.deltaMode === WheelEvent.DOM_DELTA_LINE
            ? 18
            : (event.deltaMode === WheelEvent.DOM_DELTA_PAGE ? metrics.viewportHeight : 1);
        const normalizedDelta = event.deltaY * deltaUnit;
        if (!Number.isFinite(normalizedDelta) || normalizedDelta === 0) {
            return;
        }
        const requestedScale = lightboxZoomState.scale - normalizedDelta * 0.0025;
        const nextState = lightboxZoomStateForAnchor(
            requestedScale,
            pointerAnchor || {x: metrics.viewportWidth / 2, y: metrics.viewportHeight / 2},
            metrics,
        );
        if (nextState.scale === lightboxZoomState.scale) {
            return;
        }
        event.preventDefault();
        lightboxZoomState = nextState;
        requestLightboxQualityUpgradeNow();
        applyLightboxZoomState(true, metrics);
        showLightboxHud();
    }

    /**
     * Release the active zoom-pan pointer and clear its transient cursor state.
     */
    function clearLightboxZoomPan() {
        if (lightboxZoomPan?.captureElement && lightboxZoomPan.pointerId !== null) {
            try {
                lightboxZoomPan.captureElement.releasePointerCapture?.(lightboxZoomPan.pointerId);
            } catch {
                // Pointer capture release is best-effort after navigation or browser cancellation.
            }
        }
        lightboxZoomPan = null;
        overlay.classList.remove('is-zoom-panning');
    }

    /**
     * Start a captured one-pointer pan only when the active photograph is enlarged.
     *
     * @param {PointerEvent} event Stage pointer-down event.
     */
    function startLightboxZoomPan(event) {
        if (overlay.hidden || initialLightboxLoadActive || lightboxZoomState.scale <= LIGHTBOX_ZOOM_MIN_SCALE) {
            return;
        }
        if (event.button !== 0 || event.isPrimary === false) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('[data-lightbox-zoom-viewport]') || isLightboxZoomControlTarget(target)) {
            return;
        }
        event.preventDefault();
        const captureElement = event.currentTarget instanceof Element ? event.currentTarget : stageLink;
        lightboxZoomPan = {
            pointerId: event.pointerId,
            captureElement,
            lastX: event.clientX,
            lastY: event.clientY,
            moved: false,
        };
        overlay.classList.add('is-zoom-panning');
        try {
            captureElement?.setPointerCapture?.(event.pointerId);
        } catch {
            // Pointer capture is optional; window-level cancellation still clears state.
        }
    }

    /**
     * Pan the enlarged photograph by the captured pointer delta.
     *
     * @param {PointerEvent} event Captured pointer-move event.
     */
    function trackLightboxZoomPan(event) {
        if (!lightboxZoomPan || event.pointerId !== lightboxZoomPan.pointerId) {
            return;
        }
        const deltaX = event.clientX - lightboxZoomPan.lastX;
        const deltaY = event.clientY - lightboxZoomPan.lastY;
        lightboxZoomPan.lastX = event.clientX;
        lightboxZoomPan.lastY = event.clientY;
        if (!deltaX && !deltaY) {
            return;
        }
        event.preventDefault();
        lightboxZoomPan.moved = lightboxZoomPan.moved || Math.abs(deltaX) + Math.abs(deltaY) > 2;
        lightboxZoomState = panLightboxZoomState(lightboxZoomState, deltaX, deltaY, measureLightboxZoomMetrics());
        applyLightboxZoomState(false);
        hideLightboxHud();
    }

    /**
     * Finish or cancel a captured zoom pan without triggering fullscreen from the synthetic click.
     *
     * @param {PointerEvent} event Pointer completion event.
     */
    function finishLightboxZoomPan(event) {
        if (!lightboxZoomPan || event.pointerId !== lightboxZoomPan.pointerId) {
            return;
        }
        const moved = lightboxZoomPan.moved;
        clearLightboxZoomPan();
        if (moved) {
            event.preventDefault();
            suppressNextStageClick = true;
        }
        showLightboxHud();
    }

    /**
     * Return distance and midpoint geometry for two tracked pointer positions.
     *
     * @param {{x: number, y: number}} first First pointer position.
     * @param {{x: number, y: number}} second Second pointer position.
     * @return {{distance: number, midpointX: number, midpointY: number}} Pair geometry.
     */
    function lightboxZoomPointerPairGeometry(first, second) {
        const deltaX = second.x - first.x;
        const deltaY = second.y - first.y;
        return {
            distance: Math.max(1, Math.hypot(deltaX, deltaY)),
            midpointX: (first.x + second.x) / 2,
            midpointY: (first.y + second.y) / 2,
        };
    }

    /**
     * Release tracked pinch pointers and remove all transient pinch state.
     */
    function clearLightboxZoomPointers() {
        lightboxZoomPointers.forEach((pointer) => {
            try {
                pointer.captureElement?.releasePointerCapture?.(pointer.pointerId);
            } catch {
                // Ignore capture release failures after cancellation or DOM replacement.
            }
        });
        lightboxZoomPointers.clear();
        lightboxZoomPinch = null;
        overlay.classList.remove('is-zoom-pinching');
    }

    /**
     * Track touch pointers and enter pinch mode when the second pointer arrives.
     *
     * @param {PointerEvent} event Stage pointer-down event.
     */
    function startLightboxZoomPinch(event) {
        if (overlay.hidden || initialLightboxLoadActive || event.pointerType !== 'touch') {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('[data-lightbox-zoom-viewport]') || isLightboxZoomControlTarget(target)) {
            return;
        }
        if (lightboxZoomPointers.size >= 2) {
            event.preventDefault();
            return;
        }
        const captureElement = event.currentTarget instanceof Element ? event.currentTarget : stageLink;
        lightboxZoomPointers.set(event.pointerId, {
            pointerId: event.pointerId,
            captureElement,
            x: event.clientX,
            y: event.clientY,
        });
        try {
            captureElement?.setPointerCapture?.(event.pointerId);
        } catch {
            // Pointer capture is best-effort on older touch browsers.
        }
        if (lightboxZoomPointers.size < 2) {
            return;
        }
        event.preventDefault();
        clearTouchGesture();
        resetMobileSwipeVisuals(false);
        clearLightboxZoomPan();
        lightboxZoomPointers.forEach((pointer) => {
            try {
                pointer.captureElement?.setPointerCapture?.(pointer.pointerId);
            } catch {
                // Re-capturing the first pointer after pan handoff is best-effort.
            }
        });
        const [first, second] = Array.from(lightboxZoomPointers.values()).slice(0, 2);
        const geometry = lightboxZoomPointerPairGeometry(first, second);
        lightboxZoomPinch = {
            startDistance: geometry.distance,
            startMidpointX: geometry.midpointX,
            startMidpointY: geometry.midpointY,
            startState: {...lightboxZoomState},
        };
        overlay.classList.add('is-zoom-pinching');
        hideLightboxHud();
    }

    /**
     * Scale and translate the image around the moving midpoint of an active pinch.
     *
     * @param {PointerEvent} event Tracked touch pointer-move event.
     */
    function trackLightboxZoomPinch(event) {
        const pointer = lightboxZoomPointers.get(event.pointerId);
        if (!pointer) {
            return;
        }
        pointer.x = event.clientX;
        pointer.y = event.clientY;
        if (!lightboxZoomPinch || lightboxZoomPointers.size < 2) {
            return;
        }
        event.preventDefault();
        const [first, second] = Array.from(lightboxZoomPointers.values()).slice(0, 2);
        const geometry = lightboxZoomPointerPairGeometry(first, second);
        const metrics = measureLightboxZoomMetrics();
        const stageRect = stageLink.getBoundingClientRect();
        const requestedScale = lightboxZoomPinch.startState.scale * (geometry.distance / lightboxZoomPinch.startDistance);
        const anchoredState = zoomLightboxStateAtPhotoAnchor(
            lightboxZoomPinch.startState,
            requestedScale,
            {
                x: lightboxZoomPinch.startMidpointX - stageRect.left,
                y: lightboxZoomPinch.startMidpointY - stageRect.top,
            },
            metrics,
        );
        lightboxZoomState = panLightboxZoomState(
            anchoredState,
            geometry.midpointX - lightboxZoomPinch.startMidpointX,
            geometry.midpointY - lightboxZoomPinch.startMidpointY,
            metrics,
        );
        requestLightboxQualityUpgradeNow();
        applyLightboxZoomState(false, metrics);
    }

    /**
     * Remove a completed pinch pointer and leave the resulting bounded zoom state active.
     *
     * @param {PointerEvent} event Pointer completion or cancellation event.
     */
    function finishLightboxZoomPinch(event) {
        const pointer = lightboxZoomPointers.get(event.pointerId);
        if (!pointer) {
            return;
        }
        const wasPinching = Boolean(lightboxZoomPinch);
        try {
            pointer.captureElement?.releasePointerCapture?.(event.pointerId);
        } catch {
            // Ignore capture release failures after browser cancellation.
        }
        lightboxZoomPointers.delete(event.pointerId);
        if (!wasPinching) {
            return;
        }
        event.preventDefault();
        suppressNextStageClick = true;
        lightboxZoomPinch = null;
        overlay.classList.remove('is-zoom-pinching');
        requestLightboxQualityUpgradeNow();
        applyLightboxZoomState(true);
        showLightboxHud();
    }

        /**
     * Open the lightbox at a specific index.
     *
     * @param {number} index Zero-based image index.
     * @param {object} options Optional behavior flags.
     */
    function openAt(index, options = {}) {
        if (cards.length === 0) {
            return;
        }
        const revealHud = options.revealHud !== false;
        clearLightboxSlideshowTimer();
        const normalizedIndex = ((index % cards.length) + cards.length) % cards.length;
        resetLightboxZoom(false);
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
        hideLightboxHelpPanel();
        currentIndex = normalizedIndex;
        galleryDevModeState.currentIndex = normalizedIndex;
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearPendingLightboxQualityUpgrade();
        activeLightboxQualitySource = '';
        removeTransitionImage();
        resetLightboxPreloadQueue();
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
        // previewSrc stores the lightweight thumbnail source used for nearby previews and fallback loading.
        const previewSrc = card.dataset.previewSrc || '';
        // fullSrc stores the protected browser-displayable source available for fallback or quality promotion.
        const fullSrc = card.dataset.fullSrc || previewSrc;
        // mainSrc provides a safe fallback when no generated preview can be displayed.
        const mainSrc = fullSrc || previewSrc;
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
        const navigationRequestedAt = performance.now();
        const rapidManualNavigation = !shouldShowImmediately && !lightboxSlideshowActive && navigationRequestedAt - lastLightboxNavigationRequestedAt <= lightboxRapidNavigationThreshold;
        lastLightboxNavigationRequestedAt = navigationRequestedAt;
        const shouldSwapImmediately = shouldShowImmediately || rapidManualNavigation;
        // slideshowPreparedImage is supplied only by automatic slideshow navigation after the full source has decoded.
        const slideshowPreparedImage = lightboxSlideshowActive
            && options.slideshowPreparedImage instanceof HTMLImageElement
            && String(options.slideshowPreparedSrc || '') === fullSrc
            ? options.slideshowPreparedImage
            : null;
        if (!shouldShowImmediately && !slideshowPreparedImage) {
            scheduleLightboxNavigationPending(normalizedIndex, imageToken, previewSrc || mainSrc);
        }
        if (!slideshowPreparedImage) {
            preloadCardLightboxImages(card, false, {reason: 'current-preview'});
        }
        /**
         * Handle show lightweight preview image before the full media source.
         *
         * Used by browser-side gallery behavior.
         *
         * @return {*} Result value for the caller.
         */
        const showPreviewFirst = () => showLightboxImageSource(normalizedIndex, imageToken, previewSrc, altText, shouldSwapImmediately);
        /**
         * Handle show main media image when no separate preview is available.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {*} loadedImage Loaded image value.
         * @return {*} Result value for the caller.
         */
        const showMainImage = (loadedImage = null) => showLightboxImageSource(
            normalizedIndex,
            imageToken,
            mainSrc,
            altText,
            shouldSwapImmediately,
            loadedImage,
            loadedImage === slideshowPreparedImage,
        );
        const initialMainPromise = slideshowPreparedImage
            ? showMainImage(slideshowPreparedImage)
            : (previewSrc
                ? showPreviewFirst().then((wasDisplayed) => {
                    if (!isCurrentLightboxImageRequest(normalizedIndex, imageToken)) {
                        return false;
                    }
                    if (wasDisplayed) {
                        return true;
                    }
                    return mainSrc
                        ? loadDecodedLightboxImage(mainSrc, {priority: 'high'}).then(showMainImage).catch(() => false)
                        : false;
                })
                : (mainSrc
                    ? (shouldShowImmediately
                        ? showMainImage(null)
                        : loadDecodedLightboxImage(mainSrc, {priority: 'high'}).then(showMainImage))
                    : Promise.resolve(false)));
        Promise.resolve(initialMainPromise).then((wasDisplayed) => {
            if (!wasDisplayed || !isCurrentLightboxImageRequest(normalizedIndex, imageToken)) {
                return;
            }
            clearLightboxNavigationPending(imageToken);
            hideInitialLightboxLoader();
            scheduleLightboxQualityUpgrade(0);
            scheduleLightboxSlideshowNext();
        });
        syncPictureStrip(normalizedIndex);
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            if (!sharedLightboxMapUiAvailable() || !isLightboxFullscreen()) {
                closeLightboxMapSplit();
            } else if (mapPoint) {
                openLightboxPhotoMapSplit(mapPoint, currentLightboxMapTitle(card, i18n('lightbox.map', 'Map')));
            } else if (hasLightboxGalleryMapPayload()) {
                openLightboxGalleryMapSplit(currentLightboxMapTitle(card, currentLightboxGalleryMapTitle(i18n('lightbox.map', 'Map'))));
            } else {
                openLightboxMapUnavailable(currentLightboxMapTitle(card, i18n('lightbox.map', 'Map')));
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
     *
     * @param {number} offset Relative image offset.
     * @param {object} options Optional behavior flags.
     */
    function step(offset, options = {}) {
        if (cards.length === 0) {
            return;
        }
        // nextIndex stores state or configuration for the gallery front-end flow.
        const nextIndex = ((currentIndex + offset) % cards.length + cards.length) % cards.length;
        openAt(nextIndex, options);
    }

        /**
     * Synchronize toolbar and fullscreen map controls with the current item.
     *
     * @param {string} mapPoint Serialized EXIF marker payload for the active photo.
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
     * Incrementing the schedule token also invalidates a cycle whose display
     * timer already elapsed but whose full-size preload is still in flight.
     */
    function clearLightboxSlideshowTimer() {
        lightboxSlideshowScheduleToken += 1;
        if (lightboxSlideshowPreloadController) {
            lightboxSlideshowPreloadController.abort();
            lightboxSlideshowPreloadController = null;
        }
        if (lightboxSlideshowTimer) {
            window.clearTimeout(lightboxSlideshowTimer);
            lightboxSlideshowTimer = null;
        }
    }

        /**
     * Preload and decode the exact full-size source required by one automatic slideshow step.
     *
     * Sparse paginated galleries may not have the next card metadata in memory yet,
     * so the metadata window is filled first. The returned image is detached and
     * decoded, allowing the transition to start without showing a preview or loader.
     *
     * @param {number} index Zero-based slideshow target index.
     * @param {AbortSignal|null} signal Cancellation signal for the single prepared full image.
     * @return {Promise<{index:number,src:string,image:HTMLImageElement}|null>} Prepared full image, or null on failure.
     */
    function prepareLightboxSlideshowImage(index, signal = null) {
        const normalizedIndex = ((index % cards.length) + cards.length) % cards.length;
        const metadataPromise = cards[normalizedIndex]
            ? Promise.resolve(true)
            : fetchLightboxWindowAround(normalizedIndex);
        return metadataPromise.then((loaded) => {
            if (!loaded || controller.signal.aborted || signal?.aborted) {
                return null;
            }
            const card = cards[normalizedIndex];
            if (!(card instanceof HTMLElement)) {
                return null;
            }
            const fullSrc = String(card.dataset.fullSrc || card.dataset.previewSrc || '').trim();
            if (!fullSrc) {
                return null;
            }
            preloadedSources.add(fullSrc);
            devMarkSource(fullSrc, 'preloading', 'slideshow-full');
            return loadFreshDecodedLightboxImage(fullSrc, {priority: 'high', signal})
                .then((loadedImage) => loadedImage instanceof HTMLImageElement
                    ? {index: normalizedIndex, src: fullSrc, image: loadedImage}
                    : null)
                .catch(() => null);
        });
    }

        /**
     * Synchronize visible slideshow controls and overlay state.
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
     * Schedule the next automatic slideshow step after both required gates are ready.
     *
     * The stable display timer and the next full-size image preload begin together.
     * If the image decodes first, it waits for the timer. If the timer expires first,
     * the current photo stays visible until the full image has finished decoding.
     */
    function scheduleLightboxSlideshowNext() {
        clearLightboxSlideshowTimer();
        if (!lightboxSlideshowActive || overlay.hidden || cards.length <= 1) {
            return;
        }
        const scheduledFromIndex = currentIndex;
        const nextIndex = (scheduledFromIndex + 1) % cards.length;
        const scheduleToken = lightboxSlideshowScheduleToken;
        const preloadController = new AbortController();
        lightboxSlideshowPreloadController = preloadController;
        const preparedImagePromise = prepareLightboxSlideshowImage(nextIndex, preloadController.signal).finally(() => {
            if (lightboxSlideshowPreloadController === preloadController) {
                lightboxSlideshowPreloadController = null;
            }
        });
        lightboxSlideshowTimer = window.setTimeout(() => {
            lightboxSlideshowTimer = null;
            preparedImagePromise.then((prepared) => {
                if (
                    scheduleToken !== lightboxSlideshowScheduleToken
                    || !lightboxSlideshowActive
                    || overlay.hidden
                    || currentIndex !== scheduledFromIndex
                ) {
                    return;
                }
                if (!prepared || prepared.index !== nextIndex) {
                    scheduleLightboxSlideshowNext();
                    return;
                }
                hideLightboxHud();
                openAt(nextIndex, {
                    revealHud: false,
                    slideshowPreparedImage: prepared.image,
                    slideshowPreparedSrc: prepared.src,
                });
            });
        }, lightboxSlideshowVisibleDuration);
    }

        /**
     * Start fullscreen slideshow mode.
     */
    async function startLightboxSlideshow() {
        if (lightboxSlideshowActive) {
            scheduleLightboxSlideshowNext();
            return;
        }
        resetLightboxZoom(false);
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
     */
    async function toggleLightboxSlideshow() {
        if (!lightboxSlideshowEnabled) {
            return;
        }
        if (lightboxSlideshowActive) {
            stopLightboxSlideshow();
            showLightboxHud();
            return;
        }
        await startLightboxSlideshow();
    }

    /**
     * Hide the shortcut help panel and synchronize the toggle buttons.
     */
    function hideLightboxHelpPanel() {
        if (lightboxHelpPanel instanceof HTMLElement) {
            lightboxHelpPanel.hidden = true;
        }
        lightboxHelpButtons.forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * Toggle the shortcut help panel from the lightbox toolbar.
     */
    function toggleLightboxHelpPanel() {
        if (!(lightboxHelpPanel instanceof HTMLElement)) {
            return;
        }
        const shouldShow = lightboxHelpPanel.hidden;
        lightboxHelpPanel.hidden = !shouldShow;
        lightboxHelpButtons.forEach((button) => {
            button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
        });
        if (shouldShow) {
            showLightboxHud();
        }
    }

    // Function `close` executes this focused behavior.
    /**
     * Close close.
     */
    function close() {
        telemetryPhotoClosed();
        resetLightboxZoom(false);
        stopLightboxSlideshow();
        hideLightboxHelpPanel();
        exitLightboxFullscreen();
        clearLightboxHudTimer();
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearLightboxNavigationPending();
        resetMobileSwipeVisuals(false);
        overlay.classList.remove('is-ui-visible', 'is-picture-strip-animating');
        if (pictureStripTrack instanceof HTMLElement) {
            pictureStripTrack.replaceChildren();
        }
        if (pictureStrip instanceof HTMLElement) {
            pictureStrip.hidden = !lightboxNeighborBrowserEnabled;
        }
        clearTouchGesture();
        updateLightboxViewportMode();
        hideInitialLightboxLoader();
        overlay.hidden = true;
        clearPendingLightboxQualityUpgrade();
        removeTransitionImage();
        image.removeAttribute('src');
        preloadedSources.clear();
        resetLightboxPreloadQueue();
        cancelActiveDetachedLightboxImageLoads();
        decodedLightboxImages.clear();
        lightboxPendingWindows.clear();
        lightboxGalleryMapPayloadPromises.clear();
        failedLightboxQualitySources.clear();
        activeLightboxQualitySource = '';
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
     *
     * @param {*} card Value supplied by the caller or event context.
     * @param {*} includeFullImage Value supplied by the caller or event context.
     */
    function preloadCardLightboxImages(card, includeFullImage, options = {}) {
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
        const generation = Number.isInteger(options.generation) ? options.generation : lightboxPreloadGeneration;
        const sources = [
            {src: previewSrc, reason: options.reason || 'adjacent-preview'},
            {src: includeFullImage ? fullSrc : '', reason: options.reasonFull || 'adjacent-full'},
        ];
        sources.forEach((item) => {
            if (!item.src) {
                return;
            }
            preloadedSources.add(item.src);
            if (options.queued) {
                queueDecodedLightboxPreload(item.src, item.reason, generation);
                return;
            }
            devMarkSource(item.src, 'preloading', item.reason);
            preloadDecodedLightboxImage(item.src, {signal: lightboxPreloadAbortController.signal});
        });
    }

        /**
     * Return the active nearby-preview preload radius for the current connection.
     *
     * @return {number} Number of next/previous preview images to queue.
     */
    function lightboxActivePreviewPreloadRadius() {
        if (cards.length <= 1) {
            return 0;
        }
        if (shouldLimitLightboxPreloading()) {
            return 1;
        }
        const connection = currentLightboxConnection();
        if (isMobileTouchDevice || connection?.effectiveType === '3g') {
            return Math.min(2, lightboxPreviewPreloadRadius);
        }
        return lightboxPreviewPreloadRadius;
    }

        /**
     * Return the active nearby full-media preload radius for the current connection.
     *
     * @return {number} Number of next/previous full media items to queue.
     */
    function lightboxActiveFullPreloadRadius() {
        if (lightboxFullPreloadRadius <= 0 || shouldLimitLightboxPreloading()) {
            return 0;
        }
        const connection = currentLightboxConnection();
        if (isMobileTouchDevice || connection?.effectiveType === '3g') {
            return 0;
        }
        return lightboxFullPreloadRadius;
    }

        /**
     * Handles preload adjacent images behavior for the gallery UI.
     *
     * @param {*} index Value supplied by the caller or event context.
     */
    function preloadAdjacentImages(index) {
        if (cards.length === 0) {
            return;
        }
        const previewRadius = lightboxActivePreviewPreloadRadius();
        const fullRadius = lightboxActiveFullPreloadRadius();
        const edgeDistance = Math.max(previewRadius + 4, 12);
        const nearStart = index <= edgeDistance;
        const nearEnd = index >= cards.length - edgeDistance - 1;
        if (!cards[index] || nearStart || nearEnd) {
            fetchLightboxWindowAround(index);
        }
        if (previewRadius <= 0) {
            return;
        }
        const generation = lightboxPreloadGeneration;
        // previewOffsets warms likely next steps first, then less likely previous steps.
        const previewOffsets = [];
        for (let distance = 1; distance <= previewRadius; distance += 1) {
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
            preloadCardLightboxImages(card, Math.abs(offset) <= fullRadius, {queued: true, reason: 'adjacent-preview', reasonFull: 'adjacent-full', generation});
        });
    }

        /**
     * Return the browser connection object when the Network Information API is available.
     *
     * @return {NetworkInformation|null} Browser connection details, or null.
     */
    function currentLightboxConnection() {
        return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
    }

        /**
     * Handles should limit lightbox preloading behavior for the gallery UI.
     *
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function shouldLimitLightboxPreloading() {
        // connection stores state or configuration for the gallery front-end flow.
        const connection = currentLightboxConnection();
        if (!connection) {
            return false;
        }
        if (connection.saveData) {
            return true;
        }
        return ['slow-2g', '2g'].includes(connection.effectiveType);
    }

    resetLightboxZoom(false);

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

    previousButtons.forEach((button) => {
        button.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            event.stopPropagation();
        }, {signal: controller.signal});
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            step(-1);
        }, {signal: controller.signal});
    });

    nextButtons.forEach((button) => {
        button.addEventListener('pointerdown', (event) => {
            event.preventDefault();
            event.stopPropagation();
        }, {signal: controller.signal});
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            step(1);
        }, {signal: controller.signal});
    });

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
        if (action === 'zoom-in') {
            event.preventDefault();
            event.stopPropagation();
            setLightboxZoomScale(lightboxZoomState.scale + LIGHTBOX_ZOOM_STEP, currentLightboxZoomPointerAnchor());
            return;
        }
        if (action === 'zoom-out') {
            event.preventDefault();
            event.stopPropagation();
            setLightboxZoomScale(lightboxZoomState.scale - LIGHTBOX_ZOOM_STEP, currentLightboxZoomPointerAnchor());
            return;
        }
        if (action === 'zoom-reset') {
            event.preventDefault();
            event.stopPropagation();
            setLightboxZoomScale(LIGHTBOX_ZOOM_MIN_SCALE);
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
            if (lightboxZoomState.scale > LIGHTBOX_ZOOM_MIN_SCALE) {
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
        if (action === 'fullscreen') {
            event.preventDefault();
            toggleLightboxFullscreen();
            return;
        }
        if (action === 'help') {
            event.preventDefault();
            toggleLightboxHelpPanel();
            return;
        }
        if (action === 'slideshow') {
            event.preventDefault();
            toggleLightboxSlideshow();
            return;
        }
        const stripButton = target?.closest('[data-lightbox-strip-index]');
        if (stripButton instanceof HTMLElement) {
            event.preventDefault();
            const stripIndex = Number.parseInt(stripButton.dataset.lightboxStripIndex || '-1', 10);
            if (Number.isInteger(stripIndex) && stripIndex >= 0 && stripIndex < cards.length) {
                openAt(stripIndex);
            }
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
        stageLink.addEventListener('wheel', handleLightboxZoomWheel, {passive: false, signal: controller.signal});
        stageLink.addEventListener('pointermove', rememberLightboxZoomPointerPosition, {signal: controller.signal});
        stageLink.addEventListener('pointerleave', () => {
            lightboxZoomPointerPosition = null;
        }, {signal: controller.signal});
        if (supportsPointerGestures) {
            stageLink.addEventListener('pointerdown', startLightboxZoomPinch, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointermove', trackLightboxZoomPinch, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointerup', finishLightboxZoomPinch, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointercancel', finishLightboxZoomPinch, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointerdown', startLightboxZoomPan, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointermove', trackLightboxZoomPan, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointerup', finishLightboxZoomPan, {capture: true, signal: controller.signal});
            stageLink.addEventListener('pointercancel', finishLightboxZoomPan, {capture: true, signal: controller.signal});
            window.addEventListener('pointerup', finishLightboxZoomPan, {capture: true, signal: controller.signal});
            window.addEventListener('pointercancel', finishLightboxZoomPan, {capture: true, signal: controller.signal});
            window.addEventListener('pointerup', finishLightboxZoomPinch, {capture: true, signal: controller.signal});
            window.addEventListener('pointercancel', finishLightboxZoomPinch, {capture: true, signal: controller.signal});
        }
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
        renderPictureStrip(currentIndex, false);
        updateFullscreenMapImageFit(cards[currentIndex]);
        applyLightboxZoomState(false);
        scheduleLightboxQualityUpgrade();
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
            return;
        }
        if (!event.altKey && !event.ctrlKey && !event.metaKey && !isLightboxEditableKeyboardTarget(event)) {
            if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                setLightboxZoomScale(lightboxZoomState.scale + LIGHTBOX_ZOOM_STEP, currentLightboxZoomPointerAnchor());
                return;
            }
            if (event.key === '-' || event.key === '_') {
                event.preventDefault();
                setLightboxZoomScale(lightboxZoomState.scale - LIGHTBOX_ZOOM_STEP, currentLightboxZoomPointerAnchor());
                return;
            }
            if (event.key === '0') {
                event.preventDefault();
                setLightboxZoomScale(LIGHTBOX_ZOOM_MIN_SCALE);
                return;
            }
        }
        if (!event.altKey && !event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'x') {
            event.preventDefault();
            close();
            return;
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            step(event.shiftKey ? -10 : -1);
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            step(event.shiftKey ? 10 : 1);
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
    /**
     * Submit lightbox vote.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} value Value to process.
     */
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
     *
     * @return {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxFullscreen() {
        return overlay.classList.contains('is-fullscreen') || overlay.classList.contains('is-mobile-fullscreen');
    }

        /**
     * Handles toggle lightbox fullscreen behavior for the gallery UI.
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
     */
    function enterMobileLightboxFullscreen() {
        prepareMobileLightboxOverlay();
        updateMobileLightboxViewport();
        overlay.classList.add('is-mobile-fullscreen');
        overlay.classList.remove('is-fullscreen');
        document.documentElement.classList.add('has-mobile-lightbox');
        document.body.classList.add('has-mobile-lightbox');
        scheduleLightboxZoomReclamp();
        debugLightbox('enter:mobile-auto');
    }

        /**
     * Handles enter lightbox fullscreen behavior for the gallery UI.
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
                scheduleLightboxZoomReclamp();
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
        scheduleLightboxZoomReclamp();
        clearLightboxStageFocus();
        debugLightbox('exit');
    }

        /**
     * Handles sync lightbox fullscreen state behavior for the gallery UI.
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
            scheduleLightboxZoomReclamp();
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
            scheduleLightboxZoomReclamp();
            debugLightbox('sync:browser-enter');
        }
    }

        /**
     * Handles clear lightbox hud timer behavior for the gallery UI.
     */
    function clearLightboxHudTimer() {
        if (fullscreenHideTimer) {
            clearTimeout(fullscreenHideTimer);
            fullscreenHideTimer = null;
        }
    }

    /**
     * Return whether desktop fullscreen map mode should keep navigation visible.
     *
     * @return {boolean} True when fullscreen split-map mode should keep HUD controls visible.
     */
    function shouldKeepLightboxHudVisible() {
        return isLightboxFullscreen()
            && !overlay.classList.contains('is-mobile-fullscreen')
            && overlay.classList.contains('is-map-split');
    }

    /**
     * Return a human-friendly current map title fallback.
     *
     * @param {HTMLElement|null} card Active lightbox card, when available.
     * @param {string} fallback Final fallback text.
     * @return {string} Human-friendly title for map dialogs and split views.
     */
    function currentLightboxMapTitle(card, fallback = '') {
        const explicitTitle = String(card?.dataset.title || overlay.dataset.currentTitle || '').trim();
        if (explicitTitle !== '') {
            return explicitTitle;
        }
        const galleryTitle = currentLightboxGalleryMapTitle('').trim();
        const counterText = currentIndex >= 0 && cards.length > 0 ? `${currentIndex + 1} / ${cards.length}` : '';
        if (galleryTitle && counterText) {
            return `${galleryTitle}, ${counterText}`;
        }
        if (galleryTitle) {
            return galleryTitle;
        }
        if (counterText) {
            return counterText;
        }
        return fallback;
    }

        /**
     * Hide the fullscreen controls immediately without stopping slideshow playback.
     */
    function hideLightboxHud() {
        clearLightboxHudTimer();
        if (shouldKeepLightboxHudVisible()) {
            overlay.classList.add('is-ui-visible');
            return;
        }
        overlay.classList.remove('is-ui-visible');
    }

        /**
     * Handles show lightbox hud behavior for the gallery UI.
     */
    function showLightboxHud() {
        clearLightboxHudTimer();
        overlay.classList.add('is-ui-visible');
        if (isLightboxFullscreen()) {
            if (shouldKeepLightboxHudVisible()) {
                return;
            }
            fullscreenHideTimer = window.setTimeout(() => {
                if (!shouldKeepLightboxHudVisible()) {
                    overlay.classList.remove('is-ui-visible');
                }
            }, isMobileTouchDevice ? 2400 : 1800);
        } else {
            overlay.classList.remove('is-ui-visible');
        }
    }

        /**
     * Keeps desktop pointer movement behavior without reopening the mobile HUD during a swipe.
     *
     * @param {PointerEvent|MouseEvent} event Browser pointer movement event.
     */
    function showLightboxHudFromPointerMove(event) {
        if (isActiveMobileLightbox() && isMobileLightboxStageEvent(event)) {
            return;
        }
        showLightboxHud();
    }

        /**
     * Toggle mobile fullscreen controls from a deliberate stage tap.
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
     */
    function scheduleHideLightboxHud() {
        if (!isLightboxFullscreen() || shouldKeepLightboxHudVisible()) {
            return;
        }
        clearLightboxHudTimer();
        fullscreenHideTimer = window.setTimeout(() => {
            if (isLightboxFullscreen() && !shouldKeepLightboxHudVisible()) {
                overlay.classList.remove('is-ui-visible');
            }
        }, 1200);
    }

        /**
     * Handles update lightbox viewport mode behavior for the gallery UI.
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
     * @return {boolean} True when mobile Chrome style gestures should be trapped by the lightbox.
     */
    function isActiveMobileLightbox() {
        return isMobileTouchDevice && !overlay.hidden;
    }

        /**
     * Reports whether an event belongs to the photo stage instead of the surrounding document.
     *
     * @param {Event} event Browser event to inspect.
     * @return {boolean} True when the event started from the image swipe surface.
     */
    function isMobileLightboxStageEvent(event) {
        const target = event.target instanceof Element ? event.target : null;
        return Boolean(target?.closest('[data-lightbox-stage]'));
    }

        /**
     * Prevents mobile Chrome from scrolling or swiping the page behind the lightbox.
     *
     * @param {TouchEvent} event Browser touch movement event.
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
     *
     * @param {*} event Value supplied by the caller or event context.
     */
    function startTouchGesture(event) {
        if (!isActiveMobileLightbox() || initialLightboxLoadActive || cards.length <= 1 || lightboxZoomState.scale > LIGHTBOX_ZOOM_MIN_SCALE) {
            return;
        }
        if (event.touches && event.touches.length > 1) {
            return;
        }
        if (event.type === 'pointerdown' && (event.pointerType === 'mouse' || event.button !== 0 || event.isPrimary === false)) {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('[data-lightbox-stage]') || isLightboxZoomControlTarget(target)) {
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
     *
     * @param {*} event Value supplied by the caller or event context.
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
     *
     * @param {*} event Value supplied by the caller or event context.
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
     *
     * @param {*} event Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
     * Handles detect mobile touch device behavior for the gallery UI.
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
     *
     * @param {*} message Value supplied by the caller or event context.
     * @param {*} details Value supplied by the caller or event context.
     */
    function debugLightbox(message, details = {}) {
        if (!isLightboxDebugEnabled) {
            return;
        }
        console.debug('[lightbox]', message, details);
    }

        /**
     * Handles detect lightbox debug flag behavior for the gallery UI.
     *
     * @return {*} Result of the UI operation, when a value is produced.
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
    /**
     * Handle setup gps maps.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} signal Signal value.
     */
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
                await openGalleryMap(galleryButton.dataset.galleryMapUrl || '', galleryButton.dataset.galleryMapTitle || i18n('lightbox.gallery_map', 'Gallery map'));
            }
        }, {capture: true, signal});
    }

    // Function `openPhotoMapFromJson` executes this focused behavior.
    /**
     * Open photo map from json.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} json Json JSON data.
     */
    async function openPhotoMapFromJson(json) {
        if (!json) {
            return;
        }
        try {
            // payload stores the marker or map payload read from a data attribute.
            const payload = JSON.parse(json);
            const mapPayload = await photoMapPayloadWithGalleryRoute(normalizeMapPayload(payload));
            openMapOverlay(mapPayload.title || i18n('lightbox.photo_location', 'Photo location'), mapPayload.points, mapPayload);
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
     * @return {string} Gallery map JSON endpoint, or an empty string.
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
     * @return {string} Human-readable map title.
     */
    function currentLightboxGalleryMapTitle(fallback = i18n('lightbox.gallery_map', 'Gallery map')) {
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
     * @return {boolean} True when a gallery map endpoint is present.
     */
    function hasLightboxGalleryMapPayload() {
        return currentLightboxGalleryMapUrl() !== '';
    }

        /**
     * Return whether the shared map UI can be opened from the current lightbox.
     *
     * @return {boolean} True when EXIF maps are enabled or gallery route data exist.
     */
    function sharedLightboxMapUiAvailable() {
        return lightboxMapsEnabled || hasLightboxGalleryMapPayload();
    }

    // Function `fetchGalleryMapPayload` executes this focused behavior.
    /**
     * Fetch gallery map payload.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {string} url URL used by this workflow.
     * @param {*} title Title value.
     * @return {*} Result value for the caller.
     */
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
            mapPayload.endpointUrl = endpointUrl;
            if (!mapPayload.title) {
                mapPayload.title = title || currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map'));
            }
            return mapPayload;
        }).catch(() => null);
        lightboxGalleryMapPayloadPromises.set(endpointUrl, fetchPromise);
        return fetchPromise;
    }

    // Function `openGalleryMap` executes this focused behavior.
    /**
     * Open gallery map.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {string} url URL used by this workflow.
     * @param {*} title Title value.
     */
    async function openGalleryMap(url = '', title = '') {
        const payload = await fetchGalleryMapPayload(url, title || currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map')));
        if (!payload || !payload.points.length) {
            return;
        }
        openMapOverlay(payload.title || title || currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map')), payload.points, payload);
    }

    // Function `ensureLeaflet` executes this focused behavior.
    /**
     * Ensure leaflet.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
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
    /**
     * Ensure leaflet stylesheet.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
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
    /**
     * Handle configure leaflet marker icon.
     *
     * Used by browser-side gallery behavior.
     */
    function configureLeafletMarkerIcon() {
        if (!window.L || !L.Icon || !L.Icon.Default) {
            return;
        }

        // Leaflet normally detects its default marker image URLs from leaflet.css.
        // The gallery markers below use L.divIcon() and are intentionally independent
        // from these PNG files. Keep explicit upstream URLs only for any legacy/default
        // L.Icon.Default usage elsewhere in the page.
        L.Icon.Default.mergeOptions({
            iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
            iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
            shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        });
    }

    // Function `getGalleryMapMarkerIcon` executes this focused behavior.
    /**
     * Return gallery map marker icon.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} point Point value.
     * @return {*} Result value for the caller.
     */
    function getGalleryMapMarkerIcon(point = {}) {
        if (!window.L || !L.divIcon) {
            return undefined;
        }

        const markerRole = mapPointMarkerRole(point);
        window.galleryMapMarkerIcons = window.galleryMapMarkerIcons || {};
        if (!window.galleryMapMarkerIcons[markerRole]) {
            const isActivePhoto = markerRole === 'active-photo';
            const isRouteVia = markerRole === 'route-via';
            const iconSize = isActivePhoto ? [32, 46] : (isRouteVia ? [8, 8] : [26, 40]);
            const iconAnchor = isActivePhoto ? [16, 39] : (isRouteVia ? [4, 4] : [13, 31]);
            const popupAnchor = isActivePhoto ? [0, -35] : (isRouteVia ? [0, -7] : [0, -27]);
            window.galleryMapMarkerIcons[markerRole] = L.divIcon({
                className: `gallery-leaflet-marker gallery-leaflet-marker--${markerRole}`,
                html: '<span class="gallery-leaflet-marker-shadow" aria-hidden="true"></span><span class="gallery-leaflet-marker-tail" aria-hidden="true"></span><span class="gallery-leaflet-marker-pin" aria-hidden="true"></span>',
                iconAnchor,
                iconSize,
                popupAnchor,
            });
        }

        return window.galleryMapMarkerIcons[markerRole];
    }

    // Runtime-only Leaflet viewport memory. It intentionally dies on page reload.
    const galleryLeafletViewportState = new Map();

    // Runtime-only follow-current-location preference. It starts disabled on every page load.
    const galleryLeafletFollowCurrentLocationState = {enabled: false};

        /**
     * Build a stable runtime key for one map view.
     *
     * @param {string} scope Caller scope, such as overlay or fullscreen-split.
     * @param {*} mapPayload Normalized map payload.
     * @param {Array} points Normalized fallback point list.
     * @return {string} Runtime-only viewport key.
     */
    function mapViewportKey(scope, mapPayload = {}, points = []) {
        const endpoint = String(mapPayload.endpointUrl || mapPayload.endpoint_url || '').trim();
        if (endpoint !== '') {
            return `${scope}:endpoint:${endpoint}`;
        }

        const normalizedPoints = normalizeMapPoints(points.length ? points : (mapPayload.points || []));
        const geometryPoints = normalizeMapPoints(mapPayload.geometry?.points || []);
        const keyPoints = geometryPoints.length > 0 ? geometryPoints : normalizedPoints;
        const coordinates = keyPoints.map((point) => `${point.lat.toFixed(6)},${point.lng.toFixed(6)}`).join('|');
        const sourceType = String(mapPayload.sourceType || mapPayload.source_type || 'map');
        const renderPath = mapPayload.renderPath === true || mapPayload.render_path === true ? 'path' : 'points';
        return `${scope}:${sourceType}:${renderPath}:${coordinates}`;
    }

        /**
     * Return the saved center and zoom for a runtime map view.
     *
     * @param {string} viewKey Runtime viewport key.
     * @return {{center: Array, zoom: number} |null} Saved viewport, or null.
     */
    function savedMapViewport(viewKey) {
        const saved = galleryLeafletViewportState.get(viewKey);
        if (!saved || !Array.isArray(saved.center) || !Number.isFinite(saved.zoom)) {
            return null;
        }
        return saved;
    }

        /**
     * Persist the current user-chosen Leaflet viewport in runtime memory.
     *
     * @param {*} map Leaflet map instance.
     * @param {string} viewKey Runtime viewport key.
     */
    function saveMapViewport(map, viewKey) {
        if (!isUsableLeafletMap(map) || map.galleryViewportSuppressSave) {
            return;
        }
        const center = map.getCenter();
        const zoom = map.getZoom();
        if (!center || !Number.isFinite(center.lat) || !Number.isFinite(center.lng) || !Number.isFinite(zoom)) {
            return;
        }
        map.galleryViewportUserAdjusted = true;
        galleryLeafletViewportState.set(viewKey, {center: [center.lat, center.lng], zoom});
    }

        /**
     * Run a map viewport change without recording it as a user preference.
     *
     * @param {*} map Leaflet map instance.
     * @param {Function} callback Leaflet viewport operation.
     */
    function setMapViewportSilently(map, callback) {
        map.galleryViewportSuppressSave = true;
        try {
            callback();
        } finally {
            window.setTimeout(() => {
                if (map) {
                    map.galleryViewportSuppressSave = false;
                }
            }, 0);
        }
    }

        /**
     * Fit one map to its available coordinates.
     *
     * @param {*} map Leaflet map instance.
     * @param {Array} bounds Leaflet bounds input.
     * @param {*} options fitBounds options.
     */
    function fitMapToBounds(map, bounds, options = {}) {
        if (bounds.length === 1) {
            map.setView(bounds[0], 15, {animate: false});
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, {...options, animate: false});
        }
    }

        /**
     * Return the active photo GPS point that can be kept centered on the map.
     *
     * @param {*} mapPayload Normalized map metadata.
     * @param {Array} points Normalized fallback point list.
     * @return {*|null} Normalized active point, or null.
     */
    function mapCurrentLocationPoint(mapPayload = {}, points = []) {
        const payloadActivePoints = normalizeMapPoints(mapPayload.activePoint ? [mapPayload.activePoint] : []);
        if (payloadActivePoints.length > 0) {
            return payloadActivePoints[0];
        }

        const normalizedPoints = normalizeMapPoints(points.length ? points : (mapPayload.points || []));
        const activePoint = normalizedPoints.find((point) => mapPointMarkerRole(point) === 'active-photo');
        if (activePoint) {
            return activePoint;
        }

        return normalizedPoints.length === 1 ? normalizedPoints[0] : null;
    }

        /**
     * Center the map on one point while preserving the current zoom level.
     *
     * @param {*} map Leaflet map instance.
     * @param {*} point Normalized point with lat/lng values.
     */
    function centerMapOnCurrentLocation(map, point) {
        if (!point || !Number.isFinite(point.lat) || !Number.isFinite(point.lng)) {
            return;
        }
        const zoom = Number.isFinite(map.getZoom()) ? map.getZoom() : 15;
        map.setView([point.lat, point.lng], zoom, {animate: false});
    }

        /**
     * Save the current viewport with a forced center point and the map's current zoom.
     *
     * @param {*} map Leaflet map instance.
     * @param {string} viewKey Runtime viewport key.
     * @param {*} point Normalized point with lat/lng values.
     */
    function saveMapViewportWithCurrentLocation(map, viewKey, point) {
        if (!viewKey || !point || !Number.isFinite(point.lat) || !Number.isFinite(point.lng) || !Number.isFinite(map.getZoom())) {
            return;
        }
        map.galleryViewportUserAdjusted = true;
        galleryLeafletViewportState.set(viewKey, {center: [point.lat, point.lng], zoom: map.getZoom()});
    }

        /**
     * Apply the stored viewport, or the automatic map fit, with optional current-location centering.
     *
     * @param {*} map Leaflet map instance.
     * @param {Array} bounds Leaflet bounds input.
     * @param {*} options fitBounds options.
     * @param {string} viewKey Runtime viewport key.
     * @param {*|null} currentLocationPoint Active photo GPS point.
     */
    function applyMapViewport(map, bounds, options, viewKey = '', currentLocationPoint = null) {
        const saved = viewKey ? savedMapViewport(viewKey) : null;
        const followCurrentLocation = galleryLeafletFollowCurrentLocationState.enabled && currentLocationPoint;

        if (saved && followCurrentLocation) {
            map.setView([currentLocationPoint.lat, currentLocationPoint.lng], saved.zoom, {animate: false});
            return;
        }
        if (saved) {
            map.setView(saved.center, saved.zoom, {animate: false});
            return;
        }

        fitMapToBounds(map, bounds, options);
        if (followCurrentLocation) {
            centerMapOnCurrentLocation(map, currentLocationPoint);
        }
    }

        /**
     * Add explicit reset, zoom in, zoom out, and current-location controls to one Leaflet map.
     *
     * @param {*} map Leaflet map instance.
     * @param {Array} bounds Leaflet bounds input.
     * @param {*} options fitBounds options.
     * @param {Function} isCurrent Guard checking whether this map is still current.
     * @param {string} viewKey Runtime viewport key.
     * @param {*|null} currentLocationPoint Active photo GPS point.
     * @return {void} Result value for the caller.
     */
    function addMapViewportControls(map, bounds, options, isCurrent, viewKey, currentLocationPoint = null) {
        const ViewportControl = L.Control.extend({
            options: {position: 'topleft'},
            /** Build and return the Leaflet viewport-control container. */
            onAdd() {
                const container = L.DomUtil.create('div', 'leaflet-bar gallery-map-viewport-control');
                const resetButton = createMapControlButton(i18n('lightbox.reset', 'Reset'), i18n('lightbox.reset_map_zoom', 'Reset map zoom'), () => {
                    if (!isUsableLeafletMap(map, isCurrent) || bounds.length === 0) {
                        return;
                    }
                    galleryLeafletViewportState.delete(viewKey);
                    map.galleryViewportUserAdjusted = false;
                    map.invalidateSize(false);
                    setMapViewportSilently(map, () => applyMapViewport(map, bounds, options, viewKey, currentLocationPoint));
                });
                const zoomInButton = createMapControlButton('+', i18n('lightbox.zoom_in', 'Zoom in'), () => map.zoomIn());
                const zoomOutButton = createMapControlButton('-', i18n('lightbox.zoom_out', 'Zoom out'), () => map.zoomOut());
                const followControl = createMapCheckboxControl(
                    i18n('lightbox.keep_current_centered', 'Keep current centered'),
                    galleryLeafletFollowCurrentLocationState.enabled,
                    (checked) => {
                        galleryLeafletFollowCurrentLocationState.enabled = checked;
                        if (!checked || !isUsableLeafletMap(map, isCurrent) || !currentLocationPoint) {
                            return;
                        }
                        setMapViewportSilently(map, () => centerMapOnCurrentLocation(map, currentLocationPoint));
                        saveMapViewportWithCurrentLocation(map, viewKey, currentLocationPoint);
                    }
                );
                container.append(resetButton, zoomInButton, zoomOutButton, followControl);
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);
                return container;
            },
        });
        map.addControl(new ViewportControl());
    }

        /**
     * Create one Leaflet viewport control button.
     *
     * @param {string} label Visible button label.
     * @param {string} title Accessible button title.
     * @param {Function} onClick Click handler.
     * @return {HTMLButtonElement} Prepared control button.
     */
    function createMapControlButton(label, title, onClick) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.title = title;
        button.setAttribute('aria-label', title);
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            onClick();
        });
        return button;
    }

        /**
     * Create one Leaflet checkbox control.
     *
     * @param {string} labelText Visible label text.
     * @param {boolean} checked Whether the checkbox starts enabled.
     * @param {Function} onChange Change handler receiving the checked state.
     * @return {HTMLLabelElement} Prepared checkbox label.
     */
    function createMapCheckboxControl(labelText, checked, onChange) {
        const label = document.createElement('label');
        label.className = 'gallery-map-viewport-checkbox';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = checked;
        input.setAttribute('aria-label', labelText);
        const text = document.createElement('span');
        text.textContent = labelText;
        input.addEventListener('change', (event) => {
            event.stopPropagation();
            onChange(input.checked);
        });
        label.addEventListener('click', (event) => event.stopPropagation());
        label.append(input, text);
        return label;
    }

    // Function `ensureLeafletScript` executes this focused behavior.
    /**
     * Ensure leaflet script.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
    function ensureLeafletScript() {
        if (window.L) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            // Variable `existingScript` stores this steps working value.
            const existingScript = document.querySelector('script[data-gallery-leaflet-js]');
            if (existingScript) {
                existingScript.addEventListener('load', () => resolve(), {once: true});
                existingScript.addEventListener('error', () => reject(new Error(i18n('lightbox.leaflet_failed', 'Leaflet failed to load.'))), {once: true});
                return;
            }

            // Variable `script` stores this steps working value.
            const script = document.createElement('script');
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
            script.crossOrigin = '';
            script.dataset.galleryLeafletJs = 'true';
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(i18n('lightbox.leaflet_failed', 'Leaflet failed to load.')));
            document.head.append(script);
        });
    }

    // Function `afterNextPaint` executes this focused behavior.
    /**
     * Handle after next paint.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
    function afterNextPaint() {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(resolve);
            });
        });
    }

    // Function `openMapOverlay` executes this focused behavior.
    /**
     * Open map overlay.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} title Title value.
     * @param {*} points Points value.
     * @param {object} mapPayload Map payload value.
     */
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
            overlay.innerHTML = `<div class="map-dialog"><button type="button" class="map-close" data-map-close>${escapeHtml(i18n('lightbox.close', 'Close'))}</button><h2 data-map-title></h2><div class="map-canvas" data-map-canvas></div><p class="muted map-attribution-note">${escapeHtml(i18n('lightbox.map_attribution_note', 'Map tiles by OpenStreetMap contributors. Heavy production traffic should use a dedicated tile provider.'))}</p></div>`;
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
            zoomControl: false,
        });
        overlay.galleryLeafletMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        // Variable `bounds` stores this steps working value.
        const bounds = renderLeafletMapPayload(map, points, mapPayload);

        const viewKey = mapViewportKey('overlay', mapPayload, points);
        const currentLocationPoint = mapCurrentLocationPoint(mapPayload, points);
        addMapViewportControls(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map, viewKey, currentLocationPoint);
        map.on('zoomend moveend', () => saveMapViewport(map, viewKey));
        setInitialMapViewport(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map, viewKey, currentLocationPoint);
        stabilizeMapAfterLayout(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map, viewKey, currentLocationPoint);
    }

        /**
     * Normalize marker arrays and route payloads into one renderer contract.
     *
     * @param {*} payload Raw JSON payload from PHP or a data attribute.
     * @return {{title: string, points: Array, geometry: *, sourceType: string} }.
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
     * @return {Array} Point list with numeric lat/lng values.
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
     * @return {Array} Leaflet bounds input collected from valid route points.
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
                pane: 'markerPane',
                zIndexOffset: markerRole === 'active-photo' ? 1200 : (markerRole === 'photo' ? 500 : (markerRole === 'route-via' ? 50 : 100)),
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
     * @return {boolean} True when the point list represents route geometry.
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
     * @return {string} Marker role used by CSS and icon caching.
     */
    function mapPointMarkerRole(point) {
        const pointType = String(point?.point_type || point?.type || '').trim();
        const sourceType = String(point?.source_type || point?.map_source_type || '').trim();
        if (point?.active_photo === true || pointType === 'active_photo_point') {
            return 'active-photo';
        }
        if (pointType === 'route_start') {
            return 'route-start';
        }
        if (pointType === 'route_end') {
            return 'route-end';
        }
        if (pointType === 'route_via') {
            return 'route-via';
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
     * @return {boolean} True when the marker IDs match.
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
     * @return {*} Combined payload used by the Leaflet renderer.
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
     * @return {Promise<*>} Photo-only payload or combined route/photo payload.
     */
    async function photoMapPayloadWithGalleryRoute(photoPayload) {
        if (!photoPayload?.points?.length || !hasLightboxGalleryMapPayload()) {
            return photoPayload;
        }
        const galleryPayload = await fetchGalleryMapPayload(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map')));
        if (!galleryPayload || !shouldRenderPathForPayload(galleryPayload, galleryPayload.points || [])) {
            return photoPayload;
        }
        return mergeActivePhotoIntoGalleryRoute(galleryPayload, photoPayload);
    }

        /**
     * Closes the persistent map overlay without changing the current photo viewer.
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
     * @return {string} JSON marker payload, or an empty string when unavailable.
     */
    function lightboxMapPointForCard(card) {
        if (!lightboxMapsEnabled || !(card instanceof HTMLElement)) {
            return '';
        }
        return (card.dataset.mapPoint || '').trim();
    }

        /**
     * Remove any live Leaflet instance and leave the split-map panel ready for new content.
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
        scheduleLightboxZoomReclamp();
        if (lightboxMapSplitTitle) {
            lightboxMapSplitTitle.textContent = title || i18n('lightbox.map', 'Map');
        }
        lightboxMapSplitCanvas.innerHTML = `<div class="lightbox-map-unavailable" role="status"><strong>${escapeHtml(i18n('lightbox.no_gps_title', 'No GPS EXIF data'))}</strong><span>${escapeHtml(i18n('lightbox.no_gps_detail', 'This photo has no coordinates, so the fullscreen map is unavailable for this item.'))}</span></div>`;
        requestAnimationFrame(() => updateFullscreenMapImageFit(cards[currentIndex] || null));
    }

        /**
     * Handles toggle current lightbox map behavior for the gallery UI.
     *
     * @param {*} json Value supplied by the caller or event context.
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
            const mapTitle = currentLightboxMapTitle(card, i18n('lightbox.map', 'Map'));
            if (mapPoint) {
                await toggleLightboxPhotoMapSplit(mapPoint, mapTitle);
            } else if (hasLightboxGalleryMapPayload()) {
                await toggleLightboxGalleryMapSplit(currentLightboxMapTitle(card, currentLightboxGalleryMapTitle(i18n('lightbox.map', 'Map'))));
            } else {
                toggleLightboxMapSplit('', mapTitle);
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
            await openGalleryMap(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map')));
        }
    }

        /**
     * Handles toggle lightbox map split behavior for the gallery UI.
     *
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
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
     */
    async function openLightboxGalleryMapSplit(title) {
        const payload = await fetchGalleryMapPayload(currentLightboxGalleryMapUrl(), currentLightboxGalleryMapTitle(title || i18n('lightbox.gallery_map', 'Gallery map')));
        if (!payload || !payload.points.length) {
            openLightboxMapUnavailable(title || i18n('lightbox.map', 'Map'));
            return;
        }
        await openLightboxMapSplit(JSON.stringify(payload), payload.title || title || currentLightboxGalleryMapTitle(i18n('lightbox.gallery_map', 'Gallery map')));
    }

        /**
     * Handles open lightbox map split behavior for the gallery UI.
     *
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
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
        lightboxMapSplitTitle.textContent = title || i18n('lightbox.map', 'Map');
        overlay.classList.add('is-map-split');
        overlay.classList.remove('is-map-split-disabled');
        scheduleLightboxZoomReclamp();
        requestAnimationFrame(() => updateFullscreenMapImageFit(cards[currentIndex] || null));
        await waitForElementSize(lightboxMapSplitCanvas);
        lightboxMapSplitCanvas.innerHTML = '';
        // map stores state or configuration for the gallery front-end flow.
        const map = L.map(lightboxMapSplitCanvas, {
            fadeAnimation: false,
            markerZoomAnimation: false,
            zoomAnimation: false,
            zoomControl: false,
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
        const viewKey = mapViewportKey('fullscreen-split', mapPayload, points);
        const currentLocationPoint = mapCurrentLocationPoint(mapPayload, points);
        addMapViewportControls(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map, viewKey, currentLocationPoint);
        map.on('zoomend moveend', () => saveMapViewport(map, viewKey));
        setInitialMapViewport(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map, viewKey, currentLocationPoint);
        stabilizeMapAfterLayout(map, bounds, {padding: [24, 24]}, () => overlay.galleryLeafletSplitMap === map, viewKey, currentLocationPoint);
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
     *
     * @param {*} map Value supplied by the caller or event context.
     * @param {*} isCurrent Value supplied by the caller or event context.
     * @return {*} Result of the UI operation, when a value is produced.
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
    /**
     * Set initial map viewport.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} map Map value.
     * @param {*} bounds Bounds value.
     * @param {object} options Optional behavior flags.
     * @param {boolean} isCurrent Is current flag.
     * @param {string} viewKey View key value.
     * @param {*} currentLocationPoint Current location point value.
     */
    function setInitialMapViewport(map, bounds, options, isCurrent = () => true, viewKey = '', currentLocationPoint = null) {
        requestAnimationFrame(() => {
            if (!isUsableLeafletMap(map, isCurrent) || bounds.length === 0) {
                return;
            }
            try {
                map.invalidateSize(false);
                setMapViewportSilently(map, () => applyMapViewport(map, bounds, options, viewKey, currentLocationPoint));
            } catch {
                // Leaflet can briefly expose a stale map pane while overlays are
                // being recreated. Later stabilization passes will retry.
            }
        });
    }

    // Function `stabilizeMapAfterLayout` executes this focused behavior.
    /**
     * Handle stabilize map after layout.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} map Map value.
     * @param {*} bounds Bounds value.
     * @param {object} options Optional behavior flags.
     * @param {boolean} isCurrent Is current flag.
     * @param {string} viewKey View key value.
     * @param {*} currentLocationPoint Current location point value.
     */
    function stabilizeMapAfterLayout(map, bounds, options, isCurrent = () => true, viewKey = '', currentLocationPoint = null) {
        // refreshDelays stores state or configuration for the gallery front-end flow.
        const refreshDelays = [0, 60, 150, 350];
        refreshDelays.forEach((delay) => {
            window.setTimeout(() => {
                if (!isUsableLeafletMap(map, isCurrent)) {
                    return;
                }
                if (map.galleryViewportUserAdjusted) {
                    map.invalidateSize(false);
                    return;
                }
                setInitialMapViewport(map, bounds, options, isCurrent, viewKey, currentLocationPoint);
            }, delay);
        });
    }

        /**
     * Handles wait for element size behavior for the gallery UI.
     *
     * @param {*} element Value supplied by the caller or event context.
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
        scheduleLightboxZoomReclamp();
        if (lightboxMapSplitCanvas) {
            lightboxMapSplitCanvas.innerHTML = '';
        }
    }

        /**
     * Parse a serialized map marker or route payload.
     *
     * @param {*} json Value supplied by the caller or event context.
     * @return {{title: string, points: Array, geometry: *, sourceType: string} }.
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
    /**
     * Handle map popup html.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} point Point value.
     * @return {string} Text result for the caller.
     */
    function mapPopupHtml(point) {
        // Variable `title` stores this steps working value.
        const title = escapeHtml(point.title || point.name || i18n('lightbox.map_point', 'Map point'));
        // Variable `description` stores this steps working value.
        const description = point.description ? `<p>${escapeHtml(point.description)}</p>` : '';
        // Variable `thumb` stores this steps working value.
        const thumb = point.thumb ? `<img decoding="async" loading="lazy" src="${escapeAttribute(point.thumb)}" alt="">` : '';
        // Variable `image` stores this steps working value.
        const image = point.image ? `<p><a href="${escapeAttribute(point.image)}">${escapeHtml(i18n('lightbox.open_photo', 'Open photo'))}</a></p>` : '';
        return `<div class="map-popup">${thumb}<h3>${title}</h3>${description}${image}</div>`;
    }

    // Function `escapeHtml` executes this focused behavior.
    /**
     * Escape html.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} value Value to process.
     * @return {*} Result value for the caller.
     */
    function escapeHtml(value) {
        return String(value).replace(/[&<>"]/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[character]));
    }

    // Function `escapeAttribute` executes this focused behavior.
    /**
     * Escape attribute.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} value Value to process.
     * @return {object} Object result for the caller.
     */
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
