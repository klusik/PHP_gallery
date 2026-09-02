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
    publicGalleryPageSource,
    /<section class="hero" data-public-gallery-id="' \. \(int\) \$gallery\['id'\]/,
    'Public gallery hero must expose the current gallery id for pagination-safe refresh ownership.'
);
assert.match(
    adminOperationsSource,
    /admin-side-panel\.js\?v=20260902-mutation-stage4-v1/,
    'admin-operations must cache-bust the changed side-panel module.'
);
assert.match(
    sidePanelSource,
    /admin-browser-upload\.js\?v=20260902-mutation-stage2-v1/,
    'The side-panel module must cache-bust the changed browser-upload module.'
);
assert.match(
    galleryEntrySource,
    /admin-operations\.js\?v=20260902-mutation-stage4-v1/,
    'The gallery entrypoint must cache-bust the changed admin operations module.'
);

console.log('admin_side_panel_created_gallery_refresh_test: PASS');
