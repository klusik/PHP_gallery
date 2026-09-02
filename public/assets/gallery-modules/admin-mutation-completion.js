/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: public/assets/gallery-modules/admin-mutation-completion.js
 * Module Type: Browser Module
 *
 * Purpose:
 *   Coordinates completion of successful Admin mutations without navigation or hard reloads.
 *
 * Responsibilities:
 *   - Validate and normalize the canonical mutation envelope
 *   - Match affected public contexts by stable gallery or tag identity
 *   - Fetch authoritative server-rendered HTML with no-store/cache-busting semantics
 *   - Replace only the owned server-rendered public fragments
 *   - Verify typed postconditions with bounded retries
 *   - Reject stale or out-of-order refresh responses
 *   - Report synchronization failures without hiding a successful server mutation
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - PHP remains the rendering source of truth. This module does not implement a second renderer.
 *   - Enhanced persistent workflows must preserve the canonical server envelope end to end.
 *
 * Last Updated:
 *   2026-09-02
 */

// Shared-hosting deployments can expose a short read-after-write lag between the
// successful mutation request and the next independently rendered GET. Keep the
// retry budget bounded, but long enough that the coordinator does not report a
// false synchronization failure while a manual reload a moment later is already fresh.
export const ADMIN_MUTATION_RETRY_DELAYS_MS = Object.freeze([0, 150, 450, 1000, 2000]);

let mutationOperationSequence = 0;
let mutationRefreshSequence = 0;
let mutationLatestPanelOperationSequence = 0;
let mutationActivePanelAbortController = null;
const mutationOperationLatestByContext = new Map();
const mutationRefreshLatestByContext = new Map();
const mutationActiveRefreshByContext = new Map();

/**
 * Validate and normalize one canonical successful mutation envelope.
 *
 * Stage 5 deliberately rejects legacy response shapes instead of reconstructing
 * mutation semantics in the browser. Persistent enhanced workflows must preserve
 * the server-provided mutation, panel, context, and fallback metadata end to end.
 *
 * @param {Record<string, *>} result Canonical successful mutation response.
 * @return {Record<string, *>} Normalized mutation envelope.
 */
export function normalizeAdminMutationEnvelope(result) {
    const input = result && typeof result === 'object' ? result : {};
    const mutation = input.mutation && typeof input.mutation === 'object' ? input.mutation : null;
    const type = String(mutation?.type || '').trim();
    const entity = String(mutation?.entity || '').trim();
    const action = String(mutation?.action || '').trim();

    if (input.ok !== true || !mutation || !Array.isArray(input.contexts) || type === '' || entity === '' || action === '') {
        throw new TypeError('Admin mutation response is missing the canonical completion envelope.');
    }

    return {
        ...input,
        mutation: {
            ...mutation,
            type,
            entity,
            action,
            entity_ids: normalizedIdList(mutation.entity_ids || []),
        },
        panel: normalizeMutationPanel(input.panel),
        contexts: input.contexts.map(normalizeMutationContext).filter(Boolean),
        fallback: input.fallback && typeof input.fallback === 'object' ? input.fallback : {},
    };
}

/**
 * Normalize optional panel metadata from a mutation envelope.
 *
 * @param {*} panel Panel metadata candidate.
 * @return {Record<string, *>|null} Normalized panel metadata.
 */
function normalizeMutationPanel(panel) {
    if (!panel || typeof panel !== 'object') {
        return null;
    }
    return {
        workflow: String(panel.workflow || ''),
        refresh_url: String(panel.refresh_url || ''),
        keep_open: panel.keep_open !== false,
    };
}

/**
 * Normalize one affected public context.
 *
 * @param {*} context Context candidate.
 * @return {Record<string, *>|null} Normalized context or null when unusable.
 */
function normalizeMutationContext(context) {
    if (!context || typeof context !== 'object') {
        return null;
    }
    const type = String(context.type || 'gallery');
    if (!['gallery', 'gallery_index', 'tag'].includes(type)) {
        return null;
    }
    return {
        type,
        gallery_id: type === 'gallery' ? positiveInteger(context.gallery_id) : null,
        tag_id: type === 'tag' ? positiveInteger(context.tag_id) : null,
        render_url: String(context.render_url || ''),
        render_mode: String(context.render_mode || 'preserve_view') === 'canonical' ? 'canonical' : 'preserve_view',
        postcondition: context.postcondition && typeof context.postcondition === 'object'
            ? {...context.postcondition}
            : null,
    };
}

/**
 * Return a positive integer, or zero when the supplied value is not a stable id.
 *
 * @param {*} value Candidate identifier.
 * @return {number} Positive integer identifier or zero.
 */
function positiveInteger(value) {
    const number = Number(value || 0);
    return Number.isInteger(number) && number > 0 ? number : 0;
}

/**
 * Normalize a list of stable identifiers.
 *
 * @param {*} values Candidate identifier list.
 * @return {number[]} Unique positive integer identifiers.
 */
function normalizedIdList(values) {
    if (!Array.isArray(values)) {
        return [];
    }
    return [...new Set(values.map(positiveInteger).filter((value) => value > 0))];
}

/**
 * Return the stable public gallery id rendered by a document/root.
 *
 * @param {*} root Document-like root exposing querySelector().
 * @return {number} Stable gallery id, or zero outside a concrete gallery page.
 */
export function publicGalleryIdFromRoot(root) {
    const hero = root && typeof root.querySelector === 'function'
        ? root.querySelector('.hero[data-public-gallery-id]')
        : null;
    const value = hero?.dataset?.publicGalleryId ?? hero?.getAttribute?.('data-public-gallery-id') ?? 0;
    return positiveInteger(value);
}

/**
 * Return the stable public tag id rendered by a document/root.
 *
 * @param {*} root Document-like root exposing querySelector().
 * @return {number} Stable tag id, or zero outside a tag landing page.
 */
export function publicTagIdFromRoot(root) {
    const hero = root && typeof root.querySelector === 'function'
        ? root.querySelector('.hero[data-public-tag-page][data-tag-id]')
        : null;
    const value = hero?.dataset?.tagId ?? hero?.getAttribute?.('data-tag-id') ?? 0;
    return positiveInteger(value);
}


/**
 * Return whether a document/root is the public root gallery index.
 *
 * @param {*} root Document-like root exposing querySelector().
 * @return {boolean} True only for the owned public gallery index context.
 */
export function publicGalleryIndexFromRoot(root) {
    return Boolean(root && typeof root.querySelector === 'function' && root.querySelector('[data-public-gallery-index]'));
}

/**
 * Return whether an affected context is the public context currently rendered.
 *
 * Concrete gallery/tag contexts match by stable database id, not URL string equality.
 * The root gallery index has no entity row, so URL/path equality is used only for
 * that special context after confirming no concrete gallery hero is rendered.
 *
 * @param {Record<string, *>} context Mutation context.
 * @param {*} root Current document-like root.
 * @param {string} currentUrl Current browser URL.
 * @param {string} baseUrl Base URL used for relative URL parsing in tests/browser.
 * @return {boolean} True when the context owns the currently visible public page.
 */
export function mutationContextMatchesCurrentDocument(context, root, currentUrl = '', baseUrl = '') {
    const normalized = normalizeMutationContext(context);
    if (!normalized) {
        return false;
    }
    if (normalized.type === 'gallery') {
        return normalized.gallery_id > 0 && publicGalleryIdFromRoot(root) === normalized.gallery_id;
    }
    if (normalized.type === 'tag') {
        return normalized.tag_id > 0 && publicTagIdFromRoot(root) === normalized.tag_id;
    }
    if (publicGalleryIdFromRoot(root) > 0 || publicTagIdFromRoot(root) > 0) {
        return false;
    }
    if (publicGalleryIndexFromRoot(root)) {
        return true;
    }
    return sameRenderLocation(normalized.render_url, currentUrl, baseUrl || currentUrl);
}

/**
 * Return whether two render URLs describe the same path/query while ignoring hash and cache busters.
 *
 * @param {string} left First URL.
 * @param {string} right Second URL.
 * @param {string} baseUrl Base URL for relative inputs.
 * @return {boolean} True when the render locations match.
 */
export function sameRenderLocation(left, right, baseUrl = '') {
    try {
        const base = baseUrl || left || right || 'http://localhost/';
        const leftUrl = new URL(left || base, base);
        const rightUrl = new URL(right || base, base);
        leftUrl.hash = '';
        rightUrl.hash = '';
        leftUrl.searchParams.delete('_panel_refresh');
        rightUrl.searchParams.delete('_panel_refresh');
        return leftUrl.pathname === rightUrl.pathname && leftUrl.search === rightUrl.search;
    } catch (error) {
        return false;
    }
}

/**
 * Merge non-routing visible query state onto an authoritative render URL.
 *
 * Gallery pagination, photo pagination, date sorting, language selection, and
 * similar view-state parameters belong to the page the admin is actually looking
 * at. Route identity parameters belong to the authoritative canonical URL. This
 * distinction matters after rename/move and on paginated parent galleries.
 *
 * @param {string} renderUrl Canonical/current authoritative render URL.
 * @param {string} currentUrl Browser URL whose visible view state should survive.
 * @return {string} Authoritative URL with visible non-routing query state applied.
 */
export function mergeAdminMutationRenderViewState(renderUrl, currentUrl) {
    try {
        const target = new URL(renderUrl || currentUrl, currentUrl);
        const visible = new URL(currentUrl || target.toString(), target.toString());
        const routingParameters = new Set(['page', 'public_path', 'gallery_path', 'slug', 'id', '_panel_refresh']);
        const visibleKeys = [...new Set(Array.from(visible.searchParams.keys()))];

        visibleKeys.forEach((key) => {
            if (routingParameters.has(key)) {
                return;
            }
            target.searchParams.delete(key);
            visible.searchParams.getAll(key).forEach((value) => target.searchParams.append(key, value));
        });
        target.searchParams.delete('_panel_refresh');
        target.hash = '';
        return target.toString();
    } catch (error) {
        return renderUrl || currentUrl;
    }
}

/**
 * Resolve the render source for a currently visible affected context.
 *
 * Normal mutations preserve the visible pagination/filter URL when stable gallery/tag
 * identity proves it is still the same entity. Rename/move migrations may opt into
 * canonical mode so the returned render_url is used even while the browser URL stays unchanged.
 *
 * @param {Record<string, *>} context Mutation context.
 * @param {string} currentUrl Current browser URL.
 * @return {string} Authoritative render source for the refresh.
 */
export function resolveAdminMutationContextRenderUrl(context, currentUrl, root = null) {
    const normalized = normalizeMutationContext(context);
    if (!normalized) {
        return currentUrl;
    }
    if (normalized.render_mode === 'canonical') {
        return normalized.render_url || currentUrl;
    }
    if (normalized.type === 'gallery' && root && typeof root.querySelector === 'function') {
        const hero = root.querySelector(`.hero[data-public-gallery-id="${normalized.gallery_id}"]`);
        const persistedRenderUrl = String(hero?.dataset?.adminMutationCanonicalUrl
            ?? hero?.getAttribute?.('data-admin-mutation-canonical-url')
            ?? hero?.dataset?.adminMutationRenderUrl
            ?? hero?.getAttribute?.('data-admin-mutation-render-url')
            ?? '');
        if (persistedRenderUrl !== '') {
            return mergeAdminMutationRenderViewState(persistedRenderUrl, currentUrl);
        }
    }
    if (normalized.type === 'tag' && root && typeof root.querySelector === 'function') {
        const hero = root.querySelector(`.hero[data-public-tag-page][data-tag-id="${normalized.tag_id}"]`);
        const persistedRenderUrl = String(hero?.dataset?.adminMutationCanonicalUrl
            ?? hero?.getAttribute?.('data-admin-mutation-canonical-url')
            ?? hero?.dataset?.adminMutationRenderUrl
            ?? hero?.getAttribute?.('data-admin-mutation-render-url')
            ?? '');
        if (persistedRenderUrl !== '') {
            return mergeAdminMutationRenderViewState(persistedRenderUrl, currentUrl);
        }
    }
    return currentUrl || mergeAdminMutationRenderViewState(normalized.render_url, currentUrl);
}

/**
 * Build the authoritative no-cache render URL using the established panel cache-buster.
 *
 * @param {string} renderUrl Render source returned by the server.
 * @param {string} currentUrl Browser URL used as the relative base.
 * @param {number} nonce Cache-busting value.
 * @return {string} Fetch URL.
 */
export function buildAdminMutationRefreshUrl(renderUrl, currentUrl, nonce = Date.now()) {
    const url = new URL(renderUrl || currentUrl, currentUrl);
    url.searchParams.set('_panel_refresh', String(nonce));
    return url.toString();
}

/**
 * Fetch one authoritative server-rendered document for a mutation refresh.
 *
 * @param {string} renderUrl Render source returned by the server.
 * @param {object} options Fetch and parser dependencies.
 * @return {Promise<{ok:boolean,document:*|null,response:*|null,url:string}>} Fetch result.
 */
export async function fetchAdminMutationRenderDocument(renderUrl, options = {}) {
    const currentUrl = String(options.currentUrl || globalThis.window?.location?.href || '');
    const fetchImpl = options.fetchImpl || globalThis.fetch;
    const parseHtml = options.parseHtml || ((html) => new DOMParser().parseFromString(html, 'text/html'));
    if (currentUrl === '' || typeof fetchImpl !== 'function') {
        return {ok: false, status: 'request-unavailable', document: null, response: null, url: ''};
    }
    const fetchUrl = buildAdminMutationRefreshUrl(renderUrl || currentUrl, currentUrl, options.nonce ?? Date.now());
    try {
        const response = await fetchImpl(fetchUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: options.signal || undefined,
            headers: {
                'Accept': 'text/html',
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache',
            },
        });
        const html = await response.text();
        if (!response.ok) {
            return {ok: false, status: 'http-error', document: null, response, url: fetchUrl};
        }
        if (html.trim() === '') {
            return {ok: false, status: 'empty-response', document: null, response, url: fetchUrl};
        }
        try {
            return {ok: true, status: 'ok', document: parseHtml(html), response, url: fetchUrl};
        } catch (error) {
            return {ok: false, status: 'parse-failed', document: null, response, url: fetchUrl};
        }
    } catch (error) {
        const aborted = options.signal?.aborted === true || error?.name === 'AbortError';
        return {ok: false, status: aborted ? 'request-aborted' : 'request-failed', document: null, response: null, url: fetchUrl};
    }
}

/**
 * Evaluate a typed mutation postcondition against server-rendered/live markup.
 *
 * @param {Record<string, *>|null} postcondition Typed postcondition metadata.
 * @param {*} root Document-like root exposing querySelector().
 * @return {boolean} True when the expected observable state is present.
 */
export function evaluateAdminMutationPostcondition(postcondition, root) {
    if (!postcondition || typeof postcondition !== 'object') {
        return true;
    }
    if (!root || typeof root.querySelector !== 'function') {
        return false;
    }
    const type = String(postcondition.type || '');
    const galleryId = positiveInteger(postcondition.gallery_id);
    const tagId = positiveInteger(postcondition.tag_id);
    const imageId = positiveInteger(postcondition.image_id);
    const imageIds = normalizedIdList(postcondition.image_ids || (imageId > 0 ? [imageId] : []));
    const smartGalleryId = positiveInteger(postcondition.smart_gallery_id);
    const expectedVisibility = String(postcondition.visibility || '');
    const expectedUpdatedAt = String(postcondition.updated_at || '');
    const expectedRevision = String(postcondition.revision || '');
    const expectedEnabled = Boolean(postcondition.enabled);
    const expectedPresent = Boolean(postcondition.present);
    const hasExpectedCount = Object.prototype.hasOwnProperty.call(postcondition, 'count');
    const expectedCount = hasExpectedCount ? Math.max(0, Number(postcondition.count || 0)) : null;
    const expectedPlacement = String(postcondition.placement || '');
    const hasExpectedPlacementOrder = Object.prototype.hasOwnProperty.call(postcondition, 'placement_order');
    const expectedPlacementOrder = hasExpectedPlacementOrder ? Math.max(0, Number(postcondition.placement_order || 0)) : null;

    if (type === 'gallery_present') {
        return galleryId > 0 && Boolean(publicGalleryCardById(root, galleryId));
    }
    if (type === 'gallery_absent') {
        return galleryId > 0 && !publicGalleryCardById(root, galleryId);
    }
    if (type === 'gallery_membership') {
        if (galleryId <= 0 || !hasExpectedCount || !Number.isInteger(expectedCount)) {
            return false;
        }
        const cardPresent = Boolean(publicGalleryCardById(root, galleryId));
        const paginated = publicGalleryContextIsPaginated(root);

        // Direct observation of the target card is stronger than aggregate count metadata.
        // In particular, a freshly created child may already be present in the returned HTML
        // while an auxiliary count marker still differs because of pagination/listing policy.
        // Never reject that authoritative card merely because the fallback count disagrees.
        if (expectedPresent && cardPresent) {
            return true;
        }
        if (!expectedPresent && cardPresent) {
            return false;
        }

        // On an unpaginated context, absence is directly observable because every physical
        // gallery card belonging to the context is rendered on this page. For a paginated
        // context the target may legitimately be off-page, so the aggregate count remains the
        // stale-read discriminator for both off-page create/move and off-page deletion.
        if (!paginated) {
            return !expectedPresent;
        }
        const renderedCount = publicPhysicalGalleryCountFromRoot(root);
        return renderedCount !== null && renderedCount === expectedCount;
    }
    if (type === 'gallery_identity') {
        return galleryId > 0 && publicGalleryIdFromRoot(root) === galleryId;
    }
    if (type === 'tag_identity') {
        return tagId > 0 && publicTagIdFromRoot(root) === tagId;
    }
    if (type === 'image_present') {
        return imageIds.length > 0 && imageIds.every((id) => Boolean(publicGalleryImageById(root, id)));
    }
    if (type === 'image_absent') {
        return imageIds.length > 0 && imageIds.every((id) => !publicGalleryImageById(root, id));
    }
    if (type === 'image_order') {
        if (imageIds.length === 0 || !hasExpectedCount || !Number.isInteger(expectedCount)) {
            return false;
        }
        const imageList = root.querySelector('[data-gallery-image-list][data-public-image-total-count]');
        const renderedCount = Number(imageList?.dataset?.publicImageTotalCount ?? imageList?.getAttribute?.('data-public-image-total-count') ?? -1);
        if (!Number.isInteger(renderedCount) || renderedCount !== expectedCount) {
            return false;
        }
        const expectedOrder = new Map(imageIds.map((id, index) => [id, index]));
        const visibleIds = publicGalleryImageOrder(root).filter((id) => expectedOrder.has(id));
        for (let index = 1; index < visibleIds.length; index += 1) {
            if (expectedOrder.get(visibleIds[index - 1]) > expectedOrder.get(visibleIds[index])) {
                return false;
            }
        }
        return true;
    }
    if (type === 'image_updated_at') {
        if (imageIds.length === 0 || expectedUpdatedAt === '') {
            return false;
        }
        const observableImages = imageIds.map((id) => publicGalleryImageById(root, id)).filter(Boolean);
        if (observableImages.length === 0) {
            return false;
        }
        return observableImages.every((image) => String(
            image?.dataset?.publicImageUpdatedAt ?? image?.getAttribute?.('data-public-image-updated-at') ?? ''
        ) === expectedUpdatedAt);
    }
    if (type === 'cover_image') {
        const hero = root.querySelector('[data-public-cover-image-id]');
        if (!hero) {
            return false;
        }
        const renderedId = Math.max(0, Number(hero?.dataset?.publicCoverImageId ?? hero?.getAttribute?.('data-public-cover-image-id') ?? 0));
        const expectedImageId = Math.max(0, Number(postcondition.image_id ?? 0));
        return Number.isInteger(renderedId) && renderedId === expectedImageId;
    }
    if (type === 'gallery_visibility') {
        if (galleryId <= 0 || expectedVisibility === '') {
            return false;
        }
        const hero = root.querySelector(`.hero[data-public-gallery-id="${galleryId}"]`);
        const card = publicGalleryCardById(root, galleryId);
        const node = hero || card;
        const renderedVisibility = String(node?.dataset?.publicGalleryVisibility ?? node?.dataset?.galleryVisibility ?? node?.getAttribute?.('data-public-gallery-visibility') ?? node?.getAttribute?.('data-gallery-visibility') ?? '');
        return renderedVisibility === expectedVisibility;
    }
    if (type === 'gallery_updated_at') {
        if (galleryId <= 0 || expectedUpdatedAt === '') {
            return false;
        }
        const hero = root.querySelector(`.hero[data-public-gallery-id="${galleryId}"][data-public-gallery-updated-at]`);
        const card = publicGalleryCardById(root, galleryId);
        const renderedUpdatedAt = String(hero?.dataset?.publicGalleryUpdatedAt
            ?? hero?.getAttribute?.('data-public-gallery-updated-at')
            ?? card?.dataset?.galleryUpdatedAt
            ?? card?.getAttribute?.('data-gallery-updated-at')
            ?? '');
        if (renderedUpdatedAt !== '') {
            return renderedUpdatedAt === expectedUpdatedAt;
        }
        return publicGalleryContextIsPaginated(root)
            && publicPhysicalGalleryRevisionFromRoot(root) === expectedUpdatedAt;
    }
    if (type === 'image_visibility') {
        if (expectedVisibility === '' || imageIds.length === 0) {
            return false;
        }
        const observableImages = imageIds.map((id) => publicGalleryImageById(root, id)).filter(Boolean);
        if (observableImages.length === 0) {
            return expectedRevision !== '' && publicImageRevisionFromRoot(root) === expectedRevision;
        }
        return observableImages.every((image) => {
            const renderedVisibility = String(image?.dataset?.publicImageVisibility ?? image?.getAttribute?.('data-public-image-visibility') ?? '');
            return renderedVisibility === expectedVisibility;
        });
    }
    if (type === 'image_nsfw') {
        if (imageIds.length === 0) {
            return false;
        }
        const observableImages = imageIds.map((id) => publicGalleryImageById(root, id)).filter(Boolean);
        if (observableImages.length === 0) {
            return expectedRevision !== '' && publicImageRevisionFromRoot(root) === expectedRevision;
        }
        return observableImages.every((image) => {
            const renderedEnabled = String(image?.dataset?.publicImageNsfw ?? image?.getAttribute?.('data-public-image-nsfw') ?? '') === '1';
            return renderedEnabled === expectedEnabled;
        });
    }
    if (type === 'gallery_image_count') {
        if (galleryId <= 0 || !hasExpectedCount || !Number.isInteger(expectedCount)) {
            return false;
        }
        const hero = root.querySelector(`.hero[data-public-gallery-id="${galleryId}"][data-public-image-count]`);
        const renderedCount = Number(hero?.dataset?.publicImageCount ?? hero?.getAttribute?.('data-public-image-count') ?? -1);
        return Number.isInteger(renderedCount) && renderedCount === expectedCount;
    }
    if (type === 'gallery_image_revision') {
        if (galleryId <= 0 || expectedRevision === '') {
            return false;
        }
        const hero = root.querySelector(`.hero[data-public-gallery-id="${galleryId}"][data-public-image-revision]`);
        const renderedRevision = String(hero?.dataset?.publicImageRevision ?? hero?.getAttribute?.('data-public-image-revision') ?? '');
        return renderedRevision !== '' && renderedRevision === expectedRevision;
    }
    if (type === 'smart_gallery_presence') {
        if (smartGalleryId <= 0 || !hasExpectedCount || !Number.isInteger(expectedCount)) {
            return false;
        }
        const renderedCount = publicSmartGalleryCountFromRoot(root);
        if (renderedCount === null || renderedCount !== expectedCount) {
            return false;
        }
        const card = publicSmartGalleryCardById(root, smartGalleryId);
        if (!expectedPresent) {
            return !card;
        }
        if (!card) {
            return publicGalleryIndexFromRoot(root) && publicGalleryContextIsPaginated(root);
        }
        if (expectedPlacement !== '') {
            const renderedPlacement = String(card?.dataset?.smartGalleryPlacement ?? card?.getAttribute?.('data-smart-gallery-placement') ?? '');
            if (renderedPlacement !== expectedPlacement) {
                return false;
            }
        }
        if (hasExpectedPlacementOrder) {
            const renderedOrder = Number(card?.dataset?.smartGalleryPlacementOrder ?? card?.getAttribute?.('data-smart-gallery-placement-order') ?? -1);
            if (!Number.isInteger(renderedOrder) || renderedOrder !== expectedPlacementOrder) {
                return false;
            }
        }
        return true;
    }
    return false;
}

/**
 * Return the aggregate physical-gallery revision owned by the current context.
 *
 * @param {*} root Document-like root.
 * @return {string} Maximum rendered child/root gallery updated_at value.
 */
export function publicPhysicalGalleryRevisionFromRoot(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return '';
    }
    const galleryHero = root.querySelector('.hero[data-public-gallery-id][data-public-subgallery-revision]');
    if (galleryHero) {
        return String(galleryHero?.dataset?.publicSubgalleryRevision ?? galleryHero?.getAttribute?.('data-public-subgallery-revision') ?? '');
    }
    const rootMarker = root.querySelector('[data-public-gallery-index][data-public-root-gallery-revision]');
    return String(rootMarker?.dataset?.publicRootGalleryRevision ?? rootMarker?.getAttribute?.('data-public-root-gallery-revision') ?? '');
}

/**
 * Return the full physical-gallery count owned by the current gallery context.
 *
 * Parent gallery pages expose a full subgallery count even when the card grid is
 * paginated. The root index exposes the equivalent physical-gallery count.
 *
 * @param {*} root Document-like root.
 * @return {number|null} Full physical-gallery count or null outside an owned context.
 */
export function publicPhysicalGalleryCountFromRoot(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return null;
    }
    const galleryHero = root.querySelector('.hero[data-public-gallery-id][data-public-subgallery-count]');
    if (galleryHero) {
        const value = Number(galleryHero?.dataset?.publicSubgalleryCount ?? galleryHero?.getAttribute?.('data-public-subgallery-count') ?? -1);
        return Number.isInteger(value) && value >= 0 ? value : null;
    }
    const rootMarker = root.querySelector('[data-public-gallery-index][data-public-root-gallery-count]');
    const value = Number(rootMarker?.dataset?.publicRootGalleryCount ?? rootMarker?.getAttribute?.('data-public-root-gallery-count') ?? -1);
    return Number.isInteger(value) && value >= 0 ? value : null;
}

/**
 * Return the aggregate image-state revision rendered for the current gallery.
 *
 * @param {*} root Document-like root.
 * @return {string} Aggregate image revision, or an empty string when unavailable.
 */
export function publicImageRevisionFromRoot(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return '';
    }
    const hero = root.querySelector('.hero[data-public-gallery-id][data-public-image-revision]');
    return String(hero?.dataset?.publicImageRevision ?? hero?.getAttribute?.('data-public-image-revision') ?? '');
}

/**
 * Return the full Smart Gallery count owned by the current gallery context.
 *
 * @param {*} root Document-like root.
 * @return {number|null} Full Smart Gallery count or null outside an owned context.
 */
export function publicSmartGalleryCountFromRoot(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return null;
    }
    const galleryHero = root.querySelector('.hero[data-public-gallery-id][data-public-smart-gallery-count]');
    if (galleryHero) {
        const value = Number(galleryHero?.dataset?.publicSmartGalleryCount ?? galleryHero?.getAttribute?.('data-public-smart-gallery-count') ?? -1);
        return Number.isInteger(value) && value >= 0 ? value : null;
    }
    const rootMarker = root.querySelector('[data-public-gallery-index][data-public-root-smart-gallery-count]');
    const value = Number(rootMarker?.dataset?.publicRootSmartGalleryCount ?? rootMarker?.getAttribute?.('data-public-root-smart-gallery-count') ?? -1);
    return Number.isInteger(value) && value >= 0 ? value : null;
}

/**
 * Return whether the current owned gallery/card context is paginated.
 *
 * Pagination metadata is intentionally derived from the server-owned grid state,
 * not from URL text. A positive page number means an off-page target may be valid.
 *
 * @param {*} root Document-like root.
 * @return {boolean} True when a server-owned gallery region is paginated.
 */
export function publicGalleryContextIsPaginated(root) {
    if (!root || typeof root.querySelector !== 'function') {
        return false;
    }
    const galleryRegion = root.querySelector('[data-public-subgallery-grid][data-public-gallery-total-pages]')
        || root.querySelector('[data-public-gallery-index-grid][data-public-gallery-total-pages]');
    const totalPages = Number(galleryRegion?.dataset?.publicGalleryTotalPages
        ?? galleryRegion?.getAttribute?.('data-public-gallery-total-pages')
        ?? 1);
    return Number.isInteger(totalPages) && totalPages > 1;
}

/**
 * Return the visible image ids in their server-rendered order.
 *
 * @param {*} root Document-like root.
 * @return {number[]} Visible stable image ids.
 */
export function publicGalleryImageOrder(root) {
    const imageList = root && typeof root.querySelector === 'function'
        ? root.querySelector('[data-gallery-image-list]')
        : null;
    if (!imageList || typeof imageList.querySelectorAll !== 'function') {
        return [];
    }
    return Array.from(imageList.querySelectorAll('[data-public-photo-order-item][data-public-order-id]'))
        .map((node) => positiveInteger(node?.dataset?.publicOrderId ?? node?.getAttribute?.('data-public-order-id') ?? 0))
        .filter((id) => id > 0);
}

/**
 * Find a physical gallery card only inside an owned public gallery grid.
 *
 * Admin panel/editor controls also use data-gallery-id. Restricting the lookup to
 * public card containers prevents a newly loaded editor from falsely satisfying a
 * public synchronization postcondition.
 *
 * @param {*} root Document-like root.
 * @param {number} galleryId Stable gallery id.
 * @return {*} Matching public gallery card or null.
 */
export function publicGalleryCardById(root, galleryId) {
    const id = positiveInteger(galleryId);
    if (id <= 0 || !root || typeof root.querySelector !== 'function') {
        return null;
    }
    const selector = `[data-gallery-id="${id}"]`;
    const subgalleryGrid = root.querySelector('[data-public-subgallery-grid]');
    const subgalleryCard = subgalleryGrid && typeof subgalleryGrid.querySelector === 'function'
        ? subgalleryGrid.querySelector(selector)
        : null;
    if (subgalleryCard) {
        return subgalleryCard;
    }
    const homeGrid = root.querySelector('.public-home-gallery-grid');
    return homeGrid && typeof homeGrid.querySelector === 'function'
        ? homeGrid.querySelector(selector)
        : null;
}

/**
 * Find a physical image card only inside the owned public image grid.
 *
 * Public image cards always carry data-public-order-id, while data-image-id is
 * shared with Admin controls and may be absent when the lightbox is disabled.
 * Restricting lookup to the public list avoids both false positives.
 *
 * @param {*} root Document-like root.
 * @param {number} imageId Stable image id.
 * @return {*} Matching public image card or null.
 */
export function publicGalleryImageById(root, imageId) {
    const id = positiveInteger(imageId);
    if (id <= 0 || !root || typeof root.querySelector !== 'function') {
        return null;
    }
    const imageList = root.querySelector('[data-gallery-image-list]');
    if (!imageList || typeof imageList.querySelector !== 'function') {
        return null;
    }
    return imageList.querySelector(`[data-public-order-id="${id}"]`)
        || imageList.querySelector(`[data-lightbox-image][data-image-id="${id}"]`)
        || null;
}

/**
 * Find a Smart Gallery card only inside an owned public gallery region.
 *
 * @param {*} root Document-like root.
 * @param {number} smartGalleryId Stable Smart Gallery id.
 * @return {*} Matching public Smart Gallery card or null.
 */
export function publicSmartGalleryCardById(root, smartGalleryId) {
    const id = positiveInteger(smartGalleryId);
    if (id <= 0 || !root || typeof root.querySelector !== 'function') {
        return null;
    }
    const selector = `[data-smart-gallery-id="${id}"]`;
    const topGroup = root.querySelector('[data-smart-gallery-attachment-group="top"]');
    const topCard = topGroup && typeof topGroup.querySelector === 'function' ? topGroup.querySelector(selector) : null;
    if (topCard) {
        return topCard;
    }
    const bottomGroup = root.querySelector('[data-smart-gallery-attachment-group="bottom"]');
    const bottomCard = bottomGroup && typeof bottomGroup.querySelector === 'function' ? bottomGroup.querySelector(selector) : null;
    if (bottomCard) {
        return bottomCard;
    }
    const homeGrid = root.querySelector('.public-home-gallery-grid');
    return homeGrid && typeof homeGrid.querySelector === 'function' ? homeGrid.querySelector(selector) : null;
}

/**
 * Return the bounded retry delay for an attempt index, or null when exhausted.
 *
 * @param {number} attempt Zero-based attempt index.
 * @param {number[]} delays Retry schedule.
 * @return {number|null} Delay in milliseconds, or null after the final attempt.
 */
export function adminMutationRetryDelay(attempt, delays = ADMIN_MUTATION_RETRY_DELAYS_MS) {
    return Number.isInteger(attempt) && attempt >= 0 && attempt < delays.length
        ? Math.max(0, Number(delays[attempt]) || 0)
        : null;
}

/**
 * Return the stable coordinator key for one normalized public context.
 *
 * @param {Record<string, *>} context Mutation context.
 * @return {string} Stable context key.
 */
export function adminMutationContextKey(context) {
    const normalized = normalizeMutationContext(context);
    if (!normalized) {
        return 'unknown';
    }
    if (normalized.type === 'gallery') {
        return `gallery:${normalized.gallery_id || 0}`;
    }
    if (normalized.type === 'tag') {
        return `tag:${normalized.tag_id || 0}`;
    }
    return 'gallery_index';
}

/**
 * Begin one mutation-completion generation before any panel or public refresh waits.
 *
 * Marking every affected context immediately prevents an older operation from
 * waking after a retry delay and starting a request that could supersede a newer
 * successful mutation. The panel uses the same generation rule globally because
 * only one Admin drawer can be mounted at a time.
 *
 * @param {Record<string, *>} envelope Normalized mutation envelope.
 * @return {{sequence:number,context_keys:string[],has_panel:boolean,panel_abort_controller:*|null}} Operation token.
 */
export function beginAdminMutationOperation(envelope) {
    mutationOperationSequence += 1;
    const sequence = mutationOperationSequence;
    const contextKeys = [...new Set((envelope?.contexts || []).map(adminMutationContextKey).filter((key) => key !== 'unknown'))];

    contextKeys.forEach((key) => {
        mutationOperationLatestByContext.set(key, sequence);
        const active = mutationActiveRefreshByContext.get(key);
        if (active?.controller && typeof active.controller.abort === 'function') {
            active.controller.abort('superseded-operation');
        }
        mutationActiveRefreshByContext.delete(key);
    });

    const hasPanel = Boolean(envelope?.panel);
    let panelAbortController = null;
    if (hasPanel) {
        mutationLatestPanelOperationSequence = sequence;
        if (mutationActivePanelAbortController && typeof mutationActivePanelAbortController.abort === 'function') {
            mutationActivePanelAbortController.abort('superseded-operation');
        }
        panelAbortController = typeof globalThis.AbortController === 'function' ? new AbortController() : null;
        mutationActivePanelAbortController = panelAbortController;
    }

    return {
        sequence,
        context_keys: contextKeys,
        has_panel: hasPanel,
        panel_abort_controller: panelAbortController,
    };
}

/**
 * Return whether an operation is still authoritative for one affected context.
 *
 * @param {Record<string, *>} operation Operation token.
 * @param {Record<string, *>} context Mutation context.
 * @return {boolean} True when this operation may still refresh the context.
 */
export function adminMutationOperationContextIsCurrent(operation, context) {
    const key = adminMutationContextKey(context);
    return Boolean(operation)
        && key !== 'unknown'
        && mutationOperationLatestByContext.get(key) === Number(operation.sequence || 0);
}

/**
 * Return whether an operation is still authoritative for the mounted Admin panel.
 *
 * @param {Record<string, *>} operation Operation token.
 * @return {boolean} True when the operation may still replace panel content.
 */
export function adminMutationOperationPanelIsCurrent(operation) {
    return Boolean(operation?.has_panel)
        && mutationLatestPanelOperationSequence === Number(operation.sequence || 0);
}

/**
 * Build the guard passed into side-panel refresh adapters.
 *
 * The adapter checks isCurrent() before and after its fetch and may pass signal to
 * fetch(), preventing a superseded response from replacing a newer panel state.
 *
 * @param {Record<string, *>} operation Operation token.
 * @return {{operationToken:number,isCurrent:Function,signal:*|null}} Panel guard.
 */
export function adminMutationPanelGuard(operation) {
    return {
        operationToken: Number(operation?.sequence || 0),
        isCurrent: () => adminMutationOperationPanelIsCurrent(operation),
        signal: operation?.panel_abort_controller?.signal || null,
    };
}

/**
 * Begin an ordered refresh for one stable context and return its sequence token.
 *
 * @param {Record<string, *>} context Mutation context.
 * @return {{key:string,sequence:number}} Refresh token.
 */
export function beginAdminMutationRefresh(context) {
    const key = adminMutationContextKey(context);
    mutationRefreshSequence += 1;
    mutationRefreshLatestByContext.set(key, mutationRefreshSequence);
    return {key, sequence: mutationRefreshSequence};
}

/**
 * Return whether a refresh token is still the newest request for its context.
 *
 * @param {{key:string,sequence:number}} token Refresh token.
 * @return {boolean} True when the response may still modify the live DOM.
 */
export function adminMutationRefreshIsCurrent(token) {
    return Boolean(token)
        && mutationRefreshLatestByContext.get(String(token.key || '')) === Number(token.sequence || 0);
}

/**
 * Replace the owned public gallery/tag fragments from a server-rendered response.
 *
 * Lifecycle teardown/rebind remains injectable because those bindings live in
 * other modules. This function owns only deterministic fragment replacement.
 *
 * @param {*} parsed Fresh server-rendered Document-like root.
 * @param {object} options Replacement dependencies and lifecycle hooks.
 * @return {boolean} True when at least one owned fragment changed.
 */
export function replaceOwnedPublicGalleryFragments(parsed, options = {}) {
    const liveDocument = options.documentRoot || globalThis.document;
    if (!liveDocument || !parsed || typeof liveDocument.querySelector !== 'function' || typeof parsed.querySelector !== 'function') {
        return false;
    }

    let replaced = false;
    let lifecycleStarted = false;
    const beforeReplace = typeof options.beforeReplace === 'function' ? options.beforeReplace : () => {};
    /** Start replacement lifecycle hooks at most once per coordinator pass. */
    const beginLifecycle = () => {
        if (!lifecycleStarted) {
            beforeReplace();
            lifecycleStarted = true;
        }
    };

    const currentHero = liveDocument.querySelector('.hero');
    const freshHero = parsed.querySelector('.hero');
    if (currentHero && freshHero && typeof currentHero.replaceWith === 'function') {
        currentHero.replaceWith(freshHero);
        replaced = true;
    }

    const currentFrame = liveDocument.querySelector('[data-back-to-top-scope]');
    const freshFrame = parsed.querySelector('[data-back-to-top-scope]');
    if (currentFrame && freshFrame) {
        beginLifecycle();
        replaceOwnedPublicGalleryFrameChildren(currentFrame, freshFrame);
        return true;
    }
    if (currentFrame && !freshFrame && typeof currentFrame.remove === 'function') {
        beginLifecycle();
        currentFrame.remove();
        return true;
    }
    if (!currentFrame && freshFrame) {
        const main = liveDocument.querySelector('main.site-main');
        const lightbox = main?.querySelector?.('[data-lightbox]') || null;
        if (lightbox && typeof lightbox.insertAdjacentElement === 'function') {
            beginLifecycle();
            lightbox.insertAdjacentElement('beforebegin', freshFrame);
            return true;
        }
        if (main && typeof main.append === 'function') {
            beginLifecycle();
            main.append(freshFrame);
            return true;
        }
    }

    const fragmentPairs = [
        ['[data-public-subgallery-section]', '[data-public-subgallery-section]'],
        ['[data-gallery-image-list]', '[data-gallery-image-list]'],
    ];
    fragmentPairs.forEach(([currentSelector, freshSelector]) => {
        const current = liveDocument.querySelector(currentSelector);
        const fresh = parsed.querySelector(freshSelector);
        if (current && fresh && typeof current.replaceWith === 'function') {
            beginLifecycle();
            current.replaceWith(fresh);
            replaced = true;
        } else if (current && !fresh && typeof current.remove === 'function') {
            beginLifecycle();
            current.remove();
            replaced = true;
        }
    });
    return replaced;
}

/**
 * Replace frame content without discarding the persistent back-to-top shell.
 *
 * @param {*} currentFrame Current public gallery frame.
 * @param {*} freshFrame Fresh server-rendered public gallery frame.
 */
function replaceOwnedPublicGalleryFrameChildren(currentFrame, freshFrame) {
    copyElementIdentity(currentFrame, freshFrame);

    const currentButton = currentFrame.querySelector?.('[data-back-to-top-button]') || null;
    const freshButton = freshFrame.querySelector?.('[data-back-to-top-button]') || null;
    if (freshButton && typeof freshButton.remove === 'function') {
        freshButton.remove();
    }

    Array.from(currentFrame.childNodes || []).forEach((node) => {
        if (node !== currentButton && typeof node.remove === 'function') {
            node.remove();
        }
    });

    const anchor = currentButton || null;
    Array.from(freshFrame.childNodes || []).forEach((node) => {
        if (anchor && typeof currentFrame.insertBefore === 'function') {
            currentFrame.insertBefore(node, anchor);
        } else if (typeof currentFrame.appendChild === 'function') {
            currentFrame.appendChild(node);
        }
    });

    if (!currentButton && freshButton && typeof currentFrame.appendChild === 'function') {
        currentFrame.appendChild(freshButton);
    }
}

/**
 * Copy attributes from fresh server markup to an element that remains mounted.
 *
 * @param {*} current Persistent live element.
 * @param {*} fresh Fresh server-rendered element.
 */
function copyElementIdentity(current, fresh) {
    Array.from(current.attributes || []).forEach((attribute) => {
        if (!fresh.hasAttribute?.(attribute.name)) {
            current.removeAttribute?.(attribute.name);
        }
    });
    Array.from(fresh.attributes || []).forEach((attribute) => {
        current.setAttribute?.(attribute.name, attribute.value);
    });
}

/**
 * Complete one successful Admin mutation through panel and public-context synchronization.
 *
 * @param {Record<string, *>} rawEnvelope Canonical successful mutation envelope.
 * @param {object} options Workflow adapters and browser dependencies.
 * @return {Promise<Record<string, *>>} Synchronization result.
 */
export async function completeAdminMutation(rawEnvelope, options = {}) {
    const envelope = normalizeAdminMutationEnvelope(rawEnvelope);
    const liveDocument = options.documentRoot || globalThis.document;
    const currentUrl = String(options.currentUrl || globalThis.window?.location?.href || '');
    const wait = options.wait || ((delayMs) => new Promise((resolve) => globalThis.setTimeout(resolve, delayMs)));
    const retryDelays = Array.isArray(options.retryDelays) && options.retryDelays.length > 0
        ? options.retryDelays
        : ADMIN_MUTATION_RETRY_DELAYS_MS;
    const contextResults = [];
    const diagnostics = [];
    const operation = beginAdminMutationOperation(envelope);
    let panelResult = {status: envelope.panel ? 'pending' : 'not-requested', synchronized: true};

    if (envelope.panel && typeof options.refreshPanel === 'function') {
        const guard = adminMutationPanelGuard(operation);
        try {
            if (!guard.isCurrent()) {
                panelResult = {status: 'superseded-operation', synchronized: true};
            } else {
                const refreshed = await options.refreshPanel(envelope.panel, envelope, guard);
                if (!guard.isCurrent()) {
                    panelResult = {status: 'superseded-operation', synchronized: true};
                } else if (refreshed === false) {
                    panelResult = {status: 'panel-refresh-failed', synchronized: false};
                } else {
                    panelResult = {status: 'synchronized', synchronized: true};
                }
            }
        } catch (error) {
            panelResult = guard.isCurrent()
                ? {status: 'panel-refresh-failed', synchronized: false}
                : {status: 'superseded-operation', synchronized: true};
        } finally {
            if (mutationActivePanelAbortController === operation.panel_abort_controller) {
                mutationActivePanelAbortController = null;
            }
        }
    }

    for (const context of envelope.contexts || []) {
        const contextKey = adminMutationContextKey(context);
        if (!mutationContextMatchesCurrentDocument(context, liveDocument, currentUrl, currentUrl)) {
            contextResults.push({context, status: 'not-visible', synchronized: true, verified: null});
            diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, 0, 'not-visible', currentUrl));
            continue;
        }

        let synchronized = false;
        let verified = context.postcondition ? false : null;
        let lastStatus = 'request-failed';
        for (let attempt = 0; attempt < retryDelays.length; attempt += 1) {
            if (!adminMutationOperationContextIsCurrent(operation, context)) {
                synchronized = true;
                lastStatus = 'superseded-operation';
                break;
            }

            const delayMs = adminMutationRetryDelay(attempt, retryDelays);
            if (delayMs === null) {
                break;
            }
            if (delayMs > 0) {
                await wait(delayMs);
                if (!adminMutationOperationContextIsCurrent(operation, context)) {
                    synchronized = true;
                    lastStatus = 'superseded-operation';
                    break;
                }
            }

            const attemptToken = beginAdminMutationRefresh(context);
            const controller = typeof globalThis.AbortController === 'function' ? new AbortController() : null;
            if (controller) {
                mutationActiveRefreshByContext.set(contextKey, {
                    operation_sequence: operation.sequence,
                    attempt_sequence: attemptToken.sequence,
                    controller,
                });
            }
            const renderSource = resolveAdminMutationContextRenderUrl(context, currentUrl, liveDocument);
            const fetched = await fetchAdminMutationRenderDocument(renderSource, {
                currentUrl,
                fetchImpl: options.fetchImpl,
                parseHtml: options.parseHtml,
                nonce: typeof options.nonce === 'function' ? options.nonce(attempt) : undefined,
                signal: controller?.signal || null,
            });
            const activeRefresh = mutationActiveRefreshByContext.get(contextKey);
            if (activeRefresh?.operation_sequence === operation.sequence && activeRefresh?.attempt_sequence === attemptToken.sequence) {
                mutationActiveRefreshByContext.delete(contextKey);
            }

            if (!adminMutationOperationContextIsCurrent(operation, context)) {
                synchronized = true;
                lastStatus = 'superseded-operation';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                break;
            }
            if (!adminMutationRefreshIsCurrent(attemptToken)) {
                synchronized = true;
                lastStatus = 'superseded-request';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                break;
            }
            if (!fetched.ok || !fetched.document) {
                lastStatus = fetched.status || 'request-failed';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                if (lastStatus === 'request-aborted') {
                    synchronized = true;
                    lastStatus = 'superseded-operation';
                    break;
                }
                continue;
            }
            if (context.type === 'gallery' && publicGalleryIdFromRoot(fetched.document) !== positiveInteger(context.gallery_id)) {
                lastStatus = 'wrong-context';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                continue;
            }
            if (context.type === 'tag' && publicTagIdFromRoot(fetched.document) !== positiveInteger(context.tag_id)) {
                lastStatus = 'wrong-context';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                continue;
            }
            if (context.type === 'gallery_index' && !publicGalleryIndexFromRoot(fetched.document)) {
                lastStatus = 'wrong-context';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                continue;
            }
            if (context.postcondition && !evaluateAdminMutationPostcondition(context.postcondition, fetched.document)) {
                // Structurally valid HTML can still be a stale cache/read replica response.
                // Never install it into the live DOM merely to discover that it is stale.
                verified = false;
                lastStatus = 'stale-response';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                continue;
            }

            const replace = options.replacePublicContext || ((parsed) => replaceOwnedPublicGalleryFragments(parsed, {documentRoot: liveDocument}));
            const replaced = Boolean(replace(fetched.document, context, envelope));
            if (replaced && context.render_mode === 'canonical' && context.type === 'gallery' && liveDocument && typeof liveDocument.querySelector === 'function') {
                const liveHero = liveDocument.querySelector(`.hero[data-public-gallery-id="${positiveInteger(context.gallery_id)}"]`);
                if (liveHero && context.render_url) {
                    liveHero.setAttribute?.('data-admin-mutation-canonical-url', String(context.render_url));
                }
            }
            if (replaced && context.render_mode === 'canonical' && context.type === 'tag' && liveDocument && typeof liveDocument.querySelector === 'function') {
                const liveHero = liveDocument.querySelector(`.hero[data-public-tag-page][data-tag-id="${positiveInteger(context.tag_id)}"]`);
                if (liveHero && context.render_url) {
                    liveHero.setAttribute?.('data-admin-mutation-canonical-url', String(context.render_url));
                }
            }
            if (replaced && typeof options.afterPublicReplace === 'function') {
                options.afterPublicReplace(context, envelope);
            }
            if (!replaced) {
                lastStatus = 'fragment-not-owned';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                continue;
            }

            if (!context.postcondition) {
                synchronized = true;
                verified = null;
                lastStatus = 'refreshed-unverified';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                break;
            }
            if (evaluateAdminMutationPostcondition(context.postcondition, liveDocument)) {
                synchronized = true;
                verified = true;
                lastStatus = 'synchronized';
                diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
                break;
            }
            verified = false;
            lastStatus = 'postcondition-failed';
            diagnostics.push(adminMutationDiagnosticRecord(envelope, operation, context, attempt + 1, lastStatus, renderSource));
        }
        contextResults.push({context, status: lastStatus, synchronized, verified});
    }

    const failedContexts = contextResults.filter((result) => result.status !== 'not-visible' && !result.synchronized);
    const syncResult = {
        ok: envelope.ok !== false,
        synchronized: panelResult.synchronized && failedContexts.length === 0,
        operation_token: operation.sequence,
        panel: panelResult,
        envelope,
        contexts: contextResults,
        failed_contexts: failedContexts,
        diagnostics,
    };

    if ((!panelResult.synchronized || failedContexts.length > 0) && typeof options.reportSynchronizationError === 'function') {
        options.reportSynchronizationError(syncResult);
    }
    emitAdminMutationDiagnostics(syncResult, options);
    if (typeof options.emitCompletion === 'function') {
        options.emitCompletion(syncResult);
    } else if (liveDocument && typeof liveDocument.dispatchEvent === 'function' && typeof globalThis.CustomEvent === 'function') {
        liveDocument.dispatchEvent(new CustomEvent('php-gallery:mutation-complete', {detail: syncResult}));
    }
    return syncResult;
}

/**
 * Build one privacy-bounded diagnostic record for a coordinator attempt.
 *
 * @param {Record<string, *>} envelope Canonical mutation envelope.
 * @param {Record<string, *>} operation Operation token.
 * @param {Record<string, *>} context Affected public context.
 * @param {number} attempt One-based attempt number, or zero for a skipped context.
 * @param {string} result Verification/request result.
 * @param {string} renderUrl Render URL used for the attempt.
 * @return {Record<string, *>} Safe diagnostic metadata.
 */
function adminMutationDiagnosticRecord(envelope, operation, context, attempt, result, renderUrl) {
    return {
        mutation_type: String(envelope?.mutation?.type || ''),
        operation_token: Number(operation?.sequence || 0),
        context_key: adminMutationContextKey(context),
        affected_gallery_id: positiveInteger(context?.gallery_id) || null,
        affected_tag_id: positiveInteger(context?.tag_id) || null,
        refresh_path: safeAdminMutationDiagnosticPath(renderUrl),
        expected_postcondition: context?.postcondition ? {...context.postcondition} : null,
        attempt: Math.max(0, Number(attempt || 0)),
        result: String(result || ''),
    };
}

/**
 * Return only the pathname portion of a refresh URL for debug output.
 *
 * Query strings can contain private share tokens or other sensitive values, so
 * diagnostics deliberately omit them even when development logging is enabled.
 *
 * @param {string} value URL value.
 * @return {string} Pathname or an empty string.
 */
function safeAdminMutationDiagnosticPath(value) {
    try {
        const base = String(globalThis.window?.location?.href || 'http://localhost/');
        return new URL(String(value || ''), base).pathname;
    } catch (error) {
        return '';
    }
}

/**
 * Emit coordinator diagnostics only when explicitly enabled for development/tests.
 *
 * Enable with options.debug=true, ?admin_mutation_debug=1, or the matching
 * localStorage flag. Production operation remains silent by default.
 *
 * @param {Record<string, *>} syncResult Completed synchronization result.
 * @param {Record<string, *>} options Coordinator options.
 */
function emitAdminMutationDiagnostics(syncResult, options) {
    let enabled = options.debug === true;
    if (!enabled && globalThis.window?.location?.search) {
        try {
            enabled = new URLSearchParams(globalThis.window.location.search).get('admin_mutation_debug') === '1';
        } catch (error) {
            enabled = false;
        }
    }
    if (!enabled && globalThis.window?.localStorage) {
        try {
            enabled = globalThis.window.localStorage.getItem('admin_mutation_debug') === '1';
        } catch (error) {
            enabled = false;
        }
    }
    if (enabled && globalThis.console && typeof globalThis.console.debug === 'function') {
        globalThis.console.debug('[admin-mutation]', {
            operation_token: syncResult.operation_token,
            mutation_type: String(syncResult.envelope?.mutation?.type || ''),
            panel: syncResult.panel,
            diagnostics: syncResult.diagnostics,
        });
    }
}
