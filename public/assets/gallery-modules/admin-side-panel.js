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
 *   2026-09-14
 */

import { setupImageBulkMoveFields } from './admin-bulk-actions.js?v=20260519-gallery-picker-v1';
import { setupGallerySearchPickers } from './searchable-gallery-picker.js?v=20260519-gallery-picker-v1';
import { setupBackToTopButton, teardownBackToTopButton } from './back-to-top.js?v=20260510-lifecycle-v3';
import { setupGalleryLightbox, setupTagSuggestions, teardownGalleryLightbox } from './lightbox-deferred.js?v=20260920-lightbox-preload-lifecycle-v1';
import { setupPictureManager, teardownPictureManager } from './picture-manager.js?v=20260914-picture-manager-mixed-v1';
import { setupResponsiveThumbnailSizes, teardownResponsiveThumbnailSizes } from './responsive-thumbnails.js?v=20260510-lazy-map-v1';
import { activateAdminTabInRoot, activeAdminTabId, setupAdminTabsInRoot } from './admin-tabs.js?v=20260925-editor-tabs-v2';
import { setupAdminNestedTabs } from './admin-nested-tabs.js?v=20260608-admin-cinematic-v1';
import { setupAdminImageReordering } from './admin-image-reordering.js?v=20260920-panel-lifecycle-v1';
import { setupPublicGalleryPageReordering } from './admin-gallery-list.js?v=20260512-modular-admin-v1';
import { appendUploadProgressLog, escapeHtmlAttribute, escapeHtmlText, i18n, isThumbnailSubmission, thumbnailEndpoint, updateBasicProgress, updateThumbnailProgress, ensureThumbnailProgress, updateUploadProgressMetrics } from './admin-core.js?v=20260614-upload-order-v2';
import { browserUploadRequested, browserUploadZipSelected, runBrowserGalleryUpload } from './admin-browser-upload.js?v=20260920-operation-keys-v1';
import { setupAdminSmartGalleries } from './admin-smart-galleries.js?v=20260919-smart-gallery-presentation-v1';
import { completeAdminMutation, replaceOwnedPublicGalleryFragments } from './admin-mutation-completion.js?v=20260902-create-delete-hotfix1';
import {ADMIN_PANEL_MOTION_MS as adminSidePanelMotionDurationMs} from './admin-panel-policy.js?v=20260920-panel-lifecycle-v1';
import {beginAdminPanelOpen, captureAdminPanelOwner, rememberAdminPanelMutation, adminPanelMutationOwner, combineAdminPanelGuards, activateAdminPanelModal, deactivateAdminPanel, focusAdminPanelContent, preserveAdminPanelFocus} from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';
import {prepareAdminPanelDrafts, allowAdminPanelTransition, adminPanelHasUnsavedText, submittedAdminPanelDraft, acknowledgeAdminPanelDraft, beginAdminPanelSave, acknowledgeAdminPanelSave} from './admin-panel-drafts.js?v=20260920-operation-keys-v1';
import {beginAdminOperation, adminOperationBody, finishAdminOperation, adminOperationIsRunning} from './admin-operation-keys.js?v=20260920-operation-keys-v1';

/**
 * Presentation metadata resolved from an enhanced link; refreshes need only name.
 * @typedef {Object} AdminPanelWorkflow
 * @property {string} name Form-binding and editor-selection identity.
 * @property {string} [kicker] Localized small heading for a newly opened workflow.
 * @property {string} [title] Localized main heading for a newly opened workflow.
 * @property {string} [loadingMessage] Initial GET progress text.
 * @property {string} [loadErrorMessage] Initial GET failure text.
 */

/**
 * Cancellation projection accepted from the panel lifecycle and completion owner.
 * This module composes these guards; it does not allocate coordinator generations.
 * @typedef {Object} AdminPanelCompletionGuard
 * @property {function(): boolean} [isCurrent] Whether this operation may still affect the drawer.
 * @property {AbortSignal|null} [signal] Optional cancellation signal for its editor GET.
 */

/**
 * Consumer-side view of an unmodified server completion context.
 * All additional context/postcondition fields pass through to the canonical owner.
 * @typedef {ReturnType<typeof import('./admin-mutation-completion.js').normalizeAdminMutationEnvelope>['contexts'][number]} AdminPanelMutationContext
 */

/**
 * JSON response transport bag, not proof of a successful mutation.
 * Callers validate HTTP status and ok; upload aggregation also requires mutation
 * and contexts. The canonical coordinator owns full envelope normalization.
 * This local projection describes only fields consumed here and may carry other
 * server fields unchanged; it must never be treated as a draft/storage allowlist.
 * @typedef {Object} AdminPanelResponse
 * @property {boolean} [ok] Endpoint success indication.
 * @property {string} [message] Endpoint presentation message.
 * @property {string} [error] Endpoint failure description.
 * @property {string} [action] Token action discriminator.
 * @property {number} [token_id] Newly created token row identifier, not the token.
 * @property {string} [edit_revision] Decimal revision of the just-saved row.
 * @property {{type?: string, entity?: string, action?: string, entity_ids?: number[]}} [mutation] Server mutation metadata, preserved rather than reconstructed.
 * @property {{refresh_url?: string}|null} [panel] Server-selected panel refresh metadata.
 * @property {AdminPanelMutationContext[]} [contexts] Public contexts and observable postconditions passed through unchanged.
 * @property {{redirect_url?: string}} [fallback] Direct-page destination, not the panel refresh authority.
 * @property {number|string} [gallery_id] Affected gallery row identifier.
 * @property {number[]} [gallery_ids] Aggregate uploaded gallery identifiers.
 * @property {string} [gallery_title] Persisted gallery heading.
 * @property {string} [gallery_url] Affected gallery's public URL.
 * @property {string} [edit_url] Gallery editor destination.
 * @property {string} [public_url] Saved tag's public URL.
 * @property {string} [redirect_url] Direct-page upload fallback URL.
 * @property {string} [refresh_url] Workflow-provided editor/context hint.
 * @property {number} [refresh_gallery_id] Public context gallery identifier.
 * @property {number} [parent_gallery_id] Parent gallery identifier after creation.
 * @property {string} [parent_gallery_url] Parent public URL after creation.
 * @property {boolean} [created_gallery] Whether upload created its target gallery.
 * @property {string} [bulk_action] Completed gallery-image bulk action.
 * @property {string} [destination_gallery_url] Move destination for the result notice.
 * @property {boolean} [gallery_visibility_changed] Whether persisted visibility changed.
 * @property {string} [gallery_visibility] Persisted visibility vocabulary.
 * @property {string} [gallery_visibility_label] Public card marker label.
 * @property {string} [gallery_visibility_hint] Public card marker help text.
 * @property {number|string} [image_id] Saved image row identifier.
 * @property {number[]} [image_ids] Persisted images returned by upload.
 * @property {string} [image_url] Saved image's public URL.
 * @property {string} [image_title] Persisted image title for bounded card updates.
 * @property {string} [image_description] Persisted image description for bounded card updates.
 * @property {string} [image_visibility] Persisted admin image-row visibility.
 * @property {number} [image_sort_order] Persisted admin image-row order.
 * @property {number} [uploaded] Uploaded source count.
 * @property {number} [scanned] Registered source count.
 * @property {number} [thumbnails] Created derivative count across uploaded files.
 * @property {number} [thumbnail_skipped] Skipped derivative count across files.
 * @property {number} [thumbnail_failed] Failed derivative count across files.
 * @property {string[]} [thumbnail_errors] Deduplicated derivative diagnostics.
 * @property {number} [total_files] Selected source count for progress.
 * @property {AdminPanelUploadEvent[]} [upload_events] Server upload progress messages.
 * @property {number} [total] Thumbnail job image count.
 * @property {number} [next_offset] Next server thumbnail-job offset.
 * @property {number} [created] Derivatives created in one thumbnail response.
 * @property {number} [skipped] Derivatives skipped in one thumbnail response.
 * @property {number} [failed] Derivatives failed in one thumbnail response.
 * @property {string[]} [errors] Thumbnail response diagnostics.
 * @property {boolean} [done] Whether the server thumbnail job has finished.
 */

/**
 * Observable subset of the coordinator result read by panel callers.
 * The original result (including diagnostics and per-context outcomes) is returned
 * untouched; this alias does not establish a competing completion contract.
 * @typedef {Awaited<ReturnType<typeof completeAdminMutation>>} AdminPanelSynchronization
 */

/**
 * Optional panel-only customization; public refresh/retry stays coordinator-owned.
 * @typedef {Object} AdminPanelCompletionOverrides
 * @property {function({refresh_url?: string}|null, AdminPanelResponse, AdminPanelCompletionGuard): Promise<boolean>} [refreshPanel] Refresh only while the supplied combined guard is current.
 */

/**
 * Stable card identity captured before visibility mutation replaces its DOM.
 * @typedef {Object} AdminPanelVisibilityTarget
 * @property {'gallery'|'image'} kind Selector namespace for the row identifier.
 * @property {number} id Positive row identifier, or zero for an invalid target.
 * @property {HTMLElement|null} card Original card, used only for pending/failure feedback.
 */

/**
 * Mutable source progress for sequential classic multipart requests.
 * Multipart loaded/total is projected onto source bytes, not treated as exact wire bytes.
 * @typedef {Object} AdminPanelClassicProgress
 * @property {number} totalFiles Selected source-file count.
 * @property {number} totalBytes Selected source-byte sum.
 * @property {number} uploadedFiles Fully acknowledged source-file count.
 * @property {number} uploadedBytes Acknowledged plus estimated current source bytes.
 * @property {number} currentFileIndex One-based source position, initially zero.
 * @property {number} currentFileBytes Current source size in bytes.
 * @property {number} currentFileUploadedBytes Estimated source bytes transferred for the current request.
 */

/**
 * One upload progress record provided by the server.
 * @typedef {Object} AdminPanelUploadEvent
 * @property {string} [message] Progress text appended through the existing log helper.
 * @property {number} [elapsed_ms] Optional elapsed server time in milliseconds.
 */

/**
 * Accumulated derivative outcome for one acknowledged upload's image identifiers.
 * @typedef {Object} AdminPanelThumbnailResult
 * @property {number} created Created derivative count.
 * @property {number} skipped Skipped derivative count.
 * @property {number} failed Failed derivative count; at least one for a refused job.
 * @property {string[]} errors Deduplicated server diagnostics.
 */

// Function `setupGalleryUploadProgress` executes this focused behavior.
/**
 * Bind each currently mounted upload form once, including newly injected forms.
 *
 * The per-form listener intercepts navigation and delegates replay/ownership to the
 * existing upload workflow; calling setup again does not duplicate submissions.
 * @return {void} Marks forms bound and installs their submit listener.
 */
export function setupGalleryUploadProgress() {
    document.querySelectorAll('[data-gallery-upload-form]').forEach(/** Bind each mounted upload form at most once. @param {Element} form Candidate upload form. @return {void} Marks valid forms bound before installing submission handling. */ (form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.galleryUploadProgressBound === '1') {
            return;
        }
        form.dataset.galleryUploadProgressBound = '1';
        form.addEventListener('submit', /** Keep the original upload form in its AJAX workflow. @param {SubmitEvent} event Original form submission. @return {void} Cancels native navigation and starts the upload owner. */ (event) => {
            event.preventDefault();
            runGalleryUpload(form);
        });
    });
}

// Function `runGalleryUpload` executes this focused behavior.
/**
 * Execute one explicit upload intent and retain uncertain requests for exact retry.
 *
 * Captures the drawer owner before network work; canonical success retires the key
 * before completion dispatch. A failed/uncertain request keeps its original form,
 * files and keys. Prepared ZIP batching remains owned by the upload module. Panel
 * completion never navigates; only direct-page forms use the redirect fallback.
 * @param {HTMLFormElement} form Original upload form with current transport controls.
 * @return {Promise<void>} Restores original controls after completion or a recoverable error.
 */
async function runGalleryUpload(form) {
    if (adminOperationIsRunning(form)) return;
    let operation = null;
    const owner = captureAdminPanelOwner(form.closest('[data-admin-side-panel]'));
    // progress stores state or configuration for the gallery front-end flow.
    const progress = ensureThumbnailProgress(form);
    revealPanelUploadProgress(form, progress);
    form.classList.add('is-uploading');
    // buttons stores state or configuration for the gallery front-end flow.
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
        const useBrowserUpload = browserUploadRequested(form);
        if (browserUploadZipSelected(form) && !useBrowserUpload) {
            throw new Error(i18n('admin.browser_upload.zip_browser_required', 'ZIP import requires the browser-assisted upload path and a compatible browser.'));
        }
        operation = beginAdminOperation(form, useBrowserUpload ? 'prepared' : 'classic', selectedGalleryUploadFiles(form));
        if (useBrowserUpload) {
            result = await runBrowserGalleryUpload(form, progress, operation);
        }
        // Checked browser preparation is a strict execution choice whenever files
        // are selected. The browser helper may ask for the classic path only for an
        // empty create-gallery submission where there is no media to prepare. Never
        // silently switch selected photos to server-side thumbnail generation.
        if (!useBrowserUpload || result?.fallback === true) {
            result = await runGalleryUploadFiles(form, progress, createThumbnails, operation);
        }
        requireCanonicalUploadMutationResult(result);
        finishAdminOperation(operation, true);
        if (createThumbnails) {
            const failed = Number(result.thumbnail_failed || 0);
            const message = failed > 0 ? i18n('admin.operations.upload_thumbnail_failed', 'Upload finished, but {count} thumbnail or DNG display derivative(s) failed.', {count: failed}) : i18n('admin.operations.upload_complete', 'Upload and thumbnail job complete.');
            updateThumbnailProgress(progress, result.uploaded || 0, result.total_files || 0, result.thumbnails || 0, result.thumbnail_skipped || 0, message);
        } else {
            updateBasicProgress(progress, 100, i18n('admin.operations.uploaded_scanning_complete', 'Uploaded {count} images. Scanning complete.', {count: result.uploaded || 0}));
        }
        if (galleryUploadCompletesInSidePanel(form)) {
            rememberAdminPanelMutation(result, owner);
            dispatchAdminSidePanelSuccess(form, result);
            return;
        }
        if (form.dataset.galleryPanelCloseOnSuccess === '1') {
            rememberAdminPanelMutation(result, owner);
            await completeCoreGalleryMutationInCurrentView(result);
            return;
        }
        window.location.href = result.redirect_url || adminUrlWithParams({uploaded: result.uploaded || 0, scanned: result.scanned || 0, thumbnails: result.thumbnails || 0});
    } catch (error) {
        updateBasicProgress(progress, 100, error.message || i18n('admin.operations.upload_failed', 'Upload failed.'));
    } finally {
        finishAdminOperation(operation);
        form.classList.remove('is-uploading');
        const panel = form.closest('[data-admin-side-panel]');
        if (panel instanceof HTMLElement) {
            panel.classList.remove('is-uploading');
        }
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
 * @return {void} Marks the original drawer uploading and brings its progress container into view.
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
 * Return whether an upload form is owned by the mounted side-panel completion workflow.
 *
 * @param {HTMLFormElement} form Upload form submitted by the admin.
 * @return {boolean} True when the mounted side panel owns the completion behavior.
 */
function galleryUploadCompletesInSidePanel(form) {
    return form.dataset.galleryPanelCloseOnSuccess === '1' && Boolean(form.closest('[data-admin-side-panel]'));
}

/**
 * Notify the side-panel controller that an embedded workflow finished.
 *
 * @param {HTMLFormElement} form Completed form.
 * @param {AdminPanelResponse} result Server response plus client-side aggregate upload data.
 * @return {void} Dispatches a bubbling synchronous event; its listener owns asynchronous completion.
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
 * @return {void} Installs delegated handlers once, including dynamically rendered panel forms.
 */
export function setupAdminGallerySidePanel() {
    if (document.body?.dataset.adminGallerySidePanelBound === '1') {
        return;
    }
    if (document.body) {
        document.body.dataset.adminGallerySidePanelBound = '1';
    }

    document.addEventListener('php-gallery:admin-image-order-saved', /** Forward image-order completion with its captured drawer owner. @param {CustomEvent} event Canonical result and originating panelOwner from the reorder module. @return {Promise<void>} Delegates public/panel synchronization to the canonical coordinator. */ async (event) => {
        const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
        if (!(panel instanceof HTMLElement)) {
            return;
        }
        const result = event.detail?.result || {};
        rememberAdminPanelMutation(result, event.detail?.panelOwner || captureAdminPanelOwner(event.target instanceof Element ? event.target.closest('[data-admin-side-panel]') : null));
        await completeCoreGalleryMutationInCurrentView(result);
    });

    document.addEventListener('php-gallery:metadata-organizer-applied', /** Complete organizer changes without adopting another open workflow. @param {CustomEvent} event Canonical result, panelOwner and mutable handled marker. @return {Promise<void>} Synchronizes publicly while guarding drawer status and refresh. */ async (event) => {
        const detail = event.detail || {};
        const result = detail.result || {};
        const owner = detail.panelOwner || captureAdminPanelOwner(event.target instanceof Element ? event.target.closest('[data-admin-side-panel]') : null);
        rememberAdminPanelMutation(result, owner);
        const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
        if (panel instanceof HTMLElement) {
            detail.handled = true;
            if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, i18n('admin.metadata_organizer.refreshing', 'Refreshing gallery view...'), false);
            await completeCoreGalleryMutationInCurrentView(result);
        }
    });

    document.addEventListener('php-gallery:auxiliary-mutation-success', /** Route an auxiliary result through the shared completion owner. @param {CustomEvent} event Canonical result, captured panelOwner and optional refresh/status intent. @return {Promise<void>} Applies only current-owner panel effects after synchronization. */ async (event) => {
        const detail = event.detail || {};
        const result = detail.result || {};
        const owner = detail.panelOwner || captureAdminPanelOwner(event.target instanceof Element ? event.target.closest('[data-admin-side-panel]') : null);
        rememberAdminPanelMutation(result, owner);
        const shouldRefreshPanel = detail.refreshPanel === true;
        const syncResult = await completeCoreGalleryMutationInCurrentView(result, {
            refreshPanel: shouldRefreshPanel
                ? /** Refresh the auxiliary editor only under the combined owner guard. @param {{refresh_url?: string}|null} panelMetadata Canonical editor destination. @param {AdminPanelResponse} _envelope Unmodified completion response. @param {AdminPanelCompletionGuard} completionGuard Combined cancellation scope. @return {Promise<boolean>} True after refresh; throws on an unavailable editor. */ async (panelMetadata, _envelope, completionGuard) => {
                    const refreshed = await refreshAdminSidePanelFromServer(String(panelMetadata?.refresh_url || ''), completionGuard);
                    if (!refreshed) {
                        throw new Error(i18n('admin.side_panel.refresh_failed_after_success', 'The server change was kept, but the refreshed editor could not be loaded.'));
                    }
                    return true;
                }
                : /** Retain the auxiliary workflow's existing panel fragment. @return {Promise<boolean>} Marks panel handling complete without issuing a GET. */ async () => true,
        });
        const panel = document.querySelector('[data-admin-side-panel]');
        const statusMessage = String(detail.statusMessage || '');
        if (owner.isCurrent() && syncResult.synchronized && statusMessage !== '' && panel instanceof HTMLElement) {
            writeAdminGallerySidePanelStatus(panel, statusMessage, detail.statusError === true);
        }
    });

    document.addEventListener('click', /** Open an enhanced link through the guarded reusable modal when supported. @param {MouseEvent} event Captured click narrowed to an enhanced anchor. @return {Promise<void>} Cancels native navigation and awaits the open intent, leaving unrelated links native. */ async (event) => {
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



    document.addEventListener('submit', /** Apply the existing public-delete confirmation before enhanced deletion handling. @param {SubmitEvent} event Captured public-delete form submission. @return {void} Cancels submission if the existing confirmation is declined. */ (event) => {
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
        const galleryTrashMode = kind === 'gallery' && String(form.dataset.publicAdminDeleteMode || '') === 'trash';
        const autoPurgeEnabled = String(form.dataset.publicAdminDeleteAutoPurgeEnabled || '0') === '1';
        const retentionDays = Math.max(1, Number(form.dataset.publicAdminDeleteRetentionDays || 30));
        const message = galleryTrashMode
            ? [
                i18n('js.admin.inline.trash_gallery_title', 'Move this gallery and all subgalleries to the trash?'),
                name ? `Item: ${name}` : '',
                '',
                autoPurgeEnabled
                    ? i18n('js.admin.inline.trash_gallery_detail', 'It leaves the live gallery immediately and can be restored for {days} day(s).', {days: retentionDays})
                    : i18n('js.admin.inline.trash_gallery_detail_manual', 'It leaves the live gallery immediately and stays in the trash until you restore or permanently delete it.')
            ].filter(/** Omit empty lines from the existing confirmation prompt. @param {string} line Prepared message line. @return {boolean} Whether the line contributes prompt text. */ (line) => line !== '').join('\n')
            : [
                `Remove this ${kind} from CMS?`,
                name ? `Item: ${name}` : '',
                '',
                'This removes the CMS record. Continue?'
            ].filter(/** Omit empty lines from the existing confirmation prompt. @param {string} line Prepared message line. @return {boolean} Whether the line contributes prompt text. */ (line) => line !== '').join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    }, true);

    document.addEventListener('submit', /** Intercept confirmed public gallery-card deletion when AJAX rendering is available. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('.public-admin-delete-form-card[data-public-admin-delete-form][data-public-admin-delete-kind="gallery"]')
            || event.defaultPrevented) {
            return;
        }
        if (!window.fetch || !window.DOMParser) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitPublicGalleryCardDelete(form);
    }, true);

    document.addEventListener('submit', /** Intercept confirmed public image-card deletion in its owning gallery context. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('.public-admin-delete-form-card[data-public-admin-delete-form][data-public-admin-delete-kind="photo"]')
            || event.defaultPrevented) {
            return;
        }
        if (!window.fetch || !window.DOMParser) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitPublicImageCardDelete(form);
    }, true);

    document.addEventListener('submit', /** Intercept a public card visibility option for in-place completion. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)
            || !form.matches('[data-public-admin-visibility-form]')
            || event.defaultPrevented) {
            return;
        }
        if (!window.fetch || !window.DOMParser) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitPublicCardVisibility(form);
    }, true);

    document.addEventListener('click', /** Request closure from the close button or scrim through the draft/unresolved-intent guard. @param {MouseEvent} event Delegated click narrowed before using its target. @return {void} Changes only the indicated menu, closure request or submitter bookkeeping. */ (event) => {
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

    document.addEventListener('keydown', /** Close visibility menus without claiming the drawer's nested-widget Escape. @param {KeyboardEvent} event Document keyboard event. @return {void} Removes only menu open attributes for Escape. */ (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('[data-public-admin-visibility-menu][open]').forEach(/** Close one public visibility menu without claiming drawer/picker Escape. @param {Element} menu Open visibility menu. @return {void} Removes its open attribute. */ (menu) => {
            menu.removeAttribute('open');
        });
    });

    document.addEventListener('php-gallery:panel-request-close', /** Send an unclaimed modal Escape through the ordinary draft/operation close guard. @param {CustomEvent} event Originating drawer shell as target. @return {void} Requests closure without bypassing unsaved state. */ (event) => {
        if (event.target instanceof HTMLElement) closeAdminGallerySidePanel(event.target);
    });

    document.addEventListener('click', /** Dismiss public visibility menus when a click occurs outside those menus. @param {MouseEvent} event Delegated click narrowed before using its target. @return {void} Changes only the indicated menu, closure request or submitter bookkeeping. */ (event) => {
        if (event.target instanceof Element && event.target.closest('[data-public-admin-visibility-menu]')) {
            return;
        }
        document.querySelectorAll('[data-public-admin-visibility-menu][open]').forEach(/** Close one public visibility menu without claiming drawer/picker Escape. @param {Element} menu Open visibility menu. @return {void} Removes its open attribute. */ (menu) => {
            menu.removeAttribute('open');
        });
    });

    document.addEventListener('submit', /** Route a newly mounted create form to the operation-key owner. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-panel-create-form]')) {
            return;
        }
        event.preventDefault();
        await submitAdminGalleryPanelCreateForm(form);
    });
    document.addEventListener('change', /** Keep the compact create summary aligned with advanced selections. @param {Event} event Delegated visibility or parent-picker change. @return {void} Update only this form's summary. */ (event) => {
        const field = event.target;
        if (!(field instanceof HTMLElement) || !field.matches('select[name="visibility"], input[name="parent_id"], select[name="parent_id"]')) return;
        const form = field.closest('form');
        const summary = form?.querySelector('[data-gallery-create-summary]');
        if (!(summary instanceof HTMLElement)) return;
        const visibility = form.querySelector('select[name="visibility"]');
        const visibilityLabel = summary.querySelector('[data-gallery-create-visibility]');
        if (visibility instanceof HTMLSelectElement && visibilityLabel instanceof HTMLElement) {
            visibilityLabel.textContent = visibility.selectedOptions[0]?.textContent?.trim() || visibility.value;
        }
        const parent = form.querySelector('input[name="parent_id"]:enabled, select[name="parent_id"]:enabled');
        const parentLabel = summary.querySelector('[data-gallery-create-parent]');
        if (parent instanceof HTMLInputElement && parentLabel instanceof HTMLElement) {
            const pickerLabel = parent.closest('[data-gallery-search-picker]')?.querySelector('[data-gallery-search-picker-input]');
            parentLabel.textContent = parent.value === '0'
                ? String(summary.dataset.rootLabel || '')
                : (pickerLabel instanceof HTMLInputElement ? pickerLabel.value.trim() : '') || `#${parent.value}`;
        } else if (parent instanceof HTMLSelectElement && parentLabel instanceof HTMLElement) {
            parentLabel.textContent = parent.selectedOptions[0]?.textContent?.trim() || parent.value;
        }
    });

    document.addEventListener('click', /** Capture the clicked Smart Gallery submitter as a fallback for SubmitEvent.submitter. @param {MouseEvent} event Delegated click narrowed before using its target. @return {void} Changes only the indicated menu, closure request or submitter bookkeeping. */ (event) => {
        const submitter = event.target instanceof Element ? event.target.closest('button, input[type="submit"]') : null;
        if (!(submitter instanceof HTMLElement)) return;
        const form = submitter.closest('form[data-smart-gallery-panel-form]');
        if (form instanceof HTMLFormElement) form.__adminSmartGallerySubmitter = submitter;
    }, true);

    document.addEventListener('submit', /** Claim mounted Smart Gallery submissions before generic handlers and preserve the captured submitter. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-smart-gallery-panel-form]') || !form.closest('[data-admin-side-panel]')) {
            return;
        }
        if (event.defaultPrevented) return;
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        await submitAdminSmartGalleryPanelForm(form, event.submitter || form.__adminSmartGallerySubmitter || null);
    }, true);

    document.addEventListener('submit', /** Route mounted gallery/image/tag edits through the revision-aware save owner. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-panel-edit-form]')) {
            return;
        }
        event.preventDefault();
        await submitAdminPanelEditForm(form, event.submitter instanceof HTMLElement ? event.submitter : null);
    });

    document.addEventListener('submit', /** Claim drawer scan-images submissions before generic handlers can navigate. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-panel-scan-images-form]') || !form.closest('[data-admin-side-panel]')) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitAdminPanelAuxiliaryMutation(form, event.submitter instanceof HTMLElement ? event.submitter : null);
    }, true);

    document.addEventListener('submit', /** Apply the existing AI reprocess confirmation and keep accepted work in the drawer. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-panel-ai-reprocess-form]') || !form.closest('[data-admin-side-panel]')) {
            return;
        }
        const confirmMessage = String(form.dataset.confirm || '');
        if (confirmMessage !== '' && !window.confirm(confirmMessage)) {
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        await submitAdminPanelAuxiliaryMutation(form, event.submitter instanceof HTMLElement ? event.submitter : null);
    }, true);

    document.addEventListener('submit', /** Claim upload-automation token forms only when mounted in the drawer. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
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

    document.addEventListener('click', /** Capture the clicked image-bulk submitter without claiming a future thumbnail action. @param {MouseEvent} event Delegated click narrowed before using its target. @return {void} Changes only the indicated menu, closure request or submitter bookkeeping. */ (event) => {
        const submitter = event.target instanceof Element ? event.target.closest('button, input[type="submit"]') : null;
        if (!(submitter instanceof HTMLElement)) {
            return;
        }
        const form = submitter.closest('form[data-admin-panel-bulk-form]');
        if (form instanceof HTMLFormElement) {
            form.__adminPanelSubmitter = submitter;
        }
    }, true);

    document.addEventListener('submit', /** Route gallery image bulk actions in place; leave thumbnail submissions to their existing owner. @param {SubmitEvent} event Delegated submission narrowed to the owned form. @return {Promise<void>} Awaits the selected workflow after cancelling native submission; unrelated or already-handled forms are left to their owner. */ async (event) => {
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

    document.addEventListener('php-gallery:side-panel-success', /** Route one successful panel mutation according to its captured owner and workflow. @param {CustomEvent} event Canonical result and stable source workflow. @return {void} Starts the existing reflection task without awaiting event dispatch. */ (event) => {
        const panel = event.target instanceof Element ? event.target.closest('[data-admin-side-panel]') : document.querySelector('[data-admin-side-panel]');
        const source = String(event.detail?.source || '');
        const result = event.detail?.result || {};
        const owner = adminPanelMutationOwner(result, panel);
        if (!owner.isCurrent()) {
            runAdminSidePanelSuccessTask(null, /** Synchronize a detached mutation publicly without taking ownership of the current drawer. @return {Promise<Record<string, unknown>>} Canonical coordinator synchronization outcome. */ () => completeCoreGalleryMutationInCurrentView(result));
            return;
        }
        const shouldKeepPanelOpen = adminSidePanelMutationKeepsPanelOpen(source);
        if (panel instanceof HTMLElement && !shouldKeepPanelOpen) {
            closeAdminGallerySidePanel(panel);
        }
        if (source === 'gallery-image-bulk') {
            runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged gallery image bulk result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectGalleryImageBulkInCurrentView(result));
            if (panel instanceof HTMLElement && shouldKeepPanelOpen) {
                writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.side_panel.title_picture_saved', 'Gallery title picture saved.')), false);
            }
            return;
        }
        if (source === 'gallery-edit') {
            runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged gallery save result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectSavedGalleryInCurrentView(result));
            return;
        }
        if (source === 'image-edit') {
            runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged image save result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectSavedImageInCurrentView(result));
            return;
        }
        if (source === 'tag-edit') {
            runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged tag save result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectSavedTagInCurrentView(result));
            return;
        }
        if (source === 'upload') {
            if (panel instanceof HTMLElement) {
                writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.side_panel.upload_complete', 'Upload complete.')), false);
            }
            runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged upload result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectUploadedGalleryInCurrentView(result));
            return;
        }
        runAdminSidePanelSuccessTask(panel, /** Reflect this acknowledged gallery creation result through its existing synchronization path. @return {Promise<void>} The enclosing success task handles reflection rejection after persistence. */ () => reflectCreatedGalleryInCurrentView(result));
    });
}

/**
 * Run one asynchronous side-panel success reflection without allowing a rejected
 * synchronization promise to leave the drawer permanently showing its saving state.
 *
 * CustomEvent dispatch is synchronous and cannot await async listeners. Every
 * reflection task therefore owns an explicit rejection boundary here. Persistence
 * has already succeeded when this helper runs, so failures are reported as refresh
 * failures and never misrepresented as a failed gallery save.
 *
 * @param {HTMLElement|null} panel Active side-panel root.
 * @param {() => Promise<void>} task Asynchronous success reflection task.
 * @return {void} Starts a handled asynchronous task; persistence has already succeeded.
 */
function runAdminSidePanelSuccessTask(panel, task) {
    const owner = captureAdminPanelOwner(panel);
    Promise.resolve()
        .then(task)
        .catch(/** Report synchronization failure only to the drawer that launched this task. @param {unknown} error Rejected reflection error, never a new mutation request. @return {void} Preserves saved state and suppresses unhandled rejection. */ (error) => {
            if (panel instanceof HTMLElement && owner.isCurrent()) {
                writeAdminGallerySidePanelStatus(
                    panel,
                    i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                    true
                );
            }
            console.error('PHP Gallery side-panel success reflection failed after persistence.', error);
        });
}

/**
 * Return whether a completed side-panel mutation owns an in-place gallery workflow.
 *
 * Gallery create/edit/bulk/upload and image/tag editor completions leave the
 * drawer mounted while their server-rendered contexts refresh. The stable source
 * identifier chooses this policy; it does not grant ownership of another drawer.
 *
 * @param {string} source Side-panel success source identifier.
 * @return {boolean} True when the active side panel must remain open.
 */
function adminSidePanelMutationKeepsPanelOpen(source) {
    return ['create', 'gallery-edit', 'gallery-image-bulk', 'image-edit', 'tag-edit', 'upload'].includes(source);
}

/**
 * Open the reusable side panel and fill it from an admin workflow.
 *
 * @param {HTMLAnchorElement} link Enhanced admin workflow link.
 * @return {Promise<void>} Resolves after this open intent loads, fails or is superseded; never applies stale HTML.
 */
async function openAdminGallerySidePanel(link) {
    const panel = ensureAdminGallerySidePanel();
    const body = panel.querySelector('[data-admin-side-panel-body]');
    if (!(body instanceof HTMLElement)) {
        window.location.href = link.href;
        return;
    }
    if (!allowAdminPanelTransition(panel, /** Resume this explicit open only after the draft/context guard permits it. @return {Promise<void>} Loads the requested workflow without submitting the old form. */ () => openAdminGallerySidePanel(link))) return;
    const owner = beginAdminPanelOpen(panel, link);
    const workflow = sidePanelWorkflowFromLink(link);
    setAdminGallerySidePanelHeading(panel, workflow.kicker, workflow.title);
    panel.dataset.adminSidePanelWorkflow = workflow.name;
    panel.dataset.adminSidePanelSourceUrl = '';
    panel.classList.toggle('is-edit-panel', workflow.name !== 'create');
    panel.classList.remove('is-uploading');
    openAdminGallerySidePanelShell(panel);
    writeAdminGallerySidePanelStatus(panel, workflow.loadingMessage, false);
    body.innerHTML = `<div class="admin-side-panel-loading" role="status">${escapeHtmlText(workflow.loadingMessage)}</div>`;
    prepareAdminPanelDrafts(panel);

    try {
        const url = new URL(link.dataset.gallerySidePanelUrl || link.href, window.location.href);
        url.searchParams.set('panel', '1');
        const response = await fetch(url.toString(), {
            credentials: 'same-origin',
            signal: owner.signal,
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (!owner.isCurrent()) return;
        if (!response.ok || html.trim() === '') {
            throw new Error(workflow.loadErrorMessage);
        }
        const content = sidePanelContentFromHtml(html, workflow);
        if (!owner.isCurrent()) return;
        body.innerHTML = content;
        panel.dataset.adminSidePanelSourceUrl = response.url || url.toString();
        prepareAdminSidePanelLoadedContent(body, workflow, response.url || url.toString());
        writeAdminGallerySidePanelStatus(panel, '', false);
        if (owner.isCurrent()) focusAdminPanelContent(panel);
    } catch (error) {
        if (!owner.isCurrent() || error?.name === 'AbortError') return;
        writeAdminGallerySidePanelStatus(panel, error.message || workflow.loadErrorMessage, true);
        body.innerHTML = `<div class="notice is-alert">${escapeHtmlText(workflow.loadErrorMessage)} ${escapeHtmlText(i18n('admin.side_panel.use_normal_page_prefix', 'Use the normal admin page instead:'))} <a href="${escapeHtmlAttribute(link.href)}">${escapeHtmlText(i18n('admin.side_panel.open_directly', 'open directly'))}</a>.</div>`;
        focusAdminPanelContent(panel);
    }
}

/**
 * Read side-panel workflow metadata from an enhanced link.
 *
 * @param {HTMLAnchorElement} link Enhanced admin workflow link.
 * @return {AdminPanelWorkflow} Resolved workflow identity and complete initial-open presentation labels.
 */
function sidePanelWorkflowFromLink(link) {
    const name = String(link.dataset.adminSidePanelWorkflow || 'create');
    if (name === 'gallery-edit') {
        return {
            name,
            kicker: link.dataset.adminSidePanelKicker || i18n('admin.side_panel.gallery_editor_kicker', 'Gallery editor'),
            title: link.dataset.adminSidePanelTitle || i18n('admin.side_panel.edit_gallery', 'Edit gallery'),
            loadingMessage: 'Loading gallery editor...',
            loadErrorMessage: 'The gallery editor could not be loaded.',
        };
    }
        if (name === 'image-edit') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || i18n('admin.side_panel.photo_editor_kicker', 'Photo editor'),
                title: link.dataset.adminSidePanelTitle || i18n('admin.side_panel.edit_photo', 'Edit photo'),
                loadingMessage: 'Loading photo editor...',
                loadErrorMessage: 'The photo editor could not be loaded.',
            };
        }
        if (name === 'tag-edit') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || i18n('admin.side_panel.tag_editor_kicker', 'Tag editor'),
                title: link.dataset.adminSidePanelTitle || i18n('admin.side_panel.edit_tag', 'Edit tag'),
                loadingMessage: 'Loading tag editor...',
                loadErrorMessage: 'The tag editor could not be loaded.',
            };
        }
        if (name === 'upload') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || i18n('admin.side_panel.upload_workflow_kicker', 'Upload workflow'),
                title: link.dataset.adminSidePanelTitle || i18n('admin.side_panel.upload_photos', 'Upload photos'),
                loadingMessage: 'Loading upload workflow...',
                loadErrorMessage: 'The upload workflow could not be loaded.',
            };
        }
        if (name === 'smart-gallery') {
            return {
                name,
                kicker: link.dataset.adminSidePanelKicker || i18n('smart_gallery.admin_title', 'Smart Galleries'),
                title: link.dataset.adminSidePanelTitle || i18n('smart_gallery.admin_title', 'Smart Galleries'),
                loadingMessage: i18n('smart_gallery.panel_loading', 'Loading Smart Gallery editor...'),
                loadErrorMessage: i18n('smart_gallery.panel_load_failed', 'The Smart Gallery editor could not be loaded.'),
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
            kicker: link.dataset.adminSidePanelKicker || i18n('admin.side_panel.admin_shortcut_kicker', 'Admin shortcut'),
            title: link.dataset.adminSidePanelTitle || i18n('admin.side_panel.add_gallery_here', 'Add gallery here'),
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
 * @return {void} Replaces heading text only in the supplied drawer.
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
 * Extract a workflow fragment from a trusted same-origin server-rendered response.
 *
 * This is layout extraction, not an HTML sanitizer. It removes the development panel
 * from full-page responses but relies on server view escaping and authorization.
 * @param {string} html Authorized server-rendered fragment or complete Admin page.
 * @param {AdminPanelWorkflow} workflow Editor identity used to select the workspace.
 * @return {string} Server HTML to mount after the caller rechecks its open/refresh owner.
 */
function sidePanelContentFromHtml(html, workflow) {
    const trimmed = html.trim();
    if (trimmed.startsWith('<div') || trimmed.startsWith('<section')) {
        return trimmed;
    }
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    if (workflow.name === 'smart-gallery') {
        const smartGalleryWorkspace = parsed.querySelector('[data-smart-gallery-editor-workspace]');
        if (smartGalleryWorkspace instanceof HTMLElement) {
            return smartGalleryWorkspace.outerHTML;
        }
    }
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
 * @param {AdminPanelWorkflow} workflow Prepared form-binding identity; initial opens also provide presentation text.
 * @param {string} sourceUrl URL that produced the loaded content.
 * @return {void} Binds injected controls and establishes the ordinary form's text baseline.
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
    } else if (workflow.name === 'smart-gallery') {
        setupAdminSmartGalleries(body);
        body.querySelectorAll('[data-smart-gallery-panel-form]').forEach(/** Mark injected Smart Gallery forms for delegated panel handling. @param {Element} form Candidate server-rendered form. @return {void} Sets binding metadata on valid forms. */ (form) => {
            if (form instanceof HTMLFormElement) form.dataset.adminSmartGalleryPanelForm = 'true';
        });
    } else if (workflow.name === 'upload') {
        const uploadForms = body.querySelectorAll('[data-gallery-upload-form]');
        uploadForms.forEach(/** Establish the injected upload form's workflow and current public source. @param {Element} uploadForm Candidate server-rendered upload form. @return {void} Sets workflow metadata and its hidden source URL. */ (uploadForm) => {
            if (uploadForm instanceof HTMLFormElement) {
                uploadForm.dataset.adminPanelWorkflow = 'upload';
                ensureUploadSourceUrlField(uploadForm);
            }
        });
    }
    const panel = body.closest('[data-admin-side-panel]');
    if (panel instanceof HTMLElement) prepareAdminPanelDrafts(panel);
}

/**
 * Store the page that opened the upload drawer so a successful upload can
 * refresh the same paginated gallery view instead of falling back to page one.
 *
 * @param {HTMLFormElement} form Upload form rendered inside the side panel.
 * @return {void} Creates or updates the transport-only hidden source_url control.
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
 * @return {void} Sets panel ownership/action metadata on a valid form and its containing drawer.
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
 * @return {void} Sets interception and refresh metadata without changing the form's action route.
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
 * @return {void} Binds each unbound grid slider and synchronizes its initial label.
 */
function setupAdminPanelRangeDisplays(root) {
    const pairs = [
        ['[data-gallery-grid-columns]', '[data-gallery-grid-columns-display]'],
        ['[data-gallery-grid-rows]', '[data-gallery-grid-rows-display]'],
    ];
    pairs.forEach(/** Bind a grid slider and its label as an idempotent pair. @param {[string, string]} selectors Tuple destructured into control and display selectors. @return {void} Binds input/change once and synchronizes the initial label. */ ([controlSelector, displaySelector]) => {
        const control = root.querySelector(controlSelector);
        const display = root.querySelector(displaySelector);
        if (!(control instanceof HTMLInputElement) || !(display instanceof HTMLElement) || control.dataset.adminPanelRangeBound === '1') {
            return;
        }
        control.dataset.adminPanelRangeBound = '1';
        const override = root.querySelector('[data-gallery-grid-override-enabled]');
        /**
         * Copy the grid slider value to its associated text label.
         * @return {void} Updates presentation text without submitting the form.
         */
        const sync = () => {
            display.textContent = control.value;
        };
        /**
         * Mark a user-adjusted grid dimension as an explicit override and refresh its label.
         * @return {void} Checks the override control when present and synchronizes its display.
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
 * @return {void} Binds valid slider pairs once and synchronizes labels, hidden pixel values and CSS track bounds.
 */
function setupAdminPanelThumbnailBoundControls(root) {
    root.querySelectorAll('[data-thumbnail-bound-control]').forEach(/** Bind a thumbnail range pair after validating its controls and size vocabulary. @param {Element} controlRoot Candidate thumbnail-bound widget. @return {void} Marks it bound and initializes valid paired controls. */ (controlRoot) => {
        if (!(controlRoot instanceof HTMLElement) || controlRoot.dataset.adminPanelThumbnailBound === '1') {
            return;
        }
        controlRoot.dataset.adminPanelThumbnailBound = '1';
        const values = String(controlRoot.getAttribute('data-thumbnail-bound-values') || '0')
            .split(',')
            .map(/** Parse a server-rendered thumbnail size token. @param {string} value Decimal pixel-size entry. @return {number} Pixel size or NaN for the next filter to exclude. */ (value) => parseInt(value, 10))
            .filter(/** Reject nonnumeric thumbnail size entries. @param {number} value Parsed pixel-size candidate. @return {boolean} Whether the candidate is finite. */ (value) => Number.isFinite(value));
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
         * Format a thumbnail bound for its paired slider labels.
         * @param {number} value Pixel size; zero denotes automatic sizing.
         * @param {'min'|'max'} side Bound whose automatic label is used.
         * @return {string} Automatic label or pixel-suffixed size.
         */
        const formatSize = (value, side) => value === 0 ? (side === 'min' ? 'Auto min' : 'Auto max') : `${value}px`;
        /**
         * Clamp slider indices and preserve min <= max after an adjustment.
         * Writes both indices, hidden pixel values, track CSS and text summaries.
         * @param {HTMLInputElement|null} changedControl Changed slider, or null for initialization.
         * @return {void} Synchronizes local bounds without submitting or generating thumbnails.
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
        minIndexControl.addEventListener('input', /** Apply this slider's changed index while keeping the pair ordered. @return {void} Updates local pixel values, track CSS and labels. */ () => sync(minIndexControl));
        minIndexControl.addEventListener('change', /** Apply this slider's changed index while keeping the pair ordered. @return {void} Updates local pixel values, track CSS and labels. */ () => sync(minIndexControl));
        maxIndexControl.addEventListener('input', /** Apply this slider's changed index while keeping the pair ordered. @return {void} Updates local pixel values, track CSS and labels. */ () => sync(maxIndexControl));
        maxIndexControl.addEventListener('change', /** Apply this slider's changed index while keeping the pair ordered. @return {void} Updates local pixel values, track CSS and labels. */ () => sync(maxIndexControl));
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
                <button type="button" class="button secondary" data-admin-side-panel-close>${escapeHtmlText(i18n('admin.side_panel.close', 'Close'))}</button>
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
 * @return {void} Activates loading-state modality before the guarded animation frame.
 */
function openAdminGallerySidePanelShell(panel) {
    const owner = captureAdminPanelOwner(panel);
    panel.hidden = false;
    panel.classList.remove('is-closing');
    panel.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-admin-side-panel');
    activateAdminPanelModal(panel);
    window.requestAnimationFrame(/** Apply the opening class only for the still-current open intent. @return {void} Leaves a superseding close/open animation untouched. */ () => {
        if (owner.isCurrent()) panel.classList.add('is-open');
    });
}

/**
 * Hide the side panel and clear its transient status.
 *
 * @param {HTMLElement} panel Side-panel root.
 * @return {void} Requests inline draft protection, then restores focus and schedules guarded exit hiding.
 */
function closeAdminGallerySidePanel(panel) {
    if (!allowAdminPanelTransition(panel, /** Resume the requested close after an explicit safe draft choice. @return {void} Does not submit or silently discard unresolved operation state. */ () => closeAdminGallerySidePanel(panel))) return;
    const stillClosing = deactivateAdminPanel(panel);
    panel.classList.remove('is-open');
    panel.classList.add('is-closing');
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('has-admin-side-panel');
    window.setTimeout(/** Hide only the exact close generation that started this exit transition. @return {void} Cannot hide a reopened drawer. */ () => {
        if (stillClosing()) {
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
 * @return {void} Updates plain status text and visibility/error classes if the status node exists.
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
 * @return {Promise<void>} Resolves after canonical success or retention of the original uncertain intent.
 */
async function submitAdminGalleryPanelCreateForm(form) {
    if (adminOperationIsRunning(form)) return;
    let operation = null;
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const owner = captureAdminPanelOwner(panel);
    const submittedDraft = submittedAdminPanelDraft(form);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, 'Creating gallery...', false);
    try {
        operation = beginAdminOperation(form, 'create');
        const body = adminOperationBody(operation, 0, new FormData(form));
        body.set('ajax', '1');
        body.set('panel', '1');
        const response = await fetch(renderedFormActionRequestUrl(form), {
            method: 'POST',
            body,
            headers: {'Accept': 'application/json'},
        });
        const result = await readJsonResponseSafely(response, 'Gallery creation failed.');
        if (!response.ok || !result.ok) {
            throw new Error(result.error || i18n('admin.side_panel.gallery_creation_failed', 'Gallery creation failed.'));
        }
        requireCanonicalUploadMutationResult(result);
        finishAdminOperation(operation, true);
        rememberAdminPanelMutation(result, owner);
        acknowledgeAdminPanelDraft(panel, submittedDraft, result);
        if (!form.isConnected) {
            await completeCoreGalleryMutationInCurrentView(result);
            return;
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: 'create',
                result,
            },
        }));
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.gallery_creation_failed', 'Gallery creation failed.'), true);
    } finally {
        finishAdminOperation(operation);
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
 * @return {Promise<void>} Resolves after its owned fragment update or a guarded error notice.
 */
async function submitAdminPanelUploadAutomationTokenForm(form) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const owner = captureAdminPanelOwner(panel);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
        rememberAdminPanelMutation(result, owner);
        const syncResult = await completeCoreGalleryMutationInCurrentView(result, {
            /** Reload the API-key fragment after persistence without exposing its contents to another drawer. @param {{refresh_url?: string}|null} panelMetadata Canonical refresh destination. @param {AdminPanelResponse} _envelope Unmodified server response. @param {AdminPanelCompletionGuard} completionGuard Combined ownership and cancellation scope. @return {Promise<boolean>} True after refresh; throws a saved-but-refresh-failed error otherwise. */
            refreshPanel: async (panelMetadata, _envelope, completionGuard) => {
                const refreshed = await refreshAdminSidePanelFromServer(String(panelMetadata?.refresh_url || result.refresh_url || refreshUrl || ''), completionGuard);
                if (!refreshed) {
                    throw new Error(i18n('admin.side_panel.api_key_refresh_failed', 'The API key was updated, but the refreshed API-key panel could not be loaded. The server change was kept.'));
                }
                return true;
            },
        });
        if (owner.isCurrent() && syncResult.synchronized) {
            writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.side_panel.api_key_updated', 'API key updated.')), false);
        }
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.api_key_failed', 'API key update failed.'), true);
    } finally {
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
 * Submit a Smart Gallery editor form without leaving the current public/admin context.
 *
 * Preview remains an HTML-only, non-persistent workflow. Persistent actions request
 * the canonical mutation envelope and delegate panel/public synchronization to the
 * shared coordinator. Direct-page submissions retain the controller redirect fallback.
 *
 * @param {HTMLFormElement} form Smart Gallery form rendered in the side panel.
 * @param {HTMLElement|null} submitter Submit button whose name/value must be included in the POST.
 * @return {Promise<void>} Resolves after owned preview rendering or canonical persistent completion, preserving later workflows.
 */
async function submitAdminSmartGalleryPanelForm(form, submitter) {
    const panel = form.closest('[data-admin-side-panel]');
    const bodyElement = panel?.querySelector('[data-admin-side-panel-body]');
    if (!(panel instanceof HTMLElement) || !(bodyElement instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const owner = captureAdminPanelOwner(panel);
    const formData = new FormData(form);
    if (submitter instanceof HTMLElement) {
        const name = String(submitter.getAttribute('name') || '');
        const value = String(submitter.getAttribute('value') || '');
        if (name !== '') formData.set(name, value);
    }
    const action = String(formData.get('action') || 'save');
    const persistentAction = action !== 'preview';
    if (persistentAction && action === 'delete' && String(form.dataset.confirm || '') !== '' && !window.confirm(String(form.dataset.confirm))) {
        return;
    }

    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => { button.disabled = true; });
    writeAdminGallerySidePanelStatus(panel, i18n('smart_gallery.panel_saving', 'Updating Smart Gallery...'), false);
    try {
        const actionUrl = new URL(form.getAttribute('action') || form.action || window.location.href, window.location.href);
        actionUrl.searchParams.set('panel', '1');
        const requestUrl = String(actionUrl.searchParams.get('page') || '') === 'admin_smart_galleries'
            ? `${actionUrl.pathname}${actionUrl.search}${actionUrl.hash}`
            : sameSitePathForPost(actionUrl.toString());
        if (requestUrl === '') {
            throw new Error(i18n('smart_gallery.panel_save_failed', 'The Smart Gallery update failed.'));
        }

        formData.set('panel', '1');
        if (persistentAction) {
            formData.set('ajax', '1');
        }
        const response = await fetch(requestUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': persistentAction ? 'application/json' : 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!persistentAction) {
            const html = await response.text();
            if (!owner.isCurrent()) return;
            if (!response.ok || html.trim() === '') {
                throw new Error(i18n('smart_gallery.panel_save_failed', 'The Smart Gallery update failed.'));
            }
            const workflow = {
                name: 'smart-gallery',
                kicker: i18n('smart_gallery.admin_title', 'Smart Galleries'),
                title: i18n('smart_gallery.admin_title', 'Smart Galleries'),
                loadingMessage: i18n('smart_gallery.panel_loading', 'Loading Smart Gallery editor...'),
                loadErrorMessage: i18n('smart_gallery.panel_load_failed', 'The Smart Gallery editor could not be loaded.'),
            };
            const restoreFocus = preserveAdminPanelFocus(panel);
            bodyElement.innerHTML = sidePanelContentFromHtml(html, workflow);
            panel.dataset.adminSidePanelSourceUrl = response.url || actionUrl.toString();
            prepareAdminSidePanelLoadedContent(bodyElement, workflow, response.url || actionUrl.toString());
            const titleInput = bodyElement.querySelector('[data-smart-gallery-editor] input[name="title"]');
            if (titleInput instanceof HTMLInputElement && titleInput.value.trim() !== '') {
                setAdminGallerySidePanelHeading(panel, workflow.kicker, titleInput.value.trim());
            }
            writeAdminGallerySidePanelStatus(panel, '', false);
            restoreFocus();
            return;
        }

        const result = await readJsonResponseSafely(response, i18n('smart_gallery.panel_save_failed', 'The Smart Gallery update failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('smart_gallery.panel_save_failed', 'The Smart Gallery update failed.'));
        }
        rememberAdminPanelMutation(result, owner);
        await completeCoreGalleryMutationInCurrentView(result, {
            /** Reload only the owning Smart Gallery editor and update its current heading. @param {{refresh_url?: string}|null} panelMetadata Canonical editor refresh metadata. @param {Record<string, unknown>} _envelope Unmodified canonical response. @param {{isCurrent?: function(): boolean, signal?: AbortSignal}} completionGuard Coordinator generation and cancellation signal. @return {Promise<boolean>} Whether the owned editor refresh completed. */
            refreshPanel: async (panelMetadata, _envelope, completionGuard) => {
                const refreshed = await refreshAdminSidePanelFromServer(String(panelMetadata?.refresh_url || ''), completionGuard);
                if (refreshed && owner.isCurrent() && (!completionGuard?.isCurrent || completionGuard.isCurrent())) {
                    const refreshedBody = panel.querySelector('[data-admin-side-panel-body]');
                    const titleInput = refreshedBody?.querySelector?.('[data-smart-gallery-editor] input[name="title"]');
                    const title = titleInput instanceof HTMLInputElement ? titleInput.value.trim() : '';
                    setAdminGallerySidePanelHeading(
                        panel,
                        i18n('smart_gallery.admin_title', 'Smart Galleries'),
                        title || i18n('smart_gallery.admin_title', 'Smart Galleries')
                    );
                }
                return refreshed;
            },
        });
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('smart_gallery.panel_save_failed', 'The Smart Gallery update failed.'), true);
    } finally {
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => { button.disabled = false; });
    }
}

/**
 * Submit an existing admin edit form through the side-panel JSON path.
 *
 * The side panel stays open while the existing admin save route returns JSON.
 * The same form/submission generation acknowledges its just-saved revision before
 * completion dispatch, so a later save need not await an older editor GET. No
 * server response merges privacy fields or overwrites text typed during the POST;
 * another writer's stale revision remains a server-side conflict.
 *
 * @param {HTMLFormElement} form Side-panel edit form.
 * @param {HTMLElement|null} submitter Submit button that selected a named action.
 * @return {Promise<void>} Resolves after success handling or error reporting.
 */
async function submitAdminPanelEditForm(form, submitter = null) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const workflowName = String(form.dataset.adminPanelWorkflow || 'edit');
    const owner = captureAdminPanelOwner(panel);
    const submission = beginAdminPanelSave(form, owner);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
        button.disabled = true;
    });
    writeAdminGallerySidePanelStatus(panel, workflowName === 'image-edit' ? i18n('admin.side_panel.saving_photo', 'Saving photo...') : (workflowName === 'tag-edit' ? i18n('admin.side_panel.saving_tag', 'Saving tag...') : i18n('admin.side_panel.saving_gallery', 'Saving gallery...')), false);
    try {
        const body = new FormData(form);
        if ((submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) && submitter.name !== '') {
            body.set(submitter.name, submitter.value);
        }
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
        rememberAdminPanelMutation(result, owner);
        // Accept only this form's just-saved revision before dispatch starts the
        // independent refresh; later server writers must still reject stale edits.
        acknowledgeAdminPanelSave(submission, result);
        if (!form.isConnected) {
            await completeCoreGalleryMutationInCurrentView(result);
            return;
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: workflowName,
                result,
            },
        }));
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.save_failed', 'Save failed.'), true);
    } finally {
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
            button.disabled = false;
        });
    }
}

/**
 * Submit a small panel-owned auxiliary form through the canonical mutation coordinator.
 *
 * @param {HTMLFormElement} form Auxiliary mutation form.
 * @param {HTMLElement|null} submitter Submit control whose name/value must be included in the POST.
 * @return {Promise<void>} Resolves after synchronization or controlled error reporting.
 */
async function submitAdminPanelAuxiliaryMutation(form, submitter = null) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const owner = captureAdminPanelOwner(panel);
    const buttons = Array.from(form.querySelectorAll('button, input[type="submit"]'));
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => { button.disabled = true; });
    writeAdminGallerySidePanelStatus(panel, i18n('admin.side_panel.processing', 'Processing...'), false);
    try {
        const body = new FormData(form);
        if (submitter instanceof HTMLElement) {
            const name = String(submitter.getAttribute('name') || '');
            const value = String(submitter.getAttribute('value') || '');
            if (name !== '') {
                body.set(name, value);
            }
        }
        body.set('ajax', '1');
        body.set('panel', '1');
        const response = await fetch(renderedFormActionRequestUrl(form), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.mutation_failed', 'The operation failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.mutation_failed', 'The operation failed.'));
        }
        rememberAdminPanelMutation(result, owner);
        await completeCoreGalleryMutationInCurrentView(result);
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.mutation_failed', 'The operation failed.'), true);
    } finally {
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => { button.disabled = false; });
    }
}

/**
 * Submit the gallery image bulk form from a side panel without relying on browser submitter routing.
 *
 * @param {HTMLFormElement} form Loaded gallery image bulk form.
 * @param {HTMLElement|null} submitter Button or control that triggered the submit.
 * @return {Promise<void>} Resolves after canonical bulk completion or an original-owner error notice.
 */
async function submitAdminPanelImageBulkForm(form, submitter) {
    const panel = form.closest('[data-admin-side-panel]');
    if (!(panel instanceof HTMLElement)) {
        HTMLFormElement.prototype.submit.call(form);
        return;
    }
    const owner = captureAdminPanelOwner(panel);
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
    buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
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
            const newGalleryParent = form.querySelector('select[name="new_gallery_parent_id"]:enabled, input[type="hidden"][name="new_gallery_parent_id"]:enabled');
            const newGalleryTitle = form.querySelector('input[name="new_gallery_title"]');
            const newGalleryFolderName = form.querySelector('input[name="new_gallery_folder_name"]');
            if (newGalleryParent instanceof HTMLSelectElement || newGalleryParent instanceof HTMLInputElement) {
                body.set('new_gallery_parent_id', newGalleryParent.value);
            }
            if (newGalleryTitle instanceof HTMLInputElement) {
                body.set('new_gallery_title', newGalleryTitle.value);
            }
            if (newGalleryFolderName instanceof HTMLInputElement) {
                body.set('new_gallery_folder_name', newGalleryFolderName.value);
            }
        }
        selectedInputs.forEach(/** Serialize only selected image identifiers from the original bulk form. @param {Element} input Checked image-ID control. @return {void} Appends the ID only when the candidate is an input. */ (input) => {
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
        rememberAdminPanelMutation(result, owner);
        if (!form.isConnected) {
            await completeCoreGalleryMutationInCurrentView(result);
            return;
        }
        form.dispatchEvent(new CustomEvent('php-gallery:side-panel-success', {
            bubbles: true,
            detail: {
                source: 'gallery-image-bulk',
                result,
            },
        }));
    } catch (error) {
        if (owner.isCurrent()) writeAdminGallerySidePanelStatus(panel, error.message || i18n('admin.side_panel.photo_action_failed', 'Photo action failed.'), true);
    } finally {
        buttons.forEach(/** Update a control captured from this submitted form, never from a replacement workflow. @param {HTMLButtonElement|HTMLInputElement} button Original form control. @return {void} Sets or restores its pending state and optional progress label. */ (button) => {
            button.disabled = false;
        });
    }
}

/**
 * Reflect a completed gallery image bulk action in the visible edit table and public context.
 *
 * @param {AdminPanelResponse} result Server response for the image bulk action.
 * @return {Promise<void>} Delegates refresh to the coordinator, then appends a public result notice.
 */
async function reflectGalleryImageBulkInCurrentView(result) {
    const action = String(result.bulk_action || '');
    const noticeTarget = action === 'delete'
        ? String(result.gallery_url || '')
        : String(result.destination_gallery_url || result.gallery_url || '');
    await completeCoreGalleryMutationInCurrentView(result);
    showAdminGallerySidePanelResultNotice(
        String(result.message || (action === 'delete'
            ? i18n('admin.side_panel.photo_deleted', 'Photo deleted.')
            : i18n('admin.side_panel.photo_action_completed', 'Photo action completed.'))),
        noticeTarget
    );
}

/**
 * Reflect a saved tag after editing it from the side panel.
 *
 * Tag slugs can change; the canonical coordinator owns the refreshed public
 * context. The returned public URL is used only for the result notice link here.
 *
 * @param {AdminPanelResponse} result Server response for the saved tag.
 * @return {Promise<void>} Completes canonical synchronization, then appends a link to the saved tag.
 */
async function reflectSavedTagInCurrentView(result) {
    await completeCoreGalleryMutationInCurrentView(result);
    showAdminGallerySidePanelResultNotice(
        String(result.message || i18n('admin.tags.saved', 'Tag saved.')),
        String(result.public_url || '')
    );
}

/**
 * Reflect a saved gallery in the current page without forcing a full navigation.
 *
 * The side panel save workflow refreshes visible title and notice state after JSON save.
 *
 * @param {AdminPanelResponse} result Server response for the saved gallery.
 * @return {Promise<void>} Resolves after the visible gallery state is refreshed.
 */
async function reflectSavedGalleryInCurrentView(result) {
    const panel = document.querySelector('[data-admin-side-panel]');
    if (panel instanceof HTMLElement && adminPanelMutationOwner(result, panel).isCurrent()) {
        // Persistence has already succeeded at this point. Do not leave the drawer
        // saying "Saving gallery..." while the independent public GET converges.
        writeAdminGallerySidePanelStatus(panel, String(result.message || i18n('admin.side_panel.gallery_saved', 'Gallery saved.')), false);
    }
    // Reflect the exact persisted visibility scalar immediately on a currently
    // visible child card. The canonical server-rendered refresh below remains
    // authoritative for the complete card and parent context, but this bounded
    // micro-update prevents a successful Published/Unpublished save from looking
    // unchanged while a shared host finishes read-after-write convergence.
    updatePublicGalleryCardVisibilityFromResult(result);
    await completeCoreGalleryMutationInCurrentView(result);
    showAdminGallerySidePanelResultNotice(
        String(result.message || i18n('admin.side_panel.gallery_saved', 'Gallery saved.')),
        String(result.gallery_url || '')
    );
}

/**
 * Apply the persisted visibility scalar to a gallery card already visible behind the editor.
 *
 * This is deliberately a bounded micro-update, not an alternate gallery renderer.
 * The server-provided mutation result owns the scalar state and the shared mutation
 * coordinator still refreshes the complete parent fragment from PHP immediately after.
 *
 * @param {AdminPanelResponse} result Canonical gallery-save result.
 * @return {void} Updates an existing matching card's scalar marker only; complete rendering stays coordinator-owned.
 */
function updatePublicGalleryCardVisibilityFromResult(result) {
    if (result?.gallery_visibility_changed !== true) {
        return;
    }
    const galleryId = String(result?.gallery_id || '');
    const visibility = String(result?.gallery_visibility || '');
    if (galleryId === '' || !['public', 'unpublished', 'private'].includes(visibility)) {
        return;
    }
    const escapedId = CSS.escape(galleryId);
    const card = document.querySelector(`[data-public-subgallery-grid] [data-gallery-id="${escapedId}"], .public-home-gallery-grid [data-gallery-id="${escapedId}"]`);
    if (!(card instanceof HTMLElement)) {
        return;
    }

    card.dataset.galleryVisibility = visibility;
    const unpublished = visibility === 'unpublished';
    card.classList.toggle('is-admin-unpublished-gallery', unpublished);

    const existingMarker = card.querySelector('.admin-gallery-visibility-marker');
    if (!unpublished) {
        existingMarker?.remove();
        return;
    }
    if (existingMarker instanceof HTMLElement) {
        existingMarker.textContent = String(result?.gallery_visibility_label || 'unpublished');
        existingMarker.title = String(result?.gallery_visibility_hint || '');
        return;
    }

    const marker = document.createElement('span');
    marker.className = 'admin-gallery-visibility-marker';
    marker.textContent = String(result?.gallery_visibility_label || 'unpublished');
    marker.title = String(result?.gallery_visibility_hint || '');
    card.prepend(marker);
}

/**
 * Reflect a saved image in the current page without forcing a full navigation.
 *
 * @param {AdminPanelResponse} result Server response for the saved image.
 * @return {Promise<void>} Applies bounded image metadata updates before canonical synchronization and a result notice.
 */
async function reflectSavedImageInCurrentView(result) {
    const imageId = String(result.image_id || '');
    if (imageId) {
        // These bounded micro-updates improve perceived responsiveness only.
        // The canonical server-rendered refresh below remains authoritative.
        updateAdminImageRowsFromResult(imageId, result);
        updatePublicImageCardsFromResult(imageId, result);
    }

    await completeCoreGalleryMutationInCurrentView(result);
    showAdminGallerySidePanelResultNotice(String(result.message || i18n('admin.side_panel.photo_saved', 'Photo saved.')), String(result.image_url || ''));
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
 * @param {AdminPanelResponse} result Server response for the upload operation.
 * @return {Promise<void>} Synchronizes public state and requests an owner-guarded editor transition.
 */
async function reflectUploadedGalleryInCurrentView(result) {
    if (Boolean(result.created_gallery)) {
        await reflectCreatedGalleryInCurrentView(result);
        return;
    }

    await completeCoreGalleryMutationInCurrentView(result, {
        /** Move the acknowledged upload into its editor only while the combined owner is current. @param {{refresh_url?: string}|null} _panelMetadata Canonical panel metadata, superseded here by the upload editor destination. @param {AdminPanelResponse} _envelope Unmodified completion response. @param {AdminPanelCompletionGuard} completionGuard Combined open/completion cancellation scope. @return {Promise<boolean>} Whether the editor transition was handled or safely suppressed. */
        refreshPanel: async (_panelMetadata, _envelope, completionGuard) => switchAdminSidePanelToGalleryEditor(result, completionGuard),
    });
    showAdminGallerySidePanelResultNotice(
        String(result.message || i18n('admin.side_panel.upload_complete', 'Upload complete.')),
        String(result.gallery_url || '')
    );
}

/**
 * Delegate one canonical mutation's public and panel synchronization to its owner.
 *
 * Open-intent and completion guards are composed before any editor GET. Workflow
 * overrides may customize only that guarded panel refresh; public fetch, stale-read
 * retry, postcondition verification and replacement sequencing remain exclusively
 * in admin-mutation-completion.js. Detached results cannot adopt the new drawer.
 * @param {AdminPanelResponse} result Unmodified canonical response with its remembered panel owner.
 * @param {AdminPanelCompletionOverrides} overrides Optional owner-guarded panel refresh implementation.
 * @return {Promise<AdminPanelSynchronization>} Original coordinator outcome, not a new mutation response.
 */
async function completeCoreGalleryMutationInCurrentView(result, overrides = {}) {
    const panel = document.querySelector('[data-admin-side-panel]');
    const owner = adminPanelMutationOwner(result, panel);
    const syncResult = await completeAdminMutation(result, {
        documentRoot: document,
        currentUrl: currentVisiblePageRefreshUrl(),
        /** Compose the open-intent guard with the canonical completion generation before refreshing. @param {{refresh_url?: string}|null} panelMetadata Canonical editor destination. @param {Record<string, unknown>} envelope Unmodified canonical completion response. @param {{isCurrent?: function(): boolean, signal?: AbortSignal}} completionGuard Coordinator-owned refresh scope. @return {Promise<boolean>} Whether the guarded refresh was handled. */
        refreshPanel: async (panelMetadata, envelope, completionGuard) => {
            const guard = combineAdminPanelGuards(owner, completionGuard);
            if (!guard.isCurrent()) return true;
            return typeof overrides.refreshPanel === 'function'
                ? overrides.refreshPanel(panelMetadata, envelope, guard)
                : refreshAdminSidePanelFromServer(String(panelMetadata?.refresh_url || ''), guard);
        },
        /** Install only coordinator-verified public fragments and release old bindings first. @param {Document} parsed Detached server document already checked by the canonical coordinator. @return {boolean} Whether at least one owned public fragment was replaced. */
        replacePublicContext: (parsed) => replaceOwnedPublicGalleryFragments(parsed, {
            documentRoot: document,
            beforeReplace: teardownPublicGalleryLifecycleBeforeRefresh,
        }),
        /** Notify dependent modules and rebind controls after canonical public replacement. @return {void} Dispatches the public-content lifecycle event and restores public bindings. */
        afterPublicReplace: () => {
            document.dispatchEvent(new CustomEvent('php-gallery:public-content-replaced'));
            rebindPublicGalleryLifecycleAfterRefresh();
        },
        /** Report an unverified public refresh only while this mutation still owns the drawer. @return {void} Keeps the successful mutation distinct from refresh failure. */
        reportSynchronizationError: () => {
            if (panel instanceof HTMLElement && owner.isCurrent()) {
                writeAdminGallerySidePanelStatus(
                    panel,
                    i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                    true
                );
            }
        },
    });
    if (panel instanceof HTMLElement && owner.isCurrent() && syncResult.synchronized) {
        writeAdminGallerySidePanelStatus(panel, String(result.message || ''), false);
    }
    return syncResult;
}

/**
 * Delete one gallery card from the currently visible parent through the shared mutation coordinator.
 *
 * Hero deletion intentionally keeps its direct-page redirect fallback because the deleted
 * gallery page itself has no valid same-URL server-rendered postcondition. Stage 2 enhances
 * only card deletion, where the owning parent/root context can be verified in place.
 *
 * @param {HTMLFormElement} form Public gallery-card delete form.
 * @return {Promise<void>} Resolves after mutation completion or an inline failure notice.
 */
async function submitPublicGalleryCardDelete(form) {
    const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
        submitButton.disabled = true;
    }
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        const response = await fetch(renderedFormActionRequestUrl(form), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.gallery_delete_failed', 'Gallery delete failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.gallery_delete_failed', 'Gallery delete failed.'));
        }
        const syncResult = await completeCoreGalleryMutationInCurrentView(result);
        if (!syncResult.synchronized) {
            showAdminGallerySidePanelResultNotice(
                i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                String(result.fallback?.redirect_url || '')
            );
            return;
        }
        showAdminGallerySidePanelResultNotice(String(result.message || i18n('admin.side_panel.gallery_deleted', 'Gallery deleted.')), '');
    } catch (error) {
        showAdminGallerySidePanelResultNotice(
            error instanceof Error ? error.message : i18n('admin.side_panel.gallery_delete_failed', 'Gallery delete failed.'),
            ''
        );
    } finally {
        if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
            submitButton.disabled = false;
        }
    }
}

/**
 * Delete one public photo card through the canonical mutation coordinator.
 *
 * The owning gallery remains the visible context, so the enhanced path can verify
 * that the deleted image id is absent without navigating away from the page.
 *
 * @param {HTMLFormElement} form Public image-card delete form.
 * @return {Promise<void>} Resolves after mutation completion or an inline failure notice.
 */
async function submitPublicImageCardDelete(form) {
    const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
        submitButton.disabled = true;
    }
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        const response = await fetch(renderedFormActionRequestUrl(form), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const result = await readJsonResponseSafely(response, i18n('admin.side_panel.photo_delete_failed', 'Photo delete failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('admin.side_panel.photo_delete_failed', 'Photo delete failed.'));
        }
        const syncResult = await completeCoreGalleryMutationInCurrentView(result);
        if (!syncResult.synchronized) {
            showAdminGallerySidePanelResultNotice(
                i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                String(result.fallback?.redirect_url || '')
            );
            return;
        }
        showAdminGallerySidePanelResultNotice(String(result.message || i18n('admin.side_panel.photo_deleted', 'Photo deleted.')), '');
    } catch (error) {
        showAdminGallerySidePanelResultNotice(
            error instanceof Error ? error.message : i18n('admin.side_panel.photo_delete_failed', 'Photo delete failed.'),
            ''
        );
    } finally {
        if (submitButton instanceof HTMLButtonElement || submitButton instanceof HTMLInputElement) {
            submitButton.disabled = false;
        }
    }
}

/**
 * Resolve the card and canonical target metadata for one visibility form.
 *
 * The card can be replaced by the mutation coordinator after the request, so the
 * stable kind/id pair is captured before submission and used to find the fresh
 * server-rendered card for the completion animation.
 *
 * @param {HTMLFormElement} form Visibility option form.
 * @return {AdminPanelVisibilityTarget} Stable identity plus the original card for pending feedback.
 */
function publicCardVisibilityMutationTarget(form) {
    const kind = form.dataset.publicAdminVisibilityKind === 'image' ? 'image' : 'gallery';
    const idField = kind === 'image' ? 'image_id' : 'gallery_id';
    const id = Math.max(0, Number(form.querySelector(`input[name="${idField}"]`)?.value || 0));
    const card = form.closest('.gallery-card, .image-card');
    return {
        kind,
        id: Number.isInteger(id) ? id : 0,
        card: card instanceof HTMLElement ? card : null,
    };
}

/**
 * Find the fresh server-rendered card by its captured stable kind/id, not the old node.
 * @param {AdminPanelVisibilityTarget|null|undefined} target Identity captured before submission and optional original card.
 * @return {HTMLElement|null} Current matching card, or null for an invalid/missing identity.
 */
function publicCardForVisibilityTarget(target) {
    if (!target || target.id <= 0) return null;
    const selector = target.kind === 'image'
        ? `.image-card[data-public-order-id="${target.id}"]`
        : `.gallery-card[data-gallery-id="${target.id}"]`;
    const card = document.querySelector(selector);
    return card instanceof HTMLElement ? card : null;
}

/**
 * Mark the original card busy while its visibility request is outstanding.
 * @param {AdminPanelVisibilityTarget|null|undefined} target Identity captured before submission and optional original card.
 * @return {void} Clears the previous success pulse and sets pending CSS/aria-busy on the original node.
 */
function beginPublicCardVisibilityFeedback(target) {
    const card = target?.card;
    if (!(card instanceof HTMLElement)) return;
    card.classList.remove('is-visibility-updated');
    card.classList.add('is-visibility-updating');
    card.setAttribute('aria-busy', 'true');
}

/**
 * Clear pending visibility feedback only from the still-connected original card.
 * @param {AdminPanelVisibilityTarget|null|undefined} target Identity captured before submission and optional original card.
 * @return {void} Removes pending CSS/aria-busy without altering a replacement card.
 */
function cancelPublicCardVisibilityFeedback(target) {
    const card = target?.card;
    if (!(card instanceof HTMLElement) || !card.isConnected) return;
    card.classList.remove('is-visibility-updating');
    card.removeAttribute('aria-busy');
}

/**
 * Pulse the freshly rendered card after canonical visibility verification.
 * @param {AdminPanelVisibilityTarget|null|undefined} target Identity captured before submission and optional original card.
 * @return {void} Restarts the success animation on the current card and schedules class cleanup.
 */
function completePublicCardVisibilityFeedback(target) {
    const card = publicCardForVisibilityTarget(target);
    if (!(card instanceof HTMLElement)) return;
    card.classList.remove('is-visibility-updating', 'is-visibility-updated');
    card.removeAttribute('aria-busy');
    // Force a style boundary so repeated changes on the same card replay the confirmation animation.
    void card.offsetWidth;
    card.classList.add('is-visibility-updated');
    window.setTimeout(/** End the visibility confirmation pulse only on the captured still-connected card. @return {void} Removes its success class without looking up a replacement node. */ () => {
        if (card.isConnected) card.classList.remove('is-visibility-updated');
    }, 700);
}

/**
 * Change one gallery or image card visibility through the canonical coordinator.
 *
 * @param {HTMLFormElement} form Visibility option form.
 * @return {Promise<void>} Resolves after the current public context is refreshed.
 */
async function submitPublicCardVisibility(form) {
    const target = publicCardVisibilityMutationTarget(form);
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) submitButton.disabled = true;
    beginPublicCardVisibilityFeedback(target);
    try {
        const body = new FormData(form);
        body.set('ajax', '1');
        const response = await fetch(renderedFormActionRequestUrl(form), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });
        const result = await readJsonResponseSafely(response, i18n('gallery.visibility.update_failed', 'Visibility update failed.'));
        if (!response.ok || !result.ok) {
            throw new Error(result.error || result.message || i18n('gallery.visibility.update_failed', 'Visibility update failed.'));
        }
        const syncResult = await completeCoreGalleryMutationInCurrentView(result);
        if (!syncResult.synchronized) {
            cancelPublicCardVisibilityFeedback(target);
            showAdminGallerySidePanelResultNotice(
                i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                String(result.fallback?.redirect_url || '')
            );
            return;
        }
        completePublicCardVisibilityFeedback(target);
        showAdminGallerySidePanelResultNotice(String(result.message || i18n('gallery.visibility.updated', 'Visibility updated.')), '');
    } catch (error) {
        cancelPublicCardVisibilityFeedback(target);
        showAdminGallerySidePanelResultNotice(
            error instanceof Error ? error.message : i18n('gallery.visibility.update_failed', 'Visibility update failed.'),
            ''
        );
    } finally {
        if (submitButton instanceof HTMLButtonElement && submitButton.isConnected) submitButton.disabled = false;
    }
}

/**
 * Re-render the visible admin side-panel workflow from the current server response.
 *
 * This keeps the side panel open while refreshing counts, table rows, upload forms,
 * and other gallery metadata without a full navigation reload. Current ownership
 * and unsaved text/unresolved operation protection are checked before the GET and
 * again before replacement. Superseded or protected work counts as handled (true),
 * not a reason to retry a mutation; false reports an unavailable/failed editor GET.
 *
 * @param {string} sourceUrl Optional panel source URL. Falls back to the active form or current page.
 * @param {AdminPanelCompletionGuard|null} completionGuard Optional coordinator generation/abort scope, composed with the captured drawer owner.
 * @return {Promise<boolean>} True for refreshed or deliberately suppressed work; false for a missing/failed editor response.
 */
async function refreshAdminSidePanelFromServer(sourceUrl = '', completionGuard = null) {
    const panelOwner = captureAdminPanelOwner(document.querySelector('[data-admin-side-panel]'));
    completionGuard = combineAdminPanelGuards(panelOwner, completionGuard);
    try {
        if (completionGuard?.isCurrent && !completionGuard.isCurrent()) {
            return true;
        }
        const panel = document.querySelector('[data-admin-side-panel]');
        const body = panel instanceof HTMLElement ? panel.querySelector('[data-admin-side-panel-body]') : null;
        if (panel instanceof HTMLElement && adminPanelHasUnsavedText(panel)) return true;
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
            signal: completionGuard?.signal || undefined,
            headers: {
                'Accept': 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const html = await response.text();
        if (completionGuard?.isCurrent && !completionGuard.isCurrent()) {
            return true;
        }
        if (!response.ok || html.trim() === '') {
            return false;
        }
        if (adminPanelHasUnsavedText(panel)) return true;
        const restoreFocus = preserveAdminPanelFocus(panel);
        body.innerHTML = sidePanelContentFromHtml(html, {name: workflowName});
        panel.dataset.adminSidePanelSourceUrl = resolvedUrl;
        prepareAdminSidePanelLoadedContent(body, {name: workflowName}, resolvedUrl);
        if (activeTabId) {
            activateAdminTabInRoot(body, activeTabId);
        }
        restoreFocus();
        if (panel instanceof HTMLElement) {
            panel.dataset.adminSidePanelSourceUrl = resolvedUrl;
        }
        if (panelDialog instanceof HTMLElement) {
            requestAnimationFrame(/** Restore drawer scroll only if this refresh still owns its completion generation. @return {void} Restores the captured scroll offset, or keeps uploading progress at the top. */ () => {
                if (!completionGuard?.isCurrent || completionGuard.isCurrent()) {
                    panelDialog.scrollTop = panel.classList.contains('is-uploading') ? 0 : panelScrollTop;
                }
            });
        }
        return true;
    } catch (error) {
        if (completionGuard?.isCurrent && !completionGuard.isCurrent()) {
            return true;
        }
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
 * Resolve a server-rendered form action onto the browser's current origin.
 *
 * url_for() may contain the configured canonical host while an administrator is
 * legitimately using the same installation through another host alias or scheme.
 * Posting the absolute action with fetch() can then lose the current session and
 * follow an HTML login response. The form action itself is trusted server markup,
 * so retain its application path/query while using the origin that owns this page.
 *
 * @param {HTMLFormElement} form Server-rendered mutation form.
 * @return {string} Current-origin path/query/hash for fetch().
 */
function renderedFormActionRequestUrl(form) {
    const actionValue = String(form.getAttribute('action') || form.action || window.location.href).trim();
    try {
        const url = new URL(actionValue || window.location.href, window.location.href);
        return `${url.pathname}${url.search}${url.hash}`;
    } catch (error) {
        return `${window.location.pathname}${window.location.search}${window.location.hash}`;
    }
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
 * @param {AdminPanelResponse} result Server response for the saved image.
 * @return {void} Updates matching existing rows' visibility text and numeric sort metadata.
 */
function updateAdminImageRowsFromResult(imageId, result) {
    document.querySelectorAll(`[data-admin-image-order-row][data-image-id="${CSS.escape(imageId)}"]`).forEach(/** Apply acknowledged visibility/order scalars to a matching existing admin image row. @param {Element} row Existing row for the saved image ID. @return {void} Updates text and numeric order metadata without rendering a replacement table. */ (row) => {
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
 * @param {AdminPanelResponse} result Server response for the saved image.
 * @return {void} Updates matching cards' text-only title/description overlays and lightbox metadata.
 */
function updatePublicImageCardsFromResult(imageId, result) {
    document.querySelectorAll(`[data-lightbox-image][data-image-id="${CSS.escape(imageId)}"]`).forEach(/** Apply acknowledged text metadata to an existing matching public image card. @param {Element} card Existing lightbox-enabled card for the saved image ID. @return {void} Updates dataset values and text-only overlay nodes, leaving canonical rendering to the coordinator. */ (card) => {
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
 * @param {AdminPanelResponse} result Server response containing the created gallery edit URL.
 * @param {AdminPanelCompletionGuard|null} completionGuard Optional coordinator generation/abort scope, composed with the captured drawer owner.
 * @return {Promise<boolean>} True for a loaded or owner/draft-suppressed transition; false for an unavailable/failed editor.
 */
async function switchAdminSidePanelToGalleryEditor(result, completionGuard = null) {
    completionGuard = combineAdminPanelGuards(adminPanelMutationOwner(result, document.querySelector('[data-admin-side-panel]')), completionGuard);
    if (completionGuard?.isCurrent && !completionGuard.isCurrent()) {
        return true;
    }
    const panel = document.querySelector('[data-admin-side-panel]');
    const editUrl = String(result.edit_url || '');
    if (!(panel instanceof HTMLElement) || editUrl === '') {
        return false;
    }
    if (adminPanelHasUnsavedText(panel)) return true;
    if (completionGuard?.isCurrent && !completionGuard.isCurrent()) {
        return true;
    }
    panel.dataset.adminSidePanelWorkflow = 'gallery-edit';
    panel.dataset.adminSidePanelSourceUrl = editUrl;
    panel.classList.add('is-edit-panel');
    setAdminGallerySidePanelHeading(panel, i18n('admin.side_panel.gallery_editor_kicker', 'Gallery editor'), String(result.gallery_title || i18n('admin.side_panel.edit_gallery', 'Edit gallery')));
    writeAdminGallerySidePanelStatus(panel, 'Loading gallery editor...', false);
    const refreshed = await refreshAdminSidePanelFromServer(editUrl, completionGuard);
    if (!completionGuard?.isCurrent || completionGuard.isCurrent()) {
        writeAdminGallerySidePanelStatus(panel, '', false);
    }
    return refreshed;
}

/**
 * Reflect the created child gallery in the currently visible public page.
 *
 * The persisted server render remains the source of truth for ordering, covers,
 * image counts, admin controls, and pagination after side-panel creation.
 *
 * @param {AdminPanelResponse} result Server response for the created gallery.
 * @return {Promise<void>} Resolves after the current page context is refreshed.
 */
async function reflectCreatedGalleryInCurrentView(result) {
    const owner = adminPanelMutationOwner(result, document.querySelector('[data-admin-side-panel]'));
    const galleryUrl = String(result.gallery_url || '');
    const galleryTitle = String(result.gallery_title || i18n('admin.side_panel.new_gallery', 'New gallery'));
    const galleryId = String(result.gallery_id || '');
    if (!galleryUrl) {
        showAdminGallerySidePanelResultNotice(galleryTitle, '');
        return;
    }

    const syncResult = await completeAdminMutation(result, {
        documentRoot: document,
        currentUrl: currentVisiblePageRefreshUrl(),
        /** Switch to the created gallery only when both open and completion owners remain current. @param {{refresh_url?: string}|null} panelMetadata Canonical editor destination. @param {Record<string, unknown>} _envelope Unmodified canonical response. @param {{isCurrent?: function(): boolean, signal?: AbortSignal}} completionGuard Canonical generation and abort scope. @return {Promise<boolean>} Whether the created-gallery editor refresh was handled. */
        refreshPanel: async (panelMetadata, _envelope, completionGuard) => {
            completionGuard = combineAdminPanelGuards(owner, completionGuard);
            if (!completionGuard.isCurrent()) return true;
            if (String(panelMetadata?.refresh_url || '') === '') {
                return false;
            }
            return switchAdminSidePanelToGalleryEditor(result, completionGuard);
        },
        /** Install the coordinator-verified parent/gallery fragments after releasing their old bindings. @param {Document} parsed Detached server document already checked for the create mutation's postconditions. @return {boolean} Whether an owned public fragment was replaced. */
        replacePublicContext: (parsed) => replaceOwnedPublicGalleryFragments(parsed, {
            documentRoot: document,
            beforeReplace: teardownPublicGalleryLifecycleBeforeRefresh,
        }),
        /** Notify dependents and rebind public controls after the created gallery's context is replaced. @return {void} Dispatches the replacement event and restores public gallery bindings. */
        afterPublicReplace: () => {
            document.dispatchEvent(new CustomEvent('php-gallery:public-content-replaced'));
            rebindPublicGalleryLifecycleAfterRefresh();
        },
        /** Leave a bounded synchronization notice only on the originating create workflow. @return {void} Does not change another open panel or undo creation. */
        reportSynchronizationError: () => {
            const panel = document.querySelector('[data-admin-side-panel]');
            if (panel instanceof HTMLElement && owner.isCurrent()) {
                writeAdminGallerySidePanelStatus(
                    panel,
                    i18n('admin.side_panel.sync_failed_after_success', 'The gallery was saved, but the refreshed public view could not be verified. The server change was kept; continue working or reopen the page later.'),
                    true
                );
            }
        },
    });

    if (syncResult.synchronized && galleryId !== '') {
        focusCreatedGalleryCard(galleryId);
    }
    showAdminGallerySidePanelResultNotice(String(result.message || galleryTitle), galleryUrl);
}


/**
 * Releases browser-side public gallery bindings before server-rendered content is replaced.
 * @return {void} Releases public viewer, picture, sizing and scroll bindings before owned fragment replacement.
 */
function teardownPublicGalleryLifecycleBeforeRefresh() {
    teardownPictureManager();
    teardownResponsiveThumbnailSizes();
    teardownBackToTopButton();
    teardownGalleryLightbox();
}

/**
 * Recreates browser-side public gallery bindings after server-rendered content is replaced.
 * @return {void} Binds the replaced public markup, including gallery ordering, after coordinator-owned replacement.
 */
function rebindPublicGalleryLifecycleAfterRefresh() {
    setupPictureManager();
    setupResponsiveThumbnailSizes();
    setupBackToTopButton();
    setupGalleryLightbox();
    setupPublicGalleryPageReordering();
}

/**
 * Scroll the newly created gallery card into view after fragment replacement.
 *
 * @param {string} galleryId Newly created gallery id.
 * @return {void} Scrolls an existing matching card into view without moving keyboard focus.
 */
function focusCreatedGalleryCard(galleryId) {
    if (!galleryId) {
        return;
    }
    const escapedId = CSS.escape(galleryId);
    const card = document.querySelector(`[data-public-subgallery-grid] [data-gallery-id="${escapedId}"], .public-home-gallery-grid [data-gallery-id="${escapedId}"]`);
    if (card instanceof HTMLElement) {
        card.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    }
}

/**
 * Show a compact success notice when a card cannot be inserted safely.
 *
 * @param {string} message Message value.
 * @param {string} targetUrl Target url URL.
 * @return {void} Prepends escaped message/link markup to the public main region when present.
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
 * Format a nonnegative byte count for upload progress using binary size thresholds.
 * @param {number|string|null|undefined} value Source-byte count; empty values default to zero.
 * @return {string} Rounded B/KB/MB/GB/TB label for presentation only.
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
 * @return {AdminPanelClassicProgress} New zeroed counters and selected source totals.
 */
function createClassicUploadProgressState(files) {
    return {
        totalFiles: files.length,
        totalBytes: files.reduce(/** Sum source sizes independently of multipart framing overhead. @param {number} sum Source-byte total accumulated so far. @param {File} file Selected source. @return {number} Total including this source's byte size. */ (sum, file) => sum + Number(file.size || 0), 0),
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
 * @param {AdminPanelClassicProgress} state Acknowledged counts plus estimated current source-byte progress.
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
 * @param {AdminPanelUploadEvent[]|null|undefined} events Server progress records; nonarrays are ignored.
 * @return {void} Appends nonempty server progress records and optional elapsed milliseconds.
 */
function appendServerUploadEvents(progress, events) {
    if (!Array.isArray(events)) {
        return;
    }
    events.forEach(/** Append one nonempty server progress message and its optional elapsed time. @param {AdminPanelUploadEvent} event Server progress record. @return {void} Adds presentation text to the original upload log. */ (event) => {
        const message = String(event.message || '').trim();
        if (message === '') {
            return;
        }
        const elapsed = Number(event.elapsed_ms || 0);
        appendUploadProgressLog(progress, elapsed > 0 ? `Server: ${message} (${elapsed} ms)` : `Server: ${message}`);
    });
}

/**
 * Snapshot selected File references in deterministic folder/name order.
 *
 * The original browser File objects are retained, not copied into text drafts or
 * persisted in browser storage. Missing/empty file controls yield an empty array.
 * @param {HTMLFormElement} form Original upload form containing images[].
 * @return {File[]} Sorted source references used by classic chunk indices and intent comparison.
 */
function selectedGalleryUploadFiles(form) {
    // fileInput stores state or configuration for the gallery front-end flow.
    const fileInput = form.querySelector('input[type="file"][name="images[]"]');
    if (!(fileInput instanceof HTMLInputElement) || !fileInput.files || fileInput.files.length === 0) {
        return [];
    }
    return Array.from(fileInput.files)
        .filter(/** Retain only actual browser File references for upload ordering. @param {File} file FileList entry. @return {boolean} Whether the source has the required File identity. */ (file) => file instanceof File)
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
 * Build a fresh multipart body from enabled scalar controls on the original form.
 *
 * Reads current transport fields, including CSRF, on each explicit request. Files
 * and unchecked checkbox/radio controls are excluded; this is request serialization,
 * not the allowlisted in-memory text-draft projection. The caller assigns the stable
 * operation key for its particular chunk after this base body is constructed.
 * @param {HTMLFormElement} form Original form whose enabled controls supply POST fields.
 * @return {FormData} New multipart body with ajax=1 and no source files.
 */
function galleryUploadBaseBody(form) {
    // body stores state or configuration for the gallery front-end flow.
    const body = new FormData();
    Array.from(form.elements).forEach(/** Serialize enabled named scalar controls, excluding files and unchecked choices. @param {Element} field Original form control candidate; transport fields are read afresh. @return {void} Appends eligible values to this request body, not to draft storage. */ (field) => {
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
 * Build one classic upload body and select its acknowledged target gallery.
 * @param {HTMLFormElement} form Original form supplying current scalar transport fields.
 * @param {File[]} files Original source references to attach to this request.
 * @param {number} galleryId Positive acknowledged gallery ID, or zero before creation.
 * @return {FormData} New body with images[] and optional existing-gallery targeting; caller owns its operation key.
 */
function cloneGalleryUploadBody(form, files, galleryId) {
    // body stores state or configuration for the gallery front-end flow.
    const body = galleryUploadBaseBody(form);
    if (galleryId > 0) {
        body.set('upload_mode', 'existing');
        body.set('gallery_id', String(galleryId));
    }
    files.forEach(/** Attach an original source under the endpoint's images[] multipart field. @param {File} file Source reference assigned to this chunk. @return {void} Appends the File with its original filename. */ (file) => {
        body.append('images[]', file, file.name);
    });
    return body;
}

/**
 * Upload or explicitly replay the selected classic chunks under stable per-request keys.
 *
 * @param {HTMLFormElement} form Original create/upload form with current transport controls.
 * @param {HTMLElement} progress Existing upload progress container.
 * @param {boolean} createThumbnails Whether the existing thumbnail job follows each acknowledged chunk.
 * @param {import('./admin-operation-keys.js').AdminOperationIntent} operation Original create/upload intent with stable per-request keys.
 * @return {Promise<Record<string, unknown>>} Canonical server envelopes aggregated with unique image/gallery IDs and upload counters; throws on an uncertain or refused chunk.
 */
async function runGalleryUploadFiles(form, progress, createThumbnails, operation) {
    // files stores state or configuration for the gallery front-end flow.
    const files = selectedGalleryUploadFiles(form);
    const allowEmptyPanelGallery = form.dataset.galleryPanelCloseOnSuccess === '1' && String(form.querySelector('input[name="upload_mode"]')?.value || '') === 'new';
    if (files.length === 0 && !allowEmptyPanelGallery) {
        throw new Error(i18n('admin.side_panel.choose_image_upload', 'Choose at least one image to upload.'));
    }

    if (files.length === 0 && allowEmptyPanelGallery) {
        updateBasicProgress(progress, 20, i18n('admin.side_panel.creating_gallery', 'Creating gallery...'));
        const emptyResult = requireCanonicalUploadMutationResult(await sendGalleryUploadChunk(form, adminOperationBody(operation, 0, galleryUploadBaseBody(form)), /** Empty-gallery creation has no source-byte progress to display. @return {void} Intentionally leaves the existing creation status unchanged. */ () => {}));
        const emptyGalleryId = Number(emptyResult.gallery_id || 0);
        updateBasicProgress(progress, 100, i18n('admin.side_panel.gallery_created', 'Gallery created.'));
        return {
            ...emptyResult,
            ok: true,
            gallery_id: emptyGalleryId,
            gallery_ids: emptyGalleryId ? [emptyGalleryId] : [],
            mutation: {
                ...emptyResult.mutation,
                entity_ids: emptyGalleryId ? [emptyGalleryId] : [],
            },
            panel: emptyResult.panel && typeof emptyResult.panel === 'object' ? {...emptyResult.panel} : null,
            contexts: emptyResult.contexts.map(/** Shallow-copy a server-authored context without rebuilding its postcondition. @param {AdminPanelMutationContext} context Canonical context from this acknowledged chunk. @return {AdminPanelMutationContext} Independent outer record with unchanged nested completion metadata. */ (context) => ({...context})),
            fallback: emptyResult.fallback && typeof emptyResult.fallback === 'object' ? {...emptyResult.fallback} : {},
            image_ids: [],
            uploaded: 0,
            scanned: 0,
            thumbnails: 0,
            thumbnail_skipped: 0,
            thumbnail_failed: 0,
            thumbnail_errors: [],
            total_files: 0,
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
    let galleryId = Number(form.querySelector('select[name="gallery_id"]:enabled, input[type="hidden"][name="gallery_id"]:enabled')?.value || 0);
    // redirectUrl stores state or configuration for the gallery front-end flow.
    let redirectUrl = '';
    // galleryIds stores state or configuration for the gallery front-end flow.
    const galleryIds = [];
    // imageIds stores all persisted image ids across classic one-file upload requests.
    const imageIds = [];
    // galleryTitle stores the created or selected gallery title reported by the first upload response.
    let galleryTitle = '';
    // galleryUrl stores the public gallery URL reported by the first upload response.
    let galleryUrl = '';
    // editUrl stores the admin edit URL reported by the first upload response.
    let editUrl = '';
    // refreshUrl stores the page that should redraw the visible context behind the panel.
    let refreshUrl = '';
    // refreshGalleryId stores the canonical public context owner reported by the server.
    let refreshGalleryId = 0;
    // parentGalleryUrl stores the selected parent public URL for newly-created galleries.
    let parentGalleryUrl = '';
    // parentGalleryId stores the selected parent identifier for newly-created galleries.
    let parentGalleryId = 0;
    // createdGallery stores whether this upload workflow created the target gallery before storing files.
    let createdGallery = false;
    // mutation/panel/contexts/fallback preserve the canonical completion envelope through batching.
    let mutation = null;
    let panel = null;
    let contexts = [];
    let fallback = {};

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
        const uploadResult = requireCanonicalUploadMutationResult(await sendGalleryUploadChunk(form, adminOperationBody(operation, fileIndex, cloneGalleryUploadBody(form, [file], galleryId)), /** Project current multipart transfer progress onto the selected source's byte size. @param {ProgressEvent<XMLHttpRequestEventTarget>} event Current chunk upload progress; indeterminate events preserve completed-file progress. @return {void} Updates only the original progress state, labels and metrics. */ (event) => {
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
        }));
        appendServerUploadEvents(progress, uploadResult.upload_events || []);
        progressState.uploadedFiles = humanIndex;
        progressState.uploadedBytes = uploadedBeforeFile + Number(file.size || 0);
        progressState.currentFileUploadedBytes = Number(file.size || 0);
        updateUploadProgressMetrics(progress, classicUploadProgressMetrics(progressState));
        appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_uploaded_file', 'Finished picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));

        const resultCreatedGallery = Boolean(uploadResult.created_gallery);
        if (!galleryId) {
            galleryId = Number(uploadResult.gallery_id || 0);
        }
        if (galleryId && !galleryIds.includes(galleryId)) {
            galleryIds.push(galleryId);
        }
        (Array.isArray(uploadResult.image_ids) ? uploadResult.image_ids : []).forEach(/** Accumulate positive unique image identifiers from an acknowledged upload. @param {number|string} imageId Server-reported image identifier. @return {void} Appends the normalized ID once to the aggregate mutation identities. */ (imageId) => {
            const normalizedId = Number(imageId || 0);
            if (normalizedId > 0 && !imageIds.includes(normalizedId)) {
                imageIds.push(normalizedId);
            }
        });
        uploaded += Number(uploadResult.uploaded || 0);
        scanned += Number(uploadResult.scanned || 0);
        redirectUrl = String(uploadResult.redirect_url || redirectUrl || '');
        galleryTitle = galleryTitle || String(uploadResult.gallery_title || '');
        galleryUrl = galleryUrl || String(uploadResult.gallery_url || '');
        editUrl = String(uploadResult.edit_url || editUrl || '');
        refreshGalleryId = Number(uploadResult.refresh_gallery_id || refreshGalleryId || 0);
        refreshUrl = String(uploadResult.refresh_url || refreshUrl || '');
        parentGalleryUrl = parentGalleryUrl || String(uploadResult.parent_gallery_url || '');
        parentGalleryId = Number(uploadResult.parent_gallery_id || parentGalleryId || 0);

        // For an existing-gallery upload the latest response owns the final postcondition.
        // For create-with-upload, preserve the first response because it describes the
        // parent membership mutation rather than a later per-file upload mutation.
        if (mutation === null || (!createdGallery && !resultCreatedGallery)) {
            mutation = {...uploadResult.mutation};
            panel = uploadResult.panel && typeof uploadResult.panel === 'object' ? {...uploadResult.panel} : null;
            contexts = uploadResult.contexts.map(/** Shallow-copy a server-authored context without rebuilding its postcondition. @param {AdminPanelMutationContext} context Canonical context from this acknowledged chunk. @return {AdminPanelMutationContext} Independent outer record with unchanged nested completion metadata. */ (context) => ({...context}));
            fallback = uploadResult.fallback && typeof uploadResult.fallback === 'object' ? {...uploadResult.fallback} : {};
        }
        createdGallery = createdGallery || resultCreatedGallery;

        if (createThumbnails) {
            appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_thumbnails_started', 'Creating server thumbnails for picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));
            // thumbResult stores state or configuration for the gallery front-end flow.
            const thumbResult = await runUploadedImageThumbnailJob(form, progress, uploadResult.image_ids || [], humanIndex, files.length, file.name, thumbnails, thumbnailSkipped);
            appendUploadProgressLog(progress, i18n('admin.side_panel.upload_log_thumbnails_finished', 'Thumbnail job finished for picture {current}/{total}: {name}.', {current: humanIndex, total: files.length, name: file.name}));
            thumbnails += Number(thumbResult.created || 0);
            thumbnailSkipped += Number(thumbResult.skipped || 0);
            thumbnailFailed += Number(thumbResult.failed || 0);
            if (Array.isArray(thumbResult.errors)) {
                thumbResult.errors.forEach(/** Collect derivative diagnostics across acknowledged upload chunks. @param {string} message Server thumbnail diagnostic. @return {number} New aggregate error count from push; the iterator ignores it. */ (message) => thumbnailErrors.push(String(message)));
            }
        }
    }

    if (!mutation) {
        throw new Error(i18n('admin.side_panel.mutation_contract_missing', 'The upload completed, but the server did not return the required mutation completion contract.'));
    }

    const finalRedirectUrl = appendUploadResultParams(redirectUrl, uploaded, scanned, thumbnails, thumbnailFailed);
    const finalMutation = createdGallery && galleryId > 0
        ? {...mutation, type: 'gallery.create_with_upload', entity: 'gallery', action: 'create', entity_ids: [galleryId]}
        : {...mutation, entity_ids: imageIds.slice()};

    return {
        ok: true,
        mutation: finalMutation,
        panel,
        contexts,
        fallback: {...fallback, redirect_url: finalRedirectUrl},
        gallery_id: galleryId,
        gallery_ids: galleryIds.length > 0 ? galleryIds : (galleryId ? [galleryId] : []),
        image_ids: imageIds,
        gallery_title: galleryTitle,
        gallery_url: galleryUrl,
        edit_url: editUrl,
        parent_gallery_id: parentGalleryId,
        parent_gallery_url: parentGalleryUrl,
        refresh_gallery_id: refreshGalleryId,
        refresh_url: refreshUrl,
        created_gallery: createdGallery,
        uploaded,
        scanned,
        thumbnails,
        thumbnail_skipped: thumbnailSkipped,
        thumbnail_failed: thumbnailFailed,
        thumbnail_errors: Array.from(new Set(thumbnailErrors.filter(Boolean))),
        total_files: files.length,
        redirect_url: finalRedirectUrl,
    };
}

/**
 * Require the canonical successful mutation envelope from one classic upload request.
 *
 * Stage 5 deliberately rejects workflow-specific legacy reconstruction here. Every
 * persistent AJAX upload must carry its server-authored mutation, context, and
 * postcondition metadata through the client batching layer unchanged.
 *
 * @param {AdminPanelResponse} result Server JSON response.
 * @return {AdminPanelResponse} The original response unchanged; rejects if ok/mutation/contexts are absent.
 */
function requireCanonicalUploadMutationResult(result) {
    if (!result || result.ok !== true || !result.mutation || typeof result.mutation !== 'object' || !Array.isArray(result.contexts)) {
        throw new Error(i18n('admin.side_panel.mutation_contract_missing', 'The upload completed, but the server did not return the required mutation completion contract.'));
    }
    return result;
}

/**
 * Add aggregate upload counters to the direct-page fallback destination.
 *
 * Constructs a URL only; it does not navigate or select a panel refresh context.
 * @param {string} urlValue Server fallback destination, or empty for the current page.
 * @param {number} uploaded Acknowledged source count.
 * @param {number} scanned Registered source count.
 * @param {number} thumbnails Created derivative count.
 * @param {number} thumbnailFailed Failed derivative count, omitted from the URL when zero.
 * @return {string} Absolute fallback URL with aggregate counters.
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
 * Read one response body and parse the endpoint's JSON transport value.
 *
 * Does not validate HTTP success or the canonical envelope; callers own those checks.
 * Non-JSON upload-limit and HTML failures receive targeted diagnostics; other parse
 * failures use the existing bounded response prefix or the caller fallback message.
 * @param {Response} response Fetch response or XHR adapter whose body is consumed once.
 * @param {string} fallbackMessage Workflow-specific failure text for an unusable body.
 * @return {Promise<AdminPanelResponse>} Expected endpoint object (empty body becomes {}); parsing errors reject.
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
 * Send one classic multipart request and expose its upload progress to the caller.
 *
 * This transport performs no retry, key rotation, or public/panel refresh. A network
 * failure rejects with an uncertain outcome; the original operation owner retains
 * the same request key for the next explicit retry. Resolving here is not canonical
 * acknowledgement: the caller must still require mutation/context metadata.
 * @param {HTMLFormElement} form Original form supplying the POST destination.
 * @param {FormData} body Request body already assigned its stable per-chunk operation key.
 * @param {function(ProgressEvent<XMLHttpRequestEventTarget>): void} progressHandler Callback updating only the original upload progress UI.
 * @return {Promise<AdminPanelResponse>} Parsed 2xx ok result, or rejection for transport/endpoint failure.
 */
function sendGalleryUploadChunk(form, body, progressHandler) {
    return new Promise(/** Start exactly one keyed multipart request; explicit retry remains the operation owner's responsibility. @param {function(AdminPanelResponse): void} resolve Accept a parsed successful endpoint response. @param {function(unknown): void} reject Report transport, parsing or endpoint failure without rotating its key. @return {void} Installs request listeners and sends the supplied body once. */ (resolve, reject) => {
        // xhr stores state or configuration for the gallery front-end flow.
        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', progressHandler);
        xhr.addEventListener('load', /** Adapt the completed XHR into the shared JSON reader and settle its request promise. @return {Promise<void>} Resolves or rejects the outer request; canonical validation remains with the upload caller. */ async () => {
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
        xhr.addEventListener('error', /** Reject an uncertain transport failure without starting another request or clearing intent state. @return {void} Leaves exact-retry recovery to the original operation owner. */ () => {
            reject(new Error(i18n('admin.side_panel.upload_failed', 'Upload failed.')));
        });
        xhr.send(body);
    });
}

/**
 * Advance the server thumbnail job for images from one acknowledged upload chunk.
 *
 * Posts sequential offsets with the current form CSRF, updates original progress,
 * and aggregates derivative diagnostics. This is derivative-job continuation, not
 * a second upload intent or canonical public-refresh retry pipeline. A refused job
 * returns failed counts; transport or JSON parsing failures propagate to the caller.
 * @param {HTMLFormElement} form Original upload form supplying endpoint and transport fields.
 * @param {HTMLElement} progress Original upload progress container.
 * @param {number[]} imageIds Persisted image identifiers from the acknowledged chunk.
 * @param {number} fileIndex One-based uploaded source-file position.
 * @param {number} totalFiles Selected source-file count.
 * @param {string} filename Current source filename used in progress text.
 * @param {number} createdBefore Derivative count accumulated before this source.
 * @param {number} skippedBefore Skipped count accumulated before this source.
 * @return {Promise<AdminPanelThumbnailResult>} This source's derivative counts and deduplicated errors.
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
        imageIds.forEach(/** Restrict this thumbnail continuation to images returned by the acknowledged upload. @param {number} imageId Persisted image identifier. @return {void} Appends the image ID to this derivative-job request. */ (imageId) => {
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
            result.errors.forEach(/** Accumulate this source's derivative diagnostics before final deduplication. @param {string} message Server thumbnail diagnostic. @return {number} New local error count from push; the iterator ignores it. */ (message) => errors.push(String(message)));
        }
        updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Uploaded ${fileIndex} of ${totalFiles}: ${filename}. Creating thumbnails ${Math.min(offset, total)} of ${total}...`);
        if (result.done) {
            updateThumbnailProgress(progress, fileIndex, totalFiles, createdBefore + created, skippedBefore + skipped, `Finished ${fileIndex} of ${totalFiles}: ${filename}`);
            return {created, skipped, failed, errors: Array.from(new Set(errors.filter(Boolean)))};
        }
    }
}
