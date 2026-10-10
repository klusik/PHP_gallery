/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-visual-editor.js
 * Module Type: Browser Module
 * Purpose: Inspect a scriptless public preview and prepare isolated Theme CSS edits.
 * Responsibilities: Keep the Admin HUD separate, preserve the CSS draft, and block preview actions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import { restoreVisualEditorBackgroundFile, setThemeVisualEditorAvailability, visualEditorBackgroundDraftMessage } from './theme-visual-editor-support.js?v=20261010-visual-editor-draft-availability';
// Retain the historical helper exports for direct visual-editor consumers.
export { restoreVisualEditorBackgroundFile, setVisualEditorBackgroundOperation } from './theme-visual-editor-support.js?v=20261010-visual-editor-draft-availability';

import {
    applyVisualCssDraftChanges,
    parseVisualCssDraft,
    redoVisualCssDraft,
    serializeVisualCssDraft,
    undoVisualCssDraft,
    visualCssDraftPropertyValueIsValid,
} from './theme-visual-css-draft.js?v=20261009-visual-editor-overlay-routing';
import {
    attachVisualCssResizeHandle,
    classifyVisualCssResizeProfile,
} from './theme-visual-css-resize.js?v=20261009-visual-editor-overlay-routing';
import {hasVisualCssImport} from './theme-visual-css-import.js?v=20261009-visual-editor-import';

/**
 * Define real responsive viewport widths used by the preview workspace.
 * Type: Array<{key: string, width: string}>.
 * Units: CSS pixels, except the desktop viewport which uses the available width.
 * Scope: One visual editor workspace.
 * Consumers: The HUD viewport controls and the isolated preview iframe.
 * Rationale: Let administrators inspect the genuine responsive layout at common device widths.
 */
const VISUAL_PREVIEW_PRESETS = [
    {key: 'desktop', width: '100%'},
    {key: 'tablet', width: '768px'},
    {key: 'mobile', width: '390px'},
];

/**
 * Select bounded property groups appropriate to the selected public element type.
 * Type: Readonly<Record<'text'|'linkButton'|'media'|'card'|'layout'|'hero'|'header',ReadonlyArray<string>>>.
 * Units: CSS property names. Scope: one selected element context profile.
 * Consumers: Stage3 property-control construction and per-property reset actions.
 * Rationale: show relevant appearance controls without exposing arbitrary CSS declarations.
 */
const VISUAL_PROFILE_PROPERTIES = Object.freeze({
    text: Object.freeze(['color', 'font-size', 'font-weight', 'line-height', 'text-align', 'text-decoration', 'margin']),
    linkButton: Object.freeze(['color', 'background-color', 'font-size', 'font-weight', 'line-height', 'text-align', 'text-decoration', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'padding', 'margin']),
    media: Object.freeze(['width', 'height', 'object-fit', 'object-position', 'border-radius']),
    card: Object.freeze(['color', 'background-color', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'padding', 'margin', 'width', 'min-height']),
    layout: Object.freeze(['color', 'background-color', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'padding', 'margin', 'width', 'min-height', 'gap']),
    hero: Object.freeze(['color', 'background-color', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'padding', 'margin', 'min-height', 'backdrop-filter', '-webkit-backdrop-filter']),
    header: Object.freeze(['color', 'background-color', 'border-radius', 'border-width', 'border-style', 'border-color', 'box-shadow', 'padding', 'margin', 'min-height', 'gap', 'backdrop-filter', '-webkit-backdrop-filter']),
});

/**
 * Classify an actual selected element into the smallest useful visual property profile.
 * @param {Element} element Selected element in the public preview document.
 * @returns {{key:'text'|'linkButton'|'media'|'card'|'layout'|'hero'|'header',properties:ReadonlyArray<string>}} Profile key and its safe CSS properties.
 */
function visualElementProfile(element) {
    if (element.matches('img,video,picture,svg')) return {key: 'media', properties: VISUAL_PROFILE_PROPERTIES.media};
    if (element.matches('a,button')) return {key: 'linkButton', properties: VISUAL_PROFILE_PROPERTIES.linkButton};
    if (element.matches('header,.site-header')) return {key: 'header', properties: VISUAL_PROFILE_PROPERTIES.header};
    if (element.matches('.hero')) return {key: 'hero', properties: VISUAL_PROFILE_PROPERTIES.hero};
    if (element.matches('h1,h2,h3,h4,h5,h6,p,a,button,label,span,li')) return {key: 'text', properties: VISUAL_PROFILE_PROPERTIES.text};
    if (element.matches('[data-gallery-card],.gallery-card,article,.gallery-card-body')) return {key: 'card', properties: VISUAL_PROFILE_PROPERTIES.card};
    return {key: 'layout', properties: VISUAL_PROFILE_PROPERTIES.layout};
}

/**
 * Validate a URL as a server-emitted home or gallery document within the mounted application.
 * @param {string} value Candidate absolute or relative preview URL.
 * @param {string} mountRoot Application mount root derived from the protected launch URL; defaults to `/` for launch validation.
 * @returns {URL|null} Safe same-origin homepage or gallery URL, or null when it is outside the preview boundary.
 */
function visualPreviewUrl(value, mountRoot = '/') {
    try {
        const url = new URL(value, window.location.href);
        const pages = url.searchParams.getAll('page');
        const page = pages.length === 1 ? pages[0] : null;
        /**
         * Limit link query data to route, audience and public-listing fields used by server-emitted URLs.
         * Type: Set<string>.
         * Units: distinct accepted query parameter names.
         * Scope: One URL validation call.
         * Consumers: Preview destination path and query validation.
         * Rationale: Reject unknown request state before the parent changes the protected iframe URL.
         */
        const knownParameters = new Set(['page', 'preview', 'view_as', 'visual_notice', 'public_path', 'gallery_page', 'photo_page', 'list_page', 'sort', 'order', 'q', 'tag']);
        const parameters = [...url.searchParams.keys()];
        if (url.origin !== window.location.origin || url.username !== '' || url.password !== '' || url.hash !== ''
            || !url.pathname.startsWith(mountRoot)
            || url.searchParams.get('preview') !== 'visual'
            || (url.searchParams.has('view_as') && url.searchParams.get('view_as') !== 'anonymous')
            || (url.searchParams.has('visual_notice')
                && (url.searchParams.get('visual_notice') !== 'anonymous_fallback' || url.searchParams.get('view_as') !== 'anonymous'))
            || parameters.some(name => !knownParameters.has(name))
            || parameters.some(name => url.searchParams.getAll(name).length !== 1)) return null;

        const relativePath = url.pathname.slice(mountRoot.length);
        const isFrontController = relativePath === 'index.php' && ['home', 'gallery'].includes(page);
        const isCleanMountHome = relativePath === '' && page === null;
        const isCleanGallery = page === null && /^gallery\/(?:[A-Za-z0-9._~%-]+\/)*$/.test(relativePath)
            && relativePath.length > 'gallery/'.length;
        const isCleanHomeAlias = page === null && /^galleries\/[1-9][0-9]*\/$/.test(relativePath);
        if (!isFrontController && !isCleanMountHome && !isCleanGallery && !isCleanHomeAlias) return null;
        if ((isCleanMountHome || isCleanHomeAlias)
            && parameters.some(name => !['preview', 'view_as', 'visual_notice'].includes(name))) return null;
        if (isCleanGallery && /(?:^|\/)(?:media|original|thumb-[0-9]+\.(?:jpg|webp))\/$/i.test(relativePath)) return null;
        if (isCleanGallery) {
            const segments = relativePath.slice(0, -1).split('/');
            if (segments.some(segment => {
                try {
                    const decoded = decodeURIComponent(segment);
                    const encoded = encodeURIComponent(decoded).replace(/[!'()*]/g, character => `%${character.charCodeAt(0).toString(16).toUpperCase()}`);
                    return decoded === '.' || decoded === '..' || /[\\/\u0000-\u001f]/.test(decoded)
                        || encoded.toLowerCase() !== segment.toLowerCase();
                } catch {
                    return true;
                }
            })) return null;
        }
        if (page === 'home' && [...url.searchParams.keys()].some(name => !['page', 'preview', 'view_as', 'visual_notice', 'gallery_page', 'list_page', 'sort', 'order', 'q', 'tag'].includes(name))) return null;
        if (isCleanGallery && parameters.some(name => ['page', 'public_path', 'id', 'gallery_id'].includes(name))) return null;
        return url;
    } catch {
        return null;
    }
}

/**
 * Derive the exact path prefix that the prepared preview URL identifies as the application mount.
 * @param {URL} launchUrl Server-prepared initial homepage URL.
 * @returns {string} Slash-terminated mount root used to reject paths outside this installation.
 */
function visualPreviewMountRoot(launchUrl) {
    if (launchUrl.pathname.endsWith('/index.php')) return launchUrl.pathname.slice(0, -'index.php'.length);
    return launchUrl.pathname.endsWith('/') ? launchUrl.pathname : `${launchUrl.pathname}/`;
}

/**
 * Validate the prepared homepage and classify only directly observable launch failures.
 * @param {string} value Server-prepared same-origin public homepage URL.
 * @returns {{url:URL|null,reason:'preview_url_invalid'|'preview_origin_mismatch'|'preview_marker_missing'|''}} Validated launch URL or a bounded failure category.
 */
function visualInitialPreviewUrl(value) {
    try {
        if (value.trim() === '') return {url: null, reason: 'preview_url_invalid'};
        const candidate = new URL(value, window.location.href);
        if (candidate.origin !== window.location.origin) return {url: null, reason: 'preview_origin_mismatch'};
        if (candidate.username !== '' || candidate.password !== '' || candidate.hash !== ''
            || !(candidate.pathname.endsWith('/index.php') || candidate.pathname.endsWith('/'))) {
            return {url: null, reason: 'preview_url_invalid'};
        }
        if (!candidate.searchParams.has('preview')) return {url: null, reason: 'preview_marker_missing'};
        const url = visualPreviewUrl(candidate.href, visualPreviewMountRoot(candidate));
        return {url, reason: url ? '' : 'preview_url_invalid'};
    } catch {
        return {url: null, reason: 'preview_url_invalid'};
    }
}

/**
 * Build a selector that does not depend on sibling order or visible text.
 * @param {Element} element Selected element in the scriptless public document.
 * @returns {{selector: string, count: number}} Stable selector and number of matching elements.
 */
function visualStableSelector(element) {
    const doc = element.ownerDocument;
    const candidates = [];
    if (element.id && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(element.id)) candidates.push(`#${element.id}`);
    for (const name of ['data-testid', 'data-gallery-id', 'data-image-id', 'data-role']) {
        const value = element.getAttribute(name);
        if (value && /^[A-Za-z0-9_-]+$/.test(value)) candidates.push(`[${name}="${value}"]`);
    }
    const classes = [...element.classList].filter(value => /^[A-Za-z_][A-Za-z0-9_-]*$/.test(value)).slice(0, 3).map(value => `.${value}`);
    if (classes.length) candidates.push(`${element.localName}${classes.join('')}`);
    candidates.push(element.localName);
    for (const selector of candidates) {
        try {
            const count = doc.querySelectorAll(selector).length;
            if (count > 0 && (count === 1 || selector === candidates[candidates.length - 1])) return {selector, count};
        } catch {
            continue;
        }
    }
    return {selector: element.localName, count: doc.querySelectorAll(element.localName).length};
}

/**
 * Anchor persisted public rules to the canonical page class so they outrank its Theme layer.
 * @param {Element} element Selected target in the protected public document.
 * @param {string} stableSelector Stable target selector without an Admin or runtime path.
 * @returns {string} Public-page-scoped selector used for managed CSS declarations.
 */
function visualManagedSelector(element, stableSelector) {
    return element.ownerDocument.body?.classList.contains('public-page')
        ? `body.public-page ${stableSelector}`
        : stableSelector;
}

/**
 * Resolve a semantic identity that can target the same component beyond one rendered page.
 * @param {Element} element Selected target in the protected public document.
 * @returns {string|null} Canonical entity or global-component selector, or null when uniqueness is route-local.
 */
function visualCanonicalIdentitySelector(element) {
    const galleryId = element.getAttribute('data-gallery-id') || '';
    if (/^[1-9][0-9]*$/.test(galleryId) && element.matches('article.gallery-card')) {
        return `article.gallery-card[data-gallery-id="${galleryId}"]`;
    }
    const imageId = element.getAttribute('data-image-id') || '';
    if (/^[1-9][0-9]*$/.test(imageId) && element.matches('article.image-card')) {
        return `article.image-card[data-image-id="${imageId}"]`;
    }
    for (const component of ['site-header', 'site-main', 'site-footer']) {
        if (element.classList.contains(component)) return `${element.localName}.${component}`;
    }
    return null;
}

/**
 * Describe the nearest real anchor without following it or inferring a link from neighboring content.
 * @param {Element} element Selected element from the public document.
 * @param {string} mountRoot Mounted application path used by route validation.
 * @returns {{url: URL|null, reason: string}} Validated preview destination or an explanatory denial.
 */
function visualAnchorDestination(element, mountRoot) {
    const anchor = element.closest('a[href]');
    if (!anchor || anchor.localName !== 'a') return {url: null, reason: 'unlinked'};
    if (anchor.hasAttribute('download') || (anchor.target !== '' && anchor.target !== '_self')) return {url: null, reason: 'target'};
    const url = visualPreviewUrl(anchor.href, mountRoot);
    return url ? {url, reason: ''} : {url: null, reason: 'unsafe'};
}

/**
 * Produce a short structural label for an ancestor button without exposing page copy.
 * @param {Element} element Public document ancestor.
 * @returns {string} Tag, optional identifier, and one safe class name.
 */
function visualElementLabel(element) {
    const className = [...element.classList].find(value => /^[A-Za-z0-9_-]{1,40}$/.test(value));
    return `${element.localName}${element.id ? `#${element.id}` : ''}${className ? `.${className}` : ''}`;
}

/**
 * Map common opaque CSS color syntax to a six-digit native color-picker value.
 * @param {string} value Authored or computed CSS color.
 * @returns {string|null} Six-digit hexadecimal color or null when conversion is not lossless enough to offer.
 */
function visualPickerHex(value) {
    if (/^#[A-Fa-f0-9]{6}$/.test(value)) return value;
    const shortHex = value.match(/^#([A-Fa-f0-9]{3})$/);
    if (shortHex) return `#${[...shortHex[1]].map(character => character + character).join('')}`;
    const rgb = value.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(?:\d+(?:\.\d+)?%?))?\s*\)$/i);
    if (!rgb || rgb.slice(1, 4).some(component => Number(component) > 255)) return null;
    return `#${rgb.slice(1, 4).map(component => Number(component).toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Read alpha from a supported literal CSS color for the separate opacity control.
 * @param {string} value Authored or computed CSS color value.
 * @returns {number} Alpha in the inclusive range zero through one, defaulting to one for opaque or unsupported syntax.
 */
function visualPickerAlpha(value) {
    const functional = value.match(/^(?:rgba|hsla)\([^)]*,\s*(\d+(?:\.\d+)?%?)\s*\)$/i);
    if (!functional) return 1;
    const rawAlpha = functional[1];
    const parsed = Number.parseFloat(rawAlpha);
    if (!Number.isFinite(parsed)) return 1;
    return Math.max(0, Math.min(1, rawAlpha.endsWith('%') ? parsed / 100 : parsed));
}

/**
 * Convert the native color picker value and opacity control to one validated literal token.
 * @param {string} hex Six-digit native color picker value.
 * @param {number} alpha Opacity from zero through one.
 * @returns {string} Hex for opaque colors or an explicit rgba() literal for translucent colors.
 */
function visualPickerColorValue(hex, alpha) {
    const match = hex.match(/^#([a-f0-9]{2})([a-f0-9]{2})([a-f0-9]{2})$/i);
    if (!match) return hex;
    const boundedAlpha = Math.max(0, Math.min(1, alpha));
    if (boundedAlpha >= 1) return hex;
    return `rgba(${Number.parseInt(match[1], 16)}, ${Number.parseInt(match[2], 16)}, ${Number.parseInt(match[3], 16)}, ${Math.round(boundedAlpha * 100) / 100})`;
}

/**
 * Add or remove the server-owned anonymous audience marker from a preview URL.
 * @param {URL} source Validated protected public URL.
 * @param {boolean} anonymous Whether the server should render anonymous visitor visibility.
 * @returns {URL} Independent URL carrying the requested audience while retaining route context.
 */
function visualAudienceUrl(source, anonymous) {
    const url = new URL(source.href);
    url.searchParams.delete('visual_notice');
    if (anonymous) url.searchParams.set('view_as', 'anonymous');
    else url.searchParams.delete('view_as');
    return url;
}

/**
 * Add the isolated HUD stylesheet once, outside the document affected by draft CSS.
 * @returns {void} Appends a cache-versioned Admin stylesheet link when it is not already present.
 */
function installVisualEditorStylesheet() {
    const existing = document.querySelector('[data-theme-visual-editor-styles]');
    if (existing instanceof HTMLLinkElement) return existing;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.dataset.themeVisualEditorStyles = '1';
    link.href = new URL('../styles/admin-theme-visual-editor.css?v=20261009-visual-editor-anonymous-fallback', import.meta.url).href;
    link.addEventListener('error', () => { link.dataset.loadFailed = '1'; }, {once: true});
    document.head.append(link);
    return link;
}

/**
 * Keep the preview usable if the optional workspace stylesheet cannot load.
 * @param {HTMLDialogElement} dialog Full-viewport native workspace.
 * @param {HTMLElement} hud Parent-owned navigation and status controls.
 * @param {HTMLElement} previewShell Scrollable viewport container.
 * @param {HTMLIFrameElement} frame Isolated public document.
 * @returns {void} Applies a small transparent-backdrop layout fallback to Admin-owned elements.
 */
function applyVisualEditorStyleFallback(dialog, hud, previewShell, frame) {
    dialog.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;max-width:none;max-height:none;margin:0;padding:0;overflow:hidden;background:#e7e9ed;border:0';
    previewShell.style.cssText = 'position:absolute;inset:0;display:flex;justify-content:center;overflow:auto';
    frame.style.cssText = 'display:block;flex:0 0 auto;width:100%;max-width:100%;height:100%;min-height:100%;border:0;background:#fff';
    hud.style.cssText = 'position:fixed;z-index:2;top:.5rem;left:50%;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.35rem;max-width:calc(100vw - 1rem);max-height:calc(100vh - 1rem);padding:.45rem;overflow:auto;color:#17202a;background:#fff;border:1px solid #cbd5e1;border-radius:.7rem;box-shadow:0 3px 16px rgb(15 23 42 / 18%);transform:translateX(-50%)';
    const fallback = document.createElement('style');
    fallback.dataset.themeVisualEditorBackdropFallback = '1';
    fallback.textContent = '.theme-visual-editor-workspace::backdrop{background:transparent}.theme-visual-editor-button{min-height:2.15rem;padding:.35rem .6rem;color:#17202a;background:#f8fafc;border:1px solid #cbd5e1;border-radius:.45rem}.theme-visual-editor-button:focus-visible{outline:3px solid #2563eb;outline-offset:2px}.theme-visual-editor-inspector{position:fixed;z-index:3;max-width:min(22rem,calc(100vw - 1rem));max-height:50vh;padding:.7rem;overflow:auto;color:#17202a;background:#fff;border:1px solid #cbd5e1;border-radius:.6rem;box-shadow:0 4px 18px rgb(15 23 42 / 20%)}.theme-visual-editor-inspector[hidden]{display:none}.theme-visual-editor-inspector code{display:block;overflow-wrap:anywhere}.theme-visual-editor-ancestors{display:flex;flex-wrap:wrap;gap:.25rem}.theme-visual-editor-inspector dl{display:grid;grid-template-columns:auto 1fr;gap:.2rem .5rem}.theme-visual-editor-hover-tip{position:fixed;z-index:4;max-width:min(22rem,calc(100vw - 1rem));padding:.3rem .5rem;color:#fff;background:#17202a;border-radius:.35rem;font:12px sans-serif;overflow-wrap:anywhere}.theme-visual-editor-hover-tip[hidden]{display:none}';
    document.head.append(fallback);
}

/**
 * Create a labeled workspace control without interpreting translated text as markup.
 * @param {string} label Visible and accessible button label.
 * @param {function(): void} activate Zero-argument action invoked by pointer or keyboard activation.
 * @returns {HTMLButtonElement} A native button configured for the floating HUD.
 */
function visualEditorButton(label, activate) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'theme-visual-editor-button';
    button.textContent = label;
    button.addEventListener('click', activate);
    return button;
}

/**
 * Return the three Theme-owned public shells whose widths move together.
 * @returns {Array<string>} Stable header, main-content, and footer selectors admitted by the draft model.
 */
function visualThemeWidthSelectors() {
    return ['body.public-page.public-page .site-header', 'body.public-page.public-page .site-main',
        'body.public-page.public-page .site-footer'];
}

/**
 * Initialize background draft controls independently, then optionally launch a protected scriptless CSS preview.
 * @param {HTMLElement} root Existing Custom CSS editor root carrying escaped preview labels and URL.
 * @param {HTMLTextAreaElement} text Authoritative unsaved CSS textarea.
 * @returns {void} Binds each root once, keeps invalid launch URLs visibly disabled with bounded reasons, and enables the workspace only after safe URL validation and completed setup; background drafts remain independent.
 */
export function setupThemeVisualEditor(root, text) {
    const launch = root.querySelector('[data-visual-editor-launch]');
    const backgroundFileInput = root.querySelector('[data-visual-editor-background-file]');
    const backgroundOperationInput = root.querySelector('[data-visual-editor-background-operation]');
    const backgroundTargetInput = root.querySelector('[data-visual-editor-background-target]');
    const backgroundStatus = root.querySelector('[data-visual-editor-background-status]');
    const currentBackgroundPreview = root.querySelector('[data-visual-editor-current-background-preview]');
    const pendingBackgroundPreview = root.querySelector('[data-visual-editor-pending-background-preview]');
    const backgroundTargetReview = root.querySelector('[data-visual-editor-background-target-review]');
    const backgroundOperationReview = root.querySelector('[data-visual-editor-background-operation-review]');
    const backgroundSourceReview = root.querySelector('[data-visual-editor-background-source-review]');
    const initialPreview = visualInitialPreviewUrl(root.dataset.visualEditorPreviewUrl || '');
    const initialUrl = initialPreview.url;
    const canLaunchPreview = launch instanceof HTMLButtonElement && initialUrl !== null;
    if (!(text instanceof HTMLTextAreaElement)) return;
    if (root.dataset.visualEditorInitialized === '1') return;
    if (backgroundOperationInput instanceof HTMLInputElement) backgroundOperationInput.disabled = false;
    if (backgroundTargetInput instanceof HTMLInputElement) backgroundTargetInput.disabled = false;
    const mountRoot = initialUrl ? visualPreviewMountRoot(initialUrl) : '';
    setThemeVisualEditorAvailability(root, initialPreview.reason);
    if (canLaunchPreview) launch.disabled = true;

    const labelSource = root.querySelector('[data-visual-editor-labels]');
    const backgroundLabelSource = root.querySelector('[data-visual-editor-background-labels]');
    const lifecycleLabelSource = root.querySelector('[data-visual-editor-lifecycle-labels]');
    const backgroundFitPositionLabelSource = root.querySelector('[data-visual-editor-background-fit-position-labels]');
    const extensionLabelSource = root.querySelector('[data-visual-editor-extension-labels]');
    const label = key => root.dataset[key] || labelSource?.dataset[key]
        || backgroundLabelSource?.dataset[key] || lifecycleLabelSource?.dataset[key]
        || backgroundFitPositionLabelSource?.dataset[key] || extensionLabelSource?.dataset[key] || '';

    let pendingBackgroundFile = backgroundFileInput instanceof HTMLInputElement ? backgroundFileInput.files?.[0] || null : null;
    let pendingBackgroundOperation = backgroundOperationInput instanceof HTMLInputElement
        && ['keep', 'replace', 'remove'].includes(backgroundOperationInput.value)
        ? backgroundOperationInput.value
        : 'keep';
    let activeBackgroundFrame = null;
    let backgroundVisibleOwner = root.dataset.visualEditorBackgroundAvailable === '1' ? 'theme_image' : 'none';
    let backgroundVisibleMode = backgroundVisibleOwner === 'theme_image' ? 'theme_image' : 'none';
    let backgroundVisualTarget = 'theme';
    let backgroundReplaceHudButton = null;
    let backgroundRemoveHudButton = null;
    const backgroundFileOriginallyDisabled = backgroundFileInput instanceof HTMLInputElement ? backgroundFileInput.disabled : true;
    let pendingBackgroundObjectUrl = '';
    let pendingBackgroundObjectUrlFile = null;
    const originalBackgroundImages = new WeakMap();
    let recordActiveVisualAction = () => {};

    /**
     * Refresh the safe current/pending background review beside the CSS editor.
     * @returns {void} Updates labels and same-origin image previews without exposing local paths.
     */
    function renderBackgroundReview() {
        const globalUrl = root.dataset.visualEditorBackgroundUrl || '';
        const globalAvailable = root.dataset.visualEditorBackgroundAvailable === '1' && globalUrl !== '';
        const backgroundReady = root.dataset.visualEditorBackgroundReady !== '0';
        if (currentBackgroundPreview instanceof HTMLImageElement) {
            currentBackgroundPreview.hidden = !globalAvailable;
            if (globalAvailable && currentBackgroundPreview.getAttribute('src') !== globalUrl) currentBackgroundPreview.src = globalUrl;
            if (!globalAvailable) currentBackgroundPreview.removeAttribute('src');
        }
        const emptyBackgroundMessage = root.querySelector('[data-visual-editor-background-empty]');
        if (emptyBackgroundMessage) emptyBackgroundMessage.hidden = globalAvailable;
        if (backgroundTargetReview) backgroundTargetReview.textContent = label('visualEditorBackgroundTargetGlobalLabel');
        let owner = null;
        try {
            owner = activeBackgroundFrame?.contentDocument?.querySelector('.theme-background-image') || null;
        } catch {
            backgroundVisualTarget = 'unknown';
            backgroundVisibleOwner = 'none';
            backgroundVisibleMode = 'none';
        }
        if (owner) {
            backgroundVisibleOwner = owner.dataset.themeBackgroundVisibleOwner || 'none';
            backgroundVisibleMode = owner.dataset.themeBackgroundVisibleMode || 'none';
            backgroundVisualTarget = owner.dataset.themeBackgroundVisualEditorTarget || 'unknown';
        }
        const ownerName = backgroundVisibleOwner;
        const ownerMode = backgroundVisibleMode;
        const sourceKey = ownerName === 'theme_image' ? 'ThemeImage'
            : ownerName === 'theme_gallery_fallback' ? 'ThemeGalleryFallback'
                : ownerName === 'gallery_override' ? 'GalleryOverride'
                    : 'None';
        const modeKey = ['upload', 'existing', 'collage'].includes(ownerMode)
            ? ownerMode[0].toUpperCase() + ownerMode.slice(1)
            : 'None';
        if (backgroundSourceReview) {
            const sourceText = sourceKey === 'None' ? '' : label(`visualEditorBackgroundSource${sourceKey}Label`);
            const modeText = ownerName === 'none' || modeKey !== 'None'
                ? label(`visualEditorBackgroundMode${modeKey}Label`)
                : '';
            backgroundSourceReview.textContent = [sourceText, modeText].filter(Boolean).join(' · ');
        }
        if (pendingBackgroundPreview instanceof HTMLImageElement) {
            const previewFile = pendingBackgroundOperation === 'replace' ? pendingBackgroundFile : null;
            pendingBackgroundPreview.hidden = !previewFile;
            if (previewFile) {
                if (!pendingBackgroundObjectUrl || pendingBackgroundObjectUrlFile !== previewFile) {
                    if (pendingBackgroundObjectUrl) URL.revokeObjectURL(pendingBackgroundObjectUrl);
                    pendingBackgroundObjectUrl = URL.createObjectURL(previewFile);
                    pendingBackgroundObjectUrlFile = previewFile;
                }
                pendingBackgroundPreview.src = pendingBackgroundObjectUrl;
                pendingBackgroundPreview.alt = previewFile.name;
            } else {
                pendingBackgroundPreview.removeAttribute('src');
            }
        }
        if (backgroundOperationReview) {
            const operationKey = `visualEditorBackgroundOperation${pendingBackgroundOperation[0].toUpperCase()}${pendingBackgroundOperation.slice(1)}Label`;
            backgroundOperationReview.textContent = [
                label(operationKey),
                label('visualEditorBackgroundTargetGlobalLabel'),
                pendingBackgroundOperation === 'replace' && pendingBackgroundFile ? pendingBackgroundFile.name : '',
                pendingBackgroundOperation !== 'keep' ? label('visualEditorBackgroundReviewHintLabel') : '',
            ].filter(Boolean).join(' · ');
        }
        if (backgroundStatus) {
            const unsupported = backgroundVisualTarget !== 'theme';
            if (!backgroundReady) backgroundStatus.textContent = label('visualEditorBackgroundUnavailableLabel');
            else if (unsupported) backgroundStatus.textContent = label('visualEditorBackgroundGlobalPreviewUnavailableLabel');
            else backgroundStatus.textContent = visualEditorBackgroundDraftMessage(root, pendingBackgroundOperation, pendingBackgroundFile);
        }
        const keepButton = root.querySelector('[data-visual-editor-background-keep]');
        const removeButton = root.querySelector('[data-visual-editor-background-remove]');
        if (keepButton instanceof HTMLButtonElement) keepButton.disabled = pendingBackgroundOperation === 'keep';
        if (removeButton instanceof HTMLButtonElement) removeButton.disabled = !backgroundReady || pendingBackgroundOperation === 'remove';
        const restrictedRoute = activeBackgroundFrame !== null && backgroundVisualTarget !== 'theme';
        if (backgroundReplaceHudButton instanceof HTMLButtonElement) {
            backgroundReplaceHudButton.disabled = !(backgroundFileInput instanceof HTMLInputElement) || !backgroundReady || restrictedRoute;
            backgroundReplaceHudButton.title = !backgroundReady ? label('visualEditorBackgroundUnavailableLabel')
                : restrictedRoute ? label('visualEditorBackgroundGlobalPreviewUnavailableLabel') : '';
        }
        if (backgroundRemoveHudButton instanceof HTMLButtonElement) {
            backgroundRemoveHudButton.disabled = !backgroundReady || restrictedRoute || pendingBackgroundOperation === 'remove';
            backgroundRemoveHudButton.title = !backgroundReady ? label('visualEditorBackgroundUnavailableLabel')
                : restrictedRoute ? label('visualEditorBackgroundGlobalPreviewUnavailableLabel') : '';
        }
        if (backgroundFileInput instanceof HTMLInputElement) {
            backgroundFileInput.disabled = backgroundFileOriginallyDisabled || !backgroundReady
                || activeBackgroundFrame !== null && restrictedRoute;
        }
    }

    /**
     * Apply a global Theme operation only when the real public route resolves to that asset.
     * @param {HTMLIFrameElement|null} frame Active scriptless preview frame, or null when closed.
     * @returns {void} Leaves gallery-specific and unknown background owners untouched.
     */
    function applyBackgroundOperationPreview(frame) {
        let previewDocument = null;
        try {
            previewDocument = frame?.contentDocument || null;
        } catch {
            return;
        }
        const imageLayer = previewDocument?.querySelector('.theme-background-image');
        if (!imageLayer || imageLayer.nodeType !== 1 || imageLayer.ownerDocument !== previewDocument
            || imageLayer.dataset.themeBackgroundVisualEditorTarget !== 'theme') return;
        if (!originalBackgroundImages.has(imageLayer)) {
            originalBackgroundImages.set(imageLayer, {
                value: imageLayer.style.getPropertyValue('background-image'),
                priority: imageLayer.style.getPropertyPriority('background-image'),
            });
        }
        if (pendingBackgroundOperation === 'remove') {
            imageLayer.style.setProperty('background-image', 'none');
            return;
        }
        if (pendingBackgroundOperation === 'replace' && pendingBackgroundFile) {
            if (!pendingBackgroundObjectUrl || pendingBackgroundObjectUrlFile !== pendingBackgroundFile) {
                if (pendingBackgroundObjectUrl) URL.revokeObjectURL(pendingBackgroundObjectUrl);
                pendingBackgroundObjectUrl = URL.createObjectURL(pendingBackgroundFile);
                pendingBackgroundObjectUrlFile = pendingBackgroundFile;
            }
            imageLayer.style.setProperty('background-image', `url("${pendingBackgroundObjectUrl}")`);
            return;
        }
        const original = originalBackgroundImages.get(imageLayer);
        if (original?.value) imageLayer.style.setProperty('background-image', original.value, original.priority);
        else imageLayer.style.removeProperty('background-image');
    }

    /**
     * Restore the exact server-rendered image declaration and revoke any pending object URL.
     * @param {HTMLIFrameElement|null} frame Active preview, or null when the workspace is closed.
     * @returns {void} Preserves the route-owned background style through visual session cleanup.
     */
    function clearBackgroundOperationPreview(frame) {
        let imageLayer = null;
        let previewDocument = null;
        try {
            previewDocument = frame?.contentDocument || null;
            imageLayer = previewDocument?.querySelector('.theme-background-image') || null;
        } catch {
            imageLayer = null;
        }
        if (imageLayer && imageLayer.nodeType === 1 && imageLayer.ownerDocument === previewDocument
            && originalBackgroundImages.has(imageLayer)) {
            const original = originalBackgroundImages.get(imageLayer);
            if (original.value) imageLayer.style.setProperty('background-image', original.value, original.priority);
            else imageLayer.style.removeProperty('background-image');
            originalBackgroundImages.delete(imageLayer);
        }
        if (pendingBackgroundObjectUrl) URL.revokeObjectURL(pendingBackgroundObjectUrl);
        pendingBackgroundObjectUrl = '';
        pendingBackgroundObjectUrlFile = null;
    }

    /**
     * Restore a pending file selection without uploading it or changing saved Theme state.
     * @param {File|null} file File snapshot to select, or null to clear the visual draft.
     * @returns {boolean} True when the browser accepted the requested form-control snapshot.
     */
    function restoreBackgroundFileSelection(file) {
        return restoreVisualEditorBackgroundFile(backgroundFileInput, file);
    }

    /**
     * Synchronize the reviewed operation with its same-origin frame and Admin status elements.
     * @param {HTMLIFrameElement|null} frame Active preview, or null when no workspace is open.
     * @returns {void} Keeps pending image URLs transient and respects the route-resolved target.
     */
    function syncBackgroundOperationPreview(frame = activeBackgroundFrame) {
        if (pendingBackgroundOperation === 'keep') clearBackgroundOperationPreview(frame);
        else applyBackgroundOperationPreview(frame);
        renderBackgroundReview();
    }

    /**
     * Adopt an operation change made by the HUD or a parent form lifecycle action.
     * @param {'keep'|'replace'|'remove'} operation Explicit pending global Theme operation.
     * @returns {void} Synchronizes the hidden POST field, preview, review, and grouped history.
     */
    function setPendingBackgroundOperation(operation) {
        if (!['keep', 'replace', 'remove'].includes(operation)) return;
        if (root.dataset.visualEditorBackgroundReady === '0' && operation !== 'keep') return;
        if (backgroundOperationInput instanceof HTMLInputElement) backgroundOperationInput.value = operation;
        root.dataset.visualEditorBackgroundOperation = operation;
        root.dispatchEvent(new CustomEvent('theme-background-operation-change', {detail: {operation}}));
    }

    /**
     * Restore a complete global background draft snapshot for reset, undo, or cancellation.
     * @param {File|null} file Pending local replacement attachment in the snapshot.
     * @param {'keep'|'replace'|'remove'} operation Pending global Theme operation in the snapshot.
     * @param {boolean} recordHistory Whether to record this change as a new history action; defaults to true for user actions.
     * @returns {boolean} True when the matching File selection was restored; false for an invalid operation or rejected File selection.
     */
    function restoreBackgroundDraft(file, operation, recordHistory = true) {
        if (!['keep', 'replace', 'remove'].includes(operation)) return false;
        if (file !== pendingBackgroundFile && !restoreBackgroundFileSelection(file)) return false;
        clearBackgroundOperationPreview(activeBackgroundFrame);
        pendingBackgroundFile = file;
        pendingBackgroundOperation = operation;
        if (backgroundOperationInput instanceof HTMLInputElement) backgroundOperationInput.value = operation;
        root.dataset.visualEditorBackgroundOperation = operation;
        root.dispatchEvent(new CustomEvent('theme-background-operation-change', {detail: {operation, recordHistory}}));
        return true;
    }

    root.addEventListener('theme-background-operation-change', event => {
        const operation = event.detail?.operation;
        if (!['keep', 'replace', 'remove'].includes(operation)) return;
        if (root.dataset.visualEditorBackgroundReady === '0' && operation !== 'keep') return;
        pendingBackgroundOperation = operation;
        syncBackgroundOperationPreview();
        if (event.detail?.recordHistory !== false) recordActiveVisualAction();
    });

    root.querySelector('[data-visual-editor-background-keep]')?.addEventListener('click', () => {
        if (!restoreBackgroundDraft(null, 'keep')) return;
        recordActiveVisualAction();
    });
    root.querySelector('[data-visual-editor-background-remove]')?.addEventListener('click', () => {
        if (!restoreBackgroundDraft(null, 'remove')) return;
        recordActiveVisualAction();
    });
    renderBackgroundReview();

    if (backgroundFileInput instanceof HTMLInputElement && backgroundFileInput.dataset.visualEditorReady !== '1') {
        backgroundFileInput.dataset.visualEditorReady = '1';
        backgroundFileInput.addEventListener('change', () => {
            const selectedFile = backgroundFileInput.files?.[0] || null;
            if (root.dataset.visualEditorBackgroundReady === '0') {
                restoreBackgroundFileSelection(pendingBackgroundFile);
                renderBackgroundReview();
                return;
            }
            const supportedType = selectedFile === null || ['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(selectedFile.type)
                || selectedFile.type === '' && /\.(?:jpe?g|png|gif|webp)$/i.test(selectedFile.name);
            if (!supportedType) {
                restoreBackgroundFileSelection(pendingBackgroundFile);
                if (backgroundStatus) backgroundStatus.textContent = label('visualEditorBackgroundFileInvalidLabel');
                return;
            }
            if (selectedFile !== pendingBackgroundFile) clearBackgroundOperationPreview(activeBackgroundFrame);
            pendingBackgroundFile = selectedFile;
            if (selectedFile) setPendingBackgroundOperation('replace');
            else syncBackgroundOperationPreview();
            recordActiveVisualAction();
            const clearButton = document.querySelector('[data-visual-editor-clear-pending-background]');
            if (clearButton instanceof HTMLButtonElement) clearButton.disabled = pendingBackgroundOperation === 'keep';
            renderBackgroundReview();
        });
        root.addEventListener('theme-background-saved', event => {
            const detail = event.detail;
            if (!detail?.background || typeof detail.background.url !== 'string') return;
            if (detail.pendingSelectionConsumed === true) pendingBackgroundFile = null;
            else if (backgroundFileInput instanceof HTMLInputElement) {
                pendingBackgroundFile = backgroundFileInput.files?.[0] || null;
            }
            if (detail.operationConsumed === true) {
                pendingBackgroundOperation = 'keep';
                if (backgroundOperationInput instanceof HTMLInputElement) backgroundOperationInput.value = 'keep';
                root.dataset.visualEditorBackgroundOperation = 'keep';
            }
            const clearButton = document.querySelector('[data-visual-editor-clear-pending-background]');
            if (clearButton instanceof HTMLButtonElement) clearButton.disabled = pendingBackgroundOperation === 'keep';
            syncBackgroundOperationPreview();
        });
    }
    root.dataset.visualEditorInitialized = '1';
    if (!canLaunchPreview) return;
    launch.addEventListener('click', () => {
        if (hasVisualCssImport(text.value)) {
            const message = root.querySelector('[data-css-override-message]');
            if (message instanceof HTMLElement) message.textContent = label('visualEditorImportUnsupportedLabel');
            launch.dataset.visualImportRejected = '1';
            launch.focus();
            return;
        }
        if (launch.dataset.visualImportRejected === '1') {
            const message = root.querySelector('[data-css-override-message]');
            if (message instanceof HTMLElement) message.textContent = '';
            delete launch.dataset.visualImportRejected;
        }
        const workspaceStyles = installVisualEditorStylesheet();
        const activeAudience = {anonymous: false};
        const history = [];
        const entryCss = text.value;
        const savedPageWidthMode = root.dataset.visualEditorPageWidthMode || 'default';
        const savedCustomWidthPreference = root.dataset.visualEditorPageWidthCustomPx || '1440';
        let entryPageWidthMode = savedPageWidthMode;
        let entryCustomWidthPreference = savedCustomWidthPreference;
        const entryBackgroundFile = pendingBackgroundFile;
        const entryBackgroundOperation = pendingBackgroundOperation;
        let cssDraft = parseVisualCssDraft(entryCss);
        let pageWidthMode = savedPageWidthMode;
        let customWidthPreference = savedCustomWidthPreference;
        let pageWidthModeWasSelected = false;
        /** Keep CSS, background, and width controls in one reversible visual-editor snapshot.
         * @type {Array<{cssHistoryIndex:number,rules:string,backgroundFile:File|null,backgroundOperation:'keep'|'replace'|'remove',pageWidthMode:'default'|'wide'|'custom'|'full',customWidthPreference:string}>}
         */
        const visualHistory = [{cssHistoryIndex: cssDraft.historyIndex, rules: JSON.stringify(cssDraft.rules), backgroundFile: pendingBackgroundFile,
            backgroundOperation: pendingBackgroundOperation, pageWidthMode, customWidthPreference}];
        let visualHistoryIndex = 0;
        let selectedTarget = null;
        let resizeHandleCleanup = () => {};
        const entrySelection = {start: text.selectionStart, end: text.selectionEnd};
        const entryViewport = {x: window.scrollX, y: window.scrollY};
        const dialog = document.createElement('dialog');
        dialog.className = 'theme-visual-editor-workspace';
        dialog.setAttribute('aria-label', label('visualEditorTitle'));
        dialog.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;max-width:none;max-height:none;margin:0;padding:0;overflow:hidden;border:0';
        const backdropProtection = document.createElement('style');
        backdropProtection.dataset.themeVisualEditorBackdropProtection = '1';
        backdropProtection.textContent = '.theme-visual-editor-workspace::backdrop{background:transparent}';
        document.head.append(backdropProtection);

        const hud = document.createElement('nav');
        hud.className = 'theme-visual-editor-hud';
        hud.dataset.collapsed = '0';
        hud.style.cssText = 'position:fixed;z-index:2;top:.5rem;left:50%;transform:translateX(-50%)';
        hud.setAttribute('aria-label', label('visualEditorTitle'));
        const collapseButton = visualEditorButton(label('visualEditorCollapseControlsLabel'), () => {
            const collapsed = hud.dataset.collapsed !== '1';
            hud.dataset.collapsed = collapsed ? '1' : '0';
            collapseButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            collapseButton.textContent = label(collapsed ? 'visualEditorExpandControlsLabel' : 'visualEditorCollapseControlsLabel');
        });
        collapseButton.dataset.visualEditorCollapseControls = '1';
        collapseButton.setAttribute('aria-expanded', 'true');
        const heading = document.createElement('strong');
        heading.className = 'theme-visual-editor-title';
        heading.textContent = label('visualEditorTitle');
        const pageTitle = document.createElement('span');
        pageTitle.className = 'theme-visual-editor-page-title';
        pageTitle.setAttribute('aria-live', 'polite');
        const status = document.createElement('span');
        status.className = 'theme-visual-editor-draft-status';
        status.setAttribute('role', 'status');
        status.textContent = label('visualEditorStatusLabel');
        const exitPrompt = document.createElement('div');
        exitPrompt.className = 'theme-visual-editor-exit-prompt';
        exitPrompt.setAttribute('role', 'alertdialog');
        exitPrompt.setAttribute('aria-modal', 'false');
        exitPrompt.setAttribute('aria-label', label('visualEditorExitConfirmationLabel'));
        exitPrompt.hidden = true;
        const exitPromptMessage = document.createElement('span');
        exitPromptMessage.textContent = label('visualEditorExitConfirmationLabel');
        const exitPromptApply = visualEditorButton(label('visualEditorApplyExitLabel'), () => applyButton.click());
        exitPromptApply.dataset.visualEditorApplySession = '1';
        const exitPromptDiscard = visualEditorButton(label('visualEditorDiscardSessionLabel'), () => cancelWorkspace());
        exitPromptDiscard.dataset.visualEditorDiscardSession = '1';
        const exitPromptContinue = visualEditorButton(label('visualEditorContinueEditingLabel'), () => {
            exitPrompt.hidden = true;
            focusWorkspaceExitControl();
        });
        exitPromptContinue.dataset.visualEditorContinueEditing = '1';
        exitPrompt.dataset.visualEditorExitPrompt = '1';
        exitPrompt.append(exitPromptMessage, exitPromptApply, exitPromptDiscard, exitPromptContinue);
        const inspector = document.createElement('section');
        inspector.className = 'theme-visual-editor-inspector';
        inspector.style.zIndex = '5';
        inspector.hidden = true;
        inspector.setAttribute('aria-label', label('visualEditorTitle'));
        const inspectorTitle = document.createElement('strong');
        const selectorText = document.createElement('code');
        const matchCount = document.createElement('span');
        const ancestors = document.createElement('div');
        ancestors.className = 'theme-visual-editor-ancestors';
        const computedSummary = document.createElement('dl');
        const propertiesPanel = document.createElement('div');
        propertiesPanel.className = 'theme-visual-editor-properties';
        propertiesPanel.hidden = true;
        const profileTitle = document.createElement('strong');
        const propertyScopeLabel = document.createElement('label');
        propertyScopeLabel.textContent = label('visualEditorScopeLabel');
        const propertyScope = document.createElement('select');
        propertyScope.setAttribute('aria-label', label('visualEditorScopeLabel'));
        for (const [value, key] of [
            ['site', 'visualEditorScopeSiteLabel'],
            ['responsive:tablet', 'visualEditorScopeTabletLabel'],
            ['responsive:mobile', 'visualEditorScopeMobileLabel'],
        ]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label(key);
            propertyScope.append(option);
        }
        propertyScopeLabel.append(propertyScope);
        const targetScopeLabel = document.createElement('label');
        targetScopeLabel.textContent = label('visualEditorTargetScopeLabel');
        const targetScope = document.createElement('select');
        targetScope.setAttribute('aria-label', label('visualEditorTargetScopeLabel'));
        targetScopeLabel.append(targetScope);
        const propertyControls = document.createElement('div');
        propertyControls.className = 'theme-visual-editor-property-controls';
        const cssWarning = document.createElement('p');
        cssWarning.className = 'theme-visual-editor-warning';
        cssWarning.setAttribute('role', 'status');
        const draftWarning = document.createElement('p');
        draftWarning.className = 'theme-visual-editor-warning';
        draftWarning.setAttribute('role', 'alert');
        propertiesPanel.append(profileTitle, propertyScopeLabel, targetScopeLabel, cssWarning, propertyControls);
        const undoButton = visualEditorButton(label('visualEditorUndoLabel'), () => {
            restoreVisualHistory(-1);
        });
        const redoButton = visualEditorButton(label('visualEditorRedoLabel'), () => {
            restoreVisualHistory(1);
        });
        const applyButton = visualEditorButton(label('visualEditorApplyExitLabel'), () => {
            if (!cssDraft.valid) return;
            const result = serializeVisualCssDraft(cssDraft);
            if (text.value !== result) {
                text.value = result;
                text.dispatchEvent(new Event('input', {bubbles: true}));
            }
            closeWorkspace();
        });
        applyButton.dataset.visualEditorApplyExit = '1';
        const resetSessionButton = visualEditorButton(label('visualEditorResetSessionLabel'), () => {
            if (!restoreBackgroundDraft(entryBackgroundFile, entryBackgroundOperation)) {
                if (backgroundStatus) backgroundStatus.textContent = label('visualEditorBackgroundFileInvalidLabel');
                return;
            }
            cssDraft = parseVisualCssDraft(entryCss);
            pageWidthMode = entryPageWidthMode;
            customWidthPreference = entryCustomWidthPreference;
            pageWidthModeWasSelected = true;
            visualHistory.splice(0, visualHistory.length, {
                cssHistoryIndex: cssDraft.historyIndex,
                rules: JSON.stringify(cssDraft.rules),
                backgroundFile: pendingBackgroundFile,
                backgroundOperation: pendingBackgroundOperation,
                pageWidthMode,
                customWidthPreference,
            });
            visualHistoryIndex = 0;
            exitPrompt.hidden = true;
            clearInspection();
            syncThemeWidthControls();
            applyManagedCssDraft();
        });
        resetSessionButton.dataset.visualEditorResetSession = '1';
        const followMessage = document.createElement('span');
        followMessage.className = 'theme-visual-editor-follow-message';
        const styleButton = visualEditorButton(label('visualEditorStyleElementLabel'), () => {
            if (!cssDraft.valid) return;
            const showing = propertiesPanel.hidden;
            inspector.dataset.mode = showing ? 'style' : 'inspect';
            propertiesPanel.hidden = !showing;
            styleButton.setAttribute('aria-pressed', showing ? 'true' : 'false');
            computedSummary.hidden = showing;
            if (showing) renderPropertyControls();
        });
        styleButton.disabled = !cssDraft.valid;
        const followButton = visualEditorButton(label('visualEditorFollowLinkLabel'), () => {
            if (followButton.dataset.destination) navigate(new URL(followButton.dataset.destination), true);
        });
        followButton.dataset.visualEditorFollowLink = '1';
        followButton.hidden = true;
        inspector.append(inspectorTitle, selectorText, matchCount, ancestors, followMessage, computedSummary, propertiesPanel, styleButton, followButton);
        const hoverTip = document.createElement('output');
        hoverTip.className = 'theme-visual-editor-hover-tip';
        hoverTip.style.zIndex = '6';
        hoverTip.hidden = true;
        const previewShell = document.createElement('div');
        previewShell.className = 'theme-visual-editor-preview-shell';
        previewShell.style.cssText = 'position:absolute;inset:0;display:flex;justify-content:center;overflow:auto';
        const frame = document.createElement('iframe');
        frame.className = 'theme-visual-editor-frame';
        frame.title = label('visualEditorTitle');
        frame.style.cssText = 'display:block;flex:0 0 auto;width:100%;max-width:100%;height:100%;min-height:100%;border:0';
        frame.setAttribute('sandbox', 'allow-same-origin');
        frame.setAttribute('referrerpolicy', 'same-origin');
        frame.setAttribute('scrolling', 'yes');
        activeBackgroundFrame = frame;
        let previewLoading = true;
        previewShell.append(frame);
        const resizeOverlay = document.createElement('div');
        resizeOverlay.className = 'theme-visual-editor-resize-overlay';
        resizeOverlay.style.cssText = 'position:fixed;inset:0;z-index:4;pointer-events:none';

        const homeUrl = visualAudienceUrl(initialUrl, false);
        let currentUrl = visualAudienceUrl(initialUrl, activeAudience.anonymous);
        const homeButton = visualEditorButton(label('visualEditorHomeLabel'), () => {
            const target = visualAudienceUrl(homeUrl, activeAudience.anonymous);
            if (target.href !== currentUrl.href) navigate(target, true);
        });
        const backButton = visualEditorButton(label('visualEditorBackLabel'), () => {
            const previous = history.pop();
            if (!previous) return;
            activeAudience.anonymous = previous.searchParams.get('view_as') === 'anonymous';
            currentUrl = previous;
            previewLoading = true;
            clearInspection();
            status.textContent = label('visualEditorLoadingLabel');
            frame.src = currentUrl.href;
            updateControls();
        });
        backButton.dataset.visualEditorBack = '1';
        const signedInButton = visualEditorButton(label('visualEditorSignedInLabel'), () => setAudience(false));
        signedInButton.dataset.visualEditorAudience = 'signed-in';
        const anonymousButton = visualEditorButton(label('visualEditorAnonymousLabel'), () => setAudience(true));
        anonymousButton.dataset.visualEditorAudience = 'anonymous';
        const presetButtons = VISUAL_PREVIEW_PRESETS.map(preset => {
            const button = visualEditorButton(label(`visualEditor${preset.key[0].toUpperCase()}${preset.key.slice(1)}Label`), () => {
                previewShell.dataset.viewport = preset.key;
                frame.style.width = preset.width;
                updateEffectivePageWidthOutput();
                presetButtons.forEach(candidate => candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false'));
            });
            button.dataset.visualEditorViewport = preset.key;
            button.setAttribute('aria-pressed', preset.key === 'desktop' ? 'true' : 'false');
            return button;
        });
        const widthGroup = document.createElement('fieldset');
        widthGroup.className = 'theme-visual-editor-theme-controls';
        widthGroup.classList.add('theme-visual-editor-page-width-controls');
        widthGroup.setAttribute('aria-label', label('visualEditorWidthLabel') || label('visualEditorWidthModeLabel'));
        const widthLabel = document.createElement('label');
        widthLabel.textContent = label('visualEditorWidthModeLabel');
        const widthMode = document.createElement('select');
        widthMode.dataset.visualEditorPageWidthMode = '1';
        widthMode.setAttribute('aria-label', label('visualEditorWidthModeLabel'));
        for (const [value, key] of [['default', 'visualEditorWidthDefaultLabel'], ['wide', 'visualEditorWidthWideLabel'],
            ['custom', 'visualEditorWidthCustomLabel'], ['full', 'visualEditorWidthFullLabel']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label(key);
            widthMode.append(option);
        }
        widthLabel.append(widthMode);
        /**
         * Match the Theme owner's custom page-width draft domain.
         * Type: HTMLInputElement range bounds. Units: CSS pixels. Scope: one active public page-width draft.
         * Consumers: `applyThemeWidthMode` and the managed CSS width validator.
         * Rationale: the Theme service normalizes saved custom widths to the same 1024–2048 pixel interval.
        */
        const customWidth = document.createElement('input');
        customWidth.type = 'range';
        /** Keep the input and safe-value validator on the Theme owner's custom-width draft bounds.
         * Type: Numeric range. Units: CSS pixels. Scope: one active public page-width draft.
         * Consumers: Theme width control and the managed CSS width validator.
         * Rationale: the existing Theme service clamps custom widths to 1024–2048 pixels.
         */
        customWidth.min = '1024';
        customWidth.max = '2048';
        customWidth.step = '1';
        customWidth.setAttribute('aria-label', label('visualEditorWidthValueLabel'));
        const numericWidthLabel = label('visualEditorWidthNumericValueLabel') || label('visualEditorWidthValueLabel');
        const effectiveWidthLabel = label('visualEditorWidthEffectiveLabel') || label('visualEditorEffectiveValueLabel');
        const customWidthNumberLabel = document.createElement('label');
        customWidthNumberLabel.textContent = numericWidthLabel;
        const customWidthNumber = document.createElement('input');
        customWidthNumber.type = 'number';
        customWidthNumber.dataset.visualEditorPageWidthNumber = '1';
        /** Keep the editable pixel field identical to the Theme service's accepted integer range.
         * Type: HTMLInputElement number minimum, maximum, and step.
         * Units: CSS pixels.
         * Scope: One active public page-width draft.
         * Consumers: The numeric field change handler and canonical Theme width-rule builder.
         * Rationale: Custom widths use the same 1024–2048 integer interval as the paired range input and server normalization.
         */
        customWidthNumber.min = '1024';
        customWidthNumber.max = '2048';
        customWidthNumber.step = '1';
        customWidthNumber.setAttribute('aria-label', numericWidthLabel);
        const customWidthUnit = document.createElement('span');
        customWidthUnit.textContent = 'px';
        customWidthNumberLabel.append(customWidthNumber, customWidthUnit);
        const widthOutput = document.createElement('output');
        widthOutput.className = 'theme-visual-editor-page-width-effective';
        widthOutput.dataset.visualEditorPageWidthEffective = '1';
        widthOutput.setAttribute('aria-live', 'polite');
        widthOutput.textContent = `${effectiveWidthLabel}: ${label('visualEditorValueUnavailableLabel')}`;
        const widthPrecedenceHint = document.createElement('small');
        widthPrecedenceHint.className = 'theme-visual-editor-page-width-hint';
        widthPrecedenceHint.dataset.visualEditorWidthPrecedenceHint = '1';
        widthPrecedenceHint.textContent = label('visualEditorWidthPrecedenceHintLabel');
        const resetWidthButton = visualEditorButton(label('visualEditorWidthResetLabel'), () => {
            cssDraft = applyVisualCssDraftChanges(cssDraft, visualThemeWidthSelectors().map(selector => ({
                scope: 'site', selector, property: 'width', value: null,
            })));
            pageWidthMode = savedPageWidthMode;
            customWidthPreference = savedCustomWidthPreference;
            pageWidthModeWasSelected = true;
            recordVisualAction();
            widthMode.value = pageWidthMode;
            customWidth.value = customWidthPreference;
            syncThemeWidthControls();
            applyManagedCssDraft();
        });
        widthGroup.append(widthLabel, customWidth, customWidthNumberLabel, widthOutput, widthPrecedenceHint, resetWidthButton);
        const backgroundFileButton = visualEditorButton(label('visualEditorBackgroundReplaceLabel'), () => {
            if (backgroundFileInput instanceof HTMLInputElement && !backgroundFileInput.disabled) backgroundFileInput.click();
        });
        backgroundFileButton.dataset.visualEditorBackgroundChoose = '1';
        backgroundReplaceHudButton = backgroundFileButton;
        backgroundFileButton.disabled = !(backgroundFileInput instanceof HTMLInputElement);
        if (backgroundFileInput instanceof HTMLInputElement) {
            backgroundFileInput.classList.add('theme-visual-editor-file-input');
            hud.append(backgroundFileInput);
        }
        const clearPendingBackgroundButton = visualEditorButton(label('visualEditorBackgroundKeepCurrentLabel'), () => {
            if (!restoreBackgroundDraft(null, 'keep')) return;
            recordVisualAction();
        });
        clearPendingBackgroundButton.dataset.visualEditorClearPendingBackground = '1';
        clearPendingBackgroundButton.disabled = pendingBackgroundOperation === 'keep';
        const removeBackgroundButton = visualEditorButton(label('visualEditorBackgroundRemoveLabel'), () => {
            if (!restoreBackgroundDraft(null, 'remove')) return;
            recordVisualAction();
        });
        backgroundRemoveHudButton = removeBackgroundButton;
        removeBackgroundButton.dataset.visualEditorRemoveBackground = '1';
        const backgroundOpacityGroup = document.createElement('label');
        backgroundOpacityGroup.className = 'theme-visual-editor-theme-controls';
        backgroundOpacityGroup.append(document.createTextNode(label('visualEditorBackgroundOpacityLabel')));
        /**
         * Keep the control inside the existing Theme background opacity domain.
         * Type: HTMLInputElement range bounds and step. Units: unit interval. Scope: `.theme-background-image` only.
         * Consumers: transient preview styling and the validated site-scope opacity rule.
         * Rationale: the public Theme layer uses normalized opacity and is separate from page content.
        */
        const backgroundOpacity = document.createElement('input');
        backgroundOpacity.type = 'range';
        /** Keep opacity within the Theme background image layer's normalized unit interval.
         * Type: Numeric range. Units: opacity fraction from 0 to 1. Scope: background image layer only.
         * Consumers: transient preview and the managed CSS opacity validator.
         * Rationale: the Theme background opacity setting is normalized and does not affect page content.
         */
        backgroundOpacity.min = '0';
        backgroundOpacity.max = '1';
        backgroundOpacity.step = '0.01';
        backgroundOpacity.setAttribute('aria-label', label('visualEditorBackgroundOpacityLabel'));
        const backgroundOpacityOutput = document.createElement('output');
        const resetBackgroundOpacityButton = visualEditorButton(label('visualEditorBackgroundResetOpacityLabel'), () => {
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'opacity', value: null,
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        });
        backgroundOpacityGroup.append(backgroundOpacity, backgroundOpacityOutput);
        const backgroundFitGroup = document.createElement('fieldset');
        backgroundFitGroup.className = 'theme-visual-editor-theme-controls';
        backgroundFitGroup.setAttribute('aria-label', label('visualEditorBackgroundFitLabel'));
        const backgroundFitLabel = document.createElement('label');
        backgroundFitLabel.textContent = label('visualEditorBackgroundFitLabel');
        const backgroundFit = document.createElement('select');
        backgroundFit.dataset.visualEditorBackgroundFit = '1';
        backgroundFit.setAttribute('aria-label', label('visualEditorBackgroundFitLabel'));
        const unsupportedFitOption = document.createElement('option');
        unsupportedFitOption.value = '';
        unsupportedFitOption.textContent = label('visualEditorValueUnavailableLabel');
        backgroundFit.append(unsupportedFitOption);
        for (const [value, key] of [['cover', 'visualEditorBackgroundFitCoverLabel'], ['contain', 'visualEditorBackgroundFitContainLabel']]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label(key);
            backgroundFit.append(option);
        }
        backgroundFitLabel.append(backgroundFit);
        const resetBackgroundFitButton = visualEditorButton(label('visualEditorBackgroundFitResetLabel'), () => {
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            if (imageLayer?.dataset.themeBackgroundVisualEditorTarget !== 'theme') return;
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'background-size', value: null,
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        });
        resetBackgroundFitButton.dataset.visualEditorBackgroundResetFit = '1';
        backgroundFitGroup.append(backgroundFitLabel, resetBackgroundFitButton);

        const backgroundPositionGroup = document.createElement('fieldset');
        backgroundPositionGroup.className = 'theme-visual-editor-theme-controls theme-visual-editor-background-position-controls';
        backgroundPositionGroup.setAttribute('aria-label', label('visualEditorBackgroundPositionLabel'));
        const backgroundPositionHeading = document.createElement('legend');
        backgroundPositionHeading.textContent = label('visualEditorBackgroundPositionLabel');
        const backgroundPositionXLabel = document.createElement('label');
        backgroundPositionXLabel.textContent = label('visualEditorBackgroundPositionXLabel');
        const backgroundPositionX = document.createElement('input');
        backgroundPositionX.type = 'range';
        backgroundPositionX.dataset.visualEditorBackgroundPositionX = '1';
        /** Keep the horizontal background-position control inside the model's percentage grammar.
         * Type: HTMLInputElement range bounds. Units: integer percent. Scope: global Theme image layer.
         * Consumers: transient iframe preview and the managed CSS background-position validator.
         * Rationale: percentage axes provide a bounded position control without accepting offsets or CSS expressions.
         */
        backgroundPositionX.min = '0';
        backgroundPositionX.max = '100';
        backgroundPositionX.step = '1';
        backgroundPositionX.setAttribute('aria-label', label('visualEditorBackgroundPositionXLabel'));
        backgroundPositionXLabel.append(backgroundPositionX);
        const backgroundPositionXOutput = document.createElement('output');
        backgroundPositionXOutput.dataset.visualEditorBackgroundPositionXEffective = '1';
        backgroundPositionXOutput.setAttribute('aria-label', `${label('visualEditorEffectiveValueLabel')}: ${label('visualEditorBackgroundPositionXLabel')}`);
        const backgroundPositionXNumber = document.createElement('input');
        backgroundPositionXNumber.type = 'number';
        /** Keep the numeric horizontal position alternative in the same closed integer-percent range.
         * Type: HTMLInputElement number bounds. Units: integer percent. Scope: global Theme image layer.
         * Consumers: the synchronized slider and managed CSS background-position validator.
         * Rationale: keyboard-editable values use the same bounded representation as pointer input.
         */
        backgroundPositionXNumber.min = '0';
        backgroundPositionXNumber.max = '100';
        backgroundPositionXNumber.step = '1';
        backgroundPositionXNumber.dataset.visualEditorBackgroundPositionXNumber = '1';
        backgroundPositionXNumber.setAttribute('aria-label', label('visualEditorBackgroundPositionXLabel'));
        const backgroundPositionYLabel = document.createElement('label');
        backgroundPositionYLabel.textContent = label('visualEditorBackgroundPositionYLabel');
        const backgroundPositionY = document.createElement('input');
        backgroundPositionY.type = 'range';
        backgroundPositionY.dataset.visualEditorBackgroundPositionY = '1';
        /** Keep the vertical background-position control inside the model's percentage grammar.
         * Type: HTMLInputElement range bounds. Units: integer percent. Scope: global Theme image layer.
         * Consumers: transient iframe preview and the managed CSS background-position validator.
         * Rationale: percentage axes provide a bounded position control without accepting offsets or CSS expressions.
         */
        backgroundPositionY.min = '0';
        backgroundPositionY.max = '100';
        backgroundPositionY.step = '1';
        backgroundPositionY.setAttribute('aria-label', label('visualEditorBackgroundPositionYLabel'));
        backgroundPositionYLabel.append(backgroundPositionY);
        const backgroundPositionYOutput = document.createElement('output');
        backgroundPositionYOutput.dataset.visualEditorBackgroundPositionYEffective = '1';
        backgroundPositionYOutput.setAttribute('aria-label', `${label('visualEditorEffectiveValueLabel')}: ${label('visualEditorBackgroundPositionYLabel')}`);
        const backgroundPositionYNumber = document.createElement('input');
        backgroundPositionYNumber.type = 'number';
        /** Keep the numeric vertical position alternative in the same closed integer-percent range.
         * Type: HTMLInputElement number bounds. Units: integer percent. Scope: global Theme image layer.
         * Consumers: the synchronized slider and managed CSS background-position validator.
         * Rationale: keyboard-editable values use the same bounded representation as pointer input.
         */
        backgroundPositionYNumber.min = '0';
        backgroundPositionYNumber.max = '100';
        backgroundPositionYNumber.step = '1';
        backgroundPositionYNumber.dataset.visualEditorBackgroundPositionYNumber = '1';
        backgroundPositionYNumber.setAttribute('aria-label', label('visualEditorBackgroundPositionYLabel'));
        let backgroundPositionNeedsExplicitPair = false;
        const resetBackgroundPositionButton = visualEditorButton(label('visualEditorBackgroundPositionResetLabel'), () => {
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            if (imageLayer?.dataset.themeBackgroundVisualEditorTarget !== 'theme') return;
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'background-position', value: null,
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        });
        resetBackgroundPositionButton.dataset.visualEditorBackgroundResetPosition = '1';
        backgroundPositionGroup.append(backgroundPositionHeading, backgroundPositionXLabel, backgroundPositionXOutput,
            backgroundPositionXNumber, backgroundPositionYLabel, backgroundPositionYOutput, backgroundPositionYNumber,
            resetBackgroundPositionButton);
        const unsupportedBackgroundValue = document.createElement('span');
        unsupportedBackgroundValue.className = 'theme-visual-editor-background-value-warning';
        unsupportedBackgroundValue.dataset.visualEditorBackgroundValueUnsupported = '1';
        unsupportedBackgroundValue.setAttribute('role', 'status');
        unsupportedBackgroundValue.textContent = label('visualEditorBackgroundValueUnsupportedLabel');
        unsupportedBackgroundValue.hidden = true;
        const backgroundOverrideConflict = document.createElement('span');
        backgroundOverrideConflict.className = 'theme-visual-editor-background-value-warning';
        backgroundOverrideConflict.dataset.visualEditorBackgroundOverrideConflict = '1';
        backgroundOverrideConflict.setAttribute('role', 'status');
        backgroundOverrideConflict.textContent = label('visualEditorBackgroundOverrideConflictLabel');
        backgroundOverrideConflict.hidden = true;

        const updateBackgroundOpacityPreview = () => {
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            const value = String(Number(backgroundOpacity.value));
            if (imageLayer?.nodeType === 1 && imageLayer.ownerDocument === frame.contentDocument) imageLayer.style.setProperty('opacity', value);
            backgroundOpacityOutput.textContent = `${Math.round(Number(value) * 100)}%`;
        };
        backgroundOpacity.addEventListener('input', updateBackgroundOpacityPreview);
        backgroundOpacity.addEventListener('change', () => {
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'opacity', value: String(Number(backgroundOpacity.value)),
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        });
        const previewBackgroundPosition = () => {
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            if (imageLayer?.dataset.themeBackgroundVisualEditorTarget !== 'theme'
                || imageLayer.ownerDocument !== frame.contentDocument) return;
            const requestedAxes = requestedBackgroundPositionAxes();
            if (!requestedAxes) return;
            const {x, y} = requestedAxes;
            backgroundPositionX.value = String(x);
            backgroundPositionY.value = String(y);
            imageLayer.style.setProperty('background-position', `${x}% ${y}%`);
            backgroundPositionXNumber.value = String(x);
            backgroundPositionYNumber.value = String(y);
            const computed = frame.contentWindow?.getComputedStyle(imageLayer);
            const effectiveAxes = visualBackgroundPositionPercentAxes(computed?.backgroundPosition || '');
            const effectiveMatchesRequest = effectiveAxes?.x === x && effectiveAxes?.y === y;
            backgroundPositionXOutput.textContent = effectiveAxes
                ? `${effectiveAxes.x}%`
                : (computed?.backgroundPosition || '—');
            backgroundPositionYOutput.textContent = effectiveAxes ? `${effectiveAxes.y}%` : '';
            const managedFit = cssDraft.rules.find(rule => rule.scope === 'site'
                && rule.selector === 'body.public-page .theme-background-image' && rule.property === 'background-size');
            const fitMatchesDraft = !managedFit || (computed?.backgroundSize || '').trim().toLowerCase() === managedFit.value;
            backgroundOverrideConflict.hidden = effectiveMatchesRequest && fitMatchesDraft;
            backgroundOverrideConflict.textContent = label('visualEditorBackgroundOverrideConflictLabel');
        };
        const commitBackgroundPosition = () => {
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            if (imageLayer?.dataset.themeBackgroundVisualEditorTarget !== 'theme') return;
            const requestedAxes = requestedBackgroundPositionAxes();
            if (!requestedAxes) return;
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'background-position',
                value: `${requestedAxes.x}% ${requestedAxes.y}%`,
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        };
        backgroundFit.addEventListener('change', () => {
            if (!['cover', 'contain'].includes(backgroundFit.value)) return;
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            if (imageLayer?.dataset.themeBackgroundVisualEditorTarget !== 'theme'
                || imageLayer.ownerDocument !== frame.contentDocument) return;
            imageLayer.style.setProperty('background-size', backgroundFit.value);
            cssDraft = applyVisualCssDraftChanges(cssDraft, [{
                scope: 'site', selector: 'body.public-page .theme-background-image', property: 'background-size', value: backgroundFit.value,
            }]);
            recordVisualAction();
            applyManagedCssDraft();
        });
        backgroundPositionX.addEventListener('input', previewBackgroundPosition);
        backgroundPositionY.addEventListener('input', previewBackgroundPosition);
        backgroundPositionX.addEventListener('change', commitBackgroundPosition);
        backgroundPositionY.addEventListener('change', commitBackgroundPosition);
        backgroundPositionXNumber.addEventListener('input', () => {
            if (backgroundPositionXNumber.value.trim() === '') return;
            const value = Number(backgroundPositionXNumber.value);
            if (!Number.isInteger(value) || value < 0 || value > 100) return;
            backgroundPositionX.value = String(value);
            previewBackgroundPosition();
        });
        backgroundPositionYNumber.addEventListener('input', () => {
            if (backgroundPositionYNumber.value.trim() === '') return;
            const value = Number(backgroundPositionYNumber.value);
            if (!Number.isInteger(value) || value < 0 || value > 100) return;
            backgroundPositionY.value = String(value);
            previewBackgroundPosition();
        });
        backgroundPositionXNumber.addEventListener('change', () => {
            if (backgroundPositionXNumber.value.trim() === '') {
                applyManagedCssDraft();
                return;
            }
            const value = Number(backgroundPositionXNumber.value);
            if (!Number.isInteger(value) || value < 0 || value > 100) {
                applyManagedCssDraft();
                return;
            }
            backgroundPositionX.value = String(value);
            commitBackgroundPosition();
        });
        backgroundPositionYNumber.addEventListener('change', () => {
            if (backgroundPositionYNumber.value.trim() === '') {
                applyManagedCssDraft();
                return;
            }
            const value = Number(backgroundPositionYNumber.value);
            if (!Number.isInteger(value) || value < 0 || value > 100) {
                applyManagedCssDraft();
                return;
            }
            backgroundPositionY.value = String(value);
            commitBackgroundPosition();
        });
        widthMode.addEventListener('change', () => applyThemeWidthMode(widthMode.value));
        customWidth.addEventListener('change', () => {
            widthMode.value = 'custom';
            applyThemeWidthMode('custom');
        });
        customWidth.addEventListener('input', () => {
            const value = Number(customWidth.value);
            if (Number.isInteger(value) && value >= 1024 && value <= 2048) customWidthNumber.value = String(value);
        });
        /** Accept only values that the paired Theme width controls can represent.
         * Type: Integer validation bound.
         * Units: CSS pixels.
         * Scope: One custom public page-width draft.
         * Consumers: Number-field input synchronization and the managed width history action.
         * Rationale: Keep typed values consistent with the server's 1024–2048 custom width domain.
         */
        customWidthNumber.addEventListener('input', () => {
            const value = Number(customWidthNumber.value);
            if (Number.isInteger(value) && value >= 1024 && value <= 2048) customWidth.value = String(value);
        });
        customWidthNumber.addEventListener('change', () => {
            const value = Number(customWidthNumber.value);
            if (customWidthNumber.value.trim() === '' || !Number.isInteger(value) || value < 1024 || value > 2048) {
                syncThemeWidthControls();
                return;
            }
            customWidth.value = String(value);
            widthMode.value = 'custom';
            applyThemeWidthMode('custom');
        });
        /**
         * Synchronize width controls with managed declarations and publish the rendered page width.
         * @returns {void} Shows the matching preset and custom value, then measures the iframe `.site-main` without adding CSS.
         */
        function syncThemeWidthControls() {
            const records = visualThemeWidthSelectors().map(selector => cssDraft.rules.find(rule => rule.scope === 'site'
                && rule.selector === selector && rule.property === 'width'));
            const values = records.filter(Boolean).map(rule => rule.value);
            const consistent = values.length === 3 && values.every(value => value === values[0]);
            let mode = pageWidthMode;
            let customPx = customWidthPreference;
            if (!pageWidthModeWasSelected && consistent) {
                const match = values[0].match(/^min\((\d+)px,calc\(100% - 2rem\)\)$/);
                if (values[0] === 'calc(100% - clamp(1rem, 3vw, 3rem))') mode = 'full';
                else if (match) {
                    const matchesSavedCustom = savedPageWidthMode === 'custom' && match[1] === savedCustomWidthPreference;
                    mode = matchesSavedCustom ? 'custom' : match[1] === '1120' ? 'default' : match[1] === '1440' ? 'wide' : 'custom';
                    if (mode === 'custom') customPx = match[1];
                }
            }
            pageWidthMode = ['default', 'wide', 'custom', 'full'].includes(mode) ? mode : 'default';
            customWidthPreference = customPx;
            widthMode.value = pageWidthMode;
            customWidth.value = customPx;
            customWidthNumber.value = customWidth.value;
            customWidth.disabled = !cssDraft.valid || widthMode.value !== 'custom';
            customWidthNumber.disabled = !cssDraft.valid || widthMode.value !== 'custom';
            updateEffectivePageWidthOutput();
        }
        /**
         * Read the effective CSS layout width from the current public preview document.
         * @returns {void} Announces the actual `.site-main` layout width in CSS pixels, or an unavailable state when no current frame main can be measured.
         */
        function updateEffectivePageWidthOutput() {
            const previewDocument = frame.contentDocument;
            let previewIsAllowed = false;
            try {
                previewIsAllowed = Boolean(visualPreviewUrl(frame.contentWindow.location.href, mountRoot));
            } catch {
                previewIsAllowed = false;
            }
            const main = previewIsAllowed ? previewDocument?.querySelector('.site-main') : null;
            const width = main && main.ownerDocument === previewDocument ? main.getBoundingClientRect().width : 0;
            const outputText = Number.isFinite(width) && width > 0
                ? `${effectiveWidthLabel}: ${Number(width.toFixed(2))} px`
                : `${effectiveWidthLabel}: ${label('visualEditorValueUnavailableLabel')}`;
            if (widthOutput.textContent !== outputText) widthOutput.textContent = outputText;
        }
        /**
         * Apply one canonical Theme width preset to header, main content, and footer together.
         * @param {string} mode Requested Theme width mode from the closed HUD select.
         * @returns {void} Adds the three validated site-scope rules as one managed history operation.
         */
        function applyThemeWidthMode(mode) {
            const safeMode = ['default', 'wide', 'custom', 'full'].includes(mode) ? mode : 'default';
            pageWidthMode = safeMode;
            pageWidthModeWasSelected = true;
            widthMode.value = safeMode;
            customWidth.disabled = safeMode !== 'custom';
            customWidthNumber.disabled = safeMode !== 'custom';
            const customValue = Math.max(1024, Math.min(2048, Number.parseInt(customWidth.value, 10) || 1440));
            customWidth.value = String(customValue);
            if (safeMode === 'custom') customWidthPreference = String(customValue);
            const widthValue = safeMode === 'full' ? 'calc(100% - clamp(1rem, 3vw, 3rem))'
                : `min(${safeMode === 'default' ? '1120' : safeMode === 'wide' ? '1440' : customValue}px,calc(100% - 2rem))`;
            cssDraft = applyVisualCssDraftChanges(cssDraft, visualThemeWidthSelectors().map(selector => ({
                scope: 'site', selector, property: 'width', value: widthValue,
            })));
            recordVisualAction();
            applyManagedCssDraft();
        }
        /**
         * Reflect the effective Theme-layer opacity or current managed override in its range control.
         * @returns {void} Updates the slider and percentage output without leaving transient inline CSS.
         */
        function syncBackgroundOpacityControl() {
            const managed = cssDraft.rules.find(rule => rule.scope === 'site'
                && rule.selector === 'body.public-page .theme-background-image' && rule.property === 'opacity');
            const imageLayer = frame.contentDocument?.querySelector('.theme-background-image');
            const effective = imageLayer?.nodeType === 1 && imageLayer.ownerDocument === frame.contentDocument
                ? frame.contentWindow?.getComputedStyle(imageLayer).opacity
                : '';
            const opacity = managed?.value || effective || '0.65';
            backgroundOpacity.value = opacity;
            backgroundOpacity.disabled = !cssDraft.valid;
            backgroundOpacityOutput.textContent = `${Math.round(Number(opacity) * 100)}%`;
        }
        /**
         * Read a computed background position only when it fits the visual controls' percentage axes.
         * @param {string} value Browser-computed background-position value for the Theme image layer.
         * @returns {{x:number,y:number}|null} Integer percentage axes, or null for an unsupported value.
         */
        function visualBackgroundPositionPercentAxes(value) {
            const match = value.trim().match(/^(\d{1,3})%\s+(\d{1,3})%$/);
            if (match && Number(match[1]) <= 100 && Number(match[2]) <= 100) {
                return {x: Number(match[1]), y: Number(match[2])};
            }
            const keywords = {
                'center': {x: 50, y: 50},
                'center center': {x: 50, y: 50},
                'left': {x: 0, y: 50},
                'right': {x: 100, y: 50},
                'top': {x: 50, y: 0},
                'bottom': {x: 50, y: 100},
                'left top': {x: 0, y: 0},
                'top left': {x: 0, y: 0},
                'center top': {x: 50, y: 0},
                'top center': {x: 50, y: 0},
                'right top': {x: 100, y: 0},
                'top right': {x: 100, y: 0},
                'left center': {x: 0, y: 50},
                'center left': {x: 0, y: 50},
                'right center': {x: 100, y: 50},
                'center right': {x: 100, y: 50},
                'left bottom': {x: 0, y: 100},
                'bottom left': {x: 0, y: 100},
                'center bottom': {x: 50, y: 100},
                'bottom center': {x: 50, y: 100},
                'right bottom': {x: 100, y: 100},
                'bottom right': {x: 100, y: 100},
            };
            return keywords[value.trim().toLowerCase()] || null;
        }
        /**
         * Read a complete supported position choice without inferring an unknown axis.
         * @returns {{x:number,y:number}|null} Complete integer percentage pair, or null until both unsupported axes are explicitly supplied.
         */
        function requestedBackgroundPositionAxes() {
            const rawX = backgroundPositionNeedsExplicitPair ? backgroundPositionXNumber.value : backgroundPositionX.value;
            const rawY = backgroundPositionNeedsExplicitPair ? backgroundPositionYNumber.value : backgroundPositionY.value;
            if (rawX.trim() === '' || rawY.trim() === '') return null;
            const x = Number(rawX);
            const y = Number(rawY);
            if (!Number.isInteger(x) || !Number.isInteger(y) || x < 0 || x > 100 || y < 0 || y > 100) return null;
            return {x, y};
        }
        /**
         * Synchronize fit/position controls from the live Theme layer's computed values.
         * @returns {void} Preserves unsupported effective values visibly until the administrator chooses a supported value.
         */
        function syncThemeBackgroundPresentationControls() {
            const previewDocument = frame.contentDocument;
            const imageLayer = previewDocument?.querySelector('.theme-background-image');
            const isThemeTarget = imageLayer?.ownerDocument === previewDocument
                && imageLayer.dataset.themeBackgroundVisualEditorTarget === 'theme';
            backgroundFitGroup.hidden = !isThemeTarget;
            backgroundPositionGroup.hidden = !isThemeTarget;
            unsupportedBackgroundValue.hidden = !isThemeTarget;
            backgroundOverrideConflict.hidden = !isThemeTarget;
            if (!isThemeTarget) return;
            const computed = frame.contentWindow?.getComputedStyle(imageLayer);
            const fitRule = cssDraft.rules.find(rule => rule.scope === 'site'
                && rule.selector === 'body.public-page .theme-background-image' && rule.property === 'background-size');
            const positionRule = cssDraft.rules.find(rule => rule.scope === 'site'
                && rule.selector === 'body.public-page .theme-background-image' && rule.property === 'background-position');
            const fitValue = (computed?.backgroundSize || '').trim().toLowerCase();
            const fitSupported = fitValue === 'cover' || fitValue === 'contain';
            const positionValue = computed?.backgroundPosition || '';
            const axes = visualBackgroundPositionPercentAxes(positionValue);
            backgroundPositionNeedsExplicitPair = axes === null;
            const managedPositionAxes = positionRule ? visualBackgroundPositionPercentAxes(positionRule.value) : null;
            const hasEffectiveConflict = Boolean(fitRule && fitValue !== fitRule.value
                || positionRule && (!managedPositionAxes || !axes
                    || managedPositionAxes.x !== axes.x || managedPositionAxes.y !== axes.y));
            backgroundFit.value = fitSupported ? fitValue : '';
            backgroundFit.disabled = !cssDraft.valid;
            resetBackgroundFitButton.disabled = !cssDraft.valid || !fitRule;
            backgroundPositionX.disabled = !cssDraft.valid || !axes;
            backgroundPositionY.disabled = !cssDraft.valid || !axes;
            backgroundPositionXNumber.disabled = !cssDraft.valid;
            backgroundPositionYNumber.disabled = !cssDraft.valid;
            resetBackgroundPositionButton.disabled = !cssDraft.valid || !positionRule;
            if (axes) {
                backgroundPositionX.value = String(axes.x);
                backgroundPositionY.value = String(axes.y);
                backgroundPositionXNumber.value = String(axes.x);
                backgroundPositionYNumber.value = String(axes.y);
                backgroundPositionXOutput.textContent = `${axes.x}%`;
                backgroundPositionYOutput.textContent = `${axes.y}%`;
            } else {
                backgroundPositionXNumber.value = '';
                backgroundPositionYNumber.value = '';
                backgroundPositionXOutput.textContent = positionValue.trim() || '—';
                backgroundPositionYOutput.textContent = '';
            }
            if (!fitSupported || axes === null) {
                unsupportedBackgroundValue.textContent = label('visualEditorBackgroundValueUnsupportedLabel');
                const effective = [fitSupported ? '' : fitValue, axes ? '' : positionValue.trim()].filter(Boolean).join(' · ');
                if (effective !== '') unsupportedBackgroundValue.textContent += ` (${effective})`;
                unsupportedBackgroundValue.hidden = false;
            } else if (backgroundVisibleOwner === 'none' && pendingBackgroundOperation !== 'replace') {
                unsupportedBackgroundValue.textContent = label('visualEditorBackgroundEmptyLabel');
                unsupportedBackgroundValue.hidden = false;
            } else {
                unsupportedBackgroundValue.hidden = true;
            }
            backgroundOverrideConflict.hidden = !hasEffectiveConflict;
            backgroundOverrideConflict.textContent = label('visualEditorBackgroundOverrideConflictLabel');
        }
        /**
         * Add current CSS, page-width preference, and pending-image state as one visual editor history entry.
         * @returns {void} Starts a new branch after an earlier undo and records only real changes.
         */
        function recordVisualAction() {
            if (!cssDraft.valid) return;
            const current = visualHistory[visualHistoryIndex];
            const rules = JSON.stringify(cssDraft.rules);
            if (current?.cssHistoryIndex === cssDraft.historyIndex && current.rules === rules
                && current.backgroundFile === pendingBackgroundFile
                && current.backgroundOperation === pendingBackgroundOperation
                && current.pageWidthMode === pageWidthMode
                && current.customWidthPreference === customWidthPreference) return;
            visualHistory.splice(visualHistoryIndex + 1);
            visualHistory.push({cssHistoryIndex: cssDraft.historyIndex, rules, backgroundFile: pendingBackgroundFile,
                backgroundOperation: pendingBackgroundOperation, pageWidthMode, customWidthPreference});
            visualHistoryIndex = visualHistory.length - 1;
            undoButton.disabled = visualHistoryIndex <= 0;
            redoButton.disabled = true;
        }
        /**
         * Restore one grouped visual snapshot, including page-width state and its pending local image File.
         * @param {-1|1} direction History movement, where -1 undoes and 1 redoes.
         * @returns {void} Updates the preview and form selection without sending a request.
         */
        function restoreVisualHistory(direction) {
            const nextIndex = visualHistoryIndex + direction;
            const snapshot = visualHistory[nextIndex];
            if (!snapshot) return;
            if (!restoreBackgroundDraft(snapshot.backgroundFile, snapshot.backgroundOperation, false)) {
                if (backgroundStatus) backgroundStatus.textContent = label('visualEditorBackgroundFileInvalidLabel');
                return;
            }
            pageWidthMode = snapshot.pageWidthMode || entryPageWidthMode;
            customWidthPreference = snapshot.customWidthPreference || entryCustomWidthPreference;
            pageWidthModeWasSelected = true;
            while (cssDraft.historyIndex > snapshot.cssHistoryIndex) cssDraft = undoVisualCssDraft(cssDraft);
            while (cssDraft.historyIndex < snapshot.cssHistoryIndex) cssDraft = redoVisualCssDraft(cssDraft);
            visualHistoryIndex = nextIndex;
            applyManagedCssDraft();
        }
        recordActiveVisualAction = recordVisualAction;
        widthMode.value = pageWidthMode;
        customWidth.value = customWidthPreference;
        syncThemeWidthControls();
        entryPageWidthMode = pageWidthMode;
        entryCustomWidthPreference = customWidthPreference;
        visualHistory[0].pageWidthMode = pageWidthMode;
        visualHistory[0].customWidthPreference = customWidthPreference;
        const exitButton = visualEditorButton(label('visualEditorCancelExitLabel'), requestWorkspaceExit);
        exitButton.dataset.visualEditorCancelExit = '1';

        hud.append(collapseButton, heading, pageTitle, homeButton, backButton, signedInButton, anonymousButton, ...presetButtons,
            widthGroup, backgroundFileButton, clearPendingBackgroundButton, removeBackgroundButton, backgroundOpacityGroup, resetBackgroundOpacityButton,
            backgroundFitGroup, backgroundPositionGroup, unsupportedBackgroundValue, backgroundOverrideConflict,
            status, draftWarning, undoButton, redoButton, resetSessionButton, applyButton, exitButton, exitPrompt);
        if (workspaceStyles.dataset.loadFailed === '1') applyVisualEditorStyleFallback(dialog, hud, previewShell, frame);
        workspaceStyles.addEventListener('error', () => {
            if (dialog.isConnected) applyVisualEditorStyleFallback(dialog, hud, previewShell, frame);
        }, {once: true});
        dialog.append(hud, previewShell, resizeOverlay, inspector, hoverTip);
        document.body.append(dialog);

        /**
         * Navigate only to a server-marked same-origin public homepage or gallery route.
         * @param {URL} candidate Candidate destination URL.
         * @param {boolean} remember Whether to add the current preview location to HUD history.
         * @returns {void} Loads a validated destination into the isolated frame.
         */
        function navigate(candidate, remember) {
            const safeUrl = visualPreviewUrl(candidate.href, mountRoot);
            if (!safeUrl) return;
            if (remember && currentUrl.href !== safeUrl.href) history.push(new URL(currentUrl.href));
            currentUrl = safeUrl;
            previewLoading = true;
            clearInspection();
            status.textContent = label('visualEditorLoadingLabel');
            frame.src = safeUrl.href;
            updateControls();
        }

        /**
         * Switch server-rendered audience visibility without changing the public route.
         * @param {boolean} anonymous Whether to request anonymous visitor visibility.
         * @returns {void} Reloads the current allowlisted public page with its audience marker.
         */
        function setAudience(anonymous) {
            if (activeAudience.anonymous === anonymous) return;
            activeAudience.anonymous = anonymous;
            navigate(visualAudienceUrl(currentUrl, anonymous), true);
        }

        /**
         * Synchronize HUD state with the current route and its available navigation history.
         * @returns {void} Updates audience selection, Home/Back availability and the page label.
         */
        function updateControls() {
            homeButton.disabled = currentUrl.href === visualAudienceUrl(homeUrl, activeAudience.anonymous).href;
            backButton.disabled = history.length === 0;
            signedInButton.setAttribute('aria-pressed', activeAudience.anonymous ? 'false' : 'true');
            anonymousButton.setAttribute('aria-pressed', activeAudience.anonymous ? 'true' : 'false');
            pageTitle.textContent = currentUrl.searchParams.get('page') === 'home' ? label('visualEditorHomeLabel') : label('visualEditorTitle');
        }

        /**
         * Render a stable locator, structural ancestor controls, and computed appearance for one target.
         * @param {Element} target Selected element from the current scriptless public document.
         * @param {boolean} isBackground Whether this target represents intentional page background space.
         * @returns {void} Updates parent-owned inspection controls without editing the CSS draft.
         */
        function inspectTarget(target, isBackground = false) {
            const locator = visualStableSelector(target);
            const profile = visualElementProfile(target);
            const managedSelector = visualManagedSelector(target, locator.selector);
            const managedMatchCount = target.ownerDocument.querySelectorAll(managedSelector).length;
            const canonicalIdentity = visualCanonicalIdentitySelector(target);
            const uniqueManagedSelector = canonicalIdentity
                ? visualManagedSelector(target, canonicalIdentity)
                : null;
            selectedTarget = {
                element: target,
                selector: managedSelector,
                count: managedMatchCount,
                uniqueSelector: uniqueManagedSelector
                    && target.ownerDocument.querySelectorAll(uniqueManagedSelector).length === 1
                    ? uniqueManagedSelector
                    : null,
                profile,
            };
            syncResizeHandles();
            inspector.hidden = false;
            inspector.dataset.mode = 'inspect';
            inspectorTitle.textContent = isBackground ? label('visualEditorBackgroundLabel') : visualElementLabel(target);
            selectorText.textContent = managedSelector;
            matchCount.textContent = `${label('visualEditorMatchesLabel')}: ${managedMatchCount}`;
            styleButton.disabled = !cssDraft.valid;
            styleButton.setAttribute('aria-pressed', 'false');
            propertiesPanel.hidden = true;
            profileTitle.textContent = label(`visualEditorProfile${profile.key[0].toUpperCase()}${profile.key.slice(1)}Label`);
            targetScope.replaceChildren();
            const allOption = document.createElement('option');
            allOption.value = 'all';
            allOption.textContent = label('visualEditorAllMatchesLabel');
            targetScope.append(allOption);
            if (selectedTarget.uniqueSelector) {
                const uniqueOption = document.createElement('option');
                uniqueOption.value = 'only';
                uniqueOption.textContent = label('visualEditorOnlyThisLabel');
                targetScope.append(uniqueOption);
            } else {
                const unavailableOption = document.createElement('option');
                unavailableOption.value = 'only';
                unavailableOption.disabled = true;
                unavailableOption.textContent = label('visualEditorOnlyUnavailableLabel');
                targetScope.append(unavailableOption);
            }
            targetScope.value = 'all';
            cssWarning.textContent = label('visualEditorSpecificityWarningLabel');
            computedSummary.replaceChildren();
            const computed = frame.contentWindow?.getComputedStyle(target);
            if (computed) {
                for (const [name, value] of [
                    ['color', computed.color],
                    ['background-color', computed.backgroundColor],
                    ['font-size', computed.fontSize],
                    ['font-weight', computed.fontWeight],
                ]) {
                    const term = document.createElement('dt');
                    term.textContent = name;
                    const detail = document.createElement('dd');
                    detail.textContent = value;
                    computedSummary.append(term, detail);
                }
            }
            computedSummary.hidden = true;
            ancestors.replaceChildren();
            let ancestor = target;
            while (ancestor && ancestor !== target.ownerDocument.documentElement) {
                if (ancestor.nodeType === 1 && ancestor !== target.ownerDocument.body) {
                    const selectedAncestor = ancestor;
                    ancestors.append(visualEditorButton(visualElementLabel(selectedAncestor), () => {
                        target.removeAttribute('data-visual-selected');
                        selectedAncestor.setAttribute('data-visual-selected', '');
                        inspectTarget(selectedAncestor);
                    }));
                }
                ancestor = ancestor.parentElement;
            }
            const destination = visualAnchorDestination(target, mountRoot);
            followButton.hidden = !destination.url;
            followButton.dataset.destination = destination.url?.href || '';
            followMessage.textContent = destination.reason !== 'unlinked' ? label('visualEditorFollowUnavailableLabel') : '';
            positionInspector(target);
        }

        /**
         * Keep the inspector near its selected target while respecting viewport bounds.
         * @param {Element} target Selected public element.
         * @returns {void} Sets parent-coordinate position for the floating inspector.
         */
        function positionInspector(target) {
            const rect = target.getBoundingClientRect();
            const frameRect = frame.getBoundingClientRect();
            const left = Math.max(8, Math.min(window.innerWidth - 360, frameRect.left + rect.left));
            const below = frameRect.top + rect.bottom + 10;
            const top = below + 220 < window.innerHeight ? below : Math.max(8, frameRect.top + rect.top - 230);
            inspector.style.left = `${left}px`;
            inspector.style.top = `${top}px`;
        }

        /**
         * Position the lightweight hover tooltip at the inspected iframe target.
         * @param {Element} target Element currently under the pointer in the public document.
         * @returns {void} Shows a structural selector and match count beside the target.
         */
        function positionHoverTip(target) {
            const locator = visualStableSelector(target);
            const rect = target.getBoundingClientRect();
            const frameRect = frame.getBoundingClientRect();
            hoverTip.textContent = `${locator.selector} · ${label('visualEditorMatchesLabel')}: ${locator.count}`;
            hoverTip.hidden = false;
            hoverTip.style.left = `${Math.max(8, Math.min(window.innerWidth - 360, frameRect.left + rect.left))}px`;
            hoverTip.style.top = `${Math.max(8, Math.min(window.innerHeight - 60, frameRect.top + rect.bottom + 6))}px`;
        }

        /**
         * Clear selected-document references before a new public response is inspected.
         * @returns {void} Hides stale context controls and invalidates Follow Link state.
         */
        function clearInspection() {
            frame.contentDocument?.querySelector('[data-visual-selected]')?.removeAttribute('data-visual-selected');
            clearResizeHandles();
            selectedTarget = null;
            inspector.hidden = true;
            inspector.dataset.mode = '';
            followButton.hidden = true;
            followButton.dataset.destination = '';
            styleButton.disabled = true;
            styleButton.setAttribute('aria-pressed', 'false');
            ancestors.replaceChildren();
            propertiesPanel.hidden = true;
            propertyControls.replaceChildren();
            hoverTip.hidden = true;
        }

        /**
         * Return the selected stable selector for the current all-matches or unique-element mode.
         * @returns {string} Selector explicitly approved by the current selection state.
         */
        function selectedCssSelector() {
            if (!selectedTarget) return '';
            if (targetScope.value === 'only' && selectedTarget.uniqueSelector) return selectedTarget.uniqueSelector;
            return selectedTarget.selector;
        }

        /**
         * Remove the currently selected element's pointer handles and transient resize listeners.
         * @returns {void} Restores the parent overlay and leaves no active resize transaction.
         */
        function clearResizeHandles() {
            resizeHandleCleanup();
            resizeHandleCleanup = () => {};
        }

        /**
         * Ensure the inspected element still belongs to the currently loaded public response.
         * @returns {boolean} True for a connected iframe element, otherwise clears stale controls and reports it.
         */
        function selectedTargetIsCurrent() {
            if (!selectedTarget) return false;
            const previewDocument = frame.contentDocument;
            if (selectedTarget.element.isConnected && selectedTarget.element.ownerDocument === previewDocument) return true;
            clearInspection();
            status.textContent = label('visualEditorSelectionUnavailableLabel');
            return false;
        }

        /**
         * Attach the allowed accessible resize edges for the current selected public target.
         * @returns {void} Creates profile-approved handles whose completed drag edits one managed history step.
         */
        function syncResizeHandles() {
            clearResizeHandles();
            if (!selectedTargetIsCurrent() || !cssDraft.valid) return;
            const target = selectedTarget.element;
            const selector = selectedCssSelector();
            if (!classifyVisualCssResizeProfile(target).eligible) return;
            const previousStatus = status.textContent;
            resizeHandleCleanup = attachVisualCssResizeHandle({
                overlay: resizeOverlay,
                frame,
                target,
                selector,
                scope: propertyScope.value,
                labels: {
                    top: label('visualEditorResizeTopLabel'),
                    bottom: label('visualEditorResizeBottomLabel'),
                    left: label('visualEditorResizeLeftLabel'),
                    right: label('visualEditorResizeRightLabel'),
                    topLeft: label('visualEditorResizeTopLeftLabel'),
                    topRight: label('visualEditorResizeTopRightLabel'),
                    bottomLeft: label('visualEditorResizeBottomLeftLabel'),
                    bottomRight: label('visualEditorResizeBottomRightLabel'),
                },
                onPreview: () => {
                    status.textContent = label('visualEditorResizingLabel');
                },
                onCommit: changes => {
                    cssDraft = applyVisualCssDraftChanges(cssDraft, changes);
                    recordVisualAction();
                    applyManagedCssDraft();
                },
                onCancel: () => {
                    status.textContent = previousStatus;
                },
            });
        }

        /**
         * Keep the visible CSS selector and match count aligned with the explicit target scope.
         * @returns {void} Reflects the exact selector that the next managed property edit will persist.
         */
        function updateSelectedSelectorDisplay() {
            if (!selectedTargetIsCurrent()) return;
            const selector = selectedCssSelector();
            selectorText.textContent = selector;
            matchCount.textContent = `${label('visualEditorMatchesLabel')}: ${frame.contentDocument?.querySelectorAll(selector).length || 0}`;
        }

        /**
         * Rebuild the bounded property controls from effective and managed values for the active profile.
         * @returns {void} Shows only model-supported controls and their per-property reset actions.
         */
        function renderPropertyControls() {
            propertyControls.replaceChildren();
            if (!selectedTargetIsCurrent() || !cssDraft.valid) return;
            const selector = selectedCssSelector();
            const computed = frame.contentWindow?.getComputedStyle(selectedTarget.element);
            for (const property of selectedTarget.profile.properties) {
                const fieldset = document.createElement('fieldset');
                fieldset.className = 'theme-visual-editor-property';
                const legend = document.createElement('legend');
                legend.textContent = property === 'text-decoration'
                    ? label('visualEditorTextDecorationLabel')
                    : property === 'backdrop-filter'
                        ? label('visualEditorBackdropFilterLabel')
                        : property === '-webkit-backdrop-filter'
                            ? label('visualEditorWebkitBackdropFilterLabel')
                            : property;
                const effectiveLabel = document.createElement('span');
                effectiveLabel.textContent = `${label('visualEditorEffectiveValueLabel')}: `;
                const effectiveValue = document.createElement('code');
                effectiveValue.textContent = computed?.getPropertyValue(property).trim() || label('visualEditorValueUnavailableLabel');
                effectiveLabel.append(effectiveValue);
                const authoredLabel = document.createElement('span');
                authoredLabel.textContent = `${label('visualEditorManagedValueLabel')}: `;
                const currentRule = cssDraft.rules.find(rule => rule.scope === propertyScope.value
                    && rule.selector === selector && rule.property === property);
                const authoredValue = document.createElement('code');
                authoredValue.textContent = currentRule?.value || label('visualEditorNoManagedOverrideLabel');
                authoredLabel.append(authoredValue);
                const valueInput = property === 'text-decoration' ? document.createElement('select') : document.createElement('input');
                if (property === 'text-decoration') {
                    const noOverride = document.createElement('option');
                    noOverride.value = '';
                    noOverride.textContent = label('visualEditorNoManagedOverrideLabel');
                    valueInput.append(noOverride);
                    for (const value of ['none', 'underline', 'overline', 'line-through']) {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        valueInput.append(option);
                    }
                } else {
                    valueInput.type = 'text';
                    valueInput.autocomplete = 'off';
                    valueInput.spellcheck = false;
                    valueInput.placeholder = label('visualEditorCssValuePlaceholderLabel');
                }
                valueInput.value = currentRule?.value || '';
                valueInput.setAttribute('aria-label', `${legend.textContent} · ${label('visualEditorCssValueLabel')}`);
                valueInput.disabled = !cssDraft.valid;
                const colorProperty = ['color', 'background-color', 'border-color'].includes(property);
                let colorPicker = null;
                if (colorProperty) {
                    const initialColor = currentRule?.value || computed?.getPropertyValue(property).trim() || '';
                    colorPicker = document.createElement('input');
                    colorPicker.type = 'color';
                    colorPicker.value = visualPickerHex(initialColor) || '#000000';
                    colorPicker.setAttribute('aria-label', `${property} · ${label('visualEditorChooseColorLabel')}`);
                    colorPicker.disabled = !cssDraft.valid;
                    const opacityLabel = document.createElement('label');
                    opacityLabel.className = 'theme-visual-editor-opacity';
                    opacityLabel.textContent = label('visualEditorOpacityLabel');
                    const opacity = document.createElement('input');
                    opacity.type = 'range';
                    opacity.min = '0';
                    opacity.max = '100';
                    opacity.step = '1';
                    opacity.value = String(Math.round(visualPickerAlpha(initialColor) * 100));
                    opacity.setAttribute('aria-label', label('visualEditorOpacityLabel'));
                    opacity.disabled = !cssDraft.valid;
                    opacityLabel.append(opacity);
                    const updatePickedColor = () => {
                        valueInput.value = visualPickerColorValue(colorPicker.value, Number(opacity.value) / 100);
                        changeDraftProperty(property, valueInput.value);
                    };
                    colorPicker.addEventListener('change', () => {
                        updatePickedColor();
                    });
                    opacity.addEventListener('change', updatePickedColor);
                    fieldset.append(colorPicker, opacityLabel);
                }
                valueInput.addEventListener('change', () => {
                    if (property === 'text-decoration' && valueInput.value === '') {
                        resetButton.click();
                        return;
                    }
                    changeDraftProperty(property, valueInput.value);
                });
                const resetButton = visualEditorButton(label('visualEditorResetPropertyLabel'), () => {
                    if (!selectedTargetIsCurrent()) return;
                    const scope = propertyScope.value;
                    const changes = [{scope, selector, property, value: null}];
                    if (property === 'background-color' && selectedTarget.element.matches('.hero')) {
                        const pseudoSelector = `${selector}::before`;
                        const companion = cssDraft.rules.find(rule => rule.scope === scope
                            && rule.selector === pseudoSelector && rule.property === 'background-image');
                        if (companion?.companionOf?.scope === scope
                            && companion.companionOf.selector === selector
                            && companion.companionOf.property === 'background-color') {
                            changes.push({scope, selector: pseudoSelector, property: 'background-image', value: null});
                        }
                    }
                    cssDraft = applyVisualCssDraftChanges(cssDraft, changes);
                    recordVisualAction();
                    applyManagedCssDraft();
                });
                resetButton.disabled = !currentRule || !cssDraft.valid;
                fieldset.append(legend, effectiveLabel, authoredLabel);
                fieldset.append(valueInput, resetButton);
                propertyControls.append(fieldset);
            }
        }

        /**
         * Validate and commit one deliberate property change to the immutable managed CSS model.
         * @param {string} property Canonical property name from the active context profile.
         * @param {string} value User-entered literal CSS value.
         * @returns {void} Adds one undo snapshot and reapplies the serialized managed draft in the iframe.
         */
        function changeDraftProperty(property, value) {
            if (!selectedTargetIsCurrent() || !cssDraft.valid) return;
            const validation = visualCssDraftPropertyValueIsValid(property, value);
            if (!validation.valid) {
                draftWarning.textContent = validation.reason === 'value_unsafe'
                    ? label('visualEditorManualOnlyLabel')
                    : label('visualEditorInvalidValueLabel');
                return;
            }
            const scope = propertyScope.value;
            const selector = selectedCssSelector();
            const changes = [{
                scope,
                selector,
                property,
                value: validation.value,
            }];
            let independentOverlay = false;
            if (property === 'background-color' && selectedTarget.element.matches('.hero')) {
                const pseudoSelector = `${selector}::before`;
                const existingPseudo = cssDraft.rules.find(rule => rule.scope === scope
                    && rule.selector === pseudoSelector && rule.property === 'background-image');
                const linkedCompanion = existingPseudo?.companionOf?.scope === scope
                    && existingPseudo.companionOf.selector === selector
                    && existingPseudo.companionOf.property === 'background-color';
                if (!existingPseudo || linkedCompanion) {
                    changes.push({
                        scope,
                        selector: pseudoSelector,
                        property: 'background-image',
                        value: 'none',
                        companionOf: {scope, selector, property: 'background-color'},
                    });
                } else {
                    independentOverlay = true;
                }
            }
            cssDraft = applyVisualCssDraftChanges(cssDraft, changes);
            recordVisualAction();
            applyManagedCssDraft();
            if (independentOverlay) draftWarning.textContent = label('visualEditorIndependentOverlayLabel');
        }

        /**
         * Apply the current serialized model only inside the scriptless preview and synchronize history controls.
         * @returns {void} Leaves the authoritative textarea untouched until Apply & exit.
         */
        function applyManagedCssDraft() {
            const previewDocument = frame.contentDocument;
            if (previewDocument && cssDraft.valid) {
                let draftStyle = previewDocument.querySelector('[data-theme-visual-editor-draft]');
                if (cssDraft.css === '') {
                    draftStyle?.remove();
                } else {
                    if (!draftStyle) {
                        draftStyle = previewDocument.createElement('style');
                        draftStyle.dataset.themeVisualEditorDraft = '1';
                        previewDocument.head.append(draftStyle);
                    }
                    draftStyle.textContent = serializeVisualCssDraft(cssDraft);
                }
            }
            draftWarning.textContent = !cssDraft.valid
                ? label('visualEditorWarningMalformedLabel')
                : cssDraft.warning
                    ? label('visualEditorDraftWarningLabel')
                    : '';
            const visibleRuleCount = cssDraft.rules.filter(rule => !rule.companionOf).length;
            status.textContent = visibleRuleCount > 0
                ? `${label('visualEditorDraftUpdatedLabel')} · ${visibleRuleCount}`
                : label('visualEditorStatusLabel');
            undoButton.disabled = !cssDraft.valid || visualHistoryIndex <= 0;
            redoButton.disabled = !cssDraft.valid || visualHistoryIndex >= visualHistory.length - 1;
            applyButton.disabled = !cssDraft.valid;
            styleButton.disabled = !cssDraft.valid || !selectedTarget;
            widthMode.disabled = !cssDraft.valid;
            resetWidthButton.disabled = !cssDraft.valid;
            resetBackgroundOpacityButton.disabled = !cssDraft.valid;
            syncThemeWidthControls();
            const imageLayer = previewDocument?.querySelector('.theme-background-image');
            imageLayer?.style.removeProperty('opacity');
            imageLayer?.style.removeProperty('background-size');
            imageLayer?.style.removeProperty('background-position');
            syncBackgroundOpacityControl();
            syncThemeBackgroundPresentationControls();
            if (!propertiesPanel.hidden) renderPropertyControls();
            syncResizeHandles();
        }

        propertyScope.addEventListener('change', () => {
            syncResizeHandles();
            renderPropertyControls();
        });
        targetScope.addEventListener('change', () => {
            updateSelectedSelectorDisplay();
            syncResizeHandles();
            renderPropertyControls();
        });

        const repositionContext = () => {
            updateEffectivePageWidthOutput();
            const previewDocument = frame.contentDocument;
            if (selectedTarget) selectedTargetIsCurrent();
            const selected = previewDocument?.querySelector('[data-visual-selected]');
            const hovered = previewDocument?.querySelector('[data-visual-hover]');
            if (selected && !inspector.hidden) positionInspector(selected);
            if (hovered && !hoverTip.hidden) positionHoverTip(hovered);
        };
        window.addEventListener('resize', repositionContext);

        /**
         * Exit the preview and restore the editor caret and page scroll position.
         * @returns {void} Removes the temporary frame and returns focus to the original CSS draft.
         */
        function closeWorkspace() {
            if (!dialog.isConnected) return;
            if (dialog.open) dialog.close();
            dialog.remove();
            widthOutput.textContent = `${effectiveWidthLabel}: ${label('visualEditorValueUnavailableLabel')}`;
            if (backgroundFileInput instanceof HTMLInputElement && backgroundStatus instanceof HTMLElement) {
                backgroundStatus.before(backgroundFileInput);
                backgroundFileInput.classList.remove('theme-visual-editor-file-input');
            }
            clearBackgroundOperationPreview(activeBackgroundFrame);
            activeBackgroundFrame = null;
            backgroundVisualTarget = 'theme';
            backgroundVisibleOwner = root.dataset.visualEditorBackgroundAvailable === '1' ? 'theme_image' : 'none';
            backgroundVisibleMode = root.dataset.visualEditorBackgroundAvailable === '1' ? 'theme_image' : 'none';
            renderBackgroundReview();
            clearResizeHandles();
            resizeOverlay.remove();
            window.removeEventListener('resize', repositionContext);
            backdropProtection.remove();
            document.querySelector('[data-theme-visual-editor-backdrop-fallback]')?.remove();
            recordActiveVisualAction = () => {};
            text.focus({preventScroll: true});
            text.setSelectionRange(entrySelection.start, entrySelection.end);
            window.scrollTo(entryViewport.x, entryViewport.y);
        }

        /**
         * Discard only this visual session and restore the exact CSS bytes captured at entry.
         * @returns {void} Restores the source textarea before leaving the workspace.
         */
        function cancelWorkspace() {
            if (!restoreBackgroundDraft(entryBackgroundFile, entryBackgroundOperation)) {
                if (backgroundStatus) backgroundStatus.textContent = label('visualEditorBackgroundFileInvalidLabel');
                return;
            }
            if (text.value !== entryCss) {
                text.value = entryCss;
                text.dispatchEvent(new Event('input', {bubbles: true}));
            }
            closeWorkspace();
        }

        /**
         * Compare the managed CSS and pending local image with their exact session-entry snapshots.
         * @returns {boolean} True when Apply or Discard would change the entry draft.
         */
        function visualSessionIsDirty() {
            return serializeVisualCssDraft(cssDraft) !== entryCss || pendingBackgroundFile !== entryBackgroundFile
                || pendingBackgroundOperation !== entryBackgroundOperation;
        }

        /**
         * Request an exit while preserving the current visual draft until the user chooses an action.
         * @returns {void} Opens the Apply, Discard, or Continue editing prompt only for a changed session.
         */
        function requestWorkspaceExit() {
            if (!visualSessionIsDirty()) {
                cancelWorkspace();
                return;
            }
            exitPrompt.hidden = false;
            exitPromptContinue.focus({preventScroll: true});
        }

        /**
         * Return focus to a visible control after closing the exit choices.
         * @returns {void} Focuses the HUD expander when the remaining controls are collapsed.
         */
        function focusWorkspaceExitControl() {
            const target = hud.dataset.collapsed === '1' ? collapseButton : exitButton;
            target.focus({preventScroll: true});
        }

        frame.addEventListener('load', () => {
            previewLoading = false;
            updateEffectivePageWidthOutput();
            backgroundVisualTarget = 'unknown';
            backgroundVisibleOwner = 'none';
            backgroundVisibleMode = 'none';
            renderBackgroundReview();
            try {
                const refusal = frame.contentDocument?.querySelector('[data-visual-preview-blocked]');
                if (refusal) {
                    clearInspection();
                    status.textContent = refusal.dataset.visualPreviewBlocked === 'import'
                        ? label('visualEditorImportUnsupportedLabel')
                        : label('visualEditorInspectionUnavailableLabel');
                    pageTitle.textContent = label('visualEditorTitle');
                    return;
                }
                let loadedUrl = null;
                try {
                    loadedUrl = visualPreviewUrl(frame.contentWindow.location.href, mountRoot);
                } catch {
                    loadedUrl = null;
                }
                if (!loadedUrl) {
                    clearInspection();
                    status.textContent = label('visualEditorNavigationBlockedLabel');
                    pageTitle.textContent = label('visualEditorTitle');
                    if (frame.getAttribute('src') !== 'about:blank') frame.src = 'about:blank';
                    return;
                }
                const anonymousFallbackNotice = loadedUrl.searchParams.get('visual_notice') === 'anonymous_fallback';
                if (anonymousFallbackNotice) {
                    loadedUrl.searchParams.delete('visual_notice');
                    frame.contentWindow.history.replaceState(null, '', loadedUrl.href);
                }
                const previewDocument = frame.contentDocument;
                if (!previewDocument) throw new Error('The preview document is unavailable.');
                syncBackgroundOperationPreview(frame);
                renderBackgroundReview();
                currentUrl = loadedUrl;
                clearInspection();
                const previousDraft = previewDocument.querySelector('[data-theme-visual-editor-draft]');
                previousDraft?.remove();
                if (text.value !== '') {
                    const draftStyle = previewDocument.createElement('style');
                    draftStyle.dataset.themeVisualEditorDraft = '1';
                    draftStyle.textContent = text.value;
                    previewDocument.head.append(draftStyle);
                }
                const inspectionStyle = previewDocument.createElement('style');
                inspectionStyle.dataset.themeVisualEditorInspection = '1';
                inspectionStyle.textContent = '[data-visual-hover]{outline:2px solid #3b82f6!important;outline-offset:2px!important}[data-visual-selected]{outline:3px solid #f97316!important;outline-offset:3px!important}';
                previewDocument.head.append(inspectionStyle);
                const titleText = previewDocument.title || previewDocument.querySelector('h1')?.textContent?.trim();
                pageTitle.textContent = titleText || label('visualEditorHomeLabel');
                status.textContent = anonymousFallbackNotice
                    ? label('visualEditorAnonymousFallbackNoticeLabel')
                    : label('visualEditorStatusLabel');
                previewDocument.addEventListener('pointerover', event => {
                    if (previewLoading) return;
                    const target = event.target && event.target.nodeType === 1 ? event.target : null;
                    if (target && target !== previewDocument.body) {
                        target.setAttribute('data-visual-hover', '');
                        positionHoverTip(target);
                    }
                }, true);
                previewDocument.addEventListener('pointerout', event => {
                    if (previewLoading) return;
                    const target = event.target && event.target.nodeType === 1 ? event.target : null;
                    target?.removeAttribute('data-visual-hover');
                    if (target && (!event.relatedTarget || !target.contains(event.relatedTarget))) hoverTip.hidden = true;
                }, true);
                previewDocument.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    if (previewLoading) return;
                    const clicked = event.target && event.target.nodeType === 1 ? event.target : null;
                    if (!clicked || clicked === previewDocument.documentElement) return;
                    const target = clicked.closest('body,main,.site-main,.gallery-page,[data-gallery-card],img,section,article,header,footer,nav,div,p,h1,h2,h3,a,button') || clicked;
                    const blankBackground = clicked === target && (target.localName === 'body' || target.localName === 'main'
                        || target.matches('.site-main,.gallery-page,[data-gallery-background]'));
                    previewDocument.querySelector('[data-visual-selected]')?.removeAttribute('data-visual-selected');
                    target.setAttribute('data-visual-selected', '');
                    inspectTarget(target, blankBackground);
                }, true);
                previewDocument.addEventListener('submit', event => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }, true);
                previewDocument.addEventListener('dragstart', event => {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }, true);
                previewDocument.addEventListener('keydown', event => {
                    if (previewLoading) return;
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        if (!inspector.hidden || !hoverTip.hidden) clearInspection();
                        else requestWorkspaceExit();
                        return;
                    }
                    if ((event.key === 'Enter' || event.key === ' ') && event.target && event.target.nodeType === 1
                        && event.target !== previewDocument.body && event.target !== previewDocument.documentElement) {
                        event.preventDefault();
                        previewDocument.querySelector('[data-visual-selected]')?.removeAttribute('data-visual-selected');
                        event.target.setAttribute('data-visual-selected', '');
                        inspectTarget(event.target);
                    }
                }, true);
                previewDocument.addEventListener('scroll', () => {
                    if (previewLoading) return;
                    const selected = previewDocument.querySelector('[data-visual-selected]');
                    if (selected && !inspector.hidden) positionInspector(selected);
                    const hovered = previewDocument.querySelector('[data-visual-hover]');
                    if (hovered && !hoverTip.hidden) positionHoverTip(hovered);
                }, true);
                applyManagedCssDraft();
                if (anonymousFallbackNotice) status.textContent = label('visualEditorAnonymousFallbackNoticeLabel');
            } catch {
                status.textContent = label('visualEditorStatusLabel');
                pageTitle.textContent = label('visualEditorTitle');
            }
        });
        dialog.addEventListener('cancel', event => {
            event.preventDefault();
            if (!inspector.hidden || !hoverTip.hidden) {
                clearInspection();
                exitButton.focus({preventScroll: true});
                return;
            }
            if (!exitPrompt.hidden) {
                exitPrompt.hidden = true;
                focusWorkspaceExitControl();
                return;
            }
            requestWorkspaceExit();
        });
        dialog.addEventListener('close', closeWorkspace, {once: true});
        dialog.showModal();
        applyManagedCssDraft();
        frame.src = currentUrl.href;
        updateControls();
        exitButton.focus({preventScroll: true});
    });
    launch.dataset.visualEditorReady = '1';
    setThemeVisualEditorAvailability(root, '');
}
