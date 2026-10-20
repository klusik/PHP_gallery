/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/lightbox_slideshow_speed_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise live slideshow timing changes through the production closure.
 * Responsibilities:
 *   - Verify independent presets, stale cycle rejection and presentation/cleanup gates.
 *   - Protect complete HUD inactivity hiding, keyboard routing and all four active catalogs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';
import vm from 'node:vm';
import {createLightboxNavigationLifecycle} from '../public/assets/gallery-modules/lightbox-navigation-lifecycle.js';
import {
    galleryPresentationOptions, GALLERY_PRESENTATION_SPEEDS,
} from '../public/assets/gallery-modules/gallery-presentation.js';

const source = readFileSync(new URL('../public/assets/gallery-modules/lightbox.js', import.meta.url), 'utf8');

test('presentation resets timing and prepares photo one before music', () => {
    const opened = [];
    const music = [];
    const context = vm.createContext({
        presentationOptions: {music: true, autoplay: true, fullscreen: true,
            exclusive: false, interval: 'medium', transition: 'medium'},
        LIGHTBOX_PRESENTATION_INTRO_MS: 1000, performance: {now: () => 0},
        lightboxPresentationIntro: null, lightboxSlideshowActive: false,
        GALLERY_PRESENTATION_SPEEDS,
        cards: [{}, {}, {}], lightboxSlideshowEnabled: true,
        // Deliberately different live settings prove the launch resets them.
        lightboxSlideshowVisibleDuration: 4000,
        lightboxSlideshowTransitionDuration: 500,
        lightboxSlideshowSpeedPresets: {
            interval: [4000, 2000, 1000], transition: [2000, 1000, 500],
        },
        overlay: {querySelectorAll: () => [],
            classList: {add: () => {}, toggle: () => {}}},
        musicPlayer: {start: (...args) => music.push(args)},
        enterLightboxFullscreen: () => Promise.resolve(true),
        syncLightboxSlideshowControls: () => {},
        scheduleLightboxSlideshowNext: () => {},
        stopLightboxSlideshow: () => {}, refreshLightboxOrderFromDom: () => {},
        openAt: index => opened.push(index),
        startLightboxSlideshow: force => music.push(force),
    });
    vm.runInContext(productionFunction('presentGalleryFromStart'), context);
    context.presentGalleryFromStart();
    assert.deepEqual(opened, [0]);
    assert.deepEqual(music, [[false, true]], 'Opening must hold music silent.');
    assert.equal(context.lightboxPresentationIntro.music, true);
    assert.equal(context.lightboxPresentationIntro.readyAt, 1000);
    assert.equal(context.lightboxSlideshowVisibleDuration, 2000);
    assert.equal(context.lightboxSlideshowTransitionDuration, 1000);
    context.presentationOptions = {music: false, autoplay: false, fullscreen: false,
        exclusive: false, interval: 'fast', transition: 'slow'};
    context.presentGalleryFromStart();
    assert.equal(context.lightboxSlideshowVisibleDuration, 1000);
    assert.equal(context.lightboxSlideshowTransitionDuration, 2000);
    assert.equal(context.lightboxPresentationIntro.music, false);
    assert.equal(context.lightboxSlideshowActive, false, 'Paused link must stay paused.');
    context.lightboxSlideshowEnabled = false;
    context.presentGalleryFromStart();
    assert.deepEqual(opened, [0, 0], 'Disabled slideshow must remain unavailable.');
});

test('presentation links launch once and require an authorized viewer', async () => {
    const deferred = readFileSync(new URL(
        '../public/assets/gallery-modules/lightbox-deferred.js', import.meta.url
    ), 'utf8');
    const setup = deferred.match(
        /^export function setupGalleryLightbox\([^]*?^}/m
    );
    assert.ok(setup, 'Deferred setup owner must remain available.');
    const launches = [];
    let eligible = true;
    const state = {
        setupToken: 0, presentationConsumed: false,
        module: {startGalleryPresentation: () => launches.push('start')},
    };
    const context = vm.createContext({
        AbortController, URLSearchParams,
        deferredLightboxState: state,
        location: {search: '?lang=cs&presentation=1'}, window: {},
        document: {
            querySelector: selector => selector === '[data-lightbox]'
                ? (eligible ? {} : null) : {},
            addEventListener: () => {},
        },
        teardownGalleryLightbox: () => {},
        scheduleIdleLightboxActivation: () => {},
        activateFullLightbox: async () => { launches.push('activate'); },
    });
    vm.runInContext(setup[0].replace('export ', ''), context);
    context.setupGalleryLightbox();
    await Promise.resolve();
    assert.deepEqual(launches, ['activate', 'start']);
    context.setupGalleryLightbox();
    await Promise.resolve();
    assert.equal(launches.length, 2, 'Fragment refresh must not replay a link.');
    state.presentationConsumed = false;
    eligible = false;
    context.setupGalleryLightbox();
    await Promise.resolve();
    assert.equal(launches.length, 2, 'Missing authorized viewer must not launch.');
});

test('link snapshots override saved defaults with a closed playback vocabulary', () => {
    const original = globalThis.location;
    globalThis.location = {href: 'https://gallery.test/album/'};
    try {
        const overlay = {dataset: {presentationMusic: '1',
            presentationInterval: 'slow', presentationTransition: 'medium'}};
        const intent = galleryPresentationOptions(overlay,
            '?autoplay=1&presentation_music=0&presentation_interval=fast'
                + '&presentation_transition=slow');
        assert.deepEqual(intent, {autoplay: true, fullscreen: true, exclusive: false,
            music: false, interval: 'fast', transition: 'slow'});
        const invalid = galleryPresentationOptions(overlay,
            '?presentation=1&presentation_music=invalid'
                + '&presentation_interval=-1&presentation_transition=999999');
        assert.deepEqual(invalid, {autoplay: true, fullscreen: true, exclusive: false,
            music: true, interval: 'slow', transition: 'medium'});
        const paused = galleryPresentationOptions(overlay,
            '?presentation=1&autoplay=0&presentation_fullscreen=0'
                + '&presentation_exclusive=1');
        assert.equal(paused.autoplay, false);
        assert.equal(paused.fullscreen, false);
        assert.equal(paused.exclusive, true);
        assert.deepEqual(galleryPresentationOptions(overlay,
            '?presentation_music=0&presentation_interval=fast'),
        {autoplay: true, fullscreen: true, exclusive: false,
            music: true, interval: 'slow', transition: 'medium'},
        'Ordinary gallery parameters must not change playback defaults.');
    } finally {
        if (original === undefined) delete globalThis.location;
        else globalThis.location = original;
    }
});

/**
 * Extract one production declaration at its actual viewer-closure indentation.
 * @param {string} name Production function name to execute in the adapter.
 * @return {string} Complete declaration, or throws when the owner is missing.
 */
function productionFunction(name) {
    const match = source.match(new RegExp(`^    (?:async )?function ${name}\\([^]*?^    }`, 'm'));
    assert.ok(match, `Missing production declaration: ${name}`);
    return match[0];
}

/** Native range adapter exposing only the DOM boundary used by the input handler. */
class Slider {
    /**
     * Prepare a three-position range and its displayed option labels.
     * @param {string} setting Interval or transition setting identity.
     * @param {string} value Selected native range value.
     * @return {void} Initializes an independent range with medium selected initially.
     */
    constructor(setting, value) {
        this.dataset = {lightboxSlideshowSpeed: setting};
        this.value = value;
        this.attributes = {};
        this.options = ['Slow', 'Medium', 'Fast'].map((textContent, index) => ({
            textContent, selected: index === 1,
            classList: {toggle: (name, selected) => { this.options[index].selected = selected; }},
        }));
    }

    /**
     * Resolve the owning label containing this range's discrete options.
     * @param {string} selector DOM ancestor selector used by production.
     * @return {{querySelectorAll:function(string):Array<{textContent:string,selected:boolean,classList:{toggle:function(string,boolean):void}}>}} Owning label adapter.
     */
    closest(selector) {
        assert.equal(selector, 'label');
        return {querySelectorAll: () => this.options};
    }

    /**
     * Capture accessible text updates performed by the real input handler.
     * @param {string} name Attribute name assigned by production.
     * @param {string} value Accessible text value.
     * @return {void} Records the attribute for assertions.
     */
    setAttribute(name, value) {
        this.attributes[name] = value;
    }
}

/** Speed-panel adapter allowing focused and hovered HUD retention checks. */
class SpeedPanel {
    /**
     * Test whether this panel owns the focused range.
     * @param {Slider|null} element Active document element supplied by production.
     * @return {boolean} True for a focused range adapter.
     */
    contains(element) {
        return element instanceof Slider;
    }

    /**
     * Expose panel hover independently from keyboard focus.
     * @param {string} selector Selector used to inspect pointer hover.
     * @return {boolean} Whether the panel is hovered.
     */
    matches(selector) {
        assert.equal(selector, ':hover');
        return Boolean(this.hovered);
    }
}

/**
 * Build controlled timers and deferred decodes around production slideshow functions.
 * @return {import('node:vm').Context} Production closure context with timers (Map of ID to callback/duration), loads (index/signal/resolve), opened (index/options) and panel observations.
 */
function fixture() {
    const panel = new SpeedPanel();
    const confirm = {disabled: false, focus: () => {}};
    const consent = {hidden: true, querySelector: () => confirm};
    const classes = new Set(['is-ui-visible']);
    const context = vm.createContext({
        AbortController, HTMLInputElement: Slider, HTMLElement: SpeedPanel,
        timers: new Map(), nextTimer: 0, loads: [], opened: [], hudShown: 0, panel,
        cards: [{}, {}, {}], lightboxNavigation: createLightboxNavigationLifecycle(),
        lightboxSlideshowTimer: null, lightboxSlideshowScheduleToken: 0,
        lightboxSlideshowPreloadController: null, lightboxSlideshowActive: true,
        musicPlayer: {stop: () => {}, start: () => Promise.resolve('playing')},
        presentationOptions: {fullscreen: true},
        enterLightboxFullscreen: () => Promise.resolve(true),
        consent, confirm,
        syncLightboxSlideshowControls: () => {},
        lightboxPresentationIntro: null, stageLink: null,
        performance: {now: () => 0},
        lightboxDefaultTransitionDuration: 160,
        overlay: {
            hidden: false, dataset: {},
            querySelector: selector => selector === '[data-presentation-consent]'
                ? consent : selector === '[data-presentation-confirm]'
                    ? confirm : panel,
            classList: {contains: name => classes.has(name),
                remove: (...names) => names.forEach(name => classes.delete(name)),
                add: (...names) => names.forEach(name => classes.add(name))},
        },
        document: {activeElement: null},
        isLightboxFullscreen: () => true,
        hideLightboxHud: () => {},
        showLightboxHud: () => { context.hudShown += 1; },
        prepareLightboxSlideshowImage: (index, signal) => new Promise(resolve => context.loads.push({index, signal, resolve})),
        openAt: (index, options) => {
            context.opened.push({index, options});
            context.clearLightboxSlideshowTimer();
            context.lightboxNavigation.begin(index, String(index));
        },
        window: {
            setTimeout: (callback, duration) => {
                const id = ++context.nextTimer;
                context.timers.set(id, {callback, duration});
                return id;
            },
            clearTimeout: id => context.timers.delete(id),
        },
    });
    const start = source.indexOf('    let lightboxSlideshowVisibleDuration');
    const end = source.indexOf('    // lightboxSlideshowEnabled', start);
    assert.ok(start >= 0 && end > start, 'Production timing state must be extracted intact.');
    const declarations = ['readLightboxTimingSetting', 'currentLightboxTransitionDuration',
        'updateLightboxSlideshowSpeed', 'clearLightboxSlideshowTimer', 'scheduleLightboxSlideshowNext',
        'stopLightboxSlideshow', 'cancelLightboxPresentationIntro',
        'showLightboxPresentationConsent', 'confirmLightboxPresentation',
        'revealLightboxPresentation',
        'scheduleLightboxPresentationIntro', 'shouldKeepLightboxHudVisible',
        'finishLightboxSlideshowSpeedPointer'].map(productionFunction).join('\n');
    vm.runInContext(`${declarations}\n${source.slice(start, end)}`, context);
    const transaction = context.lightboxNavigation.begin(0, '0');
    context.lightboxNavigation.settle(0, transaction.generation, 'displayed');
    return context;
}

/**
 * Drain deferred decode/finally/advance continuations without wall-clock sleeps.
 * @return {Promise<void>} Resolves after the controlled promise chain settles.
 */
async function settle() {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
}

test('black intro waits for decode and never becomes a repeated slide', async () => {
    const context = fixture();
    const started = [];
    context.musicPlayer.start = (...args) => started.push(args);
    context.lightboxPresentationIntro = {
        readyAt: 1000, music: true, autoplay: true, animation: null,
        fullscreen: Promise.resolve(true), phase: 'preparing',
    };
    context.lightboxNavigation.begin(0, '0');
    context.scheduleLightboxSlideshowNext();
    assert.equal(context.timers.size, 0, 'Decode must gate the opening hold.');
    context.lightboxNavigation.settle(0, context.lightboxNavigation.generation, 'displayed');
    context.scheduleLightboxSlideshowNext();
    const [id, hold] = [...context.timers][0];
    assert.equal(hold.duration, 1000);
    assert.equal(started.length, 0, 'Audio must wait for the reveal.');
    context.timers.delete(id);
    await hold.callback();
    await settle();
    assert.deepEqual(started, [[true, false]]);
    assert.equal(context.lightboxPresentationIntro, null);
    assert.equal(context.loads.length, 1, 'Reveal must hand off to the existing scheduler.');
    context.lightboxPresentationIntro = {
        readyAt: 1000, music: true, autoplay: true, animation: null,
        fullscreen: Promise.resolve(true), phase: 'preparing',
    };
    context.scheduleLightboxSlideshowNext();
    const cancelled = [...context.timers.values()][0];
    context.stopLightboxSlideshow();
    await cancelled.callback();
    await settle();
    assert.equal(started.length, 1, 'Cancelled intro must not restart audio.');
});

test('activation refusal holds black until one synchronous confirmation', async () => {
    for (const refusal of ['fullscreen', 'music']) {
        const context = fixture();
        const calls = [];
        let firstPlay = true;
        context.musicPlayer.start = () => {
            calls.push('audio');
            const result = firstPlay && refusal === 'music' ? 'blocked' : 'playing';
            firstPlay = false;
            return Promise.resolve(result);
        };
        context.enterLightboxFullscreen = () => {
            calls.push('fullscreen');
            // Unsupported fullscreen may retain the viewport after confirmation.
            return Promise.resolve(false);
        };
        context.lightboxPresentationIntro = {
            readyAt: 1000, music: true, autoplay: true, animation: null,
            fullscreen: Promise.resolve(refusal !== 'fullscreen'), phase: 'preparing',
        };
        context.overlay.classList.add('is-presentation-intro');
        context.scheduleLightboxSlideshowNext();
        // The timer's async callback owns both permission checks. Await that
        // completion across VM realms rather than guessing microtask depth.
        await expire(context);
        await settle();
        assert.equal(context.lightboxPresentationIntro.phase, 'consent');
        assert.equal(context.consent.hidden, false);
        assert.equal(context.loads.length, 0, 'Waiting must never advance photo one.');
        assert.equal(context.overlay.classList.contains('is-presentation-intro'), true);
        if (refusal === 'fullscreen') assert.deepEqual(calls, []);
        calls.length = 0;
        context.confirmLightboxPresentation();
        assert.deepEqual(calls, ['audio', 'fullscreen'],
            'Both native requests must precede any asynchronous continuation.');
        context.confirmLightboxPresentation();
        assert.equal(calls.length, 2, 'Double confirmation must not restart music.');
        await settle();
        assert.equal(context.consent.hidden, true);
        assert.equal(context.lightboxPresentationIntro, null);
        assert.equal(context.loads.length, 1);
    }
});

test('closing during confirmation cancels late reveal and paused links stay silent', async () => {
    const context = fixture();
    const calls = [];
    let finish;
    context.musicPlayer.start = () => {
        calls.push('audio');
        return new Promise(resolve => { finish = resolve; });
    };
    context.lightboxPresentationIntro = {
        readyAt: 1000, music: true, autoplay: true, animation: null,
        fullscreen: Promise.resolve(false), phase: 'consent',
    };
    context.consent.hidden = false;
    context.confirmLightboxPresentation();
    context.stopLightboxSlideshow();
    context.overlay.hidden = true;
    finish('playing');
    await settle();
    assert.equal(context.lightboxPresentationIntro, null);
    assert.equal(context.consent.hidden, true);
    assert.equal(context.loads.length, 0);
    context.overlay.hidden = false;
    context.lightboxPresentationIntro = {
        readyAt: 1000, music: true, autoplay: false, animation: null,
        fullscreen: Promise.resolve(false), phase: 'consent',
    };
    context.confirmLightboxPresentation();
    await settle();
    assert.deepEqual(calls, ['audio'], 'Paused confirmation must not start music.');
    assert.equal(context.loads.length, 0, 'Paused link must not schedule advances.');
});

/**
 * Open exactly one timer gate without letting an older timer run twice.
 * @param {import('node:vm').Context} context Fixture containing the controlled production timers.
 * @return {void|Promise<void>} Synchronous tick or completion of the async
 *     launch gate, after removing the sole active slideshow timeout.
 */
function expire(context) {
    assert.equal(context.timers.size, 1);
    const [id, timer] = [...context.timers][0];
    context.timers.delete(id);
    return timer.callback();
}

test('presets remain independent and changing interval replaces the pending cycle', () => {
    const context = fixture();
    context.scheduleLightboxSlideshowNext();
    assert.equal([...context.timers.values()][0].duration, 2000);
    assert.equal(context.currentLightboxTransitionDuration(), 1000);
    const original = context.loads[0];
    const generation = context.lightboxNavigation.generation;
    for (const [value, duration] of [['2', 1000], ['0', 4000], ['1', 2000], ['2', 1000]]) {
        const slider = new Slider('interval', value);
        context.updateLightboxSlideshowSpeed({target: slider});
        assert.equal(context.timers.size, 1);
        assert.equal([...context.timers.values()][0].duration, duration);
        assert.equal(slider.options.filter(option => option.selected).length, 1);
        assert.equal(slider.attributes['aria-valuetext'], slider.options[Number(value)].textContent);
    }
    assert.equal(original.signal.aborted, true);
    assert.equal(context.currentLightboxTransitionDuration(), 1000);
    assert.equal(context.lightboxNavigation.generation, generation);
    const timer = [...context.timers.keys()][0];
    for (const [value, duration] of [['0', 2000], ['2', 500], ['1', 1000]]) {
        context.updateLightboxSlideshowSpeed({target: new Slider('transition', value)});
        assert.equal(context.currentLightboxTransitionDuration(), duration);
        assert.deepEqual([...context.timers.keys()], [timer]);
        assert.equal([...context.timers.values()][0].duration, 1000);
    }
    assert.equal(context.opened.length, 0);
});

test('elapsed timers waiting for decode cannot advance after repeated input or stop', async () => {
    const context = fixture();
    context.scheduleLightboxSlideshowNext();
    const stale = context.loads[0];
    expire(context);
    context.updateLightboxSlideshowSpeed({target: new Slider('interval', '0')});
    stale.resolve({index: 1, src: 'old', image: {}});
    await settle();
    assert.equal(stale.signal.aborted, true);
    assert.equal(context.opened.length, 0);
    const current = context.loads.at(-1);
    current.resolve({index: 1, src: 'current', image: {}});
    await settle();
    assert.equal(context.opened.length, 0, 'A decoded photo must still wait for its display interval.');
    expire(context);
    await settle();
    assert.equal(context.opened.length, 1);
    assert.equal(context.opened[0].options.slideshowPreparedSrc, 'current');
    context.updateLightboxSlideshowSpeed({target: new Slider('interval', '2')});
    assert.equal(context.timers.size, 0, 'No new cycle may overlap the pending image presentation.');
    context.lightboxNavigation.settle(1, context.lightboxNavigation.generation, 'displayed');
    context.scheduleLightboxSlideshowNext();
    assert.equal([...context.timers.values()][0].duration, 1000);
    const stopped = context.loads.at(-1);
    expire(context);
    context.stopLightboxSlideshow(false);
    stopped.resolve({index: 2, src: 'stopped', image: {}});
    await settle();
    assert.equal(stopped.signal.aborted, true);
    assert.equal(context.timers.size, 0);
    assert.equal(context.opened.length, 1);
    assert.equal(context.currentLightboxTransitionDuration(), 160, 'Manual navigation retains its existing transition.');
});

test('HUD hides fully after inactivity despite focus/hover, but remains visible during a drag', () => {
    const context = fixture();
    assert.equal(context.shouldKeepLightboxHudVisible(), false);
    context.document.activeElement = new Slider('transition', '1');
    assert.equal(context.shouldKeepLightboxHudVisible(), false);
    context.document.activeElement = null;
    context.panel.hovered = true;
    assert.equal(context.shouldKeepLightboxHudVisible(), false);
    context.panel.hovered = false;
    context.fullscreenHideTimer = null;
    context.isMobileTouchDevice = false;
    context.clearTimeout = context.window.clearTimeout;
    vm.runInContext([productionFunction('clearLightboxHudTimer'), productionFunction('showLightboxHud')].join('\n'), context);
    context.showLightboxHud();
    expire(context);
    assert.equal(context.overlay.classList.contains('is-ui-visible'), false);
    vm.runInContext('lightboxSlideshowSpeedPointerActive = true;', context);
    context.showLightboxHud();
    assert.equal(context.shouldKeepLightboxHudVisible(), true);
    assert.equal(context.timers.size, 0);
    assert.equal(context.overlay.classList.contains('is-ui-visible'), true);
    context.finishLightboxSlideshowSpeedPointer();
    assert.equal(context.shouldKeepLightboxHudVisible(), false);
    expire(context);
    assert.equal(context.overlay.classList.contains('is-ui-visible'), false);
    context.overlay.hidden = true;
    context.scheduleLightboxSlideshowNext();
    assert.equal(context.loads.length, 0);
    assert.equal(context.timers.size, 0);
});

test('markup and keyboard routing expose exactly two localized native ranges', () => {
    const view = readFileSync(new URL('../app/views/public_gallery_lightbox.php', import.meta.url), 'utf8');
    assert.match(view, /\[\s*'value'\s*=>\s*'interval',\s*'label'\s*=>/);
    assert.match(view, /\[\s*'value'\s*=>\s*'transition',\s*'label'\s*=>/);
    assert.match(view, /type="range" min="0" max="2" step="1" value="1"/);
    assert.match(view, /aria-valuetext=/);
    const css = readFileSync(new URL('../public/assets/styles/lightbox.css', import.meta.url), 'utf8');
    assert.match(css, /\.lightbox\.is-fullscreen \.lightbox-hud,[^}]*\.gallery-dev-overlay\s*\{\s*opacity: 0;\s*visibility: hidden;[^}]*pointer-events: none;/);
    const guard = source.indexOf(
        "'[data-lightbox-slideshow-speeds], [data-lightbox-music]'"
    );
    const navigation = source.indexOf("if (event.key === 'ArrowLeft')", guard);
    assert.ok(guard > 0 && navigation > guard);
    assert.match(source.slice(guard, navigation), /return;/);
    for (const language of ['en', 'cs', 'de', 'sv']) {
        const catalog = JSON.parse(readFileSync(new URL(`../app/lang/${language}.json`, import.meta.url), 'utf8'));
        const dictionary = readFileSync(new URL(`../app/lang/${language}.php`, import.meta.url), 'utf8');
        for (const suffix of ['photo_speed', 'transition_speed', 'speed_fast', 'speed_medium', 'speed_slow', 'speed_help']) {
            const key = `lightbox.slideshow_${suffix}`;
            assert.ok(catalog[key]?.trim(), `${language}: missing ${key}`);
            assert.ok(dictionary.includes(`'${key}' =>`), `${language}: missing maintained PHP dictionary key ${key}`);
        }
    }
});
