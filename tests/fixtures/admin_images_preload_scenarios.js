/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_images_preload_scenarios.js
 * Module Type: Browser Test Fixture
 * Purpose: Exercise real editor preload/tab/lifecycle behavior in disposable Chromium.
 * Responsibilities: Cover interactive settings, bounded resources, recovery and stale responses.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {setupAdminTabsInRoot} from '/public/assets/gallery-modules/admin-tabs.js?v=20261013-images-preload-v1';
import {beginAdminPanelOpen, deactivateAdminPanel} from '/public/assets/gallery-modules/admin-panel-lifecycle.js?v=20260920-panel-lifecycle-v1';
import {prepareAdminPanelDrafts} from '/public/assets/gallery-modules/admin-panel-drafts.js?v=20261012-pdf-files-v1';

// Pace lifecycle condition checks without starving browser resource tasks.
// Type: number; Units: milliseconds; Scope: one synthetic wait iteration;
// Consumers: runImagesPreloadScenarios; Rationale: five milliseconds permit
// asynchronous progress within the fixture's existing bounded deadline.
const conditionPollMs = 5;
// Admit obsolete response continuations before checking cancellation effects.
// Type: number; Units: milliseconds; Scope: one stale-response observation;
// Consumers: runImagesPreloadScenarios; Rationale: ten milliseconds allow queued
// tasks to settle after Save or close without claiming real-host timing evidence.
const staleResponseSettleMs = 10;

/**
 * Run mandatory preload scenarios after the existing drawer lifecycle assertions.
 * @param {function(boolean,string):void} check Existing fixture assertion reporter.
 * @param {typeof fetch} nativeFetch Owned loopback transport for thumbnail metrics.
 * @returns {Promise<void>} Completes every scenario or propagates its first failure.
 */
export async function runImagesPreloadScenarios(check, nativeFetch) {
    const previousFetch = window.fetch;
    const previousUrl = location.href;
    let galleryId = 42;
    let count = 0;
    let holdMetadata = false;
    let malformed = false;
    let failThumbnail = false;
    let runId = 0;
    let postCount = 0;
    const gates = [];
    const metrics = [];
    let panel = null;
    const shell = id => '<section id="admin-edit-images" data-admin-tab-panel><div class="admin-gallery-images"><form data-admin-image-bulk-form action="/__images_bulk">'
        + '<input name="gallery_id" value="' + id + '"><input name="csrf_token" value="fixture"><input name="return_tab" value="admin-edit-images">'
        + '<div data-admin-image-order-toolbar data-reorder-url="/__images_order"><span data-admin-image-order-status></span></div>'
        + '<table data-admin-image-order-table><thead></thead><tbody></tbody></table></form></div></section>';
    const markup = (id, total, requested = false) => '<div data-admin-edit-panel-workspace="gallery-edit">'
        + '<nav data-admin-tabs><a role="tab" data-admin-tab-target="admin-edit-identity" href="#admin-edit-identity" aria-selected="' + !requested + '">Identity</a>'
        + '<a role="tab" data-admin-tab-target="admin-edit-access" href="#admin-edit-access">Access</a>'
        + '<a role="tab" data-admin-tab-target="admin-edit-images" href="#admin-edit-images" aria-selected="' + requested + '">Images</a></nav>'
        + '<div data-gallery-images-preload data-gallery-id="' + id + '" data-total="' + total + '" data-requested="' + (requested ? '1' : '0') + '" data-endpoint="/__images_batch?id=' + id + '"'
        + ' data-label-preparing="Preparing" data-label-progress="Photos: {current} / {total}" data-label-ready="Ready" data-label-failed="{count} failed" data-label-error="Load failed" data-label-retry="Retry">'
        + '<div role="progressbar" aria-valuemin="0" aria-valuemax="100"><span data-images-preload-fill></span></div><small data-images-preload-status></small>'
        + '<span role="status" data-images-preload-announcement></span><button type="button" data-images-preload-retry hidden>Retry</button></div>'
        + '<form class="admin-edit-gallery-form" data-admin-gallery-settings-form data-admin-panel-edit-form data-admin-panel-workflow="gallery-edit" data-admin-panel-action="/__images_save">'
        + '<input type="hidden" name="id" value="' + id + '"><input type="hidden" name="edit_revision" value="1"><input type="hidden" name="csrf_token" value="fixture">'
        + '<section id="admin-edit-identity" data-admin-tab-panel><input name="title" value="Current draft"></section>'
        + '<section id="admin-edit-access" data-admin-tab-panel><select name="visibility"><option value="unlisted">Unlisted</option><option value="public">Public</option></select></section>'
        + '<button type="submit">Save</button></form>'
        + (total === 0 ? shell(id) : '<section id="admin-edit-images" data-admin-tab-panel></section>') + '</div>';
    const mount = (total, requested = false, id = galleryId) => {
        if (panel) { deactivateAdminPanel(panel); panel.remove(); }
        galleryId = id;
        count = total;
        panel = document.createElement('div');
        panel.dataset.adminSidePanel = '';
        panel.dataset.adminSidePanelWorkflow = 'gallery-edit';
        panel.className = 'admin-side-panel is-open';
        panel.innerHTML = '<div class="admin-side-panel-dialog"><header class="admin-side-panel-header"></header><div data-admin-side-panel-status></div>'
            + '<div data-admin-side-panel-body>' + markup(id, total, requested) + '</div></div>';
        document.body.append(panel);
        beginAdminPanelOpen(panel, document.getElementById('open-a'));
        prepareAdminPanelDrafts(panel);
        setupAdminTabsInRoot(panel);
        return panel.querySelector('[data-gallery-images-preload]');
    };
    const waitFor = async condition => {
        const deadline = performance.now() + 18000;
        while (!condition()) {
            if (performance.now() > deadline) throw new Error('Images scenario deadline; state=' + panel?.querySelector('[data-gallery-images-preload]')?.dataset.preloadState);
            // Pace bounded condition polling without starving the synthetic browser.
            // Type: number; Units: milliseconds; Scope: one scenario wait iteration;
            // Consumers: Images lifecycle assertions; Rationale: five milliseconds
            // let resource and mutation tasks settle within the existing deadline.
            await new Promise(resolve => setTimeout(resolve, conditionPollMs));
        }
    };
    window.fetch = async (input, options = {}) => {
        const url = new URL(String(input), location.href);
        if (url.pathname === '/__images_save') {
            postCount += 1;
            check(options.body.get('visibility') === 'public' && options.body.get('edit_revision') === '1', 'Pending-photo Save preserves visibility/revision transport.');
            return new Response(JSON.stringify({ok: true, message: 'Saved during preload', gallery_id: galleryId, edit_revision: '2',
                mutation: {type: 'gallery.update', entity: 'gallery', action: 'update', entity_ids: [galleryId]}, contexts: [], fallback: {}}),
                {headers: {'Content-Type': 'application/json'}});
        }
        if (url.pathname !== '/__images_batch') return previousFetch(input, options);
        const id = Number(url.searchParams.get('id'));
        const offset = Number(url.searchParams.get('offset') || 0);
        const total = count;
        const end = Math.min(total, offset + 100);
        const rows = Array.from({length: end - offset}, (_, index) => {
            const identity = offset + index + 1;
            return '<tr data-admin-image-order-row data-image-id="' + identity + '" data-image-name="photo-' + identity + '.jpg">'
                + '<td><img data-admin-preload-src="/__preload_thumb?run=' + runId + '&id=' + identity + (failThumbnail && identity === 2 ? '&fail=1' : '') + '" alt=""></td>'
                + '<td><input type="checkbox" name="image_ids[]" value="' + identity + '"></td></tr>';
        }).join('');
        const payload = {ok: true, gallery_id: malformed ? id + 1 : id, offset, next_offset: end, total, done: end === total,
            revision: 'a'.repeat(64), shell_html: offset === 0 ? shell(id) : '', rows_html: rows};
        if (holdMetadata) {
            return await new Promise(resolve => gates.push({signal: options.signal, release: () => resolve(new Response(JSON.stringify(payload), {headers: {'Content-Type': 'application/json'}}))}));
        }
        return new Response(JSON.stringify(payload), {headers: {'Content-Type': 'application/json'}});
    };
    try {
        document.querySelector('[data-admin-side-panel]')?.remove();
        holdMetadata = true;
        runId += 1;
        let widget = mount(50, true);
        await waitFor(() => gates.length === 1);
        const tab = panel.querySelector('[data-admin-tab-target="admin-edit-images"]');
        check(tab.getAttribute('aria-disabled') === 'true' && tab.tabIndex === -1 && tab.getAttribute('aria-selected') !== 'true', 'Direct Images intent starts on an enabled settings tab.');
        tab.click();
        const blockedKey = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
        tab.dispatchEvent(blockedKey);
        check(blockedKey.defaultPrevented && tab.getAttribute('aria-selected') !== 'true' && location.href === previousUrl, 'Mouse/programmatic/keyboard activation cannot open pending Images or navigate.');
        panel.querySelector('[data-admin-tab-target="admin-edit-access"]').click();
        const form = panel.querySelector('.admin-edit-gallery-form');
        form.querySelector('[name="visibility"]').value = 'public';
        form.requestSubmit();
        await waitFor(() => postCount === 1 && form.querySelector('[name="edit_revision"]').value === '2' && gates.length === 2);
        const afterSave = panel.querySelector('[name="title"]');
        afterSave.value = 'Typed after Save';
        afterSave.dispatchEvent(new Event('input', {bubbles: true}));
        check(!panel.hidden && location.href === previousUrl && gates[0].signal.aborted, 'A successful existing AJAX Save restarts only photo work and keeps the drawer/URL.');
        gates.shift().release();
        // Observe the released stale response after its queued continuation can run.
        // Type: number; Units: milliseconds; Scope: stale pre-save response assertion;
        // Consumers: draft-preservation fixture; Rationale: ten milliseconds admit
        // late tasks before checking that they neither insert rows nor alter drafts.
        await new Promise(resolve => setTimeout(resolve, staleResponseSettleMs));
        check(panel.querySelectorAll('[data-admin-image-order-row]').length === 0 && afterSave.value === 'Typed after Save', 'Late pre-save metadata cannot inject rows or overwrite a later draft.');
        holdMetadata = false;
        gates.shift().release();
        await waitFor(() => widget.dataset.preloadState === 'ready');
        check(panel.querySelector('[name="title"]').value === 'Typed after Save' && tab.getAttribute('aria-disabled') === null, 'Completed images preserve settings and unlock Images.');
        check(panel.querySelector('[data-admin-image-bulk-form]').dataset.adminPanelBulkForm === 'true', 'Deferred canonical bulk form joins the existing delegated panel pipeline.');
        check(tab.getAttribute('aria-selected') === 'true', 'Direct Images intent activates after full readiness.');
        for (const width of [390, 320, 1280]) {
            const viewport = await nativeFetch('/__viewport?width=' + width);
            check(viewport.ok, 'Owned preload viewport adapter is available.');
            await waitFor(() => innerWidth === width);
            check(widget.querySelector('[role="progressbar"]').getBoundingClientRect().height <= 3
                && widget.scrollWidth <= widget.clientWidth + 1, 'Compact progress fits the drawer at ' + width + 'px.');
        }
        for (const total of [0, 1, 50, 500, 1000, 3001]) {
            runId += 1;
            for (const cache of ['cold', 'warm']) {
                const started = performance.now();
                widget = mount(total);
                check(panel.querySelector('.admin-edit-gallery-form button').disabled === false, 'Settings remain available for ' + total + ' images (' + cache + ').');
                await waitFor(() => widget.dataset.preloadState === 'ready');
                check(panel.querySelectorAll('[data-admin-image-order-row]').length === total, 'Complete browser table for ' + total + ' images (' + cache + ').');
                const resources = await (await nativeFetch('/__preload_stats?run=' + runId)).json();
                check(resources.peak <= 6 && (total < 2 || resources.peak > 1), 'Actual loopback thumbnail requests obey six-worker parallelism.');
                metrics.push({images: total, cache, completion_ms: Math.round(performance.now() - started), peak: resources.peak});
            }
        }
        runId += 1;
        failThumbnail = true;
        widget = mount(5);
        await waitFor(() => widget.dataset.preloadState === 'ready-with-errors');
        check(panel.querySelector('[data-admin-thumbnail-failed]') !== null && !widget.querySelector('[data-images-preload-retry]').hidden
            && panel.querySelector('[data-admin-tab-target="admin-edit-images"]').getAttribute('aria-disabled') === null, '404 resources settle with placeholder, unlocked tab and explicit retry.');
        failThumbnail = false;
        widget.querySelector('[data-images-preload-retry]').click();
        await waitFor(() => widget.dataset.preloadState === 'ready');
        malformed = true;
        runId += 1;
        widget = mount(5);
        await waitFor(() => widget.dataset.preloadState === 'error');
        check(!widget.querySelector('[data-images-preload-retry]').hidden && !panel.querySelector('.admin-edit-gallery-form button').disabled, 'Wrong-gallery data is retryable without blocking settings.');
        malformed = false;
        widget.querySelector('[data-images-preload-retry]').click();
        await waitFor(() => widget.dataset.preloadState === 'ready');
        holdMetadata = true;
        runId += 1;
        widget = mount(10, false, 42);
        await waitFor(() => gates.length === 1);
        const obsolete = widget;
        const b = mount(1, false, 43);
        await waitFor(() => gates.length === 2);
        check(gates[0].signal.aborted, 'Gallery A to B cancels the previous generation.');
        gates.shift().release();
        holdMetadata = false;
        gates.shift().release();
        await waitFor(() => b.dataset.preloadState === 'ready');
        check(!obsolete.isConnected && panel.querySelector('[name="gallery_id"]').value === '43', 'Late A cannot populate B.');
        holdMetadata = true;
        mount(10);
        await waitFor(() => gates.length === 1);
        deactivateAdminPanel(panel);
        panel.remove();
        gates.shift().release();
        // Allow the released response to settle after the drawer has been detached.
        // Type: number; Units: milliseconds; Scope: close-before-response assertion;
        // Consumers: drawer cancellation fixture; Rationale: ten milliseconds admit
        // obsolete tasks before checking that they cannot navigate the document.
        await new Promise(resolve => setTimeout(resolve, staleResponseSettleMs));
        check(location.href === previousUrl, 'Close-before-metadata leaves the browser URL unchanged.');
        console.log('Images preload synthetic Chromium timings (tiny cached loopback images; no live PHP/hosting or physical heap claim): ' + JSON.stringify(metrics));
    } finally {
        window.fetch = previousFetch;
        if (panel?.isConnected) { deactivateAdminPanel(panel); panel.remove(); }
    }
}
