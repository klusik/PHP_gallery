/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-gallery-date-suggestion.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Applies per-gallery EXIF date suggestions without reloading the gallery editor.
 *
 * Responsibilities:
 *   - Intercept only the focused EXIF date suggestion action
 *   - Keep the no-JavaScript submit and redirect fallback intact
 *   - Refresh the visible date range fields and suggestion panel after a successful save
 *   - Reuse the same endpoint for the full editor and side-panel editor contexts
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
 *   2026-06-07
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

const APPLY_SELECTOR = '[data-admin-gallery-date-apply]';
const SUGGESTION_SELECTOR = '[data-admin-gallery-date-suggestion]';

/**
 * Return a JSON object from a fetch response, including useful diagnostics for HTML error pages.
 *
 * @param {Response} response Server response returned by fetch.
 * @return {Promise<Record<string, *>>} Parsed JSON response.
 */
async function readJsonResponse(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (error) {
        throw new Error(text.trim().startsWith('<')
            ? i18n('admin.gallery_dates.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')
            : i18n('admin.gallery_dates.js_invalid_json', 'The server returned an invalid JSON response.'));
    }
}

/**
 * Find or create the admin notice element used for in-place feedback.
 *
 * @return {HTMLElement} Notice container.
 */
function ensureAdminNotice() {
    const existing = document.querySelector('.notice');
    if (existing instanceof HTMLElement) {
        return existing;
    }

    const notice = document.createElement('div');
    notice.className = 'notice';
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');

    const hero = document.querySelector('.admin-edit-gallery-hero, .admin-dashboard-hero');
    if (hero?.parentNode) {
        hero.parentNode.insertBefore(notice, hero.nextSibling);
        return notice;
    }

    document.body.prepend(notice);
    return notice;
}

/**
 * Show an admin notice without replacing the current editor page.
 *
 * @param {string} message Human-readable message returned by the server.
 */
function showAdminNotice(message) {
    const notice = ensureAdminNotice();
    notice.textContent = message;
    notice.hidden = false;
}

/**
 * Return the suggestion panel that owns an apply button.
 *
 * @param {HTMLButtonElement} button Button that started the action.
 * @return {HTMLElement|null} Owning suggestion panel, if present.
 */
function suggestionPanelForButton(button) {
    const panel = button.closest(SUGGESTION_SELECTOR);
    return panel instanceof HTMLElement ? panel : null;
}

/**
 * Return the gallery id carried by the suggestion component or parent form.
 *
 * @param {HTMLFormElement|null} form Parent editor form, when the button belongs to one.
 * @param {HTMLElement|null} panel Owning suggestion panel.
 * @return {string} Gallery id value for the request payload.
 */
function galleryIdForRequest(form, panel) {
    const panelGalleryId = panel?.dataset.adminGalleryDateGalleryId || '';
    if (panelGalleryId !== '') {
        return panelGalleryId;
    }

    const galleryInput = form?.querySelector('input[name="id"], input[name="gallery_id"]');
    return galleryInput instanceof HTMLInputElement ? galleryInput.value : '';
}

/**
 * Return the CSRF token carried by the suggestion component or parent form.
 *
 * @param {HTMLFormElement|null} form Parent editor form, when the button belongs to one.
 * @param {HTMLElement|null} panel Owning suggestion panel.
 * @return {string} CSRF token value for the request payload.
 */
function csrfTokenForRequest(form, panel) {
    const panelToken = panel?.dataset.adminGalleryDateCsrf || '';
    if (panelToken !== '') {
        return panelToken;
    }

    const csrfInput = form?.querySelector('input[name="csrf_token"]');
    return csrfInput instanceof HTMLInputElement ? csrfInput.value : '';
}

/**
 * Return the focused apply endpoint used by the reusable suggestion component.
 *
 * @param {HTMLFormElement|null} form Parent editor form, when the button belongs to one.
 * @param {HTMLButtonElement} button Button that started the action.
 * @param {HTMLElement|null} panel Owning suggestion panel.
 * @return {string} URL used for the apply request.
 */
function endpointForRequest(form, button, panel) {
    return button.getAttribute('formaction')
        || panel?.dataset.adminGalleryDateEndpoint
        || form?.getAttribute('action')
        || window.location.href;
}

/**
 * Update one input value and notify browser enhancements about the change.
 *
 * @param {HTMLFormElement} form Gallery editor form.
 * @param {string} name Input name to update.
 * @param {string} value New input value.
 */
function updateDateInput(form, name, value) {
    const input = form.querySelector(`input[name="${name}"]`);
    if (!(input instanceof HTMLInputElement)) {
        return;
    }
    input.value = value || '';
    input.dispatchEvent(new Event('input', {bubbles: true}));
    input.dispatchEvent(new Event('change', {bubbles: true}));
}

/**
 * Replace the visible suggestion panel with refreshed server-rendered markup.
 *
 * @param {HTMLButtonElement} button Button that started the action.
 * @param {string} html New suggestion panel HTML.
 */
function replaceSuggestionPanel(button, html) {
    if (!html) {
        return;
    }
    const panel = button.closest(SUGGESTION_SELECTOR);
    if (!(panel instanceof HTMLElement)) {
        return;
    }
    panel.outerHTML = html;
}

/**
 * Apply the current gallery EXIF date suggestion through AJAX.
 *
 * @param {HTMLFormElement|null} form Gallery editor form, if the button belongs to one.
 * @param {HTMLButtonElement} button Suggestion apply button.
 */
async function applyGalleryDateSuggestion(form, button) {
    const originalText = button.textContent || '';
    const panel = suggestionPanelForButton(button);
    button.disabled = true;
    button.textContent = i18n('admin.operations.working', 'Working...');

    try {
        const formData = new FormData();
        const csrfToken = csrfTokenForRequest(form, panel);
        const galleryId = galleryIdForRequest(form, panel);
        if (csrfToken !== '') {
            formData.set('csrf_token', csrfToken);
        }
        if (galleryId !== '') {
            formData.set('gallery_id', galleryId);
            formData.set('id', galleryId);
        }
        formData.set('return_tab', 'admin-edit-identity');
        formData.set('action', 'apply_exif_date_suggestion');
        formData.set('ajax', '1');

        const response = await fetch(endpointForRequest(form, button, panel), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponse(response);
        if (!response.ok || !result.ok) {
            throw new Error(result.message || result.error || i18n('admin.gallery_dates.js_apply_failed', 'EXIF date suggestion could not be applied.'));
        }

        if (form instanceof HTMLFormElement) {
            updateDateInput(form, 'gallery_date', result.gallery_date || '');
            updateDateInput(form, 'gallery_date_end', result.gallery_date_end || '');
        }
        replaceSuggestionPanel(button, result.suggestion_html || '');
        showAdminNotice(result.message || i18n('admin.gallery_dates.js_applied', 'EXIF date suggestion applied.'));
    } catch (error) {
        showAdminNotice(error instanceof Error ? error.message : String(error));
        button.disabled = false;
        button.textContent = originalText;
    }
}

/**
 * Initialize in-place EXIF date suggestion handling for gallery editor forms.
 */
export function setupAdminGalleryDateSuggestions() {
    if (document.body?.dataset.adminGalleryDateSuggestionsBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.adminGalleryDateSuggestionsBound = '1';
    }

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest(APPLY_SELECTOR) : null;
        if (!(target instanceof HTMLButtonElement)) {
            return;
        }

        const form = target.form instanceof HTMLFormElement ? target.form : target.closest('form');
        event.preventDefault();
        applyGalleryDateSuggestion(form instanceof HTMLFormElement ? form : null, target);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        const submitter = event.submitter;
        if (!(form instanceof HTMLFormElement) || !(submitter instanceof HTMLButtonElement)) {
            return;
        }
        if (!submitter.matches(APPLY_SELECTOR)) {
            return;
        }

        event.preventDefault();
        applyGalleryDateSuggestion(form, submitter);
    });
}
