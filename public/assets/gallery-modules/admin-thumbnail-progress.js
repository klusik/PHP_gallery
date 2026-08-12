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
import { browserThumbnailRebuildRequested, runBrowserThumbnailRebuild } from './admin-browser-thumbnail-rebuild.js?v=20260610-thumbnail-serial-v5';


/**
 * Update the targeted missing-thumbnail action state after a dry check.
 *
 * @param {boolean} enabled Whether the button should be enabled.
 * @param {string} message Browser-visible status text.
 */
function setCreateMissingThumbnailState(enabled, message) {
    document.querySelectorAll('[data-create-missing-thumbnails]').forEach((button) => {
        if (button instanceof HTMLButtonElement) {
            button.disabled = !enabled;
            button.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        }
    });
    document.querySelectorAll('[data-create-missing-thumbnails-status]').forEach((status) => {
        if (status instanceof HTMLElement && message) {
            status.textContent = message;
        }
    });
}

/**
 * Store the server-side repair queue token in every Create missing thumbnails form.
 *
 * @param {string} token Session repair token returned by the dry-check endpoint.
 */
function setCreateMissingThumbnailRepairToken(token) {
    document.querySelectorAll('[data-create-missing-thumbnails]').forEach((button) => {
        const form = button.closest('form');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        let input = form.querySelector('input[name="thumbnail_repair_token"]');
        if (!(input instanceof HTMLInputElement)) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'thumbnail_repair_token';
            form.append(input);
        }
        input.value = token || '';
    });
}

/**
 * Return the browser-visible targeted repair hint for one dry-check result.
 *
 * @param {number} affectedImages Images needing at least one thumbnail variant.
 * @param {number} missingVariants Missing or stale variants.
 * @return {string} Status line.
 */
function createMissingThumbnailStateMessage(affectedImages, missingVariants) {
    if (affectedImages > 0) {
        return i18n(
            'admin.thumbnails.create_missing_ready_after_check',
            'Targeted repair is ready. {images} image(s) still need {variants} thumbnail variant(s).',
            {images: affectedImages, variants: missingVariants}
        );
    }
    return i18n(
        'admin.thumbnails.create_missing_none_after_check',
        'The latest full check found no missing or stale thumbnails. Create missing thumbnails stays disabled.',
        {}
    );
}

// Function `setupThumbnailProgress` executes this focused behavior.
/**
 * Handle setup thumbnail progress.
 *
 * Used by browser-side gallery behavior.
 */
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
            // Variable `checkButton` stores the dry thumbnail inventory action.
            const checkButton = event.target.closest('[data-check-missing-thumbnails]');
            if (checkButton) {
                // Variable `checkForm` stores the dry thumbnail inventory form.
                const checkForm = checkButton.closest('form');
                if (!(checkForm instanceof HTMLFormElement)) {
                    return;
                }
                event.preventDefault();
                await runThumbnailMaintenanceCheck(checkForm);
                return;
            }
            // Variable `missingButton` stores this steps working value.
            const missingButton = event.target.closest('[data-create-missing-thumbnails]');
            if (!missingButton) {
                return;
            }
            // Variable `missingForm` stores this steps working value.
            const missingForm = missingButton.closest('form') || document.querySelector('[data-thumbnail-maintenance-form]');
            if (!(missingForm instanceof HTMLFormElement)) {
                return;
            }
            event.preventDefault();
            if (missingButton instanceof HTMLButtonElement && missingButton.disabled) {
                return;
            }
            await runThumbnailJob(missingForm, null, {scope: 'missing'});
            return;
        }
        const maintenanceForm = button.closest('[data-thumbnail-maintenance-action-form]');
        if (maintenanceForm instanceof HTMLFormElement) {
            event.preventDefault();
            button.disabled = true;
            try {
                if (browserThumbnailRebuildRequested(maintenanceForm)) {
                    await runBrowserThumbnailRebuild(maintenanceForm, ensureThumbnailProgress(maintenanceForm), {scope: 'all'});
                } else {
                    await runThumbnailJob(maintenanceForm, null, {scope: 'all'});
                }
            } finally {
                button.disabled = false;
            }
            return;
        }
        const browserForm = button.closest('form');
        if (browserForm instanceof HTMLFormElement && browserThumbnailRebuildRequested(browserForm)) {
            event.preventDefault();
            button.disabled = true;
            try {
                await runBrowserThumbnailRebuild(browserForm, ensureThumbnailProgress(browserForm), {scope: 'all'});
            } finally {
                button.disabled = false;
            }
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
        if (form instanceof HTMLFormElement && form.matches('[data-thumbnail-check-form]')) {
            event.preventDefault();
            runThumbnailMaintenanceCheck(form);
            return;
        }
        if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
            return;
        }
        event.preventDefault();
        runThumbnailJob(form, event.submitter);
    });

    document.addEventListener('submit', (event) => {
        // form stores state or configuration for the gallery front-end flow.
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !form.matches('[data-import-galleries-form]')) {
            return;
        }
        const discoveryAction = String(form.querySelector('input[name="discovery_action"]:checked')?.value || 'import_in_place');
        if (discoveryAction === 'delete_from_disk' || !form.querySelector('input[name="create_thumbnails"]')?.checked) {
            return;
        }
        event.preventDefault();
        runImportWithThumbnailProgress(form);
    });
}


/**
 * Run a dry thumbnail maintenance check in browser-driven batches.
 *
 * @param {HTMLFormElement} form Submitted check form.
 */
async function runThumbnailMaintenanceCheck(form) {
    const progress = ensureThumbnailProgress(form);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });

    let offset = 0;
    let total = 0;
    let jobToken = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    updateThumbnailCheckProgress(progress, 0, 0, 0, 0, i18n('admin.thumbnails.check_preparing', 'Preparing thumbnail check...'));

    try {
        while (true) {
            const body = new FormData(form);
            body.set('ajax', '1');
            body.set('job_token', jobToken);
            body.set('offset', String(offset));
            body.set('batch_size', '150');

            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error(i18n('admin.thumbnails.check_request_failed', 'Thumbnail check request failed.'));
            }
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error || i18n('admin.thumbnails.check_request_failed', 'Thumbnail check request failed.'));
            }

            jobToken = result.job_token || jobToken;
            total = result.total || 0;
            offset = result.next_offset || 0;
            updateThumbnailCheckProgress(
                progress,
                result.processed || 0,
                total,
                result.images_with_missing || 0,
                result.missing_variants || 0,
                i18n('admin.thumbnails.check_running', 'Checking thumbnails...')
            );

            if (result.done) {
                const affectedImages = Number(result.images_with_missing || 0);
                const missingVariants = Number(result.missing_variants || 0);
                updateThumbnailCheckProgress(
                    progress,
                    total,
                    total,
                    affectedImages,
                    missingVariants,
                    i18n('admin.thumbnails.check_complete', 'Thumbnail check complete.')
                );
                setCreateMissingThumbnailRepairToken(result.repair_token || '');
                setCreateMissingThumbnailState(affectedImages > 0, createMissingThumbnailStateMessage(affectedImages, missingVariants));
                break;
            }
        }
    } catch (error) {
        updateThumbnailCheckProgress(progress, offset, total, 0, 0, i18n('admin.thumbnails.check_failed', 'Thumbnail check failed. Check the admin logs or PHP error log for details.'));
        setCreateMissingThumbnailRepairToken('');
        setCreateMissingThumbnailState(false, i18n('admin.thumbnails.create_missing_requires_successful_check', 'Run Check missing thumbnails successfully before targeted repair is available.'));
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Update the dry thumbnail maintenance check progress display.
 *
 * @param {HTMLElement} progress Progress root.
 * @param {number} processed Processed image count.
 * @param {number} total Total image count.
 * @param {number} affectedImages Images requiring at least one thumbnail variant.
 * @param {number} missingVariants Missing or stale variant count.
 * @param {string} label Status label.
 */
function updateThumbnailCheckProgress(progress, processed, total, affectedImages, missingVariants, label) {
    progress.hidden = false;
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
    progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
    progress.querySelector('[data-thumbnail-progress-text]').textContent = i18n(
        'admin.thumbnails.check_progress',
        '{label} {processed}/{total} images checked, {images} image(s) need {variants} thumbnail variant(s).',
        {label, processed, total, images: affectedImages, variants: missingVariants}
    );
}

/**
 * Remove generated legacy JPEG thumbnail derivatives in browser-driven batches.
 *
 * @param {HTMLFormElement} form Submitted cleanup form.
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
                throw new Error(i18n('admin.thumbnails.legacy_cleanup_request_failed', 'Legacy JPG cleanup request failed.'));
            }
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.error || i18n('admin.thumbnails.legacy_cleanup_request_failed', 'Legacy JPG cleanup request failed.'));
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
 * @return {string} Human-readable size.
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
 *
 * @param {*} form Value supplied by the caller or event context.
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
            throw new Error(i18n('admin.thumbnails.import_request_failed', 'Import request failed.'));
        }
        // importResult stores state or configuration for the gallery front-end flow.
        const importResult = await importResponse.json();
        if (importResult.ok === false) {
            throw new Error(importResult.error || importResult.message || i18n('admin.thumbnails.import_request_failed', 'Import request failed.'));
        }
        // galleryIds stores state or configuration for the gallery front-end flow.
        const galleryIds = Array.isArray(importResult.gallery_ids) ? importResult.gallery_ids : [];
        const scannedImages = Number(importResult.scanned || 0);
        if (galleryIds.length === 0 || scannedImages <= 0) {
            updateThumbnailProgress(progress, 0, 0, 0, 0, discoveryImportFinishedMessage(importResult));
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
        // failed stores thumbnail variants that the server could not create.
        let failed = 0;
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
                throw new Error(i18n('admin.thumbnails.request_failed', 'Thumbnail request failed.'));
            }
            // result stores state or configuration for the gallery front-end flow.
            const result = await response.json();
            if (result.ok === false) {
                throw new Error(result.error || i18n('admin.thumbnails.request_failed', 'Thumbnail request failed.'));
            }
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            failed += result.failed || 0;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, discoveryThumbnailRunningMessage(importResult));
            if (result.done) {
                updateThumbnailProgress(progress, total, total, created, skipped, 'Import and thumbnail job complete.');
                window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: created});
                break;
            }
        }
    } catch (error) {
        const message = error instanceof Error && error.message
            ? error.message
            : i18n('admin.galleries.discover_import_or_thumbnail_failed', 'Import or thumbnail job failed.');
        updateThumbnailProgress(progress, 0, 0, 0, 0, message);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}


/**
 * Return a completed import or move message for a no-thumbnail-needed workflow.
 *
 * @param {Object<string, *>} result Import or move result returned by the server.
 * @return {string} Browser-visible completion message.
 */
function discoveryImportFinishedMessage(result) {
    if (String(result?.action || '') === 'move_photos') {
        return i18n('admin.galleries.discover_move_ajax_complete', 'Move complete. Moved {moved} photo file(s) and scanned {scanned} image(s).', {
            moved: Number(result?.moved || 0),
            scanned: Number(result?.scanned || 0),
        });
    }

    return i18n('admin.galleries.discover_import_ajax_complete', 'Import complete. Imported {imported} gallery folder(s) and scanned {scanned} image(s).', {
        imported: Number(result?.imported || 0),
        scanned: Number(result?.scanned || 0),
    });
}

/**
 * Return the thumbnail-progress label after import or move scanning.
 *
 * @param {Object<string, *>} result Import or move result returned by the server.
 * @return {string} Browser-visible progress message.
 */
function discoveryThumbnailRunningMessage(result) {
    if (String(result?.action || '') === 'move_photos') {
        return i18n('admin.galleries.discover_move_thumbnail_running', 'Moved {moved} photo file(s), scanned {scanned} image(s). Creating thumbnails...', {
            moved: Number(result?.moved || 0),
            scanned: Number(result?.scanned || 0),
        });
    }

    return i18n('admin.galleries.discover_import_thumbnail_running', 'Imported {imported} gallery folder(s), scanned {scanned} image(s). Creating thumbnails...', {
        imported: Number(result?.imported || 0),
        scanned: Number(result?.scanned || 0),
    });
}

/**
 * Handles run thumbnail job behavior for the gallery UI.
 *
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} submitter Value supplied by the caller or event context.
 * @param {object} options Optional behavior flags.
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
    // Variable `failed` stores thumbnail variants that the server could not create.
    let failed = 0;
    // Variable `missingStateAfterJob` stores the final targeted-repair button state after buttons are restored.
    let missingStateAfterJob = null;
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
                throw new Error(i18n('admin.thumbnails.request_failed', 'Thumbnail request failed.'));
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            if (result.ok === false) {
                throw new Error(result.error || i18n('admin.thumbnails.request_failed', 'Thumbnail request failed.'));
            }
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            failed += result.failed || 0;
            const scopeLabel = options.scope === 'missing'
                ? 'missing thumbnails'
                : (options.scope === 'metadata' ? 'thumbnail database' : 'thumbnails');
            const activeLabel = options.scope === 'metadata' ? `Refreshing ${scopeLabel}...` : `Creating ${scopeLabel}...`;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, activeLabel);
            if (result.done) {
                // finalLabel keeps empty targeted jobs readable instead of showing only 0/0 counters.
                const finalLabel = options.scope === 'missing' && total === 0
                    ? 'No server-side repair queue was available. Run Check missing thumbnails first, then run Create missing thumbnails again.'
                    : (options.scope === 'metadata' ? 'Thumbnail database refresh complete.' : 'Thumbnail job complete.');
                updateThumbnailProgress(progress, total, total, created, skipped, finalLabel);
                if (options.scope === 'missing') {
                    if (result.maintenance_after && (result.maintenance_after.images_with_missing || 0) <= 0) {
                        document.querySelectorAll('.admin-thumbnail-maintenance-notice').forEach((notice) => {
                            notice.remove();
                        });
                        missingStateAfterJob = {
                            enabled: false,
                            message: i18n('admin.thumbnails.create_missing_none_after_repair', 'Targeted repair is complete. No missing or stale thumbnails remain in the latest maintenance state.')
                        };
                    } else if (result.remaining_image_count > 0) {
                        missingStateAfterJob = {
                            enabled: true,
                            message: i18n('admin.thumbnails.create_missing_remaining_after_repair', 'Targeted repair completed, but some images still need attention. You can run Create missing thumbnails again.', {})
                        };
                    }
                }
                if (failed > 0) {
                    updateThumbnailProgress(progress, total, total, created, skipped, `${finalLabel} ${failed} file(s) failed.`);
                }
                break;
            }
        }
    } catch (error) {
        const failedLabel = options.scope === 'metadata' ? 'Thumbnail database refresh failed.' : 'Thumbnail job failed.';
        const detail = error instanceof Error && error.message ? ` ${error.message}` : '';
        updateThumbnailProgress(progress, offset, total, created, skipped, `${failedLabel}${detail}`);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
        if (missingStateAfterJob) {
            setCreateMissingThumbnailState(missingStateAfterJob.enabled, missingStateAfterJob.message);
        }
    }
}
