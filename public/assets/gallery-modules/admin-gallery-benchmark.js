/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-benchmark.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Runs admin-triggered public gallery benchmark passes in a hidden iframe.
 *
 * Responsibilities:
 *   - Start a server-side benchmark log
 *   - Load the selected gallery repeatedly as an anonymous preview
 *   - Collect browser navigation and resource timing after each iframe load
 *   - Enable the generated JSON log download when the benchmark completes
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-18
 */

/**
 * Return the first matching element inside a benchmark panel.
 *
 * @param {HTMLElement} panel Benchmark root element.
 * @param {string} selector CSS selector.
 * @return {HTMLElement|null} Matching element or null.
 */
function benchmarkPanelElement(panel, selector) {
    return panel.querySelector(selector);
}

/**
 * Return an integer data attribute with a bounded fallback.
 *
 * @param {HTMLElement} element Element containing the data attribute.
 * @param {string} name Dataset key.
 * @param {number} fallback Fallback value.
 * @param {number} min Minimum accepted value.
 * @param {number} max Maximum accepted value.
 * @return {number} Resolved integer.
 */
function boundedDatasetInteger(element, name, fallback, min, max) {
    const raw = Number.parseInt(element.dataset[name] || '', 10);
    if (!Number.isFinite(raw)) {
        return fallback;
    }
    return Math.max(min, Math.min(max, raw));
}

/**
 * Update benchmark progress text and progress bar value.
 *
 * @param {HTMLElement} panel Benchmark root element.
 * @param {string} message Status message.
 * @param {number} percent Progress percent.
 */
function updateBenchmarkStatus(panel, message, percent) {
    const progress = benchmarkPanelElement(panel, '[data-gallery-benchmark-progress]');
    const progressBar = benchmarkPanelElement(panel, '[data-gallery-benchmark-progress-bar]');
    const status = benchmarkPanelElement(panel, '[data-gallery-benchmark-status]');
    if (progress) {
        progress.hidden = false;
    }
    if (progressBar instanceof HTMLProgressElement) {
        progressBar.value = Math.max(0, Math.min(100, percent));
    }
    if (status) {
        status.textContent = message;
    }
}

/**
 * Update benchmark summary text from the server-provided aggregate object.
 *
 * @param {HTMLElement} panel Benchmark root element.
 * @param {Record<string, unknown>} summary Benchmark summary.
 */
function updateBenchmarkSummary(panel, summary) {
    const target = benchmarkPanelElement(panel, '[data-gallery-benchmark-summary]');
    if (!target || !summary || typeof summary !== 'object') {
        return;
    }
    const serverRuns = summary.server_runs_recorded || 0;
    const browserRuns = summary.browser_runs_recorded || 0;
    const serverTotal = summary.server_total_ms && typeof summary.server_total_ms === 'object' ? summary.server_total_ms : {};
    const browserTotal = summary.browser_iframe_elapsed_ms && typeof summary.browser_iframe_elapsed_ms === 'object' ? summary.browser_iframe_elapsed_ms : {};
    const serverLatest = typeof serverTotal.latest === 'number' ? `${serverTotal.latest.toFixed(2)} ms PHP` : 'PHP pending';
    const browserLatest = typeof browserTotal.latest === 'number' ? `${browserTotal.latest.toFixed(2)} ms browser` : 'browser pending';
    target.textContent = `Recorded ${serverRuns} PHP render run(s), ${browserRuns} browser load run(s). Latest: ${serverLatest}, ${browserLatest}.`;
}

/**
 * Build a readable message when a benchmark endpoint does not return JSON.
 *
 * @param {Response} response Fetch response.
 * @param {string} contentType Response content type.
 * @param {string} snippet First response bytes.
 * @return {string} Visible error text.
 */
function buildBenchmarkNonJsonMessage(response, contentType, snippet) {
    const cleanedSnippet = snippet.replace(/\s+/g, ' ').slice(0, 220);
    const detail = cleanedSnippet ? ` First response bytes: ${cleanedSnippet}` : '';
    const redirected = response.redirected ? ' Redirected response.' : '';
    return `Server returned non-JSON benchmark response. HTTP ${response.status}. Content-Type: ${contentType || 'unknown'}.${redirected}${detail}`;
}

/**
 * Send a form-encoded POST request and parse JSON.
 *
 * @param {string} url Endpoint URL.
 * @param {Record<string, string|number>} fields Request fields.
 * @return {Promise<Record<string, unknown>>} Parsed JSON response.
 */
async function postBenchmarkJson(url, fields) {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => {
        body.append(key, String(value));
    });
    body.append('ajax', '1');
    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const text = await response.text();
    const contentType = response.headers.get('content-type') || '';
    const trimmed = text.trimStart();
    const snippet = trimmed.slice(0, 1200);
    if (!contentType.toLowerCase().includes('application/json')) {
        throw new Error(buildBenchmarkNonJsonMessage(response, contentType, snippet));
    }
    let payload = null;
    try {
        payload = JSON.parse(text);
    } catch (error) {
        const cleanedSnippet = snippet.replace(/\s+/g, ' ').slice(0, 220);
        throw new Error(`Server returned invalid benchmark JSON. HTTP ${response.status}. First response bytes: ${cleanedSnippet}`);
    }
    if (!response.ok || !payload.ok) {
        throw new Error(String(payload.error || `Benchmark request failed with HTTP ${response.status}.`));
    }
    return payload;
}

/**
 * Return a URL with benchmark query parameters appended.
 *
 * @param {string} publicUrl Base public gallery URL.
 * @param {string} token Benchmark token.
 * @param {number} runIndex One-based run number.
 * @return {string} URL for the hidden iframe.
 */
function buildBenchmarkFrameUrl(publicUrl, token, runIndex) {
    const url = new URL(publicUrl, window.location.href);
    url.searchParams.set('benchmark_token', token);
    url.searchParams.set('benchmark_run', String(runIndex));
    url.searchParams.set('benchmark_cache_bust', `${Date.now()}-${runIndex}`);
    return url.toString();
}

/**
 * Create or reuse the hidden benchmark iframe for the panel.
 *
 * @param {HTMLElement} panel Benchmark root element.
 * @return {HTMLIFrameElement} Hidden iframe.
 */
function ensureBenchmarkFrame(panel) {
    let frame = panel.querySelector('iframe[data-gallery-benchmark-frame]');
    if (frame instanceof HTMLIFrameElement) {
        return frame;
    }
    frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.dataset.galleryBenchmarkFrame = '1';
    frame.style.position = 'absolute';
    frame.style.width = '1px';
    frame.style.height = '1px';
    frame.style.opacity = '0';
    frame.style.pointerEvents = 'none';
    panel.appendChild(frame);
    return frame;
}

/**
 * Load one iframe URL and resolve when the browser fires the iframe load event.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @param {string} url Target URL.
 * @param {number} timeoutMs Timeout in milliseconds.
 * @return {Promise<{elapsedMs: number}>} Load timing.
 */
function loadBenchmarkFrame(frame, url, timeoutMs) {
    return new Promise((resolve, reject) => {
        const startedAt = performance.now();
        let settled = false;
        const timer = window.setTimeout(() => {
            if (settled) {
                return;
            }
            settled = true;
            frame.onload = null;
            reject(new Error('Benchmark iframe load timed out.'));
        }, timeoutMs);
        frame.onload = () => {
            if (settled) {
                return;
            }
            settled = true;
            window.clearTimeout(timer);
            const elapsedMs = performance.now() - startedAt;
            window.setTimeout(() => resolve({elapsedMs}), 0);
        };
        frame.src = url;
    });
}

/**
 * Return a serializable copy of a PerformanceNavigationTiming entry.
 *
 * @param {PerformanceNavigationTiming|PerformanceEntry|undefined} entry Navigation timing entry.
 * @return {Record<string, number|string>} Serializable timing object.
 */
function serializeNavigationTiming(entry) {
    if (!entry) {
        return {};
    }
    const keys = [
        'startTime',
        'duration',
        'redirectStart',
        'redirectEnd',
        'fetchStart',
        'domainLookupStart',
        'domainLookupEnd',
        'connectStart',
        'connectEnd',
        'secureConnectionStart',
        'requestStart',
        'responseStart',
        'responseEnd',
        'domInteractive',
        'domContentLoadedEventStart',
        'domContentLoadedEventEnd',
        'domComplete',
        'loadEventStart',
        'loadEventEnd',
        'transferSize',
        'encodedBodySize',
        'decodedBodySize',
    ];
    const result = {
        name: String(entry.name || ''),
        entryType: String(entry.entryType || ''),
        initiatorType: String(entry.initiatorType || ''),
    };
    keys.forEach((key) => {
        const value = entry[key];
        if (typeof value === 'number' && Number.isFinite(value)) {
            result[key] = value;
        }
    });
    return result;
}

/**
 * Return aggregate browser resource timing grouped by initiator type.
 *
 * @param {PerformanceResourceTiming[]} resources Resource timing entries.
 * @return {Record<string, unknown>} Resource timing summary.
 */
function summarizeResourceTiming(resources) {
    const summary = {
        count: resources.length,
        total_transfer_size: 0,
        total_encoded_body_size: 0,
        total_decoded_body_size: 0,
        by_initiator_type: {},
        slowest: [],
    };
    resources.forEach((entry) => {
        const initiatorType = entry.initiatorType || 'unknown';
        if (!summary.by_initiator_type[initiatorType]) {
            summary.by_initiator_type[initiatorType] = {
                count: 0,
                total_duration: 0,
                max_duration: 0,
                total_transfer_size: 0,
                total_encoded_body_size: 0,
                total_decoded_body_size: 0,
            };
        }
        const bucket = summary.by_initiator_type[initiatorType];
        bucket.count += 1;
        bucket.total_duration += entry.duration || 0;
        bucket.max_duration = Math.max(bucket.max_duration, entry.duration || 0);
        bucket.total_transfer_size += entry.transferSize || 0;
        bucket.total_encoded_body_size += entry.encodedBodySize || 0;
        bucket.total_decoded_body_size += entry.decodedBodySize || 0;
        summary.total_transfer_size += entry.transferSize || 0;
        summary.total_encoded_body_size += entry.encodedBodySize || 0;
        summary.total_decoded_body_size += entry.decodedBodySize || 0;
    });
    summary.slowest = resources
        .slice()
        .sort((left, right) => (right.duration || 0) - (left.duration || 0))
        .slice(0, 25)
        .map((entry) => ({
            name: String(entry.name || '').slice(0, 600),
            initiatorType: String(entry.initiatorType || 'unknown'),
            duration: entry.duration || 0,
            transferSize: entry.transferSize || 0,
            encodedBodySize: entry.encodedBodySize || 0,
            decodedBodySize: entry.decodedBodySize || 0,
        }));
    return summary;
}

/**
 * Collect browser timing data from a same-origin benchmark iframe.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @param {number} elapsedMs Parent-measured iframe elapsed time.
 * @return {Record<string, unknown>} Browser timing payload.
 */
function collectBenchmarkBrowserTiming(frame, elapsedMs) {
    const payload = {
        recorded_at_browser: new Date().toISOString(),
        iframe_elapsed_ms: elapsedMs,
        navigation: {},
        resources: {},
        document: {},
    };
    try {
        const targetWindow = frame.contentWindow;
        const targetDocument = frame.contentDocument;
        const performanceApi = targetWindow ? targetWindow.performance : null;
        const navigation = performanceApi ? performanceApi.getEntriesByType('navigation')[0] : null;
        const resources = performanceApi ? performanceApi.getEntriesByType('resource') : [];
        payload.navigation = serializeNavigationTiming(navigation);
        payload.resources = summarizeResourceTiming(resources);
        payload.document = {
            title: targetDocument ? String(targetDocument.title || '') : '',
            readyState: targetDocument ? String(targetDocument.readyState || '') : '',
            image_count: targetDocument ? targetDocument.images.length : null,
            script_count: targetDocument ? targetDocument.scripts.length : null,
            stylesheet_count: targetDocument ? targetDocument.querySelectorAll('link[rel="stylesheet"]').length : null,
            html_length: targetDocument && targetDocument.documentElement ? targetDocument.documentElement.outerHTML.length : null,
        };
    } catch (error) {
        payload.error = error instanceof Error ? error.message : String(error);
    }
    return payload;
}

/**
 * Enable the benchmark download link.
 *
 * @param {HTMLElement} panel Benchmark root element.
 * @param {string} downloadUrl Download URL.
 */
function enableBenchmarkDownload(panel, downloadUrl) {
    const download = benchmarkPanelElement(panel, '[data-gallery-benchmark-download]');
    if (!(download instanceof HTMLAnchorElement)) {
        return;
    }
    download.href = downloadUrl;
    download.classList.remove('is-disabled');
    download.removeAttribute('aria-disabled');
}

/**
 * Run all benchmark passes for one panel.
 *
 * @param {HTMLElement} panel Benchmark root element.
 */
async function runGalleryBenchmark(panel) {
    const startButton = benchmarkPanelElement(panel, '[data-gallery-benchmark-start]');
    const galleryId = boundedDatasetInteger(panel, 'galleryId', 0, 0, Number.MAX_SAFE_INTEGER);
    const runsTotal = boundedDatasetInteger(panel, 'benchmarkRuns', 5, 1, 20);
    const csrfToken = panel.dataset.csrfToken || '';
    const startUrl = panel.dataset.startUrl || '';
    const browserUrl = panel.dataset.browserUrl || '';
    if (!(startButton instanceof HTMLButtonElement) || galleryId <= 0 || !csrfToken || !startUrl || !browserUrl) {
        return;
    }
    startButton.disabled = true;
    let downloadUrl = '';
    updateBenchmarkStatus(panel, 'Starting benchmark...', 0);
    try {
        const started = await postBenchmarkJson(startUrl, {
            csrf_token: csrfToken,
            gallery_id: galleryId,
            runs_total: runsTotal,
        });
        const token = String(started.token || '');
        const publicUrl = String(started.public_url || '');
        downloadUrl = String(started.download_url || '');
        const effectiveRunsTotal = Number.parseInt(String(started.runs_total || runsTotal), 10) || runsTotal;
        const frame = ensureBenchmarkFrame(panel);
        updateBenchmarkSummary(panel, started.summary || {});
        for (let runIndex = 1; runIndex <= effectiveRunsTotal; runIndex += 1) {
            const percentBefore = ((runIndex - 1) / effectiveRunsTotal) * 100;
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal}: loading gallery in hidden iframe...`, percentBefore);
            const frameUrl = buildBenchmarkFrameUrl(publicUrl, token, runIndex);
            const frameResult = await loadBenchmarkFrame(frame, frameUrl, 120000);
            const browserPayload = collectBenchmarkBrowserTiming(frame, frameResult.elapsedMs);
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal}: saving browser timing...`, percentBefore + (50 / effectiveRunsTotal));
            const saved = await postBenchmarkJson(browserUrl, {
                csrf_token: csrfToken,
                token,
                run_index: runIndex,
                browser_json: JSON.stringify(browserPayload),
            });
            updateBenchmarkSummary(panel, saved.summary || {});
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal} complete.`, (runIndex / effectiveRunsTotal) * 100);
        }
        enableBenchmarkDownload(panel, downloadUrl);
        updateBenchmarkStatus(panel, 'Benchmark complete. Download log is now enabled.', 100);
    } catch (error) {
        if (downloadUrl) {
            enableBenchmarkDownload(panel, downloadUrl);
        }
        updateBenchmarkStatus(panel, error instanceof Error ? error.message : String(error), 100);
    } finally {
        startButton.disabled = false;
    }
}

/**
 * Attach gallery benchmark behavior to admin-only public gallery panels.
 */
export function setupAdminGalleryBenchmark() {
    document.querySelectorAll('[data-gallery-benchmark]').forEach((panel) => {
        const startButton = benchmarkPanelElement(panel, '[data-gallery-benchmark-start]');
        if (!(panel instanceof HTMLElement) || !(startButton instanceof HTMLButtonElement) || startButton.dataset.galleryBenchmarkReady === '1') {
            return;
        }
        startButton.dataset.galleryBenchmarkReady = '1';
        startButton.addEventListener('click', () => {
            runGalleryBenchmark(panel);
        });
    });
}
