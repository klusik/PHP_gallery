/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/gallery-visit-progress.js
 * Module Type: Public Browser Module
 * Purpose: Derive browser-local branch progress and independent content alerts.
 * Responsibilities: Validate bounded history, acknowledge genuine visits, merge
 *   tabs and render inline icons plus the public notification bell.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

/**
 * Bound history parsing and serialization before touching persistent storage.
 * Type: number. Units: UTF-16 code units. Scope: one installation record.
 * Consumers: parseVisitHistory and setupGalleryVisitProgress.
 * Rationale: 1048576 bounds synchronous parsing to a small local record.
 */
const HISTORY_BYTES = 1048576;
/**
 * Bound retained gallery identities without silently expiring earlier visits.
 * Type: number. Units: galleries. Scope: one installation record.
 * Consumers: parseVisitHistory and observeVisitGraph.
 * Rationale: 4096 admits multiple authorized projections within the byte bound.
 */
const HISTORY_NODES = 4096;
/**
 * Bound compact membership watermarks across the complete history.
 * Type: number. Units: source/edge lanes. Scope: one installation record.
 * Consumers: parseVisitHistory.
 * Rationale: 16384 caps nested parsing and remains above one server projection.
 */
const HISTORY_LANES = 16384;
/**
 * Keep the floating notification panel clear of its trigger and viewport edge.
 * Type: number. Units: CSS pixels. Scope: notification panel positioning.
 * Consumers: setupGalleryVisitProgress.positionPanel.
 * Rationale: eight matches the compact public surface spacing on narrow screens.
 */
const PANEL_GAP = 8;

/** @typedef {{o:boolean,b:Record<string,number>|null,r?:string}} VisitRecord Direct open, optional branch reset order and observed watermarks. */
/** @typedef {{v:number,epoch:string,g:Record<string,VisitRecord>}} VisitHistory Versioned installation-local history. */
/** @typedef {{title:string,url:string,children:string[],watermark:Record<string,number>}} VisitNode Authorized gallery projection. */
/** @typedef {{progress:string,newContent:boolean}} VisitState Derived presentation, never persisted. */

/**
 * Validate a local history record without trusting stored prototypes or fields.
 * @param {string|null} raw Serialized local record, possibly corrupt or absent.
 * @returns {VisitHistory} Sanitized history; invalid input starts unobserved.
 */
export function parseVisitHistory(raw) {
    const empty = {v: 1, epoch: 'initial', g: {}};
    if (!raw || raw.length > HISTORY_BYTES) return empty;
    try {
        const value = JSON.parse(raw);
        if (value?.v !== 1 || !/^[\w-]{1,80}$/.test(value.epoch)
            || !value.g || typeof value.g !== 'object' || Array.isArray(value.g)) return empty;
        const entries = Object.entries(value.g);
        if (entries.length > HISTORY_NODES) return empty;
        const result = {v: 1, epoch: value.epoch, g: {}};
        let laneCount = 0;
        for (const [key, record] of entries) {
            if (!(key === 'r:0' || /^[gs]:[1-9]\d{0,15}$/.test(key)) || !record
                || typeof record.o !== 'boolean') continue;
            const baseline = record.b === null ? null : {};
            if (record.b !== null) {
                if (!record.b || typeof record.b !== 'object'
                    || Array.isArray(record.b)) continue;
                for (const [lane, revision] of Object.entries(record.b)) {
                    laneCount++;
                    if (laneCount > HISTORY_LANES) return empty;
                    if (/^(?:m:[1-9]\d{0,15}:[012]|a:[1-9]\d{0,15}|e:[gs]:[1-9]\d{0,15})$/.test(lane)
                        && Number.isSafeInteger(revision) && revision >= 0) {
                        baseline[lane] = revision;
                    }
                }
            }
            result.g[key] = {o: record.o, b: baseline};
            if (typeof record.r === 'string' && /^r_\d{16}_[\w-]{1,48}$/.test(record.r)) {
                result.g[key].r = record.r;
            }
        }
        return result;
    } catch {
        return empty;
    }
}

/**
 * Merge visits within each branch reset order and preserve content baselines.
 * @param {VisitHistory} current Latest persistent state.
 * @param {VisitHistory} pending Local state from the same or an older epoch.
 * @returns {VisitHistory} Union retaining the newest installation/branch resets.
 */
export function mergeVisitHistory(current, pending) {
    if (current.epoch !== pending.epoch) {
        // Ordered reset epochs prevent a stale fallback writer from resurrecting
        // history after another tab reset it. Legacy initial/UUID epochs lose
        // to the current ordered format; no epoch ever leaves localStorage.
        return pending.epoch.startsWith('r_')
            && (!current.epoch.startsWith('r_') || pending.epoch > current.epoch)
            ? pending : current;
    }
    const result = parseVisitHistory(JSON.stringify(current));
    for (const [key, record] of Object.entries(pending.g)) {
        const existing = result.g[key];
        if (!existing) {
            if (Object.keys(result.g).length < HISTORY_NODES) {
                result.g[key] = {...record, b: record.b && {...record.b}};
            }
            continue;
        }
        if ((record.r ?? '') > (existing.r ?? '')) {
            existing.o = record.o;
            existing.r = record.r;
        } else if ((record.r ?? '') === (existing.r ?? '')) {
            existing.o ||= record.o;
        }
        if (record.b !== null) {
            existing.b ??= {};
            for (const [lane, revision] of Object.entries(record.b)) {
                existing.b[lane] = Math.max(existing.b[lane] ?? 0, revision);
            }
        }
    }
    return result;
}

/**
 * Create an ordered local reset token newer than already merged reset evidence.
 * @param {string} previous Latest known installation/branch reset token.
 * @returns {string} Local-only order token, never transmitted to the server.
 */
function visitResetStamp(previous) {
    // Sixteen decimal positions are the fixed lexical timestamp field width,
    // not a lifetime. Advancing past prior evidence tolerates clock rollback.
    const previousTime = /^r_\d{16}_/.test(previous)
        ? Number(previous.slice(2, 18)) : 0;
    const time = Math.max(Date.now(), previousTime + 1);
    // Permanent fallback for browsers without randomUUID: uniqueness here only
    // separates local actions; this is neither a secret nor a visitor identity.
    const nonce = globalThis.crypto?.randomUUID?.()
        ?? String(Math.random()).slice(2);
    return `r_${String(time).padStart(16, '0')}_${nonce}`;
}

/**
 * Undo visits for one authorized branch without acknowledging its New content.
 * @param {VisitHistory} history Mutable installation-local history.
 * @param {Record<string,VisitNode>} nodes Current authorized physical/Smart graph.
 * @param {string} key Selected real gallery identity; synthetic roots are refused.
 * @returns {void} Resets reachable direct flags while preserving unrelated nodes
 *     and every content baseline. Shared Smart placements share their one flag.
 */
export function markVisitBranchUnseen(history, nodes, key) {
    if (key === 'r:0' || !nodes[key]) return;
    const latest = Object.values(history.g).reduce(
        (previous, record) => (record.r ?? '') > previous ? record.r : previous,
        history.epoch,
    );
    const reset = visitResetStamp(latest);
    const queue = [key];
    const selected = new Set();
    for (let cursor = 0; cursor < queue.length; cursor++) {
        const current = queue[cursor];
        if (selected.has(current) || !nodes[current]) continue;
        selected.add(current);
        if (history.g[current]) {
            history.g[current].o = false;
            history.g[current].r = reset;
        }
        queue.push(...nodes[current].children);
    }
}

/**
 * Initialize first observations without acknowledging later additions or visits.
 * @param {VisitHistory} history Mutable browser-local history.
 * @param {Record<string,VisitNode>} nodes Complete authorized projection.
 * @param {boolean} ready Whether membership evidence is current and complete.
 * @returns {void} Records only previously unobserved watermarks.
 */
export function observeVisitGraph(history, nodes, ready) {
    let count = Object.keys(history.g).length;
    for (const [key, node] of Object.entries(nodes)) {
        if (!history.g[key] && count < HISTORY_NODES) {
            history.g[key] = {o: false, b: ready ? {...node.watermark} : null};
            count++;
        } else if (ready && history.g[key]?.b === null) {
            history.g[key].b = {...node.watermark};
        }
    }
}

/**
 * Acknowledge explicit opens while retaining unopened descendants' edge alerts.
 * @param {VisitHistory} history Mutable browser-local history.
 * @param {Record<string,VisitNode>} nodes Current authorized projection.
 * @param {string[]} keys Genuine opened identities or the explicit read-all set.
 * @param {boolean} ready Whether content baselines may be acknowledged.
 * @returns {void} Stores direct opens and acknowledges matching incoming edges.
 */
export function acknowledgeVisitNodes(history, nodes, keys, ready) {
    const selected = new Set(keys);
    for (const key of selected) {
        const record = history.g[key];
        if (!record || !nodes[key]) continue;
        if (key !== 'r:0') record.o = true;
        if (!ready) continue;
        const retainedEdges = Object.fromEntries(
            Object.entries(record.b ?? {}).filter(([lane]) => lane.startsWith('e:')),
        );
        record.b = {...retainedEdges};
        for (const [lane, revision] of Object.entries(nodes[key].watermark)) {
            if (!lane.startsWith('e:')) record.b[lane] = revision;
        }
    }
    if (!ready) return;
    for (const [key, node] of Object.entries(nodes)) {
        const record = history.g[key];
        if (!record?.b) continue;
        for (const child of node.children) {
            if (selected.has(child)) {
                const lane = `e:${child}`;
                if (node.watermark[lane] !== undefined) {
                    record.b[lane] = node.watermark[lane];
                }
            }
        }
    }
}

/**
 * Dismiss current notifications without changing any gallery's visit state.
 * @param {VisitHistory} history Mutable installation-local history.
 * @param {Record<string,VisitNode>} nodes Current authorized projection.
 * @param {boolean} ready Whether complete content evidence can be acknowledged.
 * @returns {void} Updates current watermarks only; unavailable evidence is inert.
 */
export function acknowledgeVisitContent(history, nodes, ready) {
    if (!ready) return;
    for (const [key, node] of Object.entries(nodes)) {
        const record = history.g[key];
        if (!record) continue;
        record.b ??= {};
        for (const [lane, revision] of Object.entries(node.watermark)) {
            record.b[lane] = Math.max(record.b[lane] ?? 0, revision);
        }
    }
}

/**
 * Toggle all authorized galleries, prioritizing completion of unfinished visits.
 * @param {VisitHistory} history Mutable installation-local history.
 * @param {Record<string,VisitNode>} nodes Current authorized physical/Smart graph.
 * @returns {void} Marks every real node seen unless all are already seen, then
 *     marks them unseen. Preserves content baselines and unrelated history;
 *     ordered record stamps prevent stale tabs from reversing the bulk action.
 */
export function toggleVisitNodesSeen(history, nodes) {
    const keys = Object.keys(nodes).filter(key => key !== 'r:0');
    if (!keys.length) return;
    const seen = !keys.every(key => history.g[key]?.o === true);
    const latest = Object.values(history.g).reduce(
        (previous, record) => (record.r ?? '') > previous ? record.r : previous,
        history.epoch,
    );
    const order = visitResetStamp(latest);
    for (const key of keys) {
        if (!history.g[key]) continue;
        history.g[key].o = seen;
        history.g[key].r = order;
    }
}

/**
 * Compare one authorized membership lane without cosmetic NSFW false positives.
 * @param {Record<string,number>} current Current available membership lanes.
 * @param {Record<string,number>} baseline Last observed/acknowledged lanes.
 * @param {string} lane The lane being evaluated.
 * @param {number} revision Current monotonic addition watermark.
 * @param {number} maxSafe Last acknowledged safe membership across known sources.
 * @param {number} maxAll Last acknowledged complete membership across known sources.
 * @returns {boolean} True only for newly available membership in this scope.
 */
function visitLaneIsNew(current, baseline, lane, revision, maxSafe, maxAll) {
    if (!lane.startsWith('m:')) return revision > (baseline[lane] ?? 0);
    const source = lane.slice(0, lane.lastIndexOf(':'));
    const safe = `${source}:0`;
    const all = `${source}:1`;
    const restricted = `${source}:2`;
    if (lane === restricted) return false;
    if (lane === all) {
        return baseline[all] !== undefined
            ? revision > maxAll
            : (current[restricted] ?? 0) > 0;
    }
    if (current[all] !== undefined && baseline[all] !== undefined) return false;
    return revision > (baseline[safe] === undefined ? 0 : maxSafe);
}

/**
 * Compute independent progress/New bottom-up in O(nodes + edges + lanes).
 * @param {Record<string,VisitNode>} nodes Authorized physical/virtual graph.
 * @param {VisitHistory} history Direct opens and observed baselines only.
 * @param {boolean} ready Whether complete addition evidence is available.
 * @returns {Record<string,VisitState>} Derived states; cycles never become Seen.
 */
export function computeVisitStates(nodes, history, ready = true) {
    const states = {};
    const parents = {};
    const pending = {};
    const ownNew = {};
    const anySeen = {};
    const allSeen = {};
    const queue = [];
    for (const [key, node] of Object.entries(nodes)) {
        const children = node.children.filter(child => nodes[child]);
        pending[key] = children.length;
        anySeen[key] = history.g[key]?.o === true;
        allSeen[key] = history.g[key]?.o === true;
        ownNew[key] ??= false;
        if (!children.length) queue.push(key);
        for (const child of children) (parents[child] ??= []).push(key);
        const baseline = history.g[key]?.b;
        if (ready && baseline) {
            let maxSafe = 0;
            let maxAll = 0;
            for (const [lane, revision] of Object.entries(baseline)) {
                if (lane.startsWith('m:') && lane.endsWith(':0')) maxSafe = Math.max(maxSafe, revision);
                if (lane.startsWith('m:') && lane.endsWith(':1')) maxAll = Math.max(maxAll, revision);
            }
            for (const [lane, revision] of Object.entries(node.watermark)) {
                if (!visitLaneIsNew(node.watermark, baseline, lane, revision, maxSafe, maxAll)) continue;
                ownNew[key] = true;
                if (lane.startsWith('e:') && nodes[lane.slice(2)]) {
                    ownNew[lane.slice(2)] = true;
                }
            }
        }
    }
    for (let cursor = 0; cursor < queue.length; cursor++) {
        const key = queue[cursor];
        const progress = allSeen[key] ? 'seen' : anySeen[key] ? 'partial' : 'unseen';
        states[key] = {progress, newContent: ownNew[key] === true};
        for (const parent of parents[key] ?? []) {
            anySeen[parent] ||= anySeen[key];
            allSeen[parent] &&= progress === 'seen';
            ownNew[parent] ||= states[key].newContent;
            pending[parent]--;
            if (pending[parent] === 0) queue.push(parent);
        }
    }
    for (const key of Object.keys(nodes)) {
        states[key] ??= {progress: 'partial', newContent: false};
    }
    return states;
}

/**
 * Create one shape-distinct, labelled status icon from fixed local SVG markup.
 * @param {string} state unseen, partial, seen or new.
 * @param {string} label Translated accessible description and tooltip.
 * @returns {HTMLSpanElement} Small inline icon, with no visible status text.
 */
function visitIcon(state, label) {
    const span = document.createElement('span');
    span.className = `gallery-visit-icon is-${state}`;
    span.setAttribute('role', 'img');
    span.setAttribute('aria-label', label);
    span.title = label;
    span.tabIndex = 0;
    const shapes = {
        unseen: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none"/>',
        partial: '<circle cx="12" cy="12" r="8"/><path d="M12 4a8 8 0 0 0 0 16Z" fill="currentColor" stroke="none"/>',
        seen: '<circle cx="12" cy="12" r="8"/><path d="m8 12 3 3 5-6"/>',
        new: '<path d="m12 2 2.5 7.5L22 12l-7.5 2.5L12 22l-2.5-7.5L2 12l7.5-2.5Z"/>',
    };
    span.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        + 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
        + 'aria-hidden="true">' + shapes[state] + '</svg>';
    return span;
}

/**
 * Mount the public UI only when the controller supplied an authorized payload.
 * @param {{nodes:Record<string,VisitNode>,opened:string,ready:boolean,
 *     storageKey:string,strings:Record<string,string>}} config Prepared page state.
 * @returns {void} Installs local lifecycle/storage handlers without network calls.
 */
export function setupGalleryVisitProgress(config) {
    const bell = document.querySelector('[data-visit-bell]');
    const panel = document.querySelector('[data-visit-panel]');
    if (!bell || !panel || bell.dataset.visitMounted) return;
    bell.dataset.visitMounted = '1';
    // A body-owned fixed panel escapes header clipping and translucent stacking
    // contexts while remaining spatially attached to the actual bell.
    document.body.append(panel);
    const list = panel.querySelector('[data-visit-list]');
    const newsActions = panel.querySelector('[data-visit-news-actions]');
    const readAll = panel.querySelector('[data-visit-read-all]');
    const visitToggle = panel.querySelector('[data-visit-toggle]');
    const help = panel.querySelector('[data-visit-help]');
    let history = parseVisitHistory(null);
    let storageAvailable = true;
    let activated = false;

    /** Let the anchored panel grow to viewport height before its list scrolls.
     * @returns {void} Positions beneath the bell when possible; tall panels move
     *     toward the top edge before the full-height bound introduces scrolling.
     */
    function positionPanel() {
        if (panel.hidden) return;
        const anchor = bell.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        panel.style.maxWidth = `${Math.max(0, viewportWidth - PANEL_GAP * 2)}px`;
        const width = panel.offsetWidth;
        const left = Math.max(PANEL_GAP,
            Math.min(anchor.right - width, viewportWidth - width - PANEL_GAP));
        const viewportHeight = document.documentElement.clientHeight;
        panel.style.maxHeight = `${Math.max(0, viewportHeight - PANEL_GAP * 2)}px`;
        const height = panel.offsetHeight;
        const top = Math.max(PANEL_GAP, Math.min(anchor.bottom + PANEL_GAP,
            viewportHeight - height - PANEL_GAP));
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    }

    /** Dismiss the contextual panel without changing history or dimming the page.
     * @param {boolean} restoreFocus Whether keyboard focus returns to the bell.
     * @returns {void} Hides the panel and updates its disclosure relationship.
     */
    function closePanel(restoreFocus = true) {
        panel.hidden = true;
        bell.setAttribute('aria-expanded', 'false');
        if (restoreFocus) bell.focus({preventScroll: true});
    }

    /** Read only this installation's history, tolerating blocked storage.
     * @returns {VisitHistory} Latest persistent history or current volatile state.
     */
    function read() {
        try {
            return parseVisitHistory(localStorage.getItem(config.storageKey));
        } catch {
            storageAvailable = false;
            return history;
        }
    }

    /** Render current derived states without changing observations.
     * @returns {void} Refreshes inline title icons, bell and accessible overview.
     */
    function render() {
        const states = computeVisitStates(config.nodes, history, config.ready);
        for (const card of document.querySelectorAll('[data-visit-key]')) {
            const key = card.dataset.visitKey;
            if (!states[key]) continue;
            const heading = card.querySelector('.gallery-card-title-link')?.parentElement;
            if (!heading) continue;
            let icons = heading.querySelector('[data-visit-icons]');
            if (!icons) {
                icons = document.createElement('span');
                icons.className = 'gallery-visit-icons';
                icons.dataset.visitIcons = '';
                heading.append(' ', icons);
            }
            const state = states[key];
            let control = icons.querySelector('[data-visit-unseen-key]');
            if (!control) {
                control = document.createElement('button');
                control.type = 'button';
                control.className = 'gallery-visit-control';
                control.dataset.visitUnseenKey = key;
                icons.prepend(control);
            }
            const label = config.strings[state.progress]
                + (state.progress !== 'unseen' ? ` · ${config.strings.mark_unseen}` : '');
            control.setAttribute('aria-label', label);
            control.title = label;
            control.disabled = state.progress === 'unseen';
            const icon = visitIcon(state.progress, config.strings[state.progress]);
            icon.removeAttribute('tabindex');
            icon.removeAttribute('role');
            icon.setAttribute('aria-hidden', 'true');
            control.replaceChildren(icon);
            icons.querySelector('.is-new')?.remove();
            if (state.newContent) icons.append(visitIcon('new', config.strings.new));
        }
        const fresh = Object.keys(states).filter(key => key !== 'r:0' && states[key].newContent);
        list.replaceChildren();
        for (const key of fresh) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = config.nodes[key].url;
            link.textContent = config.nodes[key].title;
            item.append(visitIcon('new', config.strings.new), link);
            list.append(item);
        }
        // Empty or unavailable news needs no public section, technical warning
        // or empty-state claim; retain only the independently usable visit action.
        panel.classList.toggle('is-visits-only', !fresh.length);
        panel.setAttribute('aria-labelledby', fresh.length
            ? 'gallery-visit-heading' : 'gallery-visit-toggle-label');
        newsActions.hidden = !config.ready || !fresh.length;
        readAll.disabled = newsActions.hidden;
        const keys = Object.keys(config.nodes).filter(key => key !== 'r:0');
        const allSeen = keys.length > 0 && keys.every(key => history.g[key]?.o === true);
        visitToggle.disabled = !keys.length;
        visitToggle.querySelector('[data-visit-toggle-label]').textContent = allSeen
            ? config.strings.mark_all_unseen : config.strings.mark_all_seen;
        visitToggle.querySelector('[data-visit-toggle-icon]').setAttribute('d', allSeen
            ? 'M3 10a9 9 0 1 1 2 8M3 4v6h6' : 'm3 12 4 4L17 6M11 16l3 3 8-10');
        help.textContent = storageAvailable ? config.strings.local : config.strings.unavailable;
        bell.querySelector('[data-visit-bell-dot]').hidden = fresh.length === 0;
        bell.setAttribute('aria-label', config.strings.bell
            + (fresh.length ? ` (${fresh.length})` : ''));
        bell.hidden = false;
        positionPanel();
    }

    /** Merge one local action under Web Locks when available.
     * @param {function(VisitHistory):void} mutate Action changing validated history.
     * @returns {Promise<void>} Resolves after local persistence and repaint.
     */
    async function update(mutate) {
        const apply = () => {
            history = mergeVisitHistory(read(), history);
            mutate(history);
            try {
                const raw = JSON.stringify(history);
                if (raw.length > HISTORY_BYTES) throw new Error('History bound');
                localStorage.setItem(config.storageKey, raw);
            } catch {
                storageAvailable = false;
            }
            render();
        };
        if (navigator.locks?.request) {
            try {
                await navigator.locks.request(config.storageKey, apply);
                return;
            } catch {
                // Permanent fallback for browsers/private profiles without
                // usable Web Locks; storage-event union preserves other tabs.
            }
        }
        apply();
    }

    /** Admit a real visible document; prerenders/hidden tabs remain unvisited.
     * @returns {void} Initializes observation and marks this successful detail once.
     */
    function activate() {
        if (document.visibilityState !== 'visible' || document.prerendering) return;
        void update(current => {
            observeVisitGraph(current, config.nodes, config.ready);
            if (!activated && config.opened) {
                acknowledgeVisitNodes(current, config.nodes, [config.opened], config.ready);
            }
            activated = true;
        });
    }

    bell.addEventListener('click', () => {
        if (!panel.hidden) {
            closePanel();
            return;
        }
        panel.hidden = false;
        positionPanel();
        bell.setAttribute('aria-expanded', 'true');
        panel.querySelector('[data-visit-close]').focus({preventScroll: true});
    });
    panel.querySelector('[data-visit-close]').addEventListener('click', () => closePanel());
    document.addEventListener('pointerdown', event => {
        if (!panel.hidden && !panel.contains(event.target) && !bell.contains(event.target)) {
            closePanel(false);
        }
    });
    document.addEventListener('keydown', event => {
        if (!panel.hidden && event.key === 'Escape') {
            event.preventDefault();
            closePanel();
        }
    });
    window.addEventListener('resize', positionPanel);
    window.addEventListener('scroll', positionPanel, {capture: true, passive: true});
    readAll.addEventListener('click', async () => {
        if (readAll.disabled) return;
        await update(current => {
            acknowledgeVisitContent(current, config.nodes, config.ready);
        });
        if (!panel.hidden && readAll.disabled) {
            visitToggle.focus({preventScroll: true});
        }
    });
    document.addEventListener('click', async event => {
        const control = event.target.closest?.('[data-visit-unseen-key]');
        if (!control || control.disabled) return;
        event.preventDefault();
        const key = control.dataset.visitUnseenKey;
        await update(current => markVisitBranchUnseen(current, config.nodes, key));
        // The now-disabled icon must not strand keyboard focus after its action.
        control.closest('[data-visit-key]')?.querySelector('.gallery-card-title-link')
            ?.focus({preventScroll: true});
    });
    visitToggle.addEventListener('click', () => {
        void update(current => toggleVisitNodesSeen(current, config.nodes));
    });
    window.addEventListener('storage', event => {
        if (event.key !== config.storageKey) return;
        const latest = read();
        const merged = mergeVisitHistory(latest, history);
        history = merged;
        render();
        if (JSON.stringify(merged) !== JSON.stringify(latest)) {
            void update(() => {});
        }
    });
    window.addEventListener('pageshow', activate);
    document.addEventListener('visibilitychange', activate);
    document.addEventListener('prerenderingchange', activate);
    new MutationObserver(records => {
        if (records.some(record => [...record.addedNodes].some(node =>
            node.nodeType === Node.ELEMENT_NODE
            && (node.matches('[data-visit-key]') || node.querySelector('[data-visit-key]'))))) {
            render();
        }
    }).observe(document.querySelector('.site-main') ?? document.body,
        {childList: true, subtree: true});
    activate();
}
