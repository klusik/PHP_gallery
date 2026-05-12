/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-core.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides shared admin browser helpers for split admin modules.
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

// Function `setupAdminTabs` executes this focused behavior.

/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @returns {string} Browser-facing translated text.
 */
export function i18n(key, fallback, parameters = {}) {
    const root = window.PHP_GALLERY_I18N && typeof window.PHP_GALLERY_I18N === 'object' ? window.PHP_GALLERY_I18N : {};
    const strings = root.strings && typeof root.strings === 'object' ? root.strings : {};
    let text = typeof strings[key] === 'string' ? strings[key] : fallback;
    Object.entries(parameters).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}

/**
 * Escape HTML text before inserting generated success markup.
 *
 * @param {string} value Raw value.
 * @returns {string} Escaped text.
 */
export function escapeHtmlText(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character] || character);
}

/**
 * Escape an attribute value before inserting generated success markup.
 *
 * @param {string} value Raw value.
 * @returns {string} Escaped attribute value.
 */
export function escapeHtmlAttribute(value) {
    return escapeHtmlText(value).replace(/`/g, '&#096;');
}

/**
 * Handles admin url with params behavior for the gallery UI.
 * @param {*} params Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
export function adminUrlWithParams(params) {
    // url stores state or configuration for the gallery front-end flow.
    const url = new URL(window.location.href);
    url.search = '?page=admin';
    Object.entries(params).forEach(([key, value]) => {
        url.searchParams.set(key, String(value));
    });
    return url.toString();
}

// Function `isThumbnailSubmission` executes this focused behavior.
export function isThumbnailSubmission(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || '';
    // Variable `selectedAction` stores this steps working value.
    const selectedAction = form.querySelector('select[name="action"]')?.value || '';
    return action.includes('admin_create_thumbnails') || selectedAction === 'thumbs';
}

// Function `thumbnailEndpoint` executes this focused behavior.
export function thumbnailEndpoint(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || window.location.href;
    // Variable `endpoint` stores this steps working value.
    const endpoint = new URL(action, window.location.href);
    endpoint.searchParams.set('page', 'admin_create_thumbnails');
    return endpoint.toString();
}

// Function `ensureThumbnailProgress` executes this focused behavior.
export function ensureThumbnailProgress(form) {
    // Variable `targetSelector` stores this steps working value.
    const targetSelector = form.dataset.thumbnailProgressTarget || '';
    if (targetSelector) {
        // Variable `target` stores this steps working value.
        const target = document.querySelector(targetSelector);
        if (target) {
            // progress stores state or configuration for the gallery front-end flow.
            let progress = target.querySelector('[data-thumbnail-progress]');
            if (!progress) {
                progress = createThumbnailProgress();
                target.append(progress);
            }
            progress.hidden = false;
            return progress;
        }
    }

    const panelAnchor = form.closest('[data-gallery-panel-workflow]')?.querySelector('[data-gallery-panel-progress-anchor]');
    if (panelAnchor instanceof HTMLElement) {
        let progress = panelAnchor.querySelector('[data-thumbnail-progress]');
        if (!progress) {
            progress = createThumbnailProgress();
            panelAnchor.append(progress);
        }
        progress.hidden = false;
        return progress;
    }

    // Variable `progress` stores this steps working value.
    let progress = form.classList.contains('inline-form')
        ? form.nextElementSibling?.matches('[data-thumbnail-progress]') ? form.nextElementSibling : null
        : form.querySelector('[data-thumbnail-progress]');
    if (progress) {
        progress.hidden = false;
        return progress;
    }
    progress = createThumbnailProgress();
    if (form.classList.contains('inline-form')) {
        form.insertAdjacentElement('afterend', progress);
    } else {
        form.prepend(progress);
    }
    progress.hidden = false;
    return progress;
}

// Function `createThumbnailProgress` executes this focused behavior.
export function createThumbnailProgress() {
    // Variable `progress` stores this steps working value.
    const progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.thumbnailProgress = 'true';
    progress.innerHTML = '<progress class="thumbnail-progress-bar" data-thumbnail-progress-fill value="0" max="100"></progress><p class="muted" data-thumbnail-progress-text></p>';
    return progress;
}

// Function `updateThumbnailProgress` executes this focused behavior.
export function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
    progress.hidden = false;
    // Variable `percent` stores this steps working value.
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
    progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
    progress.querySelector('[data-thumbnail-progress-text]').textContent =
        `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
}

// Function `updateBasicProgress` executes this focused behavior.
export function updateBasicProgress(progress, percent, label) {
    progress.hidden = false;
    progress.querySelector('[data-thumbnail-progress-fill]').value = Math.max(0, Math.min(100, percent));
    progress.querySelector('[data-thumbnail-progress-text]').textContent = label;
}

// Function `setGalleryRowHiddenReason` executes this focused behavior.
export function setGalleryRowHiddenReason(row, reason, hidden) {
    if (!(row instanceof HTMLElement)) {
        return;
    }
    if (reason === 'filter') {
        row.dataset.hiddenByFilter = hidden ? '1' : '0';
    }
    if (reason === 'tree') {
        row.dataset.hiddenByTree = hidden ? '1' : '0';
    }
    row.hidden = row.dataset.hiddenByFilter === '1' || row.dataset.hiddenByTree === '1';
}
