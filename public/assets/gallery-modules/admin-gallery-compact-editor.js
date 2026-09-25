/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-compact-editor.js
 * Module Type: Browser Module
 * Purpose: Keep compact gallery-editor copy and language controls usable in injected panels.
 * Responsibilities: Update selected language flags and copy API values in dynamic editor fragments.
 * Author: Rudolf Klusal
 * License: MIT License
 */

/**
 * Bind controls once on the document so newly rendered drawer fragments work.
 *
 * @returns {void} Installs delegated copy and language events.
 */
export function setupAdminGalleryCompactEditor() {
    if (!document.body || document.body.dataset.adminGalleryCompactEditorBound === '1') return;
    document.body.dataset.adminGalleryCompactEditorBound = '1';

    document.addEventListener('change', /** Update the selected language flag in this fragment.
     * @param {Event} event Language selection change.
     * @return {void} Update the visible flag.
     */ (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement) || !select.matches('[data-content-language-select]')) return;
        const flag = select.closest('[data-content-localization]')?.querySelector('[data-content-language-flag]');
        if (!(flag instanceof HTMLImageElement)) return;
        const source = String(select.selectedOptions[0]?.dataset.flagSrc || '');
        flag.hidden = source === '';
        if (source !== '') flag.src = source;
    });

    document.addEventListener('click', /** Copy the field value and announce the result.
     * @param {MouseEvent} event Copy button activation.
     * @return {Promise<void>} Completion after the clipboard attempt.
     */ async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-admin-copy-button]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        const row = button.closest('[data-admin-copy-row]');
        const input = row?.querySelector('[data-admin-copy-input]');
        const status = row?.querySelector('[data-admin-copy-status]');
        if (!(input instanceof HTMLInputElement) || !(status instanceof HTMLElement)) return;
        event.preventDefault();
        status.textContent = '';
        const value = input.value;
        if (value === '') return;

        let copied = false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(value);
                copied = true;
            }
        } catch { /* Use the selectable field fallback below. */ }
        if (!copied) {
            input.focus({preventScroll: true});
            input.select();
            try { copied = document.execCommand('copy'); } catch { copied = false; }
        }

        status.textContent = copied
            ? String(button.dataset.copySuccess || 'Copied')
            : String(button.dataset.copyFailure || 'Select the value and copy it manually.');
        status.classList.toggle('visually-hidden', copied);
        button.classList.toggle('is-copied', copied);
        const icon = button.querySelector('[aria-hidden="true"]');
        if (icon) icon.textContent = copied ? '✓' : '⧉';
    });
}