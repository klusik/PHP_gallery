/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-customization.js
 * Module Type: Browser Module
 * Purpose: Bind advanced Theme controls and the isolated managed-CSS editor.
 * Responsibilities: Preserve unsaved CSS and pending background File drafts, multipart Save revisions and protected preview styling.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import { restoreVisualEditorBackgroundFile, setVisualEditorBackgroundOperation, setThemeVisualEditorAvailability, visualEditorBackgroundDraftMessage } from './theme-visual-editor-support.js?v=20261010-visual-editor-draft-availability';

// Share one settled initialization attempt per mounted editor, including concurrent callers.
// Type: WeakMap<HTMLElement,Promise<void>>. Units: initialization promises.
// Scope: mounted Custom CSS editor roots. Consumers: optional workspace bootstrap.
// Rationale: avoid duplicate listeners without retaining detached editor roots.
const visualEditorLoads = new WeakMap();

/**
 * Synchronize service-bounded appearance inputs and their miniature public preview.
 * @param {HTMLFormElement} form Existing shared Theme form with optional advanced controls.
 * @returns {void} Binds sliders, numeric alternatives, switches and independent default resets once.
 */
export function setupThemeAdvancedAppearance(form) {
    const rows = [...form.querySelectorAll('[data-theme-advanced-control]')];
    const preview = form.querySelector('[data-theme-preview-page]');
    const syncPreview = () => {
        if (!(preview instanceof HTMLElement)) return;
        const values = Object.fromEntries(rows.map(row => {
            const input = row.querySelector('[data-theme-advanced-value]');
            return [row.dataset.themeAdvancedKey, input?.type === 'checkbox' ? (input.checked ? '1' : '0') : input?.value];
        }));
        for (const [key, variable] of [['gallery_grid_gap', '--preview-advanced-gap'], ['gallery_card_padding', '--preview-advanced-padding']]) {
            if (values[key] && values[key] !== '16') preview.style.setProperty(variable, `${Number(values[key]) / 2}px`);
            else preview.style.removeProperty(variable);
        }
        preview.style.setProperty('--preview-public-type-scale', String(Number(values.public_type_scale || '100') / 100));
        preview.dataset.headerTransparent = values.header_transparent || '0';
        const shadows = {none: 'none', soft: '0 4px 12px rgba(54,38,20,.08)', raised: '0 18px 42px rgba(54,38,20,.18)'};
        preview.querySelectorAll('.theme-preview-card').forEach(card => { card.style.boxShadow = shadows[values.card_shadow] || ''; });
    };
    rows.forEach(row => {
        if (row.dataset.themeAdvancedReady === '1') return;
        row.dataset.themeAdvancedReady = '1';
        const value = row.querySelector('[data-theme-advanced-value]');
        const slider = row.querySelector('[data-theme-advanced-slider]');
        const output = row.querySelector('[data-theme-advanced-output]');
        const reset = row.querySelector('[data-theme-advanced-reset]');
        if (!(value instanceof HTMLInputElement || value instanceof HTMLSelectElement)) return;
        const sync = (source, final = false) => {
            if (slider instanceof HTMLInputElement && value instanceof HTMLInputElement) {
                if (source === value && value.value === '' && !final) return;
                const number = Number.parseInt(source.value, 10);
                const normalized = Math.max(Number(value.min), Math.min(Number(value.max), Number.isFinite(number) ? number : Number(row.dataset.themeAdvancedDefault)));
                value.value = slider.value = String(normalized);
                if (output) output.textContent = `${normalized}${output.dataset.unit || ''}`;
            }
            syncPreview();
        };
        [value, slider].filter(Boolean).forEach(input => {
            input.addEventListener('input', () => sync(input));
            input.addEventListener('change', () => sync(input, true));
        });
        reset?.addEventListener('click', event => {
            event.preventDefault();
            if (value.type === 'checkbox') value.checked = row.dataset.themeAdvancedDefault === '1';
            else value.value = row.dataset.themeAdvancedDefault || '';
            sync(value, true);
            value.dispatchEvent(new Event('change', {bubbles: true}));
        });
        sync(value, true);
    });
    syncPreview();
}

/**
 * Preview draft CSS as a linked Blob stylesheet inside a sandboxed public sample.
 * @param {HTMLIFrameElement} frame Isolated draft surface with scripts and parent access disabled.
 * @param {string} text Administrator draft that must never become parent-page HTML or inline CSS.
 * @returns {string} Blob URL owned by the editor and revoked before replacement or unload.
 */
function showCssDraftPreview(frame, text) {
    const source = document.querySelector('[data-theme-preview-page]');
    const sample = source ? source.cloneNode(true) : document.createElement('main');
    sample.classList.add('public-page');
    sample.querySelector('.theme-preview-header')?.classList.add('site-header');
    sample.querySelector('.theme-preview-header nav')?.classList.add('nav');
    sample.querySelector('.theme-preview-hero')?.classList.add('hero');
    sample.querySelectorAll('.theme-preview-gallery-card').forEach(card => card.classList.add('gallery-card'));
    sample.querySelectorAll('.theme-preview-card-copy').forEach(copy => copy.classList.add('gallery-card-body'));
    sample.querySelectorAll('h3').forEach(heading => {
        const replacement = document.createElement('h2');
        replacement.textContent = heading.textContent;
        heading.replaceWith(replacement);
    });
    const draftUrl = URL.createObjectURL(new Blob([text], {type: 'text/css;charset=utf-8'}));
    const doc = document.implementation.createHTMLDocument('Theme preview');
    const policy = doc.createElement('meta');
    policy.httpEquiv = 'Content-Security-Policy';
    policy.content = "default-src 'none'; style-src 'unsafe-inline' blob:; img-src 'none'; font-src 'none'; form-action 'none'; base-uri 'none'";
    doc.head.append(policy);
    const base = doc.createElement('style');
    base.textContent = '.theme-preview-page{padding:1rem;background:var(--preview-paper,#f8f4ec);font-family:var(--preview-font-family,serif);color:#0f172a}.theme-preview-header{display:flex;gap:1rem;padding:1rem;background:rgba(255,255,255,.25)}.theme-preview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--preview-advanced-gap,1rem)}.theme-preview-card{background:var(--preview-panel,#fffaf0);border-radius:var(--preview-radius,16px)}.theme-preview-media{height:5rem;background:#b0c4de}.theme-preview-card-copy{padding:var(--preview-advanced-padding,1rem)}.theme-preview-settings{display:none}';
    doc.head.append(base);
    const draft = doc.createElement('link');
    draft.rel = 'stylesheet';
    draft.href = draftUrl;
    doc.head.append(draft);
    doc.body.append(sample);
    frame.srcdoc = '<!doctype html>' + doc.documentElement.outerHTML;
    frame.hidden = false;
    return draftUrl;
}

/**
 * Bind explicit editor mutations and protect unsaved text independently of ordinary Theme saves.
 * @returns {void} Installs accessible saved-state feedback, navigation guards and isolated draft preview once.
 */
export function setupThemeCssOverrideEditor() {
    const root = document.querySelector('[data-css-override-editor]');
    const form = document.querySelector('[data-css-override-form]');
    if (!(root instanceof HTMLElement) || !(form instanceof HTMLFormElement) || form.dataset.cssOverrideReady === '1') return;
    const visualSaveFailure = root.querySelector('[data-visual-editor-failed-label]');
    if (visualSaveFailure instanceof HTMLElement && visualSaveFailure.dataset.visualEditorFailedLabel) {
        root.dataset.failedLabel = visualSaveFailure.dataset.visualEditorFailedLabel;
    }
    const text = root.querySelector('[data-css-override-text]');
    const revision = root.querySelector('[data-css-override-revision]');
    const backgroundFile = root.querySelector('[data-visual-editor-background-file]');
    const backgroundRevision = root.querySelector('[data-visual-editor-background-revision]');
    const backgroundOperation = root.querySelector('[data-visual-editor-background-operation]');
    const savedTextSnapshot = root.querySelector('[data-css-override-saved-text]');
    const savedRevisionSnapshot = root.querySelector('[data-css-override-saved-revision]');
    const lifecycleLabels = root.querySelector('[data-visual-editor-lifecycle-labels]');
    const status = root.querySelector('[data-css-override-status]');
    const message = root.querySelector('[data-css-override-message]');
    if (!(text instanceof HTMLTextAreaElement) || !(revision instanceof HTMLInputElement)) return;
    form.dataset.cssOverrideReady = '1';
    const nativeClearConfirmation = root.querySelector('[data-css-override-clear-confirm]');
    if (nativeClearConfirmation) nativeClearConfirmation.hidden = true;
    let savedText = text.dataset.initialDirty === '1' ? null : text.value;
    let busy = false;
    let leavingPage = false;
    let previewUrl = '';
    let persistedText = savedTextSnapshot instanceof HTMLTextAreaElement ? savedTextSnapshot.value : text.value;
    let persistedRevision = savedRevisionSnapshot instanceof HTMLInputElement ? savedRevisionSnapshot.value : revision.value;
    let clearDraftSnapshot = null;
    let applyingDraftAction = false;
    const restoreSavedButton = root.querySelector('[data-css-override-restore-saved]');
    const clearDraftButton = root.querySelector('[data-css-override-clear-draft]');
    const undoClearDraftButton = root.querySelector('[data-css-override-undo-clear-draft]');
    const backgroundLabels = root.querySelector('[data-visual-editor-labels]');
    const dirty = () => savedText === null || text.value !== savedText
        || backgroundFile instanceof HTMLInputElement && backgroundFile.files.length > 0
        || backgroundOperation instanceof HTMLInputElement && backgroundOperation.value !== 'keep';
    const syncStatus = () => { if (status) status.textContent = dirty() ? root.dataset.unsavedLabel : root.dataset.savedLabel; };
    root.addEventListener('theme-background-operation-change', () => {
        if (root.dataset.visualEditorInitialized !== '1') {
            const backgroundStatus = root.querySelector('[data-visual-editor-background-status]');
            if (backgroundStatus instanceof HTMLElement) {
                backgroundStatus.textContent = visualEditorBackgroundDraftMessage(root, backgroundOperation?.value || 'keep', backgroundFile?.files?.[0] || null);
            }
        }
        if (!applyingDraftAction) clearDraftSnapshot = null;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = !clearDraftSnapshot;
        syncStatus();
    });
    text.addEventListener('input', () => {
        if (!applyingDraftAction) clearDraftSnapshot = null;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = !clearDraftSnapshot;
        syncStatus();
    });
    backgroundFile?.addEventListener('change', () => {
        if (root.dataset.visualEditorInitialized !== '1' && root.dataset.visualEditorBackgroundReady !== '0'
            && backgroundFile.files.length > 0) setVisualEditorBackgroundOperation(root, 'replace');
        if (!applyingDraftAction) clearDraftSnapshot = null;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = !clearDraftSnapshot;
        syncStatus();
    });
    // Keep native background drafts usable while the optional workspace is loading or unavailable.
    // Once initialized, its existing handlers own the same controls and richer preview feedback.
    root.querySelector('[data-visual-editor-background-keep]')?.addEventListener('click', () => {
        if (root.dataset.visualEditorInitialized === '1') return;
        if (backgroundFile instanceof HTMLInputElement && !restoreVisualEditorBackgroundFile(backgroundFile, null)) {
            if (message) message.textContent = backgroundLabels?.dataset.visualEditorBackgroundUnavailableLabel || root.dataset.failedLabel;
            return;
        }
        backgroundFile?.dispatchEvent(new Event('change', {bubbles: true}));
        setVisualEditorBackgroundOperation(root, 'keep');
    });
    root.querySelector('[data-visual-editor-background-remove]')?.addEventListener('click', () => {
        if (root.dataset.visualEditorInitialized === '1' || root.dataset.visualEditorBackgroundReady === '0') return;
        setVisualEditorBackgroundOperation(root, 'remove');
    });
    restoreSavedButton?.addEventListener('click', () => {
        if (dirty() && !window.confirm(lifecycleLabels?.dataset.visualEditorRestoreConfirmLabel || '')) return;
        if (backgroundFile instanceof HTMLInputElement && !restoreVisualEditorBackgroundFile(backgroundFile, null)) {
            if (message) message.textContent = backgroundLabels?.dataset.visualEditorBackgroundUnavailableLabel || root.dataset.failedLabel;
            return;
        }
        text.value = persistedText;
        savedText = persistedText;
        revision.value = persistedRevision;
        if (backgroundFile instanceof HTMLInputElement) {
            backgroundFile.dispatchEvent(new Event('change', {bubbles: true}));
        }
        if (backgroundOperation instanceof HTMLInputElement) setVisualEditorBackgroundOperation(root, 'keep');
        clearDraftSnapshot = null;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = true;
        if (message) message.textContent = '';
        if (frame instanceof HTMLIFrameElement) frame.hidden = true;
        text.dispatchEvent(new Event('input', {bubbles: true}));
    });
    clearDraftButton?.addEventListener('click', () => {
        const file = backgroundFile instanceof HTMLInputElement ? backgroundFile.files?.[0] || null : null;
        const operation = backgroundOperation instanceof HTMLInputElement ? backgroundOperation.value : 'keep';
        if (text.value === '' && file === null && operation === 'keep') return;
        if (backgroundFile instanceof HTMLInputElement && !restoreVisualEditorBackgroundFile(backgroundFile, null)) {
            if (message) message.textContent = backgroundLabels?.dataset.visualEditorBackgroundUnavailableLabel || root.dataset.failedLabel;
            return;
        }
        clearDraftSnapshot = {text: text.value, file, operation};
        applyingDraftAction = true;
        text.value = '';
        text.dispatchEvent(new Event('input', {bubbles: true}));
        if (backgroundFile instanceof HTMLInputElement) {
            backgroundFile.dispatchEvent(new Event('change', {bubbles: true}));
        }
        if (backgroundOperation instanceof HTMLInputElement) setVisualEditorBackgroundOperation(root, 'keep');
        applyingDraftAction = false;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = false;
        if (message) message.textContent = '';
        if (frame instanceof HTMLIFrameElement) frame.hidden = true;
    });
    undoClearDraftButton?.addEventListener('click', () => {
        if (!clearDraftSnapshot) return;
        if (backgroundFile instanceof HTMLInputElement
            && !restoreVisualEditorBackgroundFile(backgroundFile, clearDraftSnapshot.file)) return;
        applyingDraftAction = true;
        text.value = clearDraftSnapshot.text;
        if (backgroundFile instanceof HTMLInputElement) backgroundFile.dispatchEvent(new Event('change', {bubbles: true}));
        if (backgroundOperation instanceof HTMLInputElement) {
            setVisualEditorBackgroundOperation(root, clearDraftSnapshot.operation);
        }
        clearDraftSnapshot = null;
        if (undoClearDraftButton instanceof HTMLButtonElement) undoClearDraftButton.hidden = true;
        applyingDraftAction = false;
        text.dispatchEvent(new Event('input', {bubbles: true}));
    });
    // Preserve native editing shortcuts; Tab inserts spaces only inside this code field.
    text.addEventListener('keydown', event => {
        if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
        event.preventDefault();
        text.setRangeText('    ', text.selectionStart, text.selectionEnd, 'end');
        text.dispatchEvent(new Event('input', {bubbles: true}));
    });
    const mayLeave = () => !dirty() || window.confirm(root.dataset.unsavedMessage || '');
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target.closest('a[href], [data-admin-tab-target], [data-admin-subtab-target]') : null;
        if (!target || root.contains(target) || target.dataset.adminSubtabTarget === 'admin-theme-css-subtab-editor'
            || target.dataset.adminTabTarget === 'admin-theme-tab-custom-css') return;
        if (!mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); }
        else if (target instanceof HTMLAnchorElement && !target.dataset.adminTabTarget && !target.dataset.adminSubtabTarget) {
            queueMicrotask(() => { if (!event.defaultPrevented && !target.hasAttribute('target')) leavingPage = true; });
        }
    }, true);
    document.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || !(event.target instanceof Element)
            || !event.target.matches('[data-admin-tab-target], [data-admin-subtab-target]')) return;
        if (!mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    document.querySelector('[data-theme-form]')?.addEventListener('submit', event => {
        if (!mayLeave()) { event.preventDefault(); event.stopImmediatePropagation(); }
        else queueMicrotask(() => { if (!event.defaultPrevented) leavingPage = true; });
    }, true);
    window.addEventListener('beforeunload', event => {
        if (dirty() && !leavingPage) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', () => { leavingPage = false; });
    const previewButton = root.querySelector('[data-css-override-preview]');
    const frame = root.querySelector('[data-css-override-frame]');
    if (previewButton instanceof HTMLButtonElement && frame instanceof HTMLIFrameElement) {
        previewButton.hidden = false;
        previewButton.addEventListener('click', () => {
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = showCssDraftPreview(frame, text.value);
        });
        window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); });
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const action = event.submitter?.value || 'save';
        if (action === 'clear' && !window.confirm(root.dataset.clearMessage || '')) return;
        if (action === 'reload' && dirty() && !window.confirm(root.dataset.discardMessage || '')) return;
        const body = new FormData(form);
        body.set('css_override_action', action);
        if (action === 'clear') body.set('css_override_clear_confirm', '1');
        const submittedBackgroundOperation = action === 'save' && backgroundOperation instanceof HTMLInputElement
            ? backgroundOperation.value
            : 'keep';
        const submittedBackgroundTarget = action === 'save' ? 'theme' : '';
        const submittedBackgroundFile = action === 'save' && submittedBackgroundOperation === 'replace'
            && backgroundFile instanceof HTMLInputElement
            ? backgroundFile.files?.[0] || null
            : null;
        if (action === 'save') {
            body.set('theme_background_operation', submittedBackgroundOperation);
            body.set('theme_background_target', submittedBackgroundTarget);
            if (submittedBackgroundOperation !== 'replace') body.delete('theme_background_file');
        }
        if (action !== 'save') {
            body.delete('theme_background_file');
            body.delete('theme_background_revision');
            body.delete('theme_background_operation');
            body.delete('theme_background_target');
        }
        busy = true;
        text.readOnly = true;
        const backgroundFileWasDisabled = backgroundFile instanceof HTMLInputElement ? backgroundFile.disabled : null;
        if (backgroundFile instanceof HTMLInputElement) backgroundFile.disabled = true;
        const buttons = [...root.querySelectorAll('button')];
        const disabled = buttons.map(button => button.disabled);
        buttons.forEach(button => { button.disabled = true; });
        try {
            const response = await fetch(form.action, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
            const result = await response.json();
            if (message) message.textContent = typeof result.message === 'string' ? result.message : root.dataset.failedLabel;
            if (response.ok && result.ok && typeof result.state?.text === 'string' && typeof result.state?.revision === 'string') {
                text.value = savedText = result.state.text;
                revision.value = result.state.revision;
                persistedText = result.state.text;
                persistedRevision = result.state.revision;
                if (savedTextSnapshot instanceof HTMLTextAreaElement) savedTextSnapshot.value = persistedText;
                if (savedRevisionSnapshot instanceof HTMLInputElement) savedRevisionSnapshot.value = persistedRevision;
                const savedBackground = result.background;
                if (savedBackground && typeof savedBackground.revision === 'string'
                    && typeof savedBackground.url === 'string' && typeof savedBackground.available === 'boolean'
                    && typeof savedBackground.source === 'string') {
                    if (backgroundRevision instanceof HTMLInputElement) backgroundRevision.value = savedBackground.revision;
                    root.dataset.visualEditorBackgroundUrl = savedBackground.url;
                    root.dataset.visualEditorBackgroundAvailable = savedBackground.available ? '1' : '0';
                    const confirmedOperation = action === 'save'
                        && savedBackground.operation === submittedBackgroundOperation
                        && savedBackground.target === submittedBackgroundTarget;
                    const operationStillCurrent = backgroundOperation instanceof HTMLInputElement
                        && backgroundOperation.value === submittedBackgroundOperation
                        && submittedBackgroundTarget === 'theme';
                    const pendingSelectionConsumed = confirmedOperation && operationStillCurrent
                        && submittedBackgroundOperation === 'replace'
                        && submittedBackgroundFile !== null
                        && backgroundFile instanceof HTMLInputElement
                        && (backgroundFile.files?.[0] || null) === submittedBackgroundFile;
                    const operationConsumed = confirmedOperation && operationStillCurrent
                        && (submittedBackgroundOperation !== 'replace' || pendingSelectionConsumed);
                    if (pendingSelectionConsumed && backgroundFile instanceof HTMLInputElement) backgroundFile.value = '';
                    if (operationConsumed) setVisualEditorBackgroundOperation(root, 'keep');
                    root.dispatchEvent(new CustomEvent('theme-background-saved', {
                        detail: {
                            background: savedBackground,
                            uploaded: pendingSelectionConsumed,
                            pendingSelectionConsumed,
                            operationConsumed,
                            operation: submittedBackgroundOperation,
                            target: submittedBackgroundTarget,
                        },
                    }));
                }
                disabled.fill(false);
                syncStatus();
                // A stale draft preview must never be mistaken for the newly saved or reloaded CSS.
                if (frame instanceof HTMLIFrameElement) frame.hidden = true;
            }
        } catch {
            if (message) message.textContent = root.dataset.failedLabel;
        } finally {
            busy = false;
            text.readOnly = false;
            if (backgroundFile instanceof HTMLInputElement && backgroundFileWasDisabled !== null) {
                backgroundFile.disabled = backgroundFileWasDisabled;
            }
            buttons.forEach((button, index) => { button.disabled = disabled[index]; });
            const availability = root.querySelector('[data-visual-editor-availability]');
            if (availability instanceof HTMLElement) {
                setThemeVisualEditorAvailability(root, availability.dataset.visualEditorReason || '');
            }
        }
    });
    syncStatus();
    void setupOptionalThemeVisualEditor(root, text);
}

/**
 * Load the optional workspace after synchronous Manual CSS form binding has completed.
 * @param {HTMLElement} root Mounted Custom CSS editor with server-rendered fallback and translated reasons.
 * @param {HTMLTextAreaElement} text Authoritative unsaved CSS field preserved during loading and failures.
 * @returns {Promise<void>} Settles after one initialization attempt; import and setup failures remain visible and do not reject the main application entrypoint.
 */
export function setupOptionalThemeVisualEditor(root, text) {
    const existing = visualEditorLoads.get(root);
    if (existing) return existing;
    root.dataset.visualEditorLoadState = 'loading';
    const loading = (async () => {
        let workspace;
        try {
            workspace = await import('./theme-visual-editor.js?v=20261010-visual-editor-draft-availability');
        } catch {
            root.dataset.visualEditorLoadState = 'failed';
            setThemeVisualEditorAvailability(root, 'preview_module_unavailable');
            return;
        }
        try {
            workspace.setupThemeVisualEditor(root, text);
            root.dataset.visualEditorLoadState = 'loaded';
        } catch {
            root.dataset.visualEditorLoadState = 'failed';
            setThemeVisualEditorAvailability(root, 'preview_initialization_failed');
        }
    })();
    visualEditorLoads.set(root, loading);
    return loading;
}
