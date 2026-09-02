import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const sidePanelSource = fs.readFileSync('public/assets/gallery-modules/admin-side-panel.js', 'utf8');
const browserUploadSource = fs.readFileSync('public/assets/gallery-modules/admin-browser-upload.js', 'utf8');
const adminOperationsSource = fs.readFileSync('public/assets/gallery-modules/admin-operations.js', 'utf8');
const galleryEntrySource = fs.readFileSync('public/assets/gallery.js', 'utf8');
const publicGalleryPageSource = fs.readFileSync('app/controllers/public_gallery_page.php', 'utf8');
const adminUploadsSource = fs.readFileSync('app/controllers/admin_uploads.php', 'utf8');

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

assert.match(
    adminUploadsSource,
    /'created_gallery'\s*=>\s*\$mode\s*===\s*'new'/,
    'The server upload response must identify create-and-upload mutations.'
);

assert.match(
    sidePanelSource,
    /created_gallery:\s*Boolean\(emptyResult\.created_gallery\)/,
    'Empty create-and-upload must preserve created_gallery in the classic upload result.'
);
assert.match(
    sidePanelSource,
    /createdGallery\s*=\s*createdGallery\s*\|\|\s*Boolean\(uploadResult\.created_gallery\)/,
    'Per-file classic upload aggregation must preserve created_gallery.'
);
assert.match(
    sidePanelSource,
    /if \(Boolean\(result\.created_gallery\)\) \{\s*await reflectCreatedGalleryInCurrentView\(result\);\s*return;/s,
    'Created upload workflows must use the created-gallery background refresh path.'
);
assert.match(
    sidePanelSource,
    /return \['create', 'gallery-edit', 'gallery-image-bulk', 'upload'\]\.includes\(source\);/,
    'Create-and-upload completion must keep the side panel open.'
);
assert.match(
    sidePanelSource,
    /const backgroundRefreshUrl = currentPageOwnsCreatedGallery \? currentVisiblePageRefreshUrl\(\) : refreshUrl;/,
    'A child created for the visible gallery must refresh the exact current URL, not only the canonical parent URL.'
);
assert.match(
    sidePanelSource,
    /const retryDelaysMs = \[0, 120, 320\];/,
    'Created-gallery refresh must use bounded retries.'
);
assert.match(
    sidePanelSource,
    /if \(publicSubgalleryContainsGalleryId\(galleryId\)\)/,
    'A fragment replacement is successful only after the new gallery card is actually visible.'
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

class FakeHTMLElement {
    constructor(dataset = {}, querySelector = () => null) {
        this.dataset = dataset;
        this._querySelector = querySelector;
    }
    querySelector(selector) {
        return this._querySelector(selector);
    }
}

let hero = new FakeHTMLElement({publicGalleryId: '42'});
let gridGalleryIds = new Set(['100']);
const sidePanelFns = loadFunctions(
    sidePanelSource,
    ['currentPublicGalleryId', 'createdGalleryBelongsToCurrentPublicPage', 'publicSubgalleryContainsGalleryId'],
    {
        HTMLElement: FakeHTMLElement,
        document: {
            querySelector(selector) {
                if (selector === '.hero[data-public-gallery-id]') return hero;
                if (selector === '[data-public-subgallery-grid]') {
                    return new FakeHTMLElement({}, (childSelector) => {
                        const match = childSelector.match(/data-gallery-id="([^"]+)"/);
                        return match && gridGalleryIds.has(match[1]) ? new FakeHTMLElement() : null;
                    });
                }
                return null;
            },
        },
        CSS: {escape: (value) => String(value)},
    }
);
assert.equal(sidePanelFns.currentPublicGalleryId(), 42);
assert.equal(sidePanelFns.createdGalleryBelongsToCurrentPublicPage({parent_gallery_id: 42}), true);
assert.equal(sidePanelFns.createdGalleryBelongsToCurrentPublicPage({parent_gallery_id: 7}), false);
assert.equal(sidePanelFns.publicSubgalleryContainsGalleryId('100'), true);
assert.equal(sidePanelFns.publicSubgalleryContainsGalleryId('101'), false);

let refreshAttempts = 0;
let focusedGalleryId = '';
const retryGridGalleryIds = new Set();
const refreshFns = loadFunctions(
    sidePanelSource,
    ['refreshPublicSubgallerySectionFromServer', 'publicSubgalleryContainsGalleryId', 'waitForGalleryMutationRefreshRetry'],
    {
        HTMLElement: FakeHTMLElement,
        CSS: {escape: (value) => String(value)},
        window: {setTimeout: (callback) => callback()},
        document: {
            querySelector(selector) {
                if (selector !== '[data-public-subgallery-grid]') return null;
                return new FakeHTMLElement({}, (childSelector) => {
                    const match = childSelector.match(/data-gallery-id="([^"]+)"/);
                    return match && retryGridGalleryIds.has(match[1]) ? new FakeHTMLElement() : null;
                });
            },
        },
        currentVisiblePageRefreshUrl: () => 'https://example.test/gallery/parent/2/?photo_page=3',
        refreshCurrentGalleryContextFromServer: async (sourceUrl) => {
            refreshAttempts++;
            assert.equal(sourceUrl, 'https://example.test/gallery/parent/2/?photo_page=3');
            if (refreshAttempts === 2) retryGridGalleryIds.add('101');
            return true;
        },
        focusCreatedGalleryCard: (galleryId) => { focusedGalleryId = String(galleryId); },
    }
);
assert.equal(
    await refreshFns.refreshPublicSubgallerySectionFromServer('101', 'https://example.test/gallery/parent/2/?photo_page=3'),
    true,
    'A stale first fragment must be retried until the created gallery is present.'
);
assert.equal(refreshAttempts, 2, 'The refresh should stop immediately after the created card appears.');
assert.equal(focusedGalleryId, '101', 'The created card should be focused only after the DOM postcondition succeeds.');

assert.match(
    publicGalleryPageSource,
    /<section class="hero" data-public-gallery-id="' \. \(int\) \$gallery\['id'\] \. '">/,
    'Public gallery hero must expose the current gallery id for pagination-safe refresh ownership.'
);
assert.match(
    adminOperationsSource,
    /admin-side-panel\.js\?v=20260902-gallery-created-refresh-v2/,
    'admin-operations must cache-bust the changed side-panel module.'
);
assert.match(
    sidePanelSource,
    /admin-browser-upload\.js\?v=20260902-gallery-created-refresh-v2/,
    'The side-panel module must cache-bust the changed browser-upload module.'
);
assert.match(
    galleryEntrySource,
    /admin-operations\.js\?v=20260902-gallery-created-refresh-v2/,
    'The gallery entrypoint must cache-bust the changed admin operations module.'
);

console.log('admin_side_panel_created_gallery_refresh_test: PASS');
