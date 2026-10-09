/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/theme-visual-css-resize.js
 * Module Type: Browser Module
 * Purpose: Provide bounded, cancellable pointer resizing for approved public preview elements.
 * Responsibilities: Classify safe resize profiles, calculate grouped CSS changes, and own transient iframe/overlay resources.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {visualCssDraftSelectorIsValid, validateVisualCssDraftRule} from './theme-visual-css-draft.js?v=20261009-visual-editor-overlay-routing';

/**
 * One immutable profile describing which dimensions an element may resize.
 * @typedef {{eligible:boolean,profileId:string,reason:string,edges:Array<'top'|'bottom'|'left'|'right'|'top-left'|'top-right'|'bottom-left'|'bottom-right'>,horizontalProperty:'width'|null,verticalProperty:'height'|'min-height'|null,preserveAspectRatio:boolean,minWidth:number,maxWidth:number,minHeight:number,maxHeight:number}} VisualCssResizeProfile
 */

/**
 * Rectangle measured in CSS pixels in its owning viewport.
 * @typedef {{left:number,top:number,width:number,height:number}} VisualCssResizeRect
 */

/**
 * One immutable in-progress pointer transaction.
 * @typedef {{profile:VisualCssResizeProfile,rect:VisualCssResizeRect,startPoint:{x:number,y:number},edge:'top'|'bottom'|'left'|'right'|'top-left'|'top-right'|'bottom-left'|'bottom-right',selector:string,scope:'site'|'responsive:tablet'|'responsive:mobile',aspectRatio:number}} VisualCssResizeTransaction
 */

/**
 * State retained only while one parent-document resize gesture is active. It records the native pointer and parent button, the approved edge and immutable transaction, the latest safe declarations, and the overlay's exact pre-gesture inline pointer-events value for restoration.
 * @typedef {{pointerId:number,handle:HTMLButtonElement,edge:'top'|'bottom'|'left'|'right'|'top-left'|'top-right'|'bottom-left'|'bottom-right',transaction:VisualCssResizeTransaction,changes:Array<VisualCssResizeChange>,overlayPointerEvents:string}} VisualCssActiveResize
 */

/**
 * One safe model declaration emitted by a resize transaction.
 * @typedef {{scope:'site'|'responsive:tablet'|'responsive:mobile',selector:string,property:'width'|'height'|'min-height',value:string}} VisualCssResizeChange
 */

/**
 * Minimum usable card or media dimension in CSS pixels.
 * Type: number.
 * Units: CSS pixels. Scope: lower bound for visual resize profiles.
 * Consumers: profile classification and transaction clamping.
 * Rationale: smaller public elements are difficult to resize and can collapse meaningful content.
 */
const VISUAL_CSS_RESIZE_MIN_DIMENSION = 48;

/**
 * Smallest allowed minimum block size for public layout and hero regions.
 * Type: number.
 * Units: CSS pixels. Scope: vertical minimum-height resize profiles.
 * Consumers: hero and public-shell profile classification.
 * Rationale: keep a usable hit area and avoid collapsing structural content containers.
 */
const VISUAL_CSS_RESIZE_MIN_BLOCK_SIZE = 64;

/**
 * Maximum dimension accepted by the visual resize model.
 * Type: number.
 * Units: CSS pixels. Scope: one public element dimension during a drag.
 * Consumers: profile classification, pointer transaction, and CSS serialization.
 * Rationale: bound user-authored sizes to a practical preview surface.
 */
const VISUAL_CSS_RESIZE_ABSOLUTE_MAX = 4096;

/**
 * Round output values to whole CSS pixels.
 * Type: number.
 * Units: CSS pixels per output step. Scope: committed resize values.
 * Consumers: resize transaction updates.
 * Rationale: avoid noisy fractional declarations from pointer movement while retaining precise layout control.
 */
const VISUAL_CSS_RESIZE_STEP = 1;

/**
 * Return a bounded number when CSS exposes a pixel-valued property.
 * @param {string} value Computed CSS value.
 * @param {number} fallback Safe fallback when the value is not pixel-numeric.
 * @returns {number} Finite nonnegative CSS pixel value or the supplied fallback.
 */
function visualCssResizePixels(value, fallback) {
    const match = typeof value === 'string' ? value.match(/^(\d+(?:\.\d+)?)px$/) : null;
    if (!match) return fallback;
    const parsed = Number(match[1]);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
}

/**
 * Split a CSS grid track list at whitespace outside functional expressions.
 * @param {string} value Computed grid track list.
 * @returns {Array<string>} Top-level grid track tokens.
 */
function visualCssResizeGridTracks(value) {
    if (!value || value === 'none' || value === 'subgrid' || value === 'masonry') return [];
    const tracks = [];
    let depth = 0;
    let start = 0;
    for (let index = 0; index < value.length; index++) {
        const character = value[index];
        if (character === '(' || character === '[') depth++;
        else if (character === ')' || character === ']') depth--;
        else if (/\s/.test(character) && depth === 0) {
            const token = value.slice(start, index).trim();
            if (token) tracks.push(token);
            start = index + 1;
        }
    }
    const finalTrack = value.slice(start).trim();
    if (finalTrack) tracks.push(finalTrack);
    return tracks;
}

/**
 * Determine which dimension axes are effective for a grid or flex child.
 * @param {Element} element Candidate child whose parent controls its layout axis.
 * @returns {Array<'horizontal'|'vertical'>} Safe effective main axes, or an empty list when layout is ambiguous.
 */
function visualCssResizeParentAxes(element) {
    const parent = element.parentElement;
    const view = element.ownerDocument?.defaultView;
    if (!parent || !view) return [];
    const style = view.getComputedStyle(parent);
    if (style.display === 'flex' || style.display === 'inline-flex') {
        return style.flexDirection.startsWith('column') ? ['vertical'] : ['horizontal'];
    }
    if (style.display === 'grid' || style.display === 'inline-grid') {
        const columns = visualCssResizeGridTracks(style.gridTemplateColumns);
        const rows = visualCssResizeGridTracks(style.gridTemplateRows);
        const axes = [];
        if (columns.length > 1) axes.push('horizontal');
        if (rows.length > 1) axes.push('vertical');
        return axes;
    }
    return [];
}

/**
 * Return a frozen ineligible profile with a stable reason for the inspector.
 * @param {string} reason Bounded reason code for the unavailable resize profile.
 * @returns {VisualCssResizeProfile} Empty immutable profile.
 */
function visualCssResizeUnavailable(reason) {
    return Object.freeze({
        eligible: false,
        profileId: 'none',
        reason,
        edges: Object.freeze([]),
        horizontalProperty: null,
        verticalProperty: null,
        preserveAspectRatio: false,
        minWidth: VISUAL_CSS_RESIZE_MIN_DIMENSION,
        maxWidth: VISUAL_CSS_RESIZE_MIN_DIMENSION,
        minHeight: VISUAL_CSS_RESIZE_MIN_DIMENSION,
        maxHeight: VISUAL_CSS_RESIZE_MIN_DIMENSION,
    });
}

/**
 * Classify a real iframe element into a deliberately narrow resize profile.
 * @param {Element} element Selected element from the public preview document.
 * @returns {VisualCssResizeProfile} Immutable allowed axes, edges, and CSS pixel bounds.
 */
export function classifyVisualCssResizeProfile(element) {
    if (!element || element.nodeType !== 1 || !element.ownerDocument?.defaultView) return visualCssResizeUnavailable('element_unavailable');
    const view = element.ownerDocument.defaultView;
    const style = view.getComputedStyle(element);
    if (style.display === 'none' || style.display === 'contents') return visualCssResizeUnavailable('not_rendered');
    const isMedia = element.localName === 'img' || element.localName === 'video';
    if (!isMedia && (style.display === 'inline' || style.display === 'inline-block' && element.localName === 'span')) {
        return visualCssResizeUnavailable('inline_content');
    }
    if (['width', 'height', 'min-height'].some(property => element.style?.getPropertyPriority(property) === 'important')) {
        return visualCssResizeUnavailable('inline_important_dimension');
    }

    const viewportWidth = Math.max(1, Number(view.innerWidth) || 1);
    const viewportHeight = Math.max(1, Number(view.innerHeight) || 1);
    const rect = element.getBoundingClientRect();
    const width = Math.max(VISUAL_CSS_RESIZE_MIN_DIMENSION, rect.width);
    const height = Math.max(VISUAL_CSS_RESIZE_MIN_DIMENSION, rect.height);
    if (width > VISUAL_CSS_RESIZE_ABSOLUTE_MAX || height > VISUAL_CSS_RESIZE_ABSOLUTE_MAX) {
        return visualCssResizeUnavailable('above_supported_dimension');
    }
    const minWidth = Math.max(VISUAL_CSS_RESIZE_MIN_DIMENSION, visualCssResizePixels(style.minWidth, VISUAL_CSS_RESIZE_MIN_DIMENSION));
    const minHeight = Math.max(VISUAL_CSS_RESIZE_MIN_DIMENSION, visualCssResizePixels(style.minHeight, VISUAL_CSS_RESIZE_MIN_DIMENSION));
    const maxWidth = Math.max(width, Math.min(VISUAL_CSS_RESIZE_ABSOLUTE_MAX,
        viewportWidth, visualCssResizePixels(style.maxWidth, viewportWidth)));
    const maxHeight = Math.max(height, Math.min(VISUAL_CSS_RESIZE_ABSOLUTE_MAX,
        viewportHeight, visualCssResizePixels(style.maxHeight, viewportHeight)));

    let profileId = '';
    let horizontalProperty = null;
    let verticalProperty = null;
    let preserveAspectRatio = false;
    let edges = [];
    const matches = selector => typeof element.matches === 'function' && element.matches(selector);

    if (matches('.hero')) {
        profileId = 'hero';
        verticalProperty = 'min-height';
        edges = ['top', 'bottom'];
    } else if (matches('.site-header, .site-main, .site-footer')) {
        profileId = 'layout';
        verticalProperty = 'min-height';
        edges = ['top', 'bottom'];
    } else if (isMedia) {
        const parentAxes = visualCssResizeParentAxes(element);
        profileId = 'media';
        horizontalProperty = parentAxes.length > 0 && !parentAxes.includes('horizontal') ? null : 'width';
        verticalProperty = parentAxes.length > 0 && !parentAxes.includes('vertical') ? null : 'height';
        preserveAspectRatio = true;
        edges = parentAxes.length === 1
            ? parentAxes[0] === 'horizontal' ? ['left', 'right'] : ['top', 'bottom']
            : ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
    } else if (matches('.gallery-card, [data-gallery-card]')) {
        const parentAxes = visualCssResizeParentAxes(element);
        if (parentAxes.length === 0) return visualCssResizeUnavailable('no_effective_parent_axis');
        profileId = 'card';
        horizontalProperty = parentAxes.includes('horizontal') ? 'width' : null;
        verticalProperty = parentAxes.includes('vertical') ? 'height' : null;
        edges = parentAxes.length === 2
            ? ['top-left', 'top-right', 'bottom-left', 'bottom-right']
            : parentAxes[0] === 'horizontal' ? ['left', 'right'] : ['top', 'bottom'];
    }

    if (!profileId || (!horizontalProperty && !verticalProperty)) return visualCssResizeUnavailable('profile_not_eligible');
    const safeMinWidth = Math.min(maxWidth, minWidth);
    const safeMinHeight = Math.min(maxHeight, Math.max(minHeight,
        verticalProperty === 'min-height' ? VISUAL_CSS_RESIZE_MIN_BLOCK_SIZE : minHeight));
    return Object.freeze({
        eligible: true,
        profileId,
        reason: 'ok',
        edges: Object.freeze(edges),
        horizontalProperty,
        verticalProperty,
        preserveAspectRatio,
        minWidth: safeMinWidth,
        maxWidth,
        minHeight: safeMinHeight,
        maxHeight,
    });
}

/**
 * Create one bounded resize transaction from measured iframe geometry and parent pointer coordinates.
 * @param {VisualCssResizeProfile} profile Approved resize profile for the selected element.
 * @param {VisualCssResizeRect} rect Current element rectangle in iframe CSS pixels.
 * @param {{x:number,y:number}} startPoint Pointer origin in parent-window CSS pixels.
 * @param {string} edge One profile edge receiving pointer input.
 * @param {string} selector Valid stable selector used only for the transient preview rule.
 * @param {'site'|'responsive:tablet'|'responsive:mobile'} scope Explicit managed CSS scope.
 * @returns {VisualCssResizeTransaction|null} Frozen transaction or null for invalid geometry/profile.
 */
export function createVisualCssResizeTransaction(profile, rect, startPoint, edge, selector, scope) {
    if (!profile?.eligible || !Array.isArray(profile.edges) || !profile.edges.includes(edge)
        || !['width', null].includes(profile.horizontalProperty)
        || !['height', 'min-height', null].includes(profile.verticalProperty)
        || ![profile.minWidth, profile.maxWidth, profile.minHeight, profile.maxHeight]
            .every(value => Number.isFinite(value) && value >= 0)
        || profile.minWidth > profile.maxWidth || profile.minHeight > profile.maxHeight || !rect
        || !Number.isFinite(rect.left) || !Number.isFinite(rect.top)
        || !Number.isFinite(rect.width) || !Number.isFinite(rect.height) || rect.width <= 0 || rect.height <= 0
        || !Number.isFinite(startPoint?.x) || !Number.isFinite(startPoint?.y)
        || !visualCssDraftSelectorIsValid(selector).valid
        || !['site', 'responsive:tablet', 'responsive:mobile'].includes(scope)) return null;
    return Object.freeze({
        profile,
        rect: Object.freeze({left: rect.left, top: rect.top, width: rect.width, height: rect.height}),
        startPoint: Object.freeze({x: startPoint.x, y: startPoint.y}),
        edge,
        selector,
        scope,
        aspectRatio: rect.width > 0 && rect.height > 0 ? rect.width / rect.height : 1,
    });
}

/**
 * Calculate a clamped frame of one active resize gesture without changing its transaction origin.
 * @param {VisualCssResizeTransaction} transaction Frozen pointer-down snapshot.
 * @param {{x:number,y:number}} point Current parent-window pointer position in CSS pixels.
 * @returns {{width:number,height:number,changes:Array<VisualCssResizeChange>}|null} Preview dimensions and grouped CSS model changes.
 */
export function updateVisualCssResizeTransaction(transaction, point) {
    if (!transaction || !Number.isFinite(point?.x) || !Number.isFinite(point?.y)) return null;
    const {profile, rect, startPoint, edge, selector, scope, aspectRatio} = transaction;
    const horizontal = ['left', 'right'].some(side => edge.includes(side)) || edge === 'left' || edge === 'right';
    const vertical = ['top', 'bottom'].some(side => edge.includes(side)) || edge === 'top' || edge === 'bottom';
    const horizontalDelta = edge.includes('left') ? startPoint.x - point.x : point.x - startPoint.x;
    const verticalDelta = edge.includes('top') ? startPoint.y - point.y : point.y - startPoint.y;
    let width = rect.width;
    let height = rect.height;

    if (profile.preserveAspectRatio) {
        const projectedScale = horizontal && vertical
            ? ((horizontalDelta / Math.max(1, rect.width)) + (verticalDelta / Math.max(1, rect.height))) / 2
            : horizontal ? horizontalDelta / Math.max(1, rect.width) : verticalDelta / Math.max(1, rect.height);
        const minScale = Math.max(profile.minWidth / Math.max(1, rect.width), profile.minHeight / Math.max(1, rect.height));
        const maxScale = Math.min(profile.maxWidth / Math.max(1, rect.width), profile.maxHeight / Math.max(1, rect.height));
        const scale = Math.max(minScale, Math.min(maxScale, 1 + projectedScale));
        width = rect.width * scale;
        height = rect.height * scale;
    } else {
        if (horizontal && profile.horizontalProperty) {
            width = rect.width + horizontalDelta;
            width = Math.max(profile.minWidth, Math.min(profile.maxWidth, width));
        }
        if (vertical && profile.verticalProperty) {
            height = rect.height + verticalDelta;
            height = Math.max(profile.minHeight, Math.min(profile.maxHeight, height));
        }
    }

    width = Math.round(width / VISUAL_CSS_RESIZE_STEP) * VISUAL_CSS_RESIZE_STEP;
    height = Math.round(height / VISUAL_CSS_RESIZE_STEP) * VISUAL_CSS_RESIZE_STEP;
    const changes = [];
    if (profile.verticalProperty === 'min-height') {
        changes.push({scope, selector, property: 'min-height', value: Math.round(height) + 'px'});
    } else if (profile.preserveAspectRatio) {
        if (horizontal && (!vertical || Math.abs(horizontalDelta / Math.max(1, rect.width))
            >= Math.abs(verticalDelta / Math.max(1, rect.height)))) {
            changes.push({scope, selector, property: 'width', value: Math.round(width) + 'px'});
            changes.push({scope, selector, property: 'height', value: 'auto'});
        } else {
            changes.push({scope, selector, property: 'width', value: 'auto'});
            changes.push({scope, selector, property: 'height', value: Math.round(height) + 'px'});
        }
    } else {
        if (horizontal && profile.horizontalProperty) {
            changes.push({scope, selector, property: profile.horizontalProperty, value: Math.round(width) + 'px'});
        }
        if (vertical && profile.verticalProperty) {
            changes.push({scope, selector, property: profile.verticalProperty, value: Math.round(height) + 'px'});
        }
    }
    if (changes.some(change => !validateVisualCssDraftRule(change).valid)) return null;
    return Object.freeze({width, height, changes: Object.freeze(changes.map(change => Object.freeze(change)))});
}

/**
 * Position one parent-document resize handle over its measured iframe edge.
 * @param {HTMLButtonElement} handle Parent-owned resize handle.
 * @param {HTMLElement} overlay Parent-owned fixed overlay.
 * @param {HTMLIFrameElement} frame Preview iframe whose content box owns the target rectangle.
 * @param {DOMRect} frameRect Parent-window iframe rectangle.
 * @param {DOMRect} targetRect Iframe-window target rectangle.
 * @param {string} edge Resize edge or corner.
 * @returns {void} Places the handle in parent-window CSS pixels.
 */
function positionVisualCssResizeHandle(handle, overlay, frame, frameRect, targetRect, edge) {
    const overlayRect = overlay.getBoundingClientRect();
    const left = frameRect.left + frame.clientLeft + targetRect.left - overlayRect.left;
    const top = frameRect.top + frame.clientTop + targetRect.top - overlayRect.top;
    const width = targetRect.width;
    const height = targetRect.height;
    const positions = {
        top: [left + width / 2, top],
        bottom: [left + width / 2, top + height],
        left: [left, top + height / 2],
        right: [left + width, top + height / 2],
        'top-left': [left, top],
        'top-right': [left + width, top],
        'bottom-left': [left, top + height],
        'bottom-right': [left + width, top + height],
    };
    const [x, y] = positions[edge] || [left, top];
    handle.style.left = Math.round(x) + 'px';
    handle.style.top = Math.round(y) + 'px';
}

/**
 * Attach accessible parent-overlay handles for one selected public preview element.
 * @param {{overlay:HTMLElement,frame:HTMLIFrameElement,target:Element,selector:string,scope:'site'|'responsive:tablet'|'responsive:mobile',labels:{top:string,bottom:string,left:string,right:string,topLeft:string,topRight:string,bottomLeft:string,bottomRight:string},onPreview:function(Array<VisualCssResizeChange>):void,onCommit:function(Array<VisualCssResizeChange>):void,onCancel:function():void}} options Parent overlay, same-origin frame/target, selected scope, localized labels, and callbacks receiving grouped changes, committing them, or cancelling them.
 * @returns {function():void} Idempotent cleanup that removes handles, transient styles, listeners, capture and pending preview state.
 */
export function attachVisualCssResizeHandle(options) {
    const {overlay, frame, target, selector, scope, onPreview, onCommit, onCancel} = options || {};
    const profile = classifyVisualCssResizeProfile(target);
    if (!overlay || !frame || !target || !profile.eligible || !visualCssDraftSelectorIsValid(selector).valid
        || !['site', 'responsive:tablet', 'responsive:mobile'].includes(scope)
        || !frame.contentDocument || target.ownerDocument !== frame.contentDocument
        || !target.matches(selector) || target.ownerDocument.querySelectorAll(selector).length !== 1
        || typeof onPreview !== 'function' || typeof onCommit !== 'function' || typeof onCancel !== 'function') return () => {};
    const parentWindow = overlay.ownerDocument.defaultView;
    const previewDocument = target.ownerDocument;
    const previewWindow = previewDocument.defaultView;
    if (!parentWindow || !previewWindow || !previewDocument.head) return () => {};
    const handles = [];
    const localized = options.labels || {};
    const labelForEdge = {
        top: localized.top,
        bottom: localized.bottom,
        left: localized.left,
        right: localized.right,
        'top-left': localized.topLeft,
        'top-right': localized.topRight,
        'bottom-left': localized.bottomLeft,
        'bottom-right': localized.bottomRight,
    };
    /** @type {VisualCssActiveResize|null} Active gesture snapshot or no active pointer transaction. */
    let active = null;
    let transientStyle = null;
    let disposed = false;
    let cleanupCalled = false;
    const previousOverlayPointerEvents = overlay.style.pointerEvents;

    /**
     * Remove the temporary stylesheet and reset a pending pointer transaction.
     * @returns {void} Leaves only committed draft CSS and restores the overlay's pre-shield pointer-event mode.
     */
    function clearTransientPreview() {
        const preShieldPointerEvents = active?.overlayPointerEvents ?? previousOverlayPointerEvents;
        try {
            if (active?.handle.hasPointerCapture?.(active.pointerId)) {
                active.handle.releasePointerCapture(active.pointerId);
            }
        } catch {
            // Browser capture may already have ended during pointerup or frame teardown.
        }
        if (transientStyle?.isConnected) transientStyle.remove();
        transientStyle = null;
        active = null;
        overlay.style.pointerEvents = preShieldPointerEvents;
    }

    /**
     * Cancel the active drag, notify the workspace, and restore handle geometry.
     * @returns {void} Restores the uncommitted preview state and repositions handles over the restored target rectangle.
     */
    function cancelActiveResize() {
        if (!active) return;
        clearTransientPreview();
        onCancel();
        if (!disposed) repositionHandles();
    }

    /**
     * Render a validated transaction snapshot in a temporary iframe stylesheet.
     * @param {Array<{scope:string,selector:string,property:string,value:string}>} changes Safe bounded CSS properties from the resize model.
     * @returns {void} Replaces only this gesture's transient selector rule.
     */
    function renderTransientPreview(changes) {
        if (changes.length === 0 || changes.some(change => !validateVisualCssDraftRule(change).valid
            || change.selector !== selector || change.scope !== changes[0].scope)) return;
        if (!transientStyle) {
            transientStyle = previewDocument.createElement('style');
            transientStyle.dataset.visualCssResizePreview = '1';
            previewDocument.head.append(transientStyle);
        }
        const declarations = selector + ' {' + changes.map(change => change.property + ': '
            + change.value + ' !important;').join(' ') + '}';
        const scope = changes[0].scope;
        transientStyle.textContent = scope === 'responsive:tablet'
            ? '@media (max-width: 768px) {' + declarations + '}'
            : scope === 'responsive:mobile'
                ? '@media (max-width: 480px) {' + declarations + '}'
                : declarations;
    }

    /**
     * Reposition each overlay handle after either viewport scrolls or resizes.
     * @returns {void} Hides handles when the selected public target is stale or invisible.
     */
    function repositionHandles() {
        if (disposed || !target.isConnected || frame.contentDocument !== previewDocument) {
            cleanup();
            return;
        }
        const targetRect = target.getBoundingClientRect();
        const frameRect = frame.getBoundingClientRect();
        const visible = targetRect.width > 0 && targetRect.height > 0
            && frameRect.width > 0 && frameRect.height > 0
            && targetRect.bottom > 0 && targetRect.right > 0
            && targetRect.top < previewWindow.innerHeight && targetRect.left < previewWindow.innerWidth;
        for (const handle of handles) {
            handle.hidden = !visible;
            if (visible) positionVisualCssResizeHandle(handle, overlay, frame, frameRect, targetRect, handle.dataset.resizeEdge || '');
        }
    }

    /**
     * Update the active resize from a parent-document pointer move.
     * @param {PointerEvent} event Parent-document pointer event received through the shared resize overlay.
     * @returns {void} Updates only the temporary iframe CSS and parent status callback.
     */
    function onPointerMove(event) {
        if (!active || event.pointerId !== active.pointerId) return;
        event.preventDefault();
        if (!target.isConnected || frame.contentDocument !== previewDocument) {
            cancelActiveResize();
            cleanup();
            return;
        }
        const frameRect = frame.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const result = updateVisualCssResizeTransaction(active.transaction, {
            x: event.clientX,
            y: event.clientY,
        });
        if (!result) return;
        active.changes = result.changes;
        renderTransientPreview(active.changes);
        onPreview(active.changes);
        positionVisualCssResizeHandle(active.handle, overlay, frame, frameRect, targetRect, active.edge);
    }

    /**
     * Finish one drag with a single grouped CSS model callback.
     * @param {PointerEvent} event Parent-document pointer release event received through the shared resize overlay.
     * @returns {void} Commits one non-no-op snapshot and clears transient resources.
     */
    function onPointerUp(event) {
        if (!active || event.pointerId !== active.pointerId) return;
        event.preventDefault();
        const finalFrame = updateVisualCssResizeTransaction(active.transaction, {x: event.clientX, y: event.clientY});
        if (finalFrame) {
            active.changes = finalFrame.changes;
            renderTransientPreview(active.changes);
            onPreview(active.changes);
        }
        const changes = active.changes;
        const pointerMoved = event.clientX !== active.transaction.startPoint.x
            || event.clientY !== active.transaction.startPoint.y;
        clearTransientPreview();
        if (pointerMoved && changes?.length) onCommit(changes);
        else onCancel();
        repositionHandles();
    }

    /**
     * Cancel a drag when its active pointer is cancelled by the browser.
     * @param {PointerEvent} event Parent-document cancellation event received through the shared resize overlay.
     * @returns {void} Restores the pre-drag preview.
     */
    function onPointerCancel(event) {
        if (active && event.pointerId === active.pointerId) cancelActiveResize();
    }

    /**
     * Cancel any active drag when the administrator presses Escape.
     * @param {KeyboardEvent} event Parent-document keyboard event.
     * @returns {void} Prevents Escape from leaving an uncommitted resize preview.
     */
    function onKeyDown(event) {
        if (event.key === 'Escape' && active) {
            event.preventDefault();
            cancelActiveResize();
        }
    }

    /**
     * Release all resources owned by this selection's resize affordances.
     * @returns {void} Removes the listeners, style node and overlay handles once.
     */
    function cleanup() {
        if (cleanupCalled) return;
        cleanupCalled = true;
        disposed = true;
        cancelActiveResize();
        parentWindow.removeEventListener('resize', repositionHandles);
        parentWindow.removeEventListener('scroll', repositionHandles, true);
        previewWindow.removeEventListener('resize', repositionHandles);
        previewWindow.removeEventListener('scroll', repositionHandles, true);
        overlay.removeEventListener('pointermove', onPointerMove);
        overlay.removeEventListener('pointerup', onPointerUp);
        overlay.removeEventListener('pointercancel', onPointerCancel);
        frame.removeEventListener('load', cleanup);
        parentWindow.document.removeEventListener('keydown', onKeyDown, true);
        for (const handle of handles) handle.remove();
        handles.length = 0;
        overlay.style.pointerEvents = previousOverlayPointerEvents;
    }

    for (const edge of profile.edges) {
        const label = labelForEdge[edge];
        if (typeof label !== 'string' || label.trim() === '') continue;
        const handle = overlay.ownerDocument.createElement('button');
        handle.type = 'button';
        handle.className = 'theme-visual-css-resize-handle';
        handle.dataset.resizeEdge = edge;
        handle.setAttribute('aria-label', label);
        handle.title = label;
        handle.textContent = edge.includes('left') || edge.includes('right') ? '↔' : '↕';
        const cursor = edge === 'top-left' || edge === 'bottom-right' ? 'nwse-resize'
            : edge === 'top-right' || edge === 'bottom-left' ? 'nesw-resize'
                : edge === 'left' || edge === 'right' ? 'ew-resize' : 'ns-resize';
        handle.style.cssText = 'position:absolute;z-index:5;transform:translate(-50%,-50%);display:grid;place-items:center;'
            + 'width:1.5rem;height:1.5rem;padding:0;border:2px solid #fff;border-radius:50%;'
            + 'background:#1d4ed8;color:#fff;box-shadow:0 1px 5px #0008;touch-action:none;user-select:none;'
            + 'pointer-events:auto;cursor:' + cursor + ';';
        handle.addEventListener('pointerdown', event => {
            if (event.isPrimary === false || event.button !== 0 || active || !target.isConnected) return;
            event.preventDefault();
            event.stopPropagation();
            const rect = target.getBoundingClientRect();
            const transaction = createVisualCssResizeTransaction(profile, rect,
                {x: event.clientX, y: event.clientY}, edge, selector, scope);
            if (!transaction) return;
            active = {
                pointerId: event.pointerId,
                handle,
                edge,
                transaction,
                changes: [],
                overlayPointerEvents: overlay.style.pointerEvents,
            };
            // Keep the next hit test in this parent document while pointer capture is pending; otherwise a move into the iframe can switch active documents before capture applies.
            overlay.style.pointerEvents = 'auto';
            try {
                handle.setPointerCapture(event.pointerId);
            } catch {
                cancelActiveResize();
            }
        });
        overlay.append(handle);
        handles.push(handle);
    }
    if (handles.length === 0) return cleanup;
    overlay.style.pointerEvents = 'none';
    // One overlay listener set receives captured handle events and moves that hit the active shield after capture loss.
    overlay.addEventListener('pointermove', onPointerMove);
    overlay.addEventListener('pointerup', onPointerUp);
    overlay.addEventListener('pointercancel', onPointerCancel);
    parentWindow.addEventListener('resize', repositionHandles, {passive: true});
    parentWindow.addEventListener('scroll', repositionHandles, {capture: true, passive: true});
    previewWindow.addEventListener('resize', repositionHandles, {passive: true});
    previewWindow.addEventListener('scroll', repositionHandles, {capture: true, passive: true});
    frame.addEventListener('load', cleanup, {once: true});
    parentWindow.document.addEventListener('keydown', onKeyDown, true);
    repositionHandles();
    return cleanup;
}
