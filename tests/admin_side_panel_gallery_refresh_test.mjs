/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_side_panel_gallery_refresh_test.mjs
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the public-gallery Admin side-panel mutation refresh contract.
 *
 * Responsibilities:
 *   - Verify create/edit mutations stay in the delegated side-panel AJAX path
 *   - Verify successful gallery mutations keep the panel open and preserve the URL
 *   - Verify create refreshes reuse the canonical cache-busted background refresh
 *   - Verify fresh server-rendered gallery fragments replace stale background content
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const testDir = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(testDir, '..');
const sidePanelPath = path.join(rootDir, 'public/assets/gallery-modules/admin-side-panel.js');
const operationsPath = path.join(rootDir, 'public/assets/gallery-modules/admin-operations.js');
const createControllerPath = path.join(rootDir, 'app/controllers/admin_galleries_discovery.php');

const sidePanelSource = fs.readFileSync(sidePanelPath, 'utf8');
const operationsSource = fs.readFileSync(operationsPath, 'utf8');
const createControllerSource = fs.readFileSync(createControllerPath, 'utf8');

/**
 * Extract a named function declaration from JavaScript source.
 *
 * The targeted functions intentionally contain no regular-expression literals,
 * so a lightweight balanced-brace scan is sufficient for this focused contract.
 *
 * @param {string} source Complete JavaScript source.
 * @param {string} name Function name.
 * @return {string} Function declaration source.
 */
function extractFunction(source, name) {
    const patterns = [`async function ${name}(`, `function ${name}(`];
    let start = -1;
    for (const pattern of patterns) {
        start = source.indexOf(pattern);
        if (start !== -1) {
            break;
        }
    }
    assert.notEqual(start, -1, `Function ${name} must exist`);

    const braceStart = source.indexOf('{', start);
    assert.notEqual(braceStart, -1, `Function ${name} must have a body`);
    let depth = 0;
    let quote = '';
    let escaped = false;
    for (let index = braceStart; index < source.length; index++) {
        const character = source[index];
        if (quote !== '') {
            if (escaped) {
                escaped = false;
                continue;
            }
            if (character === '\\') {
                escaped = true;
                continue;
            }
            if (character === quote) {
                quote = '';
            }
            continue;
        }
        if (character === '"' || character === "'" || character === '`') {
            quote = character;
            continue;
        }
        if (character === '{') {
            depth++;
        } else if (character === '}') {
            depth--;
            if (depth === 0) {
                return source.slice(start, index + 1);
            }
        }
    }
    assert.fail(`Function ${name} body is not balanced`);
}

/**
 * Evaluate one extracted function with explicit dependency stubs.
 *
 * @param {string} declaration Function declaration source.
 * @param {Record<string, *>} context Globals visible to the function.
 * @return {Function} Evaluated function.
 */
function evaluateFunction(declaration, context = {}) {
    return vm.runInNewContext(`(${declaration})`, context);
}

// The server-side create route must persist first, then return the existing JSON
// result contract consumed by the browser side-panel workflow.
assert.match(createControllerSource, /\$gallery\s*=\s*admin_create_gallery_from_input\(\$_POST\);/);
assert.match(createControllerSource, /if \(admin_wants_json\(\)\)[\s\S]*admin_new_gallery_success_response\(\$gallery\)/);
assert.match(createControllerSource, /'gallery_id'\s*=>\s*\(int\) \$gallery\['id'\]/);
assert.match(createControllerSource, /'refresh_url'\s*=>\s*\$parentGalleryUrl !== '' \? \$parentGalleryUrl : url_for\('home'\)/);

// Create and edit submissions are delegated at document level, so forms injected
// by a later panel refresh are intercepted without rebinding the whole page.
assert.match(sidePanelSource, /document\.addEventListener\('submit', async \(event\) => \{[\s\S]*?\[data-gallery-panel-create-form\][\s\S]*?submitAdminGalleryPanelCreateForm\(form\)/);
assert.match(sidePanelSource, /document\.addEventListener\('submit', async \(event\) => \{[\s\S]*?\[data-admin-panel-edit-form\][\s\S]*?submitAdminPanelEditForm\(form\)/);
assert.match(sidePanelSource, /body\.innerHTML = sidePanelContentFromHtml\(html, workflow\);[\s\S]*prepareAdminSidePanelLoadedContent\(body, workflow,/);

// Bind the real delegated setup once, then create form objects afterwards to model
// controls arriving through a later body.innerHTML panel refresh. Both fresh forms
// must still be intercepted without another setupAdminGallerySidePanel() call.
class DelegatedElement {}
class DelegatedHtmlElement extends DelegatedElement {}
class DelegatedAnchorElement extends DelegatedHtmlElement {}
class DelegatedFormElement extends DelegatedHtmlElement {
    constructor(selector) {
        super();
        this.selector = selector;
    }

    matches(selector) {
        return selector === this.selector;
    }

    closest() {
        return null;
    }
}
const delegatedListeners = new Map();
const delegatedDocument = {
    body: new DelegatedHtmlElement(),
    addEventListener: (type, callback) => {
        const callbacks = delegatedListeners.get(type) || [];
        callbacks.push(callback);
        delegatedListeners.set(type, callbacks);
    },
};
delegatedDocument.body.dataset = {};
let delegatedCreateCalls = 0;
let delegatedEditCalls = 0;
const setupDelegatedSidePanel = evaluateFunction(
    extractFunction(sidePanelSource, 'setupAdminGallerySidePanel').replace(/^export\s+/, ''),
    {
        document: delegatedDocument,
        Element: DelegatedElement,
        HTMLElement: DelegatedHtmlElement,
        HTMLAnchorElement: DelegatedAnchorElement,
        HTMLFormElement: DelegatedFormElement,
        submitAdminGalleryPanelCreateForm: async () => {
            delegatedCreateCalls++;
        },
        submitAdminPanelEditForm: async () => {
            delegatedEditCalls++;
        },
    },
);
setupDelegatedSidePanel();
assert.equal(delegatedDocument.body.dataset.adminGallerySidePanelBound, '1');

async function dispatchDelegatedSubmit(form) {
    const event = {
        target: form,
        defaultPrevented: false,
        submitter: null,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {},
        stopImmediatePropagation() {},
    };
    for (const callback of delegatedListeners.get('submit') || []) {
        await callback(event);
    }
    return event;
}

const dynamicallyRenderedCreateForm = new DelegatedFormElement('[data-gallery-panel-create-form]');
const createEvent = await dispatchDelegatedSubmit(dynamicallyRenderedCreateForm);
assert.equal(createEvent.defaultPrevented, true, 'Dynamically rendered create form must be intercepted');
assert.equal(delegatedCreateCalls, 1, 'Dynamically rendered create form must use the AJAX create workflow');

const dynamicallyRenderedEditForm = new DelegatedFormElement('[data-admin-panel-edit-form]');
const editEvent = await dispatchDelegatedSubmit(dynamicallyRenderedEditForm);
assert.equal(editEvent.defaultPrevented, true, 'Dynamically rendered edit form must be intercepted');
assert.equal(delegatedEditCalls, 1, 'Dynamically rendered edit form must use the AJAX edit workflow');

// Gallery mutations are explicitly panel-persistent. Other editors keep their
// previous completion behavior and are intentionally outside this fix.
const keepsPanelOpen = evaluateFunction(extractFunction(sidePanelSource, 'adminSidePanelMutationKeepsPanelOpen'));
for (const source of ['create', 'gallery-edit', 'gallery-image-bulk', 'upload']) {
    assert.equal(keepsPanelOpen(source), true, `${source} must keep the panel open`);
}
for (const source of ['image-edit', 'tag-edit', '']) {
    assert.equal(keepsPanelOpen(source), false, `${source || 'empty source'} must retain existing close behavior`);
}
assert.match(sidePanelSource, /const shouldKeepPanelOpen = adminSidePanelMutationKeepsPanelOpen\(source\);[\s\S]*if \(panel instanceof HTMLElement && !shouldKeepPanelOpen\) \{\s*closeAdminGallerySidePanel\(panel\);/);

// Create refresh must call the canonical context refresher, which already owns
// cache busting, no-store semantics, fragment replacement, and lifecycle rebinding.
const canonicalRefreshSource = extractFunction(sidePanelSource, 'refreshCurrentGalleryContextFromServer');
assert.match(canonicalRefreshSource, /searchParams\.set\('_panel_refresh', String\(Date\.now\(\)\)\)/);
assert.match(canonicalRefreshSource, /cache:\s*'no-store'/);
assert.match(canonicalRefreshSource, /replacePublicGalleryFragmentsFromParsedDocument\(parsed\)/);
assert.doesNotMatch(canonicalRefreshSource, /window\.location\.(?:href\s*=|reload\s*\()/);

let refreshedUrl = '';
let focusedGalleryId = '';
const refreshCreatedSection = evaluateFunction(
    extractFunction(sidePanelSource, 'refreshPublicSubgallerySectionFromServer'),
    {
        currentVisiblePageRefreshUrl: () => 'https://example.test/gallery/parent/3/',
        refreshCurrentGalleryContextFromServer: async (url) => {
            refreshedUrl = url;
            return true;
        },
        publicSubgalleryContainsGalleryId: () => true,
        waitForGalleryMutationRefreshRetry: async () => {},
        focusCreatedGalleryCard: (galleryId) => {
            focusedGalleryId = galleryId;
        },
    },
);
assert.equal(await refreshCreatedSection('77'), true);
assert.equal(refreshedUrl, 'https://example.test/gallery/parent/3/');
assert.equal(focusedGalleryId, '77');

focusedGalleryId = '';
const failedRefreshCreatedSection = evaluateFunction(
    extractFunction(sidePanelSource, 'refreshPublicSubgallerySectionFromServer'),
    {
        currentVisiblePageRefreshUrl: () => 'https://example.test/gallery/parent/3/',
        refreshCurrentGalleryContextFromServer: async () => false,
        publicSubgalleryContainsGalleryId: () => false,
        waitForGalleryMutationRefreshRetry: async () => {},
        focusCreatedGalleryCard: (galleryId) => {
            focusedGalleryId = galleryId;
        },
    },
);
assert.equal(await failedRefreshCreatedSection('77'), false);
assert.equal(focusedGalleryId, '', 'Failed refresh must not pretend the created card was found');

// Model the canonical public fragment replacement with a stale live section and
// a fresh server section that contains the newly created/edited gallery card.
class FakeElement {
    constructor(label, liveMap = null) {
        this.label = label;
        this.liveMap = liveMap;
        this.galleryIds = [];
    }

    replaceWith(fresh) {
        if (!this.liveMap) {
            return;
        }
        for (const [selector, value] of this.liveMap.entries()) {
            if (value === this) {
                this.liveMap.set(selector, fresh);
            }
        }
    }

    remove() {
        if (!this.liveMap) {
            return;
        }
        for (const [selector, value] of this.liveMap.entries()) {
            if (value === this) {
                this.liveMap.set(selector, null);
            }
        }
    }
}

const liveMap = new Map();
const staleSection = new FakeElement('stale subgallery section', liveMap);
staleSection.galleryIds = ['12'];
const freshSection = new FakeElement('fresh subgallery section');
freshSection.galleryIds = ['12', '77'];
liveMap.set('.hero', null);
liveMap.set('[data-back-to-top-scope]', null);
liveMap.set('[data-public-subgallery-section]', staleSection);
liveMap.set('[data-gallery-image-list]', null);
const freshMap = new Map([
    ['.hero', null],
    ['[data-back-to-top-scope]', null],
    ['[data-public-subgallery-section]', freshSection],
    ['[data-gallery-image-list]', null],
]);
let teardownCount = 0;
let replacementEventCount = 0;
const replacePublicFragments = evaluateFunction(
    extractFunction(sidePanelSource, 'replacePublicGalleryFragmentsFromParsedDocument'),
    {
        HTMLElement: FakeElement,
        document: {
            querySelector: (selector) => liveMap.get(selector) ?? null,
            dispatchEvent: () => {
                replacementEventCount++;
            },
        },
        CustomEvent: class CustomEvent {},
        replacePublicGalleryFrame: () => false,
        teardownPublicGalleryLifecycleBeforeRefresh: () => {
            teardownCount++;
        },
    },
);
const parsedDocument = {
    querySelector: (selector) => freshMap.get(selector) ?? null,
};
assert.equal(replacePublicFragments(parsedDocument), true);
assert.equal(liveMap.get('[data-public-subgallery-section]'), freshSection);
assert.deepEqual(liveMap.get('[data-public-subgallery-section]').galleryIds, ['12', '77']);
assert.equal(teardownCount, 1);
assert.equal(replacementEventCount, 1);

// The gallery mutation completion path must not rewrite or reload the browser URL.
for (const functionName of [
    'submitAdminGalleryPanelCreateForm',
    'reflectCreatedGalleryInCurrentView',
    'reflectSavedGalleryInCurrentView',
    'refreshPublicSubgallerySectionFromServer',
    'refreshCurrentGalleryContextFromServer',
]) {
    const functionSource = extractFunction(sidePanelSource, functionName);
    assert.doesNotMatch(functionSource, /window\.location\.(?:href\s*=|reload\s*\()/, `${functionName} must not navigate or reload`);
    assert.doesNotMatch(functionSource, /history\.(?:pushState|replaceState)\s*\(/, `${functionName} must not rewrite the URL`);
}

// Changing the side-panel module must also change its import cache key.
assert.match(operationsSource, /admin-side-panel\.js\?v=20260902-gallery-created-refresh-v2/);

console.log('PASS admin_side_panel_gallery_refresh_test');
