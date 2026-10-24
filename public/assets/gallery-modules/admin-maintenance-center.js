/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-maintenance-center.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Drives the persisted Maintenance Center through one bounded HTTP step at a time.
 *
 * Responsibilities:
 *   - Resume analysis/execution from server-authoritative persisted job state
 *   - Render plan review, weighted progress, diagnostics, and before/after evidence
 *   - Serialize this document's requests while tolerating reloads, disconnects, and safe retries
 *   - Never authorize task/table names client-side; browser selections remain presentation input only
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
 *   - The server registry, persisted state machine, and short-lived per-job lock remain authoritative.
 */

const STEP_DELAY_MS = 120;

/** Parse one inert JSON bootstrap block and return a safe fallback on malformed data. */
function parseJsonScript(root, selector, fallback) {
    const node = root.querySelector(selector);
    if (!node) return fallback;
    try {
        return JSON.parse(node.textContent || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

/** Normalize one browser-facing numeric value without allowing NaN into progress calculations. */
function finiteNumber(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

/** Format one non-negative integer for the active browser locale. */
function formatNumber(value) {
    return Math.max(0, Math.round(finiteNumber(value))).toLocaleString();
}

/** Format a byte count using compact binary units without claiming unknown measurements. */
function formatBytes(value, unknown = 'Unknown') {
    if (value === null || value === undefined || value === '') return unknown;
    let bytes = finiteNumber(value, NaN);
    if (!Number.isFinite(bytes) || bytes < 0) return unknown;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (bytes >= 1024 && unit < units.length - 1) {
        bytes /= 1024;
        unit += 1;
    }
    const precision = unit === 0 ? 0 : (bytes >= 100 ? 0 : bytes >= 10 ? 1 : 2);
    return `${bytes.toFixed(precision)} ${units[unit]}`;
}

/** Format a persisted duration in compact hour/minute/second notation. */
function formatDuration(seconds) {
    const total = Math.max(0, Math.round(finiteNumber(seconds)));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;
    if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
    if (minutes > 0) return `${minutes}m ${secs}s`;
    return `${secs}s`;
}

/** Convert one stable internal key into a readable fallback label. */
function humanize(value) {
    return String(value || '')
        .replace(/[._-]+/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/** Build a compact media-style action with a localized accessible label.
 * @param {string} label Localized action name for the tooltip and accessible name.
 * @param {string} className Existing action classes for secondary/disabled states.
 * @param {string} action Stable delegated maintenance command.
 * @param {boolean} disabled Whether the current server state forbids this action.
 * @returns {HTMLButtonElement} Native action with a trusted SVG or text fallback.
 */
function createButton(label, className, action, disabled = false) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `${className} maintenance-center-action`;
    button.title = label;
    button.setAttribute('aria-label', label);
    const icons = {
        analyze: '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1"/>',
        retry: '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1"/>',
        run: '<path d="m8 5 11 7-11 7Z"/>',
        resume: '<path d="m8 5 11 7-11 7Z"/>',
        pause: '<path d="M8 5v14M16 5v14"/>',
        cancel: '<path d="m6 6 12 12M18 6 6 18"/>',
    };
    if (icons[action]) {
        button.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24"'
            + ' fill="none" stroke="currentColor" stroke-width="1.7"'
            + ' stroke-linecap="round" stroke-linejoin="round"'
            + ' aria-hidden="true" focusable="false">'
            + icons[action] + '</svg>';
    } else {
        button.textContent = label;
    }
    button.dataset.maintenanceAction = action;
    button.disabled = disabled;
    return button;
}

/** Resolve one task key through the server-presented translated immutable plan. */
function taskLabel(job, key) {
    const task = (job?.plan?.tasks || []).find((candidate) => candidate?.key === key);
    return String(task?.display_label || task?.label || humanize(key));
}

/** Convert bounded analyzer evidence into compact review facts without authorizing execution.
 * @param {Record<string, any>} task Prepared task and read-only analysis data to summarize.
 * @param {Record<string, string>} strings Localized metric labels and fallback text.
 * @returns {Array<[string, string]>} Ordered label/value facts for the task row.
 */
function analysisFacts(task, strings) {
    const analysis = task?.analysis || {};
    const facts = [];
    /** Append one non-empty presentation fact to the current task summary. */
    const add = (label, value) => {
        if (value === null || value === undefined || value === '') return;
        facts.push([label, String(value)]);
    };
    const rowCount = analysis.affected_rows ?? analysis.eligible_rows;
    if (rowCount !== undefined) add(strings.estimated_rows, formatNumber(rowCount));
    if (analysis.estimated_bytes !== undefined) add(strings.estimated_bytes, formatBytes(analysis.estimated_bytes, strings.unknown));
    if (analysis.rollup_days !== undefined) add(strings.rollup_days || 'Rollup days', formatNumber(analysis.rollup_days));
    if (analysis.eligible_days !== undefined) add(strings.eligible_days || 'Eligible days', formatNumber(analysis.eligible_days));
    if (analysis.total !== undefined) add(strings.candidates || 'Candidates', formatNumber(analysis.total));
    if (analysis.rule_count !== undefined) add(strings.cleanup_rules || 'Cleanup rules', formatNumber(analysis.rule_count));
    if (analysis.table_count !== undefined) add(strings.tables || 'Tables', formatNumber(analysis.table_count));
    if (analysis.total_bytes !== undefined) add(strings.database_size || 'Database size', formatBytes(analysis.total_bytes, strings.unknown));
    if (analysis.reclaimable_bytes_estimate !== undefined) add(strings.data_free || 'Estimated DATA_FREE', formatBytes(analysis.reclaimable_bytes_estimate, strings.unknown));
    if (analysis.recommended_reclaimable_bytes !== undefined) add(strings.estimated_bytes, formatBytes(analysis.recommended_reclaimable_bytes, strings.unknown));
    if (Array.isArray(analysis.recommended_tables)) add(strings.recommended_tables || 'Recommended tables', formatNumber(analysis.recommended_tables.length));
    if (Array.isArray(analysis.eligible_tables)) add(strings.eligible_tables || 'Eligible tables', formatNumber(analysis.eligible_tables.length));
    if (Array.isArray(analysis.large_tables) && analysis.large_tables.length > 0) add(strings.large_tables_skipped || 'Large tables skipped', formatNumber(analysis.large_tables.length));
    if (analysis.pending_migrations === true) add(strings.pending_migrations || 'Pending migrations', '1');
    if (analysis.sample?.images_scanned !== undefined) add(strings.images_sampled || 'Images sampled', formatNumber(analysis.sample.images_scanned));
    if (analysis.sample?.images_with_missing !== undefined) add(strings.images_with_missing || 'Images with missing variants', formatNumber(analysis.sample.images_with_missing));
    if (analysis.sample?.missing_variants !== undefined) add(strings.missing_variants || 'Missing variants', formatNumber(analysis.sample.missing_variants));
    if (facts.length === 0 && task?.analysis?.has_work !== false) add(strings.work, `${formatNumber(task?.work_units || 0)} ${strings.units || 'units'}`);
    return facts;
}

/** Bind one page-owned Maintenance Center surface exactly once.
 * @param {HTMLElement} root Page-owned Maintenance Center root element.
 * @returns {void} Sets up event handling and the initial presentation.
 */
function setupOneMaintenanceCenter(root) {
    if (root.dataset.maintenanceCenterReady === '1') return;
    root.dataset.maintenanceCenterReady = '1';

    const strings = parseJsonScript(root, '[data-maintenance-center-i18n]', {});
    let job = parseJsonScript(root, '[data-maintenance-center-initial]', null);
    let requestActive = false;
    let autoContinue = true;
    let pendingControl = '';
    const blockedByOtherJob = root.dataset.blockedByOtherJob === '1';

    const endpoints = {
        status: root.dataset.statusEndpoint || '',
        analyzeStart: root.dataset.analyzeStartEndpoint || '',
        analyzeStep: root.dataset.analyzeStepEndpoint || '',
        executeStart: root.dataset.executeStartEndpoint || '',
        executeStep: root.dataset.executeStepEndpoint || '',
        pause: root.dataset.pauseEndpoint || '',
        cancel: root.dataset.cancelEndpoint || '',
    };
    const csrf = root.dataset.csrfToken || '';

    const statusTitle = root.querySelector('[data-maintenance-status-title]');
    const statusBadge = root.querySelector('[data-maintenance-status-badge]');
    const progressShell = root.querySelector('[data-maintenance-progress-shell]');
    const progressBar = root.querySelector('[data-maintenance-progress-bar]');
    const progressMeta = root.querySelector('.maintenance-center-progress-meta');
    const currentStatus = root.querySelector('.maintenance-center-current');
    const progressPercent = root.querySelector('[data-maintenance-progress-percent]');
    const progressLabel = root.querySelector('[data-maintenance-progress-label]');
    const currentPhase = root.querySelector('[data-maintenance-current-phase]');
    const currentTask = root.querySelector('[data-maintenance-current-task]');
    const currentSubtaskWrap = root.querySelector('[data-maintenance-current-subtask-wrap]');
    const currentSubtask = root.querySelector('[data-maintenance-current-subtask]');
    const actions = root.querySelector('[data-maintenance-actions]');
    const cancelNote = root.querySelector('[data-maintenance-cancel-note]');
    const browserError = root.querySelector('[data-maintenance-browser-error]');
    const review = root.querySelector('[data-maintenance-review]');
    const selectionSummary = root.querySelector('[data-maintenance-selection-summary]');
    const planTotals = root.querySelector('[data-maintenance-plan-totals]');
    const planWarnings = root.querySelector('[data-maintenance-plan-warnings]');
    const automaticDetails = root.querySelector('[data-maintenance-automatic]');
    const automaticTasks = root.querySelector('[data-maintenance-automatic-tasks]');
    const taskTabs = root.querySelector('[data-maintenance-task-tabs]');
    const taskPanels = root.querySelector('[data-maintenance-task-panels]');
    const fullOptimizeWrap = root.querySelector('[data-maintenance-full-optimize-wrap]');
    const fullOptimize = root.querySelector('[data-maintenance-full-optimize]');
    if (fullOptimize) fullOptimize.setAttribute('role', 'switch');
    const activity = root.querySelector('[data-maintenance-activity]');
    const diagnostics = root.querySelector('[data-maintenance-diagnostics]');
    const reportSection = root.querySelector('[data-maintenance-report]');
    const reportMetrics = root.querySelector('[data-maintenance-report-metrics]');
    const reportDetails = root.querySelector('[data-maintenance-report-details]');

    /** Select one task category tab and expose its matching task panel.
     * @param {string} tabId Stable cleanup, media, or database category identifier.
     * @param {boolean} focus Whether keyboard navigation should focus the selected tab.
     * @returns {void} Updates tab semantics and panel visibility.
     */
    function activateTaskTab(tabId, focus = false) {
        if (!taskTabs || !taskPanels) return;
        for (const button of taskTabs.querySelectorAll('[role="tab"]')) {
            const active = button.dataset.maintenanceTab === tabId;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
            if (active && focus) button.focus();
        }
        for (const panel of taskPanels.querySelectorAll('[role="tabpanel"]')) {
            panel.hidden = panel.dataset.maintenancePanel !== tabId;
        }
        taskTabs.dataset.activeTab = tabId;
    }

    /** Send one bounded same-origin request and require the server-authoritative job envelope. */
    async function request(url, fields = {}, method = 'POST') {
        if (!url) throw new Error(strings.request_failed || 'Missing maintenance endpoint.');
        const options = {method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        if (method !== 'GET') {
            const body = new URLSearchParams();
            body.set('csrf_token', csrf);
            for (const [key, value] of Object.entries(fields)) {
                if (Array.isArray(value)) {
                    for (const item of value) body.append(`${key}[]`, String(item));
                } else if (value !== undefined && value !== null) {
                    body.set(key, String(value));
                }
            }
            options.body = body;
            options.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        }
        const response = await fetch(url, options);
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok || !payload?.job) {
            throw new Error(String(payload?.error || `${strings.request_failed || 'Request failed'} (${response.status})`));
        }
        return payload.job;
    }

    /** Show or clear a browser/request interruption without altering persisted job state. */
    function setBrowserError(message = '') {
        if (!browserError) return;
        browserError.textContent = message;
        browserError.hidden = message === '';
    }

    /** Refresh the compact count across every category tab.
     * @returns {void} Updates the plan summary from all task switches.
     */
    function updateSelectionSummary() {
        if (!selectionSummary) return;
        const selectable = Array.from(root.querySelectorAll('[data-maintenance-task]'));
        const selectedCount = selectable.filter((input) => input.checked).length;
        selectionSummary.textContent = `${formatNumber(selectedCount)} / ${formatNumber(selectable.length)}`;
    }

    /** Render weighted progress and the current server-reported task/subtask.
     * @returns {void} Hides idle/ready progress and updates visible job progress otherwise.
     */
    function renderProgress() {
        const status = String(job?.status || 'idle');
        const visible = !['idle', 'ready'].includes(status);
        if (progressShell) progressShell.hidden = !visible;
        if (progressMeta) progressMeta.hidden = !visible;
        if (currentStatus) currentStatus.hidden = !visible;
        const percent = Math.max(0, Math.min(100, finiteNumber(job?.progress_percent)));
        if (progressBar) progressBar.style.width = `${percent}%`;
        if (progressShell) progressShell.setAttribute('aria-valuenow', String(percent));
        if (progressPercent) progressPercent.textContent = `${percent.toFixed(percent % 1 === 0 ? 0 : 1)}%`;
        if (progressLabel) {
            const done = Math.max(0, Math.round(finiteNumber(job?.progress_done)));
            const total = Math.max(0, Math.round(finiteNumber(job?.progress_total)));
            progressLabel.textContent = total > 0 ? `${formatNumber(done)} / ${formatNumber(total)} ${strings.work || 'work units'}` : '';
        }
        if (currentPhase) currentPhase.textContent = humanize(job?.phase || 'idle');
        if (currentTask) currentTask.textContent = job?.current_task ? taskLabel(job, job.current_task) : '—';
        const subtask = String(job?.current_subtask || '');
        if (currentSubtaskWrap) currentSubtaskWrap.hidden = subtask === '';
        if (currentSubtask) currentSubtask.textContent = subtask;
    }

    /** Render state-valid actions; the server still validates every transition.
     * @returns {void} Replaces the compact toolbar with currently allowed actions.
     */
    function renderActions() {
        if (!actions) return;
        actions.replaceChildren();
        if (blockedByOtherJob) {
            actions.append(createButton(strings.other_job_active || 'Another central maintenance job is active', 'button secondary', 'blocked', true));
            if (cancelNote) cancelNote.hidden = true;
            return;
        }
        const status = String(job?.status || 'idle');
        const fresh = job?.plan_freshness?.fresh !== false;
        if (status === 'idle' || status === 'completed' || status === 'failed' || status === 'cancelled' || !job) {
            actions.append(createButton(job ? (strings.analyze_again_short || 'Analyze again') : (strings.analyze_short || 'Analyze'), 'button', 'analyze'));
        } else if (status === 'ready') {
            actions.append(createButton(strings.run_short || 'Run', 'button', 'run', !fresh));
            actions.append(createButton(strings.analyze_again_short || 'Analyze again', 'button secondary', 'analyze'));
            actions.append(createButton(strings.cancel || 'Cancel', 'button secondary', 'cancel'));
        } else if (status === 'running') {
            actions.append(createButton(strings.pause || 'Pause', 'button secondary', 'pause'));
            actions.append(createButton(strings.cancel || 'Cancel', 'button secondary', 'cancel'));
        } else if (status === 'paused') {
            actions.append(createButton(strings.resume_short || 'Resume', 'button', 'resume'));
            actions.append(createButton(strings.cancel || 'Cancel', 'button secondary', 'cancel'));
        } else if (status === 'analyzing') {
            actions.append(createButton(strings.cancel || 'Cancel', 'button secondary', 'cancel'));
        }
        if (!autoContinue && ['analyzing', 'running'].includes(status)) {
            actions.prepend(createButton(strings.retry_short || 'Continue', 'button', 'retry'));
        }
        if (cancelNote) cancelNote.hidden = !['running', 'paused'].includes(status);
    }

    /** Render the immutable analyzed plan and editable pre-execution task selection.
     * @returns {void} Updates plan totals, warnings, task selection, and details in place.
     */
    function renderPlan() {
        const plan = job?.plan || null;
        const status = String(job?.status || '');
        const visible = Boolean(plan && ['ready', 'running', 'paused', 'completed', 'failed', 'cancelled'].includes(status));
        if (review) {
            review.hidden = !visible;
            review.open = !['completed', 'failed', 'cancelled'].includes(status);
        }
        if (!visible || !taskTabs || !taskPanels || !planTotals) return;

        planTotals.replaceChildren();
        const totals = [
            [strings.estimated_rows || 'Estimated affected rows', formatNumber(plan.estimated_affected_rows || 0)],
            [strings.estimated_bytes || 'Estimated reclaimable bytes', formatBytes(plan.estimated_reclaimable_bytes || 0, strings.unknown)],
            [strings.estimated_units || 'Estimated work units', formatNumber(plan.estimated_work_units || 0)],
        ];
        for (const [label, value] of totals) {
            const span = document.createElement('span');
            span.innerHTML = `<strong>${value}</strong><small></small>`;
            span.querySelector('small').textContent = label;
            planTotals.append(span);
        }

        planWarnings.replaceChildren();
        if (job?.plan_freshness?.fresh === false && status === 'ready') {
            const warning = document.createElement('div');
            warning.className = 'notice warning';
            warning.textContent = strings.stale_plan || 'Plan is stale.';
            planWarnings.append(warning);
        }
        for (const warningData of plan.warnings || []) {
            const warning = document.createElement('div');
            warning.className = 'notice warning';
            warning.textContent = String(warningData?.message || warningData || '');
            if (warning.textContent) planWarnings.append(warning);
        }

        const persistedTaskList = Array.isArray(job?.selected_tasks) ? job.selected_tasks : [];
        const selectedPersisted = new Set(persistedTaskList);
        const executionStarted = ['running', 'paused', 'completed', 'failed', 'cancelled'].includes(status) && selectedPersisted.size > 0;
        const previousSelections = new Map(
            Array.from(root.querySelectorAll('[data-maintenance-task]'))
                .map((input) => [input.dataset.maintenanceTask || '', input.checked]),
        );
        const tasksByTab = new Map([
            ['cleanup', []],
            ['media', []],
            ['database', []],
        ]);
        const automatic = [];
        for (const task of plan.tasks || []) {
            if (['preflight', 'verify'].includes(String(task?.key || ''))) {
                automatic.push(task);
                continue;
            }
            const tab = task.group === 'media'
                ? 'media'
                : task.group === 'database'
                    ? 'database'
                    : 'cleanup';
            tasksByTab.get(tab).push(task);
        }
        const activeTabBeforeRender = taskTabs.dataset.activeTab || 'cleanup';
        taskTabs.replaceChildren();
        taskPanels.replaceChildren();
        if (automaticDetails && automaticTasks) {
            const automaticWasOpen = automaticDetails.open;
            automaticDetails.hidden = automatic.length === 0;
            automaticTasks.replaceChildren();
            for (const task of automatic) {
                const row = document.createElement('div');
                row.className = 'maintenance-center-automatic-task';
                const label = document.createElement('strong');
                label.textContent = String(task.display_label || task.label || task.key || '');
                const state = document.createElement('span');
                const selectedIndex = persistedTaskList.indexOf(String(task.key || ''));
                if (!task.available) {
                    state.textContent = strings.unavailable || 'Unavailable';
                    state.classList.add('is-error');
                } else if (selectedIndex >= 0 && selectedIndex < Number(job?.task_index || 0)) {
                    state.textContent = strings.completed || 'Completed';
                    state.classList.add('is-complete');
                } else if (String(job?.current_task || '') === String(task.key || '')) {
                    state.textContent = strings.current || 'Current';
                    state.classList.add('is-current');
                } else {
                    state.textContent = strings.required_automatic || 'Required · automatic';
                }
                row.append(label, state);
                const facts = analysisFacts(task, strings);
                const warning = String(task.analysis?.warning || '');
                const checks = Array.isArray(task.analysis?.checks)
                    ? task.analysis.checks.map(humanize).join(', ')
                    : '';
                if (facts.length > 0 || warning || checks) {
                    const detail = document.createElement('small');
                    detail.textContent = [
                        ...facts.map(([factLabel, value]) => `${factLabel}: ${value}`),
                        checks,
                        warning,
                    ].filter(Boolean).join(' · ');
                    row.append(detail);
                }
                automaticTasks.append(row);
            }
            automaticDetails.open = automaticWasOpen;
        }
        const tabDefinitions = [
            ['cleanup', strings.tab_cleanup || 'Cleanup'],
            ['media', strings.tab_media || 'Media'],
            ['database', strings.tab_database || 'Database'],
        ];
        for (const [tabId, label] of tabDefinitions) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'maintenance-center-task-tab';
            button.dataset.maintenanceTab = tabId;
            button.id = `maintenance-tab-${tabId}`;
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-controls', `maintenance-panel-${tabId}`);
            button.textContent = label;
            taskTabs.append(button);

            const panel = document.createElement('div');
            panel.className = 'maintenance-center-task-panel';
            panel.id = `maintenance-panel-${tabId}`;
            panel.dataset.maintenancePanel = tabId;
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', button.id);
            panel.tabIndex = 0;
            const grid = document.createElement('div');
            grid.className = 'maintenance-center-task-grid';
            for (const task of tasksByTab.get(tabId) || []) {
                const item = document.createElement('div');
                item.className = 'maintenance-center-task-item';
                const card = document.createElement('label');
                card.className = `maintenance-center-task${task.available ? '' : ' is-unavailable'}`;
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.setAttribute('role', 'switch');
                checkbox.dataset.maintenanceTask = String(task.key || '');
                checkbox.disabled = !task.available || Boolean(task.required) || status !== 'ready';
                const taskKey = String(task.key || '');
                checkbox.checked = task.required
                    || (executionStarted
                        ? selectedPersisted.has(taskKey)
                        : previousSelections.has(taskKey)
                            ? Boolean(previousSelections.get(taskKey))
                            : Boolean(task.default_selected));
                const shortLabel = String(
                    strings.task_short_labels?.[taskKey]
                        || task.display_label
                        || task.label
                        || taskKey,
                );
                checkbox.setAttribute('aria-label', shortLabel);
                const title = document.createElement('span');
                title.className = 'maintenance-center-task-title';
                title.textContent = shortLabel;
                card.append(checkbox, title);
                if (task.required) {
                    const required = document.createElement('small');
                    required.className = 'maintenance-center-task-requirement';
                    required.textContent = strings.required_automatic || 'Required · automatic';
                    card.append(required);
                }
                if (!task.available) {
                    const unavailable = document.createElement('small');
                    unavailable.className = 'maintenance-center-task-unavailable';
                    unavailable.textContent = strings.unavailable || 'Unavailable';
                    card.append(unavailable);
                }
                item.append(card);

                const help = document.createElement('details');
                help.className = 'admin-inline-help maintenance-center-task-help';
                const helpSummary = document.createElement('summary');
                helpSummary.setAttribute('aria-label', String(task.display_label || task.label || shortLabel));
                helpSummary.setAttribute('aria-controls', `maintenance-help-${taskKey}`);
                const question = document.createElement('span');
                question.setAttribute('aria-hidden', 'true');
                question.textContent = '?';
                helpSummary.append(question);
                const helpContent = document.createElement('div');
                helpContent.className = 'admin-inline-help-content';
                helpContent.id = `maintenance-help-${taskKey}`;
                const fullLabel = document.createElement('strong');
                fullLabel.textContent = String(task.display_label || task.label || shortLabel);
                helpContent.append(fullLabel);
                if (task.analysis?.has_work === false) {
                    const noWork = document.createElement('p');
                    noWork.textContent = strings.no_work || 'No work detected';
                    helpContent.append(noWork);
                }
                const facts = analysisFacts(task, strings);
                if (facts.length > 0) {
                    const factList = document.createElement('ul');
                    for (const [factLabel, value] of facts) {
                        const fact = document.createElement('li');
                        fact.textContent = `${factLabel}: ${value}`;
                        factList.append(fact);
                    }
                    helpContent.append(factList);
                }
                const warning = String(task.analysis?.warning || '');
                if (warning) {
                    const warningNode = document.createElement('p');
                    warningNode.textContent = warning;
                    helpContent.append(warningNode);
                }
                help.append(helpSummary, helpContent);
                item.append(help);
                grid.append(item);
            }
            panel.append(grid);
            taskPanels.append(panel);
        }
        if (fullOptimizeWrap) {
            const optimizeTask = (plan.tasks || []).find(
                (task) => task?.key === 'database.optimize',
            );
            fullOptimizeWrap.hidden = !(
                optimizeTask?.available
                && optimizeTask.analysis?.full_optimization_available
            );
            if (fullOptimize) {
                fullOptimize.disabled = status !== 'ready';
                if (executionStarted) fullOptimize.checked = false;
            }
            const databasePanel = taskPanels.querySelector(
                '[data-maintenance-panel="database"]',
            );
            if (databasePanel) databasePanel.append(fullOptimizeWrap);
        }
        activateTaskTab(activeTabBeforeRender, false);
        updateSelectionSummary();

    }

    /** Render bounded persisted recent activity, warnings, and errors. */
    function renderActivityAndDiagnostics() {
        if (activity) {
            activity.replaceChildren();
            const rows = Array.isArray(job?.activity) ? job.activity.slice(-40).reverse() : [];
            if (rows.length === 0) {
                const li = document.createElement('li');
                li.className = 'muted';
                li.textContent = strings.no_activity || 'No maintenance activity yet.';
                activity.append(li);
            } else {
                for (const entry of rows) {
                    const li = document.createElement('li');
                    li.className = `is-${String(entry?.level || 'info')}`;
                    const time = document.createElement('time');
                    time.textContent = String(entry?.at || '').split(' ').pop() || '';
                    const message = document.createElement('span');
                    message.textContent = String(entry?.message || '');
                    li.append(time, message);
                    activity.append(li);
                }
            }
        }
        if (diagnostics) {
            diagnostics.replaceChildren();
            const warnings = Array.isArray(job?.warnings) ? job.warnings : [];
            const errors = Array.isArray(job?.errors) ? job.errors : [];
            if (warnings.length === 0 && errors.length === 0) {
                const p = document.createElement('p');
                p.className = 'muted';
                p.textContent = strings.none || 'None';
                diagnostics.append(p);
            }
            for (const entry of warnings) {
                const div = document.createElement('div');
                div.className = 'notice warning';
                div.textContent = String(entry?.message || entry?.code || '');
                diagnostics.append(div);
            }
            for (const entry of errors) {
                const div = document.createElement('div');
                div.className = 'notice error';
                div.textContent = String(entry?.message || entry?.code || '');
                diagnostics.append(div);
            }
            if (job?.error_message && !errors.some((entry) => entry?.message === job.error_message)) {
                const div = document.createElement('div');
                div.className = 'notice error';
                div.textContent = String(job.error_message);
                diagnostics.append(div);
            }
        }
    }

    /** Render only verified before/after measurements provided by the server report.
     * @returns {void} Updates the result metrics and bounded detail disclosures.
     */
    function renderReport() {
        const report = job?.report || null;
        if (reportSection) reportSection.hidden = !report || Object.keys(report).length === 0;
        if (!report || !reportMetrics || !reportDetails) return;
        reportMetrics.replaceChildren();
        const db = report.database || {};
        const rowsRemoved = Object.values(report.rows_removed || {}).reduce((sum, value) => sum + Math.max(0, finiteNumber(value)), 0);
        const metrics = [
            [strings.database_before || 'Database before', formatBytes(db.before_bytes, strings.unknown)],
            [strings.database_after || 'Database after', formatBytes(db.after_bytes, strings.unknown)],
            [strings.database_delta || 'Measured database change', db.reported_reclaimed_bytes === null || db.reported_reclaimed_bytes === undefined ? strings.unknown : formatBytes(db.reported_reclaimed_bytes, strings.unknown)],
            [strings.rows_removed || 'Rows removed', formatNumber(rowsRemoved)],
            [strings.files_removed || 'Files removed', formatNumber(report.files_removed || 0)],
            [strings.filesystem_bytes_removed || 'Filesystem bytes removed', report.filesystem_bytes_removed === null || report.filesystem_bytes_removed === undefined ? strings.unknown : formatBytes(report.filesystem_bytes_removed, strings.unknown)],
            [strings.tables_optimized || 'Tables optimized', formatNumber((report.tables_optimized || []).length)],
            [strings.tables_analyzed || 'Tables analyzed', formatNumber((report.tables_analyzed || []).length)],
            [strings.duration || 'Duration', formatDuration(job?.duration_seconds || 0)],
        ];
        for (const [label, value] of metrics) {
            const card = document.createElement('article');
            card.className = 'metric-card';
            const strong = document.createElement('strong');
            strong.textContent = String(value);
            const span = document.createElement('span');
            span.textContent = String(label);
            card.append(strong, span);
            reportMetrics.append(card);
        }
        reportDetails.replaceChildren();
        const note = document.createElement('p');
        note.className = 'muted';
        note.textContent = String(db.reclaim_note || '');
        if (note.textContent) reportDetails.append(note);
        const details = document.createElement('p');
        details.className = 'muted';
        details.textContent = `${strings.tasks_skipped || 'Tasks/tables skipped'}: ${(report.tasks_skipped || []).length} · ${strings.warnings || 'Warnings'}: ${report.warnings || 0} · ${strings.errors || 'Errors'}: ${report.errors || 0}`;
        reportDetails.append(details);

        const breakdown = document.createElement('div');
        breakdown.className = 'maintenance-center-report-breakdown';
        /** Append one non-empty verified report breakdown section. */
        const appendBreakdown = (title, values, mapValues = false) => {
            const entries = mapValues
                ? Object.entries(values || {}).filter(([, value]) => finiteNumber(value) > 0).map(([key, value]) => `${humanize(key)}: ${formatNumber(value)}`)
                : (Array.isArray(values) ? values.filter(Boolean).map(String) : []);
            if (entries.length === 0) return;
            const section = document.createElement('details');
            section.className = 'maintenance-center-report-detail';
            const heading = document.createElement('summary');
            heading.textContent = `${title} (${formatNumber(entries.length)})`;
            const list = document.createElement('ul');
            for (const entry of entries) {
                const item = document.createElement('li');
                item.textContent = entry;
                list.append(item);
            }
            section.append(heading, list);
            breakdown.append(section);
        };
        appendBreakdown(strings.rows_removed_breakdown || 'Rows removed by subsystem', report.rows_removed, true);
        appendBreakdown(strings.analyzed_tables_detail || 'Analyzed tables', report.tables_analyzed);
        appendBreakdown(strings.optimized_tables_detail || 'Optimized tables', report.tables_optimized);
        appendBreakdown(strings.skipped_detail || 'Skipped tasks/tables', report.tasks_skipped);
        if (breakdown.childElementCount > 0) reportDetails.append(breakdown);
    }

    /** Refresh all Maintenance Center presentation regions from the latest job snapshot.
     * @returns {void} Updates the status, progress, plan, activity, and report presentation.
     */
    function render() {
        const status = String(job?.status || 'idle');
        const statusLabels = {
            analyzing: strings.analyzing,
            ready: strings.ready,
            running: strings.running,
            paused: strings.paused,
            completed: strings.completed,
            failed: strings.failed,
            cancelled: strings.cancelled,
            idle: strings.idle,
        };
        if (statusTitle) statusTitle.textContent = statusLabels[status] || humanize(status);
        if (statusBadge) {
            statusBadge.textContent = humanize(status);
            statusBadge.dataset.status = status;
        }
        renderProgress();
        renderActions();
        renderPlan();
        renderActivityAndDiagnostics();
        renderReport();
    }

    /** Advance serially and admit queued operator controls between requests.
     * @param {string} kind Analysis or execution continuation phase.
     * @returns {Promise<void>} Resolves after the loop stops and any queued
     *     pause/cancel request is handed to the serialized action handler.
     */
    async function continueLoop(kind) {
        if (requestActive || !job?.id) return;
        requestActive = true;
        setBrowserError('');
        try {
            while (autoContinue && job?.id && ((kind === 'analysis' && job.status === 'analyzing') || (kind === 'execution' && job.status === 'running'))) {
                job = await request(kind === 'analysis' ? endpoints.analyzeStep : endpoints.executeStep, {job_id: job.id});
                render();
                if (!autoContinue) break;
                await new Promise((resolve) => window.setTimeout(resolve, STEP_DELAY_MS));
            }
        } catch (error) {
            autoContinue = false;
            setBrowserError(`${strings.network_error || 'Request interrupted.'} ${String(error?.message || '')}`.trim());
            renderActions();
        } finally {
            requestActive = false;
            if (pendingControl) {
                const action = pendingControl;
                pendingControl = '';
                void handleAction(action);
            }
        }
    }

    /** Handle an operator action, queuing stop controls behind an active step.
     * @param {string} action Server-backed toolbar action identifier.
     * @returns {Promise<void>} Resolves after queuing a control, completing its
     *     serialized request, or displaying a request failure.
     */
    async function handleAction(action) {
        if (action === 'pause' || action === 'cancel') {
            autoContinue = false;
            if (requestActive) {
                // Cancellation supersedes a pause, never the reverse. The
                // in-flight step settles before its checkpoint is controlled.
                if (pendingControl !== 'cancel') pendingControl = action;
                return;
            }
        }
        if (requestActive) return;
        try {
            setBrowserError('');
            if (action === 'analyze') {
                autoContinue = true;
                requestActive = true;
                job = await request(endpoints.analyzeStart);
                requestActive = false;
                render();
                void continueLoop('analysis');
                return;
            }
            if (action === 'run') {
                const tasks = Array.from(root.querySelectorAll('[data-maintenance-task]:checked')).map((input) => input.dataset.maintenanceTask || '').filter(Boolean);
                autoContinue = true;
                requestActive = true;
                job = await request(endpoints.executeStart, {
                    job_id: job?.id || 0,
                    tasks,
                    full_physical_optimization: fullOptimize?.checked ? '1' : '0',
                });
                requestActive = false;
                render();
                void continueLoop('execution');
                return;
            }
            if (action === 'resume') {
                autoContinue = true;
                requestActive = true;
                job = await request(endpoints.executeStart, {job_id: job?.id || 0});
                requestActive = false;
                render();
                void continueLoop('execution');
                return;
            }
            if (action === 'pause') {
                autoContinue = false;
                requestActive = true;
                job = await request(endpoints.pause, {job_id: job?.id || 0});
                requestActive = false;
                render();
                return;
            }
            if (action === 'cancel') {
                autoContinue = false;
                requestActive = true;
                job = await request(endpoints.cancel, {job_id: job?.id || 0});
                // Running jobs acknowledge the durable cancellation flag first.
                // One final guarded step consumes it and releases their claim.
                if (job?.status === 'running' || job?.status === 'analyzing') {
                    job = await request(
                        job.status === 'running'
                            ? endpoints.executeStep : endpoints.analyzeStep,
                        {job_id: job.id},
                    );
                }
                requestActive = false;
                render();
                return;
            }
            if (action === 'retry') {
                autoContinue = true;
                renderActions();
                void continueLoop(job?.status === 'analyzing' ? 'analysis' : 'execution');
            }
        } catch (error) {
            requestActive = false;
            autoContinue = false;
            setBrowserError(String(error?.message || strings.request_failed || 'Request failed.'));
            renderActions();
        } finally {
            if (!requestActive && pendingControl) {
                const queued = pendingControl;
                pendingControl = '';
                void handleAction(queued);
            }
        }
    }

    document.addEventListener('click', (event) => {
        for (const help of root.querySelectorAll(
            'details.admin-inline-help[open]',
        )) {
            if (event.target instanceof Node && !help.contains(event.target)) {
                help.open = false;
            }
        }
    }, true);

    root.addEventListener('click', (event) => {
        const tab = event.target.closest('[role="tab"][data-maintenance-tab]');
        if (tab && taskTabs?.contains(tab)) {
            event.preventDefault();
            activateTaskTab(tab.dataset.maintenanceTab || 'cleanup', false);
            return;
        }
        const button = event.target.closest('[data-maintenance-action]');
        if (!button || !root.contains(button)) return;
        event.preventDefault();
        void handleAction(button.dataset.maintenanceAction || '');
    });

    root.addEventListener('keydown', (event) => {
        const openHelp = event.target.closest('details.admin-inline-help[open]');
        if (event.key === 'Escape' && openHelp) {
            openHelp.open = false;
            openHelp.querySelector('summary')?.focus();
            return;
        }
        const tab = event.target.closest('[role="tab"][data-maintenance-tab]');
        if (!tab || !taskTabs?.contains(tab)) return;
        const tabButtons = Array.from(taskTabs.querySelectorAll('[role="tab"]'));
        const currentIndex = tabButtons.indexOf(tab);
        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabButtons.length;
        if (event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabButtons.length) % tabButtons.length;
        if (event.key === 'Home') nextIndex = 0;
        if (event.key === 'End') nextIndex = tabButtons.length - 1;
        if (nextIndex === currentIndex) return;
        event.preventDefault();
        activateTaskTab(tabButtons[nextIndex].dataset.maintenanceTab || 'cleanup', true);
    });

    root.addEventListener('change', (event) => {
        if (event.target?.matches?.('[data-maintenance-task]')) {
            updateSelectionSummary();
        }
    });

    render();
    if (job?.status === 'analyzing') void continueLoop('analysis');
    if (job?.status === 'running') void continueLoop('execution');
}

/** Initialize every Maintenance Center surface in the current document. */
export function setupAdminMaintenanceCenter() {
    document.querySelectorAll('[data-maintenance-center]').forEach(setupOneMaintenanceCenter);
}
