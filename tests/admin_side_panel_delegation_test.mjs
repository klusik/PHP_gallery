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

assert(source.includes("document.addEventListener('submit', async (event) => {"), 'Side-panel forms must be intercepted through document-level delegated submit handlers.');
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

const completionPath = path.join(projectRoot, 'public/assets/gallery-modules/admin-mutation-completion.js');
const completionSource = await fs.readFile(completionPath, 'utf8');
assert(!/location\.reload\s*\(/.test(completionSource), 'Mutation completion coordinator must not introduce a hard reload fallback.');
assert(!/location\.href\s*=/.test(completionSource), 'Mutation completion coordinator must not introduce a navigation fallback.');
assert(!/history\.replaceState\s*\(/.test(completionSource), 'Mutation completion coordinator must not hide canonical URL mismatches through browser history.');

assert(!/window\.location\.reload\s*\(/.test(source), 'Side-panel-owned enhanced workflows must not hard reload the page.');
assert(!/history\.replaceState\s*\(/.test(source), 'Side-panel completion must preserve the visible browser URL.');

console.log('admin_side_panel_delegation_test: OK');
