import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const modulePath = path.join(projectRoot, 'public/assets/gallery-modules/admin-mutation-completion.js');
const source = await fs.readFile(modulePath, 'utf8');
const moduleUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const mutation = await import(moduleUrl);

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

function galleryRoot(galleryId, cards = [], images = []) {
    const cardIds = new Set(cards.map(Number));
    const imageIds = new Set(images.map(Number));
    const grid = {
        querySelector(selector) {
            const cardMatch = selector.match(/^\[data-gallery-id="(\d+)"\]$/);
            return cardMatch && cardIds.has(Number(cardMatch[1])) ? {} : null;
        },
    };
    return {
        querySelector(selector) {
            if (selector === '.hero[data-public-gallery-id]') {
                return galleryId > 0 ? {dataset: {publicGalleryId: String(galleryId)}} : null;
            }
            if (selector === '[data-public-subgallery-grid]') {
                return galleryId > 0 ? grid : null;
            }
            if (selector === '.public-home-gallery-grid') {
                return galleryId > 0 ? null : grid;
            }
            if (selector === '[data-gallery-image-list]') {
                return {
                    querySelector(imageSelector) {
                        const orderMatch = imageSelector.match(/^\[data-public-order-id="(\d+)"\]$/);
                        return orderMatch && imageIds.has(Number(orderMatch[1])) ? {} : null;
                    },
                };
            }
            return null;
        },
    };
}

let legacyRejected = false;
try {
    mutation.normalizeAdminMutationEnvelope({
        ok: true,
        gallery_id: 123,
        parent_gallery_id: 55,
        gallery_url: '/gallery/parent/new/',
        edit_url: '/admin/edit?id=123',
        refresh_url: '/gallery/parent/',
    }, 'create');
} catch (error) {
    legacyRejected = error instanceof TypeError
        && error.message.includes('canonical completion envelope');
}
assert(legacyRejected, 'Legacy mutation responses without the canonical completion envelope must be rejected.');

const currentRoot = galleryRoot(55, []);
const context = {
    type: 'gallery',
    gallery_id: 55,
    render_url: 'https://example.test/gallery/renamed-parent/',
    render_mode: 'preserve_view',
    postcondition: {type: 'gallery_present', gallery_id: 123},
};
assert(
    mutation.mutationContextMatchesCurrentDocument(context, currentRoot, 'https://example.test/gallery/old-parent/2/', 'https://example.test/'),
    'Stable gallery identity must match even when render and visible URLs differ.'
);
assert(
    mutation.resolveAdminMutationContextRenderUrl(context, 'https://example.test/gallery/old-parent/2/') === 'https://example.test/gallery/old-parent/2/',
    'Normal context refresh must preserve the visible pagination/path state.'
);
assert(
    mutation.resolveAdminMutationContextRenderUrl({...context, render_mode: 'canonical'}, 'https://example.test/gallery/old-parent/2/') === 'https://example.test/gallery/renamed-parent/',
    'Canonical render mode must use the server-returned render URL for rename/move migrations.'
);
assert(!mutation.mutationContextMatchesCurrentDocument({...context, gallery_id: 56}, currentRoot, 'https://example.test/gallery/old-parent/', 'https://example.test/'), 'Different stable gallery id must not match only because URLs are similar.');

assert(mutation.evaluateAdminMutationPostcondition({type: 'gallery_present', gallery_id: 123}, galleryRoot(55, [123])) === true, 'gallery_present postcondition must detect the expected card.');
assert(mutation.evaluateAdminMutationPostcondition({type: 'gallery_absent', gallery_id: 123}, galleryRoot(55, [])) === true, 'gallery_absent postcondition must verify absence.');
assert(mutation.evaluateAdminMutationPostcondition({type: 'gallery_identity', gallery_id: 55}, currentRoot) === true, 'gallery_identity postcondition must use stable hero identity.');
assert(mutation.evaluateAdminMutationPostcondition({type: 'image_present', image_ids: [77]}, galleryRoot(55, [], [77])) === true, 'image_present must resolve public image identity without depending on lightbox data-image-id.');
assert(mutation.evaluateAdminMutationPostcondition({type: 'image_absent', image_ids: [77]}, galleryRoot(55, [], [])) === true, 'image_absent must verify the owned public image list.');
assert(mutation.evaluateAdminMutationPostcondition({type: 'unknown'}, currentRoot) === false, 'Unknown postconditions must fail closed.');

const adminOnlyGalleryIdRoot = {
    querySelector(selector) {
        if (selector === '[data-public-subgallery-grid]' || selector === '.public-home-gallery-grid' || selector === '.hero[data-public-gallery-id]') {
            return null;
        }
        if (selector === '[data-gallery-id="123"]') {
            return {dataset: {galleryId: '123'}};
        }
        return null;
    },
};
assert(mutation.evaluateAdminMutationPostcondition({type: 'gallery_present', gallery_id: 123}, adminOnlyGalleryIdRoot) === false, 'Admin/editor data-gallery-id elements must not satisfy a public gallery postcondition.');
const adminOnlyImageIdRoot = {
    querySelector(selector) {
        if (selector === '[data-gallery-image-list]') {
            return null;
        }
        if (selector === '[data-image-id="77"]') {
            return {};
        }
        return null;
    },
};
assert(mutation.evaluateAdminMutationPostcondition({type: 'image_present', image_ids: [77]}, adminOnlyImageIdRoot) === false, 'Admin/editor data-image-id elements must not satisfy a public image postcondition.');

assert(mutation.adminMutationRetryDelay(0) === 0, 'First refresh attempt must be immediate.');
assert(mutation.adminMutationRetryDelay(1) === 150, 'Second refresh attempt delay changed unexpectedly.');
assert(mutation.adminMutationRetryDelay(2) === 450, 'Third refresh attempt delay changed unexpectedly.');
assert(mutation.adminMutationRetryDelay(3) === 1000, 'Fourth refresh attempt delay changed unexpectedly.');
assert(mutation.adminMutationRetryDelay(4) === 2000, 'Fifth refresh attempt delay changed unexpectedly.');
assert(mutation.adminMutationRetryDelay(5) === null, 'Retry policy must be bounded.');

const firstToken = mutation.beginAdminMutationRefresh(context);
const secondToken = mutation.beginAdminMutationRefresh(context);
assert(mutation.adminMutationRefreshIsCurrent(firstToken) === false, 'Older response token must be rejected after a newer request starts.');
assert(mutation.adminMutationRefreshIsCurrent(secondToken) === true, 'Newest response token must remain current.');

let fetchOptions = null;
let fetchUrl = '';
const fetchResult = await mutation.fetchAdminMutationRenderDocument('/gallery/parent/2/', {
    currentUrl: 'https://example.test/gallery/parent/2/?filter=public',
    nonce: 42,
    fetchImpl: async (url, options) => {
        fetchUrl = url;
        fetchOptions = options;
        return {ok: true, text: async () => '<html></html>'};
    },
    parseHtml: () => galleryRoot(55, []),
});
assert(fetchResult.ok === true, 'Authoritative render fetch should accept successful non-empty HTML.');
assert(fetchOptions.cache === 'no-store', 'Mutation refresh fetch must explicitly disable cache reuse.');
assert(fetchOptions.credentials === 'same-origin', 'Mutation refresh fetch must preserve same-origin credentials.');
assert(fetchUrl.includes('_panel_refresh=42'), 'Mutation refresh fetch must use the established cache-busting query convention.');

let liveHasCreatedGallery = false;
let replaceCount = 0;
const retryWaits = [];
const liveGrid = {
    querySelector(selector) {
        return selector === '[data-gallery-id="123"]' && liveHasCreatedGallery ? {} : null;
    },
};
const liveRoot = {
    querySelector(selector) {
        if (selector === '.hero[data-public-gallery-id]') {
            return {dataset: {publicGalleryId: '55'}};
        }
        if (selector === '[data-public-subgallery-grid]') {
            return liveGrid;
        }
        if (selector === '.public-home-gallery-grid') {
            return null;
        }
        return null;
    },
};
const parsedRoot = galleryRoot(55, [123]);
const completion = await mutation.completeAdminMutation({
    ok: true,
    message: 'Created.',
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [123]},
    panel: null,
    contexts: [context],
    fallback: {redirect_url: '/admin/edit?id=123'},
}, {
    documentRoot: liveRoot,
    currentUrl: 'https://example.test/gallery/parent/2/',
    retryDelays: [0, 5, 10],
    wait: async (delay) => { retryWaits.push(delay); },
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => parsedRoot,
    replacePublicContext: () => {
        replaceCount += 1;
        if (replaceCount >= 2) {
            liveHasCreatedGallery = true;
        }
        return true;
    },
    emitCompletion: () => {},
});
assert(completion.synchronized === true, 'Coordinator must retry a successful mutation until the declared postcondition is observable.');
assert(replaceCount === 2, 'Coordinator must stop retrying immediately after postcondition success.');
assert(retryWaits.length === 1 && retryWaits[0] === 5, 'Coordinator must use only the bounded retry delay needed for success.');

console.log('admin_mutation_completion_test: OK');
