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

import {setupAdminUpdateNotes, refreshUpdateNotes} from './admin-update-notes.js?v=20261016-readability-v1';

const ACTIVE_REQUESTS = new Map();
/**
 * Space ordinary updater continuations without concurrent browser requests.
 * Type: number. Units: milliseconds. Scope: one running job in this document.
 * Consumers: scheduleContinuation().
 * Rationale: 250 ms preserves the installed cadence after useful worker slices.
 */
const CONTINUATION_DELAY_MS = 250;
/**
 * Reduce polling while another request owns the activation runtime lease.
 * Type: number. Units: milliseconds. Scope: one waiting job in this document.
 * Consumers: scheduleContinuation().
 * Rationale: 2000 ms avoids four idle worker requests per second while waiting.
 */
const RUNTIME_WAIT_DELAY_MS = 2000;
/**
 * Purpose: Serialize passive summary requests for each current DOM fragment.
 * Units: one completed job ID or discovery marker per pending summary request.
 * Scope: this browser document; detached fragments are weakly held.
 * Consumers: refreshReleaseSummary(), checkRelease().
 * Rationale: duplicate completion renders must not issue duplicate passive reads.
 * @type {WeakMap<Element, string>}
 */
const SUMMARY_REQUESTS = new WeakMap();
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

/** Return the administrator-facing label for an updater stage. */
function stageLabel(stage) {
    return STAGE_LABELS[stage] || String(stage || '').replaceAll('_', ' ').replace(/^./, (value) => value.toUpperCase());
}

/** Find all updater job surfaces relevant to a DOM context. */
function findUpdateScopes(context = document) {
    if (context instanceof Element && context.matches('[data-update-job-scope]')) {
        return [context];
    }
    const localScopes = Array.from(context.querySelectorAll?.('[data-update-job-scope]') || []);
    if (localScopes.length > 0 || context === document) {
        return localScopes;
    }
    return Array.from(document.querySelectorAll('[data-update-job-scope]'));
}

/** Return the first updater job surface relevant to a DOM context. */
function findUpdateScope(context = document) {
    return findUpdateScopes(context)[0] || null;
}

/** Read the nearest available CSRF token without leaving the current panel. */
function csrfTokenFrom(context) {
    return String(context?.querySelector?.('input[name="csrf_token"]')?.value || document.querySelector('input[name="csrf_token"]')?.value || '');
}

/**
 * Create the updater progress-card markup when a scope is initially empty.
 * @param {HTMLElement} scope Owned job surface.
 * @return {void} Inserts progress markup if absent.
 */
function ensureScopeMarkup(scope) {
    if (!scope || scope.querySelector('[data-update-job-title]')) {
        return;
    }
    scope.innerHTML = `
        <div class="admin-update-job-heading">
            <div><p class="admin-kicker">Resumable update job</p><h3 data-update-job-title></h3></div>
            <strong data-update-job-percent>0%</strong>
        </div>
        <div class="admin-update-job-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-update-job-progress></span></div>
        <p class="muted" data-update-job-message></p>
        <details class="admin-update-job-details"><summary>Status · <code data-update-job-code></code></summary><p class="muted"><strong data-update-job-stage-label>Stage:</strong> <span data-update-job-stage></span> · <strong data-update-job-runs-label>Processing runs:</strong> <span data-update-job-attempts></span></p><p class="muted" data-update-job-runs-hint></p></details>
        <div class="notice" data-update-job-error hidden></div>
        <div class="notice" data-update-job-warnings hidden></div>
        <div class="notice" data-update-job-complete hidden>Update completed successfully.</div>
        <div class="notice" data-update-job-cancelled hidden>Update job stopped.</div>
        <div data-update-job-actions></div>`;
}

/**
 * Render a durable update job into every synchronized Admin surface.
 * @param {{id:string, status:string, stage?:string, progress?:{percent:number, message:string}}} job Durable public job state.
 * @param {Document|Element} context DOM context containing update surfaces.
 * @return {void} Renders progress and schedules passive completion presentation.
 */
function renderJob(job, context = document) {
    if (!job || !job.id) {
        return;
    }
    const documentScopes = findUpdateScopes(document);
    const scopes = documentScopes.length > 0 ? documentScopes : findUpdateScopes(context);
    for (const scope of scopes) {
        renderJobInScope(scope, job);
    }
    syncReleaseControls(job);
    if (job.status === 'completed') {
        for (const summary of document.querySelectorAll('[data-update-release-summary]')) {
            refreshReleaseSummary(summary, String(job.id));
        }
    }
}

/**
 * Keep stale start controls inactive while a job runs or its summary refreshes.
 * @param {{status:string}} job Current durable job state.
 * @return {void} Updates only updater-owned controls.
 */
function syncReleaseControls(job) {
    for (const form of document.querySelectorAll('[data-update-job-form]')) {
        const action = form.querySelector('input[name="update_action"]')?.value;
        if (job.status === 'completed' && action === 'stable_update') {
            form.hidden = true;
        }
        for (const button of form.querySelectorAll('button, input[type="submit"]')) {
            button.disabled = job.status === 'running'
                && !form.hasAttribute('data-update-manual-deployment')
                && !form.hasAttribute('data-update-recovery');
        }
    }
    for (const summary of document.querySelectorAll('[data-update-release-summary]')) {
        const check = summary.querySelector('input[value="force_check"]')?.form;
        for (const button of check?.querySelectorAll('button') || []) {
            button.disabled = job.status === 'running';
        }
    }
}

/**
 * Refresh only release presentation after completion, using passive local metadata.
 * @param {HTMLElement} summary Current release summary fragment.
 * @param {string} jobId Completed job owning this refresh.
 * @return {Promise<void>} Settles after rendering the summary or its retry affordance.
 */
async function refreshReleaseSummary(summary, jobId) {
    if (!summary.dataset.updateStatusUrl || summary.dataset.updateStatusJob === jobId || SUMMARY_REQUESTS.has(summary)) {
        return;
    }
    const errorBox = summary.parentElement?.querySelector('[data-update-status-error]');
    SUMMARY_REQUESTS.set(summary, jobId);
    summary.setAttribute('aria-busy', 'true');
    if (errorBox) errorBox.hidden = true;
    try {
        const response = await fetch(summary.dataset.updateStatusUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
        });
        const payload = await response.json();
        if (!response.ok || !payload?.ok || typeof payload.html !== 'string') {
            throw new Error('Release summary could not be refreshed.');
        }
        // A newer job can start while this passive request is pending. Do not
        // replace its controls with an older completion response.
        if (!summary.isConnected || findUpdateScopes(document).some(/** Reject a summary for a superseded job. @param {HTMLElement} scope Current progress surface. @return {boolean} Whether another job now owns the surface. */ (scope) => scope.dataset.updateJobId !== jobId)) {
            return;
        }
        summary.innerHTML = payload.html;
        refreshUpdateNotes(summary.closest('.admin-updates-page'), payload);
        summary.dataset.updateStatusJob = jobId;
    } catch {
        if (errorBox && summary.isConnected) errorBox.hidden = false;
    } finally {
        summary.removeAttribute('aria-busy');
        SUMMARY_REQUESTS.delete(summary);
        const latestScope = findUpdateScope(document);
        if (summary.isConnected && latestScope?.dataset.updateJobStatus === 'completed' && latestScope.dataset.updateJobId !== jobId) {
            refreshReleaseSummary(summary, String(latestScope.dataset.updateJobId || ''));
        }
    }
}

/**
 * Discover releases asynchronously while other pages keep their session access.
 * @param {HTMLElement} summary Owned release presentation.
 * @param {boolean} force Whether the administrator explicitly bypassed the hourly cache.
 * @return {Promise<void>} Refreshes summary and notes together without installation.
 */
async function checkRelease(summary, force = false) {
    const form = summary.querySelector('[data-update-check-form]');
    if (!form || SUMMARY_REQUESTS.has(summary)) return;
    const page = summary.closest('.admin-updates-page');
    const scope = page?.querySelector('[data-update-job-scope]');
    if (scope?.dataset.updateJobStatus === 'running' && scope.dataset.updateJobAutoResume !== '0') return;
    const previousJob = scope?.dataset.updateJobId || '';
    const body = new FormData(form);
    body.set('update_action', force ? 'force_check' : 'check_due');
    body.set('update_async', '1');
    SUMMARY_REQUESTS.set(summary, 'check');
    summary.setAttribute('aria-busy', 'true');
    const button = form.querySelector('button');
    if (button) button.disabled = true;
    try {
        const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body});
        const payload = await response.json();
        if (!response.ok || !payload?.ok || typeof payload.html !== 'string') throw new Error('Could not refresh release status.');
        if (!summary.isConnected || (scope?.dataset.updateJobId || '') !== previousJob || (scope?.dataset.updateJobStatus === 'running' && scope.dataset.updateJobAutoResume !== '0')) return;
        summary.innerHTML = payload.html;
        refreshUpdateNotes(page, payload);
        const error = page?.querySelector('[data-update-status-error]');
        if (error) error.hidden = true;
    } catch {
        const error = page?.querySelector('[data-update-status-error]');
        if (error && summary.isConnected) error.hidden = false;
    } finally {
        SUMMARY_REQUESTS.delete(summary);
        summary.removeAttribute('aria-busy');
        if (button) button.disabled = false;
        if (summary.isConnected && scope?.dataset.updateJobStatus === 'completed' && summary.dataset.updateStatusJob !== scope.dataset.updateJobId) {
            refreshReleaseSummary(summary, String(scope.dataset.updateJobId || ''));
        }
    }
}

/**
 * Render one synchronized copy of the durable job card.
 *
 * Status and Advanced tools intentionally contain the same job surface so an
 * operation remains visible in the tab where the administrator launched it.
 * @param {HTMLElement} scope Owned synchronized surface.
 * @param {{id:string, status:string, stage?:string, attempts?:number, runtime_waiting?:boolean, replacement_waiting?:boolean, integrity_warnings?:string[], can_reset?:boolean, progress?:{percent:number, message:string}, error?:{message:string, reference:string}, can_cancel?:boolean, can_rollback?:boolean, can_resume?:boolean}} job Durable public job state.
 * @return {void} Updates presentation and available recovery controls.
 */
function renderJobInScope(scope, job) {
    ensureScopeMarkup(scope);
    scope.hidden = false;
    scope.dataset.updateJobId = String(job.id);
    scope.dataset.updateJobStatus = String(job.status || '');
    // A deliberate Continue/Retry click opts into this job for the current visit.
    scope.dataset.updateJobAutoResume = '1';

    const progress = job.progress || {};
    const percent = Number.isFinite(Number(progress.percent)) ? Number(progress.percent) : Number(job.stage_percent || 0);
    const safePercent = Math.max(0, Math.min(100, Math.round(percent)));
    const percentLabel = scope.querySelector('[data-update-job-percent]');
    if (percentLabel) percentLabel.textContent = `${safePercent}%`;
    scope.querySelector('[data-update-job-title]').textContent = stageLabel(job.stage);
    const page = scope.closest('.admin-updates-page');
    const runsLabel = scope.querySelector('[data-update-job-runs-label]');
    if (runsLabel) runsLabel.textContent = (page?.dataset.updateJobRunsLabel || 'Processing runs') + ':';
    const runsHint = scope.querySelector('[data-update-job-runs-hint]');
    if (runsHint) runsHint.textContent = page?.dataset.updateJobRunsHint
        || 'Short processing requests, including waits. This is not the number of errors or Retry clicks.';
    const stageHeading = scope.querySelector('[data-update-job-stage-label]');
    if (stageHeading) stageHeading.textContent = (page?.dataset.updateJobStageLabel || 'Stage') + ':';
    const title = scope.querySelector('[data-update-job-title]');
    if (job.status === 'failed') title.textContent = page?.dataset.updateJobFailedLabel || 'Update stopped';
    if (job.status === 'paused') title.textContent = page?.dataset.updateJobPausedLabel || 'Update paused';
    if (job.status === 'cancelled') title.textContent = page?.dataset.updateJobCancelledLabel || 'Update cancelled';
    if (job.status === 'completed') title.textContent = page?.dataset.updateJobCompletedLabel || 'Update completed';
    if (job.replacement_waiting) title.textContent = page?.dataset.updateJobReplacementWaiting
        || 'Waiting for the previous updater request to finish before reset.';
    const code = scope.querySelector('[data-update-job-code]') || scope.querySelector('.admin-update-job-heading code');
    if (code) code.textContent = String(job.id);
    scope.querySelector('[data-update-job-stage]').textContent = stageLabel(job.stage);
    scope.querySelector('[data-update-job-attempts]').textContent = String(job.attempts || 0);
    scope.querySelector('[data-update-job-message]').textContent = job.replacement_waiting
        ? page?.dataset.updateJobReplacementWaiting || 'Waiting to replace the previous updater job.'
        : job.runtime_waiting
        ? page?.dataset.updateJobWaitingRuntime || 'Waiting for other website requests to finish. The update will continue automatically.'
        : String(progress.message || 'Update job is ready to continue.');
    const progressBar = scope.querySelector('[data-update-job-progress]');
    if (progressBar) progressBar.style.width = `${safePercent}%`;
    const progressShell = progressBar?.parentElement;
    if (progressShell) progressShell.setAttribute('aria-valuenow', String(safePercent));

    const errorBox = scope.querySelector('[data-update-job-error]');
    if (errorBox) {
        const reference = String(job.error?.reference || '');
        errorBox.textContent = job.error ? `${String(job.error.message || 'Update failed.')} Reference: ${reference}` : '';
        errorBox.hidden = !job.error || Boolean(job.replacement_waiting);
    }
    const warnings = scope.querySelector('[data-update-job-warnings]');
    if (warnings) {
        warnings.textContent = (page?.dataset.updateJobWarnings || 'File differences recorded as warnings:')
            + ' ' + (job.integrity_warnings || []).join(', ');
        warnings.hidden = !job.integrity_warnings?.length;
    }

    const completeBox = scope.querySelector('[data-update-job-complete]');
    if (completeBox) completeBox.hidden = job.status !== 'completed';
    const cancelledBox = scope.querySelector('[data-update-job-cancelled]');
    if (cancelledBox) cancelledBox.hidden = job.status !== 'cancelled';

    const actions = scope.querySelector('[data-update-job-actions]');
    if (actions) {
        actions.replaceChildren();
        if (job.replacement_waiting) return;
        if (job.can_reset && !job.replacement_waiting) {
            const reset = document.createElement('button');
            reset.type = 'button';
            reset.className = 'button secondary';
            reset.textContent = page?.dataset.updateJobReset || 'Reset updater / finish FTP update';
            reset.dataset.updateJobControlAction = 'reset_updater';
            actions.append(reset);
        }
        if (job.status === 'running' || job.status === 'paused') {
            const control = document.createElement('button');
            control.type = 'button';
            control.className = 'button secondary';
            control.textContent = job.status === 'paused'
                ? page?.dataset.updateJobResume || 'Continue update'
                : page?.dataset.updateJobPause || 'Pause';
            control.dataset.updateJobControlAction = job.status === 'paused' ? 'job_resume' : 'job_pause';
            actions.append(control);
        }
        if (job.status === 'failed' && job.can_resume !== false) {
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'button secondary';
            retry.textContent = page?.dataset.updateJobRetry || 'Try to continue';
            retry.dataset.updateJobRetry = String(job.id);
            actions.append(retry);
        } else if (job.status === 'running') {
            const status = document.createElement('span');
            status.className = 'muted';
            status.textContent = job.runtime_waiting
                ? page?.dataset.updateJobWaitingRuntime || 'Waiting for other website requests to finish.'
                : page?.dataset.updateJobContinuing || 'The update is continuing automatically.';
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

/** Post one bounded updater transition and return its durable job state. */
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

/** Resolve the updater endpoint from a form or the current page fallback. */
function endpointFor(formOrScope) {
    if (formOrScope instanceof HTMLFormElement && formOrScope.action) {
        return formOrScope.action;
    }
    return window.location.href;
}

/**
 * Schedule one continuation with slower polling during runtime contention.
 * @param {{id:string,status:string,runtime_waiting?:boolean,replacement_waiting?:boolean,replacement_action?:string}} job Last worker state and optional pending replacement action.
 * @param {string} endpoint Same-origin updater action URL.
 * @param {string} csrfToken Authenticated form token.
 * @param {Document|Element} context Current updater presentation container.
 * @returns {void} Schedules at most one in-flight continuation for this job.
 */
function scheduleContinuation(job, endpoint, csrfToken, context = document) {
    if (!job?.id || (job.status !== 'running' && !job.replacement_waiting) || ACTIVE_REQUESTS.has(String(job.id))) {
        return;
    }
    const id = String(job.id);
    const controller = new AbortController();
    ACTIVE_REQUESTS.set(id, controller);
    window.setTimeout(async () => {
        if (controller.signal.aborted) return;
        try {
            const next = await postJob(endpoint, csrfToken,
                job.replacement_waiting ? job.replacement_action : 'job_continue', id);
            if (controller.signal.aborted) return;
            renderJob(next, context);
            if (ACTIVE_REQUESTS.get(id) === controller) ACTIVE_REQUESTS.delete(id);
            if (next.status === 'running' || next.replacement_waiting) {
                scheduleContinuation(next, endpoint, csrfToken, context);
            }
        } catch (error) {
            if (controller.signal.aborted) return;
            if (ACTIVE_REQUESTS.get(id) === controller) ACTIVE_REQUESTS.delete(id);
            const scope = findUpdateScope(context) || findUpdateScope(document);
            const errorBox = scope?.querySelector('[data-update-job-error]');
            if (errorBox) {
                errorBox.textContent = error instanceof Error ? error.message : 'The update request stopped. Reopen this page to resume from the saved checkpoint.';
                errorBox.hidden = false;
            }
        }
    }, job.runtime_waiting || job.replacement_waiting ? RUNTIME_WAIT_DELAY_MS : CONTINUATION_DELAY_MS);
}

/**
 * Start an update job from an intercepted Admin form without navigation.
 * @param {HTMLFormElement} form Submitted updater-owned form.
 * @return {Promise<void>} Starts bounded continuation or renders a safe failure.
 */
async function handleStartForm(form) {
    const endpoint = endpointFor(form);
    const csrfToken = csrfTokenFrom(form);
    if (!csrfToken) return;
    const body = new FormData(form);
    if (['reset_updater', 'finish_manual_deployment', 'beta_revert'].includes(body.get('update_action'))) {
        for (const controller of ACTIVE_REQUESTS.values()) controller.abort();
        ACTIVE_REQUESTS.clear();
    }
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
        const running = findUpdateScope(document)?.dataset.updateJobStatus === 'running';
        const recovery = form.matches('[data-update-manual-deployment], [data-update-recovery]');
        buttons.forEach(/** Preserve recovery controls while a new job runs. @param {HTMLButtonElement|HTMLInputElement} button Submitted control. @return {void} Updates disabled state. */ (button) => { button.disabled = running && !recovery; });
    }
}

/**
 * Submit one explicit pause, resume or retry without closing the drawer.
 * @param {HTMLButtonElement} button Delegated job control.
 * @param {string} action Owned job_retry, job_pause, job_resume or reset_updater action.
 * @returns {Promise<void>} Renders the durable result and resumes running work.
 */
async function retryJob(button, action = 'job_retry') {
    const scope = button.closest('[data-update-job-scope]');
    const id = String(button.dataset.updateJobRetry || scope?.dataset.updateJobId || '');
    const csrfToken = csrfTokenFrom(scope || document);
    if (!id || !csrfToken) return;
    if (action === 'job_pause' || action === 'reset_updater') {
        ACTIVE_REQUESTS.get(id)?.abort();
        ACTIVE_REQUESTS.delete(id);
    }
    button.disabled = true;
    try {
        const job = await postJob(window.location.href, csrfToken, action, id);
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

/** Cancel a prepared update before active files are replaced. */
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

/** Restore application files from the updater's pre-activation snapshot. */
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
 * @return {void} Installs delegated listeners and resumes existing running jobs.
 */
export function setupAdminUpdateJobs() {
    setupAdminUpdateNotes();
    if (document.documentElement.dataset.adminUpdateJobsReady === '1') {
        return;
    }
    document.documentElement.dataset.adminUpdateJobsReady = '1';

    document.addEventListener('submit', /** Own background discovery forms before generic panel handlers. @param {SubmitEvent} event Submitted form. @return {void} Checks releases without navigation. */ event => {
        if (!(event.target instanceof HTMLFormElement) || !event.target.matches('[data-update-check-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const summary = event.target.closest('[data-update-release-summary]');
        if (summary) checkRelease(summary, true);
    }, true);
    /** Admit one age check per mounted Updates page. @return {void} Starts passive-interval discovery for dynamic pages too. */
    function discoverPages() {
        for (const page of document.querySelectorAll('.admin-updates-page')) {
            if (page.dataset.updateCheckReady === '1') continue;
            page.dataset.updateCheckReady = '1';
            const summary = page.querySelector('[data-update-release-summary]');
            if (summary) checkRelease(summary);
        }
    }
    discoverPages();
    new MutationObserver(/** Admit newly injected Updates workspaces. @return {void} Checks each page once. */ () => discoverPages()).observe(document.body, {childList: true, subtree: true});

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
                .catch((error) => {
                    const scope = findUpdateScope(form.closest('[data-admin-side-panel-content]') || document);
                    const errorBox = scope?.querySelector('[data-update-job-error]');
                    if (errorBox) {
                        errorBox.textContent = error instanceof Error ? error.message : 'The updater control failed.';
                        errorBox.hidden = false;
                    }
                });
            return;
        }
        handleStartForm(form);
    });

    document.addEventListener('click', /** Route dynamic recovery and passive-summary controls. @param {MouseEvent} event Delegated click. @return {void} Dispatches the owned updater action. */ (event) => {
        const control = event.target instanceof Element ? event.target.closest('[data-update-job-control-action]') : null;
        if (control instanceof HTMLButtonElement) {
            retryJob(control, control.dataset.updateJobControlAction);
            return;
        }
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
            return;
        }
        const refresh = event.target instanceof Element ? event.target.closest('[data-update-status-refresh]') : null;
        if (refresh instanceof HTMLButtonElement) {
            const summary = refresh.closest('.admin-update-workspace')?.querySelector('[data-update-release-summary]');
            const scope = findUpdateScope(document);
            if (summary && scope?.dataset.updateJobStatus === 'completed') {
                refreshReleaseSummary(summary, String(scope.dataset.updateJobId || ''));
            } else if (summary) {
                checkRelease(summary, true);
            }
        }
    });

    const resumeScopes = Array.from(document.querySelectorAll('[data-update-job-scope][data-update-job-id][data-update-job-status="running"]'));
    for (const scope of resumeScopes) {
        if (scope.dataset.updateJobAutoResume === '0') continue;
        const id = String(scope.dataset.updateJobId || '');
        const csrfToken = csrfTokenFrom(scope);
        if (id && csrfToken) {
            scheduleContinuation({id, status: 'running'}, window.location.href, csrfToken, scope);
        }
    }
}
