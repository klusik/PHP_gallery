/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/public-thumbnail-render-diagnostics.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Adds copy-friendly browser measurements to the existing admin-only public render profile under a gallery.
 *
 * Responsibilities:
 *   - Inspect only selected-gallery thumbnails marked by the permanent renderer service
 *   - Report the effective responsive or progressive renderer selected by PHP
 *   - Measure rendered card widths, viewport shape, DPR, loaded-card state, and browser-selected candidate widths
 *   - Summarize matching Resource Timing entries without fetching any additional media
 *   - Report progressive observer, queue, concurrency, and upgrade counters when that renderer is active
 *   - Keep the diagnostic text updated as scrolling or later progressive image requests change the sample
 *   - Provide a one-click copy action for comparing two gallery loads outside DevTools
 *
 * Integration points:
 *   - app/services/public_render_profiler.php renders the admin-only diagnostic container and copy labels
 *   - app/services/public_thumbnail_rendering.php marks selected-gallery photo images with the effective mode
 *   - gallery.js and public-gallery.js load this module only when the Admin diagnostic container exists
 *   - progressive-thumbnail-renderer.js exposes aggregate scheduler state for progressive-mode reports
 *
 * Lifecycle:
 *   setupPublicThumbnailRenderDiagnostics() binds once per rendered panel, samples immediately, then refreshes after
 *   load, resize, scrolling, and matching PerformanceObserver resource events. Short delayed samples capture image work
 *   that finishes just after first paint. The module performs no network requests of its own.
 *
 * Invariants:
 *   - Diagnostics never alter src, srcset, sizes, loading, fetchpriority, observer eligibility, or renderer queues
 *   - Only images carrying data-public-thumbnail-rendering-mode are included
 *   - Resource matching is restricted to URLs already present in those server-rendered picture elements
 *   - Missing Resource Timing byte fields are reported honestly instead of being inferred
 *   - The report is admin-only because its containing render profile is admin-only
 *
 * Fallback behavior:
 *   Browsers without PerformanceObserver still receive a useful snapshot from performance.getEntriesByType(). Missing
 *   clipboard APIs fall back to selecting and copying the readonly report textarea. If Resource Timing byte sizes are
 *   unavailable or cached, the report explicitly exposes zero/unknown values rather than claiming transferred bytes.
 *
 * Accessibility:
 *   The report uses a readonly textarea with an associated server-rendered heading and a normal button. Updates do not
 *   steal focus or announce continuously. Copy feedback is limited to the button label after direct user activation.
 *
 * No-JavaScript behavior:
 *   The normal server render profile remains visible. Only the live browser measurement text and copy helper remain
 *   unavailable; neither public thumbnail renderer depends on this diagnostic module.
 *
 * Performance rationale:
 *   Measurements reuse DOM state and the browser Performance API. Updates are debounced, resource observation is
 *   passive, and no thumbnails are prefetched or decoded for diagnostics, so comparison does not perturb the renderer.
 *
 * Naming:
 *   Responsive and progressive remain permanent renderer names. Any Beta maturity wording belongs only to the Admin
 *   renderer selection UI and is intentionally absent from this module's identifiers and report schema.
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
 *   2026-08-09
 */

const PUBLIC_THUMBNAIL_DIAGNOSTIC_SELECTOR = 'img[data-public-thumbnail-rendering-mode]';
const PUBLIC_THUMBNAIL_DIAGNOSTIC_REFRESH_MS = 120;
const PUBLIC_THUMBNAIL_PROGRESSIVE_MODULE_URL = './progressive-thumbnail-renderer.js?v=20260809-progressive-thumbnail-renderer';

/** Resolve one possibly relative media URL into the absolute form used by Resource Timing. */
function publicThumbnailDiagnosticAbsoluteUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return '';
    }
    try {
        return new URL(raw, document.baseURI).href;
    } catch (_) {
        return '';
    }
}

/** Parse width-descriptor candidates from one srcset string. */
export function parsePublicThumbnailDiagnosticSrcset(srcset) {
    return String(srcset || '')
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => {
            const match = part.match(/^(.*)\s+(\d+)w$/);
            if (!match) {
                return null;
            }
            const width = Number.parseInt(match[2], 10);
            return width > 0 ? {url: match[1].trim(), width} : null;
        })
        .filter(Boolean);
}

/** Return a compact human-readable byte count without pretending unavailable Resource Timing bytes are known. */
export function formatPublicThumbnailDiagnosticBytes(bytes) {
    const value = Math.max(0, Number(bytes) || 0);
    if (value < 1024) {
        return `${Math.round(value)} B`;
    }
    if (value < 1024 * 1024) {
        return `${(value / 1024).toFixed(1)} KiB`;
    }
    return `${(value / (1024 * 1024)).toFixed(2)} MiB`;
}

/** Collect every candidate URL and width descriptor already exposed by selected-gallery picture markup. */
function publicThumbnailDiagnosticCandidateMap(images) {
    const widthsByUrl = new Map();
    images.forEach((image) => {
        const picture = image.closest('picture');
        const elements = [...(picture?.querySelectorAll('source') || []), image];
        elements.forEach((element) => {
            ['srcset', 'data-progressive-srcset'].forEach((attribute) => {
                parsePublicThumbnailDiagnosticSrcset(element.getAttribute(attribute) || '').forEach((candidate) => {
                    const absoluteUrl = publicThumbnailDiagnosticAbsoluteUrl(candidate.url);
                    if (absoluteUrl) {
                        widthsByUrl.set(absoluteUrl, candidate.width);
                    }
                });
            });
            if (element === image) {
                const absoluteSrc = publicThumbnailDiagnosticAbsoluteUrl(image.getAttribute('src') || '');
                if (absoluteSrc && !widthsByUrl.has(absoluteSrc)) {
                    widthsByUrl.set(absoluteSrc, 0);
                }
            }
        });
    });
    return widthsByUrl;
}

/** Count numeric values and return a width-sorted report fragment. */
function publicThumbnailDiagnosticWidthHistogram(widths) {
    const counts = new Map();
    widths.forEach((width) => {
        const normalized = Math.max(0, Number.parseInt(String(width || 0), 10) || 0);
        const key = normalized > 0 ? normalized : 0;
        counts.set(key, (counts.get(key) || 0) + 1);
    });
    if (!counts.size) {
        return 'none';
    }
    return Array.from(counts.entries())
        .sort((left, right) => left[0] - right[0])
        .map(([width, count]) => `${width > 0 ? `${width}px` : 'unknown'}=${count}`)
        .join(', ');
}

/** Return min/average/max CSS card widths for the currently rendered selected-gallery thumbnails. */
function publicThumbnailDiagnosticCardWidths(images) {
    const widths = images
        .map((image) => {
            const card = image.closest('.image-stage') || image.closest('.image-card') || image;
            return Number(card.getBoundingClientRect().width || image.getBoundingClientRect().width || 0);
        })
        .filter((width) => Number.isFinite(width) && width > 0);
    if (!widths.length) {
        return 'unknown';
    }
    const total = widths.reduce((sum, width) => sum + width, 0);
    return `${Math.min(...widths).toFixed(1)} / ${(total / widths.length).toFixed(1)} / ${Math.max(...widths).toFixed(1)} CSS px`;
}

/** Read the current browser-selected URL width for each marked thumbnail without causing any additional image work. */
function publicThumbnailDiagnosticCurrentWidths(images, widthsByUrl) {
    return images.map((image) => {
        const currentUrl = publicThumbnailDiagnosticAbsoluteUrl(image.currentSrc || image.getAttribute('src') || '');
        if (currentUrl && widthsByUrl.has(currentUrl)) {
            return widthsByUrl.get(currentUrl);
        }
        return Math.max(0, Number.parseInt(image.getAttribute('data-progressive-active-width') || '0', 10) || 0);
    });
}

/** Return Resource Timing entries that correspond only to candidate URLs already present in selected-gallery cards. */
function publicThumbnailDiagnosticResourceEntries(widthsByUrl) {
    if (!('performance' in window) || typeof performance.getEntriesByType !== 'function') {
        return [];
    }
    return performance.getEntriesByType('resource').filter((entry) => widthsByUrl.has(String(entry.name || '')));
}

/** Load aggregate progressive scheduler diagnostics without starting or reinitializing the renderer. */
async function publicThumbnailDiagnosticProgressiveState(mode) {
    if (mode !== 'progressive') {
        return null;
    }
    try {
        const module = await import(PUBLIC_THUMBNAIL_PROGRESSIVE_MODULE_URL);
        return typeof module.progressiveThumbnailRendererDiagnostics === 'function'
            ? module.progressiveThumbnailRendererDiagnostics()
            : null;
    } catch (_) {
        return null;
    }
}

/** Build the full copy-friendly comparison report from current DOM and Resource Timing state. */
async function publicThumbnailDiagnosticReport(panel) {
    const mode = String(panel.dataset.thumbnailRenderingMode || 'unknown');
    const serverTotalMs = Number(panel.dataset.serverTotalMs || 0);
    const images = Array.from(document.querySelectorAll(PUBLIC_THUMBNAIL_DIAGNOSTIC_SELECTOR));
    const widthsByUrl = publicThumbnailDiagnosticCandidateMap(images);
    const resourceEntries = publicThumbnailDiagnosticResourceEntries(widthsByUrl);
    const resourceWidths = resourceEntries.map((entry) => widthsByUrl.get(String(entry.name || '')) || 0);
    const currentWidths = publicThumbnailDiagnosticCurrentWidths(images, widthsByUrl);
    const uniqueRequestedUrls = new Set(resourceEntries.map((entry) => String(entry.name || '')));
    const duplicateRequestEntries = Math.max(0, resourceEntries.length - uniqueRequestedUrls.size);
    const transferSize = resourceEntries.reduce((sum, entry) => sum + Math.max(0, Number(entry.transferSize) || 0), 0);
    const encodedBodySize = resourceEntries.reduce((sum, entry) => sum + Math.max(0, Number(entry.encodedBodySize) || 0), 0);
    const firstStartMs = resourceEntries.length ? Math.min(...resourceEntries.map((entry) => Number(entry.startTime) || 0)) : 0;
    const lastResponseMs = resourceEntries.length ? Math.max(...resourceEntries.map((entry) => Number(entry.responseEnd) || 0)) : 0;
    const firstSecondEntries = resourceEntries.filter((entry) => (Number(entry.startTime) || 0) <= 1000);
    const knownCandidateWidths = Array.from(widthsByUrl.values()).filter((width) => width > 0);
    const smallestCandidateWidth = knownCandidateWidths.length ? Math.min(...knownCandidateWidths) : 300;
    const largeFirstSecondEntries = firstSecondEntries.filter((entry) => {
        const width = widthsByUrl.get(String(entry.name || '')) || 0;
        return width > smallestCandidateWidth;
    });
    const loadedImages = images.filter((image) => image.complete && image.naturalWidth > 0).length;
    const progressive = await publicThumbnailDiagnosticProgressiveState(mode);
    const lines = [
        'PHP Gallery thumbnail renderer diagnostics',
        `Renderer mode: ${mode}`,
        `Gallery id: ${panel.dataset.galleryId || 'unknown'}`,
        `PHP render total: ${serverTotalMs > 0 ? `${serverTotalMs.toFixed(2)} ms` : 'unknown'}`,
        `Navigation sample time: ${performance.now().toFixed(1)} ms`,
        `Viewport: ${window.innerWidth} x ${window.innerHeight} CSS px`,
        `Device pixel ratio: ${(Number(window.devicePixelRatio) || 1).toFixed(2)}`,
        `Selected-gallery photo cards: ${images.length}`,
        `Loaded marked thumbnails: ${loadedImages} / ${images.length}`,
        `Card width min / avg / max: ${publicThumbnailDiagnosticCardWidths(images)}`,
        `Current browser-selected candidate widths: ${publicThumbnailDiagnosticWidthHistogram(currentWidths)}`,
        `Known candidate URLs in markup: ${widthsByUrl.size}`,
        `Thumbnail resource entries: ${resourceEntries.length}`,
        `Unique thumbnail URLs requested: ${uniqueRequestedUrls.size}`,
        `Duplicate thumbnail URL entries: ${duplicateRequestEntries}`,
        `Requested candidate widths: ${publicThumbnailDiagnosticWidthHistogram(resourceWidths)}`,
        `Known transferSize total: ${formatPublicThumbnailDiagnosticBytes(transferSize)}`,
        `Known encodedBodySize total: ${formatPublicThumbnailDiagnosticBytes(encodedBodySize)}`,
        `First thumbnail request start: ${resourceEntries.length ? `${firstStartMs.toFixed(1)} ms` : 'none'}`,
        `Last thumbnail response end: ${resourceEntries.length ? `${lastResponseMs.toFixed(1)} ms` : 'none'}`,
        `Thumbnail requests started within 1 s: ${firstSecondEntries.length}`,
        `Larger-than-small requests started within 1 s: ${largeFirstSecondEntries.length}`,
    ];

    if (progressive) {
        lines.push(
            `Progressive renderer active: ${progressive.enabled ? 'yes' : 'no'}`,
            `Progressive concurrency limit: ${progressive.concurrencyLimit}`,
            `Progressive registered / near / visible: ${progressive.registered} / ${progressive.near} / ${progressive.visible}`,
            `Progressive waiting-small / idle / queued / active: ${progressive.waitingForSmallLoad} / ${progressive.idleScheduled} / ${progressive.queuePending} / ${progressive.queueActive}`,
            `Progressive upgrade attempts / upgraded / no-op / failed: ${progressive.upgradeAttempts} / ${progressive.upgraded} / ${progressive.noUpgrade} / ${progressive.failed}`,
            `Progressive max simultaneous upgrades observed: ${progressive.maxActiveUpgrades}`
        );
    } else {
        lines.push('Progressive scheduler: not active for this renderer');
    }

    lines.push(
        '',
        'Note: transferSize can be 0 for memory/disk cache hits. Compare modes after the same cache-clearing/reload procedure.'
    );
    return lines.join('\n');
}

/** Copy the current readonly report with a legacy selection fallback for restricted clipboard contexts. */
async function copyPublicThumbnailDiagnosticReport(textarea) {
    const value = textarea.value;
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return;
    }
    textarea.focus();
    textarea.select();
    document.execCommand('copy');
    textarea.setSelectionRange(0, 0);
}

/** Initialize the live admin-only thumbnail renderer comparison report when its server-rendered panel is present. */
export function setupPublicThumbnailRenderDiagnostics() {
    const panel = document.querySelector('[data-public-thumbnail-diagnostics]');
    if (!panel || panel.dataset.diagnosticsBound === '1') {
        return;
    }
    panel.dataset.diagnosticsBound = '1';

    // Increase the admin-only Resource Timing buffer before progressive scrolling can add many thumbnail entries.
    // This does not start requests; it only reduces the chance that a long gallery evicts early measurements.
    if (typeof performance?.setResourceTimingBufferSize === 'function') {
        const markedImageCount = document.querySelectorAll(PUBLIC_THUMBNAIL_DIAGNOSTIC_SELECTOR).length;
        performance.setResourceTimingBufferSize(Math.max(500, (markedImageCount * 4) + 100));
    }

    const textarea = panel.querySelector('[data-public-thumbnail-diagnostics-report]');
    const copyButton = panel.querySelector('[data-public-thumbnail-diagnostics-copy]');
    if (!(textarea instanceof HTMLTextAreaElement)) {
        return;
    }

    let refreshHandle = 0;
    /** Debounce DOM and Performance API sampling so scroll events never create synchronous measurement storms. */
    const scheduleRefresh = () => {
        window.clearTimeout(refreshHandle);
        refreshHandle = window.setTimeout(async () => {
            textarea.value = await publicThumbnailDiagnosticReport(panel);
        }, PUBLIC_THUMBNAIL_DIAGNOSTIC_REFRESH_MS);
    };

    copyButton?.addEventListener('click', async () => {
        try {
            textarea.value = await publicThumbnailDiagnosticReport(panel);
            await copyPublicThumbnailDiagnosticReport(textarea);
            const original = copyButton.dataset.copyLabel || copyButton.textContent || 'Copy report';
            copyButton.textContent = copyButton.dataset.copiedLabel || 'Copied';
            window.setTimeout(() => {
                copyButton.textContent = original;
            }, 1400);
        } catch (_) {
            textarea.focus();
            textarea.select();
        }
    });

    window.addEventListener('load', scheduleRefresh, {once: true});
    window.addEventListener('resize', scheduleRefresh, {passive: true});
    window.addEventListener('scroll', scheduleRefresh, {passive: true});

    if ('PerformanceObserver' in window) {
        try {
            const observer = new PerformanceObserver(scheduleRefresh);
            observer.observe({type: 'resource', buffered: true});
        } catch (_) {
        }
    }

    scheduleRefresh();
    window.setTimeout(scheduleRefresh, 350);
    window.setTimeout(scheduleRefresh, 1200);
    window.setTimeout(scheduleRefresh, 3200);
}
