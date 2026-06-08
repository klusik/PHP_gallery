/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/thumbnail-warmup.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Starts guarded background thumbnail repair for images that rendered through /media fallback URLs.
 *
 * Responsibilities:
 *   - Find server-rendered thumbnail warmup candidates
 *   - Send only signed candidate ids back to the warmup endpoint
 *   - Process candidates slowly so public browsing stays responsive
 *   - Avoid retry storms when the server is busy or the page is hidden
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
 *   2026-06-08
 */

const MAX_ITEMS_PER_BROWSER_REQUEST = 2;
const MAX_BROWSER_REQUESTS_PER_PAGE = 4;
const REQUEST_DELAY_MS = 1800;

/**
 * Parse a comma separated thumbnail size attribute into a compact integer array.
 *
 * @param {string} value Raw data-thumbnail-warmup-sizes attribute value.
 * @returns {number[]} Parsed thumbnail sizes.
 */
function parseWarmupSizes(value) {
    return value
        .split(',')
        .map((part) => Number.parseInt(part.trim(), 10))
        .filter((size, index, sizes) => Number.isFinite(size) && size > 0 && sizes.indexOf(size) === index);
}

/**
 * Return unique signed warmup candidates from the current document.
 *
 * @returns {{endpoint: string, items: Array<{id: number, token: string, sizes: number[]}>}|null} Warmup payload or null when nothing is pending.
 */
function collectWarmupCandidates() {
    const nodes = Array.from(document.querySelectorAll('img[data-thumbnail-warmup-id][data-thumbnail-warmup-token][data-thumbnail-warmup-endpoint]'));
    if (!nodes.length) {
        return null;
    }

    const byId = new Map();
    let endpoint = '';

    nodes.forEach((node) => {
        const id = Number.parseInt(node.getAttribute('data-thumbnail-warmup-id') || '', 10);
        const token = node.getAttribute('data-thumbnail-warmup-token') || '';
        const nodeEndpoint = node.getAttribute('data-thumbnail-warmup-endpoint') || '';
        const sizes = parseWarmupSizes(node.getAttribute('data-thumbnail-warmup-sizes') || '300');

        if (!Number.isFinite(id) || id <= 0 || token === '' || nodeEndpoint === '') {
            return;
        }
        if (endpoint === '') {
            endpoint = nodeEndpoint;
        }
        if (nodeEndpoint !== endpoint) {
            return;
        }

        if (!byId.has(id)) {
            byId.set(id, {id, token, sizes});
            return;
        }

        const existing = byId.get(id);
        sizes.forEach((size) => {
            if (!existing.sizes.includes(size)) {
                existing.sizes.push(size);
            }
        });
    });

    const items = Array.from(byId.values()).slice(0, MAX_ITEMS_PER_BROWSER_REQUEST * MAX_BROWSER_REQUESTS_PER_PAGE);
    if (endpoint === '' || !items.length) {
        return null;
    }

    return {endpoint, items};
}

/**
 * Wait until the browser is idle enough to start non-critical warmup work.
 *
 * @param {() => void} callback Callback to run after idle or timeout.
 * @returns {void}
 */
function runWhenIdle(callback) {
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(callback, {timeout: 3500});
        return;
    }
    window.setTimeout(callback, 1200);
}

/**
 * Send one small warmup request to the server.
 *
 * @param {string} endpoint Warmup endpoint URL.
 * @param {Array<{id: number, token: string, sizes: number[]}>} items Candidate items for this request.
 * @returns {Promise<object|null>} Parsed JSON response or null when the request failed.
 */
async function sendWarmupRequest(endpoint, items) {
    const form = new URLSearchParams();
    form.set('items', JSON.stringify(items));

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            body: form,
            headers: {'Accept': 'application/json'},
            credentials: 'same-origin',
            keepalive: true,
        });
        if (!response.ok) {
            return null;
        }
        return await response.json();
    } catch (error) {
        return null;
    }
}

/**
 * Start a slow, guarded warmup queue for thumbnail fallbacks rendered on the page.
 *
 * @returns {void}
 */
export function setupThumbnailWarmup() {
    const payload = collectWarmupCandidates();
    if (!payload) {
        return;
    }

    let requestCount = 0;
    let offset = 0;

    const runNextRequest = async () => {
        if (document.visibilityState === 'hidden' || requestCount >= MAX_BROWSER_REQUESTS_PER_PAGE || offset >= payload.items.length) {
            return;
        }

        const batch = payload.items.slice(offset, offset + MAX_ITEMS_PER_BROWSER_REQUEST);
        if (!batch.length) {
            return;
        }

        requestCount += 1;
        offset += batch.length;

        const result = await sendWarmupRequest(payload.endpoint, batch);
        if (!result || result.ok === false || result.enabled === false) {
            return;
        }
        if (result.busy === true) {
            offset -= batch.length;
        }
        if (offset < payload.items.length && requestCount < MAX_BROWSER_REQUESTS_PER_PAGE) {
            window.setTimeout(runNextRequest, REQUEST_DELAY_MS);
        }
    };

    runWhenIdle(() => {
        window.setTimeout(runNextRequest, REQUEST_DELAY_MS);
    });
}
