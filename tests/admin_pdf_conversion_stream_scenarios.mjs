/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_pdf_conversion_stream_scenarios.mjs
 * Module Type: Regression Test
 * Purpose: Protect ordered, bounded PDF.js page-to-PNG streaming behavior.
 * Responsibilities: Exercise supported DPI, page ordering, same-origin checks, canvas limits, cancellation and cleanup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';

/**
 * Describe configurable dimensions and count for one mocked PDF document.
 * @typedef {Object} PdfMockSettings
 * @property {number} [pageCount] Number of fake pages.
 * @property {(page: number, scale: number) => number} [pageWidth] Custom page width at the requested scale.
 * @property {(page: number, scale: number) => number} [pageHeight] Custom page height at the requested scale.
 * @property {number} [canvasWidthLimit] Simulated browser canvas-width capacity in pixels; wider assignments are truncated.
 */

/**
 * Describe settings passed into the converter in one fixture scenario.
 * @typedef {Object} PdfMockConversionOptions
 * @property {number} [dpi] Requested test output resolution.
 * @property {string} [baseName] Requested filename stem.
 * @property {string} [sourceId] Caller-provided test source identifier.
 * @property {(progress: {phase: string, page: number, pages: number, dpi: number}) => void} [onProgress] Collect conversion progress events.
 */

/**
 * Describe page naming and retry settings in this converter fixture.
 * @typedef {Object} PdfMockStreamSettings
 * @property {string} [baseName] Requested filename stem.
 * @property {number} [startPage] One-based starting source page.
 * @property {number} [pageDigits] Output page-number padding width.
 */

/**
 * Describe the converter stream API returned to the fixture.
 * @typedef {Object} PdfMockStream
 * @property {number} pageCount Total page count discovered by the converter.
 * @property {number} dpi Output resolution in dots per inch.
 * @property {string} sourceId Caller-provided source identity.
 * @property {(settings?: PdfMockStreamSettings) => void} configure Set output prefix or resume position.
 * @property {() => AsyncGenerator<File, void, void>} [Symbol.asyncIterator] Create sequential output iterator.
 * @property {() => Promise<void>} cancel Cancel conversion and release resources.
 * @property {() => Promise<void>} dispose Release conversion resources.
 */

/**
 * Describe state recorded by mock PDF.js and canvas APIs.
 * @typedef {Object} PdfMockState
 * @property {number[]} renderedPages Pages passed to render.
 * @property {number[]} cleanedPages Pages whose local resources were cleaned.
 * @property {number} destroyCount Number of loading tasks destroyed.
 * @property {number} documentCleanupCount Number of opened documents cleaned.
 * @property {Array<[number, number]>} canvasSizes Recorded canvas allocations and releases.
 * @property {Array<{color: string, dimensions?: number[], pageNumber: number}>} fills Full-canvas backgrounds applied before rendering.
 * @property {boolean[]} alphaModes Alpha options used for page canvases.
 * @property {number[]} scales Requested PDF.js viewport scales.
 * @property {unknown} [documentOptions] Options used to open the source document.
 */

/**
 * Describe the test-only PDF converter facade and its observations.
 * @typedef {Object} PdfMockHarness
 * @property {(source: string|URL, options?: PdfMockConversionOptions) => Promise<PdfMockStream>} createStream Open the converter under test.
 * @property {PdfMockState} state Mock page and resource observations.
 */

/**
 * Load the browser module in a deterministic VM with a controllable PDF.js facade.
 * @param {PdfMockSettings} settings Mock document dimensions and length.
 * @returns {Promise<PdfMockHarness>} Test API and observed renderer state.
 */
async function loadConverter(settings = {}) {
    const state = {renderedPages: [], cleanedPages: [], destroyCount: 0, documentCleanupCount: 0, canvasSizes: [], fills: [], alphaModes: [], scales: []};
    const pageCount = Number(settings.pageCount || 1);
    const documentProxy = {
        numPages: pageCount,
        getPage: async (pageNumber) => {
            return {
                getViewport: ({scale}) => {
                    state.scales.push(scale);
                    return {
                        width: settings.pageWidth ? settings.pageWidth(pageNumber, scale) : 100 * scale,
                        height: settings.pageHeight ? settings.pageHeight(pageNumber, scale) : 200 * scale,
                    };
                },
                render: ({canvasContext}) => {
                    state.renderedPages.push(pageNumber);
                    state.fills.push({color: canvasContext.fillStyle, dimensions: canvasContext.fillRectCall, pageNumber});
                    return {promise: Promise.resolve(), cancel: () => {}};
                },
                cleanup: () => { state.cleanedPages.push(pageNumber); },
            };
        },
        cleanup: () => { state.documentCleanupCount++; },
    };
    const pdfjs = {
        GlobalWorkerOptions: {},
        getDocument: (options) => {
            state.documentOptions = options;
            return {promise: Promise.resolve(documentProxy), destroy: async () => { state.destroyCount++; }};
        },
    };
    const createCanvas = () => {
        let assignedWidth = 0;
        return {
            /** Read the simulated browser canvas width. @returns {number} Allocated width in pixels. */
            get width() { return assignedWidth; },
            /** Simulate browser capacity when assigning a width. @param {number} value Requested width in pixels. @returns {void} Store the allocated, optionally truncated width. */
            set width(value) { assignedWidth = settings.canvasWidthLimit ? Math.min(value, settings.canvasWidthLimit) : value; },
            height: 0,
            getContext: (_kind, options) => {
                state.alphaModes.push(options.alpha);
                const context = {fillStyle: '', fillRect: (...rectangle) => { context.fillRectCall = rectangle; }};
                return context;
            },
            toBlob: (callback, type) => callback(new Blob(['png-bytes'], {type})),
        };
    };
    const context = vm.createContext({
        AbortSignal,
        Blob,
        File,
        URL,
        document: {createElement: (name) => name === 'canvas' ? createCanvas() : {}},
        window: {location: {href: 'https://gallery.example/admin.php', origin: 'https://gallery.example'}, pdfjsLib: pdfjs},
        setTimeout,
        i18n: (key, fallback, parameters = {}) => Object.entries(parameters).reduce((message, [name, value]) => message.replaceAll(`{${name}}`, String(value)), fallback),
        console,
    });
    const modulePath = new URL('../public/assets/gallery-modules/admin-pdf-conversion.js', import.meta.url);
    const source = (await readFile(modulePath, 'utf8'))
        .replace(/^import .*;\s*$/gm, '')
        .replace(/^export async function /gm, 'async function ')
        .replaceAll('import.meta.url', "'https://gallery.example/assets/gallery-modules/admin-pdf-conversion.js'");
    vm.runInContext(`${source}\nglobalThis.__createPdfStream = createPdfPngUploadStream;`, context);
    return {createStream: context.__createPdfStream, state};
}

const longDocument = await loadConverter({pageCount: 257});
const progress = [];
const stream = await longDocument.createStream('/admin.php?page=media&id=17&pdf=1', {
    dpi: 200,
    sourceId: '17',
    baseName: 'manual-17-page-',
    onProgress: (item) => progress.push(item),
});
assert.equal(stream.pageCount, 257);
assert.equal(stream.dpi, 200);
assert.equal(longDocument.state.documentOptions.withCredentials, true);
assert.equal(longDocument.state.documentOptions.isEvalSupported, false);
assert.deepEqual(longDocument.state.scales, [], 'Creating a pull-driven stream does not render a page before the consumer requests it.');
const pageNames = [];
const longIterator = stream[Symbol.asyncIterator]();
const firstPage = await longIterator.next();
assert.equal(firstPage.done, false);
assert.equal(longDocument.state.scales[0], 200 / 72, 'The first requested page uses the selected DPI scale.');
pageNames.push(firstPage.value.name);
while (true) {
    const next = await longIterator.next();
    if (next.done) break;
    pageNames.push(next.value.name);
}
assert.equal(pageNames.length, 257);
assert.equal(pageNames[0], 'manual-17-page-0001.png');
assert.equal(pageNames.at(-1), 'manual-17-page-0257.png');
assert.deepEqual(longDocument.state.renderedPages, Array.from({length: 257}, (_, index) => index + 1));
assert.equal(longDocument.state.cleanedPages.length, 257);
assert.equal(longDocument.state.documentCleanupCount, 1);
assert.equal(longDocument.state.destroyCount, 1);
assert.equal(progress[0].phase, 'ready');
assert.equal(progress.filter((item) => item.phase === 'rendered').length, 257);
assert.ok(longDocument.state.fills.every((pixel) => pixel.color === '#ffffff' && pixel.dimensions?.length === 4), 'Every page starts with a full-canvas opaque white background');
assert.ok(longDocument.state.alphaModes.every((alpha) => alpha === false), 'Rendered page canvases do not retain transparent backgrounds');

for (const pageCount of [1, 40, 41, 120, 151]) {
    const pageRun = await loadConverter({pageCount});
    const pageStream = await pageRun.createStream('/pdf/boundary.pdf');
    let count = 0;
    const pageIterator = pageStream[Symbol.asyncIterator]();
    while (true) {
        const next = await pageIterator.next();
        if (next.done) break;
        count++;
        assert.equal(next.value.type, 'image/png');
        assert.ok(next.value.size > 0);
    }
    assert.equal(count, pageCount);
    assert.equal(pageRun.state.renderedPages.at(-1), pageCount);
}

for (const dpi of [96, 150, 200, 300, 600, 1200, 2400]) {
    const dpiRun = await loadConverter({pageCount: 1});
    const dpiStream = await dpiRun.createStream('/pdf/one.pdf', {dpi});
    const iterator = dpiStream[Symbol.asyncIterator]();
    await iterator.next();
    assert.equal(dpiRun.state.scales[0], dpi / 72);
    await iterator.next();
}

const resumeRun = await loadConverter({pageCount: 12003});
const resumeStream = await resumeRun.createStream('/pdf/source.pdf');
resumeStream.configure({baseName: 'pdf-abc-page-', startPage: 12000, pageDigits: 5});
const resumedPages = [];
const resumeIterator = resumeStream[Symbol.asyncIterator]();
while (true) {
    const next = await resumeIterator.next();
    if (next.done) break;
    resumedPages.push(next.value.name);
}
assert.deepEqual(resumedPages, ['pdf-abc-page-12000.png', 'pdf-abc-page-12001.png', 'pdf-abc-page-12002.png', 'pdf-abc-page-12003.png']);
assert.deepEqual(resumeRun.state.renderedPages, [12000, 12001, 12002, 12003]);

const cancelRun = await loadConverter({pageCount: 2});
const cancelStream = await cancelRun.createStream('/pdf/cancel.pdf');
const cancelIterator = cancelStream[Symbol.asyncIterator]();
assert.equal((await cancelIterator.next()).value.name, 'cancel-page-0001.png');
await cancelStream.cancel();
await assert.rejects(() => cancelIterator.next(), /cancelled/i);
assert.equal(cancelRun.state.documentCleanupCount, 1);

const oversizedRun = await loadConverter({pageCount: 1, pageWidth: () => 17000});
const edgeWarnings = [];
const oversizedStream = await oversizedRun.createStream('/pdf/poster.pdf', {dpi: 300, onProgress: state => edgeWarnings.push(state)});
const edgeIterator = oversizedStream[Symbol.asyncIterator]();
assert.equal((await edgeIterator.next()).done, false, 'The advisory edge threshold must not prevent rendering.');
assert.ok(edgeWarnings.some(state => state.phase === 'warning' && state.width === 17000));
assert.equal((await edgeIterator.next()).done, true);
const oversizedPixels = await loadConverter({pageCount: 1, pageWidth: () => 9000, pageHeight: () => 4000});
const pixelWarnings = [];
const pixelLimitedStream = await oversizedPixels.createStream('/pdf/wide-page.pdf', {dpi: 96, onProgress: state => pixelWarnings.push(state)});
const pixelLimitedIterator = pixelLimitedStream[Symbol.asyncIterator]();
assert.equal((await pixelLimitedIterator.next()).done, false, 'The advisory pixel threshold must not prevent rendering.');
assert.ok(pixelWarnings.some(state => state.phase === 'warning' && state.memoryMiB === 138));
assert.equal((await pixelLimitedIterator.next()).done, true);
assert.equal(oversizedPixels.state.documentCleanupCount, 1, 'A warned page still releases the opened PDF document.');

const a4Warnings = [];
const a4Run = await loadConverter({pageCount: 1, pageWidth: (_page, scale) => 595.28 * scale, pageHeight: (_page, scale) => 841.89 * scale});
const a4Stream = await a4Run.createStream('/pdf/a4.pdf', {dpi: 2400, onProgress: state => a4Warnings.push(state)});
const a4Iterator = a4Stream[Symbol.asyncIterator]();
assert.equal((await a4Iterator.next()).done, false, 'A4 at 2400 DPI must reach the renderer instead of an advisory barrier.');
assert.ok(a4Warnings.some(state => state.phase === 'warning' && state.memoryMiB > 2000));
assert.equal((await a4Iterator.next()).done, true);
const capacityRun = await loadConverter({pageCount: 1, canvasWidthLimit: 50});
const capacityStream = await capacityRun.createStream('/pdf/browser-capacity.pdf');
await assert.rejects(() => capacityStream[Symbol.asyncIterator]().next(), /could not allocate the requested/i);
assert.equal(capacityRun.state.documentCleanupCount, 1, 'A real browser-capacity failure releases PDF resources.');

const invalidDpi = await loadConverter();
await assert.rejects(() => invalidDpi.createStream('/pdf/invalid.pdf', {dpi: 120}), /supported PDF output resolution/i);
const originCheck = await loadConverter();
await assert.rejects(() => originCheck.createStream('https://attacker.example/secret.pdf'), /same-origin/i);
const lockedSettings = await loadConverter();
const lockedStream = await lockedSettings.createStream('/pdf/settings.pdf');
const lockedIterator = lockedStream[Symbol.asyncIterator]();
assert.throws(() => lockedStream.configure({startPage: 2}), /must be applied before rendering starts/i);
await lockedIterator.next();
await lockedStream.dispose();

const uploaderSource = await readFile(new URL('../public/assets/gallery-modules/admin-browser-upload.js', import.meta.url), 'utf8');
assert.match(uploaderSource, /export async function uploadBrowserFileStream\(/, 'PDF conversion reuses the ordinary browser uploader entry point');
assert.match(uploaderSource, /const iterator = fileStream\[Symbol\.asyncIterator\]\(\)[\s\S]*?const next = await iterator\.next\(\)/, 'The uploader pulls generated pages serially');
assert.match(uploaderSource, /if \(batcher\.shouldFlushBefore\(item\)\) await batcher\.flush\(\)/, 'A full pending batch is uploaded before the next page is staged');
assert.match(uploaderSource, /onBatchReady: async \(batch\) =>[\s\S]*?await acknowledgePdfUploadBatch/, 'Ready ZIP batches wait for durable server acknowledgment');
assert.match(uploaderSource, /prepared\.originalDetectedFormat !== 'png'[\s\S]*?filesHaveSameBytes\(prepared\.originalFile, file\)/, 'Worker preparation keeps the original PDF page PNG byte-for-byte');
assert.match(uploaderSource, /body\.set\('pdf_conversion_token'/, 'Conversion-scoped credentials go through the existing upload request');
// Ordinary chooser uploads intentionally collect their existing FileList. Apply
// the PDF bounded-memory guard only to the stream entry point and its helpers.
const streamUploaderSource = uploaderSource.slice(uploaderSource.indexOf('export async function uploadBrowserFileStream('), uploaderSource.indexOf('export async function runBrowserGalleryUpload('));
assert.match(streamUploaderSource, /async function consumePdfPageStream\(/, 'The bounded-memory source scope must include the PDF consumer');
assert.doesNotMatch(streamUploaderSource, /const (?:files|allPages|documentFiles)\s*=\s*\[\]/, 'The stream path does not collect a document-wide file array');

console.log('PDF conversion stream tests passed.');
