/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-compact-editor.js
 * Module Type: Browser Module
 * Purpose: Keep compact gallery-editor controls usable in injected panels.
 * Responsibilities: Save isolated Display switches, anchor help, update language flags and copy API values.
 * Author: Rudolf Klusal
 * License: MIT License
 */

import { i18nForElement } from './admin-core.js?v=20261003-scoped-i18n-v1';
import { completeAdminMutation } from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';
import { beginAdminPanelSave, acknowledgeAdminPanelSave } from './admin-panel-drafts.js?v=20261012-pdf-files-v1';
import { captureAdminPanelOwner } from './admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

/** Bound one isolated switch write without retrying an uncertain mutation.
 * Type: number. Units: milliseconds. Scope: gallery Display autosave.
 * Consumers: saveGalleryDisplayPreference(). Value: 15000.
 * Rationale: match the established Admin preference request deadline.
 */
const DISPLAY_SAVE_TIMEOUT_MS = 15000;

/** Keep contextual explanations clear of the visible viewport edges.
 * Type: number. Units: CSS pixels. Scope: gallery editor contextual panels.
 * Consumers: positionDisplayHelp(). Value: 12.
 * Rationale: preserve the approved small panel edge gap on narrow screens.
 */
const DISPLAY_HELP_EDGE_PX = 12;

/** Bound visible search choices without rendering the full attachment catalog.
 * Type: number. Units: result buttons. Scope: one open attachment picker.
 * Consumers: renderSmartAttachmentResults(). Value: 8.
 * Rationale: eight choices fit a compact panel; a count identifies broader matches.
 */
const SMART_ATTACHMENT_RESULT_LIMIT = 8;

/** @typedef {{id:number,title:string,edit_url:string,visibility_state:string}} SmartAttachmentChoice */
/** @type {WeakMap<HTMLElement, SmartAttachmentChoice[]>} */
const smartAttachmentCatalogs = new WeakMap();

/**
 * Enhance newly mounted attachment fields while retaining native no-script forms.
 * @return {void} Cache presentation-only choices and expose staged detach actions.
 */
function setupSmartAttachments() {
    for (const root of document.querySelectorAll('[data-smart-attachments]')) {
        if (smartAttachmentCatalogs.has(root)) continue;
        const catalog = root.querySelector('[data-smart-attachment-catalog]');
        if (!catalog) continue;
        try {
            const choices = JSON.parse(catalog.textContent);
            if (!Array.isArray(choices)) continue;
            smartAttachmentCatalogs.set(root, choices);
            root.dataset.smartAttachmentsBound = '1';
            root.querySelectorAll('[data-smart-attachment-remove]')
                .forEach(button => { button.hidden = false; });
        } catch {
            // Retain native attached-row fields if damaged markup cannot enhance.
            // This view owner permanently supports ordinary gallery POST saves.
        }
    }
}

/**
 * Show a bounded set of name matches, excluding already staged attachments.
 * @param {HTMLElement} root Mounted attachment section in the gallery form.
 * @return {void} Replace only picker results, without changing submitted fields.
 */
function renderSmartAttachmentResults(root) {
    const input = root.querySelector('[data-smart-attachment-search]');
    const results = root.querySelector('[data-smart-attachment-results]');
    const status = root.querySelector('[data-smart-attachment-status]');
    const query = input.value.trim().toLocaleLowerCase();
    const attached = new Set(Array.from(root.querySelectorAll(
        '[data-smart-attachment-rows] [data-smart-attachment-id]'
    ), row => Number(row.dataset.smartAttachmentId)));
    const matches = query ? (smartAttachmentCatalogs.get(root) || [])
        .filter(row => !attached.has(row.id)
            && row.title.toLocaleLowerCase().includes(query)) : [];
    results.replaceChildren();
    for (const row of matches.slice(0, SMART_ATTACHMENT_RESULT_LIMIT)) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button secondary';
        button.dataset.smartAttachmentAdd = String(row.id);
        const name = document.createElement('strong');
        name.textContent = row.title;
        const state = document.createElement('small');
        state.textContent = row.visibility_state;
        button.append(name, state);
        results.append(button);
    }
    status.textContent = !query ? status.dataset.hint
        : !matches.length ? status.dataset.empty
        : matches.length > SMART_ATTACHMENT_RESULT_LIMIT
            ? `${SMART_ATTACHMENT_RESULT_LIMIT} / ${matches.length}` : '';
    positionDisplayHelp(root.querySelector('[data-smart-attachment-picker]'));
}

/**
 * Stage one explicitly selected attachment using the server-rendered row template.
 * @param {HTMLElement} root Mounted attachment section in the gallery form.
 * @param {number} id Exact identity from the current picker result.
 * @return {void} Add native fields, leaving persistence to the existing Save action.
 */
function addSmartAttachment(root, id) {
    const choice = smartAttachmentCatalogs.get(root)?.find(row => row.id === id);
    const rows = root.querySelector('[data-smart-attachment-rows]');
    if (!choice || rows.querySelector(`[data-smart-attachment-id="${id}"]`)) return;
    const row = root.querySelector('[data-smart-attachment-template]')
        .content.firstElementChild.cloneNode(true);
    row.dataset.smartAttachmentId = String(id);
    const title = row.querySelector('[data-smart-attachment-title]');
    title.textContent = choice.title + ' ↗';
    title.href = choice.edit_url;
    row.querySelector('.admin-smart-attachment-selection span').textContent = choice.title;
    row.querySelector('[data-smart-attachment-state]').textContent = choice.visibility_state;
    for (const field of row.querySelectorAll('[name]')) {
        field.name = field.name.replace('smart_gallery_children[0]',
            `smart_gallery_children[${id}]`);
    }
    row.querySelector('[data-smart-attachment-remove]').hidden = false;
    rows.append(row);
    root.querySelector('[data-smart-attachment-empty]').hidden = true;
    root.querySelector('[data-smart-attachment-search]').value = '';
    root.querySelector('[data-smart-attachment-picker]').open = false;
    row.querySelector('select').focus();
    row.querySelector('input').dispatchEvent(new Event('change', {bubbles: true}));
}

/** @type {WeakMap<HTMLFormElement, {pending:number, failed:boolean, tail:Promise<void>}>} */
const displaySaves = new WeakMap();

/**
 * Persist one switch through the editor's authenticated isolated-save action.
 * @param {HTMLInputElement} input Original mounted native binary control.
 * @param {boolean} desired Captured intent, retained while other saves finish.
 * @param {{pending:number, failed:boolean, tail:Promise<void>}} state Shared
 *     form queue; a failed write stops later queued writes and full-form replay.
 * @return {Promise<void>} Update canonical switches/revision and public context;
 *     preserve other drafts and lock uncertain results until editor review.
 */
async function saveGalleryDisplayPreference(input, desired, state) {
    const form = input.form;
    const row = input.closest('.admin-display-switch-row');
    const status = row?.querySelector('[data-gallery-display-status]');
    const text = (key, fallback) => i18nForElement(input, key, fallback);
    if (!form || !row || !status) return;
    if (state.failed || !form.isConnected) {
        state.pending--;
        status.textContent = text('admin.features.save_unknown', 'The save could not be verified. Reload current values before saving again.');
        return;
    }
    const revision = form.querySelector('input[name="edit_revision"]');
    const expected = String(revision?.value || '');
    const panel = form.closest('[data-admin-side-panel]');
    const submission = panel
        ? beginAdminPanelSave(form, captureAdminPanelOwner(panel))
        : null;
    const body = new FormData();
    body.set('action', 'save_display_preference');
    body.set('id', String(form.querySelector('input[name="id"]')?.value || ''));
    body.set('csrf_token', String(form.querySelector('input[name="csrf_token"]')?.value || ''));
    body.set('edit_revision', expected);
    body.set('preference', input.name);
    body.set('value', desired ? '1' : '0');
    body.set('ajax', '1');
    row.dataset.displaySaveState = 'saving';
    row.setAttribute('aria-busy', 'true');
    status.textContent = text('admin.features.saving', 'Saving…');
    try {
        const response = await fetch(form.getAttribute('action'), {
            method: 'POST', body, credentials: 'same-origin',
            cache: 'no-store', redirect: 'error',
            headers: {'Accept': 'application/json'},
            signal: AbortSignal.timeout(DISPLAY_SAVE_TIMEOUT_MS),
        });
        const result = await response.json();
        if (!response.ok || result.ok !== true
            || typeof result.setting_values?.[input.name] !== 'boolean'
            || !/^[1-9][0-9]*$/.test(String(result.edit_revision || ''))) {
            throw new Error('Unverified Display save');
        }
        if (form.isConnected && revision?.value === expected) {
            if (submission) {
                // Only the switch was submitted. Advance its revision without
                // acknowledging unsaved title/description/language drafts.
                submission.draft = null;
                acknowledgeAdminPanelSave(submission, result);
            } else if (revision) revision.value = result.edit_revision;
            for (const control of form.querySelectorAll('[data-gallery-display-preference]')) {
                const peer = control.closest('.admin-display-switch-row');
                if (typeof result.setting_values[control.name] === 'boolean'
                    && (control === input || !['queued', 'saving'].includes(peer?.dataset.displaySaveState))) {
                    control.checked = result.setting_values[control.name];
                }
            }
        }
        row.dataset.displaySaveState = 'saved';
        status.textContent = text('admin.features.saved', 'Saved');
        try {
            const completion = await completeAdminMutation(result);
            if (!completion.synchronized) {
                status.textContent = text('admin.side_panel.sync_failed_after_success', 'Saved. The public preview could not be refreshed.');
            }
        } catch {
            status.textContent = text('admin.side_panel.sync_failed_after_success', 'Saved. The public preview could not be refreshed.');
        }
        input.disabled = false;
    } catch {
        state.failed = true;
        row.dataset.displaySaveState = 'unknown';
        status.textContent = text('admin.features.save_unknown', 'The save could not be verified. Reload current values before saving again.');
        const latest = document.createElement('a');
        latest.href = form.getAttribute('action');
        latest.target = '_blank';
        latest.rel = 'noopener';
        latest.textContent = text('admin.gallery_editor.edit_review_open', 'Open current editor');
        status.append(' ', latest);
    } finally {
        row.removeAttribute('aria-busy');
        state.pending--;
    }
}

/**
 * Anchor an open native help disclosure in the browser's nonblocking top layer.
 * @param {HTMLDetailsElement} help Open editor explanation or attachment picker.
 * @return {void} Keep the readable glass panel inside the viewport.
 */
function positionDisplayHelp(help) {
    const content = help.querySelector('.admin-inline-help-content');
    const trigger = help.querySelector('summary');
    if (!content || !trigger) return;
    if (typeof content.showPopover === 'function') {
        content.setAttribute('popover', 'manual');
        if (!content.matches(':popover-open')) content.showPopover();
    }
    content.style.position = 'fixed';
    content.style.inset = 'auto';
    content.style.margin = '0';
    const anchor = trigger.getBoundingClientRect();
    const box = content.getBoundingClientRect();
    const edge = DISPLAY_HELP_EDGE_PX;
    content.style.left = `${Math.max(edge, Math.min(anchor.left,
        innerWidth - box.width - edge))}px`;
    const below = anchor.bottom + edge;
    content.style.top = `${Math.max(edge, below + box.height < innerHeight - edge
        ? below : anchor.top - box.height - edge)}px`;
}

/**
 * Bind controls once on the document so newly rendered drawer fragments work.
 *
 * @returns {void} Installs delegated attachment, copy, language, help and Display-save events
 *     for full-page editors and dynamically injected drawer fragments.
 */
export function setupAdminGalleryCompactEditor() {
    if (!document.body || document.body.dataset.adminGalleryCompactEditorBound === '1') return;
    document.body.dataset.adminGalleryCompactEditorBound = '1';

    setupSmartAttachments();
    new MutationObserver(setupSmartAttachments)
        .observe(document.body, {childList: true, subtree: true});
    document.addEventListener('input', event => {
        if (event.target instanceof Element && event.target.matches('[data-smart-attachment-search]')) {
            renderSmartAttachmentResults(event.target.closest('[data-smart-attachments]'));
        }
    });
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const button = event.target.closest('[data-smart-attachment-add], [data-smart-attachment-remove]');
        if (!button) return;
        const root = button.closest('[data-smart-attachments]');
        if (button.hasAttribute('data-smart-attachment-add')) {
            addSmartAttachment(root, Number(button.dataset.smartAttachmentAdd));
        } else {
            button.closest('[data-smart-attachment-id]').remove();
            root.querySelector('[data-smart-attachment-empty]').hidden =
                root.querySelector('[data-smart-attachment-rows]').childElementCount > 0;
            const picker = root.querySelector('[data-smart-attachment-picker]');
            picker.querySelector('summary').focus();
            root.querySelector('input').dispatchEvent(new Event('change', {bubbles: true}));
        }
    });
    document.addEventListener('keydown', event => {
        if (!(event.target instanceof Element) || event.isComposing
            || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const picker = event.target.closest('[data-smart-attachment-picker]');
        if (!picker || !['Enter', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
        if (event.target.matches('[data-smart-attachment-search]')) {
            event.preventDefault();
            picker.querySelector('[data-smart-attachment-add]')?.focus();
        } else if (event.key !== 'Enter') {
            event.preventDefault();
            const buttons = Array.from(picker.querySelectorAll('[data-smart-attachment-add]'));
            const index = buttons.indexOf(event.target) + (event.key === 'ArrowDown' ? 1 : -1);
            (buttons[index] || picker.querySelector('input')).focus();
        }
    });

    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)
            || !input.matches('#admin-edit-display [data-gallery-display-preference]')
            || !input.form) return;
        const form = input.form;
        const state = displaySaves.get(form)
            || {pending: 0, failed: false, tail: Promise.resolve()};
        displaySaves.set(form, state);
        const desired = input.checked;
        input.disabled = true;
        input.closest('.admin-display-switch-row').dataset.displaySaveState = 'queued';
        state.pending++;
        state.tail = state.tail.then(() => saveGalleryDisplayPreference(input, desired, state));
    });
    document.addEventListener('submit', (event) => {
        const state = displaySaves.get(event.target);
        if (state && (state.pending > 0 || state.failed)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('toggle', (event) => {
        const help = event.target;
        if (!(help instanceof HTMLDetailsElement)
            || !help.matches('#admin-edit-display .admin-inline-help, .admin-gallery-smart-attachments .admin-inline-help')) return;
        const content = help.querySelector('.admin-inline-help-content');
        if (help.open) {
            positionDisplayHelp(help);
            if (help.matches('[data-smart-attachment-picker]')) {
                renderSmartAttachmentResults(help.closest('[data-smart-attachments]'));
                help.querySelector('input').focus();
            }
        }
        else if (typeof content?.hidePopover === 'function'
            && content.matches(':popover-open')) content.hidePopover();
    }, true);
    document.addEventListener('click', (event) => {
        for (const help of document.querySelectorAll('#admin-edit-display .admin-inline-help[open], .admin-gallery-smart-attachments .admin-inline-help[open]')) {
            if (!help.contains(event.target)) help.open = false;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const help = document.querySelector('#admin-edit-display .admin-inline-help[open], .admin-gallery-smart-attachments .admin-inline-help[open]');
        if (!help) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        help.open = false;
        help.querySelector('summary')?.focus();
    }, true);
    for (const type of ['scroll', 'resize']) {
        window.addEventListener(type, () => {
            document.querySelectorAll('#admin-edit-display .admin-inline-help[open], .admin-gallery-smart-attachments .admin-inline-help[open]')
                .forEach(positionDisplayHelp);
        }, true);
    }

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
