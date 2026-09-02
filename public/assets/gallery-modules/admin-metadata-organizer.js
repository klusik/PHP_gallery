/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-metadata-organizer.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Runs the metadata organizer preview and apply workflow in AJAX batches.
 *
 * Responsibilities:
 *   - Keep the gallery edit panel mounted during organizer preview requests
 *   - Build the EXIF-date draft from server-side database batches
 *   - Apply confirmed physical moves in small requests to reduce timeout risk
 *   - Show progress and a compact operation log for large galleries
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
 *   2026-09-02
 */

import {escapeHtmlAttribute, escapeHtmlText, i18n} from './admin-core.js?v=20260614-upload-order-v2';

let metadataOrganizerReady = false;

const ROOT_SELECTOR = '[data-admin-metadata-organizer]';
const PREVIEW_FORM_SELECTOR = '[data-admin-metadata-organizer-preview-form]';
const APPLY_FORM_SELECTOR = '[data-admin-metadata-organizer-apply-form]';

/**
 * Attach delegated metadata organizer handling once per page.
 */
export function setupAdminMetadataOrganizer() {
    if (metadataOrganizerReady) {
        return;
    }
    metadataOrganizerReady = true;
    document.addEventListener('submit', handleMetadataOrganizerSubmit, true);
}

/**
 * Route preview and apply forms through the AJAX batch workflow.
 *
 * @param {SubmitEvent} event Browser submit event.
 */
async function handleMetadataOrganizerSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.matches(PREVIEW_FORM_SELECTOR)) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await previewMetadataOrganizerDraft(form, event.submitter instanceof HTMLElement ? event.submitter : null);
        return;
    }

    if (form.matches(APPLY_FORM_SELECTOR)) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await applyMetadataOrganizerDraft(form, event.submitter instanceof HTMLElement ? event.submitter : null);
    }
}

/**
 * Build a preview draft through paged database requests.
 *
 * @param {HTMLFormElement} form Preview form.
 * @param {HTMLElement|null} submitter Submit button.
 */
async function previewMetadataOrganizerDraft(form, submitter) {
    const root = rootForForm(form);
    const progress = progressForRoot(root);
    const originalLabel = buttonLabelStart(submitter);
    const batchSize = positiveInteger(form.dataset.adminMetadataOrganizerBatchSize, 200, 1, 500);
    const aggregate = emptyPreviewAggregate();
    const initialBody = formDataSnapshot(form);
    let offset = 0;
    let done = false;

    setFormDisabled(form, true);
    setProgress(root, 3, i18n('admin.metadata_organizer.progress_preview_start', 'Preparing date preview batches...'));
    revealProgressArea(root);
    clearLog(root);
    appendLog(root, i18n('admin.metadata_organizer.log_preview_start', 'Preview started. Reading image metadata from the database only.'));

    try {
        while (!done) {
            const url = previewBatchUrl(form, offset, batchSize, initialBody);
            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await readJsonResponse(response);
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || payload.message || i18n('admin.metadata_organizer.progress_preview_failed', 'Metadata organizer preview failed.'));
            }

            const plan = payload.plan || {};
            if (payload.csrf_token) {
                aggregate.csrf_token = String(payload.csrf_token || '');
            }
            mergePreviewPlan(aggregate, plan);
            const batch = plan.batch || {};
            const returned = Number(batch.returned || 0);
            const total = Number(plan.total_images || aggregate.total_images || 0);
            offset = Number(batch.next_offset || (offset + returned));
            done = Boolean(batch.done) || returned <= 0;

            const percent = total > 0 ? Math.min(92, 5 + Math.round((Math.min(offset, total) / total) * 87)) : 92;
            setProgress(root, percent, i18n('admin.metadata_organizer.progress_preview_batch', 'Previewed {processed}/{total} image row(s)...', {
                processed: String(Math.min(offset, total)),
                total: String(total),
            }));
            appendLog(root, i18n('admin.metadata_organizer.log_preview_batch', 'Preview batch: rows {start} to {end}, candidates {candidates}.', {
                start: String(offset - returned + 1),
                end: String(offset),
                candidates: String(Number(plan.candidate_images || 0)),
            }));
        }

        renderPreviewResults(root, form, aggregate);
        setProgress(root, 100, i18n('admin.metadata_organizer.progress_preview_done', 'Preview draft ready. Review it before applying moves.'));
        appendLog(root, i18n('admin.metadata_organizer.log_preview_done', 'Preview completed. No files were moved.'));
    } catch (error) {
        setProgress(root, 100, error instanceof Error ? error.message : String(error));
        appendLog(root, error instanceof Error ? error.message : String(error));
    } finally {
        setFormDisabled(form, false);
        buttonLabelEnd(submitter, originalLabel);
        if (progress instanceof HTMLElement) {
            progress.hidden = false;
        }
    }
}

/**
 * Apply the current organizer draft through repeatable move batches.
 *
 * @param {HTMLFormElement} form Apply form.
 * @param {HTMLElement|null} submitter Submit button.
 */
async function applyMetadataOrganizerDraft(form, submitter) {
    const confirmInput = form.querySelector('input[name="confirm_metadata_organizer"]');
    if (confirmInput instanceof HTMLInputElement && !confirmInput.checked) {
        setProgress(rootForForm(form), 100, i18n('admin.metadata_organizer.confirm_required', 'Confirm that you reviewed the organizer draft before moving files.'));
        return;
    }

    const root = rootForForm(form);
    const originalLabel = buttonLabelStart(submitter);
    const batchSize = positiveInteger(form.dataset.adminMetadataOrganizerBatchSize, 1, 1, 10);
    const aggregate = emptyApplyAggregate();
    const initialBody = formDataSnapshot(form);
    let done = false;
    let total = Number(form.dataset.candidateCount || 0);

    let requestNumber = 0;

    setFormDisabled(form, true);
    setProgress(root, 5, i18n('admin.metadata_organizer.progress_apply_start', 'Starting physical move batches. One browser request moves at most {limit} photo(s).', {
        limit: String(batchSize),
    }));
    revealProgressArea(root);
    clearLog(root);
    appendLog(root, i18n('admin.metadata_organizer.log_apply_start', 'Apply started. The browser now controls the loop and the server receives only small AJAX move requests.'));
    await yieldToBrowser();

    try {
        while (!done) {
            requestNumber += 1;
            appendLog(root, i18n('admin.metadata_organizer.log_apply_request', 'Request {batch}: asking server to move up to {limit} photo(s).', {
                batch: String(requestNumber),
                limit: String(batchSize),
            }));
            setProgress(root, Math.min(96, 8 + requestNumber), i18n('admin.metadata_organizer.progress_apply_waiting', 'Request {batch} is running. Waiting for the server response...', {
                batch: String(requestNumber),
            }));
            await yieldToBrowser();

            const clientStartedAt = Date.now();
            const response = await fetch(formActionUrl(form), {
                method: 'POST',
                body: applyBatchBody(form, batchSize, initialBody),
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const clientMs = Math.max(0, Date.now() - clientStartedAt);
            const payload = await readJsonResponse(response);
            const result = payload.result || {};
            result.client_ms = clientMs;
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || payload.message || firstFailure(result) || i18n('admin.metadata_organizer.progress_apply_failed', 'Metadata organizer apply failed.'));
            }
            mergeApplyResult(aggregate, result);
            mergeApplyMutationEnvelope(aggregate, payload);

            if (total <= 0) {
                total = Number(result.remaining_before || 0);
            }
            const remaining = Number(result.remaining_after || 0);
            const moved = Number(result.moved_images || 0);
            done = Boolean(result.done) || remaining <= 0;
            const processed = Math.max(0, total - remaining);
            const percent = total > 0 ? Math.min(96, 8 + Math.round((processed / total) * 88)) : 96;
            setProgress(root, percent, i18n('admin.metadata_organizer.progress_apply_batch_rich', 'Moved {done}/{total} photo(s). Batch {batch}: {batch_moved} photo(s), {originals} original(s), {derivatives} derivative(s), server {server}, browser wait {client}. Remaining: {remaining}.', {
                done: String(Number(aggregate.moved_images || 0)),
                total: String(total),
                batch: String(requestNumber),
                batch_moved: String(moved),
                originals: String(Number(result.originals_moved || 0)),
                derivatives: String(Number(result.derivatives_moved || 0)),
                server: formatDuration(Number(result.duration_ms || 0)),
                client: formatDuration(clientMs),
                remaining: String(remaining),
            }));
            appendLog(root, applyBatchLogText(requestNumber, result));
            if (result.maintenance && result.maintenance.ran) {
                appendLog(root, maintenanceLogText(result));
            }
            await yieldToBrowser();
        }

        setProgress(root, 100, applySummaryText(aggregate));
        appendLog(root, i18n('admin.metadata_organizer.log_apply_done', 'Apply completed. Refreshing the gallery view now.'));
        renderApplySummary(root, aggregate);
        refreshAfterApply(root, aggregate);
    } catch (error) {
        setProgress(root, 100, error instanceof Error ? error.message : String(error));
        appendLog(root, error instanceof Error ? error.message : String(error));
        setFormDisabled(form, false);
    } finally {
        buttonLabelEnd(submitter, originalLabel);
    }
}

/**
 * Return a parsed JSON object with diagnostics for accidental HTML responses.
 *
 * @param {Response} response Fetch response.
 * @return {Promise<Record<string, *>>} Parsed payload.
 */
async function readJsonResponse(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (error) {
        const baseMessage = text.trim().startsWith('<')
            ? i18n('admin.metadata_organizer.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')
            : i18n('admin.metadata_organizer.js_invalid_json', 'The server returned an invalid JSON response.');
        throw new Error(responseDiagnosticMessage(baseMessage, response, text));
    }
}

/**
 * Return a compact diagnostic for non-JSON organizer responses.
 *
 * @param {string} baseMessage User-facing error message.
 * @param {Response} response Fetch response.
 * @param {string} text Raw response text.
 * @return {string} Message with transport details.
 */
function responseDiagnosticMessage(baseMessage, response, text) {
    const status = Number(response.status || 0);
    const url = String(response.url || '');
    const snippet = firstResponseSnippet(text);
    const parts = [baseMessage];
    if (status > 0) {
        parts.push(`HTTP ${status}`);
    }
    if (url !== '') {
        parts.push(url);
    }
    if (snippet !== '') {
        parts.push(snippet);
    }
    return parts.join(' | ');
}

/**
 * Return the first readable part of an unexpected server response.
 *
 * @param {string} text Raw response text.
 * @return {string} Compact response excerpt.
 */
function firstResponseSnippet(text) {
    return String(text || '')
        .replace(/<script[\s\S]*?<\/script>/gi, '')
        .replace(/<style[\s\S]*?<\/style>/gi, '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 180);
}

/**
 * Build the preview batch URL from form fields.
 *
 * @param {HTMLFormElement} form Preview form.
 * @param {number} offset Preview row offset.
 * @param {number} limit Batch size.
 * @param {FormData} initialBody Snapshot captured before controls were disabled.
 * @return {URL} Fetch URL.
 */
function previewBatchUrl(form, offset, limit, initialBody) {
    const url = new URL(form.dataset.adminMetadataOrganizerPreviewUrl || form.getAttribute('action') || form.action || window.location.href, window.location.href);
    const body = cloneFormData(initialBody);
    body.forEach((value, key) => {
        if (String(key) === 'page') {
            return;
        }
        url.searchParams.set(key, String(value));
    });
    url.searchParams.set('action', 'metadata_organizer_preview_batch');
    url.searchParams.set('ajax', '1');
    url.searchParams.set('offset', String(offset));
    url.searchParams.set('limit', String(limit));
    return url;
}

/**
 * Return one apply batch request body.
 *
 * @param {HTMLFormElement} form Apply form.
 * @param {number} batchSize Batch size.
 * @param {FormData} initialBody Snapshot captured before controls were disabled.
 * @return {FormData} Form payload.
 */
function applyBatchBody(form, batchSize, initialBody) {
    const body = cloneFormData(initialBody);
    body.set('action', 'apply_metadata_organizer_date_plan_batch');
    body.set('ajax', '1');
    body.set('batch_limit', String(batchSize));
    return body;
}

/**
 * Return the owning organizer root for one form.
 *
 * @param {HTMLFormElement} form Organizer form.
 * @return {HTMLElement|null} Root element.
 */
function rootForForm(form) {
    const root = form.closest(ROOT_SELECTOR);
    return root instanceof HTMLElement ? root : null;
}

/**
 * Return the progress element for one organizer root.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @return {HTMLElement|null} Progress element.
 */
function progressForRoot(root) {
    const progress = root?.querySelector('[data-admin-metadata-organizer-progress]');
    return progress instanceof HTMLElement ? progress : null;
}

/**
 * Reveal the organizer progress area in the active admin scroll container.
 *
 * Side-panel editor content scrolls inside its dialog rather than in the main
 * document. Explicitly moving that dialog to the progress area is more reliable
 * than relying only on nested sticky positioning after the apply button is clicked
 * near the bottom of the organizer draft.
 *
 * @param {HTMLElement|null} root Organizer root.
 */
function revealProgressArea(root) {
    const progress = progressForRoot(root);
    const target = progress instanceof HTMLElement ? progress : root;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const panel = target.closest('[data-admin-side-panel]');
    const dialog = panel instanceof HTMLElement ? panel.querySelector('.admin-side-panel-dialog') : null;
    if (dialog instanceof HTMLElement) {
        const dialogRect = dialog.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const panelOffset = metadataOrganizerPanelTopOffset(panel);
        const nextTop = dialog.scrollTop + targetRect.top - dialogRect.top - panelOffset;
        dialog.scrollTo({
            top: Math.max(0, nextTop),
            behavior: 'smooth',
        });
        return;
    }

    target.scrollIntoView({behavior: 'smooth', block: 'start'});
}

/**
 * Return the visible fixed/sticky header offset inside the side panel.
 *
 * @param {Element|null} panel Side-panel root.
 * @return {number} Pixel offset used when scrolling to the progress area.
 */
function metadataOrganizerPanelTopOffset(panel) {
    if (!(panel instanceof HTMLElement)) {
        return 16;
    }
    const header = panel.querySelector('.admin-side-panel-header');
    const tabs = panel.querySelector('.admin-tabs');
    const headerHeight = header instanceof HTMLElement ? header.getBoundingClientRect().height : 0;
    const tabsHeight = tabs instanceof HTMLElement ? tabs.getBoundingClientRect().height : 0;
    return Math.max(16, Math.round(headerHeight + tabsHeight + 24));
}

/**
 * Update organizer progress text and meter.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @param {number} percent Percent complete.
 * @param {string} text Human-readable status.
 */
function setProgress(root, percent, text) {
    const progress = progressForRoot(root);
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    progress.hidden = false;
    const bar = progress.querySelector('[data-admin-metadata-organizer-progress-bar]');
    if (bar instanceof HTMLProgressElement) {
        bar.value = Math.max(0, Math.min(100, percent));
    }
    const label = progress.querySelector('[data-admin-metadata-organizer-progress-text]');
    if (label instanceof HTMLElement) {
        label.textContent = text;
    }
}

/**
 * Clear the compact organizer log.
 *
 * @param {HTMLElement|null} root Organizer root.
 */
function clearLog(root) {
    const log = root?.querySelector('[data-admin-metadata-organizer-log]');
    if (log instanceof HTMLElement) {
        log.textContent = '';
        log.hidden = true;
    }
}

/**
 * Append one line to the compact organizer log.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @param {string} message Log message.
 */
function appendLog(root, message) {
    const log = root?.querySelector('[data-admin-metadata-organizer-log]');
    if (!(log instanceof HTMLElement) || String(message || '') === '') {
        return;
    }
    const date = new Date();
    const stamp = date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'});
    log.hidden = false;
    log.textContent += `${stamp} ${message}\n`;
    log.scrollTop = log.scrollHeight;
}

/**
 * Build a detailed one-line log entry for one apply response.
 *
 * @param {number} requestNumber Browser request sequence number.
 * @param {Record<string, *>} result Server result.
 * @return {string} Log message.
 */
function applyBatchLogText(requestNumber, result) {
    const moved = Number(result.moved_images || 0);
    const selected = Number(result.candidate_rows || result.requested_images || 0);
    const groups = Number(result.groups_processed || 0);
    return i18n('admin.metadata_organizer.log_apply_batch_rich', 'Request {batch} finished: selected {selected}, groups {groups}, moved {moved}, originals {originals}, derivatives {derivatives}, remaining {remaining}, server {server}, DB select {select}, filesystem move {move}, maintenance {maintenance}, browser wait {client}.', {
        batch: String(requestNumber),
        selected: String(selected),
        groups: String(groups),
        moved: String(moved),
        originals: String(Number(result.originals_moved || 0)),
        derivatives: String(Number(result.derivatives_moved || 0)),
        remaining: String(Number(result.remaining_after || 0)),
        server: formatDuration(Number(result.duration_ms || 0)),
        select: formatDuration(Number(result.selection_ms || 0)),
        move: formatDuration(Number(result.move_ms || 0)),
        maintenance: formatDuration(Number(result.maintenance_ms || 0)),
        client: formatDuration(Number(result.client_ms || 0)),
    });
}

/**
 * Build a detailed maintenance log entry for a final apply response.
 *
 * @param {Record<string, *>} result Server result.
 * @return {string} Log message.
 */
function maintenanceLogText(result) {
    const maintenance = result.maintenance || {};
    return i18n('admin.metadata_organizer.log_apply_maintenance', 'Final maintenance: public gallery paths {paths}, image slugs {slugs}, sidecars {sidecars}, duration {duration}.', {
        paths: String(Number(maintenance.gallery_public_paths || 0)),
        slugs: String(Number(maintenance.image_public_slugs || 0)),
        sidecars: String(Number(maintenance.sidecars_written || 0)),
        duration: formatDuration(Number(result.maintenance_ms || 0)),
    });
}

/**
 * Format milliseconds for compact progress text.
 *
 * @param {number} milliseconds Duration in milliseconds.
 * @return {string} Readable duration.
 */
function formatDuration(milliseconds) {
    const value = Math.max(0, Number(milliseconds || 0));
    if (value < 1000) {
        return `${Math.round(value)} ms`;
    }
    if (value < 60000) {
        return `${(value / 1000).toFixed(value < 10000 ? 1 : 0)} s`;
    }
    const minutes = Math.floor(value / 60000);
    const seconds = Math.round((value % 60000) / 1000);
    return `${minutes} min ${seconds} s`;
}

/**
 * Yield to the browser so progress text and the mini console paint before the next request.
 *
 * @return {Promise<void>} Promise resolved after a paint opportunity.
 */
function yieldToBrowser() {
    return new Promise((resolve) => {
        window.requestAnimationFrame(() => {
            window.setTimeout(resolve, 0);
        });
    });
}

/**
 * Return an empty preview aggregate.
 *
 * @return {Record<string, *>} Mutable aggregate.
 */
function emptyPreviewAggregate() {
    return {
        total_images: 0,
        candidate_images: 0,
        ignored_without_date: 0,
        ignored_before_min: 0,
        ignored_after_max: 0,
        groups: new Map(),
        options: {},
        csrf_token: '',
    };
}

/**
 * Merge one preview plan into the aggregate preview draft.
 *
 * @param {Record<string, *>} aggregate Mutable aggregate.
 * @param {Record<string, *>} plan Server plan.
 */
function mergePreviewPlan(aggregate, plan) {
    aggregate.total_images = Math.max(Number(aggregate.total_images || 0), Number(plan.total_images || 0));
    aggregate.candidate_images += Number(plan.candidate_images || 0);
    aggregate.ignored_without_date += Number(plan.ignored_without_date || 0);
    aggregate.ignored_before_min += Number(plan.ignored_before_min || 0);
    aggregate.ignored_after_max += Number(plan.ignored_after_max || 0);
    if (plan.options && typeof plan.options === 'object') {
        aggregate.options = plan.options;
    }

    (Array.isArray(plan.groups) ? plan.groups : []).forEach((group) => {
        const key = String(group.key || group.date || group.title || '');
        if (key === '') {
            return;
        }
        const current = aggregate.groups.get(key) || {
            key,
            date: String(group.date || ''),
            title: String(group.title || key),
            destination_status: String(group.destination_status || 'new'),
            image_count: 0,
            samples: [],
        };
        current.image_count += Number(group.image_count || 0);
        if (String(group.destination_status || '') === 'existing') {
            current.destination_status = 'existing';
        }
        (Array.isArray(group.images) ? group.images : []).forEach((image) => {
            const name = String(image.relative_path || image.filename || '').trim();
            if (name !== '' && current.samples.length < 5 && !current.samples.includes(name)) {
                current.samples.push(name);
            }
        });
        aggregate.groups.set(key, current);
    });
}

/**
 * Render a completed preview draft into the current organizer workspace.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @param {HTMLFormElement} sourceForm Preview form.
 * @param {Record<string, *>} aggregate Preview aggregate.
 */
function renderPreviewResults(root, sourceForm, aggregate) {
    const results = root?.querySelector('[data-admin-metadata-organizer-results]');
    if (!(results instanceof HTMLElement)) {
        return;
    }
    const groups = Array.from(aggregate.groups.values()).sort((left, right) => String(left.key).localeCompare(String(right.key)));
    const summary = i18n('admin.metadata_organizer.preview_summary', 'Direct photos in this gallery: {total}. Candidate photos: {candidates}. Proposed subgalleries: {groups}. Ignored without EXIF date: {without}. Ignored before minimum: {before}. Ignored after maximum: {after}.', {
        total: String(Number(aggregate.total_images || 0)),
        candidates: String(Number(aggregate.candidate_images || 0)),
        groups: String(groups.length),
        without: String(Number(aggregate.ignored_without_date || 0)),
        before: String(Number(aggregate.ignored_before_min || 0)),
        after: String(Number(aggregate.ignored_after_max || 0)),
    });

    if (groups.length === 0) {
        results.innerHTML = `<h3>${escapeHtmlText(i18n('admin.metadata_organizer.preview_title', 'Draft structure'))}</h3><p class="muted">${escapeHtmlText(summary)}</p><p class="muted">${escapeHtmlText(i18n('admin.metadata_organizer.empty_preview', 'No photos match the current date boundaries.'))}</p>`;
        return;
    }

    const rows = groups.map((group) => {
        const status = group.destination_status === 'existing'
            ? i18n('admin.metadata_organizer.status_existing', 'Existing gallery, photos will be added')
            : i18n('admin.metadata_organizer.status_new', 'New gallery will be created');
        const hiddenCount = Math.max(0, Number(group.image_count || 0) - group.samples.length);
        const sample = group.samples.join(', ') + (hiddenCount > 0 ? `${group.samples.length > 0 ? ', ' : ''}${i18n('admin.metadata_organizer.more_files', '+{count} more', {count: String(hiddenCount)})}` : '');
        return `<tr><td><strong>${escapeHtmlText(group.title)}</strong><br><span class="muted">${escapeHtmlText(group.date)}</span></td><td>${escapeHtmlText(status)}</td><td>${Number(group.image_count || 0)}</td><td>${escapeHtmlText(sample)}</td></tr>`;
    }).join('');

    results.innerHTML = `${previewHeadingHtml(summary)}${previewTableHtml(rows)}${applyFormHtml(sourceForm, aggregate)}`;
}

/**
 * Return preview heading HTML.
 *
 * @param {string} summary Summary text.
 * @return {string} HTML fragment.
 */
function previewHeadingHtml(summary) {
    return `<h3>${escapeHtmlText(i18n('admin.metadata_organizer.preview_title', 'Draft structure'))}</h3><p class="muted">${escapeHtmlText(summary)}</p>`;
}

/**
 * Return preview table HTML.
 *
 * @param {string} rows Rendered table rows.
 * @return {string} HTML fragment.
 */
function previewTableHtml(rows) {
    return `<table><thead><tr><th>${escapeHtmlText(i18n('admin.metadata_organizer.target_gallery', 'Target subgallery'))}</th><th>${escapeHtmlText(i18n('admin.metadata_organizer.status', 'Status'))}</th><th>${escapeHtmlText(i18n('admin.metadata_organizer.photos', 'Photos'))}</th><th>${escapeHtmlText(i18n('admin.metadata_organizer.sample', 'Sample'))}</th></tr></thead><tbody>${rows}</tbody></table>`;
}

/**
 * Return apply form HTML for a completed JavaScript preview.
 *
 * @param {HTMLFormElement} sourceForm Preview form.
 * @param {Record<string, *>} aggregate Preview aggregate.
 * @return {string} HTML fragment.
 */
function applyFormHtml(sourceForm, aggregate) {
    const csrf = String(aggregate.csrf_token || fieldValue(sourceForm, 'csrf_token'));
    const galleryId = fieldValue(sourceForm, 'id');
    const minDate = fieldValue(sourceForm, 'min_date');
    const maxDate = fieldValue(sourceForm, 'max_date');
    const action = new URL(sourceForm.dataset.adminMetadataOrganizerApplyUrl || sourceForm.getAttribute('action') || sourceForm.action || window.location.href, window.location.href);
    action.searchParams.set('id', galleryId);
    return `<form method="post" action="${escapeHtmlAttribute(action.toString())}" data-admin-metadata-organizer-apply-form data-admin-metadata-organizer-batch-size="1" data-candidate-count="${Number(aggregate.candidate_images || 0)}">
        <input type="hidden" name="csrf_token" value="${escapeHtmlAttribute(csrf)}">
        <input type="hidden" name="id" value="${escapeHtmlAttribute(galleryId)}">
        <input type="hidden" name="return_tab" value="admin-edit-organizer">
        <input type="hidden" name="action" value="apply_metadata_organizer_date_plan">
        <input type="hidden" name="primary_grouping" value="date">
        <input type="hidden" name="secondary_grouping" value="none">
        <input type="hidden" name="min_date" value="${escapeHtmlAttribute(minDate)}">
        <input type="hidden" name="max_date" value="${escapeHtmlAttribute(maxDate)}">
        <label class="checkbox-label"><input type="checkbox" name="confirm_metadata_organizer" value="1" required> ${escapeHtmlText(i18n('admin.metadata_organizer.confirm_label', 'I reviewed the draft and want to create/reuse these subgalleries and move the matching photos now.'))}</label>
        <div class="admin-edit-gallery-savebar"><button type="submit" data-admin-metadata-organizer-apply-button>${escapeHtmlText(i18n('admin.metadata_organizer.apply_button', 'Apply draft and move photos'))}</button><span class="muted">${escapeHtmlText(i18n('admin.metadata_organizer.apply_help', 'The operation uses the same physical move path as the existing bulk image move tool.'))}</span></div>
    </form>`;
}

/**
 * Render final apply summary in the results area.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @param {Record<string, *>} aggregate Apply aggregate.
 */
function renderApplySummary(root, aggregate) {
    const results = root?.querySelector('[data-admin-metadata-organizer-results]');
    if (!(results instanceof HTMLElement)) {
        return;
    }
    const rows = (Array.isArray(aggregate.group_results) ? aggregate.group_results : []).slice(0, 80).map((group) => {
        const status = String(group.destination_status || '') === 'created'
            ? i18n('admin.metadata_organizer.status_created', 'Created gallery')
            : i18n('admin.metadata_organizer.status_existing_short', 'Existing gallery');
        return `<tr><td><strong>${escapeHtmlText(String(group.title || ''))}</strong><br><span class="muted">${escapeHtmlText(String(group.date || ''))}</span></td><td>${escapeHtmlText(status)}</td><td>${Number(group.moved || 0)}</td></tr>`;
    }).join('');
    results.innerHTML = `<h3>${escapeHtmlText(i18n('admin.metadata_organizer.apply_done_title', 'Organizer applied'))}</h3><p class="muted">${escapeHtmlText(applySummaryText(aggregate))}</p>${rows ? `<table><thead><tr><th>${escapeHtmlText(i18n('admin.metadata_organizer.target_gallery', 'Target subgallery'))}</th><th>${escapeHtmlText(i18n('admin.metadata_organizer.status', 'Status'))}</th><th>${escapeHtmlText(i18n('admin.metadata_organizer.photos', 'Photos'))}</th></tr></thead><tbody>${rows}</tbody></table>` : ''}`;
}

/**
 * Return an empty apply aggregate.
 *
 * @return {Record<string, *>} Mutable aggregate.
 */
function emptyApplyAggregate() {
    return {
        created_galleries: 0,
        reused_galleries: 0,
        requested_images: 0,
        moved_images: 0,
        originals_moved: 0,
        derivatives_moved: 0,
        group_results: [],
        failures: [],
        mutation_envelope: null,
    };
}

/**
 * Preserve the canonical successful mutation envelope from an apply batch.
 *
 * The organizer may use multiple server requests, but browser completion semantics
 * must always come from the server-authored envelope. The final successful batch
 * carries the latest postcondition for the gallery editor and public context.
 *
 * @param {Record<string, *>} target Mutable aggregate.
 * @param {Record<string, *>} payload Apply JSON response.
 */
function mergeApplyMutationEnvelope(target, payload) {
    if (!payload.mutation || typeof payload.mutation !== 'object' || !Array.isArray(payload.contexts)) {
        throw new Error(i18n('admin.metadata_organizer.mutation_contract_missing', 'The organizer saved changes, but the server did not return the required mutation completion contract.'));
    }
    target.mutation_envelope = payload;
}

/**
 * Refresh the visible admin context after metadata-organizer moves finish.
 *
 * @param {HTMLElement|null} root Organizer root.
 * @param {Record<string, *>} aggregate Apply aggregate.
 */
function refreshAfterApply(root, aggregate) {
    if (!aggregate.mutation_envelope) {
        throw new Error(i18n('admin.metadata_organizer.mutation_contract_missing', 'The organizer saved changes, but the server did not return the required mutation completion contract.'));
    }
    appendLog(root, i18n('admin.metadata_organizer.log_refresh_start', 'Refreshing visible gallery content...'));
    const detail = {
        handled: false,
        result: aggregate.mutation_envelope,
    };
    document.dispatchEvent(new CustomEvent('php-gallery:metadata-organizer-applied', {
        bubbles: true,
        detail,
    }));
    if (!detail.handled) {
        setProgress(root, 100, i18n('admin.metadata_organizer.refresh_unavailable', 'The organizer completed, but the visible gallery could not be synchronized. Reopen the page later to verify the saved result.'));
    }
}

/**
 * Merge one apply batch result into a final aggregate.
 *
 * @param {Record<string, *>} target Mutable aggregate.
 * @param {Record<string, *>} source Batch result.
 */
function mergeApplyResult(target, source) {
    ['created_galleries', 'reused_galleries', 'requested_images', 'moved_images', 'originals_moved', 'derivatives_moved'].forEach((key) => {
        target[key] = Number(target[key] || 0) + Number(source[key] || 0);
    });
    if (Array.isArray(source.group_results)) {
        target.group_results.push(...source.group_results);
    }
    if (Array.isArray(source.failures)) {
        target.failures.push(...source.failures);
    }
    target.group_results = target.group_results.slice(0, 500);
    target.failures = target.failures.slice(0, 50);
}

/**
 * Return a readable apply summary.
 *
 * @param {Record<string, *>} result Apply aggregate.
 * @return {string} Summary text.
 */
function applySummaryText(result) {
    return i18n('admin.metadata_organizer.apply_notice', 'Created {created} subgallery/subgalleries, reused {reused}, moved {moved} of {requested} photo(s), including {originals} original file(s) and {derivatives} derivative file(s).', {
        created: String(Number(result.created_galleries || 0)),
        reused: String(Number(result.reused_galleries || 0)),
        moved: String(Number(result.moved_images || 0)),
        requested: String(Number(result.requested_images || 0)),
        originals: String(Number(result.originals_moved || 0)),
        derivatives: String(Number(result.derivatives_moved || 0)),
    });
}

/**
 * Return the first failure from a batch result.
 *
 * @param {Record<string, *>} result Batch result.
 * @return {string} Failure text.
 */
function firstFailure(result) {
    return Array.isArray(result.failures) && result.failures.length > 0 ? String(result.failures[0] || '') : '';
}

/**
 * Capture form data before controls are disabled for an AJAX operation.
 *
 * @param {HTMLFormElement} form Form value.
 * @return {FormData} Form payload snapshot.
 */
function formDataSnapshot(form) {
    return new FormData(form);
}

/**
 * Clone a form-data snapshot so each batch can mutate its own payload safely.
 *
 * @param {FormData} source Source payload.
 * @return {FormData} Cloned payload.
 */
function cloneFormData(source) {
    const clone = new FormData();
    source.forEach((value, key) => {
        clone.append(key, value);
    });
    return clone;
}

/**
 * Return one field value from a form.
 *
 * @param {HTMLFormElement} form Form value.
 * @param {string} name Field name.
 * @return {string} Field value.
 */
function fieldValue(form, name) {
    const input = form.querySelector(`[name="${CSS.escape(name)}"]`);
    return input instanceof HTMLInputElement || input instanceof HTMLSelectElement || input instanceof HTMLTextAreaElement ? String(input.value || '') : '';
}

/**
 * Return a safe positive integer from a data attribute.
 *
 * @param {*} value Raw value.
 * @param {number} fallback Fallback value.
 * @param {number} min Minimum value.
 * @param {number} max Maximum value.
 * @return {number} Integer result.
 */
function positiveInteger(value, fallback, min, max) {
    const parsed = parseInt(String(value || ''), 10);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }
    return Math.max(min, Math.min(max, parsed));
}

/**
 * Return the absolute form action URL.
 *
 * @param {HTMLFormElement} form Form value.
 * @return {string} Absolute URL.
 */
function formActionUrl(form) {
    return new URL(form.getAttribute('action') || form.action || window.location.href, window.location.href).toString();
}

/**
 * Disable or enable every form control.
 *
 * @param {HTMLFormElement} form Form value.
 * @param {boolean} disabled Disabled state.
 */
function setFormDisabled(form, disabled) {
    form.querySelectorAll('button, input, select, textarea').forEach((control) => {
        if (control instanceof HTMLButtonElement || control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
            control.disabled = disabled;
        }
    });
}

/**
 * Mark a button as busy and return its previous label.
 *
 * @param {HTMLElement|null} button Button value.
 * @return {string} Previous label.
 */
function buttonLabelStart(button) {
    if (!(button instanceof HTMLButtonElement)) {
        return '';
    }
    const original = button.textContent || '';
    button.dataset.originalLabel = original;
    button.textContent = i18n('admin.operations.working', 'Working...');
    return original;
}

/**
 * Restore a button label after work completes.
 *
 * @param {HTMLElement|null} button Button value.
 * @param {string} original Previous label.
 */
function buttonLabelEnd(button, original) {
    if (button instanceof HTMLButtonElement && original !== '') {
        button.textContent = original;
    }
}
