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
    document.addEventListener('click', handleLegacyOfpDispatchClick);
    document.addEventListener('submit', handleLegacyOfpDispatchConfirm);
    document.addEventListener('submit', handleLegacyOfpPdfUpload);

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
    const routeTextarea = form.querySelector('textarea[name="flight_route_text"]');
    const routeBeforeRequest = routeTextarea instanceof HTMLTextAreaElement ? routeTextarea.value : '';

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
        const routeChanged = routeTextarea instanceof HTMLTextAreaElement && routeBeforeRequest !== routeTextarea.value;
        if (identifierChanged || descriptionsChanged || sourceLanguageChanged || routeChanged) {
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
 * @returns {void} Populate the staged route without saving the gallery.
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
    const ofpStaged = String(result?.draft_ref || '') !== '';
    const parts = [];
    if (ofpStaged) {
        parts.push(i18nForElement(tool, 'admin.simbrief.js_ofp_saved', 'OFP will be saved when you save the gallery.'));
    }
    if (pointCount >= 2) {
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
 * @returns {void} Update the visible import status.
 */
function setSimbriefStatus(tool, message, failed) {
    const status = tool.querySelector('[data-simbrief-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }
    status.textContent = message;
    status.classList.toggle('is-error', failed);
}

/**
 * Open or cancel a one-gallery pre-dispatch dialog without any remote action.
 *
 * The inert template is cloned into body because an admin side panel may clip
 * descendants, and the native modal dialog handles focus trapping and Escape.
 *
 * @param {MouseEvent} event Delegated click event.
 * @returns {void} Opens or dismisses the review dialog.
 */
function handleLegacyOfpDispatchClick(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const cancel = target.closest('[data-simbrief-dispatch-cancel]');
    if (cancel) {
        event.preventDefault();
        const dialog = cancel.closest('[data-simbrief-dispatch-dialog]');
        if (dialog instanceof HTMLDialogElement) dialog.close();
        return;
    }
    const button = target.closest('[data-simbrief-dispatch-open]');
    if (!(button instanceof HTMLButtonElement)) return;
    const card = button.closest('[data-simbrief-legacy-panel]');
    const template = card?.querySelector('[data-simbrief-dispatch-template]');
    if (!(template instanceof HTMLTemplateElement)) return;
    event.preventDefault();
    if (document.querySelector('dialog[data-simbrief-dispatch-dialog][open]')) return;
    const copy = template.content.cloneNode(true);
    const dialog = copy.querySelector('[data-simbrief-dispatch-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) return;
    document.body.append(dialog);
    dialog.addEventListener('close', /** Destroy the detached modal and its edited-only data.
     * @returns {void} Clear the transient review without persisting to the gallery.
     */ () => dialog.remove(), {once: true});
    dialog.showModal();
    dialog.querySelector('input[name="orig"]')?.focus();
}

/**
 * Convert a verified ISO departure date into SimBrief's documented DDMMMYY.
 *
 * @param {string} input ISO date explicitly reviewed by the administrator.
 * @returns {string} SimBrief date, or empty when omitted.
 * @throws {Error} When a supplied date cannot be safely encoded.
 */
export function simbriefDispatchEncodeDate(input) {
    if (input === '') return '';
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input);
    if (!match) throw new Error('Invalid departure date.');
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const utc = new Date(Date.UTC(year, month - 1, day));
    if (year < 2000 || year > 2099 || utc.getUTCFullYear() !== year
        || utc.getUTCMonth() + 1 !== month || utc.getUTCDate() !== day) {
        throw new Error('The SimBrief date must be a valid date between 2000 and 2099.');
    }
    const months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    return String(day).padStart(2, '0') + months[month - 1] + String(year % 100).padStart(2, '0');
}

/**
 * Serialize only supported, confirmed SimBrief Dispatch Redirect options.
 *
 * @param {HTMLFormElement} form Reviewed transient form, never gallery settings.
 * @returns {string} Fixed HTTPS origin and documented option names only.
 * @throws {Error} On invalid supplied values or missing ICAO endpoints.
 */
export function buildSimbriefDispatchRedirectUrl(form) {
    const endpoint = new URL('https://dispatch.simbrief.com/options/custom');
    const patterns = {
        orig: /^[A-Z]{4}$/, dest: /^[A-Z]{4}$/, type: /^[A-Z0-9]{2,8}$/,
        airline: /^[A-Z0-9]{2,3}$/, fltnum: /^[A-Z0-9]{1,8}$/,
        callsign: /^[A-Z0-9]{2,12}$/, reg: /^[A-Z0-9-]{2,12}$/,
        route: /^[A-Z0-9 .\/+()-]{1,500}$/, altn: /^[A-Z]{4}$/,
        pax: /^\d{1,3}$/, fl: /^\d{1,3}$/,
        deph: /^\d{1,2}$/, depm: /^\d{1,2}$/,
    };
    const fields = {};
    for (const key of [...Object.keys(patterns), 'date']) {
        const input = form.elements.namedItem(key);
        fields[key] = input instanceof HTMLInputElement ? input.value.trim().toUpperCase() : '';
        if (fields[key] === '') continue;
        if (key !== 'date' && !patterns[key].test(fields[key])) {
            const message = (key === 'orig' || key === 'dest')
                ? i18nForElement(form, 'admin.legacy_ofp.error_airports', 'Enter valid four-letter origin and destination ICAO codes.')
                : i18nForElement(form, 'admin.legacy_ofp.error_format', 'Check the format of {field}.').replace('{field}', key);
            throw new Error(message);
        }
        if (key === 'fl' && Number(fields[key]) > 600) throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_flight_level', 'Flight level must not exceed FL600.'));
        if (key === 'pax' && Number(fields[key]) > 999) throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_pax', 'Passenger count must be between 0 and 999.'));
        if (key === 'deph' && Number(fields[key]) > 23) throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_hour', 'UTC departure hour must be 0 to 23.'));
        if (key === 'depm' && Number(fields[key]) > 59) throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_minute', 'UTC departure minute must be 0 to 59.'));
    }
    if (!fields.orig || !fields.dest) {
        throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_airports', 'Enter valid four-letter origin and destination ICAO codes.'));
    }
    if (Boolean(fields.deph) !== Boolean(fields.depm)) {
        throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_time_pair', 'Enter both UTC departure hour and minute, or leave both blank.'));
    }
    for (const [key, value] of Object.entries(fields)) {
        if (value === '') continue;
        if (key === 'date') {
            try {
                endpoint.searchParams.set('date', simbriefDispatchEncodeDate(value));
            } catch {
                throw new Error(i18nForElement(form, 'admin.legacy_ofp.error_date', 'Enter a valid date from 2000 to 2099.'));
            }
        } else {
            endpoint.searchParams.set(key, value);
        }
    }
    return endpoint.href;
}

/**
 * Submit the reviewed redirect once, synchronously from the actual click.
 *
 * No fetch, navigation or window opening happens during modal construction or
 * editing. The new tab has neither opener access nor a referrer.
 *
 * @param {SubmitEvent} event Transient pre-dispatch form submit.
 * @returns {void} Opens the official SimBrief Dispatch Redirect, not Generate.
 */
function handleLegacyOfpDispatchConfirm(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-simbrief-dispatch-form]')) return;
    event.preventDefault();
    if (form.dataset.pending === '1') return;
    const error = form.querySelector('[data-simbrief-dispatch-error]');
    if (error) error.textContent = '';
    try {
        const url = buildSimbriefDispatchRedirectUrl(form);
        const confirm = form.querySelector('[data-simbrief-dispatch-confirm]');
        form.dataset.pending = '1';
        if (confirm instanceof HTMLButtonElement) confirm.disabled = true;
        window.open(url, '_blank', 'noopener,noreferrer');
        form.closest('[data-simbrief-dispatch-dialog]')?.close();
    } catch (failure) {
        const message = failure instanceof Error ? failure.message : i18nForElement(form, 'admin.legacy_ofp.error_format', 'Check the format of {field}.').replace('{field}', '?');
        if (error) error.textContent = message;
    }
}

/**
 * Upload one PDF through its dedicated CSRF-protected admin editor action.
 *
 * No gallery settings or photo upload fields are serialized. The selected
 * panel is refreshed in place, including when hosted by the side drawer.
 *
 * @param {SubmitEvent} event Gallery-scoped PDF form submission.
 * @returns {Promise<void>} Validated AJAX attachment outcome.
 */
async function handleLegacyOfpPdfUpload(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-simbrief-ofp-upload-form]')) return;
    event.preventDefault();
    if (form.dataset.pending === '1') return;
    const status = form.querySelector('[data-simbrief-ofp-upload-status]');
    const file = form.querySelector('input[name="simbrief_ofp_pdf"]');
    const button = form.querySelector('button[type="submit"]');
    if (!(file instanceof HTMLInputElement) || !file.files?.length) {
        if (status) status.textContent = i18nForElement(form, 'admin.legacy_ofp.invalid_upload', 'Select a PDF of up to 25 MiB and check PHP upload limits.');
        return;
    }
    if (file.files[0].size > 26214400) {
        if (status) status.textContent = i18nForElement(form, 'admin.legacy_ofp.invalid_upload', 'Select a PDF of up to 25 MiB and check PHP upload limits.');
        return;
    }
    const data = new FormData(form);
    data.set('ajax', '1');
    form.dataset.pending = '1';
    if (button instanceof HTMLButtonElement) button.disabled = true;
    if (status) status.textContent = i18nForElement(form, 'admin.legacy_ofp.uploading', 'Attaching PDF to the selected gallery...');
    try {
        const response = await fetch(form.action, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(String(result.error || i18nForElement(form, 'admin.legacy_ofp.upload_failed', 'The PDF could not be attached.')));
        }
        const card = form.closest('[data-simbrief-legacy-panel]');
        if (card && typeof result.panel_html === 'string' && result.panel_html !== '') {
            const holder = document.createElement('div');
            holder.innerHTML = result.panel_html;
            const updated = holder.querySelector('[data-simbrief-legacy-panel]');
            if (updated) {
                card.replaceWith(updated);
                const nextStatus = updated.querySelector('[data-simbrief-ofp-upload-status]');
                if (nextStatus) nextStatus.textContent = String(result.message || '');
                return;
            }
        }
        if (status) status.textContent = String(result.message || '');
    } catch (failure) {
        if (status) status.textContent = failure instanceof Error ? failure.message : i18nForElement(form, 'admin.legacy_ofp.upload_failed', 'The PDF could not be attached.');
    } finally {
        form.dataset.pending = '0';
        if (button instanceof HTMLButtonElement) button.disabled = false;
    }
}

