/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-update-jobs.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Keeps Admin application-update jobs in place while bounded PHP requests advance durable checkpoints.
 *
 * Responsibilities:
 *   - Intercept dynamically rendered update forms with delegated submit handling
 *   - Continue one update job request at a time without navigating away from the Admin side panel
 *   - Render durable progress, failure references, and completion state in place
 *   - Resume a running job when the update UI is opened again after browser closure
 *   - Preserve ordinary form submission as the non-JavaScript fallback
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
 *   - Only one continuation request may be in flight for a job in this browser document.
 */

const ACTIVE_REQUESTS = new Map();
const STAGE_LABELS = {
    download: 'Downloading package',
    archive_validate: 'Checking archive',
    extract: 'Extracting package',
    package_validate: 'Verifying integrity',
    plan: 'Preparing activation plan',
    stage_files: 'Staging files',
    backup: 'Preparing rollback data',
    ready: 'Ready to activate',
    activate: 'Activating prepared release',
    migrate: 'Applying migrations',
    finalize: 'Finalizing update',
    cleanup: 'Cleaning temporary files',
    completed: 'Completed',
};

function stageLabel(stage) {
    return STAGE_LABELS[stage] || String(stage || '').replaceAll('_', ' ').replace(/^./, (value) => value.toUpperCase());
}

function findUpdateScope(context = document) {
    if (context instanceof Element && context.matches('[data-update-job-scope]')) {
        return context;
    }
    return context.querySelector?.('[data-update-job-scope]') || document.querySelector('[data-update-job-scope]');
}

function csrfTokenFrom(context) {
    return String(context?.querySelector?.('input[name="csrf_token"]')?.value || document.querySelector('input[name="csrf_token"]')?.value || '');
}

function ensureScopeMarkup(scope) {
    if (!scope || scope.querySelector('[data-update-job-title]')) {
        return;
    }
    scope.innerHTML = `
        <div class="admin-update-job-heading">
            <div><p class="admin-kicker">Resumable update job</p><h3 data-update-job-title></h3></div>
            <code data-update-job-code></code>
        </div>
        <div class="admin-update-job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-update-job-progress></span></div>
        <p class="muted" data-update-job-message></p>
        <p class="muted"><strong>Stage:</strong> <span data-update-job-stage></span> · <strong>Attempts:</strong> <span data-update-job-attempts></span></p>
        <div class="notice" data-update-job-error hidden></div>
        <div class="notice" data-update-job-complete hidden>Update completed successfully. The next request will run the activated application version.</div>
        <div class="notice" data-update-job-cancelled hidden>Prepared update cancelled before activation. No application files were changed.</div>
        <div data-update-job-actions></div>`;
}

function renderJob(job, context = document) {
    const scope = findUpdateScope(context) || findUpdateScope(document);
    if (!scope || !job || !job.id) {
        return;
    }
    ensureScopeMarkup(scope);
    scope.hidden = false;
    scope.dataset.updateJobId = String(job.id);
    scope.dataset.updateJobStatus = String(job.status || '');

    const progress = job.progress || {};
    const percent = Number.isFinite(Number(progress.percent)) ? Number(progress.percent) : Number(job.stage_percent || 0);
    const safePercent = Math.max(0, Math.min(100, Math.round(percent)));
    scope.querySelector('[data-update-job-title]').textContent = stageLabel(job.stage);
    const code = scope.querySelector('[data-update-job-code]') || scope.querySelector('.admin-update-job-heading code');
    if (code) code.textContent = String(job.id);
    scope.querySelector('[data-update-job-stage]').textContent = stageLabel(job.stage);
    scope.querySelector('[data-update-job-attempts]').textContent = String(job.attempts || 0);
    scope.querySelector('[data-update-job-message]').textContent = String(progress.message || 'Update job is ready to continue.');
    const progressBar = scope.querySelector('[data-update-job-progress]');
    if (progressBar) progressBar.style.width = `${safePercent}%`;
    const progressShell = progressBar?.parentElement;
    if (progressShell) progressShell.setAttribute('aria-valuenow', String(safePercent));

    const errorBox = scope.querySelector('[data-update-job-error]');
    if (errorBox) {
        const reference = String(job.error?.reference || '');
        errorBox.textContent = job.error ? `${String(job.error.message || 'Update failed.')} Reference: ${reference}` : '';
        errorBox.hidden = !job.error;
    }

    const completeBox = scope.querySelector('[data-update-job-complete]');
    if (completeBox) completeBox.hidden = job.status !== 'completed';
    const cancelledBox = scope.querySelector('[data-update-job-cancelled]');
    if (cancelledBox) cancelledBox.hidden = job.status !== 'cancelled';

    const actions = scope.querySelector('[data-update-job-actions]');
    if (actions) {
        actions.replaceChildren();
        if (job.status === 'failed') {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'button secondary';
            retry.textContent = 'Retry from checkpoint';
            retry.dataset.updateJobRetry = String(job.id);
            actions.append(retry);
        } else if (job.status === 'running') {
            const status = document.createElement('span');
            status.className = 'muted';
            status.textContent = 'Continuing automatically while this panel remains open.';
            actions.append(status);
        }
        if (job.can_cancel) {
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'button secondary';
            cancel.textContent = 'Cancel prepared update';
            cancel.dataset.updateJobCancel = String(job.id);
            actions.append(cancel);
        }
        if (job.can_rollback) {
            const rollback = document.createElement('button');
            rollback.type = 'button';
            rollback.className = 'button secondary';
            rollback.textContent = 'Rollback application files';
            rollback.dataset.updateJobRollback = String(job.id);
            actions.append(rollback);
        }
    }
}

async function postJob(endpoint, csrfToken, action, jobId = '', sourceBody = null) {
    const body = sourceBody instanceof FormData ? sourceBody : new FormData();
    if (!(sourceBody instanceof FormData)) {
        body.set('csrf_token', csrfToken);
        body.set('update_action', action);
        if (jobId) body.set('job_id', jobId);
    }
    body.set('update_async', '1');
    const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
        body,
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.ok || !payload.job) {
        const message = String(payload?.error?.message || 'The update request could not continue safely.');
        const reference = String(payload?.error?.reference || '');
        throw new Error(reference ? `${message} Reference: ${reference}` : message);
    }
    return payload.job;
}

function endpointFor(formOrScope) {
    if (formOrScope instanceof HTMLFormElement && formOrScope.action) {
        return formOrScope.action;
    }
    return window.location.href;
}

function scheduleContinuation(job, endpoint, csrfToken, context = document) {
    if (!job?.id || job.status !== 'running' || ACTIVE_REQUESTS.has(String(job.id))) {
        return;
    }
    const id = String(job.id);
    const controller = new AbortController();
    ACTIVE_REQUESTS.set(id, controller);
    window.setTimeout(async () => {
        try {
            const next = await postJob(endpoint, csrfToken, 'job_continue', id);
            renderJob(next, context);
            ACTIVE_REQUESTS.delete(id);
            if (next.status === 'running') {
                scheduleContinuation(next, endpoint, csrfToken, context);
            }
        } catch (error) {
            ACTIVE_REQUESTS.delete(id);
            const scope = findUpdateScope(context) || findUpdateScope(document);
            const errorBox = scope?.querySelector('[data-update-job-error]');
            if (errorBox) {
                errorBox.textContent = error instanceof Error ? error.message : 'The update request stopped. Reopen this page to resume from the saved checkpoint.';
                errorBox.hidden = false;
            }
        }
    }, 250);
}

async function handleStartForm(form) {
    const endpoint = endpointFor(form);
    const csrfToken = csrfTokenFrom(form);
    if (!csrfToken) return;
    const body = new FormData(form);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => { button.disabled = true; });
    try {
        const job = await postJob(endpoint, csrfToken, '', '', body);
        renderJob(job, form.closest('[data-admin-side-panel-content]') || document);
        scheduleContinuation(job, endpoint, csrfToken, form.closest('[data-admin-side-panel-content]') || document);
    } catch (error) {
        const scope = findUpdateScope(form.closest('[data-admin-side-panel-content]') || document);
        if (scope) {
            ensureScopeMarkup(scope);
            scope.hidden = false;
            const errorBox = scope.querySelector('[data-update-job-error]');
            errorBox.textContent = error instanceof Error ? error.message : 'The update request failed safely.';
            errorBox.hidden = false;
        }
    } finally {
        buttons.forEach((button) => { button.disabled = false; });
    }
}

async function retryJob(button) {
    const scope = button.closest('[data-update-job-scope]');
    const id = String(button.dataset.updateJobRetry || scope?.dataset.updateJobId || '');
    const csrfToken = csrfTokenFrom(scope || document);
    if (!id || !csrfToken) return;
    button.disabled = true;
    try {
        const job = await postJob(window.location.href, csrfToken, 'job_retry', id);
        renderJob(job, scope || document);
        scheduleContinuation(job, window.location.href, csrfToken, scope || document);
    } catch (error) {
        const errorBox = scope?.querySelector('[data-update-job-error]');
        if (errorBox) {
            errorBox.textContent = error instanceof Error ? error.message : 'The retry request failed safely.';
            errorBox.hidden = false;
        }
    } finally {
        button.disabled = false;
    }
}

async function cancelJob(button) {
    const scope = button.closest('[data-update-job-scope]');
    const id = String(button.dataset.updateJobCancel || scope?.dataset.updateJobId || '');
    const csrfToken = csrfTokenFrom(scope || document);
    if (!id || !csrfToken || !window.confirm('Cancel this prepared update? Active application files have not been changed.')) return;
    button.disabled = true;
    try {
        const job = await postJob(window.location.href, csrfToken, 'job_cancel', id);
        renderJob(job, scope || document);
    } catch (error) {
        const errorBox = scope?.querySelector('[data-update-job-error]');
        if (errorBox) {
            errorBox.textContent = error instanceof Error ? error.message : 'The cancel request failed safely.';
            errorBox.hidden = false;
        }
    } finally {
        button.disabled = false;
    }
}

async function rollbackJob(button) {
    const scope = button.closest('[data-update-job-scope]');
    const id = String(button.dataset.updateJobRollback || scope?.dataset.updateJobId || '');
    const csrfToken = csrfTokenFrom(scope || document);
    if (!id || !csrfToken || !window.confirm('Restore application files from the pre-update snapshot? Database migrations are not reversed.')) return;
    button.disabled = true;
    try {
        const job = await postJob(window.location.href, csrfToken, 'job_rollback', id);
        renderJob(job, scope || document);
        scheduleContinuation(job, window.location.href, csrfToken, scope || document);
    } catch (error) {
        const errorBox = scope?.querySelector('[data-update-job-error]');
        if (errorBox) {
            errorBox.textContent = error instanceof Error ? error.message : 'The rollback request failed safely.';
            errorBox.hidden = false;
        }
    } finally {
        button.disabled = false;
    }
}

/**
 * Install delegated update-job handling once for the document.
 *
 * Delegation is intentional because Admin side-panel HTML is inserted after the
 * initial module boot. Non-JavaScript clients keep the server-rendered POST and
 * redirect path because this module is the only code that prevents submission.
 */
export function setupAdminUpdateJobs() {
    if (document.documentElement.dataset.adminUpdateJobsReady === '1') {
        return;
    }
    document.documentElement.dataset.adminUpdateJobsReady = '1';

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || !form.matches('[data-update-job-form], [data-update-job-control]') || !window.fetch) {
            return;
        }
        event.preventDefault();
        if (form.matches('[data-update-job-control]')) {
            const action = String(form.querySelector('input[name="update_action"]')?.value || '');
            const id = String(form.querySelector('input[name="job_id"]')?.value || '');
            const csrfToken = csrfTokenFrom(form);
            postJob(endpointFor(form), csrfToken, action, id)
                .then((job) => {
                    renderJob(job, form.closest('[data-admin-side-panel-content]') || document);
                    scheduleContinuation(job, endpointFor(form), csrfToken, form.closest('[data-admin-side-panel-content]') || document);
                })
                .catch(() => {});
            return;
        }
        handleStartForm(form);
    });

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-update-job-retry]') : null;
        if (button instanceof HTMLButtonElement) {
            retryJob(button);
            return;
        }
        const cancel = event.target instanceof Element ? event.target.closest('[data-update-job-cancel]') : null;
        if (cancel instanceof HTMLButtonElement) {
            cancelJob(cancel);
            return;
        }
        const rollback = event.target instanceof Element ? event.target.closest('[data-update-job-rollback]') : null;
        if (rollback instanceof HTMLButtonElement) {
            rollbackJob(rollback);
        }
    });

    const resumeScopes = Array.from(document.querySelectorAll('[data-update-job-scope][data-update-job-id][data-update-job-status="running"]'));
    for (const scope of resumeScopes) {
        const id = String(scope.dataset.updateJobId || '');
        const csrfToken = csrfTokenFrom(scope);
        if (id && csrfToken) {
            scheduleContinuation({id, status: 'running'}, window.location.href, csrfToken, scope);
        }
    }
}
