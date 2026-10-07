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
    en: {title:'Operational flight plan',close:'Close',previous:'Previous page',next:'Next page',zoomIn:'Zoom in',zoomOut:'Zoom out',reset:'Reset zoom',fitWidth:'Fit width',fitPage:'Fit whole page',actualSize:'Actual size (100%)',fullscreen:'Fullscreen',download:'Download PDF',loading:'Loading flight plan…',rendering:'Rendering page…',error:'The PDF could not be displayed. Open the original PDF instead.',fallback:'Open original PDF',page:'Page {page} of {total}',zoom:'Zoom {percent}%'},
    cs: {title:'Operační letový plán',close:'Zavřít',previous:'Předchozí stránka',next:'Další stránka',zoomIn:'Přiblížit',zoomOut:'Oddálit',reset:'Obnovit přiblížení',fitWidth:'Přizpůsobit šířce',fitPage:'Zobrazit celou stránku',actualSize:'Skutečná velikost (100 %)',fullscreen:'Celá obrazovka',download:'Stáhnout PDF',loading:'Načítání letového plánu…',rendering:'Vykreslování stránky…',error:'PDF nelze zobrazit. Otevřete původní soubor PDF.',fallback:'Otevřít původní PDF',page:'Stránka {page} z {total}',zoom:'Přiblížení {percent} %'},
    de: {title:'Flugdurchführungsplan',close:'Schließen',previous:'Vorherige Seite',next:'Nächste Seite',zoomIn:'Vergrößern',zoomOut:'Verkleinern',reset:'Zoom zurücksetzen',fitWidth:'An Breite anpassen',fitPage:'Ganze Seite anpassen',actualSize:'Originalgröße (100 %)',fullscreen:'Vollbild',download:'PDF herunterladen',loading:'Flugplan wird geladen…',rendering:'Seite wird gerendert…',error:'Das PDF konnte nicht angezeigt werden. Öffnen Sie das Original-PDF.',fallback:'Original-PDF öffnen',page:'Seite {page} von {total}',zoom:'Zoom {percent} %'},
    sv: {title:'Operativ färdplan',close:'Stäng',previous:'Föregående sida',next:'Nästa sida',zoomIn:'Zooma in',zoomOut:'Zooma ut',reset:'Återställ zoom',fitWidth:'Anpassa till bredd',fitPage:'Anpassa hela sidan',actualSize:'Faktisk storlek (100 %)',fullscreen:'Helskärm',download:'Ladda ned PDF',loading:'Läser in färdplan…',rendering:'Renderar sida…',error:'PDF-filen kunde inte visas. Öppna originalfilen i stället.',fallback:'Öppna original-PDF',page:'Sida {page} av {total}',zoom:'Zoom {percent} %'},
};
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
        '<header class="simbrief-ofp-header"><strong data-ofp-text="title"></strong>',
        '<div class="simbrief-ofp-header-actions">',
        '<a class="button secondary" data-ofp-download download="simbrief-ofp.pdf" href="#" data-ofp-text="download"></a>',
        '<button type="button" class="button secondary" data-ofp-action="fullscreen" data-ofp-label="fullscreen">⛶</button>',
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
        '</footer>'
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
        dialog, link, copy, loadingTask: null, pdf: null, renderTask: null,
        page: 1, total: 0, zoom: 1, fit: 'page', manualScale: null, renderScale: null,
        generation: 0, closed: false,
        keyHandler: null, resizeObserver: null, resizeHandler: null,
        fullscreenHandler: null, layoutFrame: null, pinch: null,
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
        void closeOfpViewer(state);
    });
    state.keyHandler = (event) => handleOfpKeyboard(state, event);
    document.addEventListener('keydown', state.keyHandler, true);
    state.resizeHandler = () => scheduleOfpFit(state);
    if (typeof ResizeObserver === 'function') {
        state.resizeObserver = new ResizeObserver(state.resizeHandler);
        state.resizeObserver.observe(dialog.querySelector('[data-ofp-stage]'));
    } else {
        // Older browsers still need fitting when their viewport changes.
        window.addEventListener('resize', state.resizeHandler);
    }
    state.fullscreenHandler = () => scheduleOfpFit(state);
    document.addEventListener('fullscreenchange', state.fullscreenHandler);
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
    // Leave an eight-pixel clearance on every side, including scrollbar gutters.
    const widthScale = Math.max(1, stage.clientWidth - horizontalPadding - 16) / Math.max(1, unit.width);
    const heightScale = Math.max(1, stage.clientHeight - verticalPadding - 16) / Math.max(1, unit.height);
    return state.fit === 'width' ? widthScale : Math.min(widthScale, heightScale);
}

/**
 * Coalesce layout changes while retaining manual CSS page scale and pan.
 *
 * @param {Record<string, unknown>} state Active document view and zoom mode.
 * @returns {void} Re-render an active automatic fit after layout stabilizes.
 */
function scheduleOfpFit(state) {
    if (state.closed || !state.pdf || state.manualScale !== null || state.fit === 'actual'
        || state.layoutFrame !== null) return;
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
 * Render only the current page to a bounded-resolution canvas.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {boolean} preservePan Keep the centered document point during manual zoom.
 * @returns {Promise<void>} Render safely despite rapid navigation or cancellation.
 */
async function renderOfpPage(state, preservePan = false) {
    if (state.closed || !state.pdf) return;
    const generation = ++state.generation;
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
    state.renderScale = scale;
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
    const pan = preservePan ? {
        x: (stage.scrollLeft + stage.clientWidth / 2) / Math.max(1, stage.scrollWidth),
        y: (stage.scrollTop + stage.clientHeight / 2) / Math.max(1, stage.scrollHeight),
    } : null;
    wrap.replaceChildren(canvas);
    const task = page.render({
        canvasContext: ctx, viewport,
        transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0],
    });
    state.renderTask = task;
    try {
        await task.promise;
        if (!state.closed && generation === state.generation) {
            stage.scrollTo({
                top: pan ? pan.y * stage.scrollHeight - stage.clientHeight / 2 : 0,
                left: pan ? pan.x * stage.scrollWidth - stage.clientWidth / 2 : 0,
            });
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
 * Dispatch an OFP-only action without invoking photo lightbox handlers.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {string} action OFP toolbar operation.
 * @returns {Promise<void>} Apply the operation.
 */
async function runOfpAction(state, action) {
    if (state.closed) return;
    if (action === 'close') return closeOfpViewer(state);
    if (action === 'fullscreen') {
        try {
            if (document.fullscreenElement === state.dialog) await document.exitFullscreen();
            else if (state.dialog.requestFullscreen) await state.dialog.requestFullscreen();
        } catch (_error) {
            // Fullscreen is optional and may be refused by the browser.
        }
        return;
    }
    if (!state.pdf) return;
    let preservePan = false;
    if (action === 'previous') state.page = Math.max(1, state.page - 1);
    else if (action === 'next') state.page = Math.min(state.total, state.page + 1);
    else if (action === 'zoom-in' || action === 'zoom-out') {
        const step = action === 'zoom-in' ? 0.25 : -0.25;
        setOfpManualZoom(state, state.zoom + step);
        preservePan = true;
    } else if (action === 'reset') {
        state.zoom = 1;
        state.manualScale = null;
    } else if (action === 'fit-width' || action === 'fit-page' || action === 'actual-size') {
        state.fit = action === 'fit-page' ? 'page' : action === 'fit-width' ? 'width' : 'actual';
        state.zoom = 1;
        state.manualScale = null;
    } else return;
    await renderOfpPage(state, preservePan);
}

/**
 * Intercept the document keys before the photo lightbox global shortcuts.
 *
 * @param {Record<string, unknown>} state Active viewer state with PDF, page, viewport and modal lifecycle.
 * @param {KeyboardEvent} event Keyboard event in capture phase.
 * @returns {void} Route the key to this document only.
 */
function handleOfpKeyboard(state, event) {
    if (state.closed || !state.dialog.open || event.altKey || event.ctrlKey || event.metaKey) return;
    const actions = {ArrowLeft:'previous',ArrowRight:'next','+':'zoom-in','=':'zoom-in','-':'zoom-out','0':'reset',Escape:'close',f:'fullscreen'};
    const action = actions[event.key];
    if (!action) return;
    event.preventDefault();
    event.stopImmediatePropagation();
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
    stage.addEventListener('touchstart', (event) => {
        if (event.touches.length === 2) state.pinch = {distance: distance(event.touches), zoom: state.zoom};
    }, {passive: true});
    stage.addEventListener('touchmove', (event) => {
        if (!state.pinch || event.touches.length !== 2) return;
        event.preventDefault();
        const canvas = stage.querySelector('canvas');
        const factor = distance(event.touches) / Math.max(1, state.pinch.distance);
        if (canvas) canvas.style.transform = 'scale(' + Math.max(0.5, Math.min(3, factor)) + ')';
    }, {passive: false});
    stage.addEventListener('touchend', (event) => {
        if (!state.pinch || event.touches.length >= 2) return;
        const canvas = stage.querySelector('canvas');
        const factor = Number(canvas?.style.transform.match(/scale\(([^)]+)\)/)?.[1]) || 1;
        if (canvas) canvas.style.transform = '';
        setOfpManualZoom(state, state.pinch.zoom * factor);
        state.pinch = null;
        void renderOfpPage(state, true);
    }, {passive: true});
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
    window.removeEventListener('resize', state.resizeHandler);
    document.removeEventListener('fullscreenchange', state.fullscreenHandler);
    if (state.layoutFrame !== null) cancelAnimationFrame(state.layoutFrame);
    document.removeEventListener('keydown', state.keyHandler, true);
    state.renderTask?.cancel();
    if (document.fullscreenElement === state.dialog) {
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
