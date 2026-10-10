/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-public-widgets.js
 * Module Type: Browser Module
 * Purpose: Enhance widget Markdown authoring and viewport placement without client-side persistence.
 * Responsibilities: Keep native form actions functional without JavaScript; use safe server preview.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {
    FLOAT_LIMIT,
    publicWidgetBoundedNumber,
    readPublicWidgetFloatingGeometry,
    resolvePublicWidgetFloatingRect,
} from './public-content-widgets.js?v=20261011-widget-geometry-preview-v1';

/**
 * Replace only the selected Markdown text with an intentionally simple formatting token.
 *
 * @param {HTMLTextAreaElement} editor Bounded authored content field.
 * @param {string} command Whitelisted formatting command.
 * @returns {void} Inserts Markdown and restores keyboard focus.
 */
function formatWidgetMarkdown(editor, command) {
    const begin = editor.selectionStart;
    const end = editor.selectionEnd;
    const selected = editor.value.slice(begin, end);
    const fallback = selected || 'text';
    const replacements = {
        bold: () => '**' + fallback + '**',
        italic: () => '*' + fallback + '*',
        heading: () => '## ' + (selected || 'Heading'),
        list: () => (selected || 'List item').split('\n').map((line) => '- ' + line).join('\n'),
        ordered: () => (selected || 'List item').split('\n').map((line, index) => String(index + 1) + '. ' + line).join('\n'),
        link: () => '[' + (selected || 'Link text') + '](https://example.org)',
    };
    if (!Object.prototype.hasOwnProperty.call(replacements, command)) return;
    const value = replacements[command]();
    editor.setRangeText(value, begin, end, 'select');
    editor.dispatchEvent(new Event('input', { bubbles: true }));
    editor.focus();
}

/**
 * Normalize pointer coordinates in a bounded editor-only viewport stage.
 *
 * Pointer movement is never persisted by this helper. The form remains the
 * only source of publication authority after explicit user Save.
 *
 * @param {number} clientX Pointer client X in CSS pixels.
 * @param {number} clientY Pointer client Y in CSS pixels.
 * @param {{left:number,top:number,width:number,height:number}} bounds Current preview stage rectangle.
 * @returns {{x:number,y:number}|null} Bounded 0..1000 coordinates or null on missing viewport geometry.
 */
export function publicWidgetPointerPosition(clientX, clientY, bounds) {
    if (![clientX, clientY, bounds.left, bounds.top, bounds.width, bounds.height].every(Number.isFinite)
        || bounds.width <= 0 || bounds.height <= 0) {
        return null;
    }
    const clamp = (value) => Math.min(1000, Math.max(0, Math.round(value)));
    return {
        x: clamp(1000 * (clientX - bounds.left) / bounds.width),
        y: clamp(1000 * (clientY - bounds.top) / bounds.height),
    };
}

/**
 * Illustrative 0..1000 anchor points shared by the Admin map and risk advisor.
 * Type: Record<string,[number,number]>. Units: normalized viewport permille.
 * Scope: authenticated Admin only. Consumers: stage marker and warnings.
 * Rationale: warn consistently without pretending to know public widget heights.
 */
const PUBLIC_WIDGET_PREVIEW_ANCHORS = Object.freeze({
    'top-left': [90, 100], 'top-center': [500, 100], 'top-right': [910, 100],
    'middle-left': [90, 500], 'middle-right': [910, 500],
    'bottom-left': [90, 900], 'bottom-center': [500, 900], 'bottom-right': [910, 900],
});

/**
 * Identify advisory Admin placement risks without predicting exact Theme geometry.
 *
 * The server provides metadata for published widgets only. Scope and self-ID
 * filtering prevent unrelated pages and the saved editor record from generating
 * false collisions. Real public placement still owns bounds and layering.
 *
 * @param {{mode:string,anchor:string,x:number,y:number,device:string,page:string,widgetId:string}} draft Unsaved placement and selected illustrative viewport.
 * @param {Array<{id:string,scope:string,anchor:string,x:number,y:number,width:number}>} peers Server-validated, published floating placements.
 * @returns {string[]} Stable translation suffixes for advisory warnings.
 */
export function publicWidgetPlacementWarnings(draft, peers) {
    if (draft.mode !== 'floating') return [];
    if (draft.device !== 'desktop') return ['fallback'];
    const warnings = [];
    const x = Number(draft.x);
    const y = Number(draft.y);
    if (!Number.isInteger(x) || !Number.isInteger(y) || x < 0 || x > 1000 || y < 0 || y > 1000) {
        warnings.push('invalid');
    }
    if (draft.anchor.startsWith('top') || (draft.anchor === 'custom' && y < 220)) {
        warnings.push('header');
    }
    if (draft.anchor === 'custom' && (x < 60 || x > 940 || y < 60 || y > 940)) {
        warnings.push('edge');
    }
    const visiblePeers = Array.isArray(peers) ? peers.filter(peer =>
        peer && peer.id !== draft.widgetId && ['home', 'gallery', 'all'].includes(peer.scope)
        && (peer.scope === 'all' || peer.scope === draft.page)
        && ['home', 'gallery'].includes(draft.page)) : [];
    if (visiblePeers.length >= 2) warnings.push('limit');
    const position = PUBLIC_WIDGET_PREVIEW_ANCHORS[draft.anchor] || [x, y];
    const collision = visiblePeers.some(peer => {
        const other = PUBLIC_WIDGET_PREVIEW_ANCHORS[peer.anchor] || [Number(peer.x), Number(peer.y)];
        return other.every(Number.isFinite)
            && position.every(Number.isFinite)
            && Math.abs(position[0] - other[0]) < 240
            && Math.abs(position[1] - other[1]) < 260;
    });
    if (collision) warnings.push('collision');
    return warnings;
}

/**
 * Synchronize supported page zones and flow/floating settings before form submission.
 *
 * @param {HTMLFormElement} form Active widget editor.
 * @param {Record<string,string>} labels Server-provided localized accessible labels.
 * @returns {() => void} Safe refresh callback shared by preview controls.
 */
function setupWidgetPlacement(form, labels) {
    const translated = (key, fallback) => typeof labels[key] === 'string' ? labels[key] : fallback;
    const mode = form.querySelector('[name="placement_mode"]');
    const scope = form.querySelector('[name="page_scope"]');
    const zone = form.querySelector('[name="flow_slot"]');
    const anchor = form.querySelector('[name="floating_anchor"]');
    const x = form.querySelector('[name="x_permille"]');
    const y = form.querySelector('[name="y_permille"]');
    const width = form.querySelector('[name="width_px"]');
    const appearance = form.querySelector('[name="appearance"]');
    const widgetId = form.querySelector('[name="widget_id"]')?.value || '';
    let peers = [];
    try {
        const parsed = JSON.parse(form.dataset.widgetPublishedPeers || '[]');
        if (Array.isArray(parsed)) peers = parsed;
    } catch (_) {
        peers = [];
    }
    const flow = form.querySelector('[data-widget-flow-controls]');
    const floating = form.querySelector('[data-widget-floating-controls]');
    const preview = document.querySelector('[data-widget-preview]');
    const card = preview?.querySelector('.public-content-widget');
    const tools = document.createElement('section');
    tools.className = 'public-widgets-placement-tools';
    const toggle = document.createElement('div');
    toggle.className = 'public-widgets-device-toggle';
    const deviceNames = ['desktop', 'tablet', 'mobile'];
    const devices = deviceNames.map((name) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'secondary';
        button.textContent = translated(name, name.slice(0, 1).toUpperCase() + name.slice(1));
        button.setAttribute('aria-pressed', name === 'desktop' ? 'true' : 'false');
        button.addEventListener('click', () => {
            stage.dataset.device = name;
            for (const other of devices) other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            refresh();
        });
        toggle.append(button);
        return button;
    });
    const pageLabel = document.createElement('label');
    pageLabel.className = 'public-widgets-preview-page';
    pageLabel.textContent = translated('page_label', 'Placement warning page');
    const pageSelect = document.createElement('select');
    pageSelect.className = 'public-widgets-page-select';
    pageSelect.setAttribute('data-widget-preview-page-selector', '');
    for (const page of ['home', 'gallery']) {
        const option = document.createElement('option');
        option.value = page;
        option.textContent = translated('page_' + page, page === 'home' ? 'Homepage' : 'Gallery page');
        pageSelect.append(option);
    }
    pageLabel.append(pageSelect);
    const stage = document.createElement('div');
    stage.className = 'public-widgets-placement-stage';
    stage.dataset.device = 'desktop';
    stage.tabIndex = 0;
    stage.setAttribute('role', 'button');
    stage.setAttribute('aria-label', translated('coordinates', 'Floating widget location preview. Click or use arrow keys to select custom coordinates.'));
    const headerGuide = document.createElement('span');
    headerGuide.className = 'public-widgets-restricted-guide public-widgets-guide-header';
    headerGuide.title = translated('guide_header', 'Illustrative reserved header');
    headerGuide.setAttribute('aria-hidden', 'true');
    const controlsGuide = document.createElement('span');
    controlsGuide.className = 'public-widgets-restricted-guide public-widgets-guide-controls';
    controlsGuide.title = translated('guide_controls', 'Illustrative fixed-controls area');
    controlsGuide.setAttribute('aria-hidden', 'true');
    const peerLayer = document.createElement('div');
    peerLayer.className = 'public-widgets-peer-layer';
    peerLayer.setAttribute('aria-hidden', 'true');
    const marker = document.createElement('span');
    marker.className = 'public-widgets-position-marker';
    marker.textContent = translated('preview_marker', 'Widget');
    stage.append(headerGuide, controlsGuide, peerLayer, marker);
    const caption = document.createElement('p');
    caption.className = 'public-widgets-placement-caption';
    caption.textContent = translated('stage_hint', 'Select desktop, tablet or mobile. Click inside the dashed preview to set a custom floating location.');
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.className = 'secondary public-widgets-reset-position';
    reset.textContent = translated('reset_position', 'Reset floating position');
    const warnings = document.createElement('ul');
    warnings.className = 'public-widgets-placement-warnings';
    warnings.setAttribute('role', 'status');
    warnings.setAttribute('aria-live', 'polite');
    const disclaimer = document.createElement('p');
    disclaimer.className = 'public-widgets-placement-disclaimer';
    disclaimer.textContent = translated('warning_disclaimer', 'Illustrative safety guides only. Actual Theme geometry and widget height determine final placement.');
    tools.append(toggle, pageLabel, stage, reset, caption, warnings, disclaimer);
    floating?.insertAdjacentElement('afterend', tools);
    const clamp = (value) => Math.min(1000, Math.max(0, Math.round(Number(value) || 0)));
    const anchorCoords = PUBLIC_WIDGET_PREVIEW_ANCHORS;
    const fallbackWarnings = {
        fallback: 'This device uses the in-page fallback, not a fixed overlay.',
        header: 'This position is near the header. The public placement solver may move the panel below navigation.',
        edge: 'Custom coordinates are near a viewport edge. The actual panel will be clamped or left in page flow.',
        collision: 'Another published widget uses a nearby position on this page. The public layout may move one panel or retain it in flow.',
        limit: 'Only two published floating panels can be active per page; additional panels remain in page flow.',
        invalid: 'Choose numeric coordinates from 0 to 1000 before saving.',
    };
    let previousWarningCodes = null;
    const refresh = () => {
        const isFloating = mode?.value === 'floating';
        if (flow) flow.hidden = isFloating;
        if (floating) floating.hidden = !isFloating;
        if (!isFloating && scope?.value === 'gallery' && ['home_before_grid', 'home_after_grid'].includes(zone?.value)) zone.value = 'content_top';
        const galleryOnly = scope?.value === 'gallery';
        for (const option of Array.from(zone?.options || [])) {
            option.disabled = galleryOnly && !isFloating && ['home_before_grid', 'home_after_grid'].includes(option.value);
        }
        stage.dataset.mode = isFloating ? 'floating' : 'flow';
        reset.hidden = !isFloating;
        if (scope?.value === 'home' || scope?.value === 'gallery') pageSelect.value = scope.value;
        pageSelect.disabled = scope?.value !== 'all';
        const xy = anchorCoords[anchor?.value] || [clamp(x?.value), clamp(y?.value)];
        marker.style.left = String(xy[0] / 10) + '%';
        marker.style.top = String(xy[1] / 10) + '%';
        if (card && width) card.style.maxWidth = String(Math.min(480, Math.max(180, Number(width.value) || 320))) + 'px';
        if (card && appearance) {
            card.classList.toggle('public-content-widget--minimal', appearance.value === 'minimal');
            card.classList.toggle('public-content-widget--card', appearance.value !== 'minimal');
        }
        const desktopFloat = isFloating && stage.dataset.device === 'desktop';
        headerGuide.hidden = !desktopFloat;
        controlsGuide.hidden = !desktopFloat;
        peerLayer.hidden = !desktopFloat;
        peerLayer.replaceChildren();
        if (desktopFloat) {
            const related = peers.filter(peer => peer && peer.id !== widgetId
                && (peer.scope === 'all' || peer.scope === pageSelect.value));
            for (const peer of related.slice(0, 5)) {
                const other = document.createElement('span');
                other.className = 'public-widgets-peer-marker';
                other.title = translated('peer_label', 'Another published widget');
                const point = anchorCoords[peer.anchor] || [clamp(peer.x), clamp(peer.y)];
                other.style.left = String(point[0] / 10) + '%';
                other.style.top = String(point[1] / 10) + '%';
                peerLayer.append(other);
            }
        }
        const warningCodes = publicWidgetPlacementWarnings({
            mode: mode?.value || 'flow', anchor: anchor?.value || 'bottom-right',
            x: x?.value === '' ? NaN : Number(x?.value),
            y: y?.value === '' ? NaN : Number(y?.value),
            device: stage.dataset.device, page: pageSelect.value, widgetId,
        }, peers);
        const signature = warningCodes.join(',');
        if (signature !== previousWarningCodes) {
            previousWarningCodes = signature;
            warnings.replaceChildren();
            for (const code of warningCodes) {
                const item = document.createElement('li');
                item.textContent = translated('warning_' + code, fallbackWarnings[code]);
                warnings.append(item);
            }
        }
        caption.textContent = stage.dataset.device !== 'desktop'
            ? translated('mobile_hint', 'This device uses an accessible in-page fallback instead of a fixed overlay.')
            : isFloating ? translated('floating_hint', 'Click or use arrow keys in the preview to customize the floating position.')
                : translated('flow_hint', 'In-page widget follows normal page flow and the selected content zone.');
        form.dispatchEvent(new Event('public-widget-preview-change'));
    };
    const applyPointer = (event) => {
        if (mode?.value !== 'floating' || !x || !y || !anchor) return;
        const coordinates = publicWidgetPointerPosition(event.clientX, event.clientY, stage.getBoundingClientRect());
        if (!coordinates) return;
        x.value = String(coordinates.x);
        y.value = String(coordinates.y);
        anchor.value = 'custom';
        refresh();
    };
    let activePointer = null;
    stage.addEventListener('pointerdown', (event) => {
        if (mode?.value !== 'floating' || !event.isPrimary
            || (event.pointerType === 'mouse' && event.button !== 0)) return;
        activePointer = event.pointerId;
        stage.setPointerCapture?.(event.pointerId);
        applyPointer(event);
        event.preventDefault();
    });
    stage.addEventListener('pointermove', (event) => {
        if (activePointer !== event.pointerId) return;
        applyPointer(event);
        event.preventDefault();
    });
    const finishPointer = (event) => {
        if (activePointer !== event.pointerId) return;
        activePointer = null;
        if (stage.hasPointerCapture?.(event.pointerId)) {
            stage.releasePointerCapture(event.pointerId);
        }
    };
    stage.addEventListener('pointerup', finishPointer);
    stage.addEventListener('pointercancel', finishPointer);
    stage.addEventListener('click', (event) => {
        if (activePointer === null) applyPointer(event);
    });
    reset.addEventListener('click', () => {
        if (!anchor || !x || !y) return;
        anchor.value = 'bottom-right';
        x.value = '900';
        y.value = '900';
        refresh();
        stage.focus();
    });
    stage.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 50 : 10;
        const dx = event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
        const dy = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
        if ((!dx && !dy) || mode?.value !== 'floating') return;
        event.preventDefault();
        x.value = String(clamp((anchorCoords[anchor?.value]?.[0] ?? Number(x.value)) + dx));
        y.value = String(clamp((anchorCoords[anchor?.value]?.[1] ?? Number(y.value)) + dy));
        anchor.value = 'custom';
        refresh();
    });
    pageSelect.addEventListener('change', refresh);
    for (const input of [mode, scope, zone, anchor, x, y, width, appearance]) {
        input?.addEventListener('input', refresh);
        input?.addEventListener('change', refresh);
    }
    refresh();
    return refresh;
}


/**
 * Validate one controller-prepared, same-origin protected public-page URL.
 *
 * Only the expected Home or Gallery route plus visual-preview markers are
 * accepted. Routed Gallery URLs may carry one public_path; clean Gallery URLs
 * must remain beneath the application's mounted /gallery/ route.
 *
 * @param {string} value Server-generated protected preview URL.
 * @param {string} baseHref URL of the authenticated Admin editor.
 * @param {'home'|'gallery'} page Expected public page type.
 * @returns {URL|null} Allowed protected Home or Gallery URL, or null.
 */
export function publicWidgetProtectedPageUrl(value, baseHref, page) {
    try {
        if (typeof value !== 'string' || !value.trim() || !['home', 'gallery'].includes(page)) return null;
        const url = new URL(value, baseHref);
        const origin = new URL(baseHref);
        if (url.origin !== origin.origin || url.username || url.password || url.hash) return null;
        const basePath = origin.pathname || '/';
        const indexPath = basePath.lastIndexOf('/index.php');
        const adminPath = basePath.indexOf('/admin/');
        const mountRoot = indexPath >= 0 && indexPath + '/index.php'.length === basePath.length
            ? basePath.slice(0, indexPath + 1)
            : adminPath >= 0 ? basePath.slice(0, adminPath + 1)
                : basePath.endsWith('/') ? basePath : basePath.slice(0, basePath.lastIndexOf('/') + 1);
        const keys = [...url.searchParams.keys()];
        if (keys.some(key => url.searchParams.getAll(key).length !== 1)
            || url.searchParams.get('preview') !== 'visual'
            || url.searchParams.get('view_as') !== 'anonymous') return null;

        const routed = url.pathname === mountRoot + 'index.php' && url.searchParams.get('page') === page;
        const cleanHome = page === 'home' && url.pathname === mountRoot
            && !url.searchParams.has('page') && !url.searchParams.has('public_path');
        let cleanGallery = false;
        if (page === 'gallery' && url.pathname.startsWith(mountRoot + 'gallery/') && url.pathname.endsWith('/')) {
            const segments = url.pathname.slice((mountRoot + 'gallery/').length, -1).split('/');
            cleanGallery = segments.length > 0 && segments.every(segment => {
                if (!segment) return false;
                try {
                    const decoded = decodeURIComponent(segment);
                    return decoded !== '.' && decoded !== '..' && !/[\\/\u0000-\u001f\u007f]/.test(decoded);
                } catch (_) {
                    return false;
                }
            }) && !url.searchParams.has('page') && !url.searchParams.has('public_path');
        }
        const routedKeys = page === 'gallery'
            ? ['page', 'public_path', 'preview', 'view_as'] : ['page', 'preview', 'view_as'];
        const cleanKeys = ['preview', 'view_as'];
        if (routed && keys.every(key => routedKeys.includes(key))) {
            const publicPath = url.searchParams.get('public_path');
            if (page === 'gallery' && publicPath === null) return null;
            if (publicPath !== null && (!publicPath || /[\\\u0000-\u001f\u007f]/.test(publicPath)
                || publicPath.split('/').some(segment => !segment || segment === '.' || segment === '..'))) return null;
            return url;
        }
        if ((cleanHome || cleanGallery) && keys.every(key => cleanKeys.includes(key))) return url;
        return null;
    } catch (_) {
        return null;
    }
}

/**
 * Validate the controller-prepared same-origin, marked public Home URL.
 *
 * This compatibility entry point retains the Home-only API used by existing
 * browser fixtures while applying the stricter mounted-route allowlist.
 *
 * @param {string} value Server-generated Home preview URL.
 * @param {string} baseHref URL of the authenticated Admin editor.
 * @returns {URL|null} Allowed protected Home URL, or null.
 */
export function publicWidgetProtectedHomeUrl(value, baseHref) {
    return publicWidgetProtectedPageUrl(value, baseHref, 'home');
}

/**
 * Reconcile the actual public CSS rail mode after a transient draft move.
 *
 * Removing the only article from a rail must not leave an obsolete grid column
 * or overlap another rail. This changes only the isolated preview document.
 *
 * @param {Element} main Protected public page main content container.
 * @returns {void} Normalizes rendered public rails and their primary content.
 */
function publicWidgetReconcileRails(main) {
    const layout = main.querySelector('.public-widget-content-layout');
    if (!layout) return;
    const left = Boolean(layout.querySelector('.public-widget-region--left_rail'));
    const right = Boolean(layout.querySelector('.public-widget-region--right_rail'));
    if (!left && !right) {
        const primary = layout.querySelector('.public-widget-primary');
        if (primary) layout.replaceWith(...primary.childNodes);
        else layout.remove();
        return;
    }
    layout.classList.remove('public-widget-content-layout--left', 'public-widget-content-layout--right',
        'public-widget-content-layout--both');
    layout.classList.add('public-widget-content-layout--' + (left && right ? 'both' : left ? 'left' : 'right'));
}

/**
 * Insert the sanitized unsaved draft into the selected protected public page.
 *
 * Home and Gallery use their actual server-rendered DOM, Theme CSS, and widget
 * zones. Only the sandboxed iframe document changes; its public scripts cannot
 * execute, and the editor and persistence remain untouched. Gallery-only grid
 * slots degrade to the public content-bottom zone.
 *
 * @param {Document} doc Protected public Home or Gallery document.
 * @param {HTMLFormElement} form Current unsaved widget form.
 * @param {Element} preview Sanitized Markdown preview owned by the server.
 * @param {'home'|'gallery'} page Route selected for the iframe.
 * @param {Record<string,string>} labels Maintained browser translations.
 * @returns {HTMLElement|null} Inserted draft article, or null when the draft does not apply.
 */
function publicWidgetRenderDraft(doc, form, preview, page, labels) {
    const main = doc.querySelector('main.site-main');
    const footer = doc.querySelector('.site-footer');
    const content = preview?.querySelector('[data-widget-preview-body]');
    const scope = form.querySelector('[name="page_scope"]')?.value;
    if (!main || !footer || !content || !['home', 'gallery'].includes(page)) return null;

    const editId = form.querySelector('[name="widget_id"]')?.value || '';
    if (/^[a-f0-9]{32}$/.test(editId)) {
        for (const saved of doc.querySelectorAll('[data-public-widget-id="' + editId + '"]')) {
            const oldRegion = saved.closest('[data-public-widget-zone]');
            saved.remove();
            if (oldRegion && !oldRegion.querySelector('.public-content-widget')) oldRegion.remove();
        }
        publicWidgetReconcileRails(main);
    }
    if (scope !== 'all' && scope !== page) return null;

    const floating = form.querySelector('[name="placement_mode"]')?.value === 'floating';
    const acceptedSlots = ['content_top', 'content_bottom', 'left_rail', 'right_rail',
        'home_before_grid', 'home_after_grid', 'footer'];
    let slot = floating ? 'floating' : form.querySelector('[name="flow_slot"]')?.value || 'content_bottom';
    if (!floating && !acceptedSlots.includes(slot)) slot = 'content_bottom';
    if (page === 'gallery' && ['home_before_grid', 'home_after_grid'].includes(slot)) slot = 'content_bottom';
    const widthInput = form.querySelector('[name="width_px"]')?.value;
    const width = publicWidgetBoundedNumber(widthInput, 320, 180, 480);
    const anchor = form.querySelector('[name="floating_anchor"]')?.value || 'bottom-right';
    const x = publicWidgetBoundedNumber(form.querySelector('[name="x_permille"]')?.value, 900, 0, 1000);
    const y = publicWidgetBoundedNumber(form.querySelector('[name="y_permille"]')?.value, 900, 0, 1000);
    const appearance = form.querySelector('[name="appearance"]')?.value === 'minimal' ? 'minimal' : 'card';
    const sortOrder = publicWidgetBoundedNumber(form.querySelector('[name="sort_order"]')?.value, 0, 0, 10000);

    const article = doc.createElement('article');
    article.className = 'public-content-widget public-content-widget--' + appearance;
    if (/^[a-f0-9]{32}$/.test(editId)) article.dataset.publicWidgetId = editId;
    article.style.setProperty('--public-widget-max-width', String(width) + 'px');
    article.dataset.widgetThemeDraft = '1';
    article.dataset.publicWidgetSortOrder = String(sortOrder);
    if (floating) {
        article.dataset.publicWidgetFloating = '1';
        article.dataset.publicWidgetWidth = String(width);
        article.dataset.publicWidgetDismissLabel = labels.theme_close || 'Close';
        article.dataset.publicWidgetAnchor = anchor;
        article.dataset.publicWidgetX = String(x);
        article.dataset.publicWidgetY = String(y);
        const close = doc.createElement('button');
        close.type = 'button';
        close.className = 'public-widget-dismiss';
        close.textContent = '\u00d7';
        close.setAttribute('aria-label', article.dataset.publicWidgetDismissLabel);
        close.title = article.dataset.publicWidgetDismissLabel;
        article.append(close);
    }
    const sourceTitle = preview.querySelector('.public-content-widget-title');
    if (sourceTitle && !sourceTitle.hidden && sourceTitle.textContent) {
        const title = doc.createElement('h2');
        title.className = 'public-content-widget-title';
        title.textContent = sourceTitle.textContent;
        article.append(title);
    }
    const body = doc.createElement('div');
    body.className = 'public-content-widget-body';
    for (const child of content.childNodes) body.append(doc.importNode(child, true));
    article.append(body);

    const publishedRegion = doc.querySelector('[data-public-widget-zone="' + slot + '"]');
    if (publishedRegion) {
        const nextWidget = Array.from(publishedRegion.children).find((saved) => {
            if (!saved.hasAttribute('data-public-widget-id')) return false;
            const savedOrder = publicWidgetBoundedNumber(saved.dataset.publicWidgetSortOrder, 10000, 0, 10000);
            if (savedOrder !== sortOrder) return savedOrder > sortOrder;
            const savedId = saved.dataset.publicWidgetId || '';
            return editId ? savedId > editId : true;
        });
        publishedRegion.insertBefore(article, nextWidget || null);
        publicWidgetReconcileRails(main);
        return article;
    }
    const region = doc.createElement('section');
    region.className = 'public-widget-region public-widget-region--' + slot;
    region.dataset.widgetThemeDraftRegion = '1';
    region.dataset.publicWidgetZone = slot;
    region.append(article);
    if (slot === 'content_top') {
        main.prepend(region);
    } else if (slot === 'content_bottom' || slot === 'floating') {
        main.append(region);
    } else if (slot === 'footer') {
        footer.prepend(region);
    } else if (slot === 'home_before_grid' || slot === 'home_after_grid') {
        const list = main.querySelector('.gallery-list-content');
        const topPagination = slot === 'home_before_grid' ? list?.querySelector('nav.pagination') : null;
        const grid = slot === 'home_before_grid' ? list?.querySelector('[data-public-gallery-index-grid]') : null;
        if (slot === 'home_before_grid' && topPagination) topPagination.before(region);
        else if (slot === 'home_before_grid' && grid) grid.before(region);
        else if (list) list.append(region);
        else main.append(region);
    } else if (slot === 'left_rail' || slot === 'right_rail') {
        let layout = main.querySelector('.public-widget-content-layout');
        if (!layout) {
            layout = doc.createElement('div');
            layout.className = 'public-widget-content-layout';
            const primary = doc.createElement('div');
            primary.className = 'public-widget-primary';
            for (const node of [...main.childNodes]) {
                if (node.nodeType === 1 && node.matches(
                    '.public-widget-region--content_top,.public-widget-region--content_bottom,.public-widget-region--floating')) continue;
                primary.append(node);
            }
            layout.append(primary);
            const after = main.querySelector('.public-widget-region--content_bottom,.public-widget-region--floating');
            if (after) after.before(layout);
            else main.append(layout);
        }
        layout.append(region);
    } else {
        main.append(region);
    }
    publicWidgetReconcileRails(main);
    return article;
}

/**
 * Apply the public floating solver in the rendered widget document order.
 *
 * The same measured iframe geometry, bounds, rectangle solver, two-panel cap,
 * and document order as visitor pages are used. Unsupported viewports and
 * non-fitting boxes remain in their server-rendered flow zones.
 *
 * @param {Document} doc Protected public document containing rendered widgets.
 * @param {Window} win Window owning the iframe viewport.
 * @param {HTMLElement|null} draft Unsaved draft article, if one was inserted.
 * @returns {boolean} True when the draft becomes an actual positioned overlay.
 */
function publicWidgetApplyFloatingPreview(doc, win, draft) {
    const widgets = Array.from(doc.querySelectorAll('[data-public-widget-floating="1"]'))
        .filter(widget => widget instanceof win.HTMLElement);
    for (const widget of widgets) {
        widget.classList.remove('is-public-widget-floating');
        widget.style.removeProperty('left');
        widget.style.removeProperty('top');
        widget.style.removeProperty('width');
        if (!widget.querySelector('.public-widget-dismiss')) {
            const close = doc.createElement('button');
            close.type = 'button';
            close.className = 'public-widget-dismiss';
            close.textContent = '\u00d7';
            close.setAttribute('aria-label', widget.dataset.publicWidgetDismissLabel || 'Close');
            close.title = widget.dataset.publicWidgetDismissLabel || 'Close';
            widget.prepend(close);
        }
    }
    const geometry = readPublicWidgetFloatingGeometry(doc, win);
    if (!geometry.supported) return false;
    let visible = 0;
    let draftPositioned = false;
    const place = widget => {
        if (visible >= FLOAT_LIMIT || widget.hidden) return false;
        const persistedWidth = publicWidgetBoundedNumber(widget.dataset.publicWidgetWidth, 320, 180, 480);
        const width = Math.min(persistedWidth, geometry.viewportWidth - 32);
        widget.style.width = width + 'px';
        const height = Math.ceil(widget.getBoundingClientRect().height);
        const rect = resolvePublicWidgetFloatingRect({
            anchor: widget.dataset.publicWidgetAnchor || 'bottom-right',
            x: publicWidgetBoundedNumber(widget.dataset.publicWidgetX, 900, 0, 1000),
            y: publicWidgetBoundedNumber(widget.dataset.publicWidgetY, 900, 0, 1000),
            width, height, viewportWidth: geometry.viewportWidth,
            viewportHeight: geometry.viewportHeight, topInset: geometry.topInset,
            exclusions: geometry.exclusions,
        });
        if (rect === null) {
            widget.style.removeProperty('width');
            return false;
        }
        widget.classList.add('is-public-widget-floating');
        widget.style.left = rect.left + 'px';
        widget.style.top = rect.top + 'px';
        geometry.exclusions.push(rect);
        visible += 1;
        return true;
    };
    for (const widget of widgets) {
        if (place(widget) && widget === draft) draftPositioned = true;
    }
    return draftPositioned;
}

/**
 * Render a sandboxed real-Theme Home or Gallery iframe with scripts disabled.
 *
 * The protected frame has only `allow-same-origin`; public scripts and forms
 * remain disabled. Route selection uses controller-prepared URLs that are
 * revalidated on load, and all draft geometry is temporary and read-only.
 *
 * @param {HTMLFormElement} form Active editor.
 * @param {Record<string,string>} labels Maintained browser translations.
 * @returns {() => void} Refresh callback after preview, route, device, or placement edits.
 */
function setupWidgetThemePreview(form, labels) {
    const host = document.querySelector('[data-widget-theme-preview]');
    const shell = host?.querySelector('[data-widget-theme-frame-wrap]');
    const status = host?.querySelector('[data-widget-theme-status]');
    const preview = document.querySelector('[data-widget-preview]');
    const pageSelect = document.querySelector('[data-widget-preview-page-selector]');
    if (!host || !shell || !status || !preview) return () => {};
    const label = (key, fallback) => typeof labels[key] === 'string' ? labels[key] : fallback;
    const legacyHomeOnly = !host.hasAttribute('data-widget-preview-home-url')
        && !host.hasAttribute('data-widget-preview-gallery-url');
    const homeValue = host.dataset.widgetPreviewHomeUrl || host.dataset.widgetPreviewUrl || '';
    const galleryValue = host.dataset.widgetPreviewGalleryUrl || '';
    const frame = document.createElement('iframe');
    frame.className = 'public-widgets-theme-frame';
    frame.title = host.querySelector('h3')?.textContent || 'Public page Theme preview';
    frame.setAttribute('sandbox', 'allow-same-origin');
    frame.setAttribute('referrerpolicy', 'same-origin');
    shell.append(frame);
    let activePage = 'home';
    let activeUrl = null;
    let mainSnapshot = null;
    let footerSnapshot = null;
    let ready = false;
    const dismissedPreviewWidgets = new Set();
    const pageName = page => label('page_' + page, page === 'home' ? 'Homepage' : 'Gallery page');
    const statusUnavailable = page => status.textContent = page === 'gallery' && !galleryValue
            ? label('theme_gallery', 'Gallery Theme preview is unavailable; the editor remains usable.')
        : label('theme_unavailable', 'The protected public-page preview is unavailable; the editor remains usable.');
    const refresh = () => {
        const page = pageSelect?.value === 'gallery' ? 'gallery' : 'home';
        if (legacyHomeOnly && page === 'gallery') {
            shell.hidden = true;
            statusUnavailable(page);
            return;
        }
        const value = page === 'gallery' ? galleryValue : homeValue;
        const url = publicWidgetProtectedPageUrl(value, window.location.href, page);
        if (!url) {
            shell.hidden = true;
            statusUnavailable(page);
            return;
        }
        shell.hidden = false;
        if (activeUrl === null || url.href !== activeUrl.href) {
            activePage = page;
            activeUrl = url;
            mainSnapshot = null;
            footerSnapshot = null;
            ready = false;
            status.textContent = label('theme_loading', 'Loading protected public-page Theme preview…');
            frame.src = url.href;
            return;
        }
        if (!ready) {
            status.textContent = label('theme_loading', 'Loading protected public-page Theme preview…');
            return;
        }
        const doc = frame.contentDocument;
        const win = frame.contentWindow;
        const main = doc?.querySelector('main.site-main');
        const footer = doc?.querySelector('.site-footer');
        if (!doc || !win || !main || !footer || !mainSnapshot || !footerSnapshot) return;
        const device = document.querySelector('.public-widgets-placement-stage')?.dataset.device || 'desktop';
        const viewport = {
            desktop: {width: 1280, height: 900},
            tablet: {width: 768, height: 1024},
            mobile: {width: 390, height: 740},
        }[device] || {width: 1280, height: 900};
        const width = viewport.width;
        const height = viewport.height;
        const scale = Math.min(1, Math.max(1, host.getBoundingClientRect().width - 20) / width);
        frame.style.width = width + 'px';
        frame.style.height = height + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        shell.style.width = String(width * scale) + 'px';
        shell.style.height = String(height * scale) + 'px';
        const sourceMain = mainSnapshot.cloneNode(true);
        const sourceFooter = footerSnapshot.cloneNode(true);
        main.replaceChildren(...sourceMain.childNodes);
        footer.replaceChildren(...sourceFooter.childNodes);
        const draft = publicWidgetRenderDraft(doc, form, preview, activePage, labels);
        for (const widget of doc.querySelectorAll('[data-public-widget-floating="1"]')) {
            const key = widget.dataset.publicWidgetId || (widget.hasAttribute('data-widget-theme-draft') ? 'draft' : '');
            if (key && dismissedPreviewWidgets.has(activePage + ':' + key)) widget.hidden = true;
        }
        const floating = form.querySelector('[name="placement_mode"]')?.value === 'floating';
        const positioned = publicWidgetApplyFloatingPreview(doc, win, draft);
        const previewState = floating && !positioned
            ? label('theme_floating', 'Floating placement uses measured public-page geometry when supported; otherwise the widget stays in page flow.')
            : label('theme_ready', 'Actual public Theme and widget position shown. Preview only: changes are not saved.');
        status.textContent = pageName(activePage) + ': ' + previewState;
    };
    frame.addEventListener('load', () => {
        try {
            const doc = frame.contentDocument;
            const win = frame.contentWindow;
            const current = publicWidgetProtectedPageUrl(win?.location.href || '', window.location.href, activePage);
            const main = doc?.querySelector('body.public-page main.site-main');
            const footer = doc?.querySelector('.site-footer');
            const pageMarker = activePage === 'home'
                ? main?.querySelector('.gallery-list-frame')
                : main?.querySelector('.hero[data-public-gallery-id]');
            if (!current || current.href !== activeUrl?.href || !main || !footer || !doc?.querySelector('.site-header')
                || !pageMarker || doc.querySelector('[data-visual-preview-blocked]')) {
                throw new Error('Protected public page did not pass verification.');
            }
            const previewPage = activePage;
            doc.addEventListener('click', event => {
                const target = event.target;
                const close = target instanceof win.Element ? target.closest('.public-widget-dismiss') : null;
                const widget = close?.closest('[data-public-widget-floating="1"]');
                if (widget) {
                    event.preventDefault();
                    const key = widget.dataset.publicWidgetId || 'draft';
                    dismissedPreviewWidgets.add(previewPage + ':' + key);
                    widget.hidden = true;
                    publicWidgetApplyFloatingPreview(doc, win, doc.querySelector('[data-widget-theme-draft]'));
                    return;
                }
                event.preventDefault();
            }, true);
            doc.addEventListener('auxclick', event => event.preventDefault(), true);
            doc.addEventListener('submit', event => event.preventDefault(), true);
            mainSnapshot = main.cloneNode(true);
            footerSnapshot = footer.cloneNode(true);
            ready = true;
            refresh();
        } catch (_) {
            shell.hidden = true;
            ready = false;
            statusUnavailable(activePage);
        }
    });
    form.addEventListener('public-widget-preview-change', refresh);
    pageSelect?.addEventListener('change', refresh);
    refresh();
    return refresh;
}

/**
 * Attach the editor to its dedicated Admin DOM only, leaving all other pages inert.
 *
 * Native POST actions remain authoritative. AJAX is used solely for a preview,
 * which is returned by the authenticated server using the same safe renderer.
 *
 * @returns {void} Installs idempotent progressive enhancement on the editor.
 */
export function setupAdminPublicWidgets() {
    const form = document.querySelector('[data-public-widget-editor]');
    if (!(form instanceof HTMLFormElement) || form.dataset.widgetEnhanced === '1') return;
    form.dataset.widgetEnhanced = '1';
    const editor = form.querySelector('[data-widget-source]');
    if (!(editor instanceof HTMLTextAreaElement)) return;
    for (const button of form.querySelectorAll('[data-widget-format]')) {
        button.addEventListener('click', () => formatWidgetMarkdown(editor, button.dataset.widgetFormat || ''));
    }
    let labels = {};
    try {
        const parsed = JSON.parse(form.dataset.widgetI18n || '{}');
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) labels = parsed;
    } catch (_) {
        labels = {};
    }
    const translated = (key, fallback) => typeof labels[key] === 'string' ? labels[key] : fallback;
    const refresh = setupWidgetPlacement(form, labels);
    const refreshTheme = setupWidgetThemePreview(form, labels);
    const preview = document.querySelector('[data-widget-preview]');
    const body = preview?.querySelector('[data-widget-preview-body]');
    const title = preview?.querySelector('.public-content-widget-title');
    const card = preview?.querySelector('.public-content-widget');
    const notice = document.createElement('p');
    notice.setAttribute('role', 'status');
    preview?.append(notice);
    form.addEventListener('submit', async (event) => {
        const submitter = event.submitter;
        if (!(submitter instanceof HTMLButtonElement) || submitter.value !== 'preview' || submitter.name !== 'widget_action') return;
        if (!body || !card || !window.fetch) return;
        event.preventDefault();
        const data = new FormData(form);
        data.set('widget_action', 'preview');
        data.set('widget_preview_json', '1');
        notice.textContent = translated('loading', 'Rendering preview…');
        submitter.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true || typeof result.html !== 'string') {
                throw new Error(result.message || 'Preview failed.');
            }
            body.innerHTML = result.html;
            const nextTitle = String(result.title || '');
            let heading = title || card.querySelector('.public-content-widget-title');
            if (!heading && nextTitle) {
                heading = document.createElement('h4');
                heading.className = 'public-content-widget-title';
                card.insertBefore(heading, card.firstChild);
            }
            if (heading) {
                heading.textContent = nextTitle;
                heading.hidden = nextTitle === '';
            }
            card.classList.toggle('public-content-widget--minimal', result.appearance === 'minimal');
            card.classList.toggle('public-content-widget--card', result.appearance !== 'minimal');
            refresh();
            refreshTheme();
            notice.textContent = result.message || 'Preview updated. No changes saved.';
        } catch (error) {
            notice.textContent = error instanceof Error ? error.message : translated('failure', 'Preview unavailable.');
        } finally {
            submitter.disabled = false;
        }
    });
}
