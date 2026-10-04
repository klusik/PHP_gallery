/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-route-navdata.js
 * Module Type: Browser Module
 * Purpose: Refresh local route navigation data independently of editor saves.
 * Responsibilities: Handle dynamic editors, coalesce checks and complete saved routes through canonical mutations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {completeAdminMutation} from './admin-mutation-completion.js?v=20260902-create-delete-hotfix1';
import {ADMIN_ROUTE_NAVDATA_BUSY_RETRY_MS, ADMIN_ROUTE_NAVDATA_BUSY_RETRIES} from './admin-interaction-policy.js?v=20261005-route-navdata-v1';

const checks = new Map();
const checkedForms = new WeakSet();

/**
 * Capture only the refresh endpoint, CSRF token and saved gallery identity.
 * @param {HTMLFormElement} form Current editor, including a dynamically injected copy.
 * @param {number|null} savedId Gallery identity returned by a successful creation/save.
 * @returns {{url:string, token:string, galleryId:number}|null} Refresh authority independent of draft contents.
 */
function routeRefreshRequest(form, savedId = null) {
    const control = form.querySelector('[data-route-navdata-url]');
    const url = String(control?.dataset.routeNavdataUrl || '');
    const token = String(form.querySelector('[name="csrf_token"]')?.value || '');
    const galleryId = savedId ?? Number(form.querySelector('[name="gallery_id"], [name="id"]')?.value
        || form.querySelector('[data-gallery-id]')?.dataset.galleryId || 0);
    if (!url || !token || !Number.isSafeInteger(galleryId) || galleryId < 0) return null;
    return {url, token, galleryId};
}

/**
 * Run coalesced freshness checks without awaiting or disabling gallery saves.
 * @param {{url:string, token:string, galleryId:number}} request Captured request authority.
 * @param {boolean} afterSave Whether persistence requires another pass after an active check.
 * @returns {void} Starts an independent request and handles every rejection locally.
 */
function queueRouteRefresh(request, afterSave = false) {
    const key = `${request.url}:${request.galleryId}`;
    const existing = checks.get(key);
    if (existing) {
        if (afterSave) existing.repeat = true;
        return;
    }
    const task = {repeat: false};
    checks.set(key, task);
    (async () => {
        let busyRetries = 0;
        do {
            task.repeat = false;
            const body = new FormData();
            body.set('csrf_token', request.token);
            body.set('gallery_id', String(request.galleryId));
            body.set('ajax', '1');
            const response = await fetch(request.url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body,
            });
            const payload = await response.json();
            if (!response.ok || payload?.ok !== true) return;
            if (payload.state === 'busy') {
                if (++busyRetries > ADMIN_ROUTE_NAVDATA_BUSY_RETRIES) return;
                task.repeat = true;
                await new Promise(resolve => window.setTimeout(resolve, ADMIN_ROUTE_NAVDATA_BUSY_RETRY_MS));
            } else {
                await completeAdminMutation(payload);
            }
        } while (task.repeat);
    })().catch(() => {
        // A failed optional import never rejects or changes the editor save workflow.
    }).finally(() => checks.delete(key));
}

/**
 * Start a freshness check once the editor contains route input or imports SimBrief.
 * @param {HTMLFormElement} form Gallery editor containing the action.
 * @param {boolean} simbrief Whether an explicit SimBrief import requested local freshness.
 * @returns {void} Admits at most one typing check per rendered form.
 */
function checkEditorRoute(form, simbrief = false) {
    if (checkedForms.has(form)) return;
    if (!simbrief && !String(form.querySelector('[name="flight_route_text"]')?.value || '').trim()) return;
    const request = routeRefreshRequest(form);
    if (!request) return;
    checkedForms.add(form);
    queueRouteRefresh(request);
}

/**
 * Bind route/SimBrief intent and saved-route completion across dynamic side-panel fragments.
 * @returns {void} Leaves form submission, URL and panel lifecycle with their existing owners.
 */
export function setupAdminRouteNavdata() {
    if (!window.fetch || document.documentElement.dataset.routeNavdataReady === '1') return;
    document.documentElement.dataset.routeNavdataReady = '1';
    for (const eventName of ['input', 'change', 'focusin']) {
        document.addEventListener(eventName, event => {
            if (event.target instanceof Element && event.target.matches('[name="flight_route_text"]')) {
                const form = event.target.closest('form');
                if (form instanceof HTMLFormElement) checkEditorRoute(form);
            }
        });
    }
    document.addEventListener('click', event => {
        const button = event.target instanceof Element ? event.target.closest('[data-simbrief-generate]') : null;
        const form = button?.closest('form');
        if (form instanceof HTMLFormElement) checkEditorRoute(form, true);
    }, true);
    document.addEventListener('submit', event => {
        if (event.target instanceof HTMLFormElement) checkEditorRoute(event.target);
    }, true);
    document.addEventListener('php-gallery:side-panel-success', event => {
        const form = event.target;
        const result = event.detail?.result;
        if (!(form instanceof HTMLFormElement) || result?.ok !== true || result.mutation?.entity !== 'gallery') return;
        if (!String(form.querySelector('[name="flight_route_text"]')?.value || '').trim()
            && !String(form.querySelector('[data-simbrief-draft-ref]')?.value || '').trim()
            && !checkedForms.has(form)) return;
        const galleryId = Number(result.mutation.entity_ids?.[0] || result.gallery_id || 0);
        const request = routeRefreshRequest(form, galleryId || null);
        if (request) queueRouteRefresh(request, true);
    }, true);
}
