/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_pdf_conversion_browser_scenarios.mjs
 * Module Type: Browser Regression Test
 * Purpose: Exercise protected PDF.js-to-PNG conversion with the real local worker in Chromium.
 * Responsibilities: Verify page ordering, real PNG pixels, DPI/orientation, pull backpressure, cancellation and same-origin access.
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
const fixtureToken = 'owned-pdf-fixture-token';
const supportedPageCounts = new Set([1, 40, 41, 120, 151]);

/**
 * Build a small valid PDF with explicit cross-reference offsets and colored corner marks.
 * @param {number} pageCount Number of pages in the fixture document.
 * @returns {Buffer} Complete synthetic PDF document with portrait pages and one landscape page when available.
 */
function buildSyntheticPdf(pageCount) {
    let source = '%PDF-1.4\n';
    const offsets = [0];
    const objectCount = 3 + pageCount * 2;
    const kids = Array.from({length: pageCount}, (_, index) => `${3 + index * 2} 0 R`).join(' ');
    /**
     * Append one numbered PDF object and remember its byte offset for the cross-reference table.
     * @param {number} id Positive PDF object number.
     * @param {string} body ASCII object body.
     * @returns {void} Adds one complete object to the document source.
     */
    function appendObject(id, body) {
        offsets[id] = Buffer.byteLength(source, 'ascii');
        source += `${id} 0 obj\n${body}\nendobj\n`;
    }
    appendObject(1, '<< /Type /Catalog /Pages 2 0 R >>');
    appendObject(2, `<< /Type /Pages /Kids [${kids}] /Count ${pageCount} >>`);
    for (let index = 0; index < pageCount; index++) {
        const pageId = 3 + index * 2;
        const contentId = pageId + 1;
        const landscape = index === 1;
        const width = landscape ? 144 : 72;
        const height = landscape ? 72 : 144;
        const content = [
            'q',
            '0.90 0.10 0.10 rg',
            '0 0 12 12 re f',
            '0.10 0.20 0.90 rg',
            `${width - 12} ${height - 12} 12 12 re f`,
            'Q',
        ].join('\n');
        appendObject(pageId, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${width} ${height}] /Resources << >> /Contents ${contentId} 0 R >>`);
        appendObject(contentId, `<< /Length ${Buffer.byteLength(content, 'ascii')} >>\nstream\n${content}\nendstream`);
    }
    const xrefOffset = Buffer.byteLength(source, 'ascii');
    source += `xref\n0 ${objectCount}\n0000000000 65535 f \n`;
    for (let id = 1; id < objectCount; id++) {
        source += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`;
    }
    source += `trailer\n<< /Size ${objectCount} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF\n`;
    return Buffer.from(source, 'ascii');
}

if (!executable || executable.startsWith('--')) {
    console.log('SKIP gallery PDF conversion browser test: Chromium executable not supplied.');
    process.exit(0);
}

const pdfDocuments = new Map([...supportedPageCounts].map(pageCount => [pageCount, buildSyntheticPdf(pageCount)]));
const protectedPdfRequests = [];
const html = String.raw`<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Gallery PDF conversion fixture</title></head>
<body><div id="results"></div><div id="photo-sequence" data-selected-image="23" data-count="48">Unrelated photo sequence</div>
<script>
window.addEventListener('error', event => {
    const output = document.getElementById('results');
    if (output && !output.textContent.startsWith('BROWSER ')) output.textContent = 'BROWSER FAIL ' + (event.error?.stack || event.message || 'Browser script failed before fixture startup');
});
window.addEventListener('unhandledrejection', event => {
    const output = document.getElementById('results');
    if (output && !output.textContent.startsWith('BROWSER ')) output.textContent = 'BROWSER FAIL ' + (event.reason?.stack || String(event.reason || 'Unhandled browser promise rejection'));
});
</script>
<script src="/public/assets/vendor/pdfjs/pdf.min.js"></script>
<script type="module">
import {createPdfPngUploadStream} from '/public/assets/gallery-modules/admin-pdf-conversion.js?v=20261012-pdf-dpi-warning-v3';
const results = document.getElementById('results');
try {
const photoSequence = document.getElementById('photo-sequence');
const activity = window.pdfActivity = {sources: [], workerUrls: [], pageLoads: 0, maxPageLoads: 0, renders: 0, activeRenders: 0, maxRenders: 0, destroyedTasks: 0};
const NativeWorker = window.Worker;
window.Worker = function(source, options) {
    activity.workerUrls.push(new URL(String(source), location.href).href);
    return new NativeWorker(source, options);
};
window.Worker.prototype = NativeWorker.prototype;
const nativePdfjsLib = window.pdfjsLib;
if (!nativePdfjsLib || typeof nativePdfjsLib.getDocument !== 'function') throw new Error('The self-hosted PDF.js runtime did not expose getDocument');
const nativeGetDocument = nativePdfjsLib.getDocument.bind(nativePdfjsLib);
const observedGetDocument = options => {
    const source = new URL(String(options.url), location.href);
    if (source.origin !== location.origin || source.pathname !== '/protected/pdf'
        || options.withCredentials !== true || options.isEvalSupported !== false || options.stopAtErrors !== true) {
        throw new Error('PDF.js did not receive the protected same-origin source and safe runtime settings');
    }
    activity.sources.push(source.href);
    const task = nativeGetDocument(options);
    const documentPromise = task.promise.then(documentProxy => new Proxy(documentProxy, {
        get(target, property) {
            if (property === 'getPage') return async pageNumber => {
                activity.pageLoads++;
                activity.maxPageLoads = Math.max(activity.maxPageLoads, activity.pageLoads);
                try {
                    const page = await target.getPage(pageNumber);
                    return new Proxy(page, {
                        get(pageTarget, pageProperty) {
                            if (pageProperty === 'render') return renderOptions => {
                                activity.renders++;
                                activity.activeRenders++;
                                activity.maxRenders = Math.max(activity.maxRenders, activity.activeRenders);
                                const renderTask = pageTarget.render(renderOptions);
                                let finished = false;
                                const settle = () => {
                                    if (finished) return;
                                    finished = true;
                                    activity.activeRenders--;
                                };
                                const promise = Promise.resolve(renderTask.promise).finally(settle);
                                return {promise, cancel() { try { return renderTask.cancel(); } finally { settle(); } }};
                            };
                            const value = Reflect.get(pageTarget, pageProperty, pageTarget);
                            return typeof value === 'function' ? value.bind(pageTarget) : value;
                        },
                    });
                } finally {
                    activity.pageLoads--;
                }
            };
            const value = Reflect.get(target, property, target);
            return typeof value === 'function' ? value.bind(target) : value;
        },
    }));
    return new Proxy(task, {
        get(target, property) {
            if (property === 'promise') return documentPromise;
            const value = Reflect.get(target, property, target);
            if (property === 'destroy' && typeof value === 'function') return async (...args) => {
                activity.destroyedTasks++;
                return value.apply(target, args);
            };
            return typeof value === 'function' ? value.bind(target) : value;
        },
    });
};
// PDF.js exposes a read-only module namespace in some builds; proxy the window
// reference instead of assigning to a possibly getter-only getDocument export.
window.pdfjsLib = new Proxy(nativePdfjsLib, {
    get(target, property) {
        if (property === 'getDocument') return observedGetDocument;
        return Reflect.get(target, property, target);
    },
});
const check = (value, message) => { if (!value) throw new Error(message); };
const makeUrl = pageCount => '/protected/pdf?count=' + pageCount + '&token=${fixtureToken}';
const pixel = (context, x, y) => [...context.getImageData(x, y, 1, 1).data];
const assertPngPixels = async (file, dpi, landscape) => {
    const bytes = new Uint8Array(await file.arrayBuffer());
    check(file.type === 'image/png', 'Generated page MIME type must remain image/png');
    check(bytes.length > 16 && [137, 80, 78, 71, 13, 10, 26, 10].every((value, index) => bytes[index] === value), 'Generated page must contain a genuine PNG signature');
    const bitmap = await createImageBitmap(file);
    try {
        const expectedWidth = landscape ? dpi * 2 : dpi;
        const expectedHeight = landscape ? dpi : dpi * 2;
        check(bitmap.width === expectedWidth && bitmap.height === expectedHeight,
            'PNG orientation/DPI mismatch: got ' + bitmap.width + ' × ' + bitmap.height + ', expected ' + expectedWidth + ' × ' + expectedHeight);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width; canvas.height = bitmap.height;
        const context = canvas.getContext('2d', {willReadFrequently: true});
        context.drawImage(bitmap, 0, 0);
        const red = pixel(context, Math.floor(dpi / 12), bitmap.height - Math.floor(dpi / 12));
        const blue = pixel(context, bitmap.width - Math.floor(dpi / 12), Math.floor(dpi / 12));
        const white = pixel(context, Math.floor(bitmap.width / 2), Math.floor(bitmap.height / 2));
        check(red[0] > 180 && red[1] < 80 && red[2] < 80 && red[3] === 255, 'PDF red corner pixel was not preserved');
        check(blue[2] > 180 && blue[0] < 80 && blue[1] < 100 && blue[3] === 255, 'PDF blue corner pixel was not preserved');
        check(white.every((channel, index) => channel === [255, 255, 255, 255][index]), 'Transparent PDF background must become opaque white PNG pixels');
        canvas.width = 0; canvas.height = 0;
    } finally {
        bitmap.close();
    }
};
    const counts = [1, 40, 41, 120, 151];
    for (const pageCount of counts) {
        const renderedBefore = activity.renders;
        const progress = [];
        const stream = await createPdfPngUploadStream(makeUrl(pageCount), {dpi: 150, sourceId: 'fixture-' + pageCount, onProgress: item => progress.push(item)});
        check(stream.pageCount === pageCount, 'PDF.js page count mismatch for ' + pageCount + ' pages');
        check(stream.sourceId === 'fixture-' + pageCount && stream.dpi === 150, 'Stream metadata must keep caller source identity and DPI');
        const digits = Math.max(4, String(pageCount).length);
        // Fully drain the 40-page boundary to verify ordering and pull backpressure.
        // Other long documents render their last page directly: PDF.js still opens the
        // real full-count source and proves high-page resume without spending CI time
        // rasterizing hundreds of redundant pages across every boundary document.
        const startPage = pageCount > 40 ? pageCount : 1;
        stream.configure({baseName: 'fixture-' + pageCount + '-page-', startPage, pageDigits: digits});
        const iterator = stream[Symbol.asyncIterator]();
        let page = startPage;
        let renderedPages = 0;
        let next = await iterator.next();
        check(!next.done, 'First page must render for ' + pageCount + '-page source');
        while (!next.done) {
            check(next.value.name === 'fixture-' + pageCount + '-page-' + String(page).padStart(digits, '0') + '.png', 'Page filenames must remain ordered and zero-padded');
            renderedPages++;
            check(activity.renders === renderedBefore + renderedPages, 'Each iterator pull must render exactly its current page');
            if (page === 1 || page === pageCount || (pageCount > 1 && page === 2)) {
                await assertPngPixels(next.value, 150, page === 2 && pageCount > 1);
            }
            if (page === 1 && pageCount === 40) {
                await new Promise(resolve => setTimeout(resolve, 40));
                check(activity.renders === renderedBefore + 1, 'The producer must pause while the consumer holds a yielded page');
            }
            page++;
            next = await iterator.next();
        }
        check(page === (startPage === 1 ? pageCount + 1 : startPage + 1), 'The iterator must yield each selected source page exactly once');
        check(progress[0]?.phase === 'ready' && progress.filter(item => item.phase === 'rendered').length === renderedPages,
            'Progress must report known total pages and each completed render');
        await stream.dispose();
    }

    const dpiValues = [96, 150, 200, 300, 600, 1200, 2400];
    for (const dpi of dpiValues) {
        const stream = await createPdfPngUploadStream(makeUrl(1), {dpi});
        stream.configure({baseName: 'dpi-' + dpi + '-page-'});
        const iterator = stream[Symbol.asyncIterator]();
        const page = await iterator.next();
        check(!page.done, 'A one-page DPI fixture must render');
        await assertPngPixels(page.value, dpi, false);
        check((await iterator.next()).done, 'The DPI fixture must contain exactly one output page');
        await stream.dispose();
    }

    const cancelController = new AbortController();
    const cancelStream = await createPdfPngUploadStream(makeUrl(151), {dpi: 300, signal: cancelController.signal});
    const cancelIterator = cancelStream[Symbol.asyncIterator]();
    const renderedBeforeCancel = activity.renders;
    const pendingPage = cancelIterator.next();
    check(activity.pageLoads === 1, 'Cancellation fixture must have one active page acquisition');
    cancelController.abort();
    let cancellationMessage = '';
    try {
        await pendingPage;
    } catch (error) {
        cancellationMessage = String(error?.message || error);
    }
    check(/cancelled/i.test(cancellationMessage), 'Cancellation during page acquisition must reject truthfully');
    await cancelStream.dispose();
    check(activity.renders === renderedBeforeCancel, 'Cancellation during page acquisition must stop before rendering');
    check(activity.activeRenders === 0 && activity.pageLoads === 0, 'Cancellation must leave no active render or page acquisition');
    check(activity.maxPageLoads === 1 && activity.maxRenders === 1, 'The producer must never render or fetch multiple pages concurrently');
    check(activity.destroyedTasks === activity.sources.length, 'Every successful or cancelled source must destroy its PDF.js loading task');
    check(activity.workerUrls.length > 0 && activity.workerUrls.every(value => new URL(value).origin === location.origin && new URL(value).pathname.endsWith('/public/assets/vendor/pdfjs/pdf.worker.min.js')),
        'PDF.js must use the real same-origin local worker');
    check(activity.sources.length === counts.length + dpiValues.length + 1, 'Only test-selected PDF conversion streams may open PDF documents');
    check(activity.sources.every(value => new URL(value).origin === location.origin && new URL(value).searchParams.get('token') === '${fixtureToken}'),
        'Every PDF.js request must remain same-origin and carry the protected fixture token');
    check(photoSequence.dataset.selectedImage === '23' && photoSequence.dataset.count === '48', 'Conversion must not mutate the unrelated photo sequence');
    const externalResources = performance.getEntriesByType('resource').map(entry => entry.name).filter(value => new URL(value).origin !== location.origin);
    check(externalResources.length === 0, 'The fixture must not request cross-origin resources');
    results.textContent = 'BROWSER PASS PDF.js real PNG conversion, DPI, orientation, ordering, pull backpressure, cancellation and same-origin protection';
} catch (error) {
    results.textContent = 'BROWSER FAIL ' + (error?.stack || String(error));
}
</script></body></html>`;

const server = createServer(async (request, response) => {
    try {
        const url = new URL(request.url || '/', 'http://127.0.0.1');
        if (url.pathname === '/') {
            response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
            response.end(html);
            return;
        }
        if (url.pathname === '/protected/pdf') {
            const pageCount = Number(url.searchParams.get('count'));
            const token = String(url.searchParams.get('token') || '');
            protectedPdfRequests.push({pageCount, token, method: request.method || 'GET'});
            const pdf = token === fixtureToken ? pdfDocuments.get(pageCount) : null;
            if (!pdf) {
                response.writeHead(404, {'Cache-Control': 'no-store'}).end();
                return;
            }
            response.writeHead(200, {'Content-Type': 'application/pdf', 'Content-Length': String(pdf.length), 'Cache-Control': 'no-store'});
            response.end(pdf);
            return;
        }
        if (!url.pathname.startsWith('/public/assets/')) {
            response.writeHead(404).end();
            return;
        }
        const relative = url.pathname.slice(1);
        const file = path.resolve(root, relative);
        if (!file.startsWith(root + path.sep)) {
            response.writeHead(404).end();
            return;
        }
        const body = await readFile(file);
        const extension = path.extname(file).toLowerCase();
        const contentType = extension === '.css' ? 'text/css; charset=utf-8'
            : extension === '.js' || extension === '.mjs' ? 'text/javascript; charset=utf-8' : 'application/octet-stream';
        response.writeHead(200, {'Content-Type': contentType, 'Cache-Control': 'no-store'});
        response.end(body);
    } catch {
        response.writeHead(404).end();
    }
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    const url = 'http://127.0.0.1:' + server.address().port + '/';
    const {result, exitCode, timeoutDetail} = await runHeadlessBrowserFixture(executable, url, 'gallery-pdf-conversion-browser-', {
        timeoutMs: 150000,
        timeoutExpression: 'JSON.stringify({pdfActivity:window.pdfActivity, result:document.getElementById("results")?.textContent})',
    });
    console.log(result || (timeoutDetail ? 'Browser timeout diagnostic: ' + timeoutDetail : 'Browser exited without a PDF conversion fixture result'));
    assert.equal(exitCode, 0, 'Chromium fixture process failed');
    assert.ok(result?.startsWith('BROWSER PASS'), 'Real PDF.js conversion must complete its owned browser fixture');
    assert.ok(protectedPdfRequests.length >= 10, 'The protected PDF route must serve every exercised conversion source');
    assert.ok(protectedPdfRequests.every(entry => entry.token === fixtureToken && supportedPageCounts.has(entry.pageCount)),
        'The fixture server must refuse nonfixture PDF identities');
} finally {
    server.close();
}
