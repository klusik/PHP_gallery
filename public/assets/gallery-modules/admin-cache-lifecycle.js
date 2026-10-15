/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-cache-lifecycle.js
 * Module Type: Browser Module
 * Purpose: Continue explicit cache inventory and approved reclamation without navigating Admin.
 * Responsibilities: Preserve preview identity, expose measured progress and diagnostics, and stop writes on transport failure.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import { completeAdminMutation } from './admin-mutation-completion.js?v=20261014-floating-shell-v1';

/**
 * Bound each lifecycle request while preserving restartable server state.
 * Type: number. Units: milliseconds. Scope: one explicit browser slice.
 * Consumers: lifecycle fetch cancellation.
 * Rationale: twenty seconds tolerates cold hosting; failures never retry writes automatically.
 */
const CACHE_LIFECYCLE_TIMEOUT_MS = 20000;

/**
 * Yield between explicitly authorized slices so progress paints and the server has breathing room.
 * Type: number. Units: milliseconds. Scope: one mounted action chain.
 * Consumers: explicit inventory/cleanup continuation.
 * Rationale: 100 ms separates bounded requests; hidden/unmounted panels stop their chain.
 */
const CACHE_LIFECYCLE_YIELD_MS = 100;

/**
 * Replace the supplied regional sample using the server-rendered localized row template.
 * @param {Element} scope Mounted lifecycle card containing labels, template and sample list.
 * @param {Array<{path?:string,phase?:string,round?:number,last_serviced_at?:number,last_completed_at?:number,next_due_at?:number,retry_at?:number,failures?:number,cursor_path?:string,cursor_offset?:number}>} regions Passive API sample; absent evidence stays unknown.
 * @returns {void} Updates presentation only, preserving open row details by relative identity.
 */
function renderAdminCacheRegions(scope, regions) {
    const view = scope.querySelector('[data-cache-region-view]');
    const template = view?.querySelector('[data-cache-region-template]');
    const list = view?.querySelector('[data-cache-region-details]');
    if (!(template instanceof HTMLTemplateElement) || !list) return;
    const labels = JSON.parse(view.dataset.cacheRegionLabels);
    const openPaths = new Set(Array.from(list.children).filter(row => row.querySelector('details')?.open)
        .map(row => row.querySelector('[data-cache-region-field="path"]').textContent));
    const timestamp = (target, value, missing) => {
        target.replaceChildren();
        const date = Number(value) > 0 ? new Date(Number(value) * 1000) : null;
        if (!date || !Number.isFinite(date.getTime())) { target.textContent = missing; return; }
        const time = document.createElement('time');
        time.dateTime = date.toISOString().replace('.000Z', 'Z');
        time.textContent = time.dateTime.slice(0,19).replace('T', ' ') + ' UTC';
        target.append(time);
    };
    list.replaceChildren(...regions.map(region => {
        const row = template.content.firstElementChild.cloneNode(true);
        const field = name => row.querySelector(`[data-cache-region-field="${name}"]`);
        const path = String(region.path ?? '');
        const names = new Map([['@discovery',labels.discovery],['@files',labels.files],['@history',labels.history]]);
        field('name').textContent = names.get(path) ?? path;
        const phase = region.phase ?? 'unobserved';
        field('state').textContent = ['idle','unobserved','inventory','preview','cleanup','complete'].includes(phase) ? labels[phase] : labels.unknown;
        if (Number(region.failures) > 0) field('state').textContent += ` · ${labels.failures}: ${region.failures}`;
        const due = Number(region.next_due_at || 0), retry = Number(region.retry_at || 0);
        row.querySelector('[data-cache-region-next-label]').textContent = retry > 0 && retry >= due ? labels.retry : labels.next;
        timestamp(field('last_completed_at'), region.last_completed_at, labels.missing);
        timestamp(field('next'), Math.max(due, retry), labels.unscheduled);
        ['path','phase','round','last_serviced_at','next_due_at','retry_at','failures','cursor_path','cursor_offset'].forEach(name => {
            if (['last_serviced_at','next_due_at','retry_at'].includes(name)) {
                timestamp(field(name), region[name], name === 'last_serviced_at' ? labels.missing : labels.unscheduled);
            } else {
                field(name).querySelector('code').textContent = region[name] === undefined || region[name] === null || region[name] === '' ? labels.missing : String(region[name]);
            }
        });
        row.querySelector('details').open = openPaths.has(path);
        return row;
    }));
    const empty = view.querySelector('[data-cache-region-empty]');
    if (empty) empty.hidden = regions.length > 0;
}

/**
 * Delegate lifecycle controls for initial and dynamically replaced Admin fragments.
 * @returns {void} Installs one capture listener; every completed envelope reaches the canonical mutation completion owner.
 */
export function setupAdminCacheLifecycle() {
    if (document.documentElement.dataset.cacheLifecycleBound === '1') return;
    document.documentElement.dataset.cacheLifecycleBound = '1';
    let pending = false;
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-cache-lifecycle-form]')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending || !(event.submitter instanceof HTMLButtonElement)) return;
        const region = form.closest('[data-admin-cache-lifecycle]');
        if (!region) return;
        const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
        const status = region.querySelector('[data-cache-lifecycle-status]');
        const bar = region.querySelector('[data-cache-lifecycle-progress]');
        const details = region.querySelector('[data-cache-diagnostics]');
        const events = region.querySelector('[data-cache-diagnostic-events]');
        const journal = [];
        const runId = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
        let action = event.submitter.value;
        let failureCode = 'network_error';
        let failureMessage = '';
        let requestTimedOut = false;
        pending = true;
        buttons.forEach(button => { button.disabled = true; });
        region.setAttribute('aria-busy', 'true');
        if (bar) { bar.hidden = false; bar.removeAttribute('value'); }
        if (details) details.hidden = false;
        if (status) status.textContent = `Working… · Diagnostic ID: ${runId}`;
        const formatBytes = bytes => `${(Number(bytes || 0) / (1024 * 1024)).toFixed(2)} MiB`;
        const formatEpoch = value => Number(value) > 0 ? new Date(Number(value) * 1000).toISOString() : '—';
        try {
            do {
                const body = new FormData(form);
                body.set('ajax', '1');
                body.set('site_maintenance_action', action);
                body.set('cache_run_id', runId);
                const abort = new AbortController();
                requestTimedOut = false;
                failureCode = 'network_error';
                const timeout = window.setTimeout(() => { requestTimedOut = true; abort.abort(); }, CACHE_LIFECYCLE_TIMEOUT_MS);
                let envelope;
                try {
                    const response = await fetch(form.action, {method:'POST',credentials:'same-origin',
                        headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body,signal:abort.signal});
                    failureCode = 'invalid_json';
                    envelope = await response.json();
                    if (Array.isArray(envelope.cache_diagnostics)) {
                        journal.push(...envelope.cache_diagnostics.slice(-48));
                        if (journal.length > 48) journal.splice(0, journal.length - 48);
                    }
                    if (events) events.textContent = JSON.stringify(journal.slice(-48), null, 2);
                    if (!response.ok || envelope.ok !== true) {
                        failureCode = 'http_error';
                        failureMessage = typeof envelope.message === 'string' ? envelope.message : '';
                        throw new Error('Cache action refused');
                    }
                } finally {
                    window.clearTimeout(timeout);
                }
                const state = envelope.cache_lifecycle;
                if (!state || typeof state.id !== 'string' || typeof state.phase !== 'string') throw new Error('Invalid cache progress');
                form.elements.namedItem('preview_id').value = state.id;
                if (status) status.textContent = `${envelope.message} Files: ${state.files}; candidates: ${state.candidates}; skipped: ${state.skipped}.`
                    + (state.partial ? ` Partial scan: ${state.blocked} blocked, ${state.errors} errors.` : '') + ` · Diagnostic ID: ${runId}`;
                const schedulerSummary = region.querySelector('[data-cache-scheduler-summary]');
                if (schedulerSummary) schedulerSummary.textContent = `Scheduler: ${state.scheduler_health || 'unobserved'}`;
                const schedulerStatus = region.querySelector('[data-cache-scheduler-status]');
                if (schedulerStatus) schedulerStatus.textContent = `Scheduler: ${state.scheduler_health}; last attempt: ${formatEpoch(state.last_attempt_at)}; last progress: ${formatEpoch(state.last_progress_at)}; observed: ${formatEpoch(state.last_observed_at)}; next due: ${formatEpoch(state.next_due)}; pending: ${formatBytes(state.pending_bytes)}. ${state.scheduler_reason || ''}`
                    + (state.stale ? ' Observation is stale or absent.' : '') + (state.stalled ? ' No recent successful progress.' : '')
                    + (state.superseded_manual_at ? ' An idle manual preview was replaced; its old ID is invalid.' : '');
                buttons.forEach(button => {
                    button.hidden = state.registry && state.scheduled ? button.value !== 'cache_inventory_start' : button.value === 'cache_inventory_start' ? ['inventory','cleanup'].includes(state.phase)
                        : button.value === 'cache_inventory_step' ? state.phase !== 'inventory'
                            : button.value === 'cache_cleanup_start' ? state.phase !== 'preview' : state.phase !== 'cleanup';
                });
                region.querySelectorAll('[data-cache-owner]').forEach(row => {
                    const total = state.totals?.[row.dataset.cacheOwner];
                    row.querySelector('[data-cache-owner-bytes]').textContent = (total ? formatBytes(total.bytes) : '—');
                    row.querySelector('[data-cache-owner-eligible]').textContent = (total ? formatBytes(total.reclaimable_bytes) : '—');
                });
                if (Array.isArray(state.regions_sample)) renderAdminCacheRegions(region, state.regions_sample);
                const sample = region.querySelector('[data-cache-lifecycle-sample]');
                if (sample) sample.replaceChildren(...(state.sample || []).slice(0,16).map(item => {
                    const entry = document.createElement('li');
                    entry.textContent = `${item.path} — ${formatBytes(item.bytes)}`;
                    return entry;
                }));
                const evidence = region.querySelector('[data-cache-registry-evidence]');
                if (evidence && state.registry) {
                    const values = {done:state.regions_completed,total:state.regions_total,working:state.regions_working,
                        oldest:formatEpoch(state.oldest_completed_at),removed:formatEpoch(state.last_removed_at)};
                    evidence.textContent = (evidence.dataset.cacheRegistryTemplate || evidence.textContent)
                        .replace(/\{(done|total|working|oldest|removed)\}/g, (match, key) => String(values[key] ?? '—'));
                }
                if (bar && state.phase === 'complete') bar.value = 100;
                else if (bar && state.phase === 'cleanup' && state.candidate_bytes > 0) bar.value = Math.min(99, state.bytes_removed / state.candidate_bytes * 100);
                else if (bar && state.phase === 'preview') bar.value = 100;
                failureCode = 'completion_error';
                await completeAdminMutation(envelope);
                action = state.scheduled ? '' : state.phase === 'inventory' ? 'cache_inventory_step' : state.phase === 'cleanup' ? 'cache_cleanup_step' : '';
                // Continue only the action the user started. Preview completion never approves deletion.
                if (action) await new Promise(resolve => window.setTimeout(resolve, CACHE_LIFECYCLE_YIELD_MS));
            } while (action && region.isConnected && !document.hidden && region.getClientRects().length > 0);
        } catch {
            if (requestTimedOut) failureCode = 'request_timeout';
            if (status) status.textContent = `${failureMessage || 'Cache action stopped safely.'} ${failureCode}. Resume explicitly. Diagnostic ID: ${runId}`;
            if (details) details.open = true;
            journal.push({run_id:runId,stage:'browser_failed',failure_code:failureCode});
            if (events) events.textContent = JSON.stringify(journal.slice(-48), null, 2);
            const report = new FormData(form);
            report.set('site_maintenance_action','cache_diagnostic');
            report.set('cache_run_id',runId);
            report.set('diagnostic_code',failureCode);
            fetch(form.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:report}).catch(() => {});
        } finally {
            pending = false;
            buttons.forEach(button => { button.disabled = false; });
            region.removeAttribute('aria-busy');
        }
    }, true);
}
