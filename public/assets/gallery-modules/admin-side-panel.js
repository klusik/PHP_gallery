/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-side-panel.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Provides side-panel, upload, and incremental page-refresh admin workflows.
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
 *   2026-08-11
 */

import { setupImageBulkMoveFields } from './admin-bulk-actions.js?v=20260519-gallery-picker-v1';
import { setupGallerySearchPickers } from './searchable-gallery-picker.js?v=20260519-gallery-picker-v1';
import { setupBackToTopButton, teardownBackToTopButton } from './back-to-top.js?v=20260510-lifecycle-v3';
import { setupGalleryLightbox, setupTagSuggestions, teardownGalleryLightbox } from './lightbox-deferred.js?v=20260811-leaflet-safari-marker-v1';
import { setupPictureManager, teardownPictureManager } from './picture-manager.js?v=20260519-picture-manager-v5';
import { setupResponsiveThumbnailSizes, teardownResponsiveThumbnailSizes } from './responsive-thumbnails.js?v=20260510-lazy-map-v1';
import { activateAdminTabInRoot, activeAdminTabId, setupAdminTabs, setupAdminTabsInRoot } from './admin-tabs.js?v=20260811-deferred-maintenance-v1';
import { setupAdminNestedTabs } from './admin-nested-tabs.js?v=20260608-admin-cinematic-v1';
import { setupAdminImageReordering } from './admin-image-reordering.js?v=20260512-modular-admin-v1';
import { setupPublicGalleryPageReordering } from './admin-gallery-list.js?v=20260512-modular-admin-v1';
import { appendUploadProgressLog, escapeHtmlAttribute, escapeHtmlText, i18n, isThumbnailSubmission, thumbnailEndpoint, updateBasicProgress, updateThumbnailProgress, ensureThumbnailProgress, updateUploadProgressMetrics } from './admin-core.js?v=20260614-upload-order-v2';
import { browserUploadRequested, runBrowserGalleryUpload } from './admin-browser-upload.js?v=20260614-upload-original-diagnostics-v1';

const adminSidePanelMotionDurationMs = 280;

// Function `setupGalleryUploadProgress` executes this focused behavior.
/**
 * Handle setup gallery upload progress.
 *
 * Used by browser-side gallery behavior.
 */
export function setupGalleryUploadProgress() {
    document.querySelectorAll('[data-gallery-upload-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.galleryUploadProgressBound === '1') {
            return;
        }
        form.dataset.galleryUploadProgressBound = '1';
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            runGalleryUpload(form);
        });
    });
}

// Function `runGalleryUpload` executes this focused behavior.
/**
 * Run gallery upload.
 *
 * Used by browser-side gallery behavior.
 *
 * @param {HTMLFormElement} form Form value.
 */
async function runGalleryUpload(form) {
    // progress stores state or configuration for the gallery front-end flow.
    const progress = ensureThumbnailProgress(form);
    revealPanelUploadProgress(form, progress);
    form.classList.add('is-uploading');
    // buttons stores state or configuration for the gallery front-end flow.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
        if (button instanceof HTMLButtonElement) {
            button.dataset.originalText = button.dataset.originalText || button.textContent || '';
            button.textContent = i18n('admin.operations.working', 'Working...');
        }
    });
    try {
        // createThumbnails stores state or configuration for the gallery front-end flow.
        const createThumbnails = Boolean(form.querySelector('input[name="create_thumbnails"]')?.checked);
        // result stores state or configuration for the gallery front-end flow.
        let result;
        if (browserUploadRequested(form)) {
            result = await runBrowserGalleryUpload(form, progress);
        }
        if (!result || result.fallback) {
            result = await runGalleryUploadFiles(form, progress, createThumbnails);
        }
        if (createThumbnails) {
            const failed = Number(result.thumbnail_failed || 0);
            const message = failed > 0 ? i18n('admin.operations.upload_thumbnail_failed', 'Upload finished, but {count} thumbnail or DNG display derivative(s) failed.', {count: failed}) : i18n('admin.operations.upload_complete', 'Upload and thumbnail job complete.');
            updateThumbnailProgress(progress, result.uploaded || 0, result.total_files || 0, result.thumbnails || 0, result.thumbnail_skipped || 0, message);
        } else {
            updateBasicProgress(progress, 100, i18n('admin.operations.uploaded_scanning_complete', 'Uploaded {count} images. Scanning complete.', {count: result.uploaded || 0}));
        }
        if (galleryUploadShouldClosePanel(form)) {
            dispatchAdminSidePanelSuccess(form, result);
            return;
        }
        window.location.href = result.redirect_url || adminUrlWithParams({uploaded: result.uploaded || 0, scanned: result.scanned || 0, thumbnails: result.thumbnails || 0});
    } catch (error) {
        updateBasicProgress(progress, 100, error.message || i18n('admin.operations.upload_failed', 'Upload failed.'));
    } finally {
        form.classList.remove('is-uploading');
        const panel = form.closest('[data-admin-side-panel]');
        if (panel instanceof HTMLElement) {
            panel.classList.remove('is-uploading');
        }
        buttons.forEach((button) => {
            button.disabled = false;
            if (button instanceof HTMLButtonElement && button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
            }
        });
    }
}

/**
 * Move side-panel upload progress into view before network work starts.
 *
 * @param {HTMLFormElement} form Upload form currently being submitted.
 * @param {HTMLElement} progress Progress element used by the upload workflow.
 */
function revealPanelUploadProgress(form, progress) {
    const panel = form.closest('[data-admin-side-panel]');
    const dialog = form.closest('.admin-side-panel-dialog');
    if (!(dialog instanceof HTMLElement)) {
        return;
    }
    progress.classList.add('is-panel-upload-progress');
    if (panel instanceof HTMLElement) {
        panel.classList.add('is-uploading');
    }
    dialog.scrollTop = 0;
    progress.scrollIntoView({behavior: 'auto', block: 'start'});
}

/**
 * Return whether an upload form should complete inside the side-panel workflow.
 *
 * @param {HTMLFormElement} form Upload form submitted by the admin.
 * @return {boolean} True when the side panel owns the completion behavior.
 */
function galleryUploadShouldClosePanel(form) {
    return form.dataset.galleryPanelCloseOnSuccess === '1' && Boolean(form.closest('[data-admin-side-panel]'));
}

/**
 * Notify the side-panel controller that an embedded workflow finished.
 *
 * @param {HTMLFormElement} form Completed form.
 * @param {Record<string, *>} result Server response plus client-side aggregate upload data.
 */
function dispatchAdminSidePanelSuccess(form, result) {
    form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
        bubbles: true,
        detail: {
            source: 'upload',
            result,
        },
    }));
}

/**
 * Attach the progressive Add gallery here side-panel behavior.
 *
 * Direct links remain unchanged for browsers without JavaScript. The enhanced path
 * fetches the existing create-gallery page as a fragment and lets the existing
 * create/upload endpoints handle all mutations.
 */
export function setupAdminGallerySidePanel() {
    if (document.body?.dataset.adminGallerySidePanelBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.adminGallerySidePanelBound = '1';
    }

    document.addEventListener('php-gallery:admin-image-order-saved', async () => {
        const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        await refreshCurrentGalleryContextFromServer('');
    });

    document.addEventListener('php-gallery:metadata-organizer-applied', async (event) => {
        const detail = event.detail || {};
        detail.handled = true;
        const result = detail.result || {};
        const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
        const editUrl = String(result.edit_url || '');
        const galleryUrl = String(result.gallery_url || '');
        if (panel instanceof HTMLElement) {
            writeAdminGallerySidePanelStatus(panel, i18n('admin.metadata_organizer.refreshing', 'Refreshing gallery view...'), false);
            const panelRefreshed = await refreshAdminSidePanelFromServer(editUrl);
            const contextRefreshed = await refreshCurrentGalleryContextFromServer(galleryUrl);
            writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.metadata_organizer.apply_done_title', 'Organizer applied')), false);
            if (!panelRefreshed && !contextRefreshed) {
                window.location.reload();
            }
            return;
        }
        const refreshed = await refreshCurrentGalleryContextFromServer(galleryUrl);
        if (!refreshed) {
            window.location.reload();
        }
    });

    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const link = event.target.closest('[data-gallery-side-panel-link]');
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        if (!window.fetch || !window.DOMParser) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        await openAdminGallerySidePanel(link);
    }, true);



    document.addEventListener('submit', (event) => {
        if (!(event.target instanceof HTMLFormElement)) {
            return;
        }
        const form = event.target.closest('[data-public-admin-delete-form]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.stopPropagation();
        const kindValue = String(form.dataset.publicAdminDeleteKind || 'photo');
        const kind = ['gallery', 'photo', 'tag'].includes(kindValue) ? kindValue : 'photo';
        const name = String(form.dataset.publicAdminDeleteName || kind).trim();
        const message = [
            `Remove this ${kind} from CMS?`,
            name ? `Item: ${name}` : '',
            '',
            'This removes the CMS record. Continue?'
        ].filter((line) => line !== '').join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const closeButton = event.target.closest('[data-admin-side-panel-close], [data-admin-side-panel-scrim]');
        if (!closeButton) {
            return;
        }
        const panel = event.target.closest('[data-admin-side-panel]') || document.querySelector('[data-admin-side-panel]');
        if (panel instanceof HTMLElement) {
            closeAdminGallerySidePanel(panel);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
        if (panel instanceof HTMLElement) {
            closeAdminGallerySidePanel(panel);
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-panel-create-form]')) {
            return;
        }
        event.preventDefault();
        await submitAdminGalleryPanelCreateForm(form);
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-panel-edit-form]')) {
            return;
        }
        event.preventDefault();
        await submitAdminPanelEditForm(form);
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-upload-automation-token-form]')) {
            return;
        }
        if (!form.closest('[data-admin-side-panel]')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitAdminPanelUploadAutomationTokenForm(form);
    }, true);

    document.addEventListener('click', (event) => {
        const submitter = event.target instanceof Element ? event.target.closest('button, input[type="submit"]') : null;
        if (!(submitter instanceof HTMLElement)) {
            return;
        }
        const form = submitter.closest('form[data-admin-panel-bulk-form]');
        if (form instanceof HTMLFormElement) {
            form.__adminPanelSubmitter = submitter;
        }
    }, true);

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-panel-bulk-form]')) {
            return;
        }
        const submitter = event.submitter || form.__adminPanelSubmitter || null;
        if (isThumbnailSubmission(form, submitter)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitAdminPanelImageBulkForm(form, submitter);
    }, true);

    document.addEventListener('php-gallery:side-panel-success', (event) => {
        const panel = event.target instanceof Element ? event.target.closest('[data-admin-side-panel]') : document.querySelector('[data-admin-side-panel]');
        const source = String(event.detail?.source || '');
        const result = event.detail?.result || {};
        const bulkAction = String(result.bulk_action || '');
        const shouldKeepPanelOpen = (source === 'gallery-image-bulk' && (bulkAction === 'cover' || bulkAction === 'delete' || bulkAction === 'move_existing' || bulkAction === 'move_new')) || source === 'upload';
        if (panel instanceof HTMLElement && !shouldKeepPanelOpen) {
            closeAdminGallerySidePanel(panel);
        }
        if (source === 'gallery-image-bulk') {
            reflectGalleryImageBulkInCurrentView(result);
            if (panel instanceof HTMLElement && shouldKeepPanelOpen) {
                writeAdminGallerySidePanelStatus(panel, String(result.message || 'Gallery title picture saved.'), false);
            }
            return;
        }
        if (source === 'gallery-edit') {
            reflectSavedGalleryInCurrentView(result);
            return;
        }
        if (source === 'image-edit') {
            reflectSavedImageInCurrentView(result);
            return;
        }
        if (source === 'tag-edit') {
            reflectSavedTagInCurrentView(result);
            return;
        }
        if (source === 'upload') {
            if (panel instanceof HTMLElement) {
                writeAdminGallerySidePanelStatus(panel, String(result.message || 'Upload complete.'), false);
            }
            reflectUploadedGalleryInCurrentView(result);
            return;
        }
        reflectCreatedGalleryInCurrentView(result);
    });
}

/**
 * Open the reusable side panel and fill it from an admin workflow.
 *
 * @param {HTMLAnchorElement} link Enhanced admin workflow link.
 */
async function openAdminGallerySidePanel(link) {
    const panel = ensureAdminGallerySidePanel();
    const body = panel.querySelector('[data-admin-side-panel-body]');
    if (!(body instanceof HTMLElement)) {
        window.location.href = link.href;
        return;
    }
    const workflow = sidePanelWorkflowFromLink(link);
    setAdminGallerySidePanelHeading(panel, workflow.kicker, workflow.title);
    panel.dataset.adminSidePanelWorkflow = workflow.name;
    panel.dataset.adminSidePanelSourceUrl = '';
    panel.classList.toggle('is-edit-panel', workflow.name !== 'create');
    openAdminGallerySidePanelShell(panel);
    writeAdminGallerySidePanelStatus(panel, workflow.loadingMessage, false);
    body.innerHTML = `<div class="admin-side-panel-loading" role="status">${escapeHtmlText(workflow.loadingMessage)}</div>`;

    try {
        const url = new URL(link.dataset.gallerySidePanelUrl || link.href, window.location.href);
        url.searchParams.set('panel', '1');
        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (!response.ok || html.trim() === '') {
            throw new Error(workflow.loadErrorMessage);
        }
        body.innerHTML = sidePanelContentFromHtml(html, workflow);
        panel.dataset.adminSidePanelSourceUrl = response.url || url.toString();
        prepareAdminSidePanelLoadedContent(body, workflow, response.url || url.toString());
        writeAdminGallerySidePanelStatus(panel, '', false);
        const firstField = body.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (firstField instanceof HTMLElement) {
            firstField.focus({preventScroll: true});
        }
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || workflow.loadErrorMessage, true);
        body.innerHTML = `<div class="notice is-alert">${escapeHtmlText(workflow.loadErrorMessage)} ${escapeHtmlText(i18n('admin.side_panel.use_normal_page_prefix', 'Use the normal admin page instead:'))} <a href="${escapeHtmlAttribute(link.href)}">${escapeHtmlText(i18n('admin.side_panel.open_directly', 'open directly'))}</a>.</div>`;
    }
}

/**
 * Read side-panel workflow metadata from an enhanced link.
 *
 * @param {HTMLAnchorElement} link Enhanced admin workflow link.
 * @return {{name: string, kicker: string, title: string, loadingMessage: string, loadErrorMessage: string} } Workflow configuration.
 */
function sidePanelWorkflowFromLink(link) {
    const name = String(link.dataset.adminSidePanelWorkflow || 'create');
    if (name === 'gallery-edit') {
        return {
            name,
            kicker: link.dataset.adminSidePanelKicker || 'Gallery editor',
            title: link.dataset.adminSidePanelTitle || 'Edit gallery',
            loadingMessage: 'Loading gallery editor...',
            loadErrorMessage: 'The gallery editor could not be loaded.',
        };
    }
        if (name === 'image-edit') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || 'Photo editor',
                title: link.dataset.adminSidePanelTitle || 'Edit photo',
                loadingMessage: 'Loading photo editor...',
                loadErrorMessage: 'The photo editor could not be loaded.',
            };
        }
        if (name === 'tag-edit') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || 'Tag editor',
                title: link.dataset.adminSidePanelTitle || 'Edit tag',
                loadingMessage: 'Loading tag editor...',
                loadErrorMessage: 'The tag editor could not be loaded.',
            };
        }
        if (name === 'upload') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || 'Upload workflow',
                title: link.dataset.adminSidePanelTitle || 'Upload photos',
                loadingMessage: 'Loading upload workflow...',
                loadErrorMessage: 'The upload workflow could not be loaded.',
            };
        }
        if (name === 'duplicate-detector') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || i18n('admin.duplicate_photos.kicker', 'Gallery tools'),
                title: link.dataset.adminSidePanelTitle || i18n('admin.duplicate_photos.page_title', 'Duplicate Photo Detector'),
                loadingMessage: i18n('admin.duplicate_photos.loading', 'Loading duplicate detector...'),
                loadErrorMessage: i18n('admin.duplicate_photos.load_failed', 'The duplicate detector could not be loaded.'),
            };
        }
        return {
            name: 'create',
            kicker: link.dataset.adminSidePanelKicker || 'Admin shortcut',
            title: link.dataset.adminSidePanelTitle || 'Add gallery here',
            loadingMessage: 'Loading gallery workflow...',
        loadErrorMessage: 'The gallery workflow could not be loaded.',
    };
}

/**
 * Update the reusable side-panel heading for the current workflow.
 *
 * @param {HTMLElement} panel Side-panel root.
 * @param {string} kicker Small heading label.
 * @param {string} title Main heading text.
 */
function setAdminGallerySidePanelHeading(panel, kicker, title) {
    const kickerNode = panel.querySelector('[data-admin-side-panel-kicker]');
    const titleNode = panel.querySelector('[data-admin-side-panel-title]');
    if (kickerNode instanceof HTMLElement) {
        kickerNode.textContent = kicker;
    }
    if (titleNode instanceof HTMLElement) {
        titleNode.textContent = title;
    }
}

/**
 * Extract usable panel content from either a fragment response or a full admin page.
 *
 * @param {string} html Server-rendered HTML.
 * @param {*} workflow Workflow value.
 * @return {string} HTML safe to inject into the panel body.
 */
function sidePanelContentFromHtml(html, workflow) {
    const trimmed = html.trim();
    if (trimmed.startsWith('<div') || trimmed.startsWith('<section')) {
        return trimmed;
    }
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const directFragment = parsed.querySelector('[data-gallery-create-panel], [data-admin-upload-panel], [data-duplicate-photo-detector]');
    if (directFragment instanceof HTMLElement) {
        return directFragment.outerHTML;
    }
    const main = parsed.querySelector('main.site-main') || parsed.querySelector('main');
    if (main instanceof HTMLElement) {
        const devModePanel = main.querySelector('.admin-devmode-panel');
        if (devModePanel instanceof HTMLElement) {
            devModePanel.remove();
        }
        return `<div class="admin-side-panel-stack admin-side-panel-edit-workspace" data-admin-edit-panel-workspace="${escapeHtmlAttribute(workflow.name)}">${main.innerHTML}</div>`;
    }
    return trimmed;
}

/**
 * Prepare forms and dynamic controls after admin content is injected into the panel.
 *
 * @param {HTMLElement} body Side-panel body element.
 * @param {*} workflow Workflow value.
 * @param {string} sourceUrl URL that produced the loaded content.
 */
function prepareAdminSidePanelLoadedContent(body, workflow, sourceUrl) {
    setupGalleryUploadProgress();
    setupAdminTabsInRoot(body);
    setupAdminNestedTabs(body);
    setupAdminPanelRangeDisplays(body);
    setupAdminPanelThumbnailBoundControls(body);
    setupTagSuggestions(body);
    setupGallerySearchPickers();
    setupImageBulkMoveFields();
    if (workflow.name === 'gallery-edit') {
        prepareAdminPanelEditForm(body.querySelector('.admin-edit-gallery-form'), workflow.name, sourceUrl);
        prepareAdminPanelBulkForm(body.querySelector('[data-admin-image-bulk-form]'));
        setupAdminImageReordering();
    } else if (workflow.name === 'image-edit') {
        const imageForm = body.querySelector('section.panel form.form-grid, form.form-grid');
        prepareAdminPanelEditForm(imageForm, workflow.name, sourceUrl);
    } else if (workflow.name === 'tag-edit') {
        const tagForm = body.querySelector('form.admin-tags-form');
        prepareAdminPanelEditForm(tagForm, workflow.name, sourceUrl);
    } else if (workflow.name === 'upload') {
        const uploadForms = body.querySelectorAll('[data-gallery-upload-form]');
        uploadForms.forEach((uploadForm) => {
            if (uploadForm instanceof HTMLFormElement) {
                uploadForm.dataset.adminPanelWorkflow = 'upload';
                ensureUploadSourceUrlField(uploadForm);
            }
        });
    }
}

/**
 * Store the page that opened the upload drawer so a successful upload can
 * refresh the same paginated gallery view instead of falling back to page one.
 *
 * @param {HTMLFormElement} form Upload form rendered inside the side panel.
 */
function ensureUploadSourceUrlField(form) {
    let field = form.querySelector('input[name="source_url"]');
    if (!(field instanceof HTMLInputElement)) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'source_url';
        form.append(field);
    }
    field.value = window.location.href;
}

/**
 * Mark one loaded admin form as side-panel owned and fix its action URL.
 *
 * @param {Element|null} formCandidate Loaded form candidate.
 * @param {string} workflowName Active workflow name.
 * @param {string} sourceUrl URL that should receive the POST.
 */
function prepareAdminPanelEditForm(formCandidate, workflowName, sourceUrl) {
    if (!(formCandidate instanceof HTMLFormElement)) {
        return;
    }
    formCandidate.dataset.adminPanelEditForm = 'true';
    formCandidate.dataset.adminPanelWorkflow = workflowName;
    formCandidate.dataset.adminPanelAction = sourceUrl;
    formCandidate.action = sourceUrl;
    const panel = formCandidate.closest('[data-admin-side-panel]');
    if (panel instanceof HTMLElement) {
        panel.dataset.adminSidePanelSourceUrl = sourceUrl;
    }
}

/**
 * Mark the gallery image bulk form as side-panel owned while keeping its original action route.
 *
 * @param {Element|null} formCandidate Loaded bulk form candidate.
 */
function prepareAdminPanelBulkForm(formCandidate) {
    if (!(formCandidate instanceof HTMLFormElement)) {
        return;
    }
    formCandidate.dataset.adminPanelBulkForm = 'true';
    formCandidate.dataset.adminPanelWorkflow = 'gallery-edit';
    const actionAttribute = formCandidate.getAttribute('action') || '';
    formCandidate.dataset.adminPanelAction = actionAttribute ? new URL(actionAttribute, window.location.href).toString() : (formCandidate.action || window.location.href);
    const panel = formCandidate.closest('[data-admin-side-panel]');
    if (panel instanceof HTMLElement && panel.dataset.adminSidePanelSourceUrl) {
        formCandidate.dataset.adminPanelSourceUrl = panel.dataset.adminSidePanelSourceUrl;
    }
}

/**
 * Keep gallery grid range labels synchronized inside dynamically loaded panel content.
 *
 * @param {HTMLElement} root Side-panel body element.
 */
function setupAdminPanelRangeDisplays(root) {
    const pairs = [
        ['[data-gallery-grid-columns]', '[data-gallery-grid-columns-display]'],
        ['[data-gallery-grid-rows]', '[data-gallery-grid-rows-display]'],
    ];
    pairs.forEach(([controlSelector, displaySelector]) => {
        const control = root.querySelector(controlSelector);
        const display = root.querySelector(displaySelector);
        if (!(control instanceof HTMLInputElement) || !(display instanceof HTMLElement) || control.dataset.adminPanelRangeBound === '1') {
            return;
        }
        control.dataset.adminPanelRangeBound = '1';
        const override = root.querySelector('[data-gallery-grid-override-enabled]');
        /**
         * Synchronize sync.
         */
        const sync = () => {
            display.textContent = control.value;
        };
        /**
         * Handle mark custom.
         *
         * Used by browser-side gallery behavior.
         */
        const markCustom = () => {
            if (override instanceof HTMLInputElement) {
                override.checked = true;
            }
            sync();
        };
        control.addEventListener('input', markCustom);
        control.addEventListener('change', markCustom);
        sync();
    });
}

/**
 * Keep thumbnail-bound slider pairs synchronized inside dynamically loaded panel content.
 *
 * @param {HTMLElement} root Side-panel body element.
 */
function setupAdminPanelThumbnailBoundControls(root) {
    root.querySelectorAll('[data-thumbnail-bound-control]').forEach((controlRoot) => {
        if (!(controlRoot instanceof HTMLElement) || controlRoot.dataset.adminPanelThumbnailBound === '1') {
            return;
        }
        controlRoot.dataset.adminPanelThumbnailBound = '1';
        const values = String(controlRoot.getAttribute('data-thumbnail-bound-values') || '0')
            .split(',')
            .map((value) => parseInt(value, 10))
            .filter((value) => Number.isFinite(value));
        const minIndexControl = controlRoot.querySelector('[data-thumbnail-bound-min-index]');
        const maxIndexControl = controlRoot.querySelector('[data-thumbnail-bound-max-index]');
        const minValueControl = controlRoot.querySelector('[data-thumbnail-bound-min-value]');
        const maxValueControl = controlRoot.querySelector('[data-thumbnail-bound-max-value]');
        const summary = controlRoot.querySelector('[data-thumbnail-bound-summary]');
        const minDisplay = controlRoot.querySelector('[data-thumbnail-bound-min-display]');
        const maxDisplay = controlRoot.querySelector('[data-thumbnail-bound-max-display]');
        if (values.length < 2 || !(minIndexControl instanceof HTMLInputElement) || !(maxIndexControl instanceof HTMLInputElement) || !(minValueControl instanceof HTMLInputElement) || !(maxValueControl instanceof HTMLInputElement) || !(summary instanceof HTMLElement)) {
            return;
        }
        /**
         * Format size.
         *
         * Used by browser-side gallery behavior.
         *
         * @param {*} value Value to process.
         * @param {*} side Side value.
         * @return {*} Result value for the caller.
         */
        const formatSize = (value, side) => value === 0 ? (side === 'min' ? 'Auto min' : 'Auto max') : `${value}px`;
        /**
         * Synchronize sync.
         *
         * @param {*} changedControl Changed control value.
         */
        const sync = (changedControl = null) => {
            let minIndex = parseInt(minIndexControl.value, 10) || 0;
            let maxIndex = parseInt(maxIndexControl.value, 10) || 0;
            const highestIndex = values.length - 1;
            minIndex = Math.max(0, Math.min(highestIndex, minIndex));
            maxIndex = Math.max(0, Math.min(highestIndex, maxIndex));
            if (minIndex > maxIndex) {
                if (changedControl === minIndexControl) {
                    maxIndex = minIndex;
                } else {
                    minIndex = maxIndex;
                }
            }
            minIndexControl.value = String(minIndex);
            maxIndexControl.value = String(maxIndex);
            const minValue = values[minIndex] || 0;
            const maxValue = values[maxIndex] || 0;
            minValueControl.value = String(minValue);
            maxValueControl.value = String(maxValue);
            const minPercent = highestIndex > 0 ? (minIndex / highestIndex) * 100 : 0;
            const maxPercent = highestIndex > 0 ? (maxIndex / highestIndex) * 100 : 100;
            controlRoot.style.setProperty('--thumbnail-bound-min-percent', `${minPercent}%`);
            controlRoot.style.setProperty('--thumbnail-bound-max-percent', `${maxPercent}%`);
            controlRoot.style.setProperty('--thumbnail-bound-active-start', `${minPercent}%`);
            controlRoot.style.setProperty('--thumbnail-bound-active-end', `${maxPercent}%`);
            controlRoot.style.setProperty('--thumbnail-bound-active-start-number', String(minPercent));
            controlRoot.style.setProperty('--thumbnail-bound-active-end-number', String(maxPercent));
            const minLabel = formatSize(minValue, 'min');
            const maxLabel = formatSize(maxValue, 'max');
            if (minDisplay instanceof HTMLElement) {
                minDisplay.textContent = minLabel;
            }
            if (maxDisplay instanceof HTMLElement) {
                maxDisplay.textContent = maxLabel;
            }
            summary.textContent = `${minLabel} to ${maxLabel}`;
        };
        minIndexControl.addEventListener('input', () => sync(minIndexControl));
        minIndexControl.addEventListener('change', () => sync(minIndexControl));
        maxIndexControl.addEventListener('input', () => sync(maxIndexControl));
        maxIndexControl.addEventListener('change', () => sync(maxIndexControl));
        sync();
    });
}

/**
 * Create the side-panel shell once and reuse it for later gallery actions.
 *
 * @return {HTMLElement} Side-panel root.
 */
function ensureAdminGallerySidePanel() {
    let panel = document.querySelector('[data-admin-side-panel]');
    if (panel instanceof HTMLElement) {
        return panel;
    }
    panel = document.createElement('div');
    panel.className = 'admin-side-panel';
    panel.hidden = true;
    panel.dataset.adminSidePanel = 'true';
    panel.setAttribute('aria-hidden', 'true');
    panel.innerHTML = `
        <div class="admin-side-panel-scrim" data-admin-side-panel-scrim></div>
        <aside class="admin-side-panel-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-side-panel-title">
            <header class="admin-side-panel-header">
                <div>
                    <p class="admin-kicker" data-admin-side-panel-kicker>Admin shortcut</p>
                    <h2 id="admin-side-panel-title" data-admin-side-panel-title>Add gallery here</h2>
                </div>
                <button type="button" class="button secondary" data-admin-side-panel-close>Close</button>
            </header>
            <div class="admin-side-panel-status visually-hidden" data-admin-side-panel-status aria-live="polite"></div>
            <div class="admin-side-panel-body" data-admin-side-panel-body></div>
        </aside>`;
    document.body.append(panel);
    return panel;
}

/**
 * Make the side panel visible while keeping the current page in place.
 *
 * @param {HTMLElement} panel Side-panel root.
 */
function openAdminGallerySidePanelShell(panel) {
    panel.hidden = false;
    panel.classList.remove('is-closing');
    panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-admin-side-panel');
    window.requestAnimationFrame(() => {
        panel.classList.add('is-open');
    });
}

/**
 * Hide the side panel and clear its transient status.
 *
 * @param {HTMLElement} panel Side-panel root.
 */
function closeAdminGallerySidePanel(panel) {
    panel.classList.remove('is-open');
    panel.classList.add('is-closing');
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-admin-side-panel');
    window.setTimeout(() => {
        if (!panel.classList.contains('is-open')) {
            panel.hidden = true;
            panel.classList.remove('is-closing');
        }
    }, adminSidePanelMotionDurationMs);
    writeAdminGallerySidePanelStatus(panel, '', false);
}

/**
 * Write a visible or screen-reader-only side-panel status message.
 *
 * @param {HTMLElement} panel Side-panel root.
 * @param {string} message Status text.
 * @param {boolean} isError Whether the message should be styled as an error.
 */
function writeAdminGallerySidePanelStatus(panel, message, isError) {
    const status = panel.querySelector('[data-admin-side-panel-status]');
    if (!(status instanceof HTMLElement)) {
        return;
    }
    status.textContent = message;
    status.classList.toggle('visually-hidden', message === '');
    status.classList.toggle('notice', message !== '');
    status.classList.toggle('is-alert', isError);
}

/**
 * Submit the empty-gallery side-panel form to the existing create endpoint.
 *
 * @param {HTMLFormElement} form Side-panel create form.
 */
async function submitAdminGalleryPanelCreateForm(form) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, 'Creating gallery...', false);
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        body.set('panel', '1');
        const response = await fetch(form.action || window.location.href, {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        const result = await readJsonResponseSafely(response, 'Gallery creation failed.');
        if (!response.ok || !result.ok) {
            throw new Error(result.error || 'Gallery creation failed.');
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: 'create',
                result,
            },
        }));
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || 'Gallery creation failed.', true);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Submit an upload-automation API-key form inside the side panel.
 *
 * The dedicated API manager can keep normal POST redirects, but the public
 * admin side panel must stay mounted and refresh only its editor content.
 *
 * @param {HTMLFormElement} form API-key create or revoke form.
 */
async function submitAdminPanelUploadAutomationTokenForm(form) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, i18n('admin.side_panel.updating_api_key', 'Updating API key...'), false);
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        body.set('panel', '1');

        const activeTab = activeAdminTabId(panel.querySelector('[data-admin-side-panel-body]'));
        const returnUrl = panelSourceUrlForRefresh(panel, String(panel.dataset.adminSidePanelWorkflow || 'gallery-edit'), '', activeTab || 'admin-edit-api');
        const refreshUrl = String(returnUrl || '');
        if (returnUrl !== '') {
            body.set('return_url', sameSitePathForPost(returnUrl));
        }

        const requestUrl = uploadAutomationTokenRequestUrl(form);
        if (requestUrl === '') {
            throw new Error(i18n('admin.side_panel.api_key_endpoint_missing', 'API key update failed. Missing API key endpoint.'));
        }
        const response = await fetch(requestUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.api_key_failed', 'API key update failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.api_key_failed', 'API key update failed.'));
        }
        if (String(result.action || 'create') === 'create' && Number(result.token_id || 0) <= 0) {
            throw new Error(i18n('admin.side_panel.api_key_created_missing', 'API key update failed. The server did not report a created API key.'));
        }
        const refreshed = await refreshAdminSidePanelFromServer(String(result.refresh_url || refreshUrl || ''));
        writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.side_panel.api_key_updated', 'API key updated.')), !refreshed);
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.api_key_failed', 'API key update failed.'), true);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Resolve the upload-automation token endpoint through the current browser origin.
 *
 * url_for() can render an absolute configured base URL. When the same local
 * install is opened through another host alias or port, a direct fetch to that
 * absolute URL loses same-origin cookies and receives an HTML admin page.
 *
 * @param {HTMLFormElement} form API-key create or revoke form.
 * @return {string} Same-origin URL for the token endpoint, or an empty string.
 */
function uploadAutomationTokenRequestUrl(form) {
    const actionValue = String(form.getAttribute('action') || form.action || '').trim();
    if (actionValue === '') {
        return '';
    }
    try {
        const url = new URL(actionValue, window.location.href);
        if (String(url.searchParams.get('page') || '') === 'admin_upload_automation_token') {
            return `${url.pathname}${url.search}${url.hash}`;
        }
        if (url.origin === window.location.origin) {
            return `${url.pathname}${url.search}${url.hash}`;
        }
    } catch (error) {
        return '';
    }
    return '';
}

/**
 * Submit an existing admin edit form through the side-panel JSON path.
 *
 * The side panel stays open while the existing admin save route returns JSON.
 *
 * @param {HTMLFormElement} form Side-panel edit form.
 * @return {Promise<void>} Resolves after success handling or error reporting.
 */
async function submitAdminPanelEditForm(form) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const workflowName = String(form.dataset.adminPanelWorkflow || 'edit');
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, workflowName === 'image-edit' ? i18n('admin.side_panel.saving_photo', 'Saving photo...') : (workflowName === 'tag-edit' ? i18n('admin.side_panel.saving_tag', 'Saving tag...') : i18n('admin.side_panel.saving_gallery', 'Saving gallery...')), false);
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        body.set('panel', '1');
        const response = await fetch(form.dataset.adminPanelAction || form.action || window.location.href, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponseSafely(response, workflowName === 'image-edit' ? i18n('admin.side_panel.photo_save_failed', 'Photo save failed.') : (workflowName === 'tag-edit' ? i18n('admin.side_panel.tag_save_failed', 'Tag save failed.') : i18n('admin.side_panel.gallery_save_failed', 'Gallery save failed.')));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.save_failed', 'Save failed.'));
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: workflowName,
                result,
            },
        }));
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.save_failed', 'Save failed.'), true);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Submit the gallery image bulk form from a side panel without relying on browser submitter routing.
 *
 * @param {HTMLFormElement} form Loaded gallery image bulk form.
 * @param {HTMLElement|null} submitter Button or control that triggered the submit.
 */
async function submitAdminPanelImageBulkForm(form, submitter) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const selectedInputs = Array.from(form.querySelectorAll('input[name="image_ids[]"]:checked'));
    const actionControl = form.querySelector('[name="action"]');
    let action = actionControl instanceof HTMLSelectElement || actionControl instanceof HTMLInputElement ? String(actionControl.value || '') : '';
    if (submitter instanceof HTMLButtonElement && submitter.name === 'action' && submitter.value !== '') {
        action = submitter.value;
    }
    if (selectedInputs.length === 0) {
        writeAdminGallerySidePanelStatus(panel, i18n('admin.side_panel.select_photo_first', 'Select at least one photo first.'), true);
        return;
    }
    if (action === '') {
        writeAdminGallerySidePanelStatus(panel, i18n('admin.side_panel.choose_photo_action', 'Choose a photo action first.'), true);
        return;
    }

    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, action === 'cover' ? i18n('admin.side_panel.saving_title_picture', 'Saving title picture...') : i18n('admin.side_panel.applying_photo_action', 'Applying photo action...'), false);
    try {
        const body = new FormData();
        const csrfInput = form.querySelector('input[name="csrf_token"]');
        const galleryInput = form.querySelector('input[name="gallery_id"]');
        const returnTabInput = form.querySelector('input[name="return_tab"]');
        if (csrfInput instanceof HTMLInputElement) {
            body.set('csrf_token', csrfInput.value);
        }
        if (galleryInput instanceof HTMLInputElement) {
            body.set('gallery_id', galleryInput.value);
            body.set('id', galleryInput.value);
        }
        if (returnTabInput instanceof HTMLInputElement) {
            body.set('return_tab', returnTabInput.value);
        }
        if (action === 'move_existing') {
            const destinationInput = form.querySelector('input[name="destination_gallery_id"]');
            if (destinationInput instanceof HTMLInputElement) {
                body.set('destination_gallery_id', destinationInput.value);
            }
        }
        if (action === 'move_new') {
            const newGalleryParent = form.querySelector('select[name="new_gallery_parent_id"]');
            const newGalleryTitle = form.querySelector('input[name="new_gallery_title"]');
            const newGalleryFolderName = form.querySelector('input[name="new_gallery_folder_name"]');
            if (newGalleryParent instanceof HTMLSelectElement) {
                body.set('new_gallery_parent_id', newGalleryParent.value);
            }
            if (newGalleryTitle instanceof HTMLInputElement) {
                body.set('new_gallery_title', newGalleryTitle.value);
            }
            if (newGalleryFolderName instanceof HTMLInputElement) {
                body.set('new_gallery_folder_name', newGalleryFolderName.value);
            }
        }
        selectedInputs.forEach((input) => {
            if (input instanceof HTMLInputElement) {
                body.append('image_ids[]', input.value);
            }
        });
        body.set('action', action);
        body.set('ajax', '1');
        body.set('panel', '1');

        const response = await fetch(form.dataset.adminPanelAction || form.action || window.location.href, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.photo_action_failed', 'Photo action failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.photo_action_failed', 'Photo action failed.'));
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: 'gallery-image-bulk',
                result,
            },
        }));
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.photo_action_failed', 'Photo action failed.'), true);
    } finally {
        buttons.forEach((button) => {
            button.disabled = false;
        });
    }
}

/**
 * Reflect a completed gallery image bulk action in the visible edit table and public context.
 *
 * @param {Record<string, *>} result Server response for the image bulk action.
 */
async function reflectGalleryImageBulkInCurrentView(result) {
    const action = String(result.bulk_action || '');
    const coverImageId = String(result.cover_image_id || '');
    if (action === 'delete' || action === 'move_existing' || action === 'move_new') {
        const removedIds = Array.isArray(result.image_ids) ? result.image_ids.map((value) => String(value || '')) : [];
        removedIds.forEach((imageId) => {
            if (!imageId) {
                return;
            }
            document.querySelectorAll(`[data-admin-image-order-row][data-image-id="${CSS.escape(imageId)}"]`).forEach((row) => {
                if (row instanceof HTMLElement) {
                    row.remove();
                }
            });
            document.querySelectorAll(`[data-lightbox-image][data-image-id="${CSS.escape(imageId)}"]`).forEach((card) => {
                if (card instanceof HTMLElement) {
                    card.remove();
                }
            });
        });
        const refreshUrl = String(result.refresh_url || result.source_gallery_url || result.gallery_url || '');
        await refreshAdminSidePanelFromServer(String(result.edit_url || ''));
        await refreshCurrentGalleryContextFromServer(refreshUrl);
        const noticeTarget = action === 'delete' ? String(result.gallery_url || '') : String(result.destination_gallery_url || result.gallery_url || '');
        showAdminGallerySidePanelResultNotice(String(result.message || (action === 'delete' ? i18n('admin.side_panel.photo_deleted', 'Photo deleted.') : i18n('admin.side_panel.photo_move_completed', 'Photo move completed.'))), noticeTarget);
        return;
    }
        if (action === 'cover' && coverImageId !== '') {
            document.querySelectorAll('[data-admin-image-order-row]').forEach((row) => {
                if (!(row instanceof HTMLElement)) {
                    return;
                }
            const coverCell = row.querySelector('[data-admin-image-cover-cell]');
            if (coverCell instanceof HTMLElement) {
                coverCell.textContent = String(row.dataset.imageId || '') === coverImageId ? i18n('admin.side_panel.title_picture', 'Title picture') : '';
            }
        });
        await refreshAdminSidePanelFromServer();
        await refreshCurrentGalleryContextFromServer(String(result.refresh_url || result.gallery_url || ''));
    }
    showAdminGallerySidePanelResultNotice(String(result.message || i18n('admin.side_panel.photo_action_completed', 'Photo action completed.')), String(result.gallery_url || ''));
}

/**
 * Reflect a saved tag after editing it from the side panel.
 *
 * Tag slugs can change, so the visible public tag page is refreshed from the
 * returned public URL and the browser URL is updated without opening a separate
 * full admin page.
 *
 * @param {Record<string, *>} result Server response for the saved tag.
 */
async function reflectSavedTagInCurrentView(result) {
    const panel = document.querySelector('[data-admin-side-panel]');
    const publicUrl = String(result.public_url || '');
    const editUrl = String(result.edit_url || '');
    if (panel instanceof HTMLElement) {
        writeAdminGallerySidePanelStatus(panel, String(result.message || 'Tag saved.'), false);
        if (editUrl !== '') {
            panel.dataset.adminSidePanelSourceUrl = editUrl;
            await refreshAdminSidePanelFromServer(editUrl);
        }
    }
    if (publicUrl !== '') {
        if (document.querySelector('[data-public-tag-page]')) {
            window.history.replaceState({}, '', publicUrl);
            await refreshCurrentGalleryContextFromServer(publicUrl);
        } else {
            showAdminGallerySidePanelResultNotice(String(result.message || 'Tag saved.'), publicUrl);
        }
    }
}

/**
 * Reflect a saved gallery in the current page without forcing a full navigation.
 *
 * The side panel save workflow refreshes visible title and notice state after JSON save.
 *
 * @param {Record<string, *>} result Server response for the saved gallery.
 * @return {Promise<void>} Resolves after the visible gallery state is refreshed.
 */
async function reflectSavedGalleryInCurrentView(result) {
    const galleryTitle = String(result.gallery_title || 'Gallery');
    await refreshAdminSidePanelFromServer(String(result.edit_url || ''));
    const refreshed = await refreshCurrentGalleryContextFromServer('');
    if (!refreshed) {
        const heading = document.querySelector('.hero h1, .gallery-branding-title');
        if (heading instanceof HTMLElement && galleryTitle) {
            heading.textContent = galleryTitle;
        }
    }
    showAdminGallerySidePanelResultNotice(String(result.message || `${galleryTitle} saved`), String(result.gallery_url || ''));
}

/**
 * Reflect a saved image in the current page without forcing a full navigation.
 *
 * @param {Record<string, *>} result Server response for the saved image.
 */
async function reflectSavedImageInCurrentView(result) {
    const imageId = String(result.image_id || '');
    if (imageId) {
        updateAdminImageRowsFromResult(imageId, result);
        updatePublicImageCardsFromResult(imageId, result);
    }

    // Photo edits are often launched from a paginated public gallery page.
    // Refreshing from the bare gallery URL would silently redraw page one.
    // Using the current browser URL preserves pagination, filters, and hash state.
    await refreshCurrentGalleryContextFromServer(currentVisiblePageRefreshUrl());
    showAdminGallerySidePanelResultNotice(String(result.message || 'Photo saved.'), String(result.image_url || ''));
}

/**
 * Return the URL that represents the currently visible page behind the side panel.
 *
 * The side-panel save endpoint returns canonical gallery URLs, but those URLs do
 * not include the active pagination state. This helper deliberately prefers the
 * browser URL so a photo edited from page 3 refreshes page 3 instead of fetching
 * the gallery root again.
 *
 * @return {string} Absolute current page URL suitable for a fragment refresh.
 */
function currentVisiblePageRefreshUrl() {
    return String(window.location.href || '');
}

/**
 * Reflect an uploaded gallery batch in the current page without forcing a hard redirect.
 *
 * @param {Record<string, *>} result Server response for the upload operation.
 */
async function reflectUploadedGalleryInCurrentView(result) {
    const message = String(result.message || i18n('admin.side_panel.upload_complete', 'Upload complete.'));
    const targetUrl = String(result.gallery_url || '');
    const refreshUrl = String(result.refresh_url || result.parent_gallery_url || result.gallery_url || '');
    showAdminGallerySidePanelResultNotice(message, targetUrl);
    if (String(result.edit_url || '') !== '') {
        await switchAdminSidePanelToGalleryEditor(result);
    } else {
        await refreshAdminSidePanelFromServer();
    }
    if (refreshUrl === '' || adminSidePanelSamePageUrl(refreshUrl, window.location.href) || document.querySelector('main.site-main .admin-edit-gallery-hero')) {
        await refreshCurrentGalleryContextFromServer(refreshUrl);
    }
}

/**
 * Re-render the visible admin side-panel workflow from the current server response.
 *
 * This keeps the side panel open while refreshing counts, table rows, upload forms,
 * and other gallery metadata without a full navigation reload.
 *
 * @param {string} sourceUrl Optional panel source URL. Falls back to the active form or current page.
 * @return {Promise<boolean>} True when the current admin editor was refreshed.
 */
async function refreshAdminSidePanelFromServer(sourceUrl = '') {
    try {
        const panel = document.querySelector('[data-admin-side-panel]');
        const body = panel instanceof HTMLElement ? panel.querySelector('[data-admin-side-panel-body]') : null;
        const workflowName = panel instanceof HTMLElement ? String(panel.dataset.adminSidePanelWorkflow || 'create') : 'create';
        const panelDialog = panel instanceof HTMLElement ? panel.querySelector('.admin-side-panel-dialog') : null;
        const panelScrollTop = panelDialog instanceof HTMLElement ? panelDialog.scrollTop : 0;
        const activeTabId = activeAdminTabId(body);
        const resolvedUrl = panelSourceUrlForRefresh(panel, workflowName, sourceUrl, activeTabId);
        if (!(body instanceof HTMLElement) || resolvedUrl === '') {
            return false;
        }
        const fetchUrl = new URL(resolvedUrl, window.location.href);
        fetchUrl.searchParams.set('_panel_refresh', String(Date.now()));
        const response = await fetch(fetchUrl.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (!response.ok || html.trim() === '') {
            return false;
        }
        body.innerHTML = sidePanelContentFromHtml(html, {name: workflowName});
        prepareAdminSidePanelLoadedContent(body, {name: workflowName}, resolvedUrl);
        if (activeTabId) {
            activateAdminTabInRoot(body, activeTabId);
        }
        if (panel instanceof HTMLElement) {
            panel.dataset.adminSidePanelSourceUrl = resolvedUrl;
        }
        if (panelDialog instanceof HTMLElement) {
            requestAnimationFrame(() => {
                panelDialog.scrollTop = panel.classList.contains('is-uploading') ? 0 : panelScrollTop;
            });
        }
        return true;
    } catch (error) {
        return false;
    }
}

/**
 * Resolve the original GET source used to redraw the current side-panel workflow.
 *
 * @param {HTMLElement|null} panel Active side-panel root.
 * @param {string} workflowName Current workflow identifier.
 * @param {string} explicitSourceUrl Optional caller-supplied source URL.
 * @param {string} activeTabId Currently selected admin tab id.
 * @return {string} URL safe to fetch for server-rendered panel HTML.
 */
function panelSourceUrlForRefresh(panel, workflowName, explicitSourceUrl, activeTabId) {
    const source = explicitSourceUrl
        || String(panel instanceof HTMLElement ? panel.dataset.adminSidePanelSourceUrl || '' : '')
        || String(window.location.href);
    if (source === '') {
        return '';
    }
    const url = new URL(source, window.location.href);
    url.searchParams.set('panel', '1');
    if (workflowName === 'gallery-edit' && activeTabId) {
        url.searchParams.set('tab', activeTabId);
        url.hash = activeTabId;
    }
    return url.toString();
}

/**
 * Convert a same-site URL into the path/query/hash format expected by PHP return-url validation.
 *
 * @param {string} urlValue URL generated by the side-panel refresh logic.
 * @return {string} Relative same-site URL, or an empty string when the URL is not usable.
 */
function sameSitePathForPost(urlValue) {
    try {
        const url = new URL(urlValue, window.location.href);
        if (url.origin !== window.location.origin) {
            return '';
        }
        return `${url.pathname}${url.search}${url.hash}`;
    } catch (error) {
        return '';
    }
}

/**
 * Update admin image table rows that are already visible behind the panel.
 *
 * @param {string} imageId Saved image id.
 * @param {Record<string, *>} result Server response for the saved image.
 */
function updateAdminImageRowsFromResult(imageId, result) {
    document.querySelectorAll(`[data-admin-image-order-row][data-image-id="${CSS.escape(imageId)}"]`).forEach((row) => {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const statusCell = row.querySelector('td:nth-child(6)');
        if (statusCell instanceof HTMLElement && result.image_visibility) {
            statusCell.textContent = String(result.image_visibility);
        }
        const sortOrder = Number(result.image_sort_order || 0);
        if (Number.isFinite(sortOrder)) {
            row.dataset.imageSortOrder = String(sortOrder);
        }
    });
}

/**
 * Update public image cards that are already visible behind the panel.
 *
 * @param {string} imageId Saved image id.
 * @param {Record<string, *>} result Server response for the saved image.
 */
function updatePublicImageCardsFromResult(imageId, result) {
    document.querySelectorAll(`[data-lightbox-image][data-image-id="${CSS.escape(imageId)}"]`).forEach((card) => {
        if (!(card instanceof HTMLElement)) {
            return;
        }
        const title = String(result.image_title || '');
        const description = String(result.image_description || '');
        card.dataset.title = title;
        card.dataset.description = description;
        let meta = card.querySelector('.image-meta');
        if ((title !== '' || description !== '') && !(meta instanceof HTMLElement)) {
            const stage = card.querySelector('.image-stage');
            if (stage instanceof HTMLElement) {
                meta = document.createElement('div');
                meta.className = 'image-meta image-meta-overlay';
                stage.append(meta);
            }
        }
        if (!(meta instanceof HTMLElement)) {
            return;
        }
        meta.innerHTML = '';
        if (title !== '') {
            const titleNode = document.createElement('h2');
            titleNode.textContent = title;
            meta.append(titleNode);
        }
        if (description !== '') {
            const descriptionNode = document.createElement('p');
            descriptionNode.textContent = description;
            meta.append(descriptionNode);
        }
        if (title === '' && description === '') {
            meta.remove();
        }
    });
}

/**
 * Return whether two URLs point at the same visible page for safe fragment refresh.
 *
 * @param {string} left First URL candidate.
 * @param {string} right Second URL candidate.
 * @return {boolean} True when path and query match after URL normalization.
 */
function adminSidePanelSamePageUrl(left, right) {
    try {
        const leftUrl = new URL(left, window.location.href);
        const rightUrl = new URL(right, window.location.href);
        leftUrl.hash = '';
        rightUrl.hash = '';
        return leftUrl.toString() === rightUrl.toString();
    } catch (error) {
        return false;
    }
}

/**
 * Switch the open side panel from create/upload mode to the editor for the newly created gallery.
 *
 * @param {Record<string, *>} result Server response containing the created gallery edit URL.
 * @return {Promise<boolean>} True when the editor was loaded into the side panel.
 */
async function switchAdminSidePanelToGalleryEditor(result) {
    const panel = document.querySelector('[data-admin-side-panel]');
    const editUrl = String(result.edit_url || '');
    if (!(panel instanceof HTMLElement) || editUrl === '') {
        return false;
    }
    panel.dataset.adminSidePanelWorkflow = 'gallery-edit';
    panel.dataset.adminSidePanelSourceUrl = editUrl;
    panel.classList.add('is-edit-panel');
    setAdminGallerySidePanelHeading(panel, 'Gallery editor', String(result.gallery_title || 'Edit gallery'));
    writeAdminGallerySidePanelStatus(panel, 'Loading gallery editor...', false);
    const refreshed = await refreshAdminSidePanelFromServer(editUrl);
    writeAdminGallerySidePanelStatus(panel, '', false);
    return refreshed;
}

/**
 * Reflect the created child gallery in the currently visible public page.
 *
 * The persisted server render remains the source of truth for ordering, covers,
 * image counts, admin controls, and pagination after side-panel creation.
 *
 * @param {Record<string, *>} result Server response for the created gallery.
 * @return {Promise<void>} Resolves after the current page context is refreshed.
 */
async function reflectCreatedGalleryInCurrentView(result) {
    const galleryUrl = String(result.gallery_url || '');
    const galleryTitle = String(result.gallery_title || 'New gallery');
    const galleryId = String(result.gallery_id || '');
    const refreshUrl = String(result.refresh_url || result.parent_gallery_url || '');
    if (!galleryUrl) {
        showAdminGallerySidePanelResultNotice(galleryTitle, '');
        return;
    }

    if (String(result.edit_url || '') !== '') {
        await switchAdminSidePanelToGalleryEditor(result);
    }

    if (refreshUrl !== '' && !adminSidePanelSamePageUrl(refreshUrl, window.location.href)) {
        showAdminGallerySidePanelResultNotice(galleryTitle, galleryUrl);
        return;
    }

    const refreshed = await refreshPublicSubgallerySectionFromServer(galleryId);
    if (refreshed) {
        showAdminGallerySidePanelResultNotice(String(result.message || galleryTitle), galleryUrl);
        return;
    }

    let grid = document.querySelector('[data-public-subgallery-grid]');
    if (!(grid instanceof HTMLElement)) {
        grid = createPublicSubgallerySection();
    }
    if (!(grid instanceof HTMLElement)) {
        showAdminGallerySidePanelResultNotice(galleryTitle, galleryUrl);
        return;
    }

    const card = document.createElement('article');
    card.className = 'gallery-card';
    card.dataset.galleryId = galleryId;
    card.dataset.sidePanelCreatedGallery = 'true';
    card.innerHTML = `
        <a class="gallery-card-link" href="${escapeHtmlAttribute(galleryUrl)}">
            <span class="gallery-card-body">
                <h2>${escapeHtmlText(galleryTitle)}</h2>
                <p class="muted">Created just now</p>
            </span>
        </a>`;
    grid.prepend(card);
    card.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}

/**
 * Refresh the currently visible gallery or admin editor behind the side panel.
 *
 * @param {string} sourceUrl Optional public gallery URL returned by the server.
 * @return {Promise<boolean>} True when at least one visible region was replaced.
 */
async function refreshCurrentGalleryContextFromServer(sourceUrl = '') {
    try {
        const source = document.querySelector('main.site-main .admin-edit-gallery-hero') ? window.location.href : (sourceUrl || window.location.href);
        const fetchUrl = new URL(source, window.location.href);
        fetchUrl.searchParams.set('_panel_refresh', String(Date.now()));
        const response = await fetch(fetchUrl.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (!response.ok || html.trim() === '') {
            return false;
        }
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        let replaced = false;
        let publicGalleryReplaced = false;
        replaced = replaceAdminEditorMainFromParsedDocument(parsed) || replaced;
        publicGalleryReplaced = replacePublicGalleryFragmentsFromParsedDocument(parsed);
        replaced = publicGalleryReplaced || replaced;
        if (replaced) {
            setupAdminTabs(document);
            setupAdminImageReordering();
            if (publicGalleryReplaced) {
                rebindPublicGalleryLifecycleAfterRefresh();
            } else {
                setupPublicGalleryPageReordering();
            }
        }
        return replaced;
    } catch (error) {
        return false;
    }
}

/**
 * Replace the full admin editor main area behind an open side panel.
 *
 * @param {Document} parsed Fresh server-rendered document.
 * @return {boolean} True when the editor main area was replaced.
 */
function replaceAdminEditorMainFromParsedDocument(parsed) {
    const currentMain = document.querySelector('main.site-main');
    const freshMain = parsed.querySelector('main.site-main');
    if (!(currentMain instanceof HTMLElement) || !(freshMain instanceof HTMLElement)) {
        return false;
    }
    if (!currentMain.querySelector('.admin-edit-gallery-hero') || !freshMain.querySelector('.admin-edit-gallery-hero')) {
        return false;
    }
    const activeTabId = activeAdminTabId(currentMain);
    currentMain.innerHTML = freshMain.innerHTML;
    setupAdminTabsInRoot(currentMain);
    setupAdminNestedTabs(currentMain);
    if (activeTabId) {
        activateAdminTabInRoot(currentMain, activeTabId);
    }
    return true;
}

/**
 * Replace public gallery lists behind an open side panel.
 *
 * @param {Document} parsed Fresh server-rendered document.
 * @return {boolean} True when any public gallery fragment was replaced.
 */
function replacePublicGalleryFragmentsFromParsedDocument(parsed) {
    let replaced = false;
    const currentHero = document.querySelector('.hero');
    const freshHero = parsed.querySelector('.hero');
    if (currentHero instanceof HTMLElement && freshHero instanceof HTMLElement) {
        currentHero.replaceWith(freshHero);
        replaced = true;
    }

    const currentFrame = document.querySelector('[data-back-to-top-scope]');
    const freshFrame = parsed.querySelector('[data-back-to-top-scope]');
    const frameChanged = replacePublicGalleryFrame(currentFrame, freshFrame);
    if (frameChanged) {
        document.dispatchEvent(new CustomEvent('php-gallery:public-content-replaced'));
        return true;
    }

    const fragmentPairs = [
        ['[data-public-subgallery-section]', '[data-public-subgallery-section]'],
        ['[data-gallery-image-list]', '[data-gallery-image-list]'],
    ];
    fragmentPairs.forEach(([currentSelector, freshSelector]) => {
        const current = document.querySelector(currentSelector);
        const fresh = parsed.querySelector(freshSelector);
        if (current instanceof HTMLElement && fresh instanceof HTMLElement) {
            teardownPublicGalleryLifecycleBeforeRefresh();
            current.replaceWith(fresh);
            replaced = true;
        } else if (current instanceof HTMLElement && !(fresh instanceof HTMLElement)) {
            teardownPublicGalleryLifecycleBeforeRefresh();
            current.remove();
            replaced = true;
        }
    });
    if (replaced) {
        document.dispatchEvent(new CustomEvent('php-gallery:public-content-replaced'));
    }
    return replaced;
}

/**
 * Releases browser-side public gallery bindings before server-rendered content is replaced.
 */
function teardownPublicGalleryLifecycleBeforeRefresh() {
    teardownPictureManager();
    teardownResponsiveThumbnailSizes();
    teardownBackToTopButton();
    teardownGalleryLightbox();
}

/**
 * Recreates browser-side public gallery bindings after server-rendered content is replaced.
 */
function rebindPublicGalleryLifecycleAfterRefresh() {
    setupPictureManager();
    setupResponsiveThumbnailSizes();
    setupBackToTopButton();
    setupGalleryLightbox();
    setupPublicGalleryPageReordering();
}

/**
 * Refreshes the public gallery frame while keeping stable controls outside the replaced content.
 *
 * @param {Element|null} currentFrame Current public gallery frame.
 * @param {Element|null} freshFrame Fresh public gallery frame from the server-rendered response.
 * @return {boolean} True when the public gallery frame changed.
 */
function replacePublicGalleryFrame(currentFrame, freshFrame) {
    if (currentFrame instanceof HTMLElement && freshFrame instanceof HTMLElement) {
        teardownPublicGalleryLifecycleBeforeRefresh();
        replacePublicGalleryFrameChildren(currentFrame, freshFrame);
        return true;
    }
    if (currentFrame instanceof HTMLElement && !(freshFrame instanceof HTMLElement)) {
        teardownPublicGalleryLifecycleBeforeRefresh();
        currentFrame.remove();
        return true;
    }
    if (!(currentFrame instanceof HTMLElement) && freshFrame instanceof HTMLElement) {
        const main = document.querySelector('main.site-main');
        const lightbox = main instanceof HTMLElement ? main.querySelector('[data-lightbox]') : null;
        if (lightbox instanceof HTMLElement) {
            lightbox.insertAdjacentElement('beforebegin', freshFrame);
            return true;
        }
        if (main instanceof HTMLElement) {
            main.append(freshFrame);
            return true;
        }
    }
    return false;
}

/**
 * Replaces frame content without discarding the existing back-to-top shell.
 *
 * @param {HTMLElement} currentFrame Current public gallery frame.
 * @param {HTMLElement} freshFrame Fresh public gallery frame from the server-rendered response.
 */
function replacePublicGalleryFrameChildren(currentFrame, freshFrame) {
    copyElementIdentity(currentFrame, freshFrame);

    const currentButton = currentFrame.querySelector('[data-back-to-top-button]');
    const freshButton = freshFrame.querySelector('[data-back-to-top-button]');
    if (freshButton instanceof HTMLElement) {
        freshButton.remove();
    }

    Array.from(currentFrame.childNodes).forEach((node) => {
        if (node !== currentButton) {
            node.remove();
        }
    });

    const anchor = currentButton instanceof HTMLElement ? currentButton : null;
    Array.from(freshFrame.childNodes).forEach((node) => {
        if (anchor) {
            currentFrame.insertBefore(node, anchor);
        } else {
            currentFrame.appendChild(node);
        }
    });

    if (!(currentButton instanceof HTMLElement) && freshButton instanceof HTMLElement) {
        currentFrame.appendChild(freshButton);
    }
}

/**
 * Copies attributes from a fresh server-rendered element to a persistent element.
 *
 * @param {HTMLElement} current Current element kept in the live document.
 * @param {HTMLElement} fresh Fresh element parsed from the server response.
 */
function copyElementIdentity(current, fresh) {
    Array.from(current.attributes).forEach((attribute) => {
        if (!fresh.hasAttribute(attribute.name)) {
            current.removeAttribute(attribute.name);
        }
    });
    Array.from(fresh.attributes).forEach((attribute) => {
        current.setAttribute(attribute.name, attribute.value);
    });
}

/**
 * Refresh the visible subgallery area from the current server-rendered page.
 *
 * @param {string} galleryId Newly created gallery id used for scroll targeting.
 * @return {Promise<boolean>} True when the page fragment was refreshed.
 */
async function refreshPublicSubgallerySectionFromServer(galleryId) {
    try {
        const response = await fetch(window.location.href, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (!response.ok || html.trim() === '') {
            return false;
        }
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const replaced = replacePublicGalleryFragmentsFromParsedDocument(parsed);
        if (!replaced) {
            return false;
        }
        rebindPublicGalleryLifecycleAfterRefresh();
        focusCreatedGalleryCard(galleryId);
        return true;
    } catch (error) {
        return false;
    }
}

/**
 * Scroll the newly created gallery card into view after fragment replacement.
 *
 * @param {string} galleryId Newly created gallery id.
 */
function focusCreatedGalleryCard(galleryId) {
    if (!galleryId) {
        return;
    }
    const card = document.querySelector(`[data-gallery-id="${CSS.escape(galleryId)}"]`);
    if (card instanceof HTMLElement) {
        card.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
}

/**
 * Create a subgallery section when the current gallery did not have children yet.
 *
 * @return {HTMLElement|null} New or existing subgallery grid.
 */
function createPublicSubgallerySection() {
    const main = document.querySelector('main.site-main');
    if (!(main instanceof HTMLElement)) {
        return null;
    }
    const section = document.createElement('section');
    section.className = 'panel';
    section.dataset.publicSubgallerySection = 'true';
    section.innerHTML = `<h2>${escapeHtmlText(i18n('admin.side_panel.subgalleries', 'Subgalleries'))}</h2><div class="grid" data-public-subgallery-grid></div>`;

    const insertionPoint = main.querySelector('.gallery-list-frame, [data-gallery-image-list], [data-lightbox]');
    if (insertionPoint instanceof HTMLElement) {
        insertionPoint.insertAdjacentElement('beforebegin', section);
    } else {
        main.append(section);
    }
    return section.querySelector('[data-public-subgallery-grid]');
}

/**
 * Show a compact success notice when a card cannot be inserted safely.
 *
 * @param {string} message Message value.
 * @param {string} targetUrl Target url URL.
 */
function showAdminGallerySidePanelResultNotice(message, targetUrl) {
    const main = document.querySelector('main.site-main');
    if (!(main instanceof HTMLElement)) {
        return;
    }
    const notice = document.createElement('div');
    notice.className = 'notice';
    notice.innerHTML = targetUrl
        ? `${escapeHtmlText(message)} <a href="${escapeHtmlAttribute(targetUrl)}">Open</a>.`
        : `${escapeHtmlText(message)}.`;
    main.prepend(notice);
}


/**
 * Return a readable byte count for upload progress text.
 *
 * @param {*} value Byte count value.
 * @return {string} Human-readable byte count.
 */
function formatFileSize(value) {
    const bytes = Math.max(0, Number(value || 0));
    if (bytes < 1024) {
        return `${Math.round(bytes)} B`;
    }
    const units = ['KB', 'MB', 'GB', 'TB'];
    let number = bytes / 1024;
    let unitIndex = 0;
    while (number >= 1024 && unitIndex < units.length - 1) {
        number /= 1024;
        unitIndex++;
    }
    const digits = number >= 100 || unitIndex === 0 ? 0 : 1;
    return `${number.toFixed(digits)} ${units[unitIndex]}`;
}

/**
 * Return source-file totals for the classic upload path.
 *
 * @param {File[]} files Selected files.
 * @return {Record<string, number>} Progress state.
 */
function createClassicUploadProgressState(files) {
    return {
        totalFiles: files.length,
        totalBytes: files.reduce((sum, file) => sum + Number(file.size || 0), 0),
        uploadedFiles: 0,
        uploadedBytes: 0,
        currentFileIndex: 0,
        currentFileBytes: 0,
        currentFileUploadedBytes: 0,
    };
}

/**
 * Return a compact progress metrics string for classic uploads.
 *
 * @param {Record<string, number>} state Progress state.
 * @return {string} Metrics label.
 */
function classicUploadProgressMetrics(state) {
    const parts = [
        `Pictures uploaded ${state.uploadedFiles}/${state.totalFiles}`,
        `source data ${formatFileSize(state.uploadedBytes)} / ${formatFileSize(state.totalBytes)}`,
    ];
    if (state.currentFileBytes > 0) {
        parts.push(`current picture ${state.currentFileIndex}: ${formatFileSize(state.currentFileUploadedBytes)} / ${formatFileSize(state.currentFileBytes)}`);
    }
    return parts.join(' | ');
}

/**
 * Append server-reported upload events to the rolling progress log.
 *
 * @param {HTMLElement} progress Progress container.
 * @param {Array<Record<string, *>>} events Server events.
 */
function appendServerUploadEvents(progress, events) {
    if (!Array.isArray(events)) {
        return;
    }
    events.forEach((event) => {
        const message = String(event.message || '').trim();
        if (message === '') {
            return;
        }
        const elapsed = Number(event.elapsed_ms || 0);
        appendUploadProgressLog(progress, elapsed > 0 ? `Server: ${message} (${elapsed} ms)` : `Server: ${message}`);
    });
}

/**
 * Handles selected gallery upload files behavior for the gallery UI.
 *
 * @param {*} form Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
 */
function selectedGalleryUploadFiles(form) {
    // fileInput stores state or configuration for the gallery front-end flow.
    const fileInput = form.querySelector('input[type="file"][name="images[]"]');
    if (!(fileInput instanceof HTMLInputElement) || !fileInput.files || fileInput.files.length === 0) {
        return [];
    }
    return Array.from(fileInput.files)
        .filter((file) => file instanceof File)
        .sort(compareGalleryUploadFilesByDefaultFolderOrder);
}

/**
 * Compare selected files by deterministic folder/name order for classic uploads.
 *
 * @param {File} left Left selected file.
 * @param {File} right Right selected file.
 * @return {number} Sort comparison result.
 */
function compareGalleryUploadFilesByDefaultFolderOrder(left, right) {
    const comparison = galleryUploadFileNameCollator().compare(galleryUploadFileOrderKey(left), galleryUploadFileOrderKey(right));
    if (comparison !== 0) {
        return comparison;
    }
    return galleryUploadFileNameCollator().compare(String(left.name || ''), String(right.name || ''));
}

/**
 * Return the stable path/name key used for upload ordering.
 *
 * @param {File} file Selected browser file.
 * @return {string} Folder-relative path when available, otherwise filename.
 */
function galleryUploadFileOrderKey(file) {
    const relativePath = typeof file.webkitRelativePath === 'string' ? file.webkitRelativePath : '';
    const key = relativePath.trim() !== '' ? relativePath : String(file.name || '');
    return key.replace(/\\/g, '/');
}

/**
 * Return a cached natural filename collator for upload order.
 *
 * @return {Intl.Collator} Collator used for source file ordering.
 */
function galleryUploadFileNameCollator() {
    if (!galleryUploadFileNameCollator.instance) {
        galleryUploadFileNameCollator.instance = new Intl.Collator(undefined, {
            numeric: true,
            sensitivity: 'base',
        });
    }
    return galleryUploadFileNameCollator.instance;
}

/**
 * Handles gallery upload base body behavior for the gallery UI.
 *
 * @param {*} form Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
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
 *
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} files Value supplied by the caller or event context.
 * @param {*} galleryId Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
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
 *
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} progress Value supplied by the caller or event context.
 * @param {*} createThumbnails Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
 */
async function runGalleryUploadFiles(form, progress, createThumbnails) {
    // files stores state or configuration for the gallery front-end flow.
    const files = selectedGalleryUploadFiles(form);
    const allowEmptyPanelGallery = form.dataset.galleryPanelCloseOnSuccess === '1' && String(form.querySelector('input[name="upload_mode"]')?.value || '') === 'new';
    if (files.length === 0 && !allowEmptyPanelGallery) {
        throw new Error(i18n('admin.side_panel.choose_image_upload', 'Choose at least one image to upload.'));
    }

    if (files.length === 0 && allowEmptyPanelGallery) {
        updateBasicProgress(progress, 20, i18n('admin.side_panel.creating_gallery', 'Creating gallery...'));
        const emptyResult = await sendGalleryUploadChunk(form, galleryUploadBaseBody(form), () => {});
        updateBasicProgress(progress, 100, i18n('admin.side_panel.gallery_created', 'Gallery created.'));
        return {
            ok: true,
            gallery_id: Number(emptyResult.gallery_id || 0),
            gallery_ids: emptyResult.gallery_id ? [Number(emptyResult.gallery_id)] : [],
            gallery_title: String(emptyResult.gallery_title || ''),
            gallery_url: String(emptyResult.gallery_url || ''),
            edit_url: String(emptyResult.edit_url || ''),
            parent_gallery_id: Number(emptyResult.parent_gallery_id || 0),
            parent_gallery_url: String(emptyResult.parent_gallery_url || ''),
            refresh_gallery_id: Number(emptyResult.refresh_gallery_id || 0),
            refresh_url: String(emptyResult.refresh_url || ''),
            uploaded: 0,
            scanned: 0,
            thumbnails: 0,
            thumbnail_skipped: 0,
            thumbnail_failed: 0,
            thumbnail_errors: [],
            total_files: 0,
            redirect_url: String(emptyResult.redirect_url || ''),
        };
    }

    const progressState = createClassicUploadProgressState(files);
    appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_selected', 'Selected {count} image(s), {bytes} source data.', {count: files.length, bytes: formatFileSize(progressState.totalBytes)}));
    updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));

    // uploaded stores state or configuration for the gallery front-end flow.
    let uploaded = 0;
    // scanned stores state or configuration for the gallery front-end flow.
    let scanned = 0;
    // thumbnails stores state or configuration for the gallery front-end flow.
    let thumbnails = 0;
    // thumbnailSkipped stores state or configuration for the gallery front-end flow.
    let thumbnailSkipped = 0;
    // thumbnailFailed stores required derivatives that could not be generated.
    let thumbnailFailed = 0;
    // thumbnailErrors stores concise diagnostics returned by the server.
    const thumbnailErrors = [];
    // galleryId stores state or configuration for the gallery front-end flow.
    let galleryId = Number(form.querySelector('select[name="gallery_id"]')?.value || 0);
    // redirectUrl stores state or configuration for the gallery front-end flow.
    let redirectUrl = '';
    // galleryIds stores state or configuration for the gallery front-end flow.
    const galleryIds = [];
    // galleryTitle stores the created or selected gallery title reported by the first upload response.
    let galleryTitle = '';
    // galleryUrl stores the public gallery URL reported by the first upload response.
    let galleryUrl = '';
    // editUrl stores the admin edit URL reported by the first upload response.
    let editUrl = '';
    // refreshUrl stores the page that should redraw the visible context behind the panel.
    let refreshUrl = '';
    // parentGalleryUrl stores the selected parent public URL for newly-created galleries.
    let parentGalleryUrl = '';
    // parentGalleryId stores the selected parent identifier for newly-created galleries.
    let parentGalleryId = 0;

    for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
        // file stores state or configuration for the gallery front-end flow.
        const file = files[fileIndex];
        // humanIndex stores state or configuration for the gallery front-end flow.
        const humanIndex = fileIndex + 1;
        const uploadedBeforeFile = progressState.uploadedBytes;
        progressState.currentFileIndex = humanIndex;
        progressState.currentFileBytes = Number(file.size || 0);
        progressState.currentFileUploadedBytes = 0;
        updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
        updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));
        appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_uploading_file', 'Uploading picture {current}/{total}: {name}, {bytes}.', {current: humanIndex, total: files.length, name: file.name, bytes: formatFileSize(file.size || 0)}));
        // uploadResult stores state or configuration for the gallery front-end flow.
        const uploadResult = await sendGalleryUploadChunk(form, cloneGalleryUploadBody(form, [file], galleryId), (event) => {
            if (!event.lengthComputable) {
                updateBasicProgress(progress, Math.round((fileIndex / files.length) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
                updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));
                return;
            }
            // completedPart stores state or configuration for the gallery front-end flow.
            const completedPart = fileIndex / files.length;
            // currentPart stores state or configuration for the gallery front-end flow.
            const currentPart = (event.loaded / event.total) / files.length;
            const ratio = Math.max(0, Math.min(1, event.loaded / event.total));
            progressState.currentFileUploadedBytes = Math.round(Number(file.size || 0) * ratio);
            progressState.uploadedBytes = uploadedBeforeFile + progressState.currentFileUploadedBytes;
            updateBasicProgress(progress, Math.round((completedPart + currentPart) * 100), `Uploading ${humanIndex} of ${files.length}: ${file.name}`);
            updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));
        });
        appendServerUploadEvents(progress, uploadResult.upload_events || []);
        progressState.uploadedFiles = humanIndex;
        progressState.uploadedBytes = uploadedBeforeFile + Number(file.size || 0);
        progressState.currentFileUploadedBytes = Number(file.size || 0);
        updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));
        appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_uploaded_file', 'Finished picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));

        if (!galleryId) {
            galleryId = Number(uploadResult.gallery_id || 0);
        }
        if (galleryId && !galleryIds.includes(galleryId)) {
            galleryIds.push(galleryId);
        }
        uploaded += Number(uploadResult.uploaded || 0);
        scanned += Number(uploadResult.scanned || 0);
        redirectUrl = uploadResult.redirect_url || redirectUrl;
        galleryTitle = galleryTitle || String(uploadResult.gallery_title || '');
        galleryUrl = galleryUrl || String(uploadResult.gallery_url || '');
        editUrl = editUrl || String(uploadResult.edit_url || '');
        refreshUrl = refreshUrl || String(uploadResult.refresh_url || '');
        parentGalleryUrl = parentGalleryUrl || String(uploadResult.parent_gallery_url || '');
        parentGalleryId = parentGalleryId || Number(uploadResult.parent_gallery_id || 0);

        if (createThumbnails) {
            // imageIds stores state or configuration for the gallery front-end flow.
            const imageIds = Array.isArray(uploadResult.image_ids) ? uploadResult.image_ids : [];
            appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_thumbnails_started', 'Creating server thumbnails for picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));
            // thumbResult stores state or configuration for the gallery front-end flow.
            const thumbResult = await runUploadedImageThumbnailJob(form, progress, imageIds, humanIndex, files.length, file.name, thumbnails, thumbnailSkipped);
            appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_thumbnails_finished', 'Thumbnail job finished for picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));
            thumbnails += Number(thumbResult.created || 0);
            thumbnailSkipped += Number(thumbResult.skipped || 0);
            thumbnailFailed += Number(thumbResult.failed || 0);
            if (Array.isArray(thumbResult.errors)) {
                thumbResult.errors.forEach((message) => thumbnailErrors.push(String(message)));
            }
        }
    }

    return {
        ok: true,
        gallery_id: galleryId,
        gallery_ids: galleryIds.length > 0 ? galleryIds : (galleryId ? [galleryId] : []),
        gallery_title: galleryTitle,
        gallery_url: galleryUrl,
        edit_url: editUrl,
        parent_gallery_id: parentGalleryId,
        parent_gallery_url: parentGalleryUrl,
        refresh_gallery_id: parentGalleryId,
        refresh_url: refreshUrl,
        uploaded,
        scanned,
        thumbnails,
        thumbnail_skipped: thumbnailSkipped,
        thumbnail_failed: thumbnailFailed,
        thumbnail_errors: Array.from(new Set(thumbnailErrors.filter(Boolean))),
        total_files: files.length,
        redirect_url: appendUploadResultParams(redirectUrl, uploaded, scanned, thumbnails, thumbnailFailed),
    };
}

/**
 * Handles append upload result params behavior for the gallery UI.
 *
 * @param {*} urlValue Value supplied by the caller or event context.
 * @param {*} uploaded Value supplied by the caller or event context.
 * @param {*} scanned Value supplied by the caller or event context.
 * @param {*} thumbnails Value supplied by the caller or event context.
 * @param {*} thumbnailFailed Thumbnail failed value.
 * @return {*} Result of the UI operation, when a value is produced.
 */
function appendUploadResultParams(urlValue, uploaded, scanned, thumbnails, thumbnailFailed = 0) {
    // url stores state or configuration for the gallery front-end flow.
    const url = new URL(urlValue || window.location.href, window.location.href);
    url.searchParams.set('uploaded', String(uploaded));
    url.searchParams.set('scanned', String(scanned));
    url.searchParams.set('thumbnails', String(thumbnails));
    if (thumbnailFailed > 0) {
        url.searchParams.set('thumbnail_failed', String(thumbnailFailed));
    }
    return url.toString();
}

/**
 * Handles send gallery upload chunk behavior for the gallery UI.
 *
 * @param {Response} response Response data.
 * @param {string} fallbackMessage Fallback message value.
 * @return {*} Result of the UI operation, when a value is produced.
 */
async function readJsonResponseSafely(response, fallbackMessage) {
    // contentType stores state or configuration for the gallery front-end flow.
    const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
    // responseText stores state or configuration for the gallery front-end flow.
    const responseText = await response.text();
    try {
        return JSON.parse(responseText || '{}');
    } catch (error) {
        // snippet stores state or configuration for the gallery front-end flow.
        const snippet = responseText.trim().slice(0, 180).replace(/\s+/g, ' ');
        if (!contentType.includes('application/json') && snippet.includes('Maximum number of allowable file uploads exceeded')) {
            throw new Error(i18n('admin.side_panel.php_upload_limit', 'The server refused too many files in one request. Upload batching is enabled, but this server returned the PHP upload-limit warning before processing the request.'));
        }
        if (snippet.startsWith('<')) {
            throw new Error(i18n('admin.side_panel.html_instead_json', '{message} The server returned HTML instead of JSON. Check the admin logs or PHP error log for the exact warning.', {message: fallbackMessage}));
        }
        throw new Error(snippet || fallbackMessage);
    }
}

/**
 * Handles send gallery upload chunk behavior for the gallery UI.
 *
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} body Value supplied by the caller or event context.
 * @param {*} progressHandler Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
 */
function sendGalleryUploadChunk(form, body, progressHandler) {
    return new Promise((resolve, reject) => {
        // xhr stores state or configuration for the gallery front-end flow.
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', progressHandler);
        xhr.addEventListener('load', async () => {
            try {
                // response stores state or configuration for the gallery front-end flow.
                const response = new Response(xhr.responseText || '', {
                    status: xhr.status,
                    headers: {'Content-Type': xhr.getResponseHeader('Content-Type') || ''},
                });
                // result stores state or configuration for the gallery front-end flow.
                const result = await readJsonResponseSafely(response, i18n('admin.side_panel.upload_failed', 'Upload failed.'));
                if (xhr.status < 200 || xhr.status >= 300 || !result.ok) {
                    throw new Error(result.error || i18n('admin.side_panel.upload_failed', 'Upload failed.'));
                }
                resolve(result);
            } catch (error) {
                reject(error);
            }
        });
        xhr.addEventListener('error', () => {
            reject(new Error(i18n('admin.side_panel.upload_failed', 'Upload failed.')));
        });
        xhr.send(body);
    });
}

/**
 * Handles run uploaded image thumbnail job behavior for the gallery UI.
 *
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} progress Value supplied by the caller or event context.
 * @param {*} imageIds Value supplied by the caller or event context.
 * @param {*} fileIndex Value supplied by the caller or event context.
 * @param {*} totalFiles Value supplied by the caller or event context.
 * @param {*} filename Value supplied by the caller or event context.
 * @param {*} createdBefore Value supplied by the caller or event context.
 * @param {*} skippedBefore Value supplied by the caller or event context.
 * @return {*} Result of the UI operation, when a value is produced.
 */
async function runUploadedImageThumbnailJob(form, progress, imageIds, fileIndex, totalFiles, filename, createdBefore, skippedBefore) {
    if (!imageIds.length) {
        updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore, skippedBefore, i18n('admin.side_panel.upload_no_image_record', 'Uploaded {current} of {total}: {filename}. No database image record was returned for thumbnails.', {current: fileIndex, total: totalFiles, filename}));
        return {created: 0, skipped: 0, failed: 0, errors: []};
    }

    // offset stores state or configuration for the gallery front-end flow.
    let offset = 0;
    // total stores state or configuration for the gallery front-end flow.
    let total = 0;
    // created stores state or configuration for the gallery front-end flow.
    let created = 0;
    // skipped stores state or configuration for the gallery front-end flow.
    let skipped = 0;
    // failed stores required derivatives that could not be generated.
    let failed = 0;
    // errors stores concise server diagnostics for this image.
    const errors = [];
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
        // result stores state or configuration for the gallery front-end flow.
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.thumbnail_request_failed', 'Thumbnail request failed.'));
        if (!response.ok || result.ok === false) {
            // message stores state or configuration for the gallery front-end flow.
            const message = result.error || i18n('admin.side_panel.thumbnail_request_failed', 'Thumbnail request failed.');
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, i18n('admin.side_panel.upload_with_message', 'Uploaded {current} of {total}: {filename}. {message}', {current: fileIndex, total: totalFiles, filename, message}));
            return {
                created,
                skipped,
                failed: Math.max(1, failed),
                errors: Array.from(new Set([...errors, message].filter(Boolean))),
            };
        }
        total = result.total || imageIds.length;
        offset = result.next_offset || 0;
        created += result.created || 0;
        skipped += result.skipped || 0;
        failed += result.failed || 0;
        if (Array.isArray(result.errors)) {
            result.errors.forEach((message) => errors.push(String(message)));
        }
        updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. Creating thumbnails ${Math.min(offset, total)} of ${total}...`);
        if (result.done) {
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Finished ${fileIndex} of ${totalFiles}: ${filename}`);
            return {created, skipped, failed, errors: Array.from(new Set(errors.filter(Boolean)))};
        }
    }
}
