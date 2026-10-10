/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-visual-editor-support.js
 * Module Type: Browser Module
 * Purpose: Keep synchronous Theme draft operations and visual availability independent of optional workspace loading.
 * Responsibilities: Restore pending File drafts, announce background operations, and render bounded availability reasons.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Set the global Theme background operation draft from an Admin form lifecycle action.
 * @param {HTMLElement} root The Custom CSS editor root containing the operation field.
 * @param {'keep'|'replace'|'remove'} operation Background operation represented by the draft.
 * @returns {boolean} True when the operation was accepted and announced to the visual editor.
 */
export function setVisualEditorBackgroundOperation(root, operation) {
    if (!(root instanceof HTMLElement) || !['keep', 'replace', 'remove'].includes(operation)) return false;
    const input = root.querySelector('[data-visual-editor-background-operation]');
    if (!(input instanceof HTMLInputElement)) return false;
    input.value = operation;
    root.dataset.visualEditorBackgroundOperation = operation;
    root.dispatchEvent(new CustomEvent('theme-background-operation-change', {detail: {operation}}));
    return true;
}

/**
 * Restore a pending image File to the existing Admin form input without uploading it.
 * @param {HTMLInputElement|null} input Existing multipart background file control.
 * @param {File|null} file File snapshot to restore, or null to clear the pending attachment.
 * @returns {boolean} True when the browser accepted the requested file-list state.
 */
export function restoreVisualEditorBackgroundFile(input, file) {
    if (!(input instanceof HTMLInputElement)) return file === null;
    if (file === null) {
        input.value = '';
        return true;
    }
    try {
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        return input.files.length === 1 && input.files[0] === file;
    } catch {
        return false;
    }
}

/**
 * Present a bounded visual-editor availability reason without exposing URL or exception data.
 * @param {HTMLElement} root Custom CSS editor containing the launcher and translated availability labels.
 * @param {'preview_not_initialized'|'preview_url_invalid'|'preview_origin_mismatch'|'preview_marker_missing'|'preview_initialization_failed'|'preview_module_unavailable'|''} reason Empty for ready, otherwise the observed unavailable category.
 * @returns {void} Keeps the launcher visible, enables it only when ready, and updates its accessible explanation.
 */
export function setThemeVisualEditorAvailability(root, reason) {
    const launch = root.querySelector('[data-visual-editor-launch]');
    const status = root.querySelector('[data-visual-editor-availability]');
    const labels = root.querySelector('[data-visual-editor-availability-labels]');
    if (launch instanceof HTMLButtonElement) {
        launch.hidden = false;
        launch.disabled = reason !== '';
    }
    if (status instanceof HTMLElement) {
        status.dataset.visualEditorReason = reason;
        status.textContent = reason === '' ? '' : labels?.getAttribute('data-' + reason.replaceAll('_', '-'))
            || labels?.getAttribute('data-preview-not-initialized') || status.textContent;
        status.hidden = reason === '';
    }
}

/**
 * Describe one pending global Theme image operation using the existing translated review labels.
 * @param {HTMLElement} root Custom CSS editor containing the background review labels.
 * @param {'keep'|'replace'|'remove'} operation Pending background operation.
 * @param {File|null} file Pending replacement File, or null when none is selected.
 * @returns {string} Empty for Keep, otherwise the operation, target, optional filename and explicit-save reminder.
 */
export function visualEditorBackgroundDraftMessage(root, operation, file) {
    const labels = root.querySelector('[data-visual-editor-background-labels]');
    const label = key => root.dataset[key] || labels?.dataset[key] || '';
    const operationLabel = label(`visualEditorBackgroundOperation${operation[0].toUpperCase()}${operation.slice(1)}Label`);
    const parts = [operationLabel, label('visualEditorBackgroundTargetGlobalLabel')];
    if (operation === 'replace' && file) parts.push(file.name);
    if (operation !== 'keep') parts.push(label('visualEditorBackgroundReviewHintLabel'));
    return operation === 'keep' ? '' : parts.filter(Boolean).join(' · ');
}
