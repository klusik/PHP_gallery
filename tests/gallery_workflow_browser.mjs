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
import {readFile, realpath} from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {runHeadlessBrowserFixture} from './support/headless_browser_fixture.mjs';

const [directory, token, executable] = process.argv.slice(2);
try {
    if (!/^[a-f0-9]{24}$/.test(token || '')) throw new Error();
    const actual = await realpath(directory);
    const temporary = await realpath(os.tmpdir());
    if (path.dirname(actual).toLowerCase() !== temporary.toLowerCase() || path.basename(actual) !== `gallery-workflow-${token}`
        || await readFile(path.join(actual, '.workflow-owner'), 'utf8') !== token) throw new Error();
    const {url} = JSON.parse(await readFile(path.join(actual, 'endpoint.json'), 'utf8'));
    if (!/^http:\/\/127\.0\.0\.1:[1-9][0-9]{3,4}$/.test(url)) throw new Error();
    let result;
    // Chromium launchers may exit before their actual browser process on Windows.
    // The owned DevTools session closes the real browser and its cache-holding children.
    const {result: marker} = await runHeadlessBrowserFixture(executable, `${url}/__workflow_${token}`,
        'workflow-browser-profile-', {timeoutMs: 145000, readResult: async () => {
            try { result = JSON.parse(await readFile(path.join(actual, 'browser-result.json'), 'utf8')); }
            catch { return ''; }
            if (!/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) return 'BROWSER FAIL invalid workflow result';
            return `BROWSER ${result.ok ? 'PASS' : 'FAIL'} ${result.stage}`;
        }});
    if (!marker.startsWith('BROWSER ') || !result) throw new Error();
    if (!result || !/^[a-zA-Z0-9 ._-]{1,100}$/.test(result.stage || '')) throw new Error();
    console.log(`${result.ok ? 'PASS' : 'FAIL'} gallery workflow ${result.stage}`);
    if (!result.ok) process.exitCode = 1;
} catch {
    console.log('FAIL gallery workflow browser launch or result timeout');
    process.exitCode = 1;
}
