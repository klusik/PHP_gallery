/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-report.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Handles complete Admin gallery overview report generation.
 *
 * Responsibilities:
 *   - Start report generation only after an explicit Admin action
 *   - Process database image rows through browser-driven Ajax batches
 *   - Convert the finished HTML response into a transient browser download
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
 *   2026-06-15
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const reportObjectUrls = new WeakMap();

/**
 * Attach complete gallery overview report behavior.
 */
export function setupAdminGalleryReport() {
    document.querySelectorAll('[data-admin-gallery-report]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        const button = panel.querySelector('[data-admin-gallery-report-button]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        button.addEventListener('click', () => {
            runAdminGalleryReport(panel, button);
        });
    });
}

/**
 * Run a complete gallery overview report job.
 *
 * @param {HTMLElement} panel Report control panel.
 * @param {HTMLButtonElement} button Button that started the job.
 */
async function runAdminGalleryReport(panel, button) {
    const endpoint = panel.dataset.generateUrl || '';
    const csrfToken = panel.dataset.csrfToken || '';
    const telemetryDays = readTelemetryDays(panel);
    if (!endpoint || !csrfToken) {
        updateGalleryReportStatus(panel, i18n('admin.gallery_report.progress_failed', 'Gallery report generation failed.'));
        return;
    }

    revokePreviousReportUrl(panel);
    clearGalleryReportResult(panel);
    const originalLabel = button.textContent || i18n('admin.gallery_report.generate_button', 'Generate complete report');
    button.disabled = true;
    button.textContent = i18n('admin.gallery_report.generate_button_running', 'Generating...');
    setGalleryReportProgress(panel, 0, 0, 0, i18n('admin.gallery_report.progress_starting', 'Preparing database and runtime report.'));

    try {
        let payload = await postGalleryReportAction(endpoint, csrfToken, 'start', telemetryDays);
        setGalleryReportProgressFromPayload(panel, payload, i18n('admin.gallery_report.progress_scanning', 'Processing image database rows and generated media metadata.'));

        while (payload && payload.status === 'running') {
            payload = await postGalleryReportAction(endpoint, csrfToken, 'step', telemetryDays);
            setGalleryReportProgressFromPayload(panel, payload, i18n('admin.gallery_report.progress_scanning', 'Processing image database rows and generated media metadata.'));
        }

        if (!payload || payload.ok === false || payload.status === 'error' || payload.status === 'missing') {
            throw new Error(payload?.error || payload?.message || i18n('admin.gallery_report.progress_failed', 'Gallery report generation failed.'));
        }

        if (payload.status !== 'complete' || typeof payload.report_html !== 'string' || payload.report_html === '') {
            throw new Error(i18n('admin.gallery_report.progress_missing_html', 'Gallery report completed without downloadable HTML.'));
        }

        createGalleryReportDownload(panel, payload.report_html, String(payload.filename || 'php-gallery-complete-overview.html'), Number(payload.report_bytes || 0));
        setGalleryReportProgressFromPayload(panel, payload, i18n('admin.gallery_report.progress_done', 'Complete gallery overview report generated.'));
    } catch (error) {
        setGalleryReportProgress(panel, 0, 0, 0, error instanceof Error ? error.message : i18n('admin.gallery_report.progress_failed', 'Gallery report generation failed.'));
    } finally {
        button.disabled = false;
        button.textContent = originalLabel;
    }
}

/**
 * Read the selected telemetry window.
 *
 * @param {HTMLElement} panel Report control panel.
 * @return {number} Number of days to request.
 */
function readTelemetryDays(panel) {
    const input = panel.querySelector('[data-admin-gallery-report-telemetry-days]');
    const value = input instanceof HTMLSelectElement ? Number(input.value || 30) : 30;
    return Math.max(1, Math.min(3650, Number.isFinite(value) ? value : 30));
}

/**
 * POST one report generation action to the server.
 *
 * @param {string} endpoint Ajax endpoint URL.
 * @param {string} csrfToken CSRF token emitted by the server.
 * @param {string} action Action name.
 * @param {number} telemetryDays Telemetry window in days.
 * @return {Promise<Object<string, *> | null>} Parsed JSON payload.
 */
async function postGalleryReportAction(endpoint, csrfToken, action, telemetryDays) {
    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('action', action);
    body.set('batch_size', '20');
    body.set('telemetry_days', String(telemetryDays));

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {'Accept': 'application/json'},
    });
    if (!response.ok) {
        throw new Error(i18n('admin.gallery_report.progress_failed_http', 'Gallery report request failed.'));
    }

    try {
        return await response.json();
    } catch (error) {
        throw new Error(i18n('admin.gallery_report.progress_failed_json', 'Gallery report response was not valid JSON.'));
    }
}

/**
 * Update progress controls from an Ajax payload.
 *
 * @param {HTMLElement} panel Report control panel.
 * @param {Object<string, *> | null} payload Parsed JSON payload.
 * @param {string} fallbackLabel Fallback label.
 */
function setGalleryReportProgressFromPayload(panel, payload, fallbackLabel) {
    const processed = Number(payload?.processed || 0);
    const total = Number(payload?.total || 0);
    const percent = Number(payload?.percent || (total > 0 ? (processed / total) * 100 : 0));
    const label = String(payload?.message || fallbackLabel);
    setGalleryReportProgress(panel, percent, processed, total, label);
}

/**
 * Update the report generation progress indicator.
 *
 * @param {HTMLElement} panel Report control panel.
 * @param {number} percent Completion percentage.
 * @param {number} processed Number of processed image rows.
 * @param {number} total Total image count.
 * @param {string} label Human-readable state.
 */
function setGalleryReportProgress(panel, percent, processed, total, label) {
    const progress = panel.querySelector('[data-admin-gallery-report-progress]');
    if (progress instanceof HTMLElement) {
        progress.hidden = false;
    }
    const fill = panel.querySelector('[data-admin-gallery-report-progress-fill]');
    if (fill instanceof HTMLElement) {
        fill.style.setProperty('--admin-storage-progress', `${Math.max(0, Math.min(100, percent)).toFixed(1)}%`);
    }
    const labelTarget = panel.querySelector('[data-admin-gallery-report-progress-label]');
    if (labelTarget) {
        labelTarget.textContent = label;
    }
    const countTarget = panel.querySelector('[data-admin-gallery-report-progress-count]');
    if (countTarget) {
        countTarget.textContent = i18n('admin.gallery_report.progress_counts', '{processed} / {total} image database row(s)', {
            processed,
            total,
        });
    }
    updateGalleryReportStatus(panel, label);
}

/**
 * Update the report generation status line.
 *
 * @param {HTMLElement} panel Report control panel.
 * @param {string} label Human-readable state.
 */
function updateGalleryReportStatus(panel, label) {
    const status = panel.querySelector('[data-admin-gallery-report-status]');
    if (status) {
        status.textContent = label;
    }
}

/**
 * Remove the previous generated download link.
 *
 * @param {HTMLElement} panel Report control panel.
 */
function clearGalleryReportResult(panel) {
    const result = panel.querySelector('[data-admin-gallery-report-result]');
    if (result instanceof HTMLElement) {
        result.hidden = true;
        result.classList.remove('is-ready');
        result.replaceChildren();
    }
}

/**
 * Build a browser-owned download for the generated HTML report.
 *
 * @param {HTMLElement} panel Report control panel.
 * @param {string} html Complete report HTML.
 * @param {string} filename Download filename.
 * @param {number} serverByteCount Server-side byte count.
 */
function createGalleryReportDownload(panel, html, filename, serverByteCount) {
    const blob = new Blob([html], {type: 'text/html;charset=utf-8'});
    const objectUrl = URL.createObjectURL(blob);
    reportObjectUrls.set(panel, objectUrl);

    const result = panel.querySelector('[data-admin-gallery-report-result]');
    if (!(result instanceof HTMLElement)) {
        return;
    }

    const title = document.createElement('strong');
    title.textContent = i18n('admin.gallery_report.download_ready', 'Report ready');

    const description = document.createElement('span');
    description.textContent = i18n('admin.gallery_report.download_ready_hint', 'The generated HTML report is available as a browser download. Nothing was saved on the server.');

    const link = document.createElement('a');
    link.className = 'button';
    link.href = objectUrl;
    link.download = filename;
    link.textContent = i18n('admin.gallery_report.download_button', 'Download generated HTML');

    const meta = document.createElement('small');
    meta.className = 'muted';
    meta.textContent = i18n('admin.gallery_report.download_meta', '{filename}, {size}', {
        filename,
        size: formatBytes(serverByteCount > 0 ? serverByteCount : blob.size),
    });

    result.replaceChildren(title, description, link, meta);
    result.hidden = false;
    result.classList.add('is-ready');
    link.click();
}

/**
 * Revoke the previous object URL for this panel.
 *
 * @param {HTMLElement} panel Report control panel.
 */
function revokePreviousReportUrl(panel) {
    const previousUrl = reportObjectUrls.get(panel);
    if (previousUrl) {
        URL.revokeObjectURL(previousUrl);
        reportObjectUrls.delete(panel);
    }
}

/**
 * Format a byte count for browser status text.
 *
 * @param {number} bytes Byte count.
 * @return {string} Human-readable size.
 */
function formatBytes(bytes) {
    const value = Math.max(0, Number.isFinite(bytes) ? bytes : 0);
    if (value < 1024) {
        return `${value.toFixed(0)} B`;
    }
    const units = ['KB', 'MB', 'GB', 'TB'];
    let current = value / 1024;
    let index = 0;
    while (current >= 1024 && index < units.length - 1) {
        current /= 1024;
        index += 1;
    }
    return `${current.toFixed(current >= 10 ? 1 : 2)} ${units[index]}`;
}
