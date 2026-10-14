/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/floating_scroll_controls_scenarios.mjs
 * Module Type: Node Regression Test
 * Purpose: Execute the real scroll control browser module against a deterministic DOM seam.
 * Responsibilities:
 *   - Prove independent Top/Down visibility at the document edges
 *   - Verify smooth/reduced-motion scrolling and accessible focus continuity
 *   - Prove resize, lightbox, fullscreen and fragment replacement lifecycles
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = readFileSync(new URL('../public/assets/gallery-modules/back-to-top.js', import.meta.url), 'utf8');
const controlledGlobals = ['window', 'document', 'Element', 'HTMLElement', 'ResizeObserver', 'MutationObserver'];
const originals = new Map(controlledGlobals.map(name => [name, {
    present: Object.prototype.hasOwnProperty.call(globalThis, name),
    value: globalThis[name],
}]));

try {
    const listeners = {window: new Map(), document: new Map()};
    const callbacks = new Map();
    const scrollCalls = [];
    const observers = {resize: [], mutation: []};
    let frameSequence = 0;
    let prefersReducedMotion = false;

    /**
     * Minimal connected control or listing node for browser-module interactions.
     * Its selector identifies which delegated click target matches.
     */
    class TestElement {
        /**
         * Initialize a connected element with a mutable visible state.
         *
         * @param {string} selector DOM selector represented by this fixture.
         * @return {void} Initialize independent CSS and focus tracking.
         */
        constructor(selector) {
            this.selector = selector;
            this.isConnected = true;
            this.hidden = true;
            this.visible = false;
            this.classList = {
                toggle: (name, enabled) => { if (name === 'is-visible') this.visible = enabled; },
            };
        }

        /**
         * Resolve the delegated click's nearest navigation button.
         *
         * @param {string} selector Delegated selector-list query.
         * @return {TestElement|null} This navigation control or no matching ancestor.
         */
        closest(selector) {
            return selector.includes(this.selector) ? this : null;
        }

        /**
         * Check the synthetic button's single directional selector.
         *
         * @param {string} selector Selector to compare.
         * @return {boolean} Whether this fixture represents the requested control.
         */
        matches(selector) {
            return selector === this.selector;
        }

        /**
         * Record native programmatic focus transfer without additional scrolling.
         *
         * @param {{preventScroll:boolean}} options Expected focus options.
         * @return {void} Update the active element.
         */
        focus(options) {
            assert.equal(options.preventScroll, true);
            fakeDocument.activeElement = this;
        }

        /**
         * Return listing bounds relative to current browser scroll position.
         *
         * @return {{top:number,bottom:number}} Minimal viewport rectangle.
         */
        getBoundingClientRect() {
            return this.selector === '[data-back-to-top-list]'
                ? {top: 200 - fakeWindow.scrollY, bottom: 1950 - fakeWindow.scrollY}
                : {top: 0, bottom: 1950 - fakeWindow.scrollY};
        }
    }

    /**
     * Browser ResizeObserver fixture which records its observed elements.
     */
    class TestResizeObserver {
        /**
         * Capture a production resize callback.
         *
         * @param {Function} callback Production coalesced visibility scheduler.
         * @return {void} Register this observer for the fixture.
         */
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            this.disconnected = false;
            observers.resize.push(this);
        }

        /**
         * Record a watched viewport/document element.
         *
         * @param {Element} target Observable element.
         * @return {void} Add the observed element.
         */
        observe(target) { this.targets.push(target); }

        /**
         * Mark the browser observation as released.
         *
         * @return {void} Record the disconnect.
         */
        disconnect() { this.disconnected = true; }
    }

    /**
     * Browser MutationObserver fixture that simulates a body class or child change.
     */
    class TestMutationObserver {
        /**
         * Capture the re-evaluation callback.
         *
         * @param {Function} callback Production visibility scheduler.
         * @return {void} Register the synthetic observer.
         */
        constructor(callback) {
            this.callback = callback;
            this.disconnected = false;
            observers.mutation.push(this);
        }

        /**
         * Validate the bounded observation target and options.
         *
         * @param {HTMLElement} target Body element being observed.
         * @param {{attributes:boolean,attributeFilter:string[],childList:boolean}} options Observer configuration.
         * @return {void} Verify no unbounded subtree watch.
         */
        observe(target, options) {
            assert.equal(target, fakeDocument.body);
            assert.deepEqual(options.attributeFilter, ['class']);
            assert.equal(options.childList, true);
            assert.equal(options.subtree, undefined);
        }

        /**
         * Mark the browser observation as released.
         *
         * @return {void} Record the disconnect.
         */
        disconnect() { this.disconnected = true; }
    }

    const bodyClasses = new Set();
    const elementMap = new Map([
        ['[data-back-to-top-scope]', new TestElement('[data-back-to-top-scope]')],
        ['[data-back-to-top-list]', new TestElement('[data-back-to-top-list]')],
        ['[data-back-to-top-button]', new TestElement('[data-back-to-top-button]')],
        ['[data-scroll-to-bottom-button]', new TestElement('[data-scroll-to-bottom-button]')],
    ]);
    const subscribe = (map, type, listener, options = {}) => {
        if (!map.has(type)) map.set(type, new Set());
        map.get(type).add(listener);
        if (options.signal) options.signal.addEventListener('abort', () => map.get(type)?.delete(listener), {once: true});
    };
    const emit = (map, type, event = {}) => {
        for (const callback of map.get(type) || []) callback(event);
    };
    const flush = () => {
        const scheduled = Array.from(callbacks.values());
        callbacks.clear();
        for (const callback of scheduled) callback();
    };
    const fakeWindow = {
        innerHeight: 800,
        scrollY: 0,
        requestAnimationFrame: (callback) => { const id = ++frameSequence; callbacks.set(id, callback); return id; },
        cancelAnimationFrame: (id) => { callbacks.delete(id); },
        addEventListener: (type, callback, options) => { subscribe(listeners.window, type, callback, options); },
        matchMedia: () => ({matches: prefersReducedMotion}),
        scrollTo: (options) => { scrollCalls.push(options); },
    };
    const fakeDocument = {
        body: {
            scrollHeight: 2000,
            classList: {
                contains: (name) => bodyClasses.has(name),
                add: (name) => { bodyClasses.add(name); },
                remove: (name) => { bodyClasses.delete(name); },
            },
        },
        documentElement: {scrollHeight: 2000},
        activeElement: null,
        fullscreenElement: null,
        querySelector: (selector) => elementMap.get(selector) || null,
        addEventListener: (type, callback, options) => { subscribe(listeners.document, type, callback, options); },
    };
    globalThis.window = fakeWindow;
    globalThis.document = fakeDocument;
    globalThis.Element = TestElement;
    globalThis.HTMLElement = TestElement;
    globalThis.ResizeObserver = TestResizeObserver;
    globalThis.MutationObserver = TestMutationObserver;

    const module = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
    const {setupBackToTopButton, teardownBackToTopButton} = module;
    const top = elementMap.get('[data-back-to-top-button]');
    const down = elementMap.get('[data-scroll-to-bottom-button]');

    setupBackToTopButton();
    assert.equal(top.hidden, true, 'Top is hidden at the page start.');
    assert.equal(down.hidden, false, 'Down is immediately available on scrollable pages.');
    assert.equal(down.visible, true, 'Down receives the same visible transition class.');
    assert.equal(observers.resize.at(-1).targets.length, 3, 'Document/body/listing geometry changes are tracked.');

    fakeWindow.scrollY = 400;
    emit(listeners.window, 'scroll');
    emit(listeners.window, 'scroll');
    assert.equal(callbacks.size, 1, 'Multiple scroll events share one animation-frame update.');
    flush();
    assert.equal(top.hidden, false, 'Top becomes visible when scrolling into the listing.');
    assert.equal(down.hidden, false, 'Down stays visible when scroll remains.');
    assert.equal(top.visible, true);

    let prevented = false;
    emit(listeners.document, 'click', {
        target: down,
        preventDefault: () => { prevented = true; },
    });
    assert.equal(prevented, true, 'Down consumes its native button click.');
    assert.deepEqual(scrollCalls.at(-1), {top: 2000, behavior: 'smooth'}, 'Down scrolls to the full document end.');

    fakeDocument.activeElement = down;
    fakeWindow.scrollY = 1200;
    emit(listeners.window, 'scroll');
    flush();
    assert.equal(top.hidden, false);
    assert.equal(down.hidden, true, 'Down disappears at the true page bottom.');
    assert.equal(fakeDocument.activeElement, top, 'Focused Down hands focus to visible Top.');

    emit(listeners.document, 'click', {target: top, preventDefault: () => {}});
    assert.deepEqual(scrollCalls.at(-1), {top: 0, behavior: 'smooth'}, 'Top retains original navigation.');

    fakeWindow.scrollY = 1199;
    emit(listeners.window, 'scroll');
    flush();
    assert.equal(down.hidden, true, 'Fractional/near-bottom positions use a two-pixel tolerance.');

    fakeDocument.documentElement.scrollHeight = 2500;
    fakeDocument.body.scrollHeight = 2500;
    observers.resize.at(-1).callback();
    flush();
    assert.equal(down.hidden, false, 'Inserting more content reveals Down without a scroll event.');

    fakeWindow.scrollY = 0;
    fakeDocument.activeElement = top;
    emit(listeners.window, 'resize');
    flush();
    assert.equal(top.hidden, true);
    assert.equal(fakeDocument.activeElement, down, 'Focused Top hands focus to Down at the page start.');

    fakeDocument.documentElement.scrollHeight = 800;
    fakeDocument.body.scrollHeight = 800;
    emit(listeners.window, 'resize');
    flush();
    assert.equal(down.hidden, true, 'A short document has no Down control.');

    fakeDocument.documentElement.scrollHeight = 2500;
    fakeDocument.body.scrollHeight = 2500;
    fakeWindow.scrollY = 400;
    bodyClasses.add('has-lightbox');
    observers.mutation.at(-1).callback();
    flush();
    assert.equal(top.hidden, true);
    assert.equal(down.hidden, true, 'Lightbox suppresses both controls immediately.');
    bodyClasses.delete('has-lightbox');
    observers.mutation.at(-1).callback();
    flush();
    assert.equal(top.hidden, false);
    assert.equal(down.hidden, false);

    fakeDocument.fullscreenElement = {};
    emit(listeners.document, 'fullscreenchange');
    flush();
    assert.equal(down.hidden, true, 'Fullscreen suppresses navigation.');
    fakeDocument.fullscreenElement = null;
    emit(listeners.document, 'fullscreenchange');
    flush();

    prefersReducedMotion = true;
    emit(listeners.document, 'click', {target: down, preventDefault: () => {}});
    assert.deepEqual(scrollCalls.at(-1), {top: 2500, behavior: 'auto'}, 'Reduced motion disables animated scrolls.');

    const firstResize = observers.resize.at(-1);
    const firstMutation = observers.mutation.at(-1);
    const firstUp = top;
    firstUp.isConnected = false;
    const refreshedTop = new TestElement('[data-back-to-top-button]');
    const refreshedDown = new TestElement('[data-scroll-to-bottom-button]');
    elementMap.set('[data-back-to-top-button]', refreshedTop);
    elementMap.set('[data-scroll-to-bottom-button]', refreshedDown);
    setupBackToTopButton();
    assert.equal(firstResize.disconnected, true, 'Rebinding disconnects old geometry observers.');
    assert.equal(firstMutation.disconnected, true, 'Rebinding disconnects old body observers.');
    assert.equal(refreshedTop.hidden, false, 'Rebinding uses the replacement page fragment.');
    assert.equal(refreshedDown.hidden, false);

    teardownBackToTopButton();
    assert.equal(observers.resize.at(-1).disconnected, true);
    assert.equal(observers.mutation.at(-1).disconnected, true);
    assert.equal((listeners.document.get('click') || new Set()).size, 0, 'Delegated click listeners are removed on teardown.');
    assert.equal((listeners.window.get('scroll') || new Set()).size, 0, 'Scroll listeners are removed on teardown.');
    console.log('Floating page-edge navigation behavior and lifecycle: PASS');
} finally {
    for (const [name, old] of originals) {
        if (old.present) globalThis[name] = old.value;
        else delete globalThis[name];
    }
}
