/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_admin_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise real widget Admin positioning and protected public previews in Chromium.
 * Responsibilities:
 *   - Verify pointer drag, keyboard positioning, reset and draft-safe controls.
 *   - Verify trusted touch-pointer positioning at a mobile-sized Admin viewport.
 *   - Verify direct native dragging in the protected Home/Gallery previews, peer/action geometry, responsive fit, and mobile fallback.
 *   - Verify native pointer, modifier-click and middle-button link activation cannot navigate or submit the Admin form.
 *   - Verify empty-Home flow ordering and responsive rail-container parity in the protected preview.
 *   - Keep fixtures confined to an isolated, loopback-only browser profile.
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
if (!executable) {
    console.log('SKIP widget Admin browser: no Chromium executable supplied.');
    process.exit(0);
}
const routes = new Map([
    ['/', 'tests/fixtures/public_content_widget_admin_position.html'],
    ['/preview', 'tests/fixtures/public_content_widget_admin_preview.html'],
    ['/widget-admin.js', 'public/assets/gallery-modules/admin-public-widgets.js'],
    ['/public-content-widgets.js', 'public/assets/gallery-modules/public-content-widgets.js'],
    ['/widget-admin.css', 'public/assets/styles/admin-public-widgets.css'],
    ['/widget-public.css', 'public/assets/styles/public-content-widgets.css'],
    ['/widget-shared.css', 'public/assets/styles/public-shared.css'],
    ['/widget-shell.css', 'public/assets/styles/public.css'],
]);
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const page = url.searchParams.get('page');
    const previewKeys = [...url.searchParams.keys()];
    const emptyHomeFixture = (request.headers.cookie || '').split(';')
        .some(cookie => cookie.trim() === 'widget-empty-home-preview=1');
    const protectedPreview = url.pathname === '/index.php'
        && url.searchParams.getAll('page').length === 1
        && url.searchParams.getAll('preview').length === 1
        && url.searchParams.get('preview') === 'visual'
        && url.searchParams.getAll('view_as').length === 1
        && url.searchParams.get('view_as') === 'anonymous'
        && (page === 'home' && previewKeys.length === 3
            || page === 'gallery' && previewKeys.length === 4
                && url.searchParams.getAll('public_path').length === 1
                && url.searchParams.get('public_path') === 'preview-gallery');
    const legacyHomePreview = url.pathname === '/index.php'
        && page === 'home' && url.searchParams.getAll('page').length === 1
        && url.searchParams.getAll('preview').length === 1
        && url.searchParams.get('preview') === 'visual'
        && previewKeys.length === 2;
    const relative = protectedPreview || legacyHomePreview
        ? page === 'gallery' ? 'tests/fixtures/public_content_widget_theme_gallery.html'
            : emptyHomeFixture ? 'tests/fixtures/public_content_widget_admin_preview_empty_home.html'
                : 'tests/fixtures/public_content_widget_theme_home.html'
        : url.pathname === '/index.php' ? null : routes.get(url.pathname);
    if (!relative) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8'
            : relative.endsWith('.css') ? 'text/css' : 'text/javascript; charset=utf-8');
        if (url.pathname === '/preview' && url.searchParams.get('empty-home') === '1') {
            response.setHeader('Set-Cookie', 'widget-empty-home-preview=1; Path=/; SameSite=Lax');
        }
        response.end(await readFile(path.join(root, relative)));
    } catch {
        response.writeHead(500).end();
    }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
try {
    const {result, exitCode} = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/', 'widget-admin-browser-');
    console.log(result || 'Widget Admin fixture produced no status marker.');
    assert.equal(exitCode, 0);
    assert.ok(result?.includes('BROWSER PASS'), 'Widget pointer and keyboard fixture must complete.');

    const touchPlacement = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/?touch=1', 'widget-admin-touch-', {
            viewport: {width: 390, height: 844},
            touchEmulation: true,
            interact: async ({evaluate, touch}) => {
                let setup = null;
                for (let attempt = 0; attempt < 800; attempt += 1) {
                    setup = await evaluate('window.__widgetPlacementTouchSetup || null');
                    if (setup?.initial) break;
                    await evaluate('new Promise(resolve => requestAnimationFrame(() => resolve(true)))');
                }
                assert.ok(setup?.expectedPosition, 'Touch fixture must expose its measured placement map and expected production coordinates.');
                assert.deepEqual(setup.viewport, {width: 390, height: 844, maxTouchPoints: 1},
                    'Touch emulation uses the requested mobile-sized viewport and one emulated contact.');
                assert.equal(setup.initial.anchor, 'bottom-right', 'Touch begins from the saved floating placement.');
                assert.equal(setup.initial.x, '900');
                assert.equal(setup.initial.y, '900');
                assert.equal(setup.initial.submissions, 0, 'Touch begins without submitting the Admin form.');

                const stageReady = await evaluate(`(() => {
                    const stage = document.querySelector('.public-widgets-placement-stage');
                    if (!(stage instanceof Element)) return false;
                    stage.scrollIntoView({block: 'center', behavior: 'instant'});
                    return true;
                })()`);
                assert.equal(stageReady, true, 'Touch fixture must retain its placement stage before measuring hit targets.');
                await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                setup = await evaluate('window.__widgetPlacementTouchRefresh()');
                assert.equal(setup?.stageMissing, false, 'Touch fixture stage must remain connected after scrolling.');
                const touchHit = await evaluate(`(() => {
                    const stage = document.querySelector('.public-widgets-placement-stage');
                    const point = ${JSON.stringify(setup.pointerDown)};
                    const hit = document.elementFromPoint(point.x, point.y);
                    const rect = element => {
                        const bounds = element?.getBoundingClientRect();
                        return bounds ? {
                            left: Math.round(bounds.left * 100) / 100,
                            top: Math.round(bounds.top * 100) / 100,
                            width: Math.round(bounds.width * 100) / 100,
                            height: Math.round(bounds.height * 100) / 100,
                        } : null;
                    };
                    return {
                        insideViewport: point.x >= 0 && point.x < innerWidth && point.y >= 0 && point.y < innerHeight,
                        stageHit: hit instanceof Element && stage instanceof Element
                            && (hit === stage || stage.contains(hit)),
                        hitTag: hit?.tagName || '',
                        hitClass: hit instanceof HTMLElement ? hit.className : '',
                        stageRect: rect(stage),
                        frameRect: rect(document.querySelector('.public-widgets-theme-frame')),
                        frameWrapRect: rect(document.querySelector('.public-widgets-theme-frame-wrap')),
                        themeHostRect: rect(document.querySelector('[data-widget-theme-preview]')),
                        hitStack: document.elementsFromPoint(point.x, point.y).slice(0, 5).map(element => {
                            const style = getComputedStyle(element);
                            return {
                                tag: element.tagName,
                                className: element instanceof HTMLElement ? element.className : '',
                                position: style.position,
                                transform: style.transform,
                                pointerEvents: style.pointerEvents,
                                zIndex: style.zIndex,
                            };
                        }),
                        scrollY: Math.round(scrollY * 100) / 100,
                        touchAction: stage ? getComputedStyle(stage).touchAction : '',
                        point,
                        viewport: {width: innerWidth, height: innerHeight},
                    };
                })()`);
                assert.equal(touchHit?.insideViewport, true,
                    'Touch pointerdown coordinates must be inside the browser viewport: ' + JSON.stringify(touchHit));
                assert.equal(touchHit?.stageHit, true,
                    'Touch pointerdown must hit the placement stage: ' + JSON.stringify(touchHit));
                assert.equal(touchHit?.touchAction, 'none',
                    'The measured floating placement stage must own the native touch gesture.');

                const waitForTrustedTouchEvent = async type => {
                    let trace = [];
                    for (let attempt = 0; attempt < 120; attempt += 1) {
                        trace = await evaluate('window.__widgetPlacementTouchTrace || []');
                        if (trace.some(event => event.type === type
                            && event.pointerType === 'touch' && event.trusted)) return trace;
                        await evaluate('new Promise(resolve => requestAnimationFrame(resolve))');
                    }
                    return evaluate('window.__widgetPlacementTouchTrace || []');
                };

                await touch('touchStart', setup.pointerDown.x, setup.pointerDown.y);
                const traceAfterTouchStart = await waitForTrustedTouchEvent('pointerdown');
                const afterTouchStart = await evaluate('window.__widgetPlacementTouchSnapshot()');
                assert.ok(traceAfterTouchStart.some(event => event.type === 'pointerdown'
                    && event.pointerType === 'touch' && event.trusted),
                'Trusted touch pointerdown reaches the placement stage: '
                    + JSON.stringify({touchHit, afterTouchStart, traceAfterTouchStart}));
                assert.equal(afterTouchStart?.anchor, 'custom',
                    'Touch pointerdown enters unsaved custom placement: ' + JSON.stringify(afterTouchStart));
                await touch('touchMove', setup.pointerMove.x, setup.pointerMove.y);
                const traceAfterTouchMove = await waitForTrustedTouchEvent('pointermove');
                const afterTouchMove = await evaluate('window.__widgetPlacementTouchSnapshot()');
                assert.ok(traceAfterTouchMove.some(event => event.type === 'pointermove'
                    && event.pointerType === 'touch' && event.trusted),
                'Trusted touch pointermove reaches the placement stage: '
                    + JSON.stringify({afterTouchMove, traceAfterTouchMove}));
                assert.deepEqual({anchor: afterTouchMove?.anchor, x: Number(afterTouchMove?.x), y: Number(afterTouchMove?.y)},
                    {anchor: 'custom', ...setup.expectedPosition},
                    'Touch pointermove applies production coordinates before contact release: '
                        + JSON.stringify({afterTouchMove, expectedPosition: setup.expectedPosition, traceAfterTouchMove}));
                await touch('touchEnd', setup.pointerMove.x, setup.pointerMove.y);
                const trace = await waitForTrustedTouchEvent('pointerup');

                let after = null;
                for (let attempt = 0; attempt < 100; attempt += 1) {
                    after = await evaluate('window.__widgetPlacementTouchSnapshot()');
                    if (after?.previewRect && !after.markerHidden
                        && after.markerLeft !== setup.initial.markerLeft
                        && after.markerTop !== setup.initial.markerTop) break;
                    await evaluate('new Promise(resolve => requestAnimationFrame(() => resolve(true)))');
                }
                assert.ok(after?.previewRect && !after.markerHidden,
                    'Touch placement must update both the draft preview rectangle and visible Admin map marker.');
                assert.ok(Array.isArray(trace), 'Trusted touch pointer trace must be available from the fixture.');
                assert.deepEqual({anchor: after.anchor, x: Number(after.x), y: Number(after.y)},
                    {anchor: 'custom', ...setup.expectedPosition},
                    'The trusted touch drag updates draft coordinates through production pointer mapping.');
                assert.ok(trace.some(event => event.type === 'pointerdown' && event.pointerType === 'touch' && event.trusted)
                    && trace.some(event => event.type === 'pointermove' && event.pointerType === 'touch' && event.trusted)
                    && trace.some(event => event.type === 'pointerup' && event.pointerType === 'touch' && event.trusted),
                    'CDP touch input reaches the production map as trusted touch PointerEvents.');
                assert.equal(after.markerHidden, false, 'The draft marker remains visible after touch placement.');
                assert.notEqual(after.markerLeft, setup.initial.markerLeft, 'The map marker moves horizontally with the unsaved draft.');
                assert.notEqual(after.markerTop, setup.initial.markerTop, 'The map marker moves vertically with the unsaved draft.');
                assert.ok(Math.abs(Number.parseFloat(after.markerLeft) - 100 * after.previewRect.left / after.geometryWidth) < 0.1
                    && Math.abs(Number.parseFloat(after.markerTop) - 100 * after.previewRect.top / after.geometryHeight) < 0.1,
                    'The placement marker reflects the measured protected preview rectangle.');
                assert.equal(after.submissions, 0, 'Touch placement remains an unsaved draft.');
                assert.equal(after.url, setup.url, 'Touch placement does not navigate or publish the Admin form.');
                assert.equal(after.content, setup.initial.content, 'Touch placement preserves the authored widget content.');
                await evaluate('window.__resumeWidgetPlacementTouchFixture()');
            },
        });
    console.log(touchPlacement.result || 'Touch placement fixture produced no status marker.');
    assert.equal(touchPlacement.exitCode, 0, 'Touch placement browser process must exit cleanly.');
    assert.ok(touchPlacement.result?.startsWith('BROWSER PASS'),
        'The touch placement fixture must complete its existing keyboard, reset, scope and unsaved-draft assertions.');

    const preview = await runHeadlessBrowserFixture(executable,
        'http://127.0.0.1:' + server.address().port + '/preview', 'widget-admin-theme-preview-', {
            viewport: {width: 1280, height: 900},
            interact: async ({evaluate, mouse}) => {
                const waitForDirectDragPage = async page => {
                    let state = null;
                    for (let attempt = 0; attempt < 800; attempt += 1) {
                        state = await evaluate(`(() => {
                            const frame = document.querySelector('.public-widgets-theme-frame');
                            return {
                                result: document.getElementById('results')?.textContent || '',
                                page: window.__widgetAdminDirectDragPage || null,
                                frameReady: Boolean(frame?.contentDocument?.body),
                            };
                        })()`);
                        if (state?.result.startsWith('BROWSER FAIL') || state?.page === page && state.frameReady) break;
                        await new Promise(resolve => setImmediate(resolve));
                    }
                    assert.equal(state?.result.startsWith('BROWSER FAIL'), false,
                        'Protected preview fixture must reach its direct-drag checkpoint: ' + (state?.result || 'no result'));
                    assert.equal(state?.page, page,
                        'Protected preview fixture must pause on ' + page + ' before the native drag.');
                    assert.equal(state?.frameReady, true,
                        'The selected ' + page + ' protected preview document must remain available for native input.');
                };
                const dragProtectedDraft = async page => {
                    await waitForDirectDragPage(page);
                    await evaluate(`(() => {
                        document.querySelector('[data-widget-theme-preview]')?.scrollIntoView({block: 'center', behavior: 'instant'});
                        return true;
                    })()`);
                    await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                    const target = await evaluate(`(() => {
                        const observation = window.__widgetAdminDirectDragObservation;
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        const doc = frame?.contentDocument;
                        const draft = doc?.querySelector('[data-widget-theme-draft][data-widget-theme-draggable="1"]');
                        const title = draft?.querySelector('.public-content-widget-title');
                        if (!observation || observation.page !== ${JSON.stringify(page)} || !frame || !doc || !draft || !title) return null;
                        const frameBounds = frame.getBoundingClientRect();
                        const titleBounds = title.getBoundingClientRect();
                        const scale = frameBounds.width / frame.offsetWidth;
                        const startInnerX = titleBounds.left + titleBounds.width / 2;
                        const startInnerY = titleBounds.top + titleBounds.height / 2;
                        const endInnerX = startInnerX - 42;
                        const endInnerY = startInnerY - 32;
                        const startX = frameBounds.left + startInnerX * scale;
                        const startY = frameBounds.top + startInnerY * scale;
                        const endX = frameBounds.left + endInnerX * scale;
                        const endY = frameBounds.top + endInnerY * scale;
                        return {
                            startX, startY, endX, endY,
                            inAdminViewport: startX >= 0 && startX < innerWidth && startY >= 0 && startY < innerHeight,
                            topLevelHitIsFrame: document.elementFromPoint(startX, startY) === frame,
                            previewHitIsDraft: (() => {
                                const hit = doc.elementFromPoint(startInnerX, startInnerY);
                                return hit === draft || draft.contains(hit);
                            })(),
                            before: observation.before,
                            adminUrl: observation.adminUrl,
                            previewUrl: observation.previewUrl,
                            submissions: observation.submissions,
                        };
                    })()`);
                    assert.ok(target?.inAdminViewport && target.topLevelHitIsFrame && target.previewHitIsDraft,
                        'Native drag must start on the visible projected ' + page + ' draft inside its iframe.');
                    await mouse('mouseMoved', target.startX, target.startY, 0);
                    await mouse('mousePressed', target.startX, target.startY, 1, 1);
                    await mouse('mouseMoved', target.endX, target.endY, 1);
                    await mouse('mouseReleased', target.endX, target.endY, 0, 1);
                    let after = null;
                    for (let attempt = 0; attempt < 120; attempt += 1) {
                        after = await evaluate(`(() => {
                            const observation = window.__widgetAdminDirectDragObservation;
                            const form = document.querySelector('[data-public-widget-editor]');
                            const frame = document.querySelector('.public-widgets-theme-frame');
                            const doc = frame?.contentDocument;
                            const draft = doc?.querySelector('[data-widget-theme-draft]');
                            const rect = draft?.getBoundingClientRect();
                            return {
                                page: observation?.page,
                                anchor: form?.querySelector('[name="floating_anchor"]')?.value,
                                x: form?.querySelector('[name="x_permille"]')?.value,
                                y: form?.querySelector('[name="y_permille"]')?.value,
                                left: rect?.left,
                                top: rect?.top,
                                adminUrl: location.href,
                                previewUrl: frame?.contentWindow?.location.href,
                                sameDocument: doc === observation?.document,
                                submissions: window.__widgetAdminSubmissionCount(),
                                content: form?.querySelector('[name="content_md"]')?.value,
                                dragging: doc?.documentElement?.hasAttribute('data-widget-theme-dragging'),
                                eventTrace: observation?.eventTrace || [],
                            };
                        })()`);
                        if (after?.page === page && after.anchor === 'custom'
                            && after.x !== target.before.x && after.y !== target.before.y) break;
                        await new Promise(resolve => setImmediate(resolve));
                    }
                    assert.equal(after?.page, page, 'Native drag observation remains tied to the selected ' + page + ' frame.');
                    assert.equal(after?.anchor, 'custom', 'Direct preview drag selects the custom placement anchor.');
                    assert.notEqual(after?.x, target.before.x, 'Direct preview drag updates the unsaved X field.');
                    assert.notEqual(after?.y, target.before.y, 'Direct preview drag updates the unsaved Y field.');
                    assert.ok(Math.abs(after.left - target.before.left) > 1 || Math.abs(after.top - target.before.top) > 1,
                        'The actual ' + page + ' preview article moves with its unsaved coordinates.');
                    assert.equal(after.adminUrl, target.adminUrl, 'Direct preview drag leaves the Admin URL unchanged.');
                    assert.equal(after.previewUrl, target.previewUrl, 'Direct preview drag leaves the public iframe URL unchanged.');
                    assert.equal(after.sameDocument, true, 'Direct preview drag does not replace the protected iframe document.');
                    assert.equal(after.submissions, target.submissions, 'Direct preview drag does not save or publish the draft.');
                    assert.equal(after.content, 'Unsaved draft with a safe link.', 'Direct preview drag preserves authored Markdown.');
                    assert.equal(after.dragging, false, 'The protected preview clears its dragging state after pointer release.');
                    for (const type of ['pointerdown', 'pointermove', 'pointerup']) {
                        assert.ok(after.eventTrace.some(event => event.type === type && event.trusted && event.pointerType === 'mouse'),
                            'The ' + page + ' preview receives trusted native mouse ' + type + ' events.');
                    }
                    const resumed = await evaluate(`(() => {
                        if (window.__widgetAdminDirectDragPage !== ${JSON.stringify(page)}
                            || typeof window.__widgetAdminDirectDragResume !== 'function') return false;
                        window.__widgetAdminDirectDragResume(${JSON.stringify(after)});
                        return true;
                    })()`);
                    assert.equal(resumed, true, 'Fixture resumes after the native ' + page + ' drag is checked.');
                };
                await dragProtectedDraft('home');
                await dragProtectedDraft('gallery');

                let previewState = null;
                for (let attempt = 0; attempt < 800; attempt += 1) {
                    previewState = await evaluate(`(() => {
                        const result = document.getElementById('results')?.textContent || '';
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        return {result, frameReady: Boolean(frame?.contentDocument?.body)};
                    })()`);
                    if (previewState?.result.startsWith('BROWSER FAIL')
                        || previewState?.result.startsWith('BROWSER PASS') && previewState.frameReady) break;
                    await new Promise(resolve => setImmediate(resolve));
                }
                assert.ok(previewState?.result.startsWith('BROWSER PASS') && previewState.frameReady,
                    'Protected preview fixture must finish its asynchronous Home/Gallery checks before geometry is measured: '
                        + (previewState?.result || 'no browser result was returned'));
                await evaluate(`(() => {
                    const host = document.querySelector('[data-widget-theme-preview]');
                    if (!host) return false;
                    host.style.width = '420px';
                    host.style.maxWidth = 'none';
                    return true;
                })()`);
                let fitted = null;
                for (let attempt = 0; attempt < 400; attempt += 1) {
                    fitted = await evaluate(`(() => {
                        const host = document.querySelector('[data-widget-theme-preview]');
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        const shell = host?.querySelector('[data-widget-theme-frame-wrap]');
                        const documentBody = frame?.contentDocument?.body;
                        if (!host || !frame || !shell || !documentBody) return null;
                        const hostBounds = host.getBoundingClientRect();
                        const frameBounds = frame.getBoundingClientRect();
                        const availableWidth = Math.max(1, hostBounds.width - 20);
                        const expectedScale = Math.min(1, availableWidth / 1280);
                        const scale = Number(frame.style.transform.match(/scale\\(([^)]+)\\)/)?.[1] || 0);
                        const ready = Math.abs(scale - expectedScale) < 0.002
                            && Math.abs(Number.parseFloat(shell.style.width) - 1280 * expectedScale) < 1
                            && frameBounds.right <= hostBounds.right + 1
                            && frame.contentDocument.documentElement.clientWidth === 1280
                            && documentBody.dataset.previewFixturePage === 'gallery';
                        return ready ? {scale, expectedScale, frameWidth: frameBounds.width,
                            hostWidth: hostBounds.width, viewportWidth: frame.contentDocument.documentElement.clientWidth} : null;
                    })()`);
                    if (fitted) break;
                    await new Promise(resolve => setImmediate(resolve));
                }
                assert.ok(fitted,
                    'Gallery detail preview must refit after the Admin workspace narrows while retaining the selected desktop viewport.');
                assert.ok(fitted.frameWidth <= fitted.hostWidth + 1,
                    'The resized Gallery preview remains inside its Admin host.');
                assert.equal(fitted.viewportWidth, 1280,
                    'Resizing the Admin host changes only the preview scale, not the selected public device viewport.');
                const linkTarget = await evaluate(`(() => {
                    const host = document.querySelector('[data-widget-theme-preview]');
                    const frame = document.querySelector('.public-widgets-theme-frame');
                    host?.scrollIntoView({block: 'center', inline: 'nearest'});
                    const doc = frame?.contentDocument;
                    const draft = doc?.querySelector('[data-widget-theme-draft]');
                    if (draft?.hidden) draft.hidden = false;
                    const link = doc?.querySelector('[data-widget-theme-draft] a');
                    if (!host || !frame || !doc || !link) return null;
                    const frameBounds = frame.getBoundingClientRect();
                    const linkBounds = link.getBoundingClientRect();
                    const scale = frameBounds.width / frame.offsetWidth;
                    const x = frameBounds.left + (linkBounds.left + linkBounds.width / 2) * scale;
                    const y = frameBounds.top + (linkBounds.top + linkBounds.height / 2) * scale;
                    const parentHit = document.elementFromPoint(x, y);
                    const previewHit = doc.elementFromPoint(linkBounds.left + linkBounds.width / 2,
                        linkBounds.top + linkBounds.height / 2);
                    const observation = {
                        adminUrl: location.href,
                        previewUrl: frame.contentWindow.location.href,
                        previewDocument: doc,
                        previewFrame: frame,
                        submissions: window.__widgetAdminSubmissionCount(),
                        linkClicks: 0,
                        auxiliaryClicks: 0,
                        defaultPrevented: false,
                        placement: {
                            anchor: document.querySelector('[name="floating_anchor"]')?.value || '',
                            x: document.querySelector('[name="x_permille"]')?.value || '',
                            y: document.querySelector('[name="y_permille"]')?.value || '',
                        },
                        eventTrace: [],
                    };
                    window.__widgetAdminNativePreviewLink = observation;
                    for (const type of ['pointerdown', 'pointerup', 'click', 'auxclick']) {
                        doc.addEventListener(type, event => {
                            if (event.composedPath().includes(link) && observation.eventTrace.length < 16) {
                                observation.eventTrace.push({type, trusted: event.isTrusted,
                                    defaultPrevented: event.defaultPrevented, button: event.button,
                                    ctrlKey: event.ctrlKey, metaKey: event.metaKey});
                            }
                        }, true);
                    }
                    doc.addEventListener('click', event => {
                        if (event.composedPath().includes(link)) {
                            observation.linkClicks += 1;
                            observation.defaultPrevented = event.defaultPrevented;
                        }
                    });
                    doc.addEventListener('auxclick', event => {
                        if (event.composedPath().includes(link)) observation.auxiliaryClicks += 1;
                    });
                    return {
                        x,
                        y,
                        adminUrl: observation.adminUrl,
                        previewUrl: observation.previewUrl,
                        submissions: observation.submissions,
                        placement: observation.placement,
                        href: link.href,
                        safeTarget: link.href === 'https://example.test/partner',
                        visibleInPreview: linkBounds.left >= 0 && linkBounds.top >= 0
                            && linkBounds.right <= doc.documentElement.clientWidth
                            && linkBounds.bottom <= doc.documentElement.clientHeight,
                        topLevelHitIsFrame: parentHit === frame,
                        previewHitIsSafeLink: previewHit === link || link.contains(previewHit),
                    };
                })()`);
                assert.ok(linkTarget?.safeTarget && linkTarget.visibleInPreview,
                    'Native input target must be the visible, rendered safe Markdown link in the protected preview.');
                assert.ok(linkTarget.x >= 0 && linkTarget.x < 1280 && linkTarget.y >= 0 && linkTarget.y < 900,
                    'The rendered preview link must map to a visible point in the Admin browser viewport.');
                assert.equal(linkTarget.topLevelHitIsFrame, true,
                    'The mapped native click point must hit the sandboxed Theme iframe in the Admin document.');
                assert.equal(linkTarget.previewHitIsSafeLink, true,
                    'The mapped native click point must hit the rendered safe link within the preview document.');
                await mouse('mouseMoved', linkTarget.x, linkTarget.y, 0);
                await mouse('mousePressed', linkTarget.x, linkTarget.y, 1, 1);
                await mouse('mouseReleased', linkTarget.x, linkTarget.y, 0, 1);
                await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                const linkResult = await evaluate(`(() => {
                    const observation = window.__widgetAdminNativePreviewLink;
                    return {
                        adminUrl: location.href,
                        previewUrl: observation?.previewFrame.contentWindow.location.href,
                        samePreviewDocument: observation?.previewFrame.contentDocument === observation?.previewDocument,
                        submissions: window.__widgetAdminSubmissionCount(),
                        linkClicks: observation?.linkClicks,
                        auxiliaryClicks: observation?.auxiliaryClicks,
                        defaultPrevented: observation?.defaultPrevented,
                        placement: {
                            anchor: document.querySelector('[name="floating_anchor"]')?.value || '',
                            x: document.querySelector('[name="x_permille"]')?.value || '',
                            y: document.querySelector('[name="y_permille"]')?.value || '',
                        },
                        eventTrace: observation?.eventTrace,
                        previewForms: observation?.previewDocument.querySelectorAll('form').length,
                    };
                })()`);
                assert.equal(linkResult.linkClicks, 1,
                    'A native primary-button pointer click must reach the rendered safe preview link. Events: '
                        + JSON.stringify(linkResult.eventTrace));
                assert.equal(linkResult.defaultPrevented, true,
                    'The protected preview must cancel native safe-link activation.');
                assert.equal(linkResult.eventTrace.find(event => event.type === 'click')?.trusted, true,
                    'The primary link activation must be a trusted native click.');
                assert.equal(linkResult.eventTrace.find(event => event.type === 'click')?.button, 0,
                    'The primary native activation must retain its left-button identity.');
                assert.equal(linkResult.adminUrl, linkTarget.adminUrl,
                    'Preview link input must leave the Admin URL unchanged.');
                assert.equal(linkResult.previewUrl, linkTarget.previewUrl,
                    'Preview link input must leave the protected public URL unchanged.');
                assert.equal(linkResult.samePreviewDocument, true,
                    'Preview link input must not replace or navigate the iframe document.');
                assert.equal(linkResult.submissions, linkTarget.submissions,
                    'Preview link input must not submit the Admin mutation form.');
                assert.deepEqual(linkResult.placement, linkTarget.placement,
                    'Primary preview link activation must not change the unsaved floating placement.');
                assert.equal(linkResult.previewForms, 0,
                    'The scriptless protected preview must not expose a submit-capable form.');

                await mouse('mouseMoved', linkTarget.x, linkTarget.y, 0);
                await mouse('mousePressed', linkTarget.x, linkTarget.y, 1, 1, {modifiers: 2});
                await mouse('mouseReleased', linkTarget.x, linkTarget.y, 0, 1, {modifiers: 2});
                await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                const modifiedLinkResult = await evaluate(`(() => {
                    const observation = window.__widgetAdminNativePreviewLink;
                    const click = observation?.eventTrace.filter(event => event.type === 'click').at(-1);
                    return {
                        adminUrl: location.href,
                        previewUrl: observation?.previewFrame.contentWindow.location.href,
                        samePreviewDocument: observation?.previewFrame.contentDocument === observation?.previewDocument,
                        submissions: window.__widgetAdminSubmissionCount(),
                        linkClicks: observation?.linkClicks,
                        defaultPrevented: click?.defaultPrevented,
                        ctrlKey: click?.ctrlKey,
                        eventTrace: observation?.eventTrace,
                        placement: {
                            anchor: document.querySelector('[name="floating_anchor"]')?.value || '',
                            x: document.querySelector('[name="x_permille"]')?.value || '',
                            y: document.querySelector('[name="y_permille"]')?.value || '',
                        },
                    };
                })()`);
                assert.equal(modifiedLinkResult.linkClicks, 2,
                    'A native Ctrl-click must reach the rendered safe preview link.');
                assert.equal(modifiedLinkResult.ctrlKey, true,
                    'The modifier-click fixture must deliver a trusted Ctrl activation.');
                assert.equal(modifiedLinkResult.eventTrace?.filter(event => event.type === 'click').at(-1)?.trusted, true,
                    'The modifier activation must be a trusted native click.');
                assert.equal(modifiedLinkResult.defaultPrevented, true,
                    'The protected preview must cancel modified safe-link activation.');
                assert.equal(modifiedLinkResult.adminUrl, linkTarget.adminUrl,
                    'Modified preview link input must leave the Admin URL unchanged.');
                assert.equal(modifiedLinkResult.previewUrl, linkTarget.previewUrl,
                    'Modified preview link input must leave the protected public URL unchanged.');
                assert.equal(modifiedLinkResult.samePreviewDocument, true,
                    'Modified preview link input must not replace or navigate the iframe document.');
                assert.equal(modifiedLinkResult.submissions, linkTarget.submissions,
                    'Modified preview link input must not submit the Admin mutation form.');
                assert.deepEqual(modifiedLinkResult.placement, linkTarget.placement,
                    'Modified preview link activation must not change the unsaved floating placement.');

                await mouse('mouseMoved', linkTarget.x, linkTarget.y, 0);
                await mouse('mousePressed', linkTarget.x, linkTarget.y, 4, 1, {button: 'middle'});
                await mouse('mouseReleased', linkTarget.x, linkTarget.y, 0, 1, {button: 'middle'});
                await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
                const auxiliaryLinkResult = await evaluate(`(() => {
                    const observation = window.__widgetAdminNativePreviewLink;
                    const activation = observation?.eventTrace.filter(event => event.type === 'auxclick').at(-1);
                    return {
                        adminUrl: location.href,
                        previewUrl: observation?.previewFrame.contentWindow.location.href,
                        samePreviewDocument: observation?.previewFrame.contentDocument === observation?.previewDocument,
                        submissions: window.__widgetAdminSubmissionCount(),
                        linkClicks: observation?.linkClicks,
                        auxiliaryClicks: observation?.auxiliaryClicks,
                        defaultPrevented: activation?.defaultPrevented,
                        button: activation?.button,
                        placement: {
                            anchor: document.querySelector('[name="floating_anchor"]')?.value || '',
                            x: document.querySelector('[name="x_permille"]')?.value || '',
                            y: document.querySelector('[name="y_permille"]')?.value || '',
                        },
                        eventTrace: observation?.eventTrace,
                    };
                })()`);
                assert.equal(auxiliaryLinkResult.auxiliaryClicks, 1,
                    'A native middle-button action must reach the rendered safe link as auxclick. Events: '
                        + JSON.stringify(auxiliaryLinkResult.eventTrace));
                assert.equal(auxiliaryLinkResult.defaultPrevented, true,
                    'The protected preview must cancel native middle-button link activation.');
                assert.equal(auxiliaryLinkResult.button, 1,
                    'The auxiliary activation must retain its native middle-button identity.');
                assert.equal(auxiliaryLinkResult.eventTrace?.filter(event => event.type === 'auxclick').at(-1)?.trusted, true,
                    'The middle-button activation must be a trusted native auxclick.');
                assert.equal(auxiliaryLinkResult.linkClicks, 2,
                    'Middle-button activation must not create a primary click.');
                assert.equal(auxiliaryLinkResult.adminUrl, linkTarget.adminUrl,
                    'Middle-button preview input must leave the Admin URL unchanged.');
                assert.equal(auxiliaryLinkResult.previewUrl, linkTarget.previewUrl,
                    'Middle-button preview input must leave the protected public URL unchanged.');
                assert.equal(auxiliaryLinkResult.samePreviewDocument, true,
                    'Middle-button preview input must not replace or navigate the iframe document.');
                assert.equal(auxiliaryLinkResult.submissions, linkTarget.submissions,
                    'Middle-button preview input must not submit the Admin mutation form.');
                assert.deepEqual(auxiliaryLinkResult.placement, linkTarget.placement,
                    'Middle-button link activation must not change the unsaved floating placement.');

                await evaluate("fetch('/preview?empty-home=1', {credentials: 'same-origin', cache: 'no-store'})");
                await evaluate(`(() => {
                    const mode = document.querySelector('[name="placement_mode"]');
                    mode.value = 'flow';
                    mode.dispatchEvent(new Event('change', {bubbles: true}));
                    document.querySelector('[name="widget_id"]').value = 'ffffffffffffffffffffffffffffffff';
                    const page = document.querySelector('[data-widget-preview-page-selector]');
                    page.value = 'home';
                    page.dispatchEvent(new Event('change', {bubbles: true}));
                    const slot = document.querySelector('[name="flow_slot"]');
                    slot.value = 'home_before_grid';
                    slot.dispatchEvent(new Event('change', {bubbles: true}));
                })()`);
                let emptyHome = null;
                for (let attempt = 0; attempt < 400; attempt += 1) {
                    emptyHome = await evaluate(`(() => {
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        const doc = frame?.contentDocument;
                        const list = doc?.querySelector('.gallery-list-content');
                        const draft = doc?.querySelector('[data-widget-theme-draft]');
                        const savedAfter = doc?.querySelector('[data-public-widget-zone="home_after_grid"]');
                        return doc?.title === 'Protected empty Home preview fixture' && draft && list && savedAfter
                            ? {url: frame.contentWindow.location.href, grid: Boolean(list.querySelector('[data-public-gallery-index-grid]')),
                                pagination: list.querySelectorAll('nav.pagination').length,
                                emptyChildren: list.children.length,
                                mode: document.querySelector('[name="placement_mode"]')?.value,
                                draftVisible: !draft.hidden,
                                draftBeforeSavedAfter: Boolean(draft.closest('[data-widget-theme-draft-region]')
                                    ?.nextElementSibling === savedAfter),
                                draftParent: draft.parentElement?.dataset.publicWidgetZone || '',
                                wrapperCount: doc.querySelectorAll('.public-widget-rails-container').length}
                            : null;
                    })()`);
                    if (emptyHome) break;
                    await evaluate('new Promise(resolve => requestAnimationFrame(resolve))');
                }
                assert.ok(emptyHome, 'The protected preview must load the dedicated empty-Home document.');
                assert.equal(emptyHome.grid, false, 'Empty Home must not fabricate a gallery grid.');
                assert.equal(emptyHome.pagination, 0, 'Empty Home must not fabricate pagination.');
                assert.equal(emptyHome.mode, 'flow', 'The empty-home zone checks use flow placement.');
                assert.equal(emptyHome.draftVisible, true, 'The fresh empty-home draft remains visible after the prior dismissal.');
                assert.equal(emptyHome.draftBeforeSavedAfter, true,
                    'The before-grid draft must precede the saved after-grid widget in the empty gallery-list content.');
                assert.equal(emptyHome.draftParent, 'home_before_grid');
                assert.equal(emptyHome.wrapperCount, 0, 'The empty page starts without a rail wrapper.');

                const contentBottom = await evaluate(`(() => {
                    const frame = document.querySelector('.public-widgets-theme-frame');
                    const doc = frame.contentDocument;
                    const slot = document.querySelector('[name="flow_slot"]');
                    slot.value = 'content_bottom';
                    slot.dispatchEvent(new Event('change', {bubbles: true}));
                    return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => {
                        const region = doc.querySelector('[data-widget-theme-draft-region]');
                        const main = doc.querySelector('main.site-main');
                        const savedAfter = doc.querySelector('[data-public-widget-zone="home_after_grid"]');
                        const floating = main?.querySelector('.public-widget-region--floating');
                        return resolve({zone: region?.dataset.publicWidgetZone, floating: Boolean(floating),
                            insideList: Boolean(region?.closest('.gallery-list-content')),
                            afterGridBeforeBottom: Boolean(savedAfter && (savedAfter.compareDocumentPosition(region)
                                & doc.defaultView.Node.DOCUMENT_POSITION_FOLLOWING)),
                            afterGridBeforeFloating: Boolean(savedAfter && floating
                                && (savedAfter.compareDocumentPosition(floating)
                                    & doc.defaultView.Node.DOCUMENT_POSITION_FOLLOWING)),
                            beforeFloating: Boolean(floating && (region.compareDocumentPosition(floating)
                                & doc.defaultView.Node.DOCUMENT_POSITION_FOLLOWING))});
                    })));
                })()`);
                assert.equal(contentBottom.zone, 'content_bottom', 'Content-bottom draft uses its named public zone.');
                assert.equal(contentBottom.insideList, false, 'Content-bottom draft remains outside the empty gallery list.');
                assert.equal(contentBottom.floating, true, 'The empty Home fixture retains its saved floating region.');
                assert.equal(contentBottom.afterGridBeforeBottom, true,
                    'The saved after-grid widget remains before the content-bottom zone.');
                assert.equal(contentBottom.afterGridBeforeFloating, true,
                    'The saved after-grid widget remains before the floating region.');
                assert.equal(contentBottom.beforeFloating, true, 'Content-bottom draft stays ahead of the floating region.');

                for (let cycle = 0; cycle < 2; cycle += 1) {
                    const rail = await evaluate(`(() => {
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        const doc = frame.contentDocument;
                        const slot = document.querySelector('[name="flow_slot"]');
                        slot.value = 'right_rail';
                        slot.dispatchEvent(new Event('change', {bubbles: true}));
                        return (async () => {
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                            const wrapper = doc.querySelector('.public-widget-rails-container');
                            const layout = wrapper?.querySelector('.public-widget-content-layout');
                            const draftRegion = layout?.querySelector('[data-widget-theme-draft-region]');
                            if (!wrapper || !layout || !draftRegion) return null;
                            wrapper.style.width = '1200px';
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                            const wide = doc.defaultView.getComputedStyle(layout).display;
                            wrapper.style.width = '1000px';
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                            const narrow = doc.defaultView.getComputedStyle(layout).display;
                            return {wrappers: doc.querySelectorAll('.public-widget-rails-container').length,
                                wide, narrow, zone: draftRegion.dataset.publicWidgetZone,
                                viewportWidth: doc.documentElement.clientWidth,
                                primaryHasList: Boolean(layout.querySelector('.public-widget-primary .gallery-list-frame')),
                                noGrid: !doc.querySelector('[data-public-gallery-index-grid]')};
                        })();
                    })()`);
                    assert.ok(rail, 'Selecting a rail on an empty Home must create the responsive rail wrapper.');
                    assert.equal(rail.wrappers, 1, 'Exactly one rail container wraps the empty Home content.');
                    assert.equal(rail.viewportWidth, 1280, 'Container-query comparison uses the desktop Theme viewport.');
                    assert.equal(rail.wide, 'grid', 'A wide rail container uses the shared grid layout.');
                    assert.equal(rail.narrow, 'flex', 'A narrow rail container uses the shared stacked layout.');
                    assert.equal(rail.zone, 'right_rail');
                    assert.equal(rail.primaryHasList, true, 'The rail wrapper keeps the empty gallery list in primary content.');
                    assert.equal(rail.noGrid, true, 'Rail enhancement does not create a gallery grid.');
                    const removed = await evaluate(`(() => {
                        const frame = document.querySelector('.public-widgets-theme-frame');
                        const doc = frame.contentDocument;
                        const slot = document.querySelector('[name="flow_slot"]');
                        slot.value = 'content_bottom';
                        slot.dispatchEvent(new Event('change', {bubbles: true}));
                        return new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => resolve({
                            wrappers: doc.querySelectorAll('.public-widget-rails-container').length,
                            rails: doc.querySelectorAll('[data-public-widget-zone="left_rail"], [data-public-widget-zone="right_rail"]').length,
                            draftZone: doc.querySelector('[data-widget-theme-draft-region]')?.dataset.publicWidgetZone,
                        }))));
                    })()`);
                    assert.ok(removed, 'Rail removal must finish on the restored empty Home document.');
                    assert.equal(removed.wrappers, 0, 'Repeated rail removal must clean up the generated wrapper.');
                    assert.equal(removed.rails, 0, 'Repeated rail removal must remove both rail zones.');
                    assert.equal(removed.draftZone, 'content_bottom');
                }
            },
        });
    console.log(preview.result || 'Protected widget preview fixture produced no status marker.');
    assert.equal(preview.exitCode, 0, 'Protected widget preview browser process must exit cleanly.');
    assert.ok(preview.result?.includes('BROWSER PASS: widget protected Home/Gallery floating geometry, flow insertion and mobile flow fallback'),
        'Protected public preview must exercise both pages, flow insertion, peer-aware desktop geometry, mobile fallback and sandbox behavior.');
} finally {
    server.close();
}
