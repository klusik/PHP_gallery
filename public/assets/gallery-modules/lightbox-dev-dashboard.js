/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/lightbox-dev-dashboard.js
 * Module Type: Browser Presentation Module
 * Purpose: Present optional administrator lightbox diagnostics from the viewer's existing runtime.
 * Responsibilities: Render stable grouped values, bounded history and explanatory colored graphs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

import {i18n} from './admin-core.js?v=20260512-modular-admin-v1';

// Purpose: bound diagnostic history; Type: integer; Units: samples; Scope: optional dashboard;
// Consumers: render/drawGraph; Rationale: retain about 31 seconds without unbounded telemetry growth.
const historyLimit = 90;
// Purpose: diagnostic sampling cadence; Type: integer; Units: milliseconds; Scope: optional dashboard;
// Consumers: render/drawGraph; Rationale: match the existing viewer diagnostic refresh interval.
const sampleInterval = 350;
const graphColors = {memory: '#7dcfff', cached: '#80e0a7', pending: '#ffd080', frame: '#eaa5ef'};

/**
 * Translate one dashboard label through the existing browser catalog.
 * @param {string} key Dashboard translation suffix.
 * @param {string} fallback English text when the catalog is unavailable.
 * @return {string} Localized display text.
 */
function label(key, fallback) {
    return i18n(`lightbox.dev.${key}`, fallback);
}

/**
 * Describe a protected media URL without credentials, query parameters or fragments.
 * @param {string} source Authorized source retained by the lightbox.
 * @return {string} Safe path label or an explicit inline-media description.
 */
export function lightboxDevSourceLabel(source) {
    if (!source) return '—';
    try {
        const url = new URL(source, window.location.href);
        if (url.protocol === 'data:') return label('inline', 'Inline image');
        if (url.protocol === 'blob:') return label('blob', 'Browser object URL');
        if (!['http:', 'https:'].includes(url.protocol)) return '—';
        return `${url.origin}${url.pathname}`.slice(0, 400);
    } catch {
        return '—';
    }
}

/**
 * Format known bytes without presenting unavailable measurements as zero.
 * @param {number|null} bytes Measured or estimated bytes.
 * @return {string} Human-readable size or an unavailable marker.
 */
function sizeLabel(bytes) {
    if (!Number.isFinite(bytes)) return '—';
    const units = ['B', 'KiB', 'MiB', 'GiB'];
    let value = Math.max(0, bytes);
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
    return `${value.toFixed(unit ? 1 : 0)} ${units[unit]}`;
}

/**
 * Format dimensions only when both axes are known.
 * @param {number} width Image or viewport width.
 * @param {number} height Image or viewport height.
 * @return {string} Pixel dimensions or an unavailable marker.
 */
function dimensions(width, height) {
    return width > 0 && height > 0 ? `${Math.round(width)} × ${Math.round(height)} px` : '—';
}

/**
 * Localize state badges while retaining a stable machine state for styling.
 * @param {string} state Canonical viewer or diagnostic state.
 * @return {string} Readable state label.
 */
function stateLabel(state) {
    const states = {
        active: ['active', 'Displayed'], ready: ['ready', 'Decoded'], cached: ['cached', 'Cached'],
        pending: ['pending', 'Pending'], loading: ['loading', 'Loading'], preloading: ['preloading', 'Preloading'],
        idle: ['idle', 'Not prepared'], error: ['error', 'Error'], cancelled: ['cancelled', 'Cancelled'],
        evicted: ['evicted', 'Released'], unknown: ['unknown', 'Unknown'], previous: ['previous', 'Previous image'],
        preview: ['preview', 'Preview'], master: ['master', 'Master preview'], full: ['full', 'Original / full'],
        shared: ['shared', 'Preview / full'],
        intent: ['intent', 'Navigation requested'], metadata: ['metadata', 'Loading metadata'],
        'source-selection': ['source_selection', 'Selecting source'], presenting: ['presenting', 'Presenting image'],
        displayed: ['active', 'Displayed'], failed: ['error', 'Error'], 'setup-failed': ['error', 'Error'],
        'quality debounce': ['quality_debounce', 'Waiting to select quality'],
        'quality transfer / decode': ['quality_work', 'Quality transfer / decode'],
        'cross-fade': ['cross_fade', 'Blending images'], 'navigation indicator timer': ['indicator_timer', 'Loading indicator delay'],
    };
    const entry = states[state];
    return entry ? label(...entry) : state;
}

/**
 * Build one optional dashboard without taking ownership of any media operations.
 * @param {HTMLElement} host Production lightbox overlay, including native fullscreen.
 * @param {AbortSignal} signal Viewer setup lifetime.
 * @param {Function} requestRender Request a fresh snapshot after resuming or expanding.
 * @return {{element:HTMLElement, isPaused:Function, render:Function}} Presentation-only dashboard.
 */
export function createLightboxDevDashboard(host, signal, requestRender) {
    const shell = document.createElement('section');
    shell.className = 'gallery-dev-overlay';
    shell.setAttribute('aria-label', i18n('lightbox.dev_diagnostics_aria', 'Gallery dev mode diagnostics'));
    const header = document.createElement('header');
    header.className = 'gallery-dev-header';
    const heading = document.createElement('strong');
    heading.textContent = `DEV · ${label('title', 'Lightbox diagnostics')}`;
    const controls = document.createElement('div');
    controls.className = 'gallery-dev-controls';
    const pause = document.createElement('button');
    const collapse = document.createElement('button');
    const expand = document.createElement('button');
    pause.type = collapse.type = expand.type = 'button';
    pause.dataset.devAction = 'pause';
    collapse.dataset.devAction = 'collapse';
    expand.dataset.devAction = 'expand';
    controls.append(pause, collapse, expand);
    header.append(heading, controls);
    const summary = document.createElement('div');
    summary.className = 'gallery-dev-summary';
    summary.dataset.devSummary = '';
    const body = document.createElement('div');
    body.className = 'gallery-dev-body';
    const tabs = document.createElement('nav');
    tabs.className = 'gallery-dev-tabs';
    tabs.setAttribute('aria-label', label('title', 'Lightbox diagnostics'));
    const fields = new Map();
    const sections = new Map();
    const samples = [];
    let paused = false;
    let collapsed = false;
    let expanded = false;
    let selectedSection = 'active';
    let selectedGraph = 'memory';
    let lastSampleAt = -Infinity;

    /**
     * Append one page of diagnostics with an explicit button instead of a scrolling disclosure stack.
     * @param {string} key Section identity.
     * @param {string} title Localized heading.
     * @param {string} hint Localized explanation.
     * @return {HTMLElement} Section content host.
     */
    function section(key, title, hint) {
        const page = document.createElement('section');
        page.dataset.devSection = key;
        page.hidden = key !== selectedSection;
        page.setAttribute('aria-label', title);
        const button = document.createElement('button');
        button.type = 'button'; button.dataset.devTab = key;
        button.textContent = title;
        button.setAttribute('aria-pressed', String(key === selectedSection));
        button.addEventListener('click', () => {
            selectedSection = key;
            sections.forEach((entry, name) => {
                entry.page.hidden = name !== key;
                entry.button.setAttribute('aria-pressed', String(name === key));
            });
            if (key === 'history') graphs.forEach((graph) => {
                graph.figure.hidden = graph.key !== selectedGraph;
                if (graph.key === selectedGraph) drawGraph(graph, samples);
            });
            requestRender();
        }, {signal});
        tabs.append(button);
        const content = document.createElement('div');
        content.className = 'gallery-dev-section-body';
        const help = document.createElement('p');
        help.className = 'gallery-dev-help';
        help.textContent = hint;
        content.append(help);
        page.append(content); body.append(page);
        sections.set(key, {page, button});
        return content;
    }

    /**
     * Append an aligned label/value pair with persistent DOM identity.
     * @param {HTMLElement} parent Owning section.
     * @param {string} key Stable snapshot field.
     * @param {string} text Localized label.
     * @return {HTMLElement} Value host.
     */
    function row(parent, key, text) {
        const item = document.createElement('div');
        item.className = 'gallery-dev-row';
        if (['source', 'promotion', 'transfer', 'timing'].includes(key)) item.dataset.devExtended = '';
        const name = document.createElement('span');
        name.textContent = text;
        const value = document.createElement('span');
        value.dataset.devValue = key;
        value.textContent = '—';
        item.append(name, value);
        parent.append(item);
        fields.set(key, value);
        return value;
    }

    const active = section('active', label('active_media', 'Displayed image & quality'),
        label('active_hint', 'This is the live image. During navigation it can still belong to the previous photo.'));
    row(active, 'photo', label('photo', 'Photo / gallery ID'));
    row(active, 'name', label('name', 'Title / filename'));
    row(active, 'quality', label('quality', 'Displayed quality'));
    row(active, 'source', label('source', 'Source URL (query hidden)')).classList.add('gallery-dev-source');
    row(active, 'intrinsic', label('intrinsic', 'Loaded / original pixels'));
    row(active, 'promotion', label('promotion', 'Requested quality'));
    row(active, 'transfer', label('transfer', 'Quality transfer'));
    row(active, 'timing', label('timing', 'Load + decode / decode time'));
    const warning = document.createElement('p');
    warning.className = 'gallery-dev-warning';
    warning.dataset.devWarning = '';
    warning.hidden = true;
    active.append(warning);

    const preload = section('preload', label('preload_cache', 'Preloading & reusable cache'),
        label('preload_hint', 'Cached means retained by this viewer. Decoded is a past success, not proof that a resource is still cached.'));
    row(preload, 'queue', label('queue', 'Queue / running / limit'));
    row(preload, 'radius', label('radius', 'Actual preview / full radius'));
    row(preload, 'cache', label('cache', 'Cache entries / limit'));
    row(preload, 'hits', label('hits', 'Reuse / fresh / released'));
    const neighbors = document.createElement('div');
    neighbors.className = 'gallery-dev-neighbors';
    const neighborHeader = document.createElement('div');
    neighborHeader.className = 'gallery-dev-neighbor';
    for (const text of [label('neighbor', 'Photo'), stateLabel('preview'), stateLabel('full')]) {
        const cell = document.createElement('span'); cell.textContent = text; neighborHeader.append(cell);
    }
    neighbors.append(neighborHeader);
    const neighborRows = Array.from({length: 7}, () => {
        const item = document.createElement('div');
        item.className = 'gallery-dev-neighbor';
        const cells = Array.from({length: 3}, () => document.createElement('span'));
        item.append(...cells); neighbors.append(item);
        return {item, cells};
    });
    preload.append(neighbors);

    const history = section('history', label('history', 'Runtime graphs'),
        label('history_hint', 'Up to 31 seconds of visible-viewer history, sampled every 350 ms. Each graph has its own scale.'));
    const graphPicker = document.createElement('div');
    graphPicker.className = 'gallery-dev-graph-picker';
    history.append(graphPicker);
    const graphs = [
        {key: 'memory', title: label('memory', 'Decoded cache estimate'), color: graphColors.memory,
            hint: label('memory_hint', 'Width × height × 4 bytes for retained decoded images. Excludes the live image, GPU and browser heap.')},
        {key: 'cached', title: label('resources', 'Cached images / active loads'), color: graphColors.cached,
            hint: label('resources_hint', 'Green: retained cache entries. Amber: unfinished detached loads/decodes; not the browser HTTP cache.')},
        {key: 'frame', title: label('frame', 'Frame interval'), color: graphColors.frame,
            hint: label('frame_hint', 'Time between animation callbacks, not render cost. Dashed line: 16.7 ms (60 Hz). Spikes can include background throttling.')},
    ].map((definition) => {
        const figure = document.createElement('figure');
        figure.hidden = definition.key !== selectedGraph;
        const button = document.createElement('button');
        button.type = 'button'; button.dataset.devGraphTab = definition.key;
        button.textContent = definition.title;
        button.setAttribute('aria-pressed', String(definition.key === selectedGraph));
        button.addEventListener('click', () => {
            selectedGraph = definition.key;
            graphs.forEach((graph) => {
                graph.figure.hidden = graph.key !== selectedGraph;
                graph.button.setAttribute('aria-pressed', String(graph.key === selectedGraph));
                if (graph.key === selectedGraph) drawGraph(graph, samples);
            });
        }, {signal});
        graphPicker.append(button);
        const caption = document.createElement('figcaption');
        const dot = document.createElement('span');
        dot.className = 'gallery-dev-dot'; dot.style.background = definition.color;
        const text = document.createElement('span'); text.textContent = definition.title;
        const value = document.createElement('span'); value.dataset.devGraphValue = definition.key;
        caption.append(dot, text, value);
        if (definition.key === 'cached') {
            const amber = document.createElement('span');
            amber.className = 'gallery-dev-dot'; amber.style.background = graphColors.pending;
            text.append(' / ', amber);
        }
        const canvas = document.createElement('canvas');
        canvas.width = 680; canvas.height = 108;
        canvas.dataset.devGraph = definition.key;
        canvas.setAttribute('role', 'img');
        const hint = document.createElement('p'); hint.className = 'gallery-dev-help'; hint.textContent = definition.hint;
        figure.append(caption, canvas, hint); history.append(figure);
        return {...definition, figure, button, canvas, context: canvas.getContext('2d'), value};
    });

    const viewport = section('viewport', label('viewport', 'Zoom & viewport'),
        label('viewport_hint', 'CSS pixels describe the layout; source pixels describe the loaded file. DPR is the device pixel ratio.'));
    row(viewport, 'zoom', label('zoom', 'Zoom / pan X,Y'));
    row(viewport, 'stage', label('stage', 'Stage / fitted image'));
    row(viewport, 'density', label('density', 'DPR / requested width'));
    row(viewport, 'mode', label('mode', 'Viewer / page visibility'));
    row(viewport, 'heap', label('heap', 'JS heap used / limit'));
    row(viewport, 'network', label('network', 'Connection hints'));

    const lifecycle = section('lifecycle', label('lifecycle', 'Navigation & recent events'),
        label('lifecycle_hint', 'Tokens identify the current owner. A newer token retires older work. Counters cover this viewer session.'));
    row(lifecycle, 'target', label('target', 'Navigation target / phase'));
    row(lifecycle, 'tokens', label('tokens', 'Navigation / quality / preload tokens'));
    row(lifecycle, 'pending', label('pending_work', 'Pending work'));
    row(lifecycle, 'slideshow', label('slideshow', 'Slideshow'));
    row(lifecycle, 'errors', label('errors', 'Failed loads (session)'));
    const log = document.createElement('pre'); log.className = 'gallery-dev-events'; log.dataset.devEvents = '';
    lifecycle.append(log);

    const footer = document.createElement('footer');
    footer.textContent = label('footer', 'Admin only · local observations · no diagnostic media requests');
    shell.append(header, summary, tabs, body, footer);
    host.append(shell);

    /**
     * Refresh controls without replacing values or resetting disclosure/scroll state.
     * @return {void} Updates presentation controls.
     */
    function refreshControls() {
        pause.textContent = paused ? label('resume', 'Resume') : label('pause', 'Freeze');
        pause.setAttribute('aria-pressed', String(paused));
        collapse.textContent = collapsed ? label('show', 'Show') : label('collapse', 'Collapse');
        expand.textContent = expanded ? label('less', 'Less detail') : label('expand', 'Expand');
        expand.setAttribute('aria-pressed', String(expanded));
        collapse.setAttribute('aria-expanded', String(!collapsed));
        body.hidden = collapsed;
        tabs.hidden = collapsed || !expanded;
        shell.dataset.paused = String(paused);
        shell.dataset.collapsed = String(collapsed);
        shell.dataset.expanded = String(expanded);
    }
    pause.addEventListener('click', () => {
        paused = !paused; refreshControls();
        if (!paused) requestRender();
    }, {signal});
    collapse.addEventListener('click', () => {
        collapsed = !collapsed; refreshControls();
        if (!collapsed) requestRender();
    }, {signal});
    expand.addEventListener('click', () => {
        expanded = !expanded; collapsed = false;
        if (!expanded) {
            selectedSection = 'active';
            sections.forEach((entry, key) => {
                entry.page.hidden = key !== 'active';
                entry.button.setAttribute('aria-pressed', String(key === 'active'));
            });
        }
        refreshControls(); requestRender();
    }, {signal});
    // Panel interaction must never reach photo navigation, zoom or stage-click handlers.
    for (const type of ['click', 'dblclick', 'pointerdown', 'touchstart', 'wheel', 'keydown']) {
        shell.addEventListener(type, (event) => event.stopPropagation(), {signal});
    }
    signal.addEventListener('abort', () => shell.remove(), {once: true});
    refreshControls();

    /**
     * Replace text only when a diagnostic value changes.
     * @param {string} key Registered field name.
     * @param {string|number} value Safe display value.
     * @return {void} Updates the existing value host.
     */
    function set(key, value) {
        const node = fields.get(key);
        const text = String(value);
        if (node.textContent !== text) node.textContent = text;
        if (['source', 'name'].includes(key)) node.title = text;
    }

    /**
     * Paint current diagnostics from one read-only viewer snapshot.
     * @param {{time:number,total:number,media:Record<string,*>,quality:Record<string,*>,zoom:Record<string,number>,preload:Record<string,number>,cache:Record<string,number>,neighbors:Array<{offset:number,position:number,preview:string,full:string}|null>,loads:number,frame:number,mode:string,visibility:string,heap:{used:number,limit:number}|null,network:string,navigation:Record<string,*>,pending:Array<string>,slideshow:boolean,slideDuration:number,errors:number,events:Array<string>}} snapshot Existing runtime values prepared by the viewer owner.
     * @return {void} Updates dashboard values and bounded graphs.
     */
    function render(snapshot) {
        if (paused || signal.aborted) return;
        const s = snapshot;
        const media = s.media;
        const kind = stateLabel(media.kind || 'unknown');
        summary.textContent = `${media.position || '—'}/${s.total} · ID ${media.id || '—'} · ${kind} · ${Math.round(s.zoom.scale * 100)}% · ${stateLabel(s.navigation.stage)}`;
        set('photo', `${media.position || '—'}/${s.total} · ID ${media.id || '—'} · ${media.galleryId || '—'}`);
        set('name', media.name || '—');
        set('quality', `${kind} · ${stateLabel(media.loaded ? 'active' : 'pending')}`);
        fields.get('quality').dataset.state = media.loaded ? 'active' : 'pending';
        set('source', lightboxDevSourceLabel(media.src));
        set('intrinsic', `${dimensions(media.width, media.height)} / ${dimensions(media.originalWidth, media.originalHeight)}`);
        set('promotion', s.quality.src ? `${stateLabel(s.quality.kind)} · ${stateLabel('pending')} · ${lightboxDevSourceLabel(s.quality.src)}` : '—');
        set('transfer', s.quality.progress || '—');
        set('timing', media.timing ? `${media.timing.loadMs.toFixed(1)} / ${media.timing.decodeMs.toFixed(1)} ms` : '—');
        set('queue', `${s.preload.queued} / ${s.preload.active} / ${s.preload.limit}`);
        set('radius', `${s.preload.previewRadius} / ${s.preload.fullRadius}`);
        set('cache', `${s.cache.entries} / ${s.cache.limit} · ${sizeLabel(s.cache.bytes)}`);
        set('hits', `${s.cache.hits} / ${s.cache.misses} / ${s.cache.evictions}`);
        s.neighbors.forEach((neighbor, index) => {
            const {item, cells} = neighborRows[index];
            item.hidden = !neighbor;
            if (!neighbor) return;
            cells[0].textContent = `${neighbor.offset > 0 ? '+' : ''}${neighbor.offset} · #${neighbor.position}`;
            for (const [cell, state] of [[cells[1], neighbor.preview], [cells[2], neighbor.full]]) {
                cell.textContent = stateLabel(state); cell.dataset.state = state;
            }
            item.dataset.current = String(neighbor.offset === 0);
        });
        set('zoom', `${Math.round(s.zoom.scale * 100)}% / ${s.zoom.x.toFixed(0)}, ${s.zoom.y.toFixed(0)} px`);
        set('stage', `${dimensions(s.zoom.stageWidth, s.zoom.stageHeight)} / ${dimensions(s.zoom.fittedWidth, s.zoom.fittedHeight)}`);
        set('density', `${s.zoom.dpr.toFixed(2)} / ${Math.round(s.zoom.requiredWidth)} px`);
        set('mode', `${s.mode} / ${s.visibility}`);
        set('heap', s.heap ? `${sizeLabel(s.heap.used)} / ${sizeLabel(s.heap.limit)}` : label('unavailable', 'Not exposed by this browser'));
        set('network', s.network || label('unavailable', 'Not exposed by this browser'));
        set('target', `#${s.navigation.position} · ID ${s.navigation.id} · ${stateLabel(s.navigation.stage)}`);
        set('tokens', `${s.navigation.token} / ${s.quality.token} / ${s.preload.generation}`);
        set('pending', s.pending.map((state) => stateLabel(state)).join(' · ') || '—');
        set('slideshow', s.slideshow ? `${label('running', 'Running')} · ${s.slideDuration} ms` : label('stopped', 'Stopped'));
        set('errors', s.errors);
        log.textContent = s.events.slice(0, 4).map((event) => event.slice(0, 76)).join('\n') || '—';
        const warnings = [];
        if (s.navigation.failed) warnings.push(label('navigation_error', 'Navigation failed; the live image can still be the previous photo.'));
        if (s.quality.failed) warnings.push(label('quality_error', 'A quality source failed; the protected preview remains available.'));
        warning.hidden = !warnings.length;
        warning.textContent = warnings.join(' ');
        if (s.time - lastSampleAt >= sampleInterval) {
            samples.push({time: s.time, memory: s.cache.bytes, cached: s.cache.entries, pending: s.loads, frame: s.frame});
            if (samples.length > historyLimit) samples.shift();
            lastSampleAt = s.time;
        }
        if (!collapsed && selectedSection === 'history') graphs.forEach((graph) => {
            if (graph.key === selectedGraph) drawGraph(graph, samples);
        });
    }
    return {element: shell, isPaused: () => paused, render};
}

/**
 * Draw one labeled graph with real units, elapsed-time positions and its own scale.
 * @param {{canvas:HTMLCanvasElement,context:CanvasRenderingContext2D|null,key:string,title:string,color:string,value:HTMLElement}} graph Canvas, legend, series key and color.
 * @param {Array<{time:number,memory:number,cached:number,pending:number,frame:number}>} samples Bounded visible-viewer history.
 * @return {void} Paints the graph and updates its accessible equivalent.
 */
function drawGraph(graph, samples) {
    const {canvas, context, key} = graph;
    if (!context || !samples.length) return;
    const latest = samples[samples.length - 1];
    const countGraph = key === 'cached';
    const maximum = Math.max(key === 'frame' ? 34 : 1, ...samples.map((s) => s[key]), ...(countGraph ? samples.map((s) => s.pending) : []));
    const unit = (value) => key === 'memory' ? sizeLabel(value) : key === 'frame' ? `${value.toFixed(1)} ms` : `${Math.ceil(value)}`;
    graph.value.textContent = `${unit(latest[key])}${countGraph ? ` / ${latest.pending}` : ''}`;
    canvas.setAttribute('aria-label', `${graph.title}: ${graph.value.textContent}. ${label('scale', 'Scale')} 0–${unit(maximum)}.`);
    const width = canvas.width;
    const height = canvas.height;
    const left = 106;
    const top = 10;
    const bottom = height - 26;
    const plotWidth = width - left - 12;
    context.clearRect(0, 0, width, height);
    context.font = '20px ui-monospace, monospace';
    context.fillStyle = '#afbed0';
    context.fillText(unit(maximum), 2, top + 14);
    context.fillText('0', 2, bottom);
    context.strokeStyle = '#344356';
    context.lineWidth = 1;
    for (const y of [top, (top + bottom) / 2, bottom]) {
        context.beginPath(); context.moveTo(left, y); context.lineTo(width - 12, y); context.stroke();
    }
    const start = Math.max(samples[0].time, latest.time - historyLimit * sampleInterval);
    const duration = Math.max(sampleInterval, latest.time - start);
    context.fillText(`−${(duration / 1000).toFixed(1)} s`, left, height - 3);
    context.fillText('0 s', width - 48, height - 3);
    if (key === 'frame') {
        const y = bottom - (16.7 / maximum) * (bottom - top);
        context.setLineDash([7, 7]); context.strokeStyle = '#b9a0bc';
        context.beginPath(); context.moveTo(left, y); context.lineTo(width - 12, y); context.stroke();
        context.setLineDash([]);
    }
    for (const [series, color] of [[key, graph.color], ...(countGraph ? [['pending', graphColors.pending]] : [])]) {
        context.beginPath();
        samples.filter((sample) => sample.time >= start).forEach((sample, index) => {
            const x = left + Math.max(0, (sample.time - start) / duration) * plotWidth;
            const y = bottom - (sample[series] / maximum) * (bottom - top);
            if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
        });
        context.strokeStyle = color; context.lineWidth = 3; context.stroke();
        context.fillStyle = color; context.beginPath();
        context.arc(left + plotWidth, bottom - (latest[series] / maximum) * (bottom - top), 3, 0, Math.PI * 2); context.fill();
    }
}
