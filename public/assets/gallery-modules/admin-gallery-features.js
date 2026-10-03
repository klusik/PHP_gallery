/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: public/assets/gallery-modules/admin-gallery-features.js
 * Module Type: Browser Module
 * Purpose: Stage local subtree feature intentions and require exact server preview confirmation.
 * Responsibilities: Preserve drafts across injected rows and forward canonical mutation completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
import {i18n} from './admin-core.js?v=20260512-modular-admin-v1';
import {completeAdminMutation} from './admin-mutation-completion.js?v=20260920-admin-interaction-policy-v1';

/** Persistent drafts belong to the stable deferred region, not its replaceable workspace. */
const drafts = new WeakMap();
/** Features admitted by the server domain owner; background remains presentation only. */
const featureKeys = new Set(['maps', 'filenames', 'voting', 'game']);

/** Resolve one translated feature-plan label.
 * @param {string} key Stable feature-plan suffix.
 * @param {string} fallback Safe English label.
 * @param {Record<string,string|number>} values Interpolation values.
 * @return {string} Localized presentation text.
 */
function label(key, fallback, values = {}) { return i18n('admin.gallery_features.' + key, fallback, values); }

/** Create presentation nodes without interpreting any gallery title as markup.
 * @param {string} tag HTML element name.
 * @param {string} text Already prepared text.
 * @return {HTMLElement} Detached text-only node.
 */
function textNode(tag, text) { const node = document.createElement(tag); node.textContent = text; return node; }

/** Find the replaceable workspace currently mounted in a draft owner.
 * @param {HTMLElement} owner Stable deferred region or direct-page panel.
 * @return {HTMLElement|null} Current feature workspace.
 */
function workspace(owner) { return owner.matches('[data-feature-plan-url]') ? owner : owner.querySelector('[data-feature-plan-url]'); }

/** Replay intentions on individual prepared values so mixed ancestor summaries stay truthful.
 * @param {HTMLElement} root Current workspace.
 * @param {Array<object>} intents Chronological local intentions.
 * @return {Map<number,object>} Final per-row states and touched fields.
 */
function stagedRows(root, intents) {
    const rows = new Map();
    for (const row of root.querySelectorAll('[data-gallery-row]')) {
        const values = {}, touched = new Set();
        for (const button of row.querySelectorAll('[data-gallery-feature-key]')) {
            values[button.dataset.galleryFeatureKey] = ['on','enabled','1','true'].includes(button.dataset.galleryFeatureOwnState || button.dataset.galleryFeatureState);
        }
        rows.set(Number(row.dataset.galleryId), {parent:Number(row.dataset.parentId || 0),values,touched,children:[]});
    }
    for (const [id,row] of rows) {
        rows.get(row.parent)?.children.push(id);
    }
    for (const intent of intents) {
        const pending = [intent.root_id], seen = new Set();
        while (pending.length) {
            const id = pending.pop(), row = rows.get(id);
            if (!row || seen.has(id)) continue;
            seen.add(id); for (const child of row.children) pending.push(child);
            row.values[intent.feature] = intent.enabled; row.touched.add(intent.feature);
            if (intent.feature === 'game' && intent.enabled) { row.values.voting = true; row.touched.add('voting'); }
            if (intent.feature === 'voting' && !intent.enabled) { row.values.game = false; row.touched.add('game'); }
        }
    }
    return rows;
}

/** Aggregate final subtree states once from leaves upward without recursive depth or per-chip scans.
 * @param {Map<number,object>} rows Replayed complete hierarchy.
 * @return {Map<number,Record<string,{on:number,total:number,touched:boolean}>>} Constant-time chip summaries.
 */
function stagedSummaries(rows) {
    const summaries = new Map(), remaining = new Map(), ready = [];
    for (const [id,row] of rows) {
        const own = {};
        for (const feature of featureKeys) own[feature] = {on:row.values[feature] ? 1 : 0,total:1,touched:row.touched.has(feature)};
        summaries.set(id,own); remaining.set(id,row.children.length);
        if (!row.children.length) ready.push(id);
    }
    while (ready.length) {
        const id = ready.pop(), parent = rows.get(id).parent;
        if (!rows.has(parent)) continue;
        for (const feature of featureKeys) {
            const child = summaries.get(id)[feature], target = summaries.get(parent)[feature];
            target.on += child.on; target.total += child.total; target.touched ||= child.touched;
        }
        remaining.set(parent,remaining.get(parent)-1);
        if (remaining.get(parent) === 0) ready.push(parent);
    }
    return summaries;
}

/** Update only draft presentation and keep persisted chip metadata authoritative.
 * @param {HTMLElement} owner Stable draft owner.
 * @param {{intents:Array<object>,busy:boolean,message:string}} state Current local intentions.
 * @return {void} Renders pending controls on newly inserted or existing rows.
 */
function renderDraft(owner, state) {
    const root = workspace(owner);
    if (!root) return;
    const pending = root.querySelector('[data-gallery-feature-pending]');
    if (pending) pending.hidden = state.intents.length === 0 && !state.message;
    const status = root.querySelector('[data-gallery-feature-pending-status]');
    if (status) status.textContent = state.message || label('pending', '{count} staged changes. Review before applying.', {count: state.intents.length});
    // Initial reads and cleared drafts use persisted labels directly, without hierarchy aggregation.
    const summaries = state.intents.length ? stagedSummaries(stagedRows(root,state.intents)) : null;
    for (const button of root.querySelectorAll('[data-gallery-feature-key]')) {
        const summary = summaries?.get(Number(button.dataset.galleryFeatureRootId))?.[button.dataset.galleryFeatureKey];
        const staged = summary?.touched === true;
        button.classList.toggle('is-pending', staged);
        if (staged) button.setAttribute('data-feature-prepared', '1');
        else button.removeAttribute('data-feature-prepared');
        button.querySelector('[data-feature-prepared-label]')?.remove();
        if (staged) {
            const target = summary.on === summary.total ? label('on','On') : summary.on === 0 ? label('off','Off') : i18n('admin.gallery_list.feature_mixed','Enabled in {on} of {total} galleries',{on:summary.on,total:summary.total});
            const prepared = textNode('span',' → ' + target); prepared.dataset.featurePreparedLabel='1'; button.append(prepared);
        }
        button.disabled = state.busy;
    }
    for (const button of root.querySelectorAll('[data-gallery-feature-review],[data-gallery-feature-discard]')) button.disabled = state.busy || state.intents.length === 0;
}

/** Resolve the next local intent while replaying ancestor intentions and coupled rules.
 * @param {HTMLElement} root Current rendered workspace.
 * @param {HTMLButtonElement} button Activated chip.
 * @param {Array<object>} intents Ordered local intentions.
 * @return {boolean} New explicit subtree state.
 */
function nextState(root, button, intents) {
    if (!intents.length) return button.dataset.galleryFeatureState !== 'on';
    const summary = stagedSummaries(stagedRows(root,intents)).get(Number(button.dataset.galleryFeatureRootId))[button.dataset.galleryFeatureKey];
    return summary.on !== summary.total;
}

/** Send one authenticated semantic request and reject malformed or refused responses.
 * @param {HTMLElement} root Current workspace with route metadata.
 * @param {string} url Prepared plan or apply route.
 * @param {Array<object>} intents Chronological intentions.
 * @param {string} fingerprint Empty for preview, reviewed value for application.
 * @return {Promise<Record<string,any>>} Parsed untouched response.
 */
async function request(root, url, intents, fingerprint = '') {
    const token = root.closest('form')?.querySelector('[name="csrf_token"]') || root.querySelector('[name="csrf_token"]');
    const body = new FormData();
    body.set('csrf_token', token?.value || ''); body.set('intents', JSON.stringify(intents)); body.set('ajax', '1');
    if (fingerprint) body.set('fingerprint', fingerprint);
    let response, result;
    try {
        response = await fetch(url, {method: 'POST', body, headers: {'Accept': 'application/json'}});
        result = await response.json();
    } catch {
        throw new Error(label('review_failed', 'Gallery changes could not be reviewed. Check feature availability and System Health, then try again.'));
    }
    if (!response.ok || result.ok !== true) throw new Error(result.message || label('review_failed', 'Gallery changes could not be reviewed. Check feature availability and System Health, then try again.'));
    return result;
}

/** Populate the owned native dialog with complete scope and current-to-new changes.
 * @param {HTMLDialogElement} dialog Owned confirmation dialog.
 * @param {Record<string,any>} plan Exact server-reviewed plan.
 * @return {void} Replaces dialog children using safe text nodes.
 */
function renderReview(dialog, plan) {
    dialog.replaceChildren(textNode('h3', label('title', 'Review gallery feature changes')));
    dialog.append(textNode('p', label('scope', '{count} galleries in the selected subtrees.', {count: plan.gallery_count})));
    const scope = textNode('ul', '');
    for (const gallery of plan.scope) scope.append(textNode('li', `${gallery.title} (#${gallery.id})`));
    dialog.append(scope, textNode('p', label('changes', '{count} changes in {galleries} galleries.', {count: plan.change_count, galleries: plan.changed_gallery_count})));
    const changes = textNode('ul', '');
    for (const gallery of plan.rows) {
        for (const change of gallery.changes) {
            const from = label(change.current ? 'on' : 'off', change.current ? 'On' : 'Off');
            const to = label(change.next ? 'on' : 'off', change.next ? 'On' : 'Off');
            let suffix = change.side_effect ? ' (' + label('side_effect', 'required coupled change') + ')' : '';
            if (change.inherited) suffix += ' (' + label('inherited', 'replaces inherited setting') + ')';
            changes.append(textNode('li', `${gallery.title}: ${label(change.feature, {maps:'Maps',filenames:'File names',voting:'Voting',game:'Picture Game'}[change.feature])} ${from} → ${to}${suffix}`));
        }
    }
    dialog.append(changes);
    if (plan.change_count === 0) dialog.append(textNode('p', label('no_changes', 'The selected galleries already have these settings.')));
    const confirm = textNode('button', label('confirm', 'Apply reviewed changes')); confirm.type = 'button'; confirm.dataset.galleryFeatureConfirm = '1'; confirm.disabled = plan.change_count === 0;
    const cancel = textNode('button', label('cancel', 'Cancel')); cancel.type = 'button'; cancel.dataset.galleryFeatureCancel = '1';
    dialog.append(confirm, cancel);
}

/** Bind local staging once per stable region and rerender surviving drafts after fragment replacement.
 * @return {void} Installs delegated handlers that never persist on chip activation or cancellation.
 */
export function setupAdminGalleryFeatures() {
    for (const root of document.querySelectorAll('[data-feature-plan-url]')) {
        const owner = root.closest('[data-dashboard-read="galleries"]') || root.closest('[data-admin-tab-panel]') || root;
        let state = drafts.get(owner);
        if (state) { renderDraft(owner, state); continue; }
        state = {intents: [], busy: false, message: '', plan: null}; drafts.set(owner, state);
        owner.addEventListener('click', /** Route dynamically inserted feature controls through their stable draft owner.
         * @param {MouseEvent} event Delegated activation.
         * @return {Promise<void>} Completes local staging, review or confirmed application.
         */ async event => {
            if (!(event.target instanceof Element)) return;
            const button = event.target.closest('button');
            const current = workspace(owner);
            if (!button || !current?.contains(button)) return;
            if (!button.matches('[data-gallery-feature-key],[data-gallery-feature-review],[data-gallery-feature-discard],[data-gallery-feature-confirm],[data-gallery-feature-cancel]')) return;
            event.preventDefault();
            if (state.busy) return;
            const dialog = current.querySelector('[data-gallery-feature-dialog]');
            if (button.hasAttribute('data-gallery-feature-key')) {
                const feature = button.dataset.galleryFeatureKey;
                const id = Number(button.dataset.galleryFeatureRootId);
                if (!featureKeys.has(feature) || !Number.isSafeInteger(id) || id < 1 || state.intents.length >= 256) return;
                state.intents.push({root_id:id,feature,enabled:nextState(current,button,state.intents)}); state.plan = null; state.message = '';
                renderDraft(owner,state); return;
            }
            if (button.hasAttribute('data-gallery-feature-discard')) { state.intents = []; state.plan = null; state.message = ''; dialog?.close(); renderDraft(owner,state); return; }
            if (button.hasAttribute('data-gallery-feature-cancel')) { dialog?.close(); state.plan = null; return; }
            if (!(dialog instanceof HTMLDialogElement)) return;
            state.busy = true; state.message = label(button.hasAttribute('data-gallery-feature-confirm') ? 'applying' : 'preview_loading', button.hasAttribute('data-gallery-feature-confirm') ? 'Applying reviewed changes…' : 'Loading exact change preview…'); renderDraft(owner,state);
            try {
                if (button.hasAttribute('data-gallery-feature-review')) {
                    const result = await request(current,current.dataset.featurePlanUrl,state.intents);
                    if (!result.plan || !Array.isArray(result.plan.scope) || !Array.isArray(result.plan.rows) || typeof result.plan.fingerprint !== 'string') throw new Error(label('review_failed','Gallery changes could not be reviewed.'));
                    state.plan = result.plan; renderReview(dialog,result.plan); dialog.showModal(); state.message = '';
                } else if (state.plan) {
                    const result = await request(current,current.dataset.featureApplyUrl,state.plan.intents,state.plan.fingerprint);
                    state.intents = []; state.plan = null; state.message = result.message || label('applied','Gallery feature changes saved.'); dialog.close();
                    try { await completeAdminMutation(result); }
                    catch { state.message = label('applied_refresh_warning','Gallery feature changes saved. Some metadata could not be refreshed; check System Health.'); }
                    document.dispatchEvent(new CustomEvent('adminGalleryWorkspaceMutation',{detail:result}));
                }
            } catch (error) { state.plan = null; dialog.close(); state.message = error.message || label('apply_failed','Gallery changes were not applied. Review the changes again.'); }
            finally { state.busy = false; renderDraft(owner,state); }
        });
        renderDraft(owner,state);
    }
}
