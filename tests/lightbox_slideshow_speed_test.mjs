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

const source = readFileSync(new URL('../public/assets/gallery-modules/lightbox.js', import.meta.url), 'utf8');

test('explicit presentation resets timing and opens photo one with music', () => {
    const opened = [];
    const music = [];
    const context = vm.createContext({
        cards: [{}, {}, {}], lightboxSlideshowEnabled: true,
        // Deliberately different live settings prove the launch resets them.
        lightboxSlideshowVisibleDuration: 4000,
        lightboxSlideshowTransitionDuration: 500,
        lightboxSlideshowSpeedPresets: {
            interval: [4000, 2000, 1000], transition: [2000, 1000, 500],
        },
        overlay: {hasAttribute: () => false, querySelectorAll: () => []},
        stopLightboxSlideshow: () => {}, refreshLightboxOrderFromDom: () => {},
        openAt: index => opened.push(index),
        startLightboxSlideshow: force => music.push(force),
    });
    vm.runInContext(productionFunction('presentGalleryFromStart'), context);
    context.presentGalleryFromStart();
    assert.deepEqual(opened, [0]);
    assert.deepEqual(music, [true]);
    assert.equal(context.lightboxSlideshowVisibleDuration, 2000);
    assert.equal(context.lightboxSlideshowTransitionDuration, 1000);
    context.lightboxSlideshowEnabled = false;
    context.presentGalleryFromStart();
    assert.deepEqual(opened, [0], 'Disabled slideshow must remain unavailable.');
});

test('presentation links launch once and require an eligible gallery control', async () => {
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
            querySelector: selector => selector === '[data-gallery-presentation-start]'
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
    assert.equal(launches.length, 2, 'Missing authorization/control must not launch.');
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
    const classes = new Set(['is-ui-visible']);
    const context = vm.createContext({
        AbortController, HTMLInputElement: Slider, HTMLElement: SpeedPanel,
        timers: new Map(), nextTimer: 0, loads: [], opened: [], hudShown: 0, panel,
        cards: [{}, {}, {}], lightboxNavigation: createLightboxNavigationLifecycle(),
        lightboxSlideshowTimer: null, lightboxSlideshowScheduleToken: 0,
        lightboxSlideshowPreloadController: null, lightboxSlideshowActive: true,
        musicPlayer: {stop: () => {}},
        lightboxDefaultTransitionDuration: 160,
        overlay: {
            hidden: false, dataset: {}, querySelector: () => panel,
            classList: {contains: name => classes.has(name), remove: name => classes.delete(name), add: name => classes.add(name)},
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
        'stopLightboxSlideshow', 'shouldKeepLightboxHudVisible', 'finishLightboxSlideshowSpeedPointer'].map(productionFunction).join('\n');
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

/**
 * Open exactly one timer gate without letting an older timer run twice.
 * @param {import('node:vm').Context} context Fixture containing the controlled production timers.
 * @return {void} Executes and removes the sole active slideshow timeout.
 */
function expire(context) {
    assert.equal(context.timers.size, 1);
    const [id, timer] = [...context.timers][0];
    context.timers.delete(id);
    timer.callback();
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
    assert.match(view, /\['value' => 'interval', 'label' =>/);
    assert.match(view, /\['value' => 'transition', 'label' =>/);
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
