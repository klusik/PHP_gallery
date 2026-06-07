/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-migration.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Drives staged gallery migration workflows from the Admin API tab.
 *
 * Responsibilities:
 *   - Run source-push and target-pull migrations through small AJAX steps
 *   - Transfer one migration asset per request
 *   - Keep visible progress and failure messages readable
 *   - Avoid exposing API keys in logs or generated markup
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
 *   2026-05-27
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const DEFAULT_RECONNECT_SECONDS = 30;
const MIN_RECONNECT_SECONDS = 5;
const MAX_RECONNECT_SECONDS = 300;
const MAX_ASSET_RETRIES = 6;
const STATUS_PROBE_COUNT = 4;
const STATUS_PROBE_DELAY_MS = 1500;

/**
 * Error type used when the browser deliberately refreshes a long transfer request.
 */
class GalleryMigrationTimeoutError extends Error {
    /**
     * Create a timeout error that can be distinguished from normal HTTP errors.
     *
     * @param {string} message Visible error message.
     */
    constructor(message) {
        super(message);
        this.name = 'GalleryMigrationTimeoutError';
    }
}

/**
 * Initialize gallery migration forms.
 *
 * @returns {void}
 */
export function setupAdminGalleryMigration() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-migration-form]')) {
            return;
        }

        event.preventDefault();
        runGalleryMigration(form);
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const button = event.target.closest('[data-gallery-migration-cancel]');
        const form = button ? button.closest('[data-gallery-migration-form]') : null;
        if (!(button instanceof HTMLButtonElement) || !(form instanceof HTMLFormElement)) {
            return;
        }
        form.dataset.galleryMigrationCancelled = '1';
        appendMigrationLog(form, 'Cancel requested. The current request will finish or time out, then the transfer will stop.');
        button.disabled = true;
    });
}

/**
 * Run one push or pull migration.
 *
 * @param {HTMLFormElement} form Migration form.
 * @returns {Promise<void>}
 */
async function runGalleryMigration(form) {
    const mode = form.dataset.galleryMigrationMode || '';
    const submitButtons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    const cancelButton = form.querySelector('[data-gallery-migration-cancel]');
    const reconnectSeconds = getReconnectSeconds(form);

    form.dataset.galleryMigrationCancelled = '0';
    submitButtons.forEach((button) => {
        button.disabled = button !== cancelButton;
    });
    if (cancelButton instanceof HTMLButtonElement) {
        cancelButton.hidden = false;
        cancelButton.disabled = false;
    }
    clearMigrationLog(form);

    try {
        updateMigrationProgress(form, 0, i18n('admin.gallery_migration.preparing', 'Preparing migration...'));
        appendMigrationLog(form, i18n('admin.gallery_migration.connection_interval', 'Connection refresh interval: {seconds} seconds.', {seconds: reconnectSeconds}));

        const manifestAction = mode === 'source_push' ? 'push_manifest' : 'pull_manifest';
        const manifestResult = await postMigrationStep(form, manifestAction, {}, {timeoutSeconds: reconnectSeconds});
        const assets = Array.isArray(manifestResult.assets) ? manifestResult.assets : [];
        const jobId = String(manifestResult.job_id || '');
        if (jobId === '') {
            throw new Error(i18n('admin.gallery_migration.job_id_missing', 'The migration job id was not returned.'));
        }

        appendMigrationLog(form, manifestResult.message || i18n('admin.gallery_migration.manifest_accepted', 'Manifest accepted.'));
        appendMigrationLog(form, i18n('admin.gallery_migration.version_check', 'Version check: {message}.', {message: versionMessage(manifestResult.compatibility)}));
        appendMigrationLog(form, i18n('admin.gallery_migration.assets_queued', 'Assets queued: {count}.', {count: assets.length}));

        const receivedAssetKeys = await loadInitialReceivedAssetKeys(form, mode, jobId, manifestResult, reconnectSeconds);
        if (receivedAssetKeys.size > 0) {
            appendMigrationLog(form, i18n('admin.gallery_migration.remote_received', 'Remote status reports {count} already received asset(s). They will be skipped.', {count: receivedAssetKeys.size}));
        }

        for (let index = 0; index < assets.length; index += 1) {
            if (form.dataset.galleryMigrationCancelled === '1') {
                throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
            }

            const asset = assets[index] || {};
            const assetKey = String(asset.asset_key || '');
            if (assetKey !== '' && receivedAssetKeys.has(assetKey)) {
                updateMigrationProgress(form, Math.round(((index + 1) / Math.max(assets.length, 1)) * 95), i18n('admin.gallery_migration.skipping_asset', 'Skipping already received {asset} ({current}/{total})...', {asset: assetLabel(asset), current: index + 1, total: assets.length}));
                appendMigrationLog(form, i18n('admin.gallery_migration.skipped_asset', 'Skipped already received asset: {asset}.', {asset: assetLabel(asset)}));
                continue;
            }

            const action = mode === 'source_push' ? 'push_asset' : 'pull_asset';
            updateMigrationProgress(form, Math.round((index / Math.max(assets.length, 1)) * 95), i18n('admin.gallery_migration.transferring_asset', 'Transferring {asset} ({current}/{total})...', {asset: assetLabel(asset), current: index + 1, total: assets.length}));
            const transferResult = await transferAssetWithReconnect(form, mode, action, asset, jobId, index, assets.length, reconnectSeconds);
            const confirmedKey = String(transferResult.asset_key || assetKey || '');
            if (confirmedKey !== '') {
                receivedAssetKeys.add(confirmedKey);
            }
        }

        if (form.dataset.galleryMigrationCancelled === '1') {
            throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
        }
        const completeAction = mode === 'source_push' ? 'push_complete' : 'pull_complete';
        const completeResult = await postMigrationStep(form, completeAction, {job_id: jobId}, {timeoutSeconds: reconnectSeconds});
        updateMigrationProgress(form, 100, i18n('admin.gallery_migration.complete', 'Migration complete. {received}/{total} assets received.', {received: completeResult.assets_received || assets.length, total: completeResult.total_assets || assets.length}));
        appendMigrationLog(form, i18n('admin.gallery_migration.completed_successfully', 'Migration completed successfully.'));
        if (completeResult.edit_url) {
            appendMigrationLog(form, i18n('admin.gallery_migration.target_editor', 'Target editor: {url}', {url: completeResult.edit_url}));
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.failed', 'Migration failed.');
        updateMigrationProgress(form, 100, message);
        appendMigrationLog(form, message);
    } finally {
        submitButtons.forEach((button) => {
            button.disabled = false;
        });
        if (cancelButton instanceof HTMLButtonElement) {
            cancelButton.hidden = true;
            cancelButton.disabled = false;
        }
    }
}

/**
 * Transfer one asset and verify target-side status before every retry.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {string} action Server transfer action.
 * @param {Object<string, *>} asset Manifest asset.
 * @param {string} jobId Migration job id.
 * @param {number} index Zero-based asset index.
 * @param {number} total Total asset count.
 * @param {number} reconnectSeconds Request refresh interval.
 * @returns {Promise<Object<string, *>>} Transfer or status response.
 */
async function transferAssetWithReconnect(form, mode, action, asset, jobId, index, total, reconnectSeconds) {
    let lastError = null;
    for (let attempt = 1; attempt <= MAX_ASSET_RETRIES; attempt += 1) {
        if (form.dataset.galleryMigrationCancelled === '1') {
            throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
        }

        try {
            return await postMigrationStep(form, action, assetFields(asset, jobId), {timeoutSeconds: reconnectSeconds});
        } catch (error) {
            lastError = error;
            const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.connection_interrupted', 'Connection interrupted during asset transfer.');
            appendMigrationLog(form, i18n('admin.gallery_migration.checking_status', '{message} Checking target gallery status before retry.', {message}));

            const status = await confirmAssetOnReconnect(form, mode, asset, jobId, reconnectSeconds);
            if (status.asset_received) {
                appendMigrationLog(form, i18n('admin.gallery_migration.target_already_has', 'Target gallery already has {asset}. Continuing without resending it.', {asset: assetLabel(asset)}));
                return status;
            }

            if (attempt >= MAX_ASSET_RETRIES) {
                break;
            }

            updateMigrationProgress(form, Math.round((index / Math.max(total, 1)) * 95), i18n('admin.gallery_migration.reconnecting_asset', 'Reconnecting for {asset} ({attempt}/{max})...', {asset: assetLabel(asset), attempt: attempt + 1, max: MAX_ASSET_RETRIES}));
            appendMigrationLog(form, i18n('admin.gallery_migration.target_missing_retry', 'Target gallery does not report this asset yet. Reconnecting and retrying {attempt}/{max}.', {attempt: attempt + 1, max: MAX_ASSET_RETRIES}));
        }
    }

    throw lastError instanceof Error ? lastError : new Error(i18n('admin.gallery_migration.transfer_failed_retries', 'Migration asset transfer failed after reconnect retries.'));
}

/**
 * Load target-side status after the manifest is accepted.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {string} jobId Migration job id.
 * @param {Object<string, *>} manifestResult Manifest response.
 * @param {number} reconnectSeconds Request refresh interval.
 * @returns {Promise<Set<string>>} Already received asset keys.
 */
async function loadInitialReceivedAssetKeys(form, mode, jobId, manifestResult, reconnectSeconds) {
    const keys = new Set();
    collectReceivedAssetKeys(manifestResult.status, keys);

    try {
        const status = await requestMigrationStatus(form, mode, jobId, null, reconnectSeconds);
        collectReceivedAssetKeys(status, keys);
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.target_status_missing', 'Could not read target status.');
        appendMigrationLog(form, i18n('admin.gallery_migration.initial_status_failed', 'Initial target status check failed: {message}', {message}));
    }

    return keys;
}

/**
 * Check whether the target accepted an asset after a timeout or broken request.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {Object<string, *>} asset Manifest asset.
 * @param {string} jobId Migration job id.
 * @param {number} reconnectSeconds Request refresh interval.
 * @returns {Promise<Object<string, *>>} Status response.
 */
async function confirmAssetOnReconnect(form, mode, asset, jobId, reconnectSeconds) {
    let lastStatus = null;
    let lastError = null;

    for (let probe = 1; probe <= STATUS_PROBE_COUNT; probe += 1) {
        if (probe > 1) {
            await sleep(STATUS_PROBE_DELAY_MS);
        }

        try {
            lastStatus = await requestMigrationStatus(form, mode, jobId, asset, reconnectSeconds);
            if (lastStatus.asset_received) {
                return lastStatus;
            }
        } catch (error) {
            lastError = error;
        }
    }

    if (lastStatus) {
        return lastStatus;
    }

    const message = lastError instanceof Error ? lastError.message : i18n('admin.gallery_migration.target_status_failed', 'Target status check failed.');
    throw new Error(message);
}

/**
 * Request migration status from the target gallery through the active side.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {string} jobId Migration job id.
 * @param {Object<string, *>|null} asset Optional asset to check.
 * @param {number} reconnectSeconds Request refresh interval.
 * @returns {Promise<Object<string, *>>} Status response.
 */
async function requestMigrationStatus(form, mode, jobId, asset, reconnectSeconds) {
    const action = mode === 'source_push' ? 'push_status' : 'pull_status';
    const fields = asset ? assetFields(asset, jobId) : {job_id: jobId};
    return postMigrationStep(form, action, fields, {timeoutSeconds: reconnectSeconds});
}

/**
 * POST one local admin migration step.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} action Server action.
 * @param {Object<string, string|number>} extraFields Additional fields.
 * @param {{timeoutSeconds?: number}} options Request options.
 * @returns {Promise<Object<string, *>>} JSON response.
 */
async function postMigrationStep(form, action, extraFields, options = {}) {
    const root = form.closest('[data-gallery-migration]');
    const endpoint = root instanceof HTMLElement ? root.dataset.galleryMigrationEndpoint || '' : '';
    if (endpoint === '') {
        throw new Error(i18n('admin.gallery_migration.endpoint_missing', 'Migration endpoint is missing.'));
    }

    const body = new FormData(form);
    body.set('action', action);
    Object.entries(extraFields).forEach(([name, value]) => {
        body.set(name, String(value));
    });

    const timeoutSeconds = clampReconnectSeconds(options.timeoutSeconds || getReconnectSeconds(form));
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => {
        controller.abort();
    }, timeoutSeconds * 1000);

    let response;
    try {
        response = await fetch(endpoint, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: controller.signal,
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw new GalleryMigrationTimeoutError(i18n('admin.gallery_migration.connection_refresh_reached', 'Connection refresh reached {seconds} seconds during {action}.', {seconds: timeoutSeconds, action}));
        }
        throw error;
    } finally {
        window.clearTimeout(timeoutId);
    }

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.ok === false) {
        throw new Error((payload && (payload.error || payload.message)) || i18n('admin.gallery_migration.request_failed_http', 'Migration request failed with HTTP {status}.', {status: response.status}));
    }

    return payload;
}

/**
 * Return form fields needed to transfer or check one asset.
 *
 * @param {Object<string, *>} asset Manifest asset.
 * @param {string} jobId Migration job id.
 * @returns {Object<string, string|number>} Request fields.
 */
function assetFields(asset, jobId) {
    return {
        job_id: jobId,
        scope: String(asset.scope || ''),
        kind: String(asset.kind || ''),
        source_image_id: Number(asset.source_image_id || 0),
        size: Number(asset.size || 0),
        format: String(asset.format || ''),
    };
}

/**
 * Collect received asset keys from one migration status payload.
 *
 * @param {Object<string, *>|null|undefined} status Status payload.
 * @param {Set<string>} keys Mutable destination set.
 * @returns {void}
 */
function collectReceivedAssetKeys(status, keys) {
    if (!status || typeof status !== 'object' || !Array.isArray(status.received_asset_keys)) {
        return;
    }

    status.received_asset_keys.forEach((key) => {
        const text = String(key || '');
        if (text !== '') {
            keys.add(text);
        }
    });
}

/**
 * Return a readable label for one asset.
 *
 * @param {Object<string, *>} asset Manifest asset.
 * @returns {string} Label.
 */
function assetLabel(asset) {
    const label = String(asset.label || asset.relative_path || asset.filename || asset.kind || i18n('admin.gallery_migration.asset_fallback', 'asset'));
    if (asset.kind === 'thumbnail') {
        return i18n('admin.gallery_migration.thumbnail_suffix', '{label} thumbnail {size} {format}', {label, size: asset.size || '', format: asset.format || ''}).trim();
    }
    if (asset.scope === 'gallery') {
        return i18n('admin.gallery_migration.gallery_asset_suffix', '{label} gallery asset', {label});
    }
    return label;
}

/**
 * Return a compatibility status message.
 *
 * @param {Object<string, *>|null} compatibility Compatibility payload.
 * @returns {string} Readable message.
 */
function versionMessage(compatibility) {
    if (!compatibility || typeof compatibility !== 'object') {
        return i18n('admin.gallery_migration.version_not_reported', 'not reported');
    }
    return String(compatibility.message || `${compatibility.source_version || '?'} to ${compatibility.target_version || '?'}`);
}

/**
 * Return the configured reconnect interval for one form.
 *
 * @param {HTMLFormElement} form Migration form.
 * @returns {number} Seconds.
 */
function getReconnectSeconds(form) {
    const control = form.elements.namedItem('reconnect_seconds');
    if (!(control instanceof HTMLInputElement)) {
        return DEFAULT_RECONNECT_SECONDS;
    }

    return clampReconnectSeconds(Number.parseInt(control.value, 10));
}

/**
 * Clamp reconnect seconds to a safe browser-side range.
 *
 * @param {number} value User-entered seconds.
 * @returns {number} Safe seconds.
 */
function clampReconnectSeconds(value) {
    if (!Number.isFinite(value)) {
        return DEFAULT_RECONNECT_SECONDS;
    }
    return Math.max(MIN_RECONNECT_SECONDS, Math.min(MAX_RECONNECT_SECONDS, Math.round(value)));
}

/**
 * Wait for a small delay between reconnect status probes.
 *
 * @param {number} milliseconds Delay length.
 * @returns {Promise<void>} Resolves after the delay.
 */
function sleep(milliseconds) {
    return new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });
}

/**
 * Update one form progress bar.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {number} percent Percentage.
 * @param {string} text Visible text.
 * @returns {void}
 */
function updateMigrationProgress(form, percent, text) {
    const progress = form.querySelector('[data-gallery-migration-progress]');
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    const bar = progress.querySelector('[data-gallery-migration-progress-fill]');
    const label = progress.querySelector('[data-gallery-migration-progress-text]');
    progress.hidden = false;
    if (bar instanceof HTMLProgressElement) {
        bar.value = Math.max(0, Math.min(100, percent));
    }
    if (label instanceof HTMLElement) {
        label.textContent = text;
    }
}

/**
 * Add one visible log line.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} text Log line.
 * @returns {void}
 */
function appendMigrationLog(form, text) {
    const log = form.querySelector('[data-gallery-migration-log]');
    if (!(log instanceof HTMLElement)) {
        return;
    }
    log.hidden = false;
    log.textContent = `${log.textContent || ''}${text}\n`;
    log.scrollTop = log.scrollHeight;
}

/**
 * Clear the visible log.
 *
 * @param {HTMLFormElement} form Migration form.
 * @returns {void}
 */
function clearMigrationLog(form) {
    const log = form.querySelector('[data-gallery-migration-log]');
    if (!(log instanceof HTMLElement)) {
        return;
    }
    log.textContent = '';
    log.hidden = true;
}
