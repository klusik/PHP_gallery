/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/public-content-widgets.js
 * Module Type: Browser Module
 * Purpose: Share floating-widget geometry and progressively enhance published visitor widgets.
 * Responsibilities:
 *   - Keep all content in normal document flow if scripting, viewport space or placement fails.
 *   - Place at most two bounded, dismissible panels below existing public dialog layers.
 *   - Preserve a visible keyboard focus destination when a floating article is dismissed.
 *   - Expose the bounded public placement geometry for the protected Admin preview.
 *   - Keep floating panels inside measured CSS safe-area insets and public controls.
 *   - Avoid navigation, Home search, Gallery hero actions, back-to-top controls and other positioned widgets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Minimum desktop width for a fixed, visitor-facing widget enhancement.
 * Type: number. Units: CSS pixels. Scope: public document layout.
 * Consumers: readPublicWidgetFloatingGeometry public and Admin viewport admission.
 * Rationale: reserve sufficient primary-content and navigation width before overlay placement.
 */
const FLOAT_MIN_VIEWPORT_WIDTH = 960;

/**
 * Minimum viewport height for an unobstructed desktop floating panel.
 * Type: number. Units: CSS pixels. Scope: public document layout.
 * Consumers: readPublicWidgetFloatingGeometry public and Admin viewport admission.
 * Rationale: small browser windows must retain the accessible inline article instead of an overlay.
 */
const FLOAT_MIN_VIEWPORT_HEIGHT = 620;

/**
 * Maximum simultaneous floating panels regardless of configured widget count.
 * Type: number. Units: visitor-visible floating articles per page.
 * Scope: one public-page display. Consumers: public runtime and protected Admin preview candidate loops.
 * Rationale: prevent stacked overlays from consuming most of the viewport.
 */
export const FLOAT_LIMIT = 2;

/**
 * Minimum margin between a floating article and its usable viewport edges.
 * Type: number. Units: CSS pixels. Scope: fixed public widget geometry.
 * Consumers: readPublicWidgetFloatingGeometry available width and top inset, and resolvePublicWidgetFloatingRect edge clamping.
 * Rationale: keep the complete panel, focus ring and close control reachable.
 */
const FLOAT_EDGE_PADDING = 16;

/**
 * Minimum floating-panel width that retains the published widget width contract.
 * Type: number. Units: CSS pixels. Scope: public floating widgets and Admin public-page previews.
 * Consumers: readPublicWidgetFloatingGeometry admission and publicWidgetBoundedNumber width bounds.
 * Rationale: a safe-area-constrained viewport must retain the existing 180px panel minimum or use normal flow.
 */
export const FLOAT_MIN_PANEL_WIDTH = 180;

/**
 * Cache one hidden safe-area probe for each visitor or preview document.
 * Type: WeakMap<Document, HTMLDivElement>. Units: probe elements per document.
 * Scope: public widget geometry and isolated Admin public-page previews.
 * Consumers: readPublicWidgetSafeAreaInsets.
 * Rationale: reuse the document-local CSS environment probe while allowing each geometry pass to read fresh computed values.
 */
const FLOAT_SAFE_AREA_PROBES = new WeakMap();

/**
 * Minimum gap used when rejecting overlaps with existing controls and panels.
 * Type: number. Units: CSS pixels. Scope: viewport collision calculations.
 * Consumers: resolvePublicWidgetFloatingRect overlap checks.
 * Rationale: preserve separation between widget controls, gallery actions and navigation.
 */
const FLOAT_GAP = 12;

/**
 * Return public controls whose measured rectangles constrain floating widgets.
 * Keep the observer targets identical to the solver exclusions so changes to a
 * fixed toolbar or page action schedule the same geometry calculation.
 *
 * @param {Document} doc Public document containing the visitor controls.
 * @returns {Array<Element>} Public controls and primary discovery previews.
 */
function publicWidgetFloatingControlElements(doc) {
    return Array.from(doc.querySelectorAll(
        '.site-header, .public-home-actions, .public-home-search, '
        + '.hero[data-public-gallery-id] .hero-actions, .back-to-top-button, '
        + '.picture-manager-toolbar, [data-picture-manager-panel], .nav, '
        + '.gallery-card-media, .gallery-image-grid .image-stage'
    ));
}

/**
 * Rebind measured public controls and schedule placement after an owned fragment replacement.
 *
 * @param {Document} doc Public document whose protected controls may have been replaced.
 * @returns {void} Observe current control elements and enqueue one coalesced geometry pass.
 */
export function refreshPublicWidgetFloatingGeometry(doc) {
    const lifecycle = FLOAT_GEOMETRY_LIFECYCLES.get(doc);
    if (!lifecycle) {
        return;
    }
    const currentControls = new Set(publicWidgetFloatingControlElements(doc));
    if (lifecycle.observer) {
        for (const previousControl of lifecycle.controls) {
            if (!currentControls.has(previousControl)) {
                lifecycle.observer.unobserve(previousControl);
            }
        }
        for (const control of currentControls) {
            if (!lifecycle.controls.has(control)) {
                lifecycle.observer.observe(control);
            }
        }
    }
    lifecycle.controls = currentControls;
    lifecycle.schedule();
}

/**
 * Report whether two viewport rectangles conflict under the public solver clearance rule.
 *
 * Both dimensions expand by the same bounded gap used for public controls and
 * already placed floating panels, so Admin warnings can match visitor layout.
 *
 * @param {{left:number,top:number,width:number,height:number}} first First viewport-relative rectangle.
 * @param {{left:number,top:number,width:number,height:number}} second Second viewport-relative rectangle.
 * @returns {boolean} True when the rectangles overlap or violate the public solver gap.
 */
export function publicWidgetRectConflicts(first, second) {
    return first.left < second.left + second.width + FLOAT_GAP
        && first.left + first.width + FLOAT_GAP > second.left
        && first.top < second.top + second.height + FLOAT_GAP
        && first.top + first.height + FLOAT_GAP > second.top;
}

/**
 * Prevent duplicate visitor controls and event listeners when public bootstrap repeats.
 * Type: WeakSet<HTMLElement>. Units: initialized document roots.
 * Scope: one browser module lifetime. Consumers: setupPublicContentWidgets.
 * Rationale: repeated module initialization must not duplicate dismissal or geometry work.
 */
const FLOAT_INITIALIZED_ROOTS = new WeakSet();

/**
 * Retain each initialized document's coalesced geometry refresh and observer targets.
 * Type: WeakMap<Document, {schedule:()=>void,observer:ResizeObserver|null,controls:Set<Element>}>
 * Units: one lifecycle record per initialized public document.
 * Scope: the public widget browser module lifetime.
 * Consumers: refreshPublicWidgetFloatingGeometry after coordinator-owned fragment replacement.
 * Rationale: rebind only replaced controls without reinstalling widget dismissal handlers.
 */
const FLOAT_GEOMETRY_LIFECYCLES = new WeakMap();

/**
 * Compute a bounded non-overlapping rectangle inside the inset-aware usable viewport.
 *
 * This is deliberately pure so invalid offsets and screen-space collisions can
 * be regression-tested without a DOM or privileged administrative context.
 *
 * @param {{anchor:string,x:number,y:number,width:number,height:number,viewportWidth:number,viewportHeight:number,topInset:number,safeAreaInsets?:{top:number,right:number,bottom:number,left:number},exclusions?:Array<{left:number,top:number,width:number,height:number}>}} options Validated semantic server data, safe-area insets and currently occupied boxes.
 * @returns {{left:number,top:number,width:number,height:number}|null} Visible panel box or a signal to retain the inline fallback.
 */
export function resolvePublicWidgetFloatingRect(options) {
    const {anchor, viewportWidth, viewportHeight, topInset} = options;
    const width = options.width;
    const height = options.height;
    const safeAreaInsets = options.safeAreaInsets ?? {top: 0, right: 0, bottom: 0, left: 0};
    const leftEdge = FLOAT_EDGE_PADDING + safeAreaInsets.left;
    const topEdge = Math.max(FLOAT_EDGE_PADDING + safeAreaInsets.top, topInset);
    const rightEdge = viewportWidth - width - FLOAT_EDGE_PADDING - safeAreaInsets.right;
    const bottomEdge = viewportHeight - height - FLOAT_EDGE_PADDING - safeAreaInsets.bottom;
    if (![viewportWidth, viewportHeight, width, height, topInset, options.x, options.y].every(Number.isFinite)
        || ![safeAreaInsets.top, safeAreaInsets.right, safeAreaInsets.bottom, safeAreaInsets.left].every(Number.isFinite)
        || [safeAreaInsets.top, safeAreaInsets.right, safeAreaInsets.bottom, safeAreaInsets.left].some(inset => inset < 0)
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
            left: Math.max(leftEdge, Math.min(rightEdge, Math.round(atX))),
            top: Math.max(topEdge, Math.min(bottomEdge, Math.round(candidateTop))),
            width, height,
        };
        const overlaps = occupied.some(box => publicWidgetRectConflicts(candidate, box));
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
 * Read the four CSS safe-area environment values through a document-local hidden probe.
 * @param {Document} doc Public page document whose safe-area environment is measured.
 * @param {Window} win Window that computes styles for the document's probe.
 * @returns {{top:number,right:number,bottom:number,left:number}} Nonnegative insets in CSS pixels, defaulting unsupported or invalid values to zero.
 */
function readPublicWidgetSafeAreaInsets(doc, win) {
    if (!doc || typeof doc.createElement !== 'function'
        || !win || typeof win.getComputedStyle !== 'function') {
        return {top: 0, right: 0, bottom: 0, left: 0};
    }
    let probe = FLOAT_SAFE_AREA_PROBES.get(doc);
    if (!probe) {
        const parent = doc.body || doc.documentElement;
        if (!parent || typeof parent.appendChild !== 'function') {
            return {top: 0, right: 0, bottom: 0, left: 0};
        }
        probe = doc.createElement('div');
        probe.setAttribute('data-public-widget-safe-area-probe', '1');
        probe.setAttribute('aria-hidden', 'true');
        probe.style.cssText = [
            'all:initial', 'display:block', 'position:fixed', 'left:-10000px', 'top:-10000px',
            'width:0', 'height:0', 'margin:0', 'border:0', 'transform:none',
            'padding-top:env(safe-area-inset-top, 0px)',
            'padding-right:env(safe-area-inset-right, 0px)',
            'padding-bottom:env(safe-area-inset-bottom, 0px)',
            'padding-left:env(safe-area-inset-left, 0px)',
            'box-sizing:content-box', 'visibility:hidden', 'pointer-events:none', 'overflow:hidden',
        ].join(';');
        parent.appendChild(probe);
        FLOAT_SAFE_AREA_PROBES.set(doc, probe);
    }

    const computed = win.getComputedStyle(probe);
    const readInset = value => {
        const inset = Number.parseFloat(value);
        return Number.isFinite(inset) && inset > 0 ? inset : 0;
    };
    return {
        top: readInset(computed.paddingTop),
        right: readInset(computed.paddingRight),
        bottom: readInset(computed.paddingBottom),
        left: readInset(computed.paddingLeft),
    };
}

/**
 * Read public safe-area insets, viewport bounds and visible controls that floating widgets must avoid.
 *
 * Admin previews pass the isolated public document and its window so placement
 * uses the same measured Theme controls and viewport policy as visitor pages.
 * Gallery detail action buttons join Home controls and fixed tools as exclusions.
 *
 * @param {Document} doc Public page document whose layout is being measured.
 * @param {Window} win Window owning the document and optional visual viewport.
 * @returns {{viewportWidth:number,viewportHeight:number,topInset:number,safeAreaInsets:{top:number,right:number,bottom:number,left:number},availableWidth:number,exclusions:Array<{left:number,top:number,width:number,height:number}>,supported:boolean}} Measured geometry and whether an overlay is allowed.
 */
export function readPublicWidgetFloatingGeometry(doc, win) {
    const viewportWidth = doc.documentElement.clientWidth;
    const viewportHeight = win.visualViewport ? win.visualViewport.height : win.innerHeight;
    const scale = win.visualViewport ? win.visualViewport.scale : 1;
    const safeAreaInsets = readPublicWidgetSafeAreaInsets(doc, win);
    const availableWidth = Math.max(0, viewportWidth - (2 * FLOAT_EDGE_PADDING)
        - safeAreaInsets.left - safeAreaInsets.right);
    const header = doc.querySelector('.site-header');
    const headerBounds = header ? header.getBoundingClientRect() : null;
    const topInset = headerBounds && headerBounds.bottom > 0
        ? Math.max(FLOAT_EDGE_PADDING + safeAreaInsets.top, headerBounds.bottom + 12)
        : FLOAT_EDGE_PADDING + safeAreaInsets.top;
    const supported = viewportWidth >= FLOAT_MIN_VIEWPORT_WIDTH
        && viewportHeight >= FLOAT_MIN_VIEWPORT_HEIGHT
        && availableWidth >= FLOAT_MIN_PANEL_WIDTH
        && scale <= 1.05
        && topInset <= viewportHeight * 0.45;
    const exclusions = supported ? publicWidgetFloatingControlElements(doc)
        .map((element) => visiblePublicWidgetExclusion(element, viewportWidth, viewportHeight)).filter(Boolean) : [];
    return {viewportWidth, viewportHeight, topInset, safeAreaInsets, availableWidth, exclusions, supported};
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
export function publicWidgetBoundedNumber(input, fallback, minimum, maximum) {
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
    if (widgets.length === 0 || FLOAT_INITIALIZED_ROOTS.has(document.body)) {
        return;
    }
    FLOAT_INITIALIZED_ROOTS.add(document.body);
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
        const geometry = readPublicWidgetFloatingGeometry(document, window);
        for (const widget of widgets) {
            restoreFlow(widget);
        }
        if (!geometry.supported) {
            return;
        }
        const {viewportWidth, viewportHeight, topInset, safeAreaInsets, availableWidth, exclusions} = geometry;
        let visible = 0;
        for (const widget of widgets) {
            if (widget.hidden || visible >= FLOAT_LIMIT) {
                continue;
            }
            const persistedWidth = publicWidgetBoundedNumber(
                widget.dataset.publicWidgetWidth, 320, FLOAT_MIN_PANEL_WIDTH, 480);
            const width = Math.min(persistedWidth, availableWidth);
            widget.style.width = width + 'px';
            // Measure with the floating CSS cap so long content can scroll inside a valid bounded panel.
            widget.classList.add('is-public-widget-floating');
            const height = Math.ceil(widget.getBoundingClientRect().height);
            widget.classList.remove('is-public-widget-floating');
            const anchor = widget.dataset.publicWidgetAnchor || 'bottom-right';
            const rect = resolvePublicWidgetFloatingRect({
                anchor,
                x: publicWidgetBoundedNumber(widget.dataset.publicWidgetX, 900, 0, 1000),
                y: publicWidgetBoundedNumber(widget.dataset.publicWidgetY, 900, 0, 1000),
                width, height, viewportWidth, viewportHeight, topInset, safeAreaInsets, exclusions,
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
     * Coalesce viewport, observed-size, font and fragment changes to one geometry pass.
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

    // Observe content and protected controls, not the outer widget articles.
    // The solver temporarily switches an article between flow and fixed while
    // measuring; that transient outer-box change must not feed back into another
    // observation cycle. Content-box changes settle after the current layout
    // task, so width wrapping or height changes schedule one fresh coalesced pass.
    let geometryObserver = null;
    const observedControls = new Set(publicWidgetFloatingControlElements(document));
    if (typeof ResizeObserver === 'function') {
        geometryObserver = new ResizeObserver(() => scheduleFloatingWidgets());
        for (const widget of widgets) {
            const title = widget.querySelector('.public-content-widget-title');
            const body = widget.querySelector('.public-content-widget-body');
            if (title) geometryObserver.observe(title);
            if (body) geometryObserver.observe(body);
        }
        for (const control of observedControls) {
            geometryObserver.observe(control);
        }
    }
    FLOAT_GEOMETRY_LIFECYCLES.set(document, {
        schedule: scheduleFloatingWidgets,
        observer: geometryObserver,
        controls: observedControls,
    });
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
        document.fonts.ready.then(scheduleFloatingWidgets, scheduleFloatingWidgets);
    }

    /**
     * Keep keyboard focus on a useful public control after hiding the focused widget.
     *
     * @param {HTMLElement} widget Floating article whose Close control was activated.
     * @returns {void} Focus the next visible floating Close control or page control without scrolling.
     * When no eligible control exists, the browser keeps its normal document focus fallback.
     */
    function focusAfterPublicWidgetDismissal(widget) {
        const floatingCloseButtons = widgets
            .filter(candidate => candidate !== widget && !candidate.hidden
                && candidate.classList.contains('is-public-widget-floating'))
            .map(candidate => candidate.querySelector('.public-widget-dismiss'))
            .filter(button => button instanceof HTMLButtonElement && !button.disabled
                && button.getClientRects().length > 0);
        const followingFloatingClose = floatingCloseButtons.find(button =>
            (widget.compareDocumentPosition(button) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0);
        const floatingClose = followingFloatingClose ?? floatingCloseButtons[0];
        if (floatingClose instanceof HTMLButtonElement) {
            floatingClose.focus({preventScroll: true});
            return;
        }

        const focusableSelector = 'a[href], area[href], button, input:not([type="hidden"]), '
            + 'select, textarea, [tabindex], [contenteditable="true"]';
        const pageControls = Array.from(document.querySelectorAll(focusableSelector))
            .filter(control => control instanceof HTMLElement && !widget.contains(control)
                && control.tabIndex >= 0 && !control.matches(':disabled')
                && !control.closest('[hidden], [inert], [aria-hidden="true"]')
                && getComputedStyle(control).visibility === 'visible'
                && control.getClientRects().length > 0);
        const followingPageControl = pageControls.find(control =>
            (widget.compareDocumentPosition(control) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0);
        (followingPageControl ?? pageControls[0])?.focus({preventScroll: true});
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
            const preserveFocus = document.activeElement === close;
            widget.hidden = true;
            scheduleFloatingWidgets();
            if (preserveFocus) {
                window.requestAnimationFrame(() => {
                    const activeElement = document.activeElement;
                    if (activeElement !== document.body && activeElement !== document.documentElement
                        && !widget.contains(activeElement)) {
                        return;
                    }
                    focusAfterPublicWidgetDismissal(widget);
                });
            }
        });
        widget.prepend(close);
    }
    window.addEventListener('resize', scheduleFloatingWidgets, {passive: true});
    window.addEventListener('scroll', scheduleFloatingWidgets, {passive: true});
    window.visualViewport?.addEventListener('resize', scheduleFloatingWidgets, {passive: true});
    window.visualViewport?.addEventListener('scroll', scheduleFloatingWidgets, {passive: true});
    document.addEventListener('click', event => {
        if (event.target instanceof Element && event.target.closest('.picture-manager-toolbar')) {
            scheduleFloatingWidgets();
        }
    });
    document.addEventListener('change', event => {
        if (event.target instanceof Element && event.target.closest('.picture-manager-toolbar')) {
            scheduleFloatingWidgets();
        }
    });
    scheduleFloatingWidgets();
}
