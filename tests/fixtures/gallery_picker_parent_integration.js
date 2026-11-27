/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Browser Test Fixture
 * Purpose: Exercise production picker and title modules against PHP-rendered forms.
 * Responsibilities:
 *   - Keep real DOM events and form fields while replacing catalog/title requests with synthetic responses.
 * File: tests/fixtures/gallery_picker_parent_integration.js
 * Author: Rudolf Klusal
 * Current production picker/title modules exercised against PHP-rendered fixtures.
 */
import {setupGallerySearchPickers} from '/picker.js';
import {setupAdminGalleryTitleCompletion} from '/title.js';
import {setupPictureManager} from '/picture-manager.js';
import {completeAdminMutation} from '/admin-mutation-completion.js';

const calls = [];
let moveFields = null;
/** One synthetic destination with the same public row fields as the real endpoint.
 * @typedef {{id:string,title:string,path:string,label:string,depth:number}} IntegrationPickerRow
 */
window.fetch =
/**
 * Isolate catalog/title requests while retaining real DOM, events and form fields.
 * @param {URL|string} resource Requested fixture endpoint.
 * @param {RequestInit} options Method and resolved mutation form for fixture requests.
 * @return {Promise<Response|{ok:boolean,json:()=>Promise<{ok:boolean,candidates:[]}|{ok:boolean,rows:IntegrationPickerRow[],next_after_id:null}>}>} Bounded synthetic wire response.
 */
async (resource, options = {}) => {
    const url = new URL(resource, location.href);
    calls.push(url);
    if (url.pathname === '/manager-move') {
        moveFields = Object.fromEntries(options.body.entries());
        const reply = {
            ok: true, message: 'Gallery moved',
            mutation: {type: 'gallery.move', entity: 'gallery', action: 'move', entity_ids: [2]},
            panel: null,
            contexts: [{type: 'gallery', gallery_id: 1, render_url: location.href,
                postcondition: {type: 'gallery_membership', gallery_id: 2, present: false, count: 0}}],
            fallback: {redirect_url: location.href},
        };
        return new Response(JSON.stringify(reply), {headers: {'Content-Type': 'application/json'}});
    }
    if (url.pathname === '/' && moveFields !== null) {
        return new Response('<!doctype html><div class="hero" data-public-gallery-id="1" data-public-subgallery-count="0"><h1>Gallery manager fixture</h1></div>',
            {headers: {'Content-Type': 'text/html'}});
    }
    if (url.pathname === '/title-search') {
        return {ok: true,
            /** Keep title matching empty so this fixture isolates parent scope. @return {Promise<{ok:boolean,candidates:[]}>} Empty title response. */
            json: async () => ({ok: true, candidates: []})};
    }
    return {ok: true,
        /** Supply the single synthetic destination used for explicit commitment. @return {Promise<{ok:boolean,rows:IntegrationPickerRow[],next_after_id:null}>} Final one-row page. */
        json: async () => ({ok: true, rows: [
        {id: '777', title: 'Repeated title', path: 'different/deep/branch', label: 'Repeated title /different/deep/branch (#777)', depth: 2},
    ], next_after_id: null})};
};

/**
 * Fail the real-browser fixture with an observable contract explanation.
 * @param {boolean} condition Observed behavior.
 * @param {string} message Contract explanation.
 * @return {void}
 */
function check(condition, message) {
    if (!condition) throw new Error(message);
}

/**
 * Exercise the production gallery picker and subtree move action on the same page.
 * @return {Promise<void>} Opens real results for screenshots and verifies in-place completion.
 */
async function checkPictureManagerMove() {
    document.body.classList.add('public-page');
    document.getElementById('panel').hidden = true;
    document.getElementById('bulk-form').hidden = true;
    document.getElementById('manager-fixture').hidden = false;
    const toolbar = document.querySelector('[data-picture-manager]');
    const createDisclosure = toolbar.querySelector('.picture-manager-create-disclosure > summary');
    check(createDisclosure.title === 'Create physical gallery from selection',
        'The compact create disclosure explains that it creates a physical gallery');
    const move = toolbar.querySelector('[data-picture-manager-move]');
    const copy = toolbar.querySelector('[data-picture-manager-copy]');
    const picker = toolbar.querySelector('[data-gallery-search-picker]');
    const destination = picker.querySelector('[data-gallery-search-picker-input]');
    const panel = toolbar.querySelector('[data-picture-manager-panel]');
    const originalUrl = location.href;
    setupPictureManager(completeAdminMutation);
    document.querySelector('[data-picture-manager-select]').click();
    check(move.disabled && copy.hidden, 'A selected gallery needs a committed destination and offers Move');
    destination.focus();
    destination.value = 'Repeated title';
    destination.dispatchEvent(new Event('input', {bubbles: true}));
    await waitFor(
        /** Observe the real picker rendering its synthetic remote result. @return {Element|null} Target option. */
        () => picker.querySelector('[data-gallery-id="777"]'));
    check(move.disabled, 'Typed text does not authorize a destination');
    const target = picker.querySelector('[data-gallery-id="777"]');
    const results = picker.querySelector('[data-gallery-search-picker-menu]');
    const panelBounds = panel.getBoundingClientRect();
    check(target.getBoundingClientRect().bottom <= panelBounds.bottom
        && getComputedStyle(results).position === 'static', 'Results are in flow inside the expanded panel');
    check(getComputedStyle(picker.querySelector('[data-gallery-search-picker-help]')).display === 'none',
        'Help copy stays out of the action panel');
    toolbar.dataset.captureReady = '1';
    await waitFor(
        /** Let the owned DevTools session verify and capture both layouts. @return {boolean} Release signal. */
        () => toolbar.dataset.captureReady === 'done');
    target.click();
    check(!move.disabled && copy.hidden, 'Committed destination enables the gallery move');
    move.click();
    await waitFor(
        /** Await canonical refresh and its durable success caption. @return {boolean} Action completion. */
        () => toolbar.querySelector('[data-picture-manager-status]').dataset.state === 'ok');
    check(moveFields?.['gallery_ids[]'] === '2' && moveFields?.['destination_gallery_id'] === '777'
        && !Object.hasOwn(moveFields, 'image_ids[]'), 'Move submits the selected subtree and exact committed destination');
    check(location.href === originalUrl && toolbar.isConnected && !panel.hidden
        && !document.querySelector('[data-picture-manager-gallery]'), 'Canonical refresh removes the moved card in place');
}

/**
 * Wait for a specific async module effect, never for the complete application.
 * @param {()=>unknown} predicate Observable fixture condition, interpreted by truthiness.
 * @return {Promise<void>} Resolves on that condition or fails after a bounded wait.
 */
async function waitFor(predicate) {
    const deadline = performance.now() + 5000;
    while (!predicate()) {
        if (performance.now() > deadline) throw new Error('Timed out awaiting parent integration');
        await new Promise(
            /** Yield briefly while the real browser module completes its bounded work. @param {()=>void} resolve Resume this wait iteration. @return {number} Browser timer handle. */
            (resolve) => setTimeout(resolve, 20));
    }
}

/**
 * Focus the title input and ask its real module to resolve the current parent.
 * @param {HTMLInputElement} title Current form title input.
 * @param {string} expected Exact parent scope expected in the outgoing URL.
 * @return {Promise<void>} Waits for the actual title request.
 */
async function checkTitleParent(title, expected) {
    const before = calls.length;
    title.focus();
    title.setSelectionRange(title.value.length, title.value.length);
    title.dispatchEvent(new Event('input', {bubbles: true}));
    await waitFor(
        /** Observe only title requests made after this input intent. @return {boolean} Whether a title request arrived. */
        () => calls.slice(before).some(
            /** Match the isolated title endpoint. @param {URL} url Captured request. @return {boolean} Whether title lookup. */
            (url) => url.pathname === '/title-search'));
    const url = calls.slice(before).filter(
        /** Retain title lookups while excluding picker requests. @param {URL} entry Captured request. @return {boolean} Whether title lookup. */
        (entry) => entry.pathname === '/title-search').at(-1);
    check(url.searchParams.get('parent_id') === expected, 'Title request uses enabled committed parent ' + expected);
}

/**
 * Exercise PHP-rendered controls and replaced form fragments with current modules.
 * @return {Promise<void>} Completes only after every DOM/form contract passes.
 */
async function run() {
    const panel = document.getElementById('panel');
    const originalMarkup = panel.innerHTML;
    const originalUrl = location.href;
    setupGallerySearchPickers();
    setupAdminGalleryTitleCompletion();
    let form = document.getElementById('parent-form');
    let title = document.getElementById('title');
    let hidden = form.querySelector('input[type="hidden"][name="parent_id"]:enabled');
    check(hidden?.value === '9000', 'PHP-rendered parent is enabled without selecting the disabled distractor');
    check(new FormData(form).get('parent_id') === '9000', 'Only the enabled committed parent submits');
    check(form.querySelectorAll('[data-gallery-search-picker-option]').length === 31, 'Initial DOM is bounded plus root');
    await checkTitleParent(title, '9000');

    const input = form.querySelector('[data-gallery-search-picker-input]');
    input.value = 'A parent not yet selected';
    input.dispatchEvent(new Event('input', {bubbles: true}));
    check(!form.checkValidity(), 'Uncommitted parent text cannot become an implicit root on submission');
    input.focus();
    await waitFor(
        /** Wait for the current form's asynchronously rendered destination. @return {Element|null} Matching option when available. */
        () => form.querySelector('[data-gallery-id="777"]'));
    const beforeChange = calls.length;
    form.querySelector('[data-gallery-id="777"]').click();
    check(hidden.value === '777', 'Dynamic result commits exact parent');
    check(form.checkValidity(), 'Explicit parent selection restores native form validity');
    await waitFor(
        /** Observe the delegated title refresh caused by explicit parent commitment. @return {boolean} Whether the selected scope was requested. */
        () => calls.slice(beforeChange).some(
            /** Match the new committed parent in a title request. @param {URL} url Captured request. @return {boolean} Whether it carries parent 777. */
            (url) => url.pathname === '/title-search'
        && url.searchParams.get('parent_id') === '777'));
    check(new FormData(form).get('parent_id') === '777', 'FormData carries the new committed parent');
    check(location.href === originalUrl && panel.isConnected, 'Picker keeps the panel and URL');

    // A disabled hidden parent must not shadow an enabled legacy select.
    hidden.disabled = true;
    const legacy = document.createElement('select');
    legacy.name = 'parent_id';
    legacy.innerHTML = '<option value="42">Legacy parent</option>';
    form.appendChild(legacy);
    await checkTitleParent(title, '42');
    hidden.disabled = false;
    legacy.disabled = true;
    await checkTitleParent(title, '777');

    const ignoredStart = calls.length;
    form.querySelector('input[name="parent_id"]:disabled').dispatchEvent(new Event('change', {bubbles: true}));
    await new Promise(
        /** Allow the normal debounce window to expose any incorrectly triggered request. @param {()=>void} resolve End the negative-observation interval. @return {number} Timer handle. */
        (resolve) => setTimeout(resolve, 250));
    check(calls.length === ignoredStart, 'Disabled hidden changes do not request title suggestions');
    const bulk = document.getElementById('bulk-form');
    check(!bulk.querySelector('select[name="new_gallery_parent_id"]'), 'Bulk new-parent select is replaced');
    check(new FormData(bulk).get('new_gallery_parent_id') === '10000', 'Actual bulk renderer retains the current gallery parent');
    check(bulk.querySelectorAll('[data-gallery-search-picker-option]').length <= 61, 'Both bulk destination controls stay bounded');

    panel.innerHTML = originalMarkup;
    await waitFor(
        /** Wait for observer binding of the replacement panel fragment. @return {Element|null} Newly bound picker. */
        () => panel.querySelector('[data-gallery-search-picker-bound="1"]'));
    form = document.getElementById('parent-form');
    title = document.getElementById('title');
    hidden = form.querySelector('input[type="hidden"][name="parent_id"]:enabled');
    await checkTitleParent(title, '9000');
    form.querySelector('[data-gallery-search-picker-input]').focus();
    await waitFor(
        /** Wait for a fresh result inside the replacement form. @return {Element|null} Current destination option. */
        () => form.querySelector('[data-gallery-id="777"]'));
    form.querySelector('[data-gallery-search-picker-root]').click();
    check(hidden.value === '0' && new FormData(form).get('parent_id') === '0', 'Replacement supports explicit root commitment');
    check(location.href === originalUrl && panel.isConnected, 'Replaced fragment remains in place');
    await checkPictureManagerMove();
    document.getElementById('results').textContent = 'BROWSER PASS: current PHP parent/bulk renderers, exact FormData, in-place gallery move and responsive visible destination results.';
}
run().catch(
    /** Expose browser assertion failure through the runner's result marker. @param {Error} error Fixture or module failure. @return {void} Marks this isolated document as failed. */
    (error) => { document.getElementById('results').textContent = 'BROWSER FAIL: ' + error.message; });
