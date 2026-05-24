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
 *   - Avoid saving anything until the user submits the gallery editor form
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

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach SimBrief draft-generation behavior to admin editor controls.
 *
 * @returns {void}
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
}

/**
 * Request one draft description and write it into the editor textarea.
 *
 * @param {HTMLElement} tool SimBrief tool root.
 * @param {HTMLButtonElement} button Generate button.
 * @returns {Promise<void>}
 */
async function generateSimbriefDescription(tool, button) {
    const form = tool.closest('form');
    if (!(form instanceof HTMLFormElement)) {
        setSimbriefStatus(tool, i18n('admin.simbrief.js_missing_form', 'The gallery form could not be found.'), true);
        return;
    }

    const textarea = form.querySelector('[data-gallery-description-textarea]');
    if (!(textarea instanceof HTMLTextAreaElement)) {
        setSimbriefStatus(tool, i18n('admin.simbrief.js_missing_textarea', 'The description field could not be found.'), true);
        return;
    }

    const pilotId = String(tool.querySelector('[data-simbrief-pilot-id]')?.value || '').trim();
    const pilotName = String(tool.querySelector('[data-simbrief-pilot-name]')?.value || '').trim();
    if (pilotId === '' && pilotName === '') {
        setSimbriefStatus(tool, i18n('admin.simbrief.js_missing_identifier', 'Enter a SimBrief Pilot ID or pilot name first.'), true);
        return;
    }

    if (textarea.value.trim() !== '' && !window.confirm(i18n('admin.simbrief.js_replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the gallery.'))) {
        return;
    }

    const endpoint = String(tool.dataset.simbriefEndpoint || '').trim();
    const csrfToken = String(form.querySelector('input[name="csrf_token"]')?.value || '').trim();
    if (endpoint === '' || csrfToken === '') {
        setSimbriefStatus(tool, i18n('admin.simbrief.js_not_configured', 'SimBrief generation is not configured correctly on this page.'), true);
        return;
    }

    const body = new FormData();
    body.set('csrf_token', csrfToken);
    body.set('gallery_id', String(tool.dataset.galleryId || form.querySelector('input[name="id"]')?.value || ''));
    body.set('simbrief_pilot_id', pilotId);
    body.set('simbrief_pilot_name', pilotName);

    button.disabled = true;
    setSimbriefStatus(tool, i18n('admin.simbrief.js_generating', 'Fetching SimBrief data and generating draft...'), false);
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
        const result = await readSimbriefJson(response);
        if (!response.ok || !result.ok) {
            throw new Error(String(result.error || result.message || i18n('admin.simbrief.js_failed', 'SimBrief generation failed.')));
        }
        const description = String(result.description || '').trim();
        if (description === '') {
            throw new Error(i18n('admin.simbrief.js_empty', 'SimBrief returned flight data, but no description could be generated.'));
        }
        textarea.value = description;
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.dispatchEvent(new Event('change', {bubbles: true}));
        textarea.focus({preventScroll: true});
        setSimbriefStatus(tool, String(result.message || i18n('admin.simbrief.js_generated', 'Draft generated. Review it, then save the gallery.')), false);
    } catch (error) {
        setSimbriefStatus(tool, error instanceof Error ? error.message : i18n('admin.simbrief.js_failed', 'SimBrief generation failed.'), true);
    } finally {
        button.disabled = false;
    }
}

/**
 * Parse an admin JSON response and convert HTML errors into a readable message.
 *
 * @param {Response} response Fetch response.
 * @returns {Promise<Record<string, *>>} Parsed JSON or normalized error payload.
 */
async function readSimbriefJson(response) {
    const text = await response.text();
    try {
        const parsed = JSON.parse(text);
        return parsed && typeof parsed === 'object' ? parsed : {ok: false, error: i18n('admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.')};
    } catch (error) {
        return {
            ok: false,
            error: text.trim().startsWith('<')
                ? i18n('admin.simbrief.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')
                : (text.trim() || i18n('admin.simbrief.js_invalid_json', 'The server returned an invalid SimBrief response.')),
        };
    }
}

/**
 * Write the current SimBrief tool status.
 *
 * @param {HTMLElement} tool SimBrief tool root.
 * @param {string} message Status text.
 * @param {boolean} failed True when the status is an error.
 * @returns {void}
 */
function setSimbriefStatus(tool, message, failed) {
    const status = tool.querySelector('[data-simbrief-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }
    status.textContent = message;
    status.classList.toggle('is-error', failed);
}
