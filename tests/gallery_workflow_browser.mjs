/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_browser.mjs
 * Module Type: Regression Test
 * Purpose: Provide a standalone Chromium workflow fixture mechanism.
 * Responsibilities:
 *   - Launch isolated browser journeys without adding an interactive runtime dependency.
 * Author: Rudolf Klusal
 * Standalone Chromium fixture mechanism; no interactive browser runtime or dependency framework.
 */
import {spawn} from 'node:child_process';
import {readFile, realpath, mkdir} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';

const [directory, token, executable] = process.argv.slice(2);
let browser;
try {
    if (!/^[a-f0-9]{24}$/.test(token || '')) throw new Error();
    const actual = await realpath(directory);
    const temporary = await realpath(os.tmpdir());
    if (path.dirname(actual).toLowerCase() !== temporary.toLowerCase() || path.basename(actual) !== `gallery-workflow-${token}`
        || await readFile(path.join(actual, '.workflow-owner'), 'utf8') !== token) throw new Error();
    const {url} = JSON.parse(await readFile(path.join(actual, 'endpoint.json'), 'utf8'));
    if (!/^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(url)) throw new Error();
    const profile = path.join(actual, 'chromium-profile');
    await mkdir(profile);
    browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-component-update',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', `--user-data-dir=${profile}`,
        `${url}/__workflow_${token}`], {windowsHide: true, stdio: 'ignore'});
    let launchFailed = false;
    browser.on('error', () => { launchFailed = true; });
    const deadline = Date.now() + 145000;
    let result;
    while (Date.now() < deadline && !launchFailed) {
        try { result = JSON.parse(await readFile(path.join(actual, 'browser-result.json'), 'utf8')); break; }
        catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
    }
    if (!result || !/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) throw new Error();
    console.log(`${result.ok ? 'PASS' : 'FAIL'} gallery workflow ${result.stage}`);
    if (!result.ok) process.exitCode = 1;
} catch {
    console.log('FAIL gallery workflow browser launch or result timeout');
    process.exitCode = 1;
} finally {
    if (browser && browser.exitCode === null) {
        const exited = new Promise((resolve) => browser.once('exit', resolve));
        browser.kill();
        await Promise.race([exited, new Promise((resolve) => setTimeout(resolve, 5000))]);
    }
}
