/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/hero-tags.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Preserves the server-rendered native disclosure in gallery headers and
 *   cards while supporting older button-based tag markup during refreshes.
 *
 * Responsibilities:
 *   - Leave native details-based disclosure and CSS row limits untouched
 *   - Keep compatibility behavior for older button-based fragment markup
 *   - Initialize newly inserted tag collections without duplicate handlers
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Current PHP views emit the configured visible tags and native details
 *     disclosure in the initial HTML, including when JavaScript is disabled.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-10-03
 */

/**
 * Parse and clamp one integer data attribute.
 *
 * @param {string|null} value Raw attribute value.
 * @param {number} fallback Value used for missing or invalid input.
 * @param {number} minimum Lowest accepted value.
 * @param {number} maximum Highest accepted value.
 * @return {number} Normalized integer value.
 */
function heroTagInteger(value, fallback, minimum, maximum) {
    const parsed = Number.parseInt(value || '', 10);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }
    return Math.max(minimum, Math.min(maximum, parsed));
}

/**
 * Return visual rows occupied by currently visible clickable or read-only tags.
 *
 * Nodes sharing nearly the same vertical origin are treated as one row. Each
 * row also records its lowest bottom edge so the applied max-height never clips
 * the last allowed row even when tags have slightly different heights.
 *
 * @param {HTMLElement} content Hero tag content container.
 * @return {Array<{top:number,bottom:number}>} Measured visual rows.
 */
function heroTagVisualRows(content) {
    const contentRect = content.getBoundingClientRect();
    const nodes = Array.from(content.querySelectorAll('.tag-list-label, .tag-list .tag')).filter((node) => {
        return node instanceof HTMLElement && !node.hidden && node.getClientRects().length > 0;
    });
    const rows = [];
    nodes.forEach((node) => {
        const rect = node.getBoundingClientRect();
        const top = rect.top - contentRect.top;
        const bottom = rect.bottom - contentRect.top;
        const row = rows.find((candidate) => Math.abs(candidate.top - top) <= 2);
        if (row) {
            row.bottom = Math.max(row.bottom, bottom);
            return;
        }
        rows.push({ top, bottom });
    });
    rows.sort((left, right) => left.top - right.top);
    return rows;
}

/**
 * Apply the configured row-based scrollbar after current tag visibility settles.
 *
 * @param {HTMLElement} root Hero tag root.
 * @param {HTMLElement} content Hero tag content container.
 */
function syncHeroTagScrollbar(root, content) {
    const enabled = root.dataset.heroTagScrollbarEnabled !== '0';
    const allowedRows = heroTagInteger(root.dataset.heroTagScrollbarRows || null, 5, 1, 12);

    // Measure the natural wrapped layout first. This prevents a previous
    // max-height from affecting the row calculation after expansion or resize.
    content.classList.remove('is-scrollable');
    content.style.removeProperty('max-height');
    if (!enabled) {
        return;
    }

    const rows = heroTagVisualRows(content);
    if (rows.length <= allowedRows) {
        return;
    }

    const lastVisibleRow = rows[allowedRows - 1];
    // Two pixels of breathing room prevents antialiasing or fractional layout
    // coordinates from clipping the bottom border of the last visible tag row.
    content.style.maxHeight = `${Math.ceil(lastVisibleRow.bottom + 2)}px`;
    content.classList.add('is-scrollable');
}

/**
 * Keep a card's inline toggle beside the last currently displayed tag.
 *
 * @param {HTMLElement} content Tag content container.
 * @param {HTMLButtonElement} toggle Accessible expand/collapse button.
 * @param {HTMLElement|null} lastTag Last displayed tag, or null for an empty collection.
 * @return {void} Repositions the tail pair without changing the tag order.
 */
function syncInlineTagToggle(content, toggle, lastTag) {
    const previousTail = content.querySelector('[data-hero-tags-tail]');
    if (previousTail) {
        const previousTag = previousTail.querySelector('.tag:not([data-hero-tags-toggle])');
        if (previousTag) {
            previousTail.before(previousTag);
        }
        previousTail.before(toggle);
        previousTail.remove();
    }
    if (!lastTag || toggle.hidden) {
        return;
    }
    const tail = document.createElement('span');
    tail.className = 'gallery-card-tag-tail';
    tail.setAttribute('data-hero-tags-tail', '');
    lastTag.before(tail);
    tail.append(lastTag, toggle);
}

/**
 * Preserve native server-rendered disclosure or initialize legacy tag markup.
 *
 * @param {HTMLElement} root Hero tag root element.
 * @return {void} Leaves native roots untouched and enhances legacy markup only.
 */
function setupHeroTagRoot(root) {
    // Current server-rendered roots are already complete; avoid even a marker
    // mutation because offscreen content-visibility cards use intrinsic size.
    if (root.dataset.heroTagsNative === '1') {
        return;
    }
    if (root.dataset.heroTagsReady === '1') {
        return;
    }
    root.dataset.heroTagsReady = '1';

    const content = root.querySelector('[data-hero-tags-content]');
    const toggle = root.querySelector('[data-hero-tags-toggle]');
    if (!(content instanceof HTMLElement)) {
        return;
    }

    const tags = Array.from(content.querySelectorAll('.tag-list .tag:not([data-hero-tags-toggle])')).filter((node) => node instanceof HTMLElement);
    const visibleLimit = heroTagInteger(root.dataset.heroTagVisibleLimit || null, 20, 1, 200);
    const displayAllImmediately = root.dataset.heroTagDisplayAll === '1';
    const needsDisclosure = !displayAllImmediately && tags.length > visibleLimit && toggle instanceof HTMLButtonElement;
    let expanded = !needsDisclosure;

    /**
     * Hide labels for groups with no currently visible tag anchors.
     * @return {void} Updates each group's label visibility.
     */
    const syncGroupLabels = () => {
        content.querySelectorAll('.tag-list').forEach(/**
         * Hide the group label when every tag in the list is collapsed.
         * @param {HTMLElement} list Tag group containing its label and anchors.
         * @return {void} Updates the label when the group has one.
         */ (list) => {
            const label = list.querySelector('.tag-list-label');
            if (!(label instanceof HTMLElement)) {
                return;
            }
            const hasVisibleTag = Array.from(list.querySelectorAll('.tag:not([data-hero-tags-toggle])')).some((tag) => tag instanceof HTMLElement && !tag.hidden);
            label.hidden = !hasVisibleTag;
        });
    };

    /**
     * Apply collapsed or expanded visibility and refresh all dependent UI.
     * @return {void} Updates tags, toggle placement and scrollbar bounds.
     */
    const renderState = () => {
        tags.forEach((tag, index) => {
            tag.hidden = !expanded && index >= visibleLimit;
        });
        syncGroupLabels();

        if (toggle instanceof HTMLButtonElement) {
            toggle.hidden = !needsDisclosure;
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const showAllLabel = toggle.dataset.showAllLabel || 'Display all tags';
            const showFewerLabel = toggle.dataset.showFewerLabel || 'Show fewer tags';
            const label = expanded ? showFewerLabel : showAllLabel;
            toggle.setAttribute('aria-label', label);
            if (root.dataset.heroTagInlineToggle === '1') {
                toggle.textContent = '[...]';
                toggle.title = label;
                const lastVisibleTag = expanded ? tags[tags.length - 1] : tags[Math.min(visibleLimit, tags.length) - 1];
                syncInlineTagToggle(content, toggle, lastVisibleTag || null);
            } else {
                toggle.textContent = label;
            }
        }
        syncHeroTagScrollbar(root, content);
    };

    if (toggle instanceof HTMLButtonElement && needsDisclosure) {
        toggle.addEventListener('click', () => {
            expanded = !expanded;
            if (!expanded) {
                content.scrollTop = 0;
            }
            renderState();
        });
    }

    renderState();

    // Wrapping depends on the available hero width. Observe width only so our
    // own max-height changes cannot create a ResizeObserver feedback loop.
    let lastObservedWidth = Math.round(root.getBoundingClientRect().width);
    let resizeFrame = 0;
    /** Coalesce scrollbar measurements into the next animation frame. */
    const scheduleScrollbarSync = () => {
        if (resizeFrame !== 0) {
            cancelAnimationFrame(resizeFrame);
        }
        resizeFrame = requestAnimationFrame(() => {
            resizeFrame = 0;
            syncHeroTagScrollbar(root, content);
        });
    };

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver((entries) => {
            const width = Math.round(entries[0]?.contentRect.width || root.getBoundingClientRect().width);
            if (width === lastObservedWidth) {
                return;
            }
            lastObservedWidth = width;
            scheduleScrollbarSync();
        });
        observer.observe(root);
    } else {
        window.addEventListener('resize', scheduleScrollbarSync, { passive: true });
    }
}

/**
 * Set up Theme-controlled tags and cover collections inserted by page refreshes.
 *
 * @return {void} Initializes existing roots and observes newly inserted collections.
 */
export function setupHeroTagDisclosure() {
    setupGalleryCardInfoPanels();
    document.querySelectorAll('[data-hero-tags]').forEach((root) => {
        if (root instanceof HTMLElement) {
            setupHeroTagRoot(root);
        }
    });
    if (heroTagInsertionObserver || !document.body || !('MutationObserver' in window)) {
        return;
    }
    heroTagInsertionObserver = new MutationObserver(/**
     * Initialize tag collections added by a gallery fragment refresh.
     * @param {MutationRecord[]} records Observed changes to the document subtree.
     * @return {void} Visits newly inserted nodes without observing them twice.
     */ (records) => {
        records.forEach(/**
         * Visit the nodes inserted by one observed document change.
         * @param {MutationRecord} record Child-list mutation containing inserted nodes.
         * @return {void} Initializes each inserted collection and its descendants.
         */ (record) => {
            record.addedNodes.forEach(/**
             * Initialize a newly inserted element's own and nested tag collections.
             * @param {Node} node Inserted document node, possibly a non-element.
             * @return {void} Enhances only elements containing supported tag roots.
             */ (node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                if (node.matches('[data-hero-tags]')) {
                    setupHeroTagRoot(node);
                }
                node.querySelectorAll('[data-hero-tags]').forEach(setupHeroTagRoot);
            });
        });
    });
    heroTagInsertionObserver.observe(document.body, { childList: true, subtree: true });
}

/**
 * Place an open public-info panel beside its card or hero control inside the viewport.
 *
 * @param {HTMLDetailsElement} details Open gallery-card information disclosure.
 * @return {void} Positions the panel over surrounding content without changing card flow.
 */
function positionGalleryCardInfoPanel(details) {
    const panel = details.querySelector('.gallery-card-public-info-panel');
    const summary = details.querySelector('summary');
    const root = details.closest('[data-hero-tags]');
    if (!(panel instanceof HTMLElement) || !(summary instanceof HTMLElement) || !(root instanceof HTMLElement)) {
        return;
    }

    const rootRect = root.getBoundingClientRect();
    const summaryRect = summary.getBoundingClientRect();
    // Exclude a classic scrollbar so the overlay cannot widen the document.
    const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
    const margin = 12;
    const gap = 10;
    panel.style.maxWidth = `${Math.max(0, viewportWidth - (margin * 2))}px`;
    panel.style.right = 'auto';
    panel.style.bottom = 'auto';
    panel.style.left = '0px';
    panel.style.top = '0px';
    panel.style.visibility = 'hidden';
    const panelRect = panel.getBoundingClientRect();
    let left = summaryRect.left - panelRect.width - gap;
    if (left < margin) {
        left = summaryRect.right + gap;
    }
    left = Math.max(margin, Math.min(left, viewportWidth - panelRect.width - margin));
    let top = summaryRect.top + ((summaryRect.height - panelRect.height) / 2);
    top = Math.max(margin, Math.min(top, window.innerHeight - panelRect.height - margin));
    panel.style.left = `${Math.round(left - rootRect.left)}px`;
    panel.style.top = `${Math.round(top - rootRect.top)}px`;
    const positionedRect = panel.getBoundingClientRect();
    const panelIsLeftOfSummary = positionedRect.right <= summaryRect.left;
    const originY = Math.max(12, Math.min(positionedRect.height - 12, summaryRect.top + (summaryRect.height / 2) - positionedRect.top));
    panel.style.transformOrigin = `${panelIsLeftOfSummary ? '100%' : '0%'} ${Math.round(originY)}px`;
    panel.style.setProperty('--gallery-card-info-slide-x', panelIsLeftOfSummary ? '8px' : '-8px');
    panel.style.visibility = '';
}

/**
 * Reposition open public-information panels after viewport movement.
 * @return {void} Updates panels that remain open after scrolling or resizing.
 */
function repositionOpenGalleryCardInfoPanels() {
    document.querySelectorAll('[data-gallery-card-info-disclosure][open]').forEach(/**
     * Reposition one open panel after a viewport change.
     * @param {Element} details Candidate disclosure element.
     * @return {void} Updates panel coordinates when the candidate is a details element.
     */ (details) => {
        if (details instanceof HTMLDetailsElement) {
            positionGalleryCardInfoPanel(details);
        }
    });
}

/**
 * Clear the fallback timer and transition listener for a panel that is closing.
 *
 * @param {HTMLDetailsElement} details Gallery-card information disclosure.
 * @return {void} Removes any pending close completion work.
 */
function clearGalleryCardInfoCloseJob(details) {
    const job = galleryCardInfoCloseJobs.get(details);
    if (!job) {
        return;
    }
    window.clearTimeout(job.timerId);
    job.panel.removeEventListener('transitionend', job.onTransitionEnd);
    galleryCardInfoCloseJobs.delete(details);
}

/**
 * Finish a panel close after its CSS transition or bounded fallback timer.
 *
 * @param {HTMLDetailsElement} details Gallery-card information disclosure.
 * @return {void} Closes the native disclosure when its close state is still current.
 */
function completeGalleryCardInfoPanelClose(details) {
    if (!details.open || details.dataset.galleryCardInfoState !== 'closing') {
        return;
    }
    clearGalleryCardInfoCloseJob(details);
    details.open = false;
    details.removeAttribute('data-gallery-card-info-state');
}

/**
 * Convert one CSS transition time value to milliseconds.
 *
 * @param {string} value CSS duration or delay value.
 * @return {number} Parsed milliseconds, or zero for an unsupported value.
 */
function galleryCardInfoTransitionTimeMs(value) {
    if (value.endsWith('ms')) return Number.parseFloat(value);
    if (value.endsWith('s')) return Number.parseFloat(value) * 1000;
    return 0;
}

/**
 * Read the longest active transition on a panel, including its transition delay.
 *
 * @param {HTMLElement} panel Animated information panel.
 * @return {number} Longest transition time in milliseconds.
 */
function galleryCardInfoTransitionDurationMs(panel) {
    const style = window.getComputedStyle(panel);
    const durations = style.transitionDuration.split(',');
    const delays = style.transitionDelay.split(',');
    let maximum = 0;
    for (let index = 0; index < durations.length; index++) {
        const duration = durations[index].trim();
        const delay = (delays[index % Math.max(delays.length, 1)] || '0s').trim() || '0s';
        const total = galleryCardInfoTransitionTimeMs(duration) + galleryCardInfoTransitionTimeMs(delay);
        if (Number.isFinite(total)) maximum = Math.max(maximum, total);
    }
    return maximum;
}

/**
 * Complete a card-info disclosure close when its panel opacity transition ends.
 *
 * @param {TransitionEvent} event Browser transition completion event.
 * @return {void} Closes the disclosure only for its current panel opacity transition.
 */
function finishGalleryCardInfoPanelTransition(event) {
    const panel = event.currentTarget;
    if (!(panel instanceof HTMLElement) || event.target !== panel || event.propertyName !== 'opacity') {
        return;
    }
    const details = panel.closest('[data-gallery-card-info-disclosure]');
    if (details instanceof HTMLDetailsElement) {
        completeGalleryCardInfoPanelClose(details);
    }
}

/**
 * Open one public information panel and animate it from its matching ellipsis control.
 *
 * @param {HTMLDetailsElement} details Gallery-card information disclosure to open.
 * @return {void} Opens, positions, and animates the requested panel.
 */
function openGalleryCardInfoPanel(details) {
    const state = details.dataset.galleryCardInfoState;
    if (details.open && state === 'closing') {
        clearGalleryCardInfoCloseJob(details);
        details.dataset.galleryCardInfoState = 'open';
        details.querySelector('summary')?.setAttribute('aria-expanded', 'true');
        positionGalleryCardInfoPanel(details);
        return;
    }
    if (details.open && state) {
        return;
    }
    document.querySelectorAll('[data-gallery-card-info-disclosure][open]').forEach(/**
     * Close any other card-info panel before opening the requested one.
     * @param {Element} other Candidate disclosure that may already be open.
     * @return {void} Starts its close animation when it is a different disclosure.
     */ (other) => {
        if (other instanceof HTMLDetailsElement && other !== details) {
            closeGalleryCardInfoPanel(other);
        }
    });

    details.open = true;
    details.querySelector('summary')?.setAttribute('aria-expanded', 'true');
    const panel = details.querySelector('.gallery-card-public-info-panel');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    details.dataset.galleryCardInfoState = reduceMotion ? 'open' : 'opening';
    positionGalleryCardInfoPanel(details);
    if (reduceMotion || !(panel instanceof HTMLElement)) {
        return;
    }
    void panel.offsetWidth;
    window.requestAnimationFrame(() => {
        if (details.open && details.dataset.galleryCardInfoState === 'opening') {
            details.dataset.galleryCardInfoState = 'open';
        }
    });
}

/**
 * Animate a public information panel out before closing its native details element.
 *
 * @param {HTMLDetailsElement} details Gallery-card information disclosure to close.
 * @return {void} Updates the accessible state and finishes closing after the animation.
 */
function closeGalleryCardInfoPanel(details) {
    if (!details.open || details.dataset.galleryCardInfoState === 'closing') {
        return;
    }
    const previousState = details.dataset.galleryCardInfoState;
    details.dataset.galleryCardInfoState = 'closing';
    details.querySelector('summary')?.setAttribute('aria-expanded', 'false');
    const panel = details.querySelector('.gallery-card-public-info-panel');
    if (!(panel instanceof HTMLElement) || previousState === 'opening' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        clearGalleryCardInfoCloseJob(details);
        details.open = false;
        details.removeAttribute('data-gallery-card-info-state');
        return;
    }
    clearGalleryCardInfoCloseJob(details);
    panel.addEventListener('transitionend', finishGalleryCardInfoPanelTransition);
    const timerId = window.setTimeout(() => completeGalleryCardInfoPanelClose(details), galleryCardInfoTransitionDurationMs(panel));
    galleryCardInfoCloseJobs.set(details, { onTransitionEnd: finishGalleryCardInfoPanelTransition, panel, timerId });
}

/**
 * Bind native card-information disclosures once for current and future fragments.
 *
 * Native details remains the no-JavaScript fallback; these delegated handlers add
 * viewport placement, Escape dismissal, and outside-click dismissal when available.
 *
 * @return {void} Installs one delegated interaction layer for gallery cards.
 */
function setupGalleryCardInfoPanels() {
    if (galleryCardInfoHandlersReady || !document.body) {
        return;
    }
    galleryCardInfoHandlersReady = true;

    document.addEventListener('click', /**
     * Route pointer and keyboard summary activation through the animated panel behavior.
     * @param {MouseEvent} event Delegated click from a summary control.
     * @return {void} Prevents the native toggle only when a managed panel summary was activated.
     */ (event) => {
        const target = event.target;
        const summary = target instanceof Element ? target.closest('summary') : null;
        const details = summary?.closest('[data-gallery-card-info-disclosure]');
        if (!(summary instanceof HTMLElement) || !(details instanceof HTMLDetailsElement)) {
            return;
        }
        event.preventDefault();
        if (details.open && details.dataset.galleryCardInfoState !== 'closing') {
            closeGalleryCardInfoPanel(details);
        } else {
            openGalleryCardInfoPanel(details);
        }
    }, true);

    document.addEventListener('toggle', /**
     * Synchronize native disclosure changes and move the opened panel into view.
     * @param {Event} event Native details toggle event.
     * @return {void} Applies viewport placement or clears stale positioning.
     */ (event) => {
        const details = event.target;
        if (!(details instanceof HTMLDetailsElement) || !details.matches('[data-gallery-card-info-disclosure]')) {
            return;
        }
        const summary = details.querySelector('summary');
        summary?.setAttribute('aria-expanded', details.open ? 'true' : 'false');
        if (details.open) {
            if (!details.dataset.galleryCardInfoState) {
                openGalleryCardInfoPanel(details);
                return;
            }
            positionGalleryCardInfoPanel(details);
            return;
        }
        clearGalleryCardInfoCloseJob(details);
        details.removeAttribute('data-gallery-card-info-state');
        const panel = details.querySelector('.gallery-card-public-info-panel');
        if (panel instanceof HTMLElement) {
            panel.removeAttribute('style');
        }
    }, true);

    document.addEventListener('pointerdown', /**
     * Close a panel when a pointer press starts outside its disclosure.
     * @param {PointerEvent} event Document-level pointer press.
     * @return {void} Closes any open card-info panel outside the pointer target.
     */ (event) => {
        const target = event.target;
        if (target instanceof Element && target.closest('[data-gallery-card-info-disclosure]')) {
            return;
        }
        document.querySelectorAll('[data-gallery-card-info-disclosure][open]').forEach(/**
         * Close each currently open card-info disclosure.
         * @param {Element} details Candidate open disclosure.
         * @return {void} Closes the candidate details element.
         */ (details) => {
            if (details instanceof HTMLDetailsElement) {
                closeGalleryCardInfoPanel(details);
            }
        });
    });

    document.addEventListener('keydown', /**
     * Close the open panel and restore focus when the visitor presses Escape.
     * @param {KeyboardEvent} event Document-level key event.
     * @return {void} Dismisses the open panel and returns focus to its summary.
     */ (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const details = document.querySelector('[data-gallery-card-info-disclosure][open]');
        if (!(details instanceof HTMLDetailsElement)) {
            return;
        }
        event.preventDefault();
        const summary = details.querySelector('summary');
        closeGalleryCardInfoPanel(details);
        summary?.focus({ preventScroll: true });
    });

    window.addEventListener('resize', repositionOpenGalleryCardInfoPanels, { passive: true });
    window.addEventListener('scroll', repositionOpenGalleryCardInfoPanels, { passive: true, capture: true });
}

// One observer per module keeps dynamically refreshed cards on the same tag pipeline.
let heroTagInsertionObserver = null;
let galleryCardInfoHandlersReady = false;
const galleryCardInfoCloseJobs = new WeakMap();
