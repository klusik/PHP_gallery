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

import { setupImageBulkMoveFields } from './admin-bulk-actions.js?v=20260509-image-move-v2';
import { setupBackToTopButton, teardownBackToTopButton } from './back-to-top.js?v=20260510-lifecycle-v3';
import { setupGalleryLightbox, setupTagSuggestions, teardownGalleryLightbox } from './lightbox-deferred.js?v=20260512-tag-whisperer-v1';
import { setupResponsiveThumbnailSizes, teardownResponsiveThumbnailSizes } from './responsive-thumbnails.js?v=20260510-lazy-map-v1';

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

/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @returns {string} Browser-facing translated text.
 */
function i18n(key, fallback, parameters = {}) {
    const root = window.PHP_GALLERY_I18N && typeof window.PHP_GALLERY_I18N === 'object' ? window.PHP_GALLERY_I18N : {};
    const strings = root.strings && typeof root.strings === 'object' ? root.strings : {};
    let text = typeof strings[key] === 'string' ? strings[key] : fallback;
    Object.entries(parameters).forEach(([name, value]) => {
        text = text.split(`{${name}}`).join(String(value));
    });
    return text;
}

export function setupAdminTabs(root = document) {
    setupAdminTabsInRoot(root);
}

/**
 * Attach admin tab behavior inside one document area.
 *
 * @param {ParentNode} root DOM root that contains admin tab controls.
 * @returns {void}
 */
function setupAdminTabsInRoot(root) {
    root.querySelectorAll('[data-admin-tabs]').forEach((tabsRoot) => {
        if (!(tabsRoot instanceof HTMLElement) || tabsRoot.dataset.adminTabsBound === '1') {
            return;
        }
        tabsRoot.dataset.adminTabsBound = '1';
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
        // shouldManageHash stores whether this tab group owns the browser URL hash.
        const shouldManageHash = !tabsRoot.closest('[data-admin-side-panel]');

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
            if (options.updateHash && shouldManageHash) {
                const nextHash = `#${targetPanel.id}`;
                if (window.location.hash !== nextHash) {
                    window.history.pushState(null, '', nextHash);
                }
            }
            tabsRoot.closest('form, [data-admin-side-panel-body], main')?.querySelectorAll('input[type="hidden"][name="return_tab"]').forEach((input) => {
                if (input instanceof HTMLInputElement) {
                    input.value = targetPanel.id;
                }
            });
        };

        // activeHash stores the hash that should select the initial tab.
        const activeHash = shouldManageHash ? normalizedAdminTabHash(window.location.hash) : '';
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

        if (!shouldManageHash) {
            return;
        }

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
                    button.textContent = i18n('admin.operations.scanning', 'Scanning...');
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
    progress.innerHTML = `<progress class="thumbnail-progress-bar"></progress><p class="muted">${i18n('admin.operations.scan_detail', 'Scanning existing galleries and checking for new gallery folders...')}</p>`;
    // target stores state or configuration for the gallery front-end flow.
    const target = form.closest('.hero') || form;
    target.insertAdjacentElement('afterend', progress);
    return progress;
}

// Function `setupGalleryUploadProgress` executes this focused behavior.
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
        const result = await runGalleryUploadFiles(form, progress, createThumbnails);
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
 * @returns {void}
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
 * @returns {boolean} True when the side panel owns the completion behavior.
 */
function galleryUploadShouldClosePanel(form) {
    return form.dataset.galleryPanelCloseOnSuccess === '1' && Boolean(form.closest('[data-admin-side-panel]'));
}

/**
 * Notify the side-panel controller that an embedded workflow finished.
 *
 * @param {HTMLFormElement} form Completed form.
 * @param {Record<string, *>} result Server response plus client-side aggregate upload data.
 * @returns {void}
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
 *
 * @returns {void}
 */
export function setupAdminGallerySidePanel() {
    if (document.body?.dataset.adminGallerySidePanelBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.adminGallerySidePanelBound = '1';
    }

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
 * @returns {Promise<void>} Resolves after content is loaded or fallback navigation starts.
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
        body.innerHTML = `<div class="notice is-alert">${escapeHtmlText(workflow.loadErrorMessage)} Use the normal admin page instead: <a href="${escapeHtmlAttribute(link.href)}">open directly</a>.</div>`;
    }
}

/**
 * Read side-panel workflow metadata from an enhanced link.
 *
 * @param {HTMLAnchorElement} link Enhanced admin workflow link.
 * @returns {{name: string, kicker: string, title: string, loadingMessage: string, loadErrorMessage: string}} Workflow configuration.
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
 * @returns {void}
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
 * @param {{name: string}} workflow Active side-panel workflow.
 * @returns {string} HTML safe to inject into the panel body.
 */
function sidePanelContentFromHtml(html, workflow) {
    const trimmed = html.trim();
    if (trimmed.startsWith('<div') || trimmed.startsWith('<section')) {
        return trimmed;
    }
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const directFragment = parsed.querySelector('[data-gallery-create-panel], [data-admin-upload-panel]');
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
 * @param {{name: string}} workflow Active side-panel workflow.
 * @param {string} sourceUrl URL that produced the loaded content.
 * @returns {void}
 */
function prepareAdminSidePanelLoadedContent(body, workflow, sourceUrl) {
    setupGalleryUploadProgress();
    setupAdminTabsInRoot(body);
    setupAdminPanelRangeDisplays(body);
    setupAdminPanelThumbnailBoundControls(body);
    setupTagSuggestions(body);
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
            }
        });
    }
}

/**
 * Mark one loaded admin form as side-panel owned and fix its action URL.
 *
 * @param {Element|null} formCandidate Loaded form candidate.
 * @param {string} workflowName Active workflow name.
 * @param {string} sourceUrl URL that should receive the POST.
 * @returns {void}
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
 * @returns {void}
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
 * @returns {void}
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
        const sync = () => {
            display.textContent = control.value;
        };
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
 * @returns {void}
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
        const formatSize = (value, side) => value === 0 ? (side === 'min' ? 'Auto min' : 'Auto max') : `${value}px`;
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
 * @returns {HTMLElement} Side-panel root.
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
 * @returns {void}
 */
function openAdminGallerySidePanelShell(panel) {
    panel.hidden = false;
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-admin-side-panel');
}

/**
 * Hide the side panel and clear its transient status.
 *
 * @param {HTMLElement} panel Side-panel root.
 * @returns {void}
 */
function closeAdminGallerySidePanel(panel) {
    panel.hidden = true;
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-admin-side-panel');
    writeAdminGallerySidePanelStatus(panel, '', false);
}

/**
 * Write a visible or screen-reader-only side-panel status message.
 *
 * @param {HTMLElement} panel Side-panel root.
 * @param {string} message Status text.
 * @param {boolean} isError Whether the message should be styled as an error.
 * @returns {void}
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
 * @returns {Promise<void>} Resolves after success handling or error reporting.
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
 * Submit an existing admin edit form through the side-panel JSON path.
 *
 * @param {HTMLFormElement} form Side-panel edit form.
 * @returns {Promise<void>} Resolves after success handling or error reporting.
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
    writeAdminGallerySidePanelStatus(panel, workflowName === 'image-edit' ? 'Saving photo...' : (workflowName === 'tag-edit' ? 'Saving tag...' : 'Saving gallery...'), false);
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
        const result = await readJsonResponseSafely(response, workflowName === 'image-edit' ? 'Photo save failed.' : (workflowName === 'tag-edit' ? 'Tag save failed.' : 'Gallery save failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || 'Save failed.');
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: workflowName,
                result,
            },
        }));
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || 'Save failed.', true);
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
 * @returns {Promise<void>} Resolves after the bulk action response is handled.
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
        writeAdminGallerySidePanelStatus(panel, 'Select at least one photo first.', true);
        return;
    }
    if (action === '') {
        writeAdminGallerySidePanelStatus(panel, 'Choose a photo action first.', true);
        return;
    }

    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach((button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, action === 'cover' ? 'Saving title picture...' : 'Applying photo action...', false);
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
            const destinationSelect = form.querySelector('select[name="destination_gallery_id"]');
            if (destinationSelect instanceof HTMLSelectElement) {
                body.set('destination_gallery_id', destinationSelect.value);
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
        const result = await readJsonResponseSafely(response, 'Photo action failed.');
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || 'Photo action failed.');
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: 'gallery-image-bulk',
                result,
            },
        }));
    } catch (error) {
        writeAdminGallerySidePanelStatus(panel, error.message || 'Photo action failed.', true);
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
 * @returns {void}
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
        showAdminGallerySidePanelResultNotice(String(result.message || (action === 'delete' ? 'Photo deleted.' : 'Photo move completed.')), noticeTarget);
        return;
    }
        if (action === 'cover' && coverImageId !== '') {
            document.querySelectorAll('[data-admin-image-order-row]').forEach((row) => {
                if (!(row instanceof HTMLElement)) {
                    return;
                }
            const coverCell = row.querySelector('[data-admin-image-cover-cell]');
            if (coverCell instanceof HTMLElement) {
                coverCell.textContent = String(row.dataset.imageId || '') === coverImageId ? 'Title picture' : '';
            }
        });
        await refreshAdminSidePanelFromServer();
        await refreshCurrentGalleryContextFromServer(String(result.refresh_url || result.gallery_url || ''));
    }
    showAdminGallerySidePanelResultNotice(String(result.message || 'Photo action completed.'), String(result.gallery_url || ''));
}

/**
 * Reflect a saved gallery in the current page without forcing a full navigation.
 *
 * @param {Record<string, *>} result Server response for the saved gallery.
 * @returns {void}
 */

/**
 * Reflect a saved tag after editing it from the side panel.
 *
 * Tag slugs can change, so the visible public tag page is refreshed from the
 * returned public URL and the browser URL is updated without opening a separate
 * full admin page.
 *
 * @param {Record<string, *>} result Server response for the saved tag.
 * @returns {Promise<void>} Resolves after the panel and visible page refresh.
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
 * @returns {void}
 */
async function reflectSavedImageInCurrentView(result) {
    const imageId = String(result.image_id || '');
    if (imageId) {
        updateAdminImageRowsFromResult(imageId, result);
    }
    await refreshCurrentGalleryContextFromServer(String(result.refresh_url || result.gallery_url || ''));
    showAdminGallerySidePanelResultNotice(String(result.message || 'Photo saved.'), String(result.image_url || ''));
}

/**
 * Reflect an uploaded gallery batch in the current page without forcing a hard redirect.
 *
 * @param {Record<string, *>} result Server response for the upload operation.
 * @returns {void}
 */
async function reflectUploadedGalleryInCurrentView(result) {
    const message = String(result.message || 'Upload complete.');
    const targetUrl = String(result.gallery_url || '');
    const refreshUrl = String(result.refresh_url || result.parent_gallery_url || result.gallery_url || '');
    showAdminGallerySidePanelResultNotice(message, targetUrl);
    if (Boolean(result.created_gallery) && String(result.edit_url || '') !== '') {
        await switchAdminSidePanelToCreatedGalleryEditor(result);
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
 * @returns {Promise<boolean>} True when the current admin editor was refreshed.
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
        const response = await fetch(resolvedUrl, {
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
 * @returns {string} URL safe to fetch for server-rendered panel HTML.
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
 * Return the active tab id inside one injected admin region.
 *
 * @param {ParentNode|null} root DOM root to inspect.
 * @returns {string} Active tab target id or an empty string.
 */
function activeAdminTabId(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return '';
    }
    const selected = root.querySelector('[role="tab"][data-admin-tab-target][aria-selected="true"]');
    return selected instanceof HTMLElement ? String(selected.dataset.adminTabTarget || '') : '';
}

/**
 * Activate one tab after replacing server-rendered panel HTML.
 *
 * @param {ParentNode} root DOM root that contains admin tabs.
 * @param {string} targetId Tab panel id to show.
 * @returns {void}
 */
function activateAdminTabInRoot(root, targetId) {
    if (!targetId) {
        return;
    }
    const tab = root.querySelector(`[role="tab"][data-admin-tab-target="${CSS.escape(targetId)}"]`);
    if (tab instanceof HTMLElement) {
        tab.click();
    }
}

/**
 * Update admin image table rows that are already visible behind the panel.
 *
 * @param {string} imageId Saved image id.
 * @param {Record<string, *>} result Server response for the saved image.
 * @returns {void}
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
 * @returns {void}
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
 * @returns {boolean} True when path and query match after URL normalization.
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
 * Reflect the created child gallery in the currently visible public page.
 *
 * The persisted server render is the source of truth for ordering, covers, image
 * counts, admin controls, and pagination. After the panel workflow succeeds, the
 * current gallery page is fetched in the background and only the subgallery area
 * is replaced. This keeps the page in context without doing a full navigation.
 *
 * @param {Record<string, *>} result Server response for the created gallery.
 * @returns {void}
 */

/**
 * Switch the open side panel from create/upload mode to the editor for the newly created gallery.
 *
 * @param {Record<string, *>} result Server response containing the created gallery edit URL.
 * @returns {Promise<boolean>} True when the editor was loaded into the side panel.
 */
async function switchAdminSidePanelToCreatedGalleryEditor(result) {
    const panel = document.querySelector('[data-admin-side-panel]');
    const editUrl = String(result.edit_url || '');
    if (!(panel instanceof HTMLElement) || editUrl === '') {
        return false;
    }
    panel.dataset.adminSidePanelWorkflow = 'gallery-edit';
    panel.dataset.adminSidePanelSourceUrl = editUrl;
    panel.classList.add('is-edit-panel');
    setAdminGallerySidePanelHeading(panel, 'Gallery editor', String(result.gallery_title || 'Edit gallery'));
    writeAdminGallerySidePanelStatus(panel, 'Loading created gallery editor...', false);
    const refreshed = await refreshAdminSidePanelFromServer(editUrl);
    writeAdminGallerySidePanelStatus(panel, '', false);
    return refreshed;
}

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
        await switchAdminSidePanelToCreatedGalleryEditor(result);
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
 * @returns {Promise<boolean>} True when at least one visible region was replaced.
 */
async function refreshCurrentGalleryContextFromServer(sourceUrl = '') {
    try {
        const source = document.querySelector('main.site-main .admin-edit-gallery-hero') ? window.location.href : (sourceUrl || window.location.href);
        const response = await fetch(source, {
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
 * @returns {boolean} True when the editor main area was replaced.
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
    if (activeTabId) {
        activateAdminTabInRoot(currentMain, activeTabId);
    }
    return true;
}

/**
 * Replace public gallery lists behind an open side panel.
 *
 * @param {Document} parsed Fresh server-rendered document.
 * @returns {boolean} True when any public gallery fragment was replaced.
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
 *
 * @returns {void}
 */
function teardownPublicGalleryLifecycleBeforeRefresh() {
    teardownResponsiveThumbnailSizes();
    teardownBackToTopButton();
    teardownGalleryLightbox();
}

/**
 * Recreates browser-side public gallery bindings after server-rendered content is replaced.
 *
 * @returns {void}
 */
function rebindPublicGalleryLifecycleAfterRefresh() {
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
 * @returns {boolean} True when the public gallery frame changed.
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
 * @returns {void}
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
 * @returns {void}
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
 * @returns {Promise<boolean>} True when the page fragment was refreshed.
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
 * @returns {void}
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
 * @returns {HTMLElement|null} New or existing subgallery grid.
 */
function createPublicSubgallerySection() {
    const main = document.querySelector('main.site-main');
    if (!(main instanceof HTMLElement)) {
        return null;
    }
    const section = document.createElement('section');
    section.className = 'panel';
    section.dataset.publicSubgallerySection = 'true';
    section.innerHTML = '<h2>Subgalleries</h2><div class="grid" data-public-subgallery-grid></div>';

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
 * @param {string} galleryTitle Created gallery title.
 * @param {string} galleryUrl Public gallery URL.
 * @returns {void}
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
 * Escape HTML text before inserting generated success markup.
 *
 * @param {string} value Raw value.
 * @returns {string} Escaped text.
 */
function escapeHtmlText(value) {
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
function escapeHtmlAttribute(value) {
    return escapeHtmlText(value).replace(/`/g, '&#096;');
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
    const allowEmptyPanelGallery = form.dataset.galleryPanelCloseOnSuccess === '1' && String(form.querySelector('input[name="upload_mode"]')?.value || '') === 'new';
    if (files.length === 0 && !allowEmptyPanelGallery) {
        throw new Error('Choose at least one image to upload.');
    }

    if (files.length === 0 && allowEmptyPanelGallery) {
        updateBasicProgress(progress, 20, 'Creating gallery...');
        const emptyResult = await sendGalleryUploadChunk(form, galleryUploadBaseBody(form), () => {});
        updateBasicProgress(progress, 100, 'Gallery created.');
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
        galleryTitle = galleryTitle || String(uploadResult.gallery_title || '');
        galleryUrl = galleryUrl || String(uploadResult.gallery_url || '');
        editUrl = editUrl || String(uploadResult.edit_url || '');
        refreshUrl = refreshUrl || String(uploadResult.refresh_url || '');
        parentGalleryUrl = parentGalleryUrl || String(uploadResult.parent_gallery_url || '');
        parentGalleryId = parentGalleryId || Number(uploadResult.parent_gallery_id || 0);

        if (createThumbnails) {
            // imageIds stores state or configuration for the gallery front-end flow.
            const imageIds = Array.isArray(uploadResult.image_ids) ? uploadResult.image_ids : [];
            // thumbResult stores state or configuration for the gallery front-end flow.
            const thumbResult = await runUploadedImageThumbnailJob(form, progress, imageIds, humanIndex, files.length, file.name, thumbnails, thumbnailSkipped);
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
 * @param {*} urlValue Value supplied by the caller or event context.
 * @param {*} uploaded Value supplied by the caller or event context.
 * @param {*} scanned Value supplied by the caller or event context.
 * @param {*} thumbnails Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
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
 * @param {*} form Value supplied by the caller or event context.
 * @param {*} body Value supplied by the caller or event context.
 * @param {*} progressHandler Value supplied by the caller or event context.
 * @returns {*} Result of the UI operation, when a value is produced.
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
            throw new Error('The server refused too many files in one request. Upload batching is enabled, but this server returned the PHP upload-limit warning before processing the request.');
        }
        if (snippet.startsWith('<')) {
            throw new Error(`${fallbackMessage} The server returned HTML instead of JSON. Check the admin logs or PHP error log for the exact warning.`);
        }
        throw new Error(snippet || fallbackMessage);
    }
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
        xhr.addEventListener('load', async () => {
            try {
                // response stores state or configuration for the gallery front-end flow.
                const response = new Response(xhr.responseText || '', {
                    status: xhr.status,
                    headers: {'Content-Type': xhr.getResponseHeader('Content-Type') || ''},
                });
                // result stores state or configuration for the gallery front-end flow.
                const result = await readJsonResponseSafely(response, 'Upload failed.');
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
        const result = await readJsonResponseSafely(response, 'Thumbnail request failed.');
        if (!response.ok || result.ok === false) {
            // message stores state or configuration for the gallery front-end flow.
            const message = result.error || 'Thumbnail request failed.';
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. ${message}`);
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

// Function `setupThumbnailProgress` executes this focused behavior.
export function setupThumbnailProgress() {
    document.addEventListener('click', async (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        // Variable `button` stores this steps working value.
        const button = event.target.closest('[data-create-all-thumbnails]');
        if (!button) {
            // Variable `missingButton` stores this steps working value.
            const missingButton = event.target.closest('[data-create-missing-thumbnails]');
            if (!missingButton) {
                return;
            }
            // Variable `missingForm` stores this steps working value.
            const missingForm = document.querySelector('[data-thumbnail-maintenance-form]');
            if (!(missingForm instanceof HTMLFormElement)) {
                return;
            }
            event.preventDefault();
            await runThumbnailJob(missingForm, null, {scope: 'missing'});
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
            await runThumbnailJob(form, null, {scope: 'all'});
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
async function runThumbnailJob(form, submitter, options = {}) {
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
            if (options.scope) {
                body.set('scope', options.scope);
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
            const scopeLabel = options.scope === 'missing' ? 'missing thumbnails' : 'thumbnails';
            updateThumbnailProgress(progress, result.processed || 0, total, created, skipped, `Creating ${scopeLabel}...`);
            if (result.done) {
                // finalLabel keeps an empty targeted maintenance run readable instead of showing only 0/0 counters.
                const finalLabel = options.scope === 'missing' && total === 0
                    ? 'No missing or stale thumbnails found.'
                    : 'Thumbnail job complete.';
                updateThumbnailProgress(progress, total, total, created, skipped, finalLabel);
                if (options.scope === 'missing' && result.maintenance_after && (result.maintenance_after.images_with_missing || 0) <= 0) {
                    const notice = form.closest('.admin-thumbnail-maintenance-notice');
                    if (notice) {
                        notice.remove();
                    }
                }
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

    // Variable `liveText` stores translated labels passed from the server-rendered form.
    const liveText = {
        searching: form.dataset.adminLogSearchingText || 'Searching...',
        updated: form.dataset.adminLogUpdatedText || 'Updated.',
        failed: form.dataset.adminLogFailedText || 'Live search failed. Use Apply filters.',
        shown: form.dataset.adminLogShownText || 'shown',
        when: form.dataset.adminLogWhenText || 'When',
    };

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
        setLiveState(liveText.searching);
        try {
            // Variable `response` stores this steps working value.
            const response = await fetch(buildUrl(true), {
                headers: {'Accept': 'application/json'},
                signal: activeRequest.signal,
            });
            if (!response.ok) {
                setLiveState(liveText.failed);
                return;
            }
            // Variable `result` stores this steps working value.
            const result = await response.json();
            if (!result.ok) {
                setLiveState(liveText.failed);
                return;
            }
            tbody.innerHTML = result.rows_html || '';
            setupAdminLogStatusForms();
            if (countLabel) {
                countLabel.textContent = `(${Number(result.count || 0)} ${liveText.shown})`;
            }
            const noResults = Number(result.count || 0) === 0;
            const empty = ensureEmptyContainer();
            empty.innerHTML = noResults ? (result.empty_html || '<p>No log entries match the current filters.</p>') : '';
            empty.hidden = !noResults;
            if (timeSortLink) {
                const currentSort = result.time_sort === 'asc' ? 'asc' : 'desc';
                const nextSort = currentSort === 'desc' ? 'asc' : 'desc';
                timeSortLink.dataset.nextSort = nextSort;
                timeSortLink.textContent = `${liveText.when} ${currentSort === 'desc' ? '↓' : '↑'}`;
                const linkUrl = new URL(buildUrl(false), window.location.href);
                linkUrl.searchParams.set('time_sort', nextSort);
                timeSortLink.href = linkUrl.toString();
            }
            window.history.replaceState(null, '', buildUrl(false));
            setLiveState(liveText.updated);
        } catch (error) {
            if (error.name !== 'AbortError') {
                setLiveState(liveText.failed);
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
        const levelDelta = Math.abs(depth - originalDepth);
        const levelText = levelDelta === 1 ? '1 level' : `${levelDelta} levels`;
        const message = direction === 'right'
            ? `→ Nest deeper (${levelText}).`
            : (direction === 'left' ? `← Move out (${levelText}).` : '↓ Same level.');
        if (placeholderRow) {
            const placeholderCell = placeholderRow.firstElementChild;
            placeholderRow.dataset.depth = String(depth);
            placeholderRow.dataset.dragDirection = direction;
            if (placeholderCell) {
                placeholderCell.dataset.dragHint = message;
            }
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
     * Reads the filename value used by automatic Name-column sorting.
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
 * Enables public gallery page card reordering for logged-in admins.
 *
 * The controller is scoped by toolbar. Subgallery cards and photo cards are
 * handled as separate lists, so the page keeps its existing galleries-first and
 * photos-underneath structure. The server receives only the visible page ids
 * plus the pagination offset/count rendered by PHP, then validates that exact
 * slice before saving.
 *
 * @returns {void}
 */
export function setupPublicGalleryPageReordering() {
    document.querySelectorAll('[data-public-reorder-toolbar]').forEach((toolbar) => {
        if (!(toolbar instanceof HTMLElement) || toolbar.dataset.publicReorderBound === '1') {
            return;
        }
        toolbar.dataset.publicReorderBound = '1';

        const kind = toolbar.dataset.reorderKind || '';
        const listSelector = `[data-public-reorder-list="${kind}"]`;
        const itemSelector = kind === 'gallery' ? '[data-public-gallery-order-item]' : '[data-public-photo-order-item]';
        const scope = toolbar.parentElement || document;
        const list = scope.querySelector(listSelector) || document.querySelector(listSelector);
        const status = toolbar.querySelector('[data-public-reorder-status]');
        const reorderUrl = toolbar.dataset.reorderUrl || '';
        const galleryId = toolbar.dataset.galleryId || '';
        const csrfToken = toolbar.dataset.csrfToken || '';
        const visibleOffset = toolbar.dataset.visibleOffset || '0';
        const visibleCount = toolbar.dataset.visibleCount || '0';

        if (!(list instanceof HTMLElement) || !reorderUrl || !galleryId || !csrfToken) {
            return;
        }

        let draggedItem = null;
        let draggedHandle = null;
        let placeholderItem = null;
        let ghostItem = null;
        let pointerOffsetX = 0;
        let pointerOffsetY = 0;
        let originalSignature = '';
        let originalItems = [];
        let activePointerId = null;
        let activeMouseFallback = false;

        /**
         * Updates the compact save status for one public reorder list.
         *
         * @param {string} message Text shown to the admin.
         * @param {string} state Visual state token used by CSS.
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
         * Returns direct sortable items for the current list.
         *
         * @returns {HTMLElement[]} Sortable cards in current DOM order.
         */
        function sortableItems() {
            return Array.from(list.querySelectorAll(itemSelector))
                .filter((item) => item instanceof HTMLElement && item.parentElement === list);
        }

        /**
         * Returns direct sortable items that are still visible during dragging.
         *
         * @returns {HTMLElement[]} Cards available as insertion targets.
         */
        function availableItems() {
            return sortableItems().filter((item) => !item.classList.contains('is-public-reorder-hidden'));
        }

        /**
         * Returns the current visible id order as strings.
         *
         * @returns {string[]} Ordered ids from the current DOM.
         */
        function currentOrder() {
            return sortableItems().map((item) => item.dataset.publicOrderId || '').filter((id) => id !== '');
        }

        /**
         * Returns a compact id signature for change detection.
         *
         * @returns {string} Ordered id signature.
         */
        function currentSignature() {
            return currentOrder().join('|');
        }

        /**
         * Builds the fixed drag preview from the original card.
         *
         * @param {HTMLElement} sourceItem Card being moved.
         * @returns {HTMLElement} Fixed-position clone appended to the body.
         */
        function buildGhost(sourceItem) {
            const box = sourceItem.getBoundingClientRect();
            const ghost = sourceItem.cloneNode(true);
            ghost.classList.add('public-reorder-ghost');
            ghost.classList.remove('is-public-reorder-hidden');
            ghost.removeAttribute('data-public-gallery-order-item');
            ghost.removeAttribute('data-public-photo-order-item');
            ghost.removeAttribute('data-lightbox-image');
            ghost.querySelectorAll('[name]').forEach((field) => field.removeAttribute('name'));
            ghost.style.left = `${box.left}px`;
            ghost.style.top = `${box.top}px`;
            ghost.style.width = `${box.width}px`;
            ghost.style.height = `${box.height}px`;
            document.body.appendChild(ghost);
            return ghost;
        }

        /**
         * Builds the card-shaped placeholder used as the drop marker.
         *
         * @param {HTMLElement} sourceItem Card being moved.
         * @returns {HTMLElement} Placeholder inserted into the list.
         */
        function buildPlaceholder(sourceItem) {
            const box = sourceItem.getBoundingClientRect();
            const placeholder = document.createElement(sourceItem.tagName.toLowerCase());
            placeholder.className = `public-reorder-placeholder ${kind === 'gallery' ? 'gallery-card' : 'image-card'}`;
            placeholder.setAttribute('aria-hidden', 'true');
            placeholder.style.minHeight = `${Math.max(96, box.height)}px`;
            placeholder.innerHTML = `<span>${kind === 'gallery' ? 'Drop gallery here' : 'Drop photo here'}</span>`;
            return placeholder;
        }

        /**
         * Returns the next real item after a target, skipping temporary nodes.
         *
         * @param {HTMLElement} target Current target card.
         * @returns {HTMLElement|null} Next insertion reference, or null to append.
         */
        function nextRealItem(target) {
            let next = target.nextElementSibling;
            while (next) {
                if (next instanceof HTMLElement && next.matches(itemSelector) && !next.classList.contains('is-public-reorder-hidden')) {
                    return next;
                }
                next = next.nextElementSibling;
            }
            return null;
        }

        /**
         * Returns the card closest to the pointer when the pointer is over a gap.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {HTMLElement|null} Nearest sortable card.
         */
        function nearestItem(clientX, clientY) {
            let closestItem = null;
            let closestDistance = Number.POSITIVE_INFINITY;
            availableItems().forEach((item) => {
                const box = item.getBoundingClientRect();
                const centerX = box.left + (box.width / 2);
                const centerY = box.top + (box.height / 2);
                const distance = Math.hypot(clientX - centerX, clientY - centerY);
                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestItem = item;
                }
            });
            return closestItem;
        }

        /**
         * Returns the best insertion target for the current pointer position.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {{target: HTMLElement|null, after: boolean}} Target card and side.
         */
        function insertionTarget(clientX, clientY) {
            const directTarget = document.elementFromPoint(clientX, clientY)?.closest(itemSelector);
            const target = directTarget instanceof HTMLElement && directTarget.parentElement === list && !directTarget.classList.contains('is-public-reorder-hidden')
                ? directTarget
                : nearestItem(clientX, clientY);
            if (!target) {
                return {target: null, after: false};
            }
            const box = target.getBoundingClientRect();
            const pointerWithinRow = clientY >= box.top && clientY <= box.bottom;
            const after = pointerWithinRow ? clientX > box.left + (box.width / 2) : clientY > box.top + (box.height / 2);
            return {target, after};
        }

        /**
         * Moves the placeholder to the candidate drop position.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {void}
         */
        function movePlaceholder(clientX, clientY) {
            if (!placeholderItem) {
                return;
            }
            const insertion = insertionTarget(clientX, clientY);
            if (!insertion.target) {
                list.appendChild(placeholderItem);
                return;
            }
            const reference = insertion.after ? nextRealItem(insertion.target) : insertion.target;
            list.insertBefore(placeholderItem, reference);
        }

        /**
         * Moves the fixed ghost to follow the pointer.
         *
         * @param {number} clientX Pointer X coordinate.
         * @param {number} clientY Pointer Y coordinate.
         * @returns {void}
         */
        function moveGhost(clientX, clientY) {
            if (!ghostItem) {
                return;
            }
            ghostItem.style.left = `${clientX - pointerOffsetX}px`;
            ghostItem.style.top = `${clientY - pointerOffsetY}px`;
        }

        /**
         * Restores DOM order when the server rejects a save.
         *
         * @returns {void}
         */
        function restoreOriginalOrder() {
            originalItems.forEach((item) => {
                list.appendChild(item);
            });
        }

        /**
         * Keeps hidden lightbox source metadata aligned with a visible photo reorder.
         *
         * @param {string[]} orderedIds Visible photo ids after the drop.
         * @returns {void}
         */
        function syncLightboxSourceOrder(orderedIds) {
            if (kind !== 'photo') {
                return;
            }
            const sourceList = document.querySelector('.lightbox-source-list');
            if (!(sourceList instanceof HTMLElement)) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const sourceNodes = Array.from(sourceList.querySelectorAll('[data-lightbox-source]'));
            const sourceById = new Map(sourceNodes.map((node) => [node.dataset.imageId || '', node]));
            const indexes = orderedIds.map((id) => sourceNodes.findIndex((node) => (node.dataset.imageId || '') === id));
            if (indexes.some((index) => index < 0)) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const sortedIndexes = indexes.slice().sort((left, right) => left - right);
            const startIndex = sortedIndexes[0];
            const isContiguous = sortedIndexes.every((index, offset) => index === startIndex + offset);
            if (!isContiguous) {
                document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
                return;
            }
            const nextNodes = sourceNodes.slice();
            orderedIds.forEach((id, offset) => {
                const node = sourceById.get(id);
                if (node) {
                    nextNodes[startIndex + offset] = node;
                }
            });
            nextNodes.forEach((node) => sourceList.appendChild(node));
            document.dispatchEvent(new CustomEvent('publicGalleryPhotoOrderChanged'));
        }

        /**
         * Persists the current visible order to the matching PHP endpoint.
         *
         * @param {string[]} orderedIds Current visible ids after the drop.
         * @returns {Promise<void>} Resolves after save handling completes.
         */
        async function saveOrder(orderedIds) {
            const body = new FormData();
            body.set('csrf_token', csrfToken);
            body.set('gallery_id', galleryId);
            body.set('visible_offset', visibleOffset);
            body.set('visible_count', visibleCount);
            body.set('ajax', '1');
            if (kind === 'gallery') {
                body.set('gallery_order', JSON.stringify(orderedIds));
            } else {
                body.set('image_order', JSON.stringify(orderedIds));
                body.set('reorder_scope', 'visible_page');
            }

            setStatus('Saving visible page order...', 'saving');
            try {
                const response = await fetch(reorderUrl, {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'},
                });
                const text = await response.text();
                let result = null;
                try {
                    result = JSON.parse(text);
                } catch (parseError) {
                    throw new Error('The server returned HTML or text instead of JSON. Check the admin logs or PHP error log.');
                }
                if (!response.ok || !result.ok) {
                    throw new Error(result.message || 'Visible page order could not be saved.');
                }
                setStatus(result.message || 'Visible page order saved.', 'saved');
                syncLightboxSourceOrder(orderedIds);
            } catch (error) {
                restoreOriginalOrder();
                setStatus(error.message || 'Visible page order could not be saved.', 'error');
            }
        }

        /**
         * Removes temporary drag state.
         *
         * @param {boolean} commit Whether to insert the moved item at the placeholder.
         * @returns {void}
         */
        function cleanupDrag(commit) {
            document.removeEventListener('pointermove', handlePointerMove, true);
            document.removeEventListener('pointerup', handlePointerEnd, true);
            document.removeEventListener('pointercancel', handlePointerCancel, true);
            document.removeEventListener('mousemove', handleMouseMove, true);
            document.removeEventListener('mouseup', handleMouseEnd, true);
            document.removeEventListener('keydown', handleKeydown, true);

            if (commit && draggedItem && placeholderItem?.parentElement === list) {
                list.insertBefore(draggedItem, placeholderItem);
            }
            draggedItem?.classList.remove('is-public-reorder-hidden');
            draggedHandle?.classList.remove('is-dragging');
            placeholderItem?.remove();
            ghostItem?.remove();
            document.body.classList.remove('public-reorder-active');
            draggedItem = null;
            draggedHandle = null;
            placeholderItem = null;
            ghostItem = null;
            activePointerId = null;
            activeMouseFallback = false;
        }

        /**
         * Handles pointer or mouse movement during an active drag.
         *
         * @param {MouseEvent|PointerEvent} event Movement event.
         * @returns {void}
         */
        function handleMove(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            moveGhost(event.clientX, event.clientY);
            movePlaceholder(event.clientX, event.clientY);
        }

        /**
         * Handles the end of a pointer or mouse drag.
         *
         * @param {MouseEvent|PointerEvent} event Release event.
         * @returns {void}
         */
        function finishDrag(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            cleanupDrag(true);
            const nextSignature = currentSignature();
            if (nextSignature === originalSignature) {
                setStatus('Order unchanged.', 'idle');
                return;
            }
            saveOrder(currentOrder());
        }

        /**
         * Cancels the active drag and leaves the DOM unchanged.
         *
         * @param {Event} event Cancellation event.
         * @returns {void}
         */
        function cancelDrag(event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            cleanupDrag(false);
            setStatus('Order unchanged.', 'idle');
        }

        /**
         * Handles pointer movement for the active drag.
         *
         * @param {PointerEvent} event Pointer movement event.
         * @returns {void}
         */
        function handlePointerMove(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            handleMove(event);
        }

        /**
         * Handles pointer release for the active drag.
         *
         * @param {PointerEvent} event Pointer release event.
         * @returns {void}
         */
        function handlePointerEnd(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            finishDrag(event);
        }

        /**
         * Handles pointer cancellation for the active drag.
         *
         * @param {PointerEvent} event Pointer cancellation event.
         * @returns {void}
         */
        function handlePointerCancel(event) {
            if (activePointerId !== null && event.pointerId !== activePointerId) {
                return;
            }
            cancelDrag(event);
        }

        /**
         * Handles mouse movement for browsers without PointerEvent support.
         *
         * @param {MouseEvent} event Mouse movement event.
         * @returns {void}
         */
        function handleMouseMove(event) {
            if (!activeMouseFallback) {
                return;
            }
            handleMove(event);
        }

        /**
         * Handles mouse release for browsers without PointerEvent support.
         *
         * @param {MouseEvent} event Mouse release event.
         * @returns {void}
         */
        function handleMouseEnd(event) {
            if (!activeMouseFallback) {
                return;
            }
            finishDrag(event);
        }

        /**
         * Lets the admin cancel a drag with Escape.
         *
         * @param {KeyboardEvent} event Keyboard event.
         * @returns {void}
         */
        function handleKeydown(event) {
            if (event.key === 'Escape') {
                cancelDrag(event);
            }
        }

        /**
         * Starts card movement from a dedicated handle.
         *
         * @param {MouseEvent|PointerEvent} event Initial press event.
         * @param {boolean} mouseFallback Whether classic mouse events own this drag.
         * @returns {void}
         */
        function startDrag(event, mouseFallback) {
            const handle = event.target instanceof Element ? event.target.closest('[data-public-reorder-handle]') : null;
            const item = handle instanceof HTMLElement ? handle.closest(itemSelector) : null;
            if (!(handle instanceof HTMLElement) || !(item instanceof HTMLElement) || item.parentElement !== list || event.button !== 0 || draggedItem) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            const itemBox = item.getBoundingClientRect();
            draggedItem = item;
            draggedHandle = handle;
            originalSignature = currentSignature();
            originalItems = sortableItems();
            pointerOffsetX = event.clientX - itemBox.left;
            pointerOffsetY = event.clientY - itemBox.top;
            activePointerId = mouseFallback ? null : event.pointerId;
            activeMouseFallback = mouseFallback;
            placeholderItem = buildPlaceholder(item);
            ghostItem = buildGhost(item);

            list.insertBefore(placeholderItem, item.nextElementSibling);
            item.classList.add('is-public-reorder-hidden');
            handle.classList.add('is-dragging');
            document.body.classList.add('public-reorder-active');
            setStatus(`Dragging visible ${kind === 'gallery' ? 'gallery' : 'photo'}...`, 'dragging');
            moveGhost(event.clientX, event.clientY);
            movePlaceholder(event.clientX, event.clientY);

            if (mouseFallback) {
                document.addEventListener('mousemove', handleMouseMove, true);
                document.addEventListener('mouseup', handleMouseEnd, true);
            } else {
                document.addEventListener('pointermove', handlePointerMove, true);
                document.addEventListener('pointerup', handlePointerEnd, true);
                document.addEventListener('pointercancel', handlePointerCancel, true);
            }
            document.addEventListener('keydown', handleKeydown, true);
        }

        list.querySelectorAll('[data-public-reorder-handle]').forEach((handle) => {
            handle.setAttribute('draggable', 'false');
            handle.addEventListener('dragstart', (event) => event.preventDefault());
            handle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
            });
            handle.addEventListener('pointerdown', (event) => {
                if (event.isPrimary === false) {
                    return;
                }
                startDrag(event, false);
            });
            handle.addEventListener('mousedown', (event) => {
                if (window.PointerEvent) {
                    return;
                }
                startDrag(event, true);
            });
        });

        setStatus('Drag handles ready.', 'idle');
    });
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
    // root stores the currently active panel body when the edit table was loaded dynamically.
    const panelBody = document.querySelector('[data-admin-side-panel]:not([hidden]) [data-admin-side-panel-body]');
    const root = panelBody instanceof HTMLElement ? panelBody : document;
    // table stores the reorder-enabled image table on the edit-gallery screen.
    const table = root.querySelector('[data-admin-image-order-table]');
    // toolbar stores endpoint metadata and status UI for the reorder feature.
    const toolbar = root.querySelector('[data-admin-image-order-toolbar]');
    // form stores the existing image bulk form, reused only for gallery id and CSRF values.
    const form = root.querySelector('[data-admin-image-bulk-form]');
    if (!table || !toolbar || !form) {
        return;
    }
    if (table.dataset.adminImageReorderBound === '1') {
        return;
    }
    table.dataset.adminImageReorderBound = '1';

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
     * Reads the filename value used by automatic Name-column sorting.
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
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
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
