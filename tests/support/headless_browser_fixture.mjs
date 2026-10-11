/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Test Support
 * Purpose: Run isolated browser fixtures through an owned Chromium DevTools session.
 * Responsibilities:
 *   - Confine headless browser access to loopback fixtures and disposable profiles.
 *   - Expose native mouse button and modifier input through the shared fixture API.
 *   - Apply deterministic CSS viewport orientation and visual viewport scale changes.
 * File: tests/support/headless_browser_fixture.mjs
 * Author: Rudolf Klusal
 */
import {mkdtemp, readFile, rm} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const startupTimeoutMs = 15000;
const fixtureTimeoutMs = 30000;
// Purpose: Bound owned Chromium result polling while allowing the extended live Gallery workflow to finish.
// Type: integer.
// Units: milliseconds.
// Scope: Maximum result-polling window for every owned Chromium fixture.
// Consumers: runHeadlessBrowserFixture() completion deadline.
// Rationale: The live Gallery workflow's 280-second result window leaves 20 seconds of its 300-second PHP child budget for the observed browser startup, close and fixture cleanup overhead.
const maximumFixtureTimeoutMs = 280000;
// Target-document polling policy.
// Type: integer.
// Units: milliseconds.
// Scope: startup navigation for every owned Chromium fixture.
// Consumers: bounded loopback-origin and interactive-document readiness checks.
// Rationale: allow the local navigation to progress between inexpensive DevTools state reads.
const navigationPollIntervalMs = 20;
// Type: number.
// Units: UTF-16 code units of one source expression.
// Scope: error context for an expired DevTools command in the owned fixture.
// Consumers: the bounded protocol-timeout message, never the evaluated page result.
// Rationale: identify which evaluation stalled without copying an entire fixture expression into logs.
const protocolFailureContextLength = 120;

/**
 * A JSON-compatible value returned by a DevTools expression serialized by value.
 * @typedef {null|boolean|number|string|Array<BrowserJsonValue>|Record<string,BrowserJsonValue>} BrowserJsonValue
 */

/**
 * Optional native button and keyboard-modifier settings for one Chromium mouse event.
 * @typedef {{button?:'left'|'middle'|'right',modifiers?:number}} BrowserMouseInputOptions
 * @property {'left'|'middle'|'right'} [button='left'] Native button used for press and release events; ignored for mouse movement.
 * @property {number} [modifiers=0] Non-negative DevTools modifier bit mask for this event; omitted to use Chromium's default.
 */

/**
 * Bounded CSS viewport dimensions and optional emulated screen orientation.
 * @typedef {{width:number,height:number,orientation?:{type:'portraitPrimary'|'portraitSecondary'|'landscapePrimary'|'landscapeSecondary',angle:0|90|180|270}}} BrowserViewport
 */

/**
 * Navigate one fresh owned tab to its loopback fixture and return the result marker.
 * @param {string} executable Installed Chromium or Edge executable path.
 * @param {string} url Loopback URL served by the calling fixture.
 * @param {string} profilePrefix Unique disposable profile prefix under cache.
 * @param {{readResult?:function():Promise<string>,timeoutExpression?:string,timeoutMs?:number,viewport?:{width:number,height:number},safeAreaInsets?:{top:number,right:number,bottom:number,left:number},touchEmulation?:boolean,interact?:function({evaluate:function(string):Promise<BrowserJsonValue|undefined>,mouse:function('mouseMoved'|'mousePressed'|'mouseReleased',number,number,number,number=,BrowserMouseInputOptions=):Promise<void>,wheel:function(number,number,number,number):Promise<void>,key:function('keyDown'|'keyUp',string):Promise<void>,touch?:function('touchStart'|'touchMove'|'touchEnd'|'touchCancel',number,number,number,number=):Promise<void>,setViewport:function(BrowserViewport):Promise<void>,setVisualViewportScale:function(number):Promise<void>}):Promise<void>}} options Optional owned result reader, bounded timeout-diagnostic expression, workflow deadline, explicit CSS-pixel viewport, CSS safe-area override, optional touch emulation, and native input interaction. When supplied, safeAreaInsets must give integer CSS-pixel values from 0 through 3840 for every side; the experimental override is applied before navigation and unsupported Chromium builds fail clearly. touchEmulation enables one emulated contact before navigation. Interaction callbacks start after the owned loopback document reaches its interactive state. The evaluator returns a JSON-compatible protocol value or undefined when DevTools omits one. Mouse, wheel and touch helpers dispatch native DevTools input using viewport CSS pixels. The mouse helper accepts optional inputOptions: button defaults to left for press/release and is ignored for movement; modifiers are an optional non-negative DevTools keyboard-modifier bit mask. setViewport updates the emulated CSS viewport and optional screen orientation without claiming a physical device; setVisualViewportScale emulates page scale for the visual viewport, not browser UI zoom.
 * @param {string} [options.timeoutExpression] Bounded page expression evaluated once after the result deadline to return optional diagnostics capped at 500 characters.
 * @return {Promise<{result:string, exitCode:number|null, timeoutDetail:string}>} Fixture marker, owned process exit code and optional bounded timeout diagnostic.
 */
export async function runHeadlessBrowserFixture(executable, url, profilePrefix, options = {}) {
    if (typeof WebSocket !== 'function') throw new Error('Native WebSocket support (Node 22+) is required for browser fixtures');
    if (!/^[-a-z0-9]+-$/.test(profilePrefix)) throw new Error('Browser fixture profile prefix must be a simple local name');
    const fixtureUrl = new URL(url);
    if (fixtureUrl.protocol !== 'http:' || fixtureUrl.hostname !== '127.0.0.1') {
        throw new Error('Browser fixtures must use the loopback-only HTTP server');
    }
    if (options.safeAreaInsets !== undefined) {
        const insetSides = ['top', 'right', 'bottom', 'left'];
        const insets = options.safeAreaInsets;
        if (!insets || typeof insets !== 'object' || Array.isArray(insets)
            || Object.keys(insets).length !== insetSides.length
            || insetSides.some(side => !Number.isSafeInteger(insets[side])
                || insets[side] < 0 || insets[side] > 3840)
            || Object.keys(insets).some(side => !insetSides.includes(side))) {
            throw new Error('Owned Chromium safeAreaInsets must provide top, right, bottom and left as integer CSS pixels from 0 through 3840');
        }
    }
    if (options.touchEmulation !== undefined && typeof options.touchEmulation !== 'boolean') {
        throw new Error('Owned Chromium touchEmulation must be a boolean');
    }
    const cacheRoot = path.join(root, 'cache');
    const profile = await mkdtemp(path.join(cacheRoot, profilePrefix));
    const resolvedProfile = path.resolve(profile);
    let browser;
    let debuggingSocket;
    let browserExit;
    let output = '';
    let errors = '';

    try {
        browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-extensions', '--disable-component-update',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--remote-debugging-port=0',
            '--user-data-dir=' + profile, 'about:blank'], {windowsHide: true});
        // Preserve only bounded diagnostic tails; fixture results come from the owned DevTools page.
        browser.stdout.on('data', data => { output = (output + data).slice(-2000); });
        browser.stderr.on('data', data => { errors = (errors + data).slice(-2000); });
        browserExit = new Promise(
            /** Observe startup failure or the final exit of this disposable process. @param {(code:number|null)=>void} resolve Browser exit receiver. @param {(error:Error)=>void} reject Spawn failure receiver. @return {void} Registers process-local completion listeners. */
            (resolve, reject) => {
                browser.on('error', reject);
                browser.on('close', resolve);
            });

        const endpoint = await waitForOwnedEndpoint(profile);
        debuggingSocket = new WebSocket(endpoint);
        await new Promise((resolve, reject) => {
            debuggingSocket.addEventListener('open', resolve, {once: true});
            debuggingSocket.addEventListener('error', reject, {once: true});
        });
        let sequence = 0;
        const requests = new Map();
        debuggingSocket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            const pending = requests.get(message.id);
            if (!pending) return;
            requests.delete(message.id);
            clearTimeout(pending.timer);
            if (message.error) {
                const detail = typeof message.error.message === 'string' ? message.error.message : 'unknown protocol error';
                const failure = new Error('Owned Chromium DevTools ' + pending.method + ' failed: ' + detail);
                failure.protocolCode = message.error.code;
                pending.reject(failure);
            }
            else pending.resolve(message.result);
        });

        /**
         * Send a bounded request to the private browser session.
         * @param {string} method DevTools command name.
         * @param {Record<string, unknown>} params Command-specific values.
         * @param {string|undefined} sessionId Optional fixture page session.
         * @return {Promise<Record<string, unknown>>} DevTools response payload.
         */
        function command(method, params = {}, sessionId = undefined) {
            const id = ++sequence;
            return new Promise((resolve, reject) => {
                const timer = setTimeout(() => {
                    requests.delete(id);
                    const expression = typeof params.expression === 'string'
                        ? params.expression.replace(/\s+/g, ' ').slice(0, protocolFailureContextLength)
                        : '';
                    const context = method === 'Runtime.evaluate'
                        ? 'page evaluation ' + (expression ? '`' + expression + '`' : '')
                        : method === 'Input.dispatchMouseEvent' || method === 'Input.dispatchKeyEvent'
                            ? 'native ' + String(params.type || 'input') + ' input'
                            : 'browser command';
                    reject(new Error('Owned Chromium protocol timeout during ' + context + ' (' + method + ')'));
                }, 5000);
                requests.set(id, {resolve, reject, timer, method});
                debuggingSocket.send(JSON.stringify({id, method, params, sessionId}));
            });
        }

        const {targetInfos} = await command('Target.getTargets');
        const target = targetInfos.find(info => info.type === 'page' && info.url === 'about:blank');
        if (!target) throw new Error('Owned loopback fixture page not found');
        const {sessionId} = await command('Target.attachToTarget', {targetId: target.targetId, flatten: true});
        await command('Page.enable', {}, sessionId);
        if (options.viewport) {
            const {width, height} = options.viewport;
            if (!Number.isSafeInteger(width) || width < 320 || width > 3840
                || !Number.isSafeInteger(height) || height < 240 || height > 2160) {
                throw new Error('Owned Chromium viewport must use bounded positive CSS pixels');
            }
            await command('Emulation.setDeviceMetricsOverride', {
                width, height, deviceScaleFactor: 1, mobile: false,
            }, sessionId);
        }
        if (options.safeAreaInsets) {
            try {
                await command('Emulation.setSafeAreaInsetsOverride', {
                    insets: options.safeAreaInsets,
                }, sessionId);
            } catch (error) {
                if (error instanceof Error && error.protocolCode === -32601) {
                    throw new Error('Owned Chromium does not support the experimental Emulation.setSafeAreaInsetsOverride command required by this fixture');
                }
                throw error;
            }
        }
        if (options.touchEmulation) {
            await command('Emulation.setTouchEmulationEnabled', {enabled: true, maxTouchPoints: 1}, sessionId);
        }
        // Keep native focus and :focus-visible behavior available in this owned headless page.
        await command('Emulation.setFocusEmulationEnabled', {enabled: true}, sessionId);
        // One explicit navigation avoids command-line startup stalls and never
        // reloads an already-running authenticated workflow.
        const navigation = await command('Page.navigate', {url}, sessionId);
        if (navigation.errorText) throw new Error('Owned fixture navigation failed');
        // Page.navigate returns before the loopback document replaces about:blank in this target.
        const navigationDeadline = Date.now() + startupTimeoutMs;
        let fixtureDocumentReady = false;
        while (Date.now() < navigationDeadline) {
            const response = await command('Runtime.evaluate', {
                expression: '({origin:location.origin,readyState:document.readyState})',
                returnByValue: true,
            }, sessionId);
            const documentState = response.result?.value;
            if (documentState?.origin === fixtureUrl.origin && documentState.readyState !== 'loading') {
                fixtureDocumentReady = true;
                break;
            }
            await new Promise(resolve => setTimeout(resolve, navigationPollIntervalMs));
        }
        if (!fixtureDocumentReady) throw new Error('Owned loopback fixture document did not become interactive');
        if (options.interact) {
            /**
             * Evaluate a page expression and return its structured by-value result.
             * @param {string} expression Expression evaluated in the owned fixture page.
             * @returns {Promise<BrowserJsonValue|undefined>} JSON-compatible result value, or undefined when DevTools omits the value.
             */
            function evaluate(expression) {
                return command('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}, sessionId)
                    .then(response => response.result?.value);
            }

            /**
             * Dispatch one native mouse input event in viewport CSS pixels, using the left button by default for press/release and an optional click count.
             * @param {'mouseMoved'|'mousePressed'|'mouseReleased'} type DevTools mouse event phase.
             * @param {number} x Horizontal viewport coordinate in CSS pixels.
             * @param {number} y Vertical viewport coordinate in CSS pixels.
             * @param {number} buttons Active mouse-button bit mask after this event.
             * @param {number} [clickCount] Optional positive native click-sequence count for press/release events; omit during drags.
             * @param {BrowserMouseInputOptions} [inputOptions={}] Optional button for press/release and non-negative keyboard-modifier bit mask; button defaults to left and is ignored for movement.
             * @returns {Promise<void>} Resolves after Chromium accepts the input event.
             */
            function mouse(type, x, y, buttons, clickCount, inputOptions = {}) {
                const params = {type, x, y, buttons};
                if (inputOptions.modifiers !== undefined) {
                    if (!Number.isInteger(inputOptions.modifiers) || inputOptions.modifiers < 0) {
                        throw new Error('Owned Chromium mouse modifiers must be a non-negative integer when supplied');
                    }
                    params.modifiers = inputOptions.modifiers;
                }
                if (type === 'mousePressed' || type === 'mouseReleased') {
                    params.button = inputOptions.button ?? 'left';
                    if (!['left', 'middle', 'right'].includes(params.button)) {
                        throw new Error('Owned Chromium mouse button must be left, middle or right');
                    }
                    if (clickCount !== undefined) {
                        if (!Number.isInteger(clickCount) || clickCount < 1) {
                            throw new Error('Owned Chromium mouse clickCount must be a positive integer when supplied');
                        }
                        params.clickCount = clickCount;
                    }
                }
                return command('Input.dispatchMouseEvent', params, sessionId).then(() => {});
            }

            /**
             * Dispatch one native wheel input event in viewport CSS pixels.
             * @param {number} x Horizontal viewport coordinate in CSS pixels.
             * @param {number} y Vertical viewport coordinate in CSS pixels.
             * @param {number} deltaX Horizontal wheel delta in CSS pixels.
             * @param {number} deltaY Vertical wheel delta in CSS pixels.
             * @returns {Promise<void>} Resolves after Chromium accepts the wheel event.
             */
            function wheel(x, y, deltaX, deltaY) {
                return command('Input.dispatchMouseEvent', {
                    type: 'mouseWheel', x, y, deltaX, deltaY,
                }, sessionId).then(() => {});
            }

            /**
             * Dispatch one native keyboard phase to the owned page with DOM identity and Windows virtual-key metadata.
             * @param {'keyDown'|'keyUp'} type DevTools keyboard event phase used for both halves of activation.
             * @param {string} key Fixture key token; Space maps to DOM key ' ' and code 'Space', Tab/Enter/Space/Escape map to virtual-key codes 9/13/32/27, and keyDown sends text for Enter/Space.
             * @returns {Promise<void>} Resolves after Chromium accepts the key event.
             */
            function key(type, key) {
                const params = {
                    type,
                    key: key === 'Space' ? ' ' : key,
                    code: key === 'Space' ? 'Space' : key,
                    windowsVirtualKeyCode: key === 'Tab' ? 9 : key === 'Enter' ? 13
                        : key === 'Space' ? 32 : key === 'Escape' ? 27 : 0,
                };
                if (type === 'keyDown' && (key === 'Enter' || key === 'Space')) {
                    params.text = key === 'Enter' ? '\r' : ' ';
                    params.unmodifiedText = params.text;
                }
                return command('Input.dispatchKeyEvent', params, sessionId).then(() => {});
            }

            /**
             * Dispatch one native emulated touch-contact phase in viewport CSS pixels.
             * @param {'touchStart'|'touchMove'|'touchEnd'|'touchCancel'} type DevTools touch phase.
             * @param {number} x Horizontal contact coordinate; ignored for terminal phases.
             * @param {number} y Vertical contact coordinate; ignored for terminal phases.
             * @param {number} [id=1] Stable contact identity shared by the phases of one gesture.
             * @returns {Promise<void>} Resolves after Chromium accepts the touch input.
             */
            function touch(type, x, y, id = 1) {
                const params = {type, touchPoints: []};
                if (type === 'touchStart' || type === 'touchMove') {
                    params.touchPoints = [{x, y, id, radiusX: 1, radiusY: 1, force: 1}];
                }
                return command('Input.dispatchTouchEvent', params, sessionId).then(() => {});
            }

            /**
             * Change the owned page's CSS viewport and optional screen orientation.
             * @param {BrowserViewport} viewport Bounded CSS-pixel dimensions and optional orientation metadata.
             * @returns {Promise<void>} Resolves after Chromium applies the viewport emulation.
             */
            function setViewport(viewport) {
                const orientations = {
                    portraitPrimary: 0,
                    portraitSecondary: 180,
                    landscapePrimary: 90,
                    landscapeSecondary: 270,
                };
                if (!viewport || !Number.isSafeInteger(viewport.width) || viewport.width < 320 || viewport.width > 3840
                    || !Number.isSafeInteger(viewport.height) || viewport.height < 240 || viewport.height > 2160) {
                    throw new Error('Owned Chromium viewport must use bounded positive CSS pixels');
                }
                const params = {width: viewport.width, height: viewport.height, deviceScaleFactor: 1, mobile: false};
                if (viewport.orientation !== undefined) {
                    if (!viewport.orientation || !Object.hasOwn(orientations, viewport.orientation.type)
                        || viewport.orientation.angle !== orientations[viewport.orientation.type]) {
                        throw new Error('Owned Chromium orientation must use a matching primary or secondary screen angle');
                    }
                    params.screenOrientation = viewport.orientation;
                }
                return command('Emulation.setDeviceMetricsOverride', params, sessionId).then(() => {});
            }

            /**
             * Set the emulated visual viewport page scale independently of browser UI zoom.
             * @param {number} scale Positive visual-viewport scale from 0.25 through 4.
             * @returns {Promise<void>} Resolves after Chromium applies the page-scale emulation.
             */
            function setVisualViewportScale(scale) {
                if (typeof scale !== 'number' || !Number.isFinite(scale) || scale < 0.25 || scale > 4) {
                    throw new Error('Owned Chromium visual viewport scale must be finite and from 0.25 through 4');
                }
                return command('Emulation.setPageScaleFactor', {pageScaleFactor: scale}, sessionId).then(() => {});
            }

            await options.interact({evaluate, mouse, wheel, key, setViewport, setVisualViewportScale,
                ...(options.touchEmulation ? {touch} : {})});
        }
        // Bound fixture completion independently from browser startup and protocol request deadlines.
        const deadline = Date.now() + Math.max(1000, Math.min(maximumFixtureTimeoutMs, options.timeoutMs ?? fixtureTimeoutMs));
        let result = '';
        while (Date.now() < deadline) {
            if (options.readResult) {
                result = await options.readResult();
            } else {
                const response = await command('Runtime.evaluate', {
                    expression: 'document.getElementById("results")?.textContent || ""', returnByValue: true,
                }, sessionId);
                result = String(response.result.value || '');
            }
            if (/^BROWSER (PASS|FAIL)/.test(result)) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        if (!/^BROWSER (PASS|FAIL)/.test(result)) {
            if (options.readResult) {
                result = await options.readResult();
            } else {
                const response = await command('Runtime.evaluate', {
                    expression: 'document.getElementById("results")?.textContent || ""', returnByValue: true,
                }, sessionId);
                result = String(response.result.value || '');
            }
        }
        let timeoutDetail = '';
        if (!/^BROWSER (PASS|FAIL)/.test(result) && typeof options.timeoutExpression === 'string') {
            const response = await command('Runtime.evaluate', {
                expression: options.timeoutExpression,
                returnByValue: true,
            }, sessionId);
            const value = response.result?.value;
            timeoutDetail = typeof value === 'string' ? value.slice(0, 500) : '';
        }
        // Close the browser over its private DevTools socket before removing its profile.
        await command('Browser.close');
        const exitCode = await browserExit;
        return {result, exitCode, timeoutDetail};
    } catch (error) {
        if (browser && browser.exitCode === null) browser.kill();
        if (browserExit) await browserExit.catch(() => {});
        const diagnostic = errors.slice(-1500) || output.slice(-1500);
        if (diagnostic && error instanceof Error) error.message += '\n' + diagnostic;
        throw error;
    } finally {
        debuggingSocket?.close();
        if (browser && browser.exitCode === null) browser.kill();
        if (browserExit) await browserExit.catch(() => {});
        // Remove only the unique profile created under this repository's cache directory.
        if (path.dirname(resolvedProfile) === cacheRoot && path.basename(resolvedProfile).startsWith(profilePrefix)) {
            await rm(resolvedProfile, {recursive: true, force: true, maxRetries: 3}).catch(
                /** Leave a locked private profile recoverable without masking fixture results. @return {void} Ignores cleanup-only failure after the checked-path removal attempt. */
                () => {});
        }
    }
}

/**
 * Wait for DevToolsActivePort inside the exact newly-created profile.
 * @param {string} profile Exact disposable profile path.
 * @return {Promise<string>} WebSocket endpoint for its owned browser process.
 */
async function waitForOwnedEndpoint(profile) {
    const deadline = Date.now() + startupTimeoutMs;
    while (Date.now() < deadline) {
        try {
            const [port, socketPath] = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).trim().split(/\r?\n/);
            if (/^[0-9]+$/.test(port) && socketPath.startsWith('/devtools/browser/')) return `ws://127.0.0.1:${port}${socketPath}`;
        } catch { /* The owned browser is still starting. */ }
        await new Promise(resolve => setTimeout(resolve, 20));
    }
    throw new Error('Owned Chromium debugging endpoint unavailable');
}
