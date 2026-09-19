/**
 * Project: PHP Gallery
 * Author: Rudolf Klusal
 * Measure pure title-matching CPU work in a disposable real Chromium document.
 * This is not a phone benchmark or an end-to-end input/network latency test.
 */
import {createServer} from 'node:http';
import {spawn, execFileSync} from 'node:child_process';
import {readFile, mkdir, mkdtemp, rm, writeFile} from 'node:fs/promises';
import {createHash, randomBytes} from 'node:crypto';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const executable = process.argv[2];
const baseline = process.argv[3] || 'HEAD';
if (!executable || !/^[A-Za-z0-9][A-Za-z0-9/_.-]*$/.test(baseline)) {
    throw new Error('Usage: node scripts/benchmark_title_completion_browser.mjs CHROMIUM_PATH [GIT_REF]');
}
const relative = 'public/assets/gallery-modules/admin-gallery-title-completion.js';
const oldSource = execFileSync('git', ['show', baseline + ':' + relative], {cwd: root, encoding: 'utf8', windowsHide: true});
const currentSource = await readFile(path.join(root, relative), 'utf8');
const revision = execFileSync('git', ['rev-parse', '--verify', baseline], {cwd: root, encoding: 'utf8', windowsHide: true}).trim();
const token = randomBytes(16).toString('hex');
let resolveResult;
const completed = new Promise(resolve => { resolveResult = resolve; });
const html = `<!doctype html><meta charset="utf-8"><title>Isolated title matcher CPU measurement</title>
<script type="module">
import {findGalleryTitleCompletion as legacy} from '/legacy.js';
import {findGalleryTitleCompletion as current} from '/current.js';
/** Measure repeated lookups, excluding fixture construction and warm-up. */
function measure(matcher, rows) {
    for (let warm = 0; warm < 10; warm++) matcher(rows, 'Fl', 7);
    const samples = [];
    for (let trial = 0; trial < 9; trial++) {
        const start = performance.now();
        for (let iteration = 0; iteration < 500; iteration++) matcher(rows, 'Fl', 7);
        samples.push((performance.now() - start) / 500);
    }
    samples.sort((a,b) => a-b);
    return {median_ms_per_lookup: samples[4], samples_ms_per_lookup: samples};
}
try {
    const rows = [];
    for (const size of [100,1000,10000]) {
        const catalog = Array.from({length:size}, (_,index) => ({
            id:index+1, parent_id:7, title:'Flight ' + String(index+1).padStart(5,'0'),
            path:'synthetic/' + index, created_at:'2026-09-20 12:00:00'
        }));
        const bounded = catalog.slice(-8).reverse();
        if (legacy(catalog,'Fl',7) !== current(bounded,'Fl',7)) throw new Error('ranking mismatch');
        rows.push({catalog_size:size, returned_candidates:8,
            legacy:measure(legacy,catalog), current:measure(current,bounded)});
    }
    await fetch('/result/${token}', {method:'POST', body:JSON.stringify({ok:true,user_agent:navigator.userAgent,rows})});
} catch {
    await fetch('/result/${token}', {method:'POST', body:JSON.stringify({ok:false})});
}
</script>`;
const server = createServer(async (request, response) => {
    const route = new URL(request.url, 'http://localhost').pathname;
    if (request.method === 'POST' && route === '/result/' + token) {
        let body = '';
        for await (const chunk of request) {
            body += chunk;
            if (body.length > 65536) { response.writeHead(413).end(); resolveResult(null); return; }
        }
        try { resolveResult(JSON.parse(body)); } catch { resolveResult(null); }
        response.writeHead(204).end();
        return;
    }
    if (request.method !== 'GET' || !['/', '/legacy.js', '/current.js'].includes(route)) {
        response.writeHead(404).end(); return;
    }
    response.setHeader('Content-Type', route === '/' ? 'text/html; charset=utf-8' : 'text/javascript; charset=utf-8');
    response.end(route === '/' ? html : route === '/legacy.js' ? oldSource : currentSource);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
await mkdir(path.join(root, 'cache'), {recursive:true});
const profile = await mkdtemp(path.join(root, 'cache', 'title-benchmark-'));
let browser;
let timer;
try {
    browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-background-networking', '--disable-extensions', '--disable-component-update',
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--user-data-dir=' + profile,
        'http://127.0.0.1:' + server.address().port + '/'], {windowsHide:true, stdio:'ignore'});
    browser.once('error', () => resolveResult(null));
    timer = setTimeout(() => resolveResult(null), 30000);
    const result = await completed;
    if (!result?.ok || !Array.isArray(result.rows) || result.rows.length !== 3) {
        throw new Error('Browser matcher measurement failed or timed out.');
    }
    const report = {
        recorded_at:new Date().toISOString(), baseline:revision,
        current_sha256:createHash('sha256').update(currentSource).digest('hex'),
        scope:'Synchronous matcher CPU only. No DOM painting, debounce, HTTP latency, full-page cost, or phone claim.',
        ...result,
    };
    const directory = path.join(root, 'cache', 'benchmarks');
    await mkdir(directory, {recursive:true});
    await writeFile(path.join(directory, 'title-completion-browser.json'), JSON.stringify(report,null,2) + '\n');
    console.log(JSON.stringify(report,null,2));
} finally {
    clearTimeout(timer);
    server.close();
    if (browser && browser.exitCode === null) {
        const exited = new Promise(resolve => browser.once('exit', resolve));
        browser.kill();
        let cleanupTimer;
        await Promise.race([exited, new Promise(resolve => { cleanupTimer = setTimeout(resolve, 5000); })]);
        clearTimeout(cleanupTimer);
    }
    if (path.dirname(profile) === path.join(root,'cache') && path.basename(profile).startsWith('title-benchmark-')) {
        await rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
}
