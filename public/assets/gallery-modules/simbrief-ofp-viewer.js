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
    en: {title:'Operational flight plan',close:'Close',previous:'Previous page',next:'Next page',zoomIn:'Zoom in',zoomOut:'Zoom out',reset:'Reset zoom',fitWidth:'Fit width',fitPage:'Fit page',fullscreen:'Fullscreen',download:'Download PDF',loading:'Loading flight plan…',rendering:'Rendering page…',error:'The PDF could not be displayed. Open the original PDF instead.',fallback:'Open original PDF',page:'Page {page} of {total}',zoom:'Zoom {percent}%'},
    cs: {title:'Operační letový plán',close:'Zavřít',previous:'Předchozí stránka',next:'Další stránka',zoomIn:'Přiblížit',zoomOut:'Oddálit',reset:'Obnovit přiblížení',fitWidth:'Přizpůsobit šířce',fitPage:'Celá stránka',fullscreen:'Celá obrazovka',download:'Stáhnout PDF',loading:'Načítání letového plánu…',rendering:'Vykreslování stránky…',error:'PDF nelze zobrazit. Otevřete původní soubor PDF.',fallback:'Otevřít původní PDF',page:'Stránka {page} z {total}',zoom:'Přiblížení {percent} %'},
    de: {title:'Flugdurchführungsplan',close:'Schließen',previous:'Vorherige Seite',next:'Nächste Seite',zoomIn:'Vergrößern',zoomOut:'Verkleinern',reset:'Zoom zurücksetzen',fitWidth:'An Breite anpassen',fitPage:'Ganze Seite',fullscreen:'Vollbild',download:'PDF herunterladen',loading:'Flugplan wird geladen…',rendering:'Seite wird gerendert…',error:'Das PDF konnte nicht angezeigt werden. Öffnen Sie das Original-PDF.',fallback:'Original-PDF öffnen',page:'Seite {page} von {total}',zoom:'Zoom {percent} %'},
    sv: {title:'Operativ färdplan',close:'Stäng',previous:'Föregående sida',next:'Nästa sida',zoomIn:'Zooma in',zoomOut:'Zooma ut',reset:'Återställ zoom',fitWidth:'Anpassa till bredd',fitPage:'Hela sidan',fullscreen:'Helskärm',download:'Ladda ned PDF',loading:'Läser in färdplan…',rendering:'Renderar sida…',error:'PDF-filen kunde inte visas. Öppna originalfilen i stället.',fallback:'Öppna original-PDF',page:'Sida {page} av {total}',zoom:'Zoom {percent} %'},
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
        if (!form || form.dataset.ofpConverting === '1') return;
        event.preventDefault();
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
        '<span class="simbrief-ofp-zoom-level" data-ofp-zoom></span>',
        '<button type="button" class="button secondary" data-ofp-action="zoom-in" data-ofp-label="zoomIn">+</button>',
        '<button type="button" class="button secondary" data-ofp-action="reset" data-ofp-label="reset">100%</button>',
        '<button type="button" class="button secondary" data-ofp-action="fit-width" data-ofp-text="fitWidth"></button>',
        '<button type="button" class="button secondary" data-ofp-action="fit-page" data-ofp-text="fitPage"></button>',
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
        page: 1, total: 0, zoom: 1, fit: 'width', generation: 0, closed: false,
        keyHandler: null, resizeObserver: null, pinch: null,
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
    if (typeof ResizeObserver === 'function') {
        state.resizeObserver = new ResizeObserver(() => {
            if (!state.closed && state.pdf && state.zoom === 1) void renderOfpPage(state);
        });
        state.resizeObserver.observe(dialog.querySelector('[data-ofp-stage]'));
    }
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
 * @param {object} state Document viewer state.
 * @param {string} message Status text.
 * @param {boolean} error Whether to show the fallback link.
 * @returns {void} Update visible status.
 */
function setOfpStatus(state, message, error = false) {
    state.dialog.querySelector('[data-ofp-status]').textContent = message;
    state.dialog.querySelector('[data-ofp-fallback]').hidden = !error;
}

/**
 * Render only the current page to a bounded-resolution canvas.
 *
 * @param {object} state Document viewer state.
 * @returns {Promise<void>} Render safely despite rapid navigation or cancellation.
 */
async function renderOfpPage(state) {
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
    const unit = page.getViewport({scale: 1});
    const widthScale = Math.max(0.1, (stage.clientWidth - 48) / unit.width);
    const heightScale = Math.max(0.1, (stage.clientHeight - 48) / unit.height);
    const viewport = page.getViewport({scale: (state.fit === 'page' ? Math.min(widthScale, heightScale) : widthScale) * state.zoom});
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
    state.dialog.querySelector('[data-ofp-canvas-wrap]').replaceChildren(canvas);
    const task = page.render({
        canvasContext: ctx, viewport,
        transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0],
    });
    state.renderTask = task;
    try {
        await task.promise;
        if (!state.closed && generation === state.generation) {
            stage.scrollTo({top: 0, left: 0});
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
 * @param {object} state Document viewer state.
 * @returns {void} Refresh controls.
 */
function updateOfpControls(state) {
    state.dialog.querySelector('[data-ofp-counter]').textContent =
        state.copy.page.replace('{page}', state.page).replace('{total}', state.total);
    state.dialog.querySelector('[data-ofp-zoom]').textContent =
        state.copy.zoom.replace('{percent}', Math.round(state.zoom * 100));
    state.dialog.querySelector('[data-ofp-action="previous"]').disabled = state.page <= 1;
    state.dialog.querySelector('[data-ofp-action="next"]').disabled = state.page >= state.total;
}

/**
 * Dispatch an OFP-only action without invoking photo lightbox handlers.
 *
 * @param {object} state Document viewer state.
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
    if (action === 'previous') state.page = Math.max(1, state.page - 1);
    else if (action === 'next') state.page = Math.min(state.total, state.page + 1);
    else if (action === 'zoom-in') state.zoom = Math.min(5, Math.round((state.zoom + 0.25) * 100) / 100);
    else if (action === 'zoom-out') state.zoom = Math.max(0.5, Math.round((state.zoom - 0.25) * 100) / 100);
    else if (action === 'reset') state.zoom = 1;
    else if (action === 'fit-width' || action === 'fit-page') {
        state.fit = action === 'fit-page' ? 'page' : 'width';
        state.zoom = 1;
    } else return;
    await renderOfpPage(state);
}

/**
 * Intercept the document keys before the photo lightbox global shortcuts.
 *
 * @param {object} state Document viewer state.
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
 * @param {object} state Document viewer state.
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
        state.zoom = Math.max(0.5, Math.min(5, Math.round(state.pinch.zoom * factor * 100) / 100));
        state.pinch = null;
        void renderOfpPage(state);
    }, {passive: true});
}

/**
 * Release the PDF worker, old renders and event listeners on close.
 *
 * @param {object} state Document viewer state.
 * @returns {Promise<void>} Restore focus to the original gallery link.
 */
async function closeOfpViewer(state) {
    if (state.closed) return;
    state.closed = true;
    ++state.generation;
    state.resizeObserver?.disconnect();
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
