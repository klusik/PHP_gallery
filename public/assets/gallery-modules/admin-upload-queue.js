/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-upload-queue.js
 * Module Type: Browser Module
 * Purpose: Keep a browser-local upload selection with stable identities and FileList ordering.
 * Responsibilities:
 *   - Append picker, paste and drop files without copying source bytes
 *   - Reconcile native input changes without losing existing client identities
 *   - Remove, reorder or clear items independently of upload transport
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * One deliberately selected File occurrence. Multiple entries may point to one File.
 * @typedef {Object} GalleryUploadQueueItem
 * @property {string} id Stable client-only selection identity.
 * @property {File} file Original source bytes, not a preview.
 * @property {string} source Native picker, clipboard, drop or external reconciliation.
 */

/**
 * Per-form operations; the FileList remains authoritative for actual submission.
 * @typedef {Object} GalleryUploadSelectionQueue
 * @property {function(): GalleryUploadQueueItem[]} items Snapshot ordered entries.
 * @property {function(Iterable<File>, string): GalleryUploadQueueItem[]} append Append raw File objects.
 * @property {function(Iterable<File>): GalleryUploadQueueItem[]} reconcile Match an external native FileList.
 * @property {function(string): boolean} remove Remove one identity.
 * @property {function(string, number): boolean} move Move one position.
 * @property {function(): void} clear Release queue references.
 */

/** Monotonic document-local identity; it is not an upload operation or server image ID. */
let nextClientId = 0;

/**
 * Own one ephemeral form selection. The calling view owns preview Blob URLs, input
 * assignment and submission. No File, image bytes or credential is persisted here.
 * @param {File[]} initialFiles Existing native selection, if any.
 * @return {GalleryUploadSelectionQueue} Queue operations and source File references.
 */
export function createGalleryUploadQueue(initialFiles = []) {
    /** @type {Array<{id: string, file: File, source: string}>} */
    let selection = [];

    /**
     * Give each selected occurrence an identity, including deliberate duplicate files.
     * @param {File} file Original upload source.
     * @param {string} source Selection route, not trusted server metadata.
     * @return {{id: string, file: File, source: string}} Item without byte copies.
     */
    const itemFor = (file, source) => ({id: `local-${++nextClientId}`, file, source});

    /** Return a shallow, ordered snapshot without exposing the mutable queue array.
     * @return {GalleryUploadQueueItem[]} Ordered selected occurrences.
     */
    const items = () => selection.slice();

    /**
     * Append one native list's File objects. Do not deduplicate by name, size or hash;
     * separately pasted copies may be intentional, while event duplication is handled
     * by the producer (the clipboard delegate reads items OR files).
     * @param {Iterable<File>} files New files.
     * @param {string} source 'paste', 'picker' or 'drop'.
     * @return {GalleryUploadQueueItem[]} Current ordered items.
     */
    function append(files, source = 'picker') {
        for (const file of files || []) {
            if (file instanceof File) selection.push(itemFor(file, source));
        }
        return items();
    }

    /**
     * Reconcile a native FileList while retaining occurrence identities.
     * Browsers may wrap the same File in a new object when assigning DataTransfer.files;
     * if reference matching fails, use its name, size, MIME and timestamp in occurrence
     * order. The incoming native File is always kept as the authoritative source.
     * @param {Iterable<File>} files Canonical current file input selection.
     * @return {GalleryUploadQueueItem[]} Current ordered items.
     */
    function reconcile(files) {
        const remaining = selection.slice();
        const next = [];
        for (const file of files || []) {
            if (!(file instanceof File)) continue;
            let match = remaining.findIndex(item => item.file === file);
            if (match < 0) {
                match = remaining.findIndex(item =>
                    item.file.name === file.name && item.file.size === file.size
                    && item.file.type === file.type && item.file.lastModified === file.lastModified);
            }
            if (match < 0) {
                next.push(itemFor(file, 'external'));
            } else {
                const previous = remaining.splice(match, 1)[0];
                next.push({...previous, file});
            }
        }
        selection = next;
        return items();
    }

    /**
     * Remove exactly one selected occurrence using its stable local identity.
     * @param {string} id One local item ID.
     * @return {boolean} Whether an item was removed.
     */
    function remove(id) {
        const index = selection.findIndex(item => item.id === id);
        if (index < 0) return false;
        selection.splice(index, 1);
        return true;
    }

    /**
     * Move one occurrence one place without changing the underlying File reference.
     * @param {string} id Local item ID.
     * @param {number} direction -1 for earlier, +1 for later.
     * @return {boolean} Whether the order changed.
     */
    function move(id, direction) {
        if (direction !== -1 && direction !== 1) return false;
        const index = selection.findIndex(item => item.id === id);
        const target = index + direction;
        if (index < 0 || target < 0 || target >= selection.length) return false;
        const [item] = selection.splice(index, 1);
        selection.splice(target, 0, item);
        return true;
    }

    /** Forget local source references; revocation is owned by the preview layer.
     * @return {void} Releases the selected source array.
     */
    function clear() { selection = []; }

    append(initialFiles, 'initial');
    return {items, append, reconcile, remove, move, clear};
}
