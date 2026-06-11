/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-refresh-progress.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances Admin gallery discovery with Ajax progress feedback.
 *
 * Responsibilities:
 *   - Start gallery discovery without blocking the Admin dashboard
 *   - Process filesystem discovery through small server batches
 *   - Render discovered folder candidates dynamically when the scan is complete
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
 *   2026-06-11
 */

import { escapeHtmlAttribute, escapeHtmlText, i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach Ajax gallery discovery behavior to the dashboard card and discovery page.
 */
export function setupGalleryRefreshProgress() {
    document.querySelectorAll('[data-admin-discovery-launch]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            runAdminGalleryDiscovery(form, {redirectWhenDone: true});
        });
    });

    document.querySelectorAll('[data-admin-discovery-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        runAdminGalleryDiscovery(panel, {redirectWhenDone: false});
    });
}

/**
 * Run the complete Admin gallery discovery workflow.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {{redirectWhenDone: boolean}} options Runtime options.
 */
async function runAdminGalleryDiscovery(container, options) {
    if (container.dataset.discoveryRunning === '1') {
        return;
    }

    const endpoint = discoveryEndpoint(container);
    const csrfToken = discoveryCsrfToken(container);
    if (!endpoint || !csrfToken) {
        setDiscoveryProgress(container, 0, 0, 0, 0, i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.'));
        return;
    }

    container.dataset.discoveryRunning = '1';
    const buttons = Array.from(container.querySelectorAll('button, input[type="submit"]'));
    const originalLabels = new Map();
    buttons.forEach((button) => {
        originalLabels.set(button, 'value' in button && button.tagName === 'INPUT' ? button.value : button.textContent);
        button.disabled = true;
        if ('value' in button && button.tagName === 'INPUT') {
            button.value = i18n('admin.galleries.discovery_button_running', 'Scanning...');
        } else {
            button.textContent = i18n('admin.galleries.discovery_button_running', 'Scanning...');
        }
    });

    let payload = null;
    let redirected = false;
    try {
        const existingToken = container.dataset.jobToken || '';
        payload = existingToken
            ? await postDiscoveryAction(endpoint, csrfToken, 'status', existingToken)
            : await postDiscoveryAction(endpoint, csrfToken, 'start', '');

        setDiscoveryProgressFromPayload(container, payload);
        while (payload && payload.done !== true && payload.status !== 'error' && payload.status !== 'missing') {
            payload = await postDiscoveryAction(endpoint, csrfToken, 'step', String(payload.job_token || ''));
            setDiscoveryProgressFromPayload(container, payload);
        }

        if (!payload || payload.ok === false || payload.status === 'error' || payload.status === 'missing') {
            throw new Error(payload?.message || payload?.error || i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.'));
        }

        if (options.redirectWhenDone) {
            redirected = true;
            window.location.href = payload.result_url || endpoint;
            return;
        }

        renderDiscoveryResults(container, payload);
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.galleries.discovery_failed', 'Gallery discovery failed.');
        setDiscoveryProgress(container, 0, 0, 0, 0, message);
    } finally {
        if (!redirected) {
            buttons.forEach((button) => {
                button.disabled = false;
                const label = originalLabels.get(button) || '';
                if ('value' in button && button.tagName === 'INPUT') {
                    button.value = label;
                } else {
                    button.textContent = label;
                }
            });
            container.dataset.discoveryRunning = '0';
        }
    }
}

/**
 * Return the discovery Ajax endpoint for a form or panel.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {string} Endpoint URL.
 */
function discoveryEndpoint(container) {
    if (container.dataset.discoveryEndpoint) {
        return container.dataset.discoveryEndpoint;
    }
    if (container instanceof HTMLFormElement) {
        return container.action || window.location.href;
    }
    return window.location.href;
}

/**
 * Return the CSRF token for a discovery request.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {string} CSRF token.
 */
function discoveryCsrfToken(container) {
    if (container.dataset.csrfToken) {
        return container.dataset.csrfToken;
    }
    const field = container.querySelector('input[name="csrf_token"]');
    return field instanceof HTMLInputElement ? field.value : '';
}

/**
 * POST one gallery discovery action and parse its JSON response.
 *
 * @param {string} endpoint Ajax endpoint URL.
 * @param {string} csrfToken CSRF token emitted by the server.
 * @param {string} action Discovery action name.
 * @param {string} jobToken Existing job token, when available.
 * @return {Promise<Object<string, *>>} Parsed payload.
 */
async function postDiscoveryAction(endpoint, csrfToken, action, jobToken) {
    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('ajax', '1');
    body.set('action', action);
    body.set('batch_size', '80');
    if (jobToken) {
        body.set('job_token', jobToken);
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {'Accept': 'application/json'},
    });
    if (!response.ok) {
        throw new Error(i18n('admin.galleries.discovery_failed_http', 'Gallery discovery request failed.'));
    }

    try {
        return await response.json();
    } catch (error) {
        throw new Error(i18n('admin.galleries.discovery_failed_json', 'Gallery discovery response was not valid JSON.'));
    }
}

/**
 * Update the discovery progress UI from a server payload.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {Object<string, *> | null} payload Parsed payload.
 */
function setDiscoveryProgressFromPayload(container, payload) {
    const percent = Number(payload?.percent || 0);
    const processed = Number(payload?.processed_directories || 0);
    const total = Number(payload?.discovered_directories || 0);
    const candidates = Number(payload?.candidate_count || 0);
    const message = String(payload?.message || i18n('admin.galleries.discovery_running', 'Scanning gallery folders...'));
    setDiscoveryProgress(container, percent, processed, total, candidates, message);
}

/**
 * Update the discovery progress controls.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @param {number} percent Completion estimate.
 * @param {number} processed Number of scanned directories.
 * @param {number} total Number of discovered directories.
 * @param {number} candidates Number of candidates found so far.
 * @param {string} message Human-readable progress message.
 */
function setDiscoveryProgress(container, percent, processed, total, candidates, message) {
    const progress = ensureDiscoveryProgress(container);
    progress.hidden = false;

    const bar = progress.querySelector('[data-admin-discovery-progress-bar]');
    if (bar instanceof HTMLProgressElement) {
        bar.max = 100;
        bar.value = Math.max(0, Math.min(100, percent));
    }

    const status = progress.querySelector('[data-admin-discovery-status]');
    if (status) {
        status.textContent = message;
    }

    const counts = progress.querySelector('[data-admin-discovery-counts]');
    if (counts) {
        counts.textContent = i18n('admin.galleries.discovery_counts', '{processed} / {total} folder(s) checked, {candidates} candidate(s) found.', {
            processed,
            total,
            candidates,
        });
    }
}

/**
 * Ensure a discovery progress element exists for the supplied container.
 *
 * @param {HTMLElement} container Form or panel that owns the progress UI.
 * @return {HTMLElement} Progress element.
 */
function ensureDiscoveryProgress(container) {
    let progress = container.querySelector('[data-admin-discovery-progress]');
    if (progress instanceof HTMLElement) {
        return progress;
    }

    progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.adminDiscoveryProgress = 'true';
    progress.hidden = true;
    progress.innerHTML = '<progress class="thumbnail-progress-bar" max="100" value="0" data-admin-discovery-progress-bar></progress><p class="muted" data-admin-discovery-status></p><p class="muted" data-admin-discovery-counts></p>';
    container.append(progress);
    return progress;
}

/**
 * Render discovered candidates into the dynamic discovery page.
 *
 * @param {HTMLElement} container Discovery panel.
 * @param {Object<string, *>} payload Completed discovery payload.
 */
function renderDiscoveryResults(container, payload) {
    const target = container.querySelector('[data-admin-discovery-results]');
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const candidates = Array.isArray(payload.candidates) ? payload.candidates : [];
    if (candidates.length === 0) {
        target.innerHTML = `<p>${escapeHtmlText(i18n('admin.galleries.discover_none_found', 'No new gallery folders found.'))}</p>`;
        return;
    }

    const importUrl = container.dataset.importUrl || '';
    const csrfToken = discoveryCsrfToken(container);
    const rows = candidates.map((candidate) => discoveryCandidateRow(candidate)).join('');
    target.innerHTML = `
        <form method="post" action="${escapeHtmlAttribute(importUrl)}" data-import-galleries-form>
            <input type="hidden" name="csrf_token" value="${escapeHtmlAttribute(csrfToken)}">
            <p><label><input type="checkbox" name="create_thumbnails" value="1" checked> ${escapeHtmlText(i18n('admin.galleries.discover_create_thumbnails', 'Create optimized thumbnails during import'))}</label></p>
            <table>
                <thead><tr><th>${escapeHtmlText(i18n('admin.galleries.discover_column_import', 'Import'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_folder', 'Folder'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_title', 'Title'))}</th><th>${escapeHtmlText(i18n('admin.galleries.discover_column_visibility', 'Visibility'))}</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <button type="submit">${escapeHtmlText(i18n('admin.galleries.discover_import_selected', 'Import selected detected galleries'))}</button>
        </form>
    `;
}

/**
 * Render one discovery candidate table row.
 *
 * @param {Object<string, *>} candidate Candidate metadata from the server.
 * @return {string} Safe table row HTML.
 */
function discoveryCandidateRow(candidate) {
    const folderPath = String(candidate?.folder_path || '');
    const title = String(candidate?.title || '');
    const visibility = String(candidate?.visibility || '');
    return `<tr><td><input type="checkbox" name="folders[]" value="${escapeHtmlAttribute(folderPath)}"></td><td>${escapeHtmlText(folderPath)}</td><td>${escapeHtmlText(title)}</td><td>${escapeHtmlText(visibility)}</td></tr>`;
}
