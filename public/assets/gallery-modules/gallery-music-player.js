/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-music-player.js
 * Module Type: Browser Module
 * Purpose: Share exclusive audio between gallery tables, the viewer and
 *     slideshow.
 * Responsibilities: Own playback, repeat-all, compact control state and
 *     disposal.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
let currentPlayer = null;
let currentSignature = '';

/**
 * @typedef {'playing'|'silent'|'blocked'|'failed'|'cancelled'} MusicPlaybackResult
 * Settled native playback, deliberate silence, activation refusal, exhausted
 * originals or a superseded request. No result grants browser permissions.
 */

/**
 * Build one exclusive player for a trusted gallery playlist.
 * @param {Document|HTMLElement} root Document or isolated fixture control
 *     owner.
 * @param {{galleryId:number, enabled:boolean, tracks:Array<{id:string,
 *     url:string, title:string}>}} playlist Authorized same-origin originals
 *     in saved order.
 * @param {HTMLAudioElement} audio Native audio instance, injectable for
 *     lifecycle fixtures.
 * @returns {{refresh:function():void,
 *     start:function(boolean=, boolean=):Promise<MusicPlaybackResult>,
 *     stop:function():void,
 *     closeViewer:function():void, destroy:function():void,
 *     resumeRequested:function():void,
 *     command:function(string, string=, boolean=):void}} Shared activation,
 *     slideshow boundaries, disposal and explicit control actions.
 */
export function createGalleryMusicPlayer(root, playlist, audio = new Audio()) {
    const listener = new AbortController();
    const tracks = Array.isArray(playlist?.tracks) ? playlist.tracks.filter(track => {
        try {
            return typeof track.id === 'string'
                && typeof track.title === 'string' && typeof track.url === 'string'
                && new URL(track.url, location.href).origin === location.origin;
        } catch { return false; }
    }) : [];
    let owner = 'page';
    let slideshow = false;
    let disposed = false;
    let playing = false;
    let index = 0;
    let generation = 0;
    let handledFailure = -1;
    let message = '';
    let awaitingGesture = false;
    const failed = new Set();
    audio.preload = 'none';

    /**
     * Release the current native request and invalidate outstanding play
     *     promises.
     * @returns {void} Silence and detach the source while retaining
     *     selected track.
     */
    function release() {
        awaitingGesture = false;
        message = '';
        generation += 1;
        playing = false;
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    }

    /**
     * Synchronize all table and viewer controls without creating another
     *     player.
     * @returns {void} Reflect exclusive track state and accessible
     *     icon-button state.
     */
    function sync() {
        const groups = root.querySelectorAll(
            '[data-gallery-music-controls], [data-lightbox-music]'
        );
        for (const group of groups) {
            const card = group.closest('[data-gallery-music]');
            const own = card ? card.dataset.musicGallery === String(playlist.galleryId)
                : group.dataset.musicGallery === String(playlist.galleryId);
            group.hidden = card ? false : !group.dataset.musicGallery;
            group.classList.toggle('is-awaiting-gesture', own
                && awaitingGesture && owner === 'slideshow' && slideshow);
            const title = group.querySelector('[data-music-current-title]');
            if (title) title.textContent = own ? tracks[index]?.title || '' : '';
            const status = group.querySelector('[data-music-playback-status]');
            if (status) status.textContent = own ? message : '';
        }
        const buttons = root.querySelectorAll(
            '[data-music-action="play"], [data-music-action="stop"], '
            + '[data-music-action="toggle"]'
        );
        for (const button of buttons) {
            const selected = !button.dataset.musicTrackId
                || button.dataset.musicTrackId === tracks[index]?.id;
            const card = button.closest('[data-gallery-music]');
            const group = button.closest('[data-lightbox-music]');
            const identity = card?.dataset.musicGallery
                || group?.dataset.musicGallery;
            const own = identity === String(playlist.galleryId);
            const active = playing && selected && own;
            if (button.dataset.musicAction === 'toggle') {
                const label = active ? button.dataset.musicStopLabel
                    : button.dataset.musicPlayLabel;
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.setAttribute('aria-label', label || '');
                button.title = label || '';
                button.querySelector('[data-music-toggle-play]').hidden = active;
                button.querySelector('[data-music-toggle-stop]').hidden = !active;
            } else if (button.dataset.musicAction === 'play') {
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            } else button.disabled = !active;
        }
        for (const row of root.querySelectorAll('[data-music-track]')) {
            const own = row.closest('[data-gallery-music]')
                ?.dataset.musicGallery === String(playlist.galleryId);
            row.classList.toggle('is-playing', playing
                && own && row.dataset.musicTrack === tracks[index]?.id);
        }
        observeMusicTitles(root);
    }

    /**
     * Skip a failed original once per pass, or expose an explicit
     *     gesture retry.
     * @param {Error|DOMException|null} error Native rejection or media error.
     * @param {number} token Source generation associated with the failure.
     * @returns {MusicPlaybackResult|Promise<MusicPlaybackResult>} Report refusal,
     *     exhaustion or cancellation, or adopt the next original's result.
     */
    function failure(error, token) {
        if (token !== generation || handledFailure === token
            || disposed || !playing) return 'cancelled';
        handledFailure = token;
        const failureMessage = root.querySelector(
            '[data-music-playback-error]'
        )?.dataset.musicPlaybackError || '';
        if (error?.name === 'NotAllowedError') {
            release();
            awaitingGesture = true;
            message = root.querySelector('[data-music-activation-required]')
                ?.dataset.musicActivationRequired || failureMessage;
            sync();
            return 'blocked';
        }
        failed.add(index);
        if (failed.size >= tracks.length) {
            release();
            message = failureMessage;
            sync();
            return 'failed';
        }
        do { index = (index + 1) % tracks.length; } while (failed.has(index));
        return play();
    }

    /**
     * Start the selected original synchronously within the triggering
     *     user task.
     * @returns {Promise<MusicPlaybackResult>} Settle playback, activation refusal,
     *     exhaustion or cancellation without leaking native rejections.
     */
    function play() {
        if (disposed || tracks.length === 0) return Promise.resolve('cancelled');
        awaitingGesture = false;
        audio.pause();
        const token = ++generation;
        audio.src = tracks[index].url;
        playing = true;
        sync();
        try {
            return Promise.resolve(audio.play()).then(() => {
                if (token !== generation || disposed) return 'cancelled';
                message = '';
                sync();
                return 'playing';
            }).catch(error => failure(error, token));
        } catch (error) { return Promise.resolve(failure(error, token)); }
    }

    audio.addEventListener('ended', () => {
        if (disposed || !playing || tracks.length === 0) return;
        failed.clear();
        index = (index + 1) % tracks.length;
        play();
    }, {signal: listener.signal});
    audio.addEventListener('error', () => {
        if (audio.error && playing) failure(null, generation);
    }, {signal: listener.signal});
    sync();

    return {
        /**
         * Refresh controls after a same-playlist card replacement.
         * @returns {void} Reflect current playback without changing its source.
         */
        refresh: sync,
        /**
         * Apply the saved default or the visitor's explicit soundtrack launch.
         * @param {boolean} force Whether this launch explicitly requests music.
         * @param {boolean} silent Whether an explicit presentation mutes even
         *     default-on or already playing page music while retaining its owner.
         * @returns {Promise<MusicPlaybackResult>} Report native playback, silence,
         *     activation refusal, exhaustion or cancellation to the launch owner.
         */
        start(force = false, silent = false) {
            slideshow = true;
            if (silent) {
                owner = 'slideshow';
                release();
                sync();
                return Promise.resolve('silent');
            }
            if (disposed) return Promise.resolve('cancelled');
            if (!force && playlist.enabled !== true) return Promise.resolve('silent');
            owner = 'slideshow';
            index = 0;
            failed.clear();
            message = '';
            return play();
        },
        /**
         * Retry only a requested slideshow soundtrack after visitor activation.
         * @returns {void} Preserve track/owner; stopped or closed music stays off.
         */
        resumeRequested() {
            if (disposed || !awaitingGesture || !slideshow
                || owner !== 'slideshow') return;
            awaitingGesture = false;
            message = '';
            play();
        },
        /**
         * Stop audio owned by the ended slideshow without silencing
         *     table playback.
         * @returns {void} Retire slideshow audio and retain the
         *     saved gallery default.
         */
        stop() {
            slideshow = false;
            if (owner === 'slideshow') { release(); sync(); }
        },
        /**
         * Release viewer or slideshow audio when its viewer closes.
         * @returns {void} Preserve independently requested page-table playback.
         */
        closeViewer() {
            slideshow = false;
            if (owner !== 'page') { release(); sync(); }
        },
        /**
         * Dispose this gallery before another playlist or page replaces it.
         * @returns {void} Prevent late callbacks and release all
         *     native media work.
         */
        destroy() {
            disposed = true;
            listener.abort();
            release();
            sync();
        },
        /**
         * Execute one explicit compact player or row-control action.
         * @param {string} action Play, stop, toggle, next or previous.
         * @param {string} trackId Optional opaque row track identity.
         * @param {boolean} viewer Whether the control belongs to the
         *     photo viewer.
         * @returns {void} Select and exclusively play, or release,
         *     the requested track.
         */
        command(action, trackId = '', viewer = false) {
            if (disposed || tracks.length === 0) return;
            if (action === 'stop') { release(); sync(); return; }
            if (!['play', 'toggle', 'next', 'previous'].includes(action)) return;
            const selected = trackId ? tracks.findIndex(track => track.id === trackId) : -1;
            if (trackId && selected < 0) return;
            if (action === 'toggle' && playing && selected === index) {
                release(); sync(); return;
            }
            if (selected >= 0) index = selected;
            else if (action === 'next') index = (index + 1) % tracks.length;
            else if (action === 'previous') index = (index - 1 + tracks.length) % tracks.length;
            owner = viewer ? (slideshow ? 'slideshow' : 'viewer') : 'page';
            failed.clear();
            message = '';
            play();
        },
    };
}

/**
 * Resolve one page-wide exclusive player from current server-rendered
 *     playlist data.
 * @param {HTMLElement|null} card Optional table whose explicit control was
 *     activated.
 * @returns {ReturnType<typeof createGalleryMusicPlayer>|null} Current shared
 *     player, or null without authorized music.
 */
export function getGalleryMusicPlayer(card = null) {
    const source = card
        ? card.querySelector('[data-gallery-music-playlist]')
        : document.querySelector('[data-gallery-music-playlist]:not([data-music-editor="1"])');
    if (!card) {
        for (const controls of document.querySelectorAll('[data-lightbox-music]')) {
            controls.dataset.musicGallery = source?.dataset.musicGallery || '';
            controls.hidden = !source;
        }
    }
    if (!source) return null;
    const signature = source.textContent;
    if (currentPlayer && signature === currentSignature) {
        currentPlayer.refresh();
        return currentPlayer;
    }
    currentPlayer?.destroy();
    currentPlayer = null;
    currentSignature = signature;
    try { currentPlayer = createGalleryMusicPlayer(document, JSON.parse(signature)); }
    catch { currentSignature = ''; }
    return currentPlayer;
}

/**
 * Suspend the active viewer owner without switching the selected gallery
 *     playlist.
 * @param {boolean} closing Whether the complete viewer is closing rather
 *     than slideshow alone.
 * @returns {void} Release only audio owned by that viewer boundary.
 */
export function stopGalleryMusicViewer(closing = false) {
    if (closing) currentPlayer?.closeViewer();
    else currentPlayer?.stop();
}

/**
 * Bind table and viewer controls once, including later injected editor tables.
 * @returns {void} Install document delegation and refresh playlist ownership
 *     after mutations.
 */
export function setupGalleryMusicPlayback() {
    getGalleryMusicPlayer();
    scanGalleryMusicDurations();
    if (document.body.dataset.musicPlaybackBound === '1') return;
    document.body.dataset.musicPlaybackBound = '1';
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        const viewer = target?.closest('[data-lightbox]');
        const control = target?.closest(
            'button,a,input,select,textarea,[role="button"]'
        );
        if (event.isTrusted && viewer && !viewer.hidden
            && control?.matches('[data-lightbox-stage]')) {
            currentPlayer?.resumeRequested();
        }
        const button = event.target instanceof Element
            ? event.target.closest('[data-music-action]') : null;
        if (!button || button.disabled) return;
        event.preventDefault();
        event.stopPropagation();
        const card = button.closest('[data-gallery-music]');
        const player = getGalleryMusicPlayer(card);
        player?.command(button.dataset.musicAction, button.dataset.musicTrackId || '',
            Boolean(button.closest('[data-lightbox]')));
    }, true);
    document.addEventListener('php-gallery:mutation-complete', () => {
        const source = document.querySelector(
            '[data-gallery-music-playlist]:not([data-music-editor="1"])'
        );
        if (source) getGalleryMusicPlayer();
        else { currentPlayer?.destroy(); currentPlayer = null; currentSignature = ''; }
        scanGalleryMusicDurations();
    });
    document.addEventListener('php-gallery:media-panel-open', () => {
        currentPlayer?.refresh();
        scanGalleryMusicDurations();
    });
    window.addEventListener('resize', () => observeMusicTitles(document));
    const observer = new MutationObserver(changes => {
        for (const change of changes) for (const node of change.addedNodes) {
            if (node instanceof Element && (node.matches('[data-gallery-music]')
                || node.querySelector('[data-gallery-music]'))) scanGalleryMusicDurations(node);
        }
    });
    observer.observe(document.body, {childList: true, subtree: true});
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        getGalleryMusicPlayer();
        observer.observe(document.body, {childList: true, subtree: true});
        durationSeen = new WeakSet();
        durationObserver = null;
        observeMusicTitles(document);
        scanGalleryMusicDurations();
    });
    window.addEventListener('pagehide', () => {
        currentPlayer?.destroy(); currentPlayer = null; currentSignature = '';
        observer.disconnect();
        durationObserver?.disconnect();
        musicTitleObserver?.disconnect();
        durationQueue.length = 0;
        cancelDurationRead?.();
    });
}

/**
 * Keep long active titles readable without rapid marquee motion.
 * Type: number. Units: CSS pixels per second. Scope: overflowing track labels.
 * Consumers: refreshMusicTitle.
 * Rationale: Thirty pixels per second permits reading while titles traverse.
 */
const MUSIC_TITLE_SPEED = 30;
/**
 * Avoid a distracting rapid bounce for titles that only slightly overflow.
 * Type: number. Units: seconds. Scope: one title animation direction.
 * Consumers: refreshMusicTitle.
 * Rationale: Eight seconds provides a calm minimum even for small overflows.
 */
const MUSIC_TITLE_MIN_SECONDS = 8;
let musicTitleObserver = null;

/**
 * Measure only actual overflow and retain full text for tooltips/screen readers.
 * @param {HTMLElement} title Clipped row-title viewport with its original text.
 * @returns {void} Set the distance/duration consumed by playing-row CSS only.
 */
function refreshMusicTitle(title) {
    const text = title.querySelector('[data-music-title-text]');
    if (!text) return;
    const distance = Math.max(0, text.scrollWidth - title.clientWidth);
    title.classList.toggle('is-overflowing', title.clientWidth > 0 && distance > 0);
    title.style.setProperty('--music-title-offset', `${-distance}px`);
    title.style.setProperty('--music-title-duration', `${Math.max(
        MUSIC_TITLE_MIN_SECONDS, distance / MUSIC_TITLE_SPEED
    )}s`);
}

/**
 * Observe title viewports across opening, resizing and card replacements.
 * @param {Document|HTMLElement} root Current document or isolated player root.
 * @returns {void} Refresh rows without touching the existing miniplayer.
 */
function observeMusicTitles(root) {
    if (!musicTitleObserver && typeof ResizeObserver === 'function') {
        musicTitleObserver = new ResizeObserver(entries => {
            for (const entry of entries) refreshMusicTitle(entry.target);
        });
    }
    // Permanent fallback: explicit playback/open/resize events still measure
    // titles without ResizeObserver. Reduced-motion users retain static text.
    for (const title of root.querySelectorAll('[data-music-title]')) {
        refreshMusicTitle(title);
        musicTitleObserver?.observe(title);
    }
}

/**
 * Bound metadata-only audio observation without blocking the active player.
 * Type: number. Units: milliseconds. Scope: one visible music-table row.
 * Consumers: readMusicDuration.
 * Rationale: Ten seconds bounds stalled metadata requests on shared hosting.
 */
const MUSIC_DURATION_TIMEOUT_MS = 10000;
let durationSeen = new WeakSet();
const durationQueue = [];
let durationObserver = null;
let durationReader = null;
let durationRunning = false;
let cancelDurationRead = null;

/**
 * Admit visible music duration cells to a single metadata-only reader.
 * @param {Document|Element} root Initial document or newly mounted music
 *     fragment.
 * @returns {void} Observe each row once, including dynamically replaced
 *     editor cards.
 */
export function scanGalleryMusicDurations(root = document) {
    if (!durationObserver && typeof IntersectionObserver === 'function') {
        durationObserver = new IntersectionObserver(entries => {
            for (const entry of entries) if (entry.isIntersecting) {
                durationObserver.unobserve(entry.target);
                durationQueue.push(entry.target);
            }
            void drainMusicDurations();
        });
    }
    for (const cell of root.querySelectorAll('[data-music-duration]')) {
        if (durationSeen.has(cell)) continue;
        durationSeen.add(cell);
        // Public totals need the complete authorized list while its disclosure
        // is closed. Keep one silent reader; editor-only rows stay viewport-led.
        const summary = cell.closest('[data-gallery-music]')
            ?.querySelector('[data-music-duration-total]');
        if (summary) durationQueue.push(cell);
        else if (durationObserver) durationObserver.observe(cell);
        else durationQueue.push(cell);
    }
    // Permanent support for browsers without IntersectionObserver: metadata
    // remains serialized and bounded by the same per-row deadline.
    void drainMusicDurations();
}

/**
 * Show a complete playlist duration without treating missing tracks as zero.
 * @param {Element} card Authorized public music section containing its rows.
 * @returns {void} Show the total, pending ellipsis or explicit unknown dash.
 */
function updateMusicDurationTotal(card) {
    const total = card.querySelector('[data-music-duration-total]');
    if (!total) return;
    const cells = [...card.querySelectorAll('[data-music-duration]')];
    const ready = cells.every(cell => cell.dataset.musicDurationLoaded === '1');
    const known = cells.every(cell => cell.dataset.musicDurationValue !== undefined);
    total.textContent = !ready ? '…' : !known ? '—' : formatMusicDuration(
        cells.reduce((sum, cell) => sum + Number(cell.dataset.musicDurationValue), 0)
    );
    if (ready && known) total.removeAttribute('title');
}

/**
 * Format a finite media duration without exposing a misleading guessed value.
 * @param {number|null} duration Seconds reported by the browser metadata
 *     decoder.
 * @returns {string} Minute/second or hour/minute/second text, or an unknown
 *     dash.
 */
export function formatMusicDuration(duration) {
    if (!Number.isFinite(duration) || duration < 0) return '—';
    // Sixty seconds per minute and 3600 per hour are time-format structure.
    const seconds = Math.floor(duration);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor(seconds % 3600 / 60);
    const prefix = hours ? hours + ':' + String(minutes).padStart(2, '0')
        : String(Math.floor(seconds / 60));
    return prefix
        + ':' + String(seconds % 60).padStart(2, '0');
}

/**
 * Resolve one same-origin duration without ever calling play on the metadata
 *     reader.
 * @param {string} source Protected original URL from server-rendered table
 *     data.
 * @returns {Promise<number|null>} Native duration, or null for
 *     failure/timeout/unknown.
 */
function readMusicDuration(source) {
    try {
        if (new URL(source, location.href).origin !== location.origin) {
            return Promise.resolve(null);
        }
    }
    catch { return Promise.resolve(null); }
    if (!durationReader) { durationReader = new Audio(); durationReader.preload = 'metadata'; }
    return new Promise(resolve => {
        const events = new AbortController();
        const finish = value => {
            clearTimeout(timer);
            events.abort();
            durationReader.removeAttribute('src');
            durationReader.load();
            cancelDurationRead = null;
            resolve(value);
        };
        const timer = setTimeout(() => finish(null), MUSIC_DURATION_TIMEOUT_MS);
        cancelDurationRead = () => finish(null);
        durationReader.addEventListener('loadedmetadata',
            () => finish(durationReader.duration), {signal: events.signal});
        durationReader.addEventListener('error', () => finish(null), {signal: events.signal});
        durationReader.src = source;
        durationReader.load();
    });
}

/**
 * Drain only connected duration cells through one silent metadata reader.
 * @returns {Promise<void>} Update each surviving row without overlapping
 *     metadata work.
 */
async function drainMusicDurations() {
    if (durationRunning) return;
    durationRunning = true;
    try {
        while (durationQueue.length) {
            const cell = durationQueue.shift();
            if (!cell.isConnected) continue;
            const duration = await readMusicDuration(cell.dataset.musicSrc || '');
            if (cell.isConnected) {
                cell.textContent = formatMusicDuration(duration);
                cell.dataset.musicDurationLoaded = '1';
                delete cell.dataset.musicDurationValue;
                if (Number.isFinite(duration) && duration >= 0) {
                    cell.dataset.musicDurationValue = String(duration);
                    cell.removeAttribute('title');
                }
                const card = cell.closest('[data-gallery-music]');
                if (card) updateMusicDurationTotal(card);
            }
        }
    } finally { durationRunning = false; }
}
