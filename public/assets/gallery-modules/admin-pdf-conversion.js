/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-pdf-conversion.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Render protected PDF documents into an ordered, pull-driven sequence of PNG files.
 *
 * Responsibilities:
 *   - Load the locally vendored PDF.js runtime and same-origin worker
 *   - Render one page at a time with an opaque white background
 *   - Warn about large canvases, validate actual allocation and dispose PDF.js and canvas resources
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

import {i18n} from './admin-core.js?v=20260614-upload-original-diagnostics-v1';

// Purpose: Restrict output resolution to supported print and screen settings.
// Type: readonly number array.
// Units: dots per inch.
// Scope: PDF page rendering in this browser module.
// Consumers: normalizePdfDpi and the returned stream metadata.
// Rationale: match the screen/print and 600–2400 DPI choices exposed by the controls;
// large-page warnings inform the user while actual browser allocation decides feasibility.
const supportedDpi = Object.freeze([96, 150, 200, 300, 600, 1200, 2400]);

// Purpose: Warn about unusually large canvas edges before requesting allocation.
// Type: integer.
// Units: pixels per edge.
// Scope: each converted PDF page canvas.
// Consumers: renderPdfPageFiles.
// Rationale: the historical 16384-pixel guard is advisory because supported browser capacities vary.
const canvasWarningEdge = 16384;

// Purpose: Warn about the memory cost of a large rendered PDF page.
// Type: integer.
// Units: pixels.
// Scope: each converted PDF page canvas.
// Consumers: renderPdfPageFiles.
// Rationale: 32 million pixels is an advisory threshold, allowing high-DPI A4 pages to be attempted.
const canvasWarningPixels = 32000000;

// Purpose: Schedule the large-page warning before allocating its canvas backing store.
// Type: integer.
// Units: milliseconds.
// Scope: both animation-frame and fallback PDF warning paint paths.
// Consumers: renderPdfPageFiles.
// Rationale: zero queues the next task without adding a deliberate rendering delay.
const warningPaintDelayMs = 0;

let pdfjsPromise = null;

/**
 * Describe progress for an opened document and completed page render.
 * @typedef {Object} PdfConversionProgress
 * @property {string} phase Current conversion phase: ready, warning or rendered.
 * @property {number} page One-based rendered page, or zero while opening.
 * @property {number} pages Total pages in the source document.
 * @property {number} dpi Output resolution in dots per inch.
 * @property {number} [width] Requested canvas width in pixels for a warning.
 * @property {number} [height] Requested canvas height in pixels for a warning.
 * @property {number} [memoryMiB] Estimated RGBA canvas allocation in MiB, excluding renderer and PNG buffers.
 */

/** @typedef {(progress: PdfConversionProgress) => void} PdfConversionProgressHandler */

/**
 * Describe optional conversion inputs accepted when opening a PDF stream.
 * @typedef {Object} PdfConversionOptions
 * @property {number} [dpi] Requested output dots per inch; defaults to 150.
 * @property {string} [baseName] Optional path-free output filename stem.
 * @property {AbortSignal|null} [signal] Optional cancellation signal.
 * @property {PdfConversionProgressHandler} [onProgress] Observe document and page progress.
 * @property {string} [sourceId] Caller-provided source document identity for progress ownership.
 */

/**
 * Describe naming and resume settings applied before requesting the first page.
 * @typedef {Object} PdfConversionSettings
 * @property {string} [baseName] Safe output prefix, optionally ending in the page marker.
 * @property {number} [startPage] One-based first page to render, including pageCount plus one.
 * @property {number} [pageDigits] Minimum zero-padding width selected by the lifecycle owner.
 */

/**
 * Describe the configured pull-driven output stream and its resource controls.
 * @typedef {Object} PdfConversionStream
 * @property {number} pageCount Total pages discovered from PDF.js.
 * @property {number} dpi Output resolution in dots per inch.
 * @property {string} sourceId Caller-provided source identity, or an empty string.
 * @property {(settings?: PdfConversionSettings) => void} configure Apply filename and resume settings before iteration starts.
 * @property {() => AsyncGenerator<File, void, void>} [Symbol.asyncIterator] Create the sequential page output iterator.
 * @property {() => Promise<void>} cancel Cancel current rendering and release PDF.js resources.
 * @property {() => Promise<void>} dispose Release PDF.js resources without emitting more pages.
 */

/**
 * Describe validated positive integer dimensions requested for one PDF page canvas.
 * @typedef {Object} PdfPageDimensions
 * @property {number} width Safe integer canvas width in pixels.
 * @property {number} height Safe integer canvas height in pixels.
 */

/**
 * Describe page naming and reporting settings retained by one page iterator.
 * @typedef {Object} PdfPageFileOptions
 * @property {string} baseName Sanitized filename prefix.
 * @property {number} startPage First one-based source page to render.
 * @property {number} pageDigits Width of the zero-padded page number.
 * @property {number} dpi Render resolution in dots per inch.
 * @property {PdfConversionProgressHandler|undefined} onProgress Optional conversion progress callback.
 */

/**
 * Describe the PDF.js page methods consumed by the sequential renderer.
 * @typedef {Object} PdfjsPageProxy
 * @property {(parameters: {scale: number}) => {width: number, height: number}} getViewport Return a page viewport at the requested scale.
 * @property {(parameters: {canvasContext: CanvasRenderingContext2D, viewport: {width: number, height: number}, background: string}) => {promise: Promise<void>, cancel: () => void}} render Draw this page and expose its cancellable render task.
 * @property {(() => void)|undefined} cleanup Release page-local resources when available.
 */

/**
 * Describe the PDF.js document methods and metadata used by the converter.
 * @typedef {Object} PdfjsDocumentProxy
 * @property {number} numPages Total one-based page count.
 * @property {(pageNumber: number) => Promise<PdfjsPageProxy>} getPage Load one page proxy.
 * @property {(() => void)|undefined} cleanup Release document-local resources when available.
 * @property {(() => Promise<void>)|undefined} destroy Destroy the worker-backed document when available.
 */

/**
 * Describe the PDF.js task that opens and destroys a document.
 * @typedef {Object} PdfjsLoadingTask
 * @property {Promise<PdfjsDocumentProxy>} promise Resolves to the opened PDF document.
 * @property {(() => Promise<void>)|undefined} destroy Stop loading and release worker resources when the task is active.
 */

/**
 * Describe the local PDF.js APIs configured by the browser module.
 * @typedef {Object} PdfjsRuntime
 * @property {{workerSrc: string}} GlobalWorkerOptions Matching local worker URL.
 * @property {(options: {url: string, withCredentials: boolean, isEvalSupported: boolean, stopAtErrors: boolean}) => PdfjsLoadingTask} getDocument Open a protected same-origin URL.
 */

/**
 * Describe resources that remain owned by one active PDF stream.
 * @typedef {Object} PdfStreamState
 * @property {PdfjsLoadingTask} loadingTask Document loading task.
 * @property {PdfjsDocumentProxy|null} documentProxy Open document or null before loading completes.
 * @property {AbortSignal|null} signal Optional cancellation owner.
 * @property {{promise: Promise<void>, cancel: () => void}|null} currentRenderTask Current page render task, if any.
 * @property {boolean} disposed Whether cleanup has already run.
 * @property {Promise<void>|null} disposePromise Shared in-flight resource cleanup promise.
 * @property {(() => void)|null} onAbort Signal listener owned by this stream.
 */

/**
 * Create a pull-driven PDF-to-PNG stream for a protected same-origin document URL.
 *
 * The returned object reports its page count before iteration and yields one PNG File
 * per page. Consumers control backpressure by requesting the next item only after the
 * current page has been consumed. Source bytes stay behind the protected same-origin URL.
 *
 * @param {string|URL} source Protected same-origin PDF URL.
 * @param {PdfConversionOptions} options Resolution, output-name, cancellation and progress options.
 * @returns {Promise<PdfConversionStream>} Ordered stream and its cleanup controls.
 */
export async function createPdfPngUploadStream(source, options = {}) {
    const dpi = normalizePdfDpi(options.dpi);
    const signal = options.signal || null;
    const sourceId = String(options.sourceId || '');
    const pdfjs = await loadPdfjs();
    throwIfPdfConversionAborted(signal);
    const loadingTask = pdfjs.getDocument(await pdfSourceOptions(source, signal));
    const streamState = {loadingTask, documentProxy: null, signal, currentRenderTask: null, disposed: false, disposePromise: null, onAbort: null};
    const onAbort = () => {
        try {
            if (streamState.currentRenderTask) streamState.currentRenderTask.cancel();
        } catch (error) {
            // PDF.js may finish or cancel the render task between the signal and this callback.
        }
        void disposePdfStream(streamState).catch(() => {});
    };
    streamState.onAbort = onAbort;
    if (signal) signal.addEventListener('abort', onAbort, {once: true});
    let documentProxy;
    try {
        documentProxy = await loadingTask.promise;
        throwIfPdfConversionAborted(signal);
    } catch (error) {
        await disposePdfStream(streamState);
        throw normalizePdfConversionError(error);
    }
    streamState.documentProxy = documentProxy;
    const pageCount = Number(documentProxy.numPages || 0);
    if (!Number.isSafeInteger(pageCount) || pageCount < 1) {
        await disposePdfStream(streamState);
        throw new Error(i18n('pdf.conversion.empty', 'The PDF contains no renderable pages.'));
    }
    let baseName = normalizePdfOutputBaseName(options.baseName || sourceName(source));
    let startPage = normalizePdfStartPage(options.startPage, pageCount);
    let pageDigits = normalizePdfPageDigits(options.pageDigits, pageCount);
    let started = false;
    reportPdfProgress(options.onProgress, {phase: 'ready', page: 0, pages: pageCount, dpi});

    return {
        pageCount,
        dpi,
        sourceId,
        configure: (settings = {}) => {
            if (started) throw new Error(i18n('pdf.conversion.settings_locked', 'PDF conversion settings must be applied before rendering starts.'));
            if (settings.baseName !== undefined) baseName = normalizePdfOutputBaseName(settings.baseName);
            if (settings.startPage !== undefined) startPage = normalizePdfStartPage(settings.startPage, pageCount);
            if (settings.pageDigits !== undefined) pageDigits = normalizePdfPageDigits(settings.pageDigits, pageCount);
        },
        [Symbol.asyncIterator]: () => {
            started = true;
            return renderPdfPageFiles(streamState, {baseName, startPage, pageDigits, dpi, onProgress: options.onProgress});
        },
        cancel: async () => {
            try {
                if (streamState.currentRenderTask) streamState.currentRenderTask.cancel();
            } catch (error) {
                // Continue to document cleanup when PDF.js has already ended the render task.
            }
            await disposePdfStream(streamState);
        },
        dispose: async () => {
            await disposePdfStream(streamState);
        },
    };
}

/**
 * Load the local PDF.js runtime and configure its matching local worker.
 * @returns {Promise<PdfjsRuntime>} Local PDF.js runtime and worker configuration.
 */
function loadPdfjs() {
    if (window.pdfjsLib) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
        return Promise.resolve(window.pdfjsLib);
    }
    if (pdfjsPromise) return pdfjsPromise;
    pdfjsPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = new URL('../vendor/pdfjs/pdf.min.js', import.meta.url).href;
        script.onload = () => window.pdfjsLib ? resolve(window.pdfjsLib) : reject(new Error(i18n('pdf.conversion.runtime_missing', 'The local PDF.js runtime did not initialize.')));
        script.onerror = () => reject(new Error(i18n('pdf.conversion.runtime_load_failed', 'The local PDF.js runtime could not be loaded.')));
        document.head.appendChild(script);
    }).then((pdfjs) => {
        pdfjs.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
        return pdfjs;
    }).catch((error) => {
        pdfjsPromise = null;
        throw error;
    });
    return pdfjsPromise;
}

/**
 * Build PDF.js source options without exposing a protected URL to conversion metadata.
 * @param {string|URL} source Protected same-origin PDF URL.
 * @param {AbortSignal|null} signal Optional cancellation signal.
 * @returns {Promise<{url: string, withCredentials: boolean, isEvalSupported: boolean, stopAtErrors: boolean}>} Same-origin PDF.js loading options.
 */
async function pdfSourceOptions(source, signal) {
    const url = new URL(String(source), window.location.href);
    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol)) {
        throw new Error(i18n('pdf.conversion.same_origin_required', 'PDF conversion requires a protected same-origin document URL.'));
    }
    if (signal && signal.aborted) throw new Error(i18n('pdf.conversion.cancelled', 'PDF conversion was cancelled.'));
    return {url: url.href, withCredentials: true, isEvalSupported: false, stopAtErrors: true};
}

/**
 * Render PDF pages sequentially and yield each completed PNG before allocating the next page.
 * @param {PdfStreamState} state Active PDF.js resource state.
 * @param {PdfPageFileOptions} options Output naming, resume position and progress options.
 * @returns {AsyncGenerator<File, void, void>} Page-ordered PNG files.
 */
async function* renderPdfPageFiles(state, options) {
    const {documentProxy} = state;
    try {
        for (let pageNumber = options.startPage; pageNumber <= documentProxy.numPages; pageNumber++) {
            throwIfPdfConversionAborted(state.signal, state.disposed);
            let page = null;
            let canvas = null;
            let outputFile = null;
            try {
                page = await documentProxy.getPage(pageNumber);
                throwIfPdfConversionAborted(state.signal, state.disposed);
                const viewport = page.getViewport({scale: options.dpi / 72});
                const dimensions = validatePdfPageDimensions(viewport.width, viewport.height);
                if (dimensions.width > canvasWarningEdge || dimensions.height > canvasWarningEdge
                    || dimensions.width * dimensions.height > canvasWarningPixels) {
                    // Four RGBA bytes per pixel estimate only the canvas backing store.
                    reportPdfProgress(options.onProgress, {phase: 'warning', page: pageNumber, pages: documentProxy.numPages,
                        dpi: options.dpi, width: dimensions.width, height: dimensions.height,
                        memoryMiB: Math.ceil(dimensions.width * dimensions.height * 4 / (1024 * 1024))});
                    // Let the warning paint before attempting the expensive allocation.
                    await new Promise(resolve => {
                        if (typeof window.requestAnimationFrame === 'function') {
                            window.requestAnimationFrame(() => {
                                // Yield one task after the animation frame so the large-page warning can paint.
                                // Type: integer. Units: milliseconds. Scope: PDF conversion warning scheduling.
                                // Consumers: renderPdfPageFiles before canvas allocation.
                                // Rationale: zero requests the next task without adding a deliberate conversion delay.
                                setTimeout(resolve, warningPaintDelayMs);
                            });
                        } else {
                            // Yield one task when animation-frame scheduling is unavailable.
                            // Type: integer. Units: milliseconds. Scope: PDF conversion warning scheduling fallback.
                            // Consumers: renderPdfPageFiles before canvas allocation.
                            // Rationale: zero lets pending warning UI work run without a deliberate conversion delay.
                            setTimeout(resolve, warningPaintDelayMs);
                        }
                    });
                    throwIfPdfConversionAborted(state.signal, state.disposed);
                }
                canvas = document.createElement('canvas');
                canvas.width = dimensions.width;
                canvas.height = dimensions.height;
                if (canvas.width !== dimensions.width || canvas.height !== dimensions.height) {
                    throw new Error(i18n('pdf.conversion.canvas_dimensions_unavailable', 'PDF page {page}: the browser could not allocate the requested {width} × {height} pixel canvas. Choose a lower DPI or try another browser.',
                        {page: pageNumber, width: dimensions.width, height: dimensions.height}));
                }
                const context = canvas.getContext('2d', {alpha: false});
                if (!context) throw new Error(i18n('pdf.conversion.canvas_unavailable', 'PDF page {page} could not allocate a 2D canvas.', {page: pageNumber}));
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                const renderTask = page.render({canvasContext: context, viewport, background: '#ffffff'});
                state.currentRenderTask = renderTask;
                await renderTask.promise;
                throwIfPdfConversionAborted(state.signal, state.disposed);
                const blob = await encodePdfCanvas(canvas, pageNumber);
                throwIfPdfConversionAborted(state.signal, state.disposed);
                const pageSuffix = options.baseName.includes('-page-')
                    ? `${String(pageNumber).padStart(options.pageDigits, '0')}.png`
                    : `-page-${String(pageNumber).padStart(options.pageDigits, '0')}.png`;
                const filename = `${options.baseName}${pageSuffix}`;
                outputFile = new File([blob], filename, {type: 'image/png', lastModified: Date.now()});
            } catch (error) {
                if (state.disposed || state.signal?.aborted) {
                    throw new Error(i18n('pdf.conversion.cancelled', 'PDF conversion was cancelled.'));
                }
                throw normalizePdfConversionError(error, pageNumber);
            } finally {
                state.currentRenderTask = null;
                if (canvas) {
                    canvas.width = 0;
                    canvas.height = 0;
                }
                if (page && typeof page.cleanup === 'function') page.cleanup();
            }
            reportPdfProgress(options.onProgress, {phase: 'rendered', page: pageNumber, pages: documentProxy.numPages, dpi: options.dpi});
            yield outputFile;
        }
    } finally {
        await disposePdfStream(state);
    }
}

/**
 * Validate positive integer viewport dimensions before requesting a canvas.
 * @param {number} width PDF.js viewport width in CSS pixels at the requested DPI.
 * @param {number} height PDF.js viewport height in CSS pixels at the requested DPI.
 * @returns {PdfPageDimensions} Safe integer canvas dimensions.
 */
function validatePdfPageDimensions(width, height) {
    const pixelWidth = Math.ceil(width);
    const pixelHeight = Math.ceil(height);
    if (!Number.isSafeInteger(pixelWidth) || !Number.isSafeInteger(pixelHeight) || pixelWidth < 1 || pixelHeight < 1) {
        throw new Error(i18n('pdf.conversion.invalid_dimensions', 'The PDF page has invalid dimensions and cannot be rendered.'));
    }
    return {width: pixelWidth, height: pixelHeight};
}

/**
 * Encode one rendered canvas as a real PNG Blob.
 * @param {HTMLCanvasElement} canvas Completed white-background page canvas.
 * @param {number} pageNumber One-based PDF page number for error context.
 * @returns {Promise<Blob>} PNG bytes.
 */
function encodePdfCanvas(canvas, pageNumber) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (!blob || blob.type !== 'image/png' || blob.size < 1) {
                reject(new Error(i18n('pdf.conversion.png_encode_failed', 'PDF page {page} could not be encoded as PNG.', {page: pageNumber})));
                return;
            }
            resolve(blob);
        }, 'image/png');
    });
}

/**
 * Normalize and validate one requested PDF rendering resolution.
 * @param {number|undefined} dpi Requested dots per inch.
 * @returns {number} Supported resolution, defaulting to 150 DPI.
 */
function normalizePdfDpi(dpi) {
    const value = dpi === undefined ? 150 : Number(dpi);
    if (!supportedDpi.includes(value)) throw new Error(i18n('pdf.conversion.invalid_dpi', 'Choose a supported PDF output resolution: 96, 150, 200, 300, 600, 1200 or 2400 DPI.'));
    return value;
}

/**
 * Validate a one-based first page for initial conversion or resume.
 * @param {number|undefined} startPage Requested first page.
 * @param {number} pageCount Total pages in the opened document.
 * @returns {number} Valid start page, including pageCount + 1 for an already complete document.
 */
function normalizePdfStartPage(startPage, pageCount) {
    const value = startPage === undefined ? 1 : Number(startPage);
    if (!Number.isSafeInteger(value) || value < 1 || value > pageCount + 1) {
        throw new Error(i18n('pdf.conversion.invalid_start_page', 'PDF resume page must be between 1 and {lastPage}.', {lastPage: pageCount + 1}));
    }
    return value;
}

/**
 * Validate server-selected page-number padding or derive it from the PDF page count.
 * @param {number|undefined} pageDigits Optional lifecycle-selected filename width.
 * @param {number} pageCount Total pages in the opened PDF.
 * @returns {number} Width of the zero-padded one-based page number.
 */
function normalizePdfPageDigits(pageDigits, pageCount) {
    const minimum = Math.max(4, String(pageCount).length);
    const value = pageDigits === undefined ? minimum : Number(pageDigits);
    if (!Number.isSafeInteger(value) || value < minimum || value > String(Number.MAX_SAFE_INTEGER).length) {
        throw new Error(i18n('pdf.conversion.invalid_page_digits', 'The PDF page filename width is invalid.'));
    }
    return value;
}

/**
 * Return a path-free basename suitable for generated page filenames.
 * @param {string} value Source document name or caller-provided basename.
 * @returns {string} Bounded filename stem with no path separators.
 */
function normalizePdfOutputBaseName(value) {
    const candidate = String(value || 'document').split(/[\\/]/).pop().replace(/\.pdf$/i, '');
    const pageMarker = candidate.endsWith('-page-') ? '-page-' : '';
    const stem = pageMarker ? candidate.slice(0, -pageMarker.length) : candidate;
    const safe = stem.normalize('NFKC').replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^[._-]+|[._-]+$/g, '').slice(0, 80);
    return `${safe || 'document'}${pageMarker}`;
}

/**
 * Derive a display name from a PDF URL or local File.
 * @param {string|URL} source PDF URL.
 * @returns {string} Safe basename candidate.
 */
function sourceName(source) {
    if (source instanceof File) return source.name;
    return new URL(String(source), window.location.href).pathname.split('/').pop() || 'document.pdf';
}

/**
 * Report conversion progress to a caller-provided callback.
 * @param {PdfConversionProgressHandler|undefined} callback Optional progress callback.
 * @param {PdfConversionProgress} progress Current conversion progress.
 * @returns {void} No value when no callback is configured.
 */
function reportPdfProgress(callback, progress) {
    if (typeof callback === 'function') callback({...progress});
}

/**
 * Throw a clear cancellation error when the stream's signal has been aborted.
 * @param {AbortSignal|null} signal Optional cancellation signal.
 * @param {boolean} disposed Whether the stream has already released its document.
 * @returns {void} No value while conversion remains active.
 */
function throwIfPdfConversionAborted(signal, disposed = false) {
    if (disposed || (signal && signal.aborted)) throw new Error(i18n('pdf.conversion.cancelled', 'PDF conversion was cancelled.'));
}

/**
 * Normalize PDF.js failures into bounded page-aware messages.
 * @param {unknown} error PDF.js or browser failure.
 * @param {number|null} pageNumber Optional one-based page number.
 * @returns {Error} User-readable conversion failure.
 */
function normalizePdfConversionError(error, pageNumber = null) {
    if (error instanceof Error && /cancel|abort/i.test(error.name + ' ' + error.message)) return new Error(i18n('pdf.conversion.cancelled', 'PDF conversion was cancelled.'));
    const detail = error instanceof Error ? error.message : String(error || 'Unknown PDF rendering failure.');
    return new Error(pageNumber
        ? i18n('pdf.conversion.page_failed', 'PDF page {page} failed to convert: {detail}', {page: pageNumber, detail})
        : i18n('pdf.conversion.open_failed', 'PDF could not be opened: {detail}', {detail}));
}

/**
 * Dispose the active PDF.js document and its worker resources once.
 * @param {PdfStreamState} state Active loading task and document.
 * @returns {Promise<void>} Resolves after PDF.js resources are released.
 */
function disposePdfStream(state) {
    if (state.disposePromise) return state.disposePromise;
    state.disposed = true;
    if (state.signal && state.onAbort) state.signal.removeEventListener('abort', state.onAbort);
    state.disposePromise = disposePdfDocument(state.loadingTask, state.documentProxy);
    return state.disposePromise;
}

/**
 * Destroy an opened PDF document and its associated loading task.
 * @param {PdfjsLoadingTask} loadingTask PDF.js document loading task.
 * @param {PdfjsDocumentProxy|null} documentProxy Open PDF.js document proxy, if loading completed.
 * @returns {Promise<void>} Resolves after cleanup attempts complete.
 */
async function disposePdfDocument(loadingTask, documentProxy) {
    try {
        if (documentProxy && typeof documentProxy.cleanup === 'function') await documentProxy.cleanup();
    } catch (error) {
        // Continue to destroy the loading task even if PDF.js already released document resources.
    } finally {
        await disposePdfLoadingTask(loadingTask);
    }
}

/**
 * Destroy a PDF.js loading task without allowing cleanup errors to mask conversion errors.
 * @param {PdfjsLoadingTask} loadingTask PDF.js document loading task.
 * @returns {Promise<void>} Resolves after the loading task has been destroyed.
 */
async function disposePdfLoadingTask(loadingTask) {
    if (!loadingTask || typeof loadingTask.destroy !== 'function') return;
    try {
        await loadingTask.destroy();
    } catch (error) {
        // PDF.js may already have destroyed the worker after a load/render failure.
    }
}
