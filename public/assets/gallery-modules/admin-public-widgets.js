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
 * @returns {Function} Safe refresh callback shared by preview controls.
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
            notice.textContent = result.message || 'Preview updated. No changes saved.';
        } catch (error) {
            notice.textContent = error instanceof Error ? error.message : translated('failure', 'Preview unavailable.');
        } finally {
            submitter.disabled = false;
        }
    });
}
