/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-storage-statistics.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Runs the shared storage statistics refresh workflow from any storage tab.
 *
 * Responsibilities:
 *   - Scan file storage through bounded browser-driven batches
 *   - Refresh database size estimates and run read-only database inspection
 *   - Preserve the current tab and URL while replacing only its refreshed content
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
 *   - Limit database work to bounded ANALYZE batches and read-only inspection.
 *   - Never launch cleanup, schema repair, or OPTIMIZE from this workflow.
 *
 * Last Updated:
 *   2026-10-04
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach the shared storage refresh action to the active storage page.
 *
 * @return {void}
 */
export function setupAdminStorageStatistics() {
    document.querySelectorAll('[data-admin-storage-statistics]').forEach((panel) => {
        if (!(panel instanceof HTMLElement) || panel.dataset.storageHandlerBound === 'true') {
            return;
        }
        const button = panel.querySelector('[data-admin-storage-update-button]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        panel.dataset.storageHandlerBound = 'true';
        button.addEventListener('click', () => {
            runStorageRefresh(panel, button);
        });
    });
}

/**
 * Run file scanning, database metadata refresh, and read-only inspection.
 *
 * @param {HTMLElement} panel Shared storage refresh toolbar.
 * @param {HTMLButtonElement} button Button that started the workflow.
 * @return {Promise<void>} Resolves after all stages and the active view refresh.
 */
async function runStorageRefresh(panel, button) {
    if (button.disabled) {
        return;
    }
    const endpoint = panel.dataset.updateUrl || '';
    const csrfToken = panel.dataset.csrfToken || '';
    if (!endpoint || !csrfToken) {
        updateStorageStatus(panel, i18n('admin.storage.update_all_failed', 'Storage update failed: required request details are unavailable.'));
        return;
    }

    const buttonLabel = button.querySelector('[data-admin-storage-update-button-label]');
    const originalLabel = buttonLabel?.textContent || i18n('admin.storage.update_all_button', 'Update all');
    button.disabled = true;
    if (buttonLabel) {
        buttonLabel.textContent = i18n('admin.storage.update_all_running', 'Updating all…');
    }
    setStorageProgress(panel, 0, i18n('admin.storage.update_all_starting', 'Preparing the storage update.'));
    let stages = [];

    try {
        let payload = await postStorageRefreshAction(endpoint, csrfToken, 'all_start');
        let workflowId = String(payload.workflow_id || '');
        if (!/^[a-f0-9]{32}$/.test(workflowId)) {
            throw new Error('workflow');
        }
        stages = normalizeStorageStages(payload.stages);
        updateStorageProgressFromPayload(panel, payload);

        let safetySteps = 0;
        while (payload.workflow_status === 'running') {
            if (++safetySteps > 10000 || !['files', 'database', 'inspection'].includes(payload.phase)) {
                throw new Error('workflow');
            }
            payload = await postStorageRefreshAction(endpoint, csrfToken, 'all_step', workflowId);
            const nextWorkflowId = String(payload.workflow_id || '');
            if (!/^[a-f0-9]{32}$/.test(nextWorkflowId)) {
                throw new Error('workflow');
            }
            workflowId = nextWorkflowId;
            stages = normalizeStorageStages(payload.stages, stages);
            updateStorageProgressFromPayload(panel, payload);
        }

        if (payload.workflow_status !== 'complete' && payload.workflow_status !== 'partial') {
            throw new Error('workflow');
        }

        const refreshed = await refreshActiveStorageContent().catch(() => false);
        let terminalStatus = '';
        if (payload.workflow_status === 'partial' || stages.some((stage) => stage.status === 'partial' || stage.status === 'failed')) {
            terminalStatus = formatPartialStorageStatus(stages);
            updateStorageStatus(panel, terminalStatus);
        } else {
            terminalStatus = i18n('admin.storage.update_all_complete', 'Storage data updated.');
            setStorageProgress(panel, 100, terminalStatus);
        }
        if (!refreshed) {
            const refreshFailure = i18n('admin.storage.update_all_view_refresh_failed', 'The update finished, but this view could not be refreshed. Reload the page to see the latest results.');
            updateStorageStatus(panel, `${terminalStatus} ${refreshFailure}`);
        }
    } catch (error) {
        const errorMessage = i18n('admin.storage.update_all_failed', 'Storage update failed. Please retry.');
        setStorageProgress(panel, 0, errorMessage);
        updateStorageStatus(panel, errorMessage);
    } finally {
        button.disabled = false;
        if (buttonLabel) {
            buttonLabel.textContent = originalLabel;
        }
    }
}

/**
 * Send one bounded step of the storage refresh workflow.
 *
 * @param {string} endpoint Workflow endpoint URL.
 * @param {string} csrfToken CSRF token emitted by the server.
 * @param {string} action Workflow action.
 * @param {string} workflowId Opaque server-owned workflow identity for bounded steps.
 * @return {Promise<Object<string, *>>} Validated workflow response.
 */
async function postStorageRefreshAction(endpoint, csrfToken, action, workflowId = '') {
    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('action', action);
    if (workflowId) {
        body.set('workflow_id', workflowId);
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
    });
    if (!response.ok) {
        throw new Error('http');
    }
    let payload;
    try {
        payload = await response.json();
    } catch (error) {
        throw new Error('json');
    }
    if (!payload || payload.ok !== true || typeof payload.workflow_status !== 'string') {
        throw new Error('response');
    }
    if (action === 'all_step' && !/^[a-f0-9]{32}$/.test(String(payload.workflow_id || ''))) {
        throw new Error('workflow');
    }
    return payload;
}

/**
 * Replace only the currently selected storage content from a fresh page response.
 *
 * @return {Promise<boolean>} Whether the current tab content was refreshed.
 */
async function refreshActiveStorageContent() {
    const current = document.querySelector('[data-admin-storage-content]');
    if (!(current instanceof HTMLElement)) {
        return false;
    }
    const response = await fetch(window.location.href, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {'Accept': 'text/html'},
    });
    if (!response.ok) {
        return false;
    }
    const markup = await response.text();
    const documentCopy = new DOMParser().parseFromString(markup, 'text/html');
    const next = documentCopy.querySelector('[data-admin-storage-content]');
    if (!(next instanceof HTMLElement)) {
        return false;
    }
    current.innerHTML = next.innerHTML;
    return true;
}

/** @typedef {{phase: ('files'|'database'|'inspection'), status: ('running'|'complete'|'partial'|'failed'), processed: number, total: number, failed: number}} StorageRefreshStage */

/**
 * Keep only bounded, recognized database refresh stage records.
 *
 * @param {unknown} value Server-provided stage list.
 * @param {Array<StorageRefreshStage>} fallback Previous valid stage list.
 * @return {Array<StorageRefreshStage>} Valid stage records.
 */
function normalizeStorageStages(value, fallback = []) {
    if (!Array.isArray(value)) {
        return fallback;
    }
    const validPhases = new Set(['files', 'database', 'inspection']);
    const validStatuses = new Set(['running', 'complete', 'partial', 'failed']);
    return value.slice(0, 3).filter((stage) => (
        stage && typeof stage === 'object'
        && validPhases.has(stage.phase)
        && validStatuses.has(stage.status)
    ));
}

/**
 * Update progress from a server response without exposing server exception text.
 *
 * @param {HTMLElement} panel Shared storage refresh toolbar.
 * @param {Object<string, *>} payload Server workflow response.
 * @return {void}
 */
function updateStorageProgressFromPayload(panel, payload) {
    const percent = Number(payload.overall_percent || 0);
    const phaseKey = {
        files: 'admin.storage.update_all_files',
        database: 'admin.storage.update_all_database_metadata',
        inspection: 'admin.storage.update_all_inspection',
        complete: 'admin.storage.update_all_complete',
    }[payload.phase] || 'admin.storage.update_all_starting';
    const fallback = {
        files: 'Scanning file storage…',
        database: 'Refreshing database metadata…',
        inspection: 'Inspecting database…',
        complete: 'Storage data updated.',
    }[payload.phase] || 'Preparing the storage update.';
    const message = payload.workflow_status === 'running'
        ? i18n(phaseKey, fallback)
        : (typeof payload.message === 'string' && payload.message !== '' ? payload.message : i18n(phaseKey, fallback));
    const count = ['files', 'database'].includes(payload.phase) && Number(payload.total || 0) > 0
        ? i18n('admin.storage.update_all_count', '{processed} / {total}', {
            processed: Number(payload.processed || 0),
            total: Number(payload.total || 0),
        })
        : '';
    setStorageProgress(panel, percent, message, count);
}

/**
 * Build a localized partial-result message from recognized stage names.
 *
 * @param {Array<Object<string, *>>} stages Validated workflow stage results.
 * @return {string} Safe localized summary of incomplete stages.
 */
function formatPartialStorageStatus(stages) {
    const labels = {
        files: i18n('admin.storage.update_all_stage_files', 'file scan'),
        database: i18n('admin.storage.update_all_stage_database_metadata', 'database metadata'),
        inspection: i18n('admin.storage.update_all_stage_inspection', 'database inspection'),
    };
    const failed = stages.filter((stage) => stage.status === 'partial' || stage.status === 'failed');
    const names = failed.map((stage) => labels[stage.phase]).filter(Boolean);
    return i18n('admin.storage.update_all_partial', 'Update completed with issues: {steps}.', {
        steps: names.length > 0 ? names.join(', ') : i18n('admin.storage.update_all_some_issues', 'one or more stages'),
    });
}

/**
 * Set the shared progress bar, status copy, and processed count.
 *
 * @param {HTMLElement} panel Shared storage refresh toolbar.
 * @param {number} percent Overall progress percentage.
 * @param {string} label Human-readable stage status.
 * @param {string} count Optional processed-count copy.
 * @return {void}
 */
function setStorageProgress(panel, percent, label, count = '') {
    const progress = panel.querySelector('[data-admin-storage-progress]');
    if (progress instanceof HTMLElement) {
        progress.hidden = false;
    }
    const bar = panel.querySelector('[role="progressbar"]');
    const boundedPercent = Math.max(0, Math.min(100, percent));
    if (bar instanceof HTMLElement) {
        bar.setAttribute('aria-valuenow', String(Math.round(boundedPercent)));
    }
    const fill = panel.querySelector('[data-admin-storage-progress-fill]');
    if (fill instanceof HTMLElement) {
        fill.style.setProperty('--admin-storage-progress', `${boundedPercent.toFixed(1)}%`);
    }
    const labelTarget = panel.querySelector('[data-admin-storage-progress-label]');
    if (labelTarget) {
        labelTarget.textContent = label;
    }
    const countTarget = panel.querySelector('[data-admin-storage-progress-count]');
    if (countTarget) {
        countTarget.textContent = count;
    }
    updateStorageStatus(panel, label);
}

/**
 * Update the live status copy for the shared toolbar.
 *
 * @param {HTMLElement} panel Shared storage refresh toolbar.
 * @param {string} label Human-readable state.
 * @return {void}
 */
function updateStorageStatus(panel, label) {
    const status = panel.querySelector('[data-admin-storage-status]');
    if (status) {
        status.textContent = label;
    }
}
