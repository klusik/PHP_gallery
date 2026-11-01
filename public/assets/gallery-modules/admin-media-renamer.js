/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-media-renamer.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Keeps the media renamer admin workspace in place during preview and apply actions.
 *
 * Responsibilities:
 *   - Submit renamer preview/apply forms through fetch when JavaScript is available
 *   - Show visible progress while the server performs filesystem and database work
 *   - Replace only the renamer panel or site-wide renamer workspace after completion
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
 *   2026-06-03
 */

import {i18nForElement} from './admin-core.js?v=20261003-scoped-i18n-v1';
import {captureAdminPanelOwner} from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

// Bound each physical request while retaining the existing site-wide cadence.
// Type: number; Units: image IDs per request; Scope: gallery/site rename runs.
// Consumers: submitApplyInBatches. Rationale: 15 matches gallery POST admission
// and gives visible server-confirmed progress without one long request.
const renameBatchSize = 15;

/**
 *  @typedef {{gallery_id?:number, galleries_requested?:number,
 *     galleries_processed?:number, renamed:number, already_matches:number,
 *     missing:number, skipped:number, collisions:number,
 *     derivatives_moved:number, derivatives_cleaned:number,
 *     derivative_failures:number, zip_archives_deleted:number,
 *     titles_updated:number, details:Array<{gallery_id:number, gallery:string,
 *     image_id:number, old:string, new:string, status:string, notes:string[]}>,
 *     warnings:string[], failures:string[]}} MediaRenameResult
 */

/**
 * @typedef {import('./admin-mutation-completion.js').MutationEnvelopeCandidate
 *     & {ok?:boolean, result?:MediaRenameResult, processed?:number,
 *     availability?:Record<string, {rename_count:number, warning_count:number}>,
 *     panel_html?:string, body_html?:string, error?:string, message?:string}}
 *     MediaRenamerPayload
 */

let mediaRenamerReady = false;

/**
 * Attach delegated media-renamer forms and local gallery filtering once per page.
 * @return {void} Registers listeners once without submitting requests.
 */
export function setupAdminMediaRenamer() {
    if (mediaRenamerReady) {
        return;
    }
    mediaRenamerReady = true;
    // Filtering only changes visibility: checked galleries remain in the submitted selection.
    document.addEventListener('input', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.matches('[data-media-renamer-search]')) {
            return;
        }
        // Quoted phrases stay together; every unquoted word must also occur, in any order.
        const terms = Array.from(input.value.toLocaleLowerCase().matchAll(/"([^"]+)"|([^\s"]+)/gu), (match) => match[1] || match[2]);
        const rows = input.form?.querySelectorAll('.admin-media-renamer-gallery-choice') || [];
        let shown = 0;
        rows.forEach((row) => {
            const text = Array.from(row.querySelectorAll('label strong, label small'), (part) => part.textContent).join(' ').toLocaleLowerCase();
            row.hidden = !terms.every((term) => text.includes(term));
            shown += row.hidden ? 0 : 1;
        });
        const status = input.form?.querySelector('[data-media-renamer-search-status]');
        if (status) {
            status.textContent = `${shown} / ${rows.length}`;
        }
    });
    document.addEventListener('submit', handleMediaRenamerSubmit);
    document.addEventListener('change', handleMediaRenamerSettingChange);
}

/**
 * Refresh only the site-wide selector when visibility filters change, without planning renames.
 *
 * @param {Event} event Browser change event.
 * @return {void} Submits the list-only action or ignores unrelated/disabled controls.
 */
function handleMediaRenamerSettingChange(event) {
    const control = event.target;
    if (!(control instanceof HTMLInputElement) || !['hide_empty_galleries', 'hide_done_galleries'].includes(control.name)) {
        return;
    }

    const form = control.form;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-media-renamer-form]')) {
        return;
    }

    const refreshButton = form.querySelector('[data-media-renamer-filter-refresh]');
    if (!(refreshButton instanceof HTMLButtonElement) || refreshButton.disabled) {
        return;
    }
    // Submit an explicit list-only action; a filter change must not build rename plans.
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(refreshButton);
    } else {
        // Preserve older browsers through the native button submission path with its action value.
        refreshButton.click();
    }
}

/**
 * Submit preview and apply forms without navigating away from the current view.
 *
 * @param {SubmitEvent} event Browser submit event.
 * @return {Promise<void>} Refreshes the workspace or displays the request failure in its progress area.
 */
async function handleMediaRenamerSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-media-renamer-form]')) {
        return;
    }

    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    const review = form.querySelector(
        'input[type="checkbox"][name="confirm_media_rename"]',
    );
    // Keep the explicit inline review even for synthetic submit events.
    // The server independently requires the same confirmation field.
    if (review instanceof HTMLInputElement && !review.checked) {
        event.preventDefault();
        review.reportValidity();
        return;
    }

    event.preventDefault();
    const workspace = findRenamerWorkspace(form);
    const mutationTarget = workspace?.parentElement || document;
    const sourcePanel = form.closest('[data-admin-side-panel]');
    const panelOwner = sourcePanel ? captureAdminPanelOwner(sourcePanel) : {
        isCurrent: () => mutationTarget.isConnected,
        signal: undefined,
    };
    const publishMutation = payload => {
        if (payload.mutation && Array.isArray(payload.contexts)) {
            (mutationTarget.isConnected ? mutationTarget : document).dispatchEvent(
                new CustomEvent('php-gallery:auxiliary-mutation-success', {
                    bubbles: true,
                    detail: {source: 'media-renamer', result: payload, panelOwner},
                }),
            );
        }
    };
    const progress = ensureRenamerProgress(form, workspace);
    // Single-response reads have no server progress samples; show activity without invented percentages.
    progress.dataset.indeterminate = !isAvailabilityCheckSubmitter(submitter) && !isBatchedApplyForm(form) ? '1' : '0';
    setRenamerProgress(progress, isBatchedApplyForm(form) ? 0 : 8, i18nForElement(form, 'admin.media_renamer.progress_starting', 'Preparing rename request...'));
    setRenamerFormDisabled(form, true);

    try {
        if (isAvailabilityCheckSubmitter(submitter)) {
            const payload = await submitAvailabilityCheckInBatches(form, submitter, progress);
            setRenamerProgress(progress, 92, i18nForElement(form, 'admin.media_renamer.progress_refreshing', 'Refreshing the renamer view...'));
            if (panelOwner.isCurrent()) replaceRenamerWorkspace(form, payload);
            setRenamerProgress(null, 100, '');
            return;
        }

        if (isBatchedApplyForm(form)) {
            const payload = await submitApplyInBatches(form, submitter, progress);
            setRenamerProgress(progress, 100, i18nForElement(form,
                'admin.media_renamer.progress_refreshing',
                'Refreshing the renamer view...',
            ));
            if (panelOwner.isCurrent()) replaceRenamerWorkspace(form, payload);
            publishMutation(payload);
            setRenamerProgress(null, 100, '');
            return;
        }

        const response = await submitRenamerForm(form, submitter, (percent, label) => setRenamerProgress(progress, percent, label));
        const payload = await parseRenamerJsonResponse(response, form);
        if (!response.ok || payload.ok === false) {
            throw new Error(payload.error || payload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
        }
        setRenamerProgress(progress, 92, i18nForElement(form, 'admin.media_renamer.progress_refreshing', 'Refreshing the renamer view...'));
        if (panelOwner.isCurrent()) replaceRenamerWorkspace(form, payload);
        publishMutation(payload);
        setRenamerProgress(null, 100, '');
    } catch (error) {
        if (error?.confirmedMutation) publishMutation(error.confirmedMutation);
        const bar = progress.querySelector('[data-admin-media-renamer-progress-bar]');
        progress.dataset.indeterminate = '0';
        setRenamerProgress(progress, Number(bar?.value || 0), error instanceof Error ? error.message : String(error));
        setRenamerFormDisabled(form, false);
    }
}


/**
 * Detect the explicit on-demand availability scan button.
 *
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @return {boolean} True when the submitter starts the availability scan.
 */
function isAvailabilityCheckSubmitter(submitter) {
    return submitter instanceof HTMLButtonElement
        && submitter.name === 'renamer_action'
        && submitter.value === 'check_availability';
}

/**
 * Detect gallery and site-wide physical apply forms with prepared image IDs.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {boolean} True when the form should be applied through photo batches.
 */
function isBatchedApplyForm(form) {
    const action = form.querySelector('input[name="renamer_action"]');
    return ((action instanceof HTMLInputElement
        && action.value === 'apply'
        && form.dataset.mediaRenamerTarget === '[data-admin-media-renamer-workspace=site]')
        || form.dataset.mediaRenamerApply === 'gallery')
        && form.querySelectorAll('input[name="rename_candidate_image_ids[]"]').length > 0;
}

/**
 * Run bounded physical rename batches with confirmed progress between requests.
 * Short requests also reduce the risk of shared-hosting request timeouts.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {HTMLElement|null} progress Progress element.
 * @return {Promise<
 *     import('./admin-mutation-completion.js').MutationEnvelopeCandidate
 *     & {result?:MediaRenameResult, panel_html?:string, body_html?:string,
 *     error?:string}>} Final server completion envelope and refreshed
 *     workspace.
 */
async function submitApplyInBatches(form, submitter, progress) {
    const imageIds = renameCandidateImageIdsFromForm(form);
    const total = imageIds.length;
    let processed = 0;
    const aggregate = emptyApplyResult();
    let confirmedMutation = null;
    aggregate.galleries_requested = galleryIdsFromForm(form).length;

    updateApplyButton(submitter, processed, total);
    setRenamerProgress(progress, 0, i18nForElement(form, 'admin.media_renamer.apply_progress_start', 'Starting physical rename batches...'));

    try {
        for (let offset = 0; offset < total; offset += renameBatchSize) {
            const batchIds = imageIds.slice(offset, offset + renameBatchSize);
            const response = await submitApplyBatch(form, batchIds);
            const payload = await parseRenamerJsonResponse(response, form);
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || payload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
            }
            if (payload.mutation && Array.isArray(payload.contexts)) {
                confirmedMutation = payload;
            }
            if (!Number.isSafeInteger(payload.processed) || payload.processed !== batchIds.length) {
                throw new Error(i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
            }
            mergeApplyResult(aggregate, payload.result || {});
            processed += payload.processed;
            updateApplyButton(submitter, processed, total);
            const percent = Math.round(processed * 100 / total);
            setRenamerProgress(progress, percent, i18nForElement(form, 'admin.media_renamer.apply_progress_counter', 'Processed photos {processed}/{total}...', {
                processed: String(processed),
                total: String(total),
            }));
        }

        aggregate.galleries_processed = aggregate.galleries_requested;
        const finalResponse = await submitApplyFinalRefresh(form, aggregate);
        const finalPayload = await parseRenamerJsonResponse(finalResponse, form);
        if (!finalResponse.ok || finalPayload.ok === false) {
            throw new Error(finalPayload.error || finalPayload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
        }

        return finalPayload;
    } catch (error) {
        if (error instanceof Error && confirmedMutation) error.confirmedMutation = confirmedMutation;
        throw error;
    }
}

/**
 * Return planned image ids from the apply form.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {string[]} Image ids in preview order.
 */
function renameCandidateImageIdsFromForm(form) {
    const ids = [];
    form.querySelectorAll('input[name="rename_candidate_image_ids[]"]').forEach((input) => {
        if (input instanceof HTMLInputElement && input.value && !ids.includes(input.value)) {
            ids.push(input.value);
        }
    });
    return ids;
}

/**
 * Return selected gallery ids from the apply form.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {string[]} Gallery ids.
 */
function galleryIdsFromForm(form) {
    const ids = [];
    form.querySelectorAll('input[name="gallery_ids[]"]').forEach((input) => {
        if (input instanceof HTMLInputElement && input.value && !ids.includes(input.value)) {
            ids.push(input.value);
        }
    });
    return ids;
}

/**
 * Submit one physical rename batch.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {string[]} batchIds Image ids in this batch.
 * @return {Promise<Response>} Fetch response.
 */
async function submitApplyBatch(form, batchIds) {
    const body = new FormData(form);
    body.delete('rename_candidate_image_ids[]');
    body.delete('batch_image_ids[]');
    batchIds.forEach((imageId) => body.append('batch_image_ids[]', imageId));
    body.set('renamer_action', 'apply_batch');
    if (form.dataset.mediaRenamerApply === 'gallery') body.set('action', 'rename_files');
    body.set('ajax', '1');

    const requestUrl = formActionUrl(form);
    const response = await fetch(requestUrl, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Submit the final refresh after all physical rename batches have completed.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {MediaRenameResult} aggregate Server-confirmed counters and per-image
 *     outcomes from all batches.
 * @return {Promise<Response>} Fetch response.
 */
async function submitApplyFinalRefresh(form, aggregate) {
    const body = new FormData(form);
    body.delete('rename_candidate_image_ids[]');
    body.set('renamer_action', 'apply_refresh');
    if (form.dataset.mediaRenamerApply === 'gallery') body.set('action', 'rename_files');
    body.set('batch_result_payload', JSON.stringify(aggregate));
    body.set('ajax', '1');

    const requestUrl = formActionUrl(form);
    const response = await fetch(requestUrl, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Return an empty aggregate result object matching the PHP result shape.
 *
 * @return {object} Mutable aggregate result.
 */
function emptyApplyResult() {
    return {
        galleries_requested: 0,
        galleries_processed: 0,
        renamed: 0,
        already_matches: 0,
        missing: 0,
        skipped: 0,
        collisions: 0,
        derivatives_moved: 0,
        derivatives_cleaned: 0,
        derivative_failures: 0,
        zip_archives_deleted: 0,
        titles_updated: 0,
        details: [],
        warnings: [],
        failures: [],
    };
}

/**
 * Merge one batch result into the aggregate result.
 *
 * @param {object} target Aggregate result.
 * @param {object} source Batch result from PHP.
 */
function mergeApplyResult(target, source) {
    ['galleries_processed', 'renamed', 'already_matches', 'missing', 'skipped', 'collisions', 'derivatives_moved', 'derivatives_cleaned', 'derivative_failures', 'zip_archives_deleted', 'titles_updated'].forEach((key) => {
        target[key] = Number(target[key] || 0) + Number(source[key] || 0);
    });
    ['details', 'warnings', 'failures'].forEach((key) => {
        if (Array.isArray(source[key])) {
            target[key].push(...source[key]);
        }
    });
    target.details = target.details.slice(0, 500);
    target.warnings = target.warnings.slice(0, 50);
    target.failures = target.failures.slice(0, 50);
}

/**
 * Update the physical apply button with a processed/total counter.
 *
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {number} processed Processed photo count.
 * @param {number} total Total photo count.
 * @return {void} Updates a live submit button, or ignores another submitter.
 */
function updateApplyButton(submitter, processed, total) {
    if (!(submitter instanceof HTMLButtonElement)) {
        return;
    }
    const baseLabel = submitter.dataset.originalLabel || submitter.textContent || i18nForElement(submitter, 'admin.media_renamer.apply_site_button', 'Apply planned renames');
    submitter.dataset.originalLabel = baseLabel;
    submitter.textContent = `${baseLabel} ${processed}/${total}`;
}

/**
 * Run rename-availability checks in small AJAX batches so the button can show a real counter.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {HTMLElement|null} progress Progress element.
 * @return {Promise<MediaRenamerPayload>} Final workspace JSON payload;
 *     rejects failed or invalid endpoint responses.
 */
async function submitAvailabilityCheckInBatches(form, submitter, progress) {
    const galleryIds = availabilityGalleryIdsFromForm(form);
    const total = galleryIds.length;
    const availability = {};
    const batchSize = 20;
    let processed = 0;

    updateAvailabilityButton(submitter, processed, total);
    setRenamerProgress(progress, 12, i18nForElement(form, 'admin.media_renamer.availability_progress_start', 'Checking rename availability...'));

    if (total > 0) {
        for (let offset = 0; offset < total; offset += batchSize) {
            const batchIds = galleryIds.slice(offset, offset + batchSize);
            const response = await submitAvailabilityBatch(form, batchIds);
            const payload = await parseRenamerJsonResponse(response, form);
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || payload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
            }
            Object.assign(availability, payload.availability || {});
            processed += batchIds.length;
            updateAvailabilityButton(submitter, processed, total);
            const percent = total > 0 ? 12 + Math.round((processed / total) * 72) : 84;
            setRenamerProgress(progress, percent, i18nForElement(form, 'admin.media_renamer.availability_progress_counter', 'Checked {processed}/{total} galleries...', {
                processed: String(processed),
                total: String(total),
            }));
        }
    }

    const finalResponse = await submitAvailabilityFinalRefresh(form, availability);
    const finalPayload = await parseRenamerJsonResponse(finalResponse, form);
    if (!finalResponse.ok || finalPayload.ok === false) {
        throw new Error(finalPayload.error || finalPayload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
    }

    return finalPayload;
}

/**
 * Return gallery ids currently present in the site-wide gallery table.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {string[]} Gallery ids in current UI order.
 */
function availabilityGalleryIdsFromForm(form) {
    const ids = [];
    form.querySelectorAll('input[name="gallery_ids[]"]').forEach((input) => {
        if (input instanceof HTMLInputElement && input.value && !ids.includes(input.value)) {
            ids.push(input.value);
        }
    });
    return ids;
}

/**
 * Submit one availability-check batch.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {string[]} batchIds Gallery ids in this batch.
 * @return {Promise<Response>} Fetch response.
 */
async function submitAvailabilityBatch(form, batchIds) {
    const body = new FormData(form);
    body.delete('gallery_ids[]');
    batchIds.forEach((galleryId) => body.append('gallery_ids[]', galleryId));
    body.set('renamer_action', 'check_availability_batch');
    body.set('ajax', '1');

    const requestUrl = formActionUrl(form);
    const response = await fetch(requestUrl, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Submit the final refresh after all availability batches have completed.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {object} availability Availability counts keyed by gallery id.
 * @return {Promise<Response>} Fetch response.
 */
async function submitAvailabilityFinalRefresh(form, availability) {
    const body = new FormData(form);
    body.set('renamer_action', 'check_availability');
    body.set('rename_availability_checked', '1');
    body.set('rename_availability_payload', JSON.stringify(availability));
    body.set('ajax', '1');

    const requestUrl = formActionUrl(form);
    const response = await fetch(requestUrl, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Resolve the explicit form action URL.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {string} Absolute request URL.
 */
function formActionUrl(form) {
    const action = form.getAttribute('action') || window.location.href;
    return new URL(action, window.location.href).toString();
}

/**
 * Update the availability scan button with a processed/total counter.
 *
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {number} processed Processed gallery count.
 * @param {number} total Total gallery count.
 * @return {void} Updates a live submit button, or ignores another submitter.
 */
function updateAvailabilityButton(submitter, processed, total) {
    if (!(submitter instanceof HTMLButtonElement)) {
        return;
    }
    const baseLabel = submitter.dataset.originalLabel || submitter.textContent || i18nForElement(submitter, 'admin.media_renamer.check_availability_button', 'Check availability');
    submitter.dataset.originalLabel = baseLabel;
    submitter.textContent = `${baseLabel} ${processed}/${total}`;
}

/**
 * Submit one renamer form and return the JSON response.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {function(number, string):void} updateProgress Receives the percentage and visible status label.
 * @return {Promise<Response>} Fetch response.
 */
async function submitRenamerForm(form, submitter, updateProgress) {
    const method = (form.method || 'get').toLowerCase();
    const action = submitter?.getAttribute('formaction') || form.getAttribute('action') || window.location.href;
    const url = new URL(action, window.location.href);

    updateProgress(18, i18nForElement(form, 'admin.media_renamer.progress_sending', 'Sending request to the server...'));
    if (method === 'get') {
        const params = new URLSearchParams(new FormData(form));
        if (submitter instanceof HTMLButtonElement && submitter.name) {
            params.set(submitter.name, submitter.value);
        }
        params.set('ajax', '1');
        url.search = params.toString();
        updateProgress(45, i18nForElement(form, 'admin.media_renamer.progress_preview', 'Building preview from current database state...'));
        const requestUrl = url.toString();
        const response = await fetch(requestUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        response.mediaRenamerRequestUrl = requestUrl;
        return response;
    }

    const body = new FormData(form);
    if (submitter instanceof HTMLButtonElement && submitter.name) {
        body.set(submitter.name, submitter.value);
    }
    body.set('ajax', '1');
    const requestAction = body.get('renamer_action');
    updateProgress(35, requestAction === 'filter'
        ? i18nForElement(form, 'admin.media_renamer.progress_refreshing', 'Refreshing the renamer view...')
        : requestAction === 'preview'
            ? i18nForElement(form, 'admin.media_renamer.progress_preview', 'Building preview from current database state...')
            : i18nForElement(form, 'admin.media_renamer.progress_running', 'Renaming files, updating database rows, and refreshing derived assets...'));
    const requestUrl = url.toString();
    const response = await fetch(requestUrl, {
        method: 'POST',
        body,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Find the workspace that should show progress.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @return {HTMLElement|null} Workspace element.
 */
function findRenamerWorkspace(form) {
    const selector = form.dataset.mediaRenamerTarget || '';
    if (selector) {
        const target = document.querySelector(selector);
        if (target instanceof HTMLElement) {
            return target;
        }
    }
    return form.closest('[data-admin-media-renamer-workspace]');
}

/**
 * Ensure a progress indicator exists near the submitted form.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLElement|null} workspace Workspace element.
 * @return {HTMLElement} Progress element.
 */
function ensureRenamerProgress(form, workspace) {
    let progress = workspace?.querySelector('[data-admin-media-renamer-progress]');
    if (progress instanceof HTMLElement) {
        progress.hidden = false;
        return progress;
    }

    progress = document.createElement('div');
    progress.className = 'thumbnail-progress admin-media-renamer-progress';
    progress.dataset.adminMediaRenamerProgress = 'true';
    progress.innerHTML = '<progress class="thumbnail-progress-bar" value="0" max="100" data-admin-media-renamer-progress-bar></progress><p class="muted" data-admin-media-renamer-progress-text></p>';
    form.insertAdjacentElement('afterend', progress);
    return progress;
}

/**
 * Update progress text and bar.
 *
 * @param {HTMLElement|null} progress Progress element.
 * @param {number} percent Percentage value.
 * @param {string} label Visible status label.
 * @return {void} Updates the activity indicator and announces the current request status.
 */
function setRenamerProgress(progress, percent, label) {
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    progress.hidden = false;
    const bar = progress.querySelector('[data-admin-media-renamer-progress-bar]');
    if (bar instanceof HTMLProgressElement) {
        if (progress.dataset.indeterminate === '1' && percent < 100) {
            bar.removeAttribute('value');
        } else {
            bar.value = Math.max(0, Math.min(100, percent));
        }
    }
    const text = progress.querySelector('[data-admin-media-renamer-progress-text]');
    if (text instanceof HTMLElement) {
        text.textContent = label;
    }
}

/**
 * Disable or re-enable form buttons during an AJAX run.
 *
 * Inputs remain enabled so FormData keeps the selected gallery ids, CSRF token,
 * pattern, and confirmation checkbox exactly as the admin submitted them.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {boolean} disabled Disabled state.
 */
function setRenamerFormDisabled(form, disabled) {
    form.querySelectorAll('button').forEach((control) => {
        if (control instanceof HTMLButtonElement) {
            control.disabled = disabled;
        }
    });
}


/**
 * Parse the server response as JSON and log non-JSON diagnostics back to Admin Logs.
 *
 * @param {Response} response Fetch response.
 * @param {HTMLFormElement} form Submitted form.
 * @return {Promise<MediaRenamerPayload>} Decoded endpoint contract; rejects
 *     non-JSON or malformed JSON after reporting the diagnostic.
 */
async function parseRenamerJsonResponse(response, form) {
    const contentType = response.headers.get('content-type') || '';
    const requestUrl = typeof response.mediaRenamerRequestUrl === 'string' ? response.mediaRenamerRequestUrl : (response.url || '');
    const text = await response.text();
    const trimmed = text.trimStart();
    const snippet = trimmed.slice(0, 1200);

    if (!contentType.toLowerCase().includes('application/json')) {
        const message = buildNonJsonMessage(response, contentType, snippet, form);
        await logRenamerClientError(form, {
            message,
            status: response.status,
            statusText: response.statusText || '',
            contentType,
            responseUrl: response.url || '',
            requestUrl,
            redirected: response.redirected,
            snippet,
        });
        throw new Error(message);
    }

    try {
        return JSON.parse(text);
    } catch (error) {
        const message = error instanceof Error
            ? error.message
            : i18nForElement(form, 'admin.media_renamer.invalid_json', 'Server returned invalid JSON.');
        await logRenamerClientError(form, {
            message,
            status: response.status,
            statusText: response.statusText || '',
            contentType,
            responseUrl: response.url || '',
            requestUrl,
            redirected: response.redirected,
            snippet,
        });
        throw new Error(i18nForElement(form, 'admin.media_renamer.invalid_json_with_snippet', 'Server returned invalid JSON. First response bytes: {snippet}', {snippet: snippet.slice(0, 180)}));
    }
}

/**
 * Build a readable browser-side error for an HTML/text response.
 *
 * @param {Response} response Fetch response.
 * @param {string} contentType Response content type.
 * @param {string} snippet First response bytes.
 * @param {HTMLFormElement} form Submitted form owning the Admin language.
 * @return {string} Visible error text.
 */
function buildNonJsonMessage(response, contentType, snippet, form) {
    const prefix = response.redirected
        ? i18nForElement(form, 'admin.media_renamer.non_json_redirect', 'Server redirected the AJAX request and returned non-JSON content.')
        : i18nForElement(form, 'admin.media_renamer.non_json_response', 'Server returned non-JSON content to the AJAX request.');
    const details = [
        `HTTP ${response.status}`,
        contentType ? `Content-Type: ${contentType}` : '',
        snippet ? `First response bytes: ${snippet.slice(0, 180)}` : '',
    ].filter(Boolean).join(' | ');
    return `${prefix} ${details}`.trim();
}

/**
 * Send browser-detected non-JSON response diagnostics to the PHP Admin Logs.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {object} details Diagnostic details.
 */
async function logRenamerClientError(form, details) {
    const token = form.querySelector('input[name="csrf_token"]');
    if (!(token instanceof HTMLInputElement) || !token.value) {
        return;
    }

    const workspace = findRenamerWorkspace(form);
    const logUrl = workspace?.dataset.mediaRenamerLogUrl || form.dataset.mediaRenamerLogUrl || '';
    if (!logUrl) {
        return;
    }

    const body = new FormData();
    body.set('csrf_token', token.value);
    body.set('ajax', '1');
    body.set('renamer_action', 'client_error');
    body.set('message', String(details.message || '').slice(0, 500));
    body.set('status', String(details.status || 0));
    body.set('status_text', String(details.statusText || '').slice(0, 120));
    body.set('content_type', String(details.contentType || '').slice(0, 160));
    body.set('response_url', String(details.responseUrl || '').slice(0, 500));
    body.set('request_url', String(details.requestUrl || '').slice(0, 500));
    body.set('redirected', details.redirected ? '1' : '0');
    body.set('snippet', String(details.snippet || '').slice(0, 1500));
    body.set('workspace', workspace?.dataset.adminMediaRenamerWorkspace || 'unknown');
    body.set('current_url', window.location.href.slice(0, 500));
    body.set('form_action', (form.getAttribute('action') || '').slice(0, 500));

    try {
        await fetch(logUrl, {
            method: 'POST',
            body,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    } catch (error) {
        console.warn('Media renamer diagnostic log request failed.', error);
    }
}

/**
 * Replace the current renamer workspace from the JSON response.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {MediaRenamerPayload} payload Confirmed response with replacement
 *     panel_html or body_html markup.
 * @return {void} Replaces the owned workspace, or throws for missing markup
 *     or a missing destination.
 */
function replaceRenamerWorkspace(form, payload) {
    const selector = form.dataset.mediaRenamerTarget || '';
    const target = selector ? document.querySelector(selector) : findRenamerWorkspace(form);
    if (!(target instanceof HTMLElement)) {
        throw new Error(i18nForElement(form, 'admin.media_renamer.refresh_target_missing', 'The rename completed, but its panel result could not be refreshed. Reopen the tool to verify the saved result.'));
    }

    if (typeof payload.panel_html === 'string' && payload.panel_html !== '') {
        target.innerHTML = payload.panel_html;
        return;
    }
    if (typeof payload.body_html === 'string' && payload.body_html !== '') {
        target.outerHTML = payload.body_html;
        return;
    }
    throw new Error(i18nForElement(form, 'admin.media_renamer.refresh_markup_missing', 'The rename completed, but the server did not return refreshed panel markup. Reopen the tool to verify the saved result.'));
}
