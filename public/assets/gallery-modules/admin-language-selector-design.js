/**
 * Project: PHP Gallery
 * File: public/assets/gallery-modules/admin-language-selector-design.js
 * Module Type: Browser Module
 * Responsibilities:
 *   - Refresh the local preview and restore unsaved editor controls.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * Purpose:
 *   Provides the in-place live preview and unsaved reset controls for the
 *   reusable public viewer-language selector design editor.
  *
 * Author:
 *   Rudolf Klusal
*/

const DESIGN_COLOR_PROPERTIES = {
    container_bg: '--language-selector-bg', text_color: '--language-selector-text', border_color: '--language-selector-border',
    active_bg: '--language-selector-active-bg', active_text: '--language-selector-active-text', hover_bg: '--language-selector-hover-bg',
    focus_color: '--language-selector-focus',
};

const DESIGN_PIXEL_PROPERTIES = {
    selector_padding_x: '--language-selector-padding-x', selector_padding_y: '--language-selector-padding-y',
    selector_margin: '--language-selector-margin', gap: '--language-selector-gap', button_padding_x: '--language-button-padding-x',
    button_padding_y: '--language-button-padding-y', border_width: '--language-selector-border-width',
    selector_radius: '--language-selector-radius', button_radius: '--language-button-radius', flag_width: '--language-flag-width',
    flag_height: '--language-flag-height', font_size: '--language-code-size',
};

/** Cache offered-language/default signatures independently of design changes. */
const languagePreviewSignatures = new WeakMap();

/**
 * Resolve the explicitly paired choices panel without crossing form ownership.
 * @param {HTMLElement} editor Designer root in Theme or shared Settings.
 * @return {HTMLElement|null} Matching choices owner, or the historical enclosing panel.
 */
function languageDesignChoicesOwner(editor) {
    const form = editor.closest('form');
    const explicit = editor.dataset.languageSettingsId
        ? document.getElementById(editor.dataset.languageSettingsId) : null;
    if (explicit instanceof HTMLElement && explicit.matches('[data-public-language-selector-settings]')
        && explicit.closest('form') === form) {
        return explicit;
    }
    return editor.closest('[data-public-language-selector-settings]');
}

/**
 * Read the current public default from the same form without changing it.
 * @param {HTMLElement} editor Designer whose preview needs an active sample.
 * @return {string} Uppercase offered language code, or an empty default.
 */
function languageDesignPublicDefault(editor) {
    const control = editor.closest('form')?.querySelector('[name="public_language"], [name="settings[public_language]"]');
    return String(control?.value || '').toUpperCase();
}

/** Return the design editor that owns a control or event target. */
function languageDesignEditorFrom(target) {
    return target instanceof Element ? target.closest('[data-language-design-editor]') : null;
}

/** Return one named design field within an editor by its final bracket key. */
function languageDesignField(editor, field, preset = '') {
    const suffix = preset ? `[presets][${preset}][${field}]` : `[${field}]`;
    return Array.from(editor.querySelectorAll('[data-language-design-field]')).find((input) => input.name.endsWith(suffix)) || null;
}

/** Read a checkbox or scalar design field value. */
function languageDesignValue(editor, field, preset = '') {
    const input = languageDesignField(editor, field, preset);
    if (preset && Object.hasOwn(DESIGN_COLOR_PROPERTIES, field)) {
        const transparent = Array.from(editor.querySelectorAll('[data-language-design-transparent]'))
            .find((candidate) => candidate.name.endsWith(`[presets][${preset}][${field}_transparent]`));
        if (transparent instanceof HTMLInputElement && transparent.checked) {
            return 'transparent';
        }
    }
    if (input instanceof HTMLInputElement && input.type === 'checkbox') {
        return input.checked;
    }
    return input?.value || '';
}

/** Set one field to a value and synchronize its adjacent output. */
function setLanguageDesignFieldValue(input, value) {
    if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement)) {
        return;
    }
    if (input instanceof HTMLInputElement && input.type === 'checkbox') {
        input.checked = String(value) === '1' || value === true;
    } else {
        input.value = String(value);
    }
    const output = input.closest('.admin-language-design-control')?.querySelector('[data-language-design-output]');
    if (output instanceof HTMLOutputElement) {
        output.value = `${input.value} px`;
        output.textContent = output.value;
    }
}

/**
 * Build the sample from the paired panel's checked maintained-language cards.
 * @param {HTMLElement} editor Designer that owns the sample.
 * @param {HTMLElement} preview Sample container to replace when its signature changes.
 * @return {HTMLDivElement} Production-shaped switcher with the offered default active.
 */
function buildLanguageDesignPreviewButtons(editor, preview) {
    preview.replaceChildren();
    const switcher = document.createElement('div');
    switcher.className = 'public-language-switcher';
    switcher.setAttribute('role', 'group');
    const cards = Array.from(languageDesignChoicesOwner(editor)?.querySelectorAll('.admin-language-selector-language') || [])
        .filter(/**
         * Include only languages offered by the current draft.
         * @param {Element} card Maintained-language choice card.
         * @return {boolean} Whether its checkbox is checked.
         */ (card) => Boolean(card.querySelector('input')?.checked));
    const publicDefault = languageDesignPublicDefault(editor);
    const activeCode = cards.some(/**
         * Check whether the public default is offered in this draft.
         * @param {Element} card Checked language card.
         * @return {boolean} Whether its code matches the public default.
         */ (card) => String(card.querySelector('input')?.value || '').toUpperCase() === publicDefault)
        ? publicDefault : String(cards[0]?.querySelector('input')?.value || '').toUpperCase();
    for (const card of cards) {
        const code = String(card.querySelector('input')?.value || '').toUpperCase();
        const name = String(card.querySelector('strong')?.textContent || code);
        const button = document.createElement('span');
        button.className = `public-language-button${code === activeCode ? ' is-active' : ''}`;
        button.setAttribute('aria-label', name);
        const codeNode = document.createElement('span');
        codeNode.className = 'public-language-code';
        codeNode.textContent = code;
        button.append(codeNode);
        const nameNode = document.createElement('span');
        nameNode.className = 'public-language-name';
        nameNode.textContent = name;
        button.append(nameNode);
        const sourceFlag = card.querySelector('img');
        if (sourceFlag instanceof HTMLImageElement) {
            const flag = sourceFlag.cloneNode(true);
            flag.className = 'public-language-flag';
            button.append(flag);
        }
        switcher.append(button);
    }
    preview.append(switcher);
    return switcher;
}

/**
 * Apply draft design and paired language choices to the local sample.
 * @param {HTMLElement} editor Designer whose existing controls are read without persistence.
 * @return {void} Updates presentation while preserving all submitted fields.
 */
function renderLanguageDesignPreview(editor) {
    if (!(editor instanceof HTMLElement)) {
        return;
    }
    const preview = editor.querySelector('[data-language-design-preview]');
    if (!(preview instanceof HTMLElement)) {
        return;
    }
    const preset = String(languageDesignValue(editor, 'preset') || 'classic');
    const choices = languageDesignChoicesOwner(editor);
    const offered = Array.from(choices?.querySelectorAll('.admin-language-selector-language input:checked') || []).map(/**
     * Include the checked code in the bounded preview identity.
     * @param {Element} input Offered-language checkbox.
     * @return {string} Its current language code.
     */ (input) => input.value);
    const signature = JSON.stringify([offered, languageDesignPublicDefault(editor)]);
    let switcher = preview.querySelector('.public-language-switcher');
    if (!(switcher instanceof HTMLElement) || languagePreviewSignatures.get(editor) !== signature) {
        languagePreviewSignatures.set(editor, signature);
        switcher = buildLanguageDesignPreviewButtons(editor, preview);
    }
    const status = editor.querySelector('[data-language-design-preview-status]');
    if (status instanceof HTMLElement) {
        const enabled = choices?.querySelector('.admin-language-selector-enabled input[type="checkbox"]');
        status.textContent = offered.length === 0 ? (status.dataset.languagePreviewEmptyMessage || '')
            : enabled instanceof HTMLInputElement && !enabled.checked ? (status.dataset.languagePreviewDisabledMessage || '') : '';
        status.hidden = status.textContent === '';
    }
    editor.querySelectorAll('[data-language-theme-reset]').forEach(/**
     * Reveal Theme's local draft resets after the designer has bound successfully.
     * @param {Element} button JS-only local reset control.
     * @return {void} Makes the existing delegated reset available.
     */ (button) => { button.hidden = false; });
    if (editor.hasAttribute('data-language-theme-editor') && !editor.hasAttribute('data-language-design-ready')) {
        editor.setAttribute('data-language-design-ready', '1');
    }
    switcher.className = `public-language-switcher language-preset-${preset} language-orientation-${languageDesignValue(editor, 'orientation')} language-density-${languageDesignValue(editor, 'density')} language-align-${languageDesignValue(editor, 'alignment')} language-active-${languageDesignValue(editor, 'active_style')}`;
    for (const [field, property] of Object.entries(DESIGN_COLOR_PROPERTIES)) {
        switcher.style.setProperty(property, String(languageDesignValue(editor, field, preset)));
    }
    const useThemeColors = Boolean(languageDesignValue(editor, 'use_theme_colors', preset));
    if (useThemeColors) {
        switcher.style.setProperty('--language-selector-bg', 'transparent');
        switcher.style.setProperty('--language-selector-text', 'var(--color-text, #2d2118)');
        switcher.style.setProperty('--language-selector-border', 'color-mix(in srgb, var(--accent-color, #2563eb) 55%, transparent)');
        switcher.style.setProperty('--language-selector-active-bg', 'var(--accent-color, #2563eb)');
        switcher.style.setProperty('--language-selector-active-text', '#fffdf8');
        switcher.style.setProperty('--language-selector-hover-bg', 'color-mix(in srgb, var(--accent-color, #2563eb) 18%, #fffaf0)');
        switcher.style.setProperty('--language-selector-focus', 'color-mix(in srgb, var(--accent-color, #2563eb) 35%, white)');
        switcher.style.setProperty('--language-button-bg', 'color-mix(in srgb, var(--accent-color, #2563eb) 9%, #fffaf0)');
    } else {
        switcher.style.setProperty('--language-button-bg', 'transparent');
    }
    for (const [field, property] of Object.entries(DESIGN_PIXEL_PROPERTIES)) {
        switcher.style.setProperty(property, `${Number(languageDesignValue(editor, field, preset)) || 0}px`);
    }
    switcher.style.setProperty('--language-selector-border-style', String(languageDesignValue(editor, 'border_style', preset) || 'solid'));
    const showFlags = Boolean(languageDesignValue(editor, 'show_flags'));
    const showNames = Boolean(languageDesignValue(editor, 'show_names'));
    const showCodes = Boolean(languageDesignValue(editor, 'show_codes')) || (!showFlags && !showNames);
    switcher.querySelectorAll('.public-language-flag').forEach((flag) => { flag.hidden = !showFlags; });
    switcher.querySelectorAll('.public-language-code').forEach((code) => { code.hidden = !showCodes; });
    switcher.querySelectorAll('.public-language-name').forEach((name) => { name.hidden = !showNames; });
    editor.querySelectorAll('[data-language-design-transparent]').forEach((transparent) => {
        const colorInput = transparent.closest('.admin-language-design-control')?.querySelector('input[type="color"]');
        if (colorInput instanceof HTMLInputElement && transparent instanceof HTMLInputElement) {
            colorInput.disabled = transparent.checked;
            colorInput.closest('.admin-language-design-control')?.classList.toggle('is-transparent', transparent.checked);
        }
    });
    editor.querySelectorAll('[data-language-design-preset]').forEach((section) => {
        section.hidden = section.getAttribute('data-language-design-preset') !== preset;
    });
}

/** Reset every design field in an editor to its canonical rendered default. */
function resetAllLanguageDesignFields(editor) {
    editor.querySelectorAll('[data-language-design-field]').forEach((input) => setLanguageDesignFieldValue(input, input.dataset.defaultValue || ''));
    renderLanguageDesignPreview(editor);
}

/**
 * Refresh paired designers after an offered-language or public-default edit.
 * @param {EventTarget|null} target Input whose draft value changed.
 * @return {void} Updates only designers owned by the same form and choices panel.
 */
function refreshLanguageChoicesPreviews(target) {
    if (!(target instanceof Element)
        || !target.matches('.admin-language-selector-language input, .admin-language-selector-enabled input, [name="public_language"], [name="settings[public_language]"]')) {
        return;
    }
    const form = target.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-language-design-editor]').forEach(/**
     * Refresh the affected owner while leaving other Settings forms untouched.
     * @param {Element} editor Designer in the target form.
     * @return {void} Reflects the offered list or current public default.
     */ (editor) => {
        if (editor instanceof HTMLElement && (target.matches('[name="public_language"], [name="settings[public_language]"]')
            || languageDesignChoicesOwner(editor)?.contains(target))) {
            renderLanguageDesignPreview(editor);
        }
    });
}

/** Attach initial previews while delegated handlers cover future panel fragments. */
export function setupAdminLanguageSelectorDesign() {
    document.querySelectorAll('[data-language-design-editor]').forEach((editor) => renderLanguageDesignPreview(editor));
    if (!document.body || document.body.dataset.languageDesignObserverBound === '1') {
        return;
    }
    document.body.dataset.languageDesignObserverBound = '1';
    const observer = new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (!(node instanceof Element)) {
                    continue;
                }
                const editors = node.matches('[data-language-design-editor]')
                    ? [node]
                    : Array.from(node.querySelectorAll('[data-language-design-editor]'));
                editors.forEach((editor) => renderLanguageDesignPreview(editor));
            }
        }
    });
    observer.observe(document.body, {childList: true, subtree: true});
}

document.addEventListener('input', /**
 * Reflect continuous design or paired language draft input.
 * @param {Event} event Bubbling input from an existing form control.
 * @return {void} Updates the owned preview and range readout.
 */ (event) => {
    refreshLanguageChoicesPreviews(event.target);
    const editor = languageDesignEditorFrom(event.target);
    if (editor && event.target.matches('[data-language-design-field]')) {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
            setLanguageDesignFieldValue(event.target, event.target.value);
        }
        renderLanguageDesignPreview(editor);
    }
});

document.addEventListener('change', /**
 * Reflect committed design or paired language draft changes.
 * @param {Event} event Bubbling change from an existing form control.
 * @return {void} Updates the affected preview without saving a preference.
 */ (event) => {
    refreshLanguageChoicesPreviews(event.target);
    const editor = languageDesignEditorFrom(event.target);
    if (editor && event.target.matches('[data-language-design-field]')) {
        renderLanguageDesignPreview(editor);
    }
});

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-language-design-reset-field], [data-language-design-reset-preset], [data-language-design-reset-all]') : null;
    const editor = languageDesignEditorFrom(button);
    if (!(button instanceof HTMLButtonElement) || !(editor instanceof HTMLElement)) {
        return;
    }
    event.preventDefault();
    if (button.hasAttribute('data-language-design-reset-field')) {
        button.closest('.admin-language-design-control')?.querySelectorAll('[data-language-design-field]').forEach((input) => setLanguageDesignFieldValue(input, input.dataset.defaultValue || ''));
    } else if (button.hasAttribute('data-language-design-reset-preset')) {
        button.closest('[data-language-design-preset]')?.querySelectorAll('[data-language-design-field]').forEach((input) => setLanguageDesignFieldValue(input, input.dataset.defaultValue || ''));
    } else {
        resetAllLanguageDesignFields(editor);
    }
    renderLanguageDesignPreview(editor);
});
