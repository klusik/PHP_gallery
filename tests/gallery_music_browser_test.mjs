/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_music_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise soundtrack controls and repeated dynamic music editor actions.
 * Responsibilities: Run production modules with native DOM and bounded audio adapters.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    if (process.env.PHP_GALLERY_BROWSER_REQUIRED === '1') throw new Error('Required Chromium is unavailable.');
    console.log('SKIP gallery music browser: Chromium executable not supplied.');
    process.exit(0);
}
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = String.raw`<!doctype html><html lang="en"><head>
<meta charset="utf-8"><link rel="stylesheet" href="/public/assets/styles/lightbox.css">
<style>
body { margin: 8px; font: 16px sans-serif; }
[data-fixture-music] { max-width: 36rem; }
</style>
</head><body><pre id="results"></pre>
<aside data-admin-side-panel data-open="1"><div data-music-mount></div></aside>
<div data-fixture-music></div>
<div data-fixture-presentation data-gallery-presentation>
<details data-gallery-media-widget data-presentation-share-widget>
<summary>Share presentation</summary>
<section id="share-fixture" data-gallery-media-panel data-presentation-share-panel
 data-presentation-csrf="fixture-csrf"
 data-presentation-url="http://127.0.0.1/gallery?lang=en&amp;presentation=1">
<input type="checkbox" data-share-option="autoplay">
<input type="checkbox" data-share-option="music">
<input type="checkbox" data-share-option="fullscreen">
<input type="checkbox" data-share-option="exclusive">
<label><input type="range" min="0" max="2" data-share-option="interval">
<output></output><span hidden data-share-speed="slow">Slow</span>
<span hidden data-share-speed="medium">Medium</span>
<span hidden data-share-speed="fast">Fast</span></label>
<label><input type="range" min="0" max="2" data-share-option="transition">
<output></output><span hidden data-share-speed="slow">Slow</span>
<span hidden data-share-speed="medium">Medium</span>
<span hidden data-share-speed="fast">Fast</span></label>
<output data-presentation-status data-copied="Copied"
 data-copy-failed="Copy manually" data-link-pending="Creating"
 data-link-failed="Unavailable"></output>
<input type="url" readonly data-presentation-copy>
<button type="button" data-presentation-copy-button>Copy</button>
</section></details>
</div>
<div data-lightbox><div data-lightbox-music data-music-gallery="42"
 data-music-playback-error="Press Play to retry">
<button type="button" data-music-action="previous" aria-label="Previous">◀</button>
<button type="button" data-music-action="play" aria-label="Play">▶</button>
<button type="button" data-music-action="stop" aria-label="Stop">■</button>
<button type="button" data-music-action="next" aria-label="Next">▶</button>
<span data-music-current-title></span><output data-music-playback-status></output>
</div></div>
<script type="module">
import {
    createGalleryMusicPlayer, setupGalleryMusicPlayback,
    getGalleryMusicPlayer, formatMusicDuration,
} from '/public/assets/gallery-modules/gallery-music-player.js?v=20261024-catalog-workspace-v3';
import {setupGalleryMusic} from '/public/assets/gallery-modules/admin-gallery-music.js?v=20261024-catalog-workspace-v3';
const check = (condition, message) => { if (!condition) throw new Error(message); };
/**
 * Yield one browser task so native form/promise completion can settle.
 * Type: number. Units: milliseconds. Scope: this deterministic browser fixture.
 * Consumers: turn.
 * Rationale: Zero-delay scheduling drains already queued promise callbacks.
 */
const MUSIC_FIXTURE_TURN_MS = 0;
const turn = () => new Promise(resolve => setTimeout(resolve, MUSIC_FIXTURE_TURN_MS));

/** Native EventTarget audio adapter retaining observable source and play work. */
class AudioAdapter extends EventTarget {
    src = '';
    error = null;
    calls = [];
    results = [];
    paused = true;
    duration = 123;
    static instances = [];
    /**
     * Retain each native substitute for exclusive-playback assertions.
     * @returns {void} Initialize the observable EventTarget audio owner.
     */
    constructor() { super(); AudioAdapter.instances.push(this); }
    /**
     * Start one controlled play attempt without decoding fixture media.
     * @returns {Promise<void>} Queued outcome or an immediately successful play.
     */
    play() {
        this.calls.push(this.src);
        this.paused = false;
        check(AudioAdapter.instances.filter(item => !item.paused).length === 1,
            'Two music sources played concurrently');
        return this.results.shift() || Promise.resolve();
    }
    /**
     * Record native suspension.
     * @returns {void} Mark the adapter paused.
     */
    pause() { this.paused = true; }
    /**
     * Release a source through the native attribute boundary.
     * @param {string} name Attribute being removed.
     * @returns {void} Clear the source attribute.
     */
    removeAttribute(name) { if (name === 'src') this.src = ''; }
    /**
     * Cancel detached sources or report controlled silent metadata.
     * @returns {void} Queue metadata only when a metadata source is present.
     */
    load() {
        if (this.src && this.preload === 'metadata') {
            queueMicrotask(() => this.dispatchEvent(new Event('loadedmetadata')));
        }
    }
}
try {
    const overlay = document.querySelector('[data-lightbox]');
    const controls = overlay.querySelector('[data-lightbox-music]');
    const tracks = [
        {id: 'one', url: '/track-one', title: 'One'},
        {id: 'two', url: '/track-two',
            title: 'Two - A very long soundtrack title extending beyond '
                + 'the compact playlist viewport in every tested layout.mp3'},
    ];
    const audio = new AudioAdapter();
    const player = createGalleryMusicPlayer(
        overlay, {galleryId: 42, enabled: true, tracks}, audio
    );
    check(audio.calls.length === 0 && audio.src === '',
        'Setup fetched audio before activation');
    player.start();
    check(audio.src === '/track-one', 'Gallery default did not start first track');
    audio.dispatchEvent(new Event('ended'));
    check(audio.src === '/track-two', 'Ended did not advance');
    audio.dispatchEvent(new Event('ended'));
    check(audio.src === '/track-one', 'Repeat-all did not wrap');
    player.command('previous', '', true);
    check(audio.src === '/track-two', 'Previous did not wrap');
    player.command('next', '', true);
    check(audio.src === '/track-one', 'Next did not wrap');
    player.command('stop');
    check(audio.src === '' && audio.paused, 'Stop did not release audio');
    player.command('play', 'two', true);
    check(audio.src === '/track-two', 'Row play did not select its original');
    player.stop();
    check(audio.src === '' && audio.paused, 'Slideshow stop left audio active');
    player.command('play', 'two');
    player.start(false, true);
    check(audio.src === '' && audio.paused,
        'Explicit silent presentation retained existing page music');
    player.command('play', '', true);
    player.stop();
    check(audio.src === '' && audio.paused,
        'Silent presentation lost ownership of a later visitor Play action');
    player.start();
    check(audio.src === '/track-one', 'Restart did not reset track');
    player.destroy();
    const count = audio.calls.length;
    audio.dispatchEvent(new Event('ended'));
    player.command('play');
    check(audio.calls.length === count && audio.src === '',
        'Destroyed player retained work');

    const silentAudio = new AudioAdapter();
    const silent = createGalleryMusicPlayer(
        overlay, {galleryId: 42, enabled: false, tracks}, silentAudio
    );
    check(await silent.start() === 'silent',
        'Default-off start did not report deliberate silence');
    check(silentAudio.calls.length === 0, 'Default-off gallery started music');
    check(await silent.start(true) === 'playing',
        'Explicit launch did not report settled playback');
    check(silentAudio.src === '/track-one',
        'Explicit presentation did not enable the default-off soundtrack');
    silent.stop();
    silent.start();
    check(silentAudio.src === '',
        'Explicit launch changed the saved automatic music preference');
    silentAudio.results.push(Promise.reject(
        new DOMException('Gesture required', 'NotAllowedError')
    ));
    silent.command('play', '', true);
    await turn();
    check(silentAudio.paused && silentAudio.src === ''
        && controls.querySelector('output').textContent !== '',
        'Autoplay rejection was not contained');
    silent.resumeRequested();
    check(silentAudio.src === '/track-one', 'Gesture retry failed');
    silentAudio.results.push(Promise.reject(
        new DOMException('Gesture required', 'NotAllowedError')
    ));
    check(await silent.start(true) === 'blocked',
        'Presentation owner could not distinguish activation refusal');
    silent.stop();
    const stoppedCalls = silentAudio.calls.length;
    silent.resumeRequested();
    check(silentAudio.calls.length === stoppedCalls && silentAudio.src === '',
        'A stopped soundtrack resumed from an obsolete activation request');
    silent.start(true);
    let rejectStale;
    silentAudio.results.push(new Promise((_resolve, reject) => {
        rejectStale = reject;
    }));
    silent.command('next', '', true);
    silent.stop();
    rejectStale(new DOMException('Late source failure', 'NotSupportedError'));
    await turn();
    check(silentAudio.src === '' && silentAudio.paused,
        'Late rejection resurrected stopped audio');
    silent.destroy();

    const brokenAudio = new AudioAdapter();
    brokenAudio.results = tracks.map(() => Promise.reject(
        new DOMException('Unsupported', 'NotSupportedError')
    ));
    const broken = createGalleryMusicPlayer(
        overlay, {galleryId: 42, enabled: true, tracks}, brokenAudio
    );
    check(await broken.start() === 'failed',
        'Exhausted playlist did not report its terminal failure');
    check(brokenAudio.calls.length === tracks.length && brokenAudio.src === '',
        'Unsupported playlist did not stop after one bounded pass');
    broken.destroy();

    const smartAudio = new AudioAdapter();
    controls.dataset.musicGallery = '-42';
    const smartPlayer = createGalleryMusicPlayer(overlay, {
        galleryId: -42, enabled: true,
        tracks: tracks.map(track => ({...track, id: 'physical:' + track.id})),
    }, smartAudio);
    smartPlayer.start();
    smartPlayer.command('play', 'physical:two', true);
    check(smartAudio.src === '/track-two'
        && controls.querySelector('[data-music-current-title]').textContent
            === tracks[1].title,
        'Smart identity/composite rows did not reuse slideshow playback');
    smartPlayer.destroy();
    controls.dataset.musicGallery = '42';

    window.Audio = AudioAdapter;
    const publicMount = document.querySelector('[data-fixture-music]');
    const mediaPanel = (id, content) =>
        '<details data-gallery-media-widget class="gallery-media-widget">'
        + '<summary aria-controls="' + id + '">Music <span>2</span></summary>'
        + '<div id="' + id + '" data-gallery-media-panel'
        + ' class="gallery-media-panel"><header><h3>Music</h3>'
        + '<button data-media-close type="button"'
        + ' class="gallery-media-close" aria-label="Close">×</button></header>'
        + '<div class="gallery-media-body">' + content
        + '</div></div></details>';
    const musicCard = (galleryId, editor = false) => {
        const data = JSON.stringify({galleryId, enabled: true, tracks});
        const rows = tracks.map(track => '<tr data-music-track="'
            + track.id + '"><td><div class="music-track-line">'
            + '<button type="button" class="music-icon music-track-toggle"'
            + ' data-music-action="toggle" data-music-track-id="' + track.id
            + '" data-music-play-label="Play" data-music-stop-label="Stop">'
            + '<span data-music-toggle-play>▶</span>'
            + '<span data-music-toggle-stop hidden>■</span></button>'
            + '<span class="music-track-name" data-music-title title="'
            + track.title + '"><span data-music-title-text>' + track.title
            + '</span></span></div></td><td class="gallery-music-duration"'
            + ' data-music-duration data-music-src="' + track.url
            + '"></td></tr>').join('');
        const playlist = '<table class="gallery-music-table"><thead><tr>'
            + '<th>Track</th><th class="gallery-music-duration">Duration</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>';
        const controls = '<div data-gallery-music-controls class="music-player">'
            + '<div class="music-player-actions">'
            + ['previous', 'play', 'stop', 'next'].map(action =>
                '<button class="music-icon" type="button" data-music-action="'
                + action + '" aria-label="' + action + '">•</button>').join('')
            + '</div><span data-music-current-title></span>'
            + '<output data-music-playback-status></output></div>';
        return '<section class="gallery-music" data-gallery-music'
            + ' data-music-gallery="' + galleryId
            + '" data-music-editor="' + (editor ? '1' : '0')
            + '"><script type="application/json" data-gallery-music-playlist'
            + ' data-music-gallery="' + galleryId + '" data-music-editor="'
            + (editor ? '1' : '0') + '">' + data + '<' + '/script>'
            + (editor ? '' : '<dl class="gallery-media-summary">'
                + '<dt>Total duration</dt><dd data-music-duration-total>…</dd>'
                + '</dl>')
            + (editor
                ? '<div class="gallery-media-inline"><header><h3>Music</h3>'
                    + '<span class="gallery-media-count">2</span>' + controls
                    + '</header><div class="gallery-media-body">' + playlist
                    + '</div></div>'
                : mediaPanel('playlist-' + galleryId, playlist) + controls)
            + '</section>';
    };
    publicMount.innerHTML = musicCard(42) + musicCard(43, true);
    await import('/public/assets/public-gallery.js');
    if (document.body.dataset.musicPlaybackBound !== '1') {
        await new Promise(resolve => {
            const observer = new MutationObserver(() => {
                if (document.body.dataset.musicPlaybackBound === '1') {
                    observer.disconnect(); resolve();
                }
            });
            observer.observe(document.body, {attributes: true});
        });
    }
    if (document.body.dataset.mediaPanelsBound !== '1') {
        await new Promise(resolve => {
            const observer = new MutationObserver(() => {
                if (document.body.dataset.mediaPanelsBound === '1') {
                    observer.disconnect(); resolve();
                }
            });
            observer.observe(document.body, {attributes: true});
        });
    }
    const publicCard = publicMount.firstElementChild;
    const editorCard = publicMount.lastElementChild;
    check(publicCard.querySelector(':scope > details.gallery-media-widget')
        instanceof HTMLDetailsElement
        && editorCard.querySelector(':scope > .gallery-media-inline')
            instanceof HTMLElement
        && editorCard.querySelector(':scope > details.gallery-media-widget') === null,
    'Public Music must retain its disclosure while an editor card renders its controls inline.');
    const clickRow = (card, id) => card.querySelector(
        '[data-music-action="toggle"][data-music-track-id="' + id + '"]'
    ).click();
    const widget = publicCard.querySelector('details');
    const mini = publicCard.querySelector('[data-gallery-music-controls]');
    const collapsedHeight = publicCard.getBoundingClientRect().height;
    check(!widget.open && mini.getBoundingClientRect().height > 0,
        'Closed playlist hid the always-available miniplayer');
    const durationReady = new Promise(resolve => {
        const observer = new MutationObserver(() => {
            if (publicCard.querySelector('[data-music-duration-total]')
                .textContent === '4:06') {
                observer.disconnect(); resolve();
            }
        });
        observer.observe(publicCard, {subtree: true, childList: true});
    });
    if (publicCard.querySelector('[data-music-duration-total]')
        .textContent !== '4:06') {
        await durationReady;
    }
    check(!widget.open, 'Total duration required opening the playlist');
    widget.querySelector('summary').click();
    const popup = widget.querySelector('[data-gallery-media-panel]');
    await Promise.all(popup.getAnimations().map(item => item.finished));
    check(widget.open && popup.matches(':popover-open')
        && publicCard.getBoundingClientRect().height === collapsedHeight,
        'Opening playlist expanded the gallery instead of its overlay');
    const bounds = popup.getBoundingClientRect();
    check(bounds.left >= 0 && bounds.right <= innerWidth
        && bounds.top >= 0 && bounds.bottom <= innerHeight,
        'Applet escaped the tested viewport');
    clickRow(publicCard, 'one');
    const firstToggle = publicCard.querySelector('[data-music-track-id="one"]');
    check(firstToggle.getAttribute('aria-label') === 'Stop'
        && firstToggle.querySelector('[data-music-toggle-play]').hidden
        && !firstToggle.querySelector('[data-music-toggle-stop]').hidden,
        'Playing row did not replace its one Play icon with Stop');
    clickRow(publicCard, 'two');
    const shared = AudioAdapter.instances.find(item => !item.paused);
    check(shared.src === '/track-two', 'Delegated row play lost selection');
    check(firstToggle.getAttribute('aria-label') === 'Play'
        && !editorCard.querySelector('.is-playing'),
        'Track state leaked into the previous row or another gallery');
    const longTitle = publicCard.querySelector(
        '[data-music-track="two"] [data-music-title]'
    );
    check(longTitle.classList.contains('is-overflowing')
        && getComputedStyle(longTitle.firstElementChild).animationName
            === 'music-title-scroll',
        'Long playing title did not scroll within its clipped row');
    popup.querySelector('[data-media-close]').click();
    await Promise.all(popup.getAnimations().map(item => item.finished));
    check(!widget.open && shared.src === '/track-two' && !shared.paused,
        'Closing playlist stopped or recreated its audio');
    check(document.activeElement === widget.querySelector('summary'),
        'Closing applet lost keyboard focus');
    widget.querySelector('summary').click();
    await Promise.all(popup.getAnimations().map(item => item.finished));
    clickRow(publicCard, 'two');
    check(shared.paused && shared.src === ''
        && getComputedStyle(longTitle.firstElementChild).textOverflow
            === 'ellipsis'
        && getComputedStyle(longTitle.firstElementChild).animationName === 'none',
        'The same row toggle did not stop and restore static ellipsis');
    clickRow(publicCard, 'two');
    getGalleryMusicPlayer().start();
    check(shared.src === '/track-one', 'Slideshow did not replace row playback');
    clickRow(editorCard, 'two');
    check(shared.paused && shared.src === '',
        'Another gallery did not release the old audio owner');
    getGalleryMusicPlayer().start();
    check(AudioAdapter.instances.filter(item => !item.paused).length === 1,
        'Viewer restart overlapped editor playback');
    getGalleryMusicPlayer().closeViewer();
    check(AudioAdapter.instances.every(item => item.paused),
        'Viewer closure retained owned music');
    check(formatMusicDuration(123) === '2:03'
        && formatMusicDuration(3601) === '1:00:01'
        && formatMusicDuration(Infinity) === '—',
        'Duration text did not reflect finite metadata');
    check(AudioAdapter.instances.filter(item => item.preload === 'metadata')
        .every(item => item.calls.length === 0),
        'Duration observation started audible playback');

    const presentation = document.querySelector('[data-fixture-presentation]');
    const share = presentation.querySelector('summary');
    const copyInput = presentation.querySelector('[data-presentation-copy]');
    const copyButton = presentation.querySelector('[data-presentation-copy-button]');
    const shareStatus = presentation.querySelector('[data-presentation-status]');
    let copiedUrl = '';
    Object.defineProperty(navigator, 'clipboard', {configurable: true,
        value: {writeText: async value => { copiedUrl = value; }},
    });
    const sharePage = location.href;
    const presentationFetch = window.fetch;
    const shareRequests = [];
    window.fetch = async (url, options) => {
        if (options?.body?.get('action') !== 'presentation_share') {
            return presentationFetch(url, options);
        }
        check(options.method === 'POST' && options.credentials === 'same-origin'
            && options.headers.Accept === 'application/json'
            && options.body.get('csrf_token') === 'fixture-csrf',
            'Short link generation lost its method, session or CSRF boundary');
        let finish;
        const pending = new Promise(resolve => { finish = resolve; });
        shareRequests.push({body: options.body, finish});
        return pending;
    };
    share.click();
    await turn();
    check(!copiedUrl && presentation.querySelector('details').open,
        'Share must open a configurable applet before copying');
    check(presentation.querySelector('[data-share-option="autoplay"]').checked
        && !presentation.querySelector('[data-share-option="exclusive"]').checked,
        'Autoplay must default on and exclusive playback off');
    for (const key of ['autoplay', 'music', 'exclusive', 'interval', 'transition']) {
        const input = presentation.querySelector('[data-share-option="' + key + '"]');
        if (input.type === 'checkbox') input.checked = key === 'exclusive';
        else input.value = '2';
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }
    const configured = shareRequests.at(-1).body;
    check(configured.get('autoplay') === '0'
        && configured.get('music') === '0'
        && configured.get('exclusive') === '1'
        && configured.get('interval') === 'fast'
        && configured.get('transition') === 'fast'
        && configured.get('lang') === 'en'
        && copyInput.value === '' && copyButton.disabled,
        'Pending sharing must send complete options and suppress stale copy URLs');
    const shortUrl = location.origin + '/presentation=abcd1234';
    shareRequests.at(-1).finish(new Response(JSON.stringify({ok: true, url: shortUrl}),
        {headers: {'Content-Type': 'application/json'}}));
    await turn();
    shareRequests[0].finish(new Response(JSON.stringify({ok: true,
        url: location.origin + '/presentation=00000000'})));
    await turn();
    check(copyInput.value === shortUrl && !copyButton.disabled,
        'A stale response replaced the current short presentation link');
    copyButton.click();
    await turn();
    check(copiedUrl === copyInput.value && location.href === sharePage
        && shareStatus.textContent === 'Copied',
        'Copy did not use the configured URL without navigation');
    navigator.clipboard.writeText = async () => {
        throw new DOMException('Denied', 'NotAllowedError');
    };
    copyButton.click();
    await turn();
    check(!copyInput.hidden && copyInput.value === copiedUrl
        && document.activeElement === copyInput
        && shareStatus.textContent === 'Copy manually',
        'Denied clipboard did not expose a keyboard-selectable launch URL');
    share.click();
    window.fetch = presentationFetch;

    // Eight rows exceed the five-row applet sample; this is a layout vector.
    const documents = document.createElement('section');
    documents.dataset.galleryPdfDocuments = '';
    documents.innerHTML = mediaPanel('documents',
        '<table class="gallery-pdf-table"><tbody>'
        + Array.from({length: 8}, (_, index) =>
            '<tr><td>Document ' + index + '</td><td>1 kB</td>'
            + '<td>1</td><td><button>View</button></td></tr>').join('')
        + '</tbody></table>');
    publicMount.append(documents);
    let documentWidget = documents.querySelector('details');
    documentWidget.querySelector('summary').click();
    let documentPopup = documentWidget.querySelector('[data-gallery-media-panel]');
    await Promise.all(documentPopup.getAnimations().map(item => item.finished));
    check(!widget.open, 'Opening PDF did not retire the previous applet');
    let scrollBody = documentPopup.querySelector('.gallery-media-body');
    check(scrollBody.scrollHeight > scrollBody.clientHeight,
        'Long PDF list did not retain its bounded scroll area');
    const scrolled = new Promise(resolve => {
        scrollBody.addEventListener('scroll', resolve, {once: true});
    });
    scrollBody.scrollTop = scrollBody.scrollHeight;
    await scrolled;
    const retainedScroll = scrollBody.scrollTop;
    const replacement = documentWidget.cloneNode(true);
    replacement.removeAttribute('open');
    replacement.querySelector('[data-gallery-media-panel]')
        .removeAttribute('popover');
    documentWidget.replaceWith(replacement);
    await turn();
    documentWidget = replacement;
    documentPopup = replacement.querySelector('[data-gallery-media-panel]');
    scrollBody = documentPopup.querySelector('.gallery-media-body');
    check(replacement.open && documentPopup.matches(':popover-open')
        && scrollBody.scrollTop === retainedScroll,
        'Card replacement lost applet/scroll: open=' + replacement.open
            + ', visible=' + documentPopup.matches(':popover-open')
            + ', scroll=' + scrollBody.scrollTop + ', expected=' + retainedScroll);
    document.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'Escape', bubbles: true, cancelable: true,
    }));
    await Promise.all(documentPopup.getAnimations().map(item => item.finished));
    check(!replacement.open, 'Escape did not dismiss the owned applet');
    replacement.querySelector('summary').click();
    document.getElementById('results').click();
    await Promise.all(documentPopup.getAnimations().map(item => item.finished));
    check(!replacement.open, 'Outside click did not dismiss the applet');
    // Feature absence is permanent support, not a second form/media owner.
    documentPopup.showPopover = undefined;
    replacement.querySelector('summary').click();
    check(replacement.open && !documentPopup.hasAttribute('popover')
        && documentPopup.getBoundingClientRect().height > 0,
        'Missing Popover API lost native bounded disclosure fallback');
    replacement.querySelector('summary').click();
    await Promise.all(documentPopup.getAnimations().map(item => item.finished));

    const mount = document.querySelector('[data-music-mount]');
    const card = revision => musicCard(44, true).replace(
        '</div></div></section>',
        '<form data-gallery-music-form method="post"'
        + ' action="/index.php?page=admin_edit_gallery&amp;id=44">'
        + '<input name="id" value="44"><input name="csrf_token" value="fixture-csrf">'
        + '<input name="music_revision" value="' + revision + '">'
        + '<button type="submit" name="music_action" value="settings">Save</button>'
        + '<output data-music-status></output></form></div></div></section>'
    );
    mount.innerHTML = card('initial');
    check(mount.querySelector('[data-gallery-music][data-music-editor="1"]')
            instanceof HTMLElement
        && mount.querySelector('.gallery-media-inline') instanceof HTMLElement
        && mount.querySelector('details.gallery-media-widget') === null,
    'The dynamically mounted editor must retain the production mutation owner and inline panel shape.');
    const initialUrl = location.href;
    const nativeFetch = window.fetch;
    let writes = 0;
    let resolveMutation;
    window.fetch = async (url, options) => {
        check(String(url).includes('page=admin_edit_gallery'), 'Music wrote to an unexpected endpoint');
        check(options.method === 'POST' && options.credentials === 'same-origin', 'Music lost protected transport');
        check(options.body.get('csrf_token') === 'fixture-csrf', 'Music lost CSRF');
        check(options.body.get('music_action') === 'settings', 'Submitter action was lost');
        check(options.body.get('music_revision') === (writes === 0 ? 'initial' : 'next-1'), 'Replacement lost its fresh revision');
        writes += 1;
        const result = {ok: true, message: 'Saved',
            mutation: {type: 'gallery.music.settings', entity: 'gallery', action: 'update', entity_ids: [44]},
            panel: {workflow: 'gallery-edit', refresh_url: '', keep_open: true},
            contexts: [{type: 'gallery', gallery_id: 44, url: '/not-visible-gallery'}],
            fallback: {}, panel_html: card('next-' + writes)};
        queueMicrotask(() => resolveMutation?.());
        return new Response(JSON.stringify(result), {headers: {'Content-Type': 'application/json'}});
    };
    setupGalleryMusic();
    setupGalleryMusic();
    // Two replacements are an exact lifecycle boundary: the second form did not
    // exist when setup ran, and must still be intercepted exactly once.
    for (const expected of [1, 2]) {
        const old = mount.querySelector('section');
        const finished = new Promise(resolve => { resolveMutation = resolve; });
        const form = old.querySelector('form');
        form.requestSubmit(form.querySelector('button'));
        await finished;
        await turn();
        check(writes === expected && !old.isConnected, 'Dynamic music form was not replaced once');
        check(mount.querySelector('.gallery-media-inline') instanceof HTMLElement
            && mount.querySelector('.gallery-media-inline').getClientRects().length > 0
            && mount.querySelector('details.gallery-media-widget') === null,
        'Canonical music mutation must preserve the visible inline editor without adding a public disclosure.');
        check(location.href === initialUrl && document.querySelector('aside').dataset.open === '1',
            'Music mutation navigated or closed its originating panel');
    }
    window.fetch = nativeFetch;
    // Resize the gallery container itself, independently of browser width.
    // These are explicit wide/narrow layout vectors, not runtime policies.
    const layout = document.createElement('div');
    layout.className = 'gallery-description-documents';
    layout.innerHTML = '<div class="gallery-description-rich">Description</div>'
        + '<div class="gallery-attachment-applets">'
        + '<section class="gallery-pdf-documents">PDF summary</section>'
        + '<section class="gallery-music">Music summary</section>'
        + '<div class="gallery-presentation">Presentation</div></div>';
    document.body.append(layout);
    const tiles = layout.querySelectorAll('section');
    layout.style.width = '1280px';
    check(tiles[0].getBoundingClientRect().top
        === tiles[1].getBoundingClientRect().top,
        'Wide gallery did not place media summaries beside each other');
    layout.style.width = '340px';
    check(tiles[1].getBoundingClientRect().top
        > tiles[0].getBoundingClientRect().bottom,
        'Narrow gallery did not wrap media summaries vertically');
    check(layout.scrollWidth <= 340,
        'Narrow media cards overflowed the gallery container');
    layout.remove();
    // A fresh document exercises URL startup without dispatching a launch click.
    // Its actual CSS and decoded images catch a zero-sized fullscreen stage.
    for (const [launchFlag, music, mode] of [
        ['presentation', '1', 'blocked'],
        ['presentation', '1', 'allowed'],
        ['presentation', '1', 'mobile'],
        ['autoplay', '0', 'muted'],
        ['presentation', '1', 'paused'],
        ['presentation', '1', 'exclusive'],
    ]) {
        const directLink = document.createElement('iframe');
        directLink.style.cssText = 'width:100%;height:100vh;border:0';
        // Match the narrow portrait boundary below even in the desktop run.
        if (mode === 'mobile') directLink.style.width = '360px';
        const directResult = new Promise(resolve => {
            const receive = event => {
                if (event.origin !== location.origin
                    || event.source !== directLink.contentWindow
                    || event.data?.kind !== 'presentation-fixture') return;
                window.removeEventListener('message', receive);
                resolve(event.data);
            };
            window.addEventListener('message', receive);
        });
        directLink.src = '/presentation?lang=en&' + launchFlag + '=1'
            + '&presentation_music=' + music
            + '&presentation_interval=fast&presentation_transition=fast'
            + (mode === 'paused' ? '&autoplay=0' : '')
            + (mode === 'exclusive' ? '&presentation_exclusive=1' : '')
            + '&fixture_audio=' + mode;
        document.body.prepend(directLink);
        const launched = await directResult;
        check(launched.ok, launched.message || 'Direct presentation failed');
        directLink.remove();
    }
    document.getElementById('results').textContent = 'BROWSER PASS gallery music repeat-all, controls, rejection, teardown and dynamic panel';
} catch (error) {
    document.getElementById('results').textContent = 'BROWSER FAIL ' + (error.stack || String(error));
}
</script></body></html>`;

const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url || '/', 'http://127.0.0.1');
        if (url.pathname === '/') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'}).end(html);
            return;
        }
        if (url.pathname === '/presentation') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            response.end(await readFile(path.join(
                root, 'tests/fixtures/gallery_presentation.html'
            )));
            return;
        }
        if (url.pathname === '/presentation-image.svg') {
            // A real decoded landscape source, with distinct scheduled URLs.
            response.writeHead(200, {'Content-Type': 'image/svg+xml'});
            response.end('<svg xmlns="http://www.w3.org/2000/svg"'
                + ' width="800" height="600"><rect width="800" height="600"'
                + ' fill="slateblue"/></svg>');
            return;
        }
        const file = path.resolve(root, '.' + url.pathname);
        if (!url.pathname.startsWith('/public/assets/') || !file.startsWith(root + path.sep)) {
            response.writeHead(404).end();
            return;
        }
        response.writeHead(200, {'Content-Type': file.endsWith('.css') ? 'text/css' : 'text/javascript'});
        response.end(await readFile(file));
    } catch {
        response.writeHead(404).end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    // Desktop and narrow portrait are exact CSS-layout boundary profiles.
    for (const viewport of [
        {width: 1280, height: 800},
        {width: 360, height: 640},
    ]) {
        const result = await runHeadlessBrowserFixture(
            executable, url, 'gallery-music-browser-', {viewport}
        );
        console.log(result.result);
        assert.equal(result.exitCode, 0);
        assert.ok(result.result.startsWith('BROWSER PASS'),
            'Music browser fixture must finish successfully.');
    }
} finally {
    server.close();
}
