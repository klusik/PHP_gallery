/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-features.js
 * Module Type: Browser Module
 * Purpose: Save individual gallery feature icons immediately.
 * Responsibilities: Revalidate the exact gallery before applying and refresh acknowledged state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {i18n} from './admin-core.js?v=20260512-modular-admin-v1';
import {completeAdminMutation} from './admin-mutation-completion.js?v=20261021-smart-photo-tag-refresh-v1';

/** Stable deferred regions keep one delegated handler across fragment replacement. */
const owners = new WeakMap();
/** Feature keys accepted by the gallery feature service. */
const featureKeys = new Set(['maps', 'filenames', 'voting', 'game']);

/** Resolve one localized status message.
 * @param {string} key Feature message suffix.
 * @param {string} fallback English fallback.
 * @return {string} Localized message.
 */
function label(key, fallback) {
    return i18n('admin.gallery_features.' + key, fallback);
}

/** Send an authenticated plan or application request.
 * @param {HTMLElement} root Current gallery workspace and route owner.
 * @param {string} url Prepared same-installation endpoint.
 * @param {Array<{root_id:number,feature:string,enabled:boolean|null,scope:string}>} intents One gallery-only change.
 * @param {string} fingerprint Exact apply digest, or empty by default for planning.
 * @return {Promise<{ok:boolean,message?:string,plan?:{fingerprint:string,gallery_count:number,scope:Array<{id:number}>},mutation?:{type:string,entity:string,action:string,entity_ids:Array<number>},panel?:null,contexts?:Array<{type:string,gallery_id:number|null,render_url:string,render_mode:string,postcondition:null}>,fallback?:{redirect_url:string}}>} Parsed plan or canonical completion; rejects on transport or server refusal.
 */
async function request(root, url, intents, fingerprint = '') {
    const body = new FormData();
    const csrf = root.closest('form')?.querySelector('[name="csrf_token"]');
    body.set('csrf_token', csrf?.value || '');
    body.set('intents', JSON.stringify(intents));
    body.set('ajax', '1');
    if (fingerprint) body.set('fingerprint', fingerprint);
    let response, result;
    try {
        response = await fetch(url, {
            method: 'POST', body, credentials: 'same-origin',
            headers: {'Accept': 'application/json'},
        });
        result = await response.json();
    } catch {
        throw new Error(label('quick_failed', 'The change could not be saved.'));
    }
    if (!response.ok || result.ok !== true) {
        throw new Error(typeof result.message === 'string'
            ? result.message : label('quick_failed', 'The change could not be saved.'));
    }
    return result;
}

/** Install one delegated immediate-save handler on each stable gallery region.
 * @return {void} Keeps server state authoritative across dynamically replaced rows.
 */
export function setupAdminGalleryFeatures() {
    for (const root of document.querySelectorAll('[data-feature-plan-url]')) {
        const owner = root.closest('[data-dashboard-read="galleries"]')
            || root.closest('[data-admin-tab-panel]') || root;
        if (owners.has(owner)) {
            const status = root.querySelector('[data-gallery-feature-status]');
            if (status) status.textContent = owners.get(owner).message;
            continue;
        }
        const state = {busy: false, message: ''};
        owners.set(owner, state);
        owner.addEventListener('click', /** Save one selected gallery preference without confirmation.
         * @param {MouseEvent} event Delegated icon activation.
         * @return {Promise<void>} Resolves after revalidation, application and completion.
         */ async (event) => {
            const button = event.target instanceof Element
                ? event.target.closest('button[data-gallery-feature-key]') : null;
            const current = button?.closest('[data-feature-plan-url]');
            if (!button || !current || !owner.contains(current) || state.busy) return;
            event.preventDefault();
            const feature = button.dataset.galleryFeatureKey;
            const id = Number(button.dataset.galleryFeatureRootId);
            const previous = button.dataset.galleryFeatureOwnState;
            if (!featureKeys.has(feature) || !Number.isSafeInteger(id) || id < 1
                || !['on', 'off', 'inherit'].includes(previous)) return;
            const enabled = feature === 'maps'
                ? (previous === 'inherit' ? true : previous === 'on' ? false : null)
                : previous !== 'on';
            const intents = [{root_id: id, feature, enabled, scope: 'gallery'}];
            const buttons = [...current.querySelectorAll('button[data-gallery-feature-key]')];
            const disabled = buttons.map(/** Preserve prior availability. @param {HTMLButtonElement} item Feature icon. @return {boolean} Original disabled state. */ item => item.disabled);
            const status = current.querySelector('[data-gallery-feature-status]');
            state.busy = true;
            buttons.forEach(/** Lock concurrent feature intentions until reconciliation. @param {HTMLButtonElement} item Feature icon. @return {void} Disables the icon. */ item => { item.disabled = true; });
            state.message = label('quick_saving', 'Saving gallery setting…');
            if (status) status.textContent = state.message;
            let applying = false;
            try {
                const preview = await request(current, current.dataset.featurePlanUrl, intents);
                const plan = preview.plan;
                if (!plan || typeof plan.fingerprint !== 'string'
                    || !/^[a-f0-9]{64}$/.test(plan.fingerprint)
                    || plan.gallery_count !== 1 || !Array.isArray(plan.scope)
                    || plan.scope.length !== 1 || Number(plan.scope[0].id) !== id) {
                    throw new Error(label('quick_failed', 'The change could not be saved.'));
                }
                applying = true;
                const result = await request(current, current.dataset.featureApplyUrl, intents, plan.fingerprint);
                state.message = String(result.message || '');
                if (status) status.textContent = state.message;
                try {
                    await completeAdminMutation(result);
                } catch {
                    state.message = label('applied_refresh_warning', 'Gallery feature changes saved. Some metadata could not be refreshed; check System Health.');
                    if (status) status.textContent = state.message;
                }
                state.busy = false;
                document.dispatchEvent(new CustomEvent('adminGalleryWorkspaceMutation', {detail: result}));
            } catch (error) {
                state.message = applying
                    ? label('quick_uncertain', 'The save could not be confirmed. Refreshing the gallery state; check it before trying again.')
                    : (error instanceof Error ? error.message : label('quick_failed', 'The change could not be saved.'));
                if (status) status.textContent = state.message;
                state.busy = false;
                if (applying) {
                    // A lost reply may follow a committed write. Read again; never replay it.
                    document.dispatchEvent(new CustomEvent('adminGalleryWorkspaceMutation'));
                } else {
                    buttons.forEach(/** Restore original availability after a non-mutating failure. @param {HTMLButtonElement} item Feature icon. @param {number} index Original availability index. @return {void} Restores the icon. */ (item, index) => { item.disabled = disabled[index]; });
                }
            }
        });
    }
}
