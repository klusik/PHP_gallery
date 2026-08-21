/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-test-run.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Finalizes the opt-in Admin full test run after a forced gallery reload.
 *
 * Responsibilities:
 *   - Collect Navigation/Resource Timing, browser cache, paint, memory, and DOM measurements
 *   - Execute a strictly sequential verification probe chain with concurrency fixed at one
 *   - Exercise one warm full-render request plus optional lightbox/thumbnail paths without parallel stress
 *   - Submit the final browser payload and expose the generated diagnostics download
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
 *   - This module must never create concurrent diagnostic probes.
 *
 * Last Updated:
 *   2026-08-21
 */

/**
 * Remove credential-like query parameters before diagnostics persistence.
 *
 * @param {string} raw URL or local request target.
 * @returns {string} Redacted URL.
 */
function sanitizeDiagnosticUrl(raw) {
    try {
        const url = new URL(String(raw || ''), window.location.href);
        [...url.searchParams.keys()].forEach((key) => {
            if (/(token|csrf|password|secret|api[_-]?key|authorization|session)/i.test(key)) {
                url.searchParams.set(key, '[REDACTED]');
            }
        });
        return url.toString();
    } catch (_) {
        return String(raw || '').replace(/([?&](?:token|csrf[^=]*|password|secret|api[_-]?key|authorization|session)[^=]*)=[^&#]*/gi, '$1=[REDACTED]');
    }
}

/**
 * Return only infrastructure response headers explicitly approved for diagnostics.
 *
 * @param {Headers} headers Fetch response headers.
 * @returns {Record<string,string>} Allowlisted infrastructure headers.
 */
function providerHeaders(headers) {
    const allowlist = [
        'x-location', 'x-cdn-cache-status', 'x-rate-limit', 'age', 'server', 'via', 'alt-svc',
        'x-cacheable', 'x-filter-info', 'cf-cache-status', 'x-cache', 'x-served-by', 'x-timer',
        'cache-control', 'server-timing', 'x-gallery-test-request-id',
    ];
    const result = {};
    allowlist.forEach((name) => {
        const value = headers.get(name);
        if (value !== null && value !== '') {
            result[name] = String(value).slice(0, 1000);
        }
    });
    return result;
}

/**
 * Return a JSON-safe representation of one PerformanceEntry.
 *
 * @param {PerformanceEntry} entry Browser performance entry.
 * @returns {Record<string, *>} Serialized entry.
 */
function serializePerformanceEntry(entry) {
    const base = typeof entry.toJSON === 'function' ? entry.toJSON() : {
        name: entry.name,
        entryType: entry.entryType,
        startTime: entry.startTime,
        duration: entry.duration,
    };
    if (typeof base.name === 'string' && /^(?:https?:|\/)/i.test(base.name)) {
        base.name = sanitizeDiagnosticUrl(base.name);
    }
    if (entry.entryType === 'resource') {
        base.transfer_size = Number(entry.transferSize || 0);
        base.encoded_body_size = Number(entry.encodedBodySize || 0);
        base.decoded_body_size = Number(entry.decodedBodySize || 0);
        base.duration_ms = Number(entry.duration || 0);
        base.response_status = Number(entry.responseStatus || 0);
        base.next_hop_protocol = String(entry.nextHopProtocol || '');
        base.delivery_type = String(entry.deliveryType || '');
        base.render_blocking_status = String(entry.renderBlockingStatus || '');
        base.initiator_type = String(entry.initiatorType || '');
        base.queue_or_stall_ms = Math.max(0, Number(entry.requestStart || 0) - Number(entry.fetchStart || 0));
        base.ttfb_ms = Math.max(0, Number(entry.responseStart || 0) - Number(entry.requestStart || 0));
        base.download_ms = Math.max(0, Number(entry.responseEnd || 0) - Number(entry.responseStart || 0));
        base.probable_cache_hit = Number(entry.transferSize || 0) === 0 && Number(entry.decodedBodySize || 0) > 0;
    }
    if (entry.entryType === 'navigation') {
        base.ttfb_ms = Math.max(0, Number(entry.responseStart || 0) - Number(entry.requestStart || entry.fetchStart || 0));
        base.redirect_ms = Math.max(0, Number(entry.redirectEnd || 0) - Number(entry.redirectStart || 0));
    }
    return base;
}

/**
 * Convert the current page URL into a warm full-render URL without preserving prior test-run phase markers.
 *
 * @param {string} token Active opaque run token.
 * @returns {string} Cache-busted local URL.
 */
function warmRenderUrl(token) {
    const url = new URL(window.location.href);
    url.searchParams.set('test_run_token', token);
    url.searchParams.set('test_run_phase', 'warm_full_render');
    url.searchParams.set('test_run_cache_bust', String(Date.now()));
    return url.toString();
}

/**
 * Run exactly one fetch and return detailed timing/result metadata.
 *
 * @param {string} name Probe name.
 * @param {string} url Target URL.
 * @param {RequestInit} options Fetch options.
 * @returns {Promise<Record<string,*>>} Probe result.
 */
async function runProbe(name, url, options = {}) {
    const started = performance.now();
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
        });
        const headersAt = performance.now();
        const text = await response.text();
        const ended = performance.now();
        return {
            name,
            ok: response.ok,
            status: response.status,
            url: sanitizeDiagnosticUrl(response.url || url),
            started_ms: started,
            headers_received_ms: headersAt,
            ended_ms: ended,
            elapsed_ms: ended - started,
            ttfb_like_ms: headersAt - started,
            body_read_ms: ended - headersAt,
            body_bytes_utf8_estimate: new TextEncoder().encode(text).byteLength,
            response_content_type: response.headers.get('content-type') || '',
            diagnostic_request_id: response.headers.get('x-gallery-test-request-id') || '',
            server_timing: response.headers.get('server-timing') || '',
            provider_headers: providerHeaders(response.headers),
        };
    } catch (error) {
        const ended = performance.now();
        return {
            name,
            ok: false,
            status: 0,
            url: sanitizeDiagnosticUrl(url),
            started_ms: started,
            ended_ms: ended,
            elapsed_ms: ended - started,
            error: String(error && error.message ? error.message : error),
        };
    }
}

/**
 * Return browser/runtime information that does not require additional network requests.
 *
 * @returns {Record<string,*>} Browser diagnostics.
 */
function browserEnvironment() {
    const memory = performance.memory ? {
        js_heap_size_limit: Number(performance.memory.jsHeapSizeLimit || 0),
        total_js_heap_size: Number(performance.memory.totalJSHeapSize || 0),
        used_js_heap_size: Number(performance.memory.usedJSHeapSize || 0),
    } : null;
    const connection = navigator.connection ? {
        effective_type: navigator.connection.effectiveType || '',
        downlink_mbps: Number(navigator.connection.downlink || 0),
        rtt_ms: Number(navigator.connection.rtt || 0),
        save_data: Boolean(navigator.connection.saveData),
    } : null;
    return {
        recorded_at: new Date().toISOString(),
        user_agent: navigator.userAgent,
        platform: navigator.platform || '',
        hardware_concurrency: Number(navigator.hardwareConcurrency || 0),
        device_memory_gib: Number(navigator.deviceMemory || 0),
        connection,
        memory,
        visibility_state: document.visibilityState,
        document_ready_state: document.readyState,
        dom_element_count: document.getElementsByTagName('*').length,
        viewport: {
            width: window.innerWidth,
            height: window.innerHeight,
            device_pixel_ratio: window.devicePixelRatio,
        },
    };
}

/**
 * Calculate peak overlap for a set of browser resource intervals.
 *
 * @param {Array<Record<string,*>>} entries Serialized Resource Timing rows.
 * @returns {number} Maximum number of overlapping resource requests.
 */
function resourcePeakConcurrency(entries) {
    const events = [];
    entries.forEach((entry) => {
        const start = Number(entry.startTime || 0);
        const end = Number(entry.responseEnd || (start + Number(entry.duration || 0)));
        if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) {
            return;
        }
        events.push({at: start, delta: 1});
        events.push({at: end, delta: -1});
    });
    events.sort((left, right) => left.at === right.at ? left.delta - right.delta : left.at - right.at);
    let active = 0;
    let peak = 0;
    events.forEach((event) => {
        active += event.delta;
        peak = Math.max(peak, active);
    });
    return peak;
}

/**
 * Collect the current Navigation/Resource Timing state.
 *
 * @returns {Record<string,*>} Browser performance snapshot.
 */
function performanceSnapshot() {
    const navigation = performance.getEntriesByType('navigation').map(serializePerformanceEntry);
    const resources = performance.getEntriesByType('resource').map(serializePerformanceEntry);
    const paints = performance.getEntriesByType('paint').map(serializePerformanceEntry);
    const longTasks = performance.getEntriesByType('longtask').map(serializePerformanceEntry);
    const cacheHits = resources.filter((entry) => entry.probable_cache_hit).length;
    const networkResources = resources.filter((entry) => Number(entry.transfer_size || 0) > 0);
    const networkLoads = networkResources.length;
    const initiatorCounts = {};
    const protocolCounts = {};
    resources.forEach((entry) => {
        const initiator = String(entry.initiator_type || 'other');
        const protocol = String(entry.next_hop_protocol || 'unknown');
        initiatorCounts[initiator] = (initiatorCounts[initiator] || 0) + 1;
        protocolCounts[protocol] = (protocolCounts[protocol] || 0) + 1;
    });
    const slowest = [...resources]
        .sort((left, right) => Number(right.duration_ms || 0) - Number(left.duration_ms || 0))
        .slice(0, 100);
    const highestTtfb = [...resources]
        .sort((left, right) => Number(right.ttfb_ms || 0) - Number(left.ttfb_ms || 0))
        .slice(0, 100);
    return {
        time_origin_ms: performance.timeOrigin,
        now_ms: performance.now(),
        navigation,
        resources,
        paints,
        long_tasks: longTasks,
        resource_summary: {
            total: resources.length,
            probable_cache_hits: cacheHits,
            network_loads: networkLoads,
            total_transfer_bytes: resources.reduce((sum, entry) => sum + Number(entry.transfer_size || 0), 0),
            total_encoded_bytes: resources.reduce((sum, entry) => sum + Number(entry.encoded_body_size || 0), 0),
            total_decoded_bytes: resources.reduce((sum, entry) => sum + Number(entry.decoded_body_size || 0), 0),
            max_ttfb_ms: resources.reduce((max, entry) => Math.max(max, Number(entry.ttfb_ms || 0)), 0),
            max_queue_or_stall_ms: resources.reduce((max, entry) => Math.max(max, Number(entry.queue_or_stall_ms || 0)), 0),
            peak_all_resource_concurrency: resourcePeakConcurrency(resources),
            peak_network_resource_concurrency: resourcePeakConcurrency(networkResources),
            by_initiator_type: initiatorCounts,
            by_protocol: protocolCounts,
            slowest_resources: slowest,
            highest_ttfb_resources: highestTtfb,
        },
    };
}

/**
 * Return the first real lightbox metadata endpoint rendered on this page, if present.
 *
 * @returns {string} Endpoint URL or empty string.
 */
function lightboxProbeUrl() {
    const config = document.querySelector('[data-lightbox-config][data-lightbox-endpoint]');
    if (!config) {
        return '';
    }
    const raw = config.getAttribute('data-lightbox-endpoint') || '';
    if (!raw) {
        return '';
    }
    const url = new URL(raw, window.location.href);
    url.searchParams.set('offset', '0');
    url.searchParams.set('limit', '3');
    url.searchParams.set('test_run_phase', 'lightbox_metadata_probe');
    return url.toString();
}

/**
 * Return the first gallery thumbnail URL visible in markup, if present.
 *
 * @returns {string} Thumbnail URL or empty string.
 */
function firstThumbnailProbeUrl() {
    const image = document.querySelector('img[src*="/thumb-"], img[src*="page=thumb"], img[src*="page=public_thumb"]');
    return image ? String(image.currentSrc || image.src || '') : '';
}

/**
 * Finalize one active Admin test run after the browser load settles.
 *
 * @param {HTMLElement} panel Test-run panel.
 */
async function finalizeTestRun(panel) {
    if (panel.dataset.testRunFinishing === '1') {
        return;
    }
    const token = panel.dataset.testRunToken || '';
    const finishUrl = panel.dataset.finishUrl || '';
    const finalizeUrl = panel.dataset.finalizeUrl || '';
    const probeUrl = panel.dataset.probeUrl || '';
    const staticProbeUrl = panel.dataset.staticProbeUrl || '';
    const csrfToken = panel.dataset.csrfToken || '';
    if (!token || !finishUrl || !finalizeUrl || !probeUrl || !csrfToken) {
        return;
    }
    panel.dataset.testRunFinishing = '1';
    const status = panel.querySelector('[data-admin-test-run-status]');
    if (status) {
        status.textContent = 'Collecting sequential browser and PHP verification probes...';
    }

    // Wait after window.load so late lazy upgrades and telemetry scheduling can settle before verification probes begin.
    await new Promise((resolve) => window.setTimeout(resolve, 1200));

    const before = performanceSnapshot();
    const probes = [];

    if (staticProbeUrl) {
        const staticUrl = new URL(staticProbeUrl, window.location.href);
        staticUrl.searchParams.set('test_run_cache_bust', String(Date.now()));
        probes.push(await runProbe('static_asset_probe', staticUrl.toString(), {cache: 'reload'}));
    }

    const phpUrl = new URL(probeUrl, window.location.href);
    phpUrl.searchParams.set('token', token);
    phpUrl.searchParams.set('test_run_phase', 'php_probe');
    phpUrl.searchParams.set('test_run_cache_bust', String(Date.now()));
    probes.push(await runProbe('php_probe', phpUrl.toString()));

    probes.push(await runProbe('warm_full_render', warmRenderUrl(token), {
        headers: {'X-Gallery-Test-Run-Probe': 'warm-full-render'},
    }));

    const lightboxUrl = lightboxProbeUrl();
    if (lightboxUrl) {
        probes.push(await runProbe('lightbox_metadata_probe', lightboxUrl));
    } else {
        probes.push({name: 'lightbox_metadata_probe', skipped: true, reason: 'No active lightbox endpoint was rendered.'});
    }

    const thumbnailUrl = firstThumbnailProbeUrl();
    if (thumbnailUrl) {
        probes.push(await runProbe('first_thumbnail_probe', thumbnailUrl, {cache: 'reload'}));
    } else {
        probes.push({name: 'first_thumbnail_probe', skipped: true, reason: 'No thumbnail URL was present in current markup.'});
    }

    // Give completed PHP subrequests a brief deterministic interval to run their shutdown recorders before payload submission.
    await new Promise((resolve) => window.setTimeout(resolve, 350));

    const after = performanceSnapshot();
    const payload = {
        diagnostics_version: '20260821-admin-test-run-browser-v1.1.3',
        environment: browserEnvironment(),
        before_probes: before,
        after_probes: after,
        probes,
        runner_observed_max_probe_concurrency: 1,
        runner_parallel_probe_calls: false,
        page: {
            url: sanitizeDiagnosticUrl(window.location.href),
            title: document.title,
            diagnostic_request_id: panel.dataset.currentRequestId || '',
            starter_request_id: panel.dataset.starterRequestId || '',
        },
    };

    const form = new URLSearchParams();
    form.set('csrf_token', csrfToken);
    form.set('token', token);
    form.set('browser_json', JSON.stringify(payload));

    try {
        const storeResponse = await fetch(finishUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: form.toString(),
        });
        const storeResult = await storeResponse.json();
        if (!storeResponse.ok || !storeResult.ok) {
            throw new Error(storeResult.message || storeResult.error || `HTTP ${storeResponse.status}`);
        }

        // The payload request is traced. Let it leave PHP and execute its shutdown observer before report assembly.
        await new Promise((resolve) => window.setTimeout(resolve, 250));
        const finalizeForm = new URLSearchParams();
        finalizeForm.set('csrf_token', csrfToken);
        finalizeForm.set('token', token);
        const response = await fetch(finalizeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: finalizeForm.toString(),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.message || result.error || `HTTP ${response.status}`);
        }
        if (status) {
            const summary = result.summary || {};
            const flags = Array.isArray(summary.analysis_flags) ? summary.analysis_flags.length : 0;
            status.textContent = `Test run complete. PHP requests: ${summary.request_count || 0}; peak concurrency: ${summary.peak_concurrency || 0}; DB queries: ${summary.db_queries || 0}; all requests closed: ${summary.all_closed ? 'yes' : 'no'}; analysis flags: ${flags}.`;
        }
        if (result.download_url) {
            const actions = panel.querySelector('.admin-hero-actions') || panel;
            const link = document.createElement('a');
            link.className = 'button secondary';
            link.href = result.download_url;
            link.textContent = 'Download this test run';
            actions.appendChild(link);
        }
    } catch (error) {
        if (status) {
            status.textContent = `Test run finalization failed: ${String(error && error.message ? error.message : error)}`;
        }
    }
}

/**
 * Initialize the full Admin test-run browser finalizer when an active panel is present.
 */
export function setupAdminTestRun() {
    const panel = document.querySelector('[data-admin-test-run-panel][data-test-run-active="1"]');
    if (!panel) {
        return;
    }
    /** Start the active test-run finalizer after the document is fully loaded. */
    const start = () => finalizeTestRun(panel);
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start, {once: true});
    }
}
