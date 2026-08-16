/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-openai-text-assist.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Adds optional OpenAI text assistance to gallery and photo description editors.
 *
 * Responsibilities:
 *   - Detect OpenAI text-assistance controls rendered by the server-side feature gate
 *   - Call the admin JSON endpoint with the existing CSRF token
 *   - Insert generated text only after explicit editor confirmation
 *   - Keep the saved gallery unchanged until the normal form submit happens
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
 *   2026-05-29
 */

import { i18n } from './admin-core.js?v=20260512-modular-admin-v1';

/**
 * Attach OpenAI text-assistance behavior to admin editor controls.
 */
export function setupOpenAITextAssist() {
    if (document.body?.dataset.openaiTextAssistBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.openaiTextAssistBound = '1';
    }

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-openai-generate]') : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const tool = button.closest('[data-openai-text-assist]');
        if (!(tool instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        await generateOpenAITextSuggestion(tool, button);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-openai-bulk-generate]') : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const tool = button.closest('[data-openai-text-assist]');
        if (!(tool instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        await generateOpenAIBulkPhotoDescriptions(tool, button);
    });
}

/**
 * Return whether the selected task uses thumbnail image input.
 *
 * @param {string} task OpenAI task id.
 * @return {boolean} True when the condition matches.
 */
function taskUsesImages(task) {
    return task === 'image_visual_description' || task === 'gallery_visual_description';
}

/**
 * Build a FormData object with shared OpenAI endpoint parameters.
 *
 * @param {HTMLElement} tool OpenAI text-assistance tool root.
 * @param {HTMLFormElement} form Editor form.
 * @return {FormData} Shared request body.
 */
function buildOpenAIBaseBody(tool, form) {
    const body = new FormData();
    body.set('csrf_token', String(form.querySelector('input[name="csrf_token"]')?.value || '').trim());
    body.set('gallery_id', String(tool.dataset.galleryId || form.querySelector('input[name="gallery_id"]')?.value || form.querySelector('input[name="id"]')?.value || ''));
    body.set('image_id', String(tool.dataset.imageId || form.querySelector('input[name="image_id"]')?.value || ''));
    body.set('language', String(tool.querySelector('[data-openai-language]')?.value || 'auto').trim());
    return body;
}

/**
 * Request one JSON OpenAI endpoint action.
 *
 * @param {string} endpoint Endpoint URL.
 * @param {FormData} body Request payload.
 * @return {Promise<Record<string, *>>} Parsed JSON response.
 */
async function postOpenAIJson(endpoint, body) {
    const response = await fetch(endpoint, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const result = await readOpenAIJson(response);
    if (!response.ok || !result.ok) {
        throw new Error(String(result.error || result.message || i18n('admin.openai.js_failed', 'OpenAI text generation failed.')));
    }
    return result;
}

/**
 * Request one text suggestion and write it into the editor textarea.
 *
 * @param {HTMLElement} tool OpenAI text-assistance tool root.
 * @param {HTMLButtonElement} button Generate button.
 */
async function generateOpenAITextSuggestion(tool, button) {
    const form = tool.closest('form');
    if (!(form instanceof HTMLFormElement)) {
        setOpenAIStatus(tool, i18n('admin.openai.js_missing_form', 'The gallery form could not be found.'), true);
        return;
    }

    const targetSelector = String(tool.dataset.openaiTargetSelector || '[data-gallery-description-textarea], [data-openai-description-textarea]').trim();
    const textarea = form.querySelector(targetSelector);
    if (!(textarea instanceof HTMLTextAreaElement) && !(textarea instanceof HTMLInputElement)) {
        setOpenAIStatus(tool, i18n('admin.openai.js_missing_textarea', 'The description field could not be found.'), true);
        return;
    }

    const task = String(tool.querySelector('[data-openai-task]')?.value || 'gallery_description').trim();
    const language = String(tool.querySelector('[data-openai-language]')?.value || 'auto').trim();
    const currentText = textarea.value.trim();
    const sourceSelector = String(tool.dataset.openaiSourceSelector || '').trim();
    const sourceField = sourceSelector !== '' ? form.querySelector(sourceSelector) : textarea;
    const sourceText = sourceField instanceof HTMLTextAreaElement || sourceField instanceof HTMLInputElement ? sourceField.value.trim() : '';
    if (task === 'translate_text' && sourceText === '') {
        setOpenAIStatus(tool, i18n('admin.openai.js_translation_requires_text', 'Add source text before requesting a translation suggestion.'), true);
        return;
    }
    if ((task === 'cleanup_text' || task === 'expand_text') && currentText === '') {
        setOpenAIStatus(tool, i18n('admin.openai.js_requires_text', 'This action needs existing description text first.'), true);
        return;
    }

    if (taskUsesImages(task) && !window.confirm(i18n('admin.openai.js_visual_confirm', 'This action will send one or more small generated thumbnails, not the original files, to OpenAI. Continue?'))) {
        return;
    }

    if (textarea.value.trim() !== '' && !window.confirm(i18n('admin.openai.js_replace_confirm', 'Replace the current description text in the editor? This is not saved until you save the gallery.'))) {
        return;
    }

    const endpoint = String(tool.dataset.openaiEndpoint || '').trim();
    const csrfToken = String(form.querySelector('input[name="csrf_token"]')?.value || '').trim();
    if (endpoint === '' || csrfToken === '') {
        setOpenAIStatus(tool, i18n('admin.openai.js_not_configured', 'OpenAI text assistance is not configured correctly on this page.'), true);
        return;
    }

    const body = buildOpenAIBaseBody(tool, form);
    body.set('task', task);
    body.set('language', language);
    body.set('text', task === 'translate_text' ? sourceText : textarea.value);
    body.set('title', String(form.querySelector('input[name="title"]')?.value || ''));

    button.disabled = true;
    setOpenAIStatus(tool, i18n('admin.openai.js_generating', 'Generating OpenAI text suggestion...'), false);
    try {
        const result = await postOpenAIJson(endpoint, body);
        const generatedText = String(result.text || '').trim();
        if (generatedText === '') {
            throw new Error(i18n('admin.openai.js_empty', 'OpenAI returned an empty suggestion.'));
        }
        textarea.value = generatedText;
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
        textarea.dispatchEvent(new Event('change', {bubbles: true}));
        textarea.focus({preventScroll: true});
        setOpenAIStatus(tool, String(result.message || i18n('admin.openai.js_generated', 'Suggestion inserted. Save the edited item to keep it.')), false);
    } catch (error) {
        setOpenAIStatus(tool, error instanceof Error ? error.message : i18n('admin.openai.js_failed', 'OpenAI text generation failed.'), true);
    } finally {
        button.disabled = false;
    }
}

/**
 * Generate and save photo descriptions for the current gallery one API request at a time.
 *
 * @param {HTMLElement} tool OpenAI text-assistance tool root.
 * @param {HTMLButtonElement} button Bulk button.
 */
async function generateOpenAIBulkPhotoDescriptions(tool, button) {
    const form = tool.closest('form');
    if (!(form instanceof HTMLFormElement)) {
        setOpenAIStatus(tool, i18n('admin.openai.js_missing_form', 'The gallery form could not be found.'), true);
        return;
    }

    const endpoint = String(tool.dataset.openaiEndpoint || '').trim();
    const csrfToken = String(form.querySelector('input[name="csrf_token"]')?.value || '').trim();
    if (endpoint === '' || csrfToken === '') {
        setOpenAIStatus(tool, i18n('admin.openai.js_not_configured', 'OpenAI text assistance is not configured correctly on this page.'), true);
        return;
    }

    button.disabled = true;
    setOpenAIStatus(tool, i18n('admin.openai.js_bulk_counting', 'Counting photos for bulk description...'), false);
    try {
        const countBody = buildOpenAIBaseBody(tool, form);
        countBody.set('bulk_action', 'count_gallery_images');
        const countResult = await postOpenAIJson(endpoint, countBody);
        const imageIds = Array.isArray(countResult.image_ids) ? countResult.image_ids.map((value) => Number.parseInt(String(value), 10)).filter((value) => Number.isFinite(value) && value > 0) : [];
        const count = Number.parseInt(String(countResult.count || imageIds.length || 0), 10);
        if (count <= 0 || imageIds.length === 0) {
            setOpenAIStatus(tool, i18n('admin.openai.js_bulk_no_photos', 'This gallery has no photos to describe.'), false);
            return;
        }

        const confirmation = window.prompt(i18n('admin.openai.js_bulk_confirm', 'This will generate and save descriptions for {count} photo(s), one OpenAI request per photo. Existing descriptions may be replaced. Type {count} to continue.').replaceAll('{count}', String(count)));
        if (confirmation !== String(count)) {
            setOpenAIStatus(tool, i18n('admin.openai.js_bulk_cancelled', 'Bulk photo description cancelled.'), false);
            return;
        }

        let completed = 0;
        let failed = 0;
        for (const imageId of imageIds) {
            const body = buildOpenAIBaseBody(tool, form);
            body.set('bulk_action', 'generate_gallery_image');
            body.set('image_id', String(imageId));
            setOpenAIStatus(tool, i18n('admin.openai.js_bulk_progress', 'Generating photo descriptions: {done}/{total} complete, {failed} failed.').replace('{done}', String(completed)).replace('{total}', String(count)).replace('{failed}', String(failed)), false);
            try {
                await postOpenAIJson(endpoint, body);
                completed += 1;
            } catch (error) {
                failed += 1;
                console.warn('OpenAI bulk photo description failed', {imageId, error});
            }
        }

        setOpenAIStatus(tool, i18n('admin.openai.js_bulk_done', 'Bulk photo descriptions finished: {done}/{total} saved, {failed} failed.').replace('{done}', String(completed)).replace('{total}', String(count)).replace('{failed}', String(failed)), failed > 0);
    } catch (error) {
        setOpenAIStatus(tool, error instanceof Error ? error.message : i18n('admin.openai.js_failed', 'OpenAI text generation failed.'), true);
    } finally {
        button.disabled = false;
    }
}

/**
 * Parse an admin JSON response and convert HTML errors into a readable message.
 *
 * @param {Response} response Fetch response.
 * @return {Promise<Record<string, *>>} Parsed JSON or normalized error payload.
 */
async function readOpenAIJson(response) {
    const text = await response.text();
    try {
        const parsed = JSON.parse(text);
        return parsed && typeof parsed === 'object' ? parsed : {ok: false, error: i18n('admin.openai.js_invalid_json', 'The server returned an invalid OpenAI response.')};
    } catch (error) {
        return {
            ok: false,
            error: text.trim().startsWith('<')
                ? i18n('admin.openai.js_html_response', 'The server returned HTML instead of JSON. Check the admin logs or PHP error log.')
                : (text.trim() || i18n('admin.openai.js_invalid_json', 'The server returned an invalid OpenAI response.')),
        };
    }
}

/**
 * Write the current OpenAI text-assistance status.
 *
 * @param {HTMLElement} tool OpenAI text-assistance tool root.
 * @param {string} message Status text.
 * @param {boolean} failed True when the status is an error.
 */
function setOpenAIStatus(tool, message, failed) {
    const status = tool.querySelector('[data-openai-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }
    status.textContent = message;
    status.classList.toggle('is-error', failed);
}
