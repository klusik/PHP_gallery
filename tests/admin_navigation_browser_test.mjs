/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_navigation_browser_test.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise production grouped Admin navigation across responsive states.
 * Responsibilities: Verify persistence, storage denial, focus, viewport bounds
 * and replacement-sidebars using the complete production stylesheet cascade.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable) {
    console.log('SKIP Admin navigation browser: no Chromium executable supplied.');
    process.exit(0);
}
/**
 * Bound isolated production markup rendering.
 * Type: number. Units: milliseconds. Scope: one fixture PHP process.
 * Consumers: execFileSync.
 * Rationale: 10000ms detects a renderer hang before the browser startup budget.
 */
const RENDER_TIMEOUT_MS = 10000;
/**
 * Cover the expanded desktop and a narrow touch viewport.
 * Type: {width:number,height:number}[]. Units: CSS pixels. Scope: this fixture.
 * Consumers: initial browser viewport and responsive transitions.
 * Rationale: 1280x800 exposes the icon rail; 390x844 reproduces phone stacking.
 */
const VIEWPORTS = [{width: 1280, height: 800}, {width: 390, height: 844}];
/**
 * Enforce the shared mobile interaction minimum.
 * Type: number. Units: CSS pixels. Scope: mobile button and navigation links.
 * Consumers: touch geometry assertions.
 * Rationale: 44px follows the mandatory Admin design contract.
 */
const MOBILE_TARGET_PX = 44;
/**
 * Bound reload observation by actual document identity.
 * Type: number. Units: milliseconds. Scope: one fixture reload.
 * Consumers: navigation readiness loop.
 * Rationale: 3000ms permits a local reload without masking a missing new page.
 */
const RELOAD_TIMEOUT_MS = 3000;
/**
 * Yield between document-identity observations during reload.
 * Type: number. Units: milliseconds. Scope: reload readiness polling.
 * Consumers: navigation readiness loop.
 * Rationale: 20ms avoids a busy protocol loop while observing real completion.
 */
const RELOAD_POLL_MS = 20;
const rendered = execFileSync(process.env.PHP_BINARY || 'php',
    [path.join(root, 'tests/fixtures/admin_navigation_view.php')],
    {encoding: 'utf8', windowsHide: true, timeout: RENDER_TIMEOUT_MS});
const server = createServer(async (request, response) => {
    const pathname = new URL(request.url, 'http://localhost').pathname;
    if (pathname === '/') {
        const denial = '<script>Object.defineProperty(Storage.prototype,'
            + '"getItem",{configurable:true,value:()=>{throw new Error("denied")}});'
            + 'Object.defineProperty(Storage.prototype,"setItem",'
            + '{configurable:true,value:()=>{throw new Error("denied")}});</script>';
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(request.url.includes('denied=1')
            ? rendered.replace('<head>', '<head>' + denial) : rendered);
        return;
    }
    const file = path.resolve(root, '.' + pathname);
    if (!file.startsWith(path.join(root, 'public/assets') + path.sep)
        || !/\.(css|js)$/.test(file)) {
        response.writeHead(404).end();
        return;
    }
    try {
        response.setHeader('Content-Type', file.endsWith('.css')
            ? 'text/css' : 'text/javascript');
        response.end(await readFile(file));
    } catch {
        response.writeHead(404).end();
    }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const url = 'http://127.0.0.1:' + server.address().port + '/';
try {
    for (const denied of [false, true]) {
        const {result, exitCode} = await runHeadlessBrowserFixture(
            executable, url + (denied ? '?denied=1' : ''), 'admin-navigation-', {
                viewport: VIEWPORTS[0],
                interact: async ({evaluate, setViewport, key}) => {
                    const ready = () => evaluate(`new Promise(resolve => {
                        const observe = () => {
                            if (document.querySelector('.admin-navigation-ready')) {
                                requestAnimationFrame(() => resolve(true));
                            } else requestAnimationFrame(observe);
                        }; observe();
                    })`);
                    const settle = () => evaluate(`new Promise(resolve =>
                        requestAnimationFrame(() => requestAnimationFrame(resolve)))`);
                    const state = () => evaluate(`(() => {
                        const shell = document.querySelector('.admin-shell');
                        const sidebar = shell.querySelector('.admin-sidebar');
                        const panel = sidebar.querySelector('.admin-navigation-links');
                        const button = sidebar.querySelector('button');
                        const main = document.querySelector('main');
                        return {
                            collapsed: shell.classList.contains('admin-navigation-collapsed'),
                            hidden: panel.hidden,
                            expanded: button.getAttribute('aria-expanded'),
                            groups: panel.querySelectorAll('.admin-menu-group').length,
                            width: sidebar.getBoundingClientRect().width,
                            mainLeft: main.getBoundingClientRect().left,
                            mainTop: main.getBoundingClientRect().top,
                            buttonHeight: button.getBoundingClientRect().height,
                            focusOnTrigger: document.activeElement === button,
                            bottom: panel.getBoundingClientRect().bottom,
                            viewportHeight: innerHeight,
                            overflow: document.documentElement.scrollWidth > innerWidth,
                            background: getComputedStyle(button).backgroundColor,
                            current: [...panel.querySelectorAll('[aria-current="page"]')]
                                .map(link => link.textContent.trim()),
                        };
                    })()`);
                    const toggle = () => evaluate(
                        `document.querySelector('[data-admin-navigation-toggle]').click()`);
                    const reachLastDesktopLink = async (mode) => {
                        // Target the viewport interior so fractional edge
                        // alignment cannot obscure the reachability invariant.
                        await evaluate(`document.querySelector(
                            '.admin-menu-group:last-child .admin-menu-link:last-child'
                        ).scrollIntoView({block: 'center', behavior: 'instant'})`);
                        await settle();
                        const reached = await evaluate(`(() => {
                            const sidebar = document.querySelector('.admin-sidebar');
                            const last = sidebar.querySelector(
                                '.admin-menu-group:last-child .admin-menu-link:last-child'
                            ).getBoundingClientRect();
                            return {
                                pageScroll: scrollY,
                                menuScroll: sidebar.scrollTop,
                                contained: sidebar.scrollHeight <= sidebar.clientHeight,
                                lastTop: last.top,
                                lastBottom: last.bottom,
                                viewportHeight: innerHeight,
                                visible: last.top >= document.querySelector(
                                    '.site-header').getBoundingClientRect().bottom
                                    && last.bottom <= innerHeight,
                            };
                        })()`);
                        assert.ok(reached.pageScroll > 0,
                            mode + ' reaches the last action through page scrolling');
                        assert.equal(reached.menuScroll, 0,
                            mode + ' must not create a separate menu scroll position');
                        assert.equal(reached.contained, true,
                            mode + ' must not clip navigation into an internal scroller');
                        assert.equal(reached.visible, true,
                            mode + ' keeps the last action visible and reachable: '
                                + JSON.stringify(reached));
                        await evaluate(`scrollTo({
                            top: 0, left: 0, behavior: 'instant',
                        })`);
                        await settle();
                    };
                    await ready();
                    let before = await state();
                    assert.equal(before.collapsed, false, 'Expanded by default');
                    assert.equal(before.hidden, false);
                    assert.equal(before.groups, 5, 'All five production groups remain');
                    assert.deepEqual(before.current, ['Overview']);
                    assert.notEqual(before.background, 'rgb(37, 99, 168)',
                        'Legacy blue button rule must not win');
                    await reachLastDesktopLink('Expanded menu');
                    await toggle();
                    let after = await state();
                    assert.equal(after.collapsed, true);
                    assert.ok(after.width < before.width, 'Rail must free content width');
                    assert.ok(after.mainLeft < before.mainLeft);
                    assert.equal(after.hidden, false, 'Rail links remain available');
                    await reachLastDesktopLink('Icon rail');
                    const oldDocument = await evaluate('performance.timeOrigin');
                    await evaluate(`location.reload(); 'reloading'`);
                    const reloadDeadline = Date.now() + RELOAD_TIMEOUT_MS;
                    let reloaded = false;
                    while (Date.now() < reloadDeadline) {
                        try {
                            reloaded = await evaluate(`performance.timeOrigin !==
                                ${oldDocument} && Boolean(document.querySelector(
                                    '.admin-navigation-ready'))`);
                            if (reloaded) break;
                        } catch (error) {
                            if (!/context|navigat/i.test(error.message)) throw error;
                        }
                        await new Promise(resolve => setTimeout(resolve, RELOAD_POLL_MS));
                    }
                    assert.equal(reloaded, true, 'A new document must initialize');
                    after = await state();
                    assert.equal(after.collapsed, !denied,
                        'Reload restores preference only when storage is writable');
                    if (denied) await toggle();
                    await evaluate(`(() => {
                        const old = document.querySelector('.admin-sidebar');
                        old.replaceWith(old.cloneNode(true));
                    })()`);
                    await settle();
                    assert.equal((await state()).collapsed, true,
                        'Settings sidebar replacement retains in-memory state');
                    await setViewport(VIEWPORTS[1]);
                    await settle();
                    before = await state();
                    assert.equal(before.hidden, true, 'Mobile starts closed');
                    assert.equal(before.expanded, 'false');
                    assert.ok(before.buttonHeight >= MOBILE_TARGET_PX);
                    assert.ok(before.mainTop < VIEWPORTS[1].height,
                        'Page content is visible without scrolling past the menu');
                    await toggle();
                    after = await state();
                    assert.equal(after.hidden, false);
                    assert.equal(after.expanded, 'true');
                    assert.equal(after.groups, before.groups);
                    assert.equal(after.mainTop, before.mainTop,
                        'Disclosure overlays content without pushing it down');
                    assert.ok(after.bottom <= after.viewportHeight);
                    assert.equal(after.overflow, false);
                    assert.equal(await evaluate(`Array.from(document.querySelectorAll(
                        '.admin-menu-link')).every(link =>
                        link.getBoundingClientRect().height >= ${MOBILE_TARGET_PX})`), true);
                    await evaluate(`document.querySelector('.admin-menu-link').focus()`);
                    await key('keyDown', 'Escape');
                    await key('keyUp', 'Escape');
                    after = await state();
                    assert.equal(after.hidden, true);
                    assert.equal(after.focusOnTrigger, true);
                    await toggle();
                    await evaluate(`document.querySelector('#content-action').click()`);
                    assert.equal((await state()).hidden, true, 'Outside click closes');
                    await toggle();
                    await evaluate(`document.querySelector('#content-action').focus()`);
                    assert.equal((await state()).hidden, true, 'Leaving navigation closes');
                    await toggle();
                    await evaluate(`(() => {
                        const link = document.querySelector('a[href*="#admin-tab-galleries"]');
                        link.setAttribute('href', link.hash);
                        link.click();
                    })()`);
                    await settle();
                    assert.equal((await state()).hidden, true, 'Hash navigation closes');
                    await setViewport(VIEWPORTS[0]);
                    await settle();
                    assert.equal((await state()).collapsed, true,
                        'Mobile use preserves the desktop preference');
                    await evaluate(`(() => {
                        document.querySelectorAll('.admin-menu-link').forEach(link =>
                            link.classList.toggle('is-active', link.hash !== ''));
                    })()`);
                    await settle();
                    assert.deepEqual((await state()).current, ['All galleries'],
                        'Hash-selected styling updates accessible current destination');
                    await toggle();
                    assert.equal((await state()).collapsed, false,
                        'Replacement sidebar still accepts delegated actions');
                    await evaluate(`document.querySelector('#results').textContent =
                        'BROWSER PASS: grouped Admin rail, persistence, mobile disclosure and focus'`);
                },
            },
        );
        console.log((denied ? 'Storage denied: ' : 'Storage available: ') + result);
        assert.equal(exitCode, 0);
        assert.ok(result?.includes('BROWSER PASS'));
    }
} finally {
    server.close();
}
