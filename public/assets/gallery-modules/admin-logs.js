/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-logs.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides admin log status and live-filter behaviors.
 *
 * Responsibilities:
 *   - Attach behavior to existing server-rendered markup
 *   - Keep DOM interaction predictable and readable
 *   - Avoid unnecessary layout work in performance-sensitive paths
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
 *   2026-05-12
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Bind delegated copy, scoped TXT downloads and page selection for live log fragments.
 * @returns {void} Installs one workspace listener set and refreshes current controls.
 */
function setupAdminLogExports() {
    const toolbar = document.querySelector('[data-admin-log-export-toolbar]');
    const results = document.querySelector('[data-admin-log-results]');
    const bulkForm = document.getElementById('admin-log-bulk-form');
    if (!toolbar || !results || !bulkForm) {
        return;
    }
    const labels = JSON.parse(toolbar.dataset.labels || '{}');
    const choices = Array.from(results.querySelectorAll('[data-admin-log-export-choice]'));
    results.querySelectorAll('[data-admin-log-copy-url], [data-admin-log-copy-selection]').forEach(button => {
        button.hidden = false;
    });
    const pageSelector = results.querySelector('[data-admin-log-select-page]');
    const pageChoices = Array.from(results.querySelectorAll('[data-admin-log-row] > td > [data-admin-log-export-choice]'));
    const checkedCount = choices.filter(choice => choice.checked).length;
    const count = results.querySelector('[data-admin-log-selection-count]');
    if (count) {
        count.textContent = (labels.selection_count || '{count} selected').replace('{count}', String(checkedCount));
    }
    if (pageSelector) {
        pageSelector.disabled = pageChoices.length === 0;
        pageSelector.checked = pageChoices.length > 0 && pageChoices.every(choice => choice.checked);
        pageSelector.indeterminate = !pageSelector.checked && pageChoices.some(choice => choice.checked);
    }
    const scope = toolbar.querySelector('[data-admin-log-export-scope]');
    const unavailable = scope?.value === 'selected' ? checkedCount === 0 : pageChoices.length === 0;
    toolbar.querySelectorAll('[data-admin-log-copy-selection], [data-admin-log-save-selection]').forEach(button => {
        button.disabled = unavailable || button.getAttribute('aria-busy') === 'true';
        button.title = unavailable ? labels.select_first : button.getAttribute('aria-label');
    });
    // Keep one serialized mapping so 500-row pages do not exceed PHP's input-variable limit.
    let mappingInput = bulkForm.querySelector('[name="export_tokens_json"]');
    if (!mappingInput) {
        mappingInput = document.createElement('input');
        mappingInput.type = 'hidden';
        mappingInput.name = 'export_tokens_json';
        bulkForm.append(mappingInput);
    }
    mappingInput.value = JSON.stringify(Object.fromEntries(pageChoices.map(choice => [choice.value, choice.dataset.exportToken || choice.value])));
    if (toolbar.dataset.ready === '1') {
        return;
    }
    toolbar.dataset.ready = '1';
    const detailRows = new WeakMap();
    results.addEventListener('toggle', event => {
        const details = event.target;
        if (!details.matches('.log-entry-details, .admin-log-context')) return;
        if (details.open) {
            const body = details.querySelector(':scope > .admin-log-detail-body');
            const ownerRow = details.closest('[data-admin-log-row]');
            if (!body || !ownerRow) return;
            const expandedRow = document.createElement('tr');
            expandedRow.className = 'admin-log-expanded-row';
            const cell = document.createElement('td');
            cell.colSpan = 9;
            cell.append(body);
            expandedRow.append(cell);
            ownerRow.after(expandedRow);
            detailRows.set(details, {row: expandedRow, body});
        } else {
            const expanded = detailRows.get(details);
            if (expanded) {
                details.append(expanded.body);
                expanded.row.remove();
                detailRows.delete(details);
            }
        }
    }, true);
    results.addEventListener('change', event => {
        if (event.target.matches('[data-admin-log-select-page]')) {
            results.querySelectorAll('[data-admin-log-export-choice]').forEach(choice => {
                choice.checked = event.target.checked;
            });
        }
        if (event.target.matches('[data-admin-log-export-choice], [data-admin-log-select-page], [data-admin-log-export-scope]')) {
            const feedback = toolbar.querySelector('[data-admin-log-export-state]');
            if (feedback) feedback.textContent = '';
            setupAdminLogExports();
        }
    });
    const status = toolbar.querySelector('[data-admin-log-export-state]');
    const setStatus = message => {
        if (status) status.textContent = message;
    };
    const syncFilters = () => {
        results.querySelectorAll('[data-admin-log-export-filter]').forEach(input => input.remove());
        const filters = document.querySelector('[data-admin-log-filter-form]');
        if (!filters) return;
        for (const [name, value] of new FormData(filters)) {
            if (!['category', 'q', 'time_sort', 'severities[]'].includes(name)) continue;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(value);
            input.dataset.adminLogExportFilter = '';
            bulkForm.append(input);
        }
    };
    const exportLogs = async button => {
        if (!button || button.disabled) return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        setStatus(labels.copying);
        try {
            let response;
            const rowUrl = button.dataset.adminLogCopyUrl;
            if (rowUrl) {
                response = await fetch(rowUrl, {headers: {'Accept': 'text/plain'}});
            } else {
                syncFilters();
                response = await fetch(toolbar.dataset.exportUrl, {method: 'POST', body: new FormData(bulkForm), headers: {'Accept': 'text/plain'}});
            }
            if (!response.ok || !response.headers.get('content-type')?.startsWith('text/plain')) {
                throw new Error('Log export failed.');
            }
            const text = await response.text();
            try {
                const previousFocus = document.activeElement;
                const transfer = document.createElement('textarea');
                transfer.value = text;
                transfer.readOnly = true;
                transfer.className = 'admin-log-clipboard-transfer';
                document.body.append(transfer);
                transfer.focus({preventScroll: true});
                transfer.select();
                let copied = false;
                try {
                    copied = document.execCommand('copy');
                } finally {
                    transfer.remove();
                    if (previousFocus instanceof HTMLElement && !previousFocus.disabled) previousFocus.focus({preventScroll: true});
                }
                if (!copied) await navigator.clipboard.writeText(text);
                setStatus(labels.copied);
            } catch {
                // Clipboard permissions and HTTP hosting vary; retain the complete text for manual copying.
                const dialog = document.createElement('dialog');
                dialog.className = 'admin-log-copy-dialog';
                const heading = document.createElement('h2');
                heading.textContent = labels.copy_logs;
                const hint = document.createElement('p');
                hint.textContent = labels.manual_copy;
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.readOnly = true;
                textarea.setAttribute('aria-label', labels.copy_logs);
                const close = document.createElement('button');
                close.type = 'button';
                close.textContent = labels.close;
                close.addEventListener('click', () => dialog.close());
                dialog.addEventListener('close', () => dialog.remove());
                dialog.append(heading, hint, textarea, close);
                document.body.append(dialog);
                dialog.showModal();
                textarea.focus();
                textarea.select();
                setStatus(labels.manual_copy);
            }
        } catch {
            setStatus(labels.export_failed);
        } finally {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            setupAdminLogExports();
        }
    };
    bulkForm.addEventListener('submit', event => {
        if (!event.submitter?.matches('[data-admin-log-save-selection]')) return;
        syncFilters();
        setStatus(labels.preparing);
    });
    results.addEventListener('click', event => {
        const download = event.target.closest('[data-admin-log-save-url]');
        if (download) {
            setStatus(labels.preparing);
            return;
        }
        const button = event.target.closest('[data-admin-log-copy-url], [data-admin-log-copy-selection]');
        if (!button) return;
        event.preventDefault();
        exportLogs(button);
    });
}

// Function `setupAdminLogStatusForms` executes this focused behavior.
/**
 * Handle setup admin log status forms.
 *
 * Used by browser-side gallery behavior.
 */
export function setupAdminLogStatusForms() {
    document.querySelectorAll('[data-admin-log-status-select]').forEach((select) => {
        if (select.dataset.adminLogStatusReady === '1') {
            return;
        }
        select.dataset.adminLogStatusReady = '1';
        // Variable `originalValue` stores this steps working value.
        let originalValue = select.value;
        select.addEventListener('change', async () => {
            // Variable `body` stores this steps working value.
            const body = new FormData();
            body.set('csrf_token', select.dataset.csrfToken || '');
            body.set('action', 'single');
            body.set('log_id', select.dataset.logId || '');
            body.set('status', select.value);
            // Variable `row` stores this steps working value.
            const row = select.closest('[data-admin-log-row]');
            // Variable `state` stores this steps working value.
            const state = row ? row.querySelector('[data-admin-log-state]') : null;
            select.disabled = true;
            try {
                // Variable `response` stores this steps working value.
                const response = await fetch(select.dataset.updateUrl || window.location.href, {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    select.value = originalValue;
                    return;
                }
                // Variable `result` stores this steps working value.
                const result = await response.json();
                if (!result.ok) {
                    select.value = originalValue;
                    return;
                }
                originalValue = select.value;
                if (state) {
                    state.textContent = result.label || select.options[select.selectedIndex]?.textContent || select.value;
                }
            } catch {
                select.value = originalValue;
            } finally {
                select.disabled = false;
            }
        });
    });
}

// Function `setupAdminLogGroupMembers` attaches lazy grouped-instance loading.
/**
 * Handle setup admin log group members.
 *
 * Raw instances are fetched only when a grouped row is opened, and every request
 * is bounded so one noisy event cannot force the browser or PHP to hold the full
 * group in memory.
 *
 * @param {ParentNode} root Root node containing newly rendered log rows.
 * @returns {void} Binds lazy loading to previously uninitialized group disclosures.
 */
function setupAdminLogGroupMembers(root = document) {
    root.querySelectorAll('[data-admin-log-group-members]').forEach((details) => {
        if (details.dataset.adminLogGroupMembersReady === '1') {
            return;
        }
        details.dataset.adminLogGroupMembersReady = '1';

        const list = details.querySelector('[data-admin-log-group-members-list]');
        const moreButton = details.querySelector('[data-admin-log-group-members-more]');
        const state = details.querySelector('[data-admin-log-group-members-state]');
        let nextOffset = 0;
        let loadedOnce = false;
        let loading = false;

        /**
         * Request one bounded server page and append its log entries in place.
         *
         * @return {Promise<void>}
         */
        const loadNextPage = async () => {
            if (loading || !list) {
                return;
            }
            const baseUrl = details.dataset.adminLogGroupMembersUrl || '';
            if (!baseUrl) {
                return;
            }
            loading = true;
            if (moreButton) {
                moreButton.disabled = true;
            }
            if (state) {
                state.textContent = i18n('admin.logs.loading_instances', 'Loading instances...');
            }

            try {
                const url = new URL(baseUrl, window.location.href);
                url.searchParams.set('offset', String(nextOffset));
                const response = await fetch(url.toString(), {
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const result = await response.json();
                if (!result.ok) {
                    throw new Error(result.error || i18n('admin.logs.load_instances_failed', 'Unable to load grouped log instances.'));
                }

                if (!loadedOnce) {
                    list.innerHTML = '';
                }
                if (result.html) {
                    list.insertAdjacentHTML('beforeend', result.html);
                }
                setupAdminLogExports();
                nextOffset = Math.max(nextOffset, Number(result.next_offset) || 0);
                loadedOnce = true;
                if (moreButton) {
                    moreButton.hidden = !result.has_more;
                }
                if (state) {
                    state.textContent = result.state_text || i18n('admin.logs.instances_loaded', '{count} raw instances loaded.', {count: nextOffset});
                }
            } catch {
                if (state) {
                    state.textContent = i18n('admin.logs.load_instances_failed', 'Unable to load grouped log instances.');
                }
            } finally {
                loading = false;
                if (moreButton) {
                    moreButton.disabled = false;
                }
            }
        };

        details.addEventListener('toggle', () => {
            if (details.open && !loadedOnce) {
                loadNextPage();
            }
        });
        if (moreButton) {
            moreButton.addEventListener('click', loadNextPage);
        }
    });
}

// Function `setupAdminLogLiveFilters` executes this focused behavior.
/**
 * Handle setup admin log live filters.
 *
 * Used by browser-side gallery behavior.
 *
 * @returns {void} Binds live filtering and delegates export controls to the log workspace.
 */
export function setupAdminLogLiveFilters() {
    // Variable `form` stores this steps working value.
    const form = document.querySelector('[data-admin-log-filter-form]');
    // Variable `tbody` stores this steps working value.
    const tbody = document.querySelector('[data-admin-log-tbody]');
    if (!form || !tbody) {
        return;
    }
    setupAdminLogGroupMembers(tbody);
    setupAdminLogExports();
    // Variable `countLabel` stores this steps working value.
    const countLabel = document.querySelector('[data-admin-log-count]');
    // Variable `stateLabel` stores this steps working value.
    const stateLabel = document.querySelector('[data-admin-log-live-state]');
    // Variable `emptyContainer` stores this steps working value.
    let emptyContainer = document.querySelector('[data-admin-log-empty]');
    // Variable `timeSortLink` stores this steps working value.
    const timeSortLink = document.querySelector('[data-admin-log-time-sort-link]');
    // Variable `pageInput` stores the hidden pagination page controlled by live filters.
    const pageInput = form.querySelector('[data-admin-log-page-input]');
    // Variable `searchInput` stores this steps working value.
    const searchInput = form.querySelector('[data-admin-log-live-search]');
    // Variable `debounceHandle` stores this steps working value.
    let debounceHandle = 0;
    // Variable `activeRequest` stores this steps working value.
    let activeRequest = null;

    // Variable `liveText` stores translated labels passed from the server-rendered form.
    const liveText = {
        searching: form.dataset.adminLogSearchingText || i18n('admin.logs.searching', 'Searching...'),
        updated: form.dataset.adminLogUpdatedText || i18n('admin.logs.updated', 'Updated.'),
        failed: form.dataset.adminLogFailedText || i18n('admin.logs.live_search_failed', 'Live search failed. Use Apply filters.'),
        shown: form.dataset.adminLogShownText || 'shown',
        when: form.dataset.adminLogWhenText || i18n('admin.logs.when', 'When'),
    };

    // Function `setLiveState` writes compact search progress text for screen readers and admins.
    /**
     * Set live state.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {string} message Message value.
     */
    const setLiveState = (message) => {
        if (stateLabel) {
            stateLabel.textContent = message;
        }
    };

    // Function `setPage` updates the hidden page input used by filtered requests.
    /**
     * Set page.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} page Page number or page data.
     */
    const setPage = (page) => {
        if (pageInput) {
            pageInput.value = String(Math.max(1, Number(page) || 1));
        }
    };

    // Function `updateSeveritySummary` keeps the multi-select state readable during live filtering.
    /**
     * Update severity summary.
     *
     * Used by browser-side gallery behavior.
     */
    const updateSeveritySummary = () => {
        const severityField = form.querySelector('[data-admin-log-severity-filter]');
        const summary = form.querySelector('[data-admin-log-severity-summary]');
        const countBadge = form.querySelector('.admin-log-severity-count');
        if (!severityField || !summary) {
            return;
        }
        const checkedLabels = Array.from(severityField.querySelectorAll('input[type="checkbox"]:checked'))
            .map((input) => input.closest('label')?.textContent?.trim() || '')
            .filter((label) => label !== '');
        if (countBadge) {
            countBadge.textContent = String(checkedLabels.length);
            countBadge.classList.toggle('is-empty', checkedLabels.length === 0);
        }
        if (checkedLabels.length === 0) {
            summary.textContent = severityField.dataset.allText || i18n('admin.logs.severity_filter_all_summary', 'All severities are shown.');
            return;
        }
        const template = severityField.dataset.activeTemplate || i18n('admin.logs.severity_filter_active_summary', 'Active severities: {values}');
        summary.textContent = template.replace('{values}', checkedLabels.join(', '));
    };

    // Function `buildUrl` creates the filtered request URL used by normal and live requests.
    /**
     * Build url.
     *
     * Used by browser-side gallery behavior.
     *
     * @param {*} includeAjax Include ajax value.
     * @return {string} Text result for the caller.
     */
    const buildUrl = (includeAjax = true) => {
        // Variable `params` stores serialized filter controls from the visible form.
        const params = new URLSearchParams(new FormData(form));
        params.set('page', 'admin_logs');
        if (includeAjax) {
            params.set('ajax', '1');
        } else {
            params.delete('ajax');
        }
        for (const [key, value] of Array.from(params.entries())) {
            if (value === '') {
                params.delete(key);
            }
        }
        return `${form.getAttribute('action') || window.location.pathname}?${params.toString()}`;
    };

    // Function `ensureEmptyContainer` creates the no-results message holder when live filtering needs it.
    /**
     * Ensure empty container.
     *
     * Used by browser-side gallery behavior.
     *
     * @return {*} Result value for the caller.
     */
    const ensureEmptyContainer = () => {
        if (emptyContainer) {
            return emptyContainer;
        }
        // Variable `resultsPanel` stores the surrounding admin log results panel.
        const resultsPanel = document.querySelector('[data-admin-log-results]');
        emptyContainer = document.createElement('div');
        emptyContainer.dataset.adminLogEmpty = '';
        if (resultsPanel) {
            const tableWrap = resultsPanel.querySelector('.admin-log-table-wrap');
            resultsPanel.insertBefore(emptyContainer, tableWrap || null);
        }
        return emptyContainer;
    };

    // Function `refreshLogs` fetches matching rows without a full page navigation.
    /**
     * Refresh logs.
     *
     * Used by browser-side gallery behavior.
     */
    const refreshLogs = async () => {
        if (activeRequest) {
            activeRequest.abort();
        }
        activeRequest = new AbortController();
        setLiveState(liveText.searching);
        try {
            // Variable `response` stores this steps working value.
            const response = await fetch(buildUrl(true), {
                headers: {'Accept': 'application/json'},
                signal: activeRequest.signal,
            });
            if (!response.ok) {
                setLiveState(liveText.failed);
                return;
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            if (!result.ok) {
                setLiveState(liveText.failed);
                return;
            }
            tbody.innerHTML = result.rows_html || '';
            setupAdminLogStatusForms();
            setupAdminLogGroupMembers(tbody);
            setupAdminLogExports();
            setPage(result.log_page || 1);
            if (countLabel) {
                countLabel.textContent = `(${result.count_text || `${Number(result.count || 0)} ${liveText.shown}`})`;
            }
            const noResults = Number(result.count || 0) === 0;
            const empty = ensureEmptyContainer();
            empty.innerHTML = noResults ? (result.empty_html || '<p>No log entries match the current filters.</p>') : '';
            empty.hidden = !noResults;
            const pagination = document.querySelector('[data-admin-log-pagination]');
            if (pagination && typeof result.pagination_html === 'string') {
                pagination.outerHTML = result.pagination_html;
            }
            if (timeSortLink) {
                const currentSort = result.time_sort === 'asc' ? 'asc' : 'desc';
                const nextSort = currentSort === 'desc' ? 'asc' : 'desc';
                timeSortLink.dataset.nextSort = nextSort;
                timeSortLink.textContent = `${liveText.when} ${currentSort === 'desc' ? '↓' : '↑'}`;
                const linkUrl = new URL(buildUrl(false), window.location.href);
                linkUrl.searchParams.set('time_sort', nextSort);
                linkUrl.searchParams.set('log_page', '1');
                timeSortLink.href = linkUrl.toString();
            }
            window.history.replaceState(null, '', buildUrl(false));
            setLiveState(liveText.updated);
        } catch (error) {
            if (error.name !== 'AbortError') {
                setLiveState(liveText.failed);
            }
        }
    };

    // Function `scheduleRefresh` debounces typing so the server is not queried on every keystroke.
    /**
     * Schedule refresh.
     *
     * Used by browser-side gallery behavior.
     */
    const scheduleRefresh = () => {
        window.clearTimeout(debounceHandle);
        debounceHandle = window.setTimeout(refreshLogs, 250);
    };

    form.querySelectorAll('[data-admin-log-live-filter]').forEach((control) => {
        control.addEventListener('change', () => {
            setPage(1);
            updateSeveritySummary();
            refreshLogs();
        });
    });
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            setPage(1);
            scheduleRefresh();
        });
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(debounceHandle);
        setPage(1);
        refreshLogs();
    });
    if (timeSortLink) {
        timeSortLink.addEventListener('click', (event) => {
            event.preventDefault();
            const sortControl = form.querySelector('select[name="time_sort"]');
            if (sortControl) {
                sortControl.value = timeSortLink.dataset.nextSort === 'asc' ? 'asc' : 'desc';
            }
            setPage(1);
            refreshLogs();
        });
    }
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-admin-log-page-link]');
        if (!link || !document.querySelector('[data-admin-log-results]')?.contains(link)) {
            return;
        }
        event.preventDefault();
        if (link.classList.contains('is-disabled')) {
            return;
        }
        setPage(link.dataset.adminLogPageLink || 1);
        refreshLogs();
    });
}
