/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-cron.js
 * Module Type: Browser Module
 * Purpose: Observe scheduler state in place and submit explicit Admin actions.
 * Responsibilities: Preserve sort/focus, serialize polls and back off on errors.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Observe only the open visible Overview once a second.
 * Type: number. Units: milliseconds. Scope: one mounted Admin view.
 * Consumers: sequential passive poll scheduling.
 * Rationale: 1000 ms meets the operational preview requirement without executing tasks.
 */
const POLL_MS = 1000;
/**
 * Bound retries on unavailable shared hosting.
 * Type: number. Units: milliseconds. Scope: failed passive polls.
 * Consumers: exponential poll retry scheduling.
 * Rationale: 30000 ms caps retry pressure while retaining the last valid snapshot.
 */
const MAX_BACKOFF_MS = 30000;
/**
 * Bound stalled read and mutation requests.
 * Type: number. Units: milliseconds. Scope: one HTTP request.
 * Consumers: AbortController timeout.
 * Rationale: 15000 ms allows bounded server admission while preventing hung UI.
 */
const REQUEST_TIMEOUT_MS = 15000;
/**
 * Increase error delay without overlapping reads.
 * Type: number. Units: multiplier. Scope: failed passive polls.
 * Consumers: retry backoff.
 * Rationale: doubling reduces traffic quickly during a hosting outage.
 */
const BACKOFF_FACTOR = 2;

/**
 * @typedef {Object} CronRow
 * @property {string} id Stable task identifier.
 * @property {string} name Localized task label.
 * @property {string} cadence Effective cadence text.
 * @property {number} cadence_seconds Normalized interval; zero denotes one-shot work.
 * @property {number|null} next_at Unix due threshold; null means unscheduled.
 * @property {number|null} last_at Actual last attempt timestamp.
 * @property {string} state Stable state identifier.
 * @property {string} state_label Localized state label.
 * @property {boolean} can_run Effective owner availability.
 * @property {string} detail Safe contextual status detail.
 * @property {string} next_label Prepared absolute due text.
 * @property {string} last_label Prepared actual attempt text.
 * @property {string} settings_url Existing settings or task-control destination.
 */

/**
 * Compare normalized values with stable name/ID ties and unknown times last.
 * @param {CronRow} left First observed row.
 * @param {CronRow} right Second observed row.
 * @param {string} key Selected supported column.
 * @param {number} direction One for ascending or minus one for descending.
 * @returns {number} Deterministic table ordering.
 */
export function compareCronRows(left, right, key, direction) {
    const a = left[key];
    const b = right[key];
    if (a === null || b === null) {
        if (a !== b) return a === null ? 1 : -1;
    }
    const difference = typeof a === 'number' && typeof b === 'number'
        ? a - b : String(a ?? '').localeCompare(String(b ?? ''));
    return difference * direction
        || left.name.localeCompare(right.name) || left.id.localeCompare(right.id);
}

/**
 * Mount passive sequential observation and explicit native-form enhancements.
 * @param {Document|HTMLElement} scope Document or newly mounted Admin fragment.
 * @returns {void} Initializes each Overview once; unrelated pages remain passive.
 */
export function setupAdminCron(scope = document) {
    scope.querySelectorAll('[data-cron-overview]').forEach(root => {
        if (root.dataset.cronMounted) return;
        root.dataset.cronMounted = '1';
        const tbody = root.querySelector('tbody');
        const connection = root.querySelector('[data-cron-connection]');
        const feedback = root.querySelector('[data-cron-feedback]');
        const records = new Map();
        const nodes = new Map(Array.from(tbody.rows, row => [row.dataset.cronTask, row]));
        const busy = new Set();
        let sortKey = 'next_at';
        let direction = 1;
        let timer = null;
        let readController = null;
        let readTimeout = null;
        let inFlight = null;
        let generation = 0;
        let delay = POLL_MS;
        let stopped = false;
        let paused = false;
        let mutating = false;

        /**
         * Cancel the passive request and its deadline as one owned resource.
         * @returns {void} Leaves no pending read timer while paused, hidden or unmounted.
         */
        function cancelRead() {
            clearTimeout(readTimeout);
            readTimeout = null;
            readController?.abort();
        }

        /**
         * Reorder existing nodes only when their positions change.
         * @returns {void} Keeps focus and both viewport and table scroll stable.
         */
        function sortRows() {
            const active = document.activeElement;
            const scroll = root.querySelector('.cron-table-scroll');
            const left = scroll.scrollLeft;
            const top = scroll.scrollTop;
            const viewportX = window.scrollX;
            const viewportY = window.scrollY;
            const ordered = [...records.values()].sort((a, b) =>
                compareCronRows(a, b, sortKey, direction));
            ordered.forEach((record, index) => {
                const node = nodes.get(record.id);
                if (node && tbody.children[index] !== node) {
                    tbody.insertBefore(node, tbody.children[index] ?? null);
                }
            });
            if (active instanceof HTMLElement && root.contains(active)
                && document.activeElement !== active) active.focus({ preventScroll: true });
            scroll.scrollLeft = left;
            scroll.scrollTop = top;
            if (window.scrollX !== viewportX || window.scrollY !== viewportY) {
                window.scrollTo(viewportX, viewportY);
            }
        }

        /**
         * Update known task cells without replacing controls or whole rows.
         * @param {Array<CronRow>} rows Safe latest snapshot, possibly one affected row.
         * @returns {void} Reconciles visible evidence and preserves selected sort.
         */
        function applyRows(rows) {
            rows.forEach(record => {
                const node = nodes.get(record.id);
                if (!node) return;
                records.set(record.id, record);
                node.dataset.state = record.state;
                ['name', 'cadence', 'next_label', 'last_label', 'state_label'].forEach(key => {
                    const cell = node.querySelector(`[data-cell="${key}"]`);
                    if (cell.textContent !== record[key]) cell.textContent = record[key];
                });
                const stateCell = node.querySelector('[data-cell="state_label"]');
                stateCell.title = record.detail;
                stateCell.setAttribute('aria-label', record.state_label + ': ' + record.detail);
                node.querySelector('.cron-play').disabled = !record.can_run || busy.has(record.id);
            });
            sortRows();
        }

        /**
         * Schedule a single read only while this view is visible and mounted.
         * @returns {void} Uses completion-based timeouts, never overlapping intervals.
         */
        function schedule() {
            clearTimeout(timer);
            if (!stopped && !paused && root.isConnected && !document.hidden
                && !mutating && !inFlight) {
                timer = setTimeout(poll, delay);
            }
        }

        /**
         * Read one authenticated snapshot without invoking scheduler execution.
         * @returns {Promise<void>} Applies only the current generation and backs off on failure.
         */
        async function poll() {
            if (stopped || paused || !root.isConnected || document.hidden || mutating || inFlight) return;
            // Immediate resume/reconciliation replaces an older scheduled read.
            // Its completion alone may schedule the next sequential request.
            clearTimeout(timer);
            timer = null;
            const current = generation;
            const controller = new AbortController();
            readController = controller;
            const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
            readTimeout = timeout;
            const work = (async () => {
                const response = await fetch(root.dataset.statusUrl, {
                    credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' }, signal: controller.signal,
                });
                if (!response.ok) throw new Error('snapshot unavailable');
                const payload = await response.json();
                if (current !== generation || stopped || paused || document.hidden || !root.isConnected) return;
                if (!payload.ok || !Array.isArray(payload.rows)) throw new Error('invalid snapshot');
                applyRows(payload.rows);
                connection.textContent = '';
                delay = POLL_MS;
            })();
            inFlight = work;
            try {
                await work;
            } catch (error) {
                if (current === generation && !stopped && !paused && !document.hidden) {
                    connection.textContent = root.dataset.connectionError;
                    delay = Math.min(MAX_BACKOFF_MS, delay * BACKOFF_FACTOR);
                }
            } finally {
                clearTimeout(timeout);
                if (readTimeout === timeout) readTimeout = null;
                if (inFlight === work) inFlight = null;
                if (readController === controller) readController = null;
                schedule();
            }
        }

        /**
         * Submit one explicit run/token action without navigating the Admin page.
         * @param {SubmitEvent} event Native form submission including its submitter.
         * @returns {Promise<void>} Shows honest feedback and immediately reconciles affected evidence.
         */
        async function submit(event) {
            const form = event.target.closest('form[data-cron-run], form[data-cron-token]');
            if (!form || !root.contains(form)) return;
            event.preventDefault();
            event.stopPropagation();
            if (mutating) return;
            mutating = true;
            generation++;
            clearTimeout(timer);
            cancelRead();
            const active = document.activeElement;
            const task = form.querySelector('[name="task"]')?.value ?? '';
            busy.add(task);
            form.setAttribute('aria-busy', 'true');
            const buttons = [...form.querySelectorAll('button')];
            buttons.forEach(button => { button.disabled = true; });
            const body = new FormData(form);
            if (event.submitter?.name) body.set(event.submitter.name, event.submitter.value);
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
            try {
                const response = await fetch(form.getAttribute('action'), {
                    method: 'POST', body, credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                const payload = await response.json();
                if (!root.isConnected || stopped) return;
                if (Array.isArray(payload.rows)) applyRows(payload.rows);
                if (typeof payload.cron_url === 'string') {
                    root.querySelector('[data-cron-url]').value = payload.cron_url;
                    root.querySelector('[data-cron-example]').textContent = payload.cron_url
                        ? `curl -fsS '${payload.cron_url}' >/dev/null` : '';
                }
                feedback.textContent = payload.message ?? '';
                if (!response.ok || !payload.ok) {
                    connection.textContent = root.dataset.connectionError;
                }
            } catch {
                connection.textContent = root.dataset.connectionError;
            } finally {
                clearTimeout(timeout);
                mutating = false;
                busy.delete(task);
                form.removeAttribute('aria-busy');
                buttons.forEach(button => { button.disabled = false; });
                if (records.has(task)) applyRows([records.get(task)]);
                if (active instanceof HTMLElement && form.contains(active)
                    && root.isConnected && !document.hidden && !active.disabled) {
                    active.focus({ preventScroll: true });
                }
                // A failed/uncertain POST also refreshes immediately. This read
                // only observes persisted admission; it cannot trigger a job.
                if (inFlight) await inFlight.catch(() => {});
                await poll();
                schedule();
            }
        }

        /**
         * Pause hidden reads and invalidate bodies that complete after a change.
         * @returns {void} Resumes with an immediate passive read on visibility.
         */
        function visibility() {
            generation++;
            clearTimeout(timer);
            cancelRead();
            if (!document.hidden && !paused) {
                delay = POLL_MS;
                if (inFlight) inFlight.finally(() => poll()).catch(() => {});
                else poll();
            }
        }

        /**
         * Release this mounted view's timers, observer and status request.
         * @returns {void} Prevents detached views from polling in the background.
         */
        function stop() {
            stopped = true;
            generation++;
            clearTimeout(timer);
            cancelRead();
            document.removeEventListener('visibilitychange', visibility);
            document.removeEventListener('click', closeHelp);
            document.removeEventListener('keydown', closeHelp);
            window.removeEventListener('pagehide', pagehide);
            window.removeEventListener('pageshow', visibility);
            observer.disconnect();
        }

        /**
         * Preserve observation after a browser back/forward-cache restoration.
         * @param {PageTransitionEvent} event Native navigation lifecycle event.
         * @returns {void} Stops departing views; cached views only pause their reads.
         */
        function pagehide(event) {
            if (!event.persisted) {
                stop();
                return;
            }
            generation++;
            clearTimeout(timer);
            cancelRead();
        }

        // Preserve native keyboard activation and close contextual help on the
        // same outside-click/Escape gestures used by other compact Admin help.
        const closeHelp = event => {
            root.querySelectorAll('.cron-help[open]').forEach(detail => {
                if (event.type === 'keydown' && event.key === 'Escape'
                    || event.type === 'click' && !detail.contains(event.target)) {
                    detail.open = false;
                }
            });
        };
        document.addEventListener('click', closeHelp);
        document.addEventListener('keydown', closeHelp);

        root.querySelectorAll('[data-cron-sort]').forEach(button => {
            button.addEventListener('click', () => {
                const key = button.dataset.cronSort;
                direction = sortKey === key ? -direction : 1;
                sortKey = key;
                root.querySelectorAll('[data-cron-sort]').forEach(header => {
                    const selected = header === button;
                    header.closest('th').setAttribute('aria-sort', selected
                        ? (direction === 1 ? 'ascending' : 'descending') : 'none');
                    header.querySelector('[data-sort-glyph]').textContent = selected
                        ? (direction === 1 ? '↑' : '↓') : '↕';
                });
                sortRows();
            });
        });
        root.querySelector('[data-cron-copy]').addEventListener('click', async () => {
            const input = root.querySelector('[data-cron-url]');
            input.select();
            try { await navigator.clipboard.writeText(input.value); } catch { /* Native selection remains copyable. */ }
        });
        const previewToggle = root.querySelector('[data-cron-preview-toggle]');
        previewToggle.hidden = false;
        previewToggle.addEventListener('click', () => {
            paused = !paused;
            const label = paused ? previewToggle.dataset.resumeLabel
                : previewToggle.dataset.pauseLabel;
            previewToggle.setAttribute('aria-pressed', String(paused));
            previewToggle.setAttribute('aria-label', label);
            previewToggle.title = label;
            previewToggle.querySelector('[data-cron-pause-icon]').hidden = paused;
            previewToggle.querySelector('[data-cron-resume-icon]').hidden = !paused;
            // Share hidden-view cancellation and stale-body invalidation. The
            // operator's pause survives visibility changes until explicitly resumed.
            visibility();
        });
        root.addEventListener('submit', submit);
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('pagehide', pagehide);
        window.addEventListener('pageshow', visibility);
        const observer = new MutationObserver(() => { if (!root.isConnected) stop(); });
        observer.observe(document.body, { childList: true, subtree: true });
        applyRows(JSON.parse(root.querySelector('[data-cron-initial]').textContent));
        schedule();
    });
}
