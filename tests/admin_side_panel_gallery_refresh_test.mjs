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
 * The targeted functions intentionally contain no regular-expression literals.
 * Ignore line/block comments before interpreting quotes or braces: callback
 * docstrings can contain type braces and apostrophes that are not JavaScript.
 * Quoted literals retain their contents, including apparent comment delimiters.
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
    let comment = '';
    for (let index = braceStart; index < source.length; index++) {
        const character = source[index];
        const nextCharacter = source[index + 1];
        if (comment === 'line') {
            if (character === '\n' || character === '\r') comment = '';
            continue;
        }
        if (comment === 'block') {
            if (character === '*' && nextCharacter === '/') {
                comment = '';
                index++;
            }
            continue;
        }
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
        if (character === '/' && (nextCharacter === '/' || nextCharacter === '*')) {
            comment = nextCharacter === '/' ? 'line' : 'block';
            index++;
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

// A callback docstring's unmatched punctuation is not executable syntax. Keep
// literal comment markers intact, and stop before the next function declaration.
const commentBraceFixture = [
    'function documentedCallback() {',
    "    const callback = /** The owner's { unmatched type/quote text. */ () => '/* literal */ // literal';",
    "    // Do not count this callback's unbalanced } or quote.",
    '    return callback();',
    '}',
    'function unrelatedFunction() {}',
].join('\n');
const extractedCommentFixture = extractFunction(commentBraceFixture, 'documentedCallback');
assert.equal(extractedCommentFixture, commentBraceFixture.slice(0, commentBraceFixture.indexOf('\nfunction unrelatedFunction')));
assert.equal(evaluateFunction(extractedCommentFixture)(), '/* literal */ // literal');

// Claim before target creation; a replay must select the original response, while
// new work records the canonical result durably before either transport sends it.
assert.match(createControllerSource, /admin_operation_begin\([\s\S]*?if \(!empty\(\$operationClaim\['replay'\]\)\) \{[\s\S]*?\$response = \$operationClaim\['response'\];[\s\S]*?\} else \{[\s\S]*?admin_create_gallery_from_input\(\$input\)/);
assert.match(createControllerSource, /admin_operation_complete\(\$operationClaim, admin_new_gallery_success_response\(\$gallery\)\);[\s\S]*?if \(admin_wants_json\(\)\)[\s\S]*?echo json_encode\(\$response\)/);
assert.match(createControllerSource, /'gallery_id'\s*=>\s*\(int\) \$gallery\['id'\]/);
assert.match(createControllerSource, /admin_mutation_public_gallery_context\([\s\S]*admin_mutation_gallery_membership_postcondition\(/);

// Create and edit submissions are delegated at document level, so forms injected
// by a later panel refresh are intercepted without rebinding the whole page.
assert.match(sidePanelSource, /document\.addEventListener\('submit', (?:\/\*[\s\S]*?\*\/\s*)?async \(event\) => \{[\s\S]*?\[data-gallery-panel-create-form\][\s\S]*?submitAdminGalleryPanelCreateForm\(form\)/);
assert.match(sidePanelSource, /document\.addEventListener\('submit', (?:\/\*[\s\S]*?\*\/\s*)?async \(event\) => \{[\s\S]*?\[data-admin-panel-edit-form\][\s\S]*?submitAdminPanelEditForm\(form,/);
assert.match(sidePanelSource, /const content = sidePanelContentFromHtml\(html, workflow\);[\s\S]*if \(!owner\.isCurrent\(\)\) return;[\s\S]*body\.innerHTML = content;[\s\S]*prepareAdminSidePanelLoadedContent\(body, workflow,/);

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
for (const source of ['create', 'gallery-edit', 'gallery-image-bulk', 'image-edit', 'tag-edit', 'upload']) {
    assert.equal(keepsPanelOpen(source), true, `${source} must keep the panel open`);
}
for (const source of ['']) {
    assert.equal(keepsPanelOpen(source), false, `${source || 'empty source'} must retain existing close behavior`);
}
assert.match(sidePanelSource, /const shouldKeepPanelOpen = adminSidePanelMutationKeepsPanelOpen\(source\);[\s\S]*if \(panel instanceof HTMLElement && !shouldKeepPanelOpen\) \{\s*closeAdminGallerySidePanel\(panel\);/);

// Gallery refresh delegates fetch/cache semantics and owned replacement to the
// canonical coordinator rather than duplicating them in this module.
const canonicalRefreshStart = sidePanelSource.indexOf('async function completeCoreGalleryMutationInCurrentView');
const canonicalRefreshEnd = sidePanelSource.indexOf('\n/**', canonicalRefreshStart);
assert.notEqual(canonicalRefreshStart, -1, 'Canonical gallery mutation completion function must exist.');
const canonicalRefreshSource = sidePanelSource.slice(canonicalRefreshStart, canonicalRefreshEnd);
assert.match(canonicalRefreshSource, /completeAdminMutation\(result, \{/);
assert.match(canonicalRefreshSource, /replaceOwnedPublicGalleryFragments\(parsed,/);
assert.doesNotMatch(canonicalRefreshSource, /window\.location\.(?:href\s*=|reload\s*\()/);

// The gallery mutation completion path must not rewrite or reload the browser URL.
for (const functionName of [
    'submitAdminGalleryPanelCreateForm',
    'reflectSavedGalleryInCurrentView',
]) {
    const functionSource = extractFunction(sidePanelSource, functionName);
    assert.doesNotMatch(functionSource, /window\.location\.(?:href\s*=|reload\s*\()/, `${functionName} must not navigate or reload`);
    assert.doesNotMatch(functionSource, /history\.(?:pushState|replaceState)\s*\(/, `${functionName} must not rewrite the URL`);
}
assert.doesNotMatch(canonicalRefreshSource, /window\.location\.(?:href\s*=|reload\s*\()/, 'completeCoreGalleryMutationInCurrentView must not navigate or reload');
assert.doesNotMatch(canonicalRefreshSource, /history\.(?:pushState|replaceState)\s*\(/, 'completeCoreGalleryMutationInCurrentView must not rewrite the URL');

// Changing the side-panel module must also change its import cache key.
assert.match(operationsSource, /admin-side-panel\.js\?v=20260920-panel-lifecycle-v1/);

console.log('PASS admin_side_panel_gallery_refresh_test');
