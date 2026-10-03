/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-simbrief-description.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Adds SimBrief description-draft generation to the existing gallery editor.
 *
 * Responsibilities:
 *   - Read SimBrief Pilot ID or pilot name from admin-side inputs
 *   - Call the admin JSON endpoint with the existing CSRF token
 *   - Insert the generated Markdown into the normal description textarea
 *   - Save the fetched OFP and route-map geometry with the edited gallery
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
 *   2026-05-24
 */

import { i18nForElement } from './admin-core.js?v=20261003-scoped-i18n-v1';

/**
 * Attach SimBrief draft-generation behavior to admin editor controls.
 *
 * @returns {void} Binds delegated controls once.
 */
export function setupSimbriefDescriptionGenerator() {
    if (document.body?.dataset.simbriefDescriptionGeneratorBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.simbriefDescriptionGeneratorBound = '1';
    }

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-simbrief-generate]') : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const tool = button.closest('[data-simbrief-description-tool]');
        if (!(tool instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        await generateSimbriefDescription(tool, button);
    });
    document.addEventListener('input', clearSimbriefDraftOnIdentifierInput);
}

/**
 * Clear an imported draft when an identifier changes in any mounted form.
 *
 * @param {Event} event Delegated input event.
 * @returns {void} Clears stale draft identity and replacement consent.
 */
function clearSimbriefDraftOnIdentifierInput(event) {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-simbrief-identifier], [data-simbrief-pilot-id], [data-simbrief-pilot-name]')) return;
    const tool = input.closest('[data-simbrief-description-tool]');
    const draft = tool?.closest('form')?.querySelector('[data-simbrief-draft-ref]');
    if (draft instanceof HTMLInputElement && draft.value !== '') {
        draft.value = '';
        draft.dispatchEvent(new Event('change', {bubbles: true}));
    }
    const button = tool?.querySelector('[data-simbrief-generate]');
    if (button instanceof HTMLButtonElement) {
        button.dataset.simbriefReplaceText = '';
        if (button.dataset.simbriefOriginalLabel) button.textContent = button.dataset.simbriefOriginalLabel;
    }
}

/**
 * Request one draft description and write it into the editor textarea.
 *
 * @param {HTMLElement} tool SimBrief tool root.
 * @param {HTMLButtonElement} button Generate button.
 * @returns {Promise<void>} Completes the preview request and UI update.
 */
async function generateSimbriefDescription(tool, button) {
    const form = tool.closest('form');
    if (!(form instanceof HTMLFormElement)) {
        setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_missing_form', 'The gallery form could not be found.'), true);
        return;
    }

    const textarea = form.querySelector('[data-gallery-description-textarea]');
    if (!(textarea instanceof HTMLTextAreaElement)) {
        setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_missing_textarea', 'The description field could not be found.'), true);
        return;
    }

    const compactInput = tool.querySelector('[data-simbrief-identifier]');
    const enteredIdentifier = compactInput instanceof HTMLInputElement ? compactInput.value.trim() : '';
    const pilotId = compactInput instanceof HTMLInputElement
        ? (/^\d+$/.test(enteredIdentifier) ? enteredIdentifier : '')
        : String(tool.querySelector('[data-simbrief-pilot-id]')?.value || '').trim();
    const pilotName = compactInput instanceof HTMLInputElement
        ? (pilotId === '' ? enteredIdentifier : '')
        : String(tool.querySelector('[data-simbrief-pilot-name]')?.value || '').trim();
    if (pilotId === '' && pilotName === '') {
        setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_missing_identifier', 'Enter a SimBrief Pilot ID or pilot name first.'), true);
        return;
    }

    const translationTextareas = Array.from(form.querySelectorAll('[data-content-translation-description]'))
        .filter(/** Keep native translation textarea controls for snapshot and application.
         * @param {Element} field Candidate localized description control.
         * @return {boolean} Whether the control is a textarea.
         */ (field) => field instanceof HTMLTextAreaElement);
    const existingDraftFingerprint = JSON.stringify([
        textarea.value,
        ...translationTextareas.map(/** Capture each translation's language and current draft text.
         * @param {HTMLTextAreaElement} field Localized description control.
         * @return {[string, string]} Language code and current description.
         */ (field) => [field.dataset.contentTranslationDescription || '', field.value]),
    ]);
    if (textarea.value.trim() !== '' || translationTextareas.some(/** Find any entered translation requiring inline replacement consent.
     * @param {HTMLTextAreaElement} field Localized description control.
     * @return {boolean} Whether this translation contains text.
     */ (field) => field.value.trim() !== '')) {
        if (button.dataset.simbriefReplaceText !== existingDraftFingerprint) {
            button.dataset.simbriefOriginalLabel ||= button.textContent || '';
            button.dataset.simbriefReplaceText = existingDraftFingerprint;
            button.textContent = i18nForElement(tool, 'admin.simbrief.js_replace_button', 'Replace all descriptions and import');
            setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_replace_inline', 'Descriptions are already entered. Click again to replace them and import.'), false);
            return;
        }
    }
    button.dataset.simbriefReplaceText = '';
    if (button.dataset.simbriefOriginalLabel) button.textContent = button.dataset.simbriefOriginalLabel;
    const descriptionBeforeRequest = textarea.value;
    const translationValuesBeforeRequest = translationTextareas.map(/** Snapshot a translation control before the asynchronous request.
     * @param {HTMLTextAreaElement} field Localized description control.
     * @return {[HTMLTextAreaElement, string]} Control identity and current text.
     */ (field) => [field, field.value]);
    const sourceLanguageSelect = form.querySelector('[data-content-language-select]');
    const sourceLanguageBeforeRequest = sourceLanguageSelect instanceof HTMLSelectElement ? sourceLanguageSelect.value : '';
    const draftReference = form.querySelector('[data-simbrief-draft-ref]');

    const endpoint = String(tool.dataset.simbriefEndpoint || '').trim();
    const csrfToken = String(form.querySelector('input[name="csrf_token"]')?.value || '').trim();
    if (endpoint === '' || csrfToken === '') {
        setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_not_configured', 'SimBrief generation is not configured correctly on this page.'), true);
        return;
    }

    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('gallery_id', String(tool.dataset.galleryId || form.querySelector('input[name="id"]')?.value || ''));
    body.set('simbrief_pilot_id', pilotId);
    body.set('simbrief_pilot_name', pilotName);
    body.set('content_language', sourceLanguageBeforeRequest);

    button.disabled = true;
    setSimbriefStatus(tool, i18nForElement(tool, 'admin.simbrief.js_generating', 'Fetching SimBrief data and generating draft...'), false);
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readSimbriefJson(response, tool);
        if (!response.ok || !result.ok) {
            throw new Error(String(result.error || result.message || i18nForElement(tool, 'admin.simbrief.js_failed', 'SimBrief generation failed.')));
        }
        const description = String(result.description || '').trim();
        if (description === '') {
            throw new Error(i18nForElement(tool, 'admin.simbrief.js_empty', 'SimBrief returned flight data, but no description could be generated.'));
        }
        const currentIdentifier = compactInput instanceof HTMLInputElement ? compactInput.value.trim() : '';
        const identifierChanged = compactInput instanceof HTMLInputElement
            ? enteredIdentifier !== currentIdentifier
            : pilotId !== String(tool.querySelector('[data-simbrief-pilot-id]')?.value || '').trim()
                || pilotName !== String(tool.querySelector('[data-simbrief-pilot-name]')?.value || '').trim();
        const descriptionsChanged = descriptionBeforeRequest !== textarea.value
            || translationValuesBeforeRequest.some(/** Detect a translated description edited during fetch.
             * @param {[HTMLTextAreaElement, string]} snapshot Original control and text pair.
             * @return {boolean} Whether the control's current value differs.
             */ ([field, value]) => field.value !== value);
        const sourceLanguageChanged = sourceLanguageSelect instanceof HTMLSelectElement
            && sourceLanguageBeforeRequest !== sourceLanguageSelect.value;
        if (identifierChanged || descriptionsChanged || sourceLanguageChanged) {
            throw new Error(i18nForElement(tool, 'admin.simbrief.js_input_changed', 'The form changed during import. Your text was kept; import the flight again.'));
        }
        textarea.value = description;
        const translations = result.translations && typeof result.translations === 'object' ? result.translations : {};
        translationTextareas.forEach(/** Apply one returned localized draft to its existing textarea.
         * @param {HTMLTextAreaElement} field Localized description control.
         * @return {void} Updates the matching language only.
         */ (field) => {
            const language = String(field.dataset.contentTranslationDescription || '');
            if (language !== '' && typeof translations[language] === 'string') {
                field.value = translations[language];
                field.dispatchEvent(new Event('input', {bubbles: true}));
                field.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
        if (draftReference instanceof HTMLInputElement) draftReference.value = String(result.draft_ref || '');
        if (sourceLanguageSelect instanceof HTMLSelectElement && sourceLanguageBeforeRequest === '') {
            const generatedSourceLanguage = String(result.source_language || '');
            if (generatedSourceLanguage !== '' && Array.from(sourceLanguageSelect.options).some(/** Confirm the server-selected source is offered by this form.
             * @param {HTMLOptionElement} option Candidate source-language option.
             * @return {boolean} Whether the option matches the generated source.
             */ (option) => option.value === generatedSourceLanguage)) {
                sourceLanguageSelect.value = generatedSourceLanguage;
                sourceLanguageSelect.dispatchEvent(new Event('change', {bubbles: true}));
            }
        }
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.dispatchEvent(new Event('change', {bubbles: true}));
        updateSimbriefRouteTextarea(form, result);
        updateSimbriefRouteStatus(tool, result);
        textarea.focus({preventScroll: true});
        setSimbriefStatus(tool, String(result.message || i18nForElement(tool, 'admin.simbrief.js_generated', 'Descriptions are ready in every supported language. Review them; nothing is saved until you save the gallery.')), false);
    } catch (error) {
        setSimbriefStatus(tool, error instanceof Error ? error.message : i18nForElement(tool, 'admin.simbrief.js_failed', 'SimBrief generation failed.'), true);
    } finally {
        button.disabled = false;
    }
}

/**
 * Write the route text returned by SimBrief into the existing route-map editor.
 *
 * @param {HTMLFormElement} form Gallery editor form.
 * @param {Record<string, *>} result Server response.
 */
function updateSimbriefRouteTextarea(form, result) {
    const routeText = String(result?.route?.route_text || '').trim();
    if (routeText === '') {
        return;
    }

    const routeTextarea = form.querySelector('textarea[name="flight_route_text"]');
    if (!(routeTextarea instanceof HTMLTextAreaElement)) {
        return;
    }

    routeTextarea.value = routeText;
    routeTextarea.dispatchEvent(new Event('input', {bubbles: true}));
    routeTextarea.dispatchEvent(new Event('change', {bubbles: true}));
}

/**
 * Show the staged OFP and route-map details next to the SimBrief controls.
 *
 * @param {HTMLElement} tool SimBrief tool root.
 * @param {Record<string, *>} result Server response.
 * @return {void} Updates the route-status message when its presentation node exists.
 */
function updateSimbriefRouteStatus(tool, result) {
    const status = tool.querySelector('[data-simbrief-route-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }

    const pointCount = Number(result?.route?.point_count || 0);
    const ofpSaved = result?.ofp?.saved === true;
    const parts = [];
    if (ofpSaved) {
        parts.push(i18nForElement(tool, 'admin.simbrief.js_ofp_saved', 'OFP will be saved when you save the gallery.'));
    }
    if (pointCount > 0) {
        parts.push(i18nForElement(tool, 'admin.simbrief.js_route_saved', 'Route map will be updated with {points} OFP point(s) when you save the gallery.').replace('{points}', String(pointCount)));
    }
    status.textContent = parts.join(' ');
    status.hidden = parts.length === 0;
}

/**
 * Parse an admin JSON response and convert HTML errors into a readable message.
 *
 * The SimBrief tool calls this after fetch so HTML error pages become readable JSON errors.
 *
 * @param {Response} response Fetch response.
 * @param {HTMLElement} tool SimBrief tool root used for localized errors.
 * @return {Promise<Record<string, *>>} Parsed JSON or normalized error payload.
 */
async function readSimbriefJson(response, tool) {
    const text = await response.text();
    try {
        const parsed = JSON.parse(text);
        return parsed && typeof parsed === 'object' ? parsed : {ok: false, error: i18nForElement(tool, 'admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.')};
    } catch (error) {
        return {
            ok: false,
            error: text.trim().startsWith('<')
                ? i18nForElement(tool, 'admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')
                : (text.trim() || i18nForElement(tool, 'admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.')),
        };
    }
}

/**
 * Write the current SimBrief tool status.
 *
 * @param {HTMLElement} tool SimBrief tool root.
 * @param {string} message Status text.
 * @param {boolean} failed True when the status is an error.
 */
function setSimbriefStatus(tool, message, failed) {
    const status = tool.querySelector('[data-simbrief-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }
    status.textContent = message;
    status.classList.toggle('is-error', failed);
}
