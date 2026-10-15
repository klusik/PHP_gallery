/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Verify public refresh after panel-driven gallery creation.
 * Responsibilities:
 *   - Check that successful creation refreshes the affected context in place.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_side_panel_created_gallery_refresh_test.mjs
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
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {browserEvaluationResult, browserFailureCategory} from './support/headless_browser_fixture.mjs';

const sidePanelSource = fs.readFileSync('public/assets/gallery-modules/admin-side-panel.js', 'utf8');
const browserUploadSource = fs.readFileSync('public/assets/gallery-modules/admin-browser-upload.js', 'utf8');
const adminOperationsSource = fs.readFileSync('public/assets/gallery-modules/admin-operations.js', 'utf8');
const galleryEntrySource = fs.readFileSync('public/assets/gallery.js', 'utf8');
const publicGalleryPageSource = fs.readFileSync('app/controllers/public_gallery_page.php', 'utf8');
const publicGalleryPagesViewSource = fs.readFileSync('app/views/public_gallery_pages.php', 'utf8');
const adminUploadsSource = fs.readFileSync('app/controllers/admin_uploads.php', 'utf8');

// Deliberately induced inert failures exercise the actual shared diagnostics;
// they do not convert a failing required browser fixture into a passing result.
const missingBrowser = Object.assign(new Error('spawn missing fixture'), {code: 'ENOENT'});
assert.equal(browserFailureCategory(missingBrowser, 'startup'), 'dependency');
assert.equal(browserFailureCategory(new Error('Owned debugging endpoint unavailable'), 'startup'), 'startup');
assert.equal(browserFailureCategory(Object.assign(new Error('DevTools refused input'), {protocolCode: -32601}), 'protocol'), 'protocol');
assert.equal(browserFailureCategory(new Error('Owned Chromium protocol timeout during input'), 'protocol'), 'protocol-timeout');
assert.equal(browserFailureCategory(new Error('Fixture deadline expired'), 'result'), 'timeout');
assert.equal(browserEvaluationResult({result: {value: false}}), false, 'False page values are successful evaluations, not infrastructure failures.');
assert.throws(() => browserEvaluationResult({exceptionDetails: {text: 'Uncaught',
    exception: {description: 'Error: expected retained shell\nunsafe URL is excluded'},
    stackTrace: {callFrames: [{functionName: 'assertOwnedShell', lineNumber: 8, columnNumber: 4}]}}}), error => {
    assert.equal(browserFailureCategory(error, 'application'), 'application');
    assert.match(error.message, /expected retained shell/);
    assert.match(error.stack, /assertOwnedShell.*owned-fixture:9:5/);
    assert.doesNotMatch(error.stack, /unsafe URL/);
    return true;
}, 'A page assertion must retain its concrete reason and original page stack frame.');

function extractFunction(source, name) {
    const asyncMarker = `async function ${name}(`;
    const marker = `function ${name}(`;
    const asyncStart = source.indexOf(asyncMarker);
    const start = asyncStart !== -1 ? asyncStart : source.indexOf(marker);
    assert.notEqual(start, -1, `Missing function ${name}`);
    const bodyStart = source.indexOf('{', start);
    assert.notEqual(bodyStart, -1, `Missing body for ${name}`);

    let depth = 0;
    let quote = '';
    let escaped = false;
    let lineComment = false;
    let blockComment = false;
    let templateExpressionDepth = 0;

    for (let index = bodyStart; index < source.length; index++) {
        const char = source[index];
        const next = source[index + 1] || '';

        if (lineComment) {
            if (char === '\n') lineComment = false;
            continue;
        }
        if (blockComment) {
            if (char === '*' && next === '/') {
                blockComment = false;
                index++;
            }
            continue;
        }
        if (quote) {
            if (escaped) {
                escaped = false;
                continue;
            }
            if (char === '\\') {
                escaped = true;
                continue;
            }
            if (quote === '`' && char === '$' && next === '{') {
                templateExpressionDepth++;
                index++;
                continue;
            }
            if (quote === '`' && templateExpressionDepth > 0) {
                if (char === '{') templateExpressionDepth++;
                if (char === '}') templateExpressionDepth--;
                continue;
            }
            if (char === quote) quote = '';
            continue;
        }
        if (char === '/' && next === '/') {
            lineComment = true;
            index++;
            continue;
        }
        if (char === '/' && next === '*') {
            blockComment = true;
            index++;
            continue;
        }
        if (char === '"' || char === "'" || char === '`') {
            quote = char;
            continue;
        }
        if (char === '{') depth++;
        if (char === '}') {
            depth--;
            if (depth === 0) return source.slice(start, index + 1);
        }
    }
    throw new Error(`Unterminated function ${name}`);
}

function loadFunctions(source, names, context = {}) {
    const sandbox = {...context};
    vm.createContext(sandbox);
    const declarations = names.map((name) => extractFunction(source, name)).join('\n');
    const exports = names.map((name) => `globalThis.${name} = ${name};`).join('\n');
    vm.runInContext(`${declarations}\n${exports}`, sandbox);
    return sandbox;
}

const workflowSource = fs.readFileSync('tests/support/gallery_workflow_browser.js', 'utf8');
const workflowFailures = loadFunctions(workflowSource, ['workflowObservedState', 'workflowFailureDetails'], {
    assertions: 7, lastHttpStatus: 404, diagnosticDocument: null,
    workflowStarted: 10, performance: {now: () => 60},
});
const inducedAssertion = {message: 'Expected owned card', fixtureCategory: 'assertion',
    stack: 'Error: Expected owned card\n at assertCard (http://127.0.0.1:12345/__workflow_private?token=secret:9:4)'};
const assertionDetails = workflowFailures.workflowFailureDetails(inducedAssertion);
assert.equal(assertionDetails.category, 'assertion');
assert.equal(assertionDetails.reason, 'Expected owned card');
assert.match(assertionDetails.stack, /assertCard.*owned-fixture:9:4/);
assert.doesNotMatch(JSON.stringify(assertionDetails), /secret|private|127\.0\.0\.1/);
assert.equal(assertionDetails.observed.lastHttpStatus, 404);
assert.equal(assertionDetails.observed.elapsedMs, 50);
assert.equal(workflowFailures.workflowFailureDetails({message: 'Expected panel replacement', fixtureCategory: 'state-timeout'}).category,
    'state-timeout', 'A passive state deadline must remain distinct from an assertion mismatch.');
assert.equal(workflowFailures.workflowFailureDetails({name: 'SyntaxError', message: 'Invalid synthetic JSON'}).category,
    'json', 'JSON failure classification must work across iframe realms.');

assert.match(
    adminUploadsSource,
    /'created_gallery'\s*=>\s*\$mode\s*===\s*'new'/,
    'The server upload response must identify create-and-upload mutations.'
);

assert.match(
    sidePanelSource,
    /return \{\s*\.\.\.emptyResult,[\s\S]*created_gallery/,
    'Empty create-and-upload must preserve the canonical server result, including created_gallery.'
);
assert.match(
    sidePanelSource,
    /const resultCreatedGallery = Boolean\(uploadResult\.created_gallery\);[\s\S]*createdGallery = createdGallery \|\| resultCreatedGallery;/,
    'Per-file classic upload aggregation must preserve created_gallery.'
);
assert.match(
    sidePanelSource,
    /return \['create', 'gallery-edit', 'gallery-image-bulk', 'image-edit', 'tag-edit', 'upload'\]\.includes\(source\);/,
    'Persistent editor and create/upload completion must keep the side panel open.'
);
assert.match(
    sidePanelSource,
    /completeAdminMutation\(result, \{/,
    'Created upload workflows must converge on the canonical completion coordinator.'
);

const browserFns = loadFunctions(browserUploadSource, ['emptyBrowserAggregate', 'mergeBrowserResult']);
const aggregateFromCreate = browserFns.emptyBrowserAggregate({
    created_gallery: true,
    gallery_title: 'Child',
    gallery_url: '/gallery/child/',
    parent_gallery_id: 42,
    refresh_gallery_id: 42,
    refresh_url: '/gallery/parent/',
}, 100, 1);
assert.equal(aggregateFromCreate.created_gallery, true, 'Browser upload seed must retain created_gallery.');
browserFns.mergeBrowserResult(aggregateFromCreate, {created_gallery: false, uploaded: 1});
assert.equal(aggregateFromCreate.created_gallery, true, 'Later batch responses must not erase created_gallery.');

const aggregateFromExisting = browserFns.emptyBrowserAggregate(null, 100, 1);
browserFns.mergeBrowserResult(aggregateFromExisting, {created_gallery: true});
assert.equal(aggregateFromExisting.created_gallery, true, 'A batch response can promote the aggregate to created_gallery.');

assert.match(
    publicGalleryPagesViewSource,
    /<section class="hero" data-public-gallery-id="' \. \(int\) \(\$viewModel\['gallery_id'\] \?\? 0\)/,
    'Public gallery hero view must expose the controller-prepared gallery id for pagination-safe refresh ownership.'
);
assert.match(
    publicGalleryPageSource,
    /'gallery_id'\s*=>\s*\(int\) \$gallery\['id'\]/,
    'Public gallery controller must prepare gallery_id for the hero view model.'
);
assert.match(
    adminOperationsSource,
    /admin-side-panel\.js\?v=[^'"\s]+/,
    'admin-operations must cache-bust the changed side-panel module.'
);
assert.match(
    sidePanelSource,
    /admin-browser-upload\.js\?v=[^'"\s]+/,
    'The side-panel module must cache-bust the changed browser-upload module.'
);
assert.match(
    galleryEntrySource,
    /admin-operations\.js\?v=[^'"\s]+/,
    'The gallery entrypoint must cache-bust the changed admin operations module.'
);

console.log('admin_side_panel_created_gallery_refresh_test: PASS');
