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


/**
 * Return a translated browser string with simple placeholder replacement.
 *
 * @param {string} key Translation key emitted by the server.
 * @param {string} fallback Safe English fallback.
 * @param {Object<string, string|number>} parameters Placeholder values.
 * @return {string} Browser-facing translated text.
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

/**
 * Handle setup admin bulk selection.
 *
 * Used by browser-side gallery behavior.
 *
 * @return {boolean} True when the condition matches.
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

/**
 * Handle setup gallery bulk delete confirmation.
 *
 * Used by browser-side gallery behavior.
 */
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
            window.alert(i18n('admin.bulk.select_gallery_delete', 'Select at least one gallery to delete.'));
            return;
        }
        // Variable `names` stores this steps working value.
        const names = selectedRows.map((row) => row.dataset.galleryTitle || row.querySelector('.tree-title a')?.textContent?.trim() || i18n('admin.bulk.gallery_fallback', 'Gallery {id}', {id: row.dataset.galleryId || ''}).trim());
        // Variable `message` stores this steps working value.
        const message = [
            i18n('admin.bulk.delete_galleries_title', 'Delete these gallery folders and all subgalleries?'),
            '',
            ...names.map((name) => `• ${name}`),
            '',
            i18n('admin.bulk.delete_galleries_detail', 'This removes the folders from disk and deletes their database records. This cannot be undone.')
        ].join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
}


/**
 * Handle setup image bulk move fields.
 *
 * Used by browser-side gallery behavior.
 *
 * @return {string} Text result for the caller.
 */
export function setupImageBulkMoveFields() {
    document.querySelectorAll('[data-admin-image-bulk-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        // Variable `panel` stores the guided physical-move panel.
        const panel = form.querySelector('[data-admin-image-move-panel]');
        // Variable `openButton` stores the toolbar action that reveals the move flow.
        const openButton = form.querySelector('[data-admin-image-move-open]');
        // Variable `cancelButtons` stores controls that hide the move flow without changing selection.
        const cancelButtons = Array.from(form.querySelectorAll('[data-admin-image-move-cancel]'));
        // Variable `actionSelect` stores the existing bulk action field submitted to the backend.
        const actionSelect = form.querySelector('[data-admin-image-bulk-action]');
        // Variable `existingFields` stores the destination selector for existing-gallery moves.
        const existingFields = form.querySelector('[data-admin-image-move-existing]');
        // Variable `newFields` stores the title and folder fields for new-gallery moves.
        const newFields = form.querySelector('[data-admin-image-move-new]');
        // Variable `destinationInput` stores the existing-gallery target ID from the shared searchable picker.
        const destinationInput = form.querySelector('input[name="destination_gallery_id"]');
        // Variable `newGalleryTitle` stores the required title for the new gallery path.
        const newGalleryTitle = form.querySelector('input[name="new_gallery_title"]');
        // Variable `summary` stores the live confirmation summary.
        const summary = form.querySelector('[data-admin-image-move-summary]');
        // Variable `submitButton` stores the final physical-move submit button.
        const submitButton = form.querySelector('[data-admin-image-move-submit]');
        // Variable `selectedCounts` stores the compact selected-photo counters.
        const selectedCounts = Array.from(form.querySelectorAll('[data-admin-image-selected-count]'));
        // Variable `stepItems` stores the visual progress markers for the guided move panel.
        const stepItems = Array.from(form.querySelectorAll('[data-admin-image-move-step]'));
        // Variable `choiceButtons` stores the two action choice cards.
        const choiceButtons = Array.from(form.querySelectorAll('[data-admin-image-move-choice]'));

        if (!(panel instanceof HTMLElement) || !(openButton instanceof HTMLElement) || !(actionSelect instanceof HTMLSelectElement)) {
            return;
        }

        // Variable `moveAction` stores the staged action selected inside the guided panel.
        let moveAction = actionSelect.value === 'move_existing' || actionSelect.value === 'move_new' ? actionSelect.value : '';

                /**
         * Return checked photo boxes from this form only.
         *
         * @return {HTMLInputElement[]} Selected image checkboxes.
         */
        function selectedImageCheckboxes() {
            return Array.from(form.querySelectorAll('input[type="checkbox"][name="image_ids[]"]:checked'))
                .filter((checkbox) => checkbox instanceof HTMLInputElement);
        }

                /**
         * Return readable names for selected photos.
         *
         * @return {string[]} Selected photo names.
         */
        function selectedImageNames() {
            return selectedImageCheckboxes()
                .map((checkbox) => checkbox.closest('[data-admin-image-order-row]'))
                .filter((row) => row instanceof HTMLElement)
                .map((row) => row.dataset.imageName || row.querySelector('[data-admin-image-name-cell]')?.textContent?.trim() || i18n('admin.bulk.image_fallback', 'Image {id}', {id: row.dataset.imageId || ''}).trim());
        }

                /**
         * Show or hide the staged target fields for the selected move action.
         */
        function updateTargetVisibility() {
            if (existingFields instanceof HTMLElement) {
                existingFields.hidden = moveAction !== 'move_existing';
                existingFields.querySelectorAll('select, input, textarea').forEach((field) => {
                    if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                        field.disabled = moveAction !== 'move_existing';
                    }
                });
            }
            if (newFields instanceof HTMLElement) {
                newFields.hidden = moveAction !== 'move_new';
                newFields.querySelectorAll('select, input, textarea').forEach((field) => {
                    if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                        field.disabled = moveAction !== 'move_new';
                    }
                });
            }
            choiceButtons.forEach((button) => {
                if (!(button instanceof HTMLElement)) {
                    return;
                }
                const selected = button.dataset.adminImageMoveChoice === moveAction;
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
        }

                /**
         * Return the current target label for the confirmation summary.
         *
         * @return {string} Human-readable target label.
         */
        function targetLabel() {
            if (moveAction === 'move_existing' && destinationInput instanceof HTMLInputElement) {
                const picker = destinationInput.closest('[data-gallery-search-picker]');
                const committedLabel = picker instanceof HTMLElement ? picker.dataset.gallerySearchCommittedLabel || '' : '';
                return destinationInput.value !== '' && destinationInput.value !== '0' ? committedLabel : '';
            }
            if (moveAction === 'move_new' && newGalleryTitle instanceof HTMLInputElement) {
                return newGalleryTitle.value.trim();
            }
            return '';
        }

                /**
         * Update selected count, summary text, and submit availability.
         */
        function updateMoveState() {
            const names = selectedImageNames();
            const count = names.length;
            const target = targetLabel();
            const hasAction = moveAction === 'move_existing' || moveAction === 'move_new';
            const hasTarget = target !== '';
            const isReady = count > 0 && hasAction && hasTarget;

            selectedCounts.forEach((selectedCount) => {
                if (selectedCount instanceof HTMLElement) {
                    selectedCount.textContent = count === 1 ? i18n('admin.bulk.photo_selected_one', '1 photo selected') : i18n('admin.bulk.photo_selected_many', '{count} photos selected', {count});
                }
            });

            if (summary instanceof HTMLElement) {
                if (count === 0) {
                    summary.textContent = i18n('admin.bulk.select_photos_first', 'Select one or more photos first.');
                } else if (!hasAction) {
                    summary.textContent = i18n('admin.bulk.choose_move_action_summary', '{count} selected. Choose one of the move actions above.', {count});
                } else if (!hasTarget) {
                    summary.textContent = moveAction === 'move_existing'
                        ? i18n('admin.bulk.choose_destination_summary', '{count} selected. Choose the destination gallery.', {count})
                        : i18n('admin.bulk.enter_new_gallery_summary', '{count} selected. Enter the new gallery title.', {count});
                } else {
                    const actionLabel = moveAction === 'move_existing' ? i18n('admin.bulk.existing_gallery', 'existing gallery') : i18n('admin.bulk.new_gallery', 'new gallery');
                    summary.textContent = i18n('admin.bulk.move_summary', '{count} selected. Move originals, thumbnails, and generated display files to the {target_type}: {target}.', {count, target_type: actionLabel, target});
                }
            }

            if (submitButton instanceof HTMLButtonElement) {
                submitButton.disabled = !isReady;
            }

            stepItems.forEach((step) => {
                if (!(step instanceof HTMLElement)) {
                    return;
                }
                const stepName = step.dataset.adminImageMoveStep || '';
                const isTargetReady = hasAction;
                const isConfirmReady = hasAction && hasTarget;
                const active = (stepName === 'action' && !hasAction)
                    || (stepName === 'target' && hasAction && !hasTarget)
                    || (stepName === 'confirm' && isConfirmReady)
                    || (stepName === 'complete' && false);
                const complete = (stepName === 'action' && hasAction)
                    || (stepName === 'target' && isTargetReady && hasTarget);
                step.classList.toggle('is-active', active);
                step.classList.toggle('is-complete', complete);
            });

            updateTargetVisibility();
        }

                /**
         * Set the active move action and mirror it into the submitted select field.
         *
         * @param {string} value Submitted backend action value.
         */
        function chooseMoveAction(value) {
            moveAction = value === 'move_existing' || value === 'move_new' ? value : '';
            if (moveAction) {
                actionSelect.value = moveAction;
            }
            updateMoveState();
        }

        openButton.addEventListener('click', () => {
            panel.hidden = false;
            updateMoveState();
            const firstChoice = panel.querySelector('[data-admin-image-move-choice]');
            if (firstChoice instanceof HTMLElement) {
                firstChoice.focus({preventScroll: true});
            }
        });

        cancelButtons.forEach((cancelButton) => {
            if (!(cancelButton instanceof HTMLElement)) {
                return;
            }
            cancelButton.addEventListener('click', () => {
                panel.hidden = true;
                if (actionSelect.value === 'move_existing' || actionSelect.value === 'move_new') {
                    actionSelect.value = 'public';
                }
                moveAction = '';
                updateMoveState();
            });
        });

        choiceButtons.forEach((button) => {
            if (!(button instanceof HTMLElement)) {
                return;
            }
            button.addEventListener('click', () => {
                chooseMoveAction(button.dataset.adminImageMoveChoice || '');
            });
        });

        form.addEventListener('change', (event) => {
            const target = event.target;
            if (target instanceof HTMLSelectElement && target === actionSelect) {
                if (target.value === 'move_existing' || target.value === 'move_new') {
                    panel.hidden = false;
                    chooseMoveAction(target.value);
                    return;
                }
                moveAction = '';
                panel.hidden = true;
            }
            updateMoveState();
        });

        form.addEventListener('input', updateMoveState);

        if (submitButton instanceof HTMLButtonElement) {
            submitButton.addEventListener('click', (event) => {
                const count = selectedImageCheckboxes().length;
                if (!moveAction) {
                    event.preventDefault();
                    window.alert(i18n('admin.bulk.choose_move_type', 'Choose whether to move to an existing gallery or a new gallery.'));
                    return;
                }
                if (count === 0) {
                    event.preventDefault();
                    window.alert(i18n('admin.bulk.select_photo_move', 'Select at least one photo to move.'));
                    return;
                }
                if (moveAction === 'move_existing' && (!(destinationInput instanceof HTMLInputElement) || (destinationInput.value === '' || destinationInput.value === '0'))) {
                    event.preventDefault();
                    window.alert(i18n('admin.bulk.choose_destination', 'Choose the destination gallery.'));
                    return;
                }
                if (moveAction === 'move_new' && (!(newGalleryTitle instanceof HTMLInputElement) || newGalleryTitle.value.trim() === '')) {
                    event.preventDefault();
                    window.alert(i18n('admin.bulk.enter_new_gallery', 'Enter the new gallery title.'));
                    return;
                }
                actionSelect.value = moveAction;
                form.dataset.adminImageMoveConfirmed = '1';
            });
        }

        updateMoveState();
    });
}

/**
 * Handle setup image bulk delete confirmation.
 *
 * Used by browser-side gallery behavior.
 */
export function setupImageBulkDeleteConfirmation() {
    // Confirm destructive photo deletes and guard physical photo moves from the dedicated admin edit-gallery image table.
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
        // Variable `isBulkMove` stores whether the staged toolbar selected a physical photo move operation.
        const isBulkMove = action instanceof HTMLSelectElement && (action.value === 'move_existing' || action.value === 'move_new');
        if (!isSingleDelete && !isBulkDelete && !isBulkMove) {
            return;
        }

        // Variable `names` stores the selected photo names shown in the confirmation prompt.
        let names = [];
        if (isSingleDelete && submitter instanceof HTMLElement) {
            names = [submitter.dataset.imageName || i18n('admin.bulk.selected_photo_fallback', 'Selected photo')];
        } else {
            names = Array.from(form.querySelectorAll('input[type="checkbox"][name="image_ids[]"]:checked'))
                .map((checkbox) => checkbox.closest('[data-admin-image-order-row]'))
                .filter((row) => row instanceof HTMLElement)
                .map((row) => row.dataset.imageName || row.querySelector('[data-admin-image-name-cell]')?.textContent?.trim() || i18n('admin.bulk.image_fallback', 'Image {id}', {id: row.dataset.imageId || ''}).trim());
        }

        if (!names.length) {
            event.preventDefault();
            window.alert(isBulkMove ? i18n('admin.bulk.select_photo_move', 'Select at least one photo to move.') : i18n('admin.bulk.select_photo_delete', 'Select at least one photo to delete.'));
            return;
        }

        if (isBulkMove) {
            const destinationInput = form.querySelector('input[name="destination_gallery_id"]');
            const newGalleryTitle = form.querySelector('input[name="new_gallery_title"]');
            if (action.value === 'move_existing' && (!(destinationInput instanceof HTMLInputElement) || (destinationInput.value === '' || destinationInput.value === '0'))) {
                event.preventDefault();
                window.alert(i18n('admin.bulk.choose_destination', 'Choose the destination gallery.'));
                return;
            }
            if (action.value === 'move_new' && (!(newGalleryTitle instanceof HTMLInputElement) || newGalleryTitle.value.trim() === '')) {
                event.preventDefault();
                window.alert(i18n('admin.bulk.enter_new_gallery', 'Enter the new gallery title.'));
                return;
            }
            if (form.dataset.adminImageMoveConfirmed === '1') {
                delete form.dataset.adminImageMoveConfirmed;
                return;
            }
            // Variable `moveMessage` stores fallback confirmation text when the staged button was not used.
            const moveMessage = [
                names.length === 1 ? i18n('admin.bulk.move_photo_one', 'Move this photo?') : i18n('admin.bulk.move_photo_many', 'Move these photos?'),
                '',
                ...names.map((name) => `• ${name}`),
                '',
                i18n('admin.bulk.move_photo_detail', 'This physically moves the original files, generated thumbnails, and display derivatives. The source gallery will no longer contain them.')
            ].join('\n');
            if (!window.confirm(moveMessage)) {
                event.preventDefault();
            }
            return;
        }

        // Variable `message` stores the destructive action confirmation text.
        const message = [
            names.length === 1 ? i18n('admin.bulk.delete_photo_one', 'Delete this photo from the gallery?') : i18n('admin.bulk.delete_photo_many', 'Delete these photos from the gallery?'),
            '',
            ...names.map((name) => `• ${name}`),
            '',
            i18n('admin.bulk.delete_photo_detail', 'This removes the original file from disk, deletes its database record, and cleans generated thumbnails. This cannot be undone.')
        ].join('\n');
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
}

/**
 * Handle setup thumbnail cache delete confirmation.
 *
 * Used by browser-side gallery behavior.
 */
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
            window.alert(i18n('admin.thumbnails.delete_not_configured', 'Thumbnail deletion is not configured correctly. No files were deleted.'));
            return;
        }
        // Variable `expectedWord` stores the randomly selected challenge word for this click.
        const expectedWord = words[Math.floor(Math.random() * words.length)];
        // Variable `typedWord` stores exactly what the admin entered in the browser prompt.
        const typedWord = window.prompt([
            i18n('admin.thumbnails.delete_prompt_intro', 'This will delete all generated thumbnail files for every gallery.'),
            i18n('admin.thumbnails.delete_prompt_originals', 'Original photos and gallery records will not be deleted.'),
            i18n('admin.thumbnails.delete_prompt_regenerate', 'The next public/admin view can regenerate thumbnails when needed.'),
            '',
            i18n('admin.thumbnails.delete_prompt_confirm', 'Type {word} to confirm.', {word: expectedWord})
        ].join('\n'));
        if ((typedWord || '').trim().toLowerCase() !== expectedWord) {
            event.preventDefault();
            window.alert(i18n('admin.thumbnails.delete_cancelled', 'Thumbnail deletion cancelled. No thumbnail files were deleted.'));
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

