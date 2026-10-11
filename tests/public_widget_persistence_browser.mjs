/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_widget_persistence_browser.mjs
 * Module Type: Regression Test
 * Purpose: Launch the focused public-widget persistence journey in Chromium.
 * Responsibilities:
 *   - Validate the owned workflow fixture before reading its bounded result.
 *   - Deliver a native Gallery fullscreen click, wait for the native state event, and restore the HUD with fresh-coordinate native pointer movement.
 *   - Report bounded input/native/fallback state when the fullscreen handshake does not settle.
 * Author: Rudolf Klusal
 * Standalone Chromium runner for the disposable public-widget HTTP workflow.
 */
import {readFile, realpath} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

// Purpose: Pace browser polling while the public-widget fullscreen interaction becomes ready.
// Type: integer.
// Units: milliseconds.
// Scope: Native-input target discovery and fullscreen acknowledgement in this owned fixture.
// Consumers: Both bounded interaction loops in the callback below.
// Rationale: A 100-millisecond cadence gives Chromium time to process page and input events without busy polling; each loop has its own fixed deadline.
const widgetBrowserInteractionPollIntervalMs = 100;

// Purpose: Bound post-interaction result polling for the isolated live public-widget persistence journey.
// Type: integer.
// Units: milliseconds.
// Scope: Maximum time spent waiting for the browser-written result after the native fullscreen interaction returns.
// Consumers: runHeadlessBrowserFixture() result poller.
// Rationale: Startup/navigation/setup reserve 60 seconds. Target readiness reserves 40 seconds plus one 5-second in-flight read. Installing diagnostics and three native click commands reserve 20 seconds. Fullscreen acknowledgement and HUD movement each have an 8-second phase deadline plus at most one 5-second in-flight command. The 35-second result poll and 30-second cleanup reserve bring the total to 216 seconds within the 225-second PHP child limit; HUD retries cannot extend their shared deadline.
const widgetBrowserTimeoutMs = 35000;
const [directory, token, executable] = process.argv.slice(2);
try {
    if (!/^[a-f0-9]{24}$/.test(token || '')) throw new Error();
    const actual = await realpath(directory);
    const temporary = await realpath(os.tmpdir());
    if (path.dirname(actual).toLowerCase() !== temporary.toLowerCase()
        || path.basename(actual) !== `gallery-workflow-${token}`
        || await readFile(path.join(actual, '.workflow-owner'), 'utf8') !== token) throw new Error();
    const {url} = JSON.parse(await readFile(path.join(actual, 'endpoint.json'), 'utf8'));
    if (!/^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(url)) throw new Error();
    let result;
    const {result: marker} = await runHeadlessBrowserFixture(executable, `${url}/__workflow_${token}/public-widgets`,
        'widget-persistence-browser-profile-', {timeoutMs: widgetBrowserTimeoutMs, viewport: {width: 1280, height: 900},
            interact: async ({evaluate, mouse}) => {
                // Purpose: Bound readiness for the user-activated Gallery fullscreen step after its real public page loads.
                // Type: number.
                // Units: milliseconds from browser-fixture interaction start.
                // Scope: Waiting for the owned browser page to publish its fullscreen-control coordinates.
                // Consumers: the native pointer activation callback below.
                // Rationale: The 40-second target allowance, native fullscreen handshake and its bounded pointer retry, post-interaction result poll, startup, and cleanup are budgeted against the 225-second PHP child limit above.
                const activationDeadline = Date.now() + 40000;
                let target = null;
                while (Date.now() < activationDeadline) {
                    const earlyResult = await readFile(path.join(actual, 'browser-widget-result.json'), 'utf8')
                        .then(JSON.parse).catch(() => null);
                    if (earlyResult?.ok === false) return;
                    target = await evaluate('window.__publicWidgetFullscreenTarget || null');
                    if (target && Number.isFinite(target.x) && Number.isFinite(target.y)
                        && target.x >= 0 && target.x < 1280 && target.y >= 0 && target.y < 900) break;
                    await new Promise(resolve => setTimeout(resolve, widgetBrowserInteractionPollIntervalMs));
                }
                if (!target || !Number.isFinite(target.x) || !Number.isFinite(target.y)
                    || target.x < 0 || target.x >= 1280 || target.y < 0 || target.y >= 900) {
                    throw new Error('Gallery fullscreen control did not become available for native pointer input');
                }
                const diagnosticsInstalled = await evaluate(`(() => {
                    const frame = document.querySelector('#application');
                    const page = frame?.contentDocument;
                    const control = page?.querySelector('[data-lightbox-action="fullscreen"]');
                    if (!page || !control) return false;
                    const evidence = {pointerdown: 0, click: 0, fullscreenerror: 0, fullscreenchange: 0};
                    page.__publicWidgetFullscreenActivationEvidence = evidence;
                    control.addEventListener('pointerdown', () => { evidence.pointerdown = 1; }, {capture: true, once: true});
                    control.addEventListener('click', () => { evidence.click = 1; }, {capture: true, once: true});
                    page.addEventListener('fullscreenerror', () => { evidence.fullscreenerror = 1; }, {capture: true, once: true});
                    page.addEventListener('fullscreenchange', () => { evidence.fullscreenchange = 1; }, {capture: true, once: true});
                    return true;
                })()`);
                if (diagnosticsInstalled !== true) {
                    throw new Error('Gallery fullscreen input diagnostics could not attach');
                }
                await mouse('mouseMoved', target.x, target.y, 0);
                await mouse('mousePressed', target.x, target.y, 1, 1);
                await mouse('mouseReleased', target.x, target.y, 0, 1);
                // Purpose: Bound native fullscreen state and its delivered document event after pointer activation.
                // Type: number.
                // Units: milliseconds from the fullscreen click.
                // Scope: The active lightbox in the owned public Gallery frame.
                // Consumers: the fullscreen acknowledgement loop below.
                // Rationale: Waiting for both state and the event prevents the pointer move from racing the product's fullscreenchange HUD synchronization.
                const fullscreenDeadline = Date.now() + 8000;
                let fullscreenState = null;
                while (Date.now() < fullscreenDeadline) {
                    fullscreenState = await evaluate(`(() => {
                        const frame = document.querySelector('#application');
                        const page = frame?.contentDocument;
                        const overlay = page?.querySelector('[data-lightbox]');
                        const evidence = page?.__publicWidgetFullscreenActivationEvidence || {};
                        return {
                            native: Boolean(page && overlay && page.fullscreenElement === overlay),
                            fallback: Boolean(overlay?.classList.contains('is-mobile-fullscreen')),
                            pointerdown: Number(evidence.pointerdown || 0),
                            click: Number(evidence.click || 0),
                            fullscreenerror: Number(evidence.fullscreenerror || 0),
                            fullscreenchange: Number(evidence.fullscreenchange || 0),
                            uiVisible: Boolean(overlay?.classList.contains('is-ui-visible')),
                        };
                    })()`);
                    if (fullscreenState?.native === true && fullscreenState.fullscreenchange > 0) {
                        break;
                    }
                    await new Promise(resolve => setTimeout(resolve, widgetBrowserInteractionPollIntervalMs));
                }
                if (fullscreenState?.native === true && fullscreenState.fullscreenchange > 0) {
                    // Purpose: Bound all HUD layout reads and native pointer retries together.
                    // Type: number. Units: milliseconds since Unix epoch.
                    // Scope: Native fullscreen HUD synchronization in this owned Gallery fixture.
                    // Consumers: The layout reads and two-attempt pointer loop below.
                    // Rationale: Eight seconds plus one in-flight protocol command fits the existing child budget without extending the result timeout.
                    const hudDeadline = Date.now() + 8000;
                    await evaluate(`new Promise(resolve => {
                        requestAnimationFrame(() => requestAnimationFrame(resolve));
                    })`);
                    if (Date.now() >= hudDeadline) throw new Error('Gallery native fullscreen HUD layout did not settle');
                    let fullscreenPointerTarget = await evaluate(`(() => {
                        const frame = document.querySelector('#application');
                        const page = frame?.contentDocument;
                        const overlay = page?.querySelector('[data-lightbox]');
                        const frameBounds = frame?.getBoundingClientRect();
                        const overlayBounds = overlay?.getBoundingClientRect();
                        if (!frameBounds || !overlayBounds || overlayBounds.width <= 0 || overlayBounds.height <= 0) return null;
                        return {x: Math.round(frameBounds.left + overlayBounds.left + overlayBounds.width / 2),
                            y: Math.round(frameBounds.top + overlayBounds.top + overlayBounds.height / 2)};
                    })()`);
                    for (let attempt = 0; attempt < 2 && fullscreenPointerTarget && Date.now() < hudDeadline; attempt += 1) {
                        if (fullscreenPointerTarget.x < 0 || fullscreenPointerTarget.x >= 1280
                            || fullscreenPointerTarget.y < 0 || fullscreenPointerTarget.y >= 900) break;
                        await mouse('mouseMoved', fullscreenPointerTarget.x, fullscreenPointerTarget.y, 0);
                        if (Date.now() >= hudDeadline) break;
                        fullscreenState = await evaluate(`(() => {
                            const frame = document.querySelector('#application');
                            const page = frame?.contentDocument;
                            const overlay = page?.querySelector('[data-lightbox]');
                            const evidence = page?.__publicWidgetFullscreenActivationEvidence || {};
                            return {native: Boolean(page && overlay && page.fullscreenElement === overlay),
                                fallback: Boolean(overlay?.classList.contains('is-mobile-fullscreen')),
                                pointerdown: Number(evidence.pointerdown || 0), click: Number(evidence.click || 0),
                                fullscreenerror: Number(evidence.fullscreenerror || 0),
                                fullscreenchange: Number(evidence.fullscreenchange || 0),
                                uiVisible: Boolean(overlay?.classList.contains('is-ui-visible'))};
                        })()`);
                        if (fullscreenState?.native === true && fullscreenState.fullscreenchange > 0
                            && fullscreenState.uiVisible === true) return;
                        if (attempt === 0) {
                            await new Promise(resolve => setTimeout(resolve, widgetBrowserInteractionPollIntervalMs));
                            if (Date.now() >= hudDeadline) break;
                            await evaluate(`new Promise(resolve => {
                                requestAnimationFrame(() => requestAnimationFrame(resolve));
                            })`);
                            if (Date.now() >= hudDeadline) break;
                            fullscreenPointerTarget = await evaluate(`(() => {
                                const frame = document.querySelector('#application');
                                const page = frame?.contentDocument;
                                const overlay = page?.querySelector('[data-lightbox]');
                                const frameBounds = frame?.getBoundingClientRect();
                                const overlayBounds = overlay?.getBoundingClientRect();
                                if (!frameBounds || !overlayBounds || overlayBounds.width <= 0 || overlayBounds.height <= 0) return null;
                                return {x: Math.round(frameBounds.left + overlayBounds.left + overlayBounds.width * 0.75),
                                    y: Math.round(frameBounds.top + overlayBounds.top + overlayBounds.height * 0.25)};
                            })()`);
                        }
                    }
                }
                const evidence = fullscreenState || {};
                const failure = evidence.native === true
                    ? 'Gallery fullscreen HUD handshake not settled'
                    : 'Gallery native fullscreen not entered';
                throw new Error(failure + ' pointer-' + Number(evidence.pointerdown || 0)
                    + ' click-' + Number(evidence.click || 0)
                    + ' fallback-' + Number(evidence.fallback || 0)
                    + ' fullscreenerror-' + Number(evidence.fullscreenerror || 0)
                    + ' fullscreenchange-' + Number(evidence.fullscreenchange || 0)
                    + ' ui-' + Number(evidence.uiVisible || 0));
            },
            readResult: async () => {
                try { result = JSON.parse(await readFile(path.join(actual, 'browser-widget-result.json'), 'utf8')); }
                catch { return ''; }
                if (!/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) return 'BROWSER FAIL invalid widget result';
                const detail = typeof result.detail === 'string'
                    && /^[a-zA-Z0-9 ._-]{1,180}$/.test(result.detail) ? result.detail : '';
                return `BROWSER ${result.ok ? 'PASS' : 'FAIL'} ${result.stage}${!result.ok && detail ? ' ' + detail : ''}`;
            }});
    if (!marker.startsWith('BROWSER ') || !result) throw new Error();
    if (!/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) throw new Error();
    const detail = typeof result.detail === 'string'
        && /^[a-zA-Z0-9 ._-]{1,180}$/.test(result.detail) ? ' ' + result.detail : '';
    console.log(`${result.ok ? 'PASS' : 'FAIL'} public widget persistence browser ${result.stage}${result.ok ? '' : detail}`);
    if (!result.ok) process.exitCode = 1;
} catch (error) {
    const detail = error instanceof Error
        ? error.message.replace(/[^a-zA-Z0-9 ._-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160) : '';
    console.log(`FAIL public widget persistence browser ${detail || 'launch or result timeout'}`);
    process.exitCode = 1;
}
