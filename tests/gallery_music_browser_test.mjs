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
</head><body><pre id="results"></pre>
<aside data-admin-side-panel data-open="1"><div data-music-mount></div></aside>
<div data-fixture-music></div>
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
} from '/public/assets/gallery-modules/gallery-music-player.js?v=20261017-music-v3';
import {setupGalleryMusic} from '/public/assets/gallery-modules/admin-gallery-music.js';
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
        {id: 'two', url: '/track-two', title: 'Two'},
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
    silent.start();
    check(silentAudio.calls.length === 0, 'Default-off gallery started music');
    silentAudio.results.push(Promise.reject(
        new DOMException('Gesture required', 'NotAllowedError')
    ));
    silent.command('play', '', true);
    await turn();
    check(silentAudio.paused && silentAudio.src === ''
        && controls.querySelector('output').textContent !== '',
        'Autoplay rejection was not contained');
    silent.command('play', '', true);
    check(silentAudio.src === '/track-one', 'Gesture retry failed');
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
    broken.start();
    await turn();
    check(brokenAudio.calls.length === tracks.length && brokenAudio.src === '',
        'Unsupported playlist did not stop after one bounded pass');
    broken.destroy();

    window.Audio = AudioAdapter;
    const publicMount = document.querySelector('[data-fixture-music]');
    const musicCard = (galleryId, editor = false) => {
        const data = JSON.stringify({galleryId, enabled: true, tracks});
        return '<section data-gallery-music data-music-gallery="' + galleryId
            + '"><script type="application/json" data-gallery-music-playlist'
            + ' data-music-gallery="' + galleryId + '" data-music-editor="'
            + (editor ? '1' : '0') + '">' + data + '<' + '/script>'
            + '<div data-gallery-music-controls></div>'
            + tracks.map(track => '<div data-music-track="' + track.id + '">'
                + '<button data-music-action="play" data-music-track-id="'
                + track.id + '">▶</button><button data-music-action="stop"'
                + ' data-music-track-id="' + track.id + '">■</button>'
                + '<span data-music-duration data-music-src="' + track.url
                + '"></span></div>').join('') + '</section>';
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
    const publicCard = publicMount.firstElementChild;
    const editorCard = publicMount.lastElementChild;
    const clickRow = (card, id) => card.querySelector(
        '[data-music-action="play"][data-music-track-id="' + id + '"]'
    ).click();
    clickRow(publicCard, 'one');
    clickRow(publicCard, 'two');
    const shared = AudioAdapter.instances.find(item => !item.paused);
    check(shared.src === '/track-two', 'Delegated row play lost selection');
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
    const durationReady = new Promise(resolve => {
        const observer = new MutationObserver(() => {
            if (publicCard.querySelector('[data-music-duration]').textContent === '2:03') {
                observer.disconnect(); resolve();
            }
        });
        observer.observe(publicCard, {subtree: true, childList: true});
    });
    if (publicCard.querySelector('[data-music-duration]').textContent !== '2:03') {
        await durationReady;
    }
    check(AudioAdapter.instances.filter(item => item.preload === 'metadata')
        .every(item => item.calls.length === 0),
        'Duration observation started audible playback');

    const mount = document.querySelector('[data-music-mount]');
    const card = revision => '<section data-gallery-music data-music-error="Save failed">'
        + '<form data-gallery-music-form method="post" action="/index.php?page=admin_edit_gallery&amp;id=42">'
        + '<input name="id" value="42"><input name="csrf_token" value="fixture-csrf">'
        + '<input name="music_revision" value="' + revision + '">'
        + '<button type="submit" name="music_action" value="settings">Save</button>'
        + '<output data-music-status></output></form></section>';
    mount.innerHTML = card('initial');
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
            mutation: {type: 'gallery.music.settings', entity: 'gallery', action: 'update', entity_ids: [42]},
            panel: {workflow: 'gallery-edit', refresh_url: '', keep_open: true},
            contexts: [{type: 'gallery', gallery_id: 42, url: '/not-visible-gallery'}],
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
        old.querySelector('form').requestSubmit(old.querySelector('button'));
        await finished;
        await turn();
        check(writes === expected && !old.isConnected, 'Dynamic music form was not replaced once');
        check(location.href === initialUrl && document.querySelector('aside').dataset.open === '1',
            'Music mutation navigated or closed its originating panel');
    }
    window.fetch = nativeFetch;
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
    const result = await runHeadlessBrowserFixture(executable, url, 'gallery-music-browser-');
    console.log(result.result);
    assert.equal(result.exitCode, 0);
    assert.ok(result.result.startsWith('BROWSER PASS'), 'Music browser fixture must finish successfully.');
} finally {
    server.close();
}
