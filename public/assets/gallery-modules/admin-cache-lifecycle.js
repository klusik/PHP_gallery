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
import { completeAdminMutation } from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

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
 * Refresh visible passive evidence independently of worker execution.
 * Type: number. Units: milliseconds. Scope: the mounted Admin cache surface.
 * Consumers: status-only GET poller. Rationale: fifteen seconds exposes automatic progress without scanning, authorizing cleanup or polling hidden panels.
 */
const CACHE_LIFECYCLE_STATUS_MS = 15000;

/**
 * Redraw elapsed waiting time without fetching or admitting server work.
 * Type: number.
 * @type {number}
 * Units: milliseconds.
 * Scope: visible cache waiting bars.
 * Consumers: setupAdminCacheLifecycle countdown presentation.
 * Rationale: 250 ms keeps the local waiting bar smooth while the separate fifteen-second GET owns evidence freshness.
 */
const CACHE_LIFECYCLE_COUNTDOWN_MS = 250;

/**
 * Update scheduled waiting bars from server time without changing worker state.
 * @param {WeakMap<Element,number>} arrivals Local arrival clocks for mounted server snapshots.
 * @returns {void} Advances time-to-due presentation only; unknown dates remain indeterminate and cursors are never converted to percentages.
 */
function refreshAdminCacheWaits(arrivals) {
    if (document.hidden) return;
    document.querySelectorAll('[data-cache-wait]').forEach(bar => {
        if (bar.hidden || !bar.getClientRects().length) return;
        if (!arrivals.has(bar)) arrivals.set(bar, performance.now());
        const observed = Number(bar.dataset.observed), due = Number(bar.dataset.due), start = Number(bar.dataset.start);
        if (!(observed > 0 && due > 0)) return;
        const now = observed + (performance.now() - arrivals.get(bar)) / 1000;
        const remaining = Math.max(0, Math.ceil(due - now));
        if (due <= now) bar.value = 100;
        else if (start > 0 && due > start) bar.value = Math.round(Math.max(0, Math.min(100, 100 * (now-start)/(due-start))) * 100) / 100;
        else bar.removeAttribute('value');
        const label = bar.parentElement.querySelector('[data-cache-wait-label]');
        if (label) label.textContent = remaining === 0 ? bar.dataset.dueLabel : Math.floor(remaining/60) + ':' + String(remaining%60).padStart(2,'0');
    });
}

/**
 * Patch passive evidence from the shared PHP presentation while retaining active controls and disclosures.
 * @param {Element} region Mounted cache lifecycle card.
 * @param {string} html Escaped card HTML returned by the authenticated read-only dashboard surface.
 * @param {Array<{path?:string,phase?:string,round?:number,last_serviced_at?:number,last_completed_at?:number,next_due_at?:number,retry_at?:number,failures?:number,cursor_path?:string,cursor_offset?:number}>} regions Stored regional sample; cursor positions are never percentages.
 * @returns {void} Updates totals, timing and category evidence without navigation, form replacement or losing open regional details.
 */
function renderAdminCacheSnapshot(region, html, regions) {
    const template = document.createElement('template');
    template.innerHTML = html;
    const source = template.content.querySelector('[data-admin-cache-lifecycle]');
    if (!source) throw new Error('Invalid cache status markup');
    const openHelp = region.querySelector('[data-cache-help]')?.open === true;
    const openUnverified = region.querySelector('[data-cache-unverified]')?.open === true;
    ['[data-cache-overview]','[data-cache-lifecycle-status]','[data-cache-registry-evidence]',
        '[data-cache-scheduler-status]','[data-cache-scheduler-summary]','[data-cache-timing]','[data-cache-lifecycle-sample]'].forEach(selector => {
        const target = region.querySelector(selector), next = source.querySelector(selector);
        if (target && next) target.replaceWith(next.cloneNode(true));
    });
    const help = region.querySelector('[data-cache-help]');
    if (help) help.open = openHelp;
    const unverified = region.querySelector('[data-cache-unverified]');
    if (unverified) unverified.open = openUnverified;
    const owners = new Map(Array.from(source.querySelectorAll('[data-cache-owner]'), row => [row.dataset.cacheOwner,row]));
    region.querySelectorAll('[data-cache-owner]').forEach(row => {
        const next = owners.get(row.dataset.cacheOwner);
        if (next) row.replaceWith(next.cloneNode(true));
    });
    renderAdminCacheRegions(region, regions);
}

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
    const waitingArrivals = new WeakMap();
    window.setInterval(() => refreshAdminCacheWaits(waitingArrivals), CACHE_LIFECYCLE_COUNTDOWN_MS);
    let pending = false;
    let statusGeneration = 0;
    let statusAbort = null;
    let statusTimer = 0;
    const refreshStatus = async region => {
        const generation = ++statusGeneration;
        statusAbort?.abort();
        const abort = new AbortController();
        statusAbort = abort;
        const timeout = window.setTimeout(() => abort.abort(), CACHE_LIFECYCLE_TIMEOUT_MS);
        const feedback = region.querySelector('[data-cache-status-feedback]');
        try {
            const response = await fetch(region.dataset.cacheStatusUrl, {credentials:'same-origin',
                headers:{Accept:'application/json'},signal:abort.signal});
            const envelope = await response.json();
            if (generation !== statusGeneration || abort.signal.aborted || !region.isConnected) return true;
            if (!response.ok || envelope.ok !== true || typeof envelope.html !== 'string') throw new Error('Cache status unavailable');
            renderAdminCacheSnapshot(region, envelope.html, envelope.cache_lifecycle?.regions_sample || []);
            if (feedback) feedback.textContent = '';
            return true;
        } catch {
            if (generation !== statusGeneration) return true;
            if (feedback) feedback.textContent = feedback.dataset.failedLabel;
            return false;
        } finally {
            window.clearTimeout(timeout);
            if (generation === statusGeneration) statusAbort = null;
        }
    };
    const refreshVisible = async () => {
        window.clearTimeout(statusTimer);
        let healthy = true;
        if (!document.hidden && !pending) for (const region of document.querySelectorAll('[data-admin-cache-lifecycle][data-cache-status-url]')) {
            if (region.getClientRects().length > 0 && !await refreshStatus(region)) healthy = false;
        }
        if (healthy) statusTimer = window.setTimeout(refreshVisible, CACHE_LIFECYCLE_STATUS_MS);
    };
    statusTimer = window.setTimeout(refreshVisible, CACHE_LIFECYCLE_STATUS_MS);
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element) || !event.target.closest('[data-cache-status-refresh]') || pending) return;
        refreshVisible();
    });
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
        let failed = false;
        let requestTimedOut = false;
        pending = true;
        window.clearTimeout(statusTimer);
        statusGeneration++;
        statusAbort?.abort();
        buttons.forEach(button => { button.disabled = true; });
        region.setAttribute('aria-busy', 'true');
        if (bar) { bar.hidden = false; bar.removeAttribute('value'); }
        if (details) details.hidden = false;
        if (status) { status.hidden = false; status.textContent = status.dataset.workingLabel || 'Working…'; }
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
                const operation = state.operation || state;
                form.elements.namedItem('preview_id').value = state.id;
                if (status) status.textContent = typeof envelope.cache_operation_label === 'string' ? envelope.cache_operation_label
                    : `${envelope.message} Files: ${operation.files}; candidates: ${operation.candidates}; skipped: ${operation.skipped}.`
                    + (operation.partial ? ` Partial scan: ${operation.blocked} blocked, ${operation.errors} errors.` : '') + ` · Diagnostic ID: ${runId}`;
                region.querySelectorAll('[data-cache-status-field]').forEach(field => {
                    const value = state[field.dataset.cacheStatusField];
                    if (field.dataset.cacheStatusFormat === 'time') {
                        field.replaceChildren();
                        const date = Number(value) > 0 ? new Date(Number(value) * 1000) : null;
                        if (!date || !Number.isFinite(date.getTime())) field.textContent = field.dataset.missing;
                        else {
                            const time = document.createElement('time');
                            time.dateTime = date.toISOString().replace('.000Z','Z');
                            time.textContent = time.dateTime.slice(0,19).replace('T',' ') + ' UTC';
                            field.append(time);
                        }
                    } else if (field.dataset.cacheStatusFormat === 'enum') {
                        const labels = JSON.parse(field.dataset.cacheStatusLabels);
                        field.querySelector('span').textContent = Object.hasOwn(labels, value) ? labels[value] : field.dataset.missing;
                        field.querySelector('code').textContent = String(value || '');
                    } else field.textContent = value === undefined ? '—' : String(value);
                });
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
                    const labels = envelope.cache_owner_labels?.[row.dataset.cacheOwner];
                    row.querySelector('[data-cache-owner-bytes]').textContent = typeof labels?.bytes === 'string' ? labels.bytes : (total ? formatBytes(total.bytes) : '—');
                    row.querySelector('[data-cache-owner-eligible]').textContent = typeof labels?.eligible === 'string' ? labels.eligible : (total ? formatBytes(total.reclaimable_bytes) : '—');
                    const observed = Math.max(0, Number(total?.bytes || 0));
                    const share = observed > 0 ? Math.round(Math.max(0, Math.min(100, 100 * Number(total?.reclaimable_bytes || 0) / observed)) * 100) / 100 : 0;
                    row.querySelector('[data-cache-owner-meter]')?.style.setProperty('--cache-eligible-share', share + '%');
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
                if (bar && operation.phase === 'complete') bar.value = 100;
                else if (bar && operation.phase === 'cleanup' && operation.candidate_bytes > 0) bar.value = Math.min(99, operation.bytes_removed / operation.candidate_bytes * 100);
                else if (bar && operation.phase === 'preview') bar.value = 100;
                failureCode = 'completion_error';
                await completeAdminMutation(envelope);
                action = state.scheduled ? '' : state.phase === 'inventory' ? 'cache_inventory_step' : state.phase === 'cleanup' ? 'cache_cleanup_step' : '';
                // Continue only the action the user started. Preview completion never approves deletion.
                if (action) await new Promise(resolve => window.setTimeout(resolve, CACHE_LIFECYCLE_YIELD_MS));
            } while (action && region.isConnected && !document.hidden && region.getClientRects().length > 0);
        } catch {
            failed = true;
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
            if (region.isConnected && !failed) refreshVisible();
        }
    }, true);
}
