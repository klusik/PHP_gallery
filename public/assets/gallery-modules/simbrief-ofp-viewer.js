/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/simbrief-ofp-viewer.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Show a SimBrief PDF with the gallery lightbox visual language but a
 *   completely independent page sequence.
 *
 * Responsibilities:
 *   - Lazily load the locally vendored Mozilla PDF.js runtime
 *   - Render one page at a time with bounded canvas allocation and cleanup
 *   - Provide page navigation, zoom, touch pinch, fullscreen and PDF download
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-10-07
 */

const locales = {
    en: {title:'Operational flight plan',close:'Close',previous:'Previous page',next:'Next page',zoomIn:'Zoom in',zoomOut:'Zoom out',reset:'Reset zoom',fitWidth:'Fit width',fitPage:'Fit whole page',actualSize:'Actual size (100%)',fullscreen:'Fullscreen (F)',fullscreenExit:'Exit fullscreen (F)',download:'Download PDF',loading:'Loading flight plan…',rendering:'Rendering page…',error:'The PDF could not be displayed. Open the original PDF instead.',fallback:'Open original PDF',page:'Page {page} of {total}',zoom:'Zoom {percent}%',stageHelp:'PDF page; scroll to pan, pinch to zoom',wheelHelp:'Wheel and two-finger scrolling pan the PDF. Ctrl + wheel or trackpad pinch zooms the PDF. F toggles fullscreen; Escape exits fullscreen or closes.'},
    cs: {title:'Operační letový plán',close:'Zavřít',previous:'Předchozí stránka',next:'Další stránka',zoomIn:'Přiblížit',zoomOut:'Oddálit',reset:'Obnovit přiblížení',fitWidth:'Přizpůsobit šířce',fitPage:'Zobrazit celou stránku',actualSize:'Skutečná velikost (100 %)',fullscreen:'Celá obrazovka (F)',fullscreenExit:'Ukončit celou obrazovku (F)',download:'Stáhnout PDF',loading:'Načítání letového plánu…',rendering:'Vykreslování stránky…',error:'PDF nelze zobrazit. Otevřete původní soubor PDF.',fallback:'Otevřít původní PDF',page:'Stránka {page} z {total}',zoom:'Přiblížení {percent} %',stageHelp:'Stránka PDF; posouváním číst, roztažením prstů přibližovat',wheelHelp:'Kolečko a posun dvěma prsty posouvají PDF. Ctrl + kolečko nebo gesto roztažení prstů přibližují obsah. F přepíná celou obrazovku; Escape ji ukončí nebo zavře dokument.'},
    de: {title:'Flugdurchführungsplan',close:'Schließen',previous:'Vorherige Seite',next:'Nächste Seite',zoomIn:'Vergrößern',zoomOut:'Verkleinern',reset:'Zoom zurücksetzen',fitWidth:'An Breite anpassen',fitPage:'Ganze Seite anpassen',actualSize:'Originalgröße (100 %)',fullscreen:'Vollbild (F)',fullscreenExit:'Vollbild beenden (F)',download:'PDF herunterladen',loading:'Flugplan wird geladen…',rendering:'Seite wird gerendert…',error:'Das PDF konnte nicht angezeigt werden. Öffnen Sie das Original-PDF.',fallback:'Original-PDF öffnen',page:'Seite {page} von {total}',zoom:'Zoom {percent} %',stageHelp:'PDF-Seite; scrollen zum Verschieben, Pinch zum Zoomen',wheelHelp:'Mausrad und Scrollen mit zwei Fingern verschieben das PDF. Strg + Mausrad oder Pinch zoomt den Inhalt. F schaltet Vollbild um; Escape beendet Vollbild oder schließt das Dokument.'},
    sv: {title:'Operativ färdplan',close:'Stäng',previous:'Föregående sida',next:'Nästa sida',zoomIn:'Zooma in',zoomOut:'Zooma ut',reset:'Återställ zoom',fitWidth:'Anpassa till bredd',fitPage:'Anpassa hela sidan',actualSize:'Faktisk storlek (100 %)',fullscreen:'Helskärm (F)',fullscreenExit:'Avsluta helskärm (F)',download:'Ladda ned PDF',loading:'Läser in färdplan…',rendering:'Renderar sida…',error:'PDF-filen kunde inte visas. Öppna originalfilen i stället.',fallback:'Öppna original-PDF',page:'Sida {page} av {total}',zoom:'Zoom {percent} %',stageHelp:'PDF-sida; rulla för att panorera, nyp för att zooma',wheelHelp:'Mushjul och rullning med två fingrar flyttar PDF-sidan. Ctrl + mushjul eller nypgest zoomar innehållet. F växlar helskärm; Escape lämnar helskärm eller stänger dokumentet.'},
};
/** Fullscreen controls become transparent when the pointer is inactive.
 * Purpose: Time the PDF-only fullscreen HUD's inactivity transition.
 * Type: number. Units: milliseconds. Scope: active OFP document dialog.
 * Consumers: revealOfpHud(). Rationale: 2.6 seconds keeps the HUD accessible without covering the PDF indefinitely.
 */
const OFP_HUD_IDLE_MS = 2600;
/** Render the final PDF bitmap once a wheel zoom burst has settled.
 * Purpose: Bound PDF.js rerenders while preserving instantaneous canvas-size feedback.
 * Type: number. Units: milliseconds. Scope: one active OFP wheel zoom gesture.
 * Consumers: attachOfpWheelZoom(). Rationale: a 95 ms idle delay avoids rerendering every mouse-wheel event.
 */
const OFP_WHEEL_RENDER_DELAY_MS = 95;
let pdfjsPromise = null;
let activeViewer = null;

/**
 * Bind document viewing to server-rendered links without affecting photo navigation.
 *
 * @returns {void} Install the delegated listener when there is an OFP link.
 */
export function setupSimbriefOfpViewer() {
    if (!document.querySelector('[data-simbrief-ofp-open]') || document.body?.dataset.ofpViewerBound === '1') return;
    document.body.dataset.ofpViewerBound = '1';
    // Only the pinned tooltip needs scripting: keyboard focus and hover use CSS.
    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const toggle = target?.closest('[data-ofp-info-toggle]');
        document.querySelectorAll('[data-ofp-info-toggle][aria-expanded="true"]').forEach((button) => {
            if (button !== toggle) {
                button.setAttribute('aria-expanded', 'false');
                if (button === document.activeElement) button.blur();
            }
        });
        if (toggle) {
            const expanded = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(expanded));
            // Mobile Safari may retain :hover after tapping. Explicitly hide
            // a dismissed tooltip until the next focus, hover-entry or tap.
            toggle.toggleAttribute('data-ofp-info-dismissed', !expanded);
            if (!expanded) toggle.blur();
        }
    });
    document.addEventListener('focusin', (event) => {
        const toggle = event.target instanceof Element ? event.target.closest('[data-ofp-info-toggle]') : null;
        toggle?.removeAttribute('data-ofp-info-dismissed');
    });
    document.addEventListener('pointerout', (event) => {
        const parent = event.target instanceof Element ? event.target.closest('.simbrief-ofp-info') : null;
        if (!parent || (event.relatedTarget instanceof Node && parent.contains(event.relatedTarget))) return;
        parent.querySelector('[data-ofp-info-toggle]')?.removeAttribute('data-ofp-info-dismissed');
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        document.querySelectorAll('[data-ofp-info-toggle][aria-expanded="true"]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('data-ofp-info-dismissed', '');
        });
        const target = event.target instanceof Element ? event.target.closest('[data-ofp-info-toggle]') : null;
        if (target) {
            target.blur(); // Dismiss the focus-only tooltip, not an unrelated dialog.
            event.stopPropagation();
        }
    });
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement
            ? event.target.closest('[data-ofp-convert-form]') : null;
        if (!form) return;
        event.preventDefault();
        if (form.dataset.ofpConverting === '1') return;
        const button = form.querySelector('button[type="submit"]');
        const output = form.querySelector('[data-ofp-convert-result]');
        form.dataset.ofpConverting = '1';
        if (button) button.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) throw new Error(result.error || 'OFP conversion failed');
            output.replaceChildren(document.createTextNode(String(result.message || '')));
            if (typeof result.url === 'string' && result.url !== '') {
                const link = document.createElement('a');
                link.href = result.url;
                link.textContent = ' ' + (locales[(document.documentElement.lang || 'en').slice(0, 2)] || locales.en).title;
                output.appendChild(link);
            }
        } catch (error) {
            output.textContent = error instanceof Error ? error.message : String(error);
        } finally {
            form.dataset.ofpConverting = '0';
            if (button) button.disabled = false;
        }
    });
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-simbrief-ofp-open]') : null;
        if (!(link instanceof HTMLAnchorElement) || event.defaultPrevented || event.button !== 0
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        void openOfpViewer(link);
    });
}

/**
 * Resolve locally distributed PDF.js once, with its matching same-origin worker.
 *
 * @returns {Promise<object>} PDF.js runtime.
 */
function loadPdfjs() {
    if (pdfjsPromise) return pdfjsPromise;
    pdfjsPromise = new Promise((resolve, reject) => {
        if (window.pdfjsLib) return resolve(window.pdfjsLib);
        const script = document.createElement('script');
        script.src = new URL('../vendor/pdfjs/pdf.min.js', import.meta.url).href;
        script.onload = () => window.pdfjsLib ? resolve(window.pdfjsLib) : reject(new Error('PDF.js not available'));
        script.onerror = () => reject(new Error('PDF.js asset failed to load'));
        document.head.appendChild(script);
    }).then((lib) => {
        lib.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
        return lib;
    }).catch((error) => {
        pdfjsPromise = null;
        throw error;
    });
    return pdfjsPromise;
}

/**
 * Construct an independent PDF dialog using shared lightbox layout tokens.
 *
 * @param {Record<string,string>} copy Localized control messages.
 * @returns {HTMLDialogElement} Document lightbox.
 */
function buildOfpDialog(copy) {
    const dialog = document.createElement('dialog');
    dialog.className = 'lightbox simbrief-ofp-dialog';
    dialog.setAttribute('aria-label', copy.title);
    dialog.innerHTML = [
        '<div class="simbrief-ofp-root" data-ofp-root>',
        '<header class="simbrief-ofp-header"><strong data-ofp-text="title"></strong>',
        '<div class="simbrief-ofp-header-actions">',
        '<a class="button secondary" data-ofp-download download="simbrief-ofp.pdf" href="#" data-ofp-text="download"></a>',
        '<button type="button" class="button secondary" data-ofp-action="fullscreen" data-ofp-label="fullscreen" aria-pressed="false" aria-keyshortcuts="F">⛶</button>',
        '<button type="button" class="button secondary" data-ofp-action="close" data-ofp-label="close">✕</button>',
        '</div></header>',
        '<div class="simbrief-ofp-stage" data-ofp-stage tabindex="0">',
        '<div class="simbrief-ofp-canvas-wrap" data-ofp-canvas-wrap></div>',
        '<p class="simbrief-ofp-status" data-ofp-status role="status" aria-live="polite"></p>',
        '<a class="simbrief-ofp-fallback" target="_blank" rel="noopener noreferrer" data-ofp-fallback hidden href="#"></a>',
        '</div>',
        '<footer class="simbrief-ofp-toolbar">',
        '<button type="button" class="button secondary" data-ofp-action="previous" data-ofp-label="previous">◀</button>',
        '<span class="simbrief-ofp-page-number" data-ofp-counter aria-live="polite"></span>',
        '<button type="button" class="button secondary" data-ofp-action="next" data-ofp-label="next">▶</button>',
        '<span class="simbrief-ofp-divider"></span>',
        '<button type="button" class="button secondary" data-ofp-action="zoom-out" data-ofp-label="zoomOut">−</button>',
        '<span class="simbrief-ofp-zoom-level" data-ofp-zoom aria-live="polite"></span>',
        '<button type="button" class="button secondary" data-ofp-action="zoom-in" data-ofp-label="zoomIn">+</button>',
        '<button type="button" class="button secondary" data-ofp-action="reset" data-ofp-label="reset">100%</button>',
        '<button type="button" class="button secondary" data-ofp-action="fit-width" data-ofp-text="fitWidth" aria-pressed="false"></button>',
        '<button type="button" class="button secondary" data-ofp-action="fit-page" data-ofp-text="fitPage" aria-pressed="true"></button>',
        '<button type="button" class="button secondary" data-ofp-action="actual-size" data-ofp-text="actualSize" aria-pressed="false"></button>',
        '</footer>',
        '</div>'
    ].join('');
    dialog.querySelectorAll('[data-ofp-text]').forEach((element) => {
        element.textContent = copy[element.dataset.ofpText] || '';
    });
    dialog.querySelectorAll('[data-ofp-label]').forEach((element) => {
        element.setAttribute('aria-label', copy[element.dataset.ofpLabel] || '');
        element.setAttribute('title', copy[element.dataset.ofpLabel] || '');
    });
    dialog.querySelector('[data-ofp-fallback]').textContent = copy.fallback;
    dialog.querySelector('footer').setAttribute('aria-label', copy.title);
    const stage = dialog.querySelector('[data-ofp-stage]');
    stage.setAttribute('aria-label', copy.stageHelp);
    stage.setAttribute('aria-description', copy.wheelHelp);
    stage.title = copy.wheelHelp;
    return dialog;
}

/**
 * Open the locally authorized PDF in its own modal page sequence.
 *
 * @param {HTMLAnchorElement} link Original PDF link.
 * @returns {Promise<void>} Render its first page or expose an accessible fallback.
 */
async function openOfpViewer(link) {
    if (activeViewer) await closeOfpViewer(activeViewer);
    const language = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    const copy = locales[language] || locales.en;
    const dialog = buildOfpDialog(copy);
    dialog.querySelector('[data-ofp-download]').href =
        link.closest('.simbrief-ofp-actions')?.querySelector('a[download]')?.href || link.href;
    dialog.querySelector('[data-ofp-fallback]').href = link.href;
    if (typeof dialog.showModal !== 'function') {
        window.location.assign(link.href);
        return;
    }
    document.body.appendChild(dialog);
    const state = {
        dialog, fullscreenTarget: dialog.querySelector('[data-ofp-root]'),
        link, copy, loadingTask: null, pdf: null, renderTask: null,
        page: 1, total: 0, zoom: 1, fit: 'page', manualScale: null, renderScale: null,
        generation: 0, closed: false,
        keyHandler: null, resizeObserver: null, resizeHandler: null,
        fullscreenHandler: null, fullscreenErrorHandler: null, fullscreenNative: false,
        fullscreenTransition: false, layoutFrame: null, pinch: null,
        listeners: new AbortController(), hudTimer: null, wheelRenderTimer: null,
        webkitGesture: null, touchPanAfterPinch: null, pageUnit: null,
    };
    activeViewer = state;
    dialog.showModal();
    dialog.querySelector('[data-ofp-stage]').focus();
    setOfpStatus(state, copy.loading);
    dialog.addEventListener('click', (event) => {
        const action = event.target instanceof Element
            ? event.target.closest('[data-ofp-action]')?.dataset.ofpAction : null;
        if (action) void runOfpAction(state, action);
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        void runOfpAction(state, isOfpFullscreen(state) ? 'fullscreen' : 'close');
    });
    // Prevent a photo lightbox behind this modal from receiving its unhandled keys.
    dialog.addEventListener('keydown', (event) => event.stopPropagation());
    state.keyHandler = (event) => handleOfpKeyboard(state, event);
    document.addEventListener('keydown', state.keyHandler, true);
    attachOfpWheelZoom(state);
    attachOfpHud(state);
    state.resizeHandler = () => scheduleOfpFit(state);
    if (typeof ResizeObserver === 'function') {
        state.resizeObserver = new ResizeObserver(state.resizeHandler);
        state.resizeObserver.observe(dialog.querySelector('[data-ofp-stage]'));
        state.resizeObserver.observe(dialog.querySelector('.simbrief-ofp-header'));
        state.resizeObserver.observe(dialog.querySelector('.simbrief-ofp-toolbar'));
    } else {
        // Older browsers still need fitting when their viewport changes.
        window.addEventListener('resize', state.resizeHandler);
    }
    state.fullscreenHandler = () => syncOfpFullscreen(state);
    document.addEventListener('fullscreenchange', state.fullscreenHandler);
    state.fullscreenErrorHandler = (event) => {
        if (event.target !== state.fullscreenTarget || !state.fullscreenTransition || state.closed) return;
        // Match the photo viewer: a denied browser request retains a CSS fullscreen fallback.
        dialog.classList.add('is-ofp-fullscreen');
        syncOfpFullscreen(state);
    };
    document.addEventListener('fullscreenerror', state.fullscreenErrorHandler);
    attachOfpTouchZoom(state);
    try {
        const lib = await loadPdfjs();
        if (state.closed) return;
        state.loadingTask = lib.getDocument({url: link.href, withCredentials: true, isEvalSupported: false});
        const pdf = await state.loadingTask.promise;
        if (state.closed) {
            await pdf.destroy();
            return;
        }
        if (pdf.numPages < 1 || pdf.numPages > 300) throw new Error('Unsupported PDF page count');
        state.pdf = pdf;
        state.total = pdf.numPages;
        await renderOfpPage(state);
    } catch (error) {
        if (!state.closed) {
            console.warn('SimBrief OFP PDF:', error);
            setOfpStatus(state, copy.error, true);
        }
    }
}

/**
 * Show a localized loading state or browser-PDF fallback.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {string} message Status text.
 * @param {boolean} error Whether to show the fallback link.
 * @returns {void} Update visible status.
 */
function setOfpStatus(state, message, error = false) {
    state.dialog.querySelector('[data-ofp-status]').textContent = message;
    state.dialog.querySelector('[data-ofp-fallback]').hidden = !error;
}

/**
 * Calculate the PDF viewport scale from the real document stage, not the window.
 * The toolbar/header are grid siblings and are already excluded by clientHeight.
 *
 * @param {Record<string, unknown>} state Active document view and zoom mode.
 * @param {{width: number, height: number}} unit Unscaled, rotated PDF page dimensions.
 * @param {HTMLElement} stage Scrollable area reserved for the document.
 * @param {HTMLElement} wrap Padded canvas container.
 * @returns {number} CSS scale for the requested fit or persistent manual zoom.
 */
function ofpViewportScale(state, unit, stage, wrap) {
    if (state.manualScale !== null) return state.manualScale;
    if (state.fit === 'actual') return 96 / 72; // PDF.js convention: 100% at 96 CSS pixels per inch.
    const css = getComputedStyle(wrap);
    const horizontalPadding = parseFloat(css.paddingLeft) + parseFloat(css.paddingRight);
    const verticalPadding = parseFloat(css.paddingTop) + parseFloat(css.paddingBottom);
    // Fullscreen chrome floats above the stage; reserve its maximum vertical footprint
    // on BOTH sides so a centered whole page never sits behind a visible toolbar.
    const chrome = isOfpFullscreen(state) ? 2 * (
        Math.max(
            state.dialog.querySelector('.simbrief-ofp-header').offsetHeight,
            state.dialog.querySelector('.simbrief-ofp-toolbar').offsetHeight
        ) + 20
    ) : 0;
    const widthScale = Math.max(1, stage.clientWidth - horizontalPadding - 16) / Math.max(1, unit.width);
    const heightScale = Math.max(1, stage.clientHeight - verticalPadding - chrome - 16) / Math.max(1, unit.height);
    return state.fit === 'width' ? widthScale : Math.min(widthScale, heightScale);
}

/**
 * Coalesce layout changes while retaining manual CSS page scale and pan.
 *
 * @param {Record<string, unknown>} state Active document view and zoom mode.
 * @returns {void} Re-render an active automatic fit after layout stabilizes.
 */
function scheduleOfpFit(state) {
    if (state.closed || !state.pdf) return;
    if (state.manualScale !== null) {
        // Resize the manual pan gutters without changing the PDF's CSS page scale.
        const stage = state.dialog.querySelector('[data-ofp-stage]');
        const rect = stage.getBoundingClientRect();
        const anchor = captureOfpAnchor(state, rect.left + rect.width / 2, rect.top + rect.height / 2);
        updateOfpPanLayout(state);
        restoreOfpAnchor(state, anchor);
        return;
    }
    if (state.fit === 'actual' || state.layoutFrame !== null) return;
    state.layoutFrame = requestAnimationFrame(() => {
        state.layoutFrame = null;
        if (!state.closed && state.manualScale === null && state.fit !== 'actual') {
            void renderOfpPage(state);
        }
    });
}

/**
 * Set manual zoom in fitted-relative steps while fixing the actual CSS scale.
 *
 * @param {Record<string, unknown>} state Active document view and zoom mode.
 * @param {number} zoom Requested multiplier relative to the last automatic fit.
 * @returns {void} Preserve a manual scale independently of future stage resizes.
 */
function setOfpManualZoom(state, zoom) {
    const baseline = state.manualScale === null
        ? (state.renderScale || 1)
        : state.manualScale / Math.max(0.01, state.zoom);
    state.zoom = Math.max(0.5, Math.min(5, Math.round(zoom * 100) / 100));
    state.manualScale = baseline * state.zoom;
}

/**
 * Capture a PDF-relative point underneath a viewport cursor, independent of scroll.
 *
 * @param {Record<string, unknown>} state Active OFP document and canvas.
 * @param {number} clientX Cursor position in viewport CSS pixels.
 * @param {number} clientY Cursor position in viewport CSS pixels.
 * @returns {{x:number,y:number,clientX:number,clientY:number}|null} Stable PDF point.
 */
function captureOfpAnchor(state, clientX, clientY) {
    const canvas = state.dialog.querySelector('[data-ofp-canvas-wrap] canvas');
    if (!canvas) return null;
    const rect = canvas.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return null;
    return {
        x: Math.max(0, Math.min(1, (clientX - rect.left) / rect.width)),
        y: Math.max(0, Math.min(1, (clientY - rect.top) / rect.height)),
        clientX, clientY,
    };
}

/**
 * Restore a PDF point to its old cursor position, clamped by the stage scrollbars.
 *
 * @param {Record<string, unknown>} state Active OFP document and canvas.
 * @param {{x:number,y:number,clientX:number,clientY:number}|null} anchor PDF point to retain.
 * @returns {void} Update only the scrollable document, never the chrome.
 */
function restoreOfpAnchor(state, anchor) {
    if (!anchor) return;
    const stage = state.dialog.querySelector('[data-ofp-stage]');
    const rect = state.dialog.querySelector('[data-ofp-canvas-wrap] canvas')?.getBoundingClientRect();
    if (!rect) return;
    stage.scrollTo({
        left: stage.scrollLeft + rect.left + anchor.x * rect.width - anchor.clientX,
        top: stage.scrollTop + rect.top + anchor.y * rect.height - anchor.clientY,
        behavior: 'instant',
    });
}

/**
 * Give manually zoomed pages enough scroll margin to retain off-center cursor anchors
 * even while the page is smaller than the viewport.
 *
 * @param {Record<string, unknown>} state Active OFP document and zoom mode.
 * @returns {void} Refresh stage-only pan gutters without resizing the toolbar.
 */
function updateOfpPanLayout(state) {
    const manual = state.manualScale !== null;
    const wrap = state.dialog.querySelector('[data-ofp-canvas-wrap]');
    state.dialog.classList.toggle('is-ofp-manual-zoom', manual);
    if (!manual) {
        wrap.style.removeProperty('--ofp-pan-x');
        wrap.style.removeProperty('--ofp-pan-y');
        return;
    }
    const stage = state.dialog.querySelector('[data-ofp-stage]');
    wrap.style.setProperty('--ofp-pan-x', Math.ceil(stage.clientWidth / 2 + 12) + 'px');
    wrap.style.setProperty('--ofp-pan-y', Math.ceil(stage.clientHeight / 2 + 12) + 'px');
}

/**
 * Stretch the current canvas as a cheap zoom preview before PDF.js resamples it.
 *
 * @param {Record<string, unknown>} state Active OFP document and requested CSS scale.
 * @param {{x:number,y:number,clientX:number,clientY:number}|null} anchor Fixed PDF point.
 * @returns {void} Apply the pending zoom to document content only.
 */
function previewOfpScale(state, anchor) {
    if (!state.pageUnit || state.manualScale === null) return;
    const canvas = state.dialog.querySelector('[data-ofp-canvas-wrap] canvas');
    if (!canvas) return;
    updateOfpPanLayout(state);
    canvas.style.width = Math.ceil(state.pageUnit.width * state.manualScale) + 'px';
    canvas.style.height = Math.ceil(state.pageUnit.height * state.manualScale) + 'px';
    restoreOfpAnchor(state, anchor);
    updateOfpControls(state);
}

/**
 * Zoom only on Ctrl+wheel or synthesized trackpad pinch. Unmodified wheel
 * events remain native stage scrolling in either axis and every fit mode.
 * Modifier-agnostic delta heuristics are unreliable across mice/trackpads.
 *
 * @param {Record<string, unknown>} state Active OFP viewer and scroll stage.
 * @returns {void} Bind scoped wheel and Safari gesture events with teardown.
 */
function attachOfpWheelZoom(state) {
    const stage = state.dialog.querySelector('[data-ofp-stage]');
    stage.addEventListener('wheel', (event) => {
        if (state.closed || !state.pageUnit || !state.pdf || !event.ctrlKey
            || event.altKey || event.metaKey || event.shiftKey) return;
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('a, button, input, textarea, select, [contenteditable]')) return;
        const rect = stage.querySelector('canvas')?.getBoundingClientRect();
        if (!rect || event.clientX < rect.left || event.clientX > rect.right
            || event.clientY < rect.top || event.clientY > rect.bottom) return;
        // Also consume Ctrl+wheel at the zoom limits, to prevent browser zoom
        // from escaping the active media stage.
        if (event.cancelable) event.preventDefault();
        if (state.webkitGesture || event.deltaY === 0) return;
        const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? stage.clientHeight : 1;
        const delta = Math.max(-360, Math.min(360, event.deltaY * unit));
        const anchor = captureOfpAnchor(state, event.clientX, event.clientY);
        const previousScale = state.manualScale;
        setOfpManualZoom(state, state.zoom * Math.exp(-delta * 0.003));
        if (state.manualScale === previousScale) return;
        previewOfpScale(state, anchor);
        if (state.wheelRenderTimer !== null) clearTimeout(state.wheelRenderTimer);
        const {clientX, clientY} = event;
        state.wheelRenderTimer = setTimeout(() => {
            state.wheelRenderTimer = null;
            void renderOfpPage(state, captureOfpAnchor(state, clientX, clientY));
        }, OFP_WHEEL_RENDER_DELAY_MS);
    }, {passive: false, signal: state.listeners.signal});

    // Desktop Safari can emit WebKit GestureEvents instead of Ctrl+wheel.
    // Ignore them when physical touchscreen fingers already own the pinch.
    const onGesture = (event) => {
        if (state.closed || !state.pdf || !state.pageUnit || state.pinch) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target || !stage.contains(target)
            || target.closest('a, button, input, textarea, select')) return;
        const rect = stage.querySelector('canvas')?.getBoundingClientRect();
        if (!rect) return;
        const x = Number.isFinite(event.clientX) ? event.clientX : rect.left + rect.width / 2;
        const y = Number.isFinite(event.clientY) ? event.clientY : rect.top + rect.height / 2;
        if (event.cancelable) event.preventDefault();
        if (event.type === 'gesturestart') {
            state.webkitGesture = {
                zoom: state.zoom, scale: Math.max(0.01, Number(event.scale) || 1),
                anchor: captureOfpAnchor(state, x, y), x, y,
            };
            return;
        }
        if (!state.webkitGesture) return;
        if (event.type === 'gesturechange') {
            const gesture = state.webkitGesture;
            setOfpManualZoom(state, gesture.zoom * Math.max(0.01, Number(event.scale) || 1) / gesture.scale);
            if (gesture.anchor) {
                gesture.anchor.clientX = x;
                gesture.anchor.clientY = y;
            }
            gesture.x = x;
            gesture.y = y;
            previewOfpScale(state, gesture.anchor);
        } else if (event.type === 'gestureend') {
            const gesture = state.webkitGesture;
            state.webkitGesture = null;
            void renderOfpPage(state, captureOfpAnchor(state, gesture.x, gesture.y));
        }
    };
    for (const name of ['gesturestart', 'gesturechange', 'gestureend']) {
        stage.addEventListener(name, onGesture, {passive: false, signal: state.listeners.signal});
    }
}

/**
 * Hide floating fullscreen PDF controls after inactivity, but retain focused,
 * hovered or keyboard-accessed controls for accessibility.
 *
 * @param {Record<string, unknown>} state Active OFP document and HUD timer.
 * @returns {void} Reveal the chrome and reset its single idle timer.
 */
function revealOfpHud(state) {
    if (state.hudTimer !== null) clearTimeout(state.hudTimer);
    state.hudTimer = null;
    state.dialog.classList.remove('is-ofp-hud-hidden');
    if (state.closed || !isOfpFullscreen(state) || document.hidden) return;
    state.hudTimer = setTimeout(() => {
        state.hudTimer = null;
        if (state.closed || !isOfpFullscreen(state)) return;
        const header = state.dialog.querySelector('.simbrief-ofp-header');
        const toolbar = state.dialog.querySelector('.simbrief-ofp-toolbar');
        const focusedControl = state.dialog.querySelector(
            '.simbrief-ofp-header :focus-visible, .simbrief-ofp-toolbar :focus-visible'
        );
        if (header.matches(':hover') || toolbar.matches(':hover') || focusedControl) return;
        state.dialog.classList.add('is-ofp-hud-hidden');
    }, OFP_HUD_IDLE_MS);
}

/**
 * Own every transient HUD interaction and remove the listeners with the viewer.
 *
 * @param {Record<string, unknown>} state Active document viewer with a listener controller.
 * @returns {void} Register isolated pointer, focus, keyboard and visibility reactions.
 */
function attachOfpHud(state) {
    const {dialog} = state;
    const options = {signal: state.listeners.signal};
    dialog.addEventListener('pointermove', () => revealOfpHud(state), options);
    dialog.addEventListener('pointerdown', () => revealOfpHud(state), options);
    dialog.addEventListener('touchstart', () => revealOfpHud(state), options);
    dialog.addEventListener('focusin', () => revealOfpHud(state), options);
    dialog.addEventListener('focusout', () => revealOfpHud(state), options);
    dialog.addEventListener('keydown', () => revealOfpHud(state), options);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && state.hudTimer !== null) {
            clearTimeout(state.hudTimer);
            state.hudTimer = null;
        } else if (!document.hidden) {
            revealOfpHud(state);
        }
    }, options);
}

/**
 * Render only the current page to a bounded-resolution canvas.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {{x:number,y:number,clientX:number,clientY:number}|null} anchor PDF point kept at its screen position.
 * @returns {Promise<void>} Render safely despite rapid navigation or cancellation.
 */
async function renderOfpPage(state, anchor = null) {
    if (state.closed || !state.pdf) return;
    const generation = ++state.generation;
    if (state.wheelRenderTimer !== null) {
        clearTimeout(state.wheelRenderTimer);
        state.wheelRenderTimer = null;
    }
    if (state.renderTask) {
        state.renderTask.cancel();
        state.renderTask = null;
    }
    setOfpStatus(state, state.copy.rendering);
    const page = await state.pdf.getPage(state.page);
    if (state.closed || generation !== state.generation) {
        page.cleanup();
        return;
    }
    const stage = state.dialog.querySelector('[data-ofp-stage]');
    const wrap = state.dialog.querySelector('[data-ofp-canvas-wrap]');
    const unit = page.getViewport({scale: 1});
    const scale = ofpViewportScale(state, unit, stage, wrap);
    const viewport = page.getViewport({scale});
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', {alpha: false});
    if (!ctx) {
        page.cleanup();
        setOfpStatus(state, state.copy.error, true);
        return;
    }
    const deviceScale = Math.min(2, window.devicePixelRatio || 1);
    const ratio = Math.min(deviceScale, Math.sqrt(16000000 / (viewport.width * viewport.height)));
    canvas.width = Math.max(1, Math.floor(viewport.width * ratio));
    canvas.height = Math.max(1, Math.floor(viewport.height * ratio));
    canvas.style.width = Math.ceil(viewport.width) + 'px';
    canvas.style.height = Math.ceil(viewport.height) + 'px';
    canvas.setAttribute('role', 'img');
    canvas.setAttribute('aria-label', state.copy.page.replace('{page}', state.page).replace('{total}', state.total));
    // Render off-screen. Keep the old PDF bitmap visible through a zoom burst.
    const task = page.render({
        canvasContext: ctx, viewport,
        transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0],
    });
    state.renderTask = task;
    try {
        await task.promise;
        if (!state.closed && generation === state.generation) {
            updateOfpPanLayout(state);
            wrap.replaceChildren(canvas);
            state.pageUnit = unit;
            state.renderScale = scale;
            if (anchor) {
                restoreOfpAnchor(state, anchor);
            } else if (state.manualScale !== null) {
                stage.scrollTo({
                    left: (stage.scrollWidth - stage.clientWidth) / 2,
                    top: (stage.scrollHeight - stage.clientHeight) / 2,
                    behavior: 'instant',
                });
            } else {
                stage.scrollTo({top: 0, left: 0, behavior: 'instant'});
            }
            setOfpStatus(state, '');
            updateOfpControls(state);
        }
    } catch (error) {
        if (error?.name !== 'RenderingCancelledException' && !state.closed && generation === state.generation) {
            console.warn('SimBrief PDF page:', error);
            setOfpStatus(state, state.copy.error, true);
        }
    } finally {
        if (state.renderTask === task) state.renderTask = null;
        page.cleanup();
    }
}

/**
 * Update the independent page counter, buttons and zoom label.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @returns {void} Refresh controls.
 */
function updateOfpControls(state) {
    state.dialog.querySelector('[data-ofp-counter]').textContent =
        state.copy.page.replace('{page}', state.page).replace('{total}', state.total);
    state.dialog.querySelector('[data-ofp-zoom]').textContent =
        state.copy.zoom.replace('{percent}', Math.round(state.zoom * 100));
    state.dialog.querySelector('[data-ofp-action="previous"]').disabled = state.page <= 1;
    state.dialog.querySelector('[data-ofp-action="next"]').disabled = state.page >= state.total;
    for (const [action, fit] of [['fit-page', 'page'], ['fit-width', 'width'], ['actual-size', 'actual']]) {
        state.dialog.querySelector('[data-ofp-action="' + action + '"]').setAttribute(
            'aria-pressed', String(state.manualScale === null && state.fit === fit)
        );
    }
}

/**
 * Check the native or CSS-only fullscreen presentation of this particular PDF.
 *
 * @param {Record<string, unknown>} state Active OFP modal and fullscreen state.
 * @returns {boolean} Whether the document viewer is currently fullscreen.
 */
function isOfpFullscreen(state) {
    return document.fullscreenElement === state.fullscreenTarget
        || state.dialog.classList.contains('is-ofp-fullscreen');
}

/**
 * Reconcile native fullscreen transitions, including the browser Escape shortcut.
 * A CSS-only fallback remains independent of the browser fullscreen ownership.
 *
 * @param {Record<string, unknown>} state Active OFP modal and fullscreen state.
 * @returns {void} Keep the dialog, accessible button and fitted viewport synchronized.
 */
function syncOfpFullscreen(state) {
    if (state.closed) return;
    const native = document.fullscreenElement === state.fullscreenTarget;
    if (native) {
        state.fullscreenNative = true;
        state.dialog.classList.add('is-ofp-fullscreen');
    } else if (state.fullscreenNative) {
        // Native fullscreen ended via Escape, browser UI or an external transition.
        state.fullscreenNative = false;
        state.dialog.classList.remove('is-ofp-fullscreen');
    }
    const fullscreen = isOfpFullscreen(state);
    const control = state.dialog.querySelector('[data-ofp-action="fullscreen"]');
    const title = fullscreen ? state.copy.fullscreenExit : state.copy.fullscreen;
    control.setAttribute('aria-pressed', String(fullscreen));
    control.setAttribute('aria-label', title);
    control.title = title;
    scheduleOfpFit(state);
    revealOfpHud(state);
}

/**
 * Toggle fullscreen for the OFP content root, never the dialog element itself.
 * The Fullscreen API forbids <dialog> as a target; an inner root holds PDF and HUD.
 * Retain the same dialog's CSS fallback when native requests fail.
 *
 * @param {Record<string, unknown>} state Active OFP modal and fullscreen state.
 * @returns {Promise<void>} Complete the native request or apply the local fallback.
 */
async function toggleOfpFullscreen(state) {
    if (state.closed || state.fullscreenTransition) return;
    state.fullscreenTransition = true;
    try {
        if (isOfpFullscreen(state)) {
            if (document.fullscreenElement === state.fullscreenTarget) {
                try {
                    await document.exitFullscreen();
                } catch (_error) {
                    // A refused exit must keep the live native viewer state intact.
                }
            } else {
                state.dialog.classList.remove('is-ofp-fullscreen');
            }
        } else {
            // Do not replace a fullscreen owner unrelated to this document.
            if (document.fullscreenElement && document.fullscreenElement !== state.fullscreenTarget) return;
            if (typeof state.fullscreenTarget.requestFullscreen === 'function') {
                try {
                    await state.fullscreenTarget.requestFullscreen();
                } catch (_error) {
                    // Same CSS fallback policy as the existing gallery photo viewer.
                }
            }
            if (!state.closed) state.dialog.classList.add('is-ofp-fullscreen');
        }
    } finally {
        state.fullscreenTransition = false;
        syncOfpFullscreen(state);
    }
}

/**
 * Dispatch an OFP-only action without invoking photo lightbox handlers.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {string} action OFP toolbar operation.
 * @returns {Promise<void>} Apply the operation.
 */
async function runOfpAction(state, action) {
    if (state.closed) return;
    if (action === 'close') return closeOfpViewer(state);
    if (action === 'fullscreen') return toggleOfpFullscreen(state);
    if (!state.pdf) return;
    if (state.wheelRenderTimer !== null) {
        clearTimeout(state.wheelRenderTimer);
        state.wheelRenderTimer = null;
    }
    state.webkitGesture = null;
    let anchor = null;
    if (action === 'previous') state.page = Math.max(1, state.page - 1);
    else if (action === 'next') state.page = Math.min(state.total, state.page + 1);
    else if (action === 'zoom-in' || action === 'zoom-out') {
        const stage = state.dialog.querySelector('[data-ofp-stage]');
        const rect = stage.getBoundingClientRect();
        anchor = captureOfpAnchor(state, rect.left + rect.width / 2, rect.top + rect.height / 2);
        setOfpManualZoom(state, state.zoom + (action === 'zoom-in' ? 0.25 : -0.25));
    } else if (action === 'reset') {
        state.zoom = 1;
        state.manualScale = null;
    } else if (action === 'fit-width' || action === 'fit-page' || action === 'actual-size') {
        state.fit = action === 'fit-page' ? 'page' : action === 'fit-width' ? 'width' : 'actual';
        state.zoom = 1;
        state.manualScale = null;
    } else return;
    await renderOfpPage(state, anchor);
}

/**
 * Intercept the document keys before the photo lightbox global shortcuts.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {KeyboardEvent} event Keyboard event in capture phase.
 * @returns {void} Route the key to this document only.
 */
function handleOfpKeyboard(state, event) {
    if (state.closed || !state.dialog.open || event.altKey || event.ctrlKey || event.metaKey
        || event.isComposing) return;
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')
        || target?.isContentEditable) return;
    if (isOfpFullscreen(state)) revealOfpHud(state);
    const key = event.key.toLowerCase();
    const actions = {ArrowLeft:'previous',ArrowRight:'next','+':'zoom-in','=':'zoom-in','-':'zoom-out','0':'reset'};
    const action = key === 'f' ? 'fullscreen'
        : event.key === 'Escape' ? (isOfpFullscreen(state) ? 'fullscreen' : 'close')
        : actions[event.key];
    if (!action) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (event.repeat) return;
    void runOfpAction(state, action);
}

/**
 * Apply two-finger pinch zoom on release, preserving a cheap live preview.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @returns {void} Register touch gesture listeners on the document viewport.
 */
function attachOfpTouchZoom(state) {
    const stage = state.dialog.querySelector('[data-ofp-stage]');
    const distance = (touches) => Math.hypot(
        touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY
    );
    const midpoint = (touches) => ({
        x: (touches[0].clientX + touches[1].clientX) / 2,
        y: (touches[0].clientY + touches[1].clientY) / 2,
    });
    const options = {signal: state.listeners.signal};
    const renderAfterPinch = (center) => {
        if (!state.pdf) return;
        const anchor = captureOfpAnchor(state, center.x, center.y);
        void renderOfpPage(state, anchor);
    };
    stage.addEventListener('touchstart', (event) => {
        if (state.closed) return;
        if (event.touches.length >= 2) {
            const center = midpoint(event.touches);
            state.webkitGesture = null;
            state.touchPanAfterPinch = null;
            state.pinch = {
                distance: distance(event.touches),
                zoom: state.zoom,
                anchor: captureOfpAnchor(state, center.x, center.y),
                center,
            };
            if (state.wheelRenderTimer !== null) {
                clearTimeout(state.wheelRenderTimer);
                state.wheelRenderTimer = null;
            }
        } else if (event.touches.length === 1 && !state.pinch) {
            const finger = event.touches[0];
            state.touchPanAfterPinch = {
                identifier: finger.identifier, x: finger.clientX, y: finger.clientY,
                fromPinch: false, center: null,
            };
        }
    }, {...options, passive: true});
    stage.addEventListener('touchmove', (event) => {
        if (state.closed) return;
        if (state.pinch && event.touches.length >= 2) {
            if (event.cancelable) event.preventDefault();
            const center = midpoint(event.touches);
            const factor = distance(event.touches) / Math.max(1, state.pinch.distance);
            setOfpManualZoom(state, state.pinch.zoom * factor);
            if (state.pinch.anchor) {
                state.pinch.anchor.clientX = center.x;
                state.pinch.anchor.clientY = center.y;
            }
            previewOfpScale(state, state.pinch.anchor);
            state.pinch.center = center;
            return;
        }
        if (!state.pinch && event.touches.length === 1 && state.touchPanAfterPinch) {
            const finger = event.touches[0];
            const pan = state.touchPanAfterPinch;
            if (finger.identifier !== pan.identifier) return;
            if (event.cancelable) event.preventDefault();
            // Explicit stage scrolling remains available in Fit width and manual
            // zoom while touch-action:none prevents the underlying page moving.
            stage.scrollLeft += pan.x - finger.clientX;
            stage.scrollTop += pan.y - finger.clientY;
            pan.x = finger.clientX;
            pan.y = finger.clientY;
        }
    }, {...options, passive: false});
    const complete = (event) => {
        if (state.closed) return;
        if (event.type === 'touchcancel') {
            const pinchCenter = state.pinch?.center || state.touchPanAfterPinch?.center;
            const changedScale = Boolean(state.pinch || state.touchPanAfterPinch?.fromPinch);
            state.pinch = null;
            state.touchPanAfterPinch = null;
            if (changedScale && pinchCenter) renderAfterPinch(pinchCenter);
            return;
        }
        if (state.pinch && event.touches.length < 2) {
            const center = state.pinch.center;
            state.pinch = null;
            if (event.touches.length === 1) {
                const finger = event.touches[0];
                state.touchPanAfterPinch = {
                    identifier: finger.identifier, x: finger.clientX, y: finger.clientY,
                    fromPinch: true, center,
                };
            } else {
                state.touchPanAfterPinch = null;
                renderAfterPinch(center);
            }
            return;
        }
        if (event.touches.length === 0) {
            const pan = state.touchPanAfterPinch;
            state.touchPanAfterPinch = null;
            if (pan?.fromPinch && pan.center) {
                // Defer rasterization until the last finger lifts: rerendering
                // midway through a one-finger pan would snap the scroll offset.
                const rect = stage.getBoundingClientRect();
                renderAfterPinch({
                    x: rect.left + rect.width / 2,
                    y: rect.top + rect.height / 2,
                });
            }
        }
    };
    stage.addEventListener('touchend', complete, {...options, passive: true});
    stage.addEventListener('touchcancel', complete, {...options, passive: true});
}

/**
 * Release the PDF worker, old renders and event listeners on close.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @returns {Promise<void>} Restore focus to the original gallery link.
 */
async function closeOfpViewer(state) {
    if (state.closed) return;
    state.closed = true;
    ++state.generation;
    state.resizeObserver?.disconnect();
    state.listeners.abort();
    if (state.hudTimer !== null) clearTimeout(state.hudTimer);
    if (state.wheelRenderTimer !== null) clearTimeout(state.wheelRenderTimer);
    state.webkitGesture = null;
    state.touchPanAfterPinch = null;
    state.pinch = null;
    window.removeEventListener('resize', state.resizeHandler);
    document.removeEventListener('fullscreenchange', state.fullscreenHandler);
    document.removeEventListener('fullscreenerror', state.fullscreenErrorHandler);
    if (state.layoutFrame !== null) cancelAnimationFrame(state.layoutFrame);
    document.removeEventListener('keydown', state.keyHandler, true);
    state.renderTask?.cancel();
    if (document.fullscreenElement === state.fullscreenTarget) {
        try { await document.exitFullscreen(); } catch (_error) {}
    }
    if (state.loadingTask) {
        try { await state.loadingTask.destroy(); } catch (_error) {}
    }
    if (state.dialog.open) state.dialog.close();
    state.dialog.remove();
    if (activeViewer === state) activeViewer = null;
    if (state.link.isConnected) state.link.focus();
}
