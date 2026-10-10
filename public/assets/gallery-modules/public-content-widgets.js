/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/public-content-widgets.js
 * Module Type: Browser Module
 * Purpose: Progressively enhance published floating content widgets without changing their source markup.
 * Responsibilities:
 *   - Keep all content in normal document flow if scripting, viewport space or placement fails.
 *   - Place at most two bounded, dismissible panels below existing public dialog layers.
 *   - Avoid navigation, back-to-top controls and other positioned widgets on viewport changes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

const FLOAT_MIN_VIEWPORT_WIDTH = 960;
const FLOAT_MIN_VIEWPORT_HEIGHT = 620;
const FLOAT_LIMIT = 2;
const FLOAT_EDGE_PADDING = 16;
const FLOAT_GAP = 12;

/**
 * Compute a bounded non-overlapping viewport rectangle for one floating panel.
 *
 * This is deliberately pure so invalid offsets and screen-space collisions can
 * be regression-tested without a DOM or privileged administrative context.
 *
 * @param {{anchor:string,x:number,y:number,width:number,height:number,viewportWidth:number,viewportHeight:number,topInset:number,exclusions?:Array<{left:number,top:number,width:number,height:number}>}} options Validated semantic server data and currently occupied boxes.
 * @returns {{left:number,top:number,width:number,height:number}|null} Visible panel box or a signal to retain the inline fallback.
 */
export function resolvePublicWidgetFloatingRect(options) {
    const {anchor, viewportWidth, viewportHeight, topInset} = options;
    const width = options.width;
    const height = options.height;
    const leftEdge = FLOAT_EDGE_PADDING;
    const topEdge = Math.max(FLOAT_EDGE_PADDING, topInset);
    const rightEdge = viewportWidth - width - FLOAT_EDGE_PADDING;
    const bottomEdge = viewportHeight - height - FLOAT_EDGE_PADDING;
    if (![viewportWidth, viewportHeight, width, height, topInset, options.x, options.y].every(Number.isFinite)
        || width < 1 || height < 1 || leftEdge > rightEdge || topEdge > bottomEdge
        || width > viewportWidth * 0.45 || height > viewportHeight * 0.42) {
        return null;
    }

    const accepted = ['top-left', 'top-center', 'top-right',
        'middle-left', 'middle-right', 'bottom-left', 'bottom-center',
        'bottom-right', 'custom'];
    if (!accepted.includes(anchor) || options.x < 0 || options.x > 1000 || options.y < 0 || options.y > 1000) {
        return null;
    }
    const atX = anchor === 'custom'
        ? leftEdge + (rightEdge - leftEdge) * options.x / 1000
        : anchor.endsWith('left') ? leftEdge
            : anchor.endsWith('right') ? rightEdge : (leftEdge + rightEdge) / 2;
    const atY = anchor === 'custom'
        ? topEdge + (bottomEdge - topEdge) * options.y / 1000
        : anchor.startsWith('top') ? topEdge
            : anchor.startsWith('bottom') ? bottomEdge : (topEdge + bottomEdge) / 2;
    const occupied = Array.isArray(options.exclusions) ? options.exclusions : [];
    const prefersUp = anchor.startsWith('bottom') || (anchor === 'custom' && options.y >= 500);

    for (let step = 0; step <= 40; step += 1) {
        const offset = step === 0 ? 0 : Math.ceil(step / 2) * 24 * (step % 2 === 1 ? 1 : -1) * (prefersUp ? -1 : 1);
        const candidateTop = Math.max(topEdge, Math.min(bottomEdge, atY + offset));
        const candidate = {
            left: Math.round(atX), top: Math.round(candidateTop), width, height,
        };
        const overlaps = occupied.some((box) =>
            candidate.left < box.left + box.width + FLOAT_GAP
            && candidate.left + candidate.width + FLOAT_GAP > box.left
            && candidate.top < box.top + box.height + FLOAT_GAP
            && candidate.top + candidate.height + FLOAT_GAP > box.top);
        if (!overlaps) {
            return candidate;
        }
    }
    return null;
}

/**
 * Return a visible, viewport-relative protected control rectangle when present.
 *
 * @param {Element} element Header navigation, action control, or fixed page tool.
 * @param {number} viewportWidth Visible layout viewport width.
 * @param {number} viewportHeight Visible layout viewport height.
 * @returns {{left:number,top:number,width:number,height:number}|null} Protected rectangle or no visible intersection.
 */
function visiblePublicWidgetExclusion(element, viewportWidth, viewportHeight) {
    if (element.hidden || element.closest('[hidden]')) {
        return null;
    }
    const bounds = element.getBoundingClientRect();
    if (bounds.width < 1 || bounds.height < 1
        || bounds.right <= 0 || bounds.bottom <= 0
        || bounds.left >= viewportWidth || bounds.top >= viewportHeight) {
        return null;
    }
    return {
        left: Math.max(0, bounds.left),
        top: Math.max(0, bounds.top),
        width: Math.min(viewportWidth, bounds.right) - Math.max(0, bounds.left),
        height: Math.min(viewportHeight, bounds.bottom) - Math.max(0, bounds.top),
    };
}

/**
 * Read one bounded integer from a sanitized data attribute, rejecting edited DOM values.
 *
 * @param {string|undefined} input Encoded saved width or normalized permille.
 * @param {number} fallback Safe value for missing attributes.
 * @param {number} minimum Lowest accepted value.
 * @param {number} maximum Highest accepted value.
 * @returns {number} Valid integral coordinate or fallback.
 */
function publicWidgetBoundedNumber(input, fallback, minimum, maximum) {
    if (typeof input !== 'string' || !/^(0|[1-9]\d*)$/.test(input)) {
        return fallback;
    }
    const number = Number(input);
    return Number.isSafeInteger(number) && number >= minimum && number <= maximum ? number : fallback;
}

/**
 * Install visitor-only placement and accessible dismiss controls for public widgets.
 *
 * The original article is rendered once by PHP in normal flow. An unavailable
 * script, narrow viewport, full-size panel, or unsafe collision cannot cause a
 * missing widget or expose unpublished content. No configuration writes occur.
 *
 * @returns {void} Register responsive floating behavior on eligible public pages only.
 */
export function setupPublicContentWidgets() {
    if (!document.body.classList.contains('public-page')) {
        return;
    }
    const widgets = Array.from(document.querySelectorAll('[data-public-widget-floating="1"]'));
    if (widgets.length === 0) {
        return;
    }
    let pendingFrame = false;

    /**
     * Return a floating widget to its server-rendered in-flow fallback.
     *
     * @param {HTMLElement} widget Only the published widget article being laid out.
     * @returns {void} Remove the viewport-specific CSS and inline measurements.
     */
    function restoreFlow(widget) {
        widget.classList.remove('is-public-widget-floating');
        widget.style.removeProperty('left');
        widget.style.removeProperty('top');
        widget.style.removeProperty('width');
    }

    /**
     * Place a bounded number of panels and retain all others in the DOM flow.
     *
     * @returns {void} Apply CSS only to rectangles that do not obscure protected controls.
     */
    function updateFloatingWidgets() {
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
        for (const widget of widgets) {
            restoreFlow(widget);
        }
        if (viewportWidth < FLOAT_MIN_VIEWPORT_WIDTH || viewportHeight < FLOAT_MIN_VIEWPORT_HEIGHT
            || window.visualViewport && window.visualViewport.scale > 1.05) {
            return;
        }

        const header = document.querySelector('.site-header');
        const headerBounds = header ? header.getBoundingClientRect() : null;
        const topInset = headerBounds && headerBounds.bottom > 0
            ? Math.max(FLOAT_EDGE_PADDING, headerBounds.bottom + 12) : FLOAT_EDGE_PADDING;
        if (topInset > viewportHeight * 0.45) {
            return;
        }
        const exclusions = Array.from(document.querySelectorAll(
            '.site-header, .public-home-actions, .back-to-top-button:not([hidden]), .picture-manager-toolbar, .nav'
        )).map((element) => visiblePublicWidgetExclusion(element, viewportWidth, viewportHeight)).filter(Boolean);
        let visible = 0;
        for (const widget of widgets) {
            if (widget.hidden || visible >= FLOAT_LIMIT) {
                continue;
            }
            const persistedWidth = publicWidgetBoundedNumber(widget.dataset.publicWidgetWidth, 320, 180, 480);
            const width = Math.min(persistedWidth, viewportWidth - 2 * FLOAT_EDGE_PADDING);
            widget.style.width = width + 'px';
            const height = Math.ceil(widget.getBoundingClientRect().height);
            const anchor = widget.dataset.publicWidgetAnchor || 'bottom-right';
            const rect = resolvePublicWidgetFloatingRect({
                anchor,
                x: publicWidgetBoundedNumber(widget.dataset.publicWidgetX, 900, 0, 1000),
                y: publicWidgetBoundedNumber(widget.dataset.publicWidgetY, 900, 0, 1000),
                width, height, viewportWidth, viewportHeight, topInset, exclusions,
            });
            if (rect === null) {
                restoreFlow(widget);
                continue;
            }
            widget.classList.add('is-public-widget-floating');
            widget.style.left = rect.left + 'px';
            widget.style.top = rect.top + 'px';
            exclusions.push(rect);
            visible += 1;
        }
    }

    /**
     * Coalesce resize, scroll and visual-viewport changes to one geometry pass.
     *
     * @returns {void} Enqueue a fresh placement evaluation.
     */
    function scheduleFloatingWidgets() {
        if (pendingFrame) {
            return;
        }
        pendingFrame = true;
        window.requestAnimationFrame(() => {
            pendingFrame = false;
            updateFloatingWidgets();
        });
    }

    for (const widget of widgets) {
        if (!(widget instanceof HTMLElement)) {
            continue;
        }
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'public-widget-dismiss';
        close.textContent = '\u00d7';
        close.setAttribute('aria-label', widget.dataset.publicWidgetDismissLabel || 'Close');
        close.title = widget.dataset.publicWidgetDismissLabel || 'Close';
        close.addEventListener('click', () => {
            widget.hidden = true;
            scheduleFloatingWidgets();
        });
        widget.prepend(close);
    }
    window.addEventListener('resize', scheduleFloatingWidgets, {passive: true});
    window.addEventListener('scroll', scheduleFloatingWidgets, {passive: true});
    window.visualViewport?.addEventListener('resize', scheduleFloatingWidgets, {passive: true});
    window.visualViewport?.addEventListener('scroll', scheduleFloatingWidgets, {passive: true});
    scheduleFloatingWidgets();
}
