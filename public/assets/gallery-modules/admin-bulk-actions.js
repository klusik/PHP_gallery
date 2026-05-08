/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-bulk-actions.js
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
 * Admin table and destructive bulk-action helpers.
 *
 * These helpers are intentionally small because they apply to multiple admin
 * tables and should remain independent from the gallery tree, upload, and
 * thumbnail job modules.
 *
 * Example usage from the gallery entrypoint:
 *
 * import { setupAdminBulkSelection, setupGalleryBulkDeleteConfirmation } from './gallery-modules/admin-bulk-actions.js';
 * setupAdminBulkSelection();
 * setupGalleryBulkDeleteConfirmation();
 */

export function setupAdminBulkSelection() {
    // Table-level select-all checkboxes are scoped by input name and form. When
    // a table is filtered, hidden rows are left untouched so bulk operations
    // only apply to what the admin can currently see.
    document.addEventListener('change', (event) => {
        // Variable `checkbox` stores this steps working value.
        const checkbox = event.target;
        if (!(checkbox instanceof HTMLInputElement) || !checkbox.matches('[data-select-all]')) {
            return;
        }
        // Variable `name` stores this steps working value.
        const name = checkbox.getAttribute('data-select-all') || '';
        if (!name) {
            return;
        }
        // Variable `scope` stores this steps working value.
        const scope = checkbox.closest('form') || document;
        // Variable `targets` stores checkboxes with the exact submitted field name.
        const targets = Array.from(scope.getElementsByTagName('input')).filter((item) => {
            if (!(item instanceof HTMLInputElement) || item.type !== 'checkbox' || item === checkbox) {
                return false;
            }
            return item.getAttribute('name') === name;
        });
        targets.forEach((item) => {
            // Variable `row` stores this steps working value.
            const row = item.closest('tr');
            if (row && row.hidden) {
                return;
            }
            item.checked = checkbox.checked;
            item.dispatchEvent(new Event('change', {bubbles: true}));
        });
    });
}

export function setupGalleryBulkDeleteConfirmation() {
    // Confirm destructive gallery bulk deletes with the exact selected names.
    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-bulk-form]')) {
            return;
        }
        // Variable `action` stores this steps working value.
        const action = form.querySelector('select[name="action"]');
        if (!(action instanceof HTMLSelectElement) || action.value !== 'delete') {
            return;
        }
        // Variable `selectedRows` stores this steps working value.
        const selectedRows = Array.from(form.querySelectorAll('input[type="checkbox"][name="gallery_ids[]"]:checked'))
            .map((checkbox) => checkbox.closest('[data-gallery-row]'))
            .filter((row) => row instanceof HTMLElement);
        if (!selectedRows.length) {
            event.preventDefault();
            window.alert('Select at least one gallery to delete.');
            return;
        }
        // Variable `names` stores this steps working value.
        const names = selectedRows.map((row) => row.dataset.galleryTitle || row.querySelector('.tree-title a')?.textContent?.trim() || `Gallery ${row.dataset.galleryId || ''}`.trim());
        // Variable `message` stores this steps working value.
        const message = [
            'Delete these gallery folders and all subgalleries?',
            '',
            ...names.map((name) => `• ${name}`),
            '',
            'This removes the folders from disk and deletes their database records. This cannot be undone.'
        ].join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
}


export function setupImageBulkDeleteConfirmation() {
    // Confirm destructive photo deletes from the dedicated admin edit-gallery image table.
    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-admin-image-bulk-form]')) {
            return;
        }
        // Variable `submitter` stores this steps working value.
        const submitter = event.submitter;
        // Variable `action` stores this steps working value.
        const action = form.querySelector('select[name="action"]');
        // Variable `isSingleDelete` stores whether a row-level delete button submitted the form.
        const isSingleDelete = submitter instanceof HTMLElement && submitter.matches('[data-admin-image-delete-single]');
        // Variable `isBulkDelete` stores whether the toolbar selected the delete operation.
        const isBulkDelete = action instanceof HTMLSelectElement && action.value === 'delete';
        if (!isSingleDelete && !isBulkDelete) {
            return;
        }

        // Variable `names` stores the selected photo names shown in the confirmation prompt.
        let names = [];
        if (isSingleDelete && submitter instanceof HTMLElement) {
            names = [submitter.dataset.imageName || 'Selected photo'];
        } else {
            names = Array.from(form.querySelectorAll('input[type="checkbox"][name="image_ids[]"]:checked'))
                .map((checkbox) => checkbox.closest('[data-admin-image-order-row]'))
                .filter((row) => row instanceof HTMLElement)
                .map((row) => row.dataset.imageName || row.querySelector('[data-admin-image-name-cell]')?.textContent?.trim() || `Image ${row.dataset.imageId || ''}`.trim());
        }

        if (!names.length) {
            event.preventDefault();
            window.alert('Select at least one photo to delete.');
            return;
        }

        // Variable `message` stores the destructive action confirmation text.
        const message = [
            names.length === 1 ? 'Delete this photo from the gallery?' : 'Delete these photos from the gallery?',
            '',
            ...names.map((name) => `• ${name}`),
            '',
            'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.'
        ].join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
}

export function setupThumbnailCacheDeleteConfirmation() {
    // Confirm all-thumbnail deletion with a randomly selected simple word. The
    // server still verifies the posted word so a missed browser prompt cannot
    // silently delete the thumbnail cache.
    document.addEventListener('submit', (event) => {
        // Variable `form` stores this steps working value.
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-delete-all-thumbnails-form]')) {
            return;
        }
        // Variable `submitter` stores this steps working value.
        const submitter = event.submitter;
        if (!(submitter instanceof HTMLElement) || !submitter.matches('[data-delete-all-thumbnails]')) {
            return;
        }
        // Variable `words` stores the small confirmation vocabulary configured on the button.
        const words = (submitter.dataset.confirmWords || '')
            .split(',')
            .map((word) => word.trim().toLowerCase())
            .filter(Boolean);
        if (!words.length) {
            event.preventDefault();
            window.alert('Thumbnail deletion is not configured correctly. No files were deleted.');
            return;
        }
        // Variable `expectedWord` stores the randomly selected challenge word for this click.
        const expectedWord = words[Math.floor(Math.random() * words.length)];
        // Variable `typedWord` stores exactly what the admin entered in the browser prompt.
        const typedWord = window.prompt([
            'This will delete all generated thumbnail files for every gallery.',
            'Original photos and gallery records will not be deleted.',
            'The next public/admin view can regenerate thumbnails when needed.',
            '',
            `Type ${expectedWord} to confirm.`
        ].join('\n'));
        if ((typedWord || '').trim().toLowerCase() !== expectedWord) {
            event.preventDefault();
            window.alert('Thumbnail deletion cancelled. No thumbnail files were deleted.');
            return;
        }
        // Variable `expectedInput` stores the hidden value used by the server-side safety check.
        const expectedInput = form.querySelector('input[name="confirmation_expected"]');
        // Variable `typedInput` stores the hidden value used by the server-side safety check.
        const typedInput = form.querySelector('input[name="confirmation_typed"]');
        if (expectedInput instanceof HTMLInputElement) {
            expectedInput.value = expectedWord;
        }
        if (typedInput instanceof HTMLInputElement) {
            typedInput.value = typedWord.trim().toLowerCase();
        }
    });
}

