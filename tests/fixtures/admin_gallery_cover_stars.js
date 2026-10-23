/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_gallery_cover_stars.js
 * Module Type: Browser Fixture
 * Purpose: Drive real image-star submissions and assert acknowledged in-place updates.
 * Responsibilities:
 *   - Supply deterministic canonical mutation responses without replacing the editor.
 *   - Verify the complete cover sequence and preserved editor state in Chromium.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {setupAdminGalleryImages} from '/assets/gallery-modules/admin-gallery-images.js?v=20261020-shift-range-v1';
import {setupAdminBulkSelection} from '/assets/gallery-modules/admin-bulk-actions.js?v=20261020-shift-range-v1';
import {setupAdminGallerySidePanel} from '/assets/gallery-modules/admin-side-panel.js?v=20261023-publication-local-time-v2';
import {beginAdminPanelOpen} from '/assets/gallery-modules/admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';

// Purpose: Pace fixture-side state polling without delaying successful coordinator updates.
// Type: integer.
// Units: milliseconds.
// Scope: browser assertions waiting for submitted cover state.
// Consumers: clickAndVerify() and ambiguous-write reconciliation polling.
// Rationale: use a short interval while the shared coordinator owns public refresh timing.
const fixtureWaitMs = 10;
// Purpose: Make the first stale public render finish after the mutation acknowledgement.
// Type: integer.
// Units: milliseconds.
// Scope: first public HTML read per simulated cover write.
// Consumers: delayed stale-response assertion in the coordinator browser fixture.
// Rationale: exercise stale response rejection before the subsequent fresh retry.
const stalePublicReadDelayMs = 50;
let revisionNumber = 1;
let selectedIds = [2];
let mode = 'single';
let failAfterPersistence = false;
let failReconciliationRead = false;
let refuseWithoutPersistence = false;
let heldPostRelease = null;
let postRequestCount = 0;
let lastPostAction = '';
let submitEventCount = 0;
let lastBrowserError = '';
let publicReadAttempt = 0;
document.addEventListener('submit', event => {
    if (event.target instanceof HTMLFormElement && event.target.matches('[data-admin-image-bulk-form]')) submitEventCount += 1;
}, true);
window.addEventListener('error', event => { lastBrowserError = String(event.message || event.error || 'window error'); });
window.addEventListener('unhandledrejection', event => { lastBrowserError = String(event.reason?.stack || event.reason || 'unhandled rejection'); });
const originalFetch = window.fetch.bind(window);
window.fetch = async (input, options = {}) => {
    const url = new URL(String(input), window.location.href);
    if (url.pathname === '/public-gallery') {
        publicReadAttempt += 1;
        const returnedRevision = publicReadAttempt === 1 ? 'cover-' + (revisionNumber - 1) : 'cover-' + revisionNumber;
        const html = '<!doctype html><html><body><section class="hero" data-public-gallery-id="42" data-public-cover-selection-revision="' + returnedRevision + '"></section></body></html>';
        if (publicReadAttempt === 1) await new Promise(resolve => setTimeout(resolve, stalePublicReadDelayMs));
        return new Response(html, {status: 200, headers: {'Content-Type': 'text/html; charset=utf-8'}});
    }
    if (!url.pathname.endsWith('/admin_bulk_images')) return originalFetch(input, options);
    if (options.method === 'GET' && url.searchParams.get('cover_state') === '1') {
        if (failReconciliationRead) return new Response(JSON.stringify({ok: false, error: 'State read unavailable'}), {status: 503, headers: {'Content-Type': 'application/json'}});
        return new Response(JSON.stringify({ok: true, reconciled: true, message: 'Reconciled', cover_selection_ids: selectedIds,
            cover_selection_mode: mode, cover_selection_revision: 'cover-' + revisionNumber,
            edit_revision: 'rev-' + revisionNumber, mutation: {type: 'gallery.cover', entity: 'gallery', action: 'cover', entity_ids: [42]},
            panel: null, contexts: [{type: 'gallery', gallery_id: 42, render_url: '/public-gallery',
                postcondition: {type: 'cover_selection', gallery_id: 42, revision: 'cover-' + revisionNumber}}]}), {status: 200, headers: {'Content-Type': 'application/json'}});
    }
    if (options.method !== 'POST') return originalFetch(input, options);
    postRequestCount += 1;
    if (heldPostRelease) await new Promise(resolve => { heldPostRelease = resolve; });
    const body = options.body;
    const action = String(body.get('action') || '');
    lastPostAction = action;
    const [, verb, idText] = /^(cover|cover_arm|cover_add|cover_reset):(\d+)$/.exec(action) || [];
    const id = Number(idText || 0);
    const previousEditRevision = 'rev-' + revisionNumber;
    if (refuseWithoutPersistence) {
        refuseWithoutPersistence = false;
        return new Response(JSON.stringify({ok: false, error: 'Cover selection refused'}), {status: 422, headers: {'Content-Type': 'application/json'}});
    }
    if (verb === 'cover') { mode = 'single'; selectedIds = [id]; }
    if (verb === 'cover_arm') { mode = 'multi'; selectedIds = [id]; }
    if (verb === 'cover_add') { mode = 'multi'; selectedIds = [...selectedIds, id]; }
    if (verb === 'cover_reset') { mode = 'single'; selectedIds = [id]; }
    revisionNumber += 1;
    publicReadAttempt = 0;
    if (failAfterPersistence) {
        failAfterPersistence = false;
        return new Response(JSON.stringify({ok: false, error: 'HTTP 500 after persistence'}), {status: 500, headers: {'Content-Type': 'application/json'}});
    }
    const result = {
        ok: true,
        message: 'Saved',
        bulk_action: verb,
        cover_selection_ids: selectedIds,
        cover_selection_mode: mode,
        cover_selection_revision: 'cover-' + revisionNumber,
        edit_revision: 'rev-' + revisionNumber,
        previous_edit_revision: previousEditRevision,
        mutation: {type: 'gallery.cover', entity: 'gallery', action: 'cover', entity_ids: [42]},
        panel: null,
        contexts: [{type: 'gallery', gallery_id: 42, render_url: '/public-gallery',
            postcondition: {type: 'cover_selection', gallery_id: 42, revision: 'cover-' + revisionNumber}}],
    };
    return new Response(JSON.stringify(result), {status: 200, headers: {'Content-Type': 'application/json'}});
};

setupAdminBulkSelection();
setupAdminGalleryImages(document);
setupAdminGallerySidePanel();
const panel = document.querySelector('[data-admin-side-panel]');
const scrollBox = document.querySelector('[data-panel-scroll]');
const bulkForm = document.querySelector('[data-admin-image-bulk-form]');
const editForm = document.querySelector('.admin-edit-gallery-form');
const title = editForm.querySelector('[name="title"]');
const description = editForm.querySelector('[name="description"]');
const mediaCover = document.querySelector('select[name="cover_image_id"]');
const tab = document.querySelector('[data-admin-tab="images"]');
beginAdminPanelOpen(panel, tab);
scrollBox.scrollTop = 280;
// Purpose: Record the browser-clamped starting scroll position for exact preservation checks.
// Type: number.
// Units: pixels.
// Scope: mounted panel scroll container in this browser fixture.
// Consumers: scenario precondition requiring a nonzero scroll offset.
// Rationale: verify the requested offset produced scrolling even when the browser clamps it.
const initialScrollTop = scrollBox.scrollTop;
const originalUrl = location.href;

/** Capture the visual state owned by the canonical cover-star button.
 * @param {HTMLButtonElement|null} star Current mounted cover-star control.
 * @returns {{state:string,background:string,color:string,border:string}|null} Computed star appearance or null when the selector is absent.
 */
function coverStarVisual(star) {
    if (!(star instanceof HTMLButtonElement)) return null;
    const style = getComputedStyle(star);
    return {
        state: star.dataset.coverState || '',
        background: style.backgroundColor,
        color: style.color,
        border: style.borderTopColor,
    };
}


/**
 * Wait for an acknowledged star action and preserve its exact pre-click editor position.
 * @param {number} id Image identifier whose star is clicked.
 * @param {string} expectedAction Expected semantic row action submitted by the button.
 * @param {string} expectedMode Expected server-confirmed selection mode.
 * @param {number[]} expectedIds Expected selected image identifiers after acknowledgement.
 * @return {Promise<void>} Resolves after the state and editor-preservation assertions pass.
 */
async function clickAndVerify(id, expectedAction, expectedMode, expectedIds) {
    const star = bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="' + id + '"]');
    const previousRevision = bulkForm.dataset.coverSelectionRevision;
    // A previous validation message may have reflowed the container. Each write
    // must preserve the position visible immediately before that write starts.
    const previousScrollTop = scrollBox.scrollTop;
    const nextRevision = revisionNumber + 1;
    star.click();
    assert.equal(star.value, expectedAction, 'Star click submits its three-state intent');
    const expectedRevision = 'cover-' + nextRevision;
    for (let attempt = 0; attempt < 300 && (bulkForm.dataset.coverSelectionRevision === previousRevision
        || document.querySelector('.hero').dataset.publicCoverSelectionRevision !== expectedRevision); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.notEqual(bulkForm.dataset.coverSelectionRevision, previousRevision,
        'Canonical state response updates the revision (posts=' + postRequestCount + ', action=' + lastPostAction
        + ', submits=' + submitEventCount + ', status=' + (panel.querySelector('[data-admin-side-panel-status]')?.textContent || '')
        + ', pending=' + String(bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="' + id + '"]')?.disabled)
        + ', browserError=' + lastBrowserError + ')');
    assert.equal(document.querySelector('.hero').dataset.publicCoverSelectionRevision, expectedRevision,
        'Shared completion refreshes the public hero to the canonical revision');
    assert.equal(document.querySelector('[data-admin-side-panel]'), panel, 'Side-panel node identity is preserved');
    assert.equal(document.querySelector('[data-admin-image-bulk-form]'), bulkForm, 'Bulk form node identity is preserved');
    assert.equal(document.querySelector('.admin-edit-gallery-form'), editForm, 'Settings form node identity is preserved');
    assert.equal(scrollBox.scrollTop, previousScrollTop, 'Panel scroll position is preserved exactly');
    assert.equal(tab.getAttribute('aria-selected'), 'true', 'Active tab remains selected');
    assert.equal(title.value, 'Unsaved title', 'Unsaved title draft is preserved');
    assert.equal(description.value, 'Unsaved description', 'Unsaved description draft is preserved');
    assert.equal(editForm.querySelector('[name="edit_revision"]').value, 'rev-' + revisionNumber, 'Only the hidden settings revision advances');
    assert.equal(editForm.querySelector('[name="cover_selection_revision"]').value, 'cover-' + revisionNumber, 'The settings form cover revision stays canonical');
    assert.equal(mediaCover.value, expectedMode === 'multi' ? 'multi' : String(expectedIds[0]), 'The settings Media selector follows acknowledged star state');
    if (expectedMode === 'multi') assert.equal(mediaCover.selectedOptions[0].textContent, 'Selected photos', 'A missing multi option is added with its localized label');
    assert.equal(location.href, originalUrl, 'The browser URL is unchanged');
    assert.equal(bulkForm.dataset.coverSelectionMode, expectedMode, 'Acknowledged mode is applied');
    assert.deepEqual([...bulkForm.querySelectorAll('[data-admin-image-cover-star][data-cover-state="single"], [data-admin-image-cover-star][data-cover-state="multi"]')]
        .map(button => Number(button.dataset.imageId)).sort(), [...expectedIds].sort(), 'Acknowledged selected stars match the canonical response');
}

/** Assert the exact value using fixture-local browser code without importing Node test helpers. */
const assert = {
    /** Compare primitive fixture values and fail with the supplied scenario. @param {unknown} actual Observed browser value. @param {unknown} expected Required browser value. @param {string} message Scenario description. @return {void} Throws on mismatch. */
    equal(actual, expected, message) { if (actual !== expected) throw new Error(message + ': expected ' + expected + ', received ' + actual); },
    /** Require two fixture values to differ. @param {unknown} actual Observed browser value. @param {unknown} expected Forbidden matching value. @param {string} message Scenario description. @return {void} Throws on equality. */
    notEqual(actual, expected, message) { if (actual === expected) throw new Error(message + ': values unexpectedly match'); },
    /** Require a truthy fixture condition. @param {unknown} actual Observed browser value. @param {string} message Scenario description. @return {void} Throws when the condition is false. */
    ok(actual, message) { if (!actual) throw new Error(message); },
    /** Compare JSON-safe fixture values structurally. @param {unknown} actual Observed browser value. @param {unknown} expected Required browser structure. @param {string} message Scenario description. @return {void} Throws on structural mismatch. */
    deepEqual(actual, expected, message) { if (JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error(message + ': expected ' + JSON.stringify(expected) + ', received ' + JSON.stringify(actual)); },
};
assert.equal(mediaCover.form, editForm,
    'The Artwork selector must remain associated with the settings form despite living outside it.');
assert.equal(editForm.contains(mediaCover), false,
    'The Artwork selector must exercise native external-form ownership.');

(async () => {
    assert.ok(initialScrollTop > 0, 'Fixture content must produce a meaningful nonzero panel scroll position');
    const initialOffVisual = coverStarVisual(bulkForm.querySelector(
        '[data-admin-image-cover-star][data-image-id="1"]',
    ));
    const initialSingleVisual = coverStarVisual(bulkForm.querySelector(
        '[data-admin-image-cover-star][data-image-id="2"]',
    ));
    assert.equal(initialOffVisual?.state, 'off', 'The neutral star starts off');
    assert.equal(initialOffVisual?.background, 'rgb(248, 250, 252)',
        'The off star uses the neutral Admin action surface under the real Admin button cascade');
    assert.equal(initialSingleVisual?.state, 'single', 'The prepared cover starts as one selected star');
    assert.equal(initialSingleVisual?.background, 'rgb(255, 244, 194)',
        'The exclusive cover has its distinct gold surface under the real Admin button cascade');
    assert.equal(initialSingleVisual?.color, 'rgb(146, 96, 0)',
        'The exclusive cover keeps its gold foreground instead of inheriting the generic button color');
    mediaCover.value = 'automatic';
    mediaCover.dispatchEvent(new Event('change', {bubbles: true}));
    assert.equal(bulkForm.dataset.coverSelectionMode, 'single', 'An unsaved Media draft does not change persisted star intent mode');
    await clickAndVerify(3, 'cover:3', 'single', [3]);
    await clickAndVerify(3, 'cover_arm:3', 'multi', [3]);
    await clickAndVerify(2, 'cover_add:2', 'multi', [3, 2]);
    const multiVisuals = [2, 3].map(id => coverStarVisual(
        bulkForm.querySelector(
            '[data-admin-image-cover-star][data-image-id="' + id + '"]',
        ),
    ));
    assert.ok(multiVisuals.every(star => star?.state === 'multi'
        && star.background === 'rgb(219, 234, 254)'
        && star.color === 'rgb(29, 78, 216)'),
    'Accumulated cover stars use the pale-blue multi-selection treatment after canonical refresh: '
        + JSON.stringify(multiVisuals));
    await clickAndVerify(2, 'cover_reset:2', 'single', [2]);
    await clickAndVerify(3, 'cover:3', 'single', [3]);
    await clickAndVerify(3, 'cover_arm:3', 'multi', [3]);
    await clickAndVerify(2, 'cover_add:2', 'multi', [3, 2]);
    await clickAndVerify(1, 'cover_add:1', 'multi', [3, 2, 1]);
    await clickAndVerify(4, 'cover_add:4', 'multi', [3, 2, 1, 4]);
    const previousRevision = bulkForm.dataset.coverSelectionRevision;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="5"]').click();
    assert.equal(bulkForm.dataset.coverSelectionRevision, previousRevision, 'A fifth selected photo is blocked before persistence');
    assert.equal(bulkForm.querySelector('[data-admin-image-cover-status]').textContent, 'Maximum four selected photos.', 'The four-photo cap is announced accessibly');
    await clickAndVerify(4, 'cover_reset:4', 'single', [4]);
    failAfterPersistence = true;
    const beforeReconcileRevision = bulkForm.dataset.coverSelectionRevision;
    const settingsRevisionBeforeReconcile = editForm.querySelector('[name="edit_revision"]').value;
    const nextReconcileRevision = revisionNumber + 1;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="1"]').click();
    const reconciledRevision = 'cover-' + nextReconcileRevision;
    for (let attempt = 0; attempt < 300 && (bulkForm.dataset.coverSelectionRevision === beforeReconcileRevision
        || document.querySelector('.hero').dataset.publicCoverSelectionRevision !== reconciledRevision); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.notEqual(bulkForm.dataset.coverSelectionRevision, beforeReconcileRevision, 'A persisted HTTP 500 is reconciled with an authoritative GET');
    assert.equal(editForm.querySelector('[name="edit_revision"]').value, settingsRevisionBeforeReconcile,
        'A passive reconciliation never advances the settings revision over an unsaved editor state');
    assert.equal(bulkForm.dataset.coverSelectionMode, 'single');
    assert.equal(bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="1"]').dataset.coverState, 'single');
    assert.equal(document.querySelector('[data-admin-side-panel]'), panel, 'Failure reconciliation retains the panel node');
    assert.equal(title.value, 'Unsaved title', 'Failure reconciliation retains unsaved drafts');
    assert.equal(panel.querySelector('[data-admin-side-panel-status]').classList.contains('is-alert'), true,
        'The original HTTP 500 remains visible after authoritative state reconciliation');

    refuseWithoutPersistence = true;
    const refusedRevision = bulkForm.dataset.coverSelectionRevision;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="2"]').click();
    for (let attempt = 0; attempt < 300 && !panel.querySelector('[data-admin-side-panel-status]').textContent.includes('Cover selection refused'); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(bulkForm.dataset.coverSelectionRevision, refusedRevision, 'A refused write with unchanged canonical state is not reported as saved');
    assert.equal(panel.querySelector('[data-admin-side-panel-status]').classList.contains('is-alert'), true,
        'A refusal remains an error after an unchanged authoritative read');

    failAfterPersistence = true;
    failReconciliationRead = true;
    const requestsBeforeUnknown = postRequestCount;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="2"]').click();
    for (let attempt = 0; attempt < 300 && postRequestCount === requestsBeforeUnknown; attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    for (let attempt = 0; attempt < 300 && !panel.querySelector('[data-admin-side-panel-status]').textContent.includes('HTTP 500'); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    const requestsBeforeGuardedClick = postRequestCount;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="3"]').click();
    const unavailableStateMessage = 'The saved title-picture state could not be checked. Try again after the connection recovers.';
    for (let attempt = 0; attempt < 300 && panel.querySelector('[data-admin-side-panel-status]').textContent !== unavailableStateMessage; attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(panel.querySelector('[data-admin-side-panel-status]').textContent, unavailableStateMessage,
        'A failed preflight read completes before the connection is restored');
    assert.equal(postRequestCount, requestsBeforeGuardedClick, 'An unresolved write is never blindly repeated');
    assert.equal(panel.querySelector('[data-admin-side-panel-status]').classList.contains('is-alert'), true,
        'A failed passive read leaves the form blocked with an error');
    failReconciliationRead = false;
    bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="3"]').click();
    const refreshedStateMessage = 'The saved title-picture state was refreshed. Review it before trying again.';
    for (let attempt = 0; attempt < 300 && (!panel.querySelector('[data-admin-side-panel-status]').textContent.includes(refreshedStateMessage)
        || bulkForm.dataset.coverSelectionRevision !== 'cover-' + revisionNumber); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(panel.querySelector('[data-admin-side-panel-status]').textContent, refreshedStateMessage,
        'A successful preflight reconciliation completes before the overlap scenario');
    assert.equal(bulkForm.dataset.coverSelectionRevision, 'cover-' + revisionNumber,
        'Preflight reconciliation applies the latest canonical revision');
    assert.equal(postRequestCount, requestsBeforeGuardedClick, 'A successful preflight read does not replay the pending mutation');

    const requestsBeforeOverlap = postRequestCount;
    const overlapStar = bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="3"]');
    const expectedOverlapRevision = 'cover-' + (revisionNumber + 1);
    heldPostRelease = () => {};
    bulkForm.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: overlapStar}));
    for (let attempt = 0; attempt < 300 && postRequestCount === requestsBeforeOverlap; attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(postRequestCount, requestsBeforeOverlap + 1, 'The first overlap request reaches the pending transport');
    bulkForm.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: overlapStar}));
    assert.equal(postRequestCount, requestsBeforeOverlap + 1, 'A rapid second submit is blocked while the first request is pending');
    heldPostRelease();
    heldPostRelease = null;
    for (let attempt = 0; attempt < 300 && (bulkForm.dataset.coverSelectionRevision !== expectedOverlapRevision
        || document.querySelector('.hero').dataset.publicCoverSelectionRevision !== expectedOverlapRevision); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(bulkForm.dataset.coverSelectionRevision, expectedOverlapRevision,
        'The released overlap request applies its own canonical revision');
    assert.equal(document.querySelector('.hero').dataset.publicCoverSelectionRevision, expectedOverlapRevision,
        'The overlap scenario completes its public refresh before standalone setup');

    panel.remove();
    document.body.append(editForm, bulkForm);
    bulkForm.removeAttribute('data-admin-panel-bulk-form');
    const standaloneStar = bulkForm.querySelector('[data-admin-image-cover-star][data-image-id="2"]');
    assert.equal(standaloneStar.dataset.coverState, 'off', 'The standalone scenario starts from a server-confirmed off star');
    const standaloneRequestCount = postRequestCount;
    const standaloneExpectedRevision = 'cover-' + (revisionNumber + 1);
    standaloneStar.click();
    for (let attempt = 0; attempt < 300 && (bulkForm.dataset.coverSelectionRevision !== standaloneExpectedRevision
        || document.querySelector('.hero').dataset.publicCoverSelectionRevision !== standaloneExpectedRevision); attempt += 1) {
        await new Promise(resolve => setTimeout(resolve, fixtureWaitMs));
    }
    assert.equal(document.querySelector('.hero').dataset.publicCoverSelectionRevision, standaloneExpectedRevision,
        'Standalone completion refreshes the public hero to the canonical revision');
    assert.equal(postRequestCount, standaloneRequestCount + 1, 'A standalone star uses the asynchronous bulk endpoint');
    assert.equal(bulkForm.dataset.coverSelectionRevision, standaloneExpectedRevision, 'Standalone state is applied from the acknowledged response');
    assert.equal(standaloneStar.dataset.coverState, 'single', 'Standalone star reflects its canonical selection');
    assert.equal(document.querySelector('.admin-edit-gallery-form'), editForm, 'Standalone success preserves the settings form node');
    assert.equal(document.querySelector('[data-admin-image-bulk-form]'), bulkForm, 'Standalone success preserves the image form node');
    assert.equal(title.value, 'Unsaved title', 'Standalone success retains the unsaved title draft');
    assert.equal(description.value, 'Unsaved description', 'Standalone success retains the unsaved description draft');
    assert.equal(location.href, originalUrl, 'Standalone success leaves the browser URL unchanged');
    assert.equal(bulkForm.querySelector('[data-admin-image-cover-status]').classList.contains('is-alert'), false,
        'Standalone completion writes to its own live region without a null-panel error');
    document.querySelector('#results').textContent = 'BROWSER PASS: cover star state and editor preservation';
})().catch(error => {
    document.querySelector('#results').textContent = 'BROWSER FAIL: ' + String(error?.stack || error);
});
