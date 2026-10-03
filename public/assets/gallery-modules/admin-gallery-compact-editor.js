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

    /** Synchronize the visible picker summary and option state from its canonical select.
     * @param {Element} picker Language picker disclosure.
     * @return {void} Update the summary and pressed option.
     */
    const syncLanguagePicker = (picker) => {
        const localization = picker.closest('[data-content-localization]');
        const select = localization?.querySelector('[data-content-language-select]');
        if (!(select instanceof HTMLSelectElement)) return;
        localization.classList.add('is-enhanced');
        const option = select.selectedOptions[0];
        const value = String(select.value);
        const source = String(option?.dataset.flagSrc || '');
        const flag = picker.querySelector('[data-content-language-flag]');
        const empty = picker.querySelector('[data-content-language-empty]');
        const label = picker.querySelector('[data-content-language-label]');

        if (flag instanceof HTMLImageElement) {
            flag.hidden = source === '';
            if (source !== '') flag.src = source;
        }
        if (empty instanceof HTMLElement) empty.hidden = source !== '';
        if (label instanceof HTMLElement) label.textContent = String(option?.textContent || '').trim();
        picker.querySelectorAll('button[data-content-language-option]').forEach(/** Mark each option against the canonical select.
         * @param {HTMLButtonElement} button Language choice button.
         * @return {void} Updates its pressed state.
         */ (button) => {
            if (button instanceof HTMLButtonElement) {
                button.setAttribute('aria-pressed', String(String(button.dataset.contentLanguageValue ?? '') === value));
            }
        });
    };

    /**
     * Synchronize picker disclosures in a document or inserted fragment.
     * @param {ParentNode} root DOM root that may contain language pickers.
     * @return {void} Refreshes matching picker summaries.
     */
    const syncLanguagePickers = (root) => {
        if (root instanceof Element && root.matches('details[data-content-language-picker]')) {
            syncLanguagePicker(root);
        }
        root.querySelectorAll?.('details[data-content-language-picker]').forEach(syncLanguagePicker);
    };

    syncLanguagePickers(document);
    new MutationObserver(/** Refresh newly inserted language picker fragments.
     * @param {MutationRecord[]} records DOM mutation records.
     * @return {void} Synchronizes pickers in added elements.
     */ (records) => {
        records.forEach(/** Inspect nodes added by one mutation.
         * @param {MutationRecord} record One observed DOM mutation.
         * @return {void} Visits added nodes.
         */ (record) => record.addedNodes.forEach(/** Synchronize pickers beneath one added node.
         * @param {Node} node Added DOM node.
         * @return {void} Refreshes the node's language pickers when applicable.
         */ (node) => {
            if (node instanceof Element) syncLanguagePickers(node);
        }));
    }).observe(document.body, {childList: true, subtree: true});

    document.addEventListener('keydown', /** Close the active language disclosure on Escape.
     * @param {KeyboardEvent} event Keyboard interaction.
     * @return {void} Closes the disclosure and returns focus to its summary.
     */ (event) => {
        if (event.key !== 'Escape') return;
        const picker = event.target instanceof Element
            ? event.target.closest('details[data-content-language-picker]')
            : null;
        if (!(picker instanceof HTMLDetailsElement) || !picker.open) return;
        event.preventDefault();
        picker.open = false;
        picker.querySelector('summary')?.focus();
    });

    document.addEventListener('change', /** Update the selected language flag in this fragment.
     * @param {Event} event Language selection change.
     * @return {void} Update the visible flag.
     */ (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement) || !select.matches('[data-content-language-select]')) return;
        const localization = select.closest('[data-content-localization]');
        const picker = localization?.querySelector('details[data-content-language-picker]');
        if (picker) syncLanguagePicker(picker);
        const flag = localization?.querySelector('[data-content-language-flag]');
        if (flag instanceof HTMLImageElement) {
            const source = String(select.selectedOptions[0]?.dataset.flagSrc || '');
            flag.hidden = source === '';
            if (source !== '') flag.src = source;
        }
    });

    document.addEventListener('click', /** Copy the field value and announce the result.
     * @param {MouseEvent} event Copy button activation.
     * @return {Promise<void>} Completion after the clipboard attempt.
    */ async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (target && !target.closest('details[data-content-language-picker]')) {
            document.querySelectorAll('details[data-content-language-picker][open]').forEach(/** Close a picker after an outside click.
             * @param {HTMLDetailsElement} picker Open language disclosure.
             * @return {void} Closes the disclosure.
             */ (picker) => {
                picker.open = false;
            });
        }

        const option = target?.closest('button[data-content-language-option]') || null;
        if (option instanceof HTMLButtonElement) {
            const picker = option.closest('details[data-content-language-picker]');
            const localization = option.closest('[data-content-localization]');
            const select = localization?.querySelector('[data-content-language-select]');
            const value = String(option.dataset.contentLanguageValue ?? '');
            if (picker && select instanceof HTMLSelectElement
                && Array.from(select.options).some(/** Confirm that the requested option exists.
                 * @param {HTMLOptionElement} candidate Native language option.
                 * @return {boolean} Whether this option has the requested value.
                 */ (candidate) => candidate.value === value)) {
                event.preventDefault();
                select.value = value;
                select.dispatchEvent(new Event('change', {bubbles: true}));
                picker.open = false;
                picker.querySelector('summary')?.focus();
            }
            return;
        }

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
