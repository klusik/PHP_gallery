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
 *   - Transfer deterministic ZIP packages prepared by the receiving instance
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
 *   2026-09-05
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const DEFAULT_RECONNECT_SECONDS = 30;
const MIN_RECONNECT_SECONDS = 5;
const MAX_RECONNECT_SECONDS = 300;
const MAX_PACKAGE_RETRIES = 6;
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
        appendMigrationLog(form, i18n('admin.gallery_migration.cancel_requested', 'Cancel requested. The current request will finish or time out, then the transfer will stop.'));
        button.disabled = true;
    });
}

/**
 * Run one push or pull migration.
 *
 * @param {HTMLFormElement} form Migration form.
 */
async function runGalleryMigration(form) {
    const mode = form.dataset.galleryMigrationMode || '';
    const submitButtons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    const cancelButton = form.querySelector('[data-gallery-migration-cancel]');
    const reconnectSeconds = getReconnectSeconds(form);
    let localMutationResult = null;

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
        if (mode === 'target_pull' && manifestResult?.mutation && Array.isArray(manifestResult?.contexts)) {
            localMutationResult = manifestResult;
        }
        const packages = Array.isArray(manifestResult.packages) ? manifestResult.packages : [];
        const jobId = String(manifestResult.job_id || '');
        if (jobId === '') {
            throw new Error(i18n('admin.gallery_migration.job_id_missing', 'The migration job id was not returned.'));
        }

        appendMigrationLog(form, manifestResult.message || i18n('admin.gallery_migration.manifest_accepted', 'Manifest accepted.'));
        appendMigrationLog(form, i18n('admin.gallery_migration.version_check', 'Version check: {message}.', {message: versionMessage(manifestResult.compatibility)}));
        appendMigrationLog(form, i18n('admin.gallery_migration.packages_queued', 'ZIP packages queued: {count}.', {count: packages.length}));
        if (manifestResult?.counts?.galleries) {
            appendMigrationLog(form, i18n('admin.gallery_migration.galleries_queued', 'Galleries in tree: {count}.', {count: manifestResult.counts.galleries}));
        }

        const receivedAssetKeys = await loadInitialReceivedAssetKeys(form, mode, jobId, manifestResult, reconnectSeconds);
        if (receivedAssetKeys.size > 0) {
            appendMigrationLog(form, i18n('admin.gallery_migration.remote_received', 'Target status reports {count} already received asset(s). They will be skipped.', {count: receivedAssetKeys.size}));
        }

        for (let index = 0; index < packages.length; index += 1) {
            if (form.dataset.galleryMigrationCancelled === '1') {
                throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
            }

            const packageDescriptor = packages[index] || {};
            const packageKeys = packageAssetKeys(packageDescriptor);
            if (packageKeys.length > 0 && packageKeys.every((key) => receivedAssetKeys.has(key))) {
                updateMigrationProgress(form, Math.round(((index + 1) / Math.max(packages.length, 1)) * 95), i18n('admin.gallery_migration.skipping_package', 'Skipping already received ZIP package {current}/{total}...', {current: index + 1, total: packages.length}));
                appendMigrationLog(form, i18n('admin.gallery_migration.skipped_package', 'Skipped already received ZIP package {current}/{total}.', {current: index + 1, total: packages.length}));
                continue;
            }

            const action = mode === 'source_push' ? 'push_package' : 'pull_package';
            updateMigrationProgress(form, Math.round((index / Math.max(packages.length, 1)) * 95), i18n('admin.gallery_migration.transferring_package', 'Transferring ZIP package {current}/{total} ({assets} assets)...', {current: index + 1, total: packages.length, assets: packageKeys.length}));
            const transferResult = await transferPackageWithReconnect(form, mode, action, packageDescriptor, jobId, reconnectSeconds);
            collectReceivedAssetKeys(transferResult, receivedAssetKeys);
            if (Array.isArray(transferResult.asset_keys)) {
                transferResult.asset_keys.forEach((key) => receivedAssetKeys.add(String(key || '')));
            }
        }

        if (form.dataset.galleryMigrationCancelled === '1') {
            throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
        }
        const completeAction = mode === 'source_push' ? 'push_complete' : 'pull_complete';
        const completeResult = await postMigrationStep(form, completeAction, {job_id: jobId}, {timeoutSeconds: reconnectSeconds});
        if (mode === 'target_pull' && completeResult?.mutation && Array.isArray(completeResult?.contexts)) {
            localMutationResult = completeResult;
        }
        const fallbackTotal = Number(manifestResult?.counts?.assets || receivedAssetKeys.size || 0);
        updateMigrationProgress(form, 100, i18n('admin.gallery_migration.complete', 'Migration complete. {received}/{total} assets received.', {received: completeResult.assets_received || receivedAssetKeys.size, total: completeResult.total_assets || fallbackTotal}));
        const completedMessage = i18n('admin.gallery_migration.completed_successfully', 'Migration completed successfully.');
        appendMigrationLog(form, completedMessage);
        if (mode === 'target_pull' && localMutationResult) {
            dispatchGalleryMigrationMutationResult(form, localMutationResult, 'gallery-migration', {
                refreshPanel: false,
                statusMessage: completedMessage,
            });
        }
        if (completeResult.edit_url) {
            appendMigrationLog(form, i18n('admin.gallery_migration.target_editor', 'Imported gallery editor: {url}', {url: completeResult.edit_url}));
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.failed', 'Migration failed.');
        updateMigrationProgress(form, 100, message);
        appendMigrationLog(form, message);
        if (mode === 'target_pull' && localMutationResult) {
            dispatchGalleryMigrationMutationResult(form, localMutationResult, 'gallery-migration-partial', {
                refreshPanel: false,
                statusMessage: message,
                statusError: true,
            });
        }
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
 * Hand local target-pull invalidation to the shared Admin mutation completion coordinator.
 *
 * Gallery Migration keeps ownership of its progress and log markup. This event therefore
 * requests only public-context synchronization and does not ask the side panel to replace
 * the migration tool fragment itself.
 *
 * @param {HTMLFormElement} form Active migration form.
 * @param {Object<string, *>} result Canonical local migration mutation result.
 * @param {string} source Stable integration source name.
 * @param {Object<string, *>} options Optional panel-refresh and status behavior.
 */
function dispatchGalleryMigrationMutationResult(form, result, source, options = {}) {
    if (!form.closest('[data-admin-side-panel]') || !result?.mutation || !Array.isArray(result?.contexts)) {
        return;
    }
    form.dispatchEvent(new CustomEvent('php-gallery:auxiliary-mutation-success', {
        bubbles: true,
        detail: {
            source,
            result,
            refreshPanel: options.refreshPanel === true,
            statusMessage: String(options.statusMessage || ''),
            statusError: options.statusError === true,
        },
    }));
}

/**
 * Transfer one ZIP package and verify target-side status before every retry.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {string} action Server transfer action.
 * @param {Object<string, *>} packageDescriptor Package descriptor.
 * @param {string} jobId Migration job id.
 * @param {number} reconnectSeconds Request refresh interval.
 * @return {Promise<Object<string, *>>} Transfer or status response.
 */
async function transferPackageWithReconnect(form, mode, action, packageDescriptor, jobId, reconnectSeconds) {
    let lastError = null;
    for (let attempt = 1; attempt <= MAX_PACKAGE_RETRIES; attempt += 1) {
        if (form.dataset.galleryMigrationCancelled === '1') {
            throw new Error(i18n('admin.gallery_migration.cancelled', 'Migration cancelled by user.'));
        }

        try {
            return await postMigrationStep(form, action, packageFields(packageDescriptor, jobId), {timeoutSeconds: reconnectSeconds});
        } catch (error) {
            lastError = error;
            const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.connection_interrupted', 'Connection interrupted during ZIP package transfer.');
            appendMigrationLog(form, i18n('admin.gallery_migration.checking_status', '{message} Checking target status before retry.', {message}));

            const status = await confirmPackageOnReconnect(form, mode, packageDescriptor, jobId, reconnectSeconds);
            if (packageIsReceived(packageDescriptor, status)) {
                appendMigrationLog(form, i18n('admin.gallery_migration.package_already_received', 'Target already has this ZIP package. Continuing without resending it.'));
                return status;
            }

            if (attempt >= MAX_PACKAGE_RETRIES) {
                break;
            }
            appendMigrationLog(form, i18n('admin.gallery_migration.retrying_package', 'Retrying ZIP package, attempt {attempt}/{total}.', {attempt: attempt + 1, total: MAX_PACKAGE_RETRIES}));
        }
    }

    throw lastError instanceof Error ? lastError : new Error(i18n('admin.gallery_migration.package_failed', 'ZIP package transfer failed.'));
}

/**
 * Load target-side state before the package loop starts.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {string} jobId Migration job id.
 * @param {Object<string, *>} manifestResult Manifest-step result.
 * @param {number} reconnectSeconds Request refresh interval.
 * @return {Promise<Set<string>>} Already received asset keys.
 */
async function loadInitialReceivedAssetKeys(form, mode, jobId, manifestResult, reconnectSeconds) {
    const keys = new Set();
    collectReceivedAssetKeys(manifestResult.status, keys);

    try {
        const status = await requestMigrationStatus(form, mode, jobId, reconnectSeconds);
        collectReceivedAssetKeys(status, keys);
    } catch (error) {
        const message = error instanceof Error ? error.message : i18n('admin.gallery_migration.target_status_missing', 'Could not read target status.');
        appendMigrationLog(form, i18n('admin.gallery_migration.initial_status_failed', 'Initial target status check failed: {message}', {message}));
    }

    return keys;
}

/**
 * Check whether the target accepted a complete package after a timeout or broken request.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} mode Migration mode.
 * @param {Object<string, *>} packageDescriptor Package descriptor.
 * @param {string} jobId Migration job id.
 * @param {number} reconnectSeconds Request refresh interval.
 * @return {Promise<Object<string, *>>} Status response.
 */
async function confirmPackageOnReconnect(form, mode, packageDescriptor, jobId, reconnectSeconds) {
    let lastStatus = null;
    let lastError = null;

    for (let probe = 1; probe <= STATUS_PROBE_COUNT; probe += 1) {
        if (probe > 1) {
            await sleep(STATUS_PROBE_DELAY_MS);
        }
        try {
            lastStatus = await requestMigrationStatus(form, mode, jobId, reconnectSeconds);
            if (packageIsReceived(packageDescriptor, lastStatus)) {
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
 * @param {number} reconnectSeconds Request refresh interval.
 * @return {Promise<Object<string, *>>} Status response.
 */
async function requestMigrationStatus(form, mode, jobId, reconnectSeconds) {
    const action = mode === 'source_push' ? 'push_status' : 'pull_status';
    return postMigrationStep(form, action, {job_id: jobId}, {timeoutSeconds: reconnectSeconds});
}

/**
 * POST one local admin migration step.
 *
 * @param {HTMLFormElement} form Migration form.
 * @param {string} action Server action.
 * @param {Object<string, string|number>} extraFields Additional fields.
 * @param {object} options Optional behavior flags.
 * @return {Promise<Object<string, *>>} JSON response.
 */
async function postMigrationStep(form, action, extraFields, options = {}) {
    const root = form.closest('[data-gallery-migration]');
    const endpoint = root instanceof HTMLElement ? root.dataset.galleryMigrationEndpoint || '' : '';
    if (endpoint === '') {
        throw new Error(i18n('admin.gallery_migration.endpoint_missing', 'Migration endpoint is missing.'));
    }

    const body = new FormData(form);
    const includeControl = form.elements.namedItem('include_subgalleries');
    if (includeControl instanceof HTMLInputElement && includeControl.type === 'checkbox') {
        body.set('include_subgalleries', includeControl.checked ? '1' : '0');
    }
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
 * Return form fields needed to transfer one package.
 *
 * @param {Object<string, *>} packageDescriptor Package descriptor.
 * @param {string} jobId Migration job id.
 * @return {Object<string, string|number>} Request fields.
 */
function packageFields(packageDescriptor, jobId) {
    return {
        job_id: jobId,
        package_id: String(packageDescriptor.package_id || ''),
        assets_json: JSON.stringify(Array.isArray(packageDescriptor.assets) ? packageDescriptor.assets : []),
    };
}

/**
 * Return stable asset keys declared by one package.
 *
 * @param {Object<string, *>} packageDescriptor Package descriptor.
 * @return {string[]} Asset keys.
 */
function packageAssetKeys(packageDescriptor) {
    if (!Array.isArray(packageDescriptor.asset_keys)) {
        return [];
    }
    return packageDescriptor.asset_keys.map((key) => String(key || '')).filter((key) => key !== '');
}

/**
 * Return whether target status confirms every asset from one package.
 *
 * @param {Object<string, *>} packageDescriptor Package descriptor.
 * @param {Object<string, *>} status Status response.
 * @return {boolean} True when all package asset keys are present.
 */
function packageIsReceived(packageDescriptor, status) {
    const keys = new Set();
    collectReceivedAssetKeys(status, keys);
    const expected = packageAssetKeys(packageDescriptor);
    return expected.length > 0 && expected.every((key) => keys.has(key));
}

/**
 * Collect received asset keys from one migration status payload.
 *
 * @param {Object<string, *>|null|undefined} status Status payload.
 * @param {Set<string>} keys Mutable destination set.
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
 * Return a compatibility status message.
 *
 * @param {Object<string, *>|null} compatibility Compatibility payload.
 * @return {string} Readable message.
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
 * @return {number} Seconds.
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
 * @return {number} Safe seconds.
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
 * @return {Promise<void>} Resolves after the delay.
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
 */
function clearMigrationLog(form) {
    const log = form.querySelector('[data-gallery-migration-log]');
    if (!(log instanceof HTMLElement)) {
        return;
    }
    log.textContent = '';
    log.hidden = true;
}
