/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Regression Test
 * Purpose: Exercise production drawer modules in disposable Chromium.
 * Responsibilities:
 *   - Verify panel lifecycle and keyboard behavior using loopback fixture assets only.
 * File: tests/admin_panel_lifecycle_browser_test.mjs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Run production drawer modules in a disposable Chromium document. The loopback
 * server exposes fixture/assets only and never loads PHP or installation data.
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile, mkdtemp, rm} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/** Absolute repository root used only to read first-party browser assets. */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
/** Browser process wall-clock deadline, in milliseconds; separate from virtual fixture time. */
const browserTimeoutMs = 45000;
/** Chromium executable supplied by the central browser registry. */
const executable = process.argv[2];
/** Explicit fixture allowlist; alternate wrapper never exposes arbitrary repository files. */
const fixtureName = process.argv[3] === 'admin_operation_keys.html' ? 'admin_operation_keys.html' : 'admin_panel_lifecycle.html';
if (!executable) {
    console.log('SKIP panel lifecycle browser: no Chromium executable supplied.');
    process.exit(0);
}

/** @type {function(string, boolean): Promise<void>|null} Trusted keyboard adapter after Chromium attaches. */
let sendKey = null;
/** @type {WebSocket|null} Owned Chromium debugging socket, never a user browser connection. */
let debuggingSocket = null;
/** @type {Promise<void>} Setup barrier for fixture keyboard requests. */
let browserReady;
/** @type {function(): Promise<string>|null} Read bounded assertion text from the owned fixture. */
let readFixtureResult = null;
/** @type {function(): Promise<Object>|null} Close only the disposable browser through its own socket. */
let closeOwnedBrowser = null;

/**
 * Attach only to the freshly created profile and expose Chromium's native key dispatch.
 * @param {string} profilePath Exact disposable profile directory.
 * @return {Promise<void>} Resolves when native keyboard injection is ready.
 */
async function attachKeyboard(profilePath) {
    if (typeof WebSocket !== 'function') throw new Error('Native WebSocket support (Node 22+) is required for trusted keyboard coverage');
    const deadline = Date.now() + 5000;
    let endpoint;
    while (Date.now() < deadline) {
        try {
            const [port, socketPath] = (await readFile(path.join(profilePath, 'DevToolsActivePort'), 'utf8')).trim().split(/\r?\n/);
            if (/^[0-9]+$/.test(port) && socketPath.startsWith('/devtools/browser/')) {
                endpoint = `ws://127.0.0.1:${port}${socketPath}`;
                break;
            }
        } catch { /* Chromium has not finished creating this owned profile. */ }
        await new Promise(/** Poll only this owned profile's endpoint file. @param {function(): void} resolve Continue the bounded discovery loop. @return {NodeJS.Timeout} Timer handle ignored by the promise executor. */ resolve => setTimeout(resolve, 20));
    }
    if (!endpoint) throw new Error('Owned Chromium debugging endpoint unavailable');
    debuggingSocket = new WebSocket(endpoint);
    await new Promise(/** Await the owned debugging connection before sending commands. @param {function(Event): void} resolve Accept socket-open notification. @param {function(Event): void} reject Reject socket setup failure. @return {void} Installs one-shot connection handlers. */ (resolve, reject) => {
        debuggingSocket.addEventListener('open', resolve, {once: true});
        debuggingSocket.addEventListener('error', reject, {once: true});
    });
    let sequence = 0;
    const requests = new Map();
    debuggingSocket.addEventListener('message', /** Resolve the matching owned DevTools command and release its timeout. @param {MessageEvent<string>} event Protocol response JSON. @return {void} Ignores unsolicited events without a pending command. */ event => {
        const message = JSON.parse(event.data);
        const pending = requests.get(message.id);
        if (!pending) return;
        requests.delete(message.id);
        clearTimeout(pending.timer);
        if (message.error) pending.reject(new Error('Owned Chromium protocol request failed'));
        else pending.resolve(message.result);
    });
    /**
     * Send one request to the owned browser, optionally scoped to its fixture page.
     * @param {string} method DevTools protocol method.
     * @param {Record<string, unknown>} params Protocol fields selected by this fixture, such as targetId, key or evaluation expression.
     * @param {string|undefined} sessionId Attached fixture page session.
     * @return {Promise<Record<string, unknown>>} Method-specific DevTools response, rejected on protocol error or timeout.
     */
    function command(method, params = {}, sessionId = undefined) {
        const id = ++sequence;
        return new Promise(/** Register one command before writing its protocol request. @param {function(Record<string, unknown>): void} resolve Matching response consumer. @param {function(Error): void} reject Protocol failure consumer. @return {void} Stores request-local completion callbacks and timeout. */ (resolve, reject) => {
            const timer = setTimeout(/** Reject only the expired command and release its registry entry. @return {void} Does not close a user-owned browser. */ () => { requests.delete(id); reject(new Error('Owned Chromium protocol timeout')); }, 5000);
            requests.set(id, {resolve, reject, timer});
            debuggingSocket.send(JSON.stringify({id, method, params, sessionId}));
        });
    }
    const {targetInfos} = await command('Target.getTargets');
    const target = targetInfos.find(/** Select the confined page in this freshly created browser profile. @param {{type: string, url: string, targetId: string}} info DevTools target description. @return {boolean} Whether it is the loopback fixture page. */ info => info.type === 'page' && info.url.startsWith('http://127.0.0.1:'));
    if (!target) throw new Error('Owned fixture page not found');
    const {sessionId} = await command('Target.attachToTarget', {targetId: target.targetId, flatten: true});
    /** Send a complete native key press to the attached fixture page. @param {string} key Tab or Escape. @param {boolean} shift Whether Shift is held. @return {Promise<void>} Resolves after key-down and key-up delivery. */
    sendKey = async (key, shift) => {
        const params = {key, code: key, windowsVirtualKeyCode: key === 'Tab' ? 9 : 27, modifiers: shift ? 8 : 0};
        await command('Input.dispatchKeyEvent', {...params, type: 'rawKeyDown'}, sessionId);
        await command('Input.dispatchKeyEvent', {...params, type: 'keyUp'}, sessionId);
    };
    /** Read only the fixture's bounded result text from its attached page. @return {Promise<string>} Current assertion summary, never application data. */
    readFixtureResult = async () => {
        const response = await command('Runtime.evaluate', {expression: 'document.getElementById("results")?.textContent || ""', returnByValue: true}, sessionId);
        return String(response.result.value || '');
    };
    /** Close only the disposable browser attached through its own profile socket. @return {Promise<Record<string, unknown>>} Browser-close protocol acknowledgment. */
    closeOwnedBrowser = () => command('Browser.close');
}

/**
 * Serve the single synthetic document and confined public JavaScript assets.
 * @param {import('node:http').IncomingMessage} request Loopback request.
 * @param {import('node:http').ServerResponse} response Fixture response.
 * @return {Promise<void>} Resolves once bytes or a bounded failure are sent.
 */
async function serveFixture(request, response) {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/__key') {
        const parameters = new URL(request.url, 'http://localhost').searchParams;
        const key = parameters.get('key');
        if (!['Tab', 'Escape'].includes(key)) { response.writeHead(400).end(); return; }
        try {
            await browserReady;
            await sendKey(key, parameters.get('shift') === '1');
            response.end('ok');
        } catch { response.writeHead(500).end('Keyboard fixture unavailable'); }
        return;
    }
    const relative = pathname === '/' ? 'tests/fixtures/' + fixtureName
        : /^\/public\/assets\/[a-zA-Z0-9_/-]+\.js$/.test(pathname) ? pathname.slice(1) : '';
    if (!relative || relative.includes('..')) { response.writeHead(404).end(); return; }
    try {
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html; charset=utf-8' : 'text/javascript; charset=utf-8');
        response.end(await readFile(path.join(root, relative)));
    } catch { response.writeHead(500).end(); }
}

const server = createServer(serveFixture);
await new Promise(/** Bind an ephemeral loopback-only fixture server. @param {function(): void} resolve Listening notification. @return {import('node:http').Server} Server handle ignored by the executor. */ resolve => server.listen(0, '127.0.0.1', resolve));
const profile = await mkdtemp(path.join(root, 'cache', 'panel-lifecycle-browser-'));
let browser;
let browserExit;
try {
    browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-component-update',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
        '--remote-debugging-port=0',
        '--user-data-dir=' + profile,
        'http://127.0.0.1:' + server.address().port + '/'], {windowsHide: true});
    browserReady = attachKeyboard(profile);
    // The HTTP keyboard boundary reports setup failure without an unhandled rejection.
    browserReady.catch(/** Consume the setup rejection already reported at the HTTP keyboard boundary. @return {void} Avoids an unhandled rejection without treating setup as successful. */ () => {});
    browser.stdout.resume();
    browser.stderr.resume();
    browserExit = new Promise(/** Observe only the disposable Chromium process lifetime. @param {function(number|null): void} resolve Process-close exit code consumer. @param {function(Error): void} reject Spawn failure consumer. @return {void} Binds process-local completion handlers. */ (resolve, reject) => { browser.on('error', reject); browser.on('close', resolve); });
    const timer = setTimeout(/** Enforce the owned fixture's wall-clock deadline. @return {boolean} Whether the process termination signal was sent. */ () => browser.kill(), browserTimeoutMs);
    try {
        await browserReady;
        const deadline = Date.now() + 30000;
        let result = '';
        while (Date.now() < deadline) {
            result = await readFixtureResult();
            if (/^BROWSER (PASS|FAIL)/.test(result)) break;
            await new Promise(/** Pace bounded assertion-result polling. @param {function(): void} resolve Continue the result loop. @return {NodeJS.Timeout} Timer handle ignored by the promise executor. */ resolve => setTimeout(resolve, 100));
        }
        console.log(result || 'BROWSER FAIL: fixture did not produce a result');
        await closeOwnedBrowser();
        const code = await browserExit;
        assert.equal(code, 0, 'Owned Chromium process exits successfully');
        assert.ok(result?.startsWith('BROWSER PASS'), 'Production drawer fixture must finish every assertion');
    } finally { clearTimeout(timer); }
} finally {
    debuggingSocket?.close();
    server.close();
    if (browser && browser.exitCode === null) browser.kill();
    if (browserExit) await browserExit.catch(/** Allow owned-profile cleanup after a previously reported process failure. @return {void} Consumes only the duplicate exit rejection. */ () => {});
    // Delete only the exact unique profile created above, within the fixture cache.
    if (path.dirname(profile) === path.join(root, 'cache') && path.basename(profile).startsWith('panel-lifecycle-browser-')) {
        await rm(profile, {recursive: true, force: true, maxRetries: 3});
    }
}
