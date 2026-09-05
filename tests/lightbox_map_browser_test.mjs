/** Run the real lightbox module in an installed headless Chromium browser, with local synthetic media only. */
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile, mkdtemp} from 'node:fs/promises';
import {spawn, execFileSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
if (!executable || executable.startsWith('--')) {
    console.log('SKIP browser map test: supply the path to an installed Chrome/Edge executable (see TESTING.md).');
    process.exit(0);
}
const baseline = process.argv.includes('--baseline');
const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url, 'http://localhost');
        if (/^\/photo\/\d+$/.test(url.pathname)) {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<pre id="results">BROWSER FAIL: unexpected page fallback to ${url.pathname}</pre>`);
            return;
        }
        const relative = url.pathname === '/'
            ? 'tests/fixtures/lightbox_map_navigation.html' : url.pathname.slice(1);
        const target = path.resolve(root, relative);
        if (!target.startsWith(root + path.sep) || (!relative.startsWith('public/assets/') && relative !== 'tests/fixtures/lightbox_map_navigation.html')) {
            response.writeHead(404).end(); return;
        }
        const body = baseline && relative === 'public/assets/gallery-modules/lightbox.js'
            ? execFileSync('git', ['show', 'HEAD:public/assets/gallery-modules/lightbox.js'], {cwd: root})
            : await readFile(target);
        response.setHeader('Content-Type', relative.endsWith('.html') ? 'text/html' : relative.endsWith('.css') ? 'text/css' : 'text/javascript');
        response.end(body);
    } catch { response.writeHead(404).end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const profile = await mkdtemp(path.join(root, 'cache', 'map-browser-profile-'));
const url = `http://127.0.0.1:${server.address().port}/`;
try {
    const browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', `--user-data-dir=${profile}`,
        '--dump-dom', '--virtual-time-budget=15000', url], {windowsHide: true});
    let output = ''; let errors = '';
    browser.stdout.on('data', data => { output += data; });
    browser.stderr.on('data', data => { errors += data; });
    const timeout = setTimeout(() => browser.kill(), 45000);
    try {
        const exit = await new Promise((resolve, reject) => { browser.on('error', reject); browser.on('close', resolve); });
        const result = output.match(/<pre id="results"[^>]*>([^]*?)<\/pre>/)?.[1];
        console.log(result || errors.slice(-1500) || 'Browser left the fixture without a result.');
        assert.equal(exit, 0, 'Browser process must finish successfully');
        assert.ok(result?.includes('BROWSER PASS'), 'Browser fixture must complete all assertions');
    } finally { clearTimeout(timeout); }
} finally { server.close(); }
