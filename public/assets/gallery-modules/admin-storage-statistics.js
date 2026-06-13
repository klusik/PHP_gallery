/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-storage-statistics.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Handles manual Admin storage statistics updates.
 *
 * Responsibilities:
 *   - Start storage statistics jobs only after an explicit admin action
 *   - Process filesystem checks through small Ajax batches
 *   - Keep the progress indicator live while generated media files are scanned
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

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach manual storage statistics update behavior.
 */
export function setupAdminStorageStatistics() {
    document.querySelectorAll('[data-admin-storage-statistics]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        const button = panel.querySelector('[data-admin-storage-update-button]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            runStorageStatisticsUpdate(panel, button);
        });
    });
}

/**
 * Run a complete manual storage statistics job.
 *
 * @param {HTMLElement} panel Storage statistics control panel.
 * @param {HTMLButtonElement} button Button that started the job.
 */
async function runStorageStatisticsUpdate(panel, button) {
    const endpoint = panel.dataset.updateUrl || '';
    const csrfToken = panel.dataset.csrfToken || '';
    if (!endpoint || !csrfToken) {
        updateStorageStatus(panel, i18n('admin.storage.progress_failed', 'Storage statistics update failed.'));
        return;
    }

    const originalLabel = button.textContent || i18n('admin.storage.update_button', 'Update statistics');
    button.disabled = true;
    button.textContent = i18n('admin.storage.update_button_running', 'Updating...');
    setStorageProgress(panel, 0, 0, 0, i18n('admin.storage.progress_starting', 'Preparing statistics scan.'));

    try {
        let payload = await postStorageStatisticsAction(endpoint, csrfToken, 'start');
        setStorageProgressFromPayload(panel, payload, i18n('admin.storage.progress_scanning', 'Scanning generated media files.'));

        while (payload && payload.status === 'running') {
            payload = await postStorageStatisticsAction(endpoint, csrfToken, 'step');
            setStorageProgressFromPayload(panel, payload, i18n('admin.storage.progress_scanning', 'Scanning generated media files.'));
        }

        if (!payload || payload.ok === false || payload.status === 'error' || payload.status === 'stale') {
            throw new Error(payload?.error || payload?.message || i18n('admin.storage.progress_failed', 'Storage statistics update failed.'));
        }

        if (payload.html) {
            const results = document.querySelector('[data-admin-storage-results]');
            if (results) {
                results.innerHTML = payload.html;
            }
        }
        setStorageProgressFromPayload(panel, payload, i18n('admin.storage.progress_done', 'Storage statistics updated.'));
        if (payload.status_text) {
            updateStorageStatus(panel, payload.status_text);
        }
    } catch (error) {
        setStorageProgress(panel, 0, 0, 0, error instanceof Error ? error.message : i18n('admin.storage.progress_failed', 'Storage statistics update failed.'));
    } finally {
        button.disabled = false;
        button.textContent = originalLabel;
    }
}

/**
 * POST one storage statistics action to the server.
 *
 * @param {string} endpoint Ajax endpoint URL.
 * @param {string} csrfToken CSRF token emitted by the server.
 * @param {string} action Action name.
 * @return {Promise<Object<string, *> | null>} Parsed JSON payload.
 */
async function postStorageStatisticsAction(endpoint, csrfToken, action) {
    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('action', action);
    body.set('batch_size', '20');

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {'Accept': 'application/json'},
    });
    if (!response.ok) {
        throw new Error(i18n('admin.storage.progress_failed_http', 'Storage statistics request failed.'));
    }

    try {
        return await response.json();
    } catch (error) {
        throw new Error(i18n('admin.storage.progress_failed_json', 'Storage statistics response was not valid JSON.'));
    }
}

/**
 * Update progress controls from an Ajax payload.
 *
 * @param {HTMLElement} panel Storage statistics control panel.
 * @param {Object<string, *> | null} payload Parsed JSON payload.
 * @param {string} fallbackLabel Fallback label.
 */
function setStorageProgressFromPayload(panel, payload, fallbackLabel) {
    const processed = Number(payload?.processed || 0);
    const total = Number(payload?.total || 0);
    const percent = Number(payload?.percent || (total > 0 ? (processed / total) * 100 : 0));
    const label = String(payload?.message || fallbackLabel);
    setStorageProgress(panel, percent, processed, total, label);
}

/**
 * Update the storage statistics progress indicator.
 *
 * @param {HTMLElement} panel Storage statistics control panel.
 * @param {number} percent Completion percentage.
 * @param {number} processed Number of processed images.
 * @param {number} total Total image count.
 * @param {string} label Human-readable state.
 */
function setStorageProgress(panel, percent, processed, total, label) {
    const progress = panel.querySelector('[data-admin-storage-progress]');
    if (progress instanceof HTMLElement) {
        progress.hidden = false;
    }
    const fill = panel.querySelector('[data-admin-storage-progress-fill]');
    if (fill instanceof HTMLElement) {
        fill.style.setProperty('--admin-storage-progress', `${Math.max(0, Math.min(100, percent)).toFixed(1)}%`);
    }
    const labelTarget = panel.querySelector('[data-admin-storage-progress-label]');
    if (labelTarget) {
        labelTarget.textContent = label;
    }
    const countTarget = panel.querySelector('[data-admin-storage-progress-count]');
    if (countTarget) {
        countTarget.textContent = i18n('admin.storage.progress_counts', '{processed} / {total} image(s)', {
            processed,
            total,
        });
    }
    updateStorageStatus(panel, label);
}

/**
 * Update the storage statistics status line.
 *
 * @param {HTMLElement} panel Storage statistics control panel.
 * @param {string} label Human-readable state.
 */
function updateStorageStatus(panel, label) {
    const status = panel.querySelector('[data-admin-storage-status]');
    if (status) {
        status.textContent = label;
    }
}
