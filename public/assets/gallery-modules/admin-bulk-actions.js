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
    document.querySelectorAll('[data-select-all]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            // Variable `name` stores this steps working value.
            const name = checkbox.dataset.selectAll;
            // Variable `scope` stores this steps working value.
            const scope = checkbox.closest('form') || document;
            scope.querySelectorAll(`input[type="checkbox"][name="${name}"]`).forEach((item) => {
                // Variable `row` stores this steps working value.
                const row = item.closest('tr');
                if (row && row.hidden) {
                    return;
                }
                item.checked = checkbox.checked;
            });
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
