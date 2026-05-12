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

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const ENHANCED_ATTRIBUTE = 'data-admin-date-picker-enhanced';
const WRAPPER_CLASS = 'admin-date-picker-control';

/**
 * Returns today's date in the YYYY-MM-DD format required by native date inputs.
 *
 * @returns {string} Current local date formatted for input[type="date"].
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
 * @returns {void}
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
 * @returns {string} SVG icon markup.
 */
function calendarIconMarkup() {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1.25A2.75 2.75 0 0 1 22 6.75v11.5A2.75 2.75 0 0 1 19.25 21H4.75A2.75 2.75 0 0 1 2 18.25V6.75A2.75 2.75 0 0 1 4.75 4H6V3a1 1 0 0 1 1-1Zm12.25 8.5H4v7.75c0 .69.56 1.25 1.25 1.25h14c.69 0 1.25-.56 1.25-1.25V10.5ZM4.75 5.5c-.69 0-1.25.56-1.25 1.25V9h17V6.75c0-.69-.56-1.25-1.25-1.25H18v1a1 1 0 1 1-2 0v-1H8v1a1 1 0 0 1-2 0v-1H4.75Z"/></svg>';
}

/**
 * Enhances one native date input in-place while keeping its original name/value.
 *
 * @param {HTMLInputElement} input Date input to enhance.
 * @returns {void}
 */
function enhanceDateInput(input) {
    if (input.getAttribute(ENHANCED_ATTRIBUTE) === '1') {
        return;
    }
    if (input.closest(`.${WRAPPER_CLASS}`)) {
        input.setAttribute(ENHANCED_ATTRIBUTE, '1');
        return;
    }

    const wrapper = document.createElement('span');
    wrapper.className = WRAPPER_CLASS;

    const opener = document.createElement('button');
    opener.type = 'button';
    opener.className = 'admin-date-picker-trigger';
    opener.setAttribute('aria-label', i18n('admin.date_picker.open', 'Open calendar'));
    opener.innerHTML = calendarIconMarkup();

    const todayButton = document.createElement('button');
    todayButton.type = 'button';
    todayButton.className = 'button secondary admin-date-picker-action';
    todayButton.textContent = i18n('admin.date_picker.today', 'Today');

    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'button secondary admin-date-picker-action';
    clearButton.textContent = i18n('admin.date_picker.delete', 'Delete');

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
 * @returns {void}
 */
function enhanceDateInputsInRoot(root) {
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
 *
 * @returns {void}
 */
export function setupAdminDatePickers() {
    enhanceDateInputsInRoot(document);

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
                enhanceDateInputsInRoot(node);
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}
