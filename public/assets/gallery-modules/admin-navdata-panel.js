/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-navdata-panel.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides browser behavior for the admin navigation-data page.
 *
 * Responsibilities:
 *   - Copy generated route-data diagnostics when present
 *   - Run small resolver lookup tests from the admin UI
 *   - Render provider/source diagnostics without a page refresh
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
 *   2026-05-27
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach navigation-data page helpers.
 */
export function setupAdminNavigationDataPanel() {
    setupNavigationDataCopyButtons();
    setupNavigationDataLookupForms();
}

/**
 * Attach copy buttons for route-data diagnostics when the page renders them.
 */
function setupNavigationDataCopyButtons() {
    document.querySelectorAll('[data-navdata-copy]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement) || button.dataset.copyReady === '1') {
            return;
        }
        button.dataset.copyReady = '1';

        button.addEventListener('click', () => {
            const value = navigationDataCopyValue(button);
            if (value === '') {
                return;
            }
            copyNavigationDataText(value).then((copied) => {
                showNavigationDataButtonFeedback(button, copied);
            });
        });
    });
}

/**
 * Return the value associated with one copy button.
 *
 * @param {HTMLButtonElement} button Copy button.
 * @return {string} Text result for the caller.
 */
function navigationDataCopyValue(button) {
    const directValue = String(button.dataset.navdataCopyValue || '');
    if (directValue !== '') {
        return directValue;
    }

    const key = String(button.dataset.navdataCopy || '');
    if (key === '') {
        return '';
    }

    const selectorKey = window.CSS && typeof window.CSS.escape === 'function'
        ? window.CSS.escape(key)
        : key.replace(/[^A-Za-z0-9_-]/g, '');
    const source = document.querySelector(`[data-navdata-copy-source="${selectorKey}"]`);
    if (source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement) {
        return source.value;
    }
    if (source instanceof HTMLElement) {
        return source.textContent || '';
    }
    return '';
}

/**
 * Copy text with a fallback for older browsers.
 *
 * @param {string} value Text to copy.
 * @return {Promise<boolean>} True when the condition matches.
 */
async function copyNavigationDataText(value) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch (error) {
            console.warn('Navigation data copy failed.', error);
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (error) {
        console.warn('Navigation data fallback copy failed.', error);
    } finally {
        textarea.remove();
    }
    return copied;
}

/**
 * Show temporary copy result feedback on a button.
 *
 * @param {HTMLButtonElement} button Copy button.
 * @param {boolean} copied Whether copying succeeded.
 */
function showNavigationDataButtonFeedback(button, copied) {
    const originalText = button.dataset.originalText || button.textContent || '';
    button.dataset.originalText = originalText;
    button.textContent = copied ? i18n('admin.navdata.copy_copied', 'Copied') : i18n('admin.navdata.copy_failed', 'Copy failed');
    window.setTimeout(() => {
        button.textContent = originalText;
    }, 1600);
}

/**
 * Attach AJAX lookup tests to resolver forms.
 */
function setupNavigationDataLookupForms() {
    document.querySelectorAll('[data-admin-navdata-lookup]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.lookupReady === '1') {
            return;
        }
        form.dataset.lookupReady = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            runNavigationDataLookup(form);
        });
    });
}

/**
 * Run one navigation-data lookup and render the JSON result in a compact view.
 *
 * @param {HTMLFormElement} form Lookup form.
 */
async function runNavigationDataLookup(form) {
    const result = form.querySelector('[data-admin-navdata-lookup-result]');
    const input = form.querySelector('input[name="ident"]');
    if (!(result instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
        return;
    }

    const ident = input.value.trim();
    if (ident.length < 2) {
        result.textContent = i18n('admin.navdata.lookup_min_chars', 'Enter at least two characters.');
        result.classList.add('is-error');
        return;
    }

    const baseUrl = String(form.dataset.navdataLookupUrl || '').trim();
    if (baseUrl === '') {
        result.textContent = i18n('admin.navdata.lookup_url_missing', 'Lookup URL is missing.');
        result.classList.add('is-error');
        return;
    }

    result.classList.remove('is-error', 'is-ok');
    result.textContent = i18n('admin.navdata.looking_up', 'Looking up {ident}...', {ident: ident.toUpperCase()});

    const separator = baseUrl.includes('?') ? '&' : '?';
    const url = baseUrl + separator + 'ident=' + encodeURIComponent(ident);
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
            result.classList.add('is-error');
            result.textContent = String(payload.error || i18n('admin.navdata.lookup_failed', 'Lookup failed.'));
            return;
        }
        result.classList.add('is-ok');
        result.innerHTML = renderNavigationDataPoint(payload.point);
    } catch (error) {
        result.classList.add('is-error');
        result.textContent = i18n('admin.navdata.lookup_failed_with_error', 'Lookup failed: {error}', {error: String(error && error.message ? error.message : error)});
    }
}

/**
 * Render a resolved point as small safe HTML.
 *
 * @param {Record<string, unknown>} point Resolved point payload.
 * @return {string} Text result for the caller.
 */
function renderNavigationDataPoint(point) {
    const ident = escapeNavigationDataHtml(String(point.ident || ''));
    const name = escapeNavigationDataHtml(String(point.name || ''));
    const kind = escapeNavigationDataHtml(String(point.kind || ''));
    const source = escapeNavigationDataHtml(String(point.source || ''));
    const cycle = escapeNavigationDataHtml(String(point.cycle || ''));
    const latitude = Number(point.latitude || 0).toFixed(6);
    const longitude = Number(point.longitude || 0).toFixed(6);

    return `<strong>${ident}</strong> <span class="muted">${kind}</span><br>` +
        `<span>${name}</span><br>` +
        `<code>${latitude}, ${longitude}</code><br>` +
        `<span class="muted">Source: ${source}${cycle ? ', cycle: ' + cycle : ''}</span>`;
}

/**
 * Escape text before inserting it into the lookup result HTML.
 *
 * @param {string} value Raw value.
 * @return {string} Text result for the caller.
 */
function escapeNavigationDataHtml(value) {
    return value.replace(/[&<>'"]/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;',
    }[char] || char));
}
