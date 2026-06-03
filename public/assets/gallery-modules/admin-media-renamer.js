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

import {i18n} from './admin-core.js?v=20260512-admin-core-v1';

let mediaRenamerReady = false;

/**
 * Attach delegated media-renamer form handling once per page.
 *
 * @returns {void}
 */
export function setupAdminMediaRenamer() {
    if (mediaRenamerReady) {
        return;
    }
    mediaRenamerReady = true;
    document.addEventListener('submit', handleMediaRenamerSubmit);
}

/**
 * Submit preview and apply forms without navigating away from the current view.
 *
 * @param {SubmitEvent} event Browser submit event.
 * @returns {void}
 */
async function handleMediaRenamerSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-media-renamer-form]')) {
        return;
    }

    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    const confirmMessage = form.dataset.mediaRenamerConfirm || '';
    if (confirmMessage && !window.confirm(confirmMessage)) {
        event.preventDefault();
        return;
    }

    event.preventDefault();
    const workspace = findRenamerWorkspace(form);
    const progress = ensureRenamerProgress(form, workspace);
    setRenamerProgress(progress, 8, i18n('admin.media_renamer.progress_starting', 'Preparing rename request...'));
    setRenamerFormDisabled(form, true);

    try {
        const response = await submitRenamerForm(form, submitter, (percent, label) => setRenamerProgress(progress, percent, label));
        const payload = await parseRenamerJsonResponse(response, form);
        if (!response.ok || payload.ok === false) {
            throw new Error(payload.error || payload.message || i18n('admin.media_renamer.progress_failed', 'Rename request failed.'));
        }
        setRenamerProgress(progress, 92, i18n('admin.media_renamer.progress_refreshing', 'Refreshing the renamer view...'));
        replaceRenamerWorkspace(form, payload);
        setRenamerProgress(null, 100, '');
    } catch (error) {
        setRenamerProgress(progress, 100, error instanceof Error ? error.message : String(error));
        setRenamerFormDisabled(form, false);
    }
}

/**
 * Submit one renamer form and return the JSON response.
 *
 * @param {HTMLFormElement} form Submitted form.
 * @param {HTMLElement|null} submitter Button that submitted the form.
 * @param {(percent: number, label: string) => void} updateProgress Progress updater.
 * @returns {Promise<Response>} Fetch response.
 */
async function submitRenamerForm(form, submitter, updateProgress) {
    const method = (form.method || 'get').toLowerCase();
    const action = submitter?.getAttribute('formaction') || form.getAttribute('action') || window.location.href;
    const url = new URL(action, window.location.href);

    updateProgress(18, i18n('admin.media_renamer.progress_sending', 'Sending request to the server...'));
    if (method === 'get') {
        const params = new URLSearchParams(new FormData(form));
        if (submitter instanceof HTMLButtonElement && submitter.name) {
            params.set(submitter.name, submitter.value);
        }
        params.set('ajax', '1');
        url.search = params.toString();
        updateProgress(45, i18n('admin.media_renamer.progress_preview', 'Building preview from current database state...'));
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
    updateProgress(35, i18n('admin.media_renamer.progress_running', 'Renaming files, updating database rows, and refreshing derived assets...'));
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
 * @returns {HTMLElement|null} Workspace element.
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
 * @returns {HTMLElement} Progress element.
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
 * @returns {void}
 */
function setRenamerProgress(progress, percent, label) {
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    progress.hidden = false;
    const bar = progress.querySelector('[data-admin-media-renamer-progress-bar]');
    if (bar instanceof HTMLProgressElement) {
        bar.value = Math.max(0, Math.min(100, percent));
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
 * @returns {void}
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
 * @returns {Promise<object>} Parsed JSON payload.
 */
async function parseRenamerJsonResponse(response, form) {
    const contentType = response.headers.get('content-type') || '';
    const requestUrl = typeof response.mediaRenamerRequestUrl === 'string' ? response.mediaRenamerRequestUrl : (response.url || '');
    const text = await response.text();
    const trimmed = text.trimStart();
    const snippet = trimmed.slice(0, 1200);

    if (!contentType.toLowerCase().includes('application/json')) {
        const message = buildNonJsonMessage(response, contentType, snippet);
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
            : i18n('admin.media_renamer.invalid_json', 'Server returned invalid JSON.');
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
        throw new Error(i18n('admin.media_renamer.invalid_json_with_snippet', 'Server returned invalid JSON. First response bytes: {snippet}', {snippet: snippet.slice(0, 180)}));
    }
}

/**
 * Build a readable browser-side error for an HTML/text response.
 *
 * @param {Response} response Fetch response.
 * @param {string} contentType Response content type.
 * @param {string} snippet First response bytes.
 * @returns {string} Visible error text.
 */
function buildNonJsonMessage(response, contentType, snippet) {
    const prefix = response.redirected
        ? i18n('admin.media_renamer.non_json_redirect', 'Server redirected the AJAX request and returned non-JSON content.')
        : i18n('admin.media_renamer.non_json_response', 'Server returned non-JSON content to the AJAX request.');
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
 * @returns {Promise<void>} Completion promise.
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
 * @param {{panel_html?: string, body_html?: string}} payload Server response payload.
 * @returns {void}
 */
function replaceRenamerWorkspace(form, payload) {
    const selector = form.dataset.mediaRenamerTarget || '';
    const target = selector ? document.querySelector(selector) : findRenamerWorkspace(form);
    if (!(target instanceof HTMLElement)) {
        window.location.reload();
        return;
    }

    if (typeof payload.panel_html === 'string' && payload.panel_html !== '') {
        target.innerHTML = payload.panel_html;
        return;
    }
    if (typeof payload.body_html === 'string' && payload.body_html !== '') {
        target.outerHTML = payload.body_html;
        return;
    }
    window.location.reload();
}
