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

// BENCHMARK_DIAGNOSTICS_VERSION must match the PHP service response before any run is accepted.
const BENCHMARK_DIAGNOSTICS_VERSION = '20260820-benchmark-diagnostics-v4.2';
const BENCHMARK_MEDIA_COOKIE_NAME = 'gallery_benchmark_media_context';

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
 * Clear the short-lived media correlation cookie before or after one benchmark run.
 *
 * The lightbox module sets this cookie only while benchmark media activity is being
 * observed. Clearing it before navigation prevents cached state from a previous run
 * from tagging unrelated gallery resources.
 */
function clearBenchmarkMediaContextCookie() {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${BENCHMARK_MEDIA_COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax${secure}`;
}


/**
 * Activate media correlation only for the lightbox exercise inside the iframe.
 *
 * @param {Document} targetDocument Same-origin iframe document.
 * @param {Window} targetWindow Same-origin iframe window.
 * @param {string} token Benchmark token.
 * @param {number} runIndex One-based benchmark run.
 */
function setBenchmarkMediaContextCookie(targetDocument, targetWindow, token, runIndex) {
    const secure = targetWindow.location.protocol === 'https:' ? '; Secure' : '';
    targetDocument.cookie = `${BENCHMARK_MEDIA_COOKIE_NAME}=${token}:${runIndex}; Path=/; Max-Age=120; SameSite=Lax${secure}`;
}

/**
 * Run one cache-busted static or lightweight PHP probe without clock synchronization.
 *
 * PHP probes report their own request duration. Subtracting that duration from
 * the browser round trip estimates time spent outside PHP without comparing clocks.
 *
 * @param {Window} targetWindow Same-origin window used for fetch.
 * @param {string} rawUrl Probe URL.
 * @param {string} kind Probe kind: static or php.
 * @param {string} token Benchmark token.
 * @param {number} runIndex One-based run number.
 * @param {string} phase Probe phase label.
 * @return {Promise<Record<string, unknown>>} Probe result.
 */
async function runBenchmarkLayerProbe(targetWindow, rawUrl, kind, token, runIndex, phase) {
    const url = new URL(rawUrl, targetWindow.location.href);
    const cacheBust = `${Date.now()}-${runIndex}-${phase}-${kind}-${Math.random().toString(16).slice(2)}`;
    url.searchParams.set('benchmark_probe_bust', cacheBust);
    if (kind === 'php') {
        url.searchParams.set('token', token);
        url.searchParams.set('run_index', String(runIndex));
        url.searchParams.set('phase', phase);
    }
    const startedAt = performance.now();
    try {
        const response = await targetWindow.fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {Accept: kind === 'php' ? 'application/json' : 'text/plain'},
        });
        const headersAt = performance.now();
        const text = await response.text();
        const completedAt = performance.now();
        let server = null;
        if (kind === 'php') {
            try {
                server = JSON.parse(text);
            } catch {
                server = null;
            }
        }
        const browserRoundTripMs = completedAt - startedAt;
        const serverProcessingMs = server && Number.isFinite(Number(server.response_at_unix)) && Number.isFinite(Number(server.request_time_unix))
            ? Math.max(0, (Number(server.response_at_unix) - Number(server.request_time_unix)) * 1000)
            : null;
        return {
            kind,
            phase,
            ok: response.ok,
            status: response.status,
            browser_round_trip_ms: browserRoundTripMs,
            browser_headers_ms: headersAt - startedAt,
            browser_body_ms: completedAt - headersAt,
            body_length: text.length,
            server_processing_ms: serverProcessingMs,
            outside_php_estimate_ms: kind === 'php' && serverProcessingMs !== null ? Math.max(0, browserRoundTripMs - serverProcessingMs) : null,
            server,
        };
    } catch (error) {
        return {
            kind,
            phase,
            ok: false,
            browser_round_trip_ms: performance.now() - startedAt,
            error: error instanceof Error ? error.message : String(error),
        };
    }
}

/**
 * Run paired static and PHP probes for one benchmark phase.
 *
 * @return {Promise<Record<string, unknown>>} Layer probe pair.
 */
async function runBenchmarkLayerProbePair(targetWindow, staticProbeUrl, phpProbeUrl, token, runIndex, phase) {
    const staticProbe = await runBenchmarkLayerProbe(targetWindow, staticProbeUrl, 'static', token, runIndex, phase);
    const phpProbe = await runBenchmarkLayerProbe(targetWindow, phpProbeUrl, 'php', token, runIndex, phase);
    return {phase, static: staticProbe, php: phpProbe};
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
    const browserRequestMs = Date.now();
    url.searchParams.set('benchmark_token', token);
    url.searchParams.set('benchmark_run', String(runIndex));
    url.searchParams.set('benchmark_browser_request_ms', String(browserRequestMs));
    url.searchParams.set('benchmark_cache_bust', `${browserRequestMs}-${runIndex}`);
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
    frame.style.position = 'fixed';
    frame.style.left = '-20000px';
    frame.style.top = '0';
    frame.style.width = `${Math.max(1024, Math.min(1920, window.innerWidth || 1365))}px`;
    frame.style.height = `${Math.max(720, Math.min(1080, window.innerHeight || 768))}px`;
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
 * Return a serializable Server-Timing metric list.
 *
 * @param {PerformanceServerTiming[]|DOMStringList|undefined} metrics Server timing entries.
 * @return {Array<Record<string, number|string>>} Serializable metrics.
 */
function serializeServerTiming(metrics) {
    if (!metrics || typeof metrics[Symbol.iterator] !== 'function') {
        return [];
    }
    return Array.from(metrics).slice(0, 40).map((metric) => ({
        name: String(metric?.name || ''),
        duration: typeof metric?.duration === 'number' && Number.isFinite(metric.duration) ? metric.duration : 0,
        description: String(metric?.description || '').slice(0, 300),
    }));
}

/**
 * Return a serializable copy of a PerformanceNavigationTiming entry.
 *
 * @param {PerformanceNavigationTiming|PerformanceEntry|undefined} entry Navigation timing entry.
 * @return {Record<string, number|string|Array<Record<string, number|string>>>} Serializable timing object.
 */
function serializeNavigationTiming(entry, timeOriginMs = 0) {
    if (!entry) {
        return {};
    }
    const numericKeys = [
        'startTime',
        'duration',
        'unloadEventStart',
        'unloadEventEnd',
        'redirectStart',
        'redirectEnd',
        'workerStart',
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
        'activationStart',
        'criticalCHRestart',
        'transferSize',
        'encodedBodySize',
        'decodedBodySize',
        'responseStatus',
    ];
    const stringKeys = ['nextHopProtocol', 'deliveryType', 'renderBlockingStatus'];
    const result = {
        name: String(entry.name || ''),
        entryType: String(entry.entryType || ''),
        initiatorType: String(entry.initiatorType || ''),
        type: String(entry.type || ''),
    };
    numericKeys.forEach((key) => {
        const value = entry[key];
        if (typeof value === 'number' && Number.isFinite(value)) {
            result[key] = value;
        }
    });
    stringKeys.forEach((key) => {
        const value = entry[key];
        if (typeof value === 'string' && value !== '') {
            result[key] = value;
        }
    });
    if (Number.isFinite(timeOriginMs) && timeOriginMs > 0) {
        ['fetchStart', 'requestStart', 'responseStart', 'responseEnd'].forEach((key) => {
            const value = Number(entry[key] || 0);
            if (value > 0) {
                result[`absolute_${key}_browser_ms`] = timeOriginMs + value;
            }
        });
    }
    result.serverTiming = serializeServerTiming(entry.serverTiming);
    return result;
}

/**
 * Return a normalized browser timing breakdown for the main document request.
 *
 * @param {PerformanceNavigationTiming|PerformanceEntry|undefined} entry Navigation timing entry.
 * @return {Record<string, number|null>} Derived timing phases.
 */
function navigationTimingBreakdown(entry) {
    if (!entry) {
        return {};
    }
    /**
     * Return one non-negative duration between two navigation timing fields.
     *
     * @param {string} end Ending field name.
     * @param {string} start Starting field name.
     * @return {number|null} Duration in milliseconds or null.
     */
    const diff = (end, start) => (
        typeof entry[end] === 'number' && typeof entry[start] === 'number'
            ? Math.max(0, entry[end] - entry[start])
            : null
    );
    return {
        dns_ms: diff('domainLookupEnd', 'domainLookupStart'),
        connect_ms: diff('connectEnd', 'connectStart'),
        tls_ms: entry.secureConnectionStart > 0 ? Math.max(0, entry.connectEnd - entry.secureConnectionStart) : 0,
        request_queue_or_stall_ms: diff('requestStart', 'fetchStart'),
        ttfb_ms: diff('responseStart', 'requestStart'),
        response_download_ms: diff('responseEnd', 'responseStart'),
        response_to_dom_interactive_ms: diff('domInteractive', 'responseEnd'),
        dom_interactive_to_dcl_ms: diff('domContentLoadedEventStart', 'domInteractive'),
        dcl_handler_ms: diff('domContentLoadedEventEnd', 'domContentLoadedEventStart'),
        dcl_to_load_ms: diff('loadEventStart', 'domContentLoadedEventEnd'),
        load_handler_ms: diff('loadEventEnd', 'loadEventStart'),
    };
}

/**
 * Return whether Resource Timing indicates a browser-cache response.
 *
 * @param {PerformanceResourceTiming} entry Resource timing entry.
 * @return {string} network, cache, or unknown.
 */
function resourceCacheKind(entry) {
    if ((entry.transferSize || 0) > 0) {
        return 'network';
    }
    if ((entry.encodedBodySize || 0) > 0 || (entry.decodedBodySize || 0) > 0) {
        return 'cache';
    }
    return 'unknown';
}

/**
 * Return a detailed serializable Resource Timing row.
 *
 * @param {PerformanceResourceTiming} entry Resource timing entry.
 * @return {Record<string, unknown>} Serializable resource timing row.
 */
function serializeResourceTiming(entry, timeOriginMs = 0) {
    const fetchStart = Number(entry.fetchStart || 0);
    const requestStart = Number(entry.requestStart || 0);
    const responseStart = Number(entry.responseStart || 0);
    const responseEnd = Number(entry.responseEnd || 0);
    return {
        name: String(entry.name || '').slice(0, 900),
        initiatorType: String(entry.initiatorType || 'unknown'),
        nextHopProtocol: String(entry.nextHopProtocol || ''),
        deliveryType: String(entry.deliveryType || ''),
        renderBlockingStatus: String(entry.renderBlockingStatus || ''),
        responseStatus: typeof entry.responseStatus === 'number' ? entry.responseStatus : null,
        startTime: Number(entry.startTime || 0),
        duration: Number(entry.duration || 0),
        fetchStart,
        requestStart,
        responseStart,
        responseEnd,
        queue_or_stall_ms: Math.max(0, requestStart - fetchStart),
        ttfb_ms: Math.max(0, responseStart - requestStart),
        download_ms: Math.max(0, responseEnd - responseStart),
        transferSize: Number(entry.transferSize || 0),
        encodedBodySize: Number(entry.encodedBodySize || 0),
        decodedBodySize: Number(entry.decodedBodySize || 0),
        cache_kind: resourceCacheKind(entry),
        absolute_fetch_start_browser_ms: Number.isFinite(timeOriginMs) && timeOriginMs > 0 && fetchStart > 0 ? timeOriginMs + fetchStart : null,
        absolute_request_start_browser_ms: Number.isFinite(timeOriginMs) && timeOriginMs > 0 && requestStart > 0 ? timeOriginMs + requestStart : null,
        absolute_response_start_browser_ms: Number.isFinite(timeOriginMs) && timeOriginMs > 0 && responseStart > 0 ? timeOriginMs + responseStart : null,
        absolute_response_end_browser_ms: Number.isFinite(timeOriginMs) && timeOriginMs > 0 && responseEnd > 0 ? timeOriginMs + responseEnd : null,
        serverTiming: serializeServerTiming(entry.serverTiming),
    };
}

/**
 * Return aggregate browser resource timing grouped by initiator type.
 *
 * @param {PerformanceResourceTiming[]} resources Resource timing entries.
 * @return {Record<string, unknown>} Resource timing summary.
 */
function summarizeResourceTiming(resources, timeOriginMs = 0) {
    const summary = {
        count: resources.length,
        network_count: 0,
        cache_count: 0,
        unknown_cache_count: 0,
        total_transfer_size: 0,
        total_encoded_body_size: 0,
        total_decoded_body_size: 0,
        total_queue_or_stall_ms: 0,
        max_queue_or_stall_ms: 0,
        total_ttfb_ms: 0,
        max_ttfb_ms: 0,
        total_download_ms: 0,
        max_download_ms: 0,
        by_initiator_type: {},
        slowest: [],
        highest_ttfb: [],
        detailed: [],
    };
    resources.forEach((entry) => {
        const initiatorType = entry.initiatorType || 'unknown';
        const cacheKind = resourceCacheKind(entry);
        const queueMs = Math.max(0, Number(entry.requestStart || 0) - Number(entry.fetchStart || 0));
        const ttfbMs = Math.max(0, Number(entry.responseStart || 0) - Number(entry.requestStart || 0));
        const downloadMs = Math.max(0, Number(entry.responseEnd || 0) - Number(entry.responseStart || 0));
        if (!summary.by_initiator_type[initiatorType]) {
            summary.by_initiator_type[initiatorType] = {
                count: 0,
                network_count: 0,
                cache_count: 0,
                total_duration: 0,
                max_duration: 0,
                total_ttfb_ms: 0,
                max_ttfb_ms: 0,
                total_transfer_size: 0,
                total_encoded_body_size: 0,
                total_decoded_body_size: 0,
            };
        }
        const bucket = summary.by_initiator_type[initiatorType];
        bucket.count += 1;
        bucket.total_duration += entry.duration || 0;
        bucket.max_duration = Math.max(bucket.max_duration, entry.duration || 0);
        bucket.total_ttfb_ms += ttfbMs;
        bucket.max_ttfb_ms = Math.max(bucket.max_ttfb_ms, ttfbMs);
        bucket.total_transfer_size += entry.transferSize || 0;
        bucket.total_encoded_body_size += entry.encodedBodySize || 0;
        bucket.total_decoded_body_size += entry.decodedBodySize || 0;
        if (cacheKind === 'network') {
            summary.network_count += 1;
            bucket.network_count += 1;
        } else if (cacheKind === 'cache') {
            summary.cache_count += 1;
            bucket.cache_count += 1;
        } else {
            summary.unknown_cache_count += 1;
        }
        summary.total_queue_or_stall_ms += queueMs;
        summary.max_queue_or_stall_ms = Math.max(summary.max_queue_or_stall_ms, queueMs);
        summary.total_ttfb_ms += ttfbMs;
        summary.max_ttfb_ms = Math.max(summary.max_ttfb_ms, ttfbMs);
        summary.total_download_ms += downloadMs;
        summary.max_download_ms = Math.max(summary.max_download_ms, downloadMs);
        summary.total_transfer_size += entry.transferSize || 0;
        summary.total_encoded_body_size += entry.encodedBodySize || 0;
        summary.total_decoded_body_size += entry.decodedBodySize || 0;
    });
    summary.slowest = resources
        .slice()
        .sort((left, right) => (right.duration || 0) - (left.duration || 0))
        .slice(0, 40)
        .map((entry) => serializeResourceTiming(entry, timeOriginMs));
    summary.highest_ttfb = resources
        .slice()
        .sort((left, right) => ((right.responseStart || 0) - (right.requestStart || 0)) - ((left.responseStart || 0) - (left.requestStart || 0)))
        .slice(0, 40)
        .map((entry) => serializeResourceTiming(entry, timeOriginMs));
    summary.detailed = resources
        .slice()
        .sort((left, right) => (left.startTime || 0) - (right.startTime || 0))
        .slice(0, 160)
        .map((entry) => serializeResourceTiming(entry, timeOriginMs));
    return summary;
}

/**
 * Return browser heap details when Chromium exposes performance.memory.
 *
 * @param {Window|null} targetWindow Iframe window.
 * @return {Record<string, number>|null} Heap metrics or null.
 */
function browserMemorySnapshot(targetWindow) {
    const memory = targetWindow?.performance?.memory;
    if (!memory) {
        return null;
    }
    return {
        used_js_heap_size: Number(memory.usedJSHeapSize || 0),
        total_js_heap_size: Number(memory.totalJSHeapSize || 0),
        js_heap_size_limit: Number(memory.jsHeapSizeLimit || 0),
    };
}

/**
 * Collect buffered performance entries that are not exposed through getEntriesByType in every browser.
 *
 * @param {Window|null} targetWindow Iframe window.
 * @param {string} type PerformanceObserver entry type.
 * @param {number} waitMs Small observer drain delay.
 * @return {Promise<Array<Record<string, unknown>>>} Serializable entry rows.
 */
function observeBufferedPerformanceEntries(targetWindow, type, waitMs = 40) {
    return new Promise((resolve) => {
        if (!targetWindow?.PerformanceObserver) {
            resolve([]);
            return;
        }
        const rows = [];
        let observer = null;
        try {
            observer = new targetWindow.PerformanceObserver((list) => {
                list.getEntries().forEach((entry) => {
                    const row = {
                        entryType: String(entry.entryType || type),
                        name: String(entry.name || '').slice(0, 500),
                        startTime: Number(entry.startTime || 0),
                        duration: Number(entry.duration || 0),
                    };
                    if (typeof entry.value === 'number') {
                        row.value = entry.value;
                    }
                    if (typeof entry.hadRecentInput === 'boolean') {
                        row.hadRecentInput = entry.hadRecentInput;
                    }
                    if (typeof entry.renderTime === 'number') {
                        row.renderTime = entry.renderTime;
                    }
                    if (typeof entry.loadTime === 'number') {
                        row.loadTime = entry.loadTime;
                    }
                    if (typeof entry.size === 'number') {
                        row.size = entry.size;
                    }
                    if (typeof entry.url === 'string') {
                        row.url = entry.url.slice(0, 700);
                    }
                    rows.push(row);
                });
            });
            observer.observe({type, buffered: true});
        } catch {
            resolve([]);
            return;
        }
        window.setTimeout(() => {
            observer?.disconnect();
            resolve(rows.slice(-100));
        }, waitMs);
    });
}

/**
 * Collect browser timing data from a same-origin benchmark iframe.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @param {number} elapsedMs Parent-measured iframe elapsed time.
 * @return {Promise<Record<string, unknown>>} Browser timing payload.
 */
async function collectBenchmarkBrowserTiming(frame, elapsedMs) {
    const payload = {
        diagnostics_version: BENCHMARK_DIAGNOSTICS_VERSION,
        recorded_at_browser: new Date().toISOString(),
        iframe_elapsed_ms: elapsedMs,
        performance_time_origin_ms: null,
        correlation_method: 'duration_difference_no_clock_sync',
        navigation: {},
        timing_breakdown: {},
        resources: {},
        paint: [],
        buffered_performance: {},
        document: {},
        environment: {},
        memory: null,
    };
    try {
        const targetWindow = frame.contentWindow;
        const targetDocument = frame.contentDocument;
        const performanceApi = targetWindow ? targetWindow.performance : null;
        const navigation = performanceApi ? performanceApi.getEntriesByType('navigation')[0] : null;
        const resources = performanceApi ? performanceApi.getEntriesByType('resource') : [];
        const timeOriginMs = Number(performanceApi?.timeOrigin || 0);
        payload.performance_time_origin_ms = Number.isFinite(timeOriginMs) && timeOriginMs > 0 ? timeOriginMs : null;
        const connection = targetWindow?.navigator?.connection || targetWindow?.navigator?.mozConnection || targetWindow?.navigator?.webkitConnection || null;
        payload.navigation = serializeNavigationTiming(navigation, timeOriginMs);
        payload.timing_breakdown = navigationTimingBreakdown(navigation);
        payload.resources = summarizeResourceTiming(resources, timeOriginMs);
        payload.paint = performanceApi ? performanceApi.getEntriesByType('paint').map((entry) => ({
            name: String(entry.name || ''),
            startTime: Number(entry.startTime || 0),
            duration: Number(entry.duration || 0),
        })) : [];
        const [largestContentfulPaint, layoutShift, longTasks] = await Promise.all([
            observeBufferedPerformanceEntries(targetWindow, 'largest-contentful-paint'),
            observeBufferedPerformanceEntries(targetWindow, 'layout-shift'),
            observeBufferedPerformanceEntries(targetWindow, 'longtask'),
        ]);
        payload.buffered_performance = {
            largest_contentful_paint: largestContentfulPaint,
            layout_shift: layoutShift,
            long_tasks: longTasks,
            long_task_total_ms: longTasks.reduce((total, entry) => total + Number(entry.duration || 0), 0),
            long_task_max_ms: longTasks.reduce((maximum, entry) => Math.max(maximum, Number(entry.duration || 0)), 0),
        };
        payload.document = {
            title: targetDocument ? String(targetDocument.title || '') : '',
            readyState: targetDocument ? String(targetDocument.readyState || '') : '',
            visibility_state: targetDocument ? String(targetDocument.visibilityState || '') : '',
            image_count: targetDocument ? targetDocument.images.length : null,
            complete_image_count: targetDocument ? Array.from(targetDocument.images).filter((image) => image.complete).length : null,
            script_count: targetDocument ? targetDocument.scripts.length : null,
            stylesheet_count: targetDocument ? targetDocument.querySelectorAll('link[rel="stylesheet"]').length : null,
            html_length: targetDocument && targetDocument.documentElement ? targetDocument.documentElement.outerHTML.length : null,
        };
        payload.environment = {
            iframe_inner_width: targetWindow?.innerWidth ?? null,
            iframe_inner_height: targetWindow?.innerHeight ?? null,
            device_pixel_ratio: targetWindow?.devicePixelRatio ?? null,
            hardware_concurrency: targetWindow?.navigator?.hardwareConcurrency ?? null,
            device_memory_gib: targetWindow?.navigator?.deviceMemory ?? null,
            max_touch_points: targetWindow?.navigator?.maxTouchPoints ?? null,
            online: targetWindow?.navigator?.onLine ?? null,
            connection: connection ? {
                effective_type: String(connection.effectiveType || ''),
                downlink_mbps: typeof connection.downlink === 'number' ? connection.downlink : null,
                rtt_ms: typeof connection.rtt === 'number' ? connection.rtt : null,
                save_data: Boolean(connection.saveData),
            } : null,
        };
        payload.memory = browserMemorySnapshot(targetWindow);
    } catch (error) {
        payload.error = error instanceof Error ? error.message : String(error);
    }
    return payload;
}

/**
 * Resolve after a bounded delay.
 *
 * @param {number} milliseconds Delay duration.
 * @return {Promise<void>} Completion promise.
 */
function benchmarkDelay(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

/**
 * Wait until a same-origin iframe condition is true or a timeout expires.
 *
 * @param {() => boolean} predicate Condition callback.
 * @param {number} timeoutMs Maximum wait duration.
 * @param {number} pollMs Poll interval.
 * @return {Promise<boolean>} True when the condition became true.
 */
async function waitForBenchmarkCondition(predicate, timeoutMs, pollMs = 50) {
    const startedAt = performance.now();
    while ((performance.now() - startedAt) < timeoutMs) {
        try {
            if (predicate()) {
                return true;
            }
        } catch {
            return false;
        }
        await benchmarkDelay(pollMs);
    }
    return false;
}

/**
 * Return the internal benchmark lightbox snapshot when the module exposes it.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @return {Record<string, unknown>|null} Lightbox diagnostics or null.
 */
function benchmarkLightboxSnapshot(frame) {
    try {
        const snapshot = frame.contentWindow?.PHPGalleryBenchmarkDiagnostics?.lightboxSnapshot;
        return typeof snapshot === 'function' ? snapshot() : null;
    } catch {
        return null;
    }
}

/**
 * Fetch the gallery HTML immediately after lightbox close to reveal session or server queue contention.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @param {string} publicUrl Public gallery URL.
 * @param {string} token Benchmark token.
 * @param {number} runIndex One-based run number.
 * @return {Promise<Record<string, unknown>>} Probe timing and response metadata.
 */
async function runPostLightboxProbe(frame, publicUrl, token, runIndex) {
    const targetWindow = frame.contentWindow;
    if (!targetWindow?.fetch) {
        return {supported: false};
    }
    const url = new URL(publicUrl, targetWindow.location.href);
    url.searchParams.set('benchmark_token', token);
    url.searchParams.set('benchmark_run', String(runIndex));
    const browserRequestMs = Date.now();
    url.searchParams.set('benchmark_phase', 'post_lightbox_probe');
    url.searchParams.set('benchmark_browser_request_ms', String(browserRequestMs));
    url.searchParams.set('benchmark_cache_bust', `${browserRequestMs}-${runIndex}-post-lightbox`);
    const startedAt = performance.now();
    try {
        const response = await targetWindow.fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {Accept: 'text/html'},
        });
        const responseReceivedAt = performance.now();
        const text = await response.text();
        const completedAt = performance.now();
        return {
            supported: true,
            ok: response.ok,
            status: response.status,
            browser_request_wall_ms: browserRequestMs,
            elapsed_ms: completedAt - startedAt,
            headers_received_ms: responseReceivedAt - startedAt,
            body_read_ms: completedAt - responseReceivedAt,
            body_length: text.length,
            server_timing_header: String(response.headers.get('server-timing') || '').slice(0, 1200),
        };
    } catch (error) {
        return {
            supported: true,
            ok: false,
            elapsed_ms: performance.now() - startedAt,
            error: error instanceof Error ? error.message : String(error),
        };
    }
}

/**
 * Exercise the real lightbox lifecycle inside the benchmark iframe.
 *
 * The scenario opens the first photo, navigates a bounded number of steps, closes
 * the viewer while preload/metadata work may still exist, and immediately probes
 * the gallery again. This reproduces the workflow that originally exposed the
 * post-lightbox slowdown without requiring manual benchmark interaction.
 *
 * @param {HTMLIFrameElement} frame Hidden benchmark iframe.
 * @param {string} publicUrl Public gallery URL.
 * @param {string} token Benchmark token.
 * @param {number} runIndex One-based run number.
 * @return {Promise<Record<string, unknown>>} Scenario diagnostics.
 */
async function runBenchmarkLightboxScenario(frame, publicUrl, token, runIndex, staticProbeUrl, phpProbeUrl) {
    const result = {
        attempted: false,
        completed: false,
        navigation_steps_requested: 0,
        navigation_steps_completed: 0,
        snapshots: {},
        step_snapshots: [],
        resource_delta: {},
        post_close_probe: {},
        layer_probes: {},
        runtime_errors: [],
    };
    let runtimeWindow = null;
    let runtimeErrorHandler = null;
    let runtimeRejectionHandler = null;
    try {
        const targetWindow = frame.contentWindow;
        runtimeWindow = targetWindow;
        const targetDocument = frame.contentDocument;
        const performanceApi = targetWindow?.performance;
        if (!targetWindow || !targetDocument || !performanceApi) {
            result.reason = 'same_origin_iframe_unavailable';
            return result;
        }
        const runtimeErrors = result.runtime_errors;
        /** Record one synchronous iframe runtime error. */
        const onWindowError = (event) => {
            runtimeErrors.push({
                type: 'error',
                message: String(event?.message || 'Unknown iframe error').slice(0, 500),
                filename: String(event?.filename || '').slice(-300),
                line: Number(event?.lineno || 0),
                column: Number(event?.colno || 0),
            });
        };
        /** Record one unhandled iframe promise rejection. */
        const onUnhandledRejection = (event) => {
            const reason = event?.reason;
            runtimeErrors.push({
                type: 'unhandledrejection',
                message: String(reason instanceof Error ? reason.message : reason || 'Unknown rejection').slice(0, 500),
            });
        };
        runtimeErrorHandler = onWindowError;
        runtimeRejectionHandler = onUnhandledRejection;
        targetWindow.addEventListener('error', onWindowError);
        targetWindow.addEventListener('unhandledrejection', onUnhandledRejection);
        result.layer_probes.before_lightbox = await runBenchmarkLayerProbePair(targetWindow, staticProbeUrl, phpProbeUrl, token, runIndex, 'before_lightbox');
        const firstCard = targetDocument.querySelector('[data-lightbox-image], [data-lightbox-source]');
        const overlay = targetDocument.querySelector('[data-lightbox]');
        if (!(firstCard instanceof targetWindow.HTMLElement) || !(overlay instanceof targetWindow.HTMLElement)) {
            result.reason = 'lightbox_markup_unavailable';
            return result;
        }
        result.attempted = true;
        const resourceStartIndex = performanceApi.getEntriesByType('resource').length;
        result.snapshots.before_open = benchmarkLightboxSnapshot(frame);
        result.memory_before_open = browserMemorySnapshot(targetWindow);
        setBenchmarkMediaContextCookie(targetDocument, targetWindow, token, runIndex);
        firstCard.dispatchEvent(new targetWindow.MouseEvent('click', {bubbles: true, cancelable: true, view: targetWindow}));
        const opened = await waitForBenchmarkCondition(() => !overlay.hidden, 8000);
        result.opened = opened;
        result.snapshots.after_open = benchmarkLightboxSnapshot(frame);
        const firstImageLoaded = await waitForBenchmarkCondition(() => {
            const image = overlay.querySelector('[data-lightbox-img]');
            return image instanceof targetWindow.HTMLImageElement && image.complete && image.naturalWidth > 0;
        }, 8000);
        result.first_image_loaded = firstImageLoaded;
        result.snapshots.after_first_image = benchmarkLightboxSnapshot(frame);
        if (!opened || !firstImageLoaded || runtimeErrors.length > 0) {
            result.reason = !opened ? 'lightbox_did_not_open' : (!firstImageLoaded ? 'first_lightbox_image_did_not_load' : 'iframe_runtime_error');
            throw new Error(`Benchmark lightbox failed fast: ${result.reason}`);
        }
        result.memory_after_first_image = browserMemorySnapshot(targetWindow);

        const config = targetDocument.querySelector('[data-lightbox-config]');
        const total = Math.max(0, Number.parseInt(config?.dataset.lightboxTotal || '0', 10) || 0);
        const navigationSteps = Math.min(8, Math.max(0, total - 1));
        result.gallery_lightbox_total = total;
        result.navigation_steps_requested = navigationSteps;
        for (let step = 1; step <= navigationSteps; step += 1) {
            const nextButton = overlay.querySelector('[data-lightbox-action="next"]');
            if (!(nextButton instanceof targetWindow.HTMLElement)) {
                break;
            }
            const before = benchmarkLightboxSnapshot(frame);
            const beforeIndex = typeof before?.current_index === 'number' ? before.current_index : null;
            nextButton.dispatchEvent(new targetWindow.MouseEvent('click', {bubbles: true, cancelable: true, view: targetWindow}));
            const changed = await waitForBenchmarkCondition(() => {
                const current = benchmarkLightboxSnapshot(frame);
                if (typeof beforeIndex === 'number' && typeof current?.current_index === 'number') {
                    return current.current_index !== beforeIndex;
                }
                return true;
            }, 4000, 40);
            const imageReady = await waitForBenchmarkCondition(() => {
                const image = overlay.querySelector('[data-lightbox-img]');
                return image instanceof targetWindow.HTMLImageElement && image.complete && image.naturalWidth > 0;
            }, 4000, 50);
            if (!changed || !imageReady || runtimeErrors.length > 0) {
                result.reason = runtimeErrors.length > 0 ? 'iframe_runtime_error' : (!changed ? 'lightbox_navigation_did_not_change' : 'lightbox_navigation_image_did_not_load');
                throw new Error(`Benchmark lightbox failed fast at step ${step}: ${result.reason}`);
            }
            if (changed) {
                result.navigation_steps_completed += 1;
            }
            if (step === 1 || step === navigationSteps || step % 2 === 0) {
                result.step_snapshots.push({step, snapshot: benchmarkLightboxSnapshot(frame)});
            }
        }

        result.snapshots.before_close = benchmarkLightboxSnapshot(frame);
        result.memory_before_close = browserMemorySnapshot(targetWindow);
        const closeButton = overlay.querySelector('[data-lightbox-action="close"]');
        if (closeButton instanceof targetWindow.HTMLElement) {
            closeButton.dispatchEvent(new targetWindow.MouseEvent('click', {bubbles: true, cancelable: true, view: targetWindow}));
        }
        await waitForBenchmarkCondition(() => overlay.hidden, 3000);
        result.snapshots.immediately_after_close = benchmarkLightboxSnapshot(frame);
        result.memory_immediately_after_close = browserMemorySnapshot(targetWindow);
        const lightboxResources = performanceApi.getEntriesByType('resource');
        result.resource_delta = summarizeResourceTiming(lightboxResources.slice(resourceStartIndex), Number(performanceApi.timeOrigin || 0));

        result.layer_probes.after_lightbox = await runBenchmarkLayerProbePair(targetWindow, staticProbeUrl, phpProbeUrl, token, runIndex, 'after_lightbox');
        result.post_close_probe = await runPostLightboxProbe(frame, publicUrl, token, runIndex);
        result.snapshots.after_post_close_probe = benchmarkLightboxSnapshot(frame);
        result.memory_after_post_close_probe = browserMemorySnapshot(targetWindow);
        await benchmarkDelay(750);
        result.snapshots.after_close_750ms = benchmarkLightboxSnapshot(frame);
        result.memory_after_close_750ms = browserMemorySnapshot(targetWindow);

        result.completed = true;
        return result;
    } catch (error) {
        result.error = error instanceof Error ? error.message : String(error);
        return result;
    } finally {
        if (runtimeWindow && runtimeErrorHandler) {
            runtimeWindow.removeEventListener('error', runtimeErrorHandler);
        }
        if (runtimeWindow && runtimeRejectionHandler) {
            runtimeWindow.removeEventListener('unhandledrejection', runtimeRejectionHandler);
        }
    }
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
    const statusUrl = panel.dataset.statusUrl || '';
    const phpProbeUrl = panel.dataset.phpProbeUrl || '';
    const staticProbeUrl = panel.dataset.staticProbeUrl || '';
    if (!(startButton instanceof HTMLButtonElement) || galleryId <= 0 || !csrfToken || !startUrl || !browserUrl || !statusUrl || !phpProbeUrl || !staticProbeUrl) {
        return;
    }
    startButton.disabled = true;
    let downloadUrl = '';
    updateBenchmarkStatus(panel, `Starting benchmark diagnostics ${BENCHMARK_DIAGNOSTICS_VERSION}...`, 0);
    try {
        const started = await postBenchmarkJson(startUrl, {
            csrf_token: csrfToken,
            gallery_id: galleryId,
            runs_total: runsTotal,
        });
        const serverDiagnosticsVersion = String(started.diagnostics_version || '');
        const serverSchemaVersion = Number.parseInt(String(started.schema_version || '0'), 10) || 0;
        if (serverSchemaVersion !== 4 || serverDiagnosticsVersion !== BENCHMARK_DIAGNOSTICS_VERSION) {
            throw new Error(`Benchmark diagnostics version mismatch. Browser=${BENCHMARK_DIAGNOSTICS_VERSION}, server=${serverDiagnosticsVersion || 'missing'}, schema=${serverSchemaVersion}.`);
        }
        const token = String(started.token || '');
        const publicUrl = String(started.public_url || '');
        downloadUrl = String(started.download_url || '');
        const effectiveRunsTotal = Number.parseInt(String(started.runs_total || runsTotal), 10) || runsTotal;
        const frame = ensureBenchmarkFrame(panel);
        clearBenchmarkMediaContextCookie();
        updateBenchmarkSummary(panel, started.summary || {});
        for (let runIndex = 1; runIndex <= effectiveRunsTotal; runIndex += 1) {
            const percentBefore = ((runIndex - 1) / effectiveRunsTotal) * 100;
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal}: loading gallery in hidden iframe...`, percentBefore);
            clearBenchmarkMediaContextCookie();
            const frameUrl = buildBenchmarkFrameUrl(publicUrl, token, runIndex);
            const frameResult = await loadBenchmarkFrame(frame, frameUrl, 120000);
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal}: collecting baseline browser timing...`, percentBefore + (20 / effectiveRunsTotal));
            const browserPayload = await collectBenchmarkBrowserTiming(frame, frameResult.elapsedMs);
            updateBenchmarkStatus(panel, `Run ${runIndex}/${effectiveRunsTotal}: exercising lightbox lifecycle...`, percentBefore + (35 / effectiveRunsTotal));
            browserPayload.lightbox_scenario = await runBenchmarkLightboxScenario(frame, publicUrl, token, runIndex, staticProbeUrl, phpProbeUrl);
            clearBenchmarkMediaContextCookie();
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
        clearBenchmarkMediaContextCookie();
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
