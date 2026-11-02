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
let currentSource = null;
let musicMuted = false;

/** Bound one explicit next-page read during administrator album playback.
 * Type: number. Units: milliseconds. Scope: one playlist page request.
 * Consumers: createGalleryMusicPlayer/selectTrack.
 * Rationale: ten seconds stops a stalled read without retrying or advancing
 * against an unverified album snapshot. Value: 10000 milliseconds.
 */
const MUSIC_PLAYLIST_READ_TIMEOUT_MS = 10000;

/** Mirror the catalog owner's existing bounded album page size.
 * Type: number. Units: tracks. Scope: one received administrator playlist page.
 * Consumers: createGalleryMusicPlayer pagination admission.
 * Rationale: 100 matches MUSIC_CATALOG_PAGE_SIZE and bounds browser allocations
 * independently of the complete album count. Value: 100 tracks per page.
 */
const MUSIC_PLAYLIST_PAGE_LIMIT = 100;

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
 *     url:string, title:string}>,pagination?:{url:string,offset:number,total:number,
 *     pageSize:number,revision:string}}} playlist Authorized same-origin originals
 *     in saved order.
 * @param {HTMLAudioElement} audio Native audio instance, injectable for
 *     lifecycle fixtures.
 * @returns {{refresh:function():void,
 *     start:function(boolean=, boolean=):Promise<MusicPlaybackResult>,
 *     stop:function():void,
 *     closeViewer:function():void, destroy:function():void,
 *     resumeRequested:function():void,
 *     seek:function(number):void,
 *     command:function(string, string=, boolean=, number=):void}} Shared activation,
 *     slideshow boundaries, disposal and explicit control actions.
 */
export function createGalleryMusicPlayer(root, playlist, audio = new Audio()) {
    const listener = new AbortController();
    let tracks = Array.isArray(playlist?.tracks) ? playlist.tracks.filter(track => {
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
    let paused = false;
    let sourceActive = false;
    let loading = false;
    let playlistStale = false;
    let repeat = true;
    let index = 0;
    let generation = 0;
    let handledFailure = -1;
    let message = '';
    let awaitingGesture = false;
    const failed = new Set();
    audio.preload = 'none';
    audio.muted = audio.muted === true || musicMuted;
    const pagination = playlist.pagination || null;
    if (pagination && (
        new URL(pagination.url, location.href).origin !== location.origin
        || !Number.isSafeInteger(pagination.pageSize)
        || pagination.pageSize < 1 || pagination.pageSize > MUSIC_PLAYLIST_PAGE_LIMIT
        || !Number.isSafeInteger(pagination.total) || pagination.total < 0
        || pagination.offset !== 0
        || tracks.length !== Math.min(pagination.pageSize, pagination.total)
        || typeof pagination.revision !== 'string'
    )) throw new TypeError('Invalid bounded music playlist.');
    let pageOffset = 0;
    const total = pagination ? pagination.total : tracks.length;
    /** @type {AbortController|null} Current bounded page read, cancelled on disposal. */
    let pageController = null;

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
        paused = false;
        sourceActive = false;
        loading = false;
        pageController?.abort();
        pageController = null;
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    }

    /** Reflect native time without rescanning table rows at every time update.
     * @returns {void} Updates only this player's timelines and elapsed/duration text.
     */
    function syncTimeline() {
        const duration = sourceActive && Number.isFinite(audio.duration)
            && audio.duration > 0 ? audio.duration : null;
        const time = sourceActive && Number.isFinite(audio.currentTime)
            ? Math.max(0, audio.currentTime) : 0;
        for (const group of root.querySelectorAll('[data-gallery-music-controls]')) {
            const own = group.closest('[data-gallery-music]')
                ?.dataset.musicGallery === String(playlist.galleryId);
            const seek = group.querySelector('[data-music-seek]');
            const elapsed = group.querySelector('[data-music-elapsed]');
            const length = group.querySelector('[data-music-length]');
            if (elapsed) elapsed.textContent = formatMusicDuration(own ? time : 0);
            if (length) length.textContent = formatMusicDuration(own ? duration : null);
            if (seek instanceof HTMLInputElement) {
                seek.disabled = !own || duration === null || loading;
                seek.max = String(duration ?? 0);
                seek.value = String(own ? Math.min(time, duration ?? 0) : 0);
                seek.setAttribute('aria-valuetext', `${formatMusicDuration(own ? time : 0)} / ${formatMusicDuration(own ? duration : null)}`);
                // One hundred is the structural conversion from ratio to percent.
                seek.style.setProperty('--music-progress',
                    `${own && duration ? Math.min(time / duration, 1) * 100 : 0}%`);
            }
        }
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
            const position = group.querySelector('[data-music-queue-position]');
            if (position) position.textContent = own && total > 0
                ? `${pageOffset + index + 1} / ${total}` : '';
        }
        const buttons = root.querySelectorAll(
            '[data-music-action="play"], [data-music-action="stop"], '
            + '[data-music-action="toggle"], [data-music-action="pause-toggle"], '
            + '[data-music-action="mute"], [data-music-action="repeat"], '
            + '[data-music-action="next"], [data-music-action="previous"]'
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
            const action = button.dataset.musicAction;
            if (action === 'mute' || action === 'repeat') {
                const pressed = action === 'mute' ? audio.muted : own && repeat;
                button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
                if (action === 'mute') {
                    button.querySelector('[data-music-volume-on]')?.toggleAttribute('hidden', audio.muted);
                    button.querySelector('[data-music-volume-off]')?.toggleAttribute('hidden', !audio.muted);
                }
            } else if (action === 'toggle' || action === 'pause-toggle') {
                const label = active ? (action === 'pause-toggle'
                    ? button.dataset.musicPauseLabel : button.dataset.musicStopLabel)
                    : button.dataset.musicPlayLabel;
                if (action === 'pause-toggle') button.disabled = own
                    ? total === 0 || loading || playlistStale
                    : button.dataset.musicUnavailable === '1';
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.setAttribute('aria-label', label || '');
                button.title = label || '';
                button.querySelector('[data-music-toggle-play]')?.toggleAttribute('hidden', active);
                button.querySelector('[data-music-toggle-stop], [data-music-toggle-pause]')?.toggleAttribute('hidden', !active);
            } else if (button.dataset.musicAction === 'play') {
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            } else if (action === 'next' || action === 'previous') {
                button.disabled = own ? total === 0 || loading || playlistStale
                    : button.dataset.musicUnavailable === '1';
            } else button.disabled = !own || (!sourceActive && !loading);
        }
        for (const row of root.querySelectorAll('[data-music-track]')) {
            const own = row.closest('[data-gallery-music]')
                ?.dataset.musicGallery === String(playlist.galleryId);
            row.classList.toggle('is-playing', playing
                && own && row.dataset.musicTrack === tracks[index]?.id);
        }
        observeMusicTitles(root);
        syncTimeline();
    }

    /** Select a global album position while retaining at most one bounded page.
     * @param {number} position Zero-based song position across the complete playlist.
     * @returns {Promise<void>} Loads one page if needed, then plays; stale reads stop visibly.
     */
    async function selectTrack(position) {
        if (disposed || playlistStale || total === 0) return;
        if (position >= pageOffset && position < pageOffset + tracks.length) {
            index = position - pageOffset;
            await play();
            return;
        }
        if (!pagination) return;
        release();
        const token = generation;
        const controller = new AbortController();
        pageController = controller;
        loading = true;
        message = root.querySelector('[data-music-playback-loading]')
            ?.dataset.musicPlaybackLoading || '';
        sync();
        const timeout = setTimeout(() => controller.abort(), MUSIC_PLAYLIST_READ_TIMEOUT_MS);
        let refusal = '';
        try {
            const url = new URL(pagination.url, location.href);
            url.searchParams.set('position', String(position));
            url.searchParams.set('revision', pagination.revision);
            const response = await fetch(url.href, {
                credentials: 'same-origin', cache: 'no-store',
                headers: {'Accept': 'application/json'}, signal: controller.signal,
            });
            if (disposed || token !== generation) return;
            const page = await response.json();
            if (disposed || token !== generation) return;
            if (!response.ok || page.ok !== true) {
                playlistStale = response.status === 409;
                refusal = String(page.message || '');
                throw new Error('Playlist page refused.');
            }
            const offset = Math.floor(position / pagination.pageSize) * pagination.pageSize;
            if (page.revision !== pagination.revision || page.offset !== offset
                || page.total !== total || !Array.isArray(page.tracks)
                || page.tracks.length !== Math.min(pagination.pageSize, total - offset)
                || page.tracks.some(track => typeof track.id !== 'string'
                    || typeof track.title !== 'string' || typeof track.url !== 'string'
                    || new URL(track.url, location.href).origin !== location.origin)) {
                playlistStale = true;
                throw new Error('Playlist page changed.');
            }
            tracks = page.tracks;
            pageOffset = offset;
            index = position - offset;
            failed.clear();
            loading = false;
            pageController = null;
            message = '';
            await play();
        } catch {
            if (disposed || token !== generation) return;
            release();
            message = refusal || root.querySelector('[data-music-playback-error]')
                ?.dataset.musicPlaybackError || '';
            sync();
        } finally {
            clearTimeout(timeout);
            if (pageController === controller) pageController = null;
        }
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
     * Start or resume the selected original within the triggering user task.
     * @param {boolean} restart Whether to replace the source and restart its timeline.
     * @returns {Promise<MusicPlaybackResult>} Settle playback, activation refusal,
     *     exhaustion or cancellation without leaking native rejections.
     */
    function play(restart = true) {
        if (disposed || tracks.length === 0) return Promise.resolve('cancelled');
        awaitingGesture = false;
        if (restart) audio.pause();
        const token = ++generation;
        if (restart) audio.src = tracks[index].url;
        sourceActive = true;
        paused = false;
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
        const next = pageOffset + index + 1;
        if (!repeat && next >= total) { release(); sync(); return; }
        void selectTrack(next % total);
    }, {signal: listener.signal});
    audio.addEventListener('error', () => {
        if (audio.error && playing) failure(null, generation);
    }, {signal: listener.signal});
    for (const event of ['loadedmetadata', 'durationchange', 'timeupdate', 'seeked']) {
        audio.addEventListener(event, syncTimeline, {signal: listener.signal});
    }
    sync();

    return {
        /**
         * Refresh controls after a same-playlist card replacement.
         * @returns {void} Reflect current playback without changing its source.
         */
        refresh: sync,
        /** Move the native cursor only after finite metadata is available.
         * @param {number} seconds Requested position in seconds, clamped to the original.
         * @returns {void} Seeks without changing playback/pause state or loading another source.
         */
        seek(seconds) {
            if (disposed || !sourceActive || !Number.isFinite(seconds)
                || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
            try { audio.currentTime = Math.max(0, Math.min(seconds, audio.duration)); }
            catch { return; }
            syncTimeline();
        },
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
         * @param {string} action Play, pause-toggle, stop, toggle, next, previous, mute or repeat.
         * @param {string} trackId Optional opaque row track identity.
         * @param {boolean} viewer Whether the control belongs to the
         *     photo viewer.
         * @param {number} position Optional global album position; -1 selects by track ID.
         * @returns {void} Select and exclusively play, or release,
         *     the requested track.
         */
        command(action, trackId = '', viewer = false, position = -1) {
            if (disposed) return;
            if (action === 'mute') {
                audio.muted = !audio.muted;
                musicMuted = audio.muted;
                sync(); return;
            }
            if (action === 'repeat') { repeat = !repeat; sync(); return; }
            if (action === 'stop') { release(); sync(); return; }
            if (tracks.length === 0 || playlistStale || loading) return;
            if (!['play', 'toggle', 'pause-toggle', 'next', 'previous'].includes(action)) return;
            const selected = trackId ? tracks.findIndex(track => track.id === trackId) : -1;
            if (trackId && selected < 0 && position < 0) return;
            const same = position >= 0 ? position === pageOffset + index
                : selected === index || !trackId;
            if (action === 'pause-toggle' && same && playing) {
                generation += 1;
                playing = false;
                paused = true;
                audio.pause(); sync(); return;
            }
            if ((action === 'pause-toggle' || action === 'play') && same && paused) {
                void play(false); return;
            }
            if (action === 'toggle' && playing && selected === index) {
                release(); sync(); return;
            }
            let target = position >= 0 ? position : pageOffset + (selected >= 0 ? selected : index);
            if (action === 'next') target = (pageOffset + index + 1) % total;
            else if (action === 'previous') target = (pageOffset + index - 1 + total) % total;
            if (!Number.isSafeInteger(target) || target < 0 || target >= total) return;
            owner = viewer ? (slideshow ? 'slideshow' : 'viewer') : 'page';
            failed.clear();
            message = '';
            void selectTrack(target);
        },
    };
}

/**
 * Resolve one page-wide exclusive player from current server-rendered
 *     playlist data.
 * @param {HTMLElement|null} card Optional table whose explicit control was
 *     activated; nested catalog players retain their own playlist ownership.
 * @returns {ReturnType<typeof createGalleryMusicPlayer>|null} Current shared
 *     player, or null without authorized music.
 */
export function getGalleryMusicPlayer(card = null) {
    const source = card
        ? Array.from(card.querySelectorAll('[data-gallery-music-playlist]'))
            .find(candidate => candidate.closest('[data-gallery-music]') === card)
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
        currentSource = source;
        currentPlayer.refresh();
        return currentPlayer;
    }
    currentPlayer?.destroy();
    currentPlayer = null;
    currentSignature = signature;
    try {
        currentPlayer = createGalleryMusicPlayer(document, JSON.parse(signature));
        currentSource = source;
    }
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
            Boolean(button.closest('[data-lightbox]')),
            Number.parseInt(button.dataset.musicPosition || '-1', 10));
    }, true);
    document.addEventListener('input', event => {
        const seek = event.target;
        if (!(seek instanceof HTMLInputElement) || seek.disabled
            || !seek.matches('[data-music-seek]')) return;
        // Refreshing an existing player synchronizes its range; capture the
        // user's requested cursor before that readback can replace the value.
        const seconds = Number(seek.value);
        getGalleryMusicPlayer(seek.closest('[data-gallery-music]'))
            ?.seek(seconds);
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
        if (currentSource?.isConnected === false) {
            currentPlayer?.destroy();
            currentPlayer = null;
            currentSource = null;
            currentSignature = '';
        }
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
