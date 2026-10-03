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

const adminI18nCatalogs = new Map();
const adminI18nLoads = new Map();

/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @return {string} Browser-facing translated text.
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
 * Load the translated Admin catalog for one injected Admin scope.
 *
 * @param {{language?: string, url?: string}|Element} scopeOrElement Scoped Admin metadata or an element inside the scope.
 * @return {Promise<void>} Resolves when the matching Admin catalog is cached.
 */
export function loadAdminI18nScope(scopeOrElement) {
    const scope = scopeOrElement instanceof Element
        ? scopeOrElement.closest('[data-admin-i18n-scope]')
        : null;
    const language = String(scope?.dataset.adminI18nLanguage || scopeOrElement?.language || '');
    const url = String(scope?.dataset.adminI18nUrl || scopeOrElement?.url || '');
    if (language === '' || url === '') return Promise.resolve();
    const safeUrl = new URL(url, window.location.href);
    if (safeUrl.origin !== window.location.origin) return Promise.reject(new Error('Admin translations must use the current site.'));
    if (adminI18nCatalogs.has(language)) return Promise.resolve();
    const pending = adminI18nLoads.get(language);
    if (pending) return pending;

    const request = fetch(safeUrl.toString(), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
        .then(/** Validate and cache the requested Admin catalog.
         * @param {Response} response Same-origin JSON response.
         * @return {Promise<void>} Resolves after catalog caching.
         */ async (response) => {
            if (!response.ok) throw new Error(`Admin translations unavailable (${response.status}).`);
            const payload = await response.json();
            if (String(payload?.language || '') !== language || !payload?.strings || typeof payload.strings !== 'object') {
                throw new Error('Admin translations returned an invalid catalog.');
            }
            adminI18nCatalogs.set(language, payload.strings);
        })
        .catch(/** Preserve catalog fetch failures for the caller's fallback path.
         * @param {Error} error Catalog request or validation failure.
         * @return {never} Rethrows the failure.
         */ (error) => {
            throw error;
        }).finally(/** Release the in-flight cache entry after settlement.
         * @return {void} Removes the settled catalog request.
         */ () => {
            adminI18nLoads.delete(language);
        });
    adminI18nLoads.set(language, request);
    return request;
}

/**
 * Return whether an injected Admin scope has finished loading its language catalog.
 * @param {Element} element Candidate element in the mounted DOM.
 * @return {boolean} Whether its Admin language is ready.
 */
export function adminI18nScopeReady(element) {
    const scope = element.closest('[data-admin-i18n-scope]');
    const language = String(scope?.dataset.adminI18nLanguage || '');
    return scope === null || scope.dataset.adminI18nFailed === '1' || (language !== '' && adminI18nCatalogs.has(language));
}

/**
 * Return a translated string using the nearest injected Admin language scope when present.
 * @param {Element|null} element Element whose nearest Admin scope owns the string.
 * @param {string} key Translation key.
 * @param {string} fallback English fallback.
 * @param {Object<string,string|number>} parameters Placeholder values.
 * @return {string} Browser-facing translated text.
 */
export function i18nForElement(element, key, fallback, parameters = {}) {
    const scope = element?.closest('[data-admin-i18n-scope]');
    const language = String(scope?.dataset.adminI18nLanguage || '');
    const catalog = language !== '' ? adminI18nCatalogs.get(language) : null;
    let text = scope
        ? (catalog && typeof catalog[key] === 'string' ? catalog[key] : fallback)
        : i18n(key, fallback);
    Object.entries(parameters).forEach(/** Replace one named interpolation token.
     * @param {[string,string|number]} entry Placeholder name and value.
     * @return {void} Updates the translated text.
     */ ([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}

/**
 * Escape HTML text before inserting generated success markup.
 *
 * @param {string} value Raw value.
 * @return {string} Escaped text.
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
 * @return {string} Escaped attribute value.
 */
export function escapeHtmlAttribute(value) {
    return escapeHtmlText(value).replace(/`/g, '&#096;');
}

/**
 * Handles admin url with params behavior for the gallery UI.
 *
 * @param {*} params Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
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
/**
 * Return whether thumbnail submission.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLFormElement} form Form value.
 * @param {*} submitter Submitter value.
 * @return {*} Result value for the caller.
 */
export function isThumbnailSubmission(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || '';
    // Variable `selectedAction` stores this steps working value.
    const selectedAction = form.querySelector('select[name="action"]')?.value || '';
    return action.includes('admin_create_thumbnails') || selectedAction === 'thumbs';
}

// Function `thumbnailEndpoint` executes this focused behavior.
/**
 * Handle thumbnail endpoint.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLFormElement} form Form value.
 * @param {*} submitter Submitter value.
 * @return {*} Result value for the caller.
 */
export function thumbnailEndpoint(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || window.location.href;
    // Variable `endpoint` stores this steps working value.
    const endpoint = new URL(action, window.location.href);
    endpoint.searchParams.set('page', 'admin_create_thumbnails');
    return endpoint.toString();
}

// Function `ensureThumbnailProgress` executes this focused behavior.
/**
 * Ensure thumbnail progress.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLFormElement} form Form value.
 * @return {*} Result value for the caller.
 */
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
/**
 * Create thumbnail progress.
 *
 * Used by browser-side gallery behavior.
 *
 * @return {*} Result value for the caller.
 */
export function createThumbnailProgress() {
    // Variable `progress` stores this steps working value.
    const progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.thumbnailProgress = 'true';
    progress.innerHTML = '<progress class="thumbnail-progress-bar" data-thumbnail-progress-fill value="0" max="100"></progress><p class="muted" data-thumbnail-progress-text></p><p class="muted upload-progress-metrics" data-upload-progress-metrics hidden></p><div class="upload-progress-log" data-upload-progress-log hidden></div>';
    return progress;
}

// Function `updateThumbnailProgress` executes this focused behavior.
/**
 * Update thumbnail progress.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} progress Progress value.
 * @param {*} processed Processed value.
 * @param {number} total Total value.
 * @param {*} created Created value.
 * @param {*} skipped Skipped value.
 * @param {string} label Label value.
 */
export function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
    progress.hidden = false;
    // Variable `percent` stores this steps working value.
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
    progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
    progress.querySelector('[data-thumbnail-progress-text]').textContent =
        `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
}

// Function `updateBasicProgress` executes this focused behavior.
/**
 * Update basic progress.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} progress Progress value.
 * @param {*} percent Percent value.
 * @param {string} label Label value.
 */
export function updateBasicProgress(progress, percent, label) {
    progress.hidden = false;
    progress.querySelector('[data-thumbnail-progress-fill]').value = Math.max(0, Math.min(100, percent));
    progress.querySelector('[data-thumbnail-progress-text]').textContent = label;
}


// Function `updateUploadProgressMetrics` executes this focused behavior.
/**
 * Update detailed upload counters under the main progress label.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} progress Progress element value.
 * @param {string} label Metrics label.
 */
export function updateUploadProgressMetrics(progress, label) {
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    const metrics = progress.querySelector('[data-upload-progress-metrics]');
    if (!(metrics instanceof HTMLElement)) {
        return;
    }
    metrics.hidden = String(label || '').trim() === '';
    metrics.textContent = String(label || '');
}

// Function `appendUploadProgressLog` executes this focused behavior.
/**
 * Append one timestamped line to the upload progress log.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} progress Progress element value.
 * @param {string} message Log message.
 */
export function appendUploadProgressLog(progress, message) {
    if (!(progress instanceof HTMLElement)) {
        return;
    }
    const log = progress.querySelector('[data-upload-progress-log]');
    if (!(log instanceof HTMLElement)) {
        return;
    }
    const line = document.createElement('div');
    const timestamp = new Date().toLocaleTimeString();
    line.textContent = `[${timestamp}] ${message}`;
    log.append(line);
    log.hidden = false;
    while (log.children.length > 80) {
        log.firstElementChild?.remove();
    }
    log.scrollTop = log.scrollHeight;
}

// Function `setGalleryRowHiddenReason` executes this focused behavior.
/**
 * Set gallery row hidden reason.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {*} row Row data.
 * @param {*} reason Reason value.
 * @param {*} hidden Hidden value.
 */
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
