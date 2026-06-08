/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-thumbnail-progress.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances thumbnail maintenance and import thumbnail jobs.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   2026-05-12
 */

import { adminUrlWithParams, ensureThumbnailProgress, i18n, isThumbnailSubmission, thumbnailEndpoint, updateThumbnailProgress } from './admin-core.js?v=20260512-modular-admin-v1';

// Function `setupThumbnailProgress` executes this focused behavior.
export function setupThumbnailProgress() {
    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const legacyCleanupButton = event.target.closest('[data-delete-legacy-jpg-thumbnails]');
        if (legacyCleanupButton) {
            const cleanupForm = legacyCleanupButton.closest('form');
            if (!(cleanupForm instanceof HTMLFormElement)) {
                return;
            }
            event.preventDefault();
            const confirmMessage = legacyCleanupButton.getAttribute('data-confirm-message') || i18n('admin.thumbnails.legacy_cleanup_confirm', 'Delete generated legacy JPG thumbnails? Original photos and WebP files will be kept.');
            if (!window.confirm(confirmMessage)) {
                return;
            }
            await runLegacyJpegThumbnailCleanup(cleanupForm);
            return;
        }
        // Variable `button` stores this steps working value.
        const button = event.target.closest('[data-create-all-thumbnails]');
        if (!button) {
            // Variable `metadataButton` stores the thumbnail database refresh action.
            const metadataButton = event.target.closest('[data-refresh-thumbnail-metadata]');
            if (metadataButton) {
                // Variable `metadataForm` stores the refresh form posted to the thumbnail endpoint.
                const metadataForm = metadataButton.closest('form');
                if (!(metadataForm instanceof HTMLFormElement)) {
                    return;
                }
                event.preventDefault();
                metadataButton.disabled = true;
                try {
                    await runThumbnailJob(metadataForm, null, {scope: 'metadata'});
                } finally {
                    metadataButton.disabled = false;
                }
                return;
            }
            // Variable `missingButton` stores this steps working value.
            const missingButton = event.target.closest('[data-create-missing-thumbnails]');
            if (!missingButton) {
                return;
            }
            // Variable `missingForm` stores this steps working value.
            const missingForm = document.querySelector('[data-thumbnail-maintenance-form]');
            if (!(missingForm instanceof HTMLFormElement)) {
                return;
            }
            event.preventDefault();
            await runThumbnailJob(missingForm, null, {scope: 'missing'});
            return;
        }
        // Variable `form` stores this steps working value.
        const form = document.querySelector('[data-gallery-bulk-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
            checkbox.checked = true;
        });
        form.querySelectorAll('input[type="checkbox"][data-select-all="gallery_ids[]"]').forEach((checkbox) => {
            checkbox.checked = true;
        });
        // Variable `action` stores this steps working value.
        const action = form.querySelector('select[name="action"]');
        if (action) {
            action.value = 'thumbs';
        }
        button.disabled = true;
        try {
            await runThumbnailJob(form, null, {scope: 'all'});
        } finally {
            button.disabled = false;
        }
    });

    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
            return;
        }
        event.preventDefault();
        runThumbnailJob(form, event.submitter);
    });

    document.addEventListener('submit', (event) => {
        // form stores state or configuration for the gallery front-end flow.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-import-galleries-form]')) {
            return;
        }
        if (!form.querySelector('input[name="create_thumbnails"]')?.checked) {
            return;
        }
        event.preventDefault();
        runImportWithThumbnailProgress(form);
    });
}


/**
 * Remove generated legacy JPEG thumbnail derivatives in browser-driven batches.
 *
 * @param {HTMLFormElement} form Submitted cleanup form.
 * @returns {Promise<void>}
 */
async function runLegacyJpegThumbnailCleanup(form) {
    const progress = ensureThumbnailProgress(form);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });

    let offset = 0;
    let total = 0;
    let deleted = 0;
    let freedBytes = 0;
    updateLegacyJpegCleanupProgress(progress, 0, 0, deleted, freedBytes, i18n('admin.thumbnails.legacy_cleanup_preparing', 'Preparing legacy JPG cleanup...'));

    try {
        while (true) {
            const body = new FormData(form);
            body.set('ajax', '1');
            body.set('offset', String(offset));
            body.set('batch_size', '24');
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Legacy JPG cleanup request failed.');
            }
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error || 'Legacy JPG cleanup request failed.');
            }
            total = result.total || 0;
            offset = result.next_offset || 0;
            deleted += result.files_deleted || 0;
            freedBytes += result.bytes_deleted || 0;
            updateLegacyJpegCleanupProgress(progress, result.processed || 0, total, deleted, freedBytes, i18n('admin.thumbnails.legacy_cleanup_running', 'Removing legacy JPG thumbnails...'));
            if (result.done) {
                updateLegacyJpegCleanupProgress(progress, total, total, deleted, freedBytes, i18n('admin.thumbnails.legacy_cleanup_complete', 'Legacy JPG cleanup complete.'));
                break;
            }
        }
    } catch (error) {
        updateLegacyJpegCleanupProgress(progress, offset, total, deleted, freedBytes, i18n('admin.thumbnails.legacy_cleanup_failed', 'Legacy JPG cleanup failed.'));
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Update the legacy JPEG cleanup progress display.
 *
 * @param {HTMLElement} progress Progress root.
 * @param {number} processed Processed image count.
 * @param {number} total Total image count.
 * @param {number} deleted Deleted file count.
 * @param {number} freedBytes Deleted byte count.
 * @param {string} label Status label.
 * @returns {void}
 */
function updateLegacyJpegCleanupProgress(progress, processed, total, deleted, freedBytes, label) {
    progress.hidden = false;
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
    progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
    progress.querySelector('[data-thumbnail-progress-text]').textContent = i18n(
        'admin.thumbnails.legacy_cleanup_progress',
        '{label} {processed}/{total} images checked, {deleted} files deleted, {size} freed.',
        {label, processed, total, deleted, size: formatBytes(freedBytes)}
    );
}

/**
 * Format byte counts for concise browser progress messages.
 *
 * @param {number} bytes Raw byte count.
 * @returns {string} Human-readable size.
 */
function formatBytes(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.max(0, Number(bytes) || 0);
    let unitIndex = 0;
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }
    const decimals = value >= 10 || unitIndex === 0 ? 0 : 1;
    return `${value.toFixed(decimals)} ${units[unitIndex]}`;
}

/**
 * Handles run import with thumbnail progress behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runImportWithThumbnailProgress(form) {
    // progress stores state or configuration for the gallery front-end flow.
    const progress = ensureThumbnailProgress(form);
    // buttons stores state or configuration for the gallery front-end flow.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    updateThumbnailProgress(progress, 0, 0, 0, 0, 'Importing selected galleries...');
    try {
        // importBody stores state or configuration for the gallery front-end flow.
        const importBody = new FormData(form);
        importBody.set('ajax', '1');
        // importResponse stores state or configuration for the gallery front-end flow.
        const importResponse = await fetch(form.action || window.location.href, {
            method: 'POST',
            body: importBody,
            headers: {'Accept': 'application/json'},
        });
        if (!importResponse.ok) {
            throw new Error('Import request failed.');
        }
        // importResult stores state or configuration for the gallery front-end flow.
        const importResult = await importResponse.json();
        // galleryIds stores state or configuration for the gallery front-end flow.
        const galleryIds = Array.isArray(importResult.gallery_ids) ? importResult.gallery_ids : [];
        if (galleryIds.length === 0) {
            updateThumbnailProgress(progress, 0, 0, 0, 0, `Import complete. ${importResult.imported || 0} galleries imported, ${importResult.scanned || 0} images scanned.`);
            window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: 0});
            return;
        }
        // offset stores state or configuration for the gallery front-end flow.
        let offset = 0;
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        // created stores state or configuration for the gallery front-end flow.
        let created = 0;
        // skipped stores state or configuration for the gallery front-end flow.
        let skipped = 0;
        while (true) {
            // thumbBody stores state or configuration for the gallery front-end flow.
            const thumbBody = new FormData();
            thumbBody.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            thumbBody.set('ajax', '1');
            thumbBody.set('offset', String(offset));
            thumbBody.set('batch_size', '6');
            galleryIds.forEach((galleryId) => {
                thumbBody.append('gallery_ids[]', String(galleryId));
            });
            // response stores state or configuration for the gallery front-end flow.
            const response = await fetch(thumbnailEndpoint(form, null), {
                method: 'POST',
                body: thumbBody,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Thumbnail request failed.');
            }
            // result stores state or configuration for the gallery front-end flow.
            const result = await response.json();
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, `Imported ${importResult.imported || 0} galleries, scanned ${importResult.scanned || 0} images. Creating thumbnails...`);
            if (result.done) {
                updateThumbnailProgress(progress, total, total, created, skipped, 'Import and thumbnail job complete.');
                window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: created});
                break;
            }
        }
    } catch (error) {
        updateThumbnailProgress(progress, 0, 0, 0, 0, 'Import or thumbnail job failed.');
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Handles run thumbnail job behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} submitter Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runThumbnailJob(form, submitter, options = {}) {
    // Variable `progress` stores this steps working value.
    const progress = ensureThumbnailProgress(form);
    // Variable `buttons` stores this steps working value.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    // Variable `offset` stores this steps working value.
    let offset = 0;
    // Variable `total` stores this steps working value.
    let total = 0;
    // Variable `created` stores this steps working value.
    let created = 0;
    // Variable `skipped` stores this steps working value.
    let skipped = 0;
    const initialLabel = options.scope === 'metadata' ? 'Preparing thumbnail database refresh...' : 'Preparing thumbnails...';
    updateThumbnailProgress(progress, 0, 0, created, skipped, initialLabel);
    try {
        while (true) {
            // Variable `body` stores this steps working value.
            const body = new FormData(form);
            if (submitter?.name) {
                body.set(submitter.name, submitter.value);
            }
            if (options.scope) {
                body.set('scope', options.scope);
            }
            body.set('ajax', '1');
            body.set('offset', String(offset));
            body.set('batch_size', '6');
            // Variable `response` stores this steps working value.
            const response = await fetch(thumbnailEndpoint(form, submitter), {
                method: 'POST',
                body,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Thumbnail request failed.');
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            const scopeLabel = options.scope === 'missing'
                ? 'missing thumbnails'
                : (options.scope === 'metadata' ? 'thumbnail database' : 'thumbnails');
            const activeLabel = options.scope === 'metadata' ? `Refreshing ${scopeLabel}...` : `Creating ${scopeLabel}...`;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, activeLabel);
            if (result.done) {
                // finalLabel keeps empty targeted jobs readable instead of showing only 0/0 counters.
                const finalLabel = options.scope === 'missing' && total === 0
                    ? 'No missing or stale thumbnails found.'
                    : (options.scope === 'metadata' ? 'Thumbnail database refresh complete.' : 'Thumbnail job complete.');
                updateThumbnailProgress(progress, total, total, created, skipped, finalLabel);
                if (options.scope === 'missing' && result.maintenance_after && (result.maintenance_after.images_with_missing || 0) <= 0) {
                    const notice = form.closest('.admin-thumbnail-maintenance-notice');
                    if (notice) {
                        notice.remove();
                    }
                }
                break;
            }
        }
    } catch (error) {
        const failedLabel = options.scope === 'metadata' ? 'Thumbnail database refresh failed.' : 'Thumbnail job failed.';
        updateThumbnailProgress(progress, offset, total, created, skipped, failedLabel);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}
