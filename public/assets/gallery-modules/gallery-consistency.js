/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-consistency.js
 * Module Type: Browser Module
 * Purpose: Drive explicit consistency reports and selected repair receipts.
 * Responsibilities: Keep actual progress, selection and uncertain writes visible.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {
    browserThumbnailRebuildAvailable,
    runBrowserThumbnailRebuild,
} from './admin-browser-thumbnail-rebuild.js?v=20261101-consistency-scope-v2';

/** Bound a single source decode without automatically replaying its request.
 * Type: number. Units: milliseconds. Scope: one consistency HTTP request.
 * Consumers: consistencyRequest. Rationale: 60000 permits a guarded large raster
 * decode; a timeout requires passive status reconciliation before further work.
 */
const CONSISTENCY_REQUEST_TIMEOUT_MS = 60000;

/** Keep the anchored help clear of viewport edges.
 * Type: number. Units: CSS pixels. Scope: consistency help positioning.
 * Consumers: positionHelp. Rationale: 8 matches the shared panel edge gap.
 */
const CONSISTENCY_HELP_EDGE = 8;

/**
 * @typedef {object} ConsistencyReceipt
 * @property {boolean=} ok Whether the request succeeded.
 * @property {string=} token Session-owned report identifier.
 * @property {number=} sequence Last confirmed operation receipt.
 * @property {string=} mode Report or repair.
 * @property {string=} status Running, complete, stale, blocked or executing.
 * @property {string=} scope Names-only, file/header, or deep content analysis.
 * @property {{image_id:number,variants:number}|null=} browser Prepared browser task.
 * @property {Array<string>=} browser_groups Repairable groups needing encoders.
 * @property {Array<{key:string,path:string,leaf:string}>=} moves Prepared
 *     gallery spelling proposals for selected-ancestor presentation.
 * @property {string=} phase Current catalog, disk or repair phase.
 * @property {number=} processed Confirmed catalog records or repair tasks.
 * @property {number=} total Known catalog records or planned repair tasks.
 * @property {number=} disk_checked Confirmed filesystem observations.
 * @property {number=} repaired Successful repair receipts.
 * @property {number=} failed Failed repair receipts.
 * @property {number=} confirmed_variants Confirmed variant files in this batch.
 * @property {number=} repairable_groups Groups with server-admitted repairs.
 * @property {string=} html Escaped server-rendered affected-gallery fragment.
 * @property {string=} code Stable error category.
 */

/**
 * Send one explicit action without retrying writes after transport uncertainty.
 * @param {HTMLElement} root Owning consistency workspace.
 * @param {URLSearchParams} fields Explicit action and current receipt fields.
 * @return {Promise<ConsistencyReceipt>} Confirmed server receipt or a rejection.
 */
async function consistencyRequest(root, fields) {
    fields.set('csrf_token', root.dataset.csrf || '');
    const response = await fetch(root.dataset.endpoint || '', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }, body: fields,
        signal: AbortSignal.timeout(CONSISTENCY_REQUEST_TIMEOUT_MS),
    });
    const receipt = await response.json();
    if (!response.ok || !receipt.ok) {
        throw new Error(receipt.code || 'consistency_failed');
    }
    return receipt;
}

/**
 * Apply only selected ancestor spellings to a server-prepared path proposal.
 * @param {string} source Original complete gallery path from the report.
 * @param {string} base Target path when repairing this gallery independently.
 * @param {Array<{key:string,path:string,leaf:string}>} moves Server-prepared
 *     gallery segment proposals; these never supply server mutation targets.
 * @param {Set<string>} selected Selected stable report group keys across pages.
 * @return {string} Preview path with identical subfolder depth and separators.
 */
export function consistencyProposedPath(source, base, moves, selected) {
    if (base === '') return '';
    const segments = source.split('/');
    const target = base.split('/');
    const selectedMoves = new Map(moves.filter(move => selected.has(move.key))
        .map(move => [move.path, move.leaf]));
    const prefix = [];
    for (let index = 0; index < target.length; index++) {
        prefix.push(segments[index]);
        const leaf = selectedMoves.get(prefix.join('/'));
        if (leaf !== undefined) target[index] = leaf;
    }
    return target.join('/');
}

/** Permit pointer travel between a question mark and its anchored help.
 * Type: number. Units: milliseconds. Scope: transient consistency help.
 * Consumers: setupConsistencyHelp. Rationale: 150 avoids closing across the
 * small trigger/panel gap; click-pinned help still closes on Escape/outside.
 */
const CONSISTENCY_HELP_LEAVE_MS = 150;

/**
 * Enhance native help disclosures, including dynamically replaced report rows.
 * @param {HTMLElement} root Owning report workspace with native details help.
 * @return {function():void} Close/restore the active panel before replacing rows.
 */
function setupConsistencyHelp(root) {
    let active = null;
    let panel = null;
    let pinned = false;
    let leaveTimer;

    /** Restore native content and clear transient help state.
     * @return {void} Leaves no portalled panel or delayed close behind.
     */
    function closeHelp() {
        clearTimeout(leaveTimer);
        if (active && panel) {
            active.append(panel);
            active.open = false;
            panel.classList.remove('consistency-help-panel');
            panel.removeAttribute('style');
            active.querySelector('summary').setAttribute('aria-expanded', 'false');
        }
        active = null;
        panel = null;
        pinned = false;
    }

    /** Keep open help beside its trigger and inside the visible viewport.
     * @return {void} Repositions without changing layout or starting work.
     */
    function positionHelp() {
        if (!active || !panel) return;
        if (!root.contains(active)) return closeHelp();
        const trigger = active.querySelector('summary').getBoundingClientRect();
        const edge = CONSISTENCY_HELP_EDGE;
        const width = document.documentElement.clientWidth;
        const height = document.documentElement.clientHeight;
        panel.style.maxWidth = `${width - edge - edge}px`;
        panel.style.maxHeight = `${height - edge - edge}px`;
        panel.style.left = `${Math.max(edge, Math.min(
            trigger.left, width - panel.offsetWidth - edge,
        ))}px`;
        const below = trigger.bottom + edge;
        const top = below + panel.offsetHeight <= height - edge
            ? below : trigger.top - panel.offsetHeight - edge;
        panel.style.top = `${Math.max(edge, Math.min(
            top, height - panel.offsetHeight - edge,
        ))}px`;
    }

    /** Open one existing disclosure without inferring report or repair state.
     * @param {HTMLDetailsElement} disclosure Native help details beside its reason.
     * @param {boolean} pin Whether explicit activation keeps the help open.
     * @return {void} Exposes escaped server text on hover, focus or activation.
     */
    function openHelp(disclosure, pin = false) {
        clearTimeout(leaveTimer);
        if (active !== disclosure) {
            closeHelp();
            active = disclosure;
            panel = active.querySelector('.consistency-help-content');
            if (!panel) return closeHelp();
            active.open = true;
            active.querySelector('summary').setAttribute('aria-expanded', 'true');
            // Portal outside backdrop-filter containing blocks, as before.
            document.body.append(panel);
            panel.classList.add('consistency-help-panel');
        }
        pinned = pinned || pin;
        positionHelp();
    }

    /** Locate help independently of source rows or their current page.
     * @param {EventTarget|null} target Pointer/focus/click target in the document.
     * @return {HTMLDetailsElement|null} Owning native disclosure, or no help trigger.
     */
    function disclosureFor(target) {
        return target?.closest?.('.consistency-help > summary')
            ?.closest('.consistency-help') || null;
    }

    root.addEventListener('pointerover', event => {
        const disclosure = disclosureFor(event.target);
        if (disclosure) openHelp(disclosure);
    });
    root.addEventListener('focusin', event => {
        const disclosure = disclosureFor(event.target);
        if (disclosure) openHelp(disclosure);
    });
    root.addEventListener('click', event => {
        const disclosure = disclosureFor(event.target);
        if (!disclosure) return;
        event.preventDefault();
        if (active === disclosure && pinned) closeHelp();
        else openHelp(disclosure, true);
    });
    document.addEventListener('pointerover', event => {
        if (!active) return;
        if (active.contains(event.target) || panel.contains(event.target)) {
            clearTimeout(leaveTimer);
        } else if (!pinned && !active.contains(document.activeElement)) {
            clearTimeout(leaveTimer);
            leaveTimer = setTimeout(closeHelp, CONSISTENCY_HELP_LEAVE_MS);
        }
    });
    document.addEventListener('focusin', event => {
        if (active && !active.contains(event.target)
            && !panel.contains(event.target)) closeHelp();
    });
    document.addEventListener('click', event => {
        if (active && !active.contains(event.target)
            && !panel.contains(event.target)) closeHelp();
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !active) return;
        const trigger = active.querySelector('summary');
        closeHelp();
        trigger.focus();
    });
    window.addEventListener('resize', positionHelp);
    window.addEventListener('scroll', positionHelp, true);
    return closeHelp;
}

/**
 * Reconcile one pending browser task before generating or counting any output.
 * @param {HTMLElement} root Authenticated consistency request owner.
 * @param {ConsistencyReceipt} receipt Current server-owned browser checkpoint.
 * @param {HTMLFormElement} form Existing browser rebuild settings/ticket carrier.
 * @param {HTMLElement} progress Established worker/upload progress target.
 * @param {function(ConsistencyReceipt):void} render Confirmed receipt consumer.
 * @return {Promise<ConsistencyReceipt>} Next confirmed receipt; incomplete or
 *     refused browser work remains pending and propagates a visible error.
 */
export async function consistencyRunBrowserTask(root, receipt, form, progress, render) {
    const fields = new URLSearchParams({
        action: 'browser_verify', token: receipt.token,
        sequence: String(receipt.sequence),
    });
    let state = await consistencyRequest(root, fields);
    render(state);
    if (state.status !== 'browser') return state;
    fields.set('token', state.token);
    fields.set('sequence', String(state.sequence));
    form.querySelector('[name="consistency_token"]').value = state.token;
    form.querySelector('[name="consistency_sequence"]').value = String(state.sequence);
    await runBrowserThumbnailRebuild(form, progress, {scope:'all', singlePass:true});
    state = await consistencyRequest(root, fields);
    render(state);
    if (state.status === 'browser') throw new Error('consistency_browser_incomplete');
    return state;
}

/**
 * Bind the Maintenance Center report, including dynamically replaced rows.
 * @return {void} Starts no analysis or repair without an explicit user action.
 */
export function setupGalleryConsistency() {
    document.querySelectorAll('[data-gallery-consistency]').forEach((root) => {
        if (!(root instanceof HTMLElement) || root.dataset.bound === 'true') {
            return;
        }
        root.dataset.bound = 'true';
        const labels = JSON.parse(root.dataset.labels || '{}');
        let state = JSON.parse(root.dataset.state || '{}');
        let proposalMoves = state.moves || [];
        const selected = new Set();
        let busy = false;
        const status = root.querySelector('[data-consistency-status]');
        const results = root.querySelector('[data-consistency-results]');
        const progress = root.querySelector('[data-consistency-progress]');
        const bar = progress?.querySelector('progress');
        const count = root.querySelector('[data-consistency-count]');
        const resume = root.querySelector('[data-consistency-action="resume"]');
        const scopeSelect = root.querySelector('[data-consistency-scope]');
        const scopeHint = root.querySelector('[data-consistency-scope-hint]');
        const reportScope = root.querySelector('[data-consistency-report-scope]');
        const browserForm = root.querySelector('[data-consistency-browser-form]');
        const browserProgress = root.querySelector('[data-consistency-browser-progress]');
        const browserNotice = root.querySelector('[data-consistency-browser-notice]');
        scopeSelect.addEventListener('change', () => {
            scopeHint.textContent = labels['scope_hint_' + scopeSelect.value];
        });
        scopeHint.textContent = labels['scope_hint_' + scopeSelect.value];
        const repairToolbar = root.querySelector('[data-consistency-repair-toolbar]');
        const exportLink = root.querySelector('[data-consistency-export]');
        const closeHelp = setupConsistencyHelp(root);

        /**
         * Reconcile path previews with selected ancestors without filesystem I/O.
         * @return {void} Updates prepared proposed values only; findings remain.
         */
        function updateProposals() {
            results.querySelectorAll('[data-consistency-path-source]').forEach(node => {
                node.textContent = consistencyProposedPath(
                    node.dataset.consistencyPathSource,
                    node.dataset.consistencyPathBase,
                    proposalMoves,
                    selected,
                );
            });
        }

        /**
         * Render confirmed counts; unknown filesystem totals stay indeterminate.
         * @param {ConsistencyReceipt} receipt Current server progress receipt.
         * @return {void} Replaces only this workspace's findings/status region.
         */
        function renderReceipt(receipt) {
            const finishedBatch = receipt.mode === 'repair'
                && receipt.status === 'complete'
                && (state.status !== 'complete' || state.mode !== 'repair'
                    || state.sequence !== receipt.sequence);
            if (finishedBatch) selected.clear();
            state = receipt;
            if (receipt.status === 'complete') proposalMoves = receipt.moves || [];
            reportScope.hidden = !receipt.token;
            reportScope.textContent = labels.report_scope + ': '
                + labels['scope_' + (receipt.scope || 'content')];
            const running = receipt.status === 'running';
            const uncertain = receipt.status === 'executing';
            const browser = receipt.status === 'browser';
            browserNotice.hidden = !(receipt.browser_groups?.length > 0)
                || (receipt.mode === 'repair' && receipt.status === 'complete');
            if (!browser) browserProgress.hidden = true;
            progress.hidden = !receipt.token;
            if (receipt.phase === 'disk' || uncertain) {
                bar.removeAttribute('value');
                count.textContent = `${labels.disk}: ${receipt.disk_checked || 0}`;
            } else {
                bar.value = receipt.total > 0
                    ? (receipt.processed / receipt.total) * 100 : 0;
                count.textContent = `${receipt.processed || 0} / ${receipt.total || 0}`;
            }
            const phase = labels[receipt.phase] || labels.busy;
            status.textContent = running || browser ? phase : uncertain ? labels.uncertain
                : receipt.status === 'complete'
                    ? receipt.mode === 'repair'
                        ? `${labels.repair_done} ${receipt.repaired || 0}; `
                            + (receipt.confirmed_variants > 0
                                ? `${labels.variants_confirmed}: ${receipt.confirmed_variants}; `
                                : '')
                            + `${labels.failed}: ${receipt.failed || 0}`
                        : labels.report_done
                    : labels[receipt.status] || labels.blocked;
            if (typeof receipt.html === 'string' && receipt.html !== '') {
                closeHelp();
                results.innerHTML = receipt.html;
                results.querySelectorAll('[data-consistency-group]').forEach((input) => {
                    input.checked = selected.has(input.dataset.consistencyGroup);
                });
            }
            updateProposals();
            repairToolbar.hidden = receipt.status !== 'complete'
                || !(receipt.repairable_groups > 0);
            exportLink.hidden = !receipt.token;
            resume.hidden = !running && !uncertain && !browser;
        }

        /**
         * Execute one explicit workflow and consume acknowledged batches.
         * @param {string} action Start, repair, all, resume or page.
         * @param {string=} page Optional zero-based report page.
         * @return {Promise<void>} Stops on completion, refusal or uncertainty.
         */
        async function runAction(action, page) {
            if (busy) {
                return;
            }
            if (action === 'repair' && selected.size === 0) {
                status.textContent = labels.select_first;
                return;
            }
            if (['repair', 'all'].includes(action)
                && (state.browser_groups || []).some(key =>
                    action === 'all' || selected.has(key))) {
                const capability = browserThumbnailRebuildAvailable(browserForm);
                if (!capability.ok) {
                    status.textContent = capability.reason
                        || labels.consistency_browser_unavailable;
                    return;
                }
            }
            if (action === 'all') {
                proposalMoves.forEach(move => selected.add(move.key));
                updateProposals();
            }
            busy = true;
            scopeSelect.disabled = true;
            root.setAttribute('aria-busy', 'true');
            root.querySelectorAll('button').forEach((button) => {
                button.dataset.wasDisabled = String(button.disabled);
                button.disabled = true;
            });
            status.textContent = labels.busy;
            const fields = new URLSearchParams({
                scope: scopeSelect.value,
                action: action === 'all' ? 'repair'
                    : action === 'resume' ? 'status' : action,
                token: state.token || '', sequence: String(state.sequence || 0),
            });
            if (action === 'all') {
                fields.set('all', '1');
            }
            if (action === 'repair') {
                selected.forEach((key) => fields.append('groups[]', key));
            }
            if (action === 'page') {
                fields.set('result_page', page || '0');
            }
            try {
                if (action === 'start') {
                    selected.clear();
                    results.replaceChildren();
                }
                renderReceipt(await consistencyRequest(root, fields));
                while (['running', 'browser'].includes(state.status) && action !== 'page') {
                    if (state.status === 'browser') {
                        // A previous upload may have succeeded despite a lost
                        // reply. Verify persisted output before doing more work.
                        await consistencyRunBrowserTask(
                            root, state, browserForm, browserProgress, renderReceipt,
                        );
                    } else {
                        renderReceipt(await consistencyRequest(root, new URLSearchParams({
                            action: 'step', token: state.token,
                            sequence: String(state.sequence),
                        })));
                    }
                }
            } catch (error) {
                status.textContent = labels[error.message] || error.message || labels.uncertain;
                resume.hidden = false;
                repairToolbar.hidden = true;
            } finally {
                busy = false;
                scopeSelect.disabled = false;
                root.removeAttribute('aria-busy');
                root.querySelectorAll('button').forEach((button) => {
                    if ('wasDisabled' in button.dataset) {
                        button.disabled = button.dataset.wasDisabled === 'true';
                        delete button.dataset.wasDisabled;
                    }
                });
            }
        }

        root.addEventListener('change', (event) => {
            const input = event.target;
            if (!(input instanceof HTMLInputElement) || !input.dataset.consistencyGroup) {
                return;
            }
            if (input.checked) {
                selected.add(input.dataset.consistencyGroup);
            } else {
                selected.delete(input.dataset.consistencyGroup);
            }
            updateProposals();
        });
        root.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const action = target?.closest('[data-consistency-action]');
            const page = target?.closest('[data-consistency-page]');
            if (action || page) {
                event.preventDefault();
                runAction(action?.dataset.consistencyAction || 'page',
                    page?.dataset.consistencyPage);
            }
        });
        if (state.token) {
            renderReceipt(state);
        }
    });
}
