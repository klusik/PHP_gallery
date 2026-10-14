/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-cache-cleanup.js
 * Module Type: Browser Module
 * Purpose: Complete bounded manual updater-cache cleanup in its existing Admin surface.
 * Responsibilities: Delegate dynamic forms, preserve mutation envelopes and update owned status without navigation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import { completeAdminMutation } from './admin-mutation-completion.js?v=20261014-floating-shell-v1';
import { setupAdminCacheLifecycle } from './admin-cache-lifecycle.js?v=20261014-cache-lifecycle-v6';

/**
 * Bound a stalled manual request while preserving a visible, safe retry.
 * Type: number. Units: milliseconds. Scope: one cache-cleanup browser request.
 * Consumers: delegated cache actions and read-only updater progress requests.
 * Rationale: twenty seconds tolerates cold shared hosting; it never queues an automatic retry of a write.
 */
const CACHE_CLEANUP_REQUEST_TIMEOUT_MS = 20000;

/**
 * Refresh visible blocking-job checkpoints without advancing the updater.
 * Type: number. Units: milliseconds. Scope: one mounted cache panel.
 * Consumers: the read-only updater progress poller.
 * Rationale: three seconds keeps progress current without a rapid request loop.
 */
const CACHE_JOB_POLL_INTERVAL_MS = 3000;

/**
 * Delegate dynamic cache/stop forms and diagnostic copying once at document scope.
 * @returns {void} Installs capture-phase delegation and keeps the current page/panel mounted.
 */
export function setupAdminCacheCleanup() {
    setupAdminCacheLifecycle();
    if (document.documentElement.dataset.cacheCleanupBound === '1') return;
    document.documentElement.dataset.cacheCleanupBound = '1';
    let pending = false;
    const pollers = new WeakMap();
    const watchJob = (region, jobId) => {
        const previous = pollers.get(region);
        if (previous) window.clearTimeout(previous.timer);
        const state = {timer:0,previous:null,previousCounters:null,lastProgressAt:Date.now(),samples:0};
        pollers.set(region, state);
        const tick = async () => {
            if (!region.isConnected || pollers.get(region) !== state) return;
            if (document.hidden || region.getClientRects().length === 0 || pending) {
                state.timer = window.setTimeout(tick, CACHE_JOB_POLL_INTERVAL_MS);
                return;
            }
            const form = region.querySelector('[data-cache-job-stop]');
            const bar = region.querySelector('[data-cache-progress]');
            const label = region.querySelector('[data-cache-job-progress]');
            if (!form || !bar || !label) return;
            const url = new URL(form.dataset.updateStatusUrl, location.href);
            url.searchParams.set('job_id', jobId);
            const abort = new AbortController();
            const timeout = window.setTimeout(() => abort.abort(), CACHE_CLEANUP_REQUEST_TIMEOUT_MS);
            let again = false;
            try {
                const response = await fetch(url, {credentials:'same-origin',headers:{Accept:'application/json'},signal:abort.signal});
                const envelope = await response.json();
                if (!response.ok || envelope.ok !== true || envelope.job?.id !== jobId) throw new Error('Invalid job status');
                if (pollers.get(region) !== state) return;
                const job = envelope.job;
                const current = Number(job.progress?.current);
                const total = Number(job.progress?.total);
                const signature = JSON.stringify([job.stage,current,total]);
                const progressChanged = state.previous !== null && state.previous !== signature;
                const previousCounters = state.previousCounters;
                const deltaCurrent = previousCounters?.stage === job.stage && previousCounters.total === total && Number.isFinite(current)
                    && Number.isFinite(previousCounters.current) ? current - previousCounters.current : null;
                state.previousCounters = {stage:job.stage,current,total};
                if (state.previous === null || progressChanged) state.lastProgressAt = Date.now();
                state.samples++;
                state.previous = signature;
                const noProgressSeconds = Math.max(0, Math.floor((Date.now() - state.lastProgressAt) / 1000));
                const runtime = envelope.diagnostic || {};
                const worker = ['held','available','missing','unreadable','unsafe','unavailable'].includes(runtime.worker_lock) ? runtime.worker_lock : 'unavailable';
                bar.hidden = false;
                bar.dataset.state = job.status;
                label.dataset.state = job.status;
                bar.removeAttribute('value');
                label.textContent = `${jobId} · ${job.status} · ${job.stage}`;
                label.textContent += ` · ${worker === 'held' ? (form.dataset.workerActiveLabel || 'Worker lock held')
                    : (form.dataset.workerIdleLabel || 'Worker activity not confirmed')}`;
                if (Number.isFinite(current) && Number.isFinite(total) && total > 0) {
                    const percent = Math.max(0, Math.min(100, Math.floor(current / total * 100)));
                    bar.value = percent;
                    label.textContent += ` · ${form.dataset.phaseLabel || 'Phase'}: ${current}/${total} (${percent}%)`;
                }
                // Bound already-redacted updater text for the compact UI and copied trace.
                // Type: string. Units: characters. Scope: one latest checkpoint.
                // Consumers: progress label and diagnostic copying.
                // Rationale: 2048 message characters and 128 reference characters
                // retain actionable support evidence without copying unbounded public state.
                const safeMessage = typeof job.error?.message === 'string' ? job.error.message.slice(0, 2048) : '';
                const reference = typeof job.error?.reference === 'string' ? job.error.reference.slice(0, 128) : '';
                if (job.status === 'failed') label.textContent += ` · ${safeMessage || form.dataset.failedLabel || 'Update failed.'}${reference ? ` [${reference}]` : ''}`;
                const trace = region.querySelector('[data-cache-diagnostic-events]');
                if (trace) {
                    let records = [];
                    try { const parsed = JSON.parse(trace.textContent); records = Array.isArray(parsed) ? parsed : [parsed]; } catch { /* Start a safe client checkpoint if an older fragment has no JSON trace. */ }
                    const checkpoint = {stage:'updater_checkpoint',source:'admin_updates_readonly',updater_job_id:jobId,
                        updater_status:job.status,updater_stage:job.stage,updater_updated_at:job.updated_at,
                        observed_at:runtime.observed_at,checkpoint_age_seconds:runtime.checkpoint_age_seconds,
                        worker_lock:worker,observed_samples:state.samples,progress_changed:progressChanged,delta_current:deltaCurrent,
                        no_observed_progress_seconds:noProgressSeconds,operation:job.operation,trigger:job.trigger,
                        created_at:job.created_at,started_at:job.started_at,attempts:job.attempts,
                        can_resume:job.can_resume,can_cancel:job.can_cancel,can_rollback:job.can_rollback,progress_unit:job.progress?.unit};
                    const runId = records.find(item => /^[a-f0-9]{32}$/.test(item?.run_id || ''))?.run_id;
                    if (runId) checkpoint.run_id = runId;
                    if (Number.isFinite(current) && Number.isFinite(total) && total > 0) {
                        checkpoint.current = current;
                        checkpoint.total = total;
                    }
                    if (safeMessage) checkpoint.error_message = safeMessage;
                    if (reference) checkpoint.error_reference = reference;
                    const lastFailure = job.result?.last_failure;
                    if (lastFailure && typeof lastFailure === 'object') {
                        checkpoint.last_failure_message = typeof lastFailure.message === 'string' ? lastFailure.message.slice(0, 2048) : '';
                        checkpoint.last_failure_reference = typeof lastFailure.reference === 'string' ? lastFailure.reference.slice(0, 128) : '';
                        checkpoint.last_failure_stage = lastFailure.stage;
                        checkpoint.last_failure_at = lastFailure.at;
                    }
                    records = records.filter(item => item?.stage !== 'updater_checkpoint');
                    records.push(checkpoint);
                    if (records.length > 24) records = [records[0], ...records.slice(-23)];
                    trace.textContent = JSON.stringify(records, null, 2);
                }
                form.querySelector('[name="job_id"]').value = jobId;
                form.hidden = job.can_cancel !== true;
                again = job.status === 'running';
                if (job.status === 'completed' || job.status === 'cancelled') bar.value = 100;
            } catch {
                label.textContent = form.dataset.statusFailedLabel || 'Could not refresh update progress.';
                form.hidden = true;
            } finally {
                window.clearTimeout(timeout);
                if (again && pollers.get(region) === state) state.timer = window.setTimeout(tick, CACHE_JOB_POLL_INTERVAL_MS);
            }
        };
        void tick();
    };
    document.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-cache-diagnostic-copy]');
        if (!(button instanceof HTMLButtonElement)) return;
        event.preventDefault();
        const details = button.closest('[data-cache-diagnostics]');
        const source = details?.querySelector('[data-cache-diagnostic-events]');
        const status = details?.querySelector('[data-cache-copy-status]');
        if (!source || !status) return;
        const text = source.textContent || '';
        let copied = false;
        button.disabled = true;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                copied = true;
            }
        } catch { /* Preserve copy support on HTTP installations and denied clipboard access. */ }
        if (!copied) {
            const field = document.createElement('textarea');
            field.value = text;
            field.readOnly = true;
            field.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
            document.body.append(field);
            field.focus({preventScroll:true});
            field.select();
            try { copied = document.execCommand('copy'); } catch { copied = false; }
            field.remove();
            button.focus({preventScroll:true});
        }
        status.textContent = copied ? button.dataset.copiedLabel : button.dataset.copyFailedLabel;
        button.disabled = false;
    }, true);
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-cache-cleanup-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending) return;
        const region = form.closest('[data-admin-cache-cleanup]');
        const button = form.querySelector('[data-cache-cleanup-button]');
        const status = region?.querySelector('[data-cache-cleanup-result]');
        if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement)) return;
        const runId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
        const isStop = form.matches('[data-cache-job-stop]');
        pending = true;
        button.disabled = true;
        button.textContent = button.dataset.workingLabel;
        const diagnosticLabel = button.dataset.diagnosticLabel || 'Diagnostic ID';
        status.textContent = `${button.dataset.workingLabel} · ${diagnosticLabel}: ${runId}`;
        status.dataset.state = 'running';
        const details = region.querySelector('[data-cache-diagnostics]');
        const events = region.querySelector('[data-cache-diagnostic-events]');
        const logLink = region.querySelector('[data-cache-diagnostic-log]');
        const bar = region.querySelector('[data-cache-progress]');
        const copyStatus = region.querySelector('[data-cache-copy-status]');
        if (copyStatus) copyStatus.textContent = '';
        if (bar) { bar.hidden = false; bar.removeAttribute('value'); }
        if (details) details.hidden = false;
        if (events) events.textContent = JSON.stringify({run_id: runId, stage: 'browser_request_started'}, null, 2);
        if (logLink) {
            const url = new URL(logLink.href, location.href);
            url.searchParams.set('q', runId);
            logLink.href = url.href;
        }
        region.setAttribute('aria-busy', 'true');
        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), CACHE_CLEANUP_REQUEST_TIMEOUT_MS);
        let nextLabel = button.dataset.idleLabel;
        let failureCode = 'network_error';
        let httpStatus = 0;
        let blockingJobId = '';
        let stepDone = false;
        try {
            const body = new FormData(form);
            body.set('ajax', '1');
            body.set('cache_run_id', runId);
            const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin',
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body, signal: abort.signal});
            httpStatus = response.status;
            failureCode = 'invalid_json';
            const envelope = await response.json();
            if (typeof envelope.ok !== 'boolean' || typeof envelope.message !== 'string') throw new Error('Invalid cleanup envelope');
            stepDone = envelope.cache_cleanup?.done === true || (isStop && envelope.ok === true);
            const serverId = typeof envelope.diagnostic_id === 'string' && /^[a-f0-9]{32}$/.test(envelope.diagnostic_id) ? envelope.diagnostic_id : runId;
            status.textContent = `${envelope.message} · ${diagnosticLabel}: ${serverId}`;
            status.dataset.state = envelope.ok === true ? 'success' : 'error';
            if (events && Array.isArray(envelope.cache_diagnostics)) events.textContent = JSON.stringify(envelope.cache_diagnostics.slice(0, 24), null, 2);
            if (Array.isArray(envelope.cache_diagnostics) && envelope.cache_diagnostics.some(item => item.admin_log_written === false)) {
                status.textContent += ` · ${button.dataset.logFailureLabel || 'Admin log write failed; see diagnostics / PHP error log.'}`;
                if (details) details.open = true;
            }
            if (envelope.ok !== true && details) details.open = true;
            const blocker = envelope.cache_diagnostics?.find(item => /^[0-9]{14}-[a-f0-9]{12}$/.test(item.updater_job_id || ''));
            if (blocker) blockingJobId = blocker.updater_job_id;
            if (bar && envelope.cache_cleanup?.done === true) bar.value = 100;
            if (isStop && envelope.ok === true) {
                const poller = pollers.get(region);
                if (poller) window.clearTimeout(poller.timer);
                pollers.delete(region);
                form.hidden = true;
                if (bar) bar.value = 100;
            }
            if (response.ok && envelope.ok === true) {
                // There is no affected public media context: only updater-owned
                // trash changed. Preserve the complete canonical envelope.
                failureCode = 'completion_error';
                await completeAdminMutation(envelope);
                if (envelope.cache_cleanup?.can_continue === true) nextLabel = button.dataset.continueLabel;
            }
        } catch {
            if (abort.signal.aborted) failureCode = 'request_timeout';
            status.textContent = `${button.dataset.errorLabel} · ${failureCode}${httpStatus ? ` (HTTP ${httpStatus})` : ''} · ${diagnosticLabel}: ${runId}`;
            status.dataset.state = 'error';
            if (details) details.open = true;
            if (events) events.textContent = JSON.stringify({run_id:runId,stage:'browser_failed',failure_code:failureCode,http_status:httpStatus}, null, 2);
            // Report only enum diagnostics, never HTML response bodies, URLs or credentials.
            const report = new FormData(form);
            report.set('site_maintenance_action', 'cache_diagnostic');
            report.set('cache_run_id', runId);
            report.set('diagnostic_code', failureCode);
            report.set('http_status', String(httpStatus));
            void fetch(form.action, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json'},
                body:report, keepalive:true}).catch(() => {});
        } finally {
            window.clearTimeout(timeout);
            pending = false;
            button.disabled = false;
            button.textContent = nextLabel;
            region.removeAttribute('aria-busy');
            if (bar && !stepDone && !blockingJobId) bar.hidden = true;
            if (blockingJobId) watchJob(region, blockingJobId);
        }
    }, true);
}
