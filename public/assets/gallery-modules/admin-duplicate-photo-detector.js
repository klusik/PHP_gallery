/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-duplicate-photo-detector.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Runs duplicate scanning, review-ledger actions, and explicit deletion through Admin AJAX requests.
 *
 * Responsibilities:
 *   - Enhance the existing POST forms without removing their non-JavaScript fallback
 *   - Continue detector jobs using only the opaque server-side job token
 *   - Update progress in place while preserving the existing Admin side panel
 *   - Replace the detector fragment with final server-rendered results
 *   - Surface translated request errors without closing the side panel
 *   - Delete an explicitly selected duplicate through the existing Admin image-deletion service without reloading the page
 *   - Persist pair/gallery ledger actions through the same in-panel AJAX fragment-refresh path
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
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const DUPLICATE_PHOTO_BATCH_SIZE = 200;

/**
 * Attach delegated duplicate detector form behavior.
 *
 * Delegation is required because the detector can be loaded into the existing
 * Admin side-panel shell after the main gallery entrypoint has already booted.
 */
export function setupAdminDuplicatePhotoDetector() {
    if (document.body?.dataset.adminDuplicatePhotoDetectorBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.adminDuplicatePhotoDetectorBound = '1';
    }

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.matches('[data-duplicate-photo-start-form], [data-duplicate-photo-step-form], [data-duplicate-photo-delete-form], [data-duplicate-photo-ledger-form]')) {
            return;
        }

        const root = form.closest('[data-duplicate-photo-detector]');
        if (!(root instanceof HTMLElement)) {
            return;
        }

        // Capture the detector form before any generic Admin form handler can
        // fall through to its normal-page action. This is especially important
        // for detector markup injected dynamically into the right-side panel.
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        if (form.matches('[data-duplicate-photo-delete-form]')) {
            runDuplicatePhotoDelete(root, form).catch((error) => {
                setDuplicatePhotoDetectorError(root, error instanceof Error ? error.message : i18n('admin.duplicate_photos.delete_failed_generic', 'Photo delete failed.'));
                setDuplicatePhotoDetectorBusy(root, false);
            });
            return;
        }
        if (form.matches('[data-duplicate-photo-ledger-form]')) {
            runDuplicatePhotoLedgerAction(root, form).catch((error) => {
                setDuplicatePhotoDetectorError(root, error instanceof Error ? error.message : i18n('admin.duplicate_photos.ledger_update_failed', 'Duplicate review ledger update failed.'));
                setDuplicatePhotoDetectorBusy(root, false);
            });
            return;
        }

        runDuplicatePhotoDetector(root, form).catch((error) => {
            setDuplicatePhotoDetectorError(root, error instanceof Error ? error.message : i18n('admin.duplicate_photos.request_failed', 'Duplicate detector request failed.'));
            setDuplicatePhotoDetectorBusy(root, false);
        });
    }, true);
}

/**
 * Start or continue a duplicate detector job until its bounded batches finish.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {HTMLFormElement} form Submitted start or continuation form.
 * @return {Promise<void>} Completion promise.
 */
async function runDuplicatePhotoDetector(root, form) {
    setDuplicatePhotoDetectorBusy(root, true);
    clearDuplicatePhotoDetectorError(root);

    const endpoint = root.dataset.duplicatePhotoEndpoint || form.action;
    if (!endpoint) {
        throw new Error(i18n('admin.duplicate_photos.request_failed', 'Duplicate detector request failed.'));
    }

    let payload = await postDuplicatePhotoDetectorForm(endpoint, new FormData(form));
    applyDuplicatePhotoDetectorProgress(root, payload);

    if (payload.done) {
        replaceDuplicatePhotoDetector(root, payload);
        return;
    }

    const csrfToken = duplicatePhotoDetectorCsrfToken(root);
    let jobToken = String(payload.job_token || duplicatePhotoDetectorJobToken(root) || '');
    if (!csrfToken || !jobToken) {
        throw new Error(i18n('admin.duplicate_photos.session_missing', 'The duplicate detector session could not be continued.'));
    }

    while (!payload.done) {
        const body = new FormData();
        body.set('csrf_token', csrfToken);
        body.set('action', 'step');
        body.set('job_token', jobToken);
        body.set('batch_size', String(DUPLICATE_PHOTO_BATCH_SIZE));
        body.set('ajax', '1');

        payload = await postDuplicatePhotoDetectorForm(endpoint, body);
        jobToken = String(payload.job_token || jobToken);
        applyDuplicatePhotoDetectorProgress(root, payload);
    }

    replaceDuplicatePhotoDetector(root, payload);
}

/**
 * Delete one explicit duplicate result and refresh the detector fragment in place.
 *
 * The server delegates filesystem/database cleanup to the existing gallery image
 * deletion service, then prunes the deleted id from the immutable detector job.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {HTMLFormElement} form Submitted delete form.
 * @return {Promise<void>} Completion promise.
 */
async function runDuplicatePhotoDelete(root, form) {
    setDuplicatePhotoDetectorBusy(root, true);
    clearDuplicatePhotoDetectorError(root);

    const endpoint = root.dataset.duplicatePhotoEndpoint || form.action;
    if (!endpoint) {
        throw new Error(i18n('admin.duplicate_photos.delete_failed_generic', 'Photo delete failed.'));
    }

    const payload = await postDuplicatePhotoDetectorForm(endpoint, new FormData(form));
    reflectDuplicatePhotoDeletionInCurrentView(payload);
    const replacement = replaceDuplicatePhotoDetector(root, payload);
    setDuplicatePhotoDetectorSuccess(replacement, String(payload.message || i18n('admin.duplicate_photos.delete_success_generic', 'Photo deleted.')));
}

/**
 * Persist one duplicate-review ledger action and refresh only the detector fragment.
 *
 * Pair ignores, exact-gallery ignores, and ledger clearing all use the same
 * side-panel JSON pipeline. The browser never changes scope identifiers beyond
 * the image/job values already rendered and validated by the server.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {HTMLFormElement} form Submitted ledger form.
 * @return {Promise<void>} Completion promise.
 */
async function runDuplicatePhotoLedgerAction(root, form) {
    setDuplicatePhotoDetectorBusy(root, true);
    clearDuplicatePhotoDetectorError(root);

    const endpoint = root.dataset.duplicatePhotoEndpoint || form.action;
    if (!endpoint) {
        throw new Error(i18n('admin.duplicate_photos.ledger_update_failed', 'Duplicate review ledger update failed.'));
    }

    const payload = await postDuplicatePhotoDetectorForm(endpoint, new FormData(form));
    const replacement = replaceDuplicatePhotoDetector(root, payload);
    setDuplicatePhotoDetectorSuccess(replacement, String(payload.message || i18n('admin.duplicate_photos.ledger_updated', 'Duplicate review ledger updated.')));
}

/**
 * Remove the deleted image from any gallery/editor content already visible behind the side panel.
 *
 * @param {Record<string, *>} payload Successful delete response.
 */
function reflectDuplicatePhotoDeletionInCurrentView(payload) {
    const imageId = String(payload.deleted_image_id || '');
    if (imageId === '') {
        return;
    }

    document.querySelectorAll(`[data-admin-image-order-row][data-image-id="${CSS.escape(imageId)}"], [data-lightbox-image][data-image-id="${CSS.escape(imageId)}"]`).forEach((element) => {
        if (element instanceof HTMLElement) {
            element.remove();
        }
    });
}

/**
 * POST one detector action and parse the existing Admin JSON response shape.
 *
 * @param {string} endpoint Detector endpoint URL.
 * @param {FormData} body Request body.
 * @return {Promise<Record<string, *>>} Parsed response payload.
 */
async function postDuplicatePhotoDetectorForm(endpoint, body) {
    body.set('ajax', '1');
    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    let payload = null;
    try {
        payload = await response.json();
    } catch (error) {
        throw new Error(i18n('admin.duplicate_photos.invalid_json', 'The duplicate detector returned an invalid JSON response.'));
    }

    if (!response.ok || !payload || payload.ok === false || payload.status === 'missing') {
        throw new Error(String(payload?.error || payload?.message || i18n('admin.duplicate_photos.request_failed', 'Duplicate detector request failed.')));
    }
    return payload;
}

/**
 * Return the CSRF token already rendered by one of the fallback forms.
 *
 * @param {HTMLElement} root Detector component root.
 * @return {string} CSRF token or an empty string.
 */
function duplicatePhotoDetectorCsrfToken(root) {
    const input = root.querySelector('input[name="csrf_token"]');
    return input instanceof HTMLInputElement ? input.value : '';
}

/**
 * Return an existing detector job token from the fallback continuation form.
 *
 * @param {HTMLElement} root Detector component root.
 * @return {string} Job token or an empty string.
 */
function duplicatePhotoDetectorJobToken(root) {
    const input = root.querySelector('input[name="job_token"]');
    return input instanceof HTMLInputElement ? input.value : '';
}

/**
 * Update the visible bounded-scan progress controls from a server response.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {Record<string, *>} payload Parsed detector state.
 */
function applyDuplicatePhotoDetectorProgress(root, payload) {
    const processed = Math.max(0, Number(payload.processed || 0));
    const total = Math.max(0, Number(payload.total || 0));
    const percent = Math.max(0, Math.min(100, Number(payload.percent || 0)));
    const progressCard = root.querySelector('[data-duplicate-photo-progress-card]');
    const progress = root.querySelector('[data-duplicate-photo-progress]');
    const count = root.querySelector('[data-duplicate-photo-progress-count]');
    const text = root.querySelector('[data-duplicate-photo-progress-text]');
    const scope = root.querySelector('[data-duplicate-photo-scope]');

    if (progressCard instanceof HTMLElement) {
        progressCard.hidden = false;
    }
    if (scope instanceof HTMLElement) {
        scope.textContent = payload.search_all
            ? i18n('admin.duplicate_photos.scope_all', 'Scope: all administrator-accessible galleries')
            : i18n('admin.duplicate_photos.scope_selected', 'Scope: selected gallery and all subgalleries');
    }
    if (progress instanceof HTMLProgressElement) {
        progress.value = percent;
    }
    if (count instanceof HTMLElement) {
        count.textContent = i18n('admin.duplicate_photos.progress_count', '{processed}/{total} images inspected', {
            processed: String(processed),
            total: String(total),
        });
    }
    if (text instanceof HTMLElement) {
        text.textContent = payload.done
            ? i18n('admin.duplicate_photos.scan_complete', 'Scan complete.')
            : i18n('admin.duplicate_photos.scan_running_ajax', 'Scanning stored metadata in bounded batches...');
    }
}

/**
 * Replace the current detector fragment with completed server-rendered results.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {Record<string, *>} payload Parsed completed detector state.
 * @return {HTMLElement} Replaced detector root.
 */
function replaceDuplicatePhotoDetector(root, payload) {
    if (typeof payload.panel_html !== 'string' || payload.panel_html.trim() === '') {
        throw new Error(i18n('admin.duplicate_photos.result_missing', 'The duplicate detector completed without result markup.'));
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = payload.panel_html.trim();
    const replacement = wrapper.firstElementChild;
    if (!(replacement instanceof HTMLElement)) {
        throw new Error(i18n('admin.duplicate_photos.result_missing', 'The duplicate detector completed without result markup.'));
    }

    root.replaceWith(replacement);
    return replacement;
}

/**
 * Disable or restore detector submit buttons during an AJAX scan.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {boolean} busy Busy state.
 */
function setDuplicatePhotoDetectorBusy(root, busy) {
    root.classList.toggle('is-busy', busy);
    root.querySelectorAll('button[type="submit"]').forEach((button) => {
        if (button instanceof HTMLButtonElement) {
            button.disabled = busy;
        }
    });
}

/**
 * Display one successful delete message inside the refreshed detector results.
 *
 * @param {HTMLElement} root Refreshed detector component root.
 * @param {string} message Success message.
 */
function setDuplicatePhotoDetectorSuccess(root, message) {
    const status = root.querySelector('[data-duplicate-photo-status]');
    if (!(status instanceof HTMLElement) || message === '') {
        return;
    }

    const notice = document.createElement('div');
    notice.className = 'notice admin-duplicate-photo-success';
    notice.dataset.duplicatePhotoSuccess = '1';
    notice.textContent = message;
    status.prepend(notice);
}

/**
 * Display one translated detector error without disturbing the side-panel shell.
 *
 * @param {HTMLElement} root Detector component root.
 * @param {string} message Error message.
 */
function setDuplicatePhotoDetectorError(root, message) {
    const status = root.querySelector('[data-duplicate-photo-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }

    let error = status.querySelector('[data-duplicate-photo-error]');
    if (!(error instanceof HTMLElement)) {
        error = document.createElement('div');
        error.className = 'notice is-alert admin-duplicate-photo-error';
        error.dataset.duplicatePhotoError = '1';
        status.prepend(error);
    }
    error.textContent = message;
}

/**
 * Remove a previous transient AJAX error before retrying the detector.
 *
 * @param {HTMLElement} root Detector component root.
 */
function clearDuplicatePhotoDetectorError(root) {
    const error = root.querySelector('[data-duplicate-photo-error]');
    if (error instanceof HTMLElement) {
        error.remove();
    }
}
