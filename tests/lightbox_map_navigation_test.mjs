/**
 * Execute production closure functions with controlled DOM, fetch, and timer boundaries.
 * No navigation algorithm is duplicated here. The small DOM adapter cannot verify
 * Leaflet hit testing or visual fullscreen layout; those remain browser checks.
 */
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import vm from 'node:vm';

const source = process.argv.includes('--baseline')
    ? execFileSync('git', ['show', 'HEAD:public/assets/gallery-modules/lightbox.js'], {encoding: 'utf8'})
    : readFileSync(new URL('../public/assets/gallery-modules/lightbox.js', import.meta.url), 'utf8');

/** Extract an unchanged production declaration using its closure-level indentation. */
function productionFunction(name, optional = false) {
    const match = source.match(new RegExp(`^    (?:async )?function ${name}\\([^]*?^    }`, 'm'));
    if (!match && !optional) throw new Error(`Missing production function: ${name}`);
    return match?.[0] || '';
}

/** Minimal DOM card supporting the metadata attributes touched by production code. */
class Element {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.hidden = false;
        this.classList = {remove() {}, contains() { return false; }};
    }
    setAttribute() {}
    removeAttribute() {}
    getAttribute(name) { return name === 'href' ? this.dataset.mapPhotoPageUrl : null; }
}

/** Let fetch/JSON continuations settle without wall-clock sleeps. */
async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve();
}

/** Build one isolated live-viewer fixture and compile the actual production functions into it. */
function fixture() {
    const visible = new Element({imageId: '1', galleryId: '7', lightboxIndex: '0'});
    const pending = [];
    const opened = [];
    const navigated = [];
    const timers = [];
    const overlay = new Element();
    const split = new Element();
    split.hidden = true;
    const mapOverlay = new Element();
    const context = vm.createContext({
        AbortController, DOMException, URL, HTMLElement: Element, Element,
        controller: new AbortController(), mapPhotoNavigationController: null,
        lightboxMetadataAbortController: new AbortController(), lightboxMetadataGeneration: 0,
        lightboxPendingWindows: new Map(), lightboxBenchmarkDiagnosticsEnabled: false,
        cards: Array.from({length: 200}, (_, i) => i === 0 ? visible : null), currentIndex: 0,
        lightboxEndpoint: '/index.php?page=gallery_lightbox_data&id=7', lightboxTotal: 200, lightboxWindowSize: 60,
        overlay, lightboxMapSplit: split, lightboxMapSplitCanvas: null, fullscreen: false,
        clearLightboxSplitMapRuntime() {}, clearFullscreenMapImageFit() {}, scheduleLightboxZoomReclamp() {},
        document: {
            querySelectorAll: selector => selector === '[data-lightbox-image]' ? [visible] : [],
            querySelector: () => mapOverlay,
            createElement: () => new Element(),
            body: {classList: {remove() {}}},
        },
        window: {
            location: {href: 'http://localhost/gallery', assign: url => navigated.push(url)},
            setTimeout: callback => timers.push(callback),
        },
        fetch: (url, options) => new Promise((resolve, reject) => pending.push({url, options, resolve, reject})),
        // Rendering is a boundary: record the committed photo while exercising all real resolution and lifecycle code.
        openAt(index, options) { opened.push({index, options}); context.currentIndex = index; },
        isLightboxFullscreen: () => context.fullscreen,
    });
    const names = ['refreshLightboxOrderFromDom', 'lightboxIndexForCard', 'createLightboxCardFromItem',
        'mergeLightboxItems', 'currentLightboxMetadataSignal', 'cancelLightboxMetadataRequests',
        'fetchLightboxTargetIndex', 'closeMapOverlay', 'closeLightboxMapSplit',
        'closeMapSurfaceForPhotoNavigation', 'mapPhotoNavigationTarget', 'openMapPhotoTarget',
        'scheduleMapPhotoLinkNavigation'];
    vm.runInContext(names.map(name => productionFunction(name)).join('\n') + '\n'
        + productionFunction('cancelMapPhotoNavigation', true) + '\n'
        + productionFunction('mapPhotoNavigationIsCurrent', true), context);
    return {
        context, pending, opened, navigated, overlay, split, mapOverlay,
        select(id, galleryId = 7) {
            context.scheduleMapPhotoLinkNavigation(new Element({mapOpenPhoto: String(id), mapPhotoGalleryId: String(galleryId), mapPhotoPageUrl: `/photo/${id}`}));
        },
        async flush() { while (timers.length) timers.shift()(); await settle(); },
        load(id, index) { context.mergeLightboxItems([{id, index, gallery_id: 7}]); },
        async respond(request, id, index) {
            request.resolve({ok: true, json: async () => ({target_index: index, items: [{id, index, gallery_id: 7}]})});
            await settle();
        },
        close() { overlay.hidden = true; context.cancelLightboxMetadataRequests(); context.refreshLightboxOrderFromDom(); },
    };
}

const cases = [
    ['cached detached target survives marker selection', async () => {
        const f = fixture(); f.load(101, 100); f.select(101); await f.flush();
        assert.equal(f.opened[0]?.index, 100); assert.equal(f.pending.length, 0); assert.deepEqual(f.navigated, []);
    }],
    ['detached current photo retains gallery identity for cross-page lookup', async () => {
        const f = fixture(); f.load(101, 100); f.context.currentIndex = 100;
        f.select(151); await f.flush(); assert.equal(f.pending.length, 1);
        const request = f.pending[0]; const url = new URL(request.url);
        assert.equal(url.searchParams.get('target_image_id'), '151'); assert.equal(url.searchParams.get('limit'), '60');
        assert.equal(request.options.credentials, 'same-origin');
        await f.respond(request, 151, 150); assert.equal(f.opened[0]?.index, 150);
        assert.equal(f.context.cards[100].dataset.imageId, '101');
    }],
    ['fullscreen split remains mounted on success', async () => {
        const f = fixture(); f.context.fullscreen = true; f.split.hidden = false;
        f.select(101); await f.flush(); await f.respond(f.pending[0], 101, 100);
        assert.equal(f.split.hidden, false); assert.equal(f.opened[0].options.preserveMapSplit, true);
        assert.equal(f.opened[0].options.forceImmediateSwap, true);
    }],
    ['ordinary overlay dismissal does not cancel its own selection', async () => {
        const f = fixture(); f.select(101); await f.flush();
        assert.equal(f.mapOverlay.hidden, true); assert.equal(f.pending[0].options.signal.aborted, false);
        await f.respond(f.pending[0], 101, 100); assert.equal(f.opened.length, 1);
    }],
    ['latest selection owns out-of-order responses and leaves range work running', async () => {
        const f = fixture(); f.select(101); await f.flush(); const first = f.pending[0];
        f.select(151); await f.flush(); const second = f.pending[1];
        assert.equal(first.options.signal.aborted, true);
        assert.equal(f.context.lightboxMetadataAbortController.signal.aborted, false);
        await f.respond(second, 151, 150); await f.respond(first, 101, 100);
        assert.deepEqual(f.opened.map(x => x.index), [150]); assert.equal(f.context.cards[100], null);
        assert.deepEqual(f.navigated, []);
    }],
    ['same-target reselection never reuses a cancelled request', async () => {
        const f = fixture(); f.select(101); await f.flush(); const first = f.pending[0];
        f.select(101); await f.flush(); assert.equal(f.pending.length, 2);
        first.reject(new DOMException('cancelled', 'AbortError')); await settle();
        await f.respond(f.pending[1], 101, 100); assert.equal(f.opened.length, 1); assert.deepEqual(f.navigated, []);
    }],
    ['new cached selection supersedes an outstanding network selection', async () => {
        const f = fixture(); f.load(151, 150); f.select(101); await f.flush();
        f.select(151); await f.flush(); await f.respond(f.pending[0], 101, 100);
        assert.deepEqual(f.opened.map(x => x.index), [150]); assert.deepEqual(f.navigated, []);
    }],
    ['viewer close with AbortError does not navigate', async () => {
        const f = fixture(); f.select(101); await f.flush(); const request = f.pending[0];
        f.close(); request.reject(new DOMException('cancelled', 'AbortError')); await settle();
        assert.deepEqual(f.navigated, []); assert.deepEqual(f.opened, []);
    }],
    ['close/reopen rejects a successful late response even when transport ignores abort', async () => {
        const f = fixture(); f.select(101); await f.flush(); const request = f.pending[0];
        f.close(); f.overlay.hidden = false; await f.respond(request, 101, 100);
        assert.deepEqual(f.navigated, []); assert.deepEqual(f.opened, []); assert.equal(f.context.cards[100], null);
    }],
    ['closing split map aborts its pending selection', async () => {
        const f = fixture(); f.context.fullscreen = true; f.split.hidden = false;
        f.select(101); await f.flush(); f.context.closeLightboxMapSplit();
        assert.equal(f.pending[0].options.signal.aborted, true);
        await f.respond(f.pending[0], 101, 100); assert.deepEqual(f.navigated, []); assert.deepEqual(f.opened, []);
    }],
    ['fullscreen exit blocks pending fallback', async () => {
        const f = fixture(); f.context.fullscreen = true; f.split.hidden = false;
        f.select(101); await f.flush(); f.context.fullscreen = false;
        f.pending[0].reject(new Error('network failed')); await settle(); assert.deepEqual(f.navigated, []);
    }],
    ['component destruction prevents late fallback', async () => {
        const f = fixture(); f.select(101); await f.flush(); f.context.cancelLightboxMetadataRequests(); f.context.controller.abort();
        f.pending[0].reject(new Error('network failed')); await settle(); assert.deepEqual(f.navigated, []);
    }],
    ['closure before deferred click dispatch prevents request and fallback', async () => {
        const f = fixture(); f.select(101); f.close(); await f.flush();
        assert.equal(f.pending.length, 0); assert.deepEqual(f.navigated, []);
    }],
    ['new selection before deferred dispatch wins', async () => {
        const f = fixture(); f.select(101); f.select(151); await f.flush();
        assert.equal(f.pending.length, 1); await f.respond(f.pending[0], 151, 150);
        assert.deepEqual(f.opened.map(x => x.index), [150]);
    }],
    ['HTTP failure retains canonical page fallback', async () => {
        const f = fixture(); f.select(101); await f.flush(); f.pending[0].resolve({ok: false, status: 503});
        await settle(); assert.deepEqual(f.navigated, ['/photo/101']);
    }],
    ['invalid JSON retains canonical page fallback', async () => {
        const f = fixture(); f.select(101); await f.flush();
        f.pending[0].resolve({ok: true, json: async () => { throw new SyntaxError('bad JSON'); }});
        await settle(); assert.deepEqual(f.navigated, ['/photo/101']);
    }],
    ['missing or mismatched target cannot open the wrong photo', async () => {
        const f = fixture(); f.select(101); await f.flush(); await f.respond(f.pending[0], 151, 150);
        assert.deepEqual(f.navigated, ['/photo/101']); assert.deepEqual(f.opened, []);
    }],
    ['cross-gallery marker keeps canonical fallback', async () => {
        const f = fixture(); f.select(101, 99); await f.flush();
        assert.equal(f.pending.length, 0); assert.deepEqual(f.navigated, ['/photo/101']);
    }],
    ['map without active viewer keeps page navigation', async () => {
        const f = fixture(); f.overlay.hidden = true; f.select(101); await f.flush();
        assert.equal(f.pending.length, 0); assert.deepEqual(f.navigated, ['/photo/101']);
    }],
    ['current-photo selection is a no-op', async () => {
        const f = fixture(); f.select(1); await f.flush();
        assert.equal(f.pending.length, 0); assert.deepEqual(f.opened, []); assert.deepEqual(f.navigated, []);
    }],
    ['cancellation while decoding JSON prevents merge and fallback', async () => {
        const f = fixture(); f.select(101); await f.flush(); let finishJson;
        f.pending[0].resolve({ok: true, json: () => new Promise(resolve => { finishJson = resolve; })});
        await settle(); f.close(); finishJson({target_index: 100, items: [{id: 101, index: 100, gallery_id: 7}]});
        await settle(); assert.equal(f.context.cards[100], null); assert.deepEqual(f.navigated, []); assert.deepEqual(f.opened, []);
    }],
    ['late failure from superseded selection cannot steal fallback ownership', async () => {
        const f = fixture(); f.select(101); await f.flush(); const first = f.pending[0];
        f.select(151); await f.flush(); first.reject(new Error('old failure')); await settle();
        f.pending[1].reject(new Error('current failure')); await settle(); assert.deepEqual(f.navigated, ['/photo/151']);
    }],
    ['genuine viewer commit failure retains page fallback', async () => {
        const f = fixture(); f.load(101, 100); f.context.openAt = () => { throw new Error('render failed'); };
        f.select(101); await f.flush(); assert.deepEqual(f.navigated, ['/photo/101']);
    }],
    ['authoritative order reset invalidates pending target work', async () => {
        const f = fixture(); f.select(101); await f.flush(); f.context.refreshLightboxOrderFromDom();
        assert.equal(f.pending[0].options.signal.aborted, true); await f.respond(f.pending[0], 101, 100);
        assert.deepEqual(f.opened, []); assert.deepEqual(f.navigated, []); assert.equal(f.context.cards[100], null);
    }],
    ['dismissal before timer cancels a map-only selection', async () => {
        const f = fixture(); f.overlay.hidden = true; f.select(101); f.context.closeMapOverlay(); await f.flush();
        assert.deepEqual(f.navigated, []); assert.equal(f.pending.length, 0);
    }],
];
let failures = 0;
for (const [name, run] of cases) {
    try { await run(); console.log(`PASS ${name}`); }
    catch (error) { failures++; console.error(`FAIL ${name}: ${error.message}`); }
}
console.log(`Map navigation: ${cases.length - failures}/${cases.length} passed.`);
process.exitCode = failures ? 1 : 0;
