/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Test Support
 * Purpose: Run isolated browser fixtures through an owned Chromium DevTools session.
 * Responsibilities:
 *   - Confine headless browser access to loopback fixtures and disposable profiles.
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
 * Navigate one fresh owned tab to its loopback fixture and return the result marker.
 * @param {string} executable Installed Chromium or Edge executable path.
 * @param {string} url Loopback URL served by the calling fixture.
 * @param {string} profilePrefix Unique disposable profile prefix under cache.
 * @param {{readResult?:function():Promise<string>,timeoutMs?:number,interact?:function({evaluate:function(string):Promise<BrowserJsonValue|undefined>,mouse:function('mouseMoved'|'mousePressed'|'mouseReleased',number,number,number):Promise<void>,key:function('keyDown'|'keyUp',string):Promise<void>}):Promise<void>}} options Optional owned result reader, workflow deadline, and native input interaction. The evaluator returns a JSON-compatible protocol value or undefined when DevTools omits one.
 * @return {Promise<{result:string, exitCode:number|null}>} Fixture marker and owned process exit code.
 */
export async function runHeadlessBrowserFixture(executable, url, profilePrefix, options = {}) {
    if (typeof WebSocket !== 'function') throw new Error('Native WebSocket support (Node 22+) is required for browser fixtures');
    if (!/^[-a-z0-9]+-$/.test(profilePrefix)) throw new Error('Browser fixture profile prefix must be a simple local name');
    const fixtureUrl = new URL(url);
    if (fixtureUrl.protocol !== 'http:' || fixtureUrl.hostname !== '127.0.0.1') {
        throw new Error('Browser fixtures must use the loopback-only HTTP server');
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
            if (message.error) pending.reject(new Error('Owned Chromium protocol request failed'));
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
                requests.set(id, {resolve, reject, timer});
                debuggingSocket.send(JSON.stringify({id, method, params, sessionId}));
            });
        }

        const {targetInfos} = await command('Target.getTargets');
        const target = targetInfos.find(info => info.type === 'page' && info.url === 'about:blank');
        if (!target) throw new Error('Owned loopback fixture page not found');
        const {sessionId} = await command('Target.attachToTarget', {targetId: target.targetId, flatten: true});
        await command('Page.enable', {}, sessionId);
        // Keep native focus and :focus-visible behavior available in this owned headless page.
        await command('Emulation.setFocusEmulationEnabled', {enabled: true}, sessionId);
        // One explicit navigation avoids command-line startup stalls and never
        // reloads an already-running authenticated workflow.
        const navigation = await command('Page.navigate', {url}, sessionId);
        if (navigation.errorText) throw new Error('Owned fixture navigation failed');
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
             * Dispatch one native mouse input event in viewport CSS pixels.
             * @param {'mouseMoved'|'mousePressed'|'mouseReleased'} type DevTools mouse event phase.
             * @param {number} x Horizontal viewport coordinate in CSS pixels.
             * @param {number} y Vertical viewport coordinate in CSS pixels.
             * @param {number} buttons Active mouse-button bit mask after this event.
             * @returns {Promise<void>} Resolves after Chromium accepts the input event.
             */
            function mouse(type, x, y, buttons) {
                const params = {type, x, y, buttons};
                if (type === 'mousePressed' || type === 'mouseReleased') params.button = 'left';
                return command('Input.dispatchMouseEvent', params, sessionId).then(() => {});
            }

            /**
             * Dispatch one native keyboard phase to the owned page with DOM identity and Windows virtual-key metadata.
             * @param {'keyDown'|'keyUp'} type DevTools keyboard event phase used for both halves of activation.
             * @param {string} key Fixture key token; Space maps to DOM key ' ' and code 'Space', Enter/Space/Escape map to virtual-key codes 13/32/27, and keyDown sends text for Enter/Space.
             * @returns {Promise<void>} Resolves after Chromium accepts the key event.
             */
            function key(type, key) {
                const params = {
                    type,
                    key: key === 'Space' ? ' ' : key,
                    code: key === 'Space' ? 'Space' : key,
                    windowsVirtualKeyCode: key === 'Enter' ? 13 : key === 'Space' ? 32 : key === 'Escape' ? 27 : 0,
                };
                if (type === 'keyDown' && (key === 'Enter' || key === 'Space')) {
                    params.text = key === 'Enter' ? '\r' : ' ';
                    params.unmodifiedText = params.text;
                }
                return command('Input.dispatchKeyEvent', params, sessionId).then(() => {});
            }

            await options.interact({evaluate, mouse, key});
        }
        // Bound fixture completion independently from browser startup and protocol request deadlines.
        const deadline = Date.now() + Math.max(1000, Math.min(145000, options.timeoutMs ?? fixtureTimeoutMs));
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
        // Close the browser over its private DevTools socket before removing its profile.
        await command('Browser.close');
        const exitCode = await browserExit;
        return {result, exitCode};
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
