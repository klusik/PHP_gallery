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

// Bound ID/sequence apply preparation independently of filename rendering.
// Type: number. Units: image IDs per response. Scope: lazy apply preparation.
// Consumers: loadRenameCandidateIds. Rationale: 500 matches the server's
// MEDIA_RENAMER_CANDIDATE_PAGE_SIZE without eager hidden-input lists.
const renameCandidatePageSize = 500;

// Bound compact warning/failure messages without retaining file-detail arrays.
// Type: number. Units: messages per run. Scope: browser rename progress.
// Consumers: mergeApplyResult. Rationale: 50 preserves the existing feedback cap;
// full requested filename evidence remains in the authenticated server report.
const renameResultMessageLimit = 50;

/** @type {WeakMap<HTMLElement, AbortController>} Active explicit detail reads. */
const detailRequests = new WeakMap();

/** @type {WeakMap<HTMLFormElement, Map<string, number>>} Original positions frozen before any batch mutates filenames. */
const candidateSequences = new WeakMap();

/**
 *  @typedef {{gallery_id?:number, galleries_requested?:number,
 *     galleries_processed?:number, renamed:number, already_matches:number,
 *     missing:number, skipped:number, collisions:number,
 *     derivatives_moved:number, derivatives_cleaned:number,
 *     derivative_failures:number, zip_archives_deleted:number,
 *     titles_updated:number, details:Array<{gallery_id:number, gallery:string,
 *     image_id:number, old:string, new:string, status:string, notes:string[]}>,
 *     warnings:string[], failures:string[], report_token?:string}} MediaRenameResult
 */

/**
 * @typedef {import('./admin-mutation-completion.js').MutationEnvelopeCandidate
 *     & {ok?:boolean, result?:MediaRenameResult, processed?:number,
 *     availability?:Record<string, {rename_count:number, warning_count:number}>,
 *     candidate_ids?:number[], next_offset?:number|null, detail_html?:string,
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
    document.addEventListener('toggle', handleMediaRenamerDetailToggle, true);
    document.addEventListener('click', handleMediaRenamerDetailMore);
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
 * Start one explicit disclosure read, or cancel its obsolete passive request.
 * @param {Event} event Native details toggle captured from a dynamic workspace.
 * @return {void} Leaves closed/previously loaded disclosures network-free.
 */
function handleMediaRenamerDetailToggle(event) {
    const details = event.target;
    if (!(details instanceof HTMLElement) || !details.matches('[data-media-renamer-detail]')) return;
    if (!details.open) {
        detailRequests.get(details)?.abort();
        detailRequests.delete(details);
        details.removeAttribute('aria-busy');
        const button = details.querySelector('[data-media-renamer-detail-more]');
        if (button instanceof HTMLButtonElement) button.disabled = false;
    } else if (details.dataset.loaded !== '1') {
        void loadRenamerDetail(details, 0);
    }
}

/**
 * Request another bounded page only after the user activates its action.
 * @param {MouseEvent} event Delegated click from a dynamically rendered detail row.
 * @return {void} Starts a passive page read and prevents unrelated form submission.
 */
function handleMediaRenamerDetailMore(event) {
    const button = event.target instanceof HTMLElement
        ? event.target.closest('[data-media-renamer-detail-more]') : null;
    const details = button?.closest('[data-media-renamer-detail]');
    if (!(details instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) return;
    event.preventDefault();
    void loadRenamerDetail(details, Number(button.dataset.detailOffset || 0));
}

/**
 * Fetch names only for the currently expanded gallery and requested page.
 * @param {HTMLElement} details Owned native disclosure in the current workspace.
 * @param {number} offset Nonnegative page displacement supplied by the server.
 * @return {Promise<void>} Commits current bounded HTML or an explicit retry action.
 */
async function loadRenamerDetail(details, offset) {
    if (detailRequests.has(details) || !Number.isSafeInteger(offset) || offset < 0) return;
    const workspace = details.closest('[data-admin-media-renamer-workspace]');
    const form = workspace?.querySelector('[data-media-renamer-reader]');
    const target = details.querySelector('[data-media-renamer-detail-body]');
    const status = details.querySelector('[data-media-renamer-detail-status]');
    const more = details.querySelector('[data-media-renamer-detail-more]');
    if (!(form instanceof HTMLFormElement) || !target || !status || !more) return;
    const controller = new AbortController();
    detailRequests.set(details, controller);
    details.setAttribute('aria-busy', 'true');
    more.disabled = true;
    status.hidden = false;
    status.textContent = i18nForElement(details, 'admin.media_renamer.loading_files', 'Loading file details...');
    try {
        const response = await submitRenamerRead(form, details.dataset.detailMode || '', {
            detail_gallery_id: details.dataset.detailGallery || '',
            detail_offset: String(offset),
            renamer_pattern: details.dataset.detailPattern || '',
            report_token: details.dataset.reportToken || '',
        }, controller.signal);
        const payload = await parseRenamerJsonResponse(response, form);
        if (controller.signal.aborted || !details.isConnected || !details.open
            || detailRequests.get(details) !== controller) return;
        const next = payload.next_offset;
        if (!response.ok || payload.ok === false || typeof payload.detail_html !== 'string'
            || (next !== null && (!Number.isSafeInteger(next) || next <= offset))) {
            throw new Error(payload.error || i18nForElement(details,
                'admin.media_renamer.detail_failed', 'File details could not be loaded. Try again.'));
        }
        target.insertAdjacentHTML('beforeend', payload.detail_html);
        details.dataset.loaded = '1';
        status.hidden = true;
        more.hidden = next === null;
        more.dataset.detailOffset = String(next ?? 0);
        more.textContent = i18nForElement(details, 'admin.media_renamer.load_more_files', 'Load more files');
    } catch (error) {
        if (controller.signal.aborted || !details.isConnected
            || detailRequests.get(details) !== controller) return;
        status.textContent = error instanceof Error ? error.message : String(error);
        more.hidden = false;
        more.dataset.detailOffset = String(offset);
        more.textContent = i18nForElement(details, 'admin.media_renamer.retry_files', 'Retry loading files');
    } finally {
        if (detailRequests.get(details) === controller) {
            detailRequests.delete(details);
            details.removeAttribute('aria-busy');
            more.disabled = false;
        }
    }
}

/**
 * Send a small authenticated passive read through the central renamer endpoint.
 * @param {HTMLFormElement} form Reader/operation form supplying the CSRF token.
 * @param {string} action Fixed read-only action name.
 * @param {Record<string, string>} fields Explicit gallery, cursor, pattern and report selectors.
 * @param {AbortSignal|undefined} signal Optional cancellation of obsolete detail reads.
 * @return {Promise<Response>} Same-origin JSON response with its diagnostic URL.
 */
async function submitRenamerRead(form, action, fields, signal = undefined) {
    const workspace = findRenamerWorkspace(form);
    const reader = workspace?.querySelector('[data-media-renamer-reader]');
    const source = reader instanceof HTMLFormElement ? reader : form;
    const body = new FormData(source);
    body.set('renamer_action', action);
    body.set('ajax', '1');
    Object.entries(fields).forEach(([key, value]) => body.set(key, value));
    const requestUrl = formActionUrl(source);
    const response = await fetch(requestUrl, {
        method: 'POST', body, credentials: 'same-origin', signal,
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    });
    response.mediaRenamerRequestUrl = requestUrl;
    return response;
}

/**
 * Freeze candidate IDs and original positions at apply time without file rows.
 * @param {HTMLFormElement} form Confirmed apply form with selected galleries.
 * @return {Promise<string[]>} Ordered unique image IDs for the existing physical batches.
 */
async function loadRenameCandidateIds(form) {
    const ids = [];
    const sequences = new Map();
    const seen = new Set();
    const pattern = String(new FormData(form).get('renamer_pattern') || '');
    for (const galleryId of galleryIdsFromForm(form)) {
        let offset = 0;
        do {
            const response = await submitRenamerRead(form, 'candidate_page', {
                detail_gallery_id: galleryId, detail_offset: String(offset), renamer_pattern: pattern,
            });
            const payload = await parseRenamerJsonResponse(response, form);
            const next = payload.next_offset;
            if (!response.ok || payload.ok === false || !Array.isArray(payload.candidate_ids)
                || !Array.isArray(payload.candidate_sequences)
                || payload.candidate_sequences.length !== payload.candidate_ids.length
                || payload.candidate_ids.length > renameCandidatePageSize
                || (next !== null && (!Number.isSafeInteger(next) || next <= offset))) {
                throw new Error(payload.error || i18nForElement(form,
                    'admin.media_renamer.progress_failed', 'Rename request failed.'));
            }
            for (const [index, id] of payload.candidate_ids.entries()) {
                const sequence = payload.candidate_sequences[index];
                if (!Number.isSafeInteger(id) || id <= 0 || seen.has(id)
                    || !Number.isSafeInteger(sequence) || sequence <= 0) {
                    throw new Error(i18nForElement(form,
                        'admin.media_renamer.progress_failed', 'Rename request failed.'));
                }
                seen.add(id);
                ids.push(String(id));
                sequences.set(String(id), sequence);
            }
            offset = next;
        } while (offset !== null);
    }
    candidateSequences.set(form, sequences);
    return ids;
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
        && (form.dataset.mediaRenamerLazyCandidates === '1'
            || form.querySelectorAll('input[name="rename_candidate_image_ids[]"]').length > 0);
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
    const workspace = findRenamerWorkspace(form);
    workspace?.querySelectorAll('[data-media-renamer-proposals], [data-media-renamer-report]')
        .forEach(node => node.replaceChildren());
    if (progress) progress.dataset.indeterminate = '1';
    const preparedIds = renameCandidateImageIdsFromForm(form);
    const imageIds = preparedIds.length > 0 ? preparedIds : await loadRenameCandidateIds(form);
    if (progress) progress.dataset.indeterminate = '0';
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
            const response = await submitApplyBatch(form, batchIds, aggregate.report_token || '');
            const payload = await parseRenamerJsonResponse(response, form);
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || payload.message || i18nForElement(form, 'admin.media_renamer.progress_failed', 'Rename request failed.'));
            }
            if (payload.mutation && Array.isArray(payload.contexts)
                && (!confirmedMutation || payload.mutation.entity_ids?.length > 0)) {
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

        // Keep the actual confirmed batch's completion contract when the
        // final read only returns compact report/markup data. No filename
        // arrays or locally invented public revision are needed for refresh.
        return confirmedMutation ? {...finalPayload,
            mutation: confirmedMutation.mutation, contexts: confirmedMutation.contexts,
        } : finalPayload;
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
 * @param {string} reportToken Server-side report identity from earlier confirmed batches.
 * @return {Promise<Response>} Fetch response.
 */
async function submitApplyBatch(form, batchIds, reportToken) {
    const body = new FormData(form);
    body.delete('rename_candidate_image_ids[]');
    body.delete('batch_image_ids[]');
    body.delete('batch_sequence_numbers[]');
    batchIds.forEach((imageId) => body.append('batch_image_ids[]', imageId));
    const sequences = candidateSequences.get(form);
    if (sequences) {
        batchIds.forEach((imageId) => body.append('batch_sequence_numbers[]', String(sequences.get(imageId))));
    }
    body.set('renamer_action', 'apply_batch');
    body.set('report_token', reportToken);
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
 * @return {MediaRenameResult} Mutable counters and report identity without filenames.
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
        report_token: '',
    };
}

/**
 * Merge one batch result into the aggregate result.
 *
 * @param {MediaRenameResult} target Aggregate result without eager file details.
 * @param {Partial<MediaRenameResult>} source Confirmed batch counters and report identity.
 * @return {void} Merges counters/messages while keeping filename details on the server.
 */
function mergeApplyResult(target, source) {
    ['galleries_processed', 'renamed', 'already_matches', 'missing', 'skipped', 'collisions', 'derivatives_moved', 'derivatives_cleaned', 'derivative_failures', 'zip_archives_deleted', 'titles_updated'].forEach((key) => {
        target[key] = Number(target[key] || 0) + Number(source[key] || 0);
    });
    ['warnings', 'failures'].forEach((key) => {
        if (Array.isArray(source[key])) {
            target[key].push(...source[key]);
        }
    });
    target.warnings = target.warnings.slice(0, renameResultMessageLimit);
    target.failures = target.failures.slice(0, renameResultMessageLimit);
    if (typeof source.report_token === 'string' && /^[a-f0-9]{32}$/u.test(source.report_token)) {
        target.report_token = source.report_token;
    }
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
