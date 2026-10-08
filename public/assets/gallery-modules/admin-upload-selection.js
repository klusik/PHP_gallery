/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-upload-selection.js
 * Module Type: Browser Module
 * Purpose: Add clipboard images to the ordinary gallery upload file selection.
 * Responsibilities:
 *   - Resolve the visible upload form without intercepting text editors
 *   - Preserve selected files and browser-provided clipboard filenames
 *   - Notify existing upload consumers through native input/change events
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

const uploadFormSelector = '[data-gallery-upload-form]';
const uploadInputSelector = 'input[type="file"][name="images[]"]';
const clipboardExtensions = new Map([
    ['image/jpeg', 'jpg'], ['image/png', 'png'], ['image/gif', 'gif'], ['image/webp', 'webp'],
    ['image/heic', 'heic'], ['image/heif', 'heif'], ['image/x-adobe-dng', 'dng'],
]);
let bound = false;
const formFocusOrder = new WeakMap();
let focusSequence = 0;
let filenameSequence = 0;

/**
 * Install delegated paste and focus tracking once, including future panel fragments.
 * @return {void} Leaves submission, validation and mutation completion with their existing owners.
 */
export function setupGalleryUploadClipboard() {
    if (bound) return;
    bound = true;
    const rememberForm = event => {
        const form = event.target instanceof Element ? event.target.closest(uploadFormSelector) : null;
        if (form instanceof HTMLFormElement) formFocusOrder.set(form, ++focusSequence);
    };
    document.addEventListener('focusin', rememberForm);
    document.addEventListener('pointerdown', rememberForm);
    document.addEventListener('paste', event => {
        if (event.defaultPrevented || clipboardTextEditor(event.target)) return;
        const form = clipboardUploadForm(event.target);
        const input = form?.querySelector(uploadInputSelector);
        if (!(input instanceof HTMLInputElement) || input.matches(':disabled') || form.classList.contains('is-uploading')) return;
        const files = clipboardUploadFiles(event.clipboardData, input);
        if (files.length === 0) return;
        try {
            // FileList is the shared queue for native, classic AJAX and prepared ZIP uploads.
            // Browsers without a writable FileList keep the existing chooser/drop workflow.
            const transfer = new DataTransfer();
            for (const file of Array.from(input.files || [])) transfer.items.add(file);
            for (const file of files) transfer.items.add(file);
            input.files = transfer.files;
            event.preventDefault();
            formFocusOrder.set(form, ++focusSequence);
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            clipboardUploadStatus(form, 'added', files.length);
        } catch {
            clipboardUploadStatus(form, 'unavailable');
        }
    });
}

/**
 * Recognize editable fields whose paste action belongs to their normal editor.
 * @param {EventTarget|null} target Original paste target.
 * @return {boolean} Whether clipboard content must remain editor-owned.
 */
function clipboardTextEditor(target) {
    if (!(target instanceof Element)) return false;
    if (target.isContentEditable || target.closest('textarea, select, [role="textbox"]')) return true;
    return target instanceof HTMLInputElement && !['file', 'checkbox', 'radio', 'button', 'submit', 'reset'].includes(target.type);
}

/**
 * Resolve a visible upload target, isolating the open panel from its background page.
 * @param {EventTarget|null} target Original paste target or surrounding page.
 * @return {HTMLFormElement|null} Focused/recent form, or the first visible form in the active surface.
 */
function clipboardUploadForm(target) {
    const panel = document.querySelector('[data-admin-side-panel]:not([hidden])');
    const root = panel || document;
    const forms = Array.from(root.querySelectorAll(uploadFormSelector)).filter(form =>
        form instanceof HTMLFormElement && form.isConnected && !form.closest('[hidden], [inert]') && form.getClientRects().length > 0);
    const focused = target instanceof Element ? target.closest(uploadFormSelector) : null;
    if (forms.includes(focused)) return focused;
    // Weak focus ranks never retain a removed panel or its selected source files.
    return forms.reduce((recent, form) =>
        (formFocusOrder.get(form) || 0) > (formFocusOrder.get(recent) || 0) ? form : recent, forms[0]) || null;
}

/**
 * Extract supported clipboard image files without reading text, HTML, URLs or remote assets.
 * @param {DataTransfer|null} clipboard Paste-event data exposed by the browser.
 * @param {HTMLInputElement} input Ordinary upload control supplying format hints.
 * @return {File[]} Supported images with original names or distinct MIME-matched generated names.
 */
export function clipboardUploadFiles(clipboard, input) {
    if (!clipboard) return [];
    const items = Array.from(clipboard.items || []);
    // Some browsers expose only files; never read both lists and duplicate one image.
    const candidates = items.length > 0
        ? items.filter(item => item.kind === 'file' && clipboardExtensions.has(String(item.type).toLowerCase())).map(item => item.getAsFile())
        : Array.from(clipboard.files || []);
    const accepts = input.accept.toLowerCase().split(',').map(value => value.trim());
    const files = [];
    for (const file of candidates) {
        if (!(file instanceof File) || file.size === 0) continue;
        const type = file.type.toLowerCase();
        const extension = clipboardExtensions.get(type);
        if (!extension) continue;
        // HEIC/HEIF/DNG require the existing server capability's explicit extension hint.
        if (['heic', 'heif', 'dng'].includes(extension) && !accepts.includes(`.${extension}`)) continue;
        if (input.accept && !accepts.includes('image/*') && !accepts.includes(type)
            && !accepts.includes(`.${extension}`) && !(extension === 'jpg' && accepts.includes('.jpeg'))) continue;
        if (file.name.trim() && file.name !== 'blob') {
            files.push(file);
        } else {
            const stamp = new Date().toISOString().replace(/[-:.]/g, '');
            files.push(new File([file], `clipboard-${stamp}-${++filenameSequence}.${extension}`, {type, lastModified: file.lastModified}));
        }
    }
    return files;
}

/**
 * Announce clipboard selection using localized presentation metadata from the form.
 * @param {HTMLFormElement} form Target upload form.
 * @param {string} outcome Added images or unavailable FileList assignment.
 * @param {number} count Number of newly appended images.
 * @return {void} Updates the form's live status without navigation or network activity.
 */
function clipboardUploadStatus(form, outcome, count = 0) {
    const status = form.querySelector('[data-gallery-upload-selection-status]');
    if (!(status instanceof HTMLElement)) return;
    status.textContent = String(status.dataset[outcome] || '').replace('{count}', String(count));
}
