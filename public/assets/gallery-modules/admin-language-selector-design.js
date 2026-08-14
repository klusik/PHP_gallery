/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * Purpose:
 *   Provides the in-place live preview and unsaved reset controls for the
 *   reusable public viewer-language selector design editor.
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

/** Build the preview buttons from the panel's real maintained-language cards. */
function buildLanguageDesignPreviewButtons(editor, preview) {
    preview.replaceChildren();
    const switcher = document.createElement('div');
    switcher.className = 'public-language-switcher';
    switcher.setAttribute('role', 'group');
    for (const [index, card] of Array.from(editor.closest('[data-public-language-selector-settings]')?.querySelectorAll('.admin-language-selector-language') || []).entries()) {
        const code = String(card.querySelector('input')?.value || '').toUpperCase();
        const name = String(card.querySelector('strong')?.textContent || code);
        const button = document.createElement('span');
        button.className = `public-language-button${index === 0 ? ' is-active' : ''}`;
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

/** Apply the current unsaved editor state to its production-shaped preview. */
function renderLanguageDesignPreview(editor) {
    if (!(editor instanceof HTMLElement)) {
        return;
    }
    const preview = editor.querySelector('[data-language-design-preview]');
    if (!(preview instanceof HTMLElement)) {
        return;
    }
    const preset = String(languageDesignValue(editor, 'preset') || 'classic');
    let switcher = preview.querySelector('.public-language-switcher');
    if (!(switcher instanceof HTMLElement)) {
        switcher = buildLanguageDesignPreviewButtons(editor, preview);
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

document.addEventListener('input', (event) => {
    const editor = languageDesignEditorFrom(event.target);
    if (editor && event.target.matches('[data-language-design-field]')) {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
            setLanguageDesignFieldValue(event.target, event.target.value);
        }
        renderLanguageDesignPreview(editor);
    }
});

document.addEventListener('change', (event) => {
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
