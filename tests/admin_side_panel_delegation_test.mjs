/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect delegated side-panel event handling.
 * Responsibilities:
 *   - Exercise dynamically replaced controls without rebinding the entire page.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_side_panel_delegation_test.mjs
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sidePanelPath = path.join(projectRoot, 'public/assets/gallery-modules/admin-side-panel.js');
const source = await fs.readFile(sidePanelPath, 'utf8');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const delegatedSubmitPattern = /document\.addEventListener\(\s*['"]submit['"]\s*,\s*(?:\/\*[\s\S]*?\*\/\s*)?async\s*\(event\)\s*=>\s*\{/;
assert(delegatedSubmitPattern.test(source), 'Side-panel forms must be intercepted through document-level delegated submit handlers, including documented callbacks.');
assert(delegatedSubmitPattern.test("document.addEventListener('submit', /** callback */ async (event) => {"), 'Attached callback documentation must not hide document-level delegation.');
assert(!delegatedSubmitPattern.test("form.addEventListener('submit', async (event) => {"), 'Direct form binding must not satisfy delegated document ownership.');
assert(source.includes("form.matches('[data-gallery-panel-create-form]')"), 'Dynamically injected create forms must remain delegated.');
assert(source.includes("form.matches('[data-admin-panel-edit-form]')"), 'Dynamically injected edit forms must remain delegated.');
assert(source.includes("form.matches('[data-admin-panel-bulk-form]')"), 'Dynamically injected bulk forms must remain delegated.');
assert(source.includes("form.matches('[data-smart-gallery-panel-form]')"), 'Dynamically injected Smart Gallery forms must remain delegated.');
assert(source.includes("form.matches('[data-admin-panel-scan-images-form]')"), 'Dynamically injected scan/import forms must remain delegated.');
assert(source.includes("form.matches('[data-admin-panel-ai-reprocess-form]')"), 'Dynamically injected AI reprocess forms must remain delegated.');
assert(source.includes("form.matches('[data-admin-upload-automation-token-form]')"), 'Dynamically injected API-key forms must remain delegated.');
assert(source.match(/data-admin-panel-scan-images-form[\s\S]*submitAdminPanelAuxiliaryMutation\(form, event\.submitter/), 'Scan/import must use the shared auxiliary completion path.');
assert(source.match(/data-admin-panel-ai-reprocess-form[\s\S]*submitAdminPanelAuxiliaryMutation\(form, event\.submitter/), 'AI reprocess must use the shared auxiliary completion path.');
assert(source.includes('await completeCoreGalleryMutationInCurrentView(result);'), 'Auxiliary side-panel success must reach the canonical coordinator.');
assert(source.includes("import { setupAdminGalleryImages, coverStarActionForState, applyCoverSelectionPresentation } from './admin-gallery-images.js?v=20261020-shift-range-v1';"), 'The dynamically rendered Images editor interactions must be loaded by the side-panel owner with a cache-busted module URL.');
assert(source.includes("submitter.matches('[data-admin-image-cover-star]')") && source.includes('coverStarActionForState('), 'Side-panel stars must derive cover actions independently of click listener timing.');
assert(source.includes('form.__adminPanelSubmitter = null;'), 'A prior row click must not leak into a later toolbar submission.');
assert(source.includes("form.matches('[data-admin-panel-bulk-form]')") && source.includes('await submitAdminPanelImageBulkForm(form, submitter);'), 'Image row actions must use the document-delegated side-panel bulk workflow after replacement.');
assert(source.includes("/^(delete|cover|cover_arm|cover_add|cover_reset|public|draft|private):(\\d+)$/"), 'Single image row actions must be recognized independently of checkbox selection.');
assert(source.includes("body.append('image_ids[]', rowAction[2]);"), 'A row action must submit only its own image ID to the existing bulk endpoint.');
assert(source.includes("body.set('cover_selection_revision', revision);"), 'Cover writes must carry the revision rendered with the stars.');
assert(source.includes("stateUrl.searchParams.set('cover_state', '1');") && source.includes("cache: 'no-store'"), 'Ambiguous cover writes must reconcile with an uncached authoritative state GET.');
assert(source.includes('result.reconciled !== true'), 'Only the dedicated canonical state-read response may reconcile an uncertain cover write.');
assert(source.includes('applyCoverSelectionPresentation(form, result)') && source.includes('completeCoreGalleryMutationInCurrentView(result)'), 'A changed authoritative reconciliation must update the mounted stars and use the shared public completion coordinator.');
assert(source.includes('form.__adminPanelSubmitter = null;'), 'A prior row click must not leak into a later toolbar submission.');
const coverReconciliationStart = source.indexOf('async function reconcileGalleryCoverAfterAmbiguousFailure(');
const coverReconciliationEnd = source.indexOf('\n}', coverReconciliationStart);
assert(coverReconciliationStart >= 0 && coverReconciliationEnd > coverReconciliationStart
    && !source.slice(coverReconciliationStart, coverReconciliationEnd).includes('.innerHTML'),
    'Cover reconciliation must not replace the panel or editor DOM.');
assert(source.includes("source: 'gallery-image-bulk'") && source.includes('result,') && source.includes('reflectGalleryImageBulkInCurrentView(result)'), 'Image row mutations must preserve the canonical response envelope through the shared side-panel completion coordinator.');
assert(source.includes("form.matches('[data-admin-image-bulk-form]')") && source.includes("submitter.matches('[data-admin-image-cover-star]')"), 'Standalone image editors must intercept only star submits asynchronously.');
assert(source.includes('applyCoverSelectionPresentation(form, result);') && source.includes('writeGalleryCoverStatus(form,'), 'Standalone cover completion must update controls and announce status without replacing the editor.');
assert(source.includes('if (!form.isConnected || !owner.isCurrent())'), 'A late saved cover response must not mutate a stale or replaced editor.');

const completionPath = path.join(projectRoot, 'public/assets/gallery-modules/admin-mutation-completion.js');
const completionSource = await fs.readFile(completionPath, 'utf8');
assert(!/location\.reload\s*\(/.test(completionSource), 'Mutation completion coordinator must not introduce a hard reload fallback.');
assert(!/location\.href\s*=/.test(completionSource), 'Mutation completion coordinator must not introduce a navigation fallback.');
assert(!/history\.replaceState\s*\(/.test(completionSource), 'Mutation completion coordinator must not hide canonical URL mismatches through browser history.');

assert(!/window\.location\.reload\s*\(/.test(source), 'Side-panel-owned enhanced workflows must not hard reload the page.');
assert(!/history\.replaceState\s*\(/.test(source), 'Side-panel completion must preserve the visible browser URL.');

console.log('admin_side_panel_delegation_test: OK');

// Mandatory feature scenarios inherit the existing central regression owner.
await import('./admin_side_panel_delegation/images_preload.mjs');
await import('./admin_side_panel_delegation/cover_stars.mjs');
