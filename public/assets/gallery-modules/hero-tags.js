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
 *   2026-08-11
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

// One observer per module keeps dynamically refreshed cards on the same tag pipeline.
let heroTagInsertionObserver = null;
