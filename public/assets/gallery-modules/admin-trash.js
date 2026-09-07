/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-trash.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances Admin Maintenance trash mutations without turning bounded server
 *   cleanup batches into one long-running PHP request.
 *
 * Responsibilities:
 *   - Submit restore and permanent-delete forms through their JSON mutation path
 *   - Repeat bounded Empty Trash batches until the server reports none remaining
 *   - Refresh only the Trash panel after each completed mutation workflow
 *   - Preserve ordinary form submission as the no-JavaScript fallback
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
 *   - Event delegation is required because Maintenance content can be lazy-loaded.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-07
 */

let adminTrashActionsBound = false;

/**
 * Return the Trash panel that owns a submitted mutation form.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {HTMLElement|null} Owning Trash panel, when present.
 */
function adminTrashPanelForForm(form) {
    const panel = form.closest('[data-admin-trash-panel]');
    return panel instanceof HTMLElement ? panel : null;
}

/**
 * Toggle all Trash mutation controls while one enhanced request is active.
 *
 * @param {HTMLElement|null} panel Owning Trash panel.
 * @param {boolean} busy Whether controls should be disabled.
 * @return {void}
 */
function setAdminTrashPanelBusy(panel, busy) {
    if (!(panel instanceof HTMLElement)) {
        return;
    }
    // Never disable hidden CSRF/trash-token fields before FormData is built. Only
    // submission controls need to be locked to prevent duplicate mutations.
    panel.querySelectorAll('button').forEach((control) => {
        if (control instanceof HTMLButtonElement) {
            control.disabled = busy;
        }
    });
    panel.dataset.adminTrashBusy = busy ? '1' : '0';
}

/**
 * Submit one Trash mutation through the canonical JSON response path.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {Promise<Record<string, *>>} Parsed mutation response.
 */
async function submitAdminTrashForm(form) {
    const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const text = await response.text();
    let payload = null;
    try {
        payload = JSON.parse(text);
    } catch (error) {
        throw new Error('The trash action returned an invalid server response.');
    }
    if (!payload || typeof payload !== 'object') {
        throw new Error('The trash action returned an invalid server response.');
    }
    if (!response.ok || payload.ok !== true) {
        throw new Error(String(payload.error || payload.message || 'The trash action failed.'));
    }
    return payload;
}

/**
 * Replace the current Trash panel with a fresh server-rendered fragment.
 *
 * @param {HTMLElement} panel Existing Trash panel.
 * @param {string} message Result message shown after the refresh.
 * @param {boolean} isError Whether the message represents an error.
 * @return {Promise<void>} Resolves after the refreshed fragment is installed.
 */
async function refreshAdminTrashPanel(panel, message, isError = false) {
    const endpoint = String(panel.dataset.adminTrashRefreshUrl || '').trim();
    if (!endpoint) {
        throw new Error('Trash panel refresh URL is missing.');
    }
    const response = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
    });
    if (!response.ok) {
        throw new Error(`Trash panel refresh failed with HTTP ${response.status}.`);
    }
    const html = await response.text();
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const replacement = template.content.querySelector('[data-admin-trash-panel]');
    if (!(replacement instanceof HTMLElement)) {
        throw new Error('Trash panel refresh returned unexpected markup.');
    }
    const status = replacement.querySelector('[data-admin-trash-status]');
    if (status instanceof HTMLElement && message) {
        status.hidden = false;
        status.textContent = message;
        status.classList.toggle('error', isError);
    }
    panel.replaceWith(replacement);
}

/**
 * Return the best redirect fallback carried by one canonical mutation response.
 *
 * @param {Record<string, *>|null} payload Mutation response, when available.
 * @return {string} Fallback URL or the current page URL.
 */
function adminTrashFallbackUrl(payload) {
    const fallback = payload && typeof payload.fallback === 'object' ? payload.fallback : null;
    const redirect = String(fallback?.redirect_url || '').trim();
    return redirect || window.location.href;
}

/**
 * Run one restore or per-entry purge and refresh the Trash fragment in place.
 *
 * @param {HTMLFormElement} form Submitted Trash form.
 * @return {Promise<void>} Resolves after completion handling.
 */
async function runAdminTrashSingleMutation(form) {
    const panel = adminTrashPanelForForm(form);
    if (!(panel instanceof HTMLElement) || panel.dataset.adminTrashBusy === '1') {
        return;
    }
    setAdminTrashPanelBusy(panel, true);
    let payload = null;
    try {
        payload = await submitAdminTrashForm(form);
        await refreshAdminTrashPanel(panel, String(payload.message || ''));
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        try {
            await refreshAdminTrashPanel(panel, message, true);
        } catch (refreshError) {
            window.location.assign(adminTrashFallbackUrl(payload));
        }
    } finally {
        setAdminTrashPanelBusy(document.querySelector('[data-admin-trash-panel]'), false);
    }
}

/**
 * Run Empty Trash as repeated bounded HTTP requests until no purgeable row remains.
 *
 * @param {HTMLFormElement} form Empty Trash form.
 * @return {Promise<void>} Resolves after the final batch and panel refresh.
 */
async function runAdminTrashEmpty(form) {
    const panel = adminTrashPanelForForm(form);
    if (!(panel instanceof HTMLElement) || panel.dataset.adminTrashBusy === '1') {
        return;
    }
    setAdminTrashPanelBusy(panel, true);

    let totalPurged = 0;
    let payload = null;
    let finalMessage = '';
    try {
        while (true) {
            payload = await submitAdminTrashForm(form);
            const batch = payload.trash_batch && typeof payload.trash_batch === 'object' ? payload.trash_batch : null;
            if (!batch) {
                throw new Error('Empty Trash response is missing bounded-batch metadata.');
            }
            totalPurged += Math.max(0, Number(batch.purged || 0));
            const failed = Math.max(0, Number(batch.failed || 0));
            const remaining = Math.max(0, Number(batch.remaining || 0));
            finalMessage = String(payload.message || '');
            if (failed > 0 || remaining === 0) {
                break;
            }
        }

        const successTemplate = String(form.dataset.adminTrashSuccessTemplate || '').trim();
        if (successTemplate && payload?.trash_batch && Number(payload.trash_batch.failed || 0) === 0) {
            finalMessage = successTemplate.replace('{count}', String(totalPurged));
        }
        await refreshAdminTrashPanel(panel, finalMessage);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        try {
            await refreshAdminTrashPanel(panel, message, true);
        } catch (refreshError) {
            window.location.assign(adminTrashFallbackUrl(payload));
        }
    } finally {
        setAdminTrashPanelBusy(document.querySelector('[data-admin-trash-panel]'), false);
    }
}

/**
 * Attach delegated enhanced behavior for Admin Trash mutation forms.
 *
 * @return {void}
 */
export function setupAdminTrashActions() {
    if (adminTrashActionsBound) {
        return;
    }
    adminTrashActionsBound = true;
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.matches('[data-admin-trash-empty-form]')) {
            event.preventDefault();
            void runAdminTrashEmpty(form);
            return;
        }
        if (form.matches('[data-admin-trash-restore-form], [data-admin-trash-purge-form]')) {
            event.preventDefault();
            void runAdminTrashSingleMutation(form);
        }
    });
}
