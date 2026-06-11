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

// Function `setupAdminLogStatusForms` executes this focused behavior.
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

// Function `setupAdminLogLiveFilters` executes this focused behavior.
export function setupAdminLogLiveFilters() {
    // Variable `form` stores this steps working value.
    const form = document.querySelector('[data-admin-log-filter-form]');
    // Variable `tbody` stores this steps working value.
    const tbody = document.querySelector('[data-admin-log-tbody]');
    if (!form || !tbody) {
        return;
    }
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
        searching: form.dataset.adminLogSearchingText || 'Searching...',
        updated: form.dataset.adminLogUpdatedText || 'Updated.',
        failed: form.dataset.adminLogFailedText || 'Live search failed. Use Apply filters.',
        shown: form.dataset.adminLogShownText || 'shown',
        when: form.dataset.adminLogWhenText || 'When',
    };

    // Function `setLiveState` writes compact search progress text for screen readers and admins.
    const setLiveState = (message) => {
        if (stateLabel) {
            stateLabel.textContent = message;
        }
    };

    // Function `setPage` updates the hidden page input used by filtered requests.
    const setPage = (page) => {
        if (pageInput) {
            pageInput.value = String(Math.max(1, Number(page) || 1));
        }
    };

    // Function `updateSeveritySummary` keeps the multi-select state readable during live filtering.
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
            summary.textContent = severityField.dataset.allText || 'All severities are shown.';
            return;
        }
        const template = severityField.dataset.activeTemplate || 'Active severities: {values}';
        summary.textContent = template.replace('{values}', checkedLabels.join(', '));
    };

    // Function `buildUrl` creates the filtered request URL used by normal and live requests.
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
