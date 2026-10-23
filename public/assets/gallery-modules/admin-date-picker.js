/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-date-picker.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Enhances native admin date inputs with a consistent compact control.
 *
 * Responsibilities:
 *   - Keep the submitted value on the original native date input
 *   - Move the clickable calendar trigger before the date value
 *   - Add Today and Delete quick actions with existing admin button styling
 *   - Re-apply the enhancement to AJAX-loaded side-panel forms
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

import { adminI18nScopeReady, i18nForElement } from './admin-core.js?v=20261003-scoped-i18n-v1';

const ENHANCED_ATTRIBUTE = 'data-admin-date-picker-enhanced';
const WRAPPER_CLASS = 'admin-date-picker-control';

/**
 * Format an instant as the browser's local native datetime control value.
 * @param {Date} instant Valid instant to display in the browser timezone.
 * @return {string} Local YYYY-MM-DDTHH:mm value, without a timezone suffix.
 */
export function publicationLocalInputValue(instant) {
    // 60,000 converts the Date API's minute offset to milliseconds; 16 is the
    // fixed minute-precision datetime-local format length, not a time policy.
    const local = new Date(
        instant.getTime() - instant.getTimezoneOffset() * 60000,
    );
    return local.toISOString().slice(0, 16);
}

/**
 * Convert the native picker's local wall time to the existing UTC transport.
 * @param {string} localValue Local YYYY-MM-DDTHH:mm input, or empty to cancel.
 * @return {string|null} UTC minute value, empty cancellation, or null for an
 *     invalid/nonexistent local wall time (including a daylight-saving gap).
 */
export function publicationUtcInputValue(localValue) {
    if (localValue === '') return '';
    const instant = new Date(localValue);
    if (!Number.isFinite(instant.getTime())
        || publicationLocalInputValue(instant) !== localValue) return null;
    return instant.toISOString().slice(0, 16);
}

/**
 * Show publication time locally while retaining the original UTC form field.
 * @param {HTMLInputElement} input Server-rendered UTC datetime-local control.
 * @return {void} Enhance once; an unavailable timezone retains labelled UTC.
 */
function enhancePublicationTime(input) {
    if (input.dataset.publicationTimeEnhanced === '1') return;
    const initialUtcValue = input.value;
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!zone) return;
    const localInput = document.createElement('input');
    localInput.type = 'datetime-local';
    if (localInput.type !== 'datetime-local') return;
    const instant = input.value ? new Date(`${input.value}:00Z`) : null;
    if (instant && !Number.isFinite(instant.getTime())) return;
    localInput.value = instant ? publicationLocalInputValue(instant) : '';
    localInput.defaultValue = localInput.value;
    localInput.disabled = input.disabled;
    localInput.setAttribute('aria-describedby', 'gallery-publication-help');
    localInput.dataset.publicationLocalTime = '1';
    const label = input.closest('label');
    if (!label) return;
    input.dataset.publicationTimeEnhanced = '1';
    input.type = 'hidden';
    label.insertBefore(localInput, input.nextSibling);
    const zoneLabel = label.querySelector('[data-publication-timezone]');
    if (zoneLabel) zoneLabel.textContent = zone;
    const help = input.form?.querySelector('#gallery-publication-help');
    if (help) help.textContent = input.dataset.localHelp || help.textContent;
    const preview = document.createElement('small');
    preview.className = 'muted admin-access-time-preview';
    label.appendChild(preview);
    const sync = () => {
        const utcValue = publicationUtcInputValue(localInput.value);
        localInput.setCustomValidity(
            utcValue === null ? input.dataset.localInvalid : '',
        );
        // Invalid local input cannot silently retain an earlier valid deadline.
        input.value = utcValue ?? '';
        preview.textContent = utcValue
            ? `${utcValue.replace('T', ' ')} UTC` : '';
        preview.hidden = !utcValue;
    };
    localInput.addEventListener('input', sync);
    localInput.addEventListener('change', sync);
    // Preserve the exact stored instant on initialization, including the second
    // occurrence of a repeated autumn hour. Only an edit chooses a new instant.
    preview.textContent = input.value
        ? `${input.value.replace('T', ' ')} UTC` : '';
    preview.hidden = !input.value;
    input.form?.addEventListener('reset', () => {
        queueMicrotask(() => {
            input.value = initialUtcValue;
            localInput.value = input.value
                ? publicationLocalInputValue(new Date(`${input.value}:00Z`)) : '';
            localInput.setCustomValidity('');
            preview.textContent = input.value
                ? `${input.value.replace('T', ' ')} UTC` : '';
            preview.hidden = !input.value;
        });
    });
}

/**
 * Returns today's date in the YYYY-MM-DD format required by native date inputs.
 *
 * @return {string} Current local date formatted for input[type="date"].
 */
function todayInputValue() {
    const now = new Date();
    const year = String(now.getFullYear()).padStart(4, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Opens the native date picker when the browser exposes the picker API.
 *
 * @param {HTMLInputElement} input Date input owned by the enhanced control.
 */
function openNativeDatePicker(input) {
    input.focus({preventScroll: true});
    if (typeof input.showPicker === 'function') {
        try {
            input.showPicker();
            return;
        } catch (error) {
            // Some browsers require a stricter user activation path. Focusing the
            // input keeps the fallback predictable without surfacing console noise.
        }
    }
    input.click();
}

/**
 * Creates an SVG calendar icon as inline markup.
 *
 * @return {string} SVG icon markup.
 */
function calendarIconMarkup() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1.25A2.75 2.75 0 0 1 22 6.75v11.5A2.75 2.75 0 0 1 19.25 21H4.75A2.75 2.75 0 0 1 2 18.25V6.75A2.75 2.75 0 0 1 4.75 4H6V3a1 1 0 0 1 1-1Zm12.25 8.5H4v7.75c0 .69.56 1.25 1.25 1.25h14c.69 0 1.25-.56 1.25-1.25V10.5ZM4.75 5.5c-.69 0-1.25.56-1.25 1.25V9h17V6.75c0-.69-.56-1.25-1.25-1.25H18v1a1 1 0 1 1-2 0v-1H8v1a1 1 0 0 1-2 0v-1H4.75Z"/></svg>';
}

/**
 * Enhances one native date input in-place while keeping its original name/value.
 *
 * @param {HTMLInputElement} input Date input to enhance.
 * @return {void} Adds translated date controls when the scoped catalog is ready.
 */
function enhanceDateInput(input) {
    if (input.getAttribute(ENHANCED_ATTRIBUTE) === '1') {
        return;
    }
    if (input.closest(`.${WRAPPER_CLASS}`)) {
        input.setAttribute(ENHANCED_ATTRIBUTE, '1');
        return;
    }

    if (!adminI18nScopeReady(input)) return;

    const wrapper = document.createElement('span');
    wrapper.className = WRAPPER_CLASS;

    const opener = document.createElement('button');
    opener.type = 'button';
    opener.className = 'admin-date-picker-trigger';
    opener.setAttribute('aria-label', i18nForElement(input, 'admin.date_picker.open', 'Open calendar'));
    opener.innerHTML = calendarIconMarkup();

    const todayButton = document.createElement('button');
    todayButton.type = 'button';
    todayButton.className = 'button secondary admin-date-picker-action';
    todayButton.textContent = i18nForElement(input, 'admin.date_picker.today', 'Today');

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'button secondary admin-date-picker-action';
    clearButton.textContent = i18nForElement(input, 'admin.date_picker.delete', 'Delete');

    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(opener);
    wrapper.appendChild(input);
    wrapper.appendChild(todayButton);
    wrapper.appendChild(clearButton);

    input.setAttribute(ENHANCED_ATTRIBUTE, '1');
    input.classList.add('admin-date-picker-input');

    opener.addEventListener('click', () => openNativeDatePicker(input));
    todayButton.addEventListener('click', () => {
        input.value = todayInputValue();
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
    });
    clearButton.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.dispatchEvent(new Event('change', {bubbles: true}));
        input.focus({preventScroll: true});
    });
}

/**
 * Enhances date inputs in the supplied DOM root.
 *
 * @param {ParentNode} root DOM root to scan.
 * @return {void} Enhance marked publication times and ordinary date controls.
 */
function enhanceDateInputsInRoot(root) {
    root.querySelectorAll('input[data-publication-utc-time]').forEach((input) => {
        if (input instanceof HTMLInputElement) enhancePublicationTime(input);
    });
    root.querySelectorAll('input[type="date"]').forEach((input) => {
        if (input instanceof HTMLInputElement) {
            enhanceDateInput(input);
        }
    });
}

/**
 * Initializes the reusable admin date picker enhancement.
 *
 * The mutation observer is intentionally narrow and only reacts to added nodes.
 * This covers side-panel content that is injected after the initial page boot.
 * @param {ParentNode} root DOM root to scan before observing future additions.
 * @return {void} Enhances the root and installs the shared observer once.
 */
export function setupAdminDatePickers(root = document) {
    enhanceDateInputsInRoot(root);
    if (!document.body || document.body.dataset.adminDatePickerObserverBound === '1') return;
    document.body.dataset.adminDatePickerObserverBound = '1';

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                if (node.matches('input[type="date"]')) {
                    enhanceDateInput(node);
                    return;
                }
                if (node.matches('input[data-publication-utc-time]')) {
                    enhancePublicationTime(node);
                    return;
                }
                enhanceDateInputsInRoot(node);
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}
