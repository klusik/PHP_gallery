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

function dataNode(dataset = {}) {
    return {
        dataset: {...dataset},
        getAttribute(name) {
            const key = name.replace(/^data-/, '').replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            return Object.prototype.hasOwnProperty.call(this.dataset, key) ? String(this.dataset[key]) : null;
        },
        setAttribute(name, value) {
            const key = name.replace(/^data-/, '').replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
            this.dataset[key] = String(value);
        },
    };
}

function physicalGalleryRoot(initial = {}) {
    const state = {
        galleryId: 55,
        subCount: 0,
        subRevision: '',
        cards: [],
        galleryPage: 1,
        galleryTotalPages: 1,
        imageCount: 0,
        imageRevision: '',
        imagePage: 1,
        imageTotalPages: 1,
        imageOrder: [],
        imageMeta: {},
        smartCount: 0,
        smartCards: {},
        ...initial,
    };
    const root = {
        __state: state,
        querySelector(selector) {
            const hero = dataNode({
                publicGalleryId: String(state.galleryId),
                publicSubgalleryCount: String(state.subCount),
                publicSubgalleryRevision: state.subRevision,
                publicImageCount: String(state.imageCount),
                publicImageRevision: state.imageRevision,
                publicSmartGalleryCount: String(state.smartCount),
            });
            if (
                selector === '.hero[data-public-gallery-id]'
                || selector.startsWith(`.hero[data-public-gallery-id="${state.galleryId}"]`)
                || selector.startsWith('.hero[data-public-gallery-id][')
            ) {
                return hero;
            }
            const subgrid = {
                dataset: {
                    publicGalleryPage: String(state.galleryPage),
                    publicGalleryTotalPages: String(state.galleryTotalPages),
                    publicSubgalleryTotalCount: String(state.subCount),
                    publicSubgalleryRevision: state.subRevision,
                },
                getAttribute: dataNode({
                    publicGalleryPage: String(state.galleryPage),
                    publicGalleryTotalPages: String(state.galleryTotalPages),
                    publicSubgalleryTotalCount: String(state.subCount),
                    publicSubgalleryRevision: state.subRevision,
                }).getAttribute,
                querySelector(cardSelector) {
                    const match = cardSelector.match(/^\[data-gallery-id="(\d+)"\]$/);
                    if (!match || !state.cards.map(Number).includes(Number(match[1]))) {
                        return null;
                    }
                    const cardId = Number(match[1]);
                    const updatedAt = String(state.cardUpdatedAt?.[cardId] || '');
                    return dataNode({galleryId: String(cardId), galleryUpdatedAt: updatedAt});
                },
            };
            if (selector === '[data-public-subgallery-grid]' || selector.startsWith('[data-public-subgallery-grid][')) {
                return subgrid;
            }
            if (selector === '.public-home-gallery-grid' || selector.startsWith('[data-public-gallery-index-grid]')) {
                return null;
            }
            const imageList = {
                dataset: {
                    publicImageTotalCount: String(state.imageCount),
                    publicImageRevision: state.imageRevision,
                    publicImagePage: String(state.imagePage),
                    publicImageTotalPages: String(state.imageTotalPages),
                },
                getAttribute: dataNode({
                    publicImageTotalCount: String(state.imageCount),
                    publicImageRevision: state.imageRevision,
                    publicImagePage: String(state.imagePage),
                    publicImageTotalPages: String(state.imageTotalPages),
                }).getAttribute,
                querySelector(imageSelector) {
                    const match = imageSelector.match(/(?:data-public-order-id|data-image-id)="(\d+)"/);
                    if (!match) {
                        return null;
                    }
                    const imageId = Number(match[1]);
                    if (!state.imageOrder.map(Number).includes(imageId)) {
                        return null;
                    }
                    const meta = state.imageMeta?.[imageId] || {};
                    return dataNode({
                        publicOrderId: String(imageId),
                        publicImageVisibility: String(meta.visibility || ''),
                        publicImageNsfw: meta.nsfw ? '1' : '0',
                        publicImageUpdatedAt: String(meta.updatedAt || ''),
                    });
                },
                querySelectorAll(imageSelector) {
                    if (imageSelector !== '[data-public-photo-order-item][data-public-order-id]') {
                        return [];
                    }
                    return state.imageOrder.map((imageId) => dataNode({publicOrderId: String(imageId)}));
                },
            };
            if (selector === '[data-gallery-image-list]' || selector.startsWith('[data-gallery-image-list][')) {
                return imageList;
            }
            const smartGroupMatch = selector.match(/^\[data-smart-gallery-attachment-group="(top|bottom)"\]$/);
            if (smartGroupMatch) {
                const placement = smartGroupMatch[1];
                return {
                    querySelector(cardSelector) {
                        const match = cardSelector.match(/^\[data-smart-gallery-id="(\d+)"\]$/);
                        if (!match) {
                            return null;
                        }
                        const smartId = Number(match[1]);
                        const smart = state.smartCards?.[smartId];
                        if (!smart || smart.placement !== placement) {
                            return null;
                        }
                        return dataNode({
                            smartGalleryId: String(smartId),
                            smartGalleryPlacement: String(smart.placement),
                            smartGalleryPlacementOrder: String(smart.order ?? 0),
                        });
                    },
                };
            }
            return null;
        },
    };
    return root;
}

function rootGalleryIndex(initial = {}) {
    const state = {
        galleryCount: 0,
        galleryRevision: '',
        smartCount: 0,
        galleryPage: 1,
        galleryTotalPages: 1,
        cards: [],
        smartCards: [],
        ...initial,
    };
    const rootMarker = dataNode({
        publicRootGalleryCount: String(state.galleryCount),
        publicRootGalleryRevision: state.galleryRevision,
        publicRootSmartGalleryCount: String(state.smartCount),
    });
    const grid = {
        dataset: {
            publicGalleryPage: String(state.galleryPage),
            publicGalleryTotalPages: String(state.galleryTotalPages),
        },
        getAttribute: dataNode({
            publicGalleryPage: String(state.galleryPage),
            publicGalleryTotalPages: String(state.galleryTotalPages),
        }).getAttribute,
        querySelector(selector) {
            const galleryMatch = selector.match(/^\[data-gallery-id="(\d+)"\]$/);
            if (galleryMatch && state.cards.map(Number).includes(Number(galleryMatch[1]))) {
                return dataNode({galleryId: galleryMatch[1]});
            }
            const smartMatch = selector.match(/^\[data-smart-gallery-id="(\d+)"\]$/);
            if (smartMatch && state.smartCards.map(Number).includes(Number(smartMatch[1]))) {
                return dataNode({smartGalleryId: smartMatch[1]});
            }
            return null;
        },
    };
    return {
        __state: state,
        querySelector(selector) {
            if (selector === '[data-public-gallery-index]' || selector.startsWith('[data-public-gallery-index][')) {
                return rootMarker;
            }
            if (selector === '.public-home-gallery-grid' || selector.startsWith('[data-public-gallery-index-grid]')) {
                return grid;
            }
            if (selector === '.hero[data-public-gallery-id]' || selector.startsWith('.hero[data-public-gallery-id=')) {
                return null;
            }
            if (selector.startsWith('.hero[data-public-tag-page]')) {
                return null;
            }
            return null;
        },
    };
}

const paginatedParent = physicalGalleryRoot({subCount: 12, galleryPage: 2, galleryTotalPages: 3, cards: []});
assert(mutation.evaluateAdminMutationPostcondition({type: 'gallery_membership', gallery_id: 123, present: true, count: 12}, paginatedParent), 'Paginated gallery membership must accept an off-page target when the authoritative full count matches.');
assert(!mutation.evaluateAdminMutationPostcondition({type: 'gallery_membership', gallery_id: 123, present: true, count: 11}, paginatedParent), 'Gallery membership must reject stale full-count metadata.');
assert(!mutation.evaluateAdminMutationPostcondition({type: 'gallery_membership', gallery_id: 123, present: true, count: 12}, physicalGalleryRoot({subCount: 12, galleryTotalPages: 1})), 'A non-paginated parent must not verify a missing expected card.');

const orderedRoot = physicalGalleryRoot({imageCount: 3, imageOrder: [2, 1, 3]});
assert(mutation.evaluateAdminMutationPostcondition({type: 'image_order', image_ids: [2, 1, 3], count: 3}, orderedRoot), 'image_order must accept the expected visible relative order.');
assert(!mutation.evaluateAdminMutationPostcondition({type: 'image_order', image_ids: [1, 2, 3], count: 3}, orderedRoot), 'image_order must reject stale relative ordering.');

const offPageImageRoot = physicalGalleryRoot({imageCount: 20, imageRevision: '2026-09-02 21:30:00', imageOrder: []});
assert(mutation.evaluateAdminMutationPostcondition({type: 'image_visibility', image_ids: [77], visibility: 'private', revision: '2026-09-02 21:30:00'}, offPageImageRoot), 'Off-page image visibility must verify through the aggregate image revision.');
assert(!mutation.evaluateAdminMutationPostcondition({type: 'image_visibility', image_ids: [77], visibility: 'private', revision: '2026-09-02 21:29:59'}, offPageImageRoot), 'Off-page image visibility must reject a stale aggregate image revision.');

const smartRoot = physicalGalleryRoot({smartCount: 1, smartCards: {91: {placement: 'top', order: 7}}});
assert(mutation.evaluateAdminMutationPostcondition({type: 'smart_gallery_presence', smart_gallery_id: 91, present: true, count: 1, placement: 'top', placement_order: 7}, smartRoot), 'Smart Gallery placement verification must include top/bottom placement and order.');
assert(!mutation.evaluateAdminMutationPostcondition({type: 'smart_gallery_presence', smart_gallery_id: 91, present: true, count: 1, placement: 'bottom', placement_order: 7}, smartRoot), 'Smart Gallery placement verification must reject stale placement metadata.');

const liveStateRoot = physicalGalleryRoot({subCount: 1, cards: [], galleryTotalPages: 1});
const staleParsed = physicalGalleryRoot({subCount: 1, cards: [], galleryTotalPages: 1});
const freshParsed = physicalGalleryRoot({subCount: 2, cards: [123], galleryTotalPages: 1});
let staleFetchCount = 0;
let staleReplaceCount = 0;
const staleCompletion = await mutation.completeAdminMutation({
    ok: true,
    message: 'Created.',
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [123]},
    panel: null,
    contexts: [{
        type: 'gallery',
        gallery_id: 55,
        render_url: '/gallery/parent/',
        postcondition: {type: 'gallery_membership', gallery_id: 123, present: true, count: 2},
    }],
    fallback: {},
}, {
    documentRoot: liveStateRoot,
    currentUrl: 'https://example.test/gallery/parent/',
    retryDelays: [0, 1],
    wait: async () => {},
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => (++staleFetchCount === 1 ? staleParsed : freshParsed),
    replacePublicContext: (parsed) => {
        staleReplaceCount += 1;
        Object.assign(liveStateRoot.__state, parsed.__state);
        return true;
    },
    emitCompletion: () => {},
});
assert(staleCompletion.synchronized, 'A stale first read must succeed after a bounded fresh retry.');
assert(staleFetchCount === 2, 'A stale structurally valid response must consume one bounded retry.');
assert(staleReplaceCount === 1, 'Stale HTML must be rejected before it can replace the live DOM.');
assert(staleCompletion.diagnostics.some((record) => record.result === 'stale-response'), 'Diagnostics must distinguish a structurally valid stale response.');

const raceLiveRoot = physicalGalleryRoot({subCount: 1, cards: [], galleryTotalPages: 1});
let oldWaitStartedResolve;
const oldWaitStarted = new Promise((resolve) => { oldWaitStartedResolve = resolve; });
let releaseOldWait;
let oldFetchCount = 0;
const oldCompletionPromise = mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [123]},
    panel: null,
    contexts: [{type: 'gallery', gallery_id: 55, render_url: '/gallery/parent/', postcondition: {type: 'gallery_membership', gallery_id: 123, present: true, count: 2}}],
    fallback: {},
}, {
    documentRoot: raceLiveRoot,
    currentUrl: 'https://example.test/gallery/parent/',
    retryDelays: [0, 1],
    wait: async () => {
        oldWaitStartedResolve();
        await new Promise((resolve) => { releaseOldWait = resolve; });
    },
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => {
        oldFetchCount += 1;
        return physicalGalleryRoot({subCount: 1, cards: [], galleryTotalPages: 1});
    },
    replacePublicContext: () => {
        throw new Error('A stale old operation must never replace the DOM.');
    },
    emitCompletion: () => {},
});
await oldWaitStarted;
const newerParsed = physicalGalleryRoot({subCount: 2, cards: [124], galleryTotalPages: 1});
const newerCompletion = await mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [124]},
    panel: null,
    contexts: [{type: 'gallery', gallery_id: 55, render_url: '/gallery/parent/', postcondition: {type: 'gallery_membership', gallery_id: 124, present: true, count: 2}}],
    fallback: {},
}, {
    documentRoot: raceLiveRoot,
    currentUrl: 'https://example.test/gallery/parent/',
    retryDelays: [0],
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => newerParsed,
    replacePublicContext: (parsed) => {
        Object.assign(raceLiveRoot.__state, parsed.__state);
        return true;
    },
    emitCompletion: () => {},
});
assert(newerCompletion.synchronized, 'The newer mutation must synchronize normally while an older retry is sleeping.');
releaseOldWait();
const oldCompletion = await oldCompletionPromise;
assert(oldFetchCount === 1, 'An older mutation must not start a retry after a newer operation owns the same context.');
assert(oldCompletion.contexts[0].status === 'superseded-operation', 'The older sleeping retry must be classified as superseded.');
assert(raceLiveRoot.__state.cards.includes(124), 'The older operation must not regress the newer DOM state.');

const abortLiveRoot = physicalGalleryRoot({subCount: 1, cards: [], galleryTotalPages: 1});
let activeFetchStartedResolve;
const activeFetchStarted = new Promise((resolve) => { activeFetchStartedResolve = resolve; });
let oldFetchAborted = false;
const abortOldPromise = mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [201]},
    panel: null,
    contexts: [{type: 'gallery', gallery_id: 55, render_url: '/gallery/parent/', postcondition: {type: 'gallery_membership', gallery_id: 201, present: true, count: 2}}],
    fallback: {},
}, {
    documentRoot: abortLiveRoot,
    currentUrl: 'https://example.test/gallery/parent/',
    retryDelays: [0],
    fetchImpl: async (_url, options) => new Promise((_resolve, reject) => {
        activeFetchStartedResolve();
        options.signal.addEventListener('abort', () => {
            oldFetchAborted = true;
            const error = new Error('aborted');
            error.name = 'AbortError';
            reject(error);
        }, {once: true});
    }),
    parseHtml: () => null,
    emitCompletion: () => {},
});
await activeFetchStarted;
const abortNewParsed = physicalGalleryRoot({subCount: 2, cards: [202], galleryTotalPages: 1});
await mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.create', entity: 'gallery', action: 'create', entity_ids: [202]},
    panel: null,
    contexts: [{type: 'gallery', gallery_id: 55, render_url: '/gallery/parent/', postcondition: {type: 'gallery_membership', gallery_id: 202, present: true, count: 2}}],
    fallback: {},
}, {
    documentRoot: abortLiveRoot,
    currentUrl: 'https://example.test/gallery/parent/',
    retryDelays: [0],
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => abortNewParsed,
    replacePublicContext: (parsed) => {
        Object.assign(abortLiveRoot.__state, parsed.__state);
        return true;
    },
    emitCompletion: () => {},
});
const abortOldCompletion = await abortOldPromise;
assert(oldFetchAborted, 'A newer operation must actively abort an in-flight refresh for the same context.');
assert(abortOldCompletion.contexts[0].status === 'superseded-operation', 'An aborted older refresh must be reported as superseded rather than as a user-visible failure.');

let panelState = 'initial';
let oldPanelStartedResolve;
const oldPanelStarted = new Promise((resolve) => { oldPanelStartedResolve = resolve; });
let releaseOldPanel;
const oldPanelPromise = mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.update', entity: 'gallery', action: 'update', entity_ids: [55]},
    panel: {workflow: 'gallery-edit', refresh_url: '/admin/edit?id=55', keep_open: true},
    contexts: [],
    fallback: {},
}, {
    refreshPanel: async (_panel, _envelope, guard) => {
        oldPanelStartedResolve();
        await new Promise((resolve) => { releaseOldPanel = resolve; });
        if (guard.isCurrent()) {
            panelState = 'old';
        }
        return true;
    },
    emitCompletion: () => {},
});
await oldPanelStarted;
const newPanelCompletion = await mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'gallery.update', entity: 'gallery', action: 'update', entity_ids: [55]},
    panel: {workflow: 'gallery-edit', refresh_url: '/admin/edit?id=55&new=1', keep_open: true},
    contexts: [],
    fallback: {},
}, {
    refreshPanel: async (_panel, _envelope, guard) => {
        if (guard.isCurrent()) {
            panelState = 'new';
        }
        return true;
    },
    emitCompletion: () => {},
});
assert(newPanelCompletion.panel.status === 'synchronized' && panelState === 'new', 'The newest panel refresh must own the mounted drawer state.');
releaseOldPanel();
const oldPanelCompletion = await oldPanelPromise;
assert(oldPanelCompletion.panel.status === 'superseded-operation', 'An older panel refresh must be classified as superseded after a newer panel mutation begins.');
assert(panelState === 'new', 'An older panel response must not replace newer panel content.');

const indexLive = rootGalleryIndex({galleryCount: 2, galleryTotalPages: 1, cards: [1, 2]});
let indexFetchCount = 0;
let indexReplaceCount = 0;
const indexCompletion = await mutation.completeAdminMutation({
    ok: true,
    mutation: {type: 'thumbnail.rebuild', entity: 'thumbnail', action: 'rebuild', entity_ids: []},
    panel: null,
    contexts: [{type: 'gallery_index', gallery_id: null, render_url: '/', postcondition: null}],
    fallback: {},
}, {
    documentRoot: indexLive,
    currentUrl: 'https://example.test/?share_token=SECRET',
    retryDelays: [0, 1],
    wait: async () => {},
    fetchImpl: async () => ({ok: true, text: async () => '<html></html>'}),
    parseHtml: () => {
        indexFetchCount += 1;
        return indexFetchCount === 1 ? physicalGalleryRoot({galleryId: 55}) : rootGalleryIndex({galleryCount: 2, cards: [1, 2]});
    },
    replacePublicContext: () => {
        indexReplaceCount += 1;
        return true;
    },
    emitCompletion: () => {},
});
assert(indexFetchCount === 2 && indexReplaceCount === 1, 'A wrong-context root response must be rejected before replacement and retried within the shared budget.');
assert(indexCompletion.contexts[0].status === 'refreshed-unverified' && indexCompletion.contexts[0].verified === null, 'Explicit no-postcondition contexts must be marked refreshed-unverified rather than falsely verified.');
assert(indexCompletion.diagnostics.every((record) => !record.refresh_path.includes('SECRET')), 'Mutation diagnostics must omit query-string secrets from refresh paths.');

console.log('admin_mutation_stage4_hardening_test: OK');
