/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-operations.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides client-side behavior for the PHP Gallery user interface.
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
 *   2026-05-04
 */

/**
 * Admin operations
 *
 * Contains heavier admin-only workflows: refresh progress, uploads, thumbnail jobs, filters, tree controls, status forms, and image reordering.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupExample } from './gallery-modules/example.js';
 * setupExample();
 */

const legacyAdminTabHashes = new Map([
    ['#admin-galleries', '#admin-tab-galleries'],
    ['#admin-ordering', '#admin-tab-galleries'],
    ['#admin-thumbnails', '#admin-tab-maintenance'],
    ['#admin-cache', '#admin-tab-maintenance'],
    ['#admin-migrations', '#admin-tab-maintenance'],
    ['#admin-appearance', '#admin-tab-overview'],
]);

// Function `normalizedAdminTabHash` executes this focused behavior.
function normalizedAdminTabHash(hash) {
    if (!hash) {
        return '';
    }
    return legacyAdminTabHashes.get(hash) || hash;
}

// Function `setupAdminTabs` executes this focused behavior.
export function setupAdminTabs() {
    document.querySelectorAll('[data-admin-tabs]').forEach((tabsRoot) => {
        // tabs stores state or configuration for the admin tab flow.
        const tabs = Array.from(tabsRoot.querySelectorAll('[role="tab"][data-admin-tab-target]'));
        if (!tabs.length) {
            return;
        }
        // panels stores state or configuration for the admin tab flow.
        const panels = tabs
            .map((tab) => document.getElementById(tab.dataset.adminTabTarget || ''))
            .filter((panel) => panel instanceof HTMLElement && panel.matches('[data-admin-tab-panel]'));
        if (!panels.length) {
            return;
        }

        // activateTab stores behavior for selecting one tab and hiding the other panels.
        const activateTab = (targetId, options = {}) => {
            // targetPanel stores the panel selected by the current hash or click.
            const targetPanel = panels.find((panel) => panel.id === targetId) || panels[0];
            if (!targetPanel) {
                return;
            }
            tabs.forEach((tab) => {
                // isSelected stores whether this control owns the selected panel.
                const isSelected = tab.dataset.adminTabTarget === targetPanel.id;
                tab.classList.toggle('is-active', isSelected);
                tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                tab.setAttribute('tabindex', isSelected ? '0' : '-1');
            });
            panels.forEach((panel) => {
                // isSelected stores whether this panel should be visible.
                const isSelected = panel.id === targetPanel.id;
                panel.hidden = !isSelected;
                panel.classList.toggle('is-active', isSelected);
            });
            if (options.focusTab) {
                tabs.find((tab) => tab.dataset.adminTabTarget === targetPanel.id)?.focus();
            }
            if (options.updateHash) {
                const nextHash = `#${targetPanel.id}`;
                if (window.location.hash !== nextHash) {
                    window.history.pushState(null, '', nextHash);
                }
            }
        };

        // activeHash stores the hash that should select the initial tab.
        const activeHash = normalizedAdminTabHash(window.location.hash);
        if (activeHash && activeHash !== window.location.hash) {
            window.history.replaceState(null, '', activeHash);
        }
        activateTab((activeHash || '').replace(/^#/, '') || tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.adminTabTarget || tabs[0].dataset.adminTabTarget || '');

        tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                // targetId stores the panel id requested by the clicked tab.
                const targetId = tab.dataset.adminTabTarget || '';
                if (!targetId || !panels.some((panel) => panel.id === targetId)) {
                    return;
                }
                event.preventDefault();
                activateTab(targetId, {updateHash: true});
            });
            tab.addEventListener('keydown', (event) => {
                // currentIndex stores the focused tab position.
                const currentIndex = tabs.indexOf(tab);
                if (currentIndex < 0) {
                    return;
                }
                // nextIndex stores the tab position selected by the keyboard.
                let nextIndex = currentIndex;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (currentIndex + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                } else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    activateTab(tab.dataset.adminTabTarget || '', {updateHash: true});
                    return;
                } else {
                    return;
                }
                event.preventDefault();
                activateTab(tabs[nextIndex].dataset.adminTabTarget || '', {updateHash: true, focusTab: true});
            });
        });

        // handleHashNavigation stores behavior shared by hashchange and history traversal.
        const handleHashNavigation = () => {
            // hash stores the normalized browser hash after navigation.
            const hash = normalizedAdminTabHash(window.location.hash);
            if (!hash) {
                return;
            }
            if (hash !== window.location.hash) {
                window.history.replaceState(null, '', hash);
            }
            activateTab(hash.replace(/^#/, ''));
        };

        window.addEventListener('hashchange', handleHashNavigation);
        window.addEventListener('popstate', handleHashNavigation);
    });
}

// Function `setupGalleryRefreshProgress` executes this focused behavior.
export function setupGalleryRefreshProgress() {
    document.querySelectorAll('[data-refresh-galleries-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === '1') {
                return;
            }
            event.preventDefault();
            form.dataset.submitting = '1';
            // Variable `button` stores this steps working value.
            const button = form.querySelector('button[type="submit"], input[type="submit"]');
            if (button) {
                button.disabled = true;
                if ('value' in button && button.tagName === 'INPUT') {
                    button.value = 'Scanning...';
                } else {
                    button.textContent = 'Scanning...';
                }
            }
            // progress stores state or configuration for the gallery front-end flow.
            const progress = ensureGalleryRefreshProgress(form);
            progress.hidden = false;
            requestAnimationFrame(() => {
                setTimeout(() => HTMLFormElement.prototype.submit.call(form), 40);
            });
        });
    });
}

// Function `ensureGalleryRefreshProgress` executes this focused behavior.
function ensureGalleryRefreshProgress(form) {
    // progress stores state or configuration for the gallery front-end flow.
    let progress = document.querySelector('[data-gallery-refresh-progress]');
    if (progress) {
        return progress;
    }
    progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.galleryRefreshProgress = 'true';
    progress.innerHTML = '<progress class="thumbnail-progress-bar"></progress><p class="muted">Scanning existing galleries and checking for new gallery folders...</p>';
    // target stores state or configuration for the gallery front-end flow.
    const target = form.closest('.hero') || form;
    target.insertAdjacentElement('afterend', progress);
    return progress;
}

// Function `setupGalleryUploadProgress` executes this focused behavior.
export function setupGalleryUploadProgress() {
    document.querySelectorAll('[data-gallery-upload-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            runGalleryUpload(form);
        });
    });
}

// Function `runGalleryUpload` executes this focused behavior.
async function runGalleryUpload(form) {
    // progress stores state or configuration for the gallery front-end flow.
    const progress = ensureThumbnailProgress(form);
    // buttons stores state or configuration for the gallery front-end flow.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    try {
        // createThumbnails stores state or configuration for the gallery front-end flow.
        const createThumbnails = Boolean(form.querySelector('input[name="create_thumbnails"]')?.checked);
        // result stores state or configuration for the gallery front-end flow.
        const result = await runGalleryUploadFiles(form, progress, createThumbnails);
        if (createThumbnails) {
            updateThumbnailProgress(progress, result.uploaded || 0, result.total_files || 0, result.thumbnails || 0, result.thumbnail_skipped || 0, 'Upload and thumbnail job complete.');
        } else {
            updateBasicProgress(progress, 100, `Uploaded ${result.uploaded || 0} images. Scanning complete.`);
        }
        window.location.href = result.redirect_url || adminUrlWithParams({uploaded: result.uploaded || 0, scanned: result.scanned || 0, thumbnails: result.thumbnails || 0});
    } catch (error) {
        updateBasicProgress(progress, 100, error.message || 'Upload failed.');
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Handles selected gallery upload files behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function selectedGalleryUploadFiles(form) {
    // fileInput stores state or configuration for the gallery front-end flow.
    const fileInput = form.querySelector('input[type="file"][name="images[]"]');
    if (!(fileInput instanceof HTMLInputElement) || !fileInput.files || fileInput.files.length === 0) {
        return [];
    }
    return Array.from(fileInput.files);
}

/**
 * Handles gallery upload base body behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function galleryUploadBaseBody(form) {
    // body stores state or configuration for the gallery front-end flow.
    const body = new FormData();
    Array.from(form.elements).forEach((field) => {
        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
            return;
        }
        if (!field.name || field.disabled || field.type === 'file') {
            return;
        }
        if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
            return;
        }
        body.append(field.name, field.value);
    });
    body.set('ajax', '1');
    return body;
}

/**
 * Handles clone gallery upload body behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} files Value supplied by the caller or event context.
 * @param {*} galleryId Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function cloneGalleryUploadBody(form, files, galleryId) {
    // body stores state or configuration for the gallery front-end flow.
    const body = galleryUploadBaseBody(form);
    if (galleryId > 0) {
        body.set('upload_mode', 'existing');
        body.set('gallery_id', String(galleryId));
    }
    files.forEach((file) => {
        body.append('images[]', file, file.name);
    });
    return body;
}

/**
 * Handles run gallery upload files behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} progress Value supplied by the caller or event context.
 * @param {*} createThumbnails Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runGalleryUploadFiles(form, progress, createThumbnails) {
    // files stores state or configuration for the gallery front-end flow.
    const files = selectedGalleryUploadFiles(form);
    if (files.length === 0) {
        throw new Error('Choose at least one image to upload.');
    }

    // uploaded stores state or configuration for the gallery front-end flow.
    let uploaded = 0;
    // scanned stores state or configuration for the gallery front-end flow.
    let scanned = 0;
    // thumbnails stores state or configuration for the gallery front-end flow.
    let thumbnails = 0;
    // thumbnailSkipped stores state or configuration for the gallery front-end flow.
    let thumbnailSkipped = 0;
    // galleryId stores state or configuration for the gallery front-end flow.
    let galleryId = Number(form.querySelector('select[name="gallery_id"]')?.value || 0);
    // redirectUrl stores state or configuration for the gallery front-end flow.
    let redirectUrl = '';
    // galleryIds stores state or configuration for the gallery front-end flow.
    const galleryIds = [];

    for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
        // file stores state or configuration for the gallery front-end flow.
        const file = files[fileIndex];
        // humanIndex stores state or configuration for the gallery front-end flow.
        const humanIndex = fileIndex + 1;
        updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
        // uploadResult stores state or configuration for the gallery front-end flow.
        const uploadResult = await sendGalleryUploadChunk(form, cloneGalleryUploadBody(form, [file], galleryId), (event) => {
            if (!event.lengthComputable) {
                updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
                return;
            }
            // completedPart stores state or configuration for the gallery front-end flow.
            const completedPart = fileIndex / files.length;
            // currentPart stores state or configuration for the gallery front-end flow.
            const currentPart = (event.loaded / event.total) / files.length;
            updateBasicProgress(progress, Math.round((completedPart + currentPart) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
        });

        if (!galleryId) {
            galleryId = Number(uploadResult.gallery_id || 0);
        }
        if (galleryId && !galleryIds.includes(galleryId)) {
            galleryIds.push(galleryId);
        }
        uploaded += Number(uploadResult.uploaded || 0);
        scanned += Number(uploadResult.scanned || 0);
        redirectUrl = uploadResult.redirect_url || redirectUrl;

        if (createThumbnails) {
            // imageIds stores state or configuration for the gallery front-end flow.
            const imageIds = Array.isArray(uploadResult.image_ids) ? uploadResult.image_ids : [];
            // thumbResult stores state or configuration for the gallery front-end flow.
            const thumbResult = await runUploadedImageThumbnailJob(form, progress, imageIds, humanIndex, files.length, file.name, thumbnails, thumbnailSkipped);
            thumbnails += Number(thumbResult.created || 0);
            thumbnailSkipped += Number(thumbResult.skipped || 0);
        }
    }

    return {
        ok: true,
        gallery_id: galleryId,
        gallery_ids: galleryIds.length > 0 ? galleryIds : (galleryId ? [galleryId] : []),
        uploaded,
        scanned,
        thumbnails,
        thumbnail_skipped: thumbnailSkipped,
        total_files: files.length,
        redirect_url: appendUploadResultParams(redirectUrl, uploaded, scanned, thumbnails),
    };
}

/**
 * Handles append upload result params behavior for the gallery UI.
 * @param {*} urlValue Value supplied by the caller or event context.
 * @param {*} uploaded Value supplied by the caller or event context.
 * @param {*} scanned Value supplied by the caller or event context.
 * @param {*} thumbnails Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function appendUploadResultParams(urlValue, uploaded, scanned, thumbnails) {
    // url stores state or configuration for the gallery front-end flow.
    const url = new URL(urlValue || window.location.href, window.location.href);
    url.searchParams.set('uploaded', String(uploaded));
    url.searchParams.set('scanned', String(scanned));
    url.searchParams.set('thumbnails', String(thumbnails));
    return url.toString();
}

/**
 * Handles send gallery upload chunk behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} body Value supplied by the caller or event context.
 * @param {*} progressHandler Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function sendGalleryUploadChunk(form, body, progressHandler) {
    return new Promise((resolve, reject) => {
        // xhr stores state or configuration for the gallery front-end flow.
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', progressHandler);
        xhr.addEventListener('load', () => {
            try {
                // contentType stores state or configuration for the gallery front-end flow.
                const contentType = (xhr.getResponseHeader('Content-Type') || '').toLowerCase();
                // responseText stores state or configuration for the gallery front-end flow.
                const responseText = xhr.responseText || '';
                if (!contentType.includes('application/json')) {
                    // snippet stores state or configuration for the gallery front-end flow.
                    const snippet = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
                    if (snippet.includes('Maximum number of allowable file uploads exceeded')) {
                        throw new Error('The server refused too many files in one request. Upload batching is enabled, but this server returned the PHP upload-limit warning before processing the request.');
                    }
                    throw new Error(snippet.startsWith('<') ? 'Server returned HTML instead of JSON. Check the PHP error log for the exact upload error.' : 'Server returned an unexpected response.');
                }
                // result stores state or configuration for the gallery front-end flow.
                const result = JSON.parse(responseText || '{}');
                if (xhr.status < 200 || xhr.status >= 300 || !result.ok) {
                    throw new Error(result.error || 'Upload failed.');
                }
                resolve(result);
            } catch (error) {
                reject(error);
            }
        });
        xhr.addEventListener('error', () => {
            reject(new Error('Upload failed.'));
        });
        xhr.send(body);
    });
}

/**
 * Handles run uploaded image thumbnail job behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} progress Value supplied by the caller or event context.
 * @param {*} imageIds Value supplied by the caller or event context.
 * @param {*} fileIndex Value supplied by the caller or event context.
 * @param {*} totalFiles Value supplied by the caller or event context.
 * @param {*} filename Value supplied by the caller or event context.
 * @param {*} createdBefore Value supplied by the caller or event context.
 * @param {*} skippedBefore Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runUploadedImageThumbnailJob(form, progress, imageIds, fileIndex, totalFiles, filename, createdBefore, skippedBefore) {
    if (!imageIds.length) {
        updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore, skippedBefore, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. No database image record was returned for thumbnails.`);
        return {created: 0, skipped: 0};
    }

    // offset stores state or configuration for the gallery front-end flow.
    let offset = 0;
    // total stores state or configuration for the gallery front-end flow.
    let total = 0;
    // created stores state or configuration for the gallery front-end flow.
    let created = 0;
    // skipped stores state or configuration for the gallery front-end flow.
    let skipped = 0;
    while (true) {
        // body stores state or configuration for the gallery front-end flow.
        const body = new FormData();
        body.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
        body.set('ajax', '1');
        body.set('offset', String(offset));
        body.set('batch_size', '1');
        body.set('gallery_id', String(Number(form.querySelector('select[name="gallery_id"]')?.value || 0)));
        imageIds.forEach((imageId) => {
            body.append('image_ids[]', String(imageId));
        });
        // response stores state or configuration for the gallery front-end flow.
        const response = await fetch(thumbnailEndpoint(form, null), {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        if (!response.ok) {
            throw new Error('Thumbnail request failed.');
        }
        // result stores state or configuration for the gallery front-end flow.
        const result = await response.json();
        total = result.total || imageIds.length;
        offset = result.next_offset || 0;
        created += result.created || 0;
        skipped += result.skipped || 0;
        updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. Creating thumbnails ${Math.min(offset, total)} of ${total}...`);
        if (result.done) {
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Finished ${fileIndex} of ${totalFiles}: ${filename}`);
            return {created, skipped};
        }
    }
}

// Function `setupThumbnailProgress` executes this focused behavior.
export function setupThumbnailProgress() {
    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = event.target.closest('[data-create-all-thumbnails]');
        if (!button) {
            return;
        }
        // Variable `form` stores this steps working value.
        const form = document.querySelector('[data-gallery-bulk-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
            checkbox.checked = true;
        });
        form.querySelectorAll('input[type="checkbox"][data-select-all="gallery_ids[]"]').forEach((checkbox) => {
            checkbox.checked = true;
        });
        // Variable `action` stores this steps working value.
        const action = form.querySelector('select[name="action"]');
        if (action) {
            action.value = 'thumbs';
        }
        button.disabled = true;
        try {
            await runThumbnailJob(form, null);
        } finally {
            button.disabled = false;
        }
    });

    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !isThumbnailSubmission(form, event.submitter)) {
            return;
        }
        event.preventDefault();
        runThumbnailJob(form, event.submitter);
    });

    document.addEventListener('submit', (event) => {
        // form stores state or configuration for the gallery front-end flow.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-import-galleries-form]')) {
            return;
        }
        if (!form.querySelector('input[name="create_thumbnails"]')?.checked) {
            return;
        }
        event.preventDefault();
        runImportWithThumbnailProgress(form);
    });
}

/**
 * Handles run import with thumbnail progress behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runImportWithThumbnailProgress(form) {
    // progress stores state or configuration for the gallery front-end flow.
    const progress = ensureThumbnailProgress(form);
    // buttons stores state or configuration for the gallery front-end flow.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    updateThumbnailProgress(progress, 0, 0, 0, 0, 'Importing selected galleries...');
    try {
        // importBody stores state or configuration for the gallery front-end flow.
        const importBody = new FormData(form);
        importBody.set('ajax', '1');
        // importResponse stores state or configuration for the gallery front-end flow.
        const importResponse = await fetch(form.action || window.location.href, {
            method: 'POST',
            body: importBody,
            headers: {'Accept': 'application/json'},
        });
        if (!importResponse.ok) {
            throw new Error('Import request failed.');
        }
        // importResult stores state or configuration for the gallery front-end flow.
        const importResult = await importResponse.json();
        // galleryIds stores state or configuration for the gallery front-end flow.
        const galleryIds = Array.isArray(importResult.gallery_ids) ? importResult.gallery_ids : [];
        if (galleryIds.length === 0) {
            updateThumbnailProgress(progress, 0, 0, 0, 0, `Import complete. ${importResult.imported || 0} galleries imported, ${importResult.scanned || 0} images scanned.`);
            window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: 0});
            return;
        }
        // offset stores state or configuration for the gallery front-end flow.
        let offset = 0;
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        // created stores state or configuration for the gallery front-end flow.
        let created = 0;
        // skipped stores state or configuration for the gallery front-end flow.
        let skipped = 0;
        while (true) {
            // thumbBody stores state or configuration for the gallery front-end flow.
            const thumbBody = new FormData();
            thumbBody.set('csrf_token', form.querySelector('input[name="csrf_token"]')?.value || '');
            thumbBody.set('ajax', '1');
            thumbBody.set('offset', String(offset));
            thumbBody.set('batch_size', '6');
            galleryIds.forEach((galleryId) => {
                thumbBody.append('gallery_ids[]', String(galleryId));
            });
            // response stores state or configuration for the gallery front-end flow.
            const response = await fetch(thumbnailEndpoint(form, null), {
                method: 'POST',
                body: thumbBody,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Thumbnail request failed.');
            }
            // result stores state or configuration for the gallery front-end flow.
            const result = await response.json();
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, `Imported ${importResult.imported || 0} galleries, scanned ${importResult.scanned || 0} images. Creating thumbnails...`);
            if (result.done) {
                updateThumbnailProgress(progress, total, total, created, skipped, 'Import and thumbnail job complete.');
                window.location.href = adminUrlWithParams({imported: importResult.imported || 0, scanned: importResult.scanned || 0, thumbnails: created});
                break;
            }
        }
    } catch (error) {
        updateThumbnailProgress(progress, 0, 0, 0, 0, 'Import or thumbnail job failed.');
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Handles admin url with params behavior for the gallery UI.
 * @param {*} params Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
function adminUrlWithParams(params) {
    // url stores state or configuration for the gallery front-end flow.
    const url = new URL(window.location.href);
    url.search = '?page=admin';
    Object.entries(params).forEach(([key, value]) => {
        url.searchParams.set(key, String(value));
    });
    return url.toString();
}

// Function `isThumbnailSubmission` executes this focused behavior.
function isThumbnailSubmission(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || '';
    // Variable `selectedAction` stores this steps working value.
    const selectedAction = form.querySelector('select[name="action"]')?.value || '';
    return action.includes('admin_create_thumbnails') || selectedAction === 'thumbs';
}

// Function `thumbnailEndpoint` executes this focused behavior.
function thumbnailEndpoint(form, submitter) {
    // Variable `action` stores this steps working value.
    const action = submitter?.formAction || form.action || window.location.href;
    // Variable `endpoint` stores this steps working value.
    const endpoint = new URL(action, window.location.href);
    endpoint.searchParams.set('page', 'admin_create_thumbnails');
    return endpoint.toString();
}

/**
 * Handles run thumbnail job behavior for the gallery UI.
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} submitter Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
 */
async function runThumbnailJob(form, submitter) {
    // Variable `progress` stores this steps working value.
    const progress = ensureThumbnailProgress(form);
    // Variable `buttons` stores this steps working value.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    // Variable `offset` stores this steps working value.
    let offset = 0;
    // Variable `total` stores this steps working value.
    let total = 0;
    // Variable `created` stores this steps working value.
    let created = 0;
    // Variable `skipped` stores this steps working value.
    let skipped = 0;
    updateThumbnailProgress(progress, 0, 0, created, skipped, 'Preparing thumbnails...');
    try {
        while (true) {
            // Variable `body` stores this steps working value.
            const body = new FormData(form);
            if (submitter?.name) {
                body.set(submitter.name, submitter.value);
            }
            body.set('ajax', '1');
            body.set('offset', String(offset));
            body.set('batch_size', '6');
            // Variable `response` stores this steps working value.
            const response = await fetch(thumbnailEndpoint(form, submitter), {
                method: 'POST',
                body,
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                throw new Error('Thumbnail request failed.');
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            total = result.total || 0;
            offset = result.next_offset || 0;
            created += result.created || 0;
            skipped += result.skipped || 0;
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, 'Creating thumbnails...');
            if (result.done) {
                updateThumbnailProgress(progress, total, total, created, skipped, 'Thumbnail job complete.');
                break;
            }
        }
    } catch (error) {
        updateThumbnailProgress(progress, offset, total, created, skipped, 'Thumbnail job failed.');
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

// Function `setupPictureGame` executes this focused behavior.
export function setupPictureGame() {
    // Variable `game` stores this steps working value.
    const game = document.querySelector('[data-picture-game]');
    if (!game) {
        return;
    }
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }
        // Variable `side` stores this steps working value.
        const side = event.key === 'ArrowLeft' ? 'left' : 'right';
        // Variable `button` stores this steps working value.
        const button = game.querySelector(`[data-picture-game-choice="${side}"]`);
        if (button) {
            event.preventDefault();
            button.click();
        }
    });
}

// Function `setupAdminLogStatusForms` executes this focused behavior.
export function setupAdminLogStatusForms() {
    document.querySelectorAll('[data-admin-log-status-select]').forEach((select) => {
        if (select.dataset.adminLogStatusReady === '1') {
            return;
        }
        select.dataset.adminLogStatusReady = '1';
        // Variable `originalValue` stores this steps working value.
        let originalValue = select.value;
        select.addEventListener('change', async () => {
            // Variable `body` stores this steps working value.
            const body = new FormData();
            body.set('csrf_token', select.dataset.csrfToken || '');
            body.set('action', 'single');
            body.set('log_id', select.dataset.logId || '');
            body.set('status', select.value);
            // Variable `row` stores this steps working value.
            const row = select.closest('[data-admin-log-row]');
            // Variable `state` stores this steps working value.
            const state = row ? row.querySelector('[data-admin-log-state]') : null;
            select.disabled = true;
            try {
                // Variable `response` stores this steps working value.
                const response = await fetch(select.dataset.updateUrl || window.location.href, {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'},
                });
                if (!response.ok) {
                    select.value = originalValue;
                    return;
                }
                // Variable `result` stores this steps working value.
                const result = await response.json();
                if (!result.ok) {
                    select.value = originalValue;
                    return;
                }
                originalValue = select.value;
                if (state) {
                    state.textContent = result.label || select.options[select.selectedIndex]?.textContent || select.value;
                }
            } catch {
                select.value = originalValue;
            } finally {
                select.disabled = false;
            }
        });
    });
}

// Function `setupAdminLogLiveFilters` executes this focused behavior.
export function setupAdminLogLiveFilters() {
    // Variable `form` stores this steps working value.
    const form = document.querySelector('[data-admin-log-filter-form]');
    // Variable `tbody` stores this steps working value.
    const tbody = document.querySelector('[data-admin-log-tbody]');
    if (!form || !tbody) {
        return;
    }
    // Variable `countLabel` stores this steps working value.
    const countLabel = document.querySelector('[data-admin-log-count]');
    // Variable `stateLabel` stores this steps working value.
    const stateLabel = document.querySelector('[data-admin-log-live-state]');
    // Variable `emptyContainer` stores this steps working value.
    let emptyContainer = document.querySelector('[data-admin-log-empty]');
    // Variable `timeSortLink` stores this steps working value.
    const timeSortLink = document.querySelector('[data-admin-log-time-sort-link]');
    // Variable `searchInput` stores this steps working value.
    const searchInput = form.querySelector('[data-admin-log-live-search]');
    // Variable `debounceHandle` stores this steps working value.
    let debounceHandle = 0;
    // Variable `activeRequest` stores this steps working value.
    let activeRequest = null;

    // Function `setLiveState` writes compact search progress text for screen readers and admins.
    const setLiveState = (message) => {
        if (stateLabel) {
            stateLabel.textContent = message;
        }
    };

    // Function `buildUrl` creates the filtered request URL used by normal and live requests.
    const buildUrl = (includeAjax = true) => {
        // Variable `params` stores serialized filter controls from the visible form.
        const params = new URLSearchParams(new FormData(form));
        params.set('page', 'admin_logs');
        if (includeAjax) {
            params.set('ajax', '1');
        } else {
            params.delete('ajax');
        }
        for (const [key, value] of Array.from(params.entries())) {
            if (value === '') {
                params.delete(key);
            }
        }
        return `${form.getAttribute('action') || window.location.pathname}?${params.toString()}`;
    };

    // Function `ensureEmptyContainer` creates the no-results message holder when live filtering needs it.
    const ensureEmptyContainer = () => {
        if (emptyContainer) {
            return emptyContainer;
        }
        // Variable `resultsPanel` stores the surrounding admin log results panel.
        const resultsPanel = document.querySelector('[data-admin-log-results]');
        emptyContainer = document.createElement('div');
        emptyContainer.dataset.adminLogEmpty = '';
        if (resultsPanel) {
            const tableWrap = resultsPanel.querySelector('.admin-log-table-wrap');
            resultsPanel.insertBefore(emptyContainer, tableWrap || null);
        }
        return emptyContainer;
    };

    // Function `refreshLogs` fetches matching rows without a full page navigation.
    const refreshLogs = async () => {
        if (activeRequest) {
            activeRequest.abort();
        }
        activeRequest = new AbortController();
        setLiveState('Searching...');
        try {
            // Variable `response` stores this steps working value.
            const response = await fetch(buildUrl(true), {
                headers: {'Accept': 'application/json'},
                signal: activeRequest.signal,
            });
            if (!response.ok) {
                setLiveState('Live search failed. Use Apply filters.');
                return;
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            if (!result.ok) {
                setLiveState('Live search failed. Use Apply filters.');
                return;
            }
            tbody.innerHTML = result.rows_html || '';
            setupAdminLogStatusForms();
            if (countLabel) {
                countLabel.textContent = `(${Number(result.count || 0)} shown)`;
            }
            const noResults = Number(result.count || 0) === 0;
            const empty = ensureEmptyContainer();
            empty.innerHTML = noResults ? (result.empty_html || '<p>No log entries match the current filters.</p>') : '';
            empty.hidden = !noResults;
            if (timeSortLink) {
                const currentSort = result.time_sort === 'asc' ? 'asc' : 'desc';
                const nextSort = currentSort === 'desc' ? 'asc' : 'desc';
                timeSortLink.dataset.nextSort = nextSort;
                timeSortLink.textContent = `When ${currentSort === 'desc' ? '↓' : '↑'}`;
                const linkUrl = new URL(buildUrl(false), window.location.href);
                linkUrl.searchParams.set('time_sort', nextSort);
                timeSortLink.href = linkUrl.toString();
            }
            window.history.replaceState(null, '', buildUrl(false));
            setLiveState('Updated.');
        } catch (error) {
            if (error.name !== 'AbortError') {
                setLiveState('Live search failed. Use Apply filters.');
            }
        }
    };

    // Function `scheduleRefresh` debounces typing so the server is not queried on every keystroke.
    const scheduleRefresh = () => {
        window.clearTimeout(debounceHandle);
        debounceHandle = window.setTimeout(refreshLogs, 250);
    };

    form.querySelectorAll('[data-admin-log-live-filter]').forEach((control) => {
        control.addEventListener('change', refreshLogs);
    });
    if (searchInput) {
        searchInput.addEventListener('input', scheduleRefresh);
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(debounceHandle);
        refreshLogs();
    });
    if (timeSortLink) {
        timeSortLink.addEventListener('click', (event) => {
            event.preventDefault();
            const sortControl = form.querySelector('select[name="time_sort"]');
            if (sortControl) {
                sortControl.value = timeSortLink.dataset.nextSort === 'asc' ? 'asc' : 'desc';
            }
            refreshLogs();
        });
    }
}

// Function `ensureThumbnailProgress` executes this focused behavior.
function ensureThumbnailProgress(form) {
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
function createThumbnailProgress() {
    // Variable `progress` stores this steps working value.
    const progress = document.createElement('div');
    progress.className = 'thumbnail-progress';
    progress.dataset.thumbnailProgress = 'true';
    progress.innerHTML = '<progress class="thumbnail-progress-bar" data-thumbnail-progress-fill value="0" max="100"></progress><p class="muted" data-thumbnail-progress-text></p>';
    return progress;
}

// Function `updateThumbnailProgress` executes this focused behavior.
function updateThumbnailProgress(progress, processed, total, created, skipped, label) {
    progress.hidden = false;
    // Variable `percent` stores this steps working value.
    const percent = total > 0 ? Math.round((processed / total) * 100) : 100;
    progress.querySelector('[data-thumbnail-progress-fill]').value = percent;
    progress.querySelector('[data-thumbnail-progress-text]').textContent =
        `${label} ${processed}/${total} images checked, ${created} files created, ${skipped} existing files skipped.`;
}

// Function `updateBasicProgress` executes this focused behavior.
function updateBasicProgress(progress, percent, label) {
    progress.hidden = false;
    progress.querySelector('[data-thumbnail-progress-fill]').value = Math.max(0, Math.min(100, percent));
    progress.querySelector('[data-thumbnail-progress-text]').textContent = label;
}

// Function `setGalleryRowHiddenReason` executes this focused behavior.
function setGalleryRowHiddenReason(row, reason, hidden) {
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

// Function `setupAdminGalleryFilters` executes this focused behavior.
export function setupAdminGalleryFilters() {
    // Variable `filter` stores this steps working value.
    const filter = document.querySelector('[data-gallery-visibility-filter]');
    if (!(filter instanceof HTMLSelectElement)) {
        return;
    }
    // Variable `form` stores this steps working value.
    const form = filter.closest('form');
    // Variable `rows` stores this steps working value.
    const rows = Array.from(document.querySelectorAll('[data-gallery-row]'));
    // Variable `summary` stores this steps working value.
    const summary = document.querySelector('[data-gallery-filter-summary]');
    // Variable `selectAll` stores this steps working value.
    const selectAll = form ? form.querySelector('[data-select-all="gallery_ids[]"]') : null;

    // Function `updateSummary` executes this focused behavior.
    function updateSummary() {
        // displayed stores state or configuration for the gallery front-end flow.
        let displayed = 0;
        // total stores state or configuration for the gallery front-end flow.
        let total = 0;
        // selectedVisibility stores state or configuration for the gallery front-end flow.
        const selectedVisibility = filter.value || 'all';
        rows.forEach((row) => {
            // matchesFilter stores state or configuration for the gallery front-end flow.
            const matchesFilter = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
            if (matchesFilter && row.dataset.hiddenByTree !== '1') {
                total++;
            }
            if (!row.hidden) {
                displayed++;
            }
        });
        if (summary) {
            summary.textContent = `${displayed} / ${total} galleries displayed`;
        }
    }

    // Function `applyFilter` executes this focused behavior.
    function applyFilter() {
        // selectedVisibility stores state or configuration for the gallery front-end flow.
        const selectedVisibility = filter.value || 'all';
        rows.forEach((row) => {
            // A filtered-out row is also unchecked. This prevents a hidden
            // stale selection from being included in the next bulk action.
            const matches = selectedVisibility === 'all' || row.dataset.galleryVisibility === selectedVisibility;
            setGalleryRowHiddenReason(row, 'filter', !matches);
            if (!matches) {
                row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                    checkbox.checked = false;
                });
            }
        });
        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = false;
        }
        updateSummary();
    }

    document.addEventListener('galleryRowsChanged', updateSummary);
    filter.addEventListener('change', applyFilter);
    applyFilter();
}

// Function `setupAdminGalleryTree` executes this focused behavior.
export function setupAdminGalleryTree() {
    // Variable `table` stores this steps working value.
    const table = document.querySelector('[data-admin-gallery-order-table]');
    if (!table) {
        return;
    }
    // Variable `csrf` stores this steps working value.
    const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
    // Variable `saveUrl` stores this steps working value.
    const saveUrl = new URL(window.location.href);
    saveUrl.search = '?page=admin_save_gallery_collapse';

    // Function `currentRows` executes this focused behavior.
    function currentRows() {
        return Array.from(table.querySelectorAll('[data-gallery-row]'));
    }

    // Function `rowById` executes this focused behavior.
    function rowById(galleryId) {
        return currentRows().find((candidate) => candidate.dataset.galleryId === String(galleryId)) || null;
    }

    // Function `collapsedIds` executes this focused behavior.
    function collapsedIds() {
        return currentRows().filter((row) => row.classList.contains('is-collapsed')).map((row) => row.dataset.galleryId);
    }

    // Function `save` executes this focused behavior.
    function save() {
        // Variable `body` stores this steps working value.
        const body = new FormData();
        body.set('csrf_token', csrf);
        body.set('collapsed_ids', JSON.stringify(collapsedIds()));
        fetch(saveUrl.toString(), {method: 'POST', body, headers: {'Accept': 'application/json'}});
    }

    /**
     * Ensures the row has the correct expand/collapse control for its current children.
     *
     * Reordering can turn a leaf gallery into a parent or remove the last child
     * from a previous parent without a page reload. The visible control must be
     * rebuilt from current parent_id values before tree visibility is recalculated.
     *
     * @param {HTMLTableRowElement} row Gallery row to refresh.
     * @param {boolean} hasChildren Whether this row currently owns child rows.
     * @returns {void}
     */
    function syncRowToggle(row, hasChildren) {
        const title = row.querySelector('.tree-title');
        if (!title) {
            return;
        }
        const galleryId = row.dataset.galleryId || '';
        const existingToggle = title.querySelector('[data-gallery-toggle]');
        const existingSpacer = title.querySelector('.tree-spacer');
        if (hasChildren) {
            if (existingToggle) {
                existingToggle.textContent = row.classList.contains('is-collapsed') ? '+' : '-';
                existingToggle.setAttribute('aria-expanded', row.classList.contains('is-collapsed') ? 'false' : 'true');
                return;
            }
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'tree-toggle';
            toggle.dataset.galleryToggle = galleryId;
            toggle.textContent = row.classList.contains('is-collapsed') ? '+' : '-';
            toggle.setAttribute('aria-expanded', row.classList.contains('is-collapsed') ? 'false' : 'true');
            existingSpacer?.remove();
            title.insertBefore(toggle, title.firstChild);
            return;
        }
        row.classList.remove('is-collapsed');
        existingToggle?.remove();
        if (!existingSpacer) {
            const spacer = document.createElement('span');
            spacer.className = 'tree-spacer';
            spacer.setAttribute('aria-hidden', 'true');
            title.insertBefore(spacer, title.firstChild);
        }
    }

    /**
     * Rebuilds child-aware toggle controls from current parent_id metadata.
     *
     * @returns {void}
     */
    function syncTreeControls() {
        const childCounts = new Map();
        currentRows().forEach((row) => {
            const parentId = row.dataset.parentId || '0';
            if (parentId === '0') {
                return;
            }
            childCounts.set(parentId, (childCounts.get(parentId) || 0) + 1);
        });
        currentRows().forEach((row) => {
            syncRowToggle(row, (childCounts.get(row.dataset.galleryId || '') || 0) > 0);
        });
    }

    // Function `refreshVisibility` executes this focused behavior.
    function refreshVisibility() {
        syncTreeControls();
        // Variable `rows` stores this steps working value.
        const rows = currentRows();
        // Variable `collapsed` stores this steps working value.
        const collapsed = new Set(collapsedIds().map(String));
        rows.forEach((row) => {
            // Variable `parentId` stores this steps working value.
            let parentId = row.dataset.parentId || '0';
            // Variable `hidden` stores this steps working value.
            let hidden = false;
            while (parentId !== '0') {
                if (collapsed.has(parentId)) {
                    hidden = true;
                    break;
                }
                // Variable `parent` stores this steps working value.
                const parent = rowById(parentId);
                parentId = parent ? (parent.dataset.parentId || '0') : '0';
            }
            setGalleryRowHiddenReason(row, 'tree', hidden);
            if (hidden) {
                row.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]').forEach((checkbox) => {
                    checkbox.checked = false;
                });
            }
        });
        // The master checkbox is a one-shot command for the current view,
        // so any tree visibility change clears it rather than leaving a
        // stale checked state after hidden rows have been unchecked.
        const selectAll = document.querySelector('[data-gallery-bulk-form] [data-select-all="gallery_ids[]"]');
        if (selectAll instanceof HTMLInputElement) {
            selectAll.checked = false;
        }
        document.dispatchEvent(new Event('galleryRowsChanged'));
    }

    table.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-gallery-toggle]') : null;
        if (!button) {
            return;
        }
        // Variable `row` stores this steps working value.
        const row = button.closest('[data-gallery-row]');
        if (!row) {
            return;
        }
        // Variable `collapsed` stores this steps working value.
        const collapsed = !row.classList.contains('is-collapsed');
        row.classList.toggle('is-collapsed', collapsed);
        button.textContent = collapsed ? '+' : '-';
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        refreshVisibility();
        save();
    });

    document.querySelectorAll('[data-gallery-tree-action]').forEach((button) => {
        button.addEventListener('click', () => {
            // Variable `collapse` stores this steps working value.
            const collapse = button.dataset.galleryTreeAction === 'collapse-all';
            syncTreeControls();
            currentRows().forEach((row) => {
                // Variable `toggle` stores this steps working value.
                const toggle = row.querySelector('[data-gallery-toggle]');
                if (!toggle) {
                    return;
                }
                row.classList.toggle('is-collapsed', collapse);
                toggle.textContent = collapse ? '+' : '-';
                toggle.setAttribute('aria-expanded', collapse ? 'false' : 'true');
            });
            refreshVisibility();
            save();
        });
    });

    document.addEventListener('adminGalleryTreeMutated', refreshVisibility);
    refreshVisibility();
}

/**
 * Enables pointer-based nested ordering for the Admin gallery table.
 *
 * The gallery table is a flattened tree. During a drag, this controller moves
 * the selected gallery together with all of its descendants, then uses pointer X
 * movement to calculate the new depth. The server receives the full flattened
 * order and derives each parent_id from that order before updating sort_order
 * and moving folders on disk when the parent changes.
 *
 * @returns {void}
 */
export function setupAdminGalleryReordering() {
    // table stores the reorder-enabled gallery table on the Admin dashboard.
    const table = document.querySelector('[data-admin-gallery-order-table]');
    // toolbar stores endpoint metadata and status UI for the gallery reorder feature.
    const toolbar = document.querySelector('[data-admin-gallery-order-toolbar]');
    // form stores the existing gallery bulk form, reused for CSRF and row scope.
    const form = document.querySelector('[data-admin-gallery-order-form]');
    if (!table || !toolbar || !form) {
        return;
    }

    // body stores the table body containing the flattened gallery tree rows.
    const body = table.querySelector('tbody');
    // status stores the live textual state displayed above the gallery table.
    const status = toolbar.querySelector('[data-admin-gallery-order-status]');
    // reorderUrl stores the server endpoint that persists order and nesting.
    const reorderUrl = toolbar.dataset.reorderUrl || '';
    // csrfInput stores the CSRF token generated by the PHP form helper.
    const csrfInput = form.querySelector('input[name="csrf_token"]');
    if (!body || !reorderUrl || !csrfInput) {
        return;
    }

    // indentWidth stores the horizontal distance that represents one tree level.
    const indentWidth = 28;
    // draggedRows stores the moved root row and all descendant rows.
    let draggedRows = [];
    // draggedHandle stores the gallery-column area that started the drag, so its visual state can be restored.
    let draggedHandle = null;
    // placeholderRow stores the temporary row marking the insertion point.
    let placeholderRow = null;
    // ghostTable stores the fixed-position visual copy that follows the pointer.
    let ghostTable = null;
    // originalSignature stores order and parent values before dragging begins.
    let originalSignature = '';
    // originalDepth stores the depth of the dragged root row before dragging begins.
    let originalDepth = 0;
    // pointerOffsetY stores the pointer distance from the top of the row at drag start.
    let pointerOffsetY = 0;
    // startClientX stores the horizontal pointer coordinate at drag start.
    let startClientX = 0;
    // proposedDepth stores the candidate depth shown by the placeholder.
    let proposedDepth = 0;
    // activePointerId stores the pointer that owns the current drag session.
    let activePointerId = null;
    // activeMouseFallback stores whether classic mouse events are currently driving movement.
    let activeMouseFallback = false;
    // pendingDrag stores a possible drag that has not crossed the movement threshold yet.
    let pendingDrag = null;
    // suppressClickUntil stores a short timestamp window used to stop link clicks after dragging a title area.
    let suppressClickUntil = 0;
    // saveController stores the in-flight request controller so a newer drop can supersede an older save.
    let saveController = null;

    /**
     * Updates the small status label above the gallery order table.
     *
     * @param {string} message Human-readable state shown to the admin.
     * @param {string} state Visual state name used by CSS.
     * @returns {void}
     */
    function setStatus(message, state) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.dataset.state = state;
    }

    /**
     * Converts accidental HTML output from a JSON endpoint into readable admin text.
     *
     * Shared hosting can print PHP warnings as HTML before JSON when display_errors
     * is enabled. The server now buffers that output, but this fallback keeps older
     * cached PHP files from showing raw parser errors to the admin.
     *
     * @param {string} responseText Raw response returned by the reorder endpoint.
     * @returns {string} Friendly status message for the toolbar.
     */
    function cleanAdminJsonParseMessage(responseText) {
        const plainText = String(responseText || '')
            .replace(/<br\s*\/?>(\s*)/gi, '\n')
            .replace(/<[^>]+>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        if (plainText) {
            return `Gallery order was saved, but the server returned a diagnostic message instead of clean JSON: ${plainText.slice(0, 240)}`;
        }
        return 'Gallery order was saved, but the server returned an empty response. Refresh the page to verify the current order.';
    }

    /**
     * Returns all real gallery rows in current DOM order.
     *
     * @returns {HTMLTableRowElement[]} Gallery rows in flattened tree order.
     */
    function galleryRows() {
        return Array.from(body.querySelectorAll('[data-gallery-row]'));
    }

    /**
     * Reads the integer depth value from one gallery row.
     *
     * @param {Element|null} row Gallery row to inspect.
     * @returns {number} Non-negative row depth.
     */
    function rowDepth(row) {
        return Math.max(0, Number(row?.dataset.depth || 0));
    }

    /**
     * Returns a compact signature used to detect whether anything changed.
     *
     * @returns {string} Ordered id and parent-id signature.
     */
    function currentGallerySignature() {
        return galleryRows().map((row) => `${row.dataset.galleryId || ''}:${row.dataset.parentId || '0'}`).join('|');
    }

    /**
     * Collects the dragged root row and every following descendant row.
     *
     * @param {HTMLTableRowElement} rootRow Gallery row whose subtree should move.
     * @returns {HTMLTableRowElement[]} Root row followed by its descendants.
     */
    function collectMovedRows(rootRow) {
        const rows = galleryRows();
        const startIndex = rows.indexOf(rootRow);
        const rootDepth = rowDepth(rootRow);
        const moved = [];
        if (startIndex < 0) {
            return moved;
        }
        for (let index = startIndex; index < rows.length; index++) {
            const row = rows[index];
            if (index !== startIndex && rowDepth(row) <= rootDepth) {
                break;
            }
            moved.push(row);
        }
        return moved;
    }

    /**
     * Copies current column widths from a real row into a cloned row.
     *
     * @param {HTMLTableRowElement} sourceRow Real row being cloned.
     * @param {HTMLTableRowElement} cloneRow Cloned row shown inside the ghost table.
     * @returns {void}
     */
    function copyCellWidths(sourceRow, cloneRow) {
        const sourceCells = Array.from(sourceRow.children);
        const cloneCells = Array.from(cloneRow.children);
        sourceCells.forEach((cell, index) => {
            const cloneCell = cloneCells[index];
            if (!cloneCell) {
                return;
            }
            cloneCell.style.width = `${cell.getBoundingClientRect().width}px`;
        });
    }

    /**
     * Creates the fixed visual copy used while a gallery subtree is moving.
     *
     * @param {HTMLTableRowElement[]} rows Real rows being moved.
     * @returns {HTMLTableElement} Ghost table appended to the document body.
     */
    function createGalleryGhost(rows) {
        const firstBox = rows[0].getBoundingClientRect();
        const ghost = document.createElement('table');
        const ghostBody = document.createElement('tbody');
        ghost.className = 'admin-image-order-ghost admin-gallery-order-ghost';
        ghost.style.width = `${firstBox.width}px`;
        ghost.style.left = `${firstBox.left}px`;
        const clonedRow = rows[0].cloneNode(true);
        copyCellWidths(rows[0], clonedRow);
        clonedRow.classList.add('is-ghost-row');
        clonedRow.removeAttribute('data-gallery-row');
        clonedRow.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));
        ghostBody.appendChild(clonedRow);
        ghost.appendChild(ghostBody);
        document.body.appendChild(ghost);
        return ghost;
    }

    /**
     * Creates a placeholder row matching the moved subtree height.
     *
     * @param {HTMLTableRowElement[]} rows Rows being moved.
     * @returns {HTMLTableRowElement} Placeholder inserted into the table body.
     */
    function createGalleryPlaceholder(rows) {
        const placeholder = document.createElement('tr');
        const cell = document.createElement('td');
        const totalHeight = rows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);
        placeholder.className = 'admin-image-order-placeholder admin-gallery-order-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.dataset.depth = String(originalDepth);
        cell.colSpan = Math.max(1, rows[0].children.length);
        cell.style.height = `${Math.max(32, totalHeight)}px`;
        placeholder.appendChild(cell);
        return placeholder;
    }

    /**
     * Moves the fixed ghost table to follow the current pointer position.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function moveGhost(clientY) {
        if (!ghostTable) {
            return;
        }
        ghostTable.style.top = `${clientY - pointerOffsetY}px`;
    }

    /**
     * Returns rows available as insertion targets while a subtree is moving.
     *
     * @returns {HTMLTableRowElement[]} Rows not currently hidden as part of the moved subtree.
     */
    function availableRows() {
        return galleryRows().filter((row) => !row.classList.contains('is-reorder-hidden'));
    }

    /**
     * Finds the row before which the placeholder should be inserted.
     *
     * @param {number} pointerY Current pointer Y coordinate.
     * @returns {HTMLTableRowElement|null} Row before the placeholder, or null to append.
     */
    function rowBeforePointer(pointerY) {
        return availableRows().reduce((closest, row) => {
            const box = row.getBoundingClientRect();
            const offset = pointerY - box.top - (box.height / 2);
            if (offset < 0 && offset > closest.offset) {
                return {offset, row};
            }
            return closest;
        }, {offset: Number.NEGATIVE_INFINITY, row: null}).row;
    }

    /**
     * Returns the row that would visually precede the placeholder.
     *
     * @returns {HTMLTableRowElement|null} Previous real gallery row, or null at table start.
     */
    function rowBeforePlaceholder() {
        let previous = placeholderRow?.previousElementSibling || null;
        while (previous && !previous.matches('[data-gallery-row]:not(.is-reorder-hidden)')) {
            previous = previous.previousElementSibling;
        }
        return previous;
    }

    /**
     * Calculates a legal tree depth from pointer X and the surrounding rows.
     *
     * @param {number} clientX Current pointer X coordinate.
     * @returns {number} Candidate depth for the moved root gallery.
     */
    function depthFromPointer(clientX) {
        const previousRow = rowBeforePlaceholder();
        const maxDepth = previousRow ? rowDepth(previousRow) + 1 : 0;
        const rawDepth = originalDepth + Math.round((clientX - startClientX) / indentWidth);
        return Math.max(0, Math.min(maxDepth, rawDepth));
    }

    /**
     * Updates placeholder indentation and status text for the current target depth.
     *
     * @param {number} depth Candidate depth for the moved gallery.
     * @returns {void}
     */
    function applyPlaceholderDepth(depth) {
        proposedDepth = depth;
        const direction = depth > originalDepth ? 'right' : (depth < originalDepth ? 'left' : 'level');
        const message = direction === 'right'
            ? '→ Release to nest the gallery here.'
            : (direction === 'left' ? '← Release to move the gallery out here.' : '↓ Release to save the new gallery position.');
        if (placeholderRow) {
            placeholderRow.dataset.depth = String(depth);
            placeholderRow.dataset.dragDirection = direction;
            placeholderRow.style.setProperty('--gallery-drag-depth', String(depth));
        }
        if (ghostTable) {
            ghostTable.dataset.dragDirection = direction;
        }
        table.dataset.galleryDragDirection = direction;
        document.body.dataset.galleryDragDirection = direction;
        setStatus(message, 'dragging');
    }

    /**
     * Moves the placeholder to the insertion point under the pointer.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @param {number} clientX Current viewport X coordinate.
     * @returns {void}
     */
    function movePlaceholder(clientY, clientX) {
        if (!placeholderRow) {
            return;
        }
        const beforeRow = rowBeforePointer(clientY);
        if (beforeRow === null) {
            body.appendChild(placeholderRow);
        } else if (beforeRow !== placeholderRow.nextElementSibling) {
            body.insertBefore(placeholderRow, beforeRow);
        }
        applyPlaceholderDepth(depthFromPointer(clientX));
    }

    /**
     * Applies a new depth to the moved rows while preserving descendant offsets.
     *
     * @param {number} newRootDepth New depth for the dragged root gallery.
     * @returns {void}
     */
    function applyMovedDepths(newRootDepth) {
        const shift = newRootDepth - originalDepth;
        draggedRows.forEach((row) => {
            const nextDepth = Math.max(0, rowDepth(row) + shift);
            setGalleryRowDepth(row, nextDepth);
        });
    }

    /**
     * Updates depth-related row metadata and title indentation classes.
     *
     * @param {HTMLTableRowElement} row Row to update.
     * @param {number} depth New visible tree depth.
     * @returns {void}
     */
    function setGalleryRowDepth(row, depth) {
        const title = row.querySelector('.tree-title');
        row.dataset.depth = String(depth);
        row.style.setProperty('--gallery-depth', String(Math.min(depth, 8)));
        row.classList.toggle('is-subgallery', depth > 0);
        if (!title) {
            return;
        }
        Array.from(title.classList).forEach((className) => {
            if (className.startsWith('tree-depth-')) {
                title.classList.remove(className);
            }
        });
        title.classList.add(`tree-depth-${Math.min(depth, 8)}`);
        title.querySelector('.tree-branch')?.remove();
        if (depth > 0 && !title.querySelector('.tree-branch')) {
            const branch = document.createElement('span');
            branch.className = 'tree-branch';
            branch.setAttribute('aria-hidden', 'true');
            const link = title.querySelector('a');
            title.insertBefore(branch, link || null);
        }
    }

    /**
     * Derives parent ids for every visible row from the flattened depth values.
     *
     * @returns {Array<{id: string, parent_id: string}>} Ordered rows with parent ids.
     */
    function serializeGalleryTree() {
        const stack = [];
        return galleryRows().map((row) => {
            const depth = rowDepth(row);
            stack.length = depth;
            const parent = depth > 0 ? stack[depth - 1] : '0';
            const id = row.dataset.galleryId || '';
            row.dataset.parentId = parent || '0';
            stack[depth] = id;
            return {id, parent_id: parent || '0'};
        }).filter((entry) => entry.id !== '');
    }

    /**
     * Returns the stable folder name segment for a gallery row.
     *
     * The Admin table can update visible paths immediately after a tree move
     * without waiting for the next page load. The folder segment is captured
     * once from the current path, then reused even after the displayed path is
     * recalculated under another parent.
     *
     * @param {HTMLTableRowElement} row Gallery row whose folder name is needed.
     * @returns {string} Last folder path segment for this gallery.
     */
    function galleryFolderName(row) {
        if (row.dataset.galleryFolderName) {
            return row.dataset.galleryFolderName;
        }
        const pathText = row.querySelector('.admin-gallery-path')?.textContent?.trim() || '';
        const parts = pathText.split('/').filter((part) => part !== '');
        const folderName = parts.length > 0 ? parts[parts.length - 1] : (row.dataset.galleryTitle || row.dataset.galleryId || 'gallery');
        row.dataset.galleryFolderName = folderName;
        return folderName;
    }

    /**
     * Returns the base public gallery URL prefix from the current link.
     *
     * @param {HTMLTableRowElement} row Gallery row whose public link should be refreshed.
     * @returns {string} Public URL prefix ending at `/gallery/`, or the current href prefix.
     */
    function galleryUrlPrefix(row) {
        if (row.dataset.galleryUrlPrefix) {
            return row.dataset.galleryUrlPrefix;
        }
        const link = row.querySelector('.admin-gallery-title-link');
        const href = link?.getAttribute('href') || '';
        const marker = '/gallery/';
        const markerIndex = href.indexOf(marker);
        const prefix = markerIndex >= 0 ? href.slice(0, markerIndex + marker.length) : '';
        row.dataset.galleryUrlPrefix = prefix;
        return prefix;
    }

    /**
     * Returns the gallery's canonical public URL segment from the current link.
     *
     * The admin tree must preserve the existing slug segment for the gallery
     * itself and only recompute the parent path when nesting changes.
     *
     * @param {HTMLTableRowElement} row Gallery row whose public segment is needed.
     * @returns {string} Decoded canonical public URL segment.
     */
    function galleryUrlSegment(row) {
        if (row.dataset.galleryUrlSegment) {
            return row.dataset.galleryUrlSegment;
        }
        const link = row.querySelector('.admin-gallery-title-link');
        const href = link?.getAttribute('href') || '';
        const marker = '/gallery/';
        const markerIndex = href.indexOf(marker);
        if (markerIndex < 0) {
            row.dataset.galleryUrlSegment = galleryFolderName(row);
            return row.dataset.galleryUrlSegment;
        }
        const path = href.slice(markerIndex + marker.length).replace(/\/+$/, '');
        const parts = path.split('/').filter((part) => part !== '');
        const segment = parts.length > 0 ? decodeURIComponent(parts[parts.length - 1]) : galleryFolderName(row);
        row.dataset.galleryUrlSegment = segment;
        return segment;
    }

    /**
     * Rebuilds the gallery link from the current tree path.
     *
     * The admin table already knows the live nesting order, so this keeps the
     * public link aligned with the just-saved move without a full refresh.
     *
     * @param {HTMLTableRowElement} row Gallery row whose link should be refreshed.
     * @param {string} nextPath Newly computed gallery path.
     * @returns {void}
     */
    function refreshGalleryLink(row, nextPath) {
        const link = row.querySelector('.admin-gallery-title-link');
        if (!link) {
            return;
        }
        const prefix = galleryUrlPrefix(row);
        if (!prefix) {
            return;
        }
        const path = nextPath.split('/').map((segment) => encodeURIComponent(segment)).join('/');
        const nextUrl = `${prefix}${path}/`;
        link.href = nextUrl;
        row.dataset.galleryUrl = nextUrl;
    }

    /**
     * Updates visible parent labels and folder paths after a client-side tree move.
     *
     * @returns {void}
     */
    function refreshVisibleGalleryTreeMetadata() {
        const titlesById = new Map();
        const pathsById = new Map();
        const urlPathsById = new Map();
        galleryRows().forEach((row) => {
            titlesById.set(row.dataset.galleryId || '', row.dataset.galleryTitle || row.querySelector('.admin-gallery-title-link')?.textContent?.trim() || 'Gallery');
        });
        galleryRows().forEach((row) => {
            const id = row.dataset.galleryId || '';
            const parentId = row.dataset.parentId || '0';
            const folderName = galleryFolderName(row);
            const parentPath = parentId !== '0' ? (pathsById.get(parentId) || '') : '';
            const nextPath = parentPath !== '' ? `${parentPath}/${folderName}` : folderName;
            const segment = galleryUrlSegment(row);
            const parentUrlPath = parentId !== '0' ? (urlPathsById.get(parentId) || '') : '';
            const nextUrlPath = parentUrlPath !== '' ? `${parentUrlPath}/${segment}` : segment;
            const pathLabel = row.querySelector('.admin-gallery-path');
            let parentLabel = row.querySelector('.admin-gallery-parent');
            if (!parentLabel) {
                parentLabel = document.createElement('span');
                parentLabel.className = 'admin-gallery-parent';
                row.querySelector('.admin-gallery-summary-text')?.appendChild(parentLabel);
            }
            pathsById.set(id, nextPath);
            urlPathsById.set(id, nextUrlPath);
            if (pathLabel) {
                pathLabel.textContent = nextPath;
            }
            refreshGalleryLink(row, nextUrlPath);
            if (parentLabel) {
                if (parentId !== '0') {
                    parentLabel.textContent = `Parent: ${titlesById.get(parentId) || 'Gallery'}`;
                    parentLabel.hidden = false;
                } else {
                    parentLabel.textContent = '';
                    parentLabel.hidden = true;
                }
            }
        });
    }

    /**
     * Sends the complete gallery order to PHP for validation and persistence.
     *
     * @returns {Promise<void>} Promise resolved after the save attempt finishes.
     */
    async function saveGalleryTree() {
        if (saveController) {
            saveController.abort();
        }
        const bodyData = new FormData();
        bodyData.set('csrf_token', csrfInput.value);
        bodyData.set('gallery_tree', JSON.stringify(serializeGalleryTree()));
        bodyData.set('ajax', '1');

        const controller = new AbortController();
        saveController = controller;
        setStatus('Saving gallery order and nesting...', 'saving');
        try {
            const response = await fetch(reorderUrl, {
                method: 'POST',
                body: bodyData,
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            });
            const responseText = await response.text();
            let result = null;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(cleanAdminJsonParseMessage(responseText));
            }
            if (!response.ok) {
                throw new Error(result.message || 'The server rejected the gallery reorder request.');
            }
            if (!result.ok) {
                throw new Error(result.message || 'Gallery order could not be saved.');
            }
            setStatus(result.message || 'Gallery order saved.', 'saved');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            setStatus(error.message || 'Gallery order could not be saved.', 'error');
        } finally {
            if (saveController === controller) {
                saveController = null;
            }
        }
    }

    /**
     * Reads the filename value used by automatic Name-column sorting.
     *
     * PHP stores the canonical relative path in data-image-name so sorting does
     * not depend on presentation markup. The visible cell is still used as a
     * fallback for older cached markup during rolling updates.
     *
     * @param {HTMLTableRowElement} row Image row from the edit-gallery table.
     * @returns {string} Trimmed name used for locale-aware comparison.
     */
    function sortableImageName(row) {
        const fallbackCell = row.querySelector('[data-admin-image-name-cell]');
        return (row.dataset.imageName || fallbackCell?.textContent || '').trim();
    }

    /**
     * Synchronizes visual and accessibility state of the Name sorting header.
     *
     * @param {HTMLButtonElement} button Header button used to sort names.
     * @param {'asc'|'desc'} nextDirection Direction to apply on the next click.
     * @param {'asc'|'desc'} activeDirection Direction now represented by the table.
     * @returns {void}
     */
    function updateNameSortHeader(button, nextDirection, activeDirection) {
        const sortHeader = button.closest('th');
        const arrow = button.querySelector('[aria-hidden="true"]');
        button.dataset.sortDirection = nextDirection;
        button.setAttribute('aria-label', nextDirection === 'asc' ? 'Sort photos by name from A to Z' : 'Sort photos by name from Z to A');
        sortHeader?.setAttribute('aria-sort', activeDirection === 'asc' ? 'ascending' : 'descending');
        if (arrow) {
            arrow.textContent = activeDirection === 'asc' ? '↑' : '↓';
        }
    }

    /**
     * Sorts rows by filename and persists the generated order immediately.
     *
     * Automatic name sorting intentionally reuses the same save endpoint as
     * manual dragging. Server-side validation, CSRF checks, exact image-list
     * comparison, transactional sort_order updates, and admin logging therefore
     * stay identical for both ordering methods.
     *
     * @param {MouseEvent} event Click event from the Name header button.
     * @returns {void}
     */
    function handleNameSortClick(event) {
        if (draggedRow) {
            return;
        }
        const button = event.currentTarget;
        const direction = button.dataset.sortDirection === 'desc' ? 'desc' : 'asc';
        const multiplier = direction === 'asc' ? 1 : -1;
        const rows = Array.from(body.querySelectorAll('[data-admin-image-order-row]'));
        if (rows.length < 2) {
            setStatus('There is only one image, so sorting is not needed.', 'idle');
            return;
        }

        const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
        rows.map((row, index) => ({row, index, name: sortableImageName(row)}))
            .sort((left, right) => {
                const compared = collator.compare(left.name, right.name);
                if (compared !== 0) {
                    return compared * multiplier;
                }
                return left.index - right.index;
            })
            .forEach((entry) => body.appendChild(entry.row));

        updateNameSortHeader(button, direction === 'asc' ? 'desc' : 'asc', direction);
        saveOrder();
    }

    /**
     * Removes document-level movement listeners for any active input path.
     *
     * @returns {void}
     */
    function removeDocumentListeners() {
        document.removeEventListener('pointermove', handleDocumentPointerMove, true);
        document.removeEventListener('pointerup', handleDocumentPointerEnd, true);
        document.removeEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.removeEventListener('mousemove', handleDocumentMouseMove, true);
        document.removeEventListener('mouseup', handleDocumentMouseEnd, true);
        document.removeEventListener('keydown', handleDocumentKeydown, true);
    }

    /**
     * Cleans temporary drag elements and optionally inserts the moved rows at the placeholder.
     *
     * @param {boolean} commit Whether the moved rows should move to the placeholder position.
     * @returns {boolean} Whether cleanup found an active drag session.
     */
    function cleanupVisuals(commit) {
        if (draggedRows.length === 0) {
            return false;
        }
        if (commit && placeholderRow?.parentNode === body) {
            applyMovedDepths(proposedDepth);
            draggedRows.forEach((row) => {
                body.insertBefore(row, placeholderRow);
            });
        }
        draggedRows.forEach((row) => row.classList.remove('is-dragging', 'is-reorder-hidden'));
        draggedHandle?.classList.remove('is-dragging');
        ghostTable?.remove();
        placeholderRow?.remove();
        document.body.classList.remove('admin-gallery-order-active');
        delete document.body.dataset.galleryDragDirection;
        delete table.dataset.galleryDragDirection;
        removeDocumentListeners();
        draggedRows = [];
        draggedHandle = null;
        placeholderRow = null;
        ghostTable = null;
        activePointerId = null;
        activeMouseFallback = false;
        return true;
    }

    /**
     * Cancels the active gallery reorder operation.
     *
     * @returns {void}
     */
    function cancelReorder() {
        if (!cleanupVisuals(false)) {
            return;
        }
        setStatus('Gallery order unchanged.', 'idle');
    }

    /**
     * Ends the current reorder operation and persists the new tree when it changed.
     *
     * @returns {void}
     */
    function finishReorder() {
        if (draggedRows.length === 0) {
            return;
        }
        cleanupVisuals(true);
        serializeGalleryTree();
        refreshVisibleGalleryTreeMetadata();
        document.dispatchEvent(new Event('adminGalleryTreeMutated'));
        document.dispatchEvent(new Event('galleryRowsChanged'));
        if (currentGallerySignature() !== originalSignature) {
            saveGalleryTree();
            return;
        }
        setStatus('Gallery order unchanged.', 'idle');
    }

    /**
     * Handles pointer movement for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerMove(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY, event.clientX);
    }

    /**
     * Handles pointer release or cancellation for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerEnd(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Handles mouse movement for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseMove(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY, event.clientX);
    }

    /**
     * Handles mouse release for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseEnd(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Lets the admin cancel an active gallery reorder operation with Escape.
     *
     * @param {KeyboardEvent} event Key event emitted during dragging.
     * @returns {void}
     */
    function handleDocumentKeydown(event) {
        if (event.key !== 'Escape') {
            return;
        }
        event.preventDefault();
        cancelReorder();
    }

    /**
     * Returns whether a pointer target should keep its native control behavior instead of starting gallery movement.
     *
     * @param {EventTarget|null} target Original pointer or mouse target.
     * @returns {boolean} Whether the target should be ignored by the row drag controller.
     */
    function isNativeGalleryControl(target) {
        if (!(target instanceof Element)) {
            return true;
        }
        return Boolean(target.closest('a[href], input, select, textarea, button, label, [contenteditable], [data-gallery-toggle], .gallery-row-action, .admin-gallery-row-action'));
    }

    /**
     * Removes listeners for a drag candidate that never crossed the movement threshold.
     *
     * @returns {void}
     */
    function removePendingDragListeners() {
        document.removeEventListener('pointermove', handlePendingPointerMove, true);
        document.removeEventListener('pointerup', handlePendingPointerEnd, true);
        document.removeEventListener('pointercancel', handlePendingPointerEnd, true);
        document.removeEventListener('mousemove', handlePendingMouseMove, true);
        document.removeEventListener('mouseup', handlePendingMouseEnd, true);
    }

    /**
     * Clears a not-yet-started drag candidate and restores document listeners.
     *
     * @returns {void}
     */
    function clearPendingDrag() {
        removePendingDragListeners();
        pendingDrag = null;
    }

    /**
     * Starts row movement only after the pointer clearly becomes a drag gesture.
     *
     * @param {number} clientX Current viewport X coordinate.
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function maybeStartPendingDrag(clientX, clientY) {
        if (!pendingDrag || draggedRows.length > 0) {
            return;
        }
        const deltaX = clientX - pendingDrag.startX;
        const deltaY = clientY - pendingDrag.startY;
        if (Math.hypot(deltaX, deltaY) < 12) {
            return;
        }
        const candidate = pendingDrag;
        clearPendingDrag();
        suppressClickUntil = Date.now() + 450;
        startReorder(candidate.zone, candidate.startX, candidate.startY, candidate.pointerId, candidate.mouseFallback);
        moveGhost(clientY);
        movePlaceholder(clientY, clientX);
    }

    /**
     * Watches pointer movement for the gallery-column drag threshold.
     *
     * @param {PointerEvent} event Pointer movement emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingPointerMove(event) {
        if (!pendingDrag || pendingDrag.pointerId !== event.pointerId) {
            return;
        }
        maybeStartPendingDrag(event.clientX, event.clientY);
        if (draggedRows.length > 0) {
            event.preventDefault();
        }
    }

    /**
     * Clears a pointer candidate when the admin clicked without dragging.
     *
     * @param {PointerEvent} event Pointer end event emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingPointerEnd(event) {
        if (!pendingDrag || pendingDrag.pointerId !== event.pointerId) {
            return;
        }
        clearPendingDrag();
    }

    /**
     * Watches classic mouse movement for browsers that do not use Pointer Events for this input.
     *
     * @param {MouseEvent} event Mouse movement emitted before a drag officially starts.
     * @returns {void}
     */
    function handlePendingMouseMove(event) {
        if (!pendingDrag || !pendingDrag.mouseFallback) {
            return;
        }
        maybeStartPendingDrag(event.clientX, event.clientY);
        if (draggedRows.length > 0) {
            event.preventDefault();
        }
    }

    /**
     * Clears a mouse candidate when the admin clicked without dragging.
     *
     * @returns {void}
     */
    function handlePendingMouseEnd() {
        if (!pendingDrag || !pendingDrag.mouseFallback) {
            return;
        }
        clearPendingDrag();
    }

    /**
     * Arms a gallery-column area so normal clicks still work and only movement starts reordering.
     *
     * @param {HTMLElement} zone Gallery-column area that can initiate row movement.
     * @param {number} clientX Starting viewport X coordinate.
     * @param {number} clientY Starting viewport Y coordinate.
     * @param {number|null} pointerId Pointer id for Pointer Events, or null for mouse fallback.
     * @param {boolean} mouseFallback Whether mouse events should be accepted for this session.
     * @returns {void}
     */
    function armGalleryDragZone(zone, clientX, clientY, pointerId, mouseFallback) {
        if (draggedRows.length > 0) {
            return;
        }
        clearPendingDrag();
        pendingDrag = {zone, startX: clientX, startY: clientY, pointerId, mouseFallback};
        if (mouseFallback) {
            document.addEventListener('mousemove', handlePendingMouseMove, true);
            document.addEventListener('mouseup', handlePendingMouseEnd, true);
            return;
        }
        document.addEventListener('pointermove', handlePendingPointerMove, true);
        document.addEventListener('pointerup', handlePendingPointerEnd, true);
        document.addEventListener('pointercancel', handlePendingPointerEnd, true);
    }

    /**
     * Starts moving the gallery subtree controlled by a gallery-column drag zone.
     *
     * @param {HTMLElement} handle Gallery-column area dragged by the admin.
     * @param {number} clientX Starting viewport X coordinate.
     * @param {number} clientY Starting viewport Y coordinate.
     * @param {number|null} pointerId Pointer id for Pointer Events, or null for mouse fallback.
     * @param {boolean} mouseFallback Whether mouse events should be accepted for this session.
     * @returns {void}
     */
    function startReorder(handle, clientX, clientY, pointerId, mouseFallback) {
        const row = handle.closest('[data-gallery-row]');
        if (!row || draggedRows.length > 0) {
            return;
        }
        draggedRows = collectMovedRows(row);
        if (draggedRows.length === 0) {
            return;
        }

        const rowBox = row.getBoundingClientRect();
        draggedHandle = handle;
        originalSignature = currentGallerySignature();
        originalDepth = rowDepth(row);
        proposedDepth = originalDepth;
        pointerOffsetY = clientY - rowBox.top;
        startClientX = clientX;
        activePointerId = pointerId;
        activeMouseFallback = mouseFallback;
        placeholderRow = createGalleryPlaceholder(draggedRows);
        ghostTable = createGalleryGhost(draggedRows);

        body.insertBefore(placeholderRow, draggedRows[draggedRows.length - 1].nextSibling);
        draggedRows.forEach((movedRow) => movedRow.classList.add('is-dragging', 'is-reorder-hidden'));
        handle.classList.add('is-dragging');
        document.body.classList.add('admin-gallery-order-active');
        moveGhost(clientY);
        applyPlaceholderDepth(originalDepth);

        document.addEventListener('pointermove', handleDocumentPointerMove, true);
        document.addEventListener('pointerup', handleDocumentPointerEnd, true);
        document.addEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.addEventListener('mousemove', handleDocumentMouseMove, true);
        document.addEventListener('mouseup', handleDocumentMouseEnd, true);
        document.addEventListener('keydown', handleDocumentKeydown, true);
    }

    body.querySelectorAll('[data-gallery-row]').forEach((row) => {
        // The native draggable attribute is disabled because custom pointer movement handles nested ordering.
        row.setAttribute('draggable', 'false');
    });

    body.querySelectorAll('[data-admin-gallery-drag-zone]').forEach((zone) => {
        // Prevents browser-provided drag images while keeping ordinary title and preview clicks usable.
        zone.setAttribute('draggable', 'false');
        zone.addEventListener('dragstart', (event) => {
            if (!isNativeGalleryControl(event.target)) {
                event.preventDefault();
            }
        });

        zone.addEventListener('click', (event) => {
            if (Date.now() <= suppressClickUntil) {
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        zone.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.isPrimary === false || isNativeGalleryControl(event.target)) {
                return;
            }
            armGalleryDragZone(zone, event.clientX, event.clientY, event.pointerId, false);
        });

        zone.addEventListener('mousedown', (event) => {
            if (window.PointerEvent || event.button !== 0 || draggedRows.length > 0 || isNativeGalleryControl(event.target)) {
                return;
            }
            armGalleryDragZone(zone, event.clientX, event.clientY, null, true);
        });
    });

    setStatus('Gallery ordering ready.', 'idle');
}

/**
 * Enables visible pointer ordering for the Admin edit-gallery image table.
 *
 * This implementation avoids native HTML drag-and-drop completely. Native
 * table dragging is inconsistent inside forms, especially when a button,
 * checkbox, thumbnail, or link is involved. Instead, the handle starts a
 * custom pointer session, the dragged row is represented by a fixed-position
 * ghost table, and a placeholder row shows the exact place where the image
 * will be inserted when the pointer is released.
 *
 * The visible ghost is intentionally not the real table row. The real row is
 * temporarily hidden and restored only when the drag ends. This keeps the
 * table layout stable, gives immediate visual feedback, and makes movement
 * obvious even before the pointer crosses another row.
 *
 * @returns {void}
 */
export function setupAdminImageReordering() {
    // table stores the reorder-enabled image table on the edit-gallery screen.
    const table = document.querySelector('[data-admin-image-order-table]');
    // toolbar stores endpoint metadata and status UI for the reorder feature.
    const toolbar = document.querySelector('[data-admin-image-order-toolbar]');
    // form stores the existing image bulk form, reused only for gallery id and CSRF values.
    const form = document.querySelector('[data-admin-image-bulk-form]');
    if (!table || !toolbar || !form) {
        return;
    }

    // body stores the table body containing movable image rows.
    const body = table.querySelector('tbody');
    // status stores the live textual state displayed above the table.
    const status = toolbar.querySelector('[data-admin-image-order-status]');
    // reorderUrl stores the server endpoint that persists the new sort_order values.
    const reorderUrl = toolbar.dataset.reorderUrl || '';
    // csrfInput stores the CSRF token generated by the PHP form helper.
    const csrfInput = form.querySelector('input[name="csrf_token"]');
    // galleryInput stores the gallery id whose direct image order is being edited.
    const galleryInput = form.querySelector('input[name="gallery_id"]');
    if (!body || !reorderUrl || !csrfInput || !galleryInput) {
        return;
    }

    // draggedRow stores the real table row being reordered.
    let draggedRow = null;
    // draggedHandle stores the gallery-column area that started the drag, so its visual state can be restored.
    let draggedHandle = null;
    // placeholderRow stores the temporary table row marking the insertion point.
    let placeholderRow = null;
    // ghostTable stores the fixed-position visual copy that follows the pointer.
    let ghostTable = null;
    // originalIndex stores the row position before dragging begins, used to avoid unnecessary saves.
    let originalIndex = -1;
    // pointerOffsetY stores the pointer distance from the top of the row at drag start.
    let pointerOffsetY = 0;
    // activePointerId stores the pointer that owns the current drag session.
    let activePointerId = null;
    // activeMouseFallback stores whether classic mouse events are currently driving movement.
    let activeMouseFallback = false;
    // pendingDrag stores a possible drag that has not crossed the movement threshold yet.
    let pendingDrag = null;
    // suppressClickUntil stores a short timestamp window used to stop link clicks after dragging a title area.
    let suppressClickUntil = 0;
    // saveController stores the in-flight request controller so a newer drop can supersede an older save.
    let saveController = null;

    /**
     * Updates the small status label above the image order table.
     *
     * @param {string} message Human-readable state shown to the admin.
     * @param {string} state Visual state name used by CSS.
     * @returns {void}
     */
    function setStatus(message, state) {
        if (!status) {
            return;
        }
        status.textContent = message;
        status.dataset.state = state;
    }

    /**
     * Reads the current DOM row order as a list of image ids.
     *
     * @returns {string[]} Ordered image ids exactly as displayed in the table.
     */
    function currentImageOrder() {
        return Array.from(body.querySelectorAll('[data-admin-image-order-row]'))
            .map((row) => row.dataset.imageId || '')
            .filter((imageId) => imageId !== '');
    }

    /**
     * Calculates the current zero-based position of a row in the reorder table.
     *
     * @param {Element} row Image table row whose current position should be measured.
     * @returns {number} Current row index, or -1 when the row is not found.
     */
    function rowIndex(row) {
        return Array.from(body.querySelectorAll('[data-admin-image-order-row]')).indexOf(row);
    }

    /**
     * Copies current column widths from the real row into the ghost row.
     *
     * Table cells otherwise shrink to their content when cloned into a fixed
     * table outside the original layout. Explicit widths keep the ghost row
     * visually aligned with the Admin table while it follows the pointer.
     *
     * @param {HTMLTableRowElement} sourceRow Real row being reordered.
     * @param {HTMLTableRowElement} cloneRow Cloned row shown inside the ghost table.
     * @returns {void}
     */
    function copyCellWidths(sourceRow, cloneRow) {
        const sourceCells = Array.from(sourceRow.children);
        const cloneCells = Array.from(cloneRow.children);
        sourceCells.forEach((cell, index) => {
            const cloneCell = cloneCells[index];
            if (!cloneCell) {
                return;
            }
            cloneCell.style.width = `${cell.getBoundingClientRect().width}px`;
        });
    }

    /**
     * Creates a placeholder row with the same height and column count as the dragged row.
     *
     * @param {HTMLTableRowElement} row Real row being reordered.
     * @returns {HTMLTableRowElement} Placeholder inserted into the table body.
     */
    function createPlaceholder(row) {
        const placeholder = document.createElement('tr');
        placeholder.className = 'admin-image-order-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');

        const cell = document.createElement('td');
        cell.colSpan = Math.max(1, row.children.length);
        cell.style.height = `${row.getBoundingClientRect().height}px`;
        placeholder.appendChild(cell);
        return placeholder;
    }

    /**
     * Creates the fixed-position visual copy used while dragging.
     *
     * @param {HTMLTableRowElement} row Real row being reordered.
     * @returns {HTMLTableElement} Ghost table appended to the document body.
     */
    function createGhostTable(row) {
        const rowBox = row.getBoundingClientRect();
        const ghost = document.createElement('table');
        const ghostBody = document.createElement('tbody');
        const clonedRow = row.cloneNode(true);

        copyCellWidths(row, clonedRow);
        clonedRow.classList.add('is-ghost-row');
        clonedRow.removeAttribute('data-admin-image-order-row');
        clonedRow.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));

        ghost.className = 'admin-image-order-ghost';
        ghost.style.width = `${rowBox.width}px`;
        ghost.style.left = `${rowBox.left}px`;
        ghost.appendChild(ghostBody);
        ghostBody.appendChild(clonedRow);
        document.body.appendChild(ghost);
        return ghost;
    }

    /**
     * Moves the fixed ghost table to follow the current pointer position.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function moveGhost(clientY) {
        if (!ghostTable) {
            return;
        }
        ghostTable.style.top = `${clientY - pointerOffsetY}px`;
    }

    /**
     * Finds the row before which the placeholder should be inserted.
     *
     * @param {number} pointerY Current pointer Y coordinate from the move event.
     * @returns {Element|null} Row before which the placeholder should be inserted, or null for append.
     */
    function rowBeforePointer(pointerY) {
        const rows = Array.from(body.querySelectorAll('[data-admin-image-order-row]:not(.is-reorder-hidden)'));
        return rows.reduce((closest, row) => {
            const box = row.getBoundingClientRect();
            const offset = pointerY - box.top - (box.height / 2);
            if (offset < 0 && offset > closest.offset) {
                return {offset, row};
            }
            return closest;
        }, {offset: Number.NEGATIVE_INFINITY, row: null}).row;
    }

    /**
     * Moves the placeholder to the insertion point under the pointer.
     *
     * @param {number} clientY Current viewport Y coordinate.
     * @returns {void}
     */
    function movePlaceholder(clientY) {
        if (!placeholderRow) {
            return;
        }
        const beforeRow = rowBeforePointer(clientY);
        if (beforeRow === null) {
            body.appendChild(placeholderRow);
        } else if (beforeRow !== placeholderRow.nextElementSibling) {
            body.insertBefore(placeholderRow, beforeRow);
        }
    }

    /**
     * Sends the current row order to PHP and persists sort_order in the database.
     *
     * @returns {Promise<void>} Promise resolved after the save attempt finishes.
     */
    async function saveOrder() {
        if (saveController) {
            saveController.abort();
        }
        const bodyData = new FormData();
        bodyData.set('csrf_token', csrfInput.value);
        bodyData.set('gallery_id', galleryInput.value);
        bodyData.set('image_order', JSON.stringify(currentImageOrder()));
        bodyData.set('ajax', '1');

        const controller = new AbortController();
        saveController = controller;
        setStatus('Saving new image order...', 'saving');
        try {
            const response = await fetch(reorderUrl, {
                method: 'POST',
                body: bodyData,
                headers: {'Accept': 'application/json'},
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error('The server rejected the reorder request.');
            }
            const result = await response.json();
            if (!result.ok) {
                throw new Error(result.message || 'Image order could not be saved.');
            }
            setStatus(result.message || 'Image order saved.', 'saved');
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            setStatus(error.message || 'Image order could not be saved.', 'error');
        } finally {
            if (saveController === controller) {
                saveController = null;
            }
        }
    }

    /**
     * Removes document-level movement listeners for any active input path.
     *
     * @returns {void}
     */
    function removeDocumentReorderListeners() {
        document.removeEventListener('pointermove', handleDocumentPointerMove, true);
        document.removeEventListener('pointerup', handleDocumentPointerEnd, true);
        document.removeEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.removeEventListener('mousemove', handleDocumentMouseMove, true);
        document.removeEventListener('mouseup', handleDocumentMouseEnd, true);
        document.removeEventListener('keydown', handleDocumentReorderKeydown, true);
    }

    /**
     * Cancels visual drag state and leaves the original order unchanged.
     *
     * @returns {void}
     */
    function cancelReorder() {
        if (!draggedRow) {
            return;
        }
        const row = draggedRow;
        cleanupReorderVisuals(false);
        setStatus('Order unchanged.', 'idle');
        row.focus?.();
    }

    /**
     * Cleans temporary drag elements and optionally inserts the real row at the placeholder.
     *
     * @param {boolean} commit Whether the real row should move to the placeholder position.
     * @returns {HTMLTableRowElement|null} Real row that was being reordered.
     */
    function cleanupReorderVisuals(commit) {
        if (!draggedRow) {
            return null;
        }

        const row = draggedRow;
        if (commit && placeholderRow?.parentNode === body) {
            body.insertBefore(row, placeholderRow);
        }

        row.classList.remove('is-dragging', 'is-reorder-hidden');
        draggedHandle?.classList.remove('is-dragging');
        ghostTable?.remove();
        placeholderRow?.remove();
        document.body.classList.remove('admin-image-order-active');
        removeDocumentReorderListeners();

        draggedRow = null;
        draggedHandle = null;
        placeholderRow = null;
        ghostTable = null;
        activePointerId = null;
        activeMouseFallback = false;
        return row;
    }

    /**
     * Ends the current reorder operation and persists the new order when it changed.
     *
     * @returns {void}
     */
    function finishReorder() {
        if (!draggedRow) {
            return;
        }
        const finalRow = cleanupReorderVisuals(true);
        if (!finalRow) {
            return;
        }
        const finalIndex = rowIndex(finalRow);
        if (originalIndex !== -1 && finalIndex !== -1 && finalIndex !== originalIndex) {
            saveOrder();
            return;
        }
        setStatus('Order unchanged.', 'idle');
    }

    /**
     * Handles pointer movement for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerMove(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY);
    }

    /**
     * Handles pointer release or cancellation for the active drag session.
     *
     * @param {PointerEvent} event Pointer event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentPointerEnd(event) {
        if (activePointerId !== null && event.pointerId !== activePointerId) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Handles mouse movement for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseMove(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        moveGhost(event.clientY);
        movePlaceholder(event.clientY);
    }

    /**
     * Handles mouse release for the fallback mouse path.
     *
     * @param {MouseEvent} event Mouse event emitted anywhere in the document.
     * @returns {void}
     */
    function handleDocumentMouseEnd(event) {
        if (!activeMouseFallback) {
            return;
        }
        event.preventDefault();
        finishReorder();
    }

    /**
     * Lets the admin cancel an active reorder operation with Escape.
     *
     * @param {KeyboardEvent} event Key event emitted during dragging.
     * @returns {void}
     */
    function handleDocumentReorderKeydown(event) {
        if (event.key !== 'Escape') {
            return;
        }
        event.preventDefault();
        cancelReorder();
    }

    /**
     * Starts moving the row controlled by a drag handle.
     *
     * @param {HTMLElement} handle Gallery-column area dragged by the admin.
     * @param {number} clientY Starting viewport Y coordinate.
     * @param {number|null} pointerId Pointer id for Pointer Events, or null for mouse fallback.
     * @param {boolean} mouseFallback Whether mouse events should be accepted for this session.
     * @returns {void}
     */
    function startReorder(handle, clientY, pointerId, mouseFallback) {
        if (draggedRow) {
            return;
        }

        const row = handle.closest('[data-admin-image-order-row]');
        if (!row) {
            return;
        }

        const rowBox = row.getBoundingClientRect();
        draggedRow = row;
        draggedHandle = handle;
        originalIndex = rowIndex(row);
        pointerOffsetY = clientY - rowBox.top;
        activePointerId = pointerId;
        activeMouseFallback = mouseFallback;
        placeholderRow = createPlaceholder(row);
        ghostTable = createGhostTable(row);

        body.insertBefore(placeholderRow, row.nextSibling);
        row.classList.add('is-dragging', 'is-reorder-hidden');
        handle.classList.add('is-dragging');
        document.body.classList.add('admin-image-order-active');
        moveGhost(clientY);
        setStatus('Move the photo to its new position, then release.', 'dragging');

        document.addEventListener('pointermove', handleDocumentPointerMove, true);
        document.addEventListener('pointerup', handleDocumentPointerEnd, true);
        document.addEventListener('pointercancel', handleDocumentPointerEnd, true);
        document.addEventListener('mousemove', handleDocumentMouseMove, true);
        document.addEventListener('mouseup', handleDocumentMouseEnd, true);
        document.addEventListener('keydown', handleDocumentReorderKeydown, true);
    }

    body.querySelectorAll('[data-admin-image-order-row]').forEach((row) => {
        // The native draggable attribute is explicitly disabled because custom pointer movement handles ordering.
        row.setAttribute('draggable', 'false');
    });

    table.querySelector('[data-admin-image-name-sort]')?.addEventListener('click', handleNameSortClick);

    body.querySelectorAll('[data-admin-image-drag-handle]').forEach((handle) => {
        // Prevents the browser from selecting the arrow text or trying to create a native button drag image.
        handle.setAttribute('draggable', 'false');
        handle.addEventListener('dragstart', (event) => event.preventDefault());
        handle.addEventListener('click', (event) => event.preventDefault());

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            startReorder(handle, event.clientY, event.pointerId, false);
        });

        handle.addEventListener('mousedown', (event) => {
            if (event.button !== 0 || draggedRow) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            startReorder(handle, event.clientY, null, true);
        });
    });
}
