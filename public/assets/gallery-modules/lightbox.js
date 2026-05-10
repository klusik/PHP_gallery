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

export function setupTagSuggestions() {
    // Tag fields still store comma-separated text, but this small helper makes
    // reused tags discoverable while the admin types.
    document.querySelectorAll('[data-tag-input]').forEach((input) => {
        // Variable `list` stores this steps working value.
        const list = document.querySelector(`#${input.getAttribute('list')}`);
        // Variable `names` stores this steps working value.
        const names = list ? Array.from(list.options).map((option) => option.value) : [];
        // Variable `suggestions` stores this steps working value.
        const suggestions = document.createElement('div');
        suggestions.className = 'tag-suggestions';
        input.insertAdjacentElement('afterend', suggestions);

        // Function `currentPrefix` executes this focused behavior.
        function currentPrefix() {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(',');
            return parts[parts.length - 1].trim().toLowerCase();
        }

        // Function `choose` executes this focused behavior.
        function choose(name) {
            // Variable `parts` stores this steps working value.
            const parts = input.value.split(',');
            parts[parts.length - 1] = ` ${name}`;
            input.value = parts.map((part) => part.trim()).filter(Boolean).join(', ');
            suggestions.innerHTML = '';
            input.focus();
        }

        input.addEventListener('input', () => {
            // Variable `prefix` stores this steps working value.
            const prefix = currentPrefix();
            suggestions.innerHTML = '';
            if (!prefix) {
                return;
            }
            names.filter((name) => name.toLowerCase().startsWith(prefix)).slice(0, 6).forEach((name) => {
                // Variable `button` stores this steps working value.
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = name;
                button.addEventListener('click', () => choose(name));
                suggestions.append(button);
            });
        });
    });
}

export function setupGalleryLightbox() {
    // Lightbox state is derived from a dedicated ordered source list when pagination is active.
    // Normal visible image links remain valid when JavaScript is unavailable.
    const visibleLightboxCards = Array.from(document.querySelectorAll('[data-lightbox-image]'));
    // lightboxSourceCards stores the complete gallery order used by fullscreen navigation.
    const lightboxSourceCards = Array.from(document.querySelectorAll('[data-lightbox-source]'));
    // cards stores the authoritative image order for the viewer.
    let cards = lightboxSourceCards.length > 0 ? lightboxSourceCards : visibleLightboxCards;
    // Variable `overlay` stores this steps working value.
    const overlay = document.querySelector('[data-lightbox]');

    // GPS map buttons can exist on public cards even if the lightbox markup is
    // absent, so this listener is registered before the lightbox early return.
    setupGpsMaps();

    if (!overlay || cards.length === 0) {
        return;
    }

    /**
     * Refreshes the lightbox order after an admin reorders visible photo cards.
     *
     * The clickable handlers stay attached to the same DOM nodes, but navigation
     * must read the new DOM order so Next and Previous match the saved gallery
     * order without requiring a page reload.
     *
     * @returns {void}
     */
    function refreshLightboxOrderFromDom() {
        const nextVisibleCards = Array.from(document.querySelectorAll('[data-lightbox-image]'));
        const nextSourceCards = Array.from(document.querySelectorAll('[data-lightbox-source]'));
        cards = nextSourceCards.length > 0 ? nextSourceCards : nextVisibleCards;
    }

    document.addEventListener('publicGalleryPhotoOrderChanged', refreshLightboxOrderFromDom);

    // Variable `image` stores this steps working value.
    const image = overlay.querySelector('[data-lightbox-img]');
    // stageLink stores state or configuration for the gallery front-end flow.
    const stageLink = image ? image.closest('.lightbox-stage-link') : null;
    // lightboxImageTransitionDuration stores state or configuration for the gallery front-end flow.
    const lightboxImageTransitionDuration = 80;
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
    // Variable `score` stores this steps working value.
    const score = overlay.querySelector('[data-lightbox-score]');
    // counter stores state or configuration for the gallery front-end flow.
    const counter = overlay.querySelector('[data-lightbox-counter]');
    // Variable `lightboxVoteForm` stores this steps working value.
    const lightboxVoteForm = overlay.querySelector('[data-lightbox-vote-form]');
    // Variable `lightboxVoteIndicator` stores this steps working value.
    const lightboxVoteIndicator = overlay.querySelector('[data-lightbox-vote-indicator]');
    // Variable `lightboxMapButton` stores this steps working value.
    const lightboxMapButton = overlay.querySelector('[data-lightbox-map]');
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
    // Variable `preloadedSources` stores this steps working value.
    const preloadedSources = new Set();
    // decodedLightboxImages stores state or configuration for the gallery front-end flow.
    const decodedLightboxImages = new Map();
    // fullscreenHideTimer stores state or configuration for the gallery front-end flow.
    let fullscreenHideTimer = null;
    // touchGesture stores state or configuration for the gallery front-end flow.
    let touchGesture = null;
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
    };
    setupGalleryDevModeOverlay();
    // supportsPointerGestures stores state or configuration for the gallery front-end flow.
    const supportsPointerGestures = Boolean(window.PointerEvent);
    // isLightboxDebugEnabled stores state or configuration for the gallery front-end flow.
    const isLightboxDebugEnabled = detectLightboxDebugFlag();
    overlay.classList.toggle('is-mobile-device', isMobileTouchDevice);
    window.__LIGHTBOX_DEBUG__ = isLightboxDebugEnabled;

    // Function `syncLightboxVote` executes this focused behavior.
    function syncLightboxVote(card) {
        if (!lightboxVoteForm || lightboxVoteForm.closest('[hidden]')) {
            return;
        }
        // Variable `vote` stores this steps working value.
        const vote = card.dataset.userVote === '1' ? '1' : '0';
        lightboxVoteForm.querySelector('input[name="image_id"]').value = card.dataset.imageId || '';
        score.dataset.scoreFor = card.dataset.imageId || '';
        updateLightboxVoteButtons(vote);
        updateLightboxVoteIndicator(vote);
    }

    // Function `updateLightboxVoteButtons` executes this focused behavior.
    function updateLightboxVoteButtons(vote) {
        if (!lightboxVoteForm) {
            return;
        }
        lightboxVoteForm.querySelectorAll('button[name="vote"]').forEach((button) => {
            // Variable `active` stores this steps working value.
            const active = button.value === vote;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    // Function `updateLightboxVoteIndicator` executes this focused behavior.
    function updateLightboxVoteIndicator(vote) {
        if (!lightboxVoteIndicator) {
            return;
        }
        lightboxVoteIndicator.classList.toggle('is-up', vote === '1');
        lightboxVoteIndicator.textContent = vote === '1' ? 'Liked' : 'No like';
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
            devRegisterSource(card.dataset.previewSrc || card.dataset.fullSrc || '', 'preview', index, 'idle');
            devRegisterSource(card.dataset.fullSrc || card.dataset.previewSrc || '', 'full', index, 'idle');
        });
        requestAnimationFrame(devFrameTick);
        window.setInterval(renderGalleryDevModeOverlay, 350);
        renderGalleryDevModeOverlay();
    }

    /**
     * Handles dev frame tick behavior for the gallery UI.
     * @param {*} timestamp Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function devFrameTick(timestamp) {
        if (!galleryDevModeEnabled) {
            return;
        }
        if (galleryDevModeState.lastFrameAt > 0) {
            galleryDevModeState.frameMs = timestamp - galleryDevModeState.lastFrameAt;
        }
        galleryDevModeState.lastFrameAt = timestamp;
        requestAnimationFrame(devFrameTick);
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
        return cards.findIndex((card) => card.dataset.previewSrc === src || card.dataset.fullSrc === src);
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
        // availableHeight stores state or configuration for the gallery front-end flow.
        const availableHeight = Math.max(180, window.innerHeight * 0.78);
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
            transitionImage = transitionNode;
            stageLink.append(transitionNode);
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
                }, lightboxImageTransitionDuration);
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

    // Function `openAt` executes this focused behavior.
    function openAt(index) {
        // Variable `card` stores this steps working value.
        const card = cards[index];
        if (!card) {
            return;
        }
        currentIndex = index;
        galleryDevModeState.currentIndex = index;
        activeLightboxImageToken += 1;
        activeLightboxTransitionToken += 1;
        clearPendingFullImageSwap();
        // imageToken stores state or configuration for the gallery front-end flow.
        const imageToken = activeLightboxImageToken;
        // pageUrl stores state or configuration for the gallery front-end flow.
        const pageUrl = card.dataset.pageUrl || '';
        // galleryUrl stores state or configuration for the gallery front-end flow.
        const galleryUrl = card.dataset.galleryUrl || window.location.href;
        if (!lightboxHistoryActive) {
            lightboxReturnUrl = galleryUrl;
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
        updateNormalLightboxStageSize(card);
        // shouldShowImmediately stores state or configuration for the gallery front-end flow.
        const shouldShowImmediately = overlay.hidden || !image.getAttribute('src');
        preloadCardLightboxImages(card, true);
        showLightboxImageSource(index, imageToken, previewSrc, altText, shouldShowImmediately).then((wasDisplayed) => {
            if (!wasDisplayed || currentIndex !== index || activeLightboxImageToken !== imageToken) {
                return;
            }
            swapLightboxImageAfterDecode(index, imageToken, previewSrc, fullSrc, altText);
        });
        title.textContent = card.dataset.title || '';
        description.textContent = card.dataset.description || 'No description.';
        score.textContent = card.dataset.score || '0';
        if (counter) {
            counter.textContent = `${index + 1} / ${cards.length}`;
        }
        overlay.dataset.currentImageId = card.dataset.imageId || '';
        overlay.dataset.currentTitle = card.dataset.title || '';
        syncLightboxVote(card);
        if (lightboxMapButton) {
            // hasMapPoint stores state or configuration for the gallery front-end flow.
            const hasMapPoint = Boolean(card.dataset.mapPoint && card.dataset.mapPoint.trim());
            lightboxMapButton.hidden = !hasMapPoint;
            lightboxMapButton.dataset.mapPoint = hasMapPoint ? card.dataset.mapPoint.trim() : '';
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            // mapPoint stores state or configuration for the gallery front-end flow.
            const mapPoint = card.dataset.mapPoint || '';
            if (mapPoint.trim()) {
                openLightboxMapSplit(mapPoint, card.dataset.title || title.textContent || 'Map');
            } else if (lightboxMapSplitTitle) {
                lightboxMapSplitTitle.textContent = card.dataset.title || title.textContent || 'Map';
            }
        }
        preloadAdjacentImages(index);
        overlay.hidden = false;
        document.body.classList.add('has-lightbox');
        updateLightboxViewportMode();
        showLightboxHud();
        telemetryPhotoOpened(card);
    }

    /**
     * Handles step behavior for the gallery UI.
     * @param {*} offset Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function step(offset) {
        if (cards.length === 0) {
            return;
        }
        // nextIndex stores state or configuration for the gallery front-end flow.
        const nextIndex = (currentIndex + offset + cards.length) % cards.length;
        openAt(nextIndex);
    }

    // Function `close` executes this focused behavior.
    function close() {
        telemetryPhotoClosed();
        exitLightboxFullscreen();
        clearLightboxHudTimer();
        overlay.classList.remove('is-ui-visible');
        clearTouchGesture();
        updateLightboxViewportMode();
        overlay.hidden = true;
        clearPendingFullImageSwap();
        removeTransitionImage();
        image.removeAttribute('src');
        galleryDevModeState.currentSource = '';
        galleryDevModeState.currentSourceKind = '';
        galleryDevModeState.currentIndex = -1;
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
        // previewSrc stores state or configuration for the gallery front-end flow.
        const previewSrc = card.dataset.previewSrc || card.dataset.fullSrc || '';
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

    // clickableLightboxCards stores visible cards plus hidden ordered sources used by direct image URLs.
    const clickableLightboxCards = lightboxSourceCards.length > 0 ? visibleLightboxCards.concat(lightboxSourceCards) : cards;
    clickableLightboxCards.forEach((card) => {
        card.addEventListener('click', (event) => {
            if (event.target.closest('form, [data-admin-inline-editor], [data-public-admin-card-action], [data-gallery-side-panel-link], [data-photo-map], [data-gallery-map-url]')) {
                return;
            }
            // index stores the card position in the complete viewer order.
            const index = cards.findIndex((candidate) => candidate.dataset.imageId === card.dataset.imageId);
            if (index < 0) {
                return;
            }
            event.preventDefault();
            openAt(index);
        });
    });

    overlay.addEventListener('click', (event) => {
        // target stores state or configuration for the gallery front-end flow.
        const target = event.target instanceof Element ? event.target : null;
        // actionTarget stores state or configuration for the gallery front-end flow.
        const actionTarget = target?.closest('[data-lightbox-action]');
        // Variable `action` stores this steps working value.
        const action = actionTarget?.dataset.lightboxAction;
        if (target?.closest('[data-lightbox-stage]')) {
            event.preventDefault();
            clearLightboxStageFocus();
            toggleLightboxFullscreen().finally(clearLightboxStageFocus);
            return;
        }
        if (action === 'close' || event.target === overlay) {
            close();
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
        // mapButton stores state or configuration for the gallery front-end flow.
        const mapButton = target?.closest('[data-lightbox-map]');
        if (mapButton) {
            event.preventDefault();
            if (isLightboxFullscreen()) {
                toggleLightboxMapSplit(mapButton.dataset.mapPoint || '', overlay.dataset.currentTitle || '');
            } else {
                openPhotoMapFromJson(mapButton.dataset.mapPoint || '');
            }
        }
    });

    if (lightboxMapSplitClose) {
        lightboxMapSplitClose.addEventListener('click', closeLightboxMapSplit);
    }

    if (stageLink) {
        stageLink.addEventListener('mousedown', (event) => {
            if (event.button === 0) {
                event.preventDefault();
            }
        });
    }

    overlay.addEventListener('mousemove', showLightboxHud);
    overlay.addEventListener('pointermove', showLightboxHud);
    overlay.addEventListener('mouseleave', scheduleHideLightboxHud);
    if (supportsPointerGestures) {
        overlay.addEventListener('pointerdown', startTouchGesture);
        overlay.addEventListener('pointermove', trackTouchGesture);
        overlay.addEventListener('pointerup', finishTouchGesture);
        overlay.addEventListener('pointercancel', clearTouchGesture);
    } else {
        overlay.addEventListener('touchstart', startTouchGesture, {passive: false});
        overlay.addEventListener('touchmove', trackTouchGesture, {passive: false});
        overlay.addEventListener('touchend', finishTouchGesture, {passive: false});
        overlay.addEventListener('touchcancel', clearTouchGesture);
    }
    overlay.addEventListener('fullscreenchange', syncLightboxFullscreenState);
    document.addEventListener('fullscreenchange', syncLightboxFullscreenState);
    window.addEventListener('resize', () => {
        if (overlay.hidden || currentIndex < 0) {
            return;
        }
        updateNormalLightboxStageSize(cards[currentIndex]);
    });

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
            step(-1);
        }
        if (event.key === 'ArrowRight') {
            step(1);
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            submitLightboxVote(1);
        }
        if (event.key === 'f' || (event.key === 'F' && event.shiftKey === false) || ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f')) {
            event.preventDefault();
            toggleLightboxFullscreen();
        }
    });

    // Function `submitLightboxVote` executes this focused behavior.
    function submitLightboxVote(value) {
        if (!lightboxVoteForm || lightboxVoteForm.closest('[hidden]')) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = lightboxVoteForm.querySelector(`button[name="vote"][value="${value}"]`);
        if (button) {
            button.click();
        }
    }

    /**
     * Handles is lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function isLightboxFullscreen() {
        return overlay.classList.contains('is-fullscreen');
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
            await exitLightboxFullscreen();
            debugLightbox('toggle:exit');
            return;
        }
        await enterLightboxFullscreen();
        debugLightbox('toggle:enter');
    }

    /**
     * Handles enter lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function enterLightboxFullscreen() {
        overlay.classList.add('is-fullscreen');
        overlay.classList.remove('is-ui-visible');
        if (isMobileTouchDevice) {
            overlay.classList.add('is-mobile-fullscreen');
            document.body.classList.add('has-mobile-lightbox');
            debugLightbox('enter:mobile-css');
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
        overlay.classList.add('is-mobile-fullscreen');
        document.body.classList.add('has-mobile-lightbox');
        debugLightbox('enter:fallback-css');
        showLightboxHud();
    }

    /**
     * Handles exit lightbox fullscreen behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function exitLightboxFullscreen() {
        overlay.classList.remove('is-fullscreen');
        overlay.classList.remove('is-mobile-fullscreen');
        closeLightboxMapSplit();
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
            document.body.classList.remove('has-mobile-lightbox');
            clearLightboxStageFocus();
            debugLightbox('sync:browser-exit');
            return;
        }
        if (document.fullscreenElement === overlay) {
            overlay.classList.add('is-fullscreen');
            overlay.classList.remove('is-mobile-fullscreen');
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
     * Handles show lightbox hud behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function showLightboxHud() {
        clearLightboxHudTimer();
        overlay.classList.add('is-ui-visible');
        if (isLightboxFullscreen()) {
            fullscreenHideTimer = window.setTimeout(() => {
                overlay.classList.remove('is-ui-visible');
            }, 1800);
        } else {
            overlay.classList.remove('is-ui-visible');
        }
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
        document.body.classList.toggle('has-mobile-lightbox', overlay.classList.contains('is-mobile-fullscreen'));
    }

    /**
     * Handles clear touch gesture behavior for the gallery UI.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function clearTouchGesture() {
        if (touchGesture && touchGesture.pointerId !== null) {
            try {
                overlay.releasePointerCapture?.(touchGesture.pointerId);
            } catch {
                // Ignore pointer capture release failures from older mobile engines.
            }
        }
        touchGesture = null;
    }

    /**
     * Handles start touch gesture behavior for the gallery UI.
     * @param {*} event Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function startTouchGesture(event) {
        if (overlay.hidden || !isLightboxFullscreen()) {
            return;
        }
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        if (event.type === 'pointerdown' && (event.pointerType === 'mouse' || event.button !== 0)) {
            return;
        }
        if (isMobileTouchDevice) {
            showLightboxHud();
        }
        // target stores state or configuration for the gallery front-end flow.
        const target = event.target instanceof Element ? event.target : null;
        if (isLightboxControlTarget(target)) {
            return;
        }
        touchGesture = {
            pointerId: event.type === 'pointerdown' ? event.pointerId : null,
            startX: point.clientX,
            startY: point.clientY,
            lastX: point.clientX,
            lastY: point.clientY,
            startedAt: Date.now(),
            active: true,
        };
        if (touchGesture.pointerId !== null) {
            try {
                overlay.setPointerCapture?.(touchGesture.pointerId);
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
        if (touchGesture.pointerId !== null && event.pointerId !== touchGesture.pointerId) {
            return;
        }
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event);
        if (!point) {
            return;
        }
        touchGesture.lastX = point.clientX;
        touchGesture.lastY = point.clientY;
        // dx stores state or configuration for the gallery front-end flow.
        const dx = touchGesture.lastX - touchGesture.startX;
        // dy stores state or configuration for the gallery front-end flow.
        const dy = touchGesture.lastY - touchGesture.startY;
        if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 12) {
            event.preventDefault();
        }
        if (Math.abs(dx) > 18 || Math.abs(dy) > 18) {
            overlay.classList.add('is-ui-visible');
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
        // point stores state or configuration for the gallery front-end flow.
        const point = lightboxGesturePoint(event) || {clientX: touchGesture.lastX, clientY: touchGesture.lastY};
        // dx stores state or configuration for the gallery front-end flow.
        const dx = point.clientX - touchGesture.startX;
        // dy stores state or configuration for the gallery front-end flow.
        const dy = point.clientY - touchGesture.startY;
        // elapsed stores state or configuration for the gallery front-end flow.
        const elapsed = Date.now() - touchGesture.startedAt;
        clearTouchGesture();
        if (Math.abs(dx) < 42 || Math.abs(dx) < Math.abs(dy) || elapsed > 1200) {
            return;
        }
        event.preventDefault();
        if (dx < 0) {
            step(1);
        } else {
            step(-1);
        }
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
    function setupGpsMaps() {
        document.addEventListener('click', async (event) => {
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
                openPhotoMapFromJson(photoButton.dataset.mapPoint || card?.dataset.mapPoint || '');
                return;
            }
            // Variable `galleryButton` stores this steps working value.
            const galleryButton = event.target.closest('[data-gallery-map-url]');
            if (galleryButton) {
                event.preventDefault();
                event.stopPropagation();
                await openGalleryMap(galleryButton.dataset.galleryMapUrl || '', galleryButton.dataset.galleryMapTitle || 'Gallery map');
            }
        }, true);
    }

    // Function `openPhotoMapFromJson` executes this focused behavior.
    function openPhotoMapFromJson(json) {
        if (!json) {
            return;
        }
        try {
            // Variable `point` stores this steps working value.
            const point = JSON.parse(json);
            openMapOverlay(point.title || 'Photo location', [point]);
        } catch {
            // Invalid rendered JSON should not break the gallery UI.
        }
    }

    // Function `openGalleryMap` executes this focused behavior.
    async function openGalleryMap(url, title) {
        if (!url) {
            return;
        }
        try {
            // Variable `response` stores this steps working value.
            const response = await fetch(url, {headers: {'Accept': 'application/json'}});
            if (!response.ok) {
                return;
            }
            // Variable `payload` stores this steps working value.
            const payload = await response.json();
            openMapOverlay(payload.title || title, payload.points || []);
        } catch {
            // Network and JSON errors are ignored so the normal gallery remains usable.
        }
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
    function getGalleryMapMarkerIcon() {
        if (!window.L || !L.divIcon) {
            return undefined;
        }

        if (!window.galleryMapMarkerIcon) {
            window.galleryMapMarkerIcon = L.divIcon({
                className: 'gallery-leaflet-marker',
                html: '<span class="gallery-leaflet-marker-shadow" aria-hidden="true"></span><span class="gallery-leaflet-marker-pin" aria-hidden="true"></span>',
                iconAnchor: [13, 40],
                iconSize: [26, 40],
                popupAnchor: [0, -36],
            });
        }

        return window.galleryMapMarkerIcon;
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
    async function openMapOverlay(title, points) {
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
            overlay.addEventListener('click', (event) => {
                if (event.target === overlay || event.target.closest('[data-map-close]')) {
                    overlay.hidden = true;
                    document.body.classList.remove('has-map-overlay');
                }
            });
        }
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
        const bounds = [];
        points.forEach((point) => {
            if (typeof point.lat !== 'number' || typeof point.lng !== 'number') {
                return;
            }
            // Variable `marker` stores this steps working value.
            const marker = L.marker([point.lat, point.lng], {icon: getGalleryMapMarkerIcon()}).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });

        setInitialMapViewport(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
        stabilizeMapAfterLayout(map, bounds, {padding: [30, 30]}, () => overlay.galleryLeafletMap === map);
    }

    /**
     * Handles toggle lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function toggleLightboxMapSplit(json, title) {
        if (!json || !isLightboxFullscreen()) {
            return;
        }
        if (lightboxMapSplit && !lightboxMapSplit.hidden) {
            closeLightboxMapSplit();
            return;
        }
        openLightboxMapSplit(json, title);
    }

    /**
     * Handles open lightbox map split behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @param {*} title Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    async function openLightboxMapSplit(json, title) {
        // points stores state or configuration for the gallery front-end flow.
        const points = parseMapPoints(json);
        if (!points.length || !lightboxMapSplit || !lightboxMapSplitCanvas) {
            return;
        }
        await ensureLeaflet();
        lightboxMapSplit.hidden = false;
        lightboxMapSplitTitle.textContent = title || 'Map';
        overlay.classList.add('is-map-split');
        await waitForElementSize(lightboxMapSplitCanvas);
        if (overlay.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
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
        const bounds = [];
        points.forEach((point) => {
            if (typeof point.lat !== 'number' || typeof point.lng !== 'number') {
                return;
            }
            // marker stores state or configuration for the gallery front-end flow.
            const marker = L.marker([point.lat, point.lng], {icon: getGalleryMapMarkerIcon()}).addTo(map);
            marker.bindPopup(mapPopupHtml(point));
            bounds.push([point.lat, point.lng]);
        });
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
        if (overlay.galleryLeafletSplitResizeObserver) {
            overlay.galleryLeafletSplitResizeObserver.disconnect();
            overlay.galleryLeafletSplitResizeObserver = null;
        }
        if (lightboxMapSplit) {
            lightboxMapSplit.hidden = true;
        }
        overlay.classList.remove('is-map-split');
        if (overlay.galleryLeafletSplitMap) {
            overlay.galleryLeafletSplitMap.remove();
            overlay.galleryLeafletSplitMap = null;
        }
        if (lightboxMapSplitCanvas) {
            lightboxMapSplitCanvas.innerHTML = '';
        }
    }

    /**
     * Handles parse map points behavior for the gallery UI.
     * @param {*} json Value supplied by the caller or event context.
     * @returns {*} Result of the UI operation, when a value is produced.
     */
    function parseMapPoints(json) {
        try {
            // parsed stores state or configuration for the gallery front-end flow.
            const parsed = JSON.parse(json);
            return Array.isArray(parsed) ? parsed : [parsed];
        } catch {
            return [];
        }
    }

    // Function `mapPopupHtml` executes this focused behavior.
    function mapPopupHtml(point) {
        // Variable `title` stores this steps working value.
        const title = escapeHtml(point.title || 'Photo');
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
        const result = event.detail || {};
        if (overlay && score && overlay.dataset.currentImageId === String(result.image_id)) {
            score.textContent = String(result.score);
            updateLightboxVoteButtons(String(result.vote));
            updateLightboxVoteIndicator(String(result.vote));
        }
    });
}
