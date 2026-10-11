/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_public_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Qualify the production public widget CSS and JS against real Chromium geometry.
 * Responsibilities:
 *   - Verify server-rendered articles are in the DOM before enhancement.
 *   - Exercise desktop fixed anchoring, safe-area bounds, collision avoidance, wheel scroll chaining, keyboard dismissal and native pointer input.
 *   - Prove tablet and narrow/mobile fallbacks retain each accessible in-flow instance.
 *   - Exercise runtime portrait/landscape transitions, visual viewport scale fallback and placement recovery.
 *   - Verify a native modal dialog keeps top-layer hit testing and focus above a floating widget.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
// Readiness polling policy.
// Type: integer.
// Units: milliseconds.
// Scope: desktop public-widget Chromium fixture.
// Consumers: host-side readiness polling before native wheel, keyboard and pointer input.
// Rationale: yield between DevTools reads while keeping the 15-second readiness budget responsive.
const readinessPollIntervalMs = 25;
if (!executable) {
    console.log('SKIP public widget browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/public_content_widget_public_placement.html'],
    ['/nojs', 'tests/fixtures/public_content_widget_public_placement.html'],
    ['/public-widgets.js', 'public/assets/gallery-modules/public-content-widgets.js'],
    ['/public-widgets.css', 'public/assets/styles/public-content-widgets.css'],
    ['/public.css', 'public/assets/styles/public.css'],
    ['/lightbox.css', 'public/assets/styles/lightbox.css'],
]);
const server = createServer(async (request, response) => {
    const relative = routes.get(new URL(request.url, 'http://localhost').pathname);
    if (!relative) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8'
            : relative.endsWith('.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8');
        const source = await readFile(path.join(root, relative), 'utf8');
        response.end(new URL(request.url, 'http://localhost').pathname === '/nojs'
            ? source.replace(/<script type="module">[\s\S]*?<\/script>/, '') : source);
    } catch {
        response.writeHead(500).end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const [label, viewport, expected] of [
        ['desktop', {width: 1280, height: 900}, 'desktop fixed placement'],
        ['tablet', {width: 768, height: 1024}, 'tablet no-overlay in-flow content'],
        ['mobile', {width: 390, height: 740}, 'mobile no-overlay'],
    ]) {
        const url = new URL('http://127.0.0.1:' + server.address().port + '/');
        const options = {viewport};
        if (label === 'desktop') {
            const safeAreaInsets = {top: 100, right: 390, bottom: 40, left: 430};
            options.safeAreaInsets = safeAreaInsets;
            url.searchParams.set('safeAreaInsets', JSON.stringify(safeAreaInsets));
            options.interact = async ({evaluate, mouse, wheel, key, setViewport, setVisualViewportScale}) => {
                /** Readiness budget for the fixture's placement checks and keyboard-dismissal handshake.
                 * Type: integer deadline.
                 * Units: milliseconds.
                 * Scope: desktop public-widget Chromium placement and dismissal interactions.
                 * Consumers: host-side polling before native wheel and keyboard input.
                 * Rationale: report stage-specific readiness failures before native input can race fixture initialization.
                 */
                let readinessDeadline = Date.now() + 15000;
                let state = null;
                while (Date.now() < readinessDeadline) {
                    state = await evaluate(`({
                        ready: window.__publicWidgetWheelReady === true,
                        failure: window.__publicWidgetWheelFailure || '',
                        result: document.getElementById('results')?.textContent || '',
                    })`);
                    if (state?.failure || state?.result.startsWith('BROWSER FAIL') || state?.ready === true) break;
                    await new Promise(resolve => setTimeout(resolve, readinessPollIntervalMs));
                }
                assert.equal(state?.failure, '', state?.failure || 'Public widget placement assertions must pass.');
                assert.equal(state?.result.startsWith('BROWSER FAIL'), false,
                    state?.result || 'Public widget placement assertions must pass.');
                assert.equal(state?.ready, true,
                    'Floating widget must become ready for native wheel coverage before the bounded deadline.');
                const target = await evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    const bounds = widget.getBoundingClientRect();
                    return {
                        x: bounds.left + bounds.width / 2,
                        y: bounds.top + bounds.height / 2,
                        overscrollBehavior: getComputedStyle(widget).overscrollBehaviorY,
                    };
                })()`);
                assert.equal(target?.overscrollBehavior, 'auto',
                    'Floating widgets must allow native wheel chaining at their own scroll boundary.');
                const settle = () => evaluate(`new Promise(resolve => {
                    requestAnimationFrame(() => requestAnimationFrame(resolve));
                })`);
                const reset = async scrollTop => {
                    await evaluate(`(() => {
                        const widget = document.getElementById('widget-one');
                        widget.scrollTop = ${scrollTop === 'bottom' ? 'widget.scrollHeight' : '0'};
                        window.scrollTo(0, 520);
                    })()`);
                    await settle();
                };

                await reset(0);
                const innerStart = await evaluate('window.scrollY');
                await wheel(target.x, target.y, 0, 160);
                await settle();
                const innerState = await evaluate(`({
                    widget: document.getElementById('widget-one').scrollTop,
                    page: window.scrollY,
                })`);
                const innerWidgetScroll = innerState?.widget > 0;
                const innerPageStayed = innerState?.page === innerStart;

                await reset(0);
                const topStart = await evaluate('window.scrollY');
                await wheel(target.x, target.y, 0, -160);
                await settle();
                const topState = await evaluate(`({
                    widget: document.getElementById('widget-one').scrollTop,
                    page: window.scrollY,
                })`);
                const topBoundaryChains = topState?.widget === 0 && topState?.page < topStart;

                await reset('bottom');
                const bottomStart = await evaluate('window.scrollY');
                const bottomBoundary = await evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    return widget.scrollHeight - widget.clientHeight;
                })()`);
                await wheel(target.x, target.y, 0, 160);
                await settle();
                const bottomState = await evaluate(`({
                    widget: document.getElementById('widget-one').scrollTop,
                    page: window.scrollY,
                })`);
                const bottomBoundaryChains = Math.abs(bottomState?.widget - bottomBoundary) <= 1
                    && bottomState?.page > bottomStart;
                const outcomes = {innerWidgetScroll, innerPageStayed, topBoundaryChains, bottomBoundaryChains};
                await evaluate(`window.__completePublicWidgetWheelInteraction(${JSON.stringify(outcomes)})`);
                assert.ok(innerWidgetScroll, 'Wheel scrolling must remain available inside the floating widget.');
                assert.ok(innerPageStayed, 'An in-range widget wheel must not move the background page.');
                assert.ok(topBoundaryChains, 'An upward wheel at the widget top must continue scrolling the page.');
                assert.ok(bottomBoundaryChains, 'A downward wheel at the widget bottom must continue scrolling the page.');

                readinessDeadline = Date.now() + 15000;
                let dismissReady = false;
                while (Date.now() < readinessDeadline) {
                    const dismissalState = await evaluate(`({
                        ready: window.__publicWidgetDismissReady === true,
                        failure: window.__publicWidgetWheelFailure || '',
                    })`);
                    assert.equal(dismissalState?.failure, '', dismissalState?.failure || 'Widget dismissal setup must pass.');
                    if (dismissalState?.ready === true) {
                        dismissReady = true;
                        break;
                    }
                    await new Promise(resolve => setTimeout(resolve, readinessPollIntervalMs));
                }
                assert.equal(dismissReady, true,
                    'The desktop fixture must reach its keyboard-dismissal handshake before native key input.');

                let closeFocus = null;
                for (let attempt = 0; attempt < 12; attempt++) {
                    await key('keyDown', 'Tab');
                    await key('keyUp', 'Tab');
                    closeFocus = await evaluate(`(() => {
                        const close = document.querySelector('#widget-one .public-widget-dismiss');
                        const style = close ? getComputedStyle(close) : null;
                        return {
                            reached: document.activeElement === close,
                            label: close?.getAttribute('aria-label') || '',
                            focusVisible: close?.matches(':focus-visible') === true,
                            outlineStyle: style?.outlineStyle || '',
                            outlineWidth: style?.outlineWidth || '',
                            scrollY: window.scrollY,
                            url: location.href,
                            cookies: document.cookie,
                            localStorage: Array.from({length: localStorage.length}, (_, index) => {
                                const key = localStorage.key(index);
                                return [key, localStorage.getItem(key)];
                            }).sort((left, right) => left[0].localeCompare(right[0])),
                            sessionStorage: Array.from({length: sessionStorage.length}, (_, index) => {
                                const key = sessionStorage.key(index);
                                return [key, sessionStorage.getItem(key)];
                            }).sort((left, right) => left[0].localeCompare(right[0])),
                        };
                    })()`);
                    if (closeFocus?.reached) break;
                }
                assert.equal(closeFocus?.reached, true,
                    'Native Tab navigation must reach the visitor Close button.');
                assert.equal(closeFocus?.label, 'Close', 'The focused button must expose its accessible Close name.');
                assert.equal(closeFocus?.focusVisible, true, 'Keyboard focus must match :focus-visible.');
                assert.notEqual(closeFocus?.outlineStyle, 'none', 'Keyboard focus must render a visible outline.');
                assert.ok(Number.parseFloat(closeFocus?.outlineWidth) > 0,
                    'The keyboard focus outline must have a nonzero width.');

                await key('keyDown', 'Enter');
                await key('keyUp', 'Enter');
                await settle();
                const enterDismissal = await evaluate(`(() => ({
                    firstHidden: document.getElementById('widget-one').hidden,
                    secondVisible: !document.getElementById('widget-two').hidden,
                    thirdVisible: !document.getElementById('widget-three').hidden,
                    floatingCount: Array.from(document.querySelectorAll('.is-public-widget-floating'))
                        .filter(widget => !widget.hidden).length,
                    focusAtNextClose: document.activeElement
                        === document.querySelector('#widget-two .public-widget-dismiss'),
                    nextCloseFocusVisible: document.querySelector('#widget-two .public-widget-dismiss')
                        ?.matches(':focus-visible') === true,
                    scrollY: window.scrollY,
                    url: location.href,
                    cookies: document.cookie,
                    storage: JSON.stringify({
                        local: Array.from({length: localStorage.length}, (_, index) => {
                            const key = localStorage.key(index);
                            return [key, localStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                        session: Array.from({length: sessionStorage.length}, (_, index) => {
                            const key = sessionStorage.key(index);
                            return [key, sessionStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                    }),
                }))()`);

                const keyboardDismissal = {
                    reachedClose: closeFocus?.reached === true,
                    activatedClose: enterDismissal?.firstHidden === true,
                    focusContinuedAfterEnter: enterDismissal?.focusAtNextClose === true,
                    onlyCurrentArticleHidden: enterDismissal?.firstHidden === true
                        && enterDismissal?.secondVisible === true && enterDismissal?.thirdVisible === true,
                    pageStateUnchanged: enterDismissal?.scrollY === closeFocus?.scrollY
                        && enterDismissal?.url === closeFocus?.url,
                    storageUnchanged: enterDismissal?.cookies === closeFocus?.cookies
                        && enterDismissal?.storage === JSON.stringify({
                            local: closeFocus?.localStorage, session: closeFocus?.sessionStorage,
                        }),
                };
                await evaluate(`window.__completePublicWidgetDismissInteraction(${JSON.stringify(keyboardDismissal)})`);
                assert.equal(keyboardDismissal.activatedClose, true,
                    'Enter on the focused Close button must hide its widget.');
                assert.equal(keyboardDismissal.focusContinuedAfterEnter, true,
                    'Keyboard dismissal must move focus to the next visible widget Close control.');
                assert.equal(enterDismissal?.nextCloseFocusVisible, true,
                    'The next widget Close control must retain visible keyboard focus.');
                assert.equal(enterDismissal?.floatingCount, 2,
                    'A remaining published article must fill the available floating slot.');
                assert.equal(keyboardDismissal.onlyCurrentArticleHidden, true,
                    'Enter dismissal must hide only the focused article.');
                assert.equal(keyboardDismissal.pageStateUnchanged, true,
                    'Enter dismissal must preserve the page URL and scroll position.');
                assert.equal(keyboardDismissal.storageUnchanged, true,
                    'Enter dismissal must not write cookies or browser storage.');

                await key('keyDown', 'Space');
                await key('keyUp', 'Space');
                await settle();
                const spaceDismissal = await evaluate(`(() => ({
                    firstHidden: document.getElementById('widget-one').hidden,
                    secondHidden: document.getElementById('widget-two').hidden,
                    thirdVisible: !document.getElementById('widget-three').hidden,
                    floatingCount: Array.from(document.querySelectorAll('.is-public-widget-floating'))
                        .filter(widget => !widget.hidden).length,
                    focusAtNextClose: document.activeElement
                        === document.querySelector('#widget-three .public-widget-dismiss'),
                    nextCloseFocusVisible: document.querySelector('#widget-three .public-widget-dismiss')
                        ?.matches(':focus-visible') === true,
                    scrollY: window.scrollY,
                    url: location.href,
                    cookies: document.cookie,
                    storage: JSON.stringify({
                        local: Array.from({length: localStorage.length}, (_, index) => {
                            const key = localStorage.key(index);
                            return [key, localStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                        session: Array.from({length: sessionStorage.length}, (_, index) => {
                            const key = sessionStorage.key(index);
                            return [key, sessionStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                    }),
                }))()`);
                assert.equal(spaceDismissal?.firstHidden, true,
                    'The first keyboard-dismissed article must remain hidden while the next is dismissed.');
                assert.equal(spaceDismissal?.secondHidden, true,
                    'Space on the next focused Close button must dismiss its widget.');
                assert.equal(spaceDismissal?.focusAtNextClose, true,
                    'Space dismissal must move focus to the next visible widget Close control.');
                assert.equal(spaceDismissal?.nextCloseFocusVisible, true,
                    'Space dismissal must leave a visible keyboard focus indicator.');
                assert.equal(spaceDismissal?.floatingCount, 1,
                    'The last visible widget must remain in the available floating slot.');
                assert.equal(spaceDismissal?.scrollY, closeFocus?.scrollY,
                    'Space dismissal must preserve page scroll.');
                assert.equal(spaceDismissal?.url, closeFocus?.url,
                    'Space dismissal must preserve the page URL.');
                assert.equal(spaceDismissal?.cookies, closeFocus?.cookies,
                    'Space dismissal must not write cookies.');
                assert.equal(spaceDismissal?.storage, JSON.stringify({
                    local: closeFocus?.localStorage, session: closeFocus?.sessionStorage,
                }), 'Space dismissal must not write browser storage.');

                await key('keyDown', 'Enter');
                await key('keyUp', 'Enter');
                await settle();
                const finalDismissal = await evaluate(`(() => ({
                    allHidden: ['widget-one', 'widget-two', 'widget-three']
                        .every(id => document.getElementById(id).hidden),
                    floatingCount: Array.from(document.querySelectorAll('.is-public-widget-floating'))
                        .filter(widget => !widget.hidden).length,
                    focusAtPageControl: document.activeElement === document.querySelector('.site-header nav a'),
                    scrollY: window.scrollY,
                    url: location.href,
                    cookies: document.cookie,
                    storage: JSON.stringify({
                        local: Array.from({length: localStorage.length}, (_, index) => {
                            const key = localStorage.key(index);
                            return [key, localStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                        session: Array.from({length: sessionStorage.length}, (_, index) => {
                            const key = sessionStorage.key(index);
                            return [key, sessionStorage.getItem(key)];
                        }).sort((left, right) => left[0].localeCompare(right[0])),
                    }),
                }))()`);
                assert.equal(finalDismissal?.allHidden, true,
                    'Enter on the last focused Close button must hide its widget.');
                assert.equal(finalDismissal?.floatingCount, 0,
                    'Closing the final floating widget must leave no floating panels.');
                assert.equal(finalDismissal?.focusAtPageControl, true,
                    'Closing the last floating widget must move focus to a visible page control.');
                assert.equal(finalDismissal?.scrollY, closeFocus?.scrollY,
                    'Final dismissal must preserve page scroll.');
                assert.equal(finalDismissal?.url, closeFocus?.url,
                    'Final dismissal must preserve the page URL.');
                assert.equal(finalDismissal?.cookies, closeFocus?.cookies,
                    'Final dismissal must not write cookies.');
                assert.equal(finalDismissal?.storage, JSON.stringify({
                    local: closeFocus?.localStorage, session: closeFocus?.sessionStorage,
                }), 'Final dismissal must not write browser storage.');
                await evaluate('window.__continuePublicWidgetDismissInteraction()');

                readinessDeadline = Date.now() + 15000;
                let pointerReady = false;
                while (Date.now() < readinessDeadline) {
                    const pointerState = await evaluate(`({
                        ready: window.__publicWidgetPointerReady === true,
                        failure: window.__publicWidgetWheelFailure || '',
                        result: document.getElementById('results')?.textContent || '',
                    })`);
                    assert.equal(pointerState?.failure, '',
                        pointerState?.failure || 'Public widget pointer setup must pass.');
                    assert.equal(pointerState?.result.startsWith('BROWSER FAIL'), false,
                        pointerState?.result || 'Public widget pointer setup must pass.');
                    if (pointerState?.ready === true) {
                        pointerReady = true;
                        break;
                    }
                    await new Promise(resolve => setTimeout(resolve, readinessPollIntervalMs));
                }
                assert.equal(pointerReady, true,
                    'The fixture must expose its pointer targets before native mouse input.');

                const closeTarget = await evaluate(`(() => {
                    const close = document.querySelector('#widget-one .public-widget-dismiss');
                    const bounds = close?.getBoundingClientRect();
                    const x = bounds ? bounds.left + bounds.width / 2 : -1;
                    const y = bounds ? bounds.top + bounds.height / 2 : -1;
                    const hit = bounds && document.elementFromPoint(x, y);
                    return {
                        x, y,
                        hit: close instanceof HTMLElement && close.getClientRects().length > 0
                            && (hit === close || close.contains(hit)),
                    };
                })()`);
                assert.equal(closeTarget?.hit, true,
                    'The visible Close button must be the native pointer hit target.');
                await mouse('mouseMoved', closeTarget.x, closeTarget.y, 0);
                await mouse('mousePressed', closeTarget.x, closeTarget.y, 1, 1);
                await mouse('mouseReleased', closeTarget.x, closeTarget.y, 0, 1);
                await settle();
                let afterPointerClose = null;
                for (let attempt = 0; attempt < 30; attempt += 1) {
                    afterPointerClose = await evaluate(`(() => ({
                        ownWidgetHidden: document.getElementById('widget-one').hidden,
                        secondWidgetVisible: !document.getElementById('widget-two').hidden,
                        thirdWidgetVisible: !document.getElementById('widget-three').hidden,
                        floatingCount: Array.from(document.querySelectorAll('.is-public-widget-floating'))
                            .filter(widget => !widget.hidden).length,
                    }))()`);
                    if (afterPointerClose?.ownWidgetHidden === true) break;
                    await evaluate('new Promise(resolve => requestAnimationFrame(resolve))');
                }
                const closeOnlyOwnWidget = afterPointerClose?.ownWidgetHidden === true
                    && afterPointerClose?.secondWidgetVisible === true
                    && afterPointerClose?.thirdWidgetVisible === true
                    && afterPointerClose?.floatingCount === 2;
                assert.equal(closeOnlyOwnWidget, true,
                    'Native pointer activation must dismiss only its own widget and preserve the other two: '
                        + JSON.stringify({closeTarget, afterPointerClose}));

                const outsideTarget = await evaluate(`(() => {
                    const outside = document.getElementById('outside-widget-action');
                    const bounds = outside?.getBoundingClientRect();
                    const x = bounds ? bounds.left + bounds.width / 2 : -1;
                    const y = bounds ? bounds.top + bounds.height / 2 : -1;
                    const visiblePanels = Array.from(document.querySelectorAll('.is-public-widget-floating'))
                        .filter(widget => !widget.hidden);
                    const pointIsClear = visiblePanels.every(widget => {
                        const panel = widget.getBoundingClientRect();
                        return x < panel.left || x > panel.right || y < panel.top || y > panel.bottom;
                    });
                    const hit = bounds && document.elementFromPoint(x, y);
                    return {
                        x, y,
                        clear: pointIsClear,
                        hit: outside instanceof HTMLElement && outside.getClientRects().length > 0
                            && (hit === outside || outside.contains(hit)),
                    };
                })()`);
                assert.equal(outsideTarget?.clear, true,
                    'The page-control click point must remain outside every visible panel after dismissal.');
                assert.equal(outsideTarget?.hit, true,
                    'The page control must remain the hit target after floating layout updates.');
                await mouse('mouseMoved', outsideTarget.x, outsideTarget.y, 0);
                await mouse('mousePressed', outsideTarget.x, outsideTarget.y, 1, 1);
                await mouse('mouseReleased', outsideTarget.x, outsideTarget.y, 0, 1);
                await settle();
                const afterOutsideClick = await evaluate(`(() => ({
                    clickCount: window.__outsideWidgetActionClicks,
                    ownWidgetStillHidden: document.getElementById('widget-one').hidden,
                    secondWidgetVisible: !document.getElementById('widget-two').hidden,
                    thirdWidgetVisible: !document.getElementById('widget-three').hidden,
                }))()`);
                const outsideControlReceivedInput = afterOutsideClick?.clickCount === 1
                    && afterOutsideClick?.ownWidgetStillHidden === true
                    && afterOutsideClick?.secondWidgetVisible === true
                    && afterOutsideClick?.thirdWidgetVisible === true;
                await evaluate(`window.__completePublicWidgetPointerInteraction(${JSON.stringify({
                    closeHit: closeTarget.hit,
                    closeOnlyOwnWidget,
                    outsideControlHit: outsideTarget.hit && outsideTarget.clear,
                    outsideControlReceivedInput,
                })})`);
                assert.equal(outsideControlReceivedInput, true,
                    'Native pointer input outside widget bounds must reach the page control without hiding other widgets.');

                const settleViewport = () => evaluate(`new Promise(resolve => {
                    requestAnimationFrame(() => requestAnimationFrame(resolve));
                })`);
                const restoreAll = await evaluate(`(() => {
                    for (const widget of document.querySelectorAll('[data-public-widget-floating="1"]')) {
                        widget.hidden = false;
                    }
                    window.dispatchEvent(new Event('resize'));
                    return true;
                })()`);
                assert.equal(restoreAll, true, 'The isolated viewport phase restores each server-rendered widget.');
                await settleViewport();
                const placementSnapshot = await evaluate(`(() => ({
                    ids: Array.from(document.querySelectorAll('[data-public-widget-id]'))
                        .map(widget => widget.dataset.publicWidgetId),
                    placement: Array.from(document.querySelectorAll('[data-public-widget-id]')).map(widget => ({
                        anchor: widget.dataset.publicWidgetAnchor,
                        x: widget.dataset.publicWidgetX,
                        y: widget.dataset.publicWidgetY,
                        width: widget.dataset.publicWidgetWidth,
                    })),
                    hidden: Array.from(document.querySelectorAll('[data-public-widget-id]')).map(widget => widget.hidden),
                    cookies: document.cookie,
                    localStorage: Array.from({length: localStorage.length}, (_, index) => {
                        const key = localStorage.key(index);
                        return [key, localStorage.getItem(key)];
                    }).sort((left, right) => left[0].localeCompare(right[0])),
                    sessionStorage: Array.from({length: sessionStorage.length}, (_, index) => {
                        const key = sessionStorage.key(index);
                        return [key, sessionStorage.getItem(key)];
                    }).sort((left, right) => left[0].localeCompare(right[0])),
                }))()`);
                assert.equal(placementSnapshot?.ids.length, 3, 'Runtime transitions begin with the three original articles.');
                assert.equal(new Set(placementSnapshot.ids).size, 3, 'Runtime transitions begin without duplicate articles.');
                assert.ok(placementSnapshot.hidden.every(hidden => !hidden),
                    'Runtime transitions begin with all published articles visible.');

                const readViewportState = () => evaluate(`(() => {
                    const widgets = Array.from(document.querySelectorAll('[data-public-widget-id]'));
                    const floats = widgets.filter(widget => widget.classList.contains('is-public-widget-floating'));
                    return {
                        // DevTools sets the window viewport; clientWidth excludes the native scrollbar.
                        width: window.innerWidth,
                        height: window.innerHeight,
                        visualScale: window.visualViewport?.scale ?? 1,
                        ids: widgets.map(widget => widget.dataset.publicWidgetId),
                        placement: widgets.map(widget => ({anchor: widget.dataset.publicWidgetAnchor,
                            x: widget.dataset.publicWidgetX, y: widget.dataset.publicWidgetY,
                            width: widget.dataset.publicWidgetWidth})),
                        hidden: widgets.map(widget => widget.hidden),
                        floatingCount: floats.length,
                        allInFlow: widgets.every(widget => !widget.hidden && widget.getClientRects().length > 0
                            && getComputedStyle(widget).position !== 'fixed'
                            && !widget.classList.contains('is-public-widget-floating')
                            && !widget.style.left && !widget.style.top && !widget.style.width),
                        allFixed: floats.length === 2 && floats.every(widget => !widget.hidden
                            && getComputedStyle(widget).position === 'fixed'),
                        uniqueIds: new Set(widgets.map(widget => widget.dataset.publicWidgetId)).size === widgets.length,
                        noHorizontalOverflow: document.documentElement.scrollWidth
                            <= document.documentElement.clientWidth + 1,
                    };
                })()`);
                const assertRuntimeState = (state, width, height, inFlow, label) => {
                    assert.equal(state?.width, width, label + ' uses the requested CSS layout viewport width.');
                    assert.equal(state?.height, height, label + ' uses the requested CSS layout viewport height.');
                    assert.equal(state?.uniqueIds, true, label + ' keeps each server-rendered article exactly once.');
                    assert.deepEqual(state?.ids, placementSnapshot.ids, label + ' preserves published article identities.');
                    assert.deepEqual(state?.placement, placementSnapshot.placement, label + ' preserves saved placement metadata.');
                    assert.deepEqual(state?.hidden, placementSnapshot.hidden, label + ' preserves visitor dismissal state.');
                    assert.equal(state?.noHorizontalOverflow, true, label + ' does not create horizontal overflow.');
                    if (inFlow) {
                        assert.equal(state?.floatingCount, 0, label + ' returns every widget to normal flow.');
                        assert.equal(state?.allInFlow, true, label + ' keeps all articles visible in document flow.');
                    } else {
                        assert.equal(state?.allFixed, true, label + ' resumes the bounded floating placement.');
                    }
                };

                await setViewport({width: 390, height: 844,
                    orientation: {type: 'portraitPrimary', angle: 0}});
                await settleViewport();
                const portraitState = await readViewportState();
                assertRuntimeState(portraitState, 390, 844, true, 'Portrait viewport fallback');

                await setViewport({width: 844, height: 390,
                    orientation: {type: 'landscapePrimary', angle: 90}});
                await settleViewport();
                const landscapeState = await readViewportState();
                assertRuntimeState(landscapeState, 844, 390, true, 'Landscape viewport fallback');

                await setViewport({width: 1280, height: 900,
                    orientation: {type: 'landscapePrimary', angle: 90}});
                await settleViewport();
                const resumedState = await readViewportState();
                assertRuntimeState(resumedState, 1280, 900, false, 'Desktop viewport recovery');

                await setVisualViewportScale(1.25);
                await settleViewport();
                const scaledState = await readViewportState();
                assert.ok(scaledState?.visualScale > 1.05,
                    'The visual viewport scale emulation crosses the public overlay admission threshold.');
                assert.equal(scaledState?.width, 1280,
                    'Visual viewport scale emulation leaves the CSS layout viewport width unchanged.');
                assert.equal(scaledState?.floatingCount, 0,
                    'An enlarged visual viewport scale returns widgets to their in-flow fallback.');
                assert.equal(scaledState?.allInFlow, true,
                    'Visual viewport scale fallback keeps all published content visible and reachable.');
                assert.equal(scaledState?.noHorizontalOverflow, true,
                    'Visual viewport scale fallback does not create horizontal layout overflow.');
                assert.deepEqual(scaledState?.placement, placementSnapshot.placement,
                    'Visual viewport scale does not change saved placement metadata.');
                assert.deepEqual(scaledState?.hidden, placementSnapshot.hidden,
                    'Visual viewport scale does not change visitor dismissal state.');

                await setVisualViewportScale(1);
                await settleViewport();
                const recoveredState = await readViewportState();
                assertRuntimeState(recoveredState, 1280, 900, false, 'Visual viewport recovery');

                const dialogTarget = await evaluate(`(() => {
                    const widget = document.getElementById('widget-two');
                    const close = widget.querySelector('.public-widget-dismiss');
                    const dialog = document.getElementById('native-public-dialog');
                    const action = document.getElementById('native-public-dialog-action');
                    const bounds = widget.getBoundingClientRect();
                    close.focus({preventScroll: true});
                    Object.assign(dialog.style, {left: bounds.left + 'px', top: bounds.top + 'px',
                        width: bounds.width + 'px', height: bounds.height + 'px'});
                    dialog.showModal();
                    const actionBounds = action.getBoundingClientRect();
                    const widgetCenter = {x: bounds.left + bounds.width / 2, y: bounds.top + bounds.height / 2};
                    const hit = document.elementFromPoint(widgetCenter.x, widgetCenter.y);
                    return {x: actionBounds.left + actionBounds.width / 2,
                        y: actionBounds.top + actionBounds.height / 2,
                        widgetCenterHit: dialog.contains(hit),
                        modal: dialog.matches(':modal'),
                        focused: document.activeElement === action,
                        floating: widget.classList.contains('is-public-widget-floating')
                            && getComputedStyle(widget).position === 'fixed',
                        opened: dialog.open};
                })()`);
                assert.equal(dialogTarget?.modal, true, 'The public dialog opens in the browser top layer.');
                assert.equal(dialogTarget?.focused, true, 'Native modal opening moves keyboard focus into the dialog.');
                assert.equal(dialogTarget?.widgetCenterHit, true,
                    'The modal dialog receives hit testing above the floating widget it covers.');
                assert.equal(dialogTarget?.floating, true, 'The dialog overlay does not remove the saved floating placement.');
                assert.equal(dialogTarget?.opened, true);
                await mouse('mouseMoved', dialogTarget.x, dialogTarget.y, 0);
                await mouse('mousePressed', dialogTarget.x, dialogTarget.y, 1, 1);
                await mouse('mouseReleased', dialogTarget.x, dialogTarget.y, 0, 1);
                await settleViewport();
                const dialogInput = await evaluate(`(() => ({
                    clicks: window.__nativePublicDialogActionClicks,
                    modal: document.getElementById('native-public-dialog').matches(':modal'),
                    focused: document.activeElement === document.getElementById('native-public-dialog-action'),
                    ids: Array.from(document.querySelectorAll('[data-public-widget-id]'))
                        .map(widget => widget.dataset.publicWidgetId),
                    placement: Array.from(document.querySelectorAll('[data-public-widget-id]')).map(widget => ({
                        anchor: widget.dataset.publicWidgetAnchor, x: widget.dataset.publicWidgetX,
                        y: widget.dataset.publicWidgetY, width: widget.dataset.publicWidgetWidth,
                    })),
                    hidden: Array.from(document.querySelectorAll('[data-public-widget-id]')).map(widget => widget.hidden),
                    noHorizontalOverflow: document.documentElement.scrollWidth
                        <= document.documentElement.clientWidth + 1,
                }))()`);
                assert.equal(dialogInput?.clicks, 1, 'A native pointer click reaches the dialog action above the widget.');
                assert.equal(dialogInput?.modal, true, 'Native dialog action input leaves the modal open.');
                assert.equal(dialogInput?.focused, true, 'Native dialog input retains focus inside the modal.');
                assert.deepEqual(dialogInput?.ids, placementSnapshot.ids, 'The dialog does not duplicate published widgets.');
                assert.deepEqual(dialogInput?.placement, placementSnapshot.placement,
                    'The dialog does not change saved placement metadata.');
                assert.deepEqual(dialogInput?.hidden, placementSnapshot.hidden,
                    'The dialog does not dismiss or hide a widget.');
                assert.equal(dialogInput?.noHorizontalOverflow, true, 'The dialog does not create horizontal overflow.');
                const dialogClose = await evaluate(`(() => {
                    document.getElementById('native-public-dialog').close();
                    return {
                        closed: !document.getElementById('native-public-dialog').open,
                        focusRestored: document.activeElement === document.querySelector('#widget-two .public-widget-dismiss'),
                    };
                })()`);
                assert.equal(dialogClose?.closed, true, 'The native dialog closes cleanly after its overlay check.');
                assert.equal(dialogClose?.focusRestored, true, 'Closing the modal restores focus to its widget control opener.');

                const toolbarStylesReady = await evaluate(`new Promise(resolve => {
                    const stylesheet = document.createElement('link');
                    stylesheet.rel = 'stylesheet';
                    stylesheet.href = '/public.css';
                    stylesheet.addEventListener('load', () => resolve(true), {once: true});
                    stylesheet.addEventListener('error', () => resolve(false), {once: true});
                    document.head.append(stylesheet);
                })`);
                assert.equal(toolbarStylesReady, true, 'The toolbar geometry phase loads its production stylesheet.');
                await settleViewport();
                const panelExpansion = await evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    const toolbar = document.getElementById('geometry-picture-manager');
                    const panel = toolbar.querySelector('[data-picture-manager-panel]');
                    const original = widget.getBoundingClientRect();
                    const panelTarget = {left: original.left, top: original.top,
                        width: original.width + 48, height: original.height + 48};
                    window.__panelWidgetAttributeMutations = 0;
                    window.__panelViewportEvents = {resize: 0, scroll: 0};
                    window.__panelWidgetObserver = new MutationObserver(records => {
                        window.__panelWidgetAttributeMutations += records.length;
                    });
                    window.__panelWidgetObserver.observe(widget, {attributes: true, attributeFilter: ['class', 'style']});
                    window.addEventListener('resize', () => { window.__panelViewportEvents.resize += 1; });
                    window.addEventListener('scroll', () => { window.__panelViewportEvents.scroll += 1; });
                    Object.assign(toolbar.style, {boxSizing: 'border-box', position: 'fixed', left: '0px', top: '0px',
                        width: '260px', height: '56px', padding: '0px', transform: 'none'});
                    toolbar.hidden = false;
                    const before = toolbar.getBoundingClientRect();
                    Object.assign(panel.style, {boxSizing: 'border-box', position: 'absolute', left: panelTarget.left + 'px',
                        top: panelTarget.top + 'px', width: panelTarget.width + 'px', height: panelTarget.height + 'px'});
                    toolbar.classList.remove('is-picture-manager-collapsed');
                    toolbar.classList.add('is-picture-manager-expanded');
                    panel.hidden = false;
                    toolbar.querySelector('.picture-manager-toggle').setAttribute('aria-expanded', 'true');
                    const after = toolbar.getBoundingClientRect();
                    const panelBounds = panel.getBoundingClientRect();
                    return {outerSizeUnchanged: before.width === after.width && before.height === after.height,
                        outerBoundsUnchanged: before.left === after.left && before.top === after.top,
                        panelVisible: panel.getClientRects().length > 0 && getComputedStyle(panel).display !== 'none',
                        panelIntersectsWidget: panelBounds.left < original.right && panelBounds.right > original.left
                            && panelBounds.top < original.bottom && panelBounds.bottom > original.top,
                        toolbarHidden: toolbar.hidden};
                })()`);
                assert.equal(panelExpansion?.outerSizeUnchanged, true,
                    'Opening the absolute action panel leaves the toolbar outer size unchanged.');
                assert.equal(panelExpansion?.outerBoundsUnchanged, true,
                    'The fixed toolbar remains in place while its panel opens.');
                assert.equal(panelExpansion?.panelVisible, true, 'The action panel becomes visible.');
                assert.equal(panelExpansion?.panelIntersectsWidget, true,
                    'The absolute action panel covers the original floating widget rectangle.');
                assert.equal(panelExpansion?.toolbarHidden, false);
                const readPanelState = () => evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    const panel = document.querySelector('[data-picture-manager-panel]');
                    const widgetBounds = widget.getBoundingClientRect();
                    const panelBounds = panel.getBoundingClientRect();
                    return {flow: !widget.classList.contains('is-public-widget-floating')
                            && getComputedStyle(widget).position !== 'fixed'
                            && !widget.style.left && !widget.style.top && !widget.style.width,
                        fixed: widget.classList.contains('is-public-widget-floating')
                            && getComputedStyle(widget).position === 'fixed',
                        clear: widgetBounds.left >= panelBounds.right || widgetBounds.right <= panelBounds.left
                            || widgetBounds.top >= panelBounds.bottom || widgetBounds.bottom <= panelBounds.top,
                        mutations: window.__panelWidgetAttributeMutations,
                        viewportEvents: {...window.__panelViewportEvents},
                        hidden: widget.hidden,
                        placement: {anchor: widget.dataset.publicWidgetAnchor, x: widget.dataset.publicWidgetX,
                            y: widget.dataset.publicWidgetY, width: widget.dataset.publicWidgetWidth},
                        uniqueIds: new Set(Array.from(document.querySelectorAll('[data-public-widget-id]'))
                            .map(node => node.dataset.publicWidgetId)).size === 3,
                        noHorizontalOverflow: document.documentElement.scrollWidth
                            <= document.documentElement.clientWidth + 1,
                    };
                })()`);
                const readSettledGeometry = async (reader, ready, label) => {
                    let state = null;
                    let unchangedSamples = 0;
                    let previousMutationCount = null;
                    for (let attempt = 0; attempt < 30; attempt += 1) {
                        await settleViewport();
                        state = await reader();
                        const mutationCount = state?.mutations;
                        unchangedSamples = Number.isSafeInteger(mutationCount)
                            && mutationCount === previousMutationCount ? unchangedSamples + 1 : 0;
                        previousMutationCount = mutationCount;
                        if (ready(state) && unchangedSamples >= 4) break;
                    }
                    assert.ok(ready(state), label + ' reaches the expected geometry state.');
                    assert.ok(unchangedSamples >= 4, label + ' reaches four consecutive stable mutation samples.');
                    const stableMutationCount = state.mutations;
                    for (let frameIndex = 0; frameIndex < 8; frameIndex += 1) {
                        await settleViewport();
                        state = await reader();
                        assert.equal(state?.mutations, stableMutationCount,
                            label + ' remains stable over eight additional double-frame samples.');
                    }
                    return state;
                };
                const openPanelState = await readSettledGeometry(readPanelState,
                    state => state?.mutations > 0 && (state.flow || state.clear), 'Visible panel geometry');
                assert.ok(openPanelState?.mutations > 0 && (openPanelState.flow || openPanelState.clear),
                    'Opening the observed absolute panel clears or falls back the floating rectangle without viewport events.');
                assert.deepEqual(openPanelState.viewportEvents, {resize: 0, scroll: 0},
                    'Panel visibility invalidation runs without resize or scroll events.');
                assert.equal(openPanelState.uniqueIds, true, 'Panel invalidation preserves unique published articles.');
                assert.equal(openPanelState.hidden, placementSnapshot.hidden[0],
                    'Panel invalidation preserves visitor dismissal state.');
                assert.deepEqual(openPanelState.placement, placementSnapshot.placement[0],
                    'Panel invalidation preserves saved widget placement.');
                assert.equal(openPanelState.noHorizontalOverflow, true,
                    'Panel invalidation does not create horizontal overflow.');
                const stableOpenPanelMutations = openPanelState.mutations;

                await evaluate(`(() => {
                    const toolbar = document.getElementById('geometry-picture-manager');
                    toolbar.querySelector('[data-picture-manager-panel]').hidden = true;
                    toolbar.classList.remove('is-picture-manager-expanded');
                    toolbar.classList.add('is-picture-manager-collapsed');
                    toolbar.querySelector('.picture-manager-toggle').setAttribute('aria-expanded', 'false');
                })()`);
                const closedPanelState = await readSettledGeometry(readPanelState,
                    state => state?.fixed, 'Closed panel geometry');
                assert.equal(closedPanelState?.fixed, true,
                    'Closing the absolute panel restores floating placement without viewport events.');
                assert.ok(closedPanelState?.mutations > stableOpenPanelMutations,
                    'Panel closure updates widget geometry after the obstruction is removed.');
                assert.deepEqual(closedPanelState?.viewportEvents, {resize: 0, scroll: 0});
                assert.equal(closedPanelState?.hidden, placementSnapshot.hidden[0]);
                assert.deepEqual(closedPanelState?.placement, placementSnapshot.placement[0]);
                assert.equal(closedPanelState?.noHorizontalOverflow, true);
                const stableClosedPanelMutations = closedPanelState.mutations;
                const replacementState = await evaluate(`(() => {
                    const current = document.querySelector('[data-picture-manager-panel]');
                    const replacement = current.cloneNode(true);
                    replacement.hidden = false;
                    current.replaceWith(replacement);
                    window.__refreshPublicWidgetFloatingGeometry();
                    return replacement.isConnected && replacement.getClientRects().length > 0;
                })()`);
                assert.equal(replacementState, true, 'The visible replacement protected panel is connected.');
                const replacedPanelState = await readSettledGeometry(readPanelState,
                    state => state?.mutations > stableClosedPanelMutations && (state.flow || state.clear),
                    'Replacement protected panel geometry');
                assert.ok(replacedPanelState?.flow || replacedPanelState?.clear,
                    'The exported refresh hook recalculates placement against the replacement protected panel.');
                assert.deepEqual(replacedPanelState?.viewportEvents, {resize: 0, scroll: 0});
                assert.equal(replacedPanelState?.uniqueIds, true, 'Replacing a protected panel does not duplicate widgets.');
                assert.deepEqual(replacedPanelState?.placement, placementSnapshot.placement[0]);
                assert.equal(replacedPanelState?.noHorizontalOverflow, true);
                const mutationsBeforeReplacementHide = replacedPanelState.mutations;
                await evaluate(`(() => {
                    document.querySelector('[data-picture-manager-panel]').hidden = true;
                })()`);
                const restoredReplacementState = await readSettledGeometry(readPanelState,
                    state => state?.mutations > mutationsBeforeReplacementHide && state.fixed,
                    'Hidden replacement panel geometry');
                assert.equal(restoredReplacementState?.fixed, true,
                    'The replacement panel observer is rebound and restores placement when it is hidden.');
                await evaluate(`(() => {
                    window.__panelWidgetObserver.disconnect();
                    document.getElementById('geometry-picture-manager').hidden = true;
                })()`);

                const toolbarExpansion = await evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    const toolbar = document.getElementById('geometry-picture-manager');
                    const panel = toolbar.querySelector('.picture-manager-panel');
                    const toggle = toolbar.querySelector('.picture-manager-toggle');
                    const original = widget.getBoundingClientRect();
                    const originalRect = {left: original.left, top: original.top, right: original.right, bottom: original.bottom};
                    const viewportWidth = document.documentElement.clientWidth;
                    const toolbarHeight = original.height + 64;
                    window.__toolbarWidgetAttributeMutations = 0;
                    window.__toolbarViewportEvents = {resize: 0, scroll: 0};
                    window.__toolbarWidgetObserver = new MutationObserver(records => {
                        window.__toolbarWidgetAttributeMutations += records.length;
                    });
                    window.__toolbarWidgetObserver.observe(widget, {attributes: true, attributeFilter: ['class', 'style']});
                    window.addEventListener('resize', () => { window.__toolbarViewportEvents.resize += 1; });
                    window.addEventListener('scroll', () => { window.__toolbarViewportEvents.scroll += 1; });
                    Object.assign(toolbar.style, {boxSizing: 'border-box', left: '0px', top: Math.max(0, original.top - 32) + 'px',
                        transform: 'none', inlineSize: viewportWidth + 'px', height: toolbarHeight + 'px'});
                    toolbar.hidden = false;
                    toolbar.classList.remove('is-picture-manager-collapsed');
                    toolbar.classList.add('is-picture-manager-expanded');
                    panel.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                    const toolbarBounds = toolbar.getBoundingClientRect();
                    return {fixed: getComputedStyle(toolbar).position === 'fixed',
                        toolbarIntersectsWidget: toolbarBounds.left < originalRect.right
                            && toolbarBounds.right > originalRect.left
                            && toolbarBounds.top < originalRect.bottom
                            && toolbarBounds.bottom > originalRect.top,
                        viewportWidth};
                })()`);
                assert.equal(toolbarExpansion?.fixed, true, 'The expanded obstruction uses the production fixed toolbar style.');
                assert.equal(toolbarExpansion?.toolbarIntersectsWidget, true,
                    'The expanded toolbar overlaps the original floating widget rectangle.');
                const readToolbarState = () => evaluate(`(() => {
                    const widget = document.getElementById('widget-one');
                    const toolbar = document.getElementById('geometry-picture-manager');
                    const widgetBounds = widget.getBoundingClientRect();
                    const toolbarBounds = toolbar.getBoundingClientRect();
                    const flow = !widget.classList.contains('is-public-widget-floating')
                        && getComputedStyle(widget).position !== 'fixed'
                        && !widget.style.left && !widget.style.top && !widget.style.width;
                    return {flow,
                        fixed: widget.classList.contains('is-public-widget-floating')
                            && getComputedStyle(widget).position === 'fixed',
                        clear: widgetBounds.left >= toolbarBounds.right || widgetBounds.right <= toolbarBounds.left
                            || widgetBounds.top >= toolbarBounds.bottom || widgetBounds.bottom <= toolbarBounds.top,
                        mutations: window.__toolbarWidgetAttributeMutations,
                        viewportEvents: {...window.__toolbarViewportEvents},
                        hidden: widget.hidden,
                        placement: {anchor: widget.dataset.publicWidgetAnchor, x: widget.dataset.publicWidgetX,
                            y: widget.dataset.publicWidgetY, width: widget.dataset.publicWidgetWidth},
                        uniqueIds: new Set(Array.from(document.querySelectorAll('[data-public-widget-id]'))
                            .map(node => node.dataset.publicWidgetId)).size === 3,
                        noHorizontalOverflow: document.documentElement.scrollWidth
                            <= document.documentElement.clientWidth + 1,
                    };
                })()`);
                const expandedState = await readSettledGeometry(readToolbarState,
                    state => state?.mutations > 0 && (state.flow || state.clear), 'Expanded toolbar geometry');
                assert.ok(expandedState?.mutations > 0 && (expandedState.flow || expandedState.clear),
                    'Toolbar ResizeObserver invalidation must clear the floating rectangle without viewport events.');
                assert.deepEqual(expandedState.viewportEvents, {resize: 0, scroll: 0},
                    'Toolbar geometry mutation is tested without window resize or scroll events.');
                assert.equal(expandedState.uniqueIds, true, 'Toolbar invalidation keeps the original articles unique.');
                assert.equal(expandedState.hidden, placementSnapshot.hidden[0],
                    'Toolbar invalidation preserves visitor dismissal state.');
                assert.deepEqual(expandedState.placement, placementSnapshot.placement[0],
                    'Toolbar invalidation preserves saved widget placement.');
                assert.equal(expandedState.noHorizontalOverflow, true, 'Toolbar invalidation does not create horizontal overflow.');
                const stableMutationCount = expandedState.mutations;
                const stableExpandedState = expandedState;
                assert.deepEqual(stableExpandedState?.viewportEvents, {resize: 0, scroll: 0});

                await evaluate(`(() => {
                    const toolbar = document.getElementById('geometry-picture-manager');
                    toolbar.hidden = true;
                    toolbar.querySelector('.picture-manager-panel').hidden = true;
                    toolbar.classList.remove('is-picture-manager-expanded');
                    toolbar.classList.add('is-picture-manager-collapsed');
                })()`);
                const recoveredToolbarState = await readSettledGeometry(readToolbarState,
                    state => state?.mutations > stableMutationCount && state.fixed, 'Recovered toolbar geometry');
                assert.equal(recoveredToolbarState?.fixed, true,
                    'Hiding the expanded toolbar restores the widget floating placement without a viewport event.');
                assert.ok(recoveredToolbarState?.mutations > stableMutationCount,
                    'Toolbar recovery changes the widget geometry after the obstruction is removed.');
                assert.deepEqual(recoveredToolbarState?.viewportEvents, {resize: 0, scroll: 0});
                assert.equal(recoveredToolbarState?.uniqueIds, true, 'Toolbar recovery does not duplicate published articles.');
                assert.equal(recoveredToolbarState?.hidden, placementSnapshot.hidden[0],
                    'Toolbar recovery preserves visitor dismissal state.');
                assert.deepEqual(recoveredToolbarState?.placement, placementSnapshot.placement[0],
                    'Toolbar recovery preserves saved widget placement.');
                assert.equal(recoveredToolbarState?.noHorizontalOverflow, true, 'Toolbar recovery preserves horizontal bounds.');
                await evaluate('window.__toolbarWidgetObserver.disconnect()');
            };
        } else if (label === 'tablet') {
            options.interact = async ({evaluate}) => {
                const state = await evaluate(`(async () => {
                    const readinessDeadline = Date.now() + 5000;
                    while (document.readyState !== 'complete' && Date.now() < readinessDeadline) {
                        await new Promise(resolve => setTimeout(resolve, 30));
                    }
                    const widgets = Array.from(document.querySelectorAll('[data-public-widget-id]'));
                    const identifiers = new Set(widgets.map(widget => widget.dataset.publicWidgetId));
                    const uniqueVisibleInFlow = widgets.length === 3 && identifiers.size === 3
                        && widgets.every(widget => !widget.hidden && widget.getClientRects().length > 0
                            && getComputedStyle(widget).visibility !== 'hidden'
                            && getComputedStyle(widget).position !== 'fixed'
                            && !widget.classList.contains('is-public-widget-floating'));
                    const reachable = [];
                    for (const widget of widgets) {
                        widget.scrollIntoView({block: 'center', inline: 'nearest'});
                        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                        const link = widget.querySelector('a[href]');
                        const content = link || widget.querySelector('p');
                        const bounds = content?.getBoundingClientRect();
                        if (link) link.focus({preventScroll: true});
                        reachable.push(Boolean(content && bounds && bounds.width > 0 && bounds.height > 0
                            && bounds.left >= 0 && bounds.right <= document.documentElement.clientWidth + 1
                            && bounds.top >= 0 && bounds.bottom <= window.innerHeight
                            && (!link || document.activeElement === link)));
                    }
                    const noHorizontalOverflow = document.documentElement.scrollWidth
                        <= document.documentElement.clientWidth + 1
                        && document.body.scrollWidth <= document.documentElement.clientWidth + 1;
                    const pass = uniqueVisibleInFlow && reachable.length === 3
                        && reachable.every(Boolean) && noHorizontalOverflow;
                    const marker = pass
                        ? 'BROWSER PASS: public widgets tablet no-overlay in-flow content remains reachable'
                        : 'BROWSER FAIL: tablet public widgets must stay unique, visible, reachable and in-flow';
                    document.getElementById('results').textContent = marker;
                    return {pass, marker, uniqueVisibleInFlow, reachable, noHorizontalOverflow};
                })()`);
                assert.equal(state?.pass, true,
                    state?.marker || 'Tablet public widget content must remain visible, reachable and in-flow.');
            };
        }
        const {result, exitCode} = await runHeadlessBrowserFixture(executable,
            url.toString(), 'widget-public-' + label + '-', options);
        console.log(result || 'Public widget ' + label + ' fixture produced no status marker.');
        assert.equal(exitCode, 0, 'Browser process must exit cleanly: ' + label);
        assert.ok(result?.startsWith('BROWSER PASS') && result.includes(expected),
            'Public widget ' + label + ' geometry and fallback must pass');
        if (label === 'desktop') {
            assert.ok(result.includes('native pointer click safety'),
                'Desktop browser result must record native pointer click safety coverage.');
        }
    }
    // Remove the browser enhancer entirely and verify the actual initial HTML/CSS fallback.
    // DevTools evaluates assertions only; no public widget script is included in /nojs.
    const noScript = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/nojs', 'widget-public-nojs-',
        {
            viewport: {width: 1280, height: 900},
            interact: async ({evaluate}) => {
                const result = await evaluate(`(async () => {
                    for (let i = 0; i < 80 && document.readyState !== 'complete'; i++) {
                        await new Promise(resolve => setTimeout(resolve, 30));
                    }
                    const widgets = Array.from(document.querySelectorAll('[data-public-widget-id]'));
                    const unique = new Set(widgets.map(node => node.dataset.publicWidgetId));
                    const pass = widgets.length === 3 && unique.size === 3
                        && widgets.every(node => !node.hidden && getComputedStyle(node).position !== 'fixed')
                        && widgets.every(node => node.querySelector('a, p'))
                        && document.querySelector('.public-widget-dismiss') === null
                        && !document.querySelector('script[type="module"]')
                        && document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1;
                    const marker = pass ? 'BROWSER PASS: public widgets genuine no-script SSR fallback'
                        : 'BROWSER FAIL: no-script SSR content or document flow missing';
                    document.getElementById('results').textContent = marker;
                    return marker;
                })()`);
                assert.ok(String(result).startsWith('BROWSER PASS'), 'No-script SSR must remain usable');
            },
        });
    console.log(noScript.result || 'No-script fixture produced no status marker.');
    assert.equal(noScript.exitCode, 0, 'No-script browser process must exit cleanly.');
    assert.ok(noScript.result.includes('genuine no-script SSR fallback'), 'No-script fixture must pass');
} finally {
    server.close();
}
